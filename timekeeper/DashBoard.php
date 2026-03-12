<?php

declare(strict_types=1);

$target = '/gcc_attendance_master/timekeeper/timekeeper_dashboard.php';
$query = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;

