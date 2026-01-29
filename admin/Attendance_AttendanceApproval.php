<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Attendance Override Approvals';

function normalize_date(?string $value, string $fallback): string {
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

function current_week_range(): array {
    $today = new DateTimeImmutable('today');
    $dayOfWeek = (int) $today->format('N');
    $start = $today->modify('-' . ($dayOfWeek - 1) . ' days');
    $end = $start->modify('+6 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function format_person(?string $name, ?string $email): string {
    $name = trim((string) $name);
    $email = trim((string) $email);
    if ($name !== '' && $email !== '' && stripos($name, $email) === false) {
        return $name . ' (' . $email . ')';
    }
    if ($name !== '') {
        return $name;
    }
    if ($email !== '') {
        return $email;
    }
    return '-';
}

function normalize_multi_param($value): array {
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

function format_filter_list(array $items, int $max = 3): string {
    $items = array_values($items);
    $count = count($items);
    if ($count === 0) {
        return '';
    }
    if ($count <= $max) {
        return implode(', ', $items);
    }
    $head = array_slice($items, 0, $max);
    return implode(', ', $head) . ' +' . ($count - $max);
}

[$defaultStart, $defaultEnd] = current_week_range();

$hasQuery = !empty($_GET);
$employeeCodeFilter = trim((string) ($_GET['employeeCode'] ?? ''));
$projectCodeFilter = normalize_multi_param($_GET['project_code'] ?? []);
$loginProjectFilter = normalize_multi_param($_GET['login_project'] ?? []);
$startDateInput = trim((string) ($_GET['start_date'] ?? ''));
$endDateInput = trim((string) ($_GET['end_date'] ?? ''));

if ($startDateInput === '') {
    $startDateInput = $defaultStart;
}
if ($endDateInput === '') {
    $endDateInput = $defaultEnd;
}

$startDate = normalize_date($startDateInput, $defaultStart);
$endDate = normalize_date($endDateInput, $defaultEnd);

if ($startDate > $endDate) {
    $swap = $startDate;
    $startDate = $endDate;
    $endDate = $swap;
}

$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Invalid request token.';
    } else {
        $empCode = trim((string) ($_POST['employeeCode'] ?? ''));
        $attDate = trim((string) ($_POST['attDate'] ?? ''));

        if ($empCode === '' || $attDate === '') {
            $error = 'Employee code and date are required.';
        } elseif ($userName === '' || $userEmail === '') {
            $error = 'User name/email missing in session.';
        } elseif (!isset($bd) || !($bd instanceof mysqli)) {
            $error = 'Database connection not available.';
        } else {
            $row = null;
            $stmt = $bd->prepare(
                'SELECT override_work_hours, override_work_code, override_changed_by_name, override_changed_by_email ' .
                'FROM gcc_attendance_master.employee_att_daily ' .
                'WHERE emp_code = ? AND att_date = ? LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ss', $empCode, $attDate);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $result->free();
                    }
                }
                $stmt->close();
            }

            if (!$row) {
                $error = 'Override row not found.';
            } else {
                $changedByName = trim((string) ($row['override_changed_by_name'] ?? ''));
                $changedByEmail = trim((string) ($row['override_changed_by_email'] ?? ''));
                if ($changedByName === '') {
                    $changedByName = $userName;
                }
                if ($changedByEmail === '') {
                    $changedByEmail = $userEmail;
                }

                $payload = [
                    'employeeCode' => $empCode,
                    'attDate' => $attDate,
                    'workHours' => $row['override_work_hours'] !== null ? (float) $row['override_work_hours'] : null,
                    'workCode' => ($row['override_work_code'] ?? '') !== '' ? $row['override_work_code'] : null,
                    'changeDate' => gmdate(DATE_ATOM),
                    'changedByEmail' => $changedByEmail,
                    'changedByName' => $changedByName,
                    'approvedByEmail' => $userEmail,
                    'approvedByName' => $userName,
                    'isApproved' => true,
                    'approvedDate' => gmdate(DATE_ATOM),
                ];

                $result = attendance_api_post_json('/attendance-override/upsert', ['rows' => [$payload]], 15);
                if ($result['ok']) {
                    $success = 'Override approved.';
                } else {
                    $status = $result['status'] ?? 'n/a';
                    $error = 'Approval failed (status ' . $status . ').';
                }
            }
        }
    }
}

