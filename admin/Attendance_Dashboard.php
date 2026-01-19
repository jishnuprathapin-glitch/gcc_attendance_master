<?php
require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Attendance Dashboard';
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? ($_SESSION['usr_type'] ?? '');
$apiBaseUrl = attendance_api_base();
$docsUrl = '/gcc_attendance_master/docs/attendance-UTIME-api.md';
$flash = get_flash();

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

function build_query(array $params): string {
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        $filtered[$key] = $value;
    }
    return http_build_query($filtered);
}

function safe_filename(string $value): string {
    $cleaned = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
    $cleaned = trim((string) $cleaned, '_');
    return $cleaned !== '' ? $cleaned : 'export';
}

function normalize_bool_flag($value): ?bool {
    if (is_bool($value)) {
        return $value;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = strtolower($value);
    if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'y') {
        return true;
    }
    if ($value === '0' || $value === 'false' || $value === 'no' || $value === 'n') {
        return false;
    }
    return null;
}

function hrms_date_key(?string $value, ?DateTimeZone $localTz = null): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
    if ($localTz instanceof DateTimeZone) {
        $dt = $dt->setTimezone($localTz);
    }
    return $dt->format('Y-m-d');
}

function csv_download(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        return;
    }
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function export_error(string $message): void {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo $message;
    exit;
}

