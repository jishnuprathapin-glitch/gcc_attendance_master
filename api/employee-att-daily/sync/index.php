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
if ($source !== 'EmployeeAttDaily') {
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

    $job = trim((string) ($change['job'] ?? ''));
    if ($job === '') {
        log_message('missing_job', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Missing job.', 'index' => $index]);
    }

    $attDate = normalize_att_date($change['attDate'] ?? null);
    if ($attDate === null) {
        log_message('invalid_att_date', [
            'index' => $index,
            'change_id' => $changeId,
            'att_date' => (string) ($change['attDate'] ?? ''),
        ]);
        respond(400, ['error' => 'Invalid attDate.', 'index' => $index]);
    }

    $workHours = normalize_work_hours($change['workHours'] ?? null);
    $workCode = normalize_optional_string($change['workCode'] ?? null);
    $pendingLeave = normalize_bool($change['pendingLeave'] ?? false);
    $pendingLeaveCode = normalize_optional_string($change['pendingLeaveCode'] ?? null);
    $pendingLeaveDocNo = normalize_optional_string($change['pendingLeaveDocNo'] ?? null);
    $projectCodeUtime = normalize_optional_string($change['projectCodeUtime'] ?? null);
    $workHoursUtime = normalize_work_hours($change['workHoursUtime'] ?? null);
    $overrideWorkHours = normalize_work_hours($change['overrideWorkHours'] ?? null);
    $overrideWorkCode = normalize_optional_string($change['overrideWorkCode'] ?? null);
    $overrideChangeDate = normalize_datetime($change['overrideChangeDate'] ?? null);
    $overrideChangedByEmail = normalize_optional_string($change['overrideChangedByEmail'] ?? null);
    $overrideChangedByName = normalize_optional_string($change['overrideChangedByName'] ?? null);
    $overrideApprovedByEmail = normalize_optional_string($change['overrideApprovedByEmail'] ?? null);
    $overrideApprovedByName = normalize_optional_string($change['overrideApprovedByName'] ?? null);
    $overrideIsApproved = normalize_optional_bool($change['overrideIsApproved'] ?? null);
    $overrideApprovedDate = normalize_datetime($change['overrideApprovedDate'] ?? null);

    $isDeleted = normalize_bool($change['isDeleted'] ?? false);
    $changeType = normalize_optional_string($change['changeType'] ?? null);
    if ($changeType !== null) {
        $changeType = strtoupper($changeType);
    }
    $changedAt = normalize_datetime($change['changedAt'] ?? null);
    if ($changeType === 'D') {
        $isDeleted = 1;
    }

    $normalizedChanges[] = [
        'change_id' => $changeId,
        'emp_code' => $empCode,
        'job' => $job,
        'att_date' => $attDate,
        'work_hours' => $workHours,
        'work_code' => $workCode,
        'pending_leave' => $pendingLeave,
        'pending_leave_code' => $pendingLeaveCode,
        'pending_leave_doc_no' => $pendingLeaveDocNo,
        'projectcode_utime' => $projectCodeUtime,
        'work_hours_utime' => $workHoursUtime,
        'override_work_hours' => $overrideWorkHours,
        'override_work_code' => $overrideWorkCode,
        'override_change_date' => $overrideChangeDate,
        'override_changed_by_email' => $overrideChangedByEmail,
        'override_changed_by_name' => $overrideChangedByName,
        'override_approved_by_email' => $overrideApprovedByEmail,
        'override_approved_by_name' => $overrideApprovedByName,
        'override_is_approved' => $overrideIsApproved,
        'override_approved_date' => $overrideApprovedDate,
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

    $stmtInboxExists = prepare_statement($bd, 'SELECT change_id FROM employee_att_daily_inbox WHERE change_id = ?', 'inbox_exists');
    $stmtInboxInsert = prepare_statement($bd, 'INSERT INTO employee_att_daily_inbox (change_id, status, error_message) VALUES (?, ?, ?)', 'inbox_insert');
    $stmtChangeInsert = prepare_statement(
        $bd,
        'INSERT IGNORE INTO employee_att_daily ' .
        '(change_id, emp_code, job, Projectcode_utime, work_hours_utime, att_date, work_hours, work_code, pending_leave, ' .
        'pending_leave_code, pending_leave_doc_no, override_work_hours, override_work_code, override_change_date, ' .
        'override_changed_by_email, override_changed_by_name, override_approved_by_email, override_approved_by_name, ' .
        'override_is_approved, override_approved_date, is_deleted, change_type, changed_at) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'changes_insert'
    );

    $summary = ['received' => count($normalizedChanges), 'applied' => 0, 'skipped' => 0, 'errors' => 0];
    $fatal = false;

    foreach ($normalizedChanges as $change) {
        $changeId = $change['change_id'];

        try {
            if (!$stmtInboxExists->bind_param('s', $changeId)) {
                throw new RuntimeException('Inbox exists bind failed: ' . $stmtInboxExists->error);
            }
            if (!$stmtInboxExists->execute()) {
                throw new RuntimeException('Inbox exists execute failed: ' . $stmtInboxExists->error);
            }
            $stmtInboxExists->store_result();
            if ($stmtInboxExists->num_rows > 0) {
                $stmtInboxExists->free_result();
                $summary['skipped']++;
                log_message('change_duplicate', ['change_id' => $changeId]);
                continue;
            }
            $stmtInboxExists->free_result();

            $types = str_repeat('s', 23);
            $changeId = $change['change_id'];
            $empCode = $change['emp_code'];
            $job = $change['job'];
            $projectCodeUtime = $change['projectcode_utime'];
            $workHoursUtime = $change['work_hours_utime'];
            $attDate = $change['att_date'];
            $workHours = $change['work_hours'];
            $workCode = $change['work_code'];
            $pendingLeave = $change['pending_leave'];
            $pendingLeaveCode = $change['pending_leave_code'];
            $pendingLeaveDocNo = $change['pending_leave_doc_no'];
            $overrideWorkHours = $change['override_work_hours'];
            $overrideWorkCode = $change['override_work_code'];
            $overrideChangeDate = $change['override_change_date'];
            $overrideChangedByEmail = $change['override_changed_by_email'];
            $overrideChangedByName = $change['override_changed_by_name'];
            $overrideApprovedByEmail = $change['override_approved_by_email'];
            $overrideApprovedByName = $change['override_approved_by_name'];
            $overrideIsApproved = $change['override_is_approved'];
            $overrideApprovedDate = $change['override_approved_date'];
            $isDeleted = $change['is_deleted'];
            $changeType = $change['change_type'];
            $changedAt = $change['changed_at'];

            if (!$stmtChangeInsert->bind_param(
                $types,
                $changeId,
                $empCode,
                $job,
                $projectCodeUtime,
                $workHoursUtime,
                $attDate,
                $workHours,
                $workCode,
                $pendingLeave,
                $pendingLeaveCode,
                $pendingLeaveDocNo,
                $overrideWorkHours,
                $overrideWorkCode,
                $overrideChangeDate,
                $overrideChangedByEmail,
                $overrideChangedByName,
                $overrideApprovedByEmail,
                $overrideApprovedByName,
                $overrideIsApproved,
                $overrideApprovedDate,
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
            $status = $inserted ? 'applied' : 'skipped';
            $summary[$inserted ? 'applied' : 'skipped']++;
            if (!$inserted) {
                log_message('change_skipped_duplicate', [
                    'change_id' => $changeId,
                ]);
            }

            $errorMessage = null;
            if (!$stmtInboxInsert->bind_param('sss', $changeId, $status, $errorMessage)) {
                throw new RuntimeException('Inbox insert bind failed: ' . $stmtInboxInsert->error);
            }
            if (!$stmtInboxInsert->execute()) {
                throw new RuntimeException('Inbox insert execute failed: ' . $stmtInboxInsert->error);
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            $message = truncate_error($e->getMessage());
            log_message('change_error', [
                'change_id' => $changeId,
                'error' => $message,
            ]);

            try {
                $status = 'error';
                if (!$stmtInboxInsert->bind_param('sss', $changeId, $status, $message)) {
                    throw new RuntimeException('Inbox insert bind failed: ' . $stmtInboxInsert->error);
                }
                if (!$stmtInboxInsert->execute()) {
                    throw new RuntimeException('Inbox insert execute failed: ' . $stmtInboxInsert->error);
                }
            } catch (Throwable $inner) {
                log_message('inbox_error', [
                    'change_id' => $changeId,
                    'error' => truncate_error($inner->getMessage()),
                ]);
                $fatal = true;
                break;
            }
        }
    }

    if ($fatal) {
        $bd->rollback();
        log_message('sync_failed', ['reason' => 'inbox_error']);
        respond(500, ['error' => 'Failed to process batch.']);
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

    $envKey = getenv('EMPLOYEE_ATT_DAILY_API_KEY');
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

    $envPath = getenv('EMPLOYEE_ATT_DAILY_LOG_PATH');
    if (is_string($envPath) && $envPath !== '') {
        return $envPath;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'employee_att_daily_sync.log';
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
        $envName = getenv('EMPLOYEE_ATT_DAILY_DB_NAME');
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
        'CREATE TABLE IF NOT EXISTS employee_att_daily (' .
        'change_id bigint NOT NULL,' .
        'emp_code varchar(10) NOT NULL,' .
        'job varchar(10) NOT NULL,' .
        'Projectcode_utime varchar(10) NULL,' .
        'work_hours_utime decimal(9,2) NULL,' .
        'att_date date NOT NULL,' .
        'work_hours decimal(9,2) NULL,' .
        'work_code varchar(10) NULL,' .
        'pending_leave tinyint(1) NULL,' .
        'pending_leave_code varchar(10) NULL,' .
        'pending_leave_doc_no varchar(20) NULL,' .
        'override_work_hours decimal(9,2) NULL,' .
        'override_work_code varchar(10) NULL,' .
        'override_change_date datetime NULL,' .
        'override_changed_by_email varchar(255) NULL,' .
        'override_changed_by_name varchar(100) NULL,' .
        'override_approved_by_email varchar(255) NULL,' .
        'override_approved_by_name varchar(100) NULL,' .
        'override_is_approved tinyint(1) NULL,' .
        'override_approved_date datetime NULL,' .
        'is_deleted tinyint(1) NULL,' .
        'change_type varchar(10) NULL,' .
        'changed_at datetime NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (change_id)' .
        ') ENGINE=InnoDB'
    )) {
        log_message('table_create_failed', [
            'table' => 'employee_att_daily',
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to create employee_att_daily table.');
    }

    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS employee_att_daily_inbox (' .
        'change_id bigint NOT NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        "status enum('applied','skipped','error') NOT NULL," .
        'error_message varchar(1024) NULL,' .
        'PRIMARY KEY (change_id)' .
        ') ENGINE=InnoDB'
    )) {
        log_message('table_create_failed', [
            'table' => 'employee_att_daily_inbox',
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to create employee_att_daily_inbox table.');
    }

    ensure_table_columns($bd, 'employee_att_daily', [
        'Projectcode_utime' => 'varchar(10) NULL',
        'work_hours_utime' => 'decimal(9,2) NULL',
        'override_work_hours' => 'decimal(9,2) NULL',
        'override_work_code' => 'varchar(10) NULL',
        'override_change_date' => 'datetime NULL',
        'override_changed_by_email' => 'varchar(255) NULL',
        'override_changed_by_name' => 'varchar(100) NULL',
        'override_approved_by_email' => 'varchar(255) NULL',
        'override_approved_by_name' => 'varchar(100) NULL',
        'override_is_approved' => 'tinyint(1) NULL',
        'override_approved_date' => 'datetime NULL',
        'change_type' => 'varchar(10) NULL',
        'changed_at' => 'datetime NULL',
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

function normalize_work_hours($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (string) $value;
    }

    return null;
}

function normalize_optional_bool($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return normalize_bool($value);
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

function normalize_att_date($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^\d{8}$/', $value)) {
        $year = substr($value, 0, 4);
        $month = substr($value, 4, 2);
        $day = substr($value, 6, 2);
        return $year . '-' . $month . '-' . $day;
    }
    return null;
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