$pending = [];
$loadError = null;
$projectOptions = [];
$loginProjectOptions = [];

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
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

    $loginProjectResult = $bd->query(
        'SELECT DISTINCT p.pro_code, p.name ' .
        'FROM gcc_attendance_master.device_project_map d ' .
        'LEFT JOIN gcc_it.projects p ON p.id = d.project_id ' .
        'WHERE p.pro_code IS NOT NULL AND p.pro_code <> "" ' .
        'ORDER BY p.pro_code'
    );
    if ($loginProjectResult) {
        while ($row = $loginProjectResult->fetch_assoc()) {
            $code = trim((string) ($row['pro_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $loginProjectOptions[$code] = trim((string) ($row['name'] ?? ''));
        }
        $loginProjectResult->free();
    }

    $filters = [
        '(d.override_work_hours IS NOT NULL OR d.override_work_code IS NOT NULL)',
        '(d.override_is_approved IS NULL OR d.override_is_approved = 0)',
        '(d.is_delete = 0 OR d.is_delete IS NULL)',
        '(d.is_deleted = 0 OR d.is_deleted IS NULL)',
    ];
    $params = [];
    $types = '';
    if ($hasQuery) {
        $filters[] = 'd.att_date BETWEEN ? AND ?';
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= 'ss';
    }
    if ($employeeCodeFilter !== '') {
        $filters[] = 'd.emp_code COLLATE utf8mb4_general_ci = ?';
        $params[] = $employeeCodeFilter;
        $types .= 's';
    }
    if (!empty($projectCodeFilter)) {
        $placeholders = implode(',', array_fill(0, count($projectCodeFilter), '?'));
        $filters[] = 'h.jbno IN (' . $placeholders . ')';
        $params = array_merge($params, $projectCodeFilter);
        $types .= str_repeat('s', count($projectCodeFilter));
    }
    if (!empty($loginProjectFilter)) {
        $placeholders = implode(',', array_fill(0, count($loginProjectFilter), '?'));
        $filters[] = 'EXISTS (' .
            'SELECT 1 ' .
            'FROM gcc_attendance_master.employee_daily_punch dp ' .
            'LEFT JOIN gcc_attendance_master.device_project_map dm ON dm.device_sn = dp.first_terminal_sn ' .
            'LEFT JOIN gcc_it.projects p ON p.id = dm.project_id ' .
            'WHERE dp.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci ' .
            'AND dp.punch_date = d.att_date ' .
            'AND p.pro_code IN (' . $placeholders . ')' .
        ')';
        $params = array_merge($params, $loginProjectFilter);
        $types .= str_repeat('s', count($loginProjectFilter));
    }

    $sql = 'SELECT d.emp_code, d.att_date, d.override_work_hours, d.override_work_code, d.override_changed_by_name, ' .
        'd.override_changed_by_email, h.emp_name, h.desg_name, h.dept_name ' .
        'FROM gcc_attendance_master.employee_att_daily d ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ON h.emp_code COLLATE utf8mb4_general_ci = d.emp_code COLLATE utf8mb4_general_ci ' .
        'WHERE ' . implode(' AND ', $filters) .
        ' ORDER BY COALESCE(d.override_change_date, d.att_date) DESC, d.emp_code ASC' .
        ($hasQuery ? '' : ' LIMIT 10');

    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $pending[] = $row;
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load pending approvals.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare approval query.';
    }
}

$pendingCount = count($pending);
$pendingMeta = $hasQuery ? 'matching pending overrides' : 'latest pending overrides';
$rangeLabel = $hasQuery ? ($startDate . ' to ' . $endDate) : 'All dates';
$heroTitle = $hasQuery ? 'Filtered overrides' : 'Latest overrides';
$heroSubtitle = $hasQuery
    ? 'Review override requests that match the selected filters.'
    : 'Review the newest override requests waiting for approval.';
$activeChips = [];
if ($hasQuery) {
    $activeChips[] = ['label' => 'Range', 'value' => $startDate . ' to ' . $endDate];
}
if ($employeeCodeFilter !== '') {
    $activeChips[] = ['label' => 'Emp code', 'value' => $employeeCodeFilter];
}
if (!empty($projectCodeFilter)) {
    $activeChips[] = ['label' => 'Allocated', 'value' => format_filter_list($projectCodeFilter)];
}
if (!empty($loginProjectFilter)) {
    $activeChips[] = ['label' => 'Log-in', 'value' => format_filter_list($loginProjectFilter)];
}
if (!$hasQuery) {
    $activeChips[] = ['label' => 'View', 'value' => 'Latest 10'];
}
$chipCount = count($activeChips);
$chipMeta = $chipCount === 1 ? 'active filter' : 'active filters';

include __DIR__ . '/include/layout_top.php';

?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Space+Grotesk:wght@400;500;600;700&display=swap');

  .approval-page {
    --approval-ink: #0f172a;
    --approval-muted: #6b7280;
    --approval-accent: #ff6b35;
    --approval-accent-2: #1f3c88;
    --approval-surface: #ffffff;
    background:
      radial-gradient(800px 400px at 10% -10%, rgba(31, 60, 136, 0.14), transparent 60%),
      radial-gradient(600px 300px at 90% 0%, rgba(255, 107, 53, 0.16), transparent 55%),
      linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
    padding-top: 1.5rem;
    padding-bottom: 2rem;
  }

  .approval-hero {
    position: relative;
    overflow: hidden;
    border-radius: 20px;
    padding: 24px 28px;
    background: linear-gradient(135deg, #182848 0%, #1f3c88 45%, #ff6b35 100%);
    color: #fff;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.28);
    margin-bottom: 1.5rem;
  }

  .approval-hero::before {
    content: "";
    position: absolute;
    inset: -30% 45% 30% -10%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.22), transparent 60%);
    opacity: 0.9;
  }

  .approval-hero::after {
    content: "";
    position: absolute;
    inset: 0;
    background:
      radial-gradient(200px 120px at 85% 20%, rgba(255, 255, 255, 0.2), transparent 60%),
      radial-gradient(260px 180px at 15% 80%, rgba(255, 255, 255, 0.12), transparent 60%);
    pointer-events: none;
  }

  .approval-hero-content {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
  }

  .approval-hero-text {
    min-width: 220px;
    max-width: 520px;
  }

  .approval-eyebrow {
    font-family: "Space Grotesk", sans-serif;
    font-size: 12px;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.75);
    margin-bottom: 8px;
  }

  .approval-title {
    font-family: "Fraunces", serif;
    font-size: 30px;
    font-weight: 600;
    margin: 0 0 6px;
  }

  .approval-subtitle {
    font-family: "Space Grotesk", sans-serif;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
    margin: 0;
  }

  .approval-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
  }

  .approval-stat {
    background: rgba(255, 255, 255, 0.16);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 14px;
    padding: 12px 16px;
    min-width: 150px;
    backdrop-filter: blur(6px);
  }

  .approval-stat-label {
    font-family: "Space Grotesk", sans-serif;
    font-size: 11px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.7);
  }

  .approval-stat-value {
    font-family: "Space Grotesk", sans-serif;
    font-size: 22px;
    font-weight: 600;
    margin: 4px 0 2px;
  }

  .approval-stat-meta {
    font-family: "Space Grotesk", sans-serif;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.75);
  }

  .approval-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
  }

  .approval-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-family: "Space Grotesk", sans-serif;
    font-size: 12px;
    color: #fff;
  }

  .approval-chip strong {
    font-weight: 600;
  }

  .approval-card {
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    overflow: hidden;
  }

  .approval-card .card-header {
    background: linear-gradient(90deg, rgba(31, 60, 136, 0.08), rgba(255, 107, 53, 0.08));
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
  }

  .approval-card .card-title {
    font-family: "Space Grotesk", sans-serif;
    font-weight: 600;
    color: var(--approval-ink);
  }

  .approval-card .form-control,
  .approval-card .btn {
    border-radius: 10px;
  }

  .approval-table thead th {
    background: #0f172a;
    color: #fff;
    border-color: rgba(255, 255, 255, 0.12);
    font-family: "Space Grotesk", sans-serif;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .approval-table tbody tr {
    transition: background 0.2s ease, transform 0.2s ease;
  }

  .approval-table tbody tr:hover {
    background: rgba(255, 107, 53, 0.08);
  }

  .approval-approve-btn {
    border-radius: 999px;
    padding: 6px 14px;
    font-family: "Space Grotesk", sans-serif;
    font-weight: 600;
  }

  .approval-meta {
    font-family: "Space Grotesk", sans-serif;
    color: var(--approval-muted);
  }

  @media (max-width: 767px) {
    .approval-hero {
      padding: 20px;
    }
    .approval-title {
      font-size: 24px;
    }
    .approval-stat {
      min-width: 140px;
    }
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Override Approvals</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_url('Attendance_AttendanceDaily.php')) ?>">Back to daily</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(admin_url('Attendance_AttendanceAdjustTime.php')) ?>">Adjust time</a>
      </div>
    </div>
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content approval-page">
  <div class="container-fluid">
    <?php if ($error): ?>
      <div class="alert alert-warning"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($loadError): ?>
      <div class="alert alert-warning"><?= h($loadError) ?></div>
    <?php endif; ?>

    <div class="approval-hero">
      <div class="approval-hero-content">
        <div class="approval-hero-text">
          <div class="approval-eyebrow">Attendance approvals</div>
          <h2 class="approval-title"><?= h($heroTitle) ?></h2>
          <p class="approval-subtitle"><?= h($heroSubtitle) ?></p>
          <div class="approval-chips">
            <?php foreach ($activeChips as $chip): ?>
              <span class="approval-chip"><strong><?= h($chip['label']) ?>:</strong> <?= h($chip['value']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="approval-stats">
          <div class="approval-stat">
            <div class="approval-stat-label">Pending</div>
            <div class="approval-stat-value"><?= h((string) $pendingCount) ?></div>
            <div class="approval-stat-meta"><?= h($pendingMeta) ?></div>
          </div>
          <div class="approval-stat">
            <div class="approval-stat-label">Range</div>
            <div class="approval-stat-value"><?= h($rangeLabel) ?></div>
            <div class="approval-stat-meta">Date window</div>
          </div>
          <div class="approval-stat">
            <div class="approval-stat-label">Filters</div>
            <div class="approval-stat-value"><?= h((string) $chipCount) ?></div>
            <div class="approval-stat-meta"><?= h($chipMeta) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card approval-card approval-card--filters">
      <div class="card-header">
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form method="get" class="form-row">
          <div class="form-group col-md-3">
            <label for="employeeCode">Employee code</label>
            <input id="employeeCode" name="employeeCode" class="form-control" value="<?= h($employeeCodeFilter) ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="project_code">Allocated project</label>
            <select id="project_code" name="project_code[]" class="form-control js-searchable" data-placeholder="All" multiple>
              <?php foreach ($projectOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= in_array($code, $projectCodeFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="login_project">Log-in project</label>
            <select id="login_project" name="login_project[]" class="form-control js-searchable" data-placeholder="All" multiple>
              <?php foreach ($loginProjectOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= in_array($code, $loginProjectFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="start_date">Start date</label>
            <input id="start_date" name="start_date" type="date" class="form-control" value="<?= h($startDate) ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="end_date">End date</label>
            <input id="end_date" name="end_date" type="date" class="form-control" value="<?= h($endDate) ?>">
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Apply</button>
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-secondary btn-block" href="<?= h(admin_url('Attendance_AttendanceApproval.php')) ?>">Reset</a>
          </div>
        </form>
        <div class="small approval-meta">Default week: <?= h($defaultStart) ?> to <?= h($defaultEnd) ?></div>
      </div>
    </div>

    <div class="card approval-card approval-card--table">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Pending overrides</h3>
        <span class="text-muted small"><?= count($pending) ?> pending</span>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-bordered table-sm approval-table">
          <thead>
            <tr>
              <th>Emp Code</th>
              <th>Emp Name</th>
              <th>Designation</th>
              <th>Department</th>
              <th>Date</th>
              <th>Override hrs</th>
              <th>Override code</th>
              <th>Requested by</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($pending)): ?>
              <?php foreach ($pending as $row): ?>
                <tr>
                  <td><?= h($row['emp_code'] ?? '-') ?></td>
                  <td><?= h($row['emp_name'] ?? '-') ?></td>
                  <td><?= h($row['desg_name'] ?? '-') ?></td>
                  <td><?= h($row['dept_name'] ?? '-') ?></td>
                  <td><?= h($row['att_date'] ?? '-') ?></td>
                  <td><?= h($row['override_work_hours'] ?? '-') ?></td>
                  <td><?= h($row['override_work_code'] ?? '-') ?></td>
                  <td><?= h(format_person($row['override_changed_by_name'] ?? '', $row['override_changed_by_email'] ?? '')) ?></td>
                  <td>
                    <?php
                      $queryParams = [];
                      if ($hasQuery) {
                          $queryParams = [
                              'employeeCode' => $employeeCodeFilter,
                              'start_date' => $startDate,
                              'end_date' => $endDate,
                          ];
                          if (!empty($projectCodeFilter)) {
                              $queryParams['project_code'] = $projectCodeFilter;
                          }
                          if (!empty($loginProjectFilter)) {
                              $queryParams['login_project'] = $loginProjectFilter;
                          }
                      }
                      $actionUrl = admin_url('Attendance_AttendanceApproval.php');
                      if (!empty($queryParams)) {
                          $actionUrl .= '?' . http_build_query($queryParams);
                      }
                    ?>
                    <form method="post" action="<?= h($actionUrl) ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="employeeCode" value="<?= h($row['emp_code'] ?? '') ?>">
                      <input type="hidden" name="attDate" value="<?= h($row['att_date'] ?? '') ?>">
                      <button type="submit" class="btn btn-sm btn-success approval-approve-btn">Approve</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center text-muted">No pending overrides found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
