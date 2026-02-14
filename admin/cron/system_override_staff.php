<?php

declare(strict_types=1);

$adminRoot = dirname(__DIR__);
$hrsmartRoot = dirname($adminRoot, 2) . '/HRSmart';

require $adminRoot . '/include/helpers.php';

$dbConnectPath = $hrsmartRoot . '/include/db_connect.php';
if (!is_file($dbConnectPath)) {
    fwrite(STDERR, "Database connection not available.\n");
    exit(1);
}
require $dbConnectPath;
if (!isset($bd) || !($bd instanceof mysqli)) {
    fwrite(STDERR, "Database connection not available.\n");
    exit(1);
}
mysqli_set_charset($bd, 'utf8mb4');

if (!mysqli_select_db($bd, 'gcc_attendance_master')) {
    fwrite(STDERR, "Unable to select gcc_attendance_master database.\n");
    exit(1);
}

if (!ensure_attendance_override_table($bd)) {
    fwrite(STDERR, "Override table not available.\n");
    exit(1);
}

function ensure_employee_att_daily_index(mysqli $bd): bool {
    $res = $bd->query(
        "SHOW INDEX FROM `gcc_attendance_master`.`employee_att_daily` WHERE Key_name = 'idx_emp_att_date'"
    );
    if ($res) {
        if ($res->num_rows > 0) {
            $res->free();
            return true;
        }
        $res->free();
    }
    // Speeds up joins/filters on (emp_code, att_date) used by system override queries.
    return (bool) $bd->query(
        'CREATE INDEX `idx_emp_att_date` ON `gcc_attendance_master`.`employee_att_daily` (`emp_code`, `att_date`)'
    );
}

if (!ensure_employee_att_daily_index($bd)) {
    fwrite(STDERR, "Warning: unable to create employee_att_daily index; system overrides may be slow.\n");
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

function is_missing_log(?string $value): bool {
    $value = trim((string) $value);
    return $value === '' || $value === '0000-00-00 00:00:00';
}

function parse_log_time(?string $value): ?DateTimeImmutable {
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
}

function parse_att_date(?string $value): ?DateTimeImmutable {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return null;
    }
    return $dt;
}

function is_sunday_date(?string $attDate): bool {
    static $cache = [];

    $key = trim((string) $attDate);
    if ($key === '') {
        return false;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $dt = parse_att_date($key);
    $isSunday = $dt ? ($dt->format('w') === '0') : false;
    $cache[$key] = $isSunday;
    return $isSunday;
}

function normalize_work_code(?string $value): string {
    return strtoupper(trim((string) $value));
}

function has_work_code(?string $value): bool {
    return normalize_work_code($value) !== '';
}

function is_public_holiday(?string $value): bool {
    return normalize_work_code($value) === 'PHL';
}

function is_wcxh(?string $value): bool {
    // "WCXH" in the spec represents work codes that should carry into Sunday when both sides match.
    // Current default: any non-empty code qualifies.
    return has_work_code($value);
}

$notesEnabled = ensure_override_notes_table($bd);
if (!$notesEnabled) {
    fwrite(STDERR, "Warning: attendance_override_notes table not available; notes will be skipped.\n");
}

$daysBack = 60;
$today = new DateTimeImmutable('today');
$startDate = $today->modify('-' . ($daysBack - 1) . ' days')->format('Y-m-d');
$endDate = $today->format('Y-m-d');

$onlyRule = null;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--only=') === 0) {
            $onlyRule = trim((string) substr($arg, 7));
        }
    }
}

$insertSql = 'INSERT IGNORE INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
    '(emp_code, att_date, override_work_hours, override_work_code, override_change_date, ' .
    'override_changed_by_email, override_changed_by_name, override_is_approved, ' .
    'override_approved_by_email, override_approved_by_name, override_approved_date) ' .
    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

$insertStmt = $bd->prepare($insertSql);
if (!$insertStmt) {
    fwrite(STDERR, "Unable to prepare override insert.\n");
    exit(1);
}

$noteStmt = null;
if ($notesEnabled) {
    $noteSql = 'INSERT INTO `gcc_attendance_master`.`attendance_override_notes` ' .
        '(emp_code, att_date, work_hours, work_code, reason_code, reason_note, changed_by_email, changed_by_name) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $noteStmt = $bd->prepare($noteSql);
    if (!$noteStmt) {
        $notesEnabled = false;
        fwrite(STDERR, "Warning: unable to prepare note insert; notes will be skipped.\n");
    }
}

