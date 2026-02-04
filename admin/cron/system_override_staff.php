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

$notesEnabled = ensure_override_notes_table($bd);
if (!$notesEnabled) {
    fwrite(STDERR, "Warning: attendance_override_notes table not available; notes will be skipped.\n");
}

$daysBack = 60;
$today = new DateTimeImmutable('today');
$startDate = $today->modify('-' . ($daysBack - 1) . ' days')->format('Y-m-d');
$endDate = $today->format('Y-m-d');

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
    'AND hr.ty_cd IN ("01", "03") ' .
    'AND o.emp_code IS NULL ' .
    'AND (d.work_code IS NULL OR d.work_code = "")';

$stmt = $bd->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "Unable to prepare punch query.\n");
    exit(1);
}
$stmt->bind_param('ss', $startDate, $endDate);
if (!$stmt->execute()) {
    fwrite(STDERR, "Unable to execute punch query.\n");
    exit(1);
}

$result = $stmt->get_result();
if (!$result) {
    fwrite(STDERR, "Unable to fetch punch rows.\n");
    exit(1);
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

$total = 0;
$eligible = 0;
$inserted = 0;
$skippedDuration = 0;
$skippedInvalid = 0;
$notesInserted = 0;

$systemValue = 'SYSTEM';
$overrideHours = '8.00';
$overrideCode = null;
$reasonCode = 'AUTO_8H';
$reasonNote = 'AUTO_8H: STAFF login incomplete; set 8 hours';

while ($row = $result->fetch_assoc()) {
    $total++;
    $empCode = trim((string) ($row['emp_code'] ?? ''));
    $attDate = trim((string) ($row['punch_date'] ?? ''));
    $firstLog = trim((string) ($row['first_log'] ?? ''));
    $lastLog = trim((string) ($row['last_log'] ?? ''));
    $workCode = trim((string) ($row['work_code'] ?? ''));

    if ($empCode === '' || $attDate === '') {
        $skippedInvalid++;
        continue;
    }
    if ($workCode !== '') {
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
        $startTime = parse_log_time($firstLog);
        $endTime = parse_log_time($lastLog);
        if (!$startTime || !$endTime) {
            $skippedInvalid++;
            continue;
        }
        $diff = $endTime->getTimestamp() - $startTime->getTimestamp();
        if ($diff >= 0 && $diff < 28800) {
            $qualifies = true;
        }
    } else {
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
$insertStmt->close();
if ($noteStmt) {
    $noteStmt->close();
}

fwrite(STDOUT, "System override staff job complete.\n");
fwrite(STDOUT, "Range: {$startDate} to {$endDate}\n");
fwrite(STDOUT, "Candidates: {$total}\n");
fwrite(STDOUT, "Eligible: {$eligible}\n");
fwrite(STDOUT, "Inserted: {$inserted}\n");
fwrite(STDOUT, "Skipped (duration >= 1h): {$skippedDuration}\n");
fwrite(STDOUT, "Skipped (invalid): {$skippedInvalid}\n");
if ($notesEnabled) {
    fwrite(STDOUT, "Notes inserted: {$notesInserted}\n");
} else {
    fwrite(STDOUT, "Notes inserted: 0 (notes disabled)\n");
}

?>