function load_onboarded_users(string $deviceSnParam): array {
    $params = [];
    if ($deviceSnParam !== '') {
        $params['deviceSn'] = $deviceSnParam;
    }
    $result = attendance_api_get('onboarded/users', $params, 20);
    if (!$result['ok'] || !is_array($result['data'])) {
        return [
            'ok' => false,
            'error' => $result['error'] ?? 'request_failed',
            'url' => $result['url'] ?? null,
            'map' => [],
        ];
    }
    $map = [];
    foreach ($result['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $badge = trim((string) ($row['badgeNumber'] ?? ''));
        if ($badge === '') {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $map[$badge] = $name;
    }
    return ['ok' => true, 'map' => $map];
}

function load_hrms_employee_details(array $employeeCodes, int $chunkSize = 100): array {
    $uniqueCodes = [];
    foreach ($employeeCodes as $code) {
        $code = trim((string) $code);
        if ($code === '') {
            continue;
        }
        $uniqueCodes[$code] = true;
    }
    if (empty($uniqueCodes)) {
        return ['ok' => true, 'map' => []];
    }
    $map = [];
    $allOk = true;
    $chunks = array_chunk(array_keys($uniqueCodes), max(1, $chunkSize));
    foreach ($chunks as $chunk) {
        $result = hrms_api_post_json('/api/employees/details', array_values($chunk), 20);
        if (!$result['ok'] || !is_array($result['data'])) {
            $allOk = false;
            continue;
        }
        $rows = $result['data'];
        if (isset($rows['employees']) && is_array($rows['employees'])) {
            $rows = $rows['employees'];
        } elseif (isset($rows['rows']) && is_array($rows['rows'])) {
            $rows = $rows['rows'];
        } elseif (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }
        if (!is_array($rows)) {
            $allOk = false;
            continue;
        }
        if (array_values($rows) !== $rows) {
            $rows = array_values($rows);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $employee = (isset($row['employee']) && is_array($row['employee'])) ? $row['employee'] : $row;
            $code = trim((string) ($employee['EMP_CODE']
                ?? $employee['empCode']
                ?? $employee['employeeCode']
                ?? $employee['code']
                ?? $row['EMP_CODE']
                ?? $row['empCode']
                ?? $row['employeeCode']
                ?? $row['code']
                ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($employee['EMP_NAME']
                ?? $employee['empName']
                ?? $employee['name']
                ?? $row['EMP_NAME']
                ?? $row['empName']
                ?? $row['name']
                ?? ''));
            $firstName = trim((string) ($employee['EMP_FIRSTNAME']
                ?? $employee['empFirstName']
                ?? $employee['firstName']
                ?? $row['EMP_FIRSTNAME']
                ?? $row['empFirstName']
                ?? $row['firstName']
                ?? ''));
            $lastName = trim((string) ($employee['EMP_LASTNAME']
                ?? $employee['empLastName']
                ?? $employee['lastName']
                ?? $row['EMP_LASTNAME']
                ?? $row['empLastName']
                ?? $row['lastName']
                ?? ''));
            if ($name === '') {
                $fullName = trim(trim($firstName) . ' ' . trim($lastName));
                if ($fullName !== '') {
                    $name = $fullName;
                }
            }
            $companyCode = trim((string) ($employee['EMP_COMPCD']
                ?? $employee['empCompanyCode']
                ?? $employee['companyCode']
                ?? $row['EMP_COMPCD']
                ?? $row['empCompanyCode']
                ?? $row['companyCode']
                ?? ''));
            $departmentCode = trim((string) ($employee['EMP_DEPT_CD']
                ?? $employee['empDeptCode']
                ?? $employee['departmentCode']
                ?? $row['EMP_DEPT_CD']
                ?? $row['empDeptCode']
                ?? $row['departmentCode']
                ?? ''));
            $department = trim((string) ($employee['DEPT_NAME']
                ?? $employee['deptName']
                ?? $employee['department']
                ?? $employee['EMP_DEPT_CD']
                ?? $row['DEPT_NAME']
                ?? $row['deptName']
                ?? $row['department']
                ?? $row['EMP_DEPT_CD']
                ?? ''));
            $designationCode = trim((string) ($employee['EMP_DESG_CD']
                ?? $employee['empDesgCode']
                ?? $employee['designationCode']
                ?? $row['EMP_DESG_CD']
                ?? $row['empDesgCode']
                ?? $row['designationCode']
                ?? ''));
            $designation = trim((string) ($employee['DESG_NAME']
                ?? $employee['desgName']
                ?? $employee['designation']
                ?? $employee['EMP_DESG_CD']
                ?? $row['DESG_NAME']
                ?? $row['desgName']
                ?? $row['designation']
                ?? $row['EMP_DESG_CD']
                ?? ''));
            $status = trim((string) ($employee['EMP_STATUS']
                ?? $employee['status']
                ?? $row['EMP_STATUS']
                ?? $row['status']
                ?? ''));
            $workTypeCode = trim((string) ($employee['TD_WT']
                ?? $employee['workTypeCode']
                ?? $employee['wtCode']
                ?? $row['TD_WT']
                ?? $row['workTypeCode']
                ?? $row['wtCode']
                ?? ''));
            $workTypeDesc = trim((string) ($employee['WT_DESC']
                ?? $employee['workTypeDescription']
                ?? $employee['wtDesc']
                ?? $row['WT_DESC']
                ?? $row['workTypeDescription']
                ?? $row['wtDesc']
                ?? ''));
            $todayWorking = normalize_bool_flag($employee['TODAY_WORKING']
                ?? $employee['todayWorking']
                ?? $row['TODAY_WORKING']
                ?? $row['todayWorking']
                ?? null);
            $onEleave = normalize_bool_flag($employee['ON_ELEAVE']
                ?? $employee['onEleave']
                ?? $employee['onEL'] 
                ?? $row['ON_ELEAVE']
                ?? $row['onEleave']
                ?? $row['onEL']
                ?? null);
            $leaveCode = trim((string) ($employee['LEAVE_CODE']
                ?? $employee['leaveCode']
                ?? $row['LEAVE_CODE']
                ?? $row['leaveCode']
                ?? ''));
            $leaveDesc = trim((string) ($employee['LEAVE_DESC']
                ?? $employee['leaveDescription']
                ?? $employee['leaveDesc']
                ?? $row['LEAVE_DESC']
                ?? $row['leaveDescription']
                ?? $row['leaveDesc']
                ?? ''));

            $map[$code] = [
                'name' => $name,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'companyCode' => $companyCode,
                'departmentCode' => $departmentCode,
                'department' => $department,
                'designationCode' => $designationCode,
                'designation' => $designation,
                'status' => $status,
                'workTypeCode' => $workTypeCode,
                'workTypeDescription' => $workTypeDesc,
                'todayWorking' => $todayWorking,
                'onEleave' => $onEleave,
                'leaveCode' => $leaveCode,
                'leaveDescription' => $leaveDesc,
            ];
        }
    }
    return ['ok' => $allOk, 'map' => $map];
}

function load_logged_in_badges(
    string $startDateTime,
    string $endDateTime,
    string $deviceSnParam,
    bool $includeHrmsDetails = false
): array {
    $result = attendance_api_get('attendance/badges/with-names', [
        'startDate' => $startDateTime,
        'endDate' => $endDateTime,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
    ], 20);
    if (!$result['ok'] || !is_array($result['data'])) {
        return [
            'ok' => false,
            'rows' => [],
            'total' => 0,
            'error' => $result['error'] ?? 'request_failed',
        ];
    }
    $rows = $result['data']['rows'] ?? null;
    if (!is_array($rows)) {
        $rows = [];
    }
    $badgeRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $badge = trim((string) ($row['badgeNumber'] ?? ''));
        if ($badge === '') {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if (!isset($badgeRows[$badge]) || ($badgeRows[$badge] === '' && $name !== '')) {
            $badgeRows[$badge] = $name;
        }
    }
    $badgeNumbers = array_keys($badgeRows);
    sort($badgeNumbers, SORT_NATURAL);
    $detailsMap = [];
    $hrmsOk = true;
    if ($includeHrmsDetails && !empty($badgeNumbers)) {
        $detailsResult = load_hrms_employee_details($badgeNumbers);
        $hrmsOk = $detailsResult['ok'];
        if ($detailsResult['ok'] && isset($detailsResult['map']) && is_array($detailsResult['map'])) {
            $detailsMap = $detailsResult['map'];
        }
    }
    $list = [];
    foreach ($badgeNumbers as $badge) {
        $name = $badgeRows[$badge] ?? '';
        $department = '';
        $departmentCode = '';
        $designation = '';
        $designationCode = '';
        $status = '';
        $companyCode = '';
        $firstName = '';
        $lastName = '';
        $workTypeCode = '';
        $workTypeDescription = '';
        $todayWorking = null;
        $onEleave = null;
        $leaveCode = '';
        $leaveDescription = '';
        if (isset($detailsMap[$badge]) && is_array($detailsMap[$badge])) {
            $details = $detailsMap[$badge];
            $hrmsName = trim((string) ($details['name'] ?? ''));
            if ($hrmsName !== '') {
                $name = $hrmsName;
            }
            $department = trim((string) ($details['department'] ?? ''));
            $departmentCode = trim((string) ($details['departmentCode'] ?? ''));
            $designation = trim((string) ($details['designation'] ?? ''));
            $designationCode = trim((string) ($details['designationCode'] ?? ''));
            $status = trim((string) ($details['status'] ?? ''));
            $companyCode = trim((string) ($details['companyCode'] ?? ''));
            $firstName = trim((string) ($details['firstName'] ?? ''));
            $lastName = trim((string) ($details['lastName'] ?? ''));
            $workTypeCode = trim((string) ($details['workTypeCode'] ?? ''));
            $workTypeDescription = trim((string) ($details['workTypeDescription'] ?? ''));
            $todayWorking = $details['todayWorking'] ?? null;
            $onEleave = $details['onEleave'] ?? null;
            $leaveCode = trim((string) ($details['leaveCode'] ?? ''));
            $leaveDescription = trim((string) ($details['leaveDescription'] ?? ''));
        }
        $list[] = [
            'badgeNumber' => $badge,
            'name' => $name,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'companyCode' => $companyCode,
            'departmentCode' => $departmentCode,
            'department' => $department,
            'designationCode' => $designationCode,
            'designation' => $designation,
            'status' => $status,
            'workTypeCode' => $workTypeCode,
            'workTypeDescription' => $workTypeDescription,
            'todayWorking' => $todayWorking,
            'onEleave' => $onEleave,
            'leaveCode' => $leaveCode,
            'leaveDescription' => $leaveDescription,
        ];
    }
    return [
        'ok' => true,
        'rows' => $list,
        'total' => count($list),
        'hrmsOk' => $hrmsOk,
        'error' => null,
    ];
}

function load_hrms_active_employees(): array {
    $result = hrms_api_get('/api/employees/active', [], 20);
    if (!$result['ok'] || !is_array($result['data'])) {
        return [
            'ok' => false,
            'error' => $result['error'] ?? 'request_failed',
            'url' => $result['url'] ?? null,
            'map' => [],
            'rows' => [],
        ];
    }
    $employees = $result['data']['employees'] ?? null;
    if (!is_array($employees)) {
        return [
            'ok' => false,
            'error' => 'missing_employees_payload',
            'url' => $result['url'] ?? null,
            'map' => [],
            'rows' => [],
        ];
    }
    $map = [];
    $rows = [];
    foreach ($employees as $employee) {
        if (!is_array($employee)) {
            continue;
        }
        $code = trim((string) ($employee['EMP_CODE'] ?? ''));
        if ($code === '') {
            continue;
        }
        $name = trim((string) ($employee['EMP_NAME'] ?? ''));
        $map[$code] = $name;
        $rows[] = ['code' => $code, 'name' => $name];
    }
    return ['ok' => true, 'map' => $map, 'rows' => $rows];
}

function build_hrms_summary(string $employeeCode, string $startDate, string $endDate): array {
    $summary = [
        'employeeName' => null,
        'department' => null,
        'designation' => null,
        'status' => null,
        'attendanceDays' => 0,
        'attendanceError' => null,
        'leaveDays' => 0,
        'lastAttendance' => null,
        'holidayCount' => 0,
    ];

    if ($employeeCode === '') {
        return ['summary' => $summary, 'error' => null];
    }

    $hrmsResult = hrms_api_get('/api/employees/' . rawurlencode($employeeCode) . '/activity', [
        'fromdate' => $startDate,
        'todate' => $endDate,
    ]);
    if (!$hrmsResult['ok'] || !is_array($hrmsResult['data'])) {
        return ['summary' => $summary, 'error' => $hrmsResult['error'] ?: 'HRMS request failed'];
    }

    $hrmsData = $hrmsResult['data'];
    $employee = $hrmsData['employee'] ?? [];
    if (is_array($employee)) {
        $employeeName = trim((string) ($employee['EMP_NAME'] ?? ''));
        $summary['employeeName'] = $employeeName !== '' ? $employeeName : $employeeCode;
        $department = trim((string) ($employee['DEPT_NAME'] ?? ''));
        if ($department === '') {
            $department = trim((string) ($employee['EMP_DEPT_CD'] ?? ''));
        }
        $summary['department'] = $department !== '' ? $department : null;
        $designation = trim((string) ($employee['DESG_NAME'] ?? ''));
        if ($designation === '') {
            $designation = trim((string) ($employee['EMP_DESG_CD'] ?? ''));
        }
        $summary['designation'] = $designation !== '' ? $designation : null;
        $status = trim((string) ($employee['EMP_STATUS'] ?? ''));
        $summary['status'] = $status !== '' ? $status : null;
    }

    $localTz = null;
    try {
        $localTz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    } catch (Exception $e) {
        $localTz = null;
    }

    $attendanceStart = $startDate . 'T00:00:00Z';
    $attendanceEnd = $endDate . 'T23:59:59Z';
    $attendanceResult = employee_attendance_api_get('attendance', [
        'badgeNumber' => $employeeCode,
        'startDate' => $attendanceStart,
        'endDate' => $attendanceEnd,
    ], 12);
    if (!$attendanceResult['ok'] || !is_array($attendanceResult['data'])) {
        $summary['attendanceError'] = $attendanceResult['error'] ?: 'Attendance request failed';
    } else {
        $attendancePayload = $attendanceResult['data'];
        $attendanceRows = [];
        if (is_array($attendancePayload)) {
            if (isset($attendancePayload['rows']) && is_array($attendancePayload['rows'])) {
                $attendanceRows = $attendancePayload['rows'];
            } elseif (array_values($attendancePayload) === $attendancePayload) {
                $attendanceRows = $attendancePayload;
            }
        }
        if (!empty($attendanceRows)) {
            $attendanceDays = [];
            $latestDate = null;
            foreach ($attendanceRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $dateValue = $row['checktime']
                    ?? ($row['checkTime']
                    ?? ($row['punchTime']
                    ?? ($row['date']
                    ?? ($row['attendanceDate']
                    ?? ($row['TD_DATE'] ?? null)))));
                $dateKey = hrms_date_key($dateValue, $localTz);
                if ($dateKey === null) {
                    continue;
                }
                if ($dateKey < $startDate || $dateKey > $endDate) {
                    continue;
                }
                $attendanceDays[$dateKey] = true;
                if ($latestDate === null || $dateKey > $latestDate) {
                    $latestDate = $dateKey;
                }
            }
            $summary['attendanceDays'] = count($attendanceDays);
            $summary['lastAttendance'] = $latestDate;
        }
    }

    $leave = $hrmsData['leave'] ?? [];
    if (is_array($leave)) {
        $leaveDates = [];
        foreach ($leave as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fromDate = hrms_date_key($row['LV_DT_FROM'] ?? null, $localTz);
            $toDate = hrms_date_key($row['LV_DT_TO'] ?? ($row['LV_DT_FROM'] ?? null), $localTz);
            if ($fromDate === null) {
                continue;
            }
            if ($toDate === null) {
                $toDate = $fromDate;
            }
            if ($fromDate > $toDate) {
                $tmp = $fromDate;
                $fromDate = $toDate;
                $toDate = $tmp;
            }
            $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate);
            $end = DateTimeImmutable::createFromFormat('Y-m-d', $toDate);
            if (!$cursor || !$end) {
                continue;
            }
            while ($cursor <= $end) {
                $dateKey = $cursor->format('Y-m-d');
                if ($dateKey >= $startDate && $dateKey <= $endDate) {
                    $leaveDates[$dateKey] = true;
                }
                $cursor = $cursor->modify('+1 day');
            }
        }
        $summary['leaveDays'] = count($leaveDates);
    }

    $holidays = $hrmsData['publicHolidays'] ?? [];
    if (is_array($holidays)) {
        $holidayDates = [];
        foreach ($holidays as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dateKey = hrms_date_key($row['CL_DATE'] ?? null, $localTz);
            if ($dateKey === null) {
                continue;
            }
            if ($dateKey < $startDate || $dateKey > $endDate) {
                continue;
            }
            $holidayDates[$dateKey] = true;
        }
        $summary['holidayCount'] = count($holidayDates);
    }

    return ['summary' => $summary, 'error' => null];
}

$today = new DateTimeImmutable('today');
$defaultDate = $today->format('Y-m-d');
$startDate = normalize_date($_GET['startDate'] ?? null, $defaultDate);
$endDate = normalize_date($_GET['endDate'] ?? null, $defaultDate);
if ($startDate > $endDate) {
    $tmp = $startDate;
    $startDate = $endDate;
    $endDate = $tmp;
}
$startDateTime = $startDate . 'T00:00:00';
$endDateTime = $endDate . 'T24:00:00';

$deviceSnInput = trim((string) ($_GET['deviceSn'] ?? ''));
$projectId = trim((string) ($_GET['projectId'] ?? ''));
$employeeCode = trim((string) ($_GET['employeeCode'] ?? ''));

$isAjax = ($_GET['ajax'] ?? '') === '1';
$ajaxSection = strtolower(trim((string) ($_GET['ajax_section'] ?? '')));
if ($isAjax && $ajaxSection === 'hrms') {
    $hrmsPayload = build_hrms_summary($employeeCode, $startDate, $endDate);
    $payload = [
        'hrms' => [
            'enabled' => $employeeCode !== '',
            'error' => $hrmsPayload['error'],
            'summary' => $hrmsPayload['summary'],
        ],
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$deviceMap = [];
$devicesByProject = [];
$projects = [];

if (isset($bd) && $bd instanceof mysqli) {
    $projectResult = $bd->query('SELECT id, name, pro_code FROM gcc_it.projects ORDER BY pro_code');
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $projects[] = $row;
        }
        $projectResult->free();
    }

    $deviceResult = $bd->query(
        'SELECT m.device_sn, m.device_name, m.project_id, p.name AS project_name, p.pro_code ' .
        'FROM gcc_attendance_master.device_project_map m ' .
        'LEFT JOIN gcc_it.projects p ON p.id = m.project_id ' .
        'ORDER BY m.device_sn'
    );
    if ($deviceResult) {
        while ($row = $deviceResult->fetch_assoc()) {
            $sn = (string) ($row['device_sn'] ?? '');
            if ($sn === '') {
                continue;
            }
            $deviceMap[$sn] = $row;
            $projectKey = $row['project_id'] !== null ? (string) $row['project_id'] : 'unassigned';
            if (!isset($devicesByProject[$projectKey])) {
                $devicesByProject[$projectKey] = [];
            }
            $devicesByProject[$projectKey][] = $sn;
        }
        $deviceResult->free();
    }
}

$deviceSnList = [];
if ($deviceSnInput !== '') {
    foreach (explode(',', $deviceSnInput) as $sn) {
        $sn = trim($sn);
        if ($sn !== '') {
            $deviceSnList[] = $sn;
        }
    }
} elseif ($projectId !== '' && isset($devicesByProject[$projectId])) {
    $deviceSnList = $devicesByProject[$projectId];
}
$deviceSnParam = !empty($deviceSnList) ? implode(',', $deviceSnList) : '';
$deviceScope = 'all';
if (!empty($deviceSnList)) {
    $deviceScope = 'selected';
} elseif ($projectId !== '' || $deviceSnInput !== '') {
    $deviceScope = 'none';
}
$deviceScopeLabel = $deviceScope === 'selected' ? 'Selected devices' : 'All devices';
$deviceScopeNote = '';
if ($deviceScope === 'none') {
    $deviceScopeLabel = 'No devices';
    $deviceScopeNote = $projectId !== '' ? 'No devices mapped to the selected project.' : 'No matching devices found.';
}
$exportType = strtolower(trim((string) ($_GET['export'] ?? '')));
if ($exportType !== '') {
    if ($deviceScope === 'none' && $exportType !== 'hrms-active-employees') {
        export_error($deviceScopeNote !== '' ? $deviceScopeNote : 'No devices matched the selected filter.');
    }
    $rangeTag = $startDate . '_to_' . $endDate;
    if ($projectId !== '') {
        $rangeTag .= '_project_' . $projectId;
    }
    $filenameTag = safe_filename($rangeTag);

    if ($exportType === 'logged-in-badges') {
        $loggedBadgesResult = load_logged_in_badges($startDateTime, $endDateTime, $deviceSnParam, true);
        if (!$loggedBadgesResult['ok']) {
            export_error('Unable to load UTime badge list for the selected range.');
        }
        $rows = [];
        foreach ($loggedBadgesResult['rows'] as $row) {
            $todayWorkingFlag = normalize_bool_flag($row['todayWorking'] ?? null);
            $onEleaveFlag = normalize_bool_flag($row['onEleave'] ?? null);
            $rows[] = [
                $row['badgeNumber'],
                $row['name'] ?? '',
                $row['department'] ?? '',
                $row['designation'] ?? '',
                $row['status'] ?? '',
                $row['workTypeCode'] ?? '',
                $row['workTypeDescription'] ?? '',
                $row['companyCode'] ?? '',
                $row['departmentCode'] ?? '',
                $row['designationCode'] ?? '',
                $row['firstName'] ?? '',
                $row['lastName'] ?? '',
                ($todayWorkingFlag === true ? 'Yes' : ($todayWorkingFlag === false ? 'No' : '')),
                ($onEleaveFlag === true ? 'Yes' : ($onEleaveFlag === false ? 'No' : '')),
                $row['leaveCode'] ?? '',
                $row['leaveDescription'] ?? '',
            ];
        }
        $filename = 'logged-in-badges-' . $filenameTag . '.csv';
        csv_download(
            $filename,
            [
                'BadgeNumber',
                'EmployeeName',
                'Department',
                'Designation',
                'Status',
                'WorkTypeCode',
                'WorkTypeDescription',
                'CompanyCode',
                'DepartmentCode',
                'DesignationCode',
                'FirstName',
                'LastName',
                'TodayWorking',
                'OnEleave',
                'LeaveCode',
                'LeaveDescription',
            ],
            $rows
        );
        exit;
    }

    if ($exportType === 'hrms-active-employees') {
        $hrmsActive = load_hrms_active_employees();
        if (!$hrmsActive['ok']) {
            export_error('Unable to load HRMS active employees.');
        }
        $rows = [];
        foreach ($hrmsActive['rows'] as $employee) {
            $rows[] = [$employee['code'], $employee['name']];
        }
        $filename = 'hrms-active-employees-' . $filenameTag . '.csv';
        csv_download($filename, ['EmployeeCode', 'EmployeeName'], $rows);
        exit;
    }

    if ($exportType === 'hrms-active-missing-utime') {
        $hrmsActive = load_hrms_active_employees();
        if (!$hrmsActive['ok']) {
            export_error('Unable to load HRMS active employees.');
        }
        $onboarded = load_onboarded_users($deviceSnParam);
        if (!$onboarded['ok']) {
            export_error('Unable to load UTime onboarded users.');
        }
        $rows = [];
        foreach ($hrmsActive['map'] as $code => $name) {
            if (!isset($onboarded['map'][$code])) {
                $rows[] = [$code, $name];
            }
        }
        $filename = 'hrms-active-not-onboarded-utime-' . $filenameTag . '.csv';
        csv_download($filename, ['EmployeeCode', 'EmployeeName'], $rows);
        exit;
    }

    if ($exportType === 'utime-missing-hrms-active') {
        $hrmsActive = load_hrms_active_employees();
        if (!$hrmsActive['ok']) {
            export_error('Unable to load HRMS active employees.');
        }
        $onboarded = load_onboarded_users($deviceSnParam);
        if (!$onboarded['ok']) {
            export_error('Unable to load UTime onboarded users.');
        }
        $rows = [];
        foreach ($onboarded['map'] as $badge => $name) {
            if (!isset($hrmsActive['map'][$badge])) {
                $rows[] = [$badge, $name];
            }
        }
        $filename = 'utime-onboarded-not-hrms-active-' . $filenameTag . '.csv';
        csv_download($filename, ['BadgeNumber', 'EmployeeName'], $rows);
        exit;
    }

    export_error('Unknown export type requested.');
}
$showActiveEmployeesRatio = ($projectId === '');
$lazyMode = ($_GET['lazy'] ?? '') !== '0';
$loadDataNow = $isAjax || !$lazyMode;

$apiErrors = [];

if ($loadDataNow) {
$badgeCountOk = false;
$uniqueBadgeCount = 0;
if ($deviceScope === 'none') {
    $badgeCountOk = true;
} else {
    $badgeCountResult = attendance_api_get('attendance/badges/count', [
        'startDate' => $startDateTime,
        'endDate' => $endDateTime,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
    ]);
    if ($badgeCountResult['ok'] && is_array($badgeCountResult['data'])) {
        $badgeCountOk = true;
        $uniqueBadgeCount = (int) ($badgeCountResult['data']['total'] ?? 0);
    } else {
        $apiErrors[] = 'Badge count';
    }
}

$activeEmployeesOk = false;
$activeEmployeesCount = 0;
if ($showActiveEmployeesRatio) {
    $activeEmployeesResult = hrms_api_get('/api/employees/active/count');
    if ($activeEmployeesResult['ok'] && is_array($activeEmployeesResult['data'])) {
        $activeEmployeesOk = true;
        $activeEmployeesCount = (int) ($activeEmployeesResult['data']['count'] ?? ($activeEmployeesResult['data']['total'] ?? 0));
    } else {
        $activeEmployeesFallback = hrms_api_get('/api/employees/active');
        if ($activeEmployeesFallback['ok'] && is_array($activeEmployeesFallback['data'])) {
            $activeEmployeesOk = true;
            $activeEmployeesCount = (int) ($activeEmployeesFallback['data']['count'] ?? 0);
            if ($activeEmployeesCount === 0) {
                $employees = $activeEmployeesFallback['data']['employees'] ?? null;
                if (is_array($employees)) {
                    $activeEmployeesCount = count($employees);
                }
            }
        } else {
            $apiErrors[] = 'HRMS active count';
        }
    }
}

$badgeCoveragePercent = null;
if ($showActiveEmployeesRatio && $badgeCountOk && $activeEmployeesOk && $activeEmployeesCount > 0) {
    $badgeCoveragePercent = (int) round(($uniqueBadgeCount / $activeEmployeesCount) * 100);
}
$badgeRatioText = $badgeCountOk
    ? ($showActiveEmployeesRatio && $activeEmployeesOk ? $uniqueBadgeCount . ' / ' . $activeEmployeesCount : (string) $uniqueBadgeCount)
    : '-';
$badgeCoverageLabel = $showActiveEmployeesRatio
    ? ($badgeCoveragePercent !== null ? $badgeCoveragePercent . '% logged in' : 'Coverage n/a')
    : 'Unique badges in range';
$badgeCardTitle = $showActiveEmployeesRatio ? 'Logged in / active employees' : 'Logged in employees';

$loggedBadgesOk = false;
$loggedBadgesRows = [];
$loggedBadgesCount = 0;
$loggedBadgesNote = '';
if ($deviceScope === 'none') {
    $loggedBadgesOk = true;
    $loggedBadgesNote = $deviceScopeNote;
} else {
    $loggedBadgesResult = load_logged_in_badges($startDateTime, $endDateTime, $deviceSnParam, true);
    if ($loggedBadgesResult['ok']) {
        $loggedBadgesOk = true;
        $loggedBadgesRows = $loggedBadgesResult['rows'];
        $loggedBadgesCount = (int) ($loggedBadgesResult['total'] ?? count($loggedBadgesRows));
        if (isset($loggedBadgesResult['hrmsOk']) && $loggedBadgesResult['hrmsOk'] === false) {
            $apiErrors[] = 'HRMS details';
        }
    } else {
        $apiErrors[] = 'Logged in badges';
    }
}
$loggedBadgesMeta = $loggedBadgesOk
    ? ($loggedBadgesNote !== '' ? $loggedBadgesNote : $loggedBadgesCount . ' badges')
    : 'Unable to load badges';
$loggedBadgesEmptyText = 'No logged in badges for the selected range.';
if (!$loggedBadgesOk) {
    $loggedBadgesEmptyText = 'Unable to load logged in badges.';
} elseif ($loggedBadgesNote !== '') {
    $loggedBadgesEmptyText = $loggedBadgesNote;
}

$dailyOk = false;
$dailyTotals = [];
$dailyLabel = $deviceScopeLabel;
$dailyNote = '';
if ($deviceScope === 'selected') {
    $dailyResult = attendance_api_get('attendance/daily/by-devices', [
        'deviceSn' => $deviceSnParam,
        'startDate' => $startDate,
        'endDate' => $endDate,
    ]);
    if ($dailyResult['ok'] && is_array($dailyResult['data'])) {
        $dailyOk = true;
        $rows = $dailyResult['data']['rows'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $date = (string) ($row['date'] ?? '');
                if ($date === '') {
                    continue;
                }
                $dailyTotals[$date] = (int) ($row['total'] ?? 0);
            }
        }
    } else {
        $apiErrors[] = 'Daily totals (devices)';
    }
} elseif ($deviceScope === 'none') {
    $dailyNote = $deviceScopeNote;
} else {
    $dailyNote = 'Select a device or project to load daily totals.';
}

$dailySeries = [];
foreach ($dailyTotals as $date => $total) {
    $dailySeries[] = ['date' => $date, 'total' => (int) $total];
}
usort($dailySeries, function (array $a, array $b): int {
    return strcmp($a['date'], $b['date']);
});
if (count($dailySeries) > 14) {
    $dailySeries = array_slice($dailySeries, -14);
    $dailyNote = 'Showing last 14 days';
}

$dailyMax = 0;
foreach ($dailySeries as $row) {
    if ($row['total'] > $dailyMax) {
        $dailyMax = $row['total'];
    }
}

$deviceCountsOk = false;
$deviceCounts = [];
if ($deviceScope === 'none') {
    $deviceCountsOk = true;
} else {
    $deviceCountsResult = attendance_api_get('attendance/counts', [
        'groupBy' => 'deviceSn',
        'startDate' => $startDateTime,
        'endDate' => $endDateTime,
    ]);
    if ($deviceCountsResult['ok'] && is_array($deviceCountsResult['data'])) {
        $deviceCountsOk = true;
        foreach ($deviceCountsResult['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sn = trim((string) ($row['value'] ?? ''));
            if ($sn === '') {
                continue;
            }
            if (!empty($deviceSnList) && !in_array($sn, $deviceSnList, true)) {
                continue;
            }
            $deviceCounts[$sn] = (int) ($row['total'] ?? 0);
        }
    } else {
        $apiErrors[] = 'Device counts';
    }
}

arsort($deviceCounts);
$activeDeviceCount = count($deviceCounts);

$deviceStatusOk = false;
$deviceStatusTotal = 0;
$deviceStatusCounts = [
    'online' => 0,
    'offline' => 0,
    'unknown' => 0,
];
$deviceStatusStartDate = $startDate;
$deviceStatusEndDate = $endDate;
$deviceStatusEnd = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
if ($deviceStatusEnd instanceof DateTimeImmutable) {
    $deviceStatusEndDate = $deviceStatusEnd->modify('+1 day')->format('Y-m-d');
}
if ($deviceScope !== 'none') {
    $deviceStatusResult = attendance_api_get('devices/status/counts', [
        'startDate' => $deviceStatusStartDate,
        'endDate' => $deviceStatusEndDate,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
    ]);
    if ($deviceStatusResult['ok'] && is_array($deviceStatusResult['data'])) {
        $deviceStatusData = $deviceStatusResult['data'];
        if (isset($deviceStatusData['counts']) && is_array($deviceStatusData['counts'])) {
            $deviceStatusData = $deviceStatusData['counts'];
        }
        $hasCounts = array_key_exists('totalActive', $deviceStatusData)
            || array_key_exists('totalInactive', $deviceStatusData)
            || array_key_exists('totalUnknown', $deviceStatusData)
            || array_key_exists('active', $deviceStatusData)
            || array_key_exists('inactive', $deviceStatusData)
            || array_key_exists('online', $deviceStatusData)
            || array_key_exists('offline', $deviceStatusData)
            || array_key_exists('unknown', $deviceStatusData)
            || array_key_exists('total', $deviceStatusData);
        if ($hasCounts) {
            $deviceStatusOk = true;
            $deviceStatusCounts['online'] = (int) ($deviceStatusData['totalActive'] ?? ($deviceStatusData['active'] ?? ($deviceStatusData['online'] ?? 0)));
            $deviceStatusCounts['offline'] = (int) ($deviceStatusData['totalInactive'] ?? ($deviceStatusData['inactive'] ?? ($deviceStatusData['offline'] ?? 0)));
            $deviceStatusCounts['unknown'] = (int) ($deviceStatusData['totalUnknown'] ?? ($deviceStatusData['unknown'] ?? 0));
            $deviceStatusTotal = (int) ($deviceStatusData['total']
                ?? ($deviceStatusCounts['online'] + $deviceStatusCounts['offline'] + $deviceStatusCounts['unknown']));
        }
    }
}
if ($deviceScope !== 'none' && !$deviceStatusOk) {
    $apiErrors[] = 'Device status';
}
$deviceStatusScope = $deviceScopeLabel;
$deviceStatusRatio = $deviceStatusOk ? ($deviceStatusCounts['online'] . ' / ' . $deviceStatusTotal) : '-';
$deviceStatusMeta = $deviceStatusOk
    ? ('Offline: ' . $deviceStatusCounts['offline'] . ' • Unknown: ' . $deviceStatusCounts['unknown'] . ' • ' . $deviceStatusScope)
    : 'Status data unavailable';
if ($deviceScope === 'none') {
    $deviceStatusOk = true;
    $deviceStatusRatio = '0 / 0';
    $deviceStatusMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
}

$hrmsError = null;
$hrmsSummary = [
    'employeeName' => null,
    'department' => null,
    'designation' => null,
    'status' => null,
    'attendanceDays' => 0,
    'leaveDays' => 0,
    'lastAttendance' => null,
    'holidayCount' => 0,
];

if ($employeeCode !== '') {
    $hrmsPayload = build_hrms_summary($employeeCode, $startDate, $endDate);
    $hrmsSummary = $hrmsPayload['summary'];
    $hrmsError = $hrmsPayload['error'];
}
} else {
    $badgeCountOk = false;
    $uniqueBadgeCount = 0;
    $activeEmployeesOk = false;
    $activeEmployeesCount = 0;
    $badgeCoveragePercent = null;
    $badgeRatioText = '...';
    $badgeCoverageLabel = 'Loading...';
    $badgeCardTitle = $showActiveEmployeesRatio ? 'Logged in / active employees' : 'Logged in employees';

    $loggedBadgesOk = false;
    $loggedBadgesRows = [];
    $loggedBadgesCount = 0;
    $loggedBadgesNote = '';
    if ($deviceScope === 'none') {
        $loggedBadgesOk = true;
        $loggedBadgesNote = $deviceScopeNote;
        $loggedBadgesMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
        $loggedBadgesEmptyText = $loggedBadgesMeta;
    } else {
        $loggedBadgesMeta = 'Loading logged in badges...';
        $loggedBadgesEmptyText = $loggedBadgesMeta;
    }

    $dailyOk = false;
    $dailyTotals = [];
    $dailyLabel = $deviceScopeLabel;
    if ($deviceScope === 'selected') {
        $dailyNote = '';
    } elseif ($deviceScope === 'none') {
        $dailyNote = $deviceScopeNote;
    } else {
        $dailyNote = 'Select a device or project to load daily totals.';
    }
    $dailySeries = [];
    $dailyMax = 0;

    $deviceCountsOk = false;
    $deviceCounts = [];
    $activeDeviceCount = 0;

    $deviceStatusOk = false;
    $deviceStatusTotal = 0;
    $deviceStatusCounts = [
        'online' => 0,
        'offline' => 0,
        'unknown' => 0,
    ];
    $deviceStatusScope = $deviceScopeLabel;
    $deviceStatusRatio = $deviceScope === 'none' ? '0 / 0' : '-';
    $deviceStatusMeta = $deviceScope === 'none'
        ? ($deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.')
        : 'Loading device status...';

$hrmsError = null;
$hrmsSummary = [
        'employeeName' => null,
        'department' => null,
        'designation' => null,
        'status' => null,
        'attendanceDays' => 0,
        'leaveDays' => 0,
        'lastAttendance' => null,
        'holidayCount' => 0,
    ];
}

if ($isAjax) {
    $payload = [
        'errors' => array_values(array_unique($apiErrors)),
        'summary' => [
            'badgeRatioText' => $badgeRatioText,
            'badgeCardTitle' => $badgeCardTitle,
            'badgeCoverageLabel' => $badgeCoverageLabel,
            'deviceStatusRatio' => $deviceStatusRatio,
            'deviceStatusMeta' => $deviceStatusMeta,
            'activeDeviceCountText' => $deviceCountsOk ? (string) $activeDeviceCount : '-',
        ],
        'daily' => [
            'note' => $dailyNote !== '' ? $dailyNote : $dailyLabel,
            'series' => $dailySeries,
        ],
        'loggedBadges' => [
            'ok' => $loggedBadgesOk,
            'note' => $loggedBadgesNote,
            'count' => $loggedBadgesCount,
            'rows' => $loggedBadgesRows,
        ],
        'hrms' => [
            'enabled' => $employeeCode !== '',
            'error' => $hrmsError,
            'summary' => $hrmsSummary,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$quickRanges = [
    [
        'label' => 'Today',
        'start' => $today->format('Y-m-d'),
        'end' => $today->format('Y-m-d'),
    ],
    [
        'label' => 'Last 7 days',
        'start' => $today->sub(new DateInterval('P6D'))->format('Y-m-d'),
        'end' => $today->format('Y-m-d'),
    ],
    [
        'label' => 'Last 30 days',
        'start' => $today->sub(new DateInterval('P29D'))->format('Y-m-d'),
        'end' => $today->format('Y-m-d'),
    ],
];

include __DIR__ . '/include/layout_top.php';

?>

<style>
  .dash-card {
    animation: fadeUp 0.5s ease both;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .trend-chart {
    display: grid;
    grid-auto-flow: column;
    gap: 10px;
    align-items: end;
    height: 180px;
    padding-bottom: 28px;
  }
  .trend-bar {
    background: linear-gradient(180deg, #2f80ed, #1b4f9a);
    border-radius: 8px 8px 4px 4px;
    position: relative;
    min-width: 14px;
  }
  .trend-bar span {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    font-size: 11px;
    color: #6c757d;
    white-space: nowrap;
  }
  .trend-bar .trend-label {
    bottom: -22px;
  }
  .trend-bar .trend-value {
    top: -18px;
    color: #1b4f9a;
    font-weight: 600;
  }
  .filter-help {
    font-size: 12px;
    color: #6c757d;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
    <div class="row mb-2">
      <div class="col-sm-7">
        <h1>Attendance Dashboard</h1>
      </div>
      <div class="col-sm-5 text-sm-right">
        <span class="badge badge-primary">Range: <?= h($startDate) ?> to <?= h($endDate) ?></span>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($apiErrors)): ?>
      <div id="apiErrors" class="alert alert-warning">
        Some data panels could not be refreshed: <?= h(implode(', ', array_unique($apiErrors))) ?>.
      </div>
    <?php else: ?>
      <div id="apiErrors" class="alert alert-warning d-none"></div>
    <?php endif; ?>

    <div class="row">
      <div class="col-lg-4 col-md-6">
        <div class="small-box bg-info dash-card" style="animation-delay: 0.1s;">
          <div class="inner">
            <h3 id="badgeRatioText"><?= h($badgeRatioText) ?></h3>
            <p id="badgeCardTitle"><?= h($badgeCardTitle) ?></p>
          </div>
          <div class="icon"><i class="fas fa-user-check"></i></div>
          <div id="badgeCoverageLabel" class="small text-white-50 px-3 pb-2"><?= h($badgeCoverageLabel) ?></div>
        </div>
      </div>
      <div class="col-lg-4 col-md-6">
        <div class="small-box bg-success dash-card" style="animation-delay: 0.15s;">
          <div class="inner">
            <h3 id="deviceStatusRatio"><?= h($deviceStatusRatio) ?></h3>
            <p>Online / total devices</p>
          </div>
          <div class="icon"><i class="fas fa-signal"></i></div>
          <div id="deviceStatusMeta" class="small text-white-50 px-3 pb-2"><?= h($deviceStatusMeta) ?></div>
        </div>
      </div>
      <div class="col-lg-4 col-md-6">
        <div class="small-box bg-warning dash-card" style="animation-delay: 0.2s;">
          <div class="inner">
            <h3 id="activeDeviceCount"><?= $deviceCountsOk ? h((string) $activeDeviceCount) : '-' ?></h3>
            <p>Active devices</p>
          </div>
          <div class="icon"><i class="fas fa-microchip"></i></div>
          <div class="small text-white-50 px-3 pb-2">Devices with punches</div>
        </div>
      </div>
    </div>

    <div class="card mb-4 dash-card" style="animation-delay: 0.05s;">
      <div class="card-header">
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form method="get">
          <div class="form-row">
            <div class="form-group col-md-3">
              <label for="startDate">Start date</label>
              <input id="startDate" name="startDate" class="form-control" type="date" value="<?= h($startDate) ?>">
            </div>
            <div class="form-group col-md-3">
              <label for="endDate">End date</label>
              <input id="endDate" name="endDate" class="form-control" type="date" value="<?= h($endDate) ?>">
              <div class="filter-help">Time range: 00:00:00 to 24:00:00</div>
            </div>
            <div class="form-group col-md-6">
              <label for="projectId">Project</label>
              <select id="projectId" name="projectId" class="form-control">
                <option value="">All projects</option>
                <?php foreach ($projects as $project): ?>
                  <?php
                    $projectValue = (string) ($project['id'] ?? '');
                    $projectLabel = trim((string) ($project['pro_code'] ?? '') . ' ' . (string) ($project['name'] ?? ''));
                  ?>
                  <option value="<?= h($projectValue) ?>" <?= $projectId === $projectValue ? 'selected' : '' ?>>
                    <?= h($projectLabel !== '' ? $projectLabel : 'Unnamed project') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="d-flex flex-wrap align-items-center">
            <button type="submit" class="btn btn-primary mr-2">Apply</button>
            <a class="btn btn-outline-secondary" href="<?= h(admin_url('Attendance_Dashboard.php')) ?>">Reset</a>
            <div class="ml-auto d-flex flex-wrap align-items-center">
              <span class="text-muted mr-2">Quick ranges:</span>
              <?php foreach ($quickRanges as $range): ?>
                <?php
                  $rangeQuery = build_query([
                      'startDate' => $range['start'],
                      'endDate' => $range['end'],
                      'projectId' => $projectId,
                      'deviceSn' => $deviceSnInput,
                      'employeeCode' => $employeeCode,
                  ]);
                ?>
                <a class="btn btn-sm btn-light mr-2" href="<?= h(admin_url('Attendance_Dashboard.php') . '?' . $rangeQuery) ?>">
                  <?= h($range['label']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php
      $exportParams = [
          'startDate' => $startDate,
          'endDate' => $endDate,
          'projectId' => $projectId,
          'deviceSn' => $deviceSnInput,
      ];
      $exportBaseUrl = admin_url('Attendance_Dashboard.php');
      $exportLoggedInUrl = $exportBaseUrl . '?' . build_query(array_merge($exportParams, [
          'export' => 'logged-in-badges',
      ]));
      $exportHrmsActiveUrl = $exportBaseUrl . '?' . build_query(array_merge($exportParams, [
          'export' => 'hrms-active-employees',
      ]));
      $exportHrmsMissingUtimeUrl = $exportBaseUrl . '?' . build_query(array_merge($exportParams, [
          'export' => 'hrms-active-missing-utime',
      ]));
      $exportUtimeMissingHrmsUrl = $exportBaseUrl . '?' . build_query(array_merge($exportParams, [
          'export' => 'utime-missing-hrms-active',
      ]));
    ?>
    <div class="card mb-4 dash-card" style="animation-delay: 0.08s;">
      <div class="card-header">
        <h3 class="card-title">Exports</h3>
      </div>
      <div class="card-body">
        <div class="d-flex flex-wrap">
          <a class="btn btn-outline-primary mr-2 mb-2" href="<?= h($exportLoggedInUrl) ?>">Logged in badges (UTime CSV)</a>
          <a class="btn btn-outline-secondary mr-2 mb-2" href="<?= h($exportHrmsActiveUrl) ?>">HRMS status A employees (CSV)</a>
          <a class="btn btn-outline-info mr-2 mb-2" href="<?= h($exportHrmsMissingUtimeUrl) ?>">HRMS active not onboarded in UTime (CSV)</a>
          <a class="btn btn-outline-dark mr-2 mb-2" href="<?= h($exportUtimeMissingHrmsUrl) ?>">UTime onboarded not HRMS status A (CSV)</a>
        </div>
        <div class="text-muted small">Exports use the current date range and project filter.</div>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-12">
        <div class="card dash-card" style="animation-delay: 0.4s;">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">Logged in badges</h3>
            <span id="loggedBadgesMeta" class="text-muted small"><?= h($loggedBadgesMeta) ?></span>
          </div>
          <div class="card-body">
            <div id="loggedBadgesTableWrapper" class="table-responsive<?= !empty($loggedBadgesRows) ? '' : ' d-none' ?>">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Badge number</th>
                    <th>Employee name</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Status</th>
                    <th>Work type code</th>
                    <th>Work type description</th>
                    <th>Company code</th>
                    <th>Department code</th>
                    <th>Designation code</th>
                    <th>First name</th>
                    <th>Last name</th>
                    <th>Today working</th>
                    <th>On eLeave</th>
                    <th>Leave code</th>
                    <th>Leave description</th>
                  </tr>
                </thead>
                <tbody id="loggedBadgesTableBody">
                  <?php foreach ($loggedBadgesRows as $row): ?>
                    <?php
                      $badgeNumber = trim((string) ($row['badgeNumber'] ?? ''));
                      $employeeName = trim((string) ($row['name'] ?? ''));
                      $department = trim((string) ($row['department'] ?? ''));
                      $designation = trim((string) ($row['designation'] ?? ''));
                      $status = trim((string) ($row['status'] ?? ''));
                      $workTypeCode = trim((string) ($row['workTypeCode'] ?? ''));
                      $workTypeDescription = trim((string) ($row['workTypeDescription'] ?? ''));
                      $companyCode = trim((string) ($row['companyCode'] ?? ''));
                      $departmentCode = trim((string) ($row['departmentCode'] ?? ''));
                      $designationCode = trim((string) ($row['designationCode'] ?? ''));
                      $firstName = trim((string) ($row['firstName'] ?? ''));
                      $lastName = trim((string) ($row['lastName'] ?? ''));
                      $todayWorking = $row['todayWorking'] ?? null;
                      $onEleave = $row['onEleave'] ?? null;
                      $leaveCode = trim((string) ($row['leaveCode'] ?? ''));
                      $leaveDescription = trim((string) ($row['leaveDescription'] ?? ''));
                      $todayWorkingText = $todayWorking === true ? 'Yes' : ($todayWorking === false ? 'No' : '-');
                      $onEleaveText = $onEleave === true ? 'Yes' : ($onEleave === false ? 'No' : '-');
                    ?>
                    <tr>
                      <td><?= h($badgeNumber) ?></td>
                      <td><?= h($employeeName !== '' ? $employeeName : '-') ?></td>
                      <td><?= h($department !== '' ? $department : '-') ?></td>
                      <td><?= h($designation !== '' ? $designation : '-') ?></td>
                      <td><?= h($status !== '' ? $status : '-') ?></td>
                      <td><?= h($workTypeCode !== '' ? $workTypeCode : '-') ?></td>
                      <td><?= h($workTypeDescription !== '' ? $workTypeDescription : '-') ?></td>
                      <td><?= h($companyCode !== '' ? $companyCode : '-') ?></td>
                      <td><?= h($departmentCode !== '' ? $departmentCode : '-') ?></td>
                      <td><?= h($designationCode !== '' ? $designationCode : '-') ?></td>
                      <td><?= h($firstName !== '' ? $firstName : '-') ?></td>
                      <td><?= h($lastName !== '' ? $lastName : '-') ?></td>
                      <td><?= h($todayWorkingText) ?></td>
                      <td><?= h($onEleaveText) ?></td>
                      <td><?= h($leaveCode !== '' ? $leaveCode : '-') ?></td>
                      <td><?= h($leaveDescription !== '' ? $leaveDescription : '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p id="loggedBadgesEmpty" class="text-muted mb-0<?= !empty($loggedBadgesRows) ? ' d-none' : '' ?>">
              <?= h($loggedBadgesEmptyText) ?>
            </p>
            <div id="loggedBadgesPagination" class="d-flex justify-content-between align-items-center mt-3 d-none">
              <button id="loggedBadgesPrev" class="btn btn-sm btn-outline-secondary" type="button">Prev</button>
              <span id="loggedBadgesPageInfo" class="text-muted small"></span>
              <button id="loggedBadgesNext" class="btn btn-sm btn-outline-secondary" type="button">Next</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-12">
        <div class="card dash-card" style="animation-delay: 0.55s;">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">HRMS employee snapshot</h3>
            <span class="text-muted small">HRMS API</span>
          </div>
          <div class="card-body">
            <form method="get" class="mb-3" id="hrmsSnapshotForm">
              <input type="hidden" name="startDate" value="<?= h($startDate) ?>">
              <input type="hidden" name="endDate" value="<?= h($endDate) ?>">
              <input type="hidden" name="projectId" value="<?= h($projectId) ?>">
              <input type="hidden" name="deviceSn" value="<?= h($deviceSnInput) ?>">
              <label class="sr-only" for="employeeCode">HRMS employee code</label>
              <div class="input-group">
                <input id="employeeCode" name="employeeCode" class="form-control" value="<?= h($employeeCode) ?>" placeholder="HRMS employee code">
                <div class="input-group-append">
                  <button type="submit" class="btn btn-primary">Load</button>
                </div>
              </div>
            </form>
            <div id="hrmsSnapshotContent">
              <?php if ($employeeCode === ''): ?>
                <p class="text-muted mb-0">Enter an employee code above to load HRMS details.</p>
              <?php elseif ($lazyMode): ?>
                <p class="text-muted mb-0">Loading HRMS details...</p>
              <?php elseif ($hrmsError): ?>
                <div class="alert alert-warning mb-0">
                  <?= h($hrmsError) ?>
                </div>
              <?php else: ?>
                <div class="mb-3">
                  <div class="h5 mb-1"><?= h($hrmsSummary['employeeName'] ?? 'Employee') ?></div>
                  <div class="text-muted">
                    <?= h($hrmsSummary['department'] ?? 'Department n/a') ?> | <?= h($hrmsSummary['designation'] ?? 'Designation n/a') ?>
                  </div>
                  <div class="text-muted">Status: <?= h($hrmsSummary['status'] ?? 'n/a') ?></div>
                </div>
                <div class="row">
                  <div class="col-6 mb-3">
                    <div class="text-muted small">Attendance days</div>
                    <div class="h5 mb-0"><?= h((string) $hrmsSummary['attendanceDays']) ?></div>
                    <?php if (!empty($hrmsSummary['attendanceError'])): ?>
                      <div class="text-warning small"><?= h($hrmsSummary['attendanceError']) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="col-6 mb-3">
                    <div class="text-muted small">Leave days</div>
                    <div class="h5 mb-0"><?= h((string) $hrmsSummary['leaveDays']) ?></div>
                  </div>
                  <div class="col-6">
                    <div class="text-muted small">Last attendance</div>
                    <div class="h6 mb-0"><?= h($hrmsSummary['lastAttendance'] ?? 'n/a') ?></div>
                  </div>
                  <div class="col-6">
                    <div class="text-muted small">Holidays</div>
                    <div class="h6 mb-0"><?= h((string) $hrmsSummary['holidayCount']) ?></div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const lazyEnabled = <?= $lazyMode ? 'true' : 'false' ?>;
    const errorBox = document.getElementById('apiErrors');
    const setText = (id, value) => {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = value;
      }
    };
    const setHtml = (id, html) => {
      const el = document.getElementById(id);
      if (el) {
        el.innerHTML = html;
      }
    };
    const escapeHtml = (value) => {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    };
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const formatMonthDay = (value) => {
      const parts = String(value ?? '').split('-');
      if (parts.length !== 3) {
        return value;
      }
      const month = months[Number(parts[1]) - 1];
      if (!month) {
        return value;
      }
      return `${month} ${parts[2]}`;
    };
    const formatFlag = (value) => {
      if (value === true) {
        return 'Yes';
      }
      if (value === false) {
        return 'No';
      }
      const text = String(value ?? '').trim();
      if (text === '') {
        return '-';
      }
      const lower = text.toLowerCase();
      if (['1', 'true', 'yes', 'y'].includes(lower)) {
        return 'Yes';
      }
      if (['0', 'false', 'no', 'n'].includes(lower)) {
        return 'No';
      }
      return text;
    };

    const baseUrl = '<?= h(admin_url('Attendance_Dashboard.php')) ?>';
    const renderHrmsSnapshot = (hrms) => {
      const hrmsContent = document.getElementById('hrmsSnapshotContent');
      if (!hrmsContent) {
        return;
      }
      if (!hrms || !hrms.enabled) {
        hrmsContent.innerHTML = '<p class="text-muted mb-0">Enter an employee code above to load HRMS details.</p>';
        return;
      }
      if (hrms.error) {
        hrmsContent.innerHTML = `<div class="alert alert-warning mb-0">${escapeHtml(hrms.error)}</div>`;
        return;
      }
      const summary = hrms.summary || {};
      const employeeName = summary.employeeName || 'Employee';
      const department = summary.department || 'Department n/a';
      const designation = summary.designation || 'Designation n/a';
      const status = summary.status || 'n/a';
      const attendanceDays = summary.attendanceDays ?? 0;
      const attendanceError = summary.attendanceError || '';
      const leaveDays = summary.leaveDays ?? 0;
      const lastAttendance = summary.lastAttendance || 'n/a';
      const holidayCount = summary.holidayCount ?? 0;
      const attendanceErrorHtml = attendanceError
        ? `<div class="text-warning small mt-1">${escapeHtml(attendanceError)}</div>`
        : '';
      hrmsContent.innerHTML = `
        <div class="mb-3">
          <div class="h5 mb-1">${escapeHtml(employeeName)}</div>
          <div class="text-muted">${escapeHtml(department)} | ${escapeHtml(designation)}</div>
          <div class="text-muted">Status: ${escapeHtml(status)}</div>
        </div>
        <div class="row">
          <div class="col-6 mb-3">
            <div class="text-muted small">Attendance days</div>
            <div class="h5 mb-0">${escapeHtml(attendanceDays)}</div>
            ${attendanceErrorHtml}
          </div>
          <div class="col-6 mb-3">
            <div class="text-muted small">Leave days</div>
            <div class="h5 mb-0">${escapeHtml(leaveDays)}</div>
          </div>
          <div class="col-6">
            <div class="text-muted small">Last attendance</div>
            <div class="h6 mb-0">${escapeHtml(lastAttendance)}</div>
          </div>
          <div class="col-6">
            <div class="text-muted small">Holidays</div>
            <div class="h6 mb-0">${escapeHtml(holidayCount)}</div>
          </div>
        </div>
      `;
    };

    const loggedBadgesState = {
      rows: [],
      note: '',
      ok: true,
      count: 0,
      page: 1,
      pageSize: 10,
    };
    let loggedBadgesBound = false;

    const renderLoggedBadgesPage = (page) => {
      const metaEl = document.getElementById('loggedBadgesMeta');
      const tableWrapper = document.getElementById('loggedBadgesTableWrapper');
      const tableBody = document.getElementById('loggedBadgesTableBody');
      const emptyEl = document.getElementById('loggedBadgesEmpty');
      const paginationEl = document.getElementById('loggedBadgesPagination');
      const prevBtn = document.getElementById('loggedBadgesPrev');
      const nextBtn = document.getElementById('loggedBadgesNext');
      const pageInfoEl = document.getElementById('loggedBadgesPageInfo');
      if (!metaEl || !tableWrapper || !tableBody || !emptyEl || !paginationEl || !pageInfoEl) {
        return;
      }
      const rows = loggedBadgesState.rows;
      const note = loggedBadgesState.note;
      const ok = loggedBadgesState.ok;
      const count = loggedBadgesState.count;

      if (ok) {
        metaEl.textContent = note !== '' ? note : `${count} badges`;
      } else {
        metaEl.textContent = 'Unable to load badges';
      }

      if (ok && rows.length) {
        const totalPages = Math.max(1, Math.ceil(rows.length / loggedBadgesState.pageSize));
        const safePage = Math.min(Math.max(page, 1), totalPages);
        loggedBadgesState.page = safePage;
        const startIndex = (safePage - 1) * loggedBadgesState.pageSize;
        const pageRows = rows.slice(startIndex, startIndex + loggedBadgesState.pageSize);
        tableBody.innerHTML = pageRows.map((row) => {
          const badge = String((row && row.badgeNumber) || '').trim();
          const name = String((row && row.name) || '').trim();
          const department = String((row && row.department) || '').trim();
          const designation = String((row && row.designation) || '').trim();
          const status = String((row && row.status) || '').trim();
          const workTypeCode = String((row && row.workTypeCode) || '').trim();
          const workTypeDescription = String((row && row.workTypeDescription) || '').trim();
          const companyCode = String((row && row.companyCode) || '').trim();
          const departmentCode = String((row && row.departmentCode) || '').trim();
          const designationCode = String((row && row.designationCode) || '').trim();
          const firstName = String((row && row.firstName) || '').trim();
          const lastName = String((row && row.lastName) || '').trim();
          const todayWorkingText = formatFlag(row && row.todayWorking);
          const onEleaveText = formatFlag(row && row.onEleave);
          const leaveCode = String((row && row.leaveCode) || '').trim();
          const leaveDescription = String((row && row.leaveDescription) || '').trim();
          const nameText = name !== '' ? name : '-';
          const departmentText = department !== '' ? department : '-';
          const designationText = designation !== '' ? designation : '-';
          const statusText = status !== '' ? status : '-';
          const workTypeCodeText = workTypeCode !== '' ? workTypeCode : '-';
          const workTypeDescText = workTypeDescription !== '' ? workTypeDescription : '-';
          const companyCodeText = companyCode !== '' ? companyCode : '-';
          const departmentCodeText = departmentCode !== '' ? departmentCode : '-';
          const designationCodeText = designationCode !== '' ? designationCode : '-';
          const firstNameText = firstName !== '' ? firstName : '-';
          const lastNameText = lastName !== '' ? lastName : '-';
          const leaveCodeText = leaveCode !== '' ? leaveCode : '-';
          const leaveDescText = leaveDescription !== '' ? leaveDescription : '-';
          return `<tr><td>${escapeHtml(badge)}</td><td>${escapeHtml(nameText)}</td><td>${escapeHtml(departmentText)}</td><td>${escapeHtml(designationText)}</td><td>${escapeHtml(statusText)}</td><td>${escapeHtml(workTypeCodeText)}</td><td>${escapeHtml(workTypeDescText)}</td><td>${escapeHtml(companyCodeText)}</td><td>${escapeHtml(departmentCodeText)}</td><td>${escapeHtml(designationCodeText)}</td><td>${escapeHtml(firstNameText)}</td><td>${escapeHtml(lastNameText)}</td><td>${escapeHtml(todayWorkingText)}</td><td>${escapeHtml(onEleaveText)}</td><td>${escapeHtml(leaveCodeText)}</td><td>${escapeHtml(leaveDescText)}</td></tr>`;
        }).join('');
        tableWrapper.classList.remove('d-none');
        emptyEl.classList.add('d-none');
        if (rows.length > loggedBadgesState.pageSize) {
          paginationEl.classList.remove('d-none');
        } else {
          paginationEl.classList.add('d-none');
        }
        if (prevBtn) {
          prevBtn.disabled = safePage <= 1;
        }
        if (nextBtn) {
          nextBtn.disabled = safePage >= totalPages;
        }
        const startLabel = startIndex + 1;
        const endLabel = Math.min(startIndex + loggedBadgesState.pageSize, rows.length);
        pageInfoEl.textContent = `Showing ${startLabel}-${endLabel} of ${rows.length}`;
      } else {
        tableBody.innerHTML = '';
        tableWrapper.classList.add('d-none');
        let emptyText = 'No logged in badges for the selected range.';
        if (!ok) {
          emptyText = 'Unable to load logged in badges.';
        } else if (note) {
          emptyText = note;
        }
        emptyEl.textContent = emptyText;
        emptyEl.classList.remove('d-none');
        paginationEl.classList.add('d-none');
      }
    };

    const renderLoggedBadges = (badges) => {
      loggedBadgesState.rows = Array.isArray(badges.rows) ? badges.rows : [];
      loggedBadgesState.note = String(badges.note || '');
      loggedBadgesState.ok = badges.ok !== false;
      loggedBadgesState.count = Number.isFinite(Number(badges.count))
        ? Number(badges.count)
        : loggedBadgesState.rows.length;
      loggedBadgesState.page = 1;

      if (!loggedBadgesBound) {
        const prevBtn = document.getElementById('loggedBadgesPrev');
        const nextBtn = document.getElementById('loggedBadgesNext');
        if (prevBtn) {
          prevBtn.addEventListener('click', () => {
            renderLoggedBadgesPage(loggedBadgesState.page - 1);
          });
        }
        if (nextBtn) {
          nextBtn.addEventListener('click', () => {
            renderLoggedBadgesPage(loggedBadgesState.page + 1);
          });
        }
        loggedBadgesBound = true;
      }

      renderLoggedBadgesPage(loggedBadgesState.page);
    };

    const initialLoggedBadges = <?= json_encode(
        [
            'ok' => $loggedBadgesOk,
            'note' => $loggedBadgesNote,
            'count' => $loggedBadgesCount,
            'rows' => $loggedBadgesRows,
        ],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
    ) ?>;

    const hrmsForm = document.getElementById('hrmsSnapshotForm');
    if (hrmsForm) {
      const hrmsButton = hrmsForm.querySelector('button[type="submit"]');
      hrmsForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(hrmsForm);
        const code = String(formData.get('employeeCode') ?? '').trim();
        formData.set('employeeCode', code);
        if (code === '') {
          renderHrmsSnapshot({ enabled: false });
          return;
        }
        const hrmsContent = document.getElementById('hrmsSnapshotContent');
        if (hrmsContent) {
          hrmsContent.innerHTML = '<p class="text-muted mb-0">Loading HRMS details...</p>';
        }
        const hrmsParams = new URLSearchParams(formData);
        hrmsParams.set('ajax', '1');
        hrmsParams.set('ajax_section', 'hrms');
        const hrmsUrl = baseUrl + '?' + hrmsParams.toString();
        if (hrmsButton) {
          hrmsButton.disabled = true;
        }
        fetch(hrmsUrl, { credentials: 'same-origin' })
          .then((response) => {
            if (!response.ok) {
              throw new Error('Request failed');
            }
            return response.json();
          })
          .then((data) => {
            renderHrmsSnapshot((data && data.hrms) || {});
          })
          .catch(() => {
            if (hrmsContent) {
              hrmsContent.innerHTML = '<div class="alert alert-warning mb-0">Unable to load HRMS details.</div>';
            }
          })
          .finally(() => {
            if (hrmsButton) {
              hrmsButton.disabled = false;
            }
          });
      });
    }

    if (!lazyEnabled) {
      renderLoggedBadges(initialLoggedBadges || {});
      return;
    }

    const params = new URLSearchParams(window.location.search);
    params.set('ajax', '1');
    const url = baseUrl + '?' + params.toString();

    fetch(url, { credentials: 'same-origin' })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Request failed');
        }
        return response.json();
      })
      .then((data) => {
        const summary = data.summary || {};
        setText('badgeRatioText', summary.badgeRatioText || '-');
        setText('badgeCardTitle', summary.badgeCardTitle || 'Logged in employees');
        setText('badgeCoverageLabel', summary.badgeCoverageLabel || '');
        setText('deviceStatusRatio', summary.deviceStatusRatio || '-');
        setText('deviceStatusMeta', summary.deviceStatusMeta || '');
        setText('activeDeviceCount', summary.activeDeviceCountText || '-');

        const daily = data.daily || {};
        const series = Array.isArray(daily.series) ? daily.series : [];
        setText('dailyTrendNote', daily.note || '');
        const chartEl = document.getElementById('dailyTrendChart');
        const placeholderEl = document.getElementById('dailyTrendPlaceholder');
        if (chartEl && placeholderEl) {
          if (series.length) {
            const max = series.reduce((acc, row) => Math.max(acc, Number(row.total) || 0), 0);
            chartEl.innerHTML = series.map((row) => {
              const total = Number(row.total) || 0;
              const height = max > 0 ? Math.max(Math.round((total / max) * 100), 4) : 0;
              const label = formatMonthDay(row.date);
              return `
                <div class="trend-bar" style="height: ${height}%">
                  <span class="trend-value">${escapeHtml(total)}</span>
                  <span class="trend-label">${escapeHtml(label)}</span>
                </div>
              `;
            }).join('');
            chartEl.classList.remove('d-none');
            placeholderEl.classList.add('d-none');
          } else {
            placeholderEl.textContent = 'No daily totals available for the selected range.';
            placeholderEl.classList.remove('d-none');
            chartEl.classList.add('d-none');
          }
        }

        renderLoggedBadges(data.loggedBadges || {});
        renderHrmsSnapshot(data.hrms || {});

        const errors = Array.isArray(data.errors) ? data.errors.filter(Boolean) : [];
        if (errorBox) {
          if (errors.length) {
            errorBox.textContent = `Some data panels could not be refreshed: ${errors.join(', ')}.`;
            errorBox.classList.remove('d-none');
          } else {
            errorBox.classList.add('d-none');
          }
        }
      })
      .catch(() => {
        if (errorBox) {
          errorBox.textContent = 'Unable to load dashboard data. Please refresh the page.';
          errorBox.classList.remove('d-none');
        }
      });
  })();
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
