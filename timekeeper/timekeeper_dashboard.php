<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'Timekeeper Dashboard';
$flash = get_flash();

function tk_dashboard_normalize_date(?string $value, string $fallback): string {
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

function tk_dashboard_normalize_multi($value): array {
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
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        $clean[$item] = true;
    }
    return array_keys($clean);
}

function tk_dashboard_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function tk_dashboard_ensure_mapping_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`timekeeper_project_map` (' .
        '`user_id` varchar(50) NOT NULL,' .
        '`project_code` varchar(20) NOT NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`user_id`, `project_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function tk_dashboard_load_projects(mysqli $bd, string $userId): array {
    if ($userId === '') {
        return [];
    }
    $projects = [];
    $stmt = $bd->prepare(
        'SELECT project_code FROM gcc_attendance_master.timekeeper_project_map WHERE user_id = ? ORDER BY project_code'
    );
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = trim((string) ($row['project_code'] ?? ''));
                    if ($code !== '') {
                        $projects[] = $code;
                    }
                }
                $result->free();
            }
        }
        $stmt->close();
    }
    return $projects;
}

function tk_dashboard_no_punch_url(string $selectedDate, array $projectCodes, string $campbossStatus = ''): string {
    $params = ['date' => $selectedDate];
    if (!empty($projectCodes)) {
        $params['project_code'] = $projectCodes;
    }
    if ($campbossStatus !== '') {
        $params['campboss_status'] = $campbossStatus;
    }
    return admin_url('timekeeper_attendance_view_no_punch.php') . '?' . http_build_query($params);
}

$uaeTz = new DateTimeZone('Asia/Dubai');
$todayUae = (new DateTimeImmutable('now', $uaeTz))->format('Y-m-d');
$selectedDate = tk_dashboard_normalize_date($_GET['date'] ?? '', $todayUae);
$projectFilter = tk_dashboard_normalize_multi($_GET['project_code'] ?? []);

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userRole = strtolower(trim((string) ($_SESSION['user_role'] ?? ($_SESSION['usr_type'] ?? ''))));
$isAdminViewer = in_array($userRole, ['admin', 'manager'], true);

$loadError = null;
$mappingRequired = false;
$mappedProjects = [];
$projectOptions = [];
$projectSummary = [];
$kpis = [
    'total' => 0,
    'not_submitted' => 0,
    'submitted' => 0,
    'reviewed' => 0,
    'escalated' => 0,
];

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!tk_dashboard_ensure_mapping_table($bd)) {
        $loadError = 'Unable to load project mapping configuration.';
    } else {
        $mappedProjects = tk_dashboard_load_projects($bd, $userId);
        if (empty($mappedProjects) && !$isAdminViewer) {
            $mappingRequired = true;
        }
    }

    $projectResult = $bd->query(
        'SELECT project_code, project_name FROM gcc_attendance_master.hrms_projects ORDER BY project_code'
    );
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $code = trim((string) ($row['project_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $projectOptions[$code] = trim((string) ($row['project_name'] ?? ''));
        }
        $projectResult->free();
    }
}

