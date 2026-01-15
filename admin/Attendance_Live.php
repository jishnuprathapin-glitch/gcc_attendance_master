<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Live Punches';

$groupBy = strtolower(trim((string) ($_GET['groupBy'] ?? 'employee')));
$allowedGroups = ['employee', 'device'];
if (!in_array($groupBy, $allowedGroups, true)) {
    $groupBy = 'employee';
}

$limit = (int) ($_GET['limit'] ?? 25);
if ($limit < 1) {
    $limit = 1;
} elseif ($limit > 200) {
    $limit = 200;
}

$departmentId = trim((string) ($_GET['departmentId'] ?? ''));
$startDate = trim((string) ($_GET['startDate'] ?? ''));
$endDate = trim((string) ($_GET['endDate'] ?? ''));

$filters = [
    'groupBy' => $groupBy,
    'limit' => $limit,
    'departmentId' => $departmentId !== '' ? $departmentId : null,
    'startDate' => $startDate !== '' ? $startDate : null,
    'endDate' => $endDate !== '' ? $endDate : null,
];

$result = attendance_api_get('attendance/latest', $filters);
$rows = [];
$error = null;
if ($result['ok']) {
    if (is_array($result['data'])) {
        $rows = $result['data'];
    }
} else {
    $error = $result['error'] ?: 'Unable to reach attendance API.';
}

function attendance_row_name(array $row): string {
    if (!empty($row['employeeName'])) {
        return (string) $row['employeeName'];
    }
    $first = trim((string) ($row['employeeFirstName'] ?? ''));
    $last = trim((string) ($row['employeeLastName'] ?? ''));
    $name = trim($first . ' ' . $last);
    return $name !== '' ? $name : '-';
}

include __DIR__ . '/include/layout_top.php';

?>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Live Punches</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <span class="badge badge-primary">Latest attendance punches</span>
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
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form method="get" class="form-row">
          <div class="form-group col-md-3">
            <label for="groupBy">Group by</label>
            <select id="groupBy" name="groupBy" class="form-control">
              <option value="employee" <?= $groupBy === 'employee' ? 'selected' : '' ?>>Employee</option>
              <option value="device" <?= $groupBy === 'device' ? 'selected' : '' ?>>Device</option>
            </select>
          </div>
          <div class="form-group col-md-2">
            <label for="limit">Limit</label>
            <input id="limit" name="limit" class="form-control" type="number" min="1" max="200" value="<?= h((string) $limit) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="departmentId">Department ID</label>
            <input id="departmentId" name="departmentId" class="form-control" value="<?= h($departmentId) ?>" placeholder="Optional">
          </div>
          <div class="form-group col-md-2">
            <label for="startDate">Start date</label>
            <input id="startDate" name="startDate" class="form-control" type="date" value="<?= h($startDate) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="endDate">End date</label>
            <input id="endDate" name="endDate" class="form-control" type="date" value="<?= h($endDate) ?>">
          </div>
          <div class="form-group col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Apply</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Latest punches</h3>
        <span class="text-muted small"><?= count($rows) ?> rows</span>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-hover table-sm">
          <thead>
            <tr>
              <th>Employee ID</th>
              <th>Badge</th>
              <th>Name</th>
              <th>Department</th>
              <th>Punch Time</th>
              <th>State</th>
              <th>Device</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h($row['employeeId'] ?? '-') ?></td>
                  <td><?= h($row['badgeNumber'] ?? '-') ?></td>
                  <td><?= h(attendance_row_name($row)) ?></td>
                  <td><?= h($row['departmentName'] ?? '-') ?></td>
                  <td><?= h($row['punchTime'] ?? '-') ?></td>
                  <td><?= h($row['punchState'] ?? '-') ?></td>
                  <td><?= h($row['terminalAlias'] ?? ($row['terminalSn'] ?? '-')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center text-muted">No punches found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
