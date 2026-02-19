<?php

declare(strict_types=1);

require __DIR__ . '/include/bootstrap.php';

$page_title = 'Camp Boss Camp Mapping';

function json_response(array $payload, int $status = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_camp_code_input($value): ?string {
    if (!is_scalar($value) && $value !== null) {
        return null;
    }
    $value = strtoupper(trim((string) $value));
    return $value === '' ? null : $value;
}

function normalize_camp_inputs($value): array {
    $items = is_array($value) ? $value : [$value];
    $codes = [];
    foreach ($items as $item) {
        $code = normalize_camp_code_input($item);
        if ($code === null) {
            continue;
        }
        $codes[$code] = true;
    }
    return array_keys($codes);
}

function load_active_camp_options(mysqli $bd): array {
    $options = [];
    $result = $bd->query(
        'SELECT camp_code, camp_name FROM gcc_attendance_master.hrms_camp_sync ' .
        'WHERE is_deleted = 0 ORDER BY camp_code'
    );
    if (!$result) {
        return $options;
    }
    while ($row = $result->fetch_assoc()) {
        $code = strtoupper(trim((string) ($row['camp_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $options[$code] = trim((string) ($row['camp_name'] ?? ''));
    }
    $result->free();
    return $options;
}

function load_active_users(mysqli $bd): array {
    $users = [];
    $result = $bd->query(
        'SELECT id, full_name, email, role, status FROM gcc_it.users WHERE status = 1 ORDER BY full_name, email'
    );
    if (!$result) {
        return $users;
    }
    while ($row = $result->fetch_assoc()) {
        $userId = trim((string) ($row['id'] ?? ''));
        if ($userId === '') {
            continue;
        }
        $users[] = [
            'id' => $userId,
            'full_name' => trim((string) ($row['full_name'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'role' => trim((string) ($row['role'] ?? '')),
            'status' => (int) ($row['status'] ?? 0),
        ];
    }
    $result->free();
    return $users;
}

function load_camp_map_by_user(mysqli $bd): array {
    $map = [];
    $result = $bd->query(
        'SELECT user_id, camp_code FROM gcc_attendance_master.campboss_camp_map ORDER BY user_id, camp_code'
    );
    if (!$result) {
        return $map;
    }
    while ($row = $result->fetch_assoc()) {
        $userId = trim((string) ($row['user_id'] ?? ''));
        $campCode = strtoupper(trim((string) ($row['camp_code'] ?? '')));
        if ($userId === '' || $campCode === '') {
            continue;
        }
        if (!isset($map[$userId])) {
            $map[$userId] = [];
        }
        $map[$userId][$campCode] = true;
    }
    $result->free();

    $normalized = [];
    foreach ($map as $userId => $campSet) {
        $normalized[$userId] = array_keys($campSet);
    }
    return $normalized;
}

$isAjax = ($_POST['ajax'] ?? '') === '1';
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

$loadError = null;
if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} elseif (!ensure_campboss_camp_map_table($bd)) {
    $loadError = 'Unable to initialize camp boss camp mapping table.';
}

if ($isAjax && $action === 'save-user-camps') {
    if ($loadError !== null) {
        json_response(['ok' => false, 'message' => $loadError], 500);
    }
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        json_response(['ok' => false, 'message' => 'Invalid request token.'], 400);
    }

    $userId = trim((string) ($_POST['userId'] ?? ''));
    if ($userId === '' || !ctype_digit($userId)) {
        json_response(['ok' => false, 'message' => 'Invalid user id.'], 400);
    }

    $userIdInt = (int) $userId;
    $userExistsStmt = $bd->prepare('SELECT id FROM gcc_it.users WHERE id = ? AND status = 1 LIMIT 1');
    if (!$userExistsStmt) {
        json_response(['ok' => false, 'message' => 'Unable to validate user.'], 500);
    }
    $userExistsStmt->bind_param('i', $userIdInt);
    $userExists = false;
    if ($userExistsStmt->execute()) {
        $result = $userExistsStmt->get_result();
        if ($result) {
            $userExists = (bool) $result->fetch_assoc();
            $result->free();
        }
    }
    $userExistsStmt->close();
    if (!$userExists) {
        json_response(['ok' => false, 'message' => 'User not found or inactive.'], 404);
    }

    $campOptions = load_active_camp_options($bd);
    $activeCampSet = array_fill_keys(array_keys($campOptions), true);

    $selectedCampCodes = normalize_camp_inputs($_POST['campCode'] ?? []);
    if ($userId === '1') {
        // Temporary business rule requested by user: id 1 must own all camps.
        $selectedCampCodes = array_keys($campOptions);
    }

    foreach ($selectedCampCodes as $campCode) {
        if (!isset($activeCampSet[$campCode])) {
            json_response(['ok' => false, 'message' => 'Invalid camp code: ' . $campCode], 400);
        }
    }

    $deleteStmt = $bd->prepare('DELETE FROM gcc_attendance_master.campboss_camp_map WHERE user_id = ?');
    $insertStmt = $bd->prepare(
        'INSERT INTO gcc_attendance_master.campboss_camp_map (user_id, camp_code) VALUES (?, ?)'
    );
    if (!$deleteStmt || !$insertStmt) {
        if ($deleteStmt) {
            $deleteStmt->close();
        }
        if ($insertStmt) {
            $insertStmt->close();
        }
        json_response(['ok' => false, 'message' => 'Unable to prepare save statement.'], 500);
    }

    try {
        $bd->begin_transaction();

        $deleteStmt->bind_param('s', $userId);
        if (!$deleteStmt->execute()) {
            throw new RuntimeException('Unable to clear current mappings.');
        }

        foreach ($selectedCampCodes as $campCode) {
            $insertStmt->bind_param('ss', $userId, $campCode);
            if (!$insertStmt->execute()) {
                throw new RuntimeException('Unable to save camp mapping.');
            }
        }

        $bd->commit();
    } catch (Throwable $e) {
        $bd->rollback();
        $deleteStmt->close();
        $insertStmt->close();
        json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }

    $deleteStmt->close();
    $insertStmt->close();

    $mappedRows = [];
    foreach ($selectedCampCodes as $campCode) {
        $mappedRows[] = [
            'campCode' => $campCode,
            'campName' => $campOptions[$campCode] ?? '',
        ];
    }

    json_response([
        'ok' => true,
        'message' => 'Saved ' . count($mappedRows) . ' camp mapping(s).',
        'userId' => $userId,
        'mapped' => $mappedRows,
    ]);
}

$flash = get_flash();
$users = [];
$campOptions = [];
$campMapByUser = [];

if ($loadError === null) {
    $users = load_active_users($bd);
    $campOptions = load_active_camp_options($bd);
    $campMapByUser = load_camp_map_by_user($bd);
}

include __DIR__ . '/include/layout_top.php';

?>

<style>
  .camp-map-card {
    border-radius: 16px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
  }
  .camp-map-table th,
  .camp-map-table td {
    vertical-align: top;
  }
  .camp-map-user {
    min-width: 220px;
  }
  .camp-map-select {
    min-width: 300px;
    min-height: 120px;
  }
  .camp-map-status {
    white-space: nowrap;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Camp Boss Camp Mapping</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($loadError !== null): ?>
      <div class="alert alert-warning"><?= h($loadError) ?></div>
    <?php else: ?>
      <div id="camp-map-alert"></div>

      <div class="card camp-map-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Search</h3>
        </div>
        <div class="card-body">
          <input id="campMapSearch" class="form-control" placeholder="Filter by user id, name, email, or role">
          <div class="small text-muted mt-2">User id <strong>1</strong> is locked to all camps by default.</div>
        </div>
      </div>

      <div class="card camp-map-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">User to Camp Mapping</h3>
          <span class="text-muted small"><?= h((string) count($users)) ?> active user(s)</span>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-bordered table-sm camp-map-table mb-0">
            <thead>
              <tr>
                <th>User</th>
                <th>Camps</th>
                <th>Action</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted p-4">No active users found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $user): ?>
                  <?php
                    $userId = (string) ($user['id'] ?? '');
                    $mappedCodes = $campMapByUser[$userId] ?? [];
                    $mappedSet = array_fill_keys($mappedCodes, true);
                    $isLockedUser = $userId === '1';
                    $searchBlob = strtolower(trim(implode(' ', [
                        $userId,
                        (string) ($user['full_name'] ?? ''),
                        (string) ($user['email'] ?? ''),
                        (string) ($user['role'] ?? ''),
                    ])));
                  ?>
                  <tr data-search="<?= h($searchBlob) ?>">
                    <td class="camp-map-user">
                      <div><strong><?= h($user['full_name'] ?? '') ?></strong></div>
                      <div class="small text-muted"><?= h($user['email'] ?? '') ?></div>
                      <div class="small text-muted">ID: <?= h($userId) ?> | Role: <?= h($user['role'] ?? '-') ?></div>
                    </td>
                    <td>
                      <select
                        class="form-control form-control-sm camp-map-select js-camp-select"
                        multiple
                        data-user-id="<?= h($userId) ?>"
                        <?= $isLockedUser ? 'disabled' : '' ?>
                      >
                        <?php foreach ($campOptions as $campCode => $campName): ?>
                          <?php $label = $campName !== '' ? ($campCode . ' - ' . $campName) : $campCode; ?>
                          <option value="<?= h($campCode) ?>" <?= isset($mappedSet[$campCode]) ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if ($isLockedUser): ?>
                        <div class="small text-muted mt-1">Locked: always mapped to all camps.</div>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <button
                        type="button"
                        class="btn btn-primary btn-sm js-save-camps"
                        data-user-id="<?= h($userId) ?>"
                        <?= $isLockedUser ? 'disabled title="Locked user"' : '' ?>
                      >Save</button>
                    </td>
                    <td class="camp-map-status">
                      <span class="badge badge-secondary js-row-status">Ready</span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($loadError === null): ?>
<script>
  (function () {
    var endpoint = <?= json_encode(admin_url('Attendance_CampbossCampMapping.php'), JSON_UNESCAPED_SLASHES) ?>;
    var csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
    var alertHost = document.getElementById('camp-map-alert');
    var searchInput = document.getElementById('campMapSearch');

    function showAlert(type, message) {
      if (!alertHost || !message) {
        return;
      }
      var div = document.createElement('div');
      div.className = 'alert alert-' + type;
      div.textContent = message;
      alertHost.innerHTML = '';
      alertHost.appendChild(div);
      setTimeout(function () {
        if (div.parentNode === alertHost) {
          alertHost.removeChild(div);
        }
      }, 4000);
    }

    function setRowStatus(row, text, klass) {
      if (!row) {
        return;
      }
      var status = row.querySelector('.js-row-status');
      if (!status) {
        return;
      }
      status.className = 'badge js-row-status ' + klass;
      status.textContent = text;
    }

    function getSelectedCodes(selectEl) {
      if (!selectEl) {
        return [];
      }
      var values = [];
      for (var i = 0; i < selectEl.options.length; i++) {
        var option = selectEl.options[i];
        if (option.selected) {
          var code = String(option.value || '').trim().toUpperCase();
          if (code !== '' && values.indexOf(code) === -1) {
            values.push(code);
          }
        }
      }
      return values;
    }

    function applyMappedCodes(selectEl, mappedRows) {
      if (!selectEl) {
        return;
      }
      var set = {};
      for (var i = 0; i < mappedRows.length; i++) {
        var code = String((mappedRows[i] && mappedRows[i].campCode) || '').trim().toUpperCase();
        if (code !== '') {
          set[code] = true;
        }
      }
      for (var j = 0; j < selectEl.options.length; j++) {
        var opt = selectEl.options[j];
        opt.selected = !!set[String(opt.value || '').trim().toUpperCase()];
      }
    }

    function saveRow(button) {
      if (!button || button.disabled) {
        return;
      }
      var userId = String(button.getAttribute('data-user-id') || '').trim();
      if (userId === '') {
        return;
      }
      var row = button.closest('tr');
      var selectEl = row ? row.querySelector('.js-camp-select') : null;
      var codes = getSelectedCodes(selectEl);

      var formData = new FormData();
      formData.append('ajax', '1');
      formData.append('action', 'save-user-camps');
      formData.append('csrf', csrf);
      formData.append('userId', userId);
      for (var i = 0; i < codes.length; i++) {
        formData.append('campCode[]', codes[i]);
      }

      button.disabled = true;
      setRowStatus(row, 'Saving...', 'badge-warning');

      fetch(endpoint, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      })
        .then(function (res) {
          return res.json().catch(function () { return {}; });
        })
        .then(function (payload) {
          if (!payload || payload.ok !== true) {
            var msg = (payload && payload.message) ? payload.message : 'Save failed.';
            setRowStatus(row, 'Failed', 'badge-danger');
            showAlert('warning', msg);
            return;
          }
          applyMappedCodes(selectEl, payload.mapped || []);
          setRowStatus(row, 'Saved', 'badge-success');
          showAlert('success', payload.message || 'Saved.');
        })
        .catch(function () {
          setRowStatus(row, 'Failed', 'badge-danger');
          showAlert('warning', 'Save failed due to a network error.');
        })
        .finally(function () {
          button.disabled = false;
        });
    }

    var saveButtons = document.querySelectorAll('.js-save-camps');
    for (var i = 0; i < saveButtons.length; i++) {
      saveButtons[i].addEventListener('click', function () {
        saveRow(this);
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        var term = String(searchInput.value || '').trim().toLowerCase();
        var rows = document.querySelectorAll('tbody tr[data-search]');
        for (var i = 0; i < rows.length; i++) {
          var row = rows[i];
          var haystack = String(row.getAttribute('data-search') || '').toLowerCase();
          var visible = term === '' || haystack.indexOf(term) !== -1;
          row.style.display = visible ? '' : 'none';
        }
      });
    }
  })();
</script>
<?php endif; ?>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
