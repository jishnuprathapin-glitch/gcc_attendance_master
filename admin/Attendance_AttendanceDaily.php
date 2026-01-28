<?php

require __DIR__ . '/include/bootstrap.php';

$page_title = 'Attendance Daily';

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

function build_date_range(string $start, string $end): array {
    $dates = [];
    try {
        $cursor = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
    } catch (Exception $e) {
        return $dates;
    }
    if ($cursor > $last) {
        return $dates;
    }
    while ($cursor <= $last) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

function format_date_label(string $date): string {
    try {
        $dt = new DateTimeImmutable($date);
    } catch (Exception $e) {
        return $date;
    }
    return $dt->format('D, d M');
}

function format_time_value(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return $value;
    }
    return $dt->format('H:i');
}

function calculate_work_hours(?string $start, ?string $end): ?string {
    $start = trim((string) $start);
    $end = trim((string) $end);
    if ($start === '' || $end === '') {
        return null;
    }
    try {
        $startDt = new DateTimeImmutable($start);
        $endDt = new DateTimeImmutable($end);
    } catch (Exception $e) {
        return null;
    }
    $diff = $endDt->getTimestamp() - $startDt->getTimestamp();
    if ($diff < 0) {
        return null;
    }
    $hours = $diff / 3600;
    return number_format($hours, 2, '.', '');
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

function build_query_url(array $params): string {
    $base = admin_url('Attendance_AttendanceDaily.php');
    $query = http_build_query($params);
    if ($query === '') {
        return $base;
    }
    return $base . '?' . $query;
}

function build_page_window(int $current, int $total, int $radius = 2): array {
    if ($total <= 1) {
        return [1];
    }
    if ($total <= 7) {
        return range(1, $total);
    }
    $pages = [1];
    $start = max(2, $current - $radius);
    $end = min($total - 1, $current + $radius);
    if ($start > 2) {
        $pages[] = '...';
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < ($total - 1)) {
        $pages[] = '...';
    }
    $pages[] = $total;
    return $pages;
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

[$defaultStart, $defaultEnd] = current_week_range();
$today = new DateTimeImmutable('today');
$last30Start = $today->modify('-29 days')->format('Y-m-d');
$last30End = $today->format('Y-m-d');
$prevWeekStart = (new DateTimeImmutable($defaultStart))->modify('-7 days')->format('Y-m-d');
$prevWeekEnd = (new DateTimeImmutable($defaultEnd))->modify('-7 days')->format('Y-m-d');

$designationFilter = trim((string) ($_GET['designation'] ?? ''));
$departmentFilter = trim((string) ($_GET['department'] ?? ''));
$projectCodeFilter = trim((string) ($_GET['project_code'] ?? ''));
$startDate = normalize_date($_GET['start_date'] ?? '', $defaultStart);
$endDate = normalize_date($_GET['end_date'] ?? '', $defaultEnd);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

if ($startDate > $endDate) {
    $swap = $startDate;
    $startDate = $endDate;
    $endDate = $swap;
}

$dateRange = build_date_range($startDate, $endDate);

$employees = [];
$dailyPunch = [];
$attDaily = [];
$deviceProjectMap = [];
$departmentOptions = [];
$designationOptions = [];
$projectOptions = [];
$offset = 0;
$totalEmployees = 0;
$totalPages = 1;
$loadError = null;

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    $deptResult = $bd->query(
        'SELECT dept_cd, dept_name FROM gcc_attendance_master.hrms_departments ORDER BY dept_name, dept_cd'
    );
    if ($deptResult) {
        while ($row = $deptResult->fetch_assoc()) {
            $code = trim((string) ($row['dept_cd'] ?? ''));
            if ($code === '') {
                continue;
            }
            $departmentOptions[$code] = trim((string) ($row['dept_name'] ?? ''));
        }
        $deptResult->free();
    }

    $desgResult = $bd->query(
        'SELECT desg_cd, desg_name FROM gcc_attendance_master.hrms_designations ORDER BY desg_name, desg_cd'
    );
    if ($desgResult) {
        while ($row = $desgResult->fetch_assoc()) {
            $code = trim((string) ($row['desg_cd'] ?? ''));
            if ($code === '') {
                continue;
            }
            $designationOptions[$code] = trim((string) ($row['desg_name'] ?? ''));
        }
        $desgResult->free();
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

    $filters = ['hr.is_deleted = 0', 'hr.st_code = "A"'];
    $params = [];
    $types = '';

    if ($designationFilter !== '') {
        $filters[] = 'hr.desg_cd = ?';
        $params[] = $designationFilter;
        $types .= 's';
    }
    if ($departmentFilter !== '') {
        $filters[] = 'hr.dept_cd = ?';
        $params[] = $departmentFilter;
        $types .= 's';
    }
    if ($projectCodeFilter !== '') {
        $filters[] = 'hr.jbno = ?';
        $params[] = $projectCodeFilter;
        $types .= 's';
    }

    $countSql = 'SELECT COUNT(*) AS total ' .
        'FROM gcc_attendance_master.hrmsvw_sync hr';
    if (!empty($filters)) {
        $countSql .= ' WHERE ' . implode(' AND ', $filters);
    }

    $countStmt = $bd->prepare($countSql);
    if ($countStmt) {
        bind_params($countStmt, $types, $params);
        if ($countStmt->execute()) {
            $result = $countStmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                if ($row && isset($row['total'])) {
                    $totalEmployees = (int) $row['total'];
                }
                $result->free();
            }
        }
        $countStmt->close();
    }

    $totalPages = max(1, (int) ceil(max(0, $totalEmployees) / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = max(0, ($page - 1) * $perPage);

    $sql = 'SELECT hr.emp_code, ' .
        'COALESCE(NULLIF(hr.emp_name, ""), NULLIF(hr.name, "")) AS emp_name, ' .
        'hr.desg_name, hr.dept_name, hr.ty_desc, hr.jbno, hr.jbdesc ' .
        'FROM gcc_attendance_master.hrmsvw_sync hr';
    if (!empty($filters)) {
        $sql .= ' WHERE ' . implode(' AND ', $filters);
    }
    $sql .= ' ORDER BY CAST(hr.emp_code AS UNSIGNED), hr.emp_code LIMIT ? OFFSET ?';

    $listParams = $params;
    $listTypes = $types;
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $listTypes .= 'ii';

    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $listTypes, $listParams);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $employees[] = $row;
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load employees.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare employee query.';
    }

    if (!$loadError && !empty($employees) && !empty($dateRange)) {
        $empCodes = [];
        foreach ($employees as $row) {
            $code = trim((string) ($row['emp_code'] ?? ''));
            if ($code !== '') {
                $empCodes[] = $code;
            }
        }
        $empCodes = array_values(array_unique($empCodes, SORT_STRING));

        if (!empty($empCodes)) {
            $placeholders = implode(',', array_fill(0, count($empCodes), '?'));
            $rangeTypes = str_repeat('s', count($empCodes)) . 'ss';
            $rangeParams = array_merge($empCodes, [$startDate, $endDate]);

            $punchSql = 'SELECT emp_code, punch_date, first_log, last_log, first_terminal_sn, last_terminal_sn ' .
                'FROM gcc_attendance_master.employee_daily_punch ' .
                'WHERE emp_code IN (' . $placeholders . ') AND punch_date BETWEEN ? AND ?';
            $stmt = $bd->prepare($punchSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['punch_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($dailyPunch[$emp])) {
                                $dailyPunch[$emp] = [];
                            }
                            $dailyPunch[$emp][$date] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $attSql = 'SELECT emp_code, att_date, work_code, pending_leave_code, override_work_hours ' .
                'FROM gcc_attendance_master.employee_att_daily ' .
                'WHERE emp_code IN (' . $placeholders . ') AND att_date BETWEEN ? AND ? ' .
                'AND (is_delete = 0 OR is_delete IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)';
            $stmt = $bd->prepare($attSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['att_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($attDaily[$emp])) {
                                $attDaily[$emp] = [];
                            }
                            $attDaily[$emp][$date] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $deviceSn = [];
            foreach ($dailyPunch as $emp => $dates) {
                foreach ($dates as $date => $row) {
                    $sn = trim((string) ($row['first_terminal_sn'] ?? ''));
                    if ($sn !== '') {
                        $deviceSn[$sn] = true;
                    }
                }
            }
            if (!empty($deviceSn)) {
                $deviceList = array_keys($deviceSn);
                $devicePlaceholders = implode(',', array_fill(0, count($deviceList), '?'));
                $deviceTypes = str_repeat('s', count($deviceList));
                $deviceSql = 'SELECT d.device_sn, p.pro_code ' .
                    'FROM gcc_attendance_master.device_project_map d ' .
                    'LEFT JOIN gcc_it.projects p ON p.id = d.project_id ' .
                    'WHERE d.device_sn IN (' . $devicePlaceholders . ')';
                $stmt = $bd->prepare($deviceSql);
                if ($stmt) {
                    bind_params($stmt, $deviceTypes, $deviceList);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $sn = trim((string) ($row['device_sn'] ?? ''));
                                if ($sn === '') {
                                    continue;
                                }
                                $deviceProjectMap[$sn] = trim((string) ($row['pro_code'] ?? ''));
                            }
                            $result->free();
                        }
                    }
                    $stmt->close();
                }
            }
        }
    }
}

