<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'Camp Boss Dashboard';
$flash = get_flash();

function cb_dashboard_normalize_date(?string $value, string $fallback): string {
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function cb_dashboard_normalize_multi($value): array {
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = [$value];
    }
    $clean = [];
    foreach ($items as $item) {
        if (!is_scalar($item) && $item !== null) {
            continue;
        }
        $item = strtoupper(trim((string) $item));
        if ($item === '') {
            continue;
        }
        $clean[$item] = true;
    }
    return array_keys($clean);
}

function cb_dashboard_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function cb_dashboard_load_camps(mysqli $bd, string $userId): array {
    if ($userId === '') {
        return [];
    }
    $camps = [];
    $stmt = $bd->prepare(
        'SELECT camp_code FROM gcc_attendance_master.campboss_camp_map WHERE user_id = ? ORDER BY camp_code'
    );
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = strtoupper(trim((string) ($row['camp_code'] ?? '')));
                    if ($code !== '') {
                        $camps[] = $code;
                    }
                }
                $result->free();
            }
        }
        $stmt->close();
    }
    return $camps;
}

function cb_dashboard_empty_kpis(): array {
    return [
        'total' => 0,
        'pending' => 0,
        'reviewed' => 0,
        'escalated' => 0,
    ];
}

function cb_dashboard_fetch_summary(
    mysqli $bd,
    string $selectedDate,
    bool $isAdminViewer,
    array $mappedCamps,
    array $campFilter
): array {
    $campSummary = [];
    $kpis = cb_dashboard_empty_kpis();
    $effectiveCampExpr = 'COALESCE(NULLIF(r.transfer_to_camp_code, ""), NULLIF(m.emp_camp_loc, ""))';

    $sql = 'SELECT ' . $effectiveCampExpr . ' AS camp_code, ' .
        'COALESCE(NULLIF(tc.camp_name, ""), NULLIF(c.camp_name, "")) AS camp_name, ' .
        'CASE ' .
        'WHEN COALESCE(r.is_escalated, 0) = 1 THEN "escalated" ' .
        'WHEN TRIM(COALESCE(r.campboss_reviewed_at, "")) <> "" OR TRIM(COALESCE(r.campboss_reason_code, "")) <> "" THEN "reviewed" ' .
        'ELSE "pending" END AS status_key, ' .
        'COUNT(*) AS total_rows ' .
        'FROM gcc_attendance_master.attendance_no_punch_reviews r ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ON h.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'LEFT JOIN gcc_attendance_master.hrms_hrmemp_camp_mapping m ON m.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci AND m.is_deleted = 0 ' .
        'LEFT JOIN gcc_attendance_master.hrms_camp_sync c ON c.camp_code COLLATE utf8mb4_general_ci = m.emp_camp_loc COLLATE utf8mb4_general_ci AND c.is_deleted = 0 ' .
        'LEFT JOIN gcc_attendance_master.hrms_camp_sync tc ON tc.camp_code COLLATE utf8mb4_general_ci = r.transfer_to_camp_code COLLATE utf8mb4_general_ci AND tc.is_deleted = 0 ' .
        'WHERE r.att_date = ? AND h.is_deleted = 0 AND h.st_code = "A"';
    $params = [$selectedDate];
    $types = 's';

    if (!$isAdminViewer && !empty($mappedCamps)) {
        $sql .= ' AND ' . $effectiveCampExpr . ' IN (' . implode(',', array_fill(0, count($mappedCamps), '?')) . ')';
        $params = array_merge($params, $mappedCamps);
        $types .= str_repeat('s', count($mappedCamps));
    }
    if (!empty($campFilter)) {
        $sql .= ' AND ' . $effectiveCampExpr . ' IN (' . implode(',', array_fill(0, count($campFilter), '?')) . ')';
        $params = array_merge($params, $campFilter);
        $types .= str_repeat('s', count($campFilter));
    }

    $sql .= ' GROUP BY camp_code, camp_name, status_key ORDER BY camp_code ASC';
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return [
            'campSummary' => $campSummary,
            'kpis' => $kpis,
            'error' => 'Unable to prepare dashboard summary query.',
        ];
    }

    cb_dashboard_bind_params($stmt, $types, $params);
    if (!$stmt->execute()) {
        $stmt->close();
        return [
            'campSummary' => $campSummary,
            'kpis' => $kpis,
            'error' => 'Unable to load dashboard summary.',
        ];
    }

    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $campCode = strtoupper(trim((string) ($row['camp_code'] ?? '')));
            $campName = trim((string) ($row['camp_name'] ?? ''));
            $statusKey = strtolower(trim((string) ($row['status_key'] ?? '')));
            $count = (int) ($row['total_rows'] ?? 0);
            $summaryKey = $campCode !== '' ? $campCode : '__UNMAPPED__';
            if (!isset($campSummary[$summaryKey])) {
                $campSummary[$summaryKey] = [
                    'camp_code' => $campCode,
                    'camp_name' => $campName,
                    'pending' => 0,
                    'reviewed' => 0,
                    'escalated' => 0,
                    'total' => 0,
                ];
            }
            if (!isset($campSummary[$summaryKey][$statusKey])) {
                continue;
            }
            $campSummary[$summaryKey][$statusKey] += $count;
            $campSummary[$summaryKey]['total'] += $count;
            $kpis[$statusKey] += $count;
            $kpis['total'] += $count;
        }
        $result->free();
    }
    $stmt->close();

    uasort($campSummary, static function (array $a, array $b): int {
        return strcmp((string) $a['camp_code'], (string) $b['camp_code']);
    });

    return [
        'campSummary' => $campSummary,
        'kpis' => $kpis,
        'error' => null,
    ];
}