function run_override_rule(
    mysqli $bd,
    mysqli_stmt $insertStmt,
    ?mysqli_stmt $noteStmt,
    bool $notesEnabled,
    string $startDate,
    string $endDate,
    array $tyCodes,
    string $overrideHours,
    string $reasonCode,
    string $reasonNote,
    ?int $minSeconds,
    ?int $maxSeconds,
    bool $requireBoth,
    bool $allowSingleOnly
): array {
    $systemValue = 'SYSTEM';
    $overrideCode = null;

    if (empty($tyCodes)) {
        return ['error' => 'No employee types provided.'];
    }
    $placeholders = implode(',', array_fill(0, count($tyCodes), '?'));
    $sql = 'SELECT dp.emp_code, dp.punch_date, dp.first_log, dp.last_log, d.work_code ' .
        'FROM gcc_attendance_master.employee_daily_punch dp ' .
        'INNER JOIN gcc_attendance_master.hrmsvw_sync hr ' .
        'ON hr.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily_overrides o ' .
        'ON o.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci ' .
        'AND o.att_date = dp.punch_date ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily d ' .
        'ON d.emp_code COLLATE utf8mb4_general_ci = dp.emp_code COLLATE utf8mb4_general_ci ' .
        'AND d.att_date = dp.punch_date ' .
        'WHERE dp.punch_date BETWEEN ? AND ? ' .
        'AND hr.ty_cd IN (' . $placeholders . ') ' .
        'AND o.emp_code IS NULL ' .
        'AND (d.work_code IS NULL OR TRIM(d.work_code) = "" OR UPPER(TRIM(d.work_code)) = "SUB")';

    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return ['error' => 'Unable to prepare punch query.'];
    }
    $types = 'ss' . str_repeat('s', count($tyCodes));
    $params = array_merge([$startDate, $endDate], $tyCodes);
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['error' => 'Unable to execute punch query.'];
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return ['error' => 'Unable to fetch punch rows.'];
    }

    $total = 0;
    $eligible = 0;
    $inserted = 0;
    $skippedDuration = 0;
    $skippedInvalid = 0;
    $notesInserted = 0;

    while ($row = $result->fetch_assoc()) {
        $total++;
        $empCode = trim((string) ($row['emp_code'] ?? ''));
        $attDate = trim((string) ($row['punch_date'] ?? ''));
        $firstLog = trim((string) ($row['first_log'] ?? ''));
        $lastLog = trim((string) ($row['last_log'] ?? ''));
        $workCode = strtoupper(trim((string) ($row['work_code'] ?? '')));

        if ($empCode === '' || $attDate === '') {
            $skippedInvalid++;
            continue;
        }
        // Treat SUB as eligible (same as empty) for system auto-overrides.
        if ($workCode !== '' && $workCode !== 'SUB') {
            $skippedInvalid++;
            continue;
        }

        $qualifies = false;
        $hasFirst = !is_missing_log($firstLog);
        $hasLast = !is_missing_log($lastLog);
        if (!$hasFirst && !$hasLast) {
            $skippedInvalid++;
            continue;
        }
        if ($hasFirst && $hasLast) {
            if ($allowSingleOnly) {
                $skippedDuration++;
                continue;
            }
            if ($minSeconds === null && $maxSeconds === null && !$requireBoth) {
                $qualifies = true;
            } else {
            $startTime = parse_log_time($firstLog);
            $endTime = parse_log_time($lastLog);
            if (!$startTime || !$endTime) {
                $skippedInvalid++;
                continue;
            }
            $diff = $endTime->getTimestamp() - $startTime->getTimestamp();
            if ($diff >= 0) {
                $minOk = $minSeconds === null || $diff >= $minSeconds;
                $maxOk = $maxSeconds === null || $diff < $maxSeconds;
                if ($minOk && $maxOk) {
                    $qualifies = true;
                }
            }
            }
        } else {
            if ($requireBoth) {
                $skippedDuration++;
                continue;
            }
            $qualifies = true;
        }

        if (!$qualifies) {
            $skippedDuration++;
            continue;
        }

        $eligible++;
        $changeDate = gmdate('Y-m-d H:i:s');
        $approvedDate = $changeDate;
        $isApproved = 1;

        $insertStmt->bind_param(
            'sssssssisss',
            $empCode,
            $attDate,
            $overrideHours,
            $overrideCode,
            $changeDate,
            $systemValue,
            $systemValue,
            $isApproved,
            $systemValue,
            $systemValue,
            $approvedDate
        );

        if (!$insertStmt->execute()) {
            fwrite(STDERR, "Override insert failed for {$empCode} {$attDate}: {$insertStmt->error}\n");
            continue;
        }

        if ($insertStmt->affected_rows < 1) {
            continue;
        }

        $inserted++;

        if ($notesEnabled && $noteStmt) {
            $noteStmt->bind_param(
                'ssssssss',
                $empCode,
                $attDate,
                $overrideHours,
                $overrideCode,
                $reasonCode,
                $reasonNote,
                $systemValue,
                $systemValue
            );
            if ($noteStmt->execute()) {
                $notesInserted++;
            }
        }
    }

    $result->free();
    $stmt->close();

    return [
        'total' => $total,
        'eligible' => $eligible,
        'inserted' => $inserted,
        'skippedDuration' => $skippedDuration,
        'skippedInvalid' => $skippedInvalid,
        'notesInserted' => $notesInserted,
    ];
}

