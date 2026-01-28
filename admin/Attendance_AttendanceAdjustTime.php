<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Adjust Attendance Time';

$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$success = null;
$error = null;

function normalize_post_date(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Invalid request token.';
    } else {
        $employeeCode = trim((string) ($_POST['employeeCode'] ?? ''));
        $attDate = normalize_post_date($_POST['attDate'] ?? null);
        $workHoursRaw = trim((string) ($_POST['workHours'] ?? ''));
        $workCode = trim((string) ($_POST['workCode'] ?? ''));

        if ($employeeCode === '' || $attDate === null) {
            $error = 'Employee code and attendance date are required.';
        } elseif ($userName === '' || $userEmail === '') {
            $error = 'User name/email missing in session.';
        } else {
            $workHours = null;
            if ($workHoursRaw !== '') {
                if (!is_numeric($workHoursRaw)) {
                    $error = 'Work hours must be numeric.';
                } else {
                    $workHours = (float) $workHoursRaw;
                }
            }

            if ($error === null) {
                $row = [
                    'employeeCode' => $employeeCode,
                    'attDate' => $attDate,
                    'workHours' => $workHours,
                    'workCode' => $workCode !== '' ? $workCode : null,
                    'changeDate' => gmdate(DATE_ATOM),
                    'changedByEmail' => $userEmail,
                    'changedByName' => $userName,
                ];

                $result = attendance_api_post_json('/attendance-override/upsert', ['rows' => [$row]], 15);
                if ($result['ok']) {
                    $success = 'Override submitted successfully.';
                } else {
                    $status = $result['status'] ?? 'n/a';
                    $error = 'Override request failed (status ' . $status . ').';
                }
            }
        }
    }
}

include __DIR__ . '/include/layout_top.php';

?>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Adjust Attendance Time</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_url('Attendance_AttendanceDaily.php')) ?>">Back to daily</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(admin_url('Attendance_AttendanceApproval.php')) ?>">Approvals</a>
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

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Submit override</h3>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <div class="form-row">
            <div class="form-group col-md-3">
              <label for="employeeCode">Employee code</label>
              <input id="employeeCode" name="employeeCode" class="form-control" required>
            </div>
            <div class="form-group col-md-3">
              <label for="attDate">Attendance date</label>
              <input id="attDate" name="attDate" type="date" class="form-control" required>
            </div>
            <div class="form-group col-md-3">
              <label for="workHours">Override work hours</label>
              <input id="workHours" name="workHours" class="form-control" placeholder="e.g. 8.00">
            </div>
            <div class="form-group col-md-3">
              <label for="workCode">Override work code (optional)</label>
              <input id="workCode" name="workCode" class="form-control" placeholder="Work code">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Submit override</button>
          <span class="text-muted ml-2 small">Overrides are sent via Attendance API.</span>
        </form>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
