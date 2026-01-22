<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Device Project Mapping';

function format_iso8601(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt) {
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Exception $e) {
            return null;
        }
    }
    return $dt->format(DATE_ATOM);
}

function load_device_mapping_row(mysqli $bd, string $deviceSn): ?array {
    $stmt = $bd->prepare(
        'SELECT device_sn, device_name, project_id, created_at, updated_at ' .
        'FROM gcc_attendance_master.device_project_map WHERE device_sn = ?'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $deviceSn);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $stmt->bind_result($sn, $name, $projectId, $createdAt, $updatedAt);
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();

    return [
        'device_sn' => $sn,
        'device_name' => $name,
        'project_id' => $projectId,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ];
}

function build_device_map_sync_row(array $row): ?array {
    $deviceSn = trim((string) ($row['device_sn'] ?? ''));
    if ($deviceSn === '') {
        return null;
    }
    $createdAt = format_iso8601($row['created_at'] ?? null);
    if ($createdAt === null) {
        $createdAt = gmdate(DATE_ATOM);
    }

    $payload = [
        'deviceSn' => $deviceSn,
        'createdAt' => $createdAt,
    ];

    $deviceName = trim((string) ($row['device_name'] ?? ''));
    if ($deviceName !== '') {
        $payload['deviceName'] = $deviceName;
    }
    if (array_key_exists('project_id', $row) && $row['project_id'] !== null) {
        $payload['projectId'] = (int) $row['project_id'];
    }

    $updatedAt = format_iso8601($row['updated_at'] ?? null);
    if ($updatedAt !== null) {
        $payload['updatedAt'] = $updatedAt;
    }

    return $payload;
}

function device_display_name(array $device): string {
    $alias = trim((string) ($device['alias'] ?? ''));
    if ($alias !== '') {
        return $alias;
    }
    $terminalName = trim((string) ($device['terminalName'] ?? ''));
    if ($terminalName !== '') {
        return $terminalName;
    }
    return 'Unnamed device';
}

function json_response(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$isAjax = ($_POST['ajax'] ?? '') === '1';
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if ($isAjax && $action === 'update-mapping') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        json_response(['ok' => false, 'message' => 'Invalid request token.']);
    }
    if (!isset($bd) || !($bd instanceof mysqli)) {
        json_response(['ok' => false, 'message' => 'Database connection not available.']);
    }

    $deviceSn = trim((string) ($_POST['deviceSn'] ?? ''));
    if ($deviceSn === '') {
        json_response(['ok' => false, 'message' => 'Device serial number is required.']);
    }
    $deviceName = trim((string) ($_POST['deviceName'] ?? ''));

    $projectIdInput = trim((string) ($_POST['projectId'] ?? ''));
    $projectId = null;
    if ($projectIdInput !== '' && $projectIdInput !== 'unassigned') {
        if (!ctype_digit($projectIdInput)) {
            json_response(['ok' => false, 'message' => 'Invalid project selection.']);
        }
        $projectId = (int) $projectIdInput;
    }

    if ($projectId !== null) {
        $stmt = $bd->prepare('SELECT id FROM gcc_it.projects WHERE id = ?');
        if (!$stmt) {
            json_response(['ok' => false, 'message' => 'Unable to validate project.']);
        }
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $stmt->store_result();
        $projectExists = $stmt->num_rows > 0;
        $stmt->close();
        if (!$projectExists) {
            json_response(['ok' => false, 'message' => 'Selected project does not exist.']);
        }
    }

    $deviceExists = false;
    $stmt = $bd->prepare('SELECT device_sn FROM gcc_attendance_master.device_project_map WHERE device_sn = ?');
    if ($stmt) {
        $stmt->bind_param('s', $deviceSn);
        $stmt->execute();
        $stmt->store_result();
        $deviceExists = $stmt->num_rows > 0;
        $stmt->close();
    }

    if ($deviceExists) {
        if ($projectId === null) {
            $stmt = $bd->prepare(
                'UPDATE gcc_attendance_master.device_project_map SET project_id = NULL WHERE device_sn = ?'
            );
            if (!$stmt) {
                json_response(['ok' => false, 'message' => 'Unable to update mapping.']);
            }
            $stmt->bind_param('s', $deviceSn);
        } else {
            $stmt = $bd->prepare(
                'UPDATE gcc_attendance_master.device_project_map SET project_id = ? WHERE device_sn = ?'
            );
            if (!$stmt) {
                json_response(['ok' => false, 'message' => 'Unable to update mapping.']);
            }
            $stmt->bind_param('is', $projectId, $deviceSn);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            json_response(['ok' => false, 'message' => 'Unable to update mapping.']);
        }
        $stmt->close();
    } else {
        if ($projectId === null) {
            $stmt = $bd->prepare(
                'INSERT INTO gcc_attendance_master.device_project_map (device_sn, device_name, project_id) ' .
                'VALUES (?, ?, NULL)'
            );
            if (!$stmt) {
                json_response(['ok' => false, 'message' => 'Unable to save mapping.']);
            }
            $stmt->bind_param('ss', $deviceSn, $deviceName);
        } else {
            $stmt = $bd->prepare(
                'INSERT INTO gcc_attendance_master.device_project_map (device_sn, device_name, project_id) ' .
                'VALUES (?, ?, ?)'
            );
            if (!$stmt) {
                json_response(['ok' => false, 'message' => 'Unable to save mapping.']);
            }
            $stmt->bind_param('ssi', $deviceSn, $deviceName, $projectId);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            json_response(['ok' => false, 'message' => 'Unable to save mapping.']);
        }
        $stmt->close();
    }

    $syncPayload = null;
    $syncResult = null;
    $syncError = null;
    $row = load_device_mapping_row($bd, $deviceSn);
    if (is_array($row)) {
        $syncPayload = build_device_map_sync_row($row);
    }
    if ($syncPayload) {
        $syncResult = attendance_api_post_json('/device-project-map/upsert', [$syncPayload], 10);
    } else {
        $syncError = 'sync_payload_unavailable';
    }

    json_response([
        'ok' => true,
        'deviceSn' => $deviceSn,
        'projectId' => $projectId,
        'sync' => [
            'ok' => $syncResult['ok'] ?? false,
            'status' => $syncResult['status'] ?? null,
            'error' => $syncResult['error'] ?? $syncError,
        ],
    ]);
}

