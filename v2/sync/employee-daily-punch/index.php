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

$changes = extract_changes($payload);
if ($changes === null) {
    log_message('missing_changes');
    respond(400, ['error' => 'Missing changes array.']);
}

log_message('sync_received', ['changes' => count($changes)]);

$normalizedChanges = [];
foreach ($changes as $index => $change) {
    if (!is_array($change)) {
        log_message('invalid_change_item', ['index' => $index, 'type' => gettype($change)]);
        respond(400, ['error' => 'Invalid change item.', 'index' => $index]);
    }

    if (!array_key_exists('emp_code', $change)) {
        log_message('missing_emp_code', ['index' => $index]);
        respond(400, ['error' => 'Missing emp_code.', 'index' => $index]);
    }
    [$empCode, $empOk] = normalize_string_field($change['emp_code'], false);
    if (!$empOk || $empCode === null) {
        log_message('invalid_emp_code', ['index' => $index]);
        respond(400, ['error' => 'Invalid emp_code.', 'index' => $index]);
    }

    if (!array_key_exists('punch_date', $change)) {
        log_message('missing_punch_date', ['index' => $index]);
        respond(400, ['error' => 'Missing punch_date.', 'index' => $index]);
    }
    $punchDate = normalize_date($change['punch_date']);
    if ($punchDate === null) {
        log_message('invalid_punch_date', ['index' => $index]);
        respond(400, ['error' => 'Invalid punch_date.', 'index' => $index]);
    }

    $changeType = normalize_change_type($change['changeType'] ?? null);
    if ($changeType === null && array_key_exists('changeType', $change)) {
        log_message('invalid_change_type', ['index' => $index, 'value' => $change['changeType']]);
        respond(400, ['error' => 'Invalid changeType.', 'index' => $index]);
    }

    $isDeleted = normalize_bitlike($change['is_deleted'] ?? null);
    if ($isDeleted === null) {
        $isDeleted = '0';
    }

    $mode = 'upsert';
    if ($changeType === 'delete' || $isDeleted === '1') {
        $mode = 'delete';
        $isDeleted = '1';
    }

    $record = [
        'emp_code' => $empCode,
        'punch_date' => $punchDate,
        'change_type' => $changeType ?? ($mode === 'delete' ? 'delete' : null),
        'is_deleted' => $isDeleted,
        'first_log' => null,
        'last_log' => null,
        'first_terminal_sn' => null,
        'last_terminal_sn' => null,
    ];

    if ($mode === 'upsert') {
        [$firstLog, $firstLogOk] = normalize_datetime_field($change['first_log'] ?? null, true);
        if (!$firstLogOk) {
            log_message('invalid_field', ['index' => $index, 'field' => 'first_log']);
            respond(400, ['error' => 'Invalid field.', 'field' => 'first_log', 'index' => $index]);
        }
        [$lastLog, $lastLogOk] = normalize_datetime_field($change['last_log'] ?? null, true);
        if (!$lastLogOk) {
            log_message('invalid_field', ['index' => $index, 'field' => 'last_log']);
            respond(400, ['error' => 'Invalid field.', 'field' => 'last_log', 'index' => $index]);
        }

        [$firstSn, $firstSnOk] = normalize_string_field($change['first_terminal_sn'] ?? null, true);
        if (!$firstSnOk) {
            log_message('invalid_field', ['index' => $index, 'field' => 'first_terminal_sn']);
            respond(400, ['error' => 'Invalid field.', 'field' => 'first_terminal_sn', 'index' => $index]);
        }
        [$lastSn, $lastSnOk] = normalize_string_field($change['last_terminal_sn'] ?? null, true);
        if (!$lastSnOk) {
            log_message('invalid_field', ['index' => $index, 'field' => 'last_terminal_sn']);
            respond(400, ['error' => 'Invalid field.', 'field' => 'last_terminal_sn', 'index' => $index]);
        }

        $record['first_log'] = $firstLog;
        $record['last_log'] = $lastLog;
        $record['first_terminal_sn'] = $firstSn;
        $record['last_terminal_sn'] = $lastSn;
    }

    $normalizedChanges[] = [
        'mode' => $mode,
        'data' => $record,
    ];
}

if (count($normalizedChanges) === 0) {
    log_message('sync_empty_changes');
    respond(200, ['received' => 0, 'applied' => 0, 'errors' => 0]);
}