function run_sunday_work_code_rule(
    mysqli $bd,
    mysqli_stmt $insertStmt,
    ?mysqli_stmt $noteStmt,
    bool $notesEnabled,
    string $startDate,
    string $endDate
): array {
    $start = parse_att_date($startDate);
    $end = parse_att_date($endDate);
    if (!$start || !$end) {
        return ['error' => 'Invalid date range provided.'];
    }

    // Pull a little extra context so "nearest previous/next" can cross the window edge.
    $extStart = $start->modify('-14 days')->format('Y-m-d');
    $extEnd = $end->modify('+14 days')->format('Y-m-d');

    // Preload existing overrides (core range only) so we never overwrite a manual/system override row.
    $existingOverrides = [];
    $ovStmt = $bd->prepare(
        'SELECT emp_code, att_date FROM gcc_attendance_master.employee_att_daily_overrides WHERE att_date BETWEEN ? AND ?'
    );
    if ($ovStmt) {
        $ovStmt->bind_param('ss', $startDate, $endDate);
        if ($ovStmt->execute()) {
            $ovRes = $ovStmt->get_result();
            if ($ovRes) {
                while ($row = $ovRes->fetch_assoc()) {
                    $emp = trim((string) ($row['emp_code'] ?? ''));
                    $date = trim((string) ($row['att_date'] ?? ''));
                    if ($emp !== '' && $date !== '') {
                        $existingOverrides[$emp . '|' . $date] = true;
                    }
                }
                $ovRes->free();
            }
        }
        $ovStmt->close();
    }

    $sql = 'SELECT emp_code, att_date, work_code ' .
        'FROM gcc_attendance_master.employee_att_daily ' .
        'WHERE att_date BETWEEN ? AND ? ' .
        'ORDER BY emp_code, att_date';

    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return ['error' => 'Unable to prepare attendance query.'];
    }
    $stmt->bind_param('ss', $extStart, $extEnd);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['error' => 'Unable to execute attendance query.'];
    }

    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return ['error' => 'Unable to fetch attendance rows.'];
    }

    $totalSundays = 0;
    $eligible = 0;
    $inserted = 0;
    $skippedHasCode = 0;
    $skippedOverrideExists = 0;
    $notesInserted = 0;

    $currentEmp = null;
    $days = [];

    $processEmployee = static function (
        ?string $empCode,
        array $days,
        array $existingOverrides,
        mysqli_stmt $insertStmt,
        ?mysqli_stmt $noteStmt,
        bool $notesEnabled,
        string $startDate,
        string $endDate,
        int &$totalSundays,
        int &$eligible,
        int &$inserted,
        int &$skippedHasCode,
        int &$skippedOverrideExists,
        int &$notesInserted
    ): void {
        if ($empCode === null || $empCode === '') {
            return;
        }
        if (empty($days)) {
            return;
        }

        $systemEmail = 'SYSTEM';
        $systemName = 'SYSTEM';

        for ($i = 0, $n = count($days); $i < $n; $i++) {
            $attDate = $days[$i]['att_date'] ?? '';
            $workCode = $days[$i]['work_code'] ?? null;

            if (!is_sunday_date($attDate)) {
                continue;
            }
            if ($attDate < $startDate || $attDate > $endDate) {
                continue;
            }

            $totalSundays++;

            // If a Sunday already has a work code, do nothing.
            if (has_work_code($workCode)) {
                $skippedHasCode++;
                continue;
            }

            // Never overwrite an existing override row.
            if (isset($existingOverrides[$empCode . '|' . $attDate])) {
                $skippedOverrideExists++;
                continue;
            }

            $prevCode = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                $candidateDate = $days[$j]['att_date'] ?? '';
                if (is_sunday_date($candidateDate)) {
                    continue;
                }
                $candidateCode = $days[$j]['work_code'] ?? null;
                if (is_public_holiday($candidateCode)) {
                    continue;
                }
                $prevCode = $candidateCode;
                break;
            }

            $nextCode = null;
            for ($k = $i + 1; $k < $n; $k++) {
                $candidateDate = $days[$k]['att_date'] ?? '';
                if (is_sunday_date($candidateDate)) {
                    continue;
                }
                $candidateCode = $days[$k]['work_code'] ?? null;
                if (is_public_holiday($candidateCode)) {
                    continue;
                }
                $nextCode = $candidateCode;
                break;
            }

            $prevNorm = normalize_work_code($prevCode);
            $nextNorm = normalize_work_code($nextCode);

            $newCode = 'HOL';
            if (is_wcxh($prevNorm) && $prevNorm !== '' && $prevNorm === $nextNorm) {
                $newCode = $prevNorm;
            }

            $eligible++;
            $changeDate = gmdate('Y-m-d H:i:s');
            $approvedDate = $changeDate;
            $isApproved = 1;
            $overrideHours = null;
            $overrideCode = $newCode;

            $changedByEmail = $systemEmail;
            $changedByName = $systemName;
            $approvedByEmail = $systemEmail;
            $approvedByName = $systemName;

            $insertStmt->bind_param(
                'sssssssisss',
                $empCode,
                $attDate,
                $overrideHours,
                $overrideCode,
                $changeDate,
                $changedByEmail,
                $changedByName,
                $isApproved,
                $approvedByEmail,
                $approvedByName,
                $approvedDate
            );

            if (!$insertStmt->execute()) {
                fwrite(STDERR, "Sunday work-code override insert failed for {$empCode} {$attDate}: {$insertStmt->error}\n");
                continue;
            }
            if ($insertStmt->affected_rows < 1) {
                continue;
            }

            $inserted++;

            if ($notesEnabled && $noteStmt) {
                $reasonCode = 'AUTO_SUN_WORK_CODE';
                $reasonNote = "AUTO_SUN_WORK_CODE: Sunday empty; prev={$prevNorm}; next={$nextNorm}; set={$newCode}";
                $noteStmt->bind_param(
                    'ssssssss',
                    $empCode,
                    $attDate,
                    $overrideHours,
                    $overrideCode,
                    $reasonCode,
                    $reasonNote,
                    $systemEmail,
                    $systemName
                );
                if ($noteStmt->execute()) {
                    $notesInserted++;
                }
            }
        }
    };

    while ($row = $result->fetch_assoc()) {
        $emp = trim((string) ($row['emp_code'] ?? ''));
        $date = trim((string) ($row['att_date'] ?? ''));
        if ($emp === '' || $date === '') {
            continue;
        }

        if ($currentEmp !== null && $emp !== $currentEmp) {
            $processEmployee(
                $currentEmp,
                $days,
                $existingOverrides,
                $insertStmt,
                $noteStmt,
                $notesEnabled,
                $startDate,
                $endDate,
                $totalSundays,
                $eligible,
                $inserted,
                $skippedHasCode,
                $skippedOverrideExists,
                $notesInserted
            );
            $days = [];
        }

        $currentEmp = $emp;
        $days[] = [
            'att_date' => $date,
            'work_code' => $row['work_code'] ?? null,
        ];
    }

    if ($currentEmp !== null) {
        $processEmployee(
            $currentEmp,
            $days,
            $existingOverrides,
            $insertStmt,
            $noteStmt,
            $notesEnabled,
            $startDate,
            $endDate,
            $totalSundays,
            $eligible,
            $inserted,
            $skippedHasCode,
            $skippedOverrideExists,
            $notesInserted
        );
    }

    $result->free();
    $stmt->close();

    return [
        'sundays' => $totalSundays,
        'eligible' => $eligible,
        'inserted' => $inserted,
        'skippedHasCode' => $skippedHasCode,
        'skippedOverrideExists' => $skippedOverrideExists,
        'notesInserted' => $notesInserted,
    ];
}

