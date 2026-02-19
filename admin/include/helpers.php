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

function attendance_app_base(): string {
    $base = rtrim(admin_base(), '/');
    if ($base === '') {
        return '/gcc_attendance_master';
    }
    if (substr($base, -6) === '/admin') {
        $root = substr($base, 0, -6);
        return $root !== '' ? $root : '/gcc_attendance_master';
    }
    return $base;
}

function user_has_mapping_access(mysqli $bd, int $userId, string $tableName): bool {
    static $allowedTables = [
        'timekeeper_project_map' => true,
        'campboss_camp_map' => true,
    ];

    if ($userId <= 0 || !isset($allowedTables[$tableName])) {
        return false;
    }
    if (!table_exists($bd, 'gcc_attendance_master', $tableName)) {
        return false;
    }

    $sql = 'SELECT 1 FROM `gcc_attendance_master`.`' . $tableName . '` WHERE user_id = ? LIMIT 1';
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $userIdParam = (string) $userId;
    $stmt->bind_param('s', $userIdParam);
    $hasAccess = false;
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $hasAccess = (bool) $result->fetch_row();
            $result->free();
        }
    }
    $stmt->close();

    return $hasAccess;
}

function resolve_attendance_landing_url(mysqli $bd, int $userId, string $role): string {
    $roleNorm = strtolower(trim($role));
    if (in_array($roleNorm, ['admin', 'manager'], true)) {
        return admin_url('Attendance_Dashboard.php');
    }

    $base = attendance_app_base();
    if (user_has_mapping_access($bd, $userId, 'timekeeper_project_map')) {
        return $base . '/timekeeper/timekeeper_dashboard.php';
    }
    if (user_has_mapping_access($bd, $userId, 'campboss_camp_map')) {
        return $base . '/campboss/campboss_dashboard.php';
    }

    return admin_url('Attendance_Dashboard.php');
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

function table_exists(mysqli $bd, string $schema, string $table): bool {
    $stmt = $bd->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $schema, $table);
    $exists = false;
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $exists = (bool) $result->fetch_row();
            $result->free();
        }
    }
    $stmt->close();
    return $exists;
}

function ensure_table_index(mysqli $bd, string $schema, string $table, string $indexName, string $indexSql): bool {
    $query = 'SHOW INDEX FROM `' . $schema . '`.`' . $table . '` WHERE Key_name = "' . $bd->real_escape_string($indexName) . '"';
    $result = $bd->query($query);
    if ($result) {
        if ($result->num_rows > 0) {
            $result->free();
            return true;
        }
        $result->free();
    }
    return (bool) $bd->query($indexSql);
}