if ($isAjax && $action === 'onboard-devices') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        json_response(['ok' => false, 'message' => 'Invalid request token.']);
    }
    if (!isset($bd) || !($bd instanceof mysqli)) {
        json_response(['ok' => false, 'message' => 'Database connection not available.']);
    }

    $rawDevices = (string) ($_POST['devices'] ?? '');
    $decoded = json_decode($rawDevices, true);
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'message' => 'Invalid device payload.']);
    }

    $deviceRows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $deviceSn = trim((string) ($row['deviceSn'] ?? $row['sn'] ?? ''));
        if ($deviceSn === '') {
            continue;
        }
        $deviceName = trim((string) ($row['deviceName'] ?? $row['name'] ?? $row['alias'] ?? $row['terminalName'] ?? ''));
        $deviceRows[] = [
            'deviceSn' => $deviceSn,
            'deviceName' => $deviceName,
        ];
    }

    if (empty($deviceRows)) {
        json_response(['ok' => false, 'message' => 'No devices selected.']);
    }

    $selectStmt = $bd->prepare(
        'SELECT device_name FROM gcc_attendance_master.device_project_map WHERE device_sn = ?'
    );
    $insertStmt = $bd->prepare(
        'INSERT INTO gcc_attendance_master.device_project_map (device_sn, device_name, project_id) ' .
        'VALUES (?, ?, NULL)'
    );
    $updateStmt = $bd->prepare(
        'UPDATE gcc_attendance_master.device_project_map SET device_name = ? WHERE device_sn = ?'
    );

    if (!$selectStmt || !$insertStmt || !$updateStmt) {
        if ($selectStmt) {
            $selectStmt->close();
        }
        if ($insertStmt) {
            $insertStmt->close();
        }
        if ($updateStmt) {
            $updateStmt->close();
        }
        json_response(['ok' => false, 'message' => 'Unable to prepare onboarding statement.']);
    }

    $received = count($deviceRows);
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $syncDeviceSn = [];

    foreach ($deviceRows as $row) {
        $deviceSn = $row['deviceSn'];
        $deviceName = $row['deviceName'];

        $existingName = null;
        $selectStmt->bind_param('s', $deviceSn);
        if (!$selectStmt->execute()) {
            $errors[] = 'Unable to check device ' . $deviceSn;
            continue;
        }
        $selectStmt->store_result();
        $exists = $selectStmt->num_rows > 0;
        if ($exists) {
            $selectStmt->bind_result($existingName);
            $selectStmt->fetch();
        }
        $selectStmt->free_result();

        if ($exists) {
            $existingName = trim((string) ($existingName ?? ''));
            if ($deviceName !== '' && $deviceName !== $existingName) {
                $updateStmt->bind_param('ss', $deviceName, $deviceSn);
                if ($updateStmt->execute()) {
                    $updated++;
                    $syncDeviceSn[] = $deviceSn;
                } else {
                    $errors[] = 'Unable to update device ' . $deviceSn;
                }
            } else {
                $skipped++;
            }
            continue;
        }

        $insertStmt->bind_param('ss', $deviceSn, $deviceName);
        if ($insertStmt->execute()) {
            $inserted++;
            $syncDeviceSn[] = $deviceSn;
        } else {
            $errors[] = 'Unable to insert device ' . $deviceSn;
        }
    }

    $selectStmt->close();
    $insertStmt->close();
    $updateStmt->close();

    $syncRows = [];
    foreach ($syncDeviceSn as $sn) {
        $row = load_device_mapping_row($bd, $sn);
        if (!is_array($row)) {
            continue;
        }
        $payload = build_device_map_sync_row($row);
        if ($payload) {
            $syncRows[] = $payload;
        }
    }

    $syncResult = null;
    if (!empty($syncRows)) {
        $syncResult = attendance_api_post_json('/device-project-map/upsert', $syncRows, 12);
    }

    $hasErrors = !empty($errors);
    json_response([
        'ok' => !$hasErrors,
        'message' => $hasErrors ? 'Some devices could not be onboarded.' : 'Devices onboarded.',
        'received' => $received,
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
        'sync' => [
            'ok' => $syncResult['ok'] ?? false,
            'status' => $syncResult['status'] ?? null,
            'error' => $syncResult['error'] ?? null,
            'received' => count($syncRows),
        ],
    ]);
}

$projects = [];
$projectMap = [];
$devicesByProject = [];
$onboardedDeviceSn = [];
$onboardedDevices = [];
$projectLabelById = ['unassigned' => 'Unassigned'];
$projectSelectOptions = [['value' => 'unassigned', 'label' => 'Unassigned']];
$loadError = null;

if (isset($bd) && $bd instanceof mysqli) {
    $projectResult = $bd->query('SELECT id, name, pro_code FROM gcc_it.projects ORDER BY pro_code');
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $projects[] = $row;
            $projectId = (string) ($row['id'] ?? '');
            if ($projectId !== '') {
                $projectMap[$projectId] = $row;
            }
        }
        $projectResult->free();
    } else {
        $loadError = 'Unable to load projects.';
    }

    $deviceResult = $bd->query(
        'SELECT device_sn, device_name, project_id FROM gcc_attendance_master.device_project_map ORDER BY device_sn'
    );
    if ($deviceResult) {
        while ($row = $deviceResult->fetch_assoc()) {
            $deviceSn = trim((string) ($row['device_sn'] ?? ''));
            if ($deviceSn === '') {
                continue;
            }
            $onboardedDeviceSn[$deviceSn] = true;
            $onboardedDevices[] = [
                'device_sn' => $deviceSn,
                'device_name' => $row['device_name'] ?? '',
                'project_id' => $row['project_id'],
            ];

            $projectId = $row['project_id'] !== null ? (string) $row['project_id'] : '';
            if ($projectId === '' || !isset($projectMap[$projectId])) {
                $projectId = 'unassigned';
            }
            if (!isset($devicesByProject[$projectId])) {
                $devicesByProject[$projectId] = [];
            }
            $devicesByProject[$projectId][] = $row;
        }
        $deviceResult->free();
    } else {
        $loadError = $loadError ?: 'Unable to load devices.';
    }
} else {
    $loadError = 'Database connection not available.';
}

$lanes = [];
$lanes[] = [
    'id' => 'unassigned',
    'label' => 'Unassigned devices',
    'code' => 'UNASSIGNED',
    'name' => 'Devices',
    'meta' => 'Drop devices here to remove mapping.',
    'devices' => $devicesByProject['unassigned'] ?? [],
];

foreach ($projects as $project) {
    $projectId = (string) ($project['id'] ?? '');
    if ($projectId === '') {
        continue;
    }
    $projectName = trim((string) ($project['name'] ?? ''));
    $projectCode = trim((string) ($project['pro_code'] ?? ''));
    $labelName = $projectName !== '' ? $projectName : ('Project ' . $projectId);
    $label = $labelName;
    if ($projectCode !== '') {
        $label = $projectCode . ' - ' . $labelName;
    }
    $projectLabelById[$projectId] = $label;
    $projectSelectOptions[] = ['value' => $projectId, 'label' => $label];
    $lanes[] = [
        'id' => $projectId,
        'label' => $label,
        'code' => $projectCode,
        'name' => $labelName,
        'meta' => 'Project ID ' . $projectId,
        'devices' => $devicesByProject[$projectId] ?? [],
    ];
}

$deviceTotal = 0;
$unassignedCount = 0;
foreach ($lanes as $lane) {
    $count = is_array($lane['devices'] ?? null) ? count($lane['devices']) : 0;
    $deviceTotal += $count;
    if (($lane['id'] ?? '') === 'unassigned') {
        $unassignedCount = $count;
    }
}
$projectCount = max(0, count($lanes) - 1);

