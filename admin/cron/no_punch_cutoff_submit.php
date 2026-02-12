<?php

require __DIR__ . '/../include/helpers.php';

$hrsmartRoot = dirname(__DIR__, 3) . '/HRSmart';
$dbConnectPath = $hrsmartRoot . '/include/db_connect.php';
if (!is_file($dbConnectPath)) {
    echo "Database connection not available.\n";
    exit(1);
}

require $dbConnectPath;
if (!isset($bd) || !($bd instanceof mysqli)) {
    echo "Database connection not available.\n";
    exit(1);
}
mysqli_set_charset($bd, 'utf8mb4');

if (!ensure_no_punch_review_table($bd)) {
    echo "Unable to ensure review table.\n";
    exit(1);
}

$tz = new DateTimeZone('Asia/Dubai');
$now = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');

$cutoffValue = get_api_config($bd, 'no_punch_cutoff_time', '11:00');
$cutoffValue = trim((string) $cutoffValue);
if ($cutoffValue === '') {
    $cutoffValue = '11:00';
}

$cutoffTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $today . ' ' . $cutoffValue, $tz);
if (!$cutoffTime) {
    $cutoffTime = new DateTimeImmutable($today . ' 11:00', $tz);
}

if ($now < $cutoffTime) {
    echo "Cutoff not reached yet.\n";
    exit(0);
}

$timekeeperNote = 'Auto submitted at cutoff';
$timekeeperName = 'Auto Cutoff';
$timekeeperEmail = null;
$submittedAt = gmdate('Y-m-d H:i:s');

$insertSql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reviews` ' .
    '(emp_code, att_date, timekeeper_note, timekeeper_email, timekeeper_name, timekeeper_submitted_at) ' .
    'SELECT hr.emp_code, ?, ?, ?, ?, ? ' .
    'FROM gcc_attendance_master.hrmsvw_sync hr ' .
    'LEFT JOIN gcc_attendance_master.employee_daily_punch dp ON dp.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci ' .
    'AND dp.punch_date = ? ' .
    'LEFT JOIN gcc_attendance_master.employee_att_daily_overrides o ON o.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci ' .
    'AND o.att_date = ? ' .
    'LEFT JOIN gcc_attendance_master.attendance_no_punch_reviews r ON r.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci ' .
    'AND r.att_date = ? ' .
    'WHERE hr.is_deleted = 0 AND hr.st_code = "A" ' .
    'AND (dp.emp_code IS NULL OR (dp.first_log IS NULL AND dp.last_log IS NULL)) ' .
    'AND (o.override_work_hours IS NULL AND o.override_work_code IS NULL) ' .
    'AND r.emp_code IS NULL';

$stmt = $bd->prepare($insertSql);
if (!$stmt) {
    echo "Unable to prepare cutoff insert.\n";
    exit(1);
}

$stmt->bind_param(
    'ssssssss',
    $today,
    $timekeeperNote,
    $timekeeperEmail,
    $timekeeperName,
    $submittedAt,
    $today,
    $today,
    $today
);

if (!$stmt->execute()) {
    echo "Cutoff insert failed.\n";
    $stmt->close();
    exit(1);
}

$count = $stmt->affected_rows;
$stmt->close();

echo "Submitted {$count} no-punch record(s).\n";