function ensure_attendance_override_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`employee_att_daily_overrides` (' .
        '`emp_code` varchar(10) NOT NULL,' .
        '`att_date` date NOT NULL,' .
        '`override_work_hours` decimal(9,2) NULL,' .
        '`override_work_code` varchar(10) NULL,' .
        '`override_reason_code` varchar(20) NULL,' .
        '`override_reason_note` varchar(255) NULL,' .
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
        'override_reason_code' => 'varchar(20) NULL',
        'override_reason_note' => 'varchar(255) NULL',
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

function ensure_attendance_medical_certificate_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`attendance_medical_certificates` (' .
        '`emp_code` varchar(10) NOT NULL,' .
        '`att_date` date NOT NULL,' .
        '`medical_note` varchar(500) NULL,' .
        '`file_path` varchar(255) NULL,' .
        '`file_name` varchar(255) NULL,' .
        '`updated_by_source` varchar(20) NULL,' .
        '`updated_by_email` varchar(255) NULL,' .
        '`updated_by_name` varchar(100) NULL,' .
        '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`emp_code`, `att_date`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!$bd->query($sql)) {
        return false;
    }

    $existing = [];
    $result = $bd->query('SHOW COLUMNS FROM `gcc_attendance_master`.`attendance_medical_certificates`');
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
        'medical_note' => 'varchar(500) NULL',
        'file_path' => 'varchar(255) NULL',
        'file_name' => 'varchar(255) NULL',
        'updated_by_source' => 'varchar(20) NULL',
        'updated_by_email' => 'varchar(255) NULL',
        'updated_by_name' => 'varchar(100) NULL',
        'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $name => $definition) {
        $key = strtolower($name);
        if (isset($existing[$key])) {
            continue;
        }
        $alter = 'ALTER TABLE `gcc_attendance_master`.`attendance_medical_certificates` ADD COLUMN `' . $name . '` ' . $definition;
        if (!$bd->query($alter)) {
            return false;
        }
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_medical_certificates',
        'idx_updated_at',
        'CREATE INDEX `idx_updated_at` ON `gcc_attendance_master`.`attendance_medical_certificates` (`updated_at`)'
    )) {
        return false;
    }

    if (table_exists($bd, 'gcc_attendance_master', 'attendance_no_punch_reviews')) {
        $backfillSql = 'INSERT IGNORE INTO `gcc_attendance_master`.`attendance_medical_certificates` ' .
            '(`emp_code`, `att_date`, `medical_note`, `file_path`, `file_name`, `updated_by_source`, `updated_by_email`, `updated_by_name`, `updated_at`) ' .
            'SELECT r.emp_code, r.att_date, ' .
            'NULLIF(TRIM(COALESCE(r.campboss_medical_note, "")), ""), ' .
            'NULLIF(TRIM(COALESCE(r.campboss_medical_certificate_path, "")), ""), ' .
            'NULLIF(TRIM(COALESCE(r.campboss_medical_certificate_name, "")), ""), ' .
            '"campboss", ' .
            'NULLIF(TRIM(COALESCE(r.campboss_email, "")), ""), ' .
            'NULLIF(TRIM(COALESCE(r.campboss_name, "")), ""), ' .
            'COALESCE(r.campboss_medical_certificate_uploaded_at, r.campboss_reviewed_at, UTC_TIMESTAMP()) ' .
            'FROM `gcc_attendance_master`.`attendance_no_punch_reviews` r ' .
            'WHERE TRIM(COALESCE(r.campboss_medical_certificate_path, "")) <> ""';
        if (!$bd->query($backfillSql)) {
            return false;
        }
    }

    return true;
}

function upload_attendance_medical_certificate(array $uploadFile, string $empCode, string $attDate): array {
    $result = [
        'ok' => false,
        'path' => null,
        'name' => null,
        'error' => 'Medical certificate upload failed.',
    ];

    if (!isset($uploadFile['error']) || (int) $uploadFile['error'] === UPLOAD_ERR_NO_FILE) {
        $result['error'] = 'Medical certificate is required for sick leave.';
        return $result;
    }
    if ((int) $uploadFile['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'Medical certificate upload failed. Please retry.';
        return $result;
    }

    $tmpPath = (string) ($uploadFile['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $result['error'] = 'Invalid medical certificate file upload.';
        return $result;
    }

    $maxBytes = 5 * 1024 * 1024;
    $fileSize = (int) ($uploadFile['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > $maxBytes) {
        $result['error'] = 'Medical certificate must be between 1 byte and 5 MB.';
        return $result;
    }

    $originalName = trim((string) ($uploadFile['name'] ?? ''));
    if ($originalName === '') {
        $originalName = 'medical-certificate';
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = [
        'pdf' => true,
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
    ];
    if (!isset($allowedExtensions[$extension])) {
        $result['error'] = 'Medical certificate must be PDF, JPG, JPEG, or PNG.';
        return $result;
    }

    $safeEmp = preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper($empCode));
    $safeEmp = $safeEmp !== '' ? $safeEmp : 'EMP';
    $safeDate = preg_replace('/[^0-9]/', '', $attDate);
    $safeDate = $safeDate !== '' ? $safeDate : gmdate('Ymd');
    $uploadDir = dirname(__DIR__) . '/uploads/attendance_medical_certificates';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            $result['error'] = 'Unable to create upload folder for medical certificates.';
            return $result;
        }
    }

    try {
        $randomToken = bin2hex(random_bytes(8));
    } catch (Throwable $ex) {
        $randomToken = str_replace('.', '', uniqid('', true));
    }

    $fileName = $safeEmp . '_' . $safeDate . '_' . $randomToken . '.' . $extension;
    $absolutePath = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        $result['error'] = 'Unable to store medical certificate file.';
        return $result;
    }

    $result['ok'] = true;
    $result['path'] = 'uploads/attendance_medical_certificates/' . $fileName;
    $result['name'] = substr($originalName, 0, 255);
    $result['error'] = null;
    return $result;
}

