<?php

require __DIR__ . '/include/bootstrap.php';

$page_title = 'Timekeeper Requests';
$flash = get_flash();

function ensure_timekeeper_access_request_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`timekeeper_project_access_requests` (' .
        '`id` int NOT NULL AUTO_INCREMENT,' .
        '`user_id` varchar(50) NOT NULL,' .
        '`project_code` varchar(20) NOT NULL,' .
        '`reason` text NULL,' .
        "`status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending'," .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        '`reviewed_by` varchar(50) NULL,' .
        '`reviewed_at` datetime NULL,' .
        '`review_note` text NULL,' .
        'PRIMARY KEY (`id`),' .
        'UNIQUE KEY `uniq_user_project` (`user_id`, `project_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$loadError = null;
if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!ensure_timekeeper_access_request_table($bd)) {
        $loadError = 'Unable to load access request table.';
    }
}

$statusOptions = ['pending', 'approved', 'rejected', 'all'];
$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = 'pending';
}
$search = trim((string) ($_GET['q'] ?? ''));

if (!$loadError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        set_flash('warning', 'Invalid request token. Please try again.');
        header('Location: ' . admin_url('Attendance_TimekeeperAccess.php'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $reviewNote = trim((string) ($_POST['review_note'] ?? ''));

    if (!in_array($action, ['approve', 'reject'], true) || $requestId <= 0) {
        set_flash('warning', 'Invalid action.');
        header('Location: ' . admin_url('Attendance_TimekeeperAccess.php'));
        exit;
    }

    $stmt = $bd->prepare(
        'SELECT id, user_id, project_code, status FROM gcc_attendance_master.timekeeper_project_access_requests WHERE id = ? LIMIT 1'
    );
    $request = null;
    if ($stmt) {
        $stmt->bind_param('i', $requestId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $request = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
        }
        $stmt->close();
    }

    if (!$request) {
        set_flash('warning', 'Request not found.');
        header('Location: ' . admin_url('Attendance_TimekeeperAccess.php'));
        exit;
    }

    $userId = (string) ($request['user_id'] ?? '');
    $projectCode = (string) ($request['project_code'] ?? '');
    $reviewerId = (string) ($_SESSION['user_id'] ?? '');

    if ($action === 'approve') {
        $insertStmt = $bd->prepare(
            'INSERT IGNORE INTO gcc_attendance_master.timekeeper_project_map (user_id, project_code) VALUES (?, ?)'
        );
        if ($insertStmt) {
            $insertStmt->bind_param('ss', $userId, $projectCode);
            $insertStmt->execute();
            $insertStmt->close();
        }

        $updateStmt = $bd->prepare(
            'UPDATE gcc_attendance_master.timekeeper_project_access_requests ' .
            'SET status = "approved", reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?'
        );
        if ($updateStmt) {
            $updateStmt->bind_param('ssi', $reviewerId, $reviewNote, $requestId);
            $updateStmt->execute();
            $updateStmt->close();
        }
        set_flash('success', 'Request approved and access granted.');
    } else {
        $updateStmt = $bd->prepare(
            'UPDATE gcc_attendance_master.timekeeper_project_access_requests ' .
            'SET status = "rejected", reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?'
        );
        if ($updateStmt) {
            $updateStmt->bind_param('ssi', $reviewerId, $reviewNote, $requestId);
            $updateStmt->execute();
            $updateStmt->close();
        }
        set_flash('info', 'Request rejected.');
    }

    header('Location: ' . admin_url('Attendance_TimekeeperAccess.php') . '?status=' . urlencode($statusFilter));
    exit;
}

$requests = [];
if (!$loadError) {
    $sql =
        'SELECT r.id, r.user_id, r.project_code, r.reason, r.status, r.created_at, r.reviewed_by, r.reviewed_at, r.review_note, ' .
        'p.project_name, u.full_name, u.email, ru.full_name AS reviewer_name, ru.email AS reviewer_email ' .
        'FROM gcc_attendance_master.timekeeper_project_access_requests r ' .
        'LEFT JOIN gcc_attendance_master.hrms_projects p ON p.project_code = r.project_code ' .
        'LEFT JOIN gcc_it.users u ON u.id = r.user_id ' .
        'LEFT JOIN gcc_it.users ru ON ru.id = r.reviewed_by';

    $where = [];
    $params = [];
    $types = '';

    if ($statusFilter !== 'all') {
        $where[] = 'r.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(r.project_code LIKE ? OR p.project_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
        $params = array_merge($params, [$like, $like, $like, $like]);
        $types .= 'ssss';
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.created_at DESC';

    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $requests[] = $row;
                }
                $result->free();
            }
        }
        $stmt->close();
    }
}