function cb_dashboard_find_latest_data_date(
    mysqli $bd,
    bool $isAdminViewer,
    array $mappedCamps,
    array $campFilter
): ?string {
    $effectiveCampExpr = 'COALESCE(NULLIF(r.transfer_to_camp_code, ""), NULLIF(m.emp_camp_loc, ""))';
    $sql = 'SELECT MAX(r.att_date) AS latest_date ' .
        'FROM gcc_attendance_master.attendance_no_punch_reviews r ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ON h.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'LEFT JOIN gcc_attendance_master.hrms_hrmemp_camp_mapping m ON m.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci AND m.is_deleted = 0 ' .
        'WHERE h.is_deleted = 0 AND h.st_code = "A"';
    $params = [];
    $types = '';

    if (!$isAdminViewer && !empty($mappedCamps)) {
        $sql .= ' AND ' . $effectiveCampExpr . ' IN (' . implode(',', array_fill(0, count($mappedCamps), '?')) . ')';
        $params = array_merge($params, $mappedCamps);
        $types .= str_repeat('s', count($mappedCamps));
    }
    if (!empty($campFilter)) {
        $sql .= ' AND ' . $effectiveCampExpr . ' IN (' . implode(',', array_fill(0, count($campFilter), '?')) . ')';
        $params = array_merge($params, $campFilter);
        $types .= str_repeat('s', count($campFilter));
    }

    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return null;
    }

    cb_dashboard_bind_params($stmt, $types, $params);
    $latestDate = null;
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $row = $result->fetch_assoc();
            $candidate = trim((string) ($row['latest_date'] ?? ''));
            if ($candidate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
                $latestDate = $candidate;
            }
            $result->free();
        }
    }
    $stmt->close();

    return $latestDate;
}

function cb_dashboard_review_url(string $selectedDate, array $campCodes, string $reviewStatus = ''): string {
    $params = ['date' => $selectedDate];
    if (!empty($campCodes)) {
        $params['camp_code'] = $campCodes;
    }
    if ($reviewStatus !== '') {
        $params['review_status'] = $reviewStatus;
    }
    return admin_url('campboss_attendance_view_no_punch.php') . '?' . http_build_query($params);
}

$uaeTz = new DateTimeZone('Asia/Dubai');
$todayUae = (new DateTimeImmutable('now', $uaeTz))->format('Y-m-d');
$rawDate = trim((string) ($_GET['date'] ?? ''));
$hasExplicitDate = ($rawDate !== '');
$selectedDate = cb_dashboard_normalize_date($rawDate, $todayUae);
$campFilter = cb_dashboard_normalize_multi($_GET['camp_code'] ?? []);

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userRole = strtolower(trim((string) ($_SESSION['user_role'] ?? ($_SESSION['usr_type'] ?? ''))));
$isAdminViewer = in_array($userRole, ['admin', 'manager'], true);