function upsert_attendance_medical_certificate(
    mysqli $bd,
    string $empCode,
    string $attDate,
    ?string $medicalNote,
    string $filePath,
    ?string $fileName,
    string $source,
    ?string $updatedByEmail,
    ?string $updatedByName,
    ?string $updatedAt = null
): bool {
    $empCode = trim($empCode);
    $attDate = trim($attDate);
    $filePath = trim($filePath);
    if ($empCode === '' || $attDate === '' || $filePath === '') {
        return false;
    }
    if (!ensure_attendance_medical_certificate_table($bd)) {
        return false;
    }

    $noteParam = trim((string) $medicalNote);
    if ($noteParam === '') {
        $noteParam = null;
    } else {
        $noteParam = substr($noteParam, 0, 500);
    }

    $nameParam = trim((string) $fileName);
    if ($nameParam === '') {
        $nameParam = null;
    } else {
        $nameParam = substr($nameParam, 0, 255);
    }

    $sourceParam = strtolower(trim($source));
    if ($sourceParam === '') {
        $sourceParam = null;
    } else {
        $sourceParam = substr($sourceParam, 0, 20);
    }

    $emailParam = trim((string) $updatedByEmail);
    if ($emailParam === '') {
        $emailParam = null;
    } else {
        $emailParam = substr($emailParam, 0, 255);
    }

    $nameByParam = trim((string) $updatedByName);
    if ($nameByParam === '') {
        $nameByParam = null;
    } else {
        $nameByParam = substr($nameByParam, 0, 100);
    }

    $updatedAtParam = trim((string) $updatedAt);
    if ($updatedAtParam === '') {
        $updatedAtParam = gmdate('Y-m-d H:i:s');
    }

    $sql = 'INSERT INTO `gcc_attendance_master`.`attendance_medical_certificates` ' .
        '(`emp_code`, `att_date`, `medical_note`, `file_path`, `file_name`, `updated_by_source`, `updated_by_email`, `updated_by_name`, `updated_at`) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE ' .
        '`medical_note` = VALUES(`medical_note`), ' .
        '`file_path` = VALUES(`file_path`), ' .
        '`file_name` = VALUES(`file_name`), ' .
        '`updated_by_source` = VALUES(`updated_by_source`), ' .
        '`updated_by_email` = VALUES(`updated_by_email`), ' .
        '`updated_by_name` = VALUES(`updated_by_name`), ' .
        '`updated_at` = VALUES(`updated_at`)';

    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $ok = false;
    $stmt->bind_param(
        'sssssssss',
        $empCode,
        $attDate,
        $noteParam,
        $filePath,
        $nameParam,
        $sourceParam,
        $emailParam,
        $nameByParam,
        $updatedAtParam
    );
    if ($stmt->execute()) {
        $ok = true;
    }
    $stmt->close();
    return $ok;
}

