<?php

session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: Attendance_Dashboard.php');
    exit;
}

header('Location: /HRSmart/index.php');
exit;

?>