if (!$loadError && !$mappingRequired) {
    if (!$isAdminViewer && !empty($mappedProjects)) {
        $projectFilter = array_values(array_intersect($projectFilter, $mappedProjects));
        $mappedSet = array_fill_keys($mappedProjects, true);
        $projectOptions = array_intersect_key($projectOptions, $mappedSet);
    } else {
        $projectFilter = array_values(array_filter($projectFilter, static function ($code): bool {
            return $code !== '';
        }));
    }

    $sql = 'SELECT hr.jbno AS project_code, ' .
        'COALESCE(NULLIF(hr.jbdesc, ""), NULLIF(pj.project_name, ""), hr.jbno) AS project_name, ' .
        'CASE ' .
        'WHEN COALESCE(r.is_escalated, 0) = 1 THEN "escalated" ' .
        'WHEN TRIM(COALESCE(r.campboss_reviewed_at, "")) <> "" OR TRIM(COALESCE(r.campboss_reason_code, "")) <> "" THEN "reviewed" ' .
        'WHEN TRIM(COALESCE(r.timekeeper_submitted_at, "")) <> "" THEN "submitted" ' .
        'ELSE "not_submitted" END AS status_key, ' .
        'COUNT(*) AS total_rows ' .
        'FROM gcc_attendance_master.hrmsvw_sync hr ' .
        'LEFT JOIN gcc_attendance_master.hrms_projects pj ON pj.project_code COLLATE utf8mb4_general_ci = hr.jbno COLLATE utf8mb4_general_ci ' .
        'LEFT JOIN gcc_attendance_master.employee_daily_punch dp ON dp.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci AND dp.punch_date = ? ' .
        'LEFT JOIN gcc_attendance_master.attendance_no_punch_reviews r ON r.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci AND r.att_date = ? ' .
        'WHERE hr.is_deleted = 0 AND hr.st_code = "A" ' .
        'AND TRIM(COALESCE(dp.first_log, "")) = "" AND TRIM(COALESCE(dp.last_log, "")) = ""';
    $params = [$selectedDate, $selectedDate];
    $types = 'ss';

    if (!$isAdminViewer && !empty($mappedProjects)) {
        $sql .= ' AND hr.jbno IN (' . implode(',', array_fill(0, count($mappedProjects), '?')) . ')';
        $params = array_merge($params, $mappedProjects);
        $types .= str_repeat('s', count($mappedProjects));
    }
    if (!empty($projectFilter)) {
        $sql .= ' AND hr.jbno IN (' . implode(',', array_fill(0, count($projectFilter), '?')) . ')';
        $params = array_merge($params, $projectFilter);
        $types .= str_repeat('s', count($projectFilter));
    }

    $sql .= ' GROUP BY hr.jbno, project_name, status_key ORDER BY hr.jbno ASC';
    $stmt = $bd->prepare($sql);
    if ($stmt) {
        tk_dashboard_bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $projectCode = trim((string) ($row['project_code'] ?? ''));
                    if ($projectCode === '') {
                        $projectCode = '-';
                    }
                    $projectName = trim((string) ($row['project_name'] ?? ''));
                    $statusKey = strtolower(trim((string) ($row['status_key'] ?? '')));
                    $count = (int) ($row['total_rows'] ?? 0);
                    if (!isset($projectSummary[$projectCode])) {
                        $projectSummary[$projectCode] = [
                            'project_code' => $projectCode,
                            'project_name' => $projectName,
                            'not_submitted' => 0,
                            'submitted' => 0,
                            'reviewed' => 0,
                            'escalated' => 0,
                            'total' => 0,
                        ];
                    }
                    if (!isset($projectSummary[$projectCode][$statusKey])) {
                        continue;
                    }
                    $projectSummary[$projectCode][$statusKey] += $count;
                    $projectSummary[$projectCode]['total'] += $count;
                    $kpis[$statusKey] += $count;
                    $kpis['total'] += $count;
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load dashboard summary.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare dashboard summary query.';
    }

    uasort($projectSummary, static function (array $a, array $b): int {
        return strcmp((string) $a['project_code'], (string) $b['project_code']);
    });
}

include dirname(__DIR__) . '/admin/include/layout_top.php';

?>

