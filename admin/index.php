<?php

session_start();

if (!empty($_SESSION['user_id'])) {
    $ADMIN_ROOT = __DIR__;
    $HRSMART_ROOT = dirname($ADMIN_ROOT, 2) . '/HRSmart';
    set_include_path($HRSMART_ROOT . PATH_SEPARATOR . get_include_path());
    require $ADMIN_ROOT . '/include/helpers.php';
    include 'include/db_connect.php';
    if (isset($bd) && ($bd instanceof mysqli)) {
        mysqli_set_charset($bd, 'utf8mb4');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $role = (string) ($_SESSION['user_role'] ?? ($_SESSION['usr_type'] ?? ''));
        header('Location: ' . resolve_attendance_landing_url($bd, $userId, $role));
        exit;
    }
    header('Location: Attendance_Dashboard.php');
    exit;
}

header('Location: /HRSmart/index.php');
exit;

?>