include __DIR__ . '/include/layout_top.php';

$statusClasses = [
    'pending' => 'badge badge-warning',
    'approved' => 'badge badge-success',
    'rejected' => 'badge badge-danger',
];

?>

<style>
  .request-table td,
  .request-table th {
    vertical-align: top;
  }
  .request-status {
    text-transform: capitalize;
    letter-spacing: 0.03em;
  }
  .request-actions {
    min-width: 240px;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Timekeeper Requests</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> mb-3"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php else: ?>
      <div class="card mb-3">
        <div class="card-header">
          <h3 class="card-title">Filters</h3>
        </div>
        <div class="card-body">
          <form method="get" class="form-row align-items-end">
            <div class="form-group col-md-3">
              <label for="status">Status</label>
              <select id="status" name="status" class="form-control">
                <?php foreach ($statusOptions as $status): ?>
                  <option value="<?= h($status) ?>" <?= $status === $statusFilter ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-6">
              <label for="q">Search</label>
              <input id="q" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Project, user, or email">
            </div>
            <div class="form-group col-md-3">
              <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Requests</h3>
        </div>
        <div class="card-body">
          <?php if (empty($requests)): ?>
            <p class="text-muted mb-0">No requests found.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm request-table">
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Requested</th>
                    <th class="request-actions">Actions / Review</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($requests as $request): ?>
                    <?php $status = (string) ($request['status'] ?? 'pending'); ?>
                    <tr>
                      <td>
                        <div><?= h($request['full_name'] ?? '') ?></div>
                        <div class="text-muted small"><?= h($request['email'] ?? '') ?></div>
                        <div class="text-muted small">ID: <?= h($request['user_id'] ?? '') ?></div>
                      </td>
                      <td>
                        <div><strong><?= h($request['project_code'] ?? '') ?></strong></div>
                        <?php if (!empty($request['project_name'])): ?>
                          <div class="text-muted small"><?= h($request['project_name']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="request-status <?= h($statusClasses[$status] ?? 'badge badge-secondary') ?>">
                          <?= h($status) ?>
                        </span>
                        <?php if (!empty($request['reviewer_name']) || !empty($request['reviewer_email'])): ?>
                          <div class="text-muted small mt-1">
                            <?= h($request['reviewer_name'] ?? '') ?> <?= h($request['reviewer_email'] ?? '') ?>
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($request['reviewed_at'])): ?>
                          <div class="text-muted small"><?= h($request['reviewed_at']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td><?= h($request['reason'] ?? '') ?></td>
                      <td><?= h($request['created_at'] ?? '') ?></td>
                      <td>
                        <?php if ($status === 'pending'): ?>
                          <form method="post" class="request-actions">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="request_id" value="<?= h($request['id'] ?? '') ?>">
                            <div class="form-group mb-2">
                              <input name="review_note" class="form-control form-control-sm" placeholder="Review note (optional)">
                            </div>
                            <div class="d-flex flex-wrap" style="gap: 6px;">
                              <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                              <button type="submit" name="action" value="reject" class="btn btn-outline-danger btn-sm">Reject</button>
                            </div>
                          </form>
                        <?php else: ?>
                          <div class="text-muted">No actions</div>
                          <?php if (!empty($request['review_note'])): ?>
                            <div class="text-muted small mt-1"><?= h($request['review_note']) ?></div>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