$staff = ['total' => 0, 'eligible' => 0, 'inserted' => 0, 'skippedDuration' => 0, 'skippedInvalid' => 0, 'notesInserted' => 0];
$nonStaff = ['total' => 0, 'eligible' => 0, 'inserted' => 0, 'skippedDuration' => 0, 'skippedInvalid' => 0, 'notesInserted' => 0];
$nonStaffOt = ['total' => 0, 'eligible' => 0, 'inserted' => 0, 'skippedDuration' => 0, 'skippedInvalid' => 0, 'notesInserted' => 0];
$sundayWorkCode = ['sundays' => 0, 'eligible' => 0, 'inserted' => 0, 'skippedHasCode' => 0, 'skippedOverrideExists' => 0, 'notesInserted' => 0];

if ($onlyRule !== null && !in_array($onlyRule, ['hours', 'sunday_work_code'], true)) {
    fwrite(STDERR, "Warning: unknown --only value '{$onlyRule}'. Expected 'hours' or 'sunday_work_code'. Running all rules.\n");
    $onlyRule = null;
}

if ($onlyRule === null || $onlyRule === 'hours') {
    $staff = run_override_rule(
        $bd,
        $insertStmt,
        $noteStmt,
        $notesEnabled,
        $startDate,
        $endDate,
        ['01'],
        '8.00',
        'AUTO_8H_STAFF',
        'AUTO_8H_STAFF: STAFF has at least one punch; set 8 hours',
        null,
        null,
        false,
        false
    );

    $nonStaff = run_override_rule(
        $bd,
        $insertStmt,
        $noteStmt,
        $notesEnabled,
        $startDate,
        $endDate,
        ['02'],
        '10.00',
        'AUTO_10H_NON_STAFF',
        'AUTO_10H_NON_STAFF: NON STAFF login incomplete; set 10 hours',
        null,
        null,
        false,
        true
    );

    $nonStaffOt = run_override_rule(
        $bd,
        $insertStmt,
        $noteStmt,
        $notesEnabled,
        $startDate,
        $endDate,
        ['02', '03'],
        '10.00',
        'OT_ELG_EMPLOYEE_9_12',
        'OT_ELG_EMPLOYEE_9_12: duration 9-12h; set 10 hours',
        32400,
        43200,
        true,
        false
    );
}

