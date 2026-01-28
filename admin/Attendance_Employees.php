<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Employees';

$employeeId = trim((string) ($_GET['employeeId'] ?? ''));
$badgeNumber = trim((string) ($_GET['badgeNumber'] ?? ''));
$departmentFilter = trim((string) ($_GET['department'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$gender = trim((string) ($_GET['gender'] ?? ''));
$isActive = trim((string) ($_GET['isActive'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'employeeId' => $employeeId !== '' ? $employeeId : null,
    'badgeNumber' => $badgeNumber !== '' ? $badgeNumber : null,
    'status' => $status !== '' ? $status : null,
    'gender' => $gender !== '' ? $gender : null,
    'isActive' => $isActive !== '' ? $isActive : null,
    'q' => $query !== '' ? $query : null,
];

$result = attendance_api_get('employees', $filters);
$rows = [];
$error = null;
if ($result['ok']) {
    if (is_array($result['data'])) {
        $rows = $result['data'];
    }
} else {
    $error = $result['error'] ?: 'Unable to reach attendance API.';
}

if (!empty($rows)) {
    usort($rows, function ($left, $right) {
        $leftCode = (string) ($left['badgeNumber'] ?? '');
        $rightCode = (string) ($right['badgeNumber'] ?? '');
        if ($leftCode === '' && $rightCode === '') {
            return 0;
        }
        if ($leftCode === '') {
            return 1;
        }
        if ($rightCode === '') {
            return -1;
        }
        $leftNum = (int) $leftCode;
        $rightNum = (int) $rightCode;
        if ($leftNum === $rightNum) {
            return strcmp($leftCode, $rightCode);
        }
        return $leftNum <=> $rightNum;
    });
}

if (!empty($rows) && $departmentFilter !== '') {
    $badgeNumbers = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $badge = trim((string) ($row['badgeNumber'] ?? ''));
        if ($badge !== '') {
            $badgeNumbers[] = $badge;
        }
    }
    $departmentMap = hrms_department_map($badgeNumbers, null, null, 12);
    $needle = strtolower($departmentFilter);
    $filteredRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $badge = trim((string) ($row['badgeNumber'] ?? ''));
        if ($badge === '') {
            continue;
        }
        $departmentName = $departmentMap[$badge] ?? '';
        if ($departmentName === '') {
            continue;
        }
        if (stripos($departmentName, $needle) === false) {
            continue;
        }
        $filteredRows[] = $row;
    }
    $rows = $filteredRows;
}

$profile = null;
if ($employeeId !== '') {
    $profileResult = attendance_api_get('employees/' . rawurlencode($employeeId));
    if ($profileResult['ok'] && is_array($profileResult['data'])) {
        $profile = $profileResult['data'];
    }
} elseif ($badgeNumber !== '') {
    $profileResult = attendance_api_get('employees/by-badge/' . rawurlencode($badgeNumber));
    if ($profileResult['ok'] && is_array($profileResult['data'])) {
        $profile = $profileResult['data'];
    }
}

$hrmsDepartment = null;
if (is_array($profile)) {
    $employeeCode = trim((string) ($profile['badgeNumber'] ?? ''));
    if ($employeeCode !== '') {
        $hrmsResult = hrms_api_get('/api/employees/' . rawurlencode($employeeCode) . '/activity');
        if ($hrmsResult['ok'] && is_array($hrmsResult['data'])) {
            $employee = $hrmsResult['data']['employee'] ?? null;
            if (is_array($employee)) {
                $department = trim((string) ($employee['DEPT_NAME'] ?? ''));
                if ($department !== '') {
                    $hrmsDepartment = $department;
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
        <h1>Employee Biometric List</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <span class="badge badge-primary">Employee lookup</span>
      </div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($error): ?>
      <div class="alert alert-warning">
        <?= h($error) ?>
        <?php if (!empty($result['url'])): ?>
          <div class="small text-muted">URL: <?= h($result['url']) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Search filters</h3>
      </div>
      <div class="card-body">
        <form method="get" class="form-row">
          <div class="form-group col-md-3">
            <label for="employeeId">Employee ID</label>
            <input id="employeeId" name="employeeId" class="form-control" value="<?= h($employeeId) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="badgeNumber">Badge number</label>
            <input id="badgeNumber" name="badgeNumber" class="form-control" value="<?= h($badgeNumber) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="department">Department (HRMS)</label>
            <input id="department" name="department" class="form-control" value="<?= h($departmentFilter) ?>" placeholder="HRMS department name">
          </div>
          <div class="form-group col-md-2">
            <label for="gender">Gender</label>
            <select id="gender" name="gender" class="form-control">
              <option value="">Any</option>
              <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
              <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>
          <div class="form-group col-md-2">
            <label for="isActive">Active</label>
            <select id="isActive" name="isActive" class="form-control">
              <option value="">Any</option>
              <option value="true" <?= $isActive === 'true' ? 'selected' : '' ?>>Active</option>
              <option value="false" <?= $isActive === 'false' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
          <div class="form-group col-md-1">
            <label for="status">Status</label>
            <input id="status" name="status" class="form-control" value="<?= h($status) ?>">
          </div>
          <div class="form-group col-md-6">
            <label for="q">Search keyword</label>
            <input id="q" name="q" class="form-control" value="<?= h($query) ?>" placeholder="Name, designation">
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Search</button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($profile): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Employee profile</h3>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <dl class="row mb-0">
                <dt class="col-sm-5">Employee ID</dt>
                <dd class="col-sm-7"><?= h($profile['employeeId'] ?? '-') ?></dd>
                <dt class="col-sm-5">Badge</dt>
                <dd class="col-sm-7"><?= h($profile['badgeNumber'] ?? '-') ?></dd>
                <dt class="col-sm-5">Name</dt>
                <dd class="col-sm-7"><?= h(trim(($profile['firstName'] ?? '') . ' ' . ($profile['lastName'] ?? '')) ?: '-') ?></dd>
                <dt class="col-sm-5">Department (HRMS)</dt>
                <dd class="col-sm-7"><?= h($hrmsDepartment ?? '-') ?></dd>
                <dt class="col-sm-5">Designation</dt>
                <dd class="col-sm-7"><?= h($profile['designation'] ?? '-') ?></dd>
              </dl>
            </div>
            <div class="col-md-6">
              <dl class="row mb-0">
                <dt class="col-sm-5">Location</dt>
                <dd class="col-sm-7"><?= h($profile['locationName'] ?? '-') ?></dd>
                <dt class="col-sm-5">Position</dt>
                <dd class="col-sm-7"><?= h($profile['positionName'] ?? '-') ?></dd>
                <dt class="col-sm-5">Gender</dt>
                <dd class="col-sm-7"><?= h($profile['gender'] ?? '-') ?></dd>
                <dt class="col-sm-5">Hire date</dt>
                <dd class="col-sm-7"><?= h($profile['hireDate'] ?? '-') ?></dd>
                <dt class="col-sm-5">Active</dt>
                <dd class="col-sm-7"><?= !empty($profile['isActive']) ? 'Yes' : 'No' ?></dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Employees</h3>
        <span class="text-muted small"><?= count($rows) ?> results</span>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-hover table-sm">
          <thead>
            <tr>
              <th>UtimeId</th>
              <th>Employee Code</th>
              <th>Name</th>
              <th>Active</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h($row['employeeId'] ?? '-') ?></td>
                  <td><?= h($row['badgeNumber'] ?? '-') ?></td>
                  <td><?= h(trim(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? '')) ?: '-') ?></td>
                  <td><?= !empty($row['isActive']) ? 'Yes' : 'No' ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="4" class="text-center text-muted">No employees found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