if ($totalEmployees === 0 && !empty($employees)) {
    $totalEmployees = count($employees);
    $totalPages = 1;
    $page = 1;
    $offset = 0;
}

$baseQuery = [
    'designation' => $designationFilter,
    'department' => $departmentFilter,
    'project_code' => $projectCodeFilter,
    'start_date' => $startDate,
    'end_date' => $endDate,
];
$currentWeekUrl = build_query_url(array_merge($baseQuery, [
    'start_date' => $defaultStart,
    'end_date' => $defaultEnd,
    'page' => 1,
]));
$previousWeekUrl = build_query_url(array_merge($baseQuery, [
    'start_date' => $prevWeekStart,
    'end_date' => $prevWeekEnd,
    'page' => 1,
]));
$last30DaysUrl = build_query_url(array_merge($baseQuery, [
    'start_date' => $last30Start,
    'end_date' => $last30End,
    'page' => 1,
]));
$isCurrentWeek = ($startDate === $defaultStart && $endDate === $defaultEnd);
$isPrevWeek = ($startDate === $prevWeekStart && $endDate === $prevWeekEnd);
$isLast30Days = ($startDate === $last30Start && $endDate === $last30End);
$pageLinks = build_page_window($page, $totalPages);
$showingStart = $totalEmployees > 0 ? ($offset + 1) : 0;
$showingEnd = $totalEmployees > 0 ? min($offset + count($employees), $totalEmployees) : 0;
$prefetchUrls = [];
if ($totalPages > 1) {
    if ($page > 1) {
        $prefetchUrls[] = build_query_url(array_merge($baseQuery, ['page' => $page - 1]));
    }
    if ($page < $totalPages) {
        $prefetchUrls[] = build_query_url(array_merge($baseQuery, ['page' => $page + 1]));
    }
}

