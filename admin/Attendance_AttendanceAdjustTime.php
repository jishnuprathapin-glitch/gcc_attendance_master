
<?php

$previewMode = (($_GET['preview'] ?? '') === '1');

if ($previewMode) {
    header('Content-Type: application/json; charset=utf-8');

    require __DIR__ . '/include/helpers.php';

    $hrsmartRoot = dirname(__DIR__, 2) . '/HRSmart';
    $dbConnectPath = $hrsmartRoot . '/include/db_connect.php';
    if (!is_file($dbConnectPath)) {
        echo json_encode(['ok' => false, 'message' => 'Database connection not available.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    require $dbConnectPath;
    if (!isset($bd) || !($bd instanceof mysqli)) {
        echo json_encode(['ok' => false, 'message' => 'Database connection not available.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    mysqli_set_charset($bd, 'utf8mb4');
    ensure_attendance_override_table($bd);
}

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

function normalize_array($value): array {
    if (is_array($value)) {
        return $value;
    }
    if ($value === null) {
        return [];
    }
    return [$value];
}

function override_debug_log(string $event, array $context = []): void {
    $context['event'] = $event;
    $context['ts'] = gmdate(DATE_ATOM);

    $payload = json_encode($context, JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $payload = json_encode([
            'event' => $event,
            'ts' => gmdate(DATE_ATOM),
            'note' => 'json_encode_failed',
        ], JSON_UNESCAPED_SLASHES);
    }

    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/attendance_override_debug.log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        @error_log($payload . PHP_EOL, 3, $logFile);
        return;
    }
    error_log($payload);
}

function truncate_log_string(?string $value, int $maxLen = 2000): ?string {
    if ($value === null) {
        return null;
    }
    if (strlen($value) <= $maxLen) {
        return $value;
    }
    return substr($value, 0, $maxLen) . '...truncated';
}

function build_form_rows(array $post): array {
    $codes = normalize_array($post['employeeCode'] ?? []);
    $dates = normalize_array($post['attDate'] ?? []);
    $hours = normalize_array($post['workHours'] ?? []);
    $codesWork = normalize_array($post['workCode'] ?? []);
    $reasonCodes = normalize_array($post['reasonCode'] ?? []);
    $reasonNotes = normalize_array($post['reasonNote'] ?? []);

    $max = max(count($codes), count($dates), count($hours), count($codesWork), count($reasonCodes), count($reasonNotes), 1);
    $rows = [];
    for ($i = 0; $i < $max; $i++) {
        $rows[] = [
            'employeeCode' => trim((string) ($codes[$i] ?? '')),
            'attDate' => trim((string) ($dates[$i] ?? '')),
            'workHours' => trim((string) ($hours[$i] ?? '')),
            'workCode' => trim((string) ($codesWork[$i] ?? '')),
            'reasonCode' => trim((string) ($reasonCodes[$i] ?? '')),
            'reasonNote' => trim((string) ($reasonNotes[$i] ?? '')),
        ];
    }

    return $rows;
}

function is_empty_form_row(array $row): bool {
    $employeeCode = trim((string) ($row['employeeCode'] ?? ''));
    $attDate = trim((string) ($row['attDate'] ?? ''));
    $workHours = trim((string) ($row['workHours'] ?? ''));
    $workCode = trim((string) ($row['workCode'] ?? ''));
    $reasonCode = trim((string) ($row['reasonCode'] ?? ''));
    $reasonNote = trim((string) ($row['reasonNote'] ?? ''));

    return $employeeCode === '' &&
        $attDate === '' &&
        $workHours === '' &&
        $workCode === '' &&
        $reasonCode === '' &&
        $reasonNote === '';
}

function filter_empty_form_rows(array $rows): array {
    $filtered = [];
    foreach ($rows as $row) {
        if (!is_empty_form_row($row)) {
            $filtered[] = $row;
        }
    }
    return $filtered;
}

function ensure_override_notes_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`attendance_override_notes` (' .
        '`id` int NOT NULL AUTO_INCREMENT,' .
        '`emp_code` varchar(10) NOT NULL,' .
        '`att_date` date NOT NULL,' .
        '`work_hours` decimal(9,2) NULL,' .
        '`work_code` varchar(10) NULL,' .
        '`reason_code` varchar(50) NULL,' .
        '`reason_note` varchar(255) NULL,' .
        '`changed_by_email` varchar(255) NULL,' .
        '`changed_by_name` varchar(100) NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`id`),' .
        'KEY `idx_emp_date` (`emp_code`, `att_date`),' .
        'KEY `idx_reason_code` (`reason_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    return (bool) $bd->query($sql);
}

function normalize_query_date(?string $value, string $fallback): string {
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

function parse_employee_codes(?string $value): array {
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/[\\s,;]+/', $value);
    if (!$parts) {
        return [];
    }
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $clean[$part] = true;
    }
    return array_keys($clean);
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

if ($previewMode) {

    $codeInput = (string) ($_GET['employee_codes'] ?? '');
    $employeeCodes = parse_employee_codes($codeInput);
    if (empty($employeeCodes)) {
        echo json_encode(['ok' => false, 'message' => 'Enter employee codes to load a preview.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    [$defaultStart, $defaultEnd] = current_week_range();
    $startDate = normalize_query_date($_GET['start_date'] ?? '', $defaultStart);
    $endDate = normalize_query_date($_GET['end_date'] ?? '', $defaultEnd);
    if ($startDate > $endDate) {
        $swap = $startDate;
        $startDate = $endDate;
        $endDate = $swap;
    }

    $dateRange = build_date_range($startDate, $endDate);
    if (empty($dateRange)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid date range.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!isset($bd) || !($bd instanceof mysqli)) {
        echo json_encode(['ok' => false, 'message' => 'Database connection not available.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $maxEmployees = 200;
    if (count($employeeCodes) > $maxEmployees) {
        $employeeCodes = array_slice($employeeCodes, 0, $maxEmployees);
    }

    $employeeMap = [];
    $placeholders = implode(',', array_fill(0, count($employeeCodes), '?'));
    $types = str_repeat('s', count($employeeCodes));

    $empSql = 'SELECT hr.emp_code, ' .
        'COALESCE(NULLIF(hr.emp_name, \'\'), NULLIF(hr.name, \'\')) AS emp_name, ' .
        'hr.jbno AS project_code ' .
        'FROM gcc_attendance_master.hrmsvw_sync hr ' .
        'WHERE hr.emp_code IN (' . $placeholders . ')';
    $stmt = $bd->prepare($empSql);
    if ($stmt) {
        $params = $employeeCodes;
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = trim((string) ($row['emp_code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    $employeeMap[$code] = [
                        'name' => trim((string) ($row['emp_name'] ?? '')),
                        'project_code' => trim((string) ($row['project_code'] ?? '')),
                    ];
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    $punchMap = [];
    $rangeTypes = $types . 'ss';
    $rangeParams = array_merge($employeeCodes, [$startDate, $endDate]);

    $punchSql = 'SELECT emp_code, punch_date, first_log, last_log ' .
        'FROM gcc_attendance_master.employee_daily_punch ' .
        'WHERE emp_code IN (' . $placeholders . ') AND punch_date BETWEEN ? AND ?';
    $stmt = $bd->prepare($punchSql);
    if ($stmt) {
        bind_params($stmt, $rangeTypes, $rangeParams);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = trim((string) ($row['emp_code'] ?? ''));
                    $date = trim((string) ($row['punch_date'] ?? ''));
                    if ($code === '' || $date === '') {
                        continue;
                    }
                    if (!isset($punchMap[$code])) {
                        $punchMap[$code] = [];
                    }
                    $punchMap[$code][$date] = $row;
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    $attMap = [];
    $attSql = 'SELECT d.emp_code, d.att_date, d.work_code, d.pending_leave_code, ' .
        'o.override_work_hours, o.override_work_code ' .
        'FROM gcc_attendance_master.employee_att_daily d ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily_overrides o ' .
        'ON o.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci ' .
        'AND o.att_date = d.att_date ' .
        'WHERE d.emp_code IN (' . $placeholders . ') AND d.att_date BETWEEN ? AND ? ' .
        'AND (d.is_delete = 0 OR d.is_delete IS NULL) AND (d.is_deleted = 0 OR d.is_deleted IS NULL)';
    $stmt = $bd->prepare($attSql);
    if ($stmt) {
        bind_params($stmt, $rangeTypes, $rangeParams);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = trim((string) ($row['emp_code'] ?? ''));
                    $date = trim((string) ($row['att_date'] ?? ''));
                    if ($code === '' || $date === '') {
                        continue;
                    }
                    if (!isset($attMap[$code])) {
                        $attMap[$code] = [];
                    }
                    $attMap[$code][$date] = $row;
                }
                $result->free();
            }
        }
        $stmt->close();
    }

    $rowCount = 0;
    ob_start();
    ?>
    <div class="table-responsive">
      <table class="table table-bordered table-sm preview-table">
        <thead>
          <tr>
            <th>Emp code</th>
            <th>Name</th>
            <th>Project code</th>
            <th>Date</th>
            <th>Work hours</th>
            <th>Work code</th>
            <th>Leave code</th>
            <th>Override hrs</th>
            <th>Override code</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employeeCodes as $code): ?>
            <?php
              $employee = $employeeMap[$code] ?? ['name' => '', 'project_code' => ''];
              $empName = $employee['name'] !== '' ? $employee['name'] : '-';
              $projectCode = $employee['project_code'] !== '' ? $employee['project_code'] : '-';
            ?>
            <?php foreach ($dateRange as $date): ?>
              <?php
                $punch = $punchMap[$code][$date] ?? null;
                $att = $attMap[$code][$date] ?? null;
                $firstLog = is_array($punch) ? ($punch['first_log'] ?? null) : null;
                $lastLog = is_array($punch) ? ($punch['last_log'] ?? null) : null;
                $workHours = calculate_work_hours($firstLog, $lastLog);
                $workCode = is_array($att) ? trim((string) ($att['work_code'] ?? '')) : '';
                $leaveCode = is_array($att) ? trim((string) ($att['pending_leave_code'] ?? '')) : '';
                $overrideHours = is_array($att) ? trim((string) ($att['override_work_hours'] ?? '')) : '';
                $overrideCode = is_array($att) ? trim((string) ($att['override_work_code'] ?? '')) : '';
                $rowCount++;
              ?>
              <tr>
                <td><?= h($code) ?></td>
                <td><?= h($empName) ?></td>
                <td><?= h($projectCode) ?></td>
                <td><?= h($date) ?></td>
                <td><?= h($workHours !== null ? $workHours : '-') ?></td>
                <td><?= h($workCode !== '' ? $workCode : '-') ?></td>
                <td><?= h($leaveCode !== '' ? $leaveCode : '-') ?></td>
                <td><?= h($overrideHours !== '' ? $overrideHours : '-') ?></td>
                <td><?= h($overrideCode !== '' ? $overrideCode : '-') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    $html = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html,
        'meta' => [
            'start' => $startDate,
            'end' => $endDate,
            'rowCount' => $rowCount,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/include/bootstrap.php';

$page_title = 'Adjust Attendance Time';

$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$success = null;
$error = null;
$warning = null;
$rowErrors = [];

$reasonOptions = [
    '' => 'No reason selected',
    'MISSED_PUNCH' => 'Missed punch',
    'DEVICE_ISSUE' => 'Device issue',
    'SHIFT_CHANGE' => 'Shift change',
    'MANAGER_REQUEST' => 'Manager request',
    'APPROVED_LEAVE' => 'Approved leave or permission',
    'DATA_CORRECTION' => 'Data correction',
    'OTHER' => 'Other',
];

$workTypeOptions = [];
if (isset($bd) && $bd instanceof mysqli) {
    $workTypeOptions = load_work_type_options($bd);
}

$formRows = [
    ['employeeCode' => '', 'attDate' => '', 'workHours' => '', 'workCode' => '', 'reasonCode' => '', 'reasonNote' => ''],
    ['employeeCode' => '', 'attDate' => '', 'workHours' => '', 'workCode' => '', 'reasonCode' => '', 'reasonNote' => ''],
    ['employeeCode' => '', 'attDate' => '', 'workHours' => '', 'workCode' => '', 'reasonCode' => '', 'reasonNote' => ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    override_debug_log('submit_start', [
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $formRows = filter_empty_form_rows(build_form_rows($_POST));
    override_debug_log('submit_parsed', [
        'rowCount' => count($formRows),
        'userEmail' => $userEmail,
        'userName' => $userName,
        'hasCsrf' => isset($_POST['csrf']),
    ]);

    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Invalid request token.';
        override_debug_log('submit_error', ['error' => $error]);
    } elseif ($userName === '' || $userEmail === '') {
        $error = 'User name/email missing in session.';
        override_debug_log('submit_error', ['error' => $error]);
    } else {
        $rows = [];
        $noteRows = [];
        $seenKeys = [];
        foreach ($formRows as $index => $input) {
            $rowNumber = $index + 1;
            $employeeCode = trim((string) ($input['employeeCode'] ?? ''));
            $attDate = normalize_post_date($input['attDate'] ?? null);
            $workHoursRaw = trim((string) ($input['workHours'] ?? ''));
            $workCodeRaw = trim((string) ($input['workCode'] ?? ''));
            $reasonCode = trim((string) ($input['reasonCode'] ?? ''));
            $reasonNote = trim((string) ($input['reasonNote'] ?? ''));

            if ($employeeCode === '' && $attDate === null && $workHoursRaw === '' && $workCodeRaw === '' && $reasonCode === '' && $reasonNote === '') {
                continue;
            }

            if ($employeeCode === '') {
                $rowErrors[] = 'Row ' . $rowNumber . ': Employee code is required.';
                continue;
            }
            if ($attDate === null) {
                $rowErrors[] = 'Row ' . $rowNumber . ': Attendance date is required.';
                continue;
            }
            if ($workHoursRaw === '' && $workCodeRaw === '') {
                $rowErrors[] = 'Row ' . $rowNumber . ': Override work hours or override work code is required.';
                continue;
            }

            $dupKey = $employeeCode . '|' . $attDate;
            if (isset($seenKeys[$dupKey])) {
                $rowErrors[] = 'Row ' . $rowNumber . ': Duplicate entry for employee ' . $employeeCode . ' on ' . $attDate . '.';
                continue;
            }
            $seenKeys[$dupKey] = $rowNumber;

            $workHours = null;
            if ($workHoursRaw !== '') {
                if (!is_numeric($workHoursRaw)) {
                    $rowErrors[] = 'Row ' . $rowNumber . ': Work hours must be numeric.';
                    continue;
                }
                $workHours = (float) $workHoursRaw;
                if ($workHours < 0) {
                    $rowErrors[] = 'Row ' . $rowNumber . ': Work hours must be zero or greater.';
                    continue;
                }
                if ($workHours > 24) {
                    $rowErrors[] = 'Row ' . $rowNumber . ': Work hours must be 24 or less.';
                    continue;
                }
            }

            $workCode = normalize_work_type_code($workCodeRaw);
            if ($workCode !== null) {
                if (empty($workTypeOptions)) {
                    $rowErrors[] = 'Row ' . $rowNumber . ': Work code list not available.';
                    continue;
                }
                if (!isset($workTypeOptions[$workCode])) {
                    $rowErrors[] = 'Row ' . $rowNumber . ': Invalid work code "' . $workCode . '". Choose from the list.';
                    continue;
                }
            }

            $rows[] = [
                'employeeCode' => $employeeCode,
                'attDate' => $attDate,
                'workHours' => $workHours,
                'workCode' => $workCode,
                'changeDate' => gmdate(DATE_ATOM),
                'changedByEmail' => $userEmail,
                'changedByName' => $userName,
            ];

            if ($reasonCode !== '' || $reasonNote !== '') {
                $noteRows[] = [
                    'emp_code' => $employeeCode,
                    'att_date' => $attDate,
                    'work_hours' => $workHours !== null ? (string) $workHours : null,
                    'work_code' => $workCode,
                    'reason_code' => $reasonCode !== '' ? $reasonCode : null,
                    'reason_note' => $reasonNote !== '' ? $reasonNote : null,
                ];
            }
        }

        override_debug_log('submit_validated', [
            'validRows' => count($rows),
            'noteRows' => count($noteRows),
            'rowErrors' => $rowErrors,
        ]);

        if ($error === null && empty($rowErrors)) {
            if (empty($rows)) {
                $error = 'Add at least one valid override row.';
                override_debug_log('submit_error', ['error' => $error]);
            } else {
                if (!isset($bd) || !($bd instanceof mysqli)) {
                    $error = 'Database connection not available.';
                    override_debug_log('submit_error', ['error' => $error]);
                } elseif (!ensure_attendance_override_table($bd)) {
                    $error = 'Override table not available.';
                    override_debug_log('submit_error', ['error' => $error]);
                } else {
                    $sql = 'INSERT INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
                        '(emp_code, att_date, override_work_hours, override_work_code, override_change_date, ' .
                        'override_changed_by_email, override_changed_by_name, override_is_approved, ' .
                        'override_approved_by_email, override_approved_by_name, override_approved_date) ' .
                        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
                        'ON DUPLICATE KEY UPDATE ' .
                        'override_work_hours = VALUES(override_work_hours), ' .
                        'override_work_code = VALUES(override_work_code), ' .
                        'override_change_date = VALUES(override_change_date), ' .
                        'override_changed_by_email = VALUES(override_changed_by_email), ' .
                        'override_changed_by_name = VALUES(override_changed_by_name), ' .
                        'override_is_approved = 0, ' .
                        'override_approved_by_email = NULL, ' .
                        'override_approved_by_name = NULL, ' .
                        'override_approved_date = NULL';
                    $stmt = $bd->prepare($sql);
                    if (!$stmt) {
                        $error = 'Override insert failed.';
                        override_debug_log('submit_error', ['error' => $error, 'db_error' => $bd->error]);
                    } else {
                        $applied = 0;
                        $changeDate = gmdate('Y-m-d H:i:s');
                        $isApproved = 0;
                        override_debug_log('submit_db_request', [
                            'rowCount' => count($rows),
                            'sampleRows' => array_slice($rows, 0, 5),
                        ]);
                        foreach ($rows as $row) {
                            $empCode = $row['employeeCode'];
                            $attDate = $row['attDate'];
                            $workHours = $row['workHours'] !== null ? (string) $row['workHours'] : null;
                            $workCode = $row['workCode'];
                            $approvedByEmail = null;
                            $approvedByName = null;
                            $approvedDate = null;
                            $stmt->bind_param(
                                'sssssssisss',
                                $empCode,
                                $attDate,
                                $workHours,
                                $workCode,
                                $changeDate,
                                $userEmail,
                                $userName,
                                $isApproved,
                                $approvedByEmail,
                                $approvedByName,
                                $approvedDate
                            );
                            if (!$stmt->execute()) {
                                $error = 'Override insert failed.';
                                override_debug_log('submit_error', [
                                    'error' => $error,
                                    'db_error' => $stmt->error,
                                    'empCode' => $empCode,
                                    'attDate' => $attDate,
                                ]);
                                break;
                            }
                            $applied++;
                        }
                        $stmt->close();

                        if ($error === null) {
                            override_debug_log('submit_db_response', [
                                'applied' => $applied,
                                'rows' => count($rows),
                            ]);
                            $success = 'Overrides submitted successfully (' . $applied . ' row(s)).';
                            $formRows = [
                                ['employeeCode' => '', 'attDate' => '', 'workHours' => '', 'workCode' => '', 'reasonCode' => '', 'reasonNote' => ''],
                            ];

                            if (!empty($noteRows)) {
                                if (!ensure_override_notes_table($bd)) {
                                    $warning = 'Overrides saved, but notes table could not be created.';
                                } else {
                                    $stmt = $bd->prepare(
                                        'INSERT INTO `gcc_attendance_master`.`attendance_override_notes` ' .
                                        '(emp_code, att_date, work_hours, work_code, reason_code, reason_note, changed_by_email, changed_by_name) ' .
                                        'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                                    );
                                    if (!$stmt) {
                                        $warning = 'Overrides saved, but notes could not be stored.';
                                    } else {
                                        foreach ($noteRows as $note) {
                                            $empCode = $note['emp_code'];
                                            $attDate = $note['att_date'];
                                            $workHours = $note['work_hours'];
                                            $workCode = $note['work_code'];
                                            $reasonCode = $note['reason_code'];
                                            $reasonNote = $note['reason_note'];
                                            $stmt->bind_param(
                                                'ssssssss',
                                                $empCode,
                                                $attDate,
                                                $workHours,
                                                $workCode,
                                                $reasonCode,
                                                $reasonNote,
                                                $userEmail,
                                                $userName
                                            );
                                            if (!$stmt->execute()) {
                                                $warning = 'Overrides saved, but some notes could not be stored.';
                                                break;
                                            }
                                        }
                                        $stmt->close();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

include __DIR__ . '/include/layout_top.php';

?>

<style>
  :root {
    --override-ink: #0f172a;
    --override-muted: #64748b;
    --override-amber: #f59e0b;
    --override-orange: #f97316;
    --override-sky: #0ea5e9;
    --override-card: #ffffff;
    --override-border: rgba(15, 23, 42, 0.12);
    --override-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
  }
  .override-hero {
    background: linear-gradient(120deg, rgba(249, 115, 22, 0.18), rgba(14, 165, 233, 0.18));
    border: 1px solid var(--override-border);
    border-radius: 18px;
    padding: 1.5rem 1.75rem;
    box-shadow: var(--override-shadow);
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
  }
  .override-hero::after {
    content: '';
    position: absolute;
    right: -40px;
    top: -60px;
    width: 180px;
    height: 180px;
    background: radial-gradient(circle, rgba(249, 115, 22, 0.2), transparent 70%);
  }
  .override-hero h2 {
    font-family: "Bebas Neue", "Sora", sans-serif;
    letter-spacing: 0.08em;
    font-size: 2.1rem;
    margin-bottom: 0.25rem;
  }
  .override-hero p {
    max-width: 720px;
    color: var(--override-muted);
    margin-bottom: 0.75rem;
  }
  .override-hero .hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
  }
  .hero-pill {
    padding: 0.35rem 0.85rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.75);
    border: 1px solid rgba(15, 23, 42, 0.1);
    font-weight: 600;
    color: var(--override-ink);
  }
  .override-grid {
    display: grid;
    grid-template-columns: minmax(280px, 360px) minmax(420px, 1fr);
    gap: 1.5rem;
  }
  .override-panel {
    background: var(--override-card);
    border: 1px solid var(--override-border);
    border-radius: 18px;
    padding: 1.25rem;
    box-shadow: var(--override-shadow);
  }
  .override-panel h3 {
    font-weight: 700;
    margin-bottom: 1rem;
  }
  .override-panel .form-group label {
    font-weight: 600;
    color: var(--override-ink);
  }
  .override-panel textarea,
  .override-panel input,
  .override-panel select {
    border-radius: 12px;
  }
  .override-hint {
    font-size: 0.8rem;
    color: var(--override-muted);
    margin-top: 0.35rem;
  }
  .override-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
  }
  .override-actions .btn {
    border-radius: 12px;
    font-weight: 600;
  }
  .override-table-wrap {
    background: var(--override-card);
    border: 1px solid var(--override-border);
    border-radius: 18px;
    box-shadow: var(--override-shadow);
    overflow: hidden;
  }
  .override-table-tools {
    padding: 1rem 1.25rem;
    background: rgba(15, 23, 42, 0.04);
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    justify-content: space-between;
  }
  .override-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }
  .summary-chip {
    background: #fff;
    border: 1px solid rgba(15, 23, 42, 0.12);
    border-radius: 999px;
    padding: 0.3rem 0.75rem;
    font-size: 0.85rem;
    font-weight: 600;
  }
  .override-table {
    margin-bottom: 0;
  }
  .override-table th {
    background: rgba(15, 23, 42, 0.05);
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .override-table td {
    vertical-align: middle;
  }
  .override-table .row-index {
    font-weight: 700;
    color: var(--override-muted);
  }
  .override-table input,
  .override-table select {
    min-width: 120px;
  }
  .override-table .comment-field {
    min-width: 220px;
  }
  .override-table .row-remove {
    border-radius: 10px;
  }
  .override-table .row-duplicate input,
  .override-table .row-duplicate select {
    border-color: #dc2626;
    box-shadow: 0 0 0 1px rgba(220, 38, 38, 0.2);
  }
  .preview-table th {
    background: rgba(15, 23, 42, 0.05);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .preview-table td {
    white-space: nowrap;
  }
  .override-footer {
    padding: 1rem 1.25rem;
    border-top: 1px solid rgba(15, 23, 42, 0.08);
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: space-between;
    align-items: center;
    background: rgba(249, 115, 22, 0.08);
  }
  .override-footer .btn-primary {
    background: linear-gradient(135deg, var(--override-orange), var(--override-amber));
    border: none;
    color: #0f172a;
  }
  .paste-area {
    border: 1px dashed rgba(15, 23, 42, 0.2);
    border-radius: 14px;
    padding: 0.75rem;
    background: rgba(14, 165, 233, 0.05);
  }
  @media (max-width: 1100px) {
    .override-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<section class="content">
  <div class="container-fluid">
    <div class="mb-3">
      <?php include __DIR__ . '/include/admin_nav.php'; ?>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-warning"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($rowErrors)): ?>
      <div class="alert alert-warning">
        <div class="font-weight-bold">Please fix the following rows:</div>
        <ul class="mb-0">
          <?php foreach ($rowErrors as $rowError): ?>
            <li><?= h($rowError) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($warning): ?>
      <div class="alert alert-info"><?= h($warning) ?></div>
    <?php endif; ?>

    <div class="override-hero">
      <h2>Attendance Adjustment Center</h2>
      <p>Batch adjust attendance in minutes. Generate rows by employee list and date range, paste from a spreadsheet, or craft a custom set of overrides before submitting them in one shot.</p>
      <div class="hero-meta">
        <span class="hero-pill">Bulk ready</span>
        <span class="hero-pill">Reason tracking</span>
        <span class="hero-pill">Multi-day edits</span>
      </div>
    </div>

    <form method="post" id="overrideForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="override-grid">
        <div class="override-panel">
          <h3>Batch builder</h3>
          <div class="form-group">
            <label for="employeeBulk">Employee codes</label>
            <textarea id="employeeBulk" class="form-control" rows="4" placeholder="EMP001, EMP002, EMP003"></textarea>
            <div class="override-hint">Paste employee codes separated by comma, space, or new line.</div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="bulkStart">Start date</label>
              <input id="bulkStart" type="date" class="form-control">
            </div>
            <div class="form-group col-md-6">
              <label for="bulkEnd">End date</label>
              <input id="bulkEnd" type="date" class="form-control">
            </div>
          </div>
          <div class="form-group">
            <label>Template override</label>
            <div class="form-row">
              <div class="form-group col-md-6">
                <input id="bulkHours" type="number" step="0.01" min="0" max="24" class="form-control" placeholder="Work hours (e.g. 8.00)">
              </div>
              <div class="form-group col-md-6">
                <input id="bulkWorkCode" class="form-control js-work-code" placeholder="Work code" maxlength="10" autocomplete="off">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <select id="bulkReason" class="form-control">
                  <?php foreach ($reasonOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-6">
                <input id="bulkReasonNote" class="form-control" placeholder="Comment (optional)">
              </div>
            </div>
            <div class="custom-control custom-checkbox">
              <input id="bulkOverwrite" type="checkbox" class="custom-control-input">
              <label class="custom-control-label" for="bulkOverwrite">Overwrite existing row values</label>
            </div>
          </div>
          <div class="override-actions">
            <button type="button" class="btn btn-primary" id="generateRows">Generate rows</button>
            <button type="button" class="btn btn-outline-secondary" id="applyTemplate">Apply reason to all rows</button>
            <button type="button" class="btn btn-outline-danger" id="clearRows">Clear all rows</button>
          </div>
        </div>

        <div class="override-panel">
          <h3>Paste grid</h3>
          <div class="paste-area">
            <label for="pasteRows" class="font-weight-bold">Paste spreadsheet rows</label>
            <textarea id="pasteRows" class="form-control" rows="6" placeholder="EMP001\t2026-01-10\t8.00\tWD\tMISSED_PUNCH\tForgot to punch"></textarea>
            <div class="override-hint">Columns: Employee Code, Date, Work Hours, Work Code, Reason Code, Comment. Use tab or comma separation.</div>
            <button type="button" class="btn btn-outline-primary mt-2" id="importPaste">Import rows</button>
          </div>
          <div class="mt-4">
            <h4 class="mb-2">Reason shortcuts</h4>
            <div class="override-actions">
              <button type="button" class="btn btn-sm btn-outline-secondary reason-chip" data-reason="MISSED_PUNCH">Missed punch</button>
              <button type="button" class="btn btn-sm btn-outline-secondary reason-chip" data-reason="DEVICE_ISSUE">Device issue</button>
              <button type="button" class="btn btn-sm btn-outline-secondary reason-chip" data-reason="SHIFT_CHANGE">Shift change</button>
              <button type="button" class="btn btn-sm btn-outline-secondary reason-chip" data-reason="MANAGER_REQUEST">Manager request</button>
              <button type="button" class="btn btn-sm btn-outline-secondary reason-chip" data-reason="APPROVED_LEAVE">Approved leave</button>
              <button type="button" class="btn btn-sm btn-outline-secondary reason-chip" data-reason="DATA_CORRECTION">Data correction</button>
            </div>
          </div>
        </div>
      </div>

      <div class="override-panel mt-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
          <div>
            <h3 class="mb-1">Attendance preview</h3>
            <div class="override-hint">Shows existing attendance details for the selected employees and date range.</div>
          </div>
          <div class="override-actions">
            <button type="button" class="btn btn-outline-primary" id="loadPreview">Load preview</button>
            <button type="button" class="btn btn-outline-secondary" id="clearPreview">Clear</button>
          </div>
        </div>
        <div id="previewStatus" class="override-hint mt-2">Select employees and a date range to load the preview.</div>
        <div id="previewContainer" class="mt-3"></div>
      </div>

      <div class="override-table-wrap mt-4">
        <div class="override-table-tools">
          <div class="override-summary">
            <span class="summary-chip" id="rowCount">Rows: 0</span>
            <span class="summary-chip" id="empCount">Employees: 0</span>
            <span class="summary-chip" id="dateCount">Dates: 0</span>
          </div>
          <div class="override-actions">
            <button type="button" class="btn btn-outline-primary" id="addRow">Add row</button>
            <button type="button" class="btn btn-outline-secondary" id="compactToggle">Toggle compact</button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm override-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Employee code</th>
                <th>Date</th>
                <th>Work hours</th>
                <th>Work code</th>
                <th>Reason</th>
                <th>Comment</th>
                <th>Remove</th>
              </tr>
            </thead>
            <tbody id="overrideRows">
              <?php foreach ($formRows as $row): ?>
                <tr class="override-row">
                  <td class="row-index">1</td>
                  <td><input name="employeeCode[]" class="form-control form-control-sm" value="<?= h($row['employeeCode'] ?? '') ?>" required></td>
                  <td><input name="attDate[]" type="date" class="form-control form-control-sm" value="<?= h($row['attDate'] ?? '') ?>" required></td>
                  <td><input name="workHours[]" type="number" step="0.01" min="0" max="24" class="form-control form-control-sm" placeholder="8.00" value="<?= h($row['workHours'] ?? '') ?>"></td>
                  <td><input name="workCode[]" class="form-control form-control-sm js-work-code" placeholder="Work code" maxlength="10" autocomplete="off" value="<?= h($row['workCode'] ?? '') ?>"></td>
                  <td>
                    <select name="reasonCode[]" class="form-control form-control-sm">
                      <?php foreach ($reasonOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= ($row['reasonCode'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input name="reasonNote[]" class="form-control form-control-sm comment-field" placeholder="Comment (optional)" value="<?= h($row['reasonNote'] ?? '') ?>"></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger row-remove">Remove</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="override-footer">
          <div class="text-muted small">Overrides are sent via Attendance API. At least work hours or work code is required per row.</div>
          <button type="submit" class="btn btn-primary btn-lg">Submit overrides</button>
        </div>
      </div>
    </form>
  </div>
</section>

<template id="overrideRowTemplate">
  <tr class="override-row">
    <td class="row-index">1</td>
    <td><input name="employeeCode[]" class="form-control form-control-sm" required></td>
    <td><input name="attDate[]" type="date" class="form-control form-control-sm" required></td>
    <td><input name="workHours[]" type="number" step="0.01" min="0" max="24" class="form-control form-control-sm" placeholder="8.00"></td>
    <td><input name="workCode[]" class="form-control form-control-sm js-work-code" placeholder="Work code" maxlength="10" autocomplete="off"></td>
    <td>
      <select name="reasonCode[]" class="form-control form-control-sm">
        <?php foreach ($reasonOptions as $value => $label): ?>
          <option value="<?= h($value) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input name="reasonNote[]" class="form-control form-control-sm comment-field" placeholder="Comment (optional)"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger row-remove">Remove</button></td>
  </tr>
</template>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const rowsBody = document.getElementById('overrideRows');
    const overrideForm = document.getElementById('overrideForm');
    const rowTemplate = document.getElementById('overrideRowTemplate');
    const addRowBtn = document.getElementById('addRow');
    const clearRowsBtn = document.getElementById('clearRows');
    const generateRowsBtn = document.getElementById('generateRows');
    const applyTemplateBtn = document.getElementById('applyTemplate');
    const importPasteBtn = document.getElementById('importPaste');
    const compactToggle = document.getElementById('compactToggle');
    const rowCount = document.getElementById('rowCount');
    const empCount = document.getElementById('empCount');
    const dateCount = document.getElementById('dateCount');
    const loadPreviewBtn = document.getElementById('loadPreview');
    const clearPreviewBtn = document.getElementById('clearPreview');
    const previewStatus = document.getElementById('previewStatus');
    const previewContainer = document.getElementById('previewContainer');

    const bulkEmployeeInput = document.getElementById('employeeBulk');
    const bulkStart = document.getElementById('bulkStart');
    const bulkEnd = document.getElementById('bulkEnd');
    const bulkHours = document.getElementById('bulkHours');
    const bulkWorkCode = document.getElementById('bulkWorkCode');
    const bulkReason = document.getElementById('bulkReason');
    const bulkReasonNote = document.getElementById('bulkReasonNote');
    const bulkOverwrite = document.getElementById('bulkOverwrite');
    const pasteRows = document.getElementById('pasteRows');
    const submitBtn = overrideForm ? overrideForm.querySelector('button[type="submit"]') : null;

    const maxRows = 500;

    function parseEmployeeList(text) {
      return Array.from(new Set(
        text
          .split(/[\s,;]+/)
          .map((item) => item.trim())
          .filter((item) => item.length > 0)
      ));
    }

    function buildDateRange(start, end) {
      if (!start || !end) {
        return [];
      }
      const startParts = start.split('-').map(Number);
      const endParts = end.split('-').map(Number);
      if (startParts.length < 3 || endParts.length < 3) {
        return [];
      }
      const startDate = new Date(Date.UTC(startParts[0], startParts[1] - 1, startParts[2]));
      const endDate = new Date(Date.UTC(endParts[0], endParts[1] - 1, endParts[2]));
      if (isNaN(startDate.getTime()) || isNaN(endDate.getTime()) || startDate > endDate) {
        return [];
      }
      const dates = [];
      const cursor = new Date(startDate);
      while (cursor <= endDate) {
        const y = cursor.getUTCFullYear();
        const m = String(cursor.getUTCMonth() + 1).padStart(2, '0');
        const d = String(cursor.getUTCDate()).padStart(2, '0');
        dates.push(`${y}-${m}-${d}`);
        cursor.setUTCDate(cursor.getUTCDate() + 1);
      }
      return dates;
    }

    function collectRowEmployees() {
      const codes = new Set();
      if (!rowsBody) {
        return [];
      }
      rowsBody.querySelectorAll('input[name="employeeCode[]"]').forEach((input) => {
        const value = input.value.trim();
        if (value !== '') {
          codes.add(value);
        }
      });
      return Array.from(codes);
    }

    function collectRowDateRange() {
      if (!rowsBody) {
        return { start: '', end: '' };
      }
      let minDate = '';
      let maxDate = '';
      rowsBody.querySelectorAll('input[name="attDate[]"]').forEach((input) => {
        const value = input.value.trim();
        if (value === '') {
          return;
        }
        if (minDate === '' || value < minDate) {
          minDate = value;
        }
        if (maxDate === '' || value > maxDate) {
          maxDate = value;
        }
      });
      return { start: minDate, end: maxDate };
    }

    function collectTemplate() {
      return {
        workHours: bulkHours ? bulkHours.value.trim() : '',
        workCode: bulkWorkCode ? bulkWorkCode.value.trim() : '',
        reasonCode: bulkReason ? bulkReason.value : '',
        reasonNote: bulkReasonNote ? bulkReasonNote.value.trim() : '',
        overwrite: bulkOverwrite ? bulkOverwrite.checked : false,
      };
    }

    function isRowEmpty(row) {
      if (!row) {
        return true;
      }
      const values = [
        row.querySelector('input[name="employeeCode[]"]'),
        row.querySelector('input[name="attDate[]"]'),
        row.querySelector('input[name="workHours[]"]'),
        row.querySelector('input[name="workCode[]"]'),
        row.querySelector('select[name="reasonCode[]"]'),
        row.querySelector('input[name="reasonNote[]"]'),
      ].map((input) => {
        if (!input) {
          return '';
        }
        return (input.value || '').trim();
      });
      return values.every((value) => value === '');
    }

    function pruneEmptyRows() {
      if (!rowsBody) {
        return 0;
      }
      let removed = 0;
      rowsBody.querySelectorAll('.override-row').forEach((row) => {
        if (isRowEmpty(row)) {
          row.remove();
          removed++;
        }
      });
      if (removed) {
        updateSummary();
      }
      return removed;
    }

    function applyTemplateToRow(row, template) {
      const hoursInput = row.querySelector('input[name="workHours[]"]');
      const codeInput = row.querySelector('input[name="workCode[]"]');
      const reasonSelect = row.querySelector('select[name="reasonCode[]"]');
      const reasonNoteInput = row.querySelector('input[name="reasonNote[]"]');

      if (template.overwrite || (hoursInput && hoursInput.value.trim() === '')) {
        if (hoursInput) {
          hoursInput.value = template.workHours;
        }
      }
      if (template.overwrite || (codeInput && codeInput.value.trim() === '')) {
        if (codeInput) {
          codeInput.value = template.workCode;
        }
      }
      if (template.overwrite || (reasonSelect && reasonSelect.value === '')) {
        if (reasonSelect) {
          reasonSelect.value = template.reasonCode;
        }
      }
      if (template.overwrite || (reasonNoteInput && reasonNoteInput.value.trim() === '')) {
        if (reasonNoteInput) {
          reasonNoteInput.value = template.reasonNote;
        }
      }
      syncRowValidity(row);
    }

    function syncRowValidity(row) {
      const hoursInput = row.querySelector('input[name="workHours[]"]');
      const codeInput = row.querySelector('input[name="workCode[]"]');
      if (!hoursInput || !codeInput) {
        return;
      }
      const hours = hoursInput.value.trim();
      const code = codeInput.value.trim();
      const message = (hours === '' && code === '') ? 'Work hours or work code is required.' : '';
      hoursInput.setCustomValidity(message);
      codeInput.setCustomValidity(message);
    }

    function validateDuplicateRows() {
      if (!rowsBody) {
        return;
      }
      const rows = Array.from(rowsBody.querySelectorAll('.override-row'));
      const groups = new Map();

      rows.forEach((row) => {
        row.classList.remove('row-duplicate');
        const empInput = row.querySelector('input[name="employeeCode[]"]');
        const dateInput = row.querySelector('input[name="attDate[]"]');
        if (empInput) empInput.setCustomValidity('');
        if (dateInput) dateInput.setCustomValidity('');
      });

      rows.forEach((row) => {
        const empInput = row.querySelector('input[name="employeeCode[]"]');
        const dateInput = row.querySelector('input[name="attDate[]"]');
        if (!empInput || !dateInput) {
          return;
        }
        const emp = empInput.value.trim();
        const date = dateInput.value.trim();
        if (emp === '' || date === '') {
          return;
        }
        const key = `${emp}|${date}`;
        if (!groups.has(key)) {
          groups.set(key, []);
        }
        groups.get(key).push(row);
      });

      groups.forEach((rowsInGroup, key) => {
        if (rowsInGroup.length < 2) {
          return;
        }
        const [emp, date] = key.split('|');
        rowsInGroup.forEach((row) => {
          row.classList.add('row-duplicate');
          const empInput = row.querySelector('input[name="employeeCode[]"]');
          const dateInput = row.querySelector('input[name="attDate[]"]');
          const message = `Duplicate entry for ${emp} on ${date}.`;
          if (empInput) empInput.setCustomValidity(message);
          if (dateInput) dateInput.setCustomValidity(message);
        });
      });
    }

    function updateSummary() {
      const rows = Array.from(rowsBody.querySelectorAll('.override-row'));
      rowCount.textContent = `Rows: ${rows.length}`;
      const empSet = new Set();
      const dateSet = new Set();
      rows.forEach((row) => {
        const emp = row.querySelector('input[name="employeeCode[]"]');
        const date = row.querySelector('input[name="attDate[]"]');
        if (emp && emp.value.trim() !== '') {
          empSet.add(emp.value.trim());
        }
        if (date && date.value.trim() !== '') {
          dateSet.add(date.value.trim());
        }
      });
      empCount.textContent = `Employees: ${empSet.size}`;
      dateCount.textContent = `Dates: ${dateSet.size}`;

      rows.forEach((row, idx) => {
        const indexCell = row.querySelector('.row-index');
        if (indexCell) {
          indexCell.textContent = String(idx + 1);
        }
      });
      validateDuplicateRows();
    }

    function setupRow(row) {
      if (!row) {
        return;
      }
      const removeBtn = row.querySelector('.row-remove');
      if (removeBtn) {
        removeBtn.addEventListener('click', () => {
          row.remove();
          updateSummary();
        });
      }
      const hoursInput = row.querySelector('input[name="workHours[]"]');
      const codeInput = row.querySelector('input[name="workCode[]"]');
      if (hoursInput) {
        hoursInput.addEventListener('input', () => syncRowValidity(row));
      }
      if (codeInput) {
        codeInput.addEventListener('input', () => syncRowValidity(row));
      }
      row.querySelectorAll('input, select').forEach((input) => {
        input.addEventListener('change', updateSummary);
      });
      syncRowValidity(row);
    }

    function addRow(values = {}) {
      if (!rowTemplate) {
        return null;
      }
      const fragment = rowTemplate.content.cloneNode(true);
      const row = fragment.querySelector('.override-row');
      if (!row) {
        return null;
      }
      const empInput = row.querySelector('input[name="employeeCode[]"]');
      const dateInput = row.querySelector('input[name="attDate[]"]');
      const hoursInput = row.querySelector('input[name="workHours[]"]');
      const codeInput = row.querySelector('input[name="workCode[]"]');
      const reasonSelect = row.querySelector('select[name="reasonCode[]"]');
      const reasonNoteInput = row.querySelector('input[name="reasonNote[]"]');

      if (empInput && values.employeeCode) empInput.value = values.employeeCode;
      if (dateInput && values.attDate) dateInput.value = values.attDate;
      if (hoursInput && values.workHours) hoursInput.value = values.workHours;
      if (codeInput && values.workCode) codeInput.value = values.workCode;
      if (reasonSelect && values.reasonCode !== undefined) reasonSelect.value = values.reasonCode;
      if (reasonNoteInput && values.reasonNote) reasonNoteInput.value = values.reasonNote;

      rowsBody.appendChild(row);
      setupRow(row);
      updateSummary();
      return row;
    }

    function addRowsFromPaste(lines) {
      const added = [];
      lines.forEach((line) => {
        if (!line.trim()) {
          return;
        }
        const parts = line.split(/\t|,/).map((value) => value.trim());
        const values = {
          employeeCode: parts[0] || '',
          attDate: parts[1] || '',
          workHours: parts[2] || '',
          workCode: parts[3] || '',
          reasonCode: parts[4] || '',
          reasonNote: parts[5] || '',
        };
        added.push(values);
      });
      if (!added.length) {
        return;
      }
      if (rowsBody.querySelectorAll('.override-row').length + added.length > maxRows) {
        alert('Too many rows. Please reduce the batch size (max ' + maxRows + ').');
        return;
      }
      added.forEach((values) => addRow(values));
    }

    document.querySelectorAll('.override-row').forEach(setupRow);
    updateSummary();

    if (addRowBtn) {
      addRowBtn.addEventListener('click', () => addRow());
    }

    if (clearRowsBtn) {
      clearRowsBtn.addEventListener('click', () => {
        rowsBody.innerHTML = '';
        updateSummary();
      });
    }

    if (generateRowsBtn) {
      generateRowsBtn.addEventListener('click', () => {
        const employees = parseEmployeeList(bulkEmployeeInput ? bulkEmployeeInput.value : '');
        const dates = buildDateRange(bulkStart ? bulkStart.value : '', bulkEnd ? bulkEnd.value : '');
        if (!employees.length || !dates.length) {
          alert('Please provide employee codes and a valid date range.');
          return;
        }
        const total = employees.length * dates.length;
        if (total > maxRows) {
          alert('Too many rows (' + total + '). Please reduce the date range or employee list (max ' + maxRows + ').');
          return;
        }
        pruneEmptyRows();
        const template = collectTemplate();
        employees.forEach((emp) => {
          dates.forEach((date) => {
            addRow({
              employeeCode: emp,
              attDate: date,
              workHours: template.workHours,
              workCode: template.workCode,
              reasonCode: template.reasonCode,
              reasonNote: template.reasonNote,
            });
          });
        });
      });
    }

    if (applyTemplateBtn) {
      applyTemplateBtn.addEventListener('click', () => {
        const template = collectTemplate();
        rowsBody.querySelectorAll('.override-row').forEach((row) => {
          applyTemplateToRow(row, template);
        });
      });
    }

    if (importPasteBtn) {
      importPasteBtn.addEventListener('click', () => {
        const text = pasteRows ? pasteRows.value : '';
        if (!text.trim()) {
          alert('Paste some rows first.');
          return;
        }
        pruneEmptyRows();
        addRowsFromPaste(text.split(/\r?\n/));
        if (pasteRows) {
          pasteRows.value = '';
        }
      });
    }

    document.querySelectorAll('.reason-chip').forEach((chip) => {
      chip.addEventListener('click', () => {
        const reason = chip.getAttribute('data-reason') || '';
        rowsBody.querySelectorAll('select[name="reasonCode[]"]').forEach((select) => {
          select.value = reason;
        });
        updateSummary();
      });
    });

    if (loadPreviewBtn) {
      loadPreviewBtn.addEventListener('click', () => {
        let employees = parseEmployeeList(bulkEmployeeInput ? bulkEmployeeInput.value : '');
        let startDate = bulkStart ? bulkStart.value : '';
        let endDate = bulkEnd ? bulkEnd.value : '';

        if (!employees.length) {
          employees = collectRowEmployees();
        }
        if (!startDate || !endDate) {
          const range = collectRowDateRange();
          if (!startDate) {
            startDate = range.start;
          }
          if (!endDate) {
            endDate = range.end;
          }
        }

        if (!employees.length) {
          if (previewStatus) {
            previewStatus.textContent = 'Enter employee codes or fill at least one row to preview.';
          }
          return;
        }
        if (!startDate || !endDate) {
          if (previewStatus) {
            previewStatus.textContent = 'Select a start and end date to load the preview.';
          }
          return;
        }

        const params = new URLSearchParams({
          preview: '1',
          employee_codes: employees.join(','),
          start_date: startDate,
          end_date: endDate,
        });

        if (previewStatus) {
          previewStatus.textContent = 'Loading preview...';
        }
        if (previewContainer) {
          previewContainer.innerHTML = '';
        }

        fetch(`${window.location.pathname}?${params.toString()}`, { credentials: 'same-origin' })
          .then((response) => response.json())
          .then((data) => {
            if (!data || !data.ok) {
              if (previewStatus) {
                previewStatus.textContent = data && data.message ? data.message : 'Unable to load preview.';
              }
              return;
            }
            if (previewContainer) {
              previewContainer.innerHTML = data.html || '';
            }
            if (previewStatus) {
              const meta = data.meta || {};
              previewStatus.textContent = `Showing ${meta.rowCount || 0} rows for ${meta.start || startDate} to ${meta.end || endDate}.`;
            }
          })
          .catch(() => {
            if (previewStatus) {
              previewStatus.textContent = 'Unable to load preview.';
            }
          });
      });
    }

    if (clearPreviewBtn) {
      clearPreviewBtn.addEventListener('click', () => {
        if (previewContainer) {
          previewContainer.innerHTML = '';
        }
        if (previewStatus) {
          previewStatus.textContent = 'Select employees and a date range to load the preview.';
        }
      });
    }

    if (compactToggle) {
      compactToggle.addEventListener('click', () => {
        document.querySelector('.override-table').classList.toggle('table-sm');
      });
    }

    if (submitBtn) {
      submitBtn.addEventListener('click', () => {
        pruneEmptyRows();
      });
    }
  });
</script>

<script>
  window.WORK_CODE_OPTIONS = <?= json_encode($workTypeOptions, JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