function delete_attendance_medical_certificate(mysqli $bd, string $empCode, string $attDate): bool {
    $empCode = trim($empCode);
    $attDate = trim($attDate);
    if ($empCode === '' || $attDate === '') {
        return true;
    }
    if (!table_exists($bd, 'gcc_attendance_master', 'attendance_medical_certificates')) {
        return true;
    }

    $stmt = $bd->prepare(
        'DELETE FROM `gcc_attendance_master`.`attendance_medical_certificates` WHERE emp_code = ? AND att_date = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $empCode, $attDate);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function migrate_no_punch_reason_codes(mysqli $bd): bool {
    $mapping = [
        'ABD' => 'EMP_ABSCONDING',
        'EMP_ABDUCTED' => 'EMP_ABSCONDING',
        'ERC' => 'EMP_RESIGNED',
        'MED' => 'VISA_MED',
        'VISA' => 'VISA_OTH',
        'BIO_VISA' => 'VISA_BIO',
    ];

    foreach ($mapping as $oldCode => $newCode) {
        $oldEsc = $bd->real_escape_string($oldCode);
        $newEsc = $bd->real_escape_string($newCode);

        $copySql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reasons` ' .
            '(reason_code, reason_text, override_work_hours, override_work_code, created_at, updated_at, ' .
            'visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project, is_active, default_behavior, default_work_code) ' .
            'SELECT "' . $newEsc . '", reason_text, override_work_hours, override_work_code, created_at, updated_at, ' .
            'visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project, is_active, default_behavior, default_work_code ' .
            'FROM `gcc_attendance_master`.`attendance_no_punch_reasons` src ' .
            'WHERE src.reason_code = "' . $oldEsc . '" ' .
            'AND NOT EXISTS (SELECT 1 FROM `gcc_attendance_master`.`attendance_no_punch_reasons` dst WHERE dst.reason_code = "' . $newEsc . '")';
        if (!$bd->query($copySql)) {
            return false;
        }

        if (!$bd->query('DELETE FROM `gcc_attendance_master`.`attendance_no_punch_reasons` WHERE reason_code = "' . $oldEsc . '"')) {
            return false;
        }

        if (!$bd->query('UPDATE `gcc_attendance_master`.`attendance_no_punch_reviews` SET campboss_reason_code = "' . $newEsc . '" WHERE campboss_reason_code = "' . $oldEsc . '"')) {
            return false;
        }

        if (!$bd->query('UPDATE `gcc_attendance_master`.`employee_att_daily_overrides` SET override_reason_code = "' . $newEsc . '" WHERE override_reason_code = "' . $oldEsc . '"')) {
            return false;
        }

        if (table_exists($bd, 'gcc_attendance_master', 'attendance_override_notes')) {
            if (!$bd->query('UPDATE `gcc_attendance_master`.`attendance_override_notes` SET reason_code = "' . $newEsc . '" WHERE reason_code = "' . $oldEsc . '"')) {
                return false;
            }
        }
    }

    return true;
}

function seed_no_punch_reason_defaults(mysqli $bd): bool {
    $reasonDefaults = [
        ['code' => 'COMP_OFF', 'text' => 'Compensatory Off', 'tk' => 1, 'cb' => 0, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'MISS_PUNCH', 'text' => 'Miss Punch', 'tk' => 1, 'cb' => 0, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'NIGHT_DAY_SHIFT', 'text' => 'Night Day Shift', 'tk' => 1, 'cb' => 0, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'NONE', 'work_code' => null],
        ['code' => 'NIGHT_SHIFT', 'text' => 'Night Shift', 'tk' => 1, 'cb' => 0, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'NO_LUNCH', 'text' => 'No Lunch', 'tk' => 1, 'cb' => 0, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY_PLUS_1H', 'work_code' => null],
        ['code' => 'TIME_INCORRECT', 'text' => 'Time Captured Incorrectly', 'tk' => 1, 'cb' => 0, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'VISA_MED', 'text' => 'Medical visit for visa', 'tk' => 0, 'cb' => 1, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'TRAN_CAMP', 'text' => 'Employee transfered to another camp', 'tk' => 0, 'cb' => 1, 'auto' => 0, 'transfer' => 1, 'active' => 1, 'behavior' => 'NONE', 'work_code' => null],
        ['code' => 'OTH', 'text' => 'Others', 'tk' => 1, 'cb' => 1, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'NONE', 'work_code' => null],
        ['code' => 'VISA_OTH', 'text' => 'Other visa related', 'tk' => 1, 'cb' => 1, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'VISA_BIO', 'text' => 'Biometric capture for visa', 'tk' => 1, 'cb' => 1, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'VISA_TAWJEEH', 'text' => 'Tawjeeh center visit for visa', 'tk' => 1, 'cb' => 1, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'FULL_DAY', 'work_code' => null],
        ['code' => 'EMP_ABSCONDING', 'text' => 'Employee Absconding', 'tk' => 1, 'cb' => 1, 'auto' => 1, 'transfer' => 0, 'active' => 1, 'behavior' => 'WORK_CODE', 'work_code' => 'ABS'],
        ['code' => 'EMP_RESIGNED', 'text' => 'Employee Resigned Company', 'tk' => 1, 'cb' => 1, 'auto' => 1, 'transfer' => 0, 'active' => 1, 'behavior' => 'NONE', 'work_code' => null],
        ['code' => 'NOT_IN_CAMP', 'text' => 'Not in Camp', 'tk' => 0, 'cb' => 1, 'auto' => 1, 'transfer' => 0, 'active' => 1, 'behavior' => 'NONE', 'work_code' => null],
        ['code' => 'SICK', 'text' => 'Sick Leave', 'tk' => 1, 'cb' => 1, 'auto' => 0, 'transfer' => 0, 'active' => 1, 'behavior' => 'WORK_CODE', 'work_code' => 'SIC'],
    ];

    $sql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reasons` ' .
        '(reason_code, reason_text, override_work_hours, override_work_code, visible_to_timekeeper, visible_to_campboss, auto_escalate, requires_transfer_project, is_active, default_behavior, default_work_code) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE ' .
        'reason_text = VALUES(reason_text), ' .
        'override_work_hours = VALUES(override_work_hours), ' .
        'override_work_code = VALUES(override_work_code), ' .
        'visible_to_timekeeper = VALUES(visible_to_timekeeper), ' .
        'visible_to_campboss = VALUES(visible_to_campboss), ' .
        'auto_escalate = VALUES(auto_escalate), ' .
        'requires_transfer_project = VALUES(requires_transfer_project), ' .
        'is_active = VALUES(is_active), ' .
        'default_behavior = VALUES(default_behavior), ' .
        'default_work_code = VALUES(default_work_code)';

    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return false;
    }

    foreach ($reasonDefaults as $reason) {
        $reasonCode = $reason['code'];
        $reasonText = $reason['text'];
        $overrideHours = null;
        $overrideCode = $reason['work_code'];
        $visibleToTimekeeper = (int) $reason['tk'];
        $visibleToCampboss = (int) $reason['cb'];
        $autoEscalate = (int) $reason['auto'];
        $requiresTransfer = (int) $reason['transfer'];
        $isActive = (int) $reason['active'];
        $defaultBehavior = strtoupper(trim((string) $reason['behavior']));
        $defaultWorkCode = $reason['work_code'] !== null ? strtoupper(trim((string) $reason['work_code'])) : null;

        $stmt->bind_param(
            'ssssiiiiiss',
            $reasonCode,
            $reasonText,
            $overrideHours,
            $overrideCode,
            $visibleToTimekeeper,
            $visibleToCampboss,
            $autoEscalate,
            $requiresTransfer,
            $isActive,
            $defaultBehavior,
            $defaultWorkCode
        );
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
    }

    $stmt->close();

    if (!$bd->query(
        'INSERT INTO `gcc_attendance_master`.`work_type_master` (`wt_cd`, `wt_desc`) ' .
        'VALUES ("SIC", "Sick Leave"), ("ABS", "Absconding") ' .
        'ON DUPLICATE KEY UPDATE `wt_desc` = VALUES(`wt_desc`)'
    )) {
        return false;
    }

    return true;
}

function ensure_no_punch_reason_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`attendance_no_punch_reasons` (' .
        '`reason_code` varchar(20) NOT NULL,' .
        '`reason_text` varchar(100) NOT NULL,' .
        '`override_work_hours` decimal(9,2) NULL,' .
        '`override_work_code` varchar(10) NULL,' .
        '`visible_to_timekeeper` tinyint(1) NOT NULL DEFAULT 1,' .
        '`visible_to_campboss` tinyint(1) NOT NULL DEFAULT 1,' .
        '`auto_escalate` tinyint(1) NOT NULL DEFAULT 0,' .
        '`requires_transfer_project` tinyint(1) NOT NULL DEFAULT 0,' .
        '`is_active` tinyint(1) NOT NULL DEFAULT 1,' .
        '`default_behavior` varchar(30) NOT NULL DEFAULT "NONE",' .
        '`default_work_code` varchar(10) NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`reason_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!$bd->query($sql)) {
        return false;
    }

    $existing = [];
    $result = $bd->query('SHOW COLUMNS FROM `gcc_attendance_master`.`attendance_no_punch_reasons`');
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
        'visible_to_timekeeper' => 'tinyint(1) NOT NULL DEFAULT 1',
        'visible_to_campboss' => 'tinyint(1) NOT NULL DEFAULT 1',
        'auto_escalate' => 'tinyint(1) NOT NULL DEFAULT 0',
        'requires_transfer_project' => 'tinyint(1) NOT NULL DEFAULT 0',
        'is_active' => 'tinyint(1) NOT NULL DEFAULT 1',
        'default_behavior' => 'varchar(30) NOT NULL DEFAULT "NONE"',
        'default_work_code' => 'varchar(10) NULL',
        'created_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $name => $definition) {
        $key = strtolower($name);
        if (isset($existing[$key])) {
            continue;
        }
        $alter = 'ALTER TABLE `gcc_attendance_master`.`attendance_no_punch_reasons` ADD COLUMN `' . $name . '` ' . $definition;
        if (!$bd->query($alter)) {
            return false;
        }
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_no_punch_reasons',
        'idx_reason_scope',
        'CREATE INDEX `idx_reason_scope` ON `gcc_attendance_master`.`attendance_no_punch_reasons` (`visible_to_timekeeper`, `visible_to_campboss`, `is_active`)'
    )) {
        return false;
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_no_punch_reasons',
        'idx_auto_escalate',
        'CREATE INDEX `idx_auto_escalate` ON `gcc_attendance_master`.`attendance_no_punch_reasons` (`auto_escalate`)'
    )) {
        return false;
    }

    if (!migrate_no_punch_reason_codes($bd)) {
        return false;
    }

    return seed_no_punch_reason_defaults($bd);
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
        '`campboss_medical_note` varchar(500) NULL,' .
        '`campboss_medical_certificate_path` varchar(255) NULL,' .
        '`campboss_medical_certificate_name` varchar(255) NULL,' .
        '`campboss_medical_certificate_uploaded_at` datetime NULL,' .
        '`campboss_email` varchar(255) NULL,' .
        '`campboss_name` varchar(100) NULL,' .
        '`campboss_reviewed_at` datetime NULL,' .
        '`is_escalated` tinyint(1) NOT NULL DEFAULT 0,' .
        '`escalated_at` datetime NULL,' .
        '`transfer_to_project_code` varchar(20) NULL,' .
        '`transfer_to_project_name` varchar(200) NULL,' .
        '`transfer_to_camp_code` varchar(20) NULL,' .
        '`transfer_to_camp_name` varchar(200) NULL,' .
        '`auto_escalated_reason` tinyint(1) NOT NULL DEFAULT 0,' .
        'PRIMARY KEY (`emp_code`, `att_date`),' .
        'KEY `idx_campboss_reason` (`campboss_reason_code`),' .
        'KEY `idx_escalated` (`is_escalated`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!$bd->query($sql)) {
        return false;
    }

    $existing = [];
    $result = $bd->query('SHOW COLUMNS FROM `gcc_attendance_master`.`attendance_no_punch_reviews`');
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
        'timekeeper_note' => 'varchar(255) NULL',
        'timekeeper_email' => 'varchar(255) NULL',
        'timekeeper_name' => 'varchar(100) NULL',
        'timekeeper_submitted_at' => 'datetime NULL',
        'campboss_reason_code' => 'varchar(20) NULL',
        'campboss_note' => 'varchar(255) NULL',
        'campboss_medical_note' => 'varchar(500) NULL',
        'campboss_medical_certificate_path' => 'varchar(255) NULL',
        'campboss_medical_certificate_name' => 'varchar(255) NULL',
        'campboss_medical_certificate_uploaded_at' => 'datetime NULL',
        'campboss_email' => 'varchar(255) NULL',
        'campboss_name' => 'varchar(100) NULL',
        'campboss_reviewed_at' => 'datetime NULL',
        'is_escalated' => 'tinyint(1) NOT NULL DEFAULT 0',
        'escalated_at' => 'datetime NULL',
        'transfer_to_project_code' => 'varchar(20) NULL',
        'transfer_to_project_name' => 'varchar(200) NULL',
        'transfer_to_camp_code' => 'varchar(20) NULL',
        'transfer_to_camp_name' => 'varchar(200) NULL',
        'auto_escalated_reason' => 'tinyint(1) NOT NULL DEFAULT 0',
    ];

    foreach ($columns as $name => $definition) {
        $key = strtolower($name);
        if (isset($existing[$key])) {
            continue;
        }
        $alter = 'ALTER TABLE `gcc_attendance_master`.`attendance_no_punch_reviews` ADD COLUMN `' . $name . '` ' . $definition;
        if (!$bd->query($alter)) {
            return false;
        }
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_no_punch_reviews',
        'idx_campboss_reason',
        'CREATE INDEX `idx_campboss_reason` ON `gcc_attendance_master`.`attendance_no_punch_reviews` (`campboss_reason_code`)'
    )) {
        return false;
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_no_punch_reviews',
        'idx_escalated',
        'CREATE INDEX `idx_escalated` ON `gcc_attendance_master`.`attendance_no_punch_reviews` (`is_escalated`)'
    )) {
        return false;
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_no_punch_reviews',
        'idx_transfer_to_project',
        'CREATE INDEX `idx_transfer_to_project` ON `gcc_attendance_master`.`attendance_no_punch_reviews` (`transfer_to_project_code`)'
    )) {
        return false;
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'attendance_no_punch_reviews',
        'idx_transfer_to_camp',
        'CREATE INDEX `idx_transfer_to_camp` ON `gcc_attendance_master`.`attendance_no_punch_reviews` (`transfer_to_camp_code`)'
    )) {
        return false;
    }

    return true;
}