$loadError = null;
$mappingRequired = false;
$mappedCamps = [];
$campOptions = [];
$filterCampOptions = [];
$campSummary = [];
$kpis = cb_dashboard_empty_kpis();
$fallbackNotice = null;

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!ensure_campboss_camp_map_table($bd)) {
        $loadError = 'Unable to load camp mapping configuration.';
    } else {
        $mappedCamps = cb_dashboard_load_camps($bd, $userId);
        if (empty($mappedCamps) && !$isAdminViewer) {
            $mappingRequired = true;
        }
    }

    $campResult = $bd->query(
        'SELECT camp_code, camp_name FROM gcc_attendance_master.hrms_camp_sync WHERE is_deleted = 0 ORDER BY camp_code'
    );
    if ($campResult) {
        while ($row = $campResult->fetch_assoc()) {
            $code = strtoupper(trim((string) ($row['camp_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $campOptions[$code] = trim((string) ($row['camp_name'] ?? ''));
        }
        $campResult->free();
    }
}

if (!$loadError && !$mappingRequired) {
    if (!$isAdminViewer && !empty($mappedCamps)) {
        $campFilter = array_values(array_intersect($campFilter, $mappedCamps));
        $mappedSet = array_fill_keys($mappedCamps, true);
        $filterCampOptions = array_intersect_key($campOptions, $mappedSet);
    } else {
        $filterCampOptions = $campOptions;
    }

    $summary = cb_dashboard_fetch_summary($bd, $selectedDate, $isAdminViewer, $mappedCamps, $campFilter);
    $campSummary = $summary['campSummary'];
    $kpis = $summary['kpis'];
    $loadError = $summary['error'];

    if (!$loadError && !$hasExplicitDate && (int) ($kpis['total'] ?? 0) === 0) {
        $latestDate = cb_dashboard_find_latest_data_date($bd, $isAdminViewer, $mappedCamps, $campFilter);
        if ($latestDate !== null && $latestDate !== $selectedDate) {
            $fallbackFromDate = $selectedDate;
            $selectedDate = $latestDate;
            $summary = cb_dashboard_fetch_summary($bd, $selectedDate, $isAdminViewer, $mappedCamps, $campFilter);
            $campSummary = $summary['campSummary'];
            $kpis = $summary['kpis'];
            $loadError = $summary['error'];
            if (!$loadError) {
                $fallbackNotice = 'No records found for ' . $fallbackFromDate . '. Showing latest available date ' . $selectedDate . '.';
            }
        }
    }
}

include dirname(__DIR__) . '/admin/include/layout_top.php';

?>

<style>
  .cb-db-card {
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
  }
  .cb-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
  }
  .cb-kpi {
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(140deg, rgba(241, 245, 249, 0.88), rgba(226, 232, 240, 0.7));
    padding: 14px;
  }
  .cb-kpi .kpi-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #334155;
    margin-bottom: 6px;
    font-weight: 700;
  }
  .cb-kpi .kpi-value {
    font-size: 1.8rem;
    line-height: 1;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 10px;
  }
  .cb-summary-table th,
  .cb-summary-table td {
    vertical-align: middle;
  }
  .cb-summary-table td:not(:first-child),
  .cb-summary-table th:not(:first-child) {
    text-align: center;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-7">
        <h1>Camp Boss Dashboard</h1>
      </div>
      <div class="col-sm-5 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'campboss'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> mb-3"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php endif; ?>
    <?php if (!$loadError && $fallbackNotice): ?>
      <div class="alert alert-info mb-3"><?= h($fallbackNotice) ?></div>
    <?php endif; ?>

    <?php if ($mappingRequired): ?>
      <div class="card cb-db-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Camp access needed</h3>
        </div>
        <div class="card-body">
          <p class="text-muted mb-0">No mapped camps found for your user. Contact HR/Admin to assign camp access.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="card cb-db-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Filters</h3>
        </div>
        <div class="card-body">
          <form method="get" class="form-row">
            <div class="form-group col-md-3">
              <label for="date">Date (UAE)</label>
              <input id="date" type="date" name="date" class="form-control" value="<?= h($selectedDate) ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="camp_code">Camp</label>
              <select id="camp_code" name="camp_code[]" class="form-control js-searchable" multiple data-placeholder="All mapped camps">
                <?php foreach ($filterCampOptions as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>" <?= in_array($code, $campFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card cb-db-card mb-3">
        <div class="card-header">
          <h3 class="card-title">No Punch Review Summary</h3>
        </div>
        <div class="card-body">
          <?php $quickCampScope = !empty($campFilter) ? $campFilter : []; ?>
          <div class="cb-kpi-grid">
            <div class="cb-kpi">
              <div class="kpi-label">Total Queue</div>
              <div class="kpi-value"><?= h((string) $kpis['total']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(cb_dashboard_review_url($selectedDate, $quickCampScope)) ?>">Open queue</a>
            </div>
            <div class="cb-kpi">
              <div class="kpi-label">Pending</div>
              <div class="kpi-value"><?= h((string) $kpis['pending']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(cb_dashboard_review_url($selectedDate, $quickCampScope, 'pending')) ?>">Quick action</a>
            </div>
            <div class="cb-kpi">
              <div class="kpi-label">Reviewed</div>
              <div class="kpi-value"><?= h((string) $kpis['reviewed']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(cb_dashboard_review_url($selectedDate, $quickCampScope, 'reviewed')) ?>">Quick action</a>
            </div>
            <div class="cb-kpi">
              <div class="kpi-label">Escalated</div>
              <div class="kpi-value"><?= h((string) $kpis['escalated']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(cb_dashboard_review_url($selectedDate, $quickCampScope, 'escalated')) ?>">Quick action</a>
            </div>
          </div>
        </div>
      </div>

      <div class="card cb-db-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">Category-wise Pending Summary (Camp + Status)</h3>
          <span class="small text-muted"><?= h(count($campSummary)) ?> camp(s)</span>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-bordered table-sm cb-summary-table mb-0">
            <thead>
              <tr>
                <th>Camp</th>
                <th>Pending</th>
                <th>Reviewed</th>
                <th>Escalated</th>
                <th>Total</th>
                <th>Quick Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($campSummary)): ?>
                <tr>
                  <td colspan="6" class="text-center text-muted p-4">No camp boss review records found for selected filters.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($campSummary as $summary): ?>
                  <?php
                    $campCode = (string) ($summary['camp_code'] ?? '');
                    $campName = trim((string) ($summary['camp_name'] ?? ''));
                    $campScope = $campCode !== '' ? [$campCode] : [];
                    $campLabel = $campCode !== '' ? $campCode : 'Unmapped';
                    if ($campCode !== '' && $campName !== '') {
                        $campLabel .= ' - ' . $campName;
                    }
                  ?>
                  <tr>
                    <td><?= h($campLabel) ?></td>
                    <td>
                      <?php if (!empty($campScope)): ?>
                        <a href="<?= h(cb_dashboard_review_url($selectedDate, $campScope, 'pending')) ?>"><?= h((string) $summary['pending']) ?></a>
                      <?php else: ?>
                        <?= h((string) $summary['pending']) ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($campScope)): ?>
                        <a href="<?= h(cb_dashboard_review_url($selectedDate, $campScope, 'reviewed')) ?>"><?= h((string) $summary['reviewed']) ?></a>
                      <?php else: ?>
                        <?= h((string) $summary['reviewed']) ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($campScope)): ?>
                        <a href="<?= h(cb_dashboard_review_url($selectedDate, $campScope, 'escalated')) ?>"><?= h((string) $summary['escalated']) ?></a>
                      <?php else: ?>
                        <?= h((string) $summary['escalated']) ?>
                      <?php endif; ?>
                    </td>
                    <td><strong><?= h((string) $summary['total']) ?></strong></td>
                    <td>
                      <?php if (!empty($campScope)): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= h(cb_dashboard_review_url($selectedDate, $campScope)) ?>">Open queue</a>
                      <?php else: ?>
                        <span class="text-muted small">No direct camp code</span>
                      <?php endif; ?>
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

<?php include dirname(__DIR__) . '/admin/include/layout_bottom.php'; ?>