include __DIR__ . '/include/layout_top.php';

?>

<link rel="stylesheet" href="plugins/select2/css/select2.min.css">
<link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">

<style>
  .attendance-daily-table th,
  .attendance-daily-table td {
    white-space: nowrap;
    vertical-align: top;
  }
  .attendance-daily-table thead th {
    background: #f8f9fa;
  }
  .attendance-daily-table .date-header {
    font-size: 0.85rem;
  }
  .attendance-daily-table .sub-header {
    font-size: 0.75rem;
    font-weight: 600;
  }
  .select2-container {
    width: 100% !important;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Attendance Daily</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <a class="btn btn-sm btn-outline-primary" href="<?= h(admin_url('Attendance_AttendanceAdjustTime.php')) ?>">Adjust time</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_url('Attendance_AttendanceApproval.php')) ?>">Approvals</a>
      </div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form method="get" class="form-row">
          <div class="form-group col-12">
            <label class="d-block">Quick ranges</label>
            <div class="btn-group btn-group-sm" role="group" aria-label="Quick ranges">
              <a class="btn <?= $isCurrentWeek ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($currentWeekUrl) ?>">Current week</a>
              <a class="btn <?= $isPrevWeek ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($previousWeekUrl) ?>">Previous week</a>
              <a class="btn <?= $isLast30Days ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($last30DaysUrl) ?>">Last 30 days</a>
            </div>
          </div>
          <div class="form-group col-md-3">
            <label for="designation">Designation</label>
            <select id="designation" name="designation" class="form-control js-searchable" data-placeholder="All">
              <option value="">All</option>
              <?php foreach ($designationOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= $designationFilter === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="department">Department</label>
            <select id="department" name="department" class="form-control js-searchable" data-placeholder="All">
              <option value="">All</option>
              <?php foreach ($departmentOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= $departmentFilter === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-2">
            <label for="project_code">Project code</label>
            <select id="project_code" name="project_code" class="form-control js-searchable" data-placeholder="All">
              <option value="">All</option>
              <?php foreach ($projectOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= $projectCodeFilter === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-2">
            <label for="start_date">Start date</label>
            <input id="start_date" type="date" name="start_date" class="form-control" value="<?= h($startDate) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="end_date">End date</label>
            <input id="end_date" type="date" name="end_date" class="form-control" value="<?= h($endDate) ?>">
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Apply</button>
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-secondary btn-block" href="<?= h(admin_url('Attendance_AttendanceDaily.php')) ?>">Reset</a>
          </div>
        </form>
        <div class="small text-muted">
          Default week: <?= h($defaultStart) ?> to <?= h($defaultEnd) ?> |
          Showing <?= $showingStart ?>-<?= $showingEnd ?> of <?= $totalEmployees ?> employee(s) |
          Page <?= $page ?> of <?= $totalPages ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Weekly attendance</h3>
        <span class="text-muted small"><?= $showingStart ?>-<?= $showingEnd ?> of <?= $totalEmployees ?> employees | <?= count($dateRange) ?> day(s)</span>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="card-body py-2 d-flex justify-content-between align-items-center">
          <div class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($page > 1 ? build_query_url(array_merge($baseQuery, ['page' => $page - 1])) : '#') ?>">Previous 50</a>
              </li>
              <?php foreach ($pageLinks as $link): ?>
                <?php if ($link === '...'): ?>
                  <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php else: ?>
                  <li class="page-item <?= $link === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= h(build_query_url(array_merge($baseQuery, ['page' => $link]))) ?>"><?= h($link) ?></a>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($page < $totalPages ? build_query_url(array_merge($baseQuery, ['page' => $page + 1])) : '#') ?>">Next 50</a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
      <div class="card-body table-responsive p-0">
        <table class="table table-bordered table-sm attendance-daily-table">
          <thead>
            <tr>
              <th rowspan="2">Emp Code</th>
              <th rowspan="2">Emp Name</th>
              <th rowspan="2">Designation</th>
              <th rowspan="2">Department</th>
              <th rowspan="2">Employee Type</th>
              <th rowspan="2">Project Code</th>
              <?php foreach ($dateRange as $date): ?>
                <th colspan="7" class="text-center date-header"><?= h(format_date_label($date)) ?></th>
              <?php endforeach; ?>
            </tr>
            <tr>
              <?php foreach ($dateRange as $date): ?>
                <th class="sub-header">Project login (U)</th>
                <th class="sub-header">Leave code (H)</th>
                <th class="sub-header">Work code (W)</th>
                <th class="sub-header">Login</th>
                <th class="sub-header">Logout</th>
                <th class="sub-header">Work hrs</th>
                <th class="sub-header">Override hrs</th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($employees) && !empty($dateRange)): ?>
              <?php foreach ($employees as $employee): ?>
                <?php
                  $empCode = trim((string) ($employee['emp_code'] ?? ''));
                  $empName = trim((string) ($employee['emp_name'] ?? ''));
                  $designation = trim((string) ($employee['desg_name'] ?? ''));
                  $department = trim((string) ($employee['dept_name'] ?? ''));
                  $employeeType = trim((string) ($employee['ty_desc'] ?? ''));
                  $projectCode = trim((string) ($employee['jbno'] ?? ''));
                ?>
                <tr>
                  <td><?= h($empCode !== '' ? $empCode : '-') ?></td>
                  <td><?= h($empName !== '' ? $empName : '-') ?></td>
                  <td><?= h($designation !== '' ? $designation : '-') ?></td>
                  <td><?= h($department !== '' ? $department : '-') ?></td>
                  <td><?= h($employeeType !== '' ? $employeeType : '-') ?></td>
                  <td><?= h($projectCode !== '' ? $projectCode : '-') ?></td>
                  <?php foreach ($dateRange as $date): ?>
                    <?php
                      $punch = ($empCode !== '' && isset($dailyPunch[$empCode][$date])) ? $dailyPunch[$empCode][$date] : null;
                      $att = ($empCode !== '' && isset($attDaily[$empCode][$date])) ? $attDaily[$empCode][$date] : null;

                      $firstLog = is_array($punch) ? ($punch['first_log'] ?? null) : null;
                      $lastLog = is_array($punch) ? ($punch['last_log'] ?? null) : null;
                      $firstSn = is_array($punch) ? trim((string) ($punch['first_terminal_sn'] ?? '')) : '';
                      $loginProject = $firstSn !== '' ? trim((string) ($deviceProjectMap[$firstSn] ?? '')) : '';
                      $leaveCode = is_array($att) ? trim((string) ($att['pending_leave_code'] ?? '')) : '';
                      $workCode = is_array($att) ? trim((string) ($att['work_code'] ?? '')) : '';
                      $workHours = calculate_work_hours($firstLog, $lastLog);
                      $overrideHours = is_array($att) ? trim((string) ($att['override_work_hours'] ?? '')) : '';
                    ?>
                    <td><?= h($loginProject !== '' ? $loginProject : '-') ?></td>
                    <td><?= h($leaveCode !== '' ? $leaveCode : '-') ?></td>
                    <td><?= h($workCode !== '' ? $workCode : '-') ?></td>
                    <td><?= h(format_time_value($firstLog)) ?></td>
                    <td><?= h(format_time_value($lastLog)) ?></td>
                    <td><?= h($workHours !== null ? $workHours : '-') ?></td>
                    <td><?= h($overrideHours !== '' ? $overrideHours : '-') ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="<?= 6 + (count($dateRange) * 7) ?>" class="text-center text-muted">No employees found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <div class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($page > 1 ? build_query_url(array_merge($baseQuery, ['page' => $page - 1])) : '#') ?>">Previous 50</a>
              </li>
              <?php foreach ($pageLinks as $link): ?>
                <?php if ($link === '...'): ?>
                  <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php else: ?>
                  <li class="page-item <?= $link === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= h(build_query_url(array_merge($baseQuery, ['page' => $link]))) ?>"><?= h($link) ?></a>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($page < $totalPages ? build_query_url(array_merge($baseQuery, ['page' => $page + 1])) : '#') ?>">Next 50</a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<script defer src="plugins/select2/js/select2.full.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
      return;
    }
    jQuery('.js-searchable').select2({
      theme: 'bootstrap4',
      width: '100%',
      allowClear: true,
      placeholder: 'All',
      minimumResultsForSearch: 0,
    });
  });
</script>
<script>
  window.addEventListener('load', function () {
    const urls = <?= json_encode(array_values($prefetchUrls)) ?>;
    if (!urls || !urls.length || typeof fetch !== 'function') {
      return;
    }
    const schedule = window.requestIdleCallback || function (cb) { setTimeout(cb, 500); };
    schedule(function () {
      urls.forEach(function (url) {
        try {
          fetch(url, { credentials: 'same-origin' });
        } catch (e) {
          // Ignore prefetch failures.
        }
      });
    });
  });
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
