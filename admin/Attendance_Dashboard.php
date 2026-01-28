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

function parse_display_datetime(string $value): ?DateTimeImmutable {
    $value = trim($value);
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
        // Use original timezone when local timezone is unavailable.
    }
    return $dt;
}

function format_display_date(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = parse_display_datetime($value);
    if (!$dt) {
        return $value;
    }
    return $dt->format('d M Y');
}

function format_display_datetime(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = parse_display_datetime($value);
    if (!$dt) {
        return $value;
    }
    if (!preg_match('/\\d{1,2}:\\d{2}/', $value)) {
        return $dt->format('d M Y');
    }
    return $dt->format('d M Y, h:i A');
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

function format_name_with_code(?string $name, ?string $code, string $fallback): string {
    $name = trim((string) $name);
    $code = trim((string) $code);
    if ($name !== '' && $code !== '') {
        return $name . ' (' . $code . ')';
    }
    if ($name !== '') {
        return $name;
    }
    if ($code !== '') {
        return $code;
    }
    return $fallback;
}

function format_yes_no(?bool $value): string {
    if ($value === null) {
        return 'n/a';
    }
    return $value ? 'Yes' : 'No';
}

function build_project_device_summary(array $deviceCounts, array $deviceMap, array $deviceTotals, int $limit = 3): array {
    $projectCounts = [];
    foreach ($deviceCounts as $sn => $count) {
        $projectKey = 'unassigned';
        $projectLabel = 'Unassigned';
        if (isset($deviceMap[$sn]) && is_array($deviceMap[$sn])) {
            $projectId = $deviceMap[$sn]['project_id'] ?? null;
            $projectKey = $projectId !== null ? (string) $projectId : 'unassigned';
            $projectCode = trim((string) ($deviceMap[$sn]['pro_code'] ?? ''));
            if ($projectKey === 'unassigned') {
                $projectLabel = 'Unassigned';
            } elseif ($projectCode !== '') {
                $projectLabel = $projectCode;
            } else {
                $projectLabel = 'Project ' . $projectKey;
            }
        }
        if (!isset($projectCounts[$projectKey])) {
            $totalDevices = (int) ($deviceTotals[$projectKey] ?? 0);
            $projectCounts[$projectKey] = [
                'key' => $projectKey,
                'label' => $projectLabel,
                'count' => 0,
                'total' => $totalDevices,
            ];
        }
        $projectCounts[$projectKey]['count']++;
    }

    $projects = array_values($projectCounts);
    usort($projects, function (array $a, array $b): int {
        $diff = $b['count'] <=> $a['count'];
        if ($diff !== 0) {
            return $diff;
        }
        return strcmp($a['label'], $b['label']);
    });

    $totalProjects = count($projects);
    $parts = [];
    foreach ($projects as $project) {
        $label = $project['label'] !== '' ? $project['label'] : 'Project';
        $totalDevices = (int) ($project['total'] ?? 0);
        if ($totalDevices < (int) $project['count']) {
            $totalDevices = (int) $project['count'];
        }
        $parts[] = $label . ' ' . $project['count'] . '/' . $totalDevices;
    }
    if ($totalProjects === 0) {
        $meta = 'No devices with punches';
    } else {
        $meta = implode(' | ', $parts);
    }

    return [
        'count' => $totalProjects,
        'meta' => $meta,
        'projects' => $projects,
        'totals' => $deviceTotals,
    ];
}

function build_project_badge_summary(array $badgeRows, array $projectCodeById, array $deviceMap): array {
    $projectCounts = [];
    foreach ($badgeRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $projectId = trim((string) ($row['lastLoginProjectId'] ?? ''));
        if ($projectId === '') {
            $projectId = trim((string) ($row['firstLoginProjectId'] ?? ''));
        }
        if ($projectId === '') {
            $deviceSn = trim((string) ($row['lastLoginDeviceSn'] ?? ''));
            if ($deviceSn === '') {
                $deviceSn = trim((string) ($row['firstLoginDeviceSn'] ?? ''));
            }
            if ($deviceSn !== '' && isset($deviceMap[$deviceSn]) && is_array($deviceMap[$deviceSn])) {
                $mappedId = $deviceMap[$deviceSn]['project_id'] ?? null;
                if ($mappedId !== null) {
                    $projectId = (string) $mappedId;
                }
            }
        }

        $projectKey = $projectId !== '' ? $projectId : 'unassigned';
        if ($projectKey === 'unassigned') {
            $projectLabel = 'Unassigned';
        } else {
            $projectCode = trim((string) ($projectCodeById[$projectKey] ?? ''));
            $projectLabel = $projectCode !== '' ? $projectCode : ('Project ' . $projectKey);
        }

        if (!isset($projectCounts[$projectKey])) {
            $projectCounts[$projectKey] = [
                'key' => $projectKey,
                'label' => $projectLabel,
                'count' => 0,
            ];
        }
        $projectCounts[$projectKey]['count']++;
    }

    $projects = array_values($projectCounts);
    usort($projects, function (array $a, array $b): int {
        $diff = $b['count'] <=> $a['count'];
        if ($diff !== 0) {
            return $diff;
        }
        return strcmp($a['label'], $b['label']);
    });

    $totalProjects = count($projects);
    $parts = [];
    foreach ($projects as $project) {
        $label = $project['label'] !== '' ? $project['label'] : 'Project';
        $parts[] = $label . ': ' . $project['count'];
    }
    if ($totalProjects === 0) {
        $meta = 'No logged in employees';
    } else {
        $meta = implode(' | ', $parts);
    }

    return [
        'count' => $totalProjects,
        'meta' => $meta,
        'projects' => $projects,
    ];
}

function build_project_device_employee_meta(array $deviceSummary, array $employeeSummary): string {
    $deviceProjects = $deviceSummary['projects'] ?? [];
    $employeeProjects = $employeeSummary['projects'] ?? [];

    $employeeByKey = [];
    foreach ($employeeProjects as $project) {
        $key = (string) ($project['key'] ?? '');
        if ($key === '') {
            $key = (string) ($project['label'] ?? '');
        }
        $employeeByKey[$key] = $project;
    }

    $parts = [];
    foreach ($deviceProjects as $project) {
        $key = (string) ($project['key'] ?? '');
        if ($key === '') {
            $key = (string) ($project['label'] ?? '');
        }
        $label = (string) ($project['label'] ?? '');
        if ($label === '') {
            $label = 'Project';
        }
        $deviceCount = (int) ($project['count'] ?? 0);
        $totalDevices = (int) ($project['total'] ?? ($deviceSummary['totals'][$key] ?? 0));
        if ($totalDevices < $deviceCount) {
            $totalDevices = $deviceCount;
        }
        $employeeCount = 0;
        if (isset($employeeByKey[$key])) {
            $employeeCount = (int) ($employeeByKey[$key]['count'] ?? 0);
            unset($employeeByKey[$key]);
        }
        $parts[] = $label . ' ' . $deviceCount . '/' . $totalDevices . '/' . $employeeCount;
    }

    if (!empty($employeeByKey)) {
        $remaining = array_values($employeeByKey);
        usort($remaining, function (array $a, array $b): int {
            $diff = $b['count'] <=> $a['count'];
            if ($diff !== 0) {
                return $diff;
            }
            return strcmp($a['label'], $b['label']);
        });
        foreach ($remaining as $project) {
            $label = $project['label'] !== '' ? $project['label'] : 'Project';
            $key = (string) ($project['key'] ?? '');
            $totalDevices = (int) ($deviceSummary['totals'][$key] ?? 0);
            $parts[] = $label . ' 0/' . $totalDevices . '/' . (int) ($project['count'] ?? 0);
        }
    }

    if (empty($parts)) {
        return 'No devices with punches';
    }

    return implode(' | ', $parts);
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

function csv_stream_begin(string $filename, array $headers) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $output = fopen('php://output', 'w');
    if ($output === false) {
        return false;
    }
    fputcsv($output, $headers);
    return $output;
}

function csv_stream_flush($output): void {
    if (is_resource($output)) {
        fflush($output);
    }
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function fetch_logged_badges_page(
    string $startDateParam,
    string $endDateParam,
    string $deviceSnParam,
    int $page,
    int $pageSize,
    string $badgeNumberFilter = '',
    int $timeoutSeconds = 20,
    int $retries = 1
): array {
    $timeoutSeconds = max(1, $timeoutSeconds);
    $retries = max(1, $retries);
    $lastResult = null;
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $result = attendance_api_get('attendance/badges/with-names', [
            'startDate' => $startDateParam,
            'endDate' => $endDateParam,
            'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
            'badgeNumber' => $badgeNumberFilter !== '' ? $badgeNumberFilter : null,
            'page' => $page,
            'pageSize' => $pageSize,
        ], $timeoutSeconds);
        $lastResult = $result;
        if ($result['ok'] && is_array($result['data'])) {
            return $result;
        }
        if ($attempt < $retries) {
            usleep(200000);
        }
    }
    if ($lastResult === null) {
        return ['ok' => false, 'status' => null, 'data' => null, 'error' => 'request_failed', 'url' => null];
    }
    return $lastResult;
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
    $codes = [];
    foreach ($employeeCodes as $code) {
        $code = trim((string) $code);
        if ($code === '') {
            continue;
        }
        $codes[] = $code;
    }
    $codes = array_values(array_unique($codes, SORT_STRING));
    if (empty($codes)) {
        return ['ok' => true, 'map' => []];
    }
    $map = [];
    $allOk = true;
    $chunks = array_chunk($codes, max(1, $chunkSize));
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
                ?? $employee['EMP_FIRST_NAME']
                ?? $employee['EMP_FNAME']
                ?? $employee['FIRST_NAME']
                ?? $employee['empFirstName']
                ?? $employee['firstName']
                ?? $employee['first_name']
                ?? $row['EMP_FIRSTNAME']
                ?? $row['EMP_FIRST_NAME']
                ?? $row['EMP_FNAME']
                ?? $row['FIRST_NAME']
                ?? $row['empFirstName']
                ?? $row['firstName']
                ?? $row['first_name']
                ?? ''));
            $lastName = trim((string) ($employee['EMP_LASTNAME']
                ?? $employee['EMP_LAST_NAME']
                ?? $employee['EMP_LNAME']
                ?? $employee['LAST_NAME']
                ?? $employee['empLastName']
                ?? $employee['lastName']
                ?? $employee['last_name']
                ?? $row['EMP_LASTNAME']
                ?? $row['EMP_LAST_NAME']
                ?? $row['EMP_LNAME']
                ?? $row['LAST_NAME']
                ?? $row['empLastName']
                ?? $row['lastName']
                ?? $row['last_name']
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
    string $startDateParam,
    string $endDateParam,
    string $deviceSnParam,
    bool $includeHrmsDetails = false,
    int $page = 1,
    int $pageSize = 10,
    bool $fetchAll = false,
    string $badgeNumberFilter = '',
    int $hrmsChunkSize = 100,
    int $apiTimeoutSeconds = 20,
    int $apiRetries = 1
): array {
    $badgeDetails = [];
    $total = 0;
    $page = max(1, $page);
    $pageSize = max(1, $pageSize);
    $pageSize = min($pageSize, 200);
    $currentPage = $page;
    $badgeNumberFilter = trim($badgeNumberFilter);
    $originalFetchAll = $fetchAll;
    if ($badgeNumberFilter !== '') {
        $fetchAll = true;
    }

    $apiTimeoutSeconds = max(1, $apiTimeoutSeconds);
    $apiRetries = max(1, $apiRetries);
    while (true) {
        $result = fetch_logged_badges_page(
            $startDateParam,
            $endDateParam,
            $deviceSnParam,
            $currentPage,
            $pageSize,
            $badgeNumberFilter,
            $apiTimeoutSeconds,
            $apiRetries
        );
        if (!$result['ok'] || !is_array($result['data'])) {
            return [
                'ok' => false,
                'rows' => [],
                'total' => 0,
                'error' => $result['error'] ?? 'request_failed',
                'status' => $result['status'] ?? null,
                'url' => $result['url'] ?? null,
            ];
        }
        $rows = $result['data']['rows'] ?? null;
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $badge = trim((string) ($row['badgeNumber'] ?? ''));
            if ($badge === '') {
                continue;
            }
            $utimeName = trim((string) ($row['name'] ?? ''));
            $details = $badgeDetails[$badge] ?? [
                'utimeName' => '',
                'firstLoginTime' => '',
                'lastLoginTime' => '',
                'firstLoginDeviceSn' => '',
                'lastLoginDeviceSn' => '',
                'firstLoginProjectId' => '',
                'firstLoginProjectName' => '',
                'lastLoginProjectId' => '',
                'lastLoginProjectName' => '',
            ];
            if ($utimeName !== '' && $details['utimeName'] === '') {
                $details['utimeName'] = $utimeName;
            }
            $firstLoginTime = trim((string) ($row['firstLoginTime'] ?? ''));
            if ($details['firstLoginTime'] === '' && $firstLoginTime !== '') {
                $details['firstLoginTime'] = $firstLoginTime;
            }
            $lastLoginTime = trim((string) ($row['lastLoginTime'] ?? ''));
            if ($details['lastLoginTime'] === '' && $lastLoginTime !== '') {
                $details['lastLoginTime'] = $lastLoginTime;
            }
            $firstLoginDeviceSn = trim((string) ($row['firstLoginDeviceSn'] ?? ''));
            if ($details['firstLoginDeviceSn'] === '' && $firstLoginDeviceSn !== '') {
                $details['firstLoginDeviceSn'] = $firstLoginDeviceSn;
            }
            $lastLoginDeviceSn = trim((string) ($row['lastLoginDeviceSn'] ?? ''));
            if ($details['lastLoginDeviceSn'] === '' && $lastLoginDeviceSn !== '') {
                $details['lastLoginDeviceSn'] = $lastLoginDeviceSn;
            }
            $firstLoginProjectId = trim((string) ($row['firstLoginProjectId'] ?? ''));
            if ($details['firstLoginProjectId'] === '' && $firstLoginProjectId !== '') {
                $details['firstLoginProjectId'] = $firstLoginProjectId;
            }
            $firstLoginProjectName = trim((string) ($row['firstLoginProjectName'] ?? ''));
            if ($details['firstLoginProjectName'] === '' && $firstLoginProjectName !== '') {
                $details['firstLoginProjectName'] = $firstLoginProjectName;
            }
            $lastLoginProjectId = trim((string) ($row['lastLoginProjectId'] ?? ''));
            if ($details['lastLoginProjectId'] === '' && $lastLoginProjectId !== '') {
                $details['lastLoginProjectId'] = $lastLoginProjectId;
            }
            $lastLoginProjectName = trim((string) ($row['lastLoginProjectName'] ?? ''));
            if ($details['lastLoginProjectName'] === '' && $lastLoginProjectName !== '') {
                $details['lastLoginProjectName'] = $lastLoginProjectName;
            }
            $badgeDetails[$badge] = $details;
        }

        $total = (int) ($result['data']['total'] ?? $total);
        if (!$fetchAll) {
            break;
        }
        if (count($rows) < 1) {
            break;
        }
        if ($total > 0 && ($currentPage * $pageSize) >= $total) {
            break;
        }
        $currentPage++;
    }

    $badgeNumbers = array_keys($badgeDetails);
    sort($badgeNumbers, SORT_NATURAL);
    $detailsMap = [];
    $hrmsOk = true;
    if ($includeHrmsDetails && !empty($badgeNumbers)) {
        $detailsResult = load_hrms_employee_details($badgeNumbers, max(1, $hrmsChunkSize));
        $hrmsOk = $detailsResult['ok'];
        if ($detailsResult['ok'] && isset($detailsResult['map']) && is_array($detailsResult['map'])) {
            $detailsMap = $detailsResult['map'];
        }
    }
    $list = [];
    foreach ($badgeNumbers as $badge) {
        $apiDetails = $badgeDetails[$badge] ?? [];
        $utimeName = trim((string) ($apiDetails['utimeName'] ?? ($apiDetails['name'] ?? '')));
        $hrmsName = '';
        $firstLoginTime = trim((string) ($apiDetails['firstLoginTime'] ?? ''));
        $lastLoginTime = trim((string) ($apiDetails['lastLoginTime'] ?? ''));
        $firstLoginTime = format_display_datetime($firstLoginTime);
        $lastLoginTime = format_display_datetime($lastLoginTime);
        $firstLoginDeviceSn = trim((string) ($apiDetails['firstLoginDeviceSn'] ?? ''));
        $lastLoginDeviceSn = trim((string) ($apiDetails['lastLoginDeviceSn'] ?? ''));
        $firstLoginProjectId = trim((string) ($apiDetails['firstLoginProjectId'] ?? ''));
        $firstLoginProjectName = trim((string) ($apiDetails['firstLoginProjectName'] ?? ''));
        $lastLoginProjectId = trim((string) ($apiDetails['lastLoginProjectId'] ?? ''));
        $lastLoginProjectName = trim((string) ($apiDetails['lastLoginProjectName'] ?? ''));
        $department = '';
        $designation = '';
        if (isset($detailsMap[$badge]) && is_array($detailsMap[$badge])) {
            $details = $detailsMap[$badge];
            $hrmsName = trim((string) ($details['name'] ?? ''));
            $department = trim((string) ($details['department'] ?? ''));
            $designation = trim((string) ($details['designation'] ?? ''));
        }
        $list[] = [
            'badgeNumber' => $badge,
            'utimeName' => $utimeName,
            'hrmsName' => $hrmsName,
            'department' => $department,
            'designation' => $designation,
            'firstLoginTime' => $firstLoginTime,
            'lastLoginTime' => $lastLoginTime,
            'firstLoginDeviceSn' => $firstLoginDeviceSn,
            'lastLoginDeviceSn' => $lastLoginDeviceSn,
            'firstLoginProjectId' => $firstLoginProjectId,
            'firstLoginProjectName' => $firstLoginProjectName,
            'lastLoginProjectId' => $lastLoginProjectId,
            'lastLoginProjectName' => $lastLoginProjectName,
        ];
    }

    if ($badgeNumberFilter !== '') {
        $needle = strtolower($badgeNumberFilter);
        $list = array_values(array_filter($list, function (array $row) use ($needle) {
            $badge = strtolower(trim((string) ($row['badgeNumber'] ?? '')));
            if ($badge === '') {
                return false;
            }
            return strpos($badge, $needle) !== false;
        }));
        $total = count($list);
        if (!$originalFetchAll) {
            $offset = max(0, ($page - 1) * $pageSize);
            $list = array_slice($list, $offset, $pageSize);
        }
    }
    return [
        'ok' => true,
        'rows' => $list,
        'total' => $total > 0 ? $total : count($list),
        'page' => $page,
        'pageSize' => $pageSize,
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
        'employeeCode' => $employeeCode !== '' ? $employeeCode : null,
        'companyCode' => null,
        'department' => null,
        'departmentCode' => null,
        'designation' => null,
        'designationCode' => null,
        'status' => null,
        'workTypeCode' => null,
        'workTypeDescription' => null,
        'todayWorking' => null,
        'onEleave' => null,
        'leaveCode' => null,
        'leaveDescription' => null,
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
        $employeeCodeValue = trim((string) ($employee['EMP_CODE']
            ?? $employee['empCode']
            ?? $employee['employeeCode']
            ?? $employee['code']
            ?? ''));
        if ($employeeCodeValue !== '') {
            $summary['employeeCode'] = $employeeCodeValue;
        }
        $employeeName = trim((string) ($employee['EMP_NAME'] ?? ''));
        $summary['employeeName'] = $employeeName !== '' ? $employeeName : $employeeCode;
        $companyCode = trim((string) ($employee['EMP_COMPCD']
            ?? $employee['empCompanyCode']
            ?? $employee['companyCode']
            ?? ''));
        $summary['companyCode'] = $companyCode !== '' ? $companyCode : null;
        $departmentCode = trim((string) ($employee['EMP_DEPT_CD']
            ?? $employee['empDeptCode']
            ?? $employee['departmentCode']
            ?? ''));
        $summary['departmentCode'] = $departmentCode !== '' ? $departmentCode : null;
        $department = trim((string) ($employee['DEPT_NAME'] ?? ''));
        if ($department === '') {
            $department = trim((string) ($employee['EMP_DEPT_CD'] ?? ''));
        }
        $summary['department'] = $department !== '' ? $department : null;
        $designationCode = trim((string) ($employee['EMP_DESG_CD']
            ?? $employee['empDesgCode']
            ?? $employee['designationCode']
            ?? ''));
        $summary['designationCode'] = $designationCode !== '' ? $designationCode : null;
        $designation = trim((string) ($employee['DESG_NAME'] ?? ''));
        if ($designation === '') {
            $designation = trim((string) ($employee['EMP_DESG_CD'] ?? ''));
        }
        $summary['designation'] = $designation !== '' ? $designation : null;
        $status = trim((string) ($employee['EMP_STATUS'] ?? ''));
        $summary['status'] = $status !== '' ? $status : null;
        $workTypeCode = trim((string) ($employee['TD_WT']
            ?? $employee['workTypeCode']
            ?? $employee['wtCode']
            ?? ''));
        $summary['workTypeCode'] = $workTypeCode !== '' ? $workTypeCode : null;
        $workTypeDesc = trim((string) ($employee['WT_DESC']
            ?? $employee['workTypeDescription']
            ?? $employee['wtDesc']
            ?? ''));
        $summary['workTypeDescription'] = $workTypeDesc !== '' ? $workTypeDesc : null;
        $summary['todayWorking'] = normalize_bool_flag($employee['TODAY_WORKING']
            ?? $employee['todayWorking']
            ?? null);
        $summary['onEleave'] = normalize_bool_flag($employee['ON_ELEAVE']
            ?? $employee['onEleave']
            ?? $employee['onEL']
            ?? null);
        $leaveCode = trim((string) ($employee['LEAVE_CODE']
            ?? $employee['leaveCode']
            ?? ''));
        $summary['leaveCode'] = $leaveCode !== '' ? $leaveCode : null;
        $leaveDesc = trim((string) ($employee['LEAVE_DESC']
            ?? $employee['leaveDescription']
            ?? $employee['leaveDesc']
            ?? ''));
        $summary['leaveDescription'] = $leaveDesc !== '' ? $leaveDesc : null;
    }

    $localTz = null;
    try {
        $localTz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    } catch (Exception $e) {
        $localTz = null;
    }

$attendanceStart = $startDate . 'T00:00:00+00:00';
$attendanceEnd = $endDate . 'T23:59:59+00:00';
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

    if (!empty($summary['lastAttendance'])) {
        $summary['lastAttendance'] = format_display_date($summary['lastAttendance']);
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
$endDateExclusive = $endDate;
$endDateCursor = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
if ($endDateCursor instanceof DateTimeImmutable) {
    $endDateExclusive = $endDateCursor->modify('+1 day')->format('Y-m-d');
}
$startDateParam = $startDate;
$endDateParam = $endDateExclusive;

$deviceSnInput = trim((string) ($_GET['deviceSn'] ?? ''));
$projectId = trim((string) ($_GET['projectId'] ?? ''));
$employeeCode = trim((string) ($_GET['employeeCode'] ?? ''));
$badgeNumberFilter = trim((string) ($_GET['badgeNumber'] ?? ''));
$loggedBadgesPage = max(1, (int) ($_GET['page'] ?? 1));
$loggedBadgesPageSize = (int) ($_GET['pageSize'] ?? 10);
if ($loggedBadgesPageSize < 1) {
    $loggedBadgesPageSize = 10;
}
if ($loggedBadgesPageSize > 200) {
    $loggedBadgesPageSize = 200;
}

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
$deviceTotalsByProject = [];
$projects = [];
$projectCodeById = [];

if (isset($bd) && $bd instanceof mysqli) {
    $projectResult = $bd->query('SELECT id, name, pro_code FROM gcc_it.projects ORDER BY pro_code');
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $projects[] = $row;
            $projectRowId = (string) ($row['id'] ?? '');
            if ($projectRowId !== '') {
                $projectCodeById[$projectRowId] = trim((string) ($row['pro_code'] ?? ''));
            }
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

if (!empty($devicesByProject)) {
    foreach ($devicesByProject as $projectKey => $devices) {
        $deviceTotalsByProject[$projectKey] = count($devices);
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
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    if ($deviceScope === 'none' && $exportType !== 'hrms-active-employees') {
        export_error($deviceScopeNote !== '' ? $deviceScopeNote : 'No devices matched the selected filter.');
    }
    $rangeTag = $startDate . '_to_' . $endDate;
    if ($projectId !== '') {
        $rangeTag .= '_project_' . $projectId;
    }
    $filenameTag = safe_filename($rangeTag);

    if ($exportType === 'logged-in-badges') {
        $filename = 'logged-in-badges-' . $filenameTag . '.csv';
        $exportPageSize = 100;
        $exportHrmsChunkSize = 200;
        $exportTimeoutSeconds = 60;
        $exportRetries = 2;
        $output = csv_stream_begin($filename, [
            'Employee Code (utime)',
            'Name (utime)',
            'Name (hrms)',
            'Department (hrms)',
            'Designation (hrms)',
            'First Punch time (utime)',
            'First Punch device (utime)',
            'FP Project ID',
            'FP project name',
            'Last Punch time (utime)',
            'Last Punch device (utime)',
            'LP project id',
            'LP project name',
        ]);
        if ($output === false) {
            export_error('Unable to open export stream.');
        }
        csv_stream_flush($output);

        $firstResult = fetch_logged_badges_page(
            $startDateParam,
            $endDateParam,
            $deviceSnParam,
            1,
            $exportPageSize,
            $badgeNumberFilter,
            $exportTimeoutSeconds,
            $exportRetries
        );
        if (!$firstResult['ok'] || !is_array($firstResult['data'])) {
            $details = 'Unable to load UTime badge list for the selected range.';
            if (!empty($firstResult['error'])) {
                $details .= ' Error: ' . $firstResult['error'] . '.';
            }
            if (!empty($firstResult['status'])) {
                $details .= ' Status: ' . $firstResult['status'] . '.';
            }
            if (!empty($firstResult['url'])) {
                $details .= ' URL: ' . $firstResult['url'] . '.';
            }
            fputcsv($output, ['ERROR', $details]);
            csv_stream_flush($output);
            if (is_resource($output)) {
                fclose($output);
            }
            exit;
        }

        $page = 1;
        $total = (int) ($firstResult['data']['total'] ?? 0);
        $badgeNeedle = strtolower($badgeNumberFilter);
        $seenBadges = [];
        $currentResult = $firstResult;
        $exportError = null;

        while (true) {
            $dataRows = $currentResult['data']['rows'] ?? [];
            if (!is_array($dataRows)) {
                $dataRows = [];
            }

            $pageBadges = [];
            $pageRows = [];
            foreach ($dataRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $badge = trim((string) ($row['badgeNumber'] ?? ''));
                if ($badge === '') {
                    continue;
                }
                if ($badgeNeedle !== '' && strpos(strtolower($badge), $badgeNeedle) === false) {
                    continue;
                }
                if (isset($seenBadges[$badge])) {
                    continue;
                }
                $seenBadges[$badge] = true;
                $pageBadges[] = $badge;
                $pageRows[] = [
                    'badgeNumber' => $badge,
                    'utimeName' => trim((string) ($row['name'] ?? '')),
                    'firstLoginTime' => trim((string) ($row['firstLoginTime'] ?? '')),
                    'lastLoginTime' => trim((string) ($row['lastLoginTime'] ?? '')),
                    'firstLoginDeviceSn' => trim((string) ($row['firstLoginDeviceSn'] ?? '')),
                    'lastLoginDeviceSn' => trim((string) ($row['lastLoginDeviceSn'] ?? '')),
                    'firstLoginProjectId' => trim((string) ($row['firstLoginProjectId'] ?? '')),
                    'firstLoginProjectName' => trim((string) ($row['firstLoginProjectName'] ?? '')),
                    'lastLoginProjectId' => trim((string) ($row['lastLoginProjectId'] ?? '')),
                    'lastLoginProjectName' => trim((string) ($row['lastLoginProjectName'] ?? '')),
                ];
            }

            $detailsMap = [];
            if (!empty($pageBadges)) {
                $detailsResult = load_hrms_employee_details($pageBadges, $exportHrmsChunkSize);
                if ($detailsResult['ok'] && isset($detailsResult['map']) && is_array($detailsResult['map'])) {
                    $detailsMap = $detailsResult['map'];
                }
            }

            foreach ($pageRows as $row) {
                $badge = $row['badgeNumber'];
                $details = $detailsMap[$badge] ?? [];
                $hrmsName = trim((string) ($details['name'] ?? ''));
                $department = trim((string) ($details['department'] ?? ''));
                $designation = trim((string) ($details['designation'] ?? ''));
                fputcsv($output, [
                    $badge,
                    $row['utimeName'],
                    $hrmsName,
                    $department,
                    $designation,
                    $row['firstLoginTime'],
                    $row['firstLoginDeviceSn'],
                    $row['firstLoginProjectId'],
                    $row['firstLoginProjectName'],
                    $row['lastLoginTime'],
                    $row['lastLoginDeviceSn'],
                    $row['lastLoginProjectId'],
                    $row['lastLoginProjectName'],
                ]);
            }
            csv_stream_flush($output);

            if (count($dataRows) < 1) {
                break;
            }
            if ($total > 0 && ($page * $exportPageSize) >= $total) {
                break;
            }
            $page++;
            $currentResult = fetch_logged_badges_page(
                $startDateParam,
                $endDateParam,
                $deviceSnParam,
                $page,
                $exportPageSize,
                $badgeNumberFilter,
                $exportTimeoutSeconds,
                $exportRetries
            );
            if (!$currentResult['ok'] || !is_array($currentResult['data'])) {
                $exportError = 'UTime request failed';
                if (!empty($currentResult['error'])) {
                    $exportError .= ' (' . $currentResult['error'] . ')';
                }
                if (!empty($currentResult['status'])) {
                    $exportError .= ' status ' . $currentResult['status'];
                }
                if (!empty($currentResult['url'])) {
                    $exportError .= ' url ' . $currentResult['url'];
                }
                break;
            }
        }

        if ($exportError !== null) {
            fputcsv($output, ['ERROR', $exportError]);
            csv_stream_flush($output);
        }
        if (is_resource($output)) {
            fclose($output);
        }
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
if (!$isAjax) {
    $lazyMode = true;
}

$apiErrors = [];

if ($isAjax && $ajaxSection === 'logged-badges') {
    $loggedBadgesOk = false;
    $loggedBadgesRows = [];
    $loggedBadgesCount = 0;
    $loggedBadgesNote = '';
    $loggedBadgesPage = max(1, $loggedBadgesPage);
    $includeHrmsDetails = normalize_bool_flag($_GET['includeHrms'] ?? null);
    if ($includeHrmsDetails === null) {
        $includeHrmsDetails = true;
    }
    if ($deviceScope === 'none') {
        $loggedBadgesOk = true;
        $loggedBadgesNote = $deviceScopeNote;
    } else {
        $loggedBadgesResult = load_logged_in_badges(
            $startDateParam,
            $endDateParam,
            $deviceSnParam,
            $includeHrmsDetails,
            $loggedBadgesPage,
            $loggedBadgesPageSize,
            false,
            $badgeNumberFilter
        );
        if ($loggedBadgesResult['ok']) {
            $loggedBadgesOk = true;
            $loggedBadgesRows = $loggedBadgesResult['rows'];
            $loggedBadgesCount = (int) ($loggedBadgesResult['total'] ?? count($loggedBadgesRows));
        }
    }

    $payload = [
        'loggedBadges' => [
            'ok' => $loggedBadgesOk,
            'note' => $loggedBadgesNote,
            'count' => $loggedBadgesCount,
            'rows' => $loggedBadgesRows,
            'page' => $loggedBadgesPage,
            'pageSize' => $loggedBadgesPageSize,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'active-devices') {
    $apiErrors = [];
    $deviceCountsOk = false;
    $deviceCounts = [];
    if ($deviceScope === 'none') {
        $deviceCountsOk = true;
    } else {
        $deviceCountsResult = attendance_api_get('attendance/counts', [
            'groupBy' => 'deviceSn',
            'startDate' => $startDateParam,
            'endDate' => $endDateParam,
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
            $apiErrors[] = 'Project counts';
        }
    }

    arsort($deviceCounts);
    $projectSummary = build_project_device_summary($deviceCounts, $deviceMap, $deviceTotalsByProject);
    $activeDeviceCount = $projectSummary['count'];
    $activeDeviceMeta = $projectSummary['meta'];
    $activeDeviceMetaIsList = false;

    $employeeCountsOk = false;
    $employeeSummary = ['count' => 0, 'meta' => '', 'projects' => []];
    if ($deviceScope === 'none') {
        $employeeCountsOk = true;
    } else {
        $loggedBadgesAll = load_logged_in_badges(
            $startDateParam,
            $endDateParam,
            $deviceSnParam,
            false,
            1,
            200,
            true
        );
        if ($loggedBadgesAll['ok']) {
            $employeeCountsOk = true;
            $employeeSummary = build_project_badge_summary(
                $loggedBadgesAll['rows'],
                $projectCodeById,
                $deviceMap
            );
        } else {
            $apiErrors[] = 'Employee counts';
        }
    }

    if ($deviceScope === 'none') {
        $activeDeviceMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
    } elseif ($deviceCountsOk && $employeeCountsOk) {
        $activeDeviceMeta = build_project_device_employee_meta($projectSummary, $employeeSummary);
        $activeDeviceMetaIsList = ($activeDeviceMeta !== 'No devices with punches');
    } elseif ($deviceCountsOk) {
        $activeDeviceMeta = 'Devices: ' . $projectSummary['meta'] . ' | Employees: unavailable';
    } elseif ($employeeCountsOk) {
        $activeDeviceMeta = 'Employees: ' . $employeeSummary['meta'] . ' | Devices: unavailable';
    } else {
        $activeDeviceMeta = 'Project counts unavailable';
    }

    $activeDeviceCountText = $deviceCountsOk ? (string) $activeDeviceCount : '-';
    $activeDeviceLabel = $activeDeviceCountText !== '-'
        ? ($activeDeviceCountText . ' Projects with punches')
        : 'Projects with punches';
    if ($activeDeviceMetaIsList) {
        $activeDeviceLabel .= ' (Devices active/total / Employees:)';
    }

    $payload = [
        'errors' => array_values(array_unique($apiErrors)),
        'activeDevices' => [
            'activeDeviceCountText' => $activeDeviceCountText,
            'activeDeviceLabel' => $activeDeviceLabel,
            'activeDeviceMeta' => $activeDeviceMeta,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'device-status') {
    $apiErrors = [];
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
        $apiErrors[] = 'Online/total devices';
    }
    $deviceStatusScope = $deviceScopeLabel;
    $deviceStatusRatio = $deviceStatusOk ? ($deviceStatusCounts['online'] . ' / ' . $deviceStatusTotal) : '-';
    $deviceStatusMeta = $deviceStatusOk
        ? ('Offline: ' . $deviceStatusCounts['offline'] . ' | Unknown: ' . $deviceStatusCounts['unknown'] . ' | ' . $deviceStatusScope)
        : 'Status data unavailable';
    if ($deviceScope === 'none') {
        $deviceStatusOk = true;
        $deviceStatusRatio = '0 / 0';
        $deviceStatusMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
    }

    $payload = [
        'errors' => array_values(array_unique($apiErrors)),
        'deviceStatus' => [
            'deviceStatusRatio' => $deviceStatusRatio,
            'deviceStatusMeta' => $deviceStatusMeta,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'badge-ratio') {
    $apiErrors = [];
    $badgeCountOk = false;
    $uniqueBadgeCount = 0;
    if ($deviceScope === 'none') {
        $badgeCountOk = true;
    } else {
        $badgeCountResult = attendance_api_get('attendance/badges/count', [
            'startDate' => $startDateParam,
            'endDate' => $endDateParam,
            'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
        ]);
        if ($badgeCountResult['ok'] && is_array($badgeCountResult['data'])) {
            $badgeCountOk = true;
            $uniqueBadgeCount = (int) ($badgeCountResult['data']['total'] ?? 0);
        } else {
            $apiErrors[] = 'Logged in / active employees';
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

    $payload = [
        'errors' => array_values(array_unique($apiErrors)),
        'badgeRatio' => [
            'badgeRatioText' => $badgeRatioText,
            'badgeCardTitle' => $badgeCardTitle,
            'badgeCoverageLabel' => $badgeCoverageLabel,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'summary') {
    $badgeCountOk = false;
    $uniqueBadgeCount = 0;
    if ($deviceScope === 'none') {
        $badgeCountOk = true;
    } else {
        $badgeCountResult = attendance_api_get('attendance/badges/count', [
            'startDate' => $startDateParam,
            'endDate' => $endDateParam,
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

    $dailyOk = false;
    $dailyTotals = [];
    $dailyLabel = $deviceScopeLabel;
    $dailyNote = '';
    if ($deviceScope === 'selected') {
        $dailyResult = attendance_api_get('attendance/daily/by-devices', [
            'deviceSn' => $deviceSnParam,
            'startDate' => $startDateParam,
            'endDate' => $endDateParam,
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
            'startDate' => $startDateParam,
            'endDate' => $endDateParam,
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
    $projectSummary = build_project_device_summary($deviceCounts, $deviceMap, $deviceTotalsByProject);
    $activeDeviceCount = $projectSummary['count'];
    $activeDeviceMeta = $projectSummary['meta'];
    $activeDeviceMetaIsList = false;

    $employeeCountsOk = false;
    $employeeSummary = ['count' => 0, 'meta' => '', 'projects' => []];
    if ($deviceScope === 'none') {
        $employeeCountsOk = true;
    } else {
        $loggedBadgesAll = load_logged_in_badges(
            $startDateParam,
            $endDateParam,
            $deviceSnParam,
            false,
            1,
            200,
            true
        );
        if ($loggedBadgesAll['ok']) {
            $employeeCountsOk = true;
            $employeeSummary = build_project_badge_summary(
                $loggedBadgesAll['rows'],
                $projectCodeById,
                $deviceMap
            );
        } else {
            $apiErrors[] = 'Employee counts';
        }
    }

    if ($deviceScope === 'none') {
        $activeDeviceMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
    } elseif ($deviceCountsOk && $employeeCountsOk) {
        $activeDeviceMeta = build_project_device_employee_meta($projectSummary, $employeeSummary);
        $activeDeviceMetaIsList = ($activeDeviceMeta !== 'No devices with punches');
    } elseif ($deviceCountsOk) {
        $activeDeviceMeta = 'Devices: ' . $projectSummary['meta'] . ' | Employees: unavailable';
    } elseif ($employeeCountsOk) {
        $activeDeviceMeta = 'Employees: ' . $employeeSummary['meta'] . ' | Devices: unavailable';
    } else {
        $activeDeviceMeta = 'Project counts unavailable';
    }

    $activeDeviceCountText = $deviceCountsOk ? (string) $activeDeviceCount : '-';
    $activeDeviceLabel = $activeDeviceCountText !== '-'
        ? ($activeDeviceCountText . ' Projects with punches')
        : 'Projects with punches';
    if ($activeDeviceMetaIsList) {
        $activeDeviceLabel .= ' (Devices active/total / Employees:)';
    }

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
        ? ('Offline: ' . $deviceStatusCounts['offline'] . ' | Unknown: ' . $deviceStatusCounts['unknown'] . ' | ' . $deviceStatusScope)
        : 'Status data unavailable';
    if ($deviceScope === 'none') {
        $deviceStatusOk = true;
        $deviceStatusRatio = '0 / 0';
        $deviceStatusMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
    }

    $payload = [
        'errors' => array_values(array_unique($apiErrors)),
        'summary' => [
            'badgeRatioText' => $badgeRatioText,
            'badgeCardTitle' => $badgeCardTitle,
            'badgeCoverageLabel' => $badgeCoverageLabel,
            'deviceStatusRatio' => $deviceStatusRatio,
            'deviceStatusMeta' => $deviceStatusMeta,
            'activeDeviceCountText' => $activeDeviceCountText,
            'activeDeviceLabel' => $activeDeviceLabel,
            'activeDeviceMeta' => $activeDeviceMeta,
        ],
        'daily' => [
            'note' => $dailyNote !== '' ? $dailyNote : $dailyLabel,
            'series' => $dailySeries,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$badgeCountOk = false;
$uniqueBadgeCount = 0;
$activeEmployeesOk = false;
$activeEmployeesCount = 0;
$badgeCoveragePercent = null;
$badgeRatioText = '...';
$badgeCoverageLabel = 'Loading...';
$badgeCardTitle = $showActiveEmployeesRatio ? 'Logged in / active employees' : 'Logged in employees';

$loggedBadgesOk = true;
$loggedBadgesRows = [];
$loggedBadgesCount = 0;
$loggedBadgesNote = '';
if ($deviceScope === 'none') {
    $loggedBadgesOk = true;
    $loggedBadgesNote = $deviceScopeNote;
    $loggedBadgesMeta = $deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.';
    $loggedBadgesEmptyText = $loggedBadgesMeta;
} else {
    $loggedBadgesNote = 'Loading logged in badges...';
    $loggedBadgesMeta = $loggedBadgesNote;
    $loggedBadgesEmptyText = $loggedBadgesNote;
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
$activeDeviceMeta = $deviceScope === 'none'
    ? ($deviceScopeNote !== '' ? $deviceScopeNote : 'No devices selected.')
    : 'Loading project counts...';
$activeDeviceCountText = $deviceCountsOk ? (string) $activeDeviceCount : '-';
$activeDeviceLabel = $activeDeviceCountText !== '-'
    ? ($activeDeviceCountText . ' Projects with punches')
    : 'Projects with punches';

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
    'employeeCode' => null,
    'companyCode' => null,
    'department' => null,
    'departmentCode' => null,
    'designation' => null,
    'designationCode' => null,
    'status' => null,
    'workTypeCode' => null,
    'workTypeDescription' => null,
    'todayWorking' => null,
    'onEleave' => null,
    'leaveCode' => null,
    'leaveDescription' => null,
    'attendanceDays' => 0,
    'leaveDays' => 0,
    'lastAttendance' => null,
    'holidayCount' => 0,
];

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

$displayStartDate = format_display_date($startDate);
$displayEndDate = format_display_date($endDate);

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
  .project-punches-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: center;
    margin-bottom: 12px;
  }
  .project-punches-count {
    min-width: 54px;
    height: 54px;
    padding: 8px;
    border-radius: 14px;
    background: #f1f5f9;
    border: 1px solid #d7e0ea;
    color: #1b4f9a;
    font-size: 22px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .project-punches-title {
    font-weight: 600;
  }
  .project-punches-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  }
  .project-punches-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 10px;
  }
  .project-punches-item-title {
    font-weight: 600;
    margin-bottom: 2px;
  }
  .project-punches-item-counts {
    font-size: 12px;
    color: #6c757d;
  }
  .project-punches-bars {
    display: grid;
    gap: 6px;
    margin-top: 8px;
  }
  .project-punches-metric {
    display: grid;
    gap: 4px;
  }
  .project-punches-metric-label {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #6c757d;
  }
  .project-punches-metric-value {
    font-weight: 600;
    color: #1f2937;
  }
  .project-punches-bar {
    position: relative;
    height: 6px;
    border-radius: 999px;
    background: #e9edf2;
    overflow: hidden;
  }
  .project-punches-bar::after {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: var(--bar-size, 0%);
    background: var(--bar-color, #1b4f9a);
    border-radius: inherit;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-7">
        <h1>Attendance Dashboard</h1>
      </div>
      <div class="col-sm-5 text-sm-right">
        <span class="badge badge-primary">Range: <?= h($displayStartDate) ?> to <?= h($displayEndDate) ?></span>
      </div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
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
        <div class="info-box dash-card" style="animation-delay: 0.2s;">
          <span class="info-box-icon bg-warning"><i class="fas fa-microchip"></i></span>
          <div class="info-box-content">
            <span id="activeDeviceLabel" class="info-box-text"><?= h($activeDeviceLabel) ?></span>
            <div id="activeDeviceMeta" class="text-muted small"><?= h($activeDeviceMeta) ?></div>
          </div>
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
              <div class="filter-help">Range uses start date through end date (end date +1 day for API).</div>
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

    <div class="card mb-4 dash-card" style="animation-delay: 0.07s;">
      <div class="card-header">
        <h3 class="card-title">Projects with punches</h3>
      </div>
      <div class="card-body">
        <div class="project-punches-summary">
          <div id="activeDeviceReportCount" class="project-punches-count"><?= h($activeDeviceCountText) ?></div>
          <div>
            <div id="activeDeviceReportLabel" class="project-punches-title"><?= h($activeDeviceLabel) ?></div>
            <div id="activeDeviceReportSub" class="text-muted small"></div>
          </div>
        </div>
        <div id="activeDeviceReportChart" class="project-punches-grid d-none"></div>
        <div id="activeDeviceReportMeta" class="text-muted small d-none"><?= h($activeDeviceMeta) ?></div>
      </div>
    </div>

    <?php
      $exportParams = [
          'startDate' => $startDate,
          'endDate' => $endDate,
          'projectId' => $projectId,
          'deviceSn' => $deviceSnInput,
      ];
      if ($badgeNumberFilter !== '') {
          $exportParams['badgeNumber'] = $badgeNumberFilter;
      }
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
            <div class="d-flex flex-wrap justify-content-end mb-3">
              <label class="sr-only" for="loggedBadgesSearch">Badge number</label>
              <div class="input-group input-group-sm" style="max-width: 280px; width: 100%;">
                <input id="loggedBadgesSearch" class="form-control" type="text" placeholder="Badge number" autocomplete="off" value="<?= h($badgeNumberFilter) ?>">
                <div class="input-group-append">
                  <button id="loggedBadgesSearchBtn" class="btn btn-outline-secondary" type="button">Search</button>
                  <button id="loggedBadgesSearchClear" class="btn btn-outline-secondary" type="button">Clear</button>
                </div>
              </div>
            </div>
            <div id="loggedBadgesTableWrapper" class="table-responsive<?= !empty($loggedBadgesRows) ? '' : ' d-none' ?>">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Employee Code (utime)</th>
                    <th>Name (utime)</th>
                    <th>Name (hrms)</th>
                    <th>Department (hrms)</th>
                    <th>Designation (hrms)</th>
                    <th>First Punch time (utime)</th>
                    <th>First Punch device (utime)</th>
                    <th>FP Project ID</th>
                    <th>FP project name</th>
                    <th>Last Punch time (utime)</th>
                    <th>Last Punch device (utime)</th>
                    <th>LP project id</th>
                    <th>LP project name</th>
                  </tr>
                </thead>
                <tbody id="loggedBadgesTableBody">
                  <?php foreach ($loggedBadgesRows as $row): ?>
                    <?php
                      $badgeNumber = trim((string) ($row['badgeNumber'] ?? ''));
                      $utimeName = trim((string) ($row['utimeName'] ?? ''));
                      $hrmsName = trim((string) ($row['hrmsName'] ?? ''));
                      $department = trim((string) ($row['department'] ?? ''));
                      $designation = trim((string) ($row['designation'] ?? ''));
                      $firstLoginTime = trim((string) ($row['firstLoginTime'] ?? ''));
                      $lastLoginTime = trim((string) ($row['lastLoginTime'] ?? ''));
                      $firstLoginDeviceSn = trim((string) ($row['firstLoginDeviceSn'] ?? ''));
                      $lastLoginDeviceSn = trim((string) ($row['lastLoginDeviceSn'] ?? ''));
                      $firstLoginProjectId = trim((string) ($row['firstLoginProjectId'] ?? ''));
                      $firstLoginProjectName = trim((string) ($row['firstLoginProjectName'] ?? ''));
                      $lastLoginProjectId = trim((string) ($row['lastLoginProjectId'] ?? ''));
                      $lastLoginProjectName = trim((string) ($row['lastLoginProjectName'] ?? ''));
                    ?>
                    <tr>
                      <td><?= h($badgeNumber) ?></td>
                      <td><?= h($utimeName !== '' ? $utimeName : '-') ?></td>
                      <td><?= h($hrmsName !== '' ? $hrmsName : '-') ?></td>
                      <td><?= h($department !== '' ? $department : '-') ?></td>
                      <td><?= h($designation !== '' ? $designation : '-') ?></td>
                      <td><?= h($firstLoginTime !== '' ? $firstLoginTime : '-') ?></td>
                      <td><?= h($firstLoginDeviceSn !== '' ? $firstLoginDeviceSn : '-') ?></td>
                      <td><?= h($firstLoginProjectId !== '' ? $firstLoginProjectId : '-') ?></td>
                      <td><?= h($firstLoginProjectName !== '' ? $firstLoginProjectName : '-') ?></td>
                      <td><?= h($lastLoginTime !== '' ? $lastLoginTime : '-') ?></td>
                      <td><?= h($lastLoginDeviceSn !== '' ? $lastLoginDeviceSn : '-') ?></td>
                      <td><?= h($lastLoginProjectId !== '' ? $lastLoginProjectId : '-') ?></td>
                      <td><?= h($lastLoginProjectName !== '' ? $lastLoginProjectName : '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p id="loggedBadgesEmpty" class="text-muted mb-0<?= !empty($loggedBadgesRows) ? ' d-none' : '' ?>">
              <?= h($loggedBadgesEmptyText) ?>
            </p>
            <div id="loggedBadgesPagination" class="d-flex flex-wrap justify-content-between align-items-center mt-3 d-none">
              <button id="loggedBadgesPrev" class="btn btn-sm btn-outline-secondary" type="button">Prev</button>
              <div class="d-flex align-items-center">
                <span id="loggedBadgesPageInfo" class="text-muted small mr-3"></span>
                <div class="input-group input-group-sm" style="width: 120px;">
                  <input id="loggedBadgesPageInput" class="form-control" type="number" min="1" placeholder="Page">
                  <div class="input-group-append">
                    <button id="loggedBadgesGo" class="btn btn-outline-secondary" type="button">Go</button>
                  </div>
                </div>
              </div>
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
              <input type="hidden" name="deviceSn" value="<?= h($deviceSnParam) ?>">
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
                <?php
                  $employeeName = $hrmsSummary['employeeName'] ?? 'Employee';
                  $employeeCodeLabel = trim((string) ($hrmsSummary['employeeCode'] ?? $employeeCode));
                  $departmentDisplay = format_name_with_code(
                      $hrmsSummary['department'] ?? '',
                      $hrmsSummary['departmentCode'] ?? '',
                      'Department n/a'
                  );
                  $designationDisplay = format_name_with_code(
                      $hrmsSummary['designation'] ?? '',
                      $hrmsSummary['designationCode'] ?? '',
                      'Designation n/a'
                  );
                  $statusDisplay = $hrmsSummary['status'] ?? 'n/a';
                  $companyDisplay = trim((string) ($hrmsSummary['companyCode'] ?? ''));
                  if ($companyDisplay === '') {
                      $companyDisplay = 'n/a';
                  }
                  $workTypeDisplay = format_name_with_code(
                      $hrmsSummary['workTypeDescription'] ?? '',
                      $hrmsSummary['workTypeCode'] ?? '',
                      'n/a'
                  );
                  $todayWorkingDisplay = format_yes_no($hrmsSummary['todayWorking'] ?? null);
                  $onEleaveDisplay = format_yes_no($hrmsSummary['onEleave'] ?? null);
                  $leaveDisplay = format_name_with_code(
                      $hrmsSummary['leaveDescription'] ?? '',
                      $hrmsSummary['leaveCode'] ?? '',
                      'n/a'
                  );
                ?>
                <div class="mb-3">
                  <div class="h5 mb-1">
                    <?= h($employeeName) ?>
                    <?php if ($employeeCodeLabel !== ''): ?>
                      <span class="text-muted small">(<?= h($employeeCodeLabel) ?>)</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted">
                    <?= h($departmentDisplay) ?> | <?= h($designationDisplay) ?>
                  </div>
                  <div class="text-muted">Status: <?= h($statusDisplay) ?></div>
                </div>
                <div class="row mb-2">
                  <div class="col-md-4 col-6 mb-2">
                    <div class="text-muted small">Company</div>
                    <div class="h6 mb-0"><?= h($companyDisplay) ?></div>
                  </div>
                  <div class="col-md-4 col-6 mb-2">
                    <div class="text-muted small">Work type</div>
                    <div class="h6 mb-0"><?= h($workTypeDisplay) ?></div>
                  </div>
                  <div class="col-md-4 col-6 mb-2">
                    <div class="text-muted small">Today working</div>
                    <div class="h6 mb-0"><?= h($todayWorkingDisplay) ?></div>
                  </div>
                  <div class="col-md-4 col-6 mb-2">
                    <div class="text-muted small">On leave</div>
                    <div class="h6 mb-0"><?= h($onEleaveDisplay) ?></div>
                  </div>
                  <div class="col-md-4 col-6 mb-2">
                    <div class="text-muted small">Leave type</div>
                    <div class="h6 mb-0"><?= h($leaveDisplay) ?></div>
                  </div>
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
              <div class="border-top pt-3 mt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="mb-0">UTime punch details</h6>
                  <span class="text-muted small">UTime API</span>
                </div>
                <div id="utimeSnapshotContent">
                  <?php if ($employeeCode === ''): ?>
                    <p class="text-muted mb-0">Enter an employee code above to load UTime punch details.</p>
                  <?php else: ?>
                    <p class="text-muted mb-0">Loading UTime details...</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const errorBox = document.getElementById('apiErrors');
    const setText = (id, value) => {
      const el = document.getElementById(id);
      if (el) {
        el.textContent = value;
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
    const formatNameWithCode = (name, code, fallback) => {
      const cleanName = String(name ?? '').trim();
      const cleanCode = String(code ?? '').trim();
      if (cleanName && cleanCode) {
        return `${cleanName} (${cleanCode})`;
      }
      if (cleanName) {
        return cleanName;
      }
      if (cleanCode) {
        return cleanCode;
      }
      return fallback;
    };
    const formatYesNo = (value) => {
      if (value === true) {
        return 'Yes';
      }
      if (value === false) {
        return 'No';
      }
      return 'n/a';
    };
    const valueOrDash = (value) => {
      const text = String(value ?? '').trim();
      return text !== '' ? text : '-';
    };
    const updateProjectPunchesSummary = (labelText) => {
      const countEl = document.getElementById('activeDeviceReportCount');
      const titleEl = document.getElementById('activeDeviceReportLabel');
      const subEl = document.getElementById('activeDeviceReportSub');
      const rawLabel = String(labelText || '');
      let count = '';
      let title = rawLabel;
      let sub = '';
      const match = rawLabel.match(/^(\d+)\s+(.*)$/);
      if (match) {
        count = match[1];
        title = match[2];
      }
      if (title.includes('(Devices active/total / Employees:)')) {
        title = title.replace('(Devices active/total / Employees:)', '').trim();
        sub = 'Devices active/total / Employees';
      } else if (title.includes('(Devices/Employees:)')) {
        title = title.replace('(Devices/Employees:)', '').trim();
        sub = 'Devices / Employees';
      }
      if (countEl) {
        countEl.textContent = count || '-';
      }
      if (titleEl) {
        titleEl.textContent = title || 'Projects with punches';
      }
      if (subEl) {
        subEl.textContent = sub;
        subEl.classList.toggle('d-none', !sub);
      }
    };
    const parseProjectPunchesMeta = (metaText) => {
      const raw = String(metaText || '');
      if (!raw || raw.includes('unavailable') || raw.includes('No devices')) {
        return [];
      }
      const parts = raw.split(' | ');
      const rows = [];
      for (const part of parts) {
        const trimmed = part.trim();
        if (!trimmed) {
          continue;
        }
        const matchThree = trimmed.match(/^(.*)\s+(\d+)\/(\d+)\/(\d+)$/);
        if (matchThree) {
          rows.push({
            label: matchThree[1].trim(),
            devicesActive: Number(matchThree[2]),
            devicesTotal: Number(matchThree[3]),
            employees: Number(matchThree[4]),
          });
          continue;
        }
        const matchTwo = trimmed.match(/^(.*)\s+(\d+)\/(\d+)$/);
        if (!matchTwo) {
          return [];
        }
        rows.push({
          label: matchTwo[1].trim(),
          devicesActive: Number(matchTwo[2]),
          devicesTotal: null,
          employees: Number(matchTwo[3]),
        });
      }
      return rows;
    };
    const renderProjectPunches = (metaText) => {
      const chart = document.getElementById('activeDeviceReportChart');
      const fallback = document.getElementById('activeDeviceReportMeta');
      if (!chart) {
        return;
      }
      chart.innerHTML = '';
      const rows = parseProjectPunchesMeta(metaText);
      if (!rows.length) {
        chart.classList.add('d-none');
        if (fallback) {
          fallback.textContent = metaText || '';
          fallback.classList.toggle('d-none', !(metaText || ''));
        }
        return;
      }
      const maxDevices = Math.max(...rows.map((row) => row.devicesActive), 0);
      const maxEmployees = Math.max(...rows.map((row) => row.employees), 0);
      const fragment = document.createDocumentFragment();
      rows.forEach((row) => {
        const item = document.createElement('div');
        item.className = 'project-punches-item';
        const hasTotal = Number.isFinite(row.devicesTotal);
        const totalDevices = hasTotal ? row.devicesTotal : row.devicesActive;
        const devicePct = hasTotal
          ? (totalDevices > 0 ? (row.devicesActive / totalDevices) * 100 : 0)
          : (maxDevices > 0 ? (row.devicesActive / maxDevices) * 100 : 0);
        const employeePct = maxEmployees > 0 ? (row.employees / maxEmployees) * 100 : 0;
        const deviceCountText = hasTotal ? `${row.devicesActive} / ${totalDevices}` : `${row.devicesActive}`;
        const deviceLabel = hasTotal ? 'Devices (active/total)' : 'Devices';
        item.innerHTML = `
          <div class="project-punches-item-title">${escapeHtml(row.label)}</div>
          <div class="project-punches-item-counts">${deviceCountText} devices · ${row.employees} employees</div>
          <div class="project-punches-bars">
            <div class="project-punches-metric">
              <div class="project-punches-metric-label">
                <span>${deviceLabel}</span>
                <span class="project-punches-metric-value">${deviceCountText}</span>
              </div>
              <div class="project-punches-bar" style="--bar-size:${devicePct.toFixed(1)}%; --bar-color:#1b4f9a"></div>
            </div>
            <div class="project-punches-metric">
              <div class="project-punches-metric-label">
                <span>Employees</span>
                <span class="project-punches-metric-value">${row.employees}</span>
              </div>
              <div class="project-punches-bar" style="--bar-size:${employeePct.toFixed(1)}%; --bar-color:#2f855a"></div>
            </div>
          </div>
        `;
        fragment.appendChild(item);
      });
      chart.appendChild(fragment);
      chart.classList.remove('d-none');
      if (fallback) {
        fallback.classList.add('d-none');
      }
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
      const employeeCode = String(summary.employeeCode ?? '').trim();
      const departmentDisplay = formatNameWithCode(
        summary.department,
        summary.departmentCode,
        'Department n/a'
      );
      const designationDisplay = formatNameWithCode(
        summary.designation,
        summary.designationCode,
        'Designation n/a'
      );
      const status = summary.status || 'n/a';
      const companyDisplay = String(summary.companyCode ?? '').trim() || 'n/a';
      const workTypeDisplay = formatNameWithCode(
        summary.workTypeDescription,
        summary.workTypeCode,
        'n/a'
      );
      const todayWorkingText = formatYesNo(summary.todayWorking);
      const onEleaveText = formatYesNo(summary.onEleave);
      const leaveDisplay = formatNameWithCode(
        summary.leaveDescription,
        summary.leaveCode,
        'n/a'
      );
      const attendanceDays = summary.attendanceDays ?? 0;
      const attendanceError = summary.attendanceError || '';
      const leaveDays = summary.leaveDays ?? 0;
      const lastAttendance = summary.lastAttendance || 'n/a';
      const holidayCount = summary.holidayCount ?? 0;
      const attendanceErrorHtml = attendanceError
        ? `<div class="text-warning small mt-1">${escapeHtml(attendanceError)}</div>`
        : '';
      const employeeCodeHtml = employeeCode !== ''
        ? `<span class="text-muted small">(${escapeHtml(employeeCode)})</span>`
        : '';
      hrmsContent.innerHTML = `
        <div class="mb-3">
          <div class="h5 mb-1">${escapeHtml(employeeName)} ${employeeCodeHtml}</div>
          <div class="text-muted">${escapeHtml(departmentDisplay)} | ${escapeHtml(designationDisplay)}</div>
          <div class="text-muted">Status: ${escapeHtml(status)}</div>
        </div>
        <div class="row mb-2">
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Company</div>
            <div class="h6 mb-0">${escapeHtml(companyDisplay)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Work type</div>
            <div class="h6 mb-0">${escapeHtml(workTypeDisplay)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Today working</div>
            <div class="h6 mb-0">${escapeHtml(todayWorkingText)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">On leave</div>
            <div class="h6 mb-0">${escapeHtml(onEleaveText)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Leave type</div>
            <div class="h6 mb-0">${escapeHtml(leaveDisplay)}</div>
          </div>
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
    const renderUtimeSnapshotState = (message) => {
      const utimeContent = document.getElementById('utimeSnapshotContent');
      if (!utimeContent) {
        return;
      }
      utimeContent.innerHTML = `<p class="text-muted mb-0">${escapeHtml(message)}</p>`;
    };
    const renderUtimeSnapshot = (payload, hrmsSummary) => {
      const utimeContent = document.getElementById('utimeSnapshotContent');
      if (!utimeContent) {
        return;
      }
      const data = payload || {};
      if (data.ok === false) {
        utimeContent.innerHTML = '<div class="alert alert-warning mb-0">Unable to load UTime details.</div>';
        return;
      }
      const rows = Array.isArray(data.rows) ? data.rows : [];
      if (!rows.length) {
        const note = data.note || 'No UTime punches found in range.';
        utimeContent.innerHTML = `<p class="text-muted mb-0">${escapeHtml(note)}</p>`;
        return;
      }
      const row = rows[0] || {};
      const summary = hrmsSummary || {};
      const departmentDisplay = formatNameWithCode(
        summary.department,
        summary.departmentCode,
        ''
      );
      const designationDisplay = formatNameWithCode(
        summary.designation,
        summary.designationCode,
        ''
      );
      const badgeNumber = valueOrDash(row.badgeNumber);
      const utimeName = valueOrDash(row.utimeName || row.name);
      const hrmsName = valueOrDash(row.hrmsName || summary.employeeName);
      const department = valueOrDash(row.department || departmentDisplay);
      const designation = valueOrDash(row.designation || designationDisplay);
      const firstLoginTime = valueOrDash(row.firstLoginTime);
      const firstLoginDevice = valueOrDash(row.firstLoginDeviceSn);
      const firstLoginProjectId = valueOrDash(row.firstLoginProjectId);
      const firstLoginProjectName = valueOrDash(row.firstLoginProjectName);
      const lastLoginTime = valueOrDash(row.lastLoginTime);
      const lastLoginDevice = valueOrDash(row.lastLoginDeviceSn);
      const lastLoginProjectId = valueOrDash(row.lastLoginProjectId);
      const lastLoginProjectName = valueOrDash(row.lastLoginProjectName);

      utimeContent.innerHTML = `
        <div class="row">
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Employee Code (utime)</div>
            <div class="h6 mb-0">${escapeHtml(badgeNumber)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Name (utime)</div>
            <div class="h6 mb-0">${escapeHtml(utimeName)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Name (hrms)</div>
            <div class="h6 mb-0">${escapeHtml(hrmsName)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Department (hrms)</div>
            <div class="h6 mb-0">${escapeHtml(department)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Designation (hrms)</div>
            <div class="h6 mb-0">${escapeHtml(designation)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">First login time (utime)</div>
            <div class="h6 mb-0">${escapeHtml(firstLoginTime)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">First login device (utime)</div>
            <div class="h6 mb-0">${escapeHtml(firstLoginDevice)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">FP Project ID</div>
            <div class="h6 mb-0">${escapeHtml(firstLoginProjectId)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">FP project name</div>
            <div class="h6 mb-0">${escapeHtml(firstLoginProjectName)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Last login time (utime)</div>
            <div class="h6 mb-0">${escapeHtml(lastLoginTime)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">Last login device (utime)</div>
            <div class="h6 mb-0">${escapeHtml(lastLoginDevice)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">LP project id</div>
            <div class="h6 mb-0">${escapeHtml(lastLoginProjectId)}</div>
          </div>
          <div class="col-md-4 col-6 mb-2">
            <div class="text-muted small">LP project name</div>
            <div class="h6 mb-0">${escapeHtml(lastLoginProjectName)}</div>
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
      badgeQuery: '',
      cache: {},
      inflight: {},
    };
    let loggedBadgesBound = false;

    const normalizeBadgeQuery = (value) => String(value ?? '').trim();
    const loggedBadgesCacheKey = (page, pageSize, badgeQuery = loggedBadgesState.badgeQuery) => {
      const normalized = normalizeBadgeQuery(badgeQuery).toLowerCase();
      return `${page}:${pageSize}:${normalized}`;
    };
    const getLoggedBadgesCache = (page, pageSize, badgeQuery) => loggedBadgesState.cache[loggedBadgesCacheKey(page, pageSize, badgeQuery)];
    const setLoggedBadgesCache = (page, pageSize, payload, badgeQuery) => {
      loggedBadgesState.cache[loggedBadgesCacheKey(page, pageSize, badgeQuery)] = payload;
    };
    const isLoggedBadgesInflight = (page, pageSize, badgeQuery) => !!loggedBadgesState.inflight[loggedBadgesCacheKey(page, pageSize, badgeQuery)];
    const setLoggedBadgesInflight = (page, pageSize, value, badgeQuery) => {
      const key = loggedBadgesCacheKey(page, pageSize, badgeQuery);
      if (value) {
        loggedBadgesState.inflight[key] = true;
      } else {
        delete loggedBadgesState.inflight[key];
      }
    };
    const loggedBadgesSearchInput = document.getElementById('loggedBadgesSearch');
    const loggedBadgesSearchBtn = document.getElementById('loggedBadgesSearchBtn');
    const loggedBadgesSearchClear = document.getElementById('loggedBadgesSearchClear');
    if (loggedBadgesSearchInput) {
      loggedBadgesState.badgeQuery = normalizeBadgeQuery(loggedBadgesSearchInput.value);
    }

    const renderLoggedBadgesPage = (page) => {
      const metaEl = document.getElementById('loggedBadgesMeta');
      const tableWrapper = document.getElementById('loggedBadgesTableWrapper');
      const tableBody = document.getElementById('loggedBadgesTableBody');
      const emptyEl = document.getElementById('loggedBadgesEmpty');
      const paginationEl = document.getElementById('loggedBadgesPagination');
      const prevBtn = document.getElementById('loggedBadgesPrev');
      const nextBtn = document.getElementById('loggedBadgesNext');
      const pageInfoEl = document.getElementById('loggedBadgesPageInfo');
      const pageInput = document.getElementById('loggedBadgesPageInput');
      if (!metaEl || !tableWrapper || !tableBody || !emptyEl || !paginationEl || !pageInfoEl) {
        return;
      }
      const rows = loggedBadgesState.rows;
      const note = loggedBadgesState.note;
      const ok = loggedBadgesState.ok;
      const count = loggedBadgesState.count;
      const pageSize = loggedBadgesState.pageSize;
      const totalCount = count > 0 ? count : rows.length;

      if (ok) {
        metaEl.textContent = note !== '' ? note : `${totalCount} badges`;
      } else {
        metaEl.textContent = 'Unable to load badges';
      }

      if (ok && rows.length) {
        const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));
        const safePage = Math.min(Math.max(page, 1), totalPages);
        loggedBadgesState.page = safePage;
        if (pageInput) {
          pageInput.min = '1';
          pageInput.max = String(totalPages);
          pageInput.value = String(safePage);
        }
        const pageRows = rows;
        tableBody.innerHTML = pageRows.map((row) => {
          const badge = String((row && row.badgeNumber) || '').trim();
          const utimeName = String((row && row.utimeName) || '').trim();
          const hrmsName = String((row && row.hrmsName) || '').trim();
          const department = String((row && row.department) || '').trim();
          const designation = String((row && row.designation) || '').trim();
          const firstLoginTime = String((row && row.firstLoginTime) || '').trim();
          const lastLoginTime = String((row && row.lastLoginTime) || '').trim();
          const firstLoginDeviceSn = String((row && row.firstLoginDeviceSn) || '').trim();
          const lastLoginDeviceSn = String((row && row.lastLoginDeviceSn) || '').trim();
          const firstLoginProjectId = String((row && row.firstLoginProjectId) || '').trim();
          const firstLoginProjectName = String((row && row.firstLoginProjectName) || '').trim();
          const lastLoginProjectId = String((row && row.lastLoginProjectId) || '').trim();
          const lastLoginProjectName = String((row && row.lastLoginProjectName) || '').trim();
          const utimeNameText = utimeName !== '' ? utimeName : '-';
          const hrmsNameText = hrmsName !== '' ? hrmsName : '-';
          const departmentText = department !== '' ? department : '-';
          const designationText = designation !== '' ? designation : '-';
          const firstLoginTimeText = firstLoginTime !== '' ? firstLoginTime : '-';
          const lastLoginTimeText = lastLoginTime !== '' ? lastLoginTime : '-';
          const firstLoginDeviceSnText = firstLoginDeviceSn !== '' ? firstLoginDeviceSn : '-';
          const lastLoginDeviceSnText = lastLoginDeviceSn !== '' ? lastLoginDeviceSn : '-';
          const firstLoginProjectIdText = firstLoginProjectId !== '' ? firstLoginProjectId : '-';
          const firstLoginProjectNameText = firstLoginProjectName !== '' ? firstLoginProjectName : '-';
          const lastLoginProjectIdText = lastLoginProjectId !== '' ? lastLoginProjectId : '-';
          const lastLoginProjectNameText = lastLoginProjectName !== '' ? lastLoginProjectName : '-';
          return `<tr><td>${escapeHtml(badge)}</td><td>${escapeHtml(utimeNameText)}</td><td>${escapeHtml(hrmsNameText)}</td><td>${escapeHtml(departmentText)}</td><td>${escapeHtml(designationText)}</td><td>${escapeHtml(firstLoginTimeText)}</td><td>${escapeHtml(firstLoginDeviceSnText)}</td><td>${escapeHtml(firstLoginProjectIdText)}</td><td>${escapeHtml(firstLoginProjectNameText)}</td><td>${escapeHtml(lastLoginTimeText)}</td><td>${escapeHtml(lastLoginDeviceSnText)}</td><td>${escapeHtml(lastLoginProjectIdText)}</td><td>${escapeHtml(lastLoginProjectNameText)}</td></tr>`;
        }).join('');
        tableWrapper.classList.remove('d-none');
        emptyEl.classList.add('d-none');
        if (totalCount > pageSize) {
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
        const startLabel = totalCount > 0 ? ((safePage - 1) * pageSize + 1) : 0;
        const endLabel = totalCount > 0 ? Math.min(startLabel + pageRows.length - 1, totalCount) : 0;
        pageInfoEl.textContent = totalCount > 0
          ? `Showing ${startLabel}-${endLabel} of ${totalCount} | Page ${safePage} of ${totalPages}`
          : 'Showing 0';
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
        pageInfoEl.textContent = '';
        if (pageInput) {
          pageInput.value = '';
        }
      }
    };

    const renderLoggedBadges = (badges) => {
      loggedBadgesState.rows = Array.isArray(badges.rows) ? badges.rows : [];
      loggedBadgesState.note = String(badges.note || '');
      loggedBadgesState.ok = badges.ok !== false;
      loggedBadgesState.count = Number.isFinite(Number(badges.count))
        ? Number(badges.count)
        : loggedBadgesState.rows.length;
      if (loggedBadgesState.count === 0 && loggedBadgesState.rows.length > 0) {
        loggedBadgesState.count = loggedBadgesState.rows.length;
      }
      const nextPage = Number(badges.page);
      if (Number.isFinite(nextPage) && nextPage > 0) {
        loggedBadgesState.page = nextPage;
      } else if (!loggedBadgesState.page) {
        loggedBadgesState.page = 1;
      }
      const nextPageSize = Number(badges.pageSize);
      if (Number.isFinite(nextPageSize) && nextPageSize > 0) {
        loggedBadgesState.pageSize = nextPageSize;
      }

      setLoggedBadgesCache(loggedBadgesState.page, loggedBadgesState.pageSize, {
        ok: loggedBadgesState.ok,
        note: loggedBadgesState.note,
        count: loggedBadgesState.count,
        rows: loggedBadgesState.rows,
        page: loggedBadgesState.page,
        pageSize: loggedBadgesState.pageSize,
      }, loggedBadgesState.badgeQuery);

      if (!loggedBadgesBound) {
        const prevBtn = document.getElementById('loggedBadgesPrev');
        const nextBtn = document.getElementById('loggedBadgesNext');
        const pageInput = document.getElementById('loggedBadgesPageInput');
        const goBtn = document.getElementById('loggedBadgesGo');
        const applySearch = (value) => {
          const nextQuery = normalizeBadgeQuery(value);
          if (nextQuery === loggedBadgesState.badgeQuery) {
            return;
          }
          loggedBadgesState.badgeQuery = nextQuery;
          loggedBadgesState.cache = {};
          loggedBadgesState.inflight = {};
          requestLoggedBadgesPage(1);
        };
        if (prevBtn) {
          prevBtn.addEventListener('click', () => {
            requestLoggedBadgesPage(loggedBadgesState.page - 1);
          });
        }
        if (nextBtn) {
          nextBtn.addEventListener('click', () => {
            requestLoggedBadgesPage(loggedBadgesState.page + 1);
          });
        }
        if (pageInput && goBtn) {
          const submitPage = () => {
            const value = Number.parseInt(pageInput.value, 10);
            if (Number.isFinite(value)) {
              requestLoggedBadgesPage(value);
            }
          };
          goBtn.addEventListener('click', submitPage);
          pageInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              submitPage();
            }
          });
        }
        if (loggedBadgesSearchInput && loggedBadgesSearchBtn) {
          const submitSearch = () => applySearch(loggedBadgesSearchInput.value);
          loggedBadgesSearchBtn.addEventListener('click', submitSearch);
          loggedBadgesSearchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              submitSearch();
            }
          });
        }
        if (loggedBadgesSearchClear) {
          loggedBadgesSearchClear.addEventListener('click', () => {
            if (loggedBadgesSearchInput) {
              loggedBadgesSearchInput.value = '';
              loggedBadgesSearchInput.focus();
            }
            applySearch('');
          });
        }
        loggedBadgesBound = true;
      }

      renderLoggedBadgesPage(loggedBadgesState.page);
      prefetchNextLoggedBadges();
    };

    const initialLoggedBadges = <?= json_encode(
        [
            'ok' => $loggedBadgesOk,
            'note' => $loggedBadgesNote,
            'count' => $loggedBadgesCount,
            'rows' => $loggedBadgesRows,
            'page' => $loggedBadgesPage,
            'pageSize' => $loggedBadgesPageSize,
        ],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
    ) ?>;

    const hrmsForm = document.getElementById('hrmsSnapshotForm');
    const hrmsButton = hrmsForm ? hrmsForm.querySelector('button[type="submit"]') : null;
    const hrmsContent = document.getElementById('hrmsSnapshotContent');

    const fetchUtimeSnapshot = (code, hrmsSummary) => {
      if (!hrmsForm) {
        return;
      }
      const cleanCode = String(code ?? '').trim();
      if (cleanCode === '') {
        renderUtimeSnapshotState('Enter an employee code above to load UTime punch details.');
        return;
      }
      renderUtimeSnapshotState('Loading UTime details...');
      const formData = new FormData(hrmsForm);
      formData.set('badgeNumber', cleanCode);
      formData.set('page', '1');
      formData.set('pageSize', '1');
      formData.set('includeHrms', '0');
      const utimeParams = new URLSearchParams(formData);
      utimeParams.set('ajax', '1');
      utimeParams.set('ajax_section', 'logged-badges');
      const utimeUrl = baseUrl + '?' + utimeParams.toString();
      fetch(utimeUrl, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        })
        .then((data) => {
          renderUtimeSnapshot((data && data.loggedBadges) || {}, hrmsSummary || {});
        })
        .catch(() => {
          renderUtimeSnapshotState('Unable to load UTime details.');
        });
    };

    const fetchHrmsSnapshot = (code) => {
      if (!hrmsForm) {
        return;
      }
      const cleanCode = String(code ?? '').trim();
      if (cleanCode === '') {
        renderHrmsSnapshot({ enabled: false });
        renderUtimeSnapshotState('Enter an employee code above to load UTime punch details.');
        return;
      }
      const formData = new FormData(hrmsForm);
      formData.set('employeeCode', cleanCode);
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
          const hrmsPayload = (data && data.hrms) || {};
          renderHrmsSnapshot(hrmsPayload);
          fetchUtimeSnapshot(cleanCode, hrmsPayload.summary || {});
        })
        .catch(() => {
          if (hrmsContent) {
            hrmsContent.innerHTML = '<div class="alert alert-warning mb-0">Unable to load HRMS details.</div>';
          }
          fetchUtimeSnapshot(cleanCode, {});
        })
        .finally(() => {
          if (hrmsButton) {
            hrmsButton.disabled = false;
          }
        });
    };

    if (hrmsForm) {
      hrmsForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(hrmsForm);
        const code = String(formData.get('employeeCode') ?? '').trim();
        fetchHrmsSnapshot(code);
      });

      const employeeInput = hrmsForm.querySelector('#employeeCode');
      const initialCode = employeeInput ? String(employeeInput.value ?? '').trim() : '';
      fetchHrmsSnapshot(initialCode);
    }

    const baseParams = new URLSearchParams(window.location.search);
    baseParams.delete('ajax_section');
    baseParams.delete('badgeNumber');
    baseParams.set('ajax', '1');

    const panelErrors = new Set();
    const updateErrorBox = () => {
      if (!errorBox) {
        return;
      }
      if (!panelErrors.size) {
        errorBox.classList.add('d-none');
        return;
      }
      errorBox.textContent = `Some data panels could not be refreshed: ${Array.from(panelErrors).join(', ')}.`;
      errorBox.classList.remove('d-none');
    };
    const setPanelError = (label, hasError) => {
      if (!label) {
        return;
      }
      if (hasError) {
        panelErrors.add(label);
      } else {
        panelErrors.delete(label);
      }
      updateErrorBox();
    };

    const fetchActiveDevices = () => {
      const params = new URLSearchParams(baseParams);
      params.set('ajax_section', 'active-devices');
      const url = baseUrl + '?' + params.toString();

      fetch(url, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        })
        .then((data) => {
          const activeDevices = (data && data.activeDevices) || {};
          setText('activeDeviceCount', activeDevices.activeDeviceCountText || '-');
          setText('activeDeviceLabel', activeDevices.activeDeviceLabel || 'Projects with punches');
          setText('activeDeviceMeta', activeDevices.activeDeviceMeta || '');
          updateProjectPunchesSummary(activeDevices.activeDeviceLabel || 'Projects with punches');
          renderProjectPunches(activeDevices.activeDeviceMeta || '');
          const errors = Array.isArray(data.errors) ? data.errors.filter(Boolean) : [];
          setPanelError('Project counts', errors.length > 0);
        })
        .catch(() => {
          setText('activeDeviceCount', '-');
          setText('activeDeviceLabel', 'Projects with punches');
          setText('activeDeviceMeta', 'Project counts unavailable');
          updateProjectPunchesSummary('Projects with punches');
          renderProjectPunches('Project counts unavailable');
          setPanelError('Project counts', true);
        });
    };

    const fetchDeviceStatus = () => {
      const params = new URLSearchParams(baseParams);
      params.set('ajax_section', 'device-status');
      const url = baseUrl + '?' + params.toString();

      fetch(url, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        })
        .then((data) => {
          const deviceStatus = (data && data.deviceStatus) || {};
          setText('deviceStatusRatio', deviceStatus.deviceStatusRatio || '-');
          setText('deviceStatusMeta', deviceStatus.deviceStatusMeta || '');
          const errors = Array.isArray(data.errors) ? data.errors.filter(Boolean) : [];
          setPanelError('Online/total devices', errors.length > 0);
        })
        .catch(() => {
          setText('deviceStatusRatio', '-');
          setText('deviceStatusMeta', 'Status data unavailable');
          setPanelError('Online/total devices', true);
        });
    };

    const fetchBadgeRatio = () => {
      const params = new URLSearchParams(baseParams);
      params.set('ajax_section', 'badge-ratio');
      const url = baseUrl + '?' + params.toString();

      fetch(url, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        })
        .then((data) => {
          const badgeRatio = (data && data.badgeRatio) || {};
          setText('badgeRatioText', badgeRatio.badgeRatioText || '-');
          setText('badgeCardTitle', badgeRatio.badgeCardTitle || 'Logged in employees');
          setText('badgeCoverageLabel', badgeRatio.badgeCoverageLabel || '');
          const errors = Array.isArray(data.errors) ? data.errors.filter(Boolean) : [];
          setPanelError('Logged in / active employees', errors.length > 0);
        })
        .catch(() => {
          setText('badgeRatioText', '-');
          setText('badgeCardTitle', 'Logged in employees');
          setText('badgeCoverageLabel', 'Coverage n/a');
          setPanelError('Logged in / active employees', true);
        });
    };

    const fetchLoggedBadges = (page = loggedBadgesState.page, pageSize = loggedBadgesState.pageSize, options = {}) => {
      const prefetch = options.prefetch === true;
      const badgeQuery = loggedBadgesState.badgeQuery;
      if (prefetch && isLoggedBadgesInflight(page, pageSize, badgeQuery)) {
        return;
      }
      const badgesParams = new URLSearchParams(baseParams);
      badgesParams.set('ajax_section', 'logged-badges');
      badgesParams.set('page', String(page));
      badgesParams.set('pageSize', String(pageSize));
      if (badgeQuery !== '') {
        badgesParams.set('badgeNumber', badgeQuery);
      } else {
        badgesParams.delete('badgeNumber');
      }
      const badgesUrl = baseUrl + '?' + badgesParams.toString();

      if (prefetch) {
        setLoggedBadgesInflight(page, pageSize, true, badgeQuery);
      }

      fetch(badgesUrl, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        })
        .then((data) => {
          const loggedBadges = (data && data.loggedBadges) || {};
          if (!Number.isFinite(Number(loggedBadges.page))) {
            loggedBadges.page = page;
          }
          if (!Number.isFinite(Number(loggedBadges.pageSize))) {
            loggedBadges.pageSize = pageSize;
          }
          if (prefetch) {
            if (loggedBadges.ok !== false) {
              setLoggedBadgesCache(loggedBadges.page, loggedBadges.pageSize, loggedBadges, badgeQuery);
            }
            return;
          }
          renderLoggedBadges(loggedBadges);
          setPanelError('Logged in badges', loggedBadges.ok === false);
        })
        .catch(() => {
          if (prefetch) {
            return;
          }
          renderLoggedBadges({ ok: false, page, pageSize });
          setPanelError('Logged in badges', true);
        })
        .finally(() => {
          if (prefetch) {
            setLoggedBadgesInflight(page, pageSize, false, badgeQuery);
          }
        });
    };

    const prefetchNextLoggedBadges = () => {
      const totalCount = loggedBadgesState.count > 0 ? loggedBadgesState.count : loggedBadgesState.rows.length;
      const totalPages = Math.max(1, Math.ceil(totalCount / loggedBadgesState.pageSize));
      const nextPage = loggedBadgesState.page + 1;
      if (nextPage > totalPages) {
        return;
      }
      if (getLoggedBadgesCache(nextPage, loggedBadgesState.pageSize, loggedBadgesState.badgeQuery)) {
        return;
      }
      fetchLoggedBadges(nextPage, loggedBadgesState.pageSize, { prefetch: true });
    };

    const requestLoggedBadgesPage = (page) => {
      const requested = Number(page);
      if (!Number.isFinite(requested)) {
        return;
      }
      const totalCount = loggedBadgesState.count > 0 ? loggedBadgesState.count : loggedBadgesState.rows.length;
      const totalPages = Math.max(1, Math.ceil(totalCount / loggedBadgesState.pageSize));
      const safePage = Math.min(Math.max(Math.floor(requested), 1), totalPages);
      const cached = getLoggedBadgesCache(safePage, loggedBadgesState.pageSize, loggedBadgesState.badgeQuery);
      if (cached) {
        renderLoggedBadges(cached);
        return;
      }
      loggedBadgesState.page = safePage;
      loggedBadgesState.note = 'Loading logged in badges...';
      loggedBadgesState.rows = [];
      renderLoggedBadgesPage(safePage);
      fetchLoggedBadges(safePage, loggedBadgesState.pageSize);
    };

    renderLoggedBadges(initialLoggedBadges || {});
    const initialLabelEl = document.getElementById('activeDeviceLabel');
    const initialMetaEl = document.getElementById('activeDeviceMeta');
    updateProjectPunchesSummary(initialLabelEl ? initialLabelEl.textContent : '');
    renderProjectPunches(initialMetaEl ? initialMetaEl.textContent : '');

    fetchActiveDevices();
    fetchDeviceStatus();
    fetchBadgeRatio();
    fetchLoggedBadges(loggedBadgesState.page, loggedBadgesState.pageSize);
  })();
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>


