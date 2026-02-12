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

function ensure_attendance_override_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`employee_att_daily_overrides` (' .
        '`emp_code` varchar(10) NOT NULL,' .
        '`att_date` date NOT NULL,' .
        '`override_work_hours` decimal(9,2) NULL,' .
        '`override_work_code` varchar(10) NULL,' .
        '`override_change_date` datetime NULL,' .
        '`override_changed_by_email` varchar(255) NULL,' .
        '`override_changed_by_name` varchar(100) NULL,' .
        '`override_approved_by_email` varchar(255) NULL,' .
        '`override_approved_by_name` varchar(100) NULL,' .
        '`override_is_approved` tinyint(1) NULL,' .
        '`override_approved_date` datetime NULL,' .
        'PRIMARY KEY (`emp_code`, `att_date`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!$bd->query($sql)) {
        return false;
    }

    $existing = [];
    $result = $bd->query('SHOW COLUMNS FROM `gcc_attendance_master`.`employee_att_daily_overrides`');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = strtolower((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $existing[$name] = true;
            }
        }
        $result->free();
    }

    $columns = [
        'override_work_hours' => 'decimal(9,2) NULL',
        'override_work_code' => 'varchar(10) NULL',
        'override_change_date' => 'datetime NULL',
        'override_changed_by_email' => 'varchar(255) NULL',
        'override_changed_by_name' => 'varchar(100) NULL',
        'override_approved_by_email' => 'varchar(255) NULL',
        'override_approved_by_name' => 'varchar(100) NULL',
        'override_is_approved' => 'tinyint(1) NULL',
        'override_approved_date' => 'datetime NULL',
    ];

    foreach ($columns as $name => $definition) {
        $key = strtolower($name);
        if (isset($existing[$key])) {
            continue;
        }
        $alter = 'ALTER TABLE `gcc_attendance_master`.`employee_att_daily_overrides` ADD COLUMN `' . $name . '` ' . $definition;
        if (!$bd->query($alter)) {
            return false;
        }
    }

    return true;
}

function ensure_no_punch_reason_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`attendance_no_punch_reasons` (' .
        '`reason_code` varchar(20) NOT NULL,' .
        '`reason_text` varchar(100) NOT NULL,' .
        '`override_work_hours` decimal(9,2) NULL,' .
        '`override_work_code` varchar(10) NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`reason_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function ensure_no_punch_review_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`attendance_no_punch_reviews` (' .
        '`emp_code` varchar(10) NOT NULL,' .
        '`att_date` date NOT NULL,' .
        '`timekeeper_note` varchar(255) NULL,' .
        '`timekeeper_email` varchar(255) NULL,' .
        '`timekeeper_name` varchar(100) NULL,' .
        '`timekeeper_submitted_at` datetime NULL,' .
        '`campboss_reason_code` varchar(20) NULL,' .
        '`campboss_note` varchar(255) NULL,' .
        '`campboss_email` varchar(255) NULL,' .
        '`campboss_name` varchar(100) NULL,' .
        '`campboss_reviewed_at` datetime NULL,' .
        '`is_escalated` tinyint(1) NOT NULL DEFAULT 0,' .
        '`escalated_at` datetime NULL,' .
        'PRIMARY KEY (`emp_code`, `att_date`),' .
        'KEY `idx_campboss_reason` (`campboss_reason_code`),' .
        'KEY `idx_escalated` (`is_escalated`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function ensure_campboss_project_map_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`campboss_project_map` (' .
        '`user_id` varchar(50) NOT NULL,' .
        '`project_code` varchar(20) NOT NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`user_id`, `project_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function get_api_config(mysqli $bd, string $key, ?string $fallback = null): ?string {
    $stmt = $bd->prepare('SELECT config_value FROM gcc_attendance_master.api_config WHERE config_key = ? LIMIT 1');
    if (!$stmt) {
        return $fallback;
    }
    $value = $fallback;
    $stmt->bind_param('s', $key);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ($row && isset($row['config_value'])) {
                $candidate = trim((string) $row['config_value']);
                if ($candidate !== '') {
                    $value = $candidate;
                }
            }
        }
    }
    $stmt->close();
    return $value;
}

?>