function load_active_camp_codes(mysqli $bd): array {
    $codes = [];
    $result = $bd->query(
        'SELECT camp_code FROM gcc_attendance_master.hrms_camp_sync ' .
        'WHERE is_deleted = 0 ORDER BY camp_code'
    );
    if (!$result) {
        return $codes;
    }
    while ($row = $result->fetch_assoc()) {
        $code = strtoupper(trim((string) ($row['camp_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $codes[$code] = true;
    }
    $result->free();
    return array_keys($codes);
}

function normalize_legacy_camp_candidate(string $value): ?string {
    $value = strtoupper(trim($value));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^([A-Z])0*([0-9]{1,2})$/', $value, $matches)) {
        return $matches[1] . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    return null;
}

function resolve_mapped_camp_code(string $value, array $activeCampSet): ?string {
    $raw = strtoupper(trim($value));
    if ($raw === '') {
        return null;
    }
    if (isset($activeCampSet[$raw])) {
        return $raw;
    }
    $normalized = normalize_legacy_camp_candidate($raw);
    if ($normalized !== null && isset($activeCampSet[$normalized])) {
        return $normalized;
    }
    return null;
}

function seed_campboss_camp_map_from_legacy(mysqli $bd): bool {
    if (!table_exists($bd, 'gcc_attendance_master', 'campboss_project_map')) {
        return true;
    }

    $activeCampCodes = load_active_camp_codes($bd);
    if (empty($activeCampCodes)) {
        return true;
    }
    $activeCampSet = array_fill_keys($activeCampCodes, true);

    $legacyResult = $bd->query(
        'SELECT user_id, project_code FROM gcc_attendance_master.campboss_project_map'
    );
    if (!$legacyResult) {
        return false;
    }

    $insertStmt = $bd->prepare(
        'INSERT INTO gcc_attendance_master.campboss_camp_map (user_id, camp_code) VALUES (?, ?) ' .
        'ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
    );
    if (!$insertStmt) {
        $legacyResult->free();
        return false;
    }

    while ($row = $legacyResult->fetch_assoc()) {
        $userId = trim((string) ($row['user_id'] ?? ''));
        if ($userId === '') {
            continue;
        }
        $resolvedCampCode = resolve_mapped_camp_code((string) ($row['project_code'] ?? ''), $activeCampSet);
        if ($resolvedCampCode === null) {
            continue;
        }
        $insertStmt->bind_param('ss', $userId, $resolvedCampCode);
        if (!$insertStmt->execute()) {
            $insertStmt->close();
            $legacyResult->free();
            return false;
        }
    }

    $insertStmt->close();
    $legacyResult->free();
    return true;
}

function seed_all_camps_to_user(mysqli $bd, string $userId): bool {
    $userId = trim($userId);
    if ($userId === '') {
        return true;
    }

    $activeCampCodes = load_active_camp_codes($bd);
    if (empty($activeCampCodes)) {
        return true;
    }

    $insertStmt = $bd->prepare(
        'INSERT IGNORE INTO gcc_attendance_master.campboss_camp_map (user_id, camp_code) VALUES (?, ?)'
    );
    if (!$insertStmt) {
        return false;
    }

    foreach ($activeCampCodes as $campCode) {
        $insertStmt->bind_param('ss', $userId, $campCode);
        if (!$insertStmt->execute()) {
            $insertStmt->close();
            return false;
        }
    }

    $insertStmt->close();
    return true;
}

function ensure_campboss_camp_map_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`campboss_camp_map` (' .
        '`user_id` varchar(50) NOT NULL,' .
        '`camp_code` varchar(10) NOT NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`user_id`, `camp_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!$bd->query($sql)) {
        return false;
    }

    $existing = [];
    $result = $bd->query('SHOW COLUMNS FROM `gcc_attendance_master`.`campboss_camp_map`');
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
        'created_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];
    foreach ($columns as $name => $definition) {
        $key = strtolower($name);
        if (isset($existing[$key])) {
            continue;
        }
        $alter = 'ALTER TABLE `gcc_attendance_master`.`campboss_camp_map` ADD COLUMN `' . $name . '` ' . $definition;
        if (!$bd->query($alter)) {
            return false;
        }
    }

    if (!ensure_table_index(
        $bd,
        'gcc_attendance_master',
        'campboss_camp_map',
        'idx_camp_code',
        'CREATE INDEX `idx_camp_code` ON `gcc_attendance_master`.`campboss_camp_map` (`camp_code`)'
    )) {
        return false;
    }

    if (!seed_campboss_camp_map_from_legacy($bd)) {
        return false;
    }

    // Temporary business rule: user id 1 must always have access to all camps.
    if (!seed_all_camps_to_user($bd, '1')) {
        return false;
    }

    return true;
}

function ensure_campboss_project_map_table(mysqli $bd): bool {
    return ensure_campboss_camp_map_table($bd);
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

function normalize_work_type_code(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return strtoupper($value);
}

function load_work_type_options(mysqli $bd): array {
    $options = [];
    $result = $bd->query(
        'SELECT wt_cd, wt_desc FROM gcc_attendance_master.work_type_master ORDER BY wt_desc, wt_cd'
    );
    if (!$result) {
        return $options;
    }
    while ($row = $result->fetch_assoc()) {
        $code = normalize_work_type_code($row['wt_cd'] ?? null);
        if ($code === null) {
            continue;
        }
        $options[$code] = trim((string) ($row['wt_desc'] ?? ''));
    }
    $result->free();
    return $options;
}

function is_staff_employee_type(?string $employeeTypeCode): bool {
    return strtoupper(trim((string) $employeeTypeCode)) === '01';
}

function derive_reason_default_hours(array $reasonMeta, bool $isStaff): ?string {
    $behavior = strtoupper(trim((string) ($reasonMeta['default_behavior'] ?? 'NONE')));
    $fullDay = $isStaff ? 8.0 : 10.0;
    $halfDay = $isStaff ? 4.0 : 5.0;

    if ($behavior === 'FULL_DAY') {
        return number_format($fullDay, 2, '.', '');
    }
    if ($behavior === 'HALF_DAY') {
        return number_format($halfDay, 2, '.', '');
    }
    if ($behavior === 'FULL_DAY_PLUS_1H') {
        return number_format($fullDay + 1.0, 2, '.', '');
    }
    return null;
}

function load_no_punch_reason_options(mysqli $bd, string $actor): array {
    $actor = strtolower(trim($actor));
    $where = ['is_active = 1'];
    if ($actor === 'timekeeper') {
        $where[] = 'visible_to_timekeeper = 1';
    } elseif ($actor === 'campboss') {
        $where[] = 'visible_to_campboss = 1';
    }

    $sql = 'SELECT reason_code, reason_text, auto_escalate, requires_transfer_project, default_behavior, default_work_code ' .
        'FROM gcc_attendance_master.attendance_no_punch_reasons ' .
        'WHERE ' . implode(' AND ', $where) . ' ' .
        'ORDER BY CASE WHEN UPPER(TRIM(reason_code)) = "OTH" THEN 1 ELSE 0 END, ' .
        'UPPER(TRIM(reason_code)), UPPER(TRIM(reason_text)), reason_code';

    $options = [];
    $result = $bd->query($sql);
    if (!$result) {
        return $options;
    }

    while ($row = $result->fetch_assoc()) {
        $code = strtoupper(trim((string) ($row['reason_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $defaultBehavior = strtoupper(trim((string) ($row['default_behavior'] ?? 'NONE')));
        if (!in_array($defaultBehavior, ['NONE', 'FULL_DAY', 'HALF_DAY', 'FULL_DAY_PLUS_1H', 'WORK_CODE'], true)) {
            $defaultBehavior = 'NONE';
        }
        $options[$code] = [
            'text' => trim((string) ($row['reason_text'] ?? '')),
            'auto_escalate' => ((int) ($row['auto_escalate'] ?? 0)) === 1,
            'requires_transfer_project' => ((int) ($row['requires_transfer_project'] ?? 0)) === 1,
            'default_behavior' => $defaultBehavior,
            'default_work_code' => normalize_work_type_code($row['default_work_code'] ?? null),
        ];
    }
    $result->free();

    return $options;
}

?>
