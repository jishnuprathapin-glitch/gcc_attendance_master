
<?php
require __DIR__ . '/include/bootstrap.php';

$page_title = 'HR Insights Dashboard';
$flash = get_flash();

function normalize_date(?string $value, string $fallback): string {
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function parse_time_minutes(?string $value): ?int {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
    try {
        $tzName = date_default_timezone_get() ?: 'UTC';
        $tz = new DateTimeZone($tzName);
        $dt = $dt->setTimezone($tz);
    } catch (Exception $e) {
        // Keep original timezone.
    }
    return ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
}

function format_time_minutes(?int $minutes): string {
    if ($minutes === null) {
        return '-';
    }
    $hours = (int) floor($minutes / 60);
    $mins = (int) ($minutes % 60);
    $dt = new DateTimeImmutable('today');
    $dt = $dt->setTime($hours, $mins);
    return $dt->format('h:i A');
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

function db_fetch_all(mysqli $bd, string $sql, string $types = '', array $params = []): array {
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'rows' => [], 'error' => $bd->error ?: 'prepare_failed'];
    }
    if ($types !== '' && !empty($params)) {
        bind_params($stmt, $types, $params);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error ?: 'execute_failed';
        $stmt->close();
        return ['ok' => false, 'rows' => [], 'error' => $error];
    }
    $rows = [];
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
    $stmt->close();
    return ['ok' => true, 'rows' => $rows, 'error' => null];
}

function build_date_series(string $startDate, string $endDate): array {
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end) {
        return [];
    }
    if ($start > $end) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    $dates = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

$today = new DateTimeImmutable('today');
$defaultEnd = $today->format('Y-m-d');
$defaultStart = $today->modify('-6 days')->format('Y-m-d');
$startDate = normalize_date($_GET['startDate'] ?? null, $defaultStart);
$endDate = normalize_date($_GET['endDate'] ?? null, $defaultEnd);
if ($startDate > $endDate) {
    $tmp = $startDate;
    $startDate = $endDate;
    $endDate = $tmp;
}
$deviceSnInput = trim((string) ($_GET['deviceSn'] ?? ''));
$deviceSnList = [];
if ($deviceSnInput !== '') {
    foreach (explode(',', $deviceSnInput) as $sn) {
        $sn = trim($sn);
        if ($sn !== '') {
            $deviceSnList[] = $sn;
        }
    }
}
$deviceSnParam = !empty($deviceSnList) ? implode(',', $deviceSnList) : '';

$isAjax = ($_GET['ajax'] ?? '') === '1';
$ajaxSection = strtolower(trim((string) ($_GET['ajax_section'] ?? '')));

