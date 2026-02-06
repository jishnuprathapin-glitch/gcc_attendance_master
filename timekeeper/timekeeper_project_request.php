<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'Project Access Requests';
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

function normalize_selection($value): array {
    $items = is_array($value) ? $value : [$value];
    $clean = [];
    foreach ($items as $item) {
        if (!is_scalar($item) && $item !== null) {
            continue;
        }
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        $clean[$item] = true;
    }
    return array_keys($clean);
}

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$loadError = null;
$projectOptions = [];
$currentAccess = [];
$requests = [];

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!ensure_timekeeper_access_request_table($bd)) {
        $loadError = 'Unable to load access request table.';
    }
}

if (!$loadError && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_request') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        set_flash('warning', 'Invalid request token. Please try again.');
        header('Location: ' . admin_url('timekeeper_project_request.php'));
        exit;
    }

    $selected = normalize_selection($_POST['project_codes'] ?? []);
    if (empty($selected)) {
        set_flash('warning', 'Select at least one project to request access.');
        header('Location: ' . admin_url('timekeeper_project_request.php'));
        exit;
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = null;
    }

    $projectResult = $bd->query(
        'SELECT project_code, project_name FROM gcc_attendance_master.hrms_projects ORDER BY project_code'
    );
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $code = trim((string) ($row['project_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $projectOptions[$code] = trim((string) ($row['project_name'] ?? ''));
        }
        $projectResult->free();
    }

    $mapped = [];
    if ($userId !== '') {
        $stmt = $bd->prepare(
            'SELECT project_code FROM gcc_attendance_master.timekeeper_project_map WHERE user_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('s', $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $code = trim((string) ($row['project_code'] ?? ''));
                        if ($code !== '') {
                            $mapped[$code] = true;
                        }
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }
    }

    $existing = [];
    if ($userId !== '') {
        $stmt = $bd->prepare(
            'SELECT project_code, status FROM gcc_attendance_master.timekeeper_project_access_requests WHERE user_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('s', $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $code = trim((string) ($row['project_code'] ?? ''));
                        if ($code !== '') {
                            $existing[$code] = (string) ($row['status'] ?? 'pending');
                        }
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }
    }

    $insertStmt = $bd->prepare(
        'INSERT INTO gcc_attendance_master.timekeeper_project_access_requests (user_id, project_code, reason) VALUES (?, ?, ?)'
    );
    $reasonUpdateStmt = $bd->prepare(
        'UPDATE gcc_attendance_master.timekeeper_project_access_requests SET reason = ? WHERE user_id = ? AND project_code = ?'
    );

    $counts = [
        'created' => 0,
        'reason_updated' => 0,
        'already_access' => 0,
        'invalid' => 0,
    ];

    foreach ($selected as $code) {
        if (!isset($projectOptions[$code])) {
            $counts['invalid']++;
            continue;
        }
        if (isset($mapped[$code])) {
            $counts['already_access']++;
            continue;
        }
        if (isset($existing[$code])) {
            if ($reasonUpdateStmt) {
                $reasonUpdateStmt->bind_param('sss', $reason, $userId, $code);
                $reasonUpdateStmt->execute();
                $counts['reason_updated']++;
            }
            continue;
        }
        if ($insertStmt) {
            $insertStmt->bind_param('sss', $userId, $code, $reason);
            $insertStmt->execute();
            if ($insertStmt->affected_rows > 0) {
                $counts['created']++;
            }
        }
    }

    if ($insertStmt) {
        $insertStmt->close();
    }
    if ($reasonUpdateStmt) {
        $reasonUpdateStmt->close();
    }

    $parts = [];
    if ($counts['created'] > 0) {
        $parts[] = $counts['created'] . ' request(s) submitted';
    }
    if ($counts['reason_updated'] > 0) {
        $parts[] = $counts['reason_updated'] . ' request(s) updated';
    }
    if ($counts['already_access'] > 0) {
        $parts[] = $counts['already_access'] . ' already have access';
    }
    if ($counts['invalid'] > 0) {
        $parts[] = $counts['invalid'] . ' invalid project(s)';
    }

    if (empty($parts)) {
        set_flash('info', 'No changes were made.');
    } else {
        set_flash($counts['created'] > 0 || $counts['reason_updated'] > 0 ? 'success' : 'info', implode('. ', $parts) . '.');
    }

    header('Location: ' . admin_url('timekeeper_project_request.php'));
    exit;
}

