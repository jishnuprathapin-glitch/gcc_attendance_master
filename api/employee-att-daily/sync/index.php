<?php

declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'Method not allowed.']);
}

$headers = get_request_headers();
$apiKey = trim((string) ($headers['x-api-key'] ?? ''));
$expectedApiKey = resolve_api_key();
if ($expectedApiKey === '' || !hash_equals($expectedApiKey, $apiKey)) {
    respond(401, ['error' => 'Unauthorized.']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    respond(400, ['error' => 'Empty request body.']);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    respond(400, ['error' => 'Invalid JSON payload.']);
}

if (($payload['source'] ?? '') !== 'EmployeeAttDaily') {
    respond(400, ['error' => 'Invalid source.']);
}

$sentAt = $payload['sentAt'] ?? null;
if (!is_string($sentAt) || $sentAt === '') {
    respond(400, ['error' => 'Missing sentAt.']);
}

$changes = $payload['changes'] ?? null;
if (!is_array($changes)) {
    respond(400, ['error' => 'Missing changes array.']);
}

$normalizedChanges = [];
foreach ($changes as $index => $change) {
    if (!is_array($change)) {
        respond(400, ['error' => 'Invalid change item.', 'index' => $index]);
    }

    $changeId = normalize_change_id($change['changeId'] ?? null);
    if ($changeId === '') {
        respond(400, ['error' => 'Missing changeId.', 'index' => $index]);
    }

    $empCode = trim((string) ($change['empCode'] ?? ''));
    if ($empCode === '') {
        respond(400, ['error' => 'Missing empCode.', 'index' => $index]);
    }

    $job = trim((string) ($change['job'] ?? ''));
    if ($job === '') {
        respond(400, ['error' => 'Missing job.', 'index' => $index]);
    }

    $attDate = trim((string) ($change['attDate'] ?? ''));
    if (!is_valid_att_date($attDate)) {
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
    respond(200, ['received' => 0, 'applied' => 0, 'skipped' => 0, 'errors' => 0]);
}

try {
    $bd = open_db();
    ensure_tables($bd);

    $bd->begin_transaction();

    $stmtInboxExists = $bd->prepare('SELECT change_id FROM employee_att_daily_inbox WHERE change_id = ?');
    $stmtInboxInsert = $bd->prepare('INSERT INTO employee_att_daily_inbox (change_id, status, error_message) VALUES (?, ?, ?)');
    $stmtUpsert = $bd->prepare(
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
        'updated_at = IF(VALUES(last_change_id) > last_change_id, CURRENT_TIMESTAMP, updated_at)'
    );
    $stmtSelectLast = $bd->prepare('SELECT last_change_id FROM employee_att_daily WHERE emp_code = ? AND job = ? AND att_date = ?');

    $summary = ['received' => count($normalizedChanges), 'applied' => 0, 'skipped' => 0, 'errors' => 0];
    $fatal = false;

    foreach ($normalizedChanges as $change) {
        $changeId = $change['change_id'];

        try {
            $stmtInboxExists->bind_param('s', $changeId);
            $stmtInboxExists->execute();
            $stmtInboxExists->store_result();
            if ($stmtInboxExists->num_rows > 0) {
                $stmtInboxExists->free_result();
                $summary['skipped']++;
                continue;
            }
            $stmtInboxExists->free_result();

            $stmtUpsert->bind_param(
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
            );
            $stmtUpsert->execute();

            $stmtSelectLast->bind_param('sss', $change['emp_code'], $change['job'], $change['att_date']);
            $stmtSelectLast->execute();
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

            $errorMessage = null;
            $stmtInboxInsert->bind_param('sss', $changeId, $status, $errorMessage);
            $stmtInboxInsert->execute();
        } catch (Throwable $e) {
            $summary['errors']++;
            $message = truncate_error($e->getMessage());
            error_log('employee_att_daily sync error changeId=' . $changeId . ': ' . $message);

            try {
                $status = 'error';
                $stmtInboxInsert->bind_param('sss', $changeId, $status, $message);
                $stmtInboxInsert->execute();
            } catch (Throwable $inner) {
                error_log('employee_att_daily sync inbox error changeId=' . $changeId . ': ' . $inner->getMessage());
                $fatal = true;
                break;
            }
        }
    }

    if ($fatal) {
        $bd->rollback();
        respond(500, ['error' => 'Failed to process batch.']);
    }

    $bd->commit();
    respond(200, $summary);
} catch (Throwable $e) {
    error_log('employee_att_daily sync fatal error: ' . $e->getMessage());
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

function resolve_api_key(): string
{
    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config) && !empty($config['api_key'])) {
            return (string) $config['api_key'];
        }
    }

    $envKey = getenv('EMPLOYEE_ATT_DAILY_API_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return $envKey;
    }

    return '';
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
    $bd->query(
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
    );

    $bd->query(
        'CREATE TABLE IF NOT EXISTS employee_att_daily_inbox (' .
        'change_id bigint NOT NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        "status enum('applied','skipped','error') NOT NULL," .
        'error_message varchar(1024) NULL,' .
        'PRIMARY KEY (change_id)' .
        ') ENGINE=InnoDB'
    );
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

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
