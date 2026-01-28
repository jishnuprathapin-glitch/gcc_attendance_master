<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Attendance Override Approvals';

function normalize_date(?string $value, string $fallback): string {
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function current_week_range(): array {
    $today = new DateTimeImmutable('today');
    $dayOfWeek = (int) $today->format('N');
    $start = $today->modify('-' . ($dayOfWeek - 1) . ' days');
    $end = $start->modify('+6 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
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

function format_person(?string $name, ?string $email): string {
    $name = trim((string) $name);
    $email = trim((string) $email);
    if ($name !== '' && $email !== '' && stripos($name, $email) === false) {
        return $name . ' (' . $email . ')';
    }
    if ($name !== '') {
        return $name;
    }
    if ($email !== '') {
        return $email;
    }
    return '-';
}

[$defaultStart, $defaultEnd] = current_week_range();

$employeeCodeFilter = trim((string) ($_GET['employeeCode'] ?? ''));
$startDate = normalize_date($_GET['start_date'] ?? '', $defaultStart);
$endDate = normalize_date($_GET['end_date'] ?? '', $defaultEnd);

if ($startDate > $endDate) {
    $swap = $startDate;
    $startDate = $endDate;
    $endDate = $swap;
}

$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Invalid request token.';
    } else {
        $empCode = trim((string) ($_POST['employeeCode'] ?? ''));
        $attDate = trim((string) ($_POST['attDate'] ?? ''));

        if ($empCode === '' || $attDate === '') {
            $error = 'Employee code and date are required.';
        } elseif ($userName === '' || $userEmail === '') {
            $error = 'User name/email missing in session.';
        } elseif (!isset($bd) || !($bd instanceof mysqli)) {
            $error = 'Database connection not available.';
        } else {
            $row = null;
            $stmt = $bd->prepare(
                'SELECT override_work_hours, override_work_code, override_changed_by_name, override_changed_by_email ' .
                'FROM gcc_attendance_master.employee_att_daily ' .
                'WHERE emp_code = ? AND att_date = ? LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ss', $empCode, $attDate);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $result->free();
                    }
                }
                $stmt->close();
            }

            if (!$row) {
                $error = 'Override row not found.';
            } else {
                $changedByName = trim((string) ($row['override_changed_by_name'] ?? ''));
                $changedByEmail = trim((string) ($row['override_changed_by_email'] ?? ''));
                if ($changedByName === '') {
                    $changedByName = $userName;
                }
                if ($changedByEmail === '') {
                    $changedByEmail = $userEmail;
                }

                $payload = [
                    'employeeCode' => $empCode,
                    'attDate' => $attDate,
                    'workHours' => $row['override_work_hours'] !== null ? (float) $row['override_work_hours'] : null,
                    'workCode' => ($row['override_work_code'] ?? '') !== '' ? $row['override_work_code'] : null,
                    'changeDate' => gmdate(DATE_ATOM),
                    'changedByEmail' => $changedByEmail,
                    'changedByName' => $changedByName,
                    'approvedByEmail' => $userEmail,
                    'approvedByName' => $userName,
                    'isApproved' => true,
                    'approvedDate' => gmdate(DATE_ATOM),
                ];

                $result = attendance_api_post_json('/attendance-override/upsert', ['rows' => [$payload]], 15);
                if ($result['ok']) {
                    $success = 'Override approved.';
                } else {
                    $status = $result['status'] ?? 'n/a';
                    $error = 'Approval failed (status ' . $status . ').';
                }
            }
        }
    }
}

$pending = [];
$loadError = null;

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    $filters = [
        '(d.override_work_hours IS NOT NULL OR d.override_work_code IS NOT NULL)',
        '(d.override_is_approved IS NULL OR d.override_is_approved = 0)',
        '(d.is_delete = 0 OR d.is_delete IS NULL)',
        '(d.is_deleted = 0 OR d.is_deleted IS NULL)',
        'd.att_date BETWEEN ? AND ?',
    ];
    $params = [$startDate, $endDate];
    $types = 'ss';
    if ($employeeCodeFilter !== '') {
        $filters[] = 'd.emp_code COLLATE utf8mb4_general_ci = ?';
        $params[] = $employeeCodeFilter;
        $types .= 's';
    }

    $sql = 'SELECT d.emp_code, d.att_date, d.override_work_hours, d.override_work_code, d.override_changed_by_name, ' .
        'd.override_changed_by_email, h.emp_name, h.desg_name, h.dept_name ' .
        'FROM gcc_attendance_master.employee_att_daily d ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ON h.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci ' .
        'WHERE ' . implode(' AND ', $filters) .
        ' ORDER BY d.att_date DESC, d.emp_code ASC';

    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $pending[] = $row;
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load pending approvals.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare approval query.';
    }
}

include __DIR__ . '/include/layout_top.php';

?>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Override Approvals</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_url('Attendance_AttendanceDaily.php')) ?>">Back to daily</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(admin_url('Attendance_AttendanceAdjustTime.php')) ?>">Adjust time</a>
      </div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($error): ?>
      <div class="alert alert-warning"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($loadError): ?>
      <div class="alert alert-warning"><?= h($loadError) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form method="get" class="form-row">
          <div class="form-group col-md-3">
            <label for="employeeCode">Employee code</label>
            <input id="employeeCode" name="employeeCode" class="form-control" value="<?= h($employeeCodeFilter) ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="start_date">Start date</label>
            <input id="start_date" name="start_date" type="date" class="form-control" value="<?= h($startDate) ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="end_date">End date</label>
            <input id="end_date" name="end_date" type="date" class="form-control" value="<?= h($endDate) ?>">
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Apply</button>
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-secondary btn-block" href="<?= h(admin_url('Attendance_AttendanceApproval.php')) ?>">Reset</a>
          </div>
        </form>
        <div class="small text-muted">Default week: <?= h($defaultStart) ?> to <?= h($defaultEnd) ?></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Pending overrides</h3>
        <span class="text-muted small"><?= count($pending) ?> pending</span>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-bordered table-sm">
          <thead>
            <tr>
              <th>Emp Code</th>
              <th>Emp Name</th>
              <th>Designation</th>
              <th>Department</th>
              <th>Date</th>
              <th>Override hrs</th>
              <th>Override code</th>
              <th>Requested by</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($pending)): ?>
              <?php foreach ($pending as $row): ?>
                <tr>
                  <td><?= h($row['emp_code'] ?? '-') ?></td>
                  <td><?= h($row['emp_name'] ?? '-') ?></td>
                  <td><?= h($row['desg_name'] ?? '-') ?></td>
                  <td><?= h($row['dept_name'] ?? '-') ?></td>
                  <td><?= h($row['att_date'] ?? '-') ?></td>
                  <td><?= h($row['override_work_hours'] ?? '-') ?></td>
                  <td><?= h($row['override_work_code'] ?? '-') ?></td>
                  <td><?= h(format_person($row['override_changed_by_name'] ?? '', $row['override_changed_by_email'] ?? '')) ?></td>
                  <td>
                    <form method="post" action="<?= h(admin_url('Attendance_AttendanceApproval.php') . '?' . http_build_query(['employeeCode' => $employeeCodeFilter, 'start_date' => $startDate, 'end_date' => $endDate])) ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="employeeCode" value="<?= h($row['emp_code'] ?? '') ?>">
                      <input type="hidden" name="attDate" value="<?= h($row['att_date'] ?? '') ?>">
                      <button type="submit" class="btn btn-sm btn-success">Approve</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center text-muted">No pending overrides found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
