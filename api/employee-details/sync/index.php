<?php

declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    log_message('method_not_allowed', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    respond(405, ['error' => 'Method not allowed.']);
}

$headers = get_request_headers();
$apiKey = trim((string) ($headers['x-api-key'] ?? ''));
$expectedApiKey = resolve_api_key();
if ($expectedApiKey === '' || !hash_equals($expectedApiKey, $apiKey)) {
    log_message('unauthorized', [
        'has_key' => $apiKey !== '',
        'remote' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    respond(401, ['error' => 'Unauthorized.']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    log_message('empty_body', ['content_length' => $_SERVER['CONTENT_LENGTH'] ?? null]);
    respond(400, ['error' => 'Empty request body.']);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    log_message('invalid_json', [
        'error' => json_last_error_msg(),
        'body_length' => strlen($rawBody),
    ]);
    respond(400, ['error' => 'Invalid JSON payload.']);
}

$source = (string) ($payload['source'] ?? '');
if ($source !== 'EmployeeDetails') {
    log_message('invalid_source', ['source' => $source]);
    respond(400, ['error' => 'Invalid source.']);
}

$sentAt = $payload['sentAt'] ?? null;
if (!is_string($sentAt) || $sentAt === '') {
    log_message('missing_sent_at');
    respond(400, ['error' => 'Missing sentAt.']);
}

$changes = $payload['changes'] ?? null;
if (!is_array($changes)) {
    log_message('missing_changes', ['type' => gettype($changes)]);
    respond(400, ['error' => 'Missing changes array.']);
}

log_message('sync_received', ['source' => $source, 'changes' => count($changes)]);

$normalizedChanges = [];
foreach ($changes as $index => $change) {
    if (!is_array($change)) {
        log_message('invalid_change_item', ['index' => $index, 'type' => gettype($change)]);
        respond(400, ['error' => 'Invalid change item.', 'index' => $index]);
    }

    $changeId = normalize_change_id($change['changeId'] ?? null);
    if ($changeId === '') {
        log_message('missing_change_id', ['index' => $index]);
        respond(400, ['error' => 'Missing changeId.', 'index' => $index]);
    }

    $empCode = trim((string) ($change['empCode'] ?? ''));
    if ($empCode === '') {
        log_message('missing_emp_code', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Missing empCode.', 'index' => $index]);
    }

    if (!array_key_exists('isActive', $change)) {
        log_message('missing_is_active', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Missing isActive.', 'index' => $index]);
    }
    $isActive = normalize_bool($change['isActive']);

    if (!array_key_exists('isDeleted', $change)) {
        log_message('missing_is_deleted', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Missing isDeleted.', 'index' => $index]);
    }
    $isDeleted = normalize_bool($change['isDeleted']);

    $employeeName = normalize_optional_string($change['employeeName'] ?? null);
    $companyName = normalize_optional_string($change['companyName'] ?? null);
    $departmentName = normalize_optional_string($change['departmentName'] ?? null);
    $designationName = normalize_optional_string($change['designationName'] ?? null);
    $doj = normalize_datetime($change['doj'] ?? null);

    $changeType = normalize_optional_string($change['changeType'] ?? null);
    if ($changeType === null) {
        log_message('missing_change_type', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Missing changeType.', 'index' => $index]);
    }
    $changeType = strtoupper($changeType);
    if (!in_array($changeType, ['I', 'U', 'D'], true)) {
        log_message('invalid_change_type', ['index' => $index, 'change_id' => $changeId, 'value' => $changeType]);
        respond(400, ['error' => 'Invalid changeType.', 'index' => $index]);
    }

    $changedAt = normalize_datetime($change['changedAt'] ?? null);
    if ($changedAt === null) {
        log_message('invalid_changed_at', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid changedAt.', 'index' => $index]);
    }

    if ($changeType === 'D') {
        $isDeleted = '1';
    }

    $normalizedChanges[] = [
        'change_id' => $changeId,
        'emp_code' => $empCode,
        'employee_name' => $employeeName,
        'company_name' => $companyName,
        'department_name' => $departmentName,
        'designation_name' => $designationName,
        'doj' => $doj,
        'is_active' => $isActive,
        'is_deleted' => $isDeleted,
        'change_type' => $changeType,
        'changed_at' => $changedAt,
    ];
}

if (count($normalizedChanges) === 0) {
    log_message('sync_empty_changes', ['source' => $source]);
    respond(200, ['received' => 0, 'applied' => 0, 'skipped' => 0, 'errors' => 0]);
}

try {
    $bd = open_db();
    ensure_tables($bd);

    $bd->begin_transaction();

    $stmtChangeInsert = prepare_statement(
        $bd,
        'INSERT IGNORE INTO employee_details ' .
        '(change_id, emp_code, employee_name, company_name, department_name, designation_name, doj, is_active, is_deleted, change_type, changed_at) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'changes_insert'
    );

    $summary = ['received' => count($normalizedChanges), 'applied' => 0, 'skipped' => 0, 'errors' => 0];

    foreach ($normalizedChanges as $change) {
        $types = str_repeat('s', 11);
        $changeId = $change['change_id'];
        $empCode = $change['emp_code'];
        $employeeName = $change['employee_name'];
        $companyName = $change['company_name'];
        $departmentName = $change['department_name'];
        $designationName = $change['designation_name'];
        $doj = $change['doj'];
        $isActive = $change['is_active'];
        $isDeleted = $change['is_deleted'];
        $changeType = $change['change_type'];
        $changedAt = $change['changed_at'];

        if (!$stmtChangeInsert->bind_param(
            $types,
            $changeId,
            $empCode,
            $employeeName,
            $companyName,
            $departmentName,
            $designationName,
            $doj,
            $isActive,
            $isDeleted,
            $changeType,
            $changedAt
        )) {
            throw new RuntimeException('Changes insert bind failed: ' . $stmtChangeInsert->error);
        }
        if (!$stmtChangeInsert->execute()) {
            throw new RuntimeException('Changes insert execute failed: ' . $stmtChangeInsert->error);
        }

        $inserted = ($stmtChangeInsert->affected_rows > 0);
        $summary[$inserted ? 'applied' : 'skipped']++;
        if (!$inserted) {
            log_message('change_skipped_duplicate', ['change_id' => $changeId]);
        }
    }

    $bd->commit();
    log_message('sync_complete', $summary);
    respond(200, $summary);
} catch (Throwable $e) {
    log_message('sync_fatal', ['error' => truncate_error($e->getMessage())]);
    if (isset($bd) && $bd instanceof mysqli && $bd->errno === 0) {
        $bd->rollback();
    }
    respond(500, ['error' => 'Failed to process batch.']);
}

function get_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }
    }

    $lower = [];
    foreach ($headers as $key => $value) {
        $lower[strtolower($key)] = $value;
    }

    return $lower;
}

function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    $config = [];
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return $config;
}

function resolve_api_key(): string
{
    $config = load_config();
    if (!empty($config['api_key'])) {
        return (string) $config['api_key'];
    }

    $envKey = getenv('EMPLOYEE_DETAILS_API_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return $envKey;
    }

    return '';
}

function resolve_log_path(): string
{
    $config = load_config();
    if (!empty($config['log_path'])) {
        return (string) $config['log_path'];
    }

    $envPath = getenv('EMPLOYEE_DETAILS_LOG_PATH');
    if (is_string($envPath) && $envPath !== '') {
        return $envPath;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'employee_details_sync.log';
}

function open_db(): mysqli
{
    $repoRoot = dirname(__DIR__, 3);
    $hrsmartRoot = dirname($repoRoot) . DIRECTORY_SEPARATOR . 'HRSmart';
    $dbConnectPath = $hrsmartRoot . DIRECTORY_SEPARATOR . 'include' . DIRECTORY_SEPARATOR . 'db_connect.php';

    if (!is_file($dbConnectPath)) {
        throw new RuntimeException('HRSmart db_connect.php not found.');
    }

    require $dbConnectPath;

    if (!isset($bd) || !($bd instanceof mysqli)) {
        throw new RuntimeException('Database connection not available.');
    }

    mysqli_set_charset($bd, 'utf8mb4');

    $config = load_config();
    $dbName = '';
    if (!empty($config['db_name'])) {
        $dbName = (string) $config['db_name'];
    }
    if ($dbName === '') {
        $envName = getenv('EMPLOYEE_DETAILS_DB_NAME');
        if (is_string($envName) && $envName !== '') {
            $dbName = $envName;
        }
    }
    if ($dbName !== '') {
        if (!$bd->select_db($dbName)) {
            log_message('db_select_failed', ['database' => $dbName, 'error' => $bd->error]);
            throw new RuntimeException('Failed to select database.');
        }
        log_message('db_selected', ['database' => $dbName]);
    }

    return $bd;
}

function ensure_tables(mysqli $bd): void
{
    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS employee_details (' .
        'change_id bigint NOT NULL,' .
        'emp_code varchar(20) NOT NULL,' .
        'employee_name varchar(200) NULL,' .
        'company_name varchar(200) NULL,' .
        'department_name varchar(200) NULL,' .
        'designation_name varchar(200) NULL,' .
        'doj datetime NULL,' .
        'is_active tinyint(1) NOT NULL,' .
        'is_deleted tinyint(1) NOT NULL,' .
        'change_type char(1) NULL,' .
        'changed_at datetime NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (change_id)' .
        ') ENGINE=InnoDB'
    )) {
        log_message('table_create_failed', [
            'table' => 'employee_details',
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to create employee_details table.');
    }

    ensure_table_columns($bd, 'employee_details', [
        'employee_name' => 'varchar(200) NULL',
        'company_name' => 'varchar(200) NULL',
        'department_name' => 'varchar(200) NULL',
        'designation_name' => 'varchar(200) NULL',
        'doj' => 'datetime NULL',
        'is_active' => 'tinyint(1) NOT NULL',
        'is_deleted' => 'tinyint(1) NOT NULL',
        'change_type' => 'char(1) NULL',
        'changed_at' => 'datetime NULL',
        'received_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ]);
}

function prepare_statement(mysqli $bd, string $sql, string $label): mysqli_stmt
{
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        log_message('statement_prepare_failed', [
            'label' => $label,
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to prepare statement: ' . $label);
    }

    return $stmt;
}

function ensure_table_columns(mysqli $bd, string $table, array $columns): void
{
    $existing = [];
    $result = $bd->query('SHOW COLUMNS FROM `' . $table . '`');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = strtolower((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $existing[$name] = true;
            }
        }
        $result->free();
    }

    foreach ($columns as $name => $definition) {
        $key = strtolower($name);
        if (isset($existing[$key])) {
            continue;
        }
        $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition;
        if (!$bd->query($sql)) {
            log_message('column_add_failed', [
                'table' => $table,
                'column' => $name,
                'error' => $bd->error,
            ]);
            throw new RuntimeException('Failed to add column ' . $name . ' to ' . $table . '.');
        }
    }
}

function normalize_change_id($value): string
{
    if (is_int($value)) {
        return (string) $value;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '' && ctype_digit($value)) {
            return $value;
        }
    }

    return '';
}

function normalize_optional_string($value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function normalize_bool($value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value)) {
        return $value ? '1' : '0';
    }

    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'true', 'yes', 'y'], true)) {
            return '1';
        }
        if (in_array($value, ['0', 'false', 'no', 'n'], true)) {
            return '0';
        }
    }

    return '0';
}

function normalize_datetime($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function truncate_error(string $message): string
{
    if (strlen($message) <= 1024) {
        return $message;
    }

    return substr($message, 0, 1021) . '...';
}

function log_message(string $message, array $context = []): void
{
    $entry = [
        'ts' => gmdate('c'),
        'message' => $message,
    ];
    if ($context) {
        $entry['context'] = $context;
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = gmdate('c') . ' ' . $message;
    }

    error_log($line);

    $logPath = resolve_log_path();
    if ($logPath === '') {
        return;
    }

    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
