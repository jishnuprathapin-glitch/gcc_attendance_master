<?php

declare(strict_types=1);

function h($value): string {
    if (is_array($value)) {
        $value = implode(', ', array_map(static function ($item): string {
            if (is_scalar($item) || $item === null) {
                return (string) $item;
            }
            return json_encode($item, JSON_UNESCAPED_SLASHES) ?: '';
        }, $value));
    } elseif (is_object($value)) {
        if (method_exists($value, '__toString')) {
            $value = (string) $value;
        } else {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_base(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($script === '') {
        return '/gcc_attendance_master/admin';
    }
    $dir = str_replace('\\', '/', dirname($script));
    return rtrim($dir, '/');
}

function admin_url(string $path): string {
    return admin_base() . '/' . ltrim($path, '/');
}

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || $token === null) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash(string $type, string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

?>
