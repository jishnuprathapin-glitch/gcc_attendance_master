<?php

declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    log_message('method_not_allowed', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    respond(405, ['error' => 'Method not allowed.']);
}

try {
    $bd = open_db();
} catch (Throwable $e) {
    log_message('db_connect_failed', ['error' => truncate_error($e->getMessage())]);
    respond(500, ['error' => 'Failed to connect database.']);
}

$headers = get_request_headers();
$apiKey = trim((string) ($headers['x-api-key'] ?? ''));
$expectedApiKey = resolve_api_key($bd);
if ($expectedApiKey !== '' && !hash_equals($expectedApiKey, $apiKey)) {
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

$source = trim((string) ($payload['source'] ?? ''));
if ($source !== 'HRMS_RELOCN_PUSH') {
    log_message('invalid_source', ['source' => $source]);
    respond(400, ['error' => 'Invalid source.']);
}

$sentAt = normalize_datetime($payload['sentAt'] ?? null);
if ($sentAt === null) {
    log_message('invalid_sent_at', ['sent_at' => (string) ($payload['sentAt'] ?? '')]);
    respond(400, ['error' => 'Invalid sentAt.']);
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
        respond(400, ['error' => 'Missing or invalid changeId.', 'index' => $index]);
    }

    $changeType = normalize_change_type($change['changeType'] ?? null);
    if ($changeType === null) {
        log_message('invalid_change_type', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid changeType.', 'index' => $index]);
    }

    $isDeleted = normalize_required_bool($change['isDeleted'] ?? null);
    if ($isDeleted === null) {
        log_message('invalid_is_deleted', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid isDeleted.', 'index' => $index]);
    }
    if ($changeType === 'D') {
        $isDeleted = '1';
    }

    [$okCompCd, $campCompCd] = normalize_required_string($change['LCCompCd'] ?? null, 3);
    if (!$okCompCd) {
        log_message('invalid_lc_comp_cd', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid LCCompCd.', 'index' => $index]);
    }

    [$okCampCode, $campCode] = normalize_required_string($change['LCCD'] ?? null, 3);
    if (!$okCampCode) {
        log_message('invalid_lccd', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid LCCD.', 'index' => $index]);
    }

    [$okCampId, $campId] = normalize_required_int($change['LCID'] ?? null);
    if (!$okCampId) {
        log_message('invalid_lcid', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid LCID.', 'index' => $index]);
    }

    [$okCampName, $campName] = normalize_optional_string($change['LCDESC'] ?? null, 50);
    if (!$okCampName) {
        log_message('invalid_lcdesc', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid LCDESC.', 'index' => $index]);
    }

    [$okCampEmirate, $campEmirate] = normalize_optional_string($change['LCEMIRATE'] ?? null, 3);
    if (!$okCampEmirate) {
        log_message('invalid_lcemirate', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid LCEMIRATE.', 'index' => $index]);
    }

    $changedAt = normalize_datetime($change['changedAt'] ?? null);
    if ($changedAt === null) {
        log_message('invalid_changed_at', ['index' => $index, 'change_id' => $changeId]);
        respond(400, ['error' => 'Invalid changedAt.', 'index' => $index]);
    }

    $normalizedChanges[] = [
        'change_id' => $changeId,
        'camp_comp_cd' => $campCompCd,
        'camp_code' => $campCode,
        'camp_id' => $campId,
        'camp_name' => $campName,
        'camp_emirate' => $campEmirate,
        'is_deleted' => $isDeleted,
        'change_type' => $changeType,
        'changed_at' => $changedAt,
    ];
}

if (count($normalizedChanges) === 0) {
    log_message('sync_empty_changes', ['source' => $source]);
    respond(200, ['ok' => true, 'received' => 0]);
}

try {
    ensure_tables($bd);
    $bd->begin_transaction();

    $stmtInboxExists = prepare_statement($bd, 'SELECT change_id FROM hrms_camp_inbox WHERE change_id = ?', 'camp_inbox_exists');
    $stmtInboxInsert = prepare_statement($bd, 'INSERT INTO hrms_camp_inbox (change_id, status, error_message) VALUES (?, ?, ?)', 'camp_inbox_insert');
    $stmtUpsert = prepare_statement(
        $bd,
        'INSERT INTO hrms_camp_sync ' .
        '(camp_comp_cd, camp_code, camp_id, camp_name, camp_emirate, is_deleted, change_type, changed_at, last_change_id) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE ' .
        'camp_id = VALUES(camp_id), ' .
        'camp_name = VALUES(camp_name), ' .
        'camp_emirate = VALUES(camp_emirate), ' .
        'is_deleted = VALUES(is_deleted), ' .
        'change_type = VALUES(change_type), ' .
        'changed_at = VALUES(changed_at), ' .
        'last_change_id = VALUES(last_change_id), ' .
        'received_at = CURRENT_TIMESTAMP',
        'camp_upsert'
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

            $campCompCd = $change['camp_comp_cd'];
            $campCode = $change['camp_code'];
            $campId = $change['camp_id'];
            $campName = $change['camp_name'];
            $campEmirate = $change['camp_emirate'];
            $isDeleted = (int) $change['is_deleted'];
            $changeType = $change['change_type'];
            $changedAt = $change['changed_at'];

            if (!$stmtUpsert->bind_param(
                'ssississs',
                $campCompCd,
                $campCode,
                $campId,
                $campName,
                $campEmirate,
                $isDeleted,
                $changeType,
                $changedAt,
                $changeId
            )) {
                throw new RuntimeException('Camp upsert bind failed: ' . $stmtUpsert->error);
            }
            if (!$stmtUpsert->execute()) {
                throw new RuntimeException('Camp upsert execute failed: ' . $stmtUpsert->error);
            }

            $summary['applied']++;
            $status = 'applied';
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
                    throw new RuntimeException('Inbox error bind failed: ' . $stmtInboxInsert->error);
                }
                if (!$stmtInboxInsert->execute()) {
                    throw new RuntimeException('Inbox error execute failed: ' . $stmtInboxInsert->error);
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
    respond(200, [
        'ok' => true,
        'received' => $summary['received'],
    ]);
} catch (Throwable $e) {
    log_message('sync_fatal', ['error' => truncate_error($e->getMessage())]);
    if (isset($bd) && $bd instanceof mysqli) {
        @$bd->rollback();
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
        $lower[strtolower((string) $key)] = $value;
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

function resolve_api_key(mysqli $bd): string
{
    $config = load_config();
    if (!empty($config['api_key'])) {
        return trim((string) $config['api_key']);
    }

    $configKey = trim((string) ($config['api_key_config_key'] ?? 'hrms_camp_sync_api_key'));
    if ($configKey !== '') {
        $stmt = $bd->prepare('SELECT config_value FROM api_config WHERE config_key = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $configKey);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    $result->free();
                    if ($row && isset($row['config_value'])) {
                        $value = trim((string) $row['config_value']);
                        if ($value !== '') {
                            $stmt->close();
                            return $value;
                        }
                    }
                }
            }
            $stmt->close();
        }
    }

    $envKey = getenv('HRMS_CAMP_SYNC_API_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return trim($envKey);
    }

    return '';
}

function resolve_log_path(): string
{
    $config = load_config();
    if (!empty($config['log_path'])) {
        return (string) $config['log_path'];
    }

    $envPath = getenv('HRMS_CAMP_SYNC_LOG_PATH');
    if (is_string($envPath) && $envPath !== '') {
        return $envPath;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'hrms_camp_sync.log';
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
    $dbName = trim((string) ($config['db_name'] ?? ''));
    if ($dbName === '') {
        $envName = getenv('HRMS_CAMP_SYNC_DB_NAME');
        if (is_string($envName) && $envName !== '') {
            $dbName = $envName;
        }
    }
    if ($dbName !== '') {
        if (!$bd->select_db($dbName)) {
            throw new RuntimeException('Failed to select database.');
        }
    }

    return $bd;
}

function ensure_tables(mysqli $bd): void
{
    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS hrms_camp_sync (' .
        'camp_comp_cd varchar(3) NOT NULL,' .
        'camp_code varchar(3) NOT NULL,' .
        'camp_id int NULL,' .
        'camp_name varchar(50) NULL,' .
        'camp_emirate varchar(3) NULL,' .
        'is_deleted tinyint(1) NOT NULL DEFAULT 0,' .
        'change_type char(1) NOT NULL,' .
        'changed_at datetime NOT NULL,' .
        'last_change_id bigint NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (camp_comp_cd, camp_code),' .
        'KEY idx_camp_changed_at (changed_at),' .
        'KEY idx_camp_last_change_id (last_change_id)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    )) {
        throw new RuntimeException('Failed to create hrms_camp_sync table: ' . $bd->error);
    }

    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS hrms_camp_inbox (' .
        'change_id bigint NOT NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        "status enum('applied','skipped','error') NOT NULL," .
        'error_message varchar(1024) NULL,' .
        'PRIMARY KEY (change_id)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    )) {
        throw new RuntimeException('Failed to create hrms_camp_inbox table: ' . $bd->error);
    }
}

function prepare_statement(mysqli $bd, string $sql, string $label): mysqli_stmt
{
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare statement (' . $label . '): ' . $bd->error);
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
        if ($value !== '' && preg_match('/^\d+$/', $value)) {
            return $value;
        }
    }
    return '';
}

function normalize_change_type($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = strtoupper(trim($value));
    if (!in_array($value, ['I', 'U', 'D'], true)) {
        return null;
    }
    return $value;
}

function normalize_required_bool($value): ?string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) && ($value === 0 || $value === 1)) {
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
    return null;
}

function normalize_required_string($value, int $maxLength): array
{
    if (!is_scalar($value)) {
        return [false, null];
    }
    $text = trim((string) $value);
    if ($text === '' || strlen($text) > $maxLength) {
        return [false, null];
    }
    return [true, $text];
}

function normalize_optional_string($value, int $maxLength): array
{
    if ($value === null) {
        return [true, null];
    }
    if (!is_scalar($value)) {
        return [false, null];
    }
    $text = trim((string) $value);
    if ($text === '') {
        return [true, null];
    }
    if (strlen($text) > $maxLength) {
        return [false, null];
    }
    return [true, $text];
}

function normalize_required_int($value): array
{
    if (is_int($value)) {
        return [true, $value];
    }
    if (is_float($value) && floor($value) === $value) {
        return [true, (int) $value];
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '' && preg_match('/^-?\d+$/', $value)) {
            return [true, (int) $value];
        }
    }
    return [false, null];
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
    } catch (Throwable $e) {
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
