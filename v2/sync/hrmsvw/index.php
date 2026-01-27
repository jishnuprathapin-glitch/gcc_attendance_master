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

$fieldMap = get_field_map();
$normalizedChanges = [];
foreach ($changes as $index => $change) {
    if (!is_array($change)) {
        log_message('invalid_change_item', ['index' => $index, 'type' => gettype($change)]);
        respond(400, ['error' => 'Invalid change item.', 'index' => $index]);
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

    if (!array_key_exists('EMP_CODE', $change)) {
        log_message('missing_emp_code', ['index' => $index]);
        respond(400, ['error' => 'Missing EMP_CODE.', 'index' => $index]);
    }

    [$empCode, $empOk] = normalize_string_field($change['EMP_CODE'], false);
    if (!$empOk || $empCode === null) {
        log_message('invalid_emp_code', ['index' => $index]);
        respond(400, ['error' => 'Invalid EMP_CODE.', 'index' => $index]);
    }

    $mode = 'upsert';
    if ($changeType === 'delete' || $isDeleted === '1') {
        $mode = 'delete';
        $isDeleted = '1';
    }

    $enforceRequired = ($changeType !== null && in_array($changeType, ['upsert', 'insert', 'update'], true));

    $record = [
        'emp_code' => $empCode,
        'change_type' => $changeType ?? ($mode === 'delete' ? 'delete' : null),
        'is_deleted' => $isDeleted,
    ];

    if ($mode === 'upsert') {
        foreach ($fieldMap as $payloadKey => $meta) {
            if ($payloadKey === 'EMP_CODE') {
                continue;
            }

            $hasKey = array_key_exists($payloadKey, $change);
            if (!$hasKey) {
                if ($enforceRequired && $meta['required']) {
                    log_message('missing_field', ['index' => $index, 'field' => $payloadKey]);
                    respond(400, ['error' => 'Missing field.', 'field' => $payloadKey, 'index' => $index]);
                }
                $record[$meta['column']] = null;
                continue;
            }

            $value = $change[$payloadKey];
            if ($meta['type'] === 'string') {
                [$normalized, $ok] = normalize_string_field($value, $meta['nullable']);
            } elseif ($meta['type'] === 'datetime') {
                [$normalized, $ok] = normalize_datetime_field($value, $meta['nullable']);
            } elseif ($meta['type'] === 'bit') {
                $normalized = normalize_bitlike($value);
                $ok = ($normalized !== null);
            } else {
                [$normalized, $ok] = [null, false];
            }

            if (!$ok) {
                log_message('invalid_field', ['index' => $index, 'field' => $payloadKey]);
                respond(400, ['error' => 'Invalid field.', 'field' => $payloadKey, 'index' => $index]);
            }

            $record[$meta['column']] = $normalized;
        }
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

    $stmtUpsert = prepare_statement(
        $bd,
        'INSERT INTO hrmsvw_sync (' .
        'emp_code, br_cd, br_desc, lc_cd, lc_desc, div_cd, div_desc, cc_code, cc_name, sph_cd, sph_name, ty_cd, ty_desc, ' .
        'st_code, st_desc, dept_cd, dept_name, desg_cd, desg_name, tc_cd, tc_desc, grd_cd, grd_desc, cm_cd, cm_desc, code, name, ' .
        'cu_ccd, cu_cname, emp_name, spg_id, sph_group, jbno, jbdesc, st_pay, emp_sex, emp_nationality, emp_dor, emp_doj, ' .
        'is_deleted, change_type, updated_at' .
        ') VALUES (' .
        '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP' .
        ') ON DUPLICATE KEY UPDATE ' .
        'br_cd = VALUES(br_cd), br_desc = VALUES(br_desc), lc_cd = VALUES(lc_cd), lc_desc = VALUES(lc_desc), div_cd = VALUES(div_cd), div_desc = VALUES(div_desc), ' .
        'cc_code = VALUES(cc_code), cc_name = VALUES(cc_name), sph_cd = VALUES(sph_cd), sph_name = VALUES(sph_name), ty_cd = VALUES(ty_cd), ty_desc = VALUES(ty_desc), ' .
        'st_code = VALUES(st_code), st_desc = VALUES(st_desc), dept_cd = VALUES(dept_cd), dept_name = VALUES(dept_name), desg_cd = VALUES(desg_cd), desg_name = VALUES(desg_name), ' .
        'tc_cd = VALUES(tc_cd), tc_desc = VALUES(tc_desc), grd_cd = VALUES(grd_cd), grd_desc = VALUES(grd_desc), cm_cd = VALUES(cm_cd), cm_desc = VALUES(cm_desc), ' .
        'code = VALUES(code), name = VALUES(name), cu_ccd = VALUES(cu_ccd), cu_cname = VALUES(cu_cname), emp_name = VALUES(emp_name), spg_id = VALUES(spg_id), ' .
        'sph_group = VALUES(sph_group), jbno = VALUES(jbno), jbdesc = VALUES(jbdesc), st_pay = VALUES(st_pay), emp_sex = VALUES(emp_sex), emp_nationality = VALUES(emp_nationality), ' .
        'emp_dor = VALUES(emp_dor), emp_doj = VALUES(emp_doj), is_deleted = VALUES(is_deleted), change_type = VALUES(change_type), updated_at = CURRENT_TIMESTAMP',
        'hrmsvw_upsert'
    );

    $stmtDelete = prepare_statement(
        $bd,
        'INSERT INTO hrmsvw_sync (emp_code, is_deleted, change_type, updated_at) VALUES (?, 1, ?, CURRENT_TIMESTAMP) ' .
        'ON DUPLICATE KEY UPDATE is_deleted = VALUES(is_deleted), change_type = VALUES(change_type), updated_at = CURRENT_TIMESTAMP',
        'hrmsvw_delete'
    );

    $summary = ['received' => count($normalizedChanges), 'applied' => 0, 'errors' => 0];

    foreach ($normalizedChanges as $change) {
        $mode = $change['mode'];
        $data = $change['data'];

        if ($mode === 'delete') {
            $changeType = $data['change_type'] ?? 'delete';
            if (!$stmtDelete->bind_param('ss', $data['emp_code'], $changeType)) {
                throw new RuntimeException('Delete bind failed: ' . $stmtDelete->error);
            }
            if (!$stmtDelete->execute()) {
                throw new RuntimeException('Delete execute failed: ' . $stmtDelete->error);
            }
            $summary['applied']++;
            continue;
        }

        $types = str_repeat('s', 41);
        if (!$stmtUpsert->bind_param(
            $types,
            $data['emp_code'],
            $data['br_cd'],
            $data['br_desc'],
            $data['lc_cd'],
            $data['lc_desc'],
            $data['div_cd'],
            $data['div_desc'],
            $data['cc_code'],
            $data['cc_name'],
            $data['sph_cd'],
            $data['sph_name'],
            $data['ty_cd'],
            $data['ty_desc'],
            $data['st_code'],
            $data['st_desc'],
            $data['dept_cd'],
            $data['dept_name'],
            $data['desg_cd'],
            $data['desg_name'],
            $data['tc_cd'],
            $data['tc_desc'],
            $data['grd_cd'],
            $data['grd_desc'],
            $data['cm_cd'],
            $data['cm_desc'],
            $data['code'],
            $data['name'],
            $data['cu_ccd'],
            $data['cu_cname'],
            $data['emp_name'],
            $data['spg_id'],
            $data['sph_group'],
            $data['jbno'],
            $data['jbdesc'],
            $data['st_pay'],
            $data['emp_sex'],
            $data['emp_nationality'],
            $data['emp_dor'],
            $data['emp_doj'],
            $data['is_deleted'],
            $data['change_type']
        )) {
            throw new RuntimeException('Upsert bind failed: ' . $stmtUpsert->error);
        }
        if (!$stmtUpsert->execute()) {
            throw new RuntimeException('Upsert execute failed: ' . $stmtUpsert->error);
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
    $envKey = getenv('HRMSVW_SYNC_API_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return $envKey;
    }

    $dbKey = resolve_api_key_from_db('hrmsvw_sync_api_key');
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

    $envPath = getenv('HRMSVW_SYNC_LOG_PATH');
    if (is_string($envPath) && $envPath !== '') {
        return $envPath;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'hrmsvw_sync.log';
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
        $envName = getenv('HRMSVW_SYNC_DB_NAME');
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
        'CREATE TABLE IF NOT EXISTS hrmsvw_sync (' .
        'emp_code varchar(20) NOT NULL,' .
        'br_cd varchar(50) NULL,' .
        'br_desc varchar(200) NULL,' .
        'lc_cd varchar(50) NULL,' .
        'lc_desc varchar(200) NULL,' .
        'div_cd varchar(50) NULL,' .
        'div_desc varchar(200) NULL,' .
        'cc_code varchar(50) NULL,' .
        'cc_name varchar(200) NULL,' .
        'sph_cd varchar(50) NULL,' .
        'sph_name varchar(200) NULL,' .
        'ty_cd varchar(50) NULL,' .
        'ty_desc varchar(200) NULL,' .
        'st_code varchar(50) NULL,' .
        'st_desc varchar(200) NULL,' .
        'dept_cd varchar(50) NULL,' .
        'dept_name varchar(200) NULL,' .
        'desg_cd varchar(50) NULL,' .
        'desg_name varchar(200) NULL,' .
        'tc_cd varchar(50) NULL,' .
        'tc_desc varchar(200) NULL,' .
        'grd_cd varchar(50) NULL,' .
        'grd_desc varchar(200) NULL,' .
        'cm_cd varchar(50) NULL,' .
        'cm_desc varchar(200) NULL,' .
        'code varchar(50) NULL,' .
        'name varchar(200) NULL,' .
        'cu_ccd varchar(50) NULL,' .
        'cu_cname varchar(200) NULL,' .
        'emp_name varchar(200) NULL,' .
        'spg_id varchar(50) NULL,' .
        'sph_group varchar(200) NULL,' .
        'jbno varchar(50) NULL,' .
        'jbdesc varchar(200) NULL,' .
        'st_pay tinyint(1) NULL,' .
        'emp_sex varchar(20) NULL,' .
        'emp_nationality varchar(100) NULL,' .
        'emp_dor datetime NULL,' .
        'emp_doj datetime NULL,' .
        'change_type varchar(10) NULL,' .
        'is_deleted tinyint(1) NOT NULL DEFAULT 0,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (emp_code)' .
        ') ENGINE=InnoDB'
    )) {
        log_message('table_create_failed', [
            'table' => 'hrmsvw_sync',
            'error' => $bd->error,
        ]);
        throw new RuntimeException('Failed to create hrmsvw_sync table.');
    }

    ensure_table_columns($bd, 'hrmsvw_sync', [
        'br_cd' => 'varchar(50) NULL',
        'br_desc' => 'varchar(200) NULL',
        'lc_cd' => 'varchar(50) NULL',
        'lc_desc' => 'varchar(200) NULL',
        'div_cd' => 'varchar(50) NULL',
        'div_desc' => 'varchar(200) NULL',
        'cc_code' => 'varchar(50) NULL',
        'cc_name' => 'varchar(200) NULL',
        'sph_cd' => 'varchar(50) NULL',
        'sph_name' => 'varchar(200) NULL',
        'ty_cd' => 'varchar(50) NULL',
        'ty_desc' => 'varchar(200) NULL',
        'st_code' => 'varchar(50) NULL',
        'st_desc' => 'varchar(200) NULL',
        'dept_cd' => 'varchar(50) NULL',
        'dept_name' => 'varchar(200) NULL',
        'desg_cd' => 'varchar(50) NULL',
        'desg_name' => 'varchar(200) NULL',
        'tc_cd' => 'varchar(50) NULL',
        'tc_desc' => 'varchar(200) NULL',
        'grd_cd' => 'varchar(50) NULL',
        'grd_desc' => 'varchar(200) NULL',
        'cm_cd' => 'varchar(50) NULL',
        'cm_desc' => 'varchar(200) NULL',
        'code' => 'varchar(50) NULL',
        'name' => 'varchar(200) NULL',
        'cu_ccd' => 'varchar(50) NULL',
        'cu_cname' => 'varchar(200) NULL',
        'emp_name' => 'varchar(200) NULL',
        'spg_id' => 'varchar(50) NULL',
        'sph_group' => 'varchar(200) NULL',
        'jbno' => 'varchar(50) NULL',
        'jbdesc' => 'varchar(200) NULL',
        'st_pay' => 'tinyint(1) NULL',
        'emp_sex' => 'varchar(20) NULL',
        'emp_nationality' => 'varchar(100) NULL',
        'emp_dor' => 'datetime NULL',
        'emp_doj' => 'datetime NULL',
        'change_type' => 'varchar(10) NULL',
        'is_deleted' => 'tinyint(1) NOT NULL DEFAULT 0',
        'received_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ]); 
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

function get_field_map(): array
{
    return [
        'EMP_CODE' => ['column' => 'emp_code', 'type' => 'string', 'nullable' => false, 'required' => true],
        'BR_CD' => ['column' => 'br_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'BR_DESC' => ['column' => 'br_desc', 'type' => 'string', 'nullable' => false, 'required' => true],
        'LC_CD' => ['column' => 'lc_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'LC_DESC' => ['column' => 'lc_desc', 'type' => 'string', 'nullable' => false, 'required' => true],
        'DIV_CD' => ['column' => 'div_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'DIV_DESC' => ['column' => 'div_desc', 'type' => 'string', 'nullable' => false, 'required' => true],
        'CC_CODE' => ['column' => 'cc_code', 'type' => 'string', 'nullable' => false, 'required' => true],
        'CC_NAME' => ['column' => 'cc_name', 'type' => 'string', 'nullable' => false, 'required' => true],
        'SPH_CD' => ['column' => 'sph_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'SPH_NAME' => ['column' => 'sph_name', 'type' => 'string', 'nullable' => true, 'required' => true],
        'TY_CD' => ['column' => 'ty_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'TY_DESC' => ['column' => 'ty_desc', 'type' => 'string', 'nullable' => true, 'required' => true],
        'ST_CODE' => ['column' => 'st_code', 'type' => 'string', 'nullable' => false, 'required' => true],
        'ST_DESC' => ['column' => 'st_desc', 'type' => 'string', 'nullable' => true, 'required' => true],
        'DEPT_CD' => ['column' => 'dept_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'DEPT_NAME' => ['column' => 'dept_name', 'type' => 'string', 'nullable' => true, 'required' => true],
        'DESG_CD' => ['column' => 'desg_cd', 'type' => 'string', 'nullable' => false, 'required' => true],
        'DESG_NAME' => ['column' => 'desg_name', 'type' => 'string', 'nullable' => false, 'required' => true],
        'TC_CD' => ['column' => 'tc_cd', 'type' => 'string', 'nullable' => true, 'required' => true],
        'TC_DESC' => ['column' => 'tc_desc', 'type' => 'string', 'nullable' => true, 'required' => true],
        'GRD_CD' => ['column' => 'grd_cd', 'type' => 'string', 'nullable' => true, 'required' => true],
        'GRD_DESC' => ['column' => 'grd_desc', 'type' => 'string', 'nullable' => true, 'required' => true],
        'CM_CD' => ['column' => 'cm_cd', 'type' => 'string', 'nullable' => true, 'required' => true],
        'CM_DESC' => ['column' => 'cm_desc', 'type' => 'string', 'nullable' => true, 'required' => true],
        'Code' => ['column' => 'code', 'type' => 'string', 'nullable' => true, 'required' => true],
        'NAME' => ['column' => 'name', 'type' => 'string', 'nullable' => true, 'required' => true],
        'CU_CCD' => ['column' => 'cu_ccd', 'type' => 'string', 'nullable' => true, 'required' => true],
        'CU_CNAME' => ['column' => 'cu_cname', 'type' => 'string', 'nullable' => true, 'required' => true],
        'EMP_NAME' => ['column' => 'emp_name', 'type' => 'string', 'nullable' => true, 'required' => true],
        'SPG_ID' => ['column' => 'spg_id', 'type' => 'string', 'nullable' => false, 'required' => true],
        'SPH_GROUP' => ['column' => 'sph_group', 'type' => 'string', 'nullable' => true, 'required' => true],
        'JBNO' => ['column' => 'jbno', 'type' => 'string', 'nullable' => true, 'required' => true],
        'JBDESC' => ['column' => 'jbdesc', 'type' => 'string', 'nullable' => true, 'required' => true],
        'ST_PAY' => ['column' => 'st_pay', 'type' => 'bit', 'nullable' => false, 'required' => true],
        'EMP_SEX' => ['column' => 'emp_sex', 'type' => 'string', 'nullable' => false, 'required' => true],
        'EMP_NATIONALITY' => ['column' => 'emp_nationality', 'type' => 'string', 'nullable' => true, 'required' => true],
        'EMP_DOR' => ['column' => 'emp_dor', 'type' => 'datetime', 'nullable' => true, 'required' => true],
        'EMP_DOJ' => ['column' => 'emp_doj', 'type' => 'datetime', 'nullable' => true, 'required' => true],
    ];
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
