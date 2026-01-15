<?php

session_start();

$ADMIN_ROOT = __DIR__;
$HRSMART_ROOT = dirname($ADMIN_ROOT, 2) . '/HRSmart';
set_include_path($HRSMART_ROOT . PATH_SEPARATOR . get_include_path());

require $ADMIN_ROOT . '/include/helpers.php';

if (isset($_GET['log'])) {
    session_destroy();
    header('Location: /HRSmart/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /HRSmart/index.php');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: /HRSmart/index.php');
    exit;
}

$email = trim((string) ($_POST['email_id'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$remember = !empty($_POST['remember']);

if ($email === '' || $password === '') {
    header('Location: /HRSmart/index.php');
    exit;
}

include 'include/db_connect.php';
if (!isset($bd) || !($bd instanceof mysqli)) {
    header('Location: /HRSmart/index.php');
    exit;
}
mysqli_set_charset($bd, 'utf8mb4');

$stmt = $bd->prepare('SELECT id, full_name, email, password, role, status FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
    header('Location: /HRSmart/index.php');
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

$valid = false;
if ($user && isset($user['password'])) {
    $stored = (string) $user['password'];
    if (password_verify($password, $stored)) {
        $valid = true;
    } elseif (hash_equals($stored, $password)) {
        $valid = true;
    }
}

if (!$valid || (isset($user['status']) && (string) $user['status'] === '0')) {
    header('Location: /HRSmart/index.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['auth_type'] = 'user';
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_name'] = (string) $user['full_name'];
$_SESSION['user_email'] = (string) $user['email'];
$_SESSION['user_role'] = (string) $user['role'];
$_SESSION['usr_type'] = (string) $user['role'];
$_SESSION['alogin'] = (string) $user['email'];

if ($remember && session_status() === PHP_SESSION_ACTIVE) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

header('Location: ' . admin_url('Attendance_Dashboard.php'));
exit;

?>
