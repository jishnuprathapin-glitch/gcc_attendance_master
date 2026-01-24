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

    $attDate = trim((string) ($change['attDate'] ?? ''));
    if (!is_valid_att_date($attDate)) {
        log_message('invalid_att_date', [
            'index' => $index,
            'change_id' => $changeId,
            'att_date' => $attDate,
        ]);
        respond(400, ['error' => 'Invalid attDate.', 'index' => $index]);
    }

    $workHours = normalize_work_hours($change['workHours'] ?? null);
    $workCode = normalize_optional_string($change['workCode'] ?? null);
    $pendingLeave = normalize_bool($change['pendingLeave'] ?? false);
    $pendingLeaveCode = normalize_optional_string($change['pendingLeaveCode'] ?? null);
    $pendingLeaveDocNo = normalize_optional_string($change['pendingLeaveDocNo'] ?? null);

    $isDeleted = normalize_bool($change['isDeleted'] ?? false);
    $changeType = strtoupper(trim((string) ($change['changeType'] ?? '')));
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
        'is_deleted' => $isDeleted,
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
    $stmtUpsert = prepare_statement(
        $bd,
        'INSERT INTO employee_att_daily ' .
        '(emp_code, job, att_date, work_hours, work_code, pending_leave, pending_leave_code, pending_leave_doc_no, is_deleted, last_change_id) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE ' .
        'work_hours = IF(VALUES(last_change_id) > last_change_id, VALUES(work_hours), work_hours), ' .
        'work_code = IF(VALUES(last_change_id) > last_change_id, VALUES(work_code), work_code), ' .
        'pending_leave = IF(VALUES(last_change_id) > last_change_id, VALUES(pending_leave), pending_leave), ' .
        'pending_leave_code = IF(VALUES(last_change_id) > last_change_id, VALUES(pending_leave_code), pending_leave_code), ' .
        'pending_leave_doc_no = IF(VALUES(last_change_id) > last_change_id, VALUES(pending_leave_doc_no), pending_leave_doc_no), ' .
        'is_deleted = IF(VALUES(last_change_id) > last_change_id, VALUES(is_deleted), is_deleted), ' .
        'last_change_id = GREATEST(last_change_id, VALUES(last_change_id)), ' .
        'updated_at = IF(VALUES(last_change_id) > last_change_id, CURRENT_TIMESTAMP, updated_at)',
        'upsert'
    );
    $stmtSelectLast = prepare_statement($bd, 'SELECT last_change_id FROM employee_att_daily WHERE emp_code = ? AND job = ? AND att_date = ?', 'select_last_change');

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

            if (!$stmtUpsert->bind_param(
                'ssssssssss',
                $change['emp_code'],
                $change['job'],
                $change['att_date'],
                $change['work_hours'],
                $change['work_code'],
                $change['pending_leave'],
                $change['pending_leave_code'],
                $change['pending_leave_doc_no'],
                $change['is_deleted'],
                $changeId
            )) {
                throw new RuntimeException('Upsert bind failed: ' . $stmtUpsert->error);
            }
            if (!$stmtUpsert->execute()) {
                throw new RuntimeException('Upsert execute failed: ' . $stmtUpsert->error);
            }

            if (!$stmtSelectLast->bind_param('sss', $change['emp_code'], $change['job'], $change['att_date'])) {
                throw new RuntimeException('Select last bind failed: ' . $stmtSelectLast->error);
            }
            if (!$stmtSelectLast->execute()) {
                throw new RuntimeException('Select last execute failed: ' . $stmtSelectLast->error);
            }
            $stmtSelectLast->bind_result($lastChangeId);

            $applied = false;
            if ($stmtSelectLast->fetch()) {
                $applied = ((string) $lastChangeId === (string) $changeId);
            } else {
                throw new RuntimeException('Missing row after upsert.');
            }
            $stmtSelectLast->free_result();

            $status = $applied ? 'applied' : 'skipped';
            $summary[$applied ? 'applied' : 'skipped']++;
            if (!$applied) {
                log_message('change_skipped_outdated', [
                    'change_id' => $changeId,
                    'last_change_id' => $lastChangeId,
                    'emp_code' => $change['emp_code'],
                    'job' => $change['job'],
                    'att_date' => $change['att_date'],
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

    return $bd;
}

function ensure_tables(mysqli $bd): void
{
    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS employee_att_daily (' .
        'emp_code varchar(10) NOT NULL,' .
        'job varchar(10) NOT NULL,' .
        'att_date date NOT NULL,' .
        'work_hours decimal(9,2) NULL,' .
        'work_code varchar(10) NULL,' .
        'pending_leave tinyint(1) NOT NULL DEFAULT 0,' .
        'pending_leave_code varchar(10) NULL,' .
        'pending_leave_doc_no varchar(20) NULL,' .
        'is_deleted tinyint(1) NOT NULL DEFAULT 0,' .
        'last_change_id bigint NOT NULL DEFAULT 0,' .
        'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (emp_code, job, att_date)' .
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

function is_valid_att_date(string $value): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
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