$availableDevices = [];
$availableDevicesError = null;
if (isset($bd) && $bd instanceof mysqli) {
    $deviceApiResult = attendance_api_get('/devices', [], 15);
    if (!$deviceApiResult['ok'] || !is_array($deviceApiResult['data'])) {
        $availableDevicesError = 'Unable to load devices from attendance API.';
    } else {
        foreach ($deviceApiResult['data'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $deviceSn = trim((string) ($device['sn'] ?? ''));
            if ($deviceSn === '' || isset($onboardedDeviceSn[$deviceSn])) {
                continue;
            }
            $alias = trim((string) ($device['alias'] ?? ''));
            $terminalName = trim((string) ($device['terminalName'] ?? ''));
            $labelParts = [];
            if ($alias !== '') {
                $labelParts[] = $alias;
            }
            if ($terminalName !== '' && $terminalName !== $alias) {
                $labelParts[] = $terminalName;
            }
            $displayName = $labelParts ? implode(' / ', $labelParts) : device_display_name($device);
            $availableDevices[] = [
                'sn' => $deviceSn,
                'name' => $displayName,
                'alias' => $alias,
                'terminalName' => $terminalName,
            ];
        }
        usort($availableDevices, function (array $a, array $b): int {
            return strcmp($a['sn'], $b['sn']);
        });
    }
} else {
    $availableDevicesError = 'Database connection not available.';
}

$hasAvailableDevices = !empty($availableDevices);
$showOnboardControls = $hasAvailableDevices && !$availableDevicesError;
$showOnboardEmpty = !$availableDevicesError && !$hasAvailableDevices;
$hasOnboardedDevices = !empty($onboardedDevices);

include __DIR__ . '/include/layout_top.php';

?>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Device to project mapping</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <span class="badge badge-primary">Drag or select mapping</span>
      </div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content mapping-page">
  <div class="container-fluid">
    <?php if ($loadError): ?>
      <div class="alert alert-warning"><?= h($loadError) ?></div>
    <?php endif; ?>

    <div class="card mapping-intro">
      <div class="card-body d-flex flex-column flex-lg-row justify-content-between">
        <div>
          <div class="font-weight-bold">How it works</div>
          <div class="text-muted">Drag devices into a project container or use the dropdowns below to update mappings. Changes save automatically and sync.</div>
          <div class="mapping-intro-meta mt-2">
            <span class="badge badge-light mapping-pill">
              <i class="fas fa-arrows-alt mr-1"></i>Drag to assign
            </span>
            <span class="badge badge-light mapping-pill">
              <i class="fas fa-undo mr-1"></i>Drop in Unassigned to clear
            </span>
          </div>
        </div>
        <div id="mappingStatus" class="small text-muted mt-3 mt-lg-0" role="status" aria-live="polite"></div>
      </div>
    </div>

    <div class="card mapping-toolbar">
      <div class="card-body">
        <div class="row align-items-end">
          <div class="col-lg-4 col-md-6">
            <label for="deviceSearchInput" class="small font-weight-bold text-muted">Search devices</label>
            <div class="input-group input-group-sm">
              <div class="input-group-prepend">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
              </div>
              <input id="deviceSearchInput" type="search" class="form-control form-control-sm" placeholder="Serial number or device name">
            </div>
          </div>
          <div class="col-lg-4 col-md-6 mt-3 mt-md-0">
            <label for="projectSearchInput" class="small font-weight-bold text-muted">Filter projects</label>
            <div class="input-group input-group-sm">
              <div class="input-group-prepend">
                <span class="input-group-text"><i class="fas fa-filter"></i></span>
              </div>
              <input id="projectSearchInput" type="search" class="form-control form-control-sm" placeholder="Project code or name">
            </div>
          </div>
          <div class="col-lg-4 col-md-12 mt-3 mt-lg-0">
            <div class="d-flex flex-wrap align-items-center justify-content-lg-end mapping-toolbar-actions">
              <div class="custom-control custom-switch mr-3">
                <input type="checkbox" class="custom-control-input" id="toggleEmpty">
                <label class="custom-control-label small" for="toggleEmpty">Hide empty projects</label>
              </div>
              <div class="btn-group btn-group-sm mr-2" role="group" aria-label="Collapse controls">
                <button type="button" id="collapseAll" class="btn btn-outline-secondary">Collapse all</button>
                <button type="button" id="expandAll" class="btn btn-outline-secondary">Expand all</button>
              </div>
              <button type="button" id="resetFilters" class="btn btn-sm btn-outline-secondary">Reset</button>
            </div>
          </div>
        </div>
        <div
          id="mappingSummary"
          class="mapping-summary small text-muted mt-3"
          data-total-projects="<?= h((string) $projectCount) ?>"
          data-total-devices="<?= h((string) $deviceTotal) ?>"
          data-unassigned-devices="<?= h((string) $unassignedCount) ?>"
        >
          Projects: <?= h((string) $projectCount) ?> | Devices: <?= h((string) $deviceTotal) ?> | Unassigned: <?= h((string) $unassignedCount) ?>
        </div>
      </div>
    </div>

    <div class="card mapping-onboard">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Onboard new devices</h3>
        <span class="text-muted small">Attendance API</span>
      </div>
      <div class="card-body">
        <?php if ($availableDevicesError): ?>
          <div id="onboardError" class="alert alert-warning mb-0"><?= h($availableDevicesError) ?></div>
        <?php endif; ?>
        <p id="onboardEmptyMessage" class="text-muted mb-0" <?= $showOnboardEmpty ? '' : 'hidden' ?>>All devices are already onboarded.</p>
        <div id="onboardControls" <?= $showOnboardControls ? '' : 'hidden' ?>>
          <div class="form-row align-items-end">
            <div class="col-lg-5 col-md-6">
              <label for="onboardSearchInput" class="small font-weight-bold text-muted">Search available devices</label>
              <input id="onboardSearchInput" type="search" class="form-control form-control-sm" placeholder="Serial number or name">
            </div>
            <div class="col-lg-3 col-md-6 mt-3 mt-md-0">
              <span id="onboardSummary" class="small text-muted d-inline-block mt-md-4">
                Showing <?= h((string) count($availableDevices)) ?> of <?= h((string) count($availableDevices)) ?> | Selected 0
              </span>
            </div>
            <div class="col-lg-4 col-md-12 mt-3 mt-lg-0 text-lg-right">
              <button type="button" id="onboardSelectedBtn" class="btn btn-sm btn-primary">Onboard selected</button>
            </div>
          </div>
          <div class="table-responsive mt-3">
            <table class="table table-sm table-striped table-hover" id="onboardTable">
              <thead>
                <tr>
                  <th style="width: 36px;">
                    <div class="custom-control custom-checkbox">
                      <input type="checkbox" class="custom-control-input" id="onboardSelectAll">
                      <label class="custom-control-label" for="onboardSelectAll"></label>
                    </div>
                  </th>
                  <th>Device SN</th>
                  <th>Device name</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($availableDevices as $device): ?>
                  <?php
                  $deviceSn = (string) ($device['sn'] ?? '');
                  $deviceName = (string) ($device['name'] ?? '');
                  $deviceSearch = strtolower(trim($deviceSn . ' ' . $deviceName));
                  $deviceId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $deviceSn);
                  ?>
                  <tr class="onboard-row" data-device-sn="<?= h($deviceSn) ?>" data-device-name="<?= h($deviceName) ?>" data-device-search="<?= h($deviceSearch) ?>">
                    <td>
                      <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input onboard-checkbox" id="onboard-<?= h($deviceId) ?>">
                        <label class="custom-control-label" for="onboard-<?= h($deviceId) ?>"></label>
                      </div>
                    </td>
                    <td class="text-monospace small"><?= h($deviceSn) ?></td>
                    <td><?= h($deviceName !== '' ? $deviceName : 'Unnamed device') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="mapping-fullscreen-control">
      <button type="button" id="toggleFullscreen" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-expand mr-1"></i><span>Full screen</span>
      </button>
    </div>

    <div class="device-board" id="deviceBoard">
      <?php foreach ($lanes as $lane): ?>
        <?php
        $laneDevices = $lane['devices'];
        $laneLabel = (string) ($lane['label'] ?? '');
        $laneCode = (string) ($lane['code'] ?? '');
        $laneName = (string) ($lane['name'] ?? $laneLabel);
        $laneLabelSearch = strtolower(trim((string) preg_replace('/\s+/', ' ', $laneCode . ' ' . $laneName)));
        $laneId = (string) ($lane['id'] ?? '');
        $laneIsUnassigned = $laneId === 'unassigned';
        ?>
        <div
          class="device-lane <?= $laneIsUnassigned ? 'device-lane--unassigned' : '' ?>"
          data-project-id="<?= h($laneId) ?>"
          data-project-label="<?= h($laneLabelSearch) ?>"
        >
          <div class="device-lane-header">
            <div class="device-lane-header-main">
              <div class="device-lane-title">
                <?php if ($laneCode !== ''): ?>
                  <span class="project-code"><?= h($laneCode) ?></span>
                <?php endif; ?>
                <span class="project-name"><?= h($laneName) ?></span>
              </div>
              <div class="device-lane-sub"><?= h($lane['meta']) ?></div>
            </div>
            <div class="device-lane-actions">
              <span class="badge badge-secondary device-count" data-total-count="<?= count($laneDevices) ?>">
                <?= count($laneDevices) ?>
              </span>
              <button type="button" class="btn btn-sm btn-light lane-toggle" aria-expanded="true" title="Collapse lane">
                <i class="fas fa-chevron-up"></i>
              </button>
            </div>
          </div>
          <div class="device-list <?= empty($laneDevices) ? 'is-empty' : '' ?>" data-project-id="<?= h($laneId) ?>">
            <?php foreach ($laneDevices as $device): ?>
              <?php
              $deviceSn = trim((string) ($device['device_sn'] ?? ''));
              $deviceNameRaw = trim((string) ($device['device_name'] ?? ''));
              $deviceNameLabel = $deviceNameRaw !== '' ? $deviceNameRaw : 'Unnamed device';
              $deviceSearch = strtolower(trim($deviceSn . ' ' . $deviceNameRaw));
              ?>
              <div
                class="device-card"
                data-device-sn="<?= h($deviceSn) ?>"
                data-device-name="<?= h($deviceNameRaw) ?>"
                data-device-search="<?= h($deviceSearch) ?>"
              >
                <div class="device-code"><?= h($deviceSn) ?></div>
                <div class="device-name"><?= h($deviceNameLabel) ?></div>
              </div>
            <?php endforeach; ?>
            <div class="device-empty" data-empty-default="Drop devices here">Drop devices here</div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card mapping-manual">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Manual mapping</h3>
        <span class="text-muted small">Dropdown updates</span>
      </div>
      <div class="card-body">
        <p id="manualEmptyMessage" class="text-muted mb-0" <?= $hasOnboardedDevices ? 'hidden' : '' ?>>No onboarded devices yet.</p>
        <div id="manualMappingForm" class="form-row align-items-end" <?= $hasOnboardedDevices ? '' : 'hidden' ?>>
          <div class="col-lg-5 col-md-6">
            <label for="manualDeviceSelect" class="small font-weight-bold text-muted">Device</label>
            <select id="manualDeviceSelect" class="form-control form-control-sm">
              <?php foreach ($onboardedDevices as $device): ?>
                <?php
                $deviceSn = trim((string) ($device['device_sn'] ?? ''));
                $deviceNameRaw = trim((string) ($device['device_name'] ?? ''));
                $deviceNameLabel = $deviceNameRaw !== '' ? $deviceNameRaw : 'Unnamed device';
                $projectValue = $device['project_id'] !== null ? (string) $device['project_id'] : 'unassigned';
                if ($projectValue === '' || !isset($projectLabelById[$projectValue])) {
                    $projectValue = 'unassigned';
                }
                $optionLabel = $deviceSn . ' - ' . $deviceNameLabel;
                ?>
                <option value="<?= h($deviceSn) ?>" data-device-name="<?= h($deviceNameRaw) ?>" data-project-id="<?= h($projectValue) ?>">
                  <?= h($optionLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-4 col-md-4 mt-3 mt-md-0">
            <label for="manualProjectSelect" class="small font-weight-bold text-muted">Project</label>
            <select id="manualProjectSelect" class="form-control form-control-sm">
              <?php foreach ($projectSelectOptions as $option): ?>
                <option value="<?= h($option['value']) ?>"><?= h($option['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-3 col-md-2 mt-3 mt-md-0">
            <button type="button" id="manualApplyBtn" class="btn btn-sm btn-primary btn-block">Update mapping</button>
          </div>
        </div>
        <div id="manualMappingHint" class="text-muted small mt-2" <?= $hasOnboardedDevices ? '' : 'hidden' ?>>Select a device to load its current project.</div>
      </div>
    </div>
  </div>
</section>

<style>
  .mapping-page {
    --p1: #007bff;
    --p2: #17a2b8;
    --p3: #f8f9fa;
    --p4: #e9ecef;
    --p5: #f8f9fa;
    --ink-strong: #343a40;
    --ink-muted: #6c757d;
    --border-strong: #dee2e6;
    --border-soft: #e9ecef;
    background: var(--p3);
  }

  .content-header h1 {
    color: var(--ink-strong);
  }

  .content-header .badge-primary {
    background: var(--p1);
    border: 1px solid var(--p1);
    color: #ffffff;
  }

  .mapping-page .card {
    border-radius: 0.98rem;
    border: 1px solid var(--border-strong);
    box-shadow: 0 0 1px rgba(0, 0, 0, 0.13), 0 1px 3px rgba(0, 0, 0, 0.2);
  }

  .mapping-intro {
    background: #ffffff;
    border-color: var(--border-strong);
  }

  .mapping-intro .text-muted {
    color: var(--ink-muted) !important;
  }

  .mapping-intro-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.46rem;
  }

  .mapping-pill {
    border: 1px solid var(--border-strong);
    background: var(--p3);
    color: var(--ink-strong);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.26rem 0.59rem;
    border-radius: 999px;
  }

  .mapping-toolbar {
    position: sticky;
    top: 0.65rem;
    z-index: 6;
    backdrop-filter: blur(6px);
    background: #ffffff;
    border: 1px solid var(--border-strong);
  }

  .mapping-toolbar .card-body {
    background: transparent;
  }

  .mapping-toolbar .input-group-text {
    background: var(--p5);
    border-color: var(--border-strong);
    color: var(--ink-strong);
  }

  .mapping-toolbar .form-control {
    border-color: var(--border-strong);
  }

  .mapping-toolbar .form-control:focus {
    border-color: var(--p1);
    box-shadow: 0 0 0 0.1rem rgba(0, 123, 255, 0.2);
  }

  .mapping-toolbar-actions > * {
    margin-bottom: 0.46rem;
    margin-right: 0.65rem;
  }

  .mapping-summary {
    letter-spacing: 0.02em;
    background: #ffffff;
    border: 1px solid var(--p1);
    color: var(--ink-strong);
    padding: 0.39rem 0.78rem;
    border-radius: 999px;
    display: inline-flex;
    gap: 0.65rem;
  }

  .mapping-onboard,
  .mapping-manual {
    margin-top: 1rem;
  }

  .mapping-onboard .table {
    margin-bottom: 0;
  }

  .onboard-row.is-hidden {
    display: none;
  }

  .mapping-fullscreen-control {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 0.46rem;
  }

  .mapping-fullscreen-control .btn {
    border-color: var(--border-strong);
    color: var(--ink-strong);
    background: #ffffff;
  }

  body.mapping-fullscreen .content-header,
  body.mapping-fullscreen .mapping-intro,
  body.mapping-fullscreen .mapping-toolbar,
  body.mapping-fullscreen .mapping-onboard,
  body.mapping-fullscreen .mapping-manual {
    display: none;
  }

  body.mapping-fullscreen .mapping-fullscreen-control {
    position: fixed;
    top: 0.65rem;
    right: 0.65rem;
    z-index: 1000;
    margin: 0;
  }

  body.mapping-fullscreen .mapping-fullscreen-control .btn {
    background: #ffffff;
    box-shadow: 0 6px 18px rgba(11, 31, 58, 0.18);
  }

  body.mapping-fullscreen .device-board {
    margin-top: 0.65rem;
  }

  body.mapping-fullscreen .device-list {
    max-height: calc(100vh - 210px);
  }

  .device-board {
    --lane-col-width: 265px;
    --lane-col-gap: 0.62rem;
    --lane-row-gap: 0.29rem;
    display: flex;
    flex-wrap: nowrap;
    justify-content: center;
    align-items: flex-start;
    gap: var(--lane-col-gap);
    padding: 0.29rem;
    border-radius: 0.94rem;
    border: 1px solid var(--border-strong);
    background: var(--p3);
    margin: 0 auto;
  }

  .device-board-col {
    display: flex;
    flex-direction: column;
    gap: var(--lane-row-gap);
    flex: 0 0 var(--lane-col-width);
    min-width: var(--lane-col-width);
  }

  .device-board.is-dragging .device-card {
    cursor: grabbing;
  }

  .device-lane {
    --lane-header-bg: var(--p3);
    --lane-header-text: var(--ink-strong);
    --lane-sub-text: var(--ink-muted);
    --lane-border: var(--border-strong);
    --lane-chip-bg: color(xyz 0.31 0.4 0.55);
    --lane-chip-text: #ffffff;
    --lane-chip-border: #ab7373;
    --lane-body-bg: #ffffff;
    --lane-card-accent: #396559;
    --lane-empty-bg: var(--p3);
    background: var(--lane-body-bg);
    border: 1px solid var(--lane-border);
    border-left: 4px solid #518b8a;
    border-radius: 0.51rem;
    display: inline-flex;
    flex-direction: column;
    min-height: 126px;
    box-shadow: 0 0 1px rgba(0, 0, 0, 0.13), 0 1px 3px rgba(0, 0, 0, 0.2);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    width: 100%;
    margin: 0;
    break-inside: avoid;
    page-break-inside: avoid;
    -webkit-column-break-inside: avoid;
  }

  .device-lane.is-drop-target {
    border-color: var(--p1);
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.2), 0 8px 18px rgba(0, 123, 255, 0.18);
  }

  .device-lane.is-filtered-out {
    display: none;
  }

  .device-lane-header {
    padding: 0.29rem 0.36rem;
    border-bottom: 1px solid var(--lane-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.29rem;
    background: var(--lane-header-bg);
    color: var(--lane-header-text);
  }

  .device-lane-header-main {
    min-width: 0;
    text-align: center;
  }

  .project-code {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    background: var(--lane-chip-bg);
    color: var(--lane-chip-text);
    border: 1px solid var(--lane-chip-border);
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    margin-right: 0.28rem;
    box-shadow: 0 2px 6px rgba(0, 123, 255, 0.25);
  }

  .project-name {
    color: rgb(144 151 107);
    font-weight: 600;
  }

  .device-lane--unassigned .project-code {
    background: var(--lane-chip-bg);
    color: var(--lane-chip-text);
  }

  .device-lane-title {
    font-weight: 600;
    font-size: 0.88rem;
    word-break: break-word;
    color: inherit;
  }

  .device-lane-sub {
    font-size: 0.74rem;
    color: var(--lane-sub-text);
    margin-top: 0.08rem;
  }

  .device-lane-actions {
    display: flex;
    align-items: center;
    gap: 0.22rem;
  }

  .device-count {
    background: var(--lane-chip-bg);
    border: 1px solid var(--lane-chip-border);
    color: var(--lane-chip-text);
    font-size: 0.81rem;
    padding: 0.17rem 0.4rem;
  }

  .device-lane--unassigned .device-count {
    background: var(--lane-chip-bg);
    border-color: var(--lane-border);
    color: var(--lane-chip-text);
  }

  .device-lane-actions .btn {
    padding: 0.09rem 0.26rem;
    border-color: var(--lane-border);
    color: var(--ink-strong);
    background: var(--p3);
  }

  .device-list {
    padding: 0.29rem;
    min-height: 100px;
    max-height: 47vh;
    overflow-y: auto;
  }

  .device-lane.is-collapsed .device-list {
    max-height: 57px;
    min-height: 57px;
    overflow: hidden;
  }

  .device-lane.is-collapsed .device-card {
    display: none;
  }

  .device-lane.is-collapsed .device-empty {
    display: block;
  }

  .device-card {
    border: 1px solid var(--lane-border);
    border-radius: 0.39rem;
    background: #ffffff;
    padding: 0.22rem 0.42rem;
    margin-bottom: 0.22rem;
    cursor: move;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    text-align: center;
    border-left: 3px solid var(--lane-card-accent);
  }

  .device-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.08);
  }

  .device-card.is-saving {
    opacity: 0.6;
  }

  .device-card.is-hidden {
    display: none;
  }

  .device-code {
    font-weight: 600;
    font-size: 0.83rem;
    color: var(--ink-strong);
  }

  .device-name {
    color: var(--ink-muted);
    font-size: 0.74rem;
  }

  .device-empty {
    display: none;
    color: var(--ink-muted);
    font-size: 0.74rem;
    border: 1px dashed var(--lane-border);
    border-radius: 0.39rem;
    padding: 0.29rem;
    text-align: center;
    background: var(--lane-empty-bg);
  }

  .device-list.is-empty .device-empty {
    display: block;
  }

  .device-list.has-filter .device-empty {
    display: block;
  }

  .device-placeholder {
    border: 2px dashed var(--lane-border);
    border-radius: 0.39rem;
    background: var(--lane-empty-bg);
    height: 38px;
    margin-bottom: 0.22rem;
  }

  @media (max-width: 991.98px) {
    .device-board {
      --lane-col-width: 234px;
    }

    .device-list {
      max-height: 44vh;
    }
  }