if ($isAjax && $ajaxSection === 'summary') {
    $errors = [];
    $activeCount = null;
    $loggedCount = 0;
    $deviceOnline = null;
    $deviceTotal = null;

    $activeResult = db_fetch_all($bd, 'SELECT COUNT(*) AS total FROM gcc_attendance_master.hrmsvw_sync');
    if ($activeResult['ok'] && !empty($activeResult['rows'])) {
        $activeCount = (int) ($activeResult['rows'][0]['total'] ?? 0);
    } else {
        $errors[] = 'Employee roster';
    }

    if (!empty($deviceSnList)) {
        $deviceTotal = count($deviceSnList);
    } else {
        $deviceTotalResult = db_fetch_all($bd, 'SELECT COUNT(*) AS total FROM gcc_attendance_master.device_project_map');
        if ($deviceTotalResult['ok'] && !empty($deviceTotalResult['rows'])) {
            $deviceTotal = (int) ($deviceTotalResult['rows'][0]['total'] ?? 0);
        }
    }

    $deviceParams = [$startDate, $endDate, $startDate, $endDate];
    $deviceTypes = 'ssss';
    $firstDeviceClause = '';
    $lastDeviceClause = '';
    if ($deviceSnParam !== '') {
        $firstDeviceClause = ' AND FIND_IN_SET(first_terminal_sn, ?)';
        $lastDeviceClause = ' AND FIND_IN_SET(last_terminal_sn, ?)';
        $deviceParams[] = $deviceSnParam;
        $deviceParams[] = $deviceSnParam;
        $deviceTypes .= 'ss';
    }
    $deviceActiveSql = 'SELECT COUNT(DISTINCT device_sn) AS total FROM (' .
        'SELECT first_terminal_sn AS device_sn FROM gcc_attendance_master.employee_daily_punch ' .
        'WHERE punch_date BETWEEN ? AND ? AND first_terminal_sn IS NOT NULL' . $firstDeviceClause .
        ' UNION ALL ' .
        'SELECT last_terminal_sn AS device_sn FROM gcc_attendance_master.employee_daily_punch ' .
        'WHERE punch_date BETWEEN ? AND ? AND last_terminal_sn IS NOT NULL' . $lastDeviceClause .
        ') t';
    $deviceActiveResult = db_fetch_all($bd, $deviceActiveSql, $deviceTypes, $deviceParams);
    if ($deviceActiveResult['ok'] && !empty($deviceActiveResult['rows'])) {
        $deviceOnline = (int) ($deviceActiveResult['rows'][0]['total'] ?? 0);
    } else {
        $errors[] = 'Device punches';
    }

    $pulseDate = $endDate;
    $punchWhere = 'p.punch_date = ? AND (p.first_log IS NOT NULL OR p.last_log IS NOT NULL)';
    $punchTypes = 's';
    $punchParams = [$pulseDate];
    if ($deviceSnParam !== '') {
        $punchWhere .= ' AND (FIND_IN_SET(p.first_terminal_sn, ?) OR FIND_IN_SET(p.last_terminal_sn, ?))';
        $punchParams[] = $deviceSnParam;
        $punchParams[] = $deviceSnParam;
        $punchTypes .= 'ss';
    }

    $projectSelect = 'COALESCE(NULLIF(pf.pro_code, \'\'), NULLIF(pl.pro_code, \'\'), NULLIF(d.job, \'\'), NULLIF(h.jbno, \'\')) AS project_code';
    $projectTypes = '';
    $projectParams = [];
    if ($deviceSnParam !== '') {
        $projectSelect = 'COALESCE(' .
            'NULLIF(IF(FIND_IN_SET(p.first_terminal_sn, ?), pf.pro_code, \'\'), \'\'), ' .
            'NULLIF(IF(FIND_IN_SET(p.last_terminal_sn, ?), pl.pro_code, \'\'), \'\'), ' .
            'NULLIF(d.job, \'\'), NULLIF(h.jbno, \'\')) AS project_code';
        $projectTypes = 'ss';
        $projectParams = [$deviceSnParam, $deviceSnParam];
    }

    $badgeSql = 'SELECT p.emp_code, p.first_log, p.last_log, p.first_terminal_sn, p.last_terminal_sn, ' .
        $projectSelect . ', ' .
        'COALESCE(NULLIF(h.emp_name, \'\'), NULLIF(h.name, \'\')) AS emp_name ' .
        'FROM gcc_attendance_master.employee_daily_punch p ' .
        'LEFT JOIN gcc_attendance_master.device_project_map df ON df.device_sn = p.first_terminal_sn ' .
        'LEFT JOIN gcc_it.projects pf ON pf.id = df.project_id ' .
        'LEFT JOIN gcc_attendance_master.device_project_map dl ON dl.device_sn = p.last_terminal_sn ' .
        'LEFT JOIN gcc_it.projects pl ON pl.id = dl.project_id ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily d ' .
        'ON d.emp_code COLLATE utf8mb4_general_ci = p.emp_code COLLATE utf8mb4_general_ci ' .
        'AND d.att_date = p.punch_date ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ' .
        'ON h.emp_code COLLATE utf8mb4_general_ci = p.emp_code COLLATE utf8mb4_general_ci ' .
        'WHERE ' . $punchWhere;
    $badgeResult = db_fetch_all($bd, $badgeSql, $projectTypes . $punchTypes, array_merge($projectParams, $punchParams));
    $badgeRows = $badgeResult['ok'] ? $badgeResult['rows'] : [];
    if (!$badgeResult['ok']) {
        $errors[] = 'Daily punches';
    }

    $badgeSampleCount = count($badgeRows);
    $badgeTotal = $badgeSampleCount;
    $badgeSampled = false;

    $lateThreshold = 10 * 60;
    $earlyLeaveThreshold = 16 * 60;
    $overtimeThreshold = 19 * 60;

    $lateCount = 0;
    $earlyLeaveCount = 0;
    $overtimeCount = 0;
    $firstMinutes = [];
    $lastMinutes = [];
    $arrivalBuckets = [
        'Before 9' => 0,
        '9-10' => 0,
        '10-11' => 0,
        'After 11' => 0,
    ];
    $completionCount = 0;

    $projectCounts = [];
    $recentRows = [];
    $uniqueEmployees = [];

    foreach ($badgeRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $empCode = trim((string) ($row['emp_code'] ?? ''));
        if ($empCode !== '') {
            $uniqueEmployees[$empCode] = true;
        }
        $firstMinutesValue = parse_time_minutes($row['first_log'] ?? null);
        $lastMinutesValue = parse_time_minutes($row['last_log'] ?? null);

        if ($firstMinutesValue !== null) {
            $firstMinutes[] = $firstMinutesValue;
            if ($firstMinutesValue > $lateThreshold) {
                $lateCount++;
            }
            if ($firstMinutesValue < 9 * 60) {
                $arrivalBuckets['Before 9']++;
            } elseif ($firstMinutesValue < 10 * 60) {
                $arrivalBuckets['9-10']++;
            } elseif ($firstMinutesValue < 11 * 60) {
                $arrivalBuckets['10-11']++;
            } else {
                $arrivalBuckets['After 11']++;
            }
        }
        if ($lastMinutesValue !== null) {
            $lastMinutes[] = $lastMinutesValue;
            if ($lastMinutesValue < $earlyLeaveThreshold) {
                $earlyLeaveCount++;
            }
            if ($lastMinutesValue > $overtimeThreshold) {
                $overtimeCount++;
            }
        }
        if ($firstMinutesValue !== null && $lastMinutesValue !== null) {
            $completionCount++;
        }

        $project = trim((string) ($row['project_code'] ?? ''));
        if ($project === '') {
            $project = 'Unassigned';
        }
        if (!isset($projectCounts[$project])) {
            $projectCounts[$project] = 0;
        }
        $projectCounts[$project]++;

        $lastTime = $row['last_log'] ?? $row['first_log'] ?? null;
        $timestamp = null;
        if ($lastTime) {
            try {
                $timestamp = (new DateTimeImmutable($lastTime))->getTimestamp();
            } catch (Exception $e) {
                $timestamp = null;
            }
        }
        $recentRows[] = [
            'badge' => $empCode,
            'name' => trim((string) ($row['emp_name'] ?? '')),
            'project' => $project,
            'time' => $lastTime ? (string) $lastTime : '',
            'timestamp' => $timestamp ?? 0,
        ];
    }

    $loggedCount = count($uniqueEmployees);

    arsort($projectCounts);
    $projectLabels = [];
    $projectValues = [];
    $projectIndex = 0;
    $otherCount = 0;
    foreach ($projectCounts as $label => $count) {
        if ($projectIndex < 6) {
            $projectLabels[] = $label;
            $projectValues[] = $count;
        } else {
            $otherCount += $count;
        }
        $projectIndex++;
    }
    if ($otherCount > 0) {
        $projectLabels[] = 'Other';
        $projectValues[] = $otherCount;
    }

    usort($recentRows, function (array $a, array $b): int {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });
    $recentRows = array_slice($recentRows, 0, 6);

    $avgFirst = null;
    if (!empty($firstMinutes)) {
        $avgFirst = (int) round(array_sum($firstMinutes) / count($firstMinutes));
    }
    $avgLast = null;
    if (!empty($lastMinutes)) {
        $avgLast = (int) round(array_sum($lastMinutes) / count($lastMinutes));
    }

    $completionRate = null;
    if ($badgeSampleCount > 0) {
        $completionRate = (int) round(($completionCount / $badgeSampleCount) * 100);
    }

    $coveragePercent = null;
    if ($activeCount !== null && $activeCount > 0) {
        $coveragePercent = (int) round(($loggedCount / $activeCount) * 100);
    }

    $payload = [
        'ok' => true,
        'errors' => array_values(array_unique($errors)),
        'meta' => [
            'pulseDate' => $pulseDate,
            'sampleCount' => $badgeSampleCount,
            'totalCount' => $badgeTotal,
            'sampled' => $badgeSampled,
        ],
        'kpis' => [
            'activeCount' => $activeCount,
            'loggedCount' => $loggedCount,
            'coveragePercent' => $coveragePercent,
            'deviceOnline' => $deviceOnline,
            'deviceTotal' => $deviceTotal,
        ],
        'timeMetrics' => [
            'lateCount' => $lateCount,
            'earlyLeaveCount' => $earlyLeaveCount,
            'overtimeCount' => $overtimeCount,
            'avgFirst' => format_time_minutes($avgFirst),
            'avgLast' => format_time_minutes($avgLast),
            'completionRate' => $completionRate,
        ],
        'arrivalBuckets' => [
            'labels' => array_keys($arrivalBuckets),
            'counts' => array_values($arrivalBuckets),
        ],
        'projects' => [
            'labels' => $projectLabels,
            'counts' => $projectValues,
        ],
        'recent' => array_map(function (array $row): array {
            $displayName = $row['name'] !== '' ? $row['name'] : ($row['badge'] !== '' ? $row['badge'] : 'Unknown');
            $timeLabel = $row['time'];
            if ($timeLabel !== '') {
                try {
                    $dt = new DateTimeImmutable($timeLabel);
                    $timeLabel = $dt->format('d M, h:i A');
                } catch (Exception $e) {
                    $timeLabel = $row['time'];
                }
            }
            return [
                'name' => $displayName,
                'badge' => $row['badge'],
                'project' => $row['project'],
                'time' => $timeLabel,
            ];
        }, $recentRows),
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'trend') {
    $errors = [];
    $labels = [];
    $values = [];
    if ($deviceSnParam !== '') {
        $dailySql = 'SELECT punch_date AS date_key, COUNT(DISTINCT emp_code) AS total ' .
            'FROM gcc_attendance_master.employee_daily_punch ' .
            'WHERE punch_date BETWEEN ? AND ? ' .
            'AND (first_log IS NOT NULL OR last_log IS NOT NULL) ' .
            'AND (FIND_IN_SET(first_terminal_sn, ?) OR FIND_IN_SET(last_terminal_sn, ?)) ' .
            'GROUP BY punch_date';
        $dailyResult = db_fetch_all($bd, $dailySql, 'ssss', [$startDate, $endDate, $deviceSnParam, $deviceSnParam]);
    } else {
        $dailySql = 'SELECT att_date AS date_key, COUNT(DISTINCT emp_code) AS total ' .
            'FROM gcc_attendance_master.employee_att_daily ' .
            'WHERE att_date BETWEEN ? AND ? ' .
            'AND (is_delete = 0 OR is_delete IS NULL) ' .
            'AND (is_deleted = 0 OR is_deleted IS NULL) ' .
            'GROUP BY att_date';
        $dailyResult = db_fetch_all($bd, $dailySql, 'ss', [$startDate, $endDate]);
    }

    if ($dailyResult['ok']) {
        $map = [];
        foreach ($dailyResult['rows'] as $row) {
            $dateKey = trim((string) ($row['date_key'] ?? ''));
            if ($dateKey === '') {
                continue;
            }
            $map[$dateKey] = (int) ($row['total'] ?? 0);
        }
        $series = build_date_series($startDate, $endDate);
        foreach ($series as $dateKey) {
            $labels[] = $dateKey;
            $values[] = (int) ($map[$dateKey] ?? 0);
        }
    } else {
        $errors[] = 'Daily trend';
    }

    $maxValue = 0;
    $avgValue = 0;
    $delta = null;
    if (!empty($values)) {
        $maxValue = max($values);
        $avgValue = (int) round(array_sum($values) / count($values));
        if (count($values) >= 2) {
            $delta = $values[count($values) - 1] - $values[count($values) - 2];
        }
    }

    $payload = [
        'ok' => true,
        'errors' => array_values(array_unique($errors)),
        'labels' => $labels,
        'values' => $values,
        'max' => $maxValue,
        'avg' => $avgValue,
        'delta' => $delta,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'departments') {
    $errors = [];
    $deptRoster = [];
    $deptProjectRoster = [];
    $projectNameMap = [];
    $deptRosterSql = 'SELECT COALESCE(NULLIF(dept_name, \'\'), \'Unassigned\') AS dept_name, ' .
        'COUNT(*) AS total FROM gcc_attendance_master.hrmsvw_sync ' .
        'GROUP BY COALESCE(NULLIF(dept_name, \'\'), \'Unassigned\')';
    $deptRosterResult = db_fetch_all($bd, $deptRosterSql);
    if ($deptRosterResult['ok']) {
        foreach ($deptRosterResult['rows'] as $row) {
            $deptName = trim((string) ($row['dept_name'] ?? ''));
            if ($deptName === '') {
                $deptName = 'Unassigned';
            }
            $deptRoster[$deptName] = (int) ($row['total'] ?? 0);
        }
    } else {
        $errors[] = 'Department roster';
    }

    $projectMapResult = db_fetch_all($bd, 'SELECT pro_code, name FROM gcc_it.projects');
    if ($projectMapResult['ok']) {
        foreach ($projectMapResult['rows'] as $row) {
            $projectCode = trim((string) ($row['pro_code'] ?? ''));
            $projectName = trim((string) ($row['name'] ?? ''));
            if ($projectCode !== '' && $projectName !== '' && !isset($projectNameMap[$projectCode])) {
                $projectNameMap[$projectCode] = $projectName;
            }
        }
    }

    $deptProjectSql = 'SELECT COALESCE(NULLIF(dept_name, \'\'), \'Unassigned\') AS dept_name, ' .
        'COALESCE(NULLIF(jbno, \'\'), \'Unassigned\') AS project_code, ' .
        'MAX(NULLIF(jbdesc, \'\')) AS project_name, COUNT(*) AS total ' .
        'FROM gcc_attendance_master.hrmsvw_sync ' .
        'GROUP BY COALESCE(NULLIF(dept_name, \'\'), \'Unassigned\'), COALESCE(NULLIF(jbno, \'\'), \'Unassigned\')';
    $deptProjectResult = db_fetch_all($bd, $deptProjectSql);
    if ($deptProjectResult['ok']) {
        foreach ($deptProjectResult['rows'] as $row) {
            $deptName = trim((string) ($row['dept_name'] ?? ''));
            if ($deptName === '') {
                $deptName = 'Unassigned';
            }
            $projectCode = trim((string) ($row['project_code'] ?? ''));
            if ($projectCode === '') {
                $projectCode = 'Unassigned';
            }
            $projectName = trim((string) ($row['project_name'] ?? ''));
            if ($projectName !== '' && !isset($projectNameMap[$projectCode])) {
                $projectNameMap[$projectCode] = $projectName;
            }
            if (!isset($deptProjectRoster[$deptName])) {
                $deptProjectRoster[$deptName] = [];
            }
            $deptProjectRoster[$deptName][$projectCode] = (int) ($row['total'] ?? 0);
        }
    } else {
        $errors[] = 'Project roster';
    }

    $deptStats = [];
    foreach ($deptRoster as $deptName => $count) {
        $deptStats[$deptName] = [
            'name' => $deptName,
            'activeCount' => $count,
            'employees' => [],
            'lateCount' => 0,
            'earlyLeaveCount' => 0,
            'overtimeCount' => 0,
            'completionCount' => 0,
            'sampleCount' => 0,
            'firstTotal' => 0,
            'firstCount' => 0,
            'lastTotal' => 0,
            'lastCount' => 0,
            'projects' => [],
        ];
    }

    $punchWhere = 'p.punch_date = ? AND (p.first_log IS NOT NULL OR p.last_log IS NOT NULL)';
    $punchTypes = 's';
    $punchParams = [$endDate];
    if ($deviceSnParam !== '') {
        $punchWhere .= ' AND (FIND_IN_SET(p.first_terminal_sn, ?) OR FIND_IN_SET(p.last_terminal_sn, ?))';
        $punchParams[] = $deviceSnParam;
        $punchParams[] = $deviceSnParam;
        $punchTypes .= 'ss';
    }

    $deptProjectSelect = 'COALESCE(NULLIF(pf.pro_code, \'\'), NULLIF(pl.pro_code, \'\'), NULLIF(d.job, \'\'), NULLIF(h.jbno, \'\')) AS project_code';
    $deptProjectTypes = '';
    $deptProjectParams = [];
    if ($deviceSnParam !== '') {
        $deptProjectSelect = 'COALESCE(' .
            'NULLIF(IF(FIND_IN_SET(p.first_terminal_sn, ?), pf.pro_code, \'\'), \'\'), ' .
            'NULLIF(IF(FIND_IN_SET(p.last_terminal_sn, ?), pl.pro_code, \'\'), \'\'), ' .
            'NULLIF(d.job, \'\'), NULLIF(h.jbno, \'\')) AS project_code';
        $deptProjectTypes = 'ss';
        $deptProjectParams = [$deviceSnParam, $deviceSnParam];
    }

    $deptSql = 'SELECT p.emp_code, p.first_log, p.last_log, ' .
        $deptProjectSelect . ', ' .
        'COALESCE(NULLIF(h.dept_name, \'\'), NULLIF(d.department_name, \'\')) AS dept_name ' .
        'FROM gcc_attendance_master.employee_daily_punch p ' .
        'LEFT JOIN gcc_attendance_master.device_project_map df ON df.device_sn = p.first_terminal_sn ' .
        'LEFT JOIN gcc_it.projects pf ON pf.id = df.project_id ' .
        'LEFT JOIN gcc_attendance_master.device_project_map dl ON dl.device_sn = p.last_terminal_sn ' .
        'LEFT JOIN gcc_it.projects pl ON pl.id = dl.project_id ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily d ' .
        'ON d.emp_code COLLATE utf8mb4_general_ci = p.emp_code COLLATE utf8mb4_general_ci ' .
        'AND d.att_date = p.punch_date ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ' .
        'ON h.emp_code COLLATE utf8mb4_general_ci = p.emp_code COLLATE utf8mb4_general_ci ' .
        'WHERE ' . $punchWhere;
    $deptResult = db_fetch_all($bd, $deptSql, $deptProjectTypes . $punchTypes, array_merge($deptProjectParams, $punchParams));
    $deptRows = $deptResult['ok'] ? $deptResult['rows'] : [];
    if (!$deptResult['ok']) {
        $errors[] = 'Department punches';
    }

    $lateThreshold = 10 * 60;
    $earlyLeaveThreshold = 16 * 60;
    $overtimeThreshold = 19 * 60;

    foreach ($deptRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $deptName = trim((string) ($row['dept_name'] ?? ''));
        if ($deptName === '') {
            $deptName = 'Unassigned';
        }
        if (!isset($deptStats[$deptName])) {
            $deptStats[$deptName] = [
                'name' => $deptName,
                'activeCount' => $deptRoster[$deptName] ?? null,
                'employees' => [],
                'lateCount' => 0,
                'earlyLeaveCount' => 0,
                'overtimeCount' => 0,
                'completionCount' => 0,
                'sampleCount' => 0,
                'firstTotal' => 0,
                'firstCount' => 0,
                'lastTotal' => 0,
                'lastCount' => 0,
                'projects' => [],
            ];
        }

        $deptStats[$deptName]['sampleCount']++;

        $empCode = trim((string) ($row['emp_code'] ?? ''));
        if ($empCode !== '') {
            $deptStats[$deptName]['employees'][$empCode] = true;
        }

        $firstMinutesValue = parse_time_minutes($row['first_log'] ?? null);
        $lastMinutesValue = parse_time_minutes($row['last_log'] ?? null);

        if ($firstMinutesValue !== null) {
            $deptStats[$deptName]['firstTotal'] += $firstMinutesValue;
            $deptStats[$deptName]['firstCount']++;
            if ($firstMinutesValue > $lateThreshold) {
                $deptStats[$deptName]['lateCount']++;
            }
        }
        if ($lastMinutesValue !== null) {
            $deptStats[$deptName]['lastTotal'] += $lastMinutesValue;
            $deptStats[$deptName]['lastCount']++;
            if ($lastMinutesValue < $earlyLeaveThreshold) {
                $deptStats[$deptName]['earlyLeaveCount']++;
            }
            if ($lastMinutesValue > $overtimeThreshold) {
                $deptStats[$deptName]['overtimeCount']++;
            }
        }
        if ($firstMinutesValue !== null && $lastMinutesValue !== null) {
            $deptStats[$deptName]['completionCount']++;
        }

        $project = trim((string) ($row['project_code'] ?? ''));
        if ($project === '') {
            $project = 'Unassigned';
        }
        if (!isset($deptStats[$deptName]['projects'][$project])) {
            $deptStats[$deptName]['projects'][$project] = 0;
        }
        $deptStats[$deptName]['projects'][$project]++;
    }

    $departments = [];
    foreach ($deptStats as $deptName => $stats) {
        $loggedCount = count($stats['employees']);
        $coveragePercent = null;
        if ($stats['activeCount'] !== null && $stats['activeCount'] > 0) {
            $coveragePercent = (int) round(($loggedCount / $stats['activeCount']) * 100);
        }
        $absentCount = null;
        if ($stats['activeCount'] !== null) {
            $absentCount = max(0, $stats['activeCount'] - $loggedCount);
        }

        $completionRate = null;
        if ($stats['sampleCount'] > 0) {
            $completionRate = (int) round(($stats['completionCount'] / $stats['sampleCount']) * 100);
        }

        $avgFirst = null;
        if ($stats['firstCount'] > 0) {
            $avgFirst = (int) round($stats['firstTotal'] / $stats['firstCount']);
        }
        $avgLast = null;
        if ($stats['lastCount'] > 0) {
            $avgLast = (int) round($stats['lastTotal'] / $stats['lastCount']);
        }

        $projectCounts = $stats['projects'];
        $projectRoster = $deptProjectRoster[$deptName] ?? [];
        $projectKeys = array_unique(array_merge(array_keys($projectRoster), array_keys($projectCounts)));
        $projectItems = [];
        $projectTotalActive = 0;
        $projectTotalLogged = 0;
        $projectTotalAbsent = 0;
        foreach ($projectKeys as $projectCode) {
            $activeCount = (int) ($projectRoster[$projectCode] ?? 0);
            $loggedProjectCount = (int) ($projectCounts[$projectCode] ?? 0);
            $absentProjectCount = max(0, $activeCount - $loggedProjectCount);
            $projectTotalActive += $activeCount;
            $projectTotalLogged += $loggedProjectCount;
            $projectTotalAbsent += $absentProjectCount;
            $label = $projectCode;
            if ($projectCode !== 'Unassigned' && isset($projectNameMap[$projectCode])) {
                $label = $projectCode . ' - ' . $projectNameMap[$projectCode];
            } elseif (isset($projectNameMap[$projectCode]) && $projectNameMap[$projectCode] !== '') {
                $label = $projectNameMap[$projectCode];
            }
            $projectItems[] = [
                'code' => $projectCode,
                'label' => $label,
                'activeCount' => $activeCount,
                'loggedCount' => $loggedProjectCount,
                'absentCount' => $absentProjectCount,
            ];
        }

        usort($projectItems, function (array $a, array $b): int {
            $absentCompare = ($b['absentCount'] ?? 0) <=> ($a['absentCount'] ?? 0);
            if ($absentCompare !== 0) {
                return $absentCompare;
            }
            return ($b['loggedCount'] ?? 0) <=> ($a['loggedCount'] ?? 0);
        });

        $projectLimit = 4;
        $projectItemsLimited = array_slice($projectItems, 0, $projectLimit);
        $projectItemsRemainder = array_slice($projectItems, $projectLimit);
        if (!empty($projectItemsRemainder)) {
            $otherActive = 0;
            $otherLogged = 0;
            $otherAbsent = 0;
            foreach ($projectItemsRemainder as $item) {
                $otherActive += (int) ($item['activeCount'] ?? 0);
                $otherLogged += (int) ($item['loggedCount'] ?? 0);
                $otherAbsent += (int) ($item['absentCount'] ?? 0);
            }
            $projectItemsLimited[] = [
                'code' => 'Other',
                'label' => 'Other',
                'activeCount' => $otherActive,
                'loggedCount' => $otherLogged,
                'absentCount' => $otherAbsent,
            ];
        }

        $departments[] = [
            'name' => $deptName,
            'kpis' => [
                'activeCount' => $stats['activeCount'],
                'loggedCount' => $loggedCount,
                'coveragePercent' => $coveragePercent,
                'absentCount' => $absentCount,
            ],
            'timeMetrics' => [
                'lateCount' => $stats['lateCount'],
                'earlyLeaveCount' => $stats['earlyLeaveCount'],
                'overtimeCount' => $stats['overtimeCount'],
                'avgFirst' => format_time_minutes($avgFirst),
                'avgLast' => format_time_minutes($avgLast),
                'completionRate' => $completionRate,
            ],
            'projects' => [
                'items' => $projectItemsLimited,
                'totalActive' => $projectTotalActive,
                'totalLogged' => $projectTotalLogged,
                'totalAbsent' => $projectTotalAbsent,
            ],
            'meta' => [
                'sampleCount' => $stats['sampleCount'],
            ],
        ];
    }

    usort($departments, function (array $a, array $b): int {
        return ($b['kpis']['loggedCount'] ?? 0) <=> ($a['kpis']['loggedCount'] ?? 0);
    });

    $payload = [
        'ok' => true,
        'errors' => array_values(array_unique($errors)),
        'meta' => [
            'pulseDate' => $endDate,
            'departmentCount' => count($departments),
        ],
        'departments' => $departments,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

?>
<?php include __DIR__ . '/include/layout_top.php'; ?>

<style>
  :root {
    --hr-ink: #0b1120;
    --hr-ink-soft: #2a3553;
    --hr-cream: #fff8e1;
    --hr-mint: #d1fae5;
    --hr-coral: #fecdd3;
    --hr-blue: #bae6fd;
    --hr-violet: #e9d5ff;
    --hr-glow: rgba(251, 191, 36, 0.35);
    --hr-card: #ffffff;
    --hr-shadow: 0 22px 45px rgba(15, 23, 42, 0.16);
  }
  .hr-dashboard {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
  }
  .hr-hero {
    padding: 1.6rem 1.8rem;
    border-radius: 22px;
    background: linear-gradient(120deg, #0ea5e9 0%, #38bdf8 25%, #fbbf24 60%, #f97316 100%);
    color: #0b1120;
    position: relative;
    overflow: hidden;
  }
  .hr-hero::after {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.45), transparent 60%);
    opacity: 0.8;
  }
  .hr-hero-inner {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    align-items: center;
  }
  .hr-hero h2 {
    font-family: "Bebas Neue", "Sora", sans-serif;
    font-size: 2.8rem;
    margin: 0;
    letter-spacing: 0.08em;
  }
  .hr-hero p {
    margin: 0.2rem 0 0;
    font-weight: 500;
    color: rgba(15, 23, 42, 0.8);
  }
  .hr-hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
  }
  .hr-pill {
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    font-size: 0.85rem;
  }
  .hr-toolbar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.85rem;
    align-items: end;
  }
  .hr-toolbar .form-group {
    margin-bottom: 0;
  }
  .hr-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    border: 1px solid rgba(15, 23, 42, 0.15);
    background: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    cursor: pointer;
  }
  .gap-2 {
    gap: 0.5rem;
  }
  .text-pop {
    animation: hr-pop 0.35s ease;
  }
  .hr-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
  }
  .hr-kpi {
    padding: 1rem 1.1rem;
    border-radius: 18px;
    background: var(--hr-card);
    box-shadow: var(--hr-shadow);
    position: relative;
    overflow: hidden;
  }
  .hr-kpi::after {
    content: "";
    position: absolute;
    right: -20%;
    top: -30%;
    width: 120px;
    height: 120px;
    border-radius: 999px;
    background: rgba(251, 191, 36, 0.3);
  }
  .hr-kpi h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--hr-ink-soft);
  }
  .hr-kpi .hr-kpi-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--hr-ink);
    letter-spacing: 0.02em;
  }
  .hr-kpi .hr-kpi-sub {
    font-size: 0.85rem;
    color: var(--att-muted);
  }
  .hr-bento {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 1rem;
  }
  .hr-card {
    grid-column: span 12;
    padding: 1rem 1.2rem;
  }
  .hr-card h4 {
    font-weight: 700;
    margin-bottom: 0.5rem;
  }
  .hr-chart {
    height: 260px;
  }
  .hr-list {
    display: grid;
    gap: 0.65rem;
  }
  .hr-list-item {
    display: flex;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.55rem 0.75rem;
    border-radius: 12px;
    background: rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(15, 23, 42, 0.08);
  }
  .hr-list-item small {
    color: var(--att-muted);
  }
  .hr-insights {
    display: grid;
    gap: 0.75rem;
  }
  .hr-insight {
    padding: 0.65rem 0.85rem;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.15), rgba(251, 191, 36, 0.12));
    border: 1px solid rgba(15, 23, 42, 0.08);
    font-weight: 600;
  }
  .hr-skeleton {
    position: relative;
    overflow: hidden;
    background: rgba(148, 163, 184, 0.2);
    border-radius: 8px;
  }
  .hr-skeleton::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.7), transparent);
    animation: hr-shimmer 1.5s infinite;
  }
  .hr-pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
  }
  .hr-dept-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
  }
  .hr-dept-card {
    position: relative;
    border-radius: 20px;
    padding: 1rem 1.1rem;
    background: linear-gradient(160deg, rgba(255, 255, 255, 0.98), #ffffff);
    box-shadow: var(--hr-shadow);
    border: 1px solid rgba(15, 23, 42, 0.08);
    overflow: hidden;
  }
  .hr-dept-card::before {
    content: "";
    position: absolute;
    inset: -40% -20% auto auto;
    width: 240px;
    height: 240px;
    background: radial-gradient(circle, var(--accent-soft, rgba(56, 189, 248, 0.25)), transparent 65%);
    opacity: 0.9;
  }
  .hr-dept-card.is-low {
    border-color: rgba(248, 113, 113, 0.4);
    box-shadow: 0 20px 40px rgba(248, 113, 113, 0.2);
  }
  .hr-dept-header {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
  }
  .hr-dept-title h5 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--hr-ink);
  }
  .hr-dept-sub {
    font-size: 0.85rem;
    color: var(--att-muted);
  }
  .hr-ring {
    --value: 0;
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: conic-gradient(var(--accent, #38bdf8) calc(var(--value) * 1%), rgba(148, 163, 184, 0.2) 0);
    display: grid;
    place-items: center;
    position: relative;
  }
  .hr-ring::after {
    content: "";
    position: absolute;
    inset: 8px;
    border-radius: 50%;
    background: #ffffff;
  }
  .hr-ring span,
  .hr-ring small {
    position: relative;
    z-index: 1;
    display: block;
    text-align: center;
  }
  .hr-ring span {
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--hr-ink);
  }
  .hr-ring small {
    font-size: 0.65rem;
    color: var(--att-muted);
    margin-top: -2px;
  }
  .hr-coverage {
    position: relative;
    z-index: 1;
    display: grid;
    gap: 0.35rem;
  }
  .hr-coverage-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--hr-ink-soft);
  }
  .hr-coverage-bar {
    height: 8px;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--accent, #38bdf8) calc(var(--coverage) * 1%), rgba(148, 163, 184, 0.25) 0);
  }
  .hr-chip-row {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
  }
  .hr-chip {
    padding: 0.28rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    background: rgba(15, 23, 42, 0.08);
    color: var(--hr-ink);
  }
  .hr-chip.is-warn {
    background: rgba(248, 113, 113, 0.2);
    color: #b91c1c;
  }
  .hr-chip.is-ok {
    background: rgba(16, 185, 129, 0.2);
    color: #047857;
  }
  .hr-chip.is-neutral {
    background: rgba(59, 130, 246, 0.18);
    color: #1d4ed8;
  }
  .hr-dept-insights {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.5rem;
  }
  .hr-dept-card .hr-insight {
    padding: 0.45rem 0.6rem;
    font-size: 0.78rem;
  }
  .hr-dept-projects {
    position: relative;
    z-index: 1;
    display: grid;
    gap: 0.5rem;
  }
  .hr-project-title {
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--hr-ink-soft);
  }
  .hr-project {
    display: grid;
    gap: 0.25rem;
  }
  .hr-project-head {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--hr-ink);
  }
  .hr-project-bar {
    height: 6px;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--accent, #38bdf8) calc(var(--pct) * 1%), rgba(148, 163, 184, 0.25) 0);
  }
  .hr-dept-sample {
    position: relative;
    z-index: 1;
    font-size: 0.72rem;
    color: var(--att-muted);
  }
  @keyframes hr-pop {
    from { transform: scale(0.96); color: #0ea5e9; }
    to { transform: scale(1); color: inherit; }
  }
  @keyframes hr-shimmer {
    from { transform: translateX(-100%); }
    to { transform: translateX(100%); }
  }
  .hr-focus .content-wrapper {
    background: #f8fafc;
  }
  .hr-focus .hr-hero {
    background: linear-gradient(120deg, #e2e8f0, #f8fafc);
  }
  .hr-focus .hr-kpi::after {
    display: none;
  }
  .hr-reduce-motion * {
    animation: none !important;
    transition: none !important;
  }
  @media (min-width: 992px) {
    .hr-card.hr-span-7 { grid-column: span 7; }
    .hr-card.hr-span-5 { grid-column: span 5; }
    .hr-card.hr-span-4 { grid-column: span 4; }
    .hr-card.hr-span-6 { grid-column: span 6; }
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>HR Insights Dashboard</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <span class="badge badge-primary">HR Focus</span>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid hr-dashboard">
    <?php include __DIR__ . '/include/admin_nav.php'; ?>

    <?php if (!empty($flash)): ?>
      <div class="alert alert-info mb-0"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="hr-hero card">
      <div class="hr-hero-inner">
        <div>
          <h2>People Pulse</h2>
          <p>High-energy overview of attendance health, coverage, and risk signals.</p>
        </div>
        <div class="hr-hero-meta">
          <span class="hr-pill">Range: <?= h($startDate) ?> to <?= h($endDate) ?></span>
          <span class="hr-pill" id="pulseDatePill">Daily pulse: <?= h($endDate) ?></span>
          <span class="hr-pill" id="sampleNotePill">Sampled: waiting</span>
        </div>
      </div>
    </div>

    <div class="card p-3">
      <form class="hr-toolbar" method="get">
        <div class="form-group">
          <label for="startDate">Start date</label>
          <input type="date" class="form-control" id="startDate" name="startDate" value="<?= h($startDate) ?>">
        </div>
        <div class="form-group">
          <label for="endDate">End date</label>
          <input type="date" class="form-control" id="endDate" name="endDate" value="<?= h($endDate) ?>">
        </div>
        <div class="form-group">
          <label for="deviceSn">Device SN filter</label>
          <input type="text" class="form-control" id="deviceSn" name="deviceSn" placeholder="Comma separated" value="<?= h($deviceSnInput) ?>">
        </div>
        <div class="form-group">
          <button type="submit" class="btn btn-primary btn-block">Apply</button>
        </div>
        <div class="form-group d-flex gap-2">
          <button type="button" class="hr-toggle" data-range="today">Today</button>
          <button type="button" class="hr-toggle" data-range="7">Last 7</button>
          <button type="button" class="hr-toggle" data-range="30">Last 30</button>
        </div>
        <div class="form-group d-flex gap-2">
          <button type="button" class="hr-toggle" id="toggleFocus"><i class="fas fa-adjust"></i> Focus mode</button>
          <button type="button" class="hr-toggle" id="toggleMotion"><i class="fas fa-running"></i> Reduce motion</button>
        </div>
      </form>
    </div>

    <div class="hr-kpi-grid">
      <div class="hr-kpi">
        <h3>Active employees</h3>
        <div class="hr-kpi-value" id="kpiActive">-</div>
        <div class="hr-kpi-sub" id="kpiActiveSub">HRMS status A</div>
      </div>
      <div class="hr-kpi">
        <h3>Logged in (pulse day)</h3>
        <div class="hr-kpi-value" id="kpiLogged">-</div>
        <div class="hr-kpi-sub" id="kpiCoverage">Coverage n/a</div>
      </div>
      <div class="hr-kpi">
        <h3>Late arrivals</h3>
        <div class="hr-kpi-value" id="kpiLate">-</div>
        <div class="hr-kpi-sub" id="kpiLateSub">After 10:00</div>
      </div>
      <div class="hr-kpi">
        <h3>Early leaves</h3>
        <div class="hr-kpi-value" id="kpiEarly">-</div>
        <div class="hr-kpi-sub" id="kpiEarlySub">Before 16:00</div>
      </div>
      <div class="hr-kpi">
        <h3>Overtime pulse</h3>
        <div class="hr-kpi-value" id="kpiOvertime">-</div>
        <div class="hr-kpi-sub" id="kpiOvertimeSub">After 19:00</div>
      </div>
      <div class="hr-kpi">
        <h3>Device uptime</h3>
        <div class="hr-kpi-value" id="kpiDevices">-</div>
        <div class="hr-kpi-sub" id="kpiDevicesSub">Active / total devices</div>
      </div>
    </div>

    <div class="hr-bento">
      <div class="card hr-card hr-span-7">
        <h4>Attendance pulse</h4>
        <p class="text-muted mb-2">Daily logged in counts for the selected range.</p>
        <div class="hr-chart">
          <canvas id="trendChart"></canvas>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="hr-pill" id="trendAvg">Avg: -</span>
          <span class="hr-pill" id="trendDelta">Delta: -</span>
        </div>
      </div>

      <div class="card hr-card hr-span-5">
        <h4>Arrival mix</h4>
        <p class="text-muted mb-2">Check-in distribution for the pulse day.</p>
        <div class="hr-chart">
          <canvas id="arrivalChart"></canvas>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="hr-pill" id="avgFirst">Avg first login: -</span>
          <span class="hr-pill" id="avgLast">Avg last login: -</span>
        </div>
      </div>

      <div class="card hr-card hr-span-4">
        <h4>Project spotlight</h4>
        <p class="text-muted mb-2">Where attendance energy is concentrated.</p>
        <div class="hr-chart">
          <canvas id="projectChart"></canvas>
        </div>
      </div>

      <div class="card hr-card hr-span-4">
        <h4>Recent activity</h4>
        <div class="hr-list" id="recentList">
          <div class="hr-skeleton" style="height: 48px;"></div>
          <div class="hr-skeleton" style="height: 48px;"></div>
          <div class="hr-skeleton" style="height: 48px;"></div>
        </div>
      </div>

      <div class="card hr-card hr-span-6">
        <h4>Focus actions</h4>
        <div class="hr-insights" id="insightList">
          <div class="hr-skeleton" style="height: 44px;"></div>
          <div class="hr-skeleton" style="height: 44px;"></div>
          <div class="hr-skeleton" style="height: 44px;"></div>
        </div>
      </div>

      <div class="card hr-card hr-span-6">
        <h4>Attendance completeness</h4>
        <p class="text-muted mb-2">Percent of badges with both first and last login.</p>
        <div class="hr-chart">
          <canvas id="completionChart"></canvas>
        </div>
      </div>

      <div class="card hr-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <h4>Department focus</h4>
            <p class="text-muted mb-2">All core insights by department with project mix.</p>
          </div>
          <div class="hr-pill-row">
            <span class="hr-pill" id="deptCountPill">Departments: -</span>
            <span class="hr-pill" id="deptPulsePill">Pulse: <?= h($endDate) ?></span>
          </div>
        </div>
        <div class="hr-dept-grid" id="deptInsights">
          <div class="hr-dept-card">
            <div class="hr-skeleton" style="height: 200px;"></div>
          </div>
          <div class="hr-dept-card">
            <div class="hr-skeleton" style="height: 200px;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  window.addEventListener('load', function () {
    const baseUrl = window.location.pathname;
    const baseParams = new URLSearchParams({
      startDate: <?= json_encode($startDate) ?>,
      endDate: <?= json_encode($endDate) ?>,
      deviceSn: <?= json_encode($deviceSnInput) ?>
    });

    const charts = {};

    const palette = {
      blue: '#38bdf8',
      orange: '#f97316',
      gold: '#fbbf24',
      violet: '#a855f7',
      teal: '#14b8a6',
      rose: '#f43f5e'
    };
    const accentPalette = [
      palette.blue,
      palette.orange,
      palette.gold,
      palette.violet,
      palette.teal,
      palette.rose,
      '#22c55e',
      '#0ea5e9'
    ];

    const setText = (id, value) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = value;
      el.classList.add('text-pop');
      setTimeout(() => el.classList.remove('text-pop'), 400);
    };

    const setPill = (id, text) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = text;
    };

    const hexToRgba = (hex, alpha) => {
      if (!hex) return `rgba(56, 189, 248, ${alpha})`;
      const cleaned = hex.replace('#', '');
      const full = cleaned.length === 3
        ? cleaned.split('').map((ch) => ch + ch).join('')
        : cleaned;
      if (full.length !== 6) return `rgba(56, 189, 248, ${alpha})`;
      const r = parseInt(full.slice(0, 2), 16);
      const g = parseInt(full.slice(2, 4), 16);
      const b = parseInt(full.slice(4, 6), 16);
      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    };

    const createLineChart = (ctx, labels, values) => {
      const gradient = ctx.createLinearGradient(0, 0, 0, 240);
      gradient.addColorStop(0, 'rgba(56, 189, 248, 0.55)');
      gradient.addColorStop(1, 'rgba(56, 189, 248, 0.05)');
      return new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            data: values,
            borderColor: palette.blue,
            backgroundColor: gradient,
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointBackgroundColor: palette.gold
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          },
          scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } },
            x: { ticks: { maxTicksLimit: 7 } }
          }
        }
      });
    };

    const createBarChart = (ctx, labels, values, colors) => {
      return new Chart(ctx, {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: colors,
            borderRadius: 10
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    };

    const createDonutChart = (ctx, labels, values, colors) => {
      return new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: colors,
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          cutout: '65%'
        }
      });
    };

    const createCompletionChart = (ctx, value) => {
      return new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['Complete', 'Missing'],
          datasets: [{
            data: [value, Math.max(0, 100 - value)],
            backgroundColor: [palette.teal, 'rgba(148, 163, 184, 0.4)'],
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          cutout: '70%'
        }
      });
    };

    const updateChart = (key, creator) => {
      if (charts[key]) {
        charts[key].destroy();
      }
      charts[key] = creator();
    };

    const renderRecent = (rows) => {
      const list = document.getElementById('recentList');
      if (!list) return;
      list.innerHTML = '';
      if (!rows || rows.length === 0) {
        list.innerHTML = '<div class="text-muted">No recent activity available.</div>';
        return;
      }
      rows.forEach((row) => {
        const item = document.createElement('div');
        item.className = 'hr-list-item';
        item.innerHTML = `
          <div>
            <div><strong>${row.name}</strong></div>
            <small>${row.project || 'Unassigned'}</small>
          </div>
          <div class="text-right">
            <div>${row.time || '-'}</div>
            <small>${row.badge || ''}</small>
          </div>
        `;
        list.appendChild(item);
      });
    };

    const buildInsightLines = (summary) => {
      const insights = [];
      const kpis = summary.kpis || {};
      const timeMetrics = summary.timeMetrics || {};
      const coverage = kpis.coveragePercent;
      const late = timeMetrics.lateCount ?? 0;
      const early = timeMetrics.earlyLeaveCount ?? 0;
      const overtime = timeMetrics.overtimeCount ?? 0;
      const completion = timeMetrics.completionRate;

      if (coverage !== null && coverage !== undefined) {
        insights.push(`Coverage at ${coverage}%. ${coverage < 70 ? 'Needs attention.' : 'Healthy range.'}`);
      } else {
        insights.push('Coverage data unavailable. Check HRMS or attendance API.');
      }

      insights.push(`Late arrivals: ${late}. ${late > 10 ? 'High variance detected.' : 'On track today.'}`);
      insights.push(`Early leaves: ${early}. ${early > 8 ? 'Review shift adherence.' : 'Stable departures.'}`);
      insights.push(`Overtime: ${overtime}. ${overtime > 6 ? 'Check workload balance.' : 'Overtime controlled.'}`);

      if (completion !== null && completion !== undefined) {
        insights.push(`Completeness: ${completion}% of badges have both in/out.`);
      } else {
        insights.push('Completeness data unavailable.');
      }

      return insights;
    };

    const renderInsights = (summary, listEl) => {
      const list = listEl || document.getElementById('insightList');
      if (!list) return;
      list.innerHTML = '';
      const insights = buildInsightLines(summary || {});
      insights.slice(0, 5).forEach((text) => {
        const item = document.createElement('div');
        item.className = 'hr-insight';
        item.textContent = text;
        list.appendChild(item);
      });
    };

    const createChip = (label, value, threshold) => {
      const chip = document.createElement('div');
      const numeric = Number.isFinite(value) ? value : 0;
      let state = 'is-neutral';
      if (threshold !== null && threshold !== undefined) {
        state = numeric > threshold ? 'is-warn' : 'is-ok';
      }
      chip.className = `hr-chip ${state}`;
      chip.textContent = `${label}: ${numeric}`;
      return chip;
    };

    const renderDepartments = (data) => {
      const container = document.getElementById('deptInsights');
      if (!container) return;
      container.innerHTML = '';

      const departments = (data && data.departments) ? data.departments : [];

      setPill('deptCountPill', `Departments: ${departments.length}`);
      if (data && data.meta && data.meta.pulseDate) {
        setPill('deptPulsePill', `Pulse: ${data.meta.pulseDate}`);
      }

      if (departments.length === 0) {
        container.innerHTML = '<div class="text-muted">No department insights available.</div>';
        return;
      }

      departments.forEach((dept, index) => {
        const accent = accentPalette[index % accentPalette.length];
        const card = document.createElement('div');
        card.className = 'hr-dept-card';
        card.style.setProperty('--accent', accent);
        card.style.setProperty('--accent-soft', hexToRgba(accent, 0.25));

        const kpis = dept.kpis || {};
        const timeMetrics = dept.timeMetrics || {};
        const logged = kpis.loggedCount ?? 0;
        const active = (kpis.activeCount !== null && kpis.activeCount !== undefined) ? kpis.activeCount : '-';
        const coverage = kpis.coveragePercent;
        const coverageValue = (coverage !== null && coverage !== undefined) ? coverage : 0;

        if (coverage !== null && coverage !== undefined && coverage < 70) {
          card.classList.add('is-low');
        }

        const header = document.createElement('div');
        header.className = 'hr-dept-header';
        const titleWrap = document.createElement('div');
        titleWrap.className = 'hr-dept-title';
        const title = document.createElement('h5');
        title.textContent = dept.name || 'Unassigned';
        const subtitle = document.createElement('div');
        subtitle.className = 'hr-dept-sub';
        subtitle.textContent = `Logged ${logged} / ${active}`;
        titleWrap.appendChild(title);
        titleWrap.appendChild(subtitle);

        const ring = document.createElement('div');
        ring.className = 'hr-ring';
        const completion = timeMetrics.completionRate;
        const completionValue = Number.isFinite(completion) ? completion : null;
        ring.style.setProperty('--value', completionValue ?? 0);
        const ringValue = document.createElement('span');
        ringValue.textContent = completionValue !== null ? `${completionValue}%` : '--';
        const ringLabel = document.createElement('small');
        ringLabel.textContent = 'Complete';
        ring.appendChild(ringValue);
        ring.appendChild(ringLabel);

        header.appendChild(titleWrap);
        header.appendChild(ring);

        const coverageWrap = document.createElement('div');
        coverageWrap.className = 'hr-coverage';
        const coverageLabel = document.createElement('div');
        coverageLabel.className = 'hr-coverage-label';
        coverageLabel.textContent = `Coverage ${coverage !== null && coverage !== undefined ? coverage + '%' : 'n/a'}`;
        const coverageBar = document.createElement('div');
        coverageBar.className = 'hr-coverage-bar';
        coverageBar.style.setProperty('--coverage', coverageValue);
        coverageWrap.appendChild(coverageLabel);
        coverageWrap.appendChild(coverageBar);

        const chipRow = document.createElement('div');
        chipRow.className = 'hr-chip-row';
        chipRow.appendChild(createChip('Late', timeMetrics.lateCount, 10));
        chipRow.appendChild(createChip('Early', timeMetrics.earlyLeaveCount, 8));
        chipRow.appendChild(createChip('Overtime', timeMetrics.overtimeCount, 6));

        const insightWrap = document.createElement('div');
        insightWrap.className = 'hr-dept-insights';
        renderInsights({ kpis, timeMetrics }, insightWrap);

        const projectWrap = document.createElement('div');
        projectWrap.className = 'hr-dept-projects';
        const projectTitle = document.createElement('div');
        projectTitle.className = 'hr-project-title';
        projectTitle.textContent = 'Project mix';
        projectWrap.appendChild(projectTitle);

        const projectItems = (dept.projects && Array.isArray(dept.projects.items)) ? dept.projects.items : [];
        const projectTotal = (dept.projects && Number.isFinite(dept.projects.totalLogged))
          ? dept.projects.totalLogged
          : projectItems.reduce((sum, item) => sum + (Number(item.loggedCount) || 0), 0);

        if (projectItems.length === 0) {
          const empty = document.createElement('div');
          empty.className = 'text-muted';
          empty.textContent = 'No project breakdown available.';
          projectWrap.appendChild(empty);
        } else {
          projectItems.forEach((item) => {
            const label = item.label || item.code || 'Unassigned';
            const count = Number(item.loggedCount) || 0;
            const pct = projectTotal > 0 ? Math.round((count / projectTotal) * 100) : 0;
            const project = document.createElement('div');
            project.className = 'hr-project';
            const head = document.createElement('div');
            head.className = 'hr-project-head';
            const name = document.createElement('span');
            name.textContent = label;
            const value = document.createElement('span');
            value.textContent = count;
            head.appendChild(name);
            head.appendChild(value);
            const bar = document.createElement('div');
            bar.className = 'hr-project-bar';
            bar.style.setProperty('--pct', pct);
            project.appendChild(head);
            project.appendChild(bar);
            projectWrap.appendChild(project);
          });
        }

        const sampleNote = document.createElement('div');
        sampleNote.className = 'hr-dept-sample';
        sampleNote.textContent = `Pulse sample: ${dept.meta && dept.meta.sampleCount ? dept.meta.sampleCount : 0}`;

        card.appendChild(header);
        card.appendChild(coverageWrap);
        card.appendChild(chipRow);
        card.appendChild(insightWrap);
        card.appendChild(projectWrap);
        card.appendChild(sampleNote);
        container.appendChild(card);
      });
    };

    const fetchSection = (section) => {
      const params = new URLSearchParams(baseParams);
      params.set('ajax', '1');
      params.set('ajax_section', section);
      return fetch(`${baseUrl}?${params.toString()}`, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        });
    };

    const loadSummary = () => {
      fetchSection('summary')
        .then((data) => {
          if (!data) return;
          const kpis = data.kpis || {};
          const timeMetrics = data.timeMetrics || {};

          setText('kpiActive', kpis.activeCount !== null ? kpis.activeCount : '-');
          setText('kpiLogged', kpis.loggedCount !== null ? kpis.loggedCount : '-');
          setText('kpiLate', timeMetrics.lateCount ?? '-');
          setText('kpiEarly', timeMetrics.earlyLeaveCount ?? '-');
          setText('kpiOvertime', timeMetrics.overtimeCount ?? '-');

          if (kpis.coveragePercent !== null) {
            setText('kpiCoverage', `Coverage ${kpis.coveragePercent}%`);
          } else {
            setText('kpiCoverage', 'Coverage n/a');
          }

          if (kpis.deviceOnline !== null && kpis.deviceTotal !== null) {
            setText('kpiDevices', `${kpis.deviceOnline} / ${kpis.deviceTotal}`);
          } else {
            setText('kpiDevices', '-');
          }

          setPill('avgFirst', `Avg first login: ${timeMetrics.avgFirst || '-'}`);
          setPill('avgLast', `Avg last login: ${timeMetrics.avgLast || '-'}`);

          if (data.meta) {
            setPill('pulseDatePill', `Daily pulse: ${data.meta.pulseDate}`);
            if (data.meta.sampled) {
              setPill('sampleNotePill', `Sampled ${data.meta.sampleCount} of ${data.meta.totalCount}`);
            } else {
              setPill('sampleNotePill', `Loaded ${data.meta.sampleCount}`);
            }
          }

          if (data.arrivalBuckets) {
            updateChart('arrival', () => createBarChart(
              document.getElementById('arrivalChart').getContext('2d'),
              data.arrivalBuckets.labels || [],
              data.arrivalBuckets.counts || [],
              [palette.teal, palette.blue, palette.gold, palette.rose]
            ));
          }

          if (data.projects) {
            updateChart('projects', () => createDonutChart(
              document.getElementById('projectChart').getContext('2d'),
              data.projects.labels || [],
              data.projects.counts || [],
              [palette.blue, palette.gold, palette.orange, palette.violet, palette.teal, palette.rose, '#94a3b8']
            ));
          }

          const completion = timeMetrics.completionRate ?? 0;
          updateChart('completion', () => createCompletionChart(
            document.getElementById('completionChart').getContext('2d'),
            completion
          ));

          renderRecent(data.recent || []);
          renderInsights(data);
        })
        .catch(() => {
          setText('kpiActive', '-');
        });
    };

    const loadTrend = () => {
      fetchSection('trend')
        .then((data) => {
          if (!data) return;
          updateChart('trend', () => createLineChart(
            document.getElementById('trendChart').getContext('2d'),
            data.labels || [],
            data.values || []
          ));
          setPill('trendAvg', `Avg: ${data.avg ?? '-'}`);
          if (data.delta !== null && data.delta !== undefined) {
            const sign = data.delta >= 0 ? '+' : '';
            setPill('trendDelta', `Delta: ${sign}${data.delta}`);
          }
        })
        .catch(() => {
          setPill('trendAvg', 'Avg: -');
        });
    };

    const loadDepartments = () => {
      fetchSection('departments')
        .then((data) => {
          if (!data) return;
          renderDepartments(data);
        })
        .catch(() => {
          const container = document.getElementById('deptInsights');
          if (container) {
            container.innerHTML = '<div class="text-muted">Department insights unavailable.</div>';
          }
        });
    };

    const bindQuickRanges = () => {
      const buttons = document.querySelectorAll('[data-range]');
      buttons.forEach((button) => {
        button.addEventListener('click', () => {
          const range = button.getAttribute('data-range');
          const startField = document.getElementById('startDate');
          const endField = document.getElementById('endDate');
          if (!startField || !endField) return;

          const today = new Date();
          const endDate = today.toISOString().slice(0, 10);
          let startDate = endDate;
          if (range === 'today') {
            startDate = endDate;
          } else {
            const days = parseInt(range, 10);
            if (!Number.isNaN(days)) {
              const start = new Date(today);
              start.setDate(start.getDate() - (days - 1));
              startDate = start.toISOString().slice(0, 10);
            }
          }
          startField.value = startDate;
          endField.value = endDate;
        });
      });
    };

    const bindToggles = () => {
      const root = document.documentElement;
      const focusToggle = document.getElementById('toggleFocus');
      const motionToggle = document.getElementById('toggleMotion');

      const applyStored = () => {
        if (localStorage.getItem('hrFocus') === '1') {
          root.classList.add('hr-focus');
        }
        if (localStorage.getItem('hrMotion') === '1') {
          root.classList.add('hr-reduce-motion');
        }
      };
      applyStored();

      if (focusToggle) {
        focusToggle.addEventListener('click', () => {
          root.classList.toggle('hr-focus');
          localStorage.setItem('hrFocus', root.classList.contains('hr-focus') ? '1' : '0');
        });
      }
      if (motionToggle) {
        motionToggle.addEventListener('click', () => {
          root.classList.toggle('hr-reduce-motion');
          localStorage.setItem('hrMotion', root.classList.contains('hr-reduce-motion') ? '1' : '0');
        });
      }
    };

    bindQuickRanges();
    bindToggles();
    loadSummary();
    loadTrend();
    loadDepartments();
  });
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