try {
    $bd = open_db();
    ensure_tables($bd);

    $bd->begin_transaction();

    $stmtInsert = prepare_statement(
        $bd,
        'INSERT INTO employee_daily_punch_sync (' .
        'emp_code, punch_date, first_log, last_log, first_terminal_sn, last_terminal_sn, is_deleted, change_type' .
        ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'daily_punch_insert'
    );

    $stmtDelete = prepare_statement(
        $bd,
        'INSERT INTO employee_daily_punch_sync (emp_code, punch_date, is_deleted, change_type) VALUES (?, ?, 1, ?)',
        'daily_punch_delete'
    );

    $summary = ['received' => count($normalizedChanges), 'applied' => 0, 'errors' => 0];

    foreach ($normalizedChanges as $change) {
        $mode = $change['mode'];
        $data = $change['data'];

        if ($mode === 'delete') {
            $changeType = $data['change_type'] ?? 'delete';
            if (!$stmtDelete->bind_param('sss', $data['emp_code'], $data['punch_date'], $changeType)) {
                throw new RuntimeException('Delete bind failed: ' . $stmtDelete->error);
            }
            if (!$stmtDelete->execute()) {
                throw new RuntimeException('Delete execute failed: ' . $stmtDelete->error);
            }
            $summary['applied']++;
            continue;
        }

        $types = str_repeat('s', 8);
        if (!$stmtInsert->bind_param(
            $types,
            $data['emp_code'],
            $data['punch_date'],
            $data['first_log'],
            $data['last_log'],
            $data['first_terminal_sn'],
            $data['last_terminal_sn'],
            $data['is_deleted'],
            $data['change_type']
        )) {
            throw new RuntimeException('Insert bind failed: ' . $stmtInsert->error);
        }
        if (!$stmtInsert->execute()) {
            throw new RuntimeException('Insert execute failed: ' . $stmtInsert->error);
        }
        $summary['applied']++;
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

function extract_changes(array $payload): ?array
{
    if (array_key_exists('changes', $payload) && is_array($payload['changes'])) {
        return $payload['changes'];
    }
    if (array_key_exists('rows', $payload) && is_array($payload['rows'])) {
        return $payload['rows'];
    }
    if (is_list_array($payload)) {
        return $payload;
    }

    return null;
}

function is_list_array(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
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
    $envKey = getenv('EMPLOYEE_DAILY_PUNCH_SYNC_API_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return $envKey;
    }

    $dbKey = resolve_api_key_from_db('employee_daily_punch_sync_api_key');
    if ($dbKey !== '') {
        return $dbKey;
    }

    $config = load_config();
    if (!empty($config['api_key'])) {
        return (string) $config['api_key'];
    }

    return '';
}

function resolve_api_key_from_db(string $configKey): string
{
    try {
        $bd = open_db();
        ensure_config_table($bd);

        $stmt = prepare_statement(
            $bd,
            'SELECT config_value FROM api_config WHERE config_key = ? LIMIT 1',
            'api_key_lookup'
        );
        if (!$stmt->bind_param('s', $configKey)) {
            throw new RuntimeException('API key lookup bind failed: ' . $stmt->error);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('API key lookup execute failed: ' . $stmt->error);
        }
        $stmt->bind_result($value);
        $result = '';
        if ($stmt->fetch()) {
            $result = (string) $value;
        }
        $stmt->close();

        return $result;
    } catch (Throwable $e) {
        log_message('api_key_db_lookup_failed', ['error' => truncate_error($e->getMessage())]);
    }

    return '';
}

function resolve_log_path(): string
{
    $config = load_config();
    if (!empty($config['log_path'])) {
        return (string) $config['log_path'];
    }

    $envPath = getenv('EMPLOYEE_DAILY_PUNCH_SYNC_LOG_PATH');
    if (is_string($envPath) && $envPath !== '') {
        return $envPath;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'employee_daily_punch_sync.log';
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
        $envName = getenv('EMPLOYEE_DAILY_PUNCH_SYNC_DB_NAME');
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
    ensure_config_table($bd);

    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS employee_daily_punch_sync (' .
        'id bigint NOT NULL AUTO_INCREMENT,' .
        'emp_code varchar(20) NOT NULL,' .
        'punch_date date NOT NULL,' .
        'first_log datetime NULL,' .
        'last_log datetime NULL,' .
        'first_terminal_sn varchar(50) NULL,' .
        'last_terminal_sn varchar(50) NULL,' .
        'change_type varchar(10) NULL,' .
        'is_deleted tinyint(1) NOT NULL DEFAULT 0,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (id),' .
        'KEY idx_emp_date (emp_code, punch_date)' .
        ') ENGINE=InnoDB'
    )) {
        log_message('table_create_failed', [
            'table' => 'employee_daily_punch_sync',
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to create employee_daily_punch_sync table.');
    }

    ensure_table_columns($bd, 'employee_daily_punch_sync', [
        'first_log' => 'datetime NULL',
        'last_log' => 'datetime NULL',
        'first_terminal_sn' => 'varchar(50) NULL',
        'last_terminal_sn' => 'varchar(50) NULL',
        'change_type' => 'varchar(10) NULL',
        'is_deleted' => 'tinyint(1) NOT NULL DEFAULT 0',
        'received_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ]);

    drop_index_if_exists($bd, 'employee_daily_punch_sync', 'uniq_emp_date');
    drop_primary_key_if_not_id($bd, 'employee_daily_punch_sync');
    ensure_sync_identity_column($bd, 'employee_daily_punch_sync');
    ensure_index($bd, 'employee_daily_punch_sync', 'idx_emp_date', ['emp_code', 'punch_date']);
}

function ensure_config_table(mysqli $bd): void
{
    if (!$bd->query(
        'CREATE TABLE IF NOT EXISTS api_config (' .
        'config_key varchar(100) NOT NULL,' .
        'config_value varchar(255) NOT NULL,' .
        'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (config_key)' .
        ') ENGINE=InnoDB'
    )) {
        log_message('table_create_failed', [
            'table' => 'api_config',
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to create api_config table.');
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

function ensure_unique_index(mysqli $bd, string $table, string $indexName, array $columns): void
{
    if (has_unique_index($bd, $table, $columns)) {
        return;
    }

    $parts = [];
    foreach ($columns as $column) {
        $parts[] = '`' . $column . '`';
    }
    $sql = 'ALTER TABLE `' . $table . '` ADD UNIQUE KEY `' . $indexName . '` (' . implode(', ', $parts) . ')';
    if (!$bd->query($sql)) {
        log_message('index_add_failed', [
            'table' => $table,
            'index' => $indexName,
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to add unique index ' . $indexName . ' to ' . $table . '.');
    }
}

function ensure_index(mysqli $bd, string $table, string $indexName, array $columns): void
{
    if (has_index($bd, $table, $indexName)) {
        return;
    }

    $parts = [];
    foreach ($columns as $column) {
        $parts[] = '`' . $column . '`';
    }
    $sql = 'ALTER TABLE `' . $table . '` ADD INDEX `' . $indexName . '` (' . implode(', ', $parts) . ')';
    if (!$bd->query($sql)) {
        log_message('index_add_failed', [
            'table' => $table,
            'index' => $indexName,
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to add index ' . $indexName . ' to ' . $table . '.');
    }
}

function has_index(mysqli $bd, string $table, string $indexName): bool
{
    $result = $bd->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $bd->real_escape_string($indexName) . "'");
    if (!$result) {
        return false;
    }
    $has = $result->num_rows > 0;
    $result->free();

    return $has;
}

function has_unique_index(mysqli $bd, string $table, array $columns): bool
{
    $result = $bd->query('SHOW INDEX FROM `' . $table . '`');
    if (!$result) {
        return false;
    }

    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $key = (string) ($row['Key_name'] ?? '');
        if ($key === '') {
            continue;
        }
        $seq = (int) ($row['Seq_in_index'] ?? 0);
        $col = strtolower((string) ($row['Column_name'] ?? ''));
        $unique = ((string) ($row['Non_unique'] ?? '1') === '0');

        if (!isset($indexes[$key])) {
            $indexes[$key] = ['unique' => $unique, 'cols' => []];
        }
        if ($unique) {
            $indexes[$key]['unique'] = true;
        }
        if ($seq > 0) {
            $indexes[$key]['cols'][$seq] = $col;
        }
    }
    $result->free();

    $target = array_map('strtolower', $columns);

    foreach ($indexes as $meta) {
        if (!$meta['unique']) {
            continue;
        }
        ksort($meta['cols']);
        $cols = array_values($meta['cols']);
        if ($cols === $target) {
            return true;
        }
    }

    return false;
}

function drop_index_if_exists(mysqli $bd, string $table, string $indexName): void
{
    if (!has_index($bd, $table, $indexName)) {
        return;
    }
    $sql = 'ALTER TABLE `' . $table . '` DROP INDEX `' . $indexName . '`';
    if (!$bd->query($sql)) {
        log_message('index_drop_failed', [
            'table' => $table,
            'index' => $indexName,
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to drop index ' . $indexName . ' from ' . $table . '.');
    }
}

function drop_primary_key_if_not_id(mysqli $bd, string $table): void
{
    $primaryCols = get_index_columns($bd, $table, 'PRIMARY');
    if (!$primaryCols) {
        return;
    }
    if ($primaryCols === ['emp_code', 'punch_date'] || $primaryCols === ['punch_date', 'emp_code']) {
        if (!$bd->query('ALTER TABLE `' . $table . '` DROP PRIMARY KEY')) {
            log_message('primary_key_drop_failed', [
                'table' => $table,
                'error' => $bd->error,
            ]);
            throw new RuntimeException('Failed to drop primary key from ' . $table . '.');
        }
        return;
    }
}

function ensure_sync_identity_column(mysqli $bd, string $table): void
{
    $primaryCols = get_index_columns($bd, $table, 'PRIMARY');
    if ($primaryCols) {
        return;
    }

    $autoIncrementColumn = get_auto_increment_column($bd, $table);
    if ($autoIncrementColumn !== null) {
        if (!$bd->query('ALTER TABLE `' . $table . '` ADD PRIMARY KEY (`' . $autoIncrementColumn . '`)')) {
            log_message('primary_key_add_failed', [
                'table' => $table,
                'error' => $bd->error,
            ]);
            throw new RuntimeException('Failed to add primary key to ' . $table . '.');
        }
        return;
    }

    $columnInfo = get_column_info($bd, $table, 'id');
    if ($columnInfo === null) {
        if (!$bd->query('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST')) {
            log_message('column_add_failed', [
                'table' => $table,
                'column' => 'id',
                'error' => $bd->error,
            ]);
            throw new RuntimeException('Failed to add id column to ' . $table . '.');
        }
        return;
    }

    $extra = strtolower((string) ($columnInfo['Extra'] ?? ''));
    if (strpos($extra, 'auto_increment') === false) {
        if (!$bd->query('ALTER TABLE `' . $table . '` MODIFY COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT')) {
            log_message('column_modify_failed', [
                'table' => $table,
                'column' => 'id',
                'error' => $bd->error,
            ]);
            throw new RuntimeException('Failed to enable AUTO_INCREMENT for id column in ' . $table . '.');
        }
    }

    if (!$bd->query('ALTER TABLE `' . $table . '` ADD PRIMARY KEY (`id`)')) {
        log_message('primary_key_add_failed', [
            'table' => $table,
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to add primary key to ' . $table . '.');
    }
}

function get_column_info(mysqli $bd, string $table, string $column): ?array
{
    $result = $bd->query('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $bd->real_escape_string($column) . '\'');
    if (!$result) {
        return null;
    }
    $row = $result->fetch_assoc();
    $result->free();

    return $row ?: null;
}

function get_auto_increment_column(mysqli $bd, string $table): ?string
{
    $result = $bd->query('SHOW COLUMNS FROM `' . $table . '`');
    if (!$result) {
        return null;
    }
    while ($row = $result->fetch_assoc()) {
        $extra = strtolower((string) ($row['Extra'] ?? ''));
        if ($extra !== '' && strpos($extra, 'auto_increment') !== false) {
            $result->free();
            return (string) ($row['Field'] ?? '');
        }
    }
    $result->free();
    return null;
}

function get_index_columns(mysqli $bd, string $table, string $indexName): array
{
    $result = $bd->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = \'' . $bd->real_escape_string($indexName) . '\'');
    if (!$result) {
        return [];
    }

    $cols = [];
    while ($row = $result->fetch_assoc()) {
        $seq = (int) ($row['Seq_in_index'] ?? 0);
        $col = strtolower((string) ($row['Column_name'] ?? ''));
        if ($seq > 0 && $col !== '') {
            $cols[$seq] = $col;
        }
    }
    $result->free();

    if (!$cols) {
        return [];
    }

    ksort($cols);
    return array_values($cols);
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

function normalize_change_type($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = strtolower(trim((string) $value));
    if (in_array($value, ['upsert', 'insert', 'update', 'delete'], true)) {
        return $value;
    }
    return null;
}

function normalize_bitlike($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value)) {
        if ($value === 0 || $value === 1) {
            return (string) $value;
        }
        return null;
    }
    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'true'], true)) {
            return '1';
        }
        if (in_array($value, ['0', 'false'], true)) {
            return '0';
        }
    }
    return null;
}

function normalize_string_field($value, bool $nullable): array
{
    if ($value === null) {
        return [null, $nullable];
    }

    $value = trim((string) $value);
    if ($value === '') {
        return [null, $nullable];
    }

    return [$value, true];
}

function normalize_date($value): ?string
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

    return null;
}

function normalize_datetime_field($value, bool $nullable): array
{
    if ($value === null) {
        return [null, $nullable];
    }

    $value = trim((string) $value);
    if ($value === '') {
        return [null, $nullable];
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return [null, false];
    }

    return [$dt->format('Y-m-d H:i:s'), true];
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
