<?php

declare(strict_types=1);

$ADMIN_ROOT = dirname(__DIR__);
$HRSMART_ROOT = dirname($ADMIN_ROOT, 2) . '/HRSmart';

define('ADMIN_ROOT', $ADMIN_ROOT);
define('HRSMART_ROOT', $HRSMART_ROOT);

set_include_path(HRSMART_ROOT . PATH_SEPARATOR . get_include_path());

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require ADMIN_ROOT . '/include/helpers.php';

if (empty($_SESSION['usr_type']) && !empty($_SESSION['user_role'])) {
    $_SESSION['usr_type'] = $_SESSION['user_role'];
}
if (empty($_SESSION['user_role']) && !empty($_SESSION['usr_type'])) {
    $_SESSION['user_role'] = $_SESSION['usr_type'];
}

include 'include/db_connect.php';
if (!isset($bd) || !($bd instanceof mysqli)) {
    header('Location: /HRSmart/index.php');
    exit;
}
mysqli_set_charset($bd, 'utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: /HRSmart/index.php');
    exit;
}

include 'include/page_guard.php';
guard_page($bd);

?>