<style>
  .tk-db-card {
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
  }
  .tk-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
  }
  .tk-kpi {
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(140deg, rgba(241, 245, 249, 0.88), rgba(226, 232, 240, 0.7));
    padding: 14px;
  }
  .tk-kpi .kpi-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #334155;
    margin-bottom: 6px;
    font-weight: 700;
  }
  .tk-kpi .kpi-value {
    font-size: 1.8rem;
    line-height: 1;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 10px;
  }
  .tk-summary-table th,
  .tk-summary-table td {
    vertical-align: middle;
  }
  .tk-summary-table td:not(:first-child),
  .tk-summary-table th:not(:first-child) {
    text-align: center;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-7">
        <h1>Timekeeper Dashboard</h1>
      </div>
      <div class="col-sm-5 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'timekeeper'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
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

    <?php if ($mappingRequired): ?>
      <div class="card tk-db-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Project access needed</h3>
        </div>
        <div class="card-body">
          <p class="text-muted mb-0">No mapped projects found for your user. Request access from HR/Admin.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="card tk-db-card mb-3">
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
              <label for="project_code">Project</label>
              <select id="project_code" name="project_code[]" class="form-control js-searchable" multiple data-placeholder="All mapped projects">
                <?php foreach ($projectOptions as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>" <?= in_array($code, $projectFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card tk-db-card mb-3">
        <div class="card-header">
          <h3 class="card-title">No Punch Workflow Summary</h3>
        </div>
        <div class="card-body">
          <?php $quickProjectScope = !empty($projectFilter) ? $projectFilter : []; ?>
          <div class="tk-kpi-grid">
            <div class="tk-kpi">
              <div class="kpi-label">Total Queue</div>
              <div class="kpi-value"><?= h((string) $kpis['total']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(tk_dashboard_no_punch_url($selectedDate, $quickProjectScope)) ?>">Open queue</a>
            </div>
            <div class="tk-kpi">
              <div class="kpi-label">Not Submitted</div>
              <div class="kpi-value"><?= h((string) $kpis['not_submitted']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(tk_dashboard_no_punch_url($selectedDate, $quickProjectScope, 'not_submitted')) ?>">Quick action</a>
            </div>
            <div class="tk-kpi">
              <div class="kpi-label">Submitted</div>
              <div class="kpi-value"><?= h((string) $kpis['submitted']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(tk_dashboard_no_punch_url($selectedDate, $quickProjectScope, 'submitted')) ?>">Quick action</a>
            </div>
            <div class="tk-kpi">
              <div class="kpi-label">Reviewed</div>
              <div class="kpi-value"><?= h((string) $kpis['reviewed']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(tk_dashboard_no_punch_url($selectedDate, $quickProjectScope, 'reviewed')) ?>">Quick action</a>
            </div>
            <div class="tk-kpi">
              <div class="kpi-label">Escalated</div>
              <div class="kpi-value"><?= h((string) $kpis['escalated']) ?></div>
              <a class="btn btn-sm btn-outline-primary" href="<?= h(tk_dashboard_no_punch_url($selectedDate, $quickProjectScope, 'escalated')) ?>">Quick action</a>
            </div>
          </div>
        </div>
      </div>

      <div class="card tk-db-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">Category-wise Pending Summary (Project + Status)</h3>
          <span class="small text-muted"><?= h(count($projectSummary)) ?> project(s)</span>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-bordered table-sm tk-summary-table mb-0">
            <thead>
              <tr>
                <th>Project</th>
                <th>Not Submitted</th>
                <th>Submitted</th>
                <th>Reviewed</th>
                <th>Escalated</th>
                <th>Total</th>
                <th>Quick Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($projectSummary)): ?>
                <tr>
                  <td colspan="7" class="text-center text-muted p-4">No no-punch records found for selected filters.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($projectSummary as $summary): ?>
                  <?php
                    $projectCode = (string) ($summary['project_code'] ?? '');
                    $projectName = trim((string) ($summary['project_name'] ?? ''));
                    $projectScope = $projectCode !== '' && $projectCode !== '-' ? [$projectCode] : [];
                    $projectLabel = $projectCode;
                    if ($projectName !== '') {
                        $projectLabel = $projectCode . ' - ' . $projectName;
                    }
                  ?>
                  <tr>
                    <td><?= h($projectLabel !== '' ? $projectLabel : '-') ?></td>
                    <td><a href="<?= h(tk_dashboard_no_punch_url($selectedDate, $projectScope, 'not_submitted')) ?>"><?= h((string) $summary['not_submitted']) ?></a></td>
                    <td><a href="<?= h(tk_dashboard_no_punch_url($selectedDate, $projectScope, 'submitted')) ?>"><?= h((string) $summary['submitted']) ?></a></td>
                    <td><a href="<?= h(tk_dashboard_no_punch_url($selectedDate, $projectScope, 'reviewed')) ?>"><?= h((string) $summary['reviewed']) ?></a></td>
                    <td><a href="<?= h(tk_dashboard_no_punch_url($selectedDate, $projectScope, 'escalated')) ?>"><?= h((string) $summary['escalated']) ?></a></td>
                    <td><strong><?= h((string) $summary['total']) ?></strong></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="<?= h(tk_dashboard_no_punch_url($selectedDate, $projectScope)) ?>">Open queue</a></td>
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