if (!$loadError && empty($projectOptions)) {
    $projectResult = $bd->query(
        'SELECT project_code, project_name FROM gcc_attendance_master.hrms_projects ORDER BY project_code'
    );
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $code = trim((string) ($row['project_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $projectOptions[$code] = trim((string) ($row['project_name'] ?? ''));
        }
        $projectResult->free();
    }
}

if (!$loadError && $userId !== '') {
    $stmt = $bd->prepare(
        'SELECT m.project_code, p.project_name ' .
        'FROM gcc_attendance_master.timekeeper_project_map m ' .
        'LEFT JOIN gcc_attendance_master.hrms_projects p ON p.project_code = m.project_code ' .
        'WHERE m.user_id = ? ORDER BY m.project_code'
    );
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $currentAccess[] = [
                        'code' => trim((string) ($row['project_code'] ?? '')),
                        'name' => trim((string) ($row['project_name'] ?? '')),
                    ];
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    $stmt = $bd->prepare(
        'SELECT r.project_code, r.reason, r.status, r.created_at, r.reviewed_by, r.reviewed_at, r.review_note, ' .
        'p.project_name, u.full_name AS reviewer_name, u.email AS reviewer_email ' .
        'FROM gcc_attendance_master.timekeeper_project_access_requests r ' .
        'LEFT JOIN gcc_attendance_master.hrms_projects p ON p.project_code = r.project_code ' .
        'LEFT JOIN gcc_it.users u ON u.id = r.reviewed_by ' .
        'WHERE r.user_id = ? ORDER BY r.created_at DESC'
    );
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $requests[] = [
                        'code' => trim((string) ($row['project_code'] ?? '')),
                        'name' => trim((string) ($row['project_name'] ?? '')),
                        'status' => (string) ($row['status'] ?? 'pending'),
                        'reason' => trim((string) ($row['reason'] ?? '')),
                        'created_at' => (string) ($row['created_at'] ?? ''),
                        'reviewed_by' => trim((string) ($row['reviewed_by'] ?? '')),
                        'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                        'review_note' => trim((string) ($row['review_note'] ?? '')),
                        'reviewer_name' => trim((string) ($row['reviewer_name'] ?? '')),
                        'reviewer_email' => trim((string) ($row['reviewer_email'] ?? '')),
                    ];
                }
                $result->free();
            }
        }
        $stmt->close();
    }
}

include dirname(__DIR__) . '/admin/include/layout_top.php';

$statusClasses = [
    'pending' => 'badge badge-warning',
    'approved' => 'badge badge-success',
    'rejected' => 'badge badge-danger',
];

?>

<link rel="stylesheet" href="plugins/select2/css/select2.min.css">
<link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">

<style>
  .request-summary {
    font-size: 0.9rem;
    color: #475569;
  }
  .request-table td,
  .request-table th {
    vertical-align: top;
  }
  .request-status {
    text-transform: capitalize;
    letter-spacing: 0.03em;
  }
  .request-form .select2-container {
    width: 100% !important;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Project Access Requests</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'timekeeper'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
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
          <h3 class="card-title">My Current Access</h3>
        </div>
        <div class="card-body">
          <?php if (empty($currentAccess)): ?>
            <p class="text-muted mb-0">No project access assigned yet.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm request-table">
                <thead>
                  <tr>
                    <th>Project Code</th>
                    <th>Project Name</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($currentAccess as $access): ?>
                    <tr>
                      <td><?= h($access['code']) ?></td>
                      <td><?= h($access['name']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">
          <h3 class="card-title">My Requests</h3>
        </div>
        <div class="card-body">
          <?php if (empty($requests)): ?>
            <p class="text-muted mb-0">No requests submitted yet.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm request-table">
                <thead>
                  <tr>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Reviewed By</th>
                    <th>Review Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($requests as $request): ?>
                    <?php $status = $request['status'] ?: 'pending'; ?>
                    <tr>
                      <td>
                        <div><strong><?= h($request['code']) ?></strong></div>
                        <?php if ($request['name'] !== ''): ?>
                          <div class="text-muted small"><?= h($request['name']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="request-status <?= h($statusClasses[$status] ?? 'badge badge-secondary') ?>">
                          <?= h($status) ?>
                        </span>
                      </td>
                      <td><?= h($request['created_at']) ?></td>
                      <td>
                        <?php if ($request['reviewer_name'] || $request['reviewer_email']): ?>
                          <div><?= h($request['reviewer_name']) ?></div>
                          <div class="text-muted small"><?= h($request['reviewer_email']) ?></div>
                        <?php elseif ($request['reviewed_by'] !== ''): ?>
                          <?= h($request['reviewed_by']) ?>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                        <?php if ($request['reviewed_at'] !== ''): ?>
                          <div class="text-muted small"><?= h($request['reviewed_at']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($request['review_note'] !== ''): ?>
                          <?= h($request['review_note']) ?>
                        <?php else: ?>
                          <span class="text-muted">-</span>
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

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Request New Access</h3>
        </div>
        <div class="card-body">
          <p class="request-summary">Request access for one or more projects. Your request will be reviewed by an administrator.</p>
          <form method="post" class="request-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit_request">
            <div class="form-group">
              <label for="project_codes">Projects</label>
              <select id="project_codes" name="project_codes[]" class="form-control js-searchable" data-placeholder="Select projects" multiple>
                <?php foreach ($projectOptions as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="reason">Reason (optional)</label>
              <textarea id="reason" name="reason" class="form-control" rows="3" placeholder="Add a short reason for this request."></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit request</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<script defer src="plugins/select2/js/select2.full.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
      return;
    }
    jQuery('.js-searchable').each(function () {
      const $select = jQuery(this);
      const isMultiple = $select.prop('multiple');
      $select.select2({
        theme: 'bootstrap4',
        width: '100%',
        allowClear: true,
        placeholder: $select.data('placeholder') || 'Select',
        minimumResultsForSearch: 0,
        closeOnSelect: !isMultiple,
      });
    });
  });
</script>

<?php include dirname(__DIR__) . '/admin/include/layout_bottom.php'; ?>