if ($onlyRule === null || $onlyRule === 'sunday_work_code') {
    $sundayWorkCode = run_sunday_work_code_rule(
        $bd,
        $insertStmt,
        $noteStmt,
        $notesEnabled,
        $startDate,
        $endDate
    );
}

$insertStmt->close();
if ($noteStmt) {
    $noteStmt->close();
}

fwrite(STDOUT, "System override job complete.\n");
fwrite(STDOUT, "Range: {$startDate} to {$endDate}\n");
if (isset($staff['error'])) {
    fwrite(STDOUT, "STAFF (01): {$staff['error']}\n");
} else {
    fwrite(STDOUT, "STAFF (01) - Candidates: {$staff['total']}, Eligible: {$staff['eligible']}, Inserted: {$staff['inserted']}, Skipped (duration): {$staff['skippedDuration']}, Skipped (invalid): {$staff['skippedInvalid']}\n");
}
if (isset($nonStaff['error'])) {
    fwrite(STDOUT, "NON_STAFF (02): {$nonStaff['error']}\n");
} else {
    fwrite(STDOUT, "NON_STAFF (02) - Candidates: {$nonStaff['total']}, Eligible: {$nonStaff['eligible']}, Inserted: {$nonStaff['inserted']}, Skipped (duration): {$nonStaff['skippedDuration']}, Skipped (invalid): {$nonStaff['skippedInvalid']}\n");
}
if (isset($nonStaffOt['error'])) {
    fwrite(STDOUT, "OT_ELG_EMPLOYEE_9_12 (02,03): {$nonStaffOt['error']}\n");
} else {
    fwrite(STDOUT, "OT_ELG_EMPLOYEE_9_12 (02,03) - Candidates: {$nonStaffOt['total']}, Eligible: {$nonStaffOt['eligible']}, Inserted: {$nonStaffOt['inserted']}, Skipped (duration): {$nonStaffOt['skippedDuration']}, Skipped (invalid): {$nonStaffOt['skippedInvalid']}\n");
}
if (isset($sundayWorkCode['error'])) {
    fwrite(STDOUT, "SUNDAY_WORK_CODE: {$sundayWorkCode['error']}\n");
} else {
    fwrite(
        STDOUT,
        "SUNDAY_WORK_CODE - Sundays: {$sundayWorkCode['sundays']}, Eligible: {$sundayWorkCode['eligible']}, Inserted: {$sundayWorkCode['inserted']}, Skipped (has code): {$sundayWorkCode['skippedHasCode']}, Skipped (override exists): {$sundayWorkCode['skippedOverrideExists']}\n"
    );
}
if ($notesEnabled) {
    $notesTotal = ($staff['notesInserted'] ?? 0) + ($nonStaff['notesInserted'] ?? 0) + ($nonStaffOt['notesInserted'] ?? 0) + ($sundayWorkCode['notesInserted'] ?? 0);
    fwrite(STDOUT, "Notes inserted: {$notesTotal}\n");
} else {
    fwrite(STDOUT, "Notes inserted: 0 (notes disabled)\n");
}

?>