</style>

<script>
  const origin = window.location.origin || `${window.location.protocol}//${window.location.host}`;
  const baseUrl = origin + '<?= h(admin_url('Attendance_DeviceMapping.php')) ?>';
  const csrfToken = '<?= h(csrf_token()) ?>';

  const statusEl = document.getElementById('mappingStatus');
  const boardEl = document.getElementById('deviceBoard');
  const fullscreenToggleBtn = document.getElementById('toggleFullscreen');
  const summaryEl = document.getElementById('mappingSummary');
  const deviceSearchInput = document.getElementById('deviceSearchInput');
  const projectSearchInput = document.getElementById('projectSearchInput');
  const toggleEmptyInput = document.getElementById('toggleEmpty');
  const collapseAllBtn = document.getElementById('collapseAll');
  const expandAllBtn = document.getElementById('expandAll');
  const resetFiltersBtn = document.getElementById('resetFilters');
  const onboardSearchInput = document.getElementById('onboardSearchInput');
  const onboardSelectAll = document.getElementById('onboardSelectAll');
  const onboardTable = document.getElementById('onboardTable');
  const onboardSummary = document.getElementById('onboardSummary');
  const onboardSubmitBtn = document.getElementById('onboardSelectedBtn');
  const onboardControls = document.getElementById('onboardControls');
  const onboardEmptyMessage = document.getElementById('onboardEmptyMessage');
  const manualDeviceSelect = document.getElementById('manualDeviceSelect');
  const manualProjectSelect = document.getElementById('manualProjectSelect');
  const manualApplyBtn = document.getElementById('manualApplyBtn');
  const manualEmptyMessage = document.getElementById('manualEmptyMessage');
  const manualMappingForm = document.getElementById('manualMappingForm');
  const manualMappingHint = document.getElementById('manualMappingHint');
  let statusTimer = null;
  let controlsReady = false;
  let cssFullscreen = false;

  const setStatus = (message, type) => {
    if (!statusEl) {
      return;
    }
    if (statusTimer) {
      clearTimeout(statusTimer);
      statusTimer = null;
    }
    statusEl.textContent = message;
    statusEl.classList.remove('text-muted', 'text-success', 'text-danger');
    if (type === 'success') {
      statusEl.classList.add('text-success');
    } else if (type === 'error') {
      statusEl.classList.add('text-danger');
    } else {
      statusEl.classList.add('text-muted');
    }
    if (type !== 'loading') {
      statusTimer = setTimeout(() => {
        statusEl.textContent = '';
        statusEl.classList.remove('text-success', 'text-danger');
        statusEl.classList.add('text-muted');
      }, 3500);
    }
  };

  const updateFullscreenState = () => {
    const isNativeFullscreen = Boolean(document.fullscreenElement);
    const isFullscreen = isNativeFullscreen || cssFullscreen;
    document.body.classList.toggle('mapping-fullscreen', isFullscreen);
    if (fullscreenToggleBtn) {
      const icon = fullscreenToggleBtn.querySelector('i');
      const label = fullscreenToggleBtn.querySelector('span');
      if (icon) {
        icon.classList.toggle('fa-expand', !isFullscreen);
        icon.classList.toggle('fa-compress', isFullscreen);
      }
      if (label) {
        label.textContent = isFullscreen ? 'Exit full screen' : 'Full screen';
      }
      fullscreenToggleBtn.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');
    }
  };

  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      document.exitFullscreen();
      return;
    }
    if (document.documentElement.requestFullscreen) {
      document.documentElement.requestFullscreen().catch(() => {
        cssFullscreen = !cssFullscreen;
        updateFullscreenState();
      });
      return;
    }
    cssFullscreen = !cssFullscreen;
    updateFullscreenState();
  };

  const normalizeText = (value) => {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  };

  const escapeSelector = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(String(value));
    }
    return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  };

  const updateSelectForDevice = (deviceSn, projectId) => {
    if (!deviceSn) {
      return;
    }
    const safeSn = escapeSelector(deviceSn);
    const value = projectId && String(projectId).trim() !== '' ? String(projectId) : 'unassigned';

    const select = document.querySelector(`.device-project-select[data-device-sn="${safeSn}"]`);
    if (select) {
      select.value = value;
      select.dataset.current = value;
      const row = select.closest('tr');
      if (row) {
        const deviceName = row.dataset.deviceName || '';
        const optionLabel = select.options[select.selectedIndex]
          ? select.options[select.selectedIndex].textContent
          : '';
        row.dataset.deviceSearch = normalizeText(`${deviceSn} ${deviceName} ${optionLabel}`);
      }
    }

    if (manualDeviceSelect) {
      const option = manualDeviceSelect.querySelector(`option[value="${safeSn}"]`);
      if (option) {
        option.dataset.projectId = value;
        if (manualDeviceSelect.value === deviceSn && manualProjectSelect) {
          const safeProjectId = escapeSelector(value);
          const exists = manualProjectSelect.querySelector(`option[value="${safeProjectId}"]`);
          manualProjectSelect.value = exists ? value : 'unassigned';
        }
      }
    }
  };

  const setVisibility = (element, visible) => {
    if (!element) {
      return;
    }
    element.hidden = !visible;
  };

  const normalizeDeviceName = (deviceName) => {
    const trimmed = String(deviceName || '').trim();
    return trimmed !== '' ? trimmed : 'Unnamed device';
  };

  const ensureManualOption = (deviceSn, deviceName) => {
    if (!manualDeviceSelect || !deviceSn) {
      return null;
    }
    const safeSn = escapeSelector(deviceSn);
    const existing = manualDeviceSelect.querySelector(`option[value="${safeSn}"]`);
    const rawName = String(deviceName || '').trim();
    const nameLabel = normalizeDeviceName(rawName);
    const optionLabel = `${deviceSn} - ${nameLabel}`;
    if (existing) {
      existing.dataset.deviceName = rawName;
      if (!existing.dataset.projectId) {
        existing.dataset.projectId = 'unassigned';
      }
      existing.textContent = optionLabel;
      return existing;
    }
    const option = document.createElement('option');
    option.value = deviceSn;
    option.dataset.deviceName = rawName;
    option.dataset.projectId = 'unassigned';
    option.textContent = optionLabel;
    manualDeviceSelect.appendChild(option);
    return option;
  };

  const ensureDeviceCard = (deviceSn, deviceName) => {
    if (!deviceSn) {
      return null;
    }
    const safeSn = escapeSelector(deviceSn);
    const existing = document.querySelector(`.device-card[data-device-sn="${safeSn}"]`);
    const rawName = String(deviceName || '').trim();
    const nameLabel = normalizeDeviceName(rawName);
    const searchText = normalizeText(`${deviceSn} ${rawName}`);

    if (existing) {
      existing.dataset.deviceName = rawName;
      existing.dataset.deviceSearch = searchText;
      const nameEl = existing.querySelector('.device-name');
      if (nameEl) {
        nameEl.textContent = nameLabel;
      }
      return existing;
    }

    const card = document.createElement('div');
    card.className = 'device-card';
    card.dataset.deviceSn = deviceSn;
    card.dataset.deviceName = rawName;
    card.dataset.deviceSearch = searchText;

    const codeEl = document.createElement('div');
    codeEl.className = 'device-code';
    codeEl.textContent = deviceSn;

    const nameEl = document.createElement('div');
    nameEl.className = 'device-name';
    nameEl.textContent = nameLabel;

    card.appendChild(codeEl);
    card.appendChild(nameEl);
    return card;
  };

  const showManualMapping = () => {
    setVisibility(manualMappingForm, true);
    setVisibility(manualMappingHint, true);
    setVisibility(manualEmptyMessage, false);
  };

  let layoutHandle = null;

  const parseSize = (value, fallback) => {
    const raw = String(value || '').trim();
    const numeric = parseFloat(raw);
    if (!Number.isFinite(numeric)) {
      return fallback;
    }
    if (raw.endsWith('rem')) {
      const rootSize = parseFloat(getComputedStyle(document.documentElement).fontSize || '16');
      return numeric * (Number.isFinite(rootSize) ? rootSize : 16);
    }
    return numeric;
  };

  const getBoardMetrics = () => {
    if (!boardEl) {
      return { colWidth: 200, colGap: 8 };
    }
    const styles = getComputedStyle(boardEl);
    return {
      colWidth: parseSize(styles.getPropertyValue('--lane-col-width'), 200),
      colGap: parseSize(styles.getPropertyValue('--lane-col-gap'), 8),
    };
  };

  const createBoardColumns = (count) => {
    const columns = [];
    for (let i = 0; i < count; i += 1) {
      const column = document.createElement('div');
      column.className = 'device-board-col';
      columns.push(column);
      boardEl.appendChild(column);
    }
    return columns;
  };

  const layoutBoard = () => {
    if (!boardEl || boardEl.classList.contains('is-dragging')) {
      return;
    }
    const lanes = Array.from(boardEl.querySelectorAll('.device-lane'));
    if (!lanes.length) {
      return;
    }
    const { colWidth, colGap } = getBoardMetrics();
    const boardWidth = boardEl.clientWidth || 0;
    const columnCount = Math.max(1, Math.floor((boardWidth + colGap) / (colWidth + colGap)));

    lanes.forEach((lane) => lane.remove());
    boardEl.querySelectorAll('.device-board-col').forEach((column) => column.remove());

    const columns = createBoardColumns(columnCount);
    const heights = Array(columnCount).fill(0);

    lanes.forEach((lane) => {
      let targetIndex = 0;
      for (let i = 1; i < columnCount; i += 1) {
        if (heights[i] < heights[targetIndex]) {
          targetIndex = i;
        }
      }
      const column = columns[targetIndex];
      column.appendChild(lane);
      heights[targetIndex] = column.offsetHeight;
    });
  };

  const scheduleLayout = () => {
    if (!boardEl) {
      return;
    }
    if (layoutHandle) {
      cancelAnimationFrame(layoutHandle);
    }
    layoutHandle = requestAnimationFrame(() => {
      layoutHandle = null;
      layoutBoard();
    });
  };

  const updateSummary = (filters) => {
    if (!summaryEl) {
      return;
    }
    const totalProjects = Math.max(0, document.querySelectorAll('.device-lane').length - 1);
    const totalDevices = document.querySelectorAll('.device-card').length;
    const unassignedLane = document.querySelector('.device-lane[data-project-id=\"unassigned\"] .device-list');
    const unassignedDevices = unassignedLane ? unassignedLane.querySelectorAll('.device-card').length : 0;
    const hasFilter = filters.deviceTerm !== '' || filters.projectTerm !== '' || filters.hideEmpty;
    if (!hasFilter) {
      summaryEl.textContent = `Projects: ${totalProjects} | Devices: ${totalDevices} | Unassigned: ${unassignedDevices}`;
      return;
    }
    summaryEl.textContent = `Showing ${filters.visibleProjects} projects | ${filters.visibleDevices} devices (of ${totalDevices})`;
  };

  const applyFilters = (options = {}) => {
    const shouldLayout = options.layout !== false;
    const deviceTerm = normalizeText(deviceSearchInput ? deviceSearchInput.value : '');
    const projectTerm = normalizeText(projectSearchInput ? projectSearchInput.value : '');
    const hideEmpty = toggleEmptyInput ? toggleEmptyInput.checked : false;
    const lanes = Array.from(document.querySelectorAll('.device-lane'));
    let visibleProjects = 0;
    let visibleDevices = 0;

    lanes.forEach((lane) => {
      const label = normalizeText(lane.dataset.projectLabel || '');
      const projectMatch = projectTerm === '' || label.includes(projectTerm);
      const list = lane.querySelector('.device-list');
      const cards = list ? Array.from(list.querySelectorAll('.device-card')) : [];
      let hasVisible = false;

      cards.forEach((card) => {
        const search = normalizeText(card.dataset.deviceSearch || '');
        const match = deviceTerm === '' || search.includes(deviceTerm);
        card.classList.toggle('is-hidden', !match);
        if (match) {
          hasVisible = true;
        }
      });

      const hasAny = cards.length > 0;
      const showByDevice = deviceTerm === '' ? true : hasVisible;
      const showByEmpty = hideEmpty ? hasAny : true;
      const shouldShow = projectMatch && showByDevice && showByEmpty;
      lane.classList.toggle('is-filtered-out', !shouldShow);

      if (list) {
        const emptyEl = list.querySelector('.device-empty');
        if (emptyEl) {
          if (deviceTerm !== '' && !hasVisible) {
            list.classList.add('has-filter');
            emptyEl.textContent = 'No matching devices';
          } else {
            list.classList.remove('has-filter');
            emptyEl.textContent = emptyEl.dataset.emptyDefault || 'Drop devices here';
          }
        }
      }

      if (shouldShow) {
        if ((lane.dataset.projectId || '') !== 'unassigned') {
          visibleProjects += 1;
        }
        if (deviceTerm === '') {
          visibleDevices += cards.length;
        } else {
          visibleDevices += cards.filter((card) => !card.classList.contains('is-hidden')).length;
        }
      }
    });

    updateSummary({
      deviceTerm,
      projectTerm,
      hideEmpty,
      visibleProjects,
      visibleDevices,
    });
    if (shouldLayout) {
      scheduleLayout();
    }
  };

  const syncManualSelection = () => {
    if (!manualDeviceSelect || !manualProjectSelect) {
      return;
    }
    const option = manualDeviceSelect.options[manualDeviceSelect.selectedIndex];
    if (!option) {
      return;
    }
    const projectId = option.dataset.projectId || 'unassigned';
    const safeProjectId = escapeSelector(projectId);
    const exists = manualProjectSelect.querySelector(`option[value="${safeProjectId}"]`);
    manualProjectSelect.value = exists ? projectId : 'unassigned';
  };

  const filterOnboardTable = () => {
    if (!onboardTable) {
      return;
    }
    const term = normalizeText(onboardSearchInput ? onboardSearchInput.value : '');
    const rows = Array.from(onboardTable.querySelectorAll('tbody tr'));
    let visible = 0;
    let selected = 0;

    rows.forEach((row) => {
      const search = normalizeText(row.dataset.deviceSearch || '');
      const match = term === '' || search.includes(term);
      row.classList.toggle('is-hidden', !match);
      if (match) {
        visible += 1;
        const checkbox = row.querySelector('.onboard-checkbox');
        if (checkbox && checkbox.checked) {
          selected += 1;
        }
      }
    });

    if (onboardSummary) {
      onboardSummary.textContent = `Showing ${visible} of ${rows.length} | Selected ${selected}`;
    }
  };

  if (fullscreenToggleBtn) {
    fullscreenToggleBtn.addEventListener('click', toggleFullscreen);
    fullscreenToggleBtn.setAttribute('aria-pressed', 'false');
  }

  document.addEventListener('fullscreenchange', () => {
    cssFullscreen = false;
    updateFullscreenState();
  });

  const updateLaneToggle = (lane) => {
    const toggle = lane.querySelector('.lane-toggle');
    if (!toggle) {
      return;
    }
    const isCollapsed = lane.classList.contains('is-collapsed');
    const icon = toggle.querySelector('i');
    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    toggle.setAttribute('title', isCollapsed ? 'Expand lane' : 'Collapse lane');
    if (icon) {
      icon.classList.toggle('fa-chevron-up', !isCollapsed);
      icon.classList.toggle('fa-chevron-down', isCollapsed);
    }
  };

  const setAllLanesCollapsed = (collapsed) => {
    document.querySelectorAll('.device-lane').forEach((lane) => {
      lane.classList.toggle('is-collapsed', collapsed);
      updateLaneToggle(lane);
    });
  };

  const initControls = () => {
    if (controlsReady) {
      return;
    }
    controlsReady = true;

    if (deviceSearchInput) {
      deviceSearchInput.addEventListener('input', applyFilters);
    }
    if (projectSearchInput) {
      projectSearchInput.addEventListener('input', applyFilters);
    }
    if (toggleEmptyInput) {
      toggleEmptyInput.addEventListener('change', applyFilters);
    }
    if (resetFiltersBtn) {
      resetFiltersBtn.addEventListener('click', () => {
        if (deviceSearchInput) {
          deviceSearchInput.value = '';
        }
        if (projectSearchInput) {
          projectSearchInput.value = '';
        }
        if (toggleEmptyInput) {
          toggleEmptyInput.checked = false;
        }
        applyFilters();
      });
    }
    if (collapseAllBtn) {
      collapseAllBtn.addEventListener('click', () => {
        setAllLanesCollapsed(true);
        applyFilters();
      });
    }
    if (expandAllBtn) {
      expandAllBtn.addEventListener('click', () => {
        setAllLanesCollapsed(false);
        applyFilters();
      });
    }

    if (manualDeviceSelect) {
      manualDeviceSelect.addEventListener('change', syncManualSelection);
      syncManualSelection();
    }
    if (manualApplyBtn) {
      manualApplyBtn.addEventListener('click', () => {
        if (!manualDeviceSelect || !manualProjectSelect) {
          return;
        }
        const deviceSn = manualDeviceSelect.value || '';
        const option = manualDeviceSelect.options[manualDeviceSelect.selectedIndex];
        const deviceName = option ? (option.dataset.deviceName || '') : '';
        const nextProjectId = manualProjectSelect.value || 'unassigned';
        const prevProjectId = option ? (option.dataset.projectId || 'unassigned') : 'unassigned';

        if (deviceSn === '' || nextProjectId === prevProjectId) {
          return;
        }

        const jq = window.jQuery;
        if (!jq || !jq.ajax) {
          setStatus('Mapping save failed. Please try again.', 'error');
          return;
        }

        manualApplyBtn.disabled = true;
        const safeSn = escapeSelector(deviceSn);
        const $item = jq(`.device-card[data-device-sn="${safeSn}"]`);
        const $fromList = $item && $item.length ? $item.closest('.device-list') : null;
        const $toList = jq(`.device-list[data-project-id="${escapeSelector(nextProjectId)}"]`);

        if ($item && $item.length && $toList && $toList.length) {
          $item.detach().appendTo($toList);
        }

        const request = saveMapping($item, {
          ajax: 1,
          action: 'update-mapping',
          deviceSn: deviceSn,
          deviceName: deviceName,
          projectId: nextProjectId,
          csrf: csrfToken,
        }, $fromList, $toList);

        if (request && request.always) {
          request.always(() => {
            manualApplyBtn.disabled = false;
          });
        } else {
          manualApplyBtn.disabled = false;
        }
      });
    }
    if (onboardSearchInput) {
      onboardSearchInput.addEventListener('input', filterOnboardTable);
    }
    if (onboardSelectAll) {
      onboardSelectAll.addEventListener('change', () => {
        const rows = onboardTable ? Array.from(onboardTable.querySelectorAll('tbody tr')) : [];
        rows.forEach((row) => {
          if (row.classList.contains('is-hidden')) {
            return;
          }
          const checkbox = row.querySelector('.onboard-checkbox');
          if (checkbox) {
            checkbox.checked = onboardSelectAll.checked;
          }
        });
        filterOnboardTable();
      });
    }
    if (onboardTable) {
      onboardTable.addEventListener('change', (event) => {
        const target = event.target;
        if (target && target.classList.contains('onboard-checkbox')) {
          filterOnboardTable();
        }
      });
    }
    if (onboardSubmitBtn) {
      onboardSubmitBtn.addEventListener('click', () => {
        if (!onboardTable) {
          return;
        }
        const rows = Array.from(onboardTable.querySelectorAll('tbody tr'));
        const selected = rows.filter((row) => {
          const checkbox = row.querySelector('.onboard-checkbox');
          return checkbox && checkbox.checked;
        });
        if (!selected.length) {
          setStatus('Select devices to onboard.', 'error');
          return;
        }
        const devices = selected.map((row) => {
          return {
            deviceSn: row.dataset.deviceSn || '',
            deviceName: row.dataset.deviceName || '',
          };
        }).filter((row) => row.deviceSn !== '');

        if (!devices.length) {
          setStatus('Select devices to onboard.', 'error');
          return;
        }

        const jq = window.jQuery;
        if (!jq || !jq.ajax) {
          setStatus('Onboarding failed. Please try again.', 'error');
          return;
        }

        onboardSubmitBtn.disabled = true;
        setStatus('Onboarding devices...', 'loading');
        jq.ajax({
          url: baseUrl,
          method: 'POST',
          dataType: 'json',
          data: {
            ajax: 1,
            action: 'onboard-devices',
            csrf: csrfToken,
            devices: JSON.stringify(devices),
          },
        })
          .done((response) => {
            if (response && response.ok) {
              const inserted = Number(response.inserted || 0);
              const message = inserted
                ? `Onboarded ${inserted} device(s).`
                : 'Devices already onboarded.';
              setStatus(message, 'success');
              const unassignedList = document.querySelector('.device-list[data-project-id="unassigned"]');
              const $unassignedList = jq && unassignedList ? jq(unassignedList) : null;

              selected.forEach((row) => {
                const deviceSn = row.dataset.deviceSn || '';
                const deviceName = row.dataset.deviceName || '';
                if (deviceSn === '') {
                  row.remove();
                  return;
                }
                const card = ensureDeviceCard(deviceSn, deviceName);
                if (card && unassignedList && !card.closest('.device-list')) {
                  unassignedList.appendChild(card);
                }
                ensureManualOption(deviceSn, deviceName);
                row.remove();
              });

              if ($unassignedList) {
                refreshLaneState($unassignedList);
              }
              showManualMapping();
              if (manualDeviceSelect && manualDeviceSelect.options.length && manualDeviceSelect.selectedIndex < 0) {
                manualDeviceSelect.selectedIndex = 0;
              }
              syncManualSelection();
              applyFilters({ layout: false });
              filterOnboardTable();

              if (onboardSelectAll) {
                onboardSelectAll.checked = false;
                onboardSelectAll.indeterminate = false;
              }
              if (onboardTable) {
                const remainingRows = onboardTable.querySelectorAll('tbody tr').length;
                const hasRows = remainingRows > 0;
                setVisibility(onboardControls, hasRows);
                setVisibility(onboardEmptyMessage, !hasRows);
              }
            } else {
              const message = response && response.message ? response.message : 'Unable to onboard devices.';
              setStatus(message, 'error');
            }
          })
          .fail(() => {
            setStatus('Unable to onboard devices.', 'error');
          })
          .always(() => {
            onboardSubmitBtn.disabled = false;
          });
      });
    }

    document.addEventListener('click', (event) => {
      const toggle = event.target.closest('.lane-toggle');
      if (!toggle) {
        return;
      }
      const lane = toggle.closest('.device-lane');
      if (!lane) {
        return;
      }
      lane.classList.toggle('is-collapsed');
      updateLaneToggle(lane);
      applyFilters();
    });

    applyFilters();
    filterOnboardTable();

    window.addEventListener('resize', scheduleLayout);
  };

  const refreshLaneState = ($list) => {
    if (!$list || !$list.length) {
      return;
    }
    const count = $list.children('.device-card').length;
    $list.toggleClass('is-empty', count === 0);
    const $countEl = $list.closest('.device-lane').find('.device-count');
    $countEl.text(count);
    $countEl.attr('data-total-count', count);
  };

  const saveMapping = ($item, payload, $fromList, $toList) => {
    const jq = window.jQuery;
    if (!jq || !jq.ajax) {
      setStatus('Mapping save failed. Please try again.', 'error');
      return null;
    }
    const hasItem = $item && $item.length;
    const deviceSn = hasItem ? $item.data('device-sn') : (payload.deviceSn || '');
    const fromProjectId = $fromList && $fromList.length ? ($fromList.data('project-id') || 'unassigned') : 'unassigned';
    const toProjectId = $toList && $toList.length ? ($toList.data('project-id') || 'unassigned') : (payload.projectId || 'unassigned');

    if (hasItem) {
      $item.addClass('is-saving');
    }
    refreshLaneState($fromList);
    refreshLaneState($toList);
    setStatus('Saving mapping...', 'loading');
    const request = jq.ajax({
      url: baseUrl,
      method: 'POST',
      dataType: 'json',
      data: payload,
    })
      .done((response) => {
        if (response && response.ok) {
          setStatus('Mapping saved.', 'success');
          refreshLaneState($fromList);
          refreshLaneState($toList);
          updateSelectForDevice(deviceSn, toProjectId);
        } else {
          const message = response && response.message ? response.message : 'Unable to save mapping.';
          setStatus(message, 'error');
          if (hasItem && $fromList && $fromList.length) {
            $item.detach().appendTo($fromList);
            refreshLaneState($fromList);
            refreshLaneState($toList);
          }
          updateSelectForDevice(deviceSn, fromProjectId);
        }
      })
      .fail(() => {
        setStatus('Unable to save mapping.', 'error');
        if (hasItem && $fromList && $fromList.length) {
          $item.detach().appendTo($fromList);
          refreshLaneState($fromList);
          refreshLaneState($toList);
        }
        updateSelectForDevice(deviceSn, fromProjectId);
      })
      .always(() => {
        if (hasItem) {
          $item.removeClass('is-saving');
        }
        applyFilters({ layout: false });
      });

    return request;
  };

  const initBoard = () => {
    const jq = window.jQuery;
    if (!jq || !jq.fn || !jq.fn.sortable) {
      return false;
    }
    const $lists = jq('.device-list');
    if (!$lists.length) {
      return true;
    }
    $lists.sortable({
      connectWith: '.device-list',
      items: '.device-card',
      placeholder: 'device-placeholder',
      tolerance: 'pointer',
      start: function (event, ui) {
        if (boardEl) {
          boardEl.classList.add('is-dragging');
        }
        ui.placeholder.height(ui.item.outerHeight());
      },
      stop: function () {
        if (boardEl) {
          boardEl.classList.remove('is-dragging');
        }
        jq('.device-lane').removeClass('is-drop-target');
      },
      over: function () {
        jq(this).closest('.device-lane').addClass('is-drop-target');
      },
      out: function () {
        jq(this).closest('.device-lane').removeClass('is-drop-target');
      },
      receive: function (event, ui) {
        const $item = jq(ui.item);
        const $fromList = jq(ui.sender);
        const $toList = jq(this);
        const deviceSn = $item.data('device-sn');
        const deviceName = $item.data('device-name') || '';
        const projectId = $toList.data('project-id') || '';
        const prevProjectId = $fromList.data('project-id') || '';

        if (projectId === prevProjectId) {
          return;
        }

        saveMapping($item, {
          ajax: 1,
          action: 'update-mapping',
          deviceSn: deviceSn,
          deviceName: deviceName,
          projectId: projectId,
          csrf: csrfToken,
        }, $fromList, $toList);
      },
    }).disableSelection();
    return true;
  };

  const waitForBoard = () => {
    initControls();
    if (!initBoard()) {
      setTimeout(waitForBoard, 120);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', waitForBoard);
  } else {
    waitForBoard();
  }
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
