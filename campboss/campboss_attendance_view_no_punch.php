<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'Camp Boss No Punch Review';

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

function load_campboss_projects(mysqli $bd, string $userId): array {
    if ($userId === '') {
        return [];
    }
    $projects = [];
    $stmt = $bd->prepare(
        'SELECT project_code FROM gcc_attendance_master.campboss_project_map WHERE user_id = ? ORDER BY project_code'
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

$uaeTz = new DateTimeZone('Asia/Dubai');
$todayUae = (new DateTimeImmutable('now', $uaeTz))->format('Y-m-d');

$selectedDate = normalize_date($_GET['date'] ?? '', $todayUae);
$projectFilter = normalize_multi_param($_GET['project_code'] ?? []);

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$loadError = null;
$mappingRequired = false;
$mappedProjects = [];
$projectOptions = [];
$reasonOptions = [];
$rows = [];
$flash = get_flash();
$reviewedCount = 0;
$escalatedCount = 0;

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!ensure_campboss_project_map_table($bd)) {
        $loadError = 'Unable to load camp boss project access.';
    } else {
        $mappedProjects = load_campboss_projects($bd, $userId);
        if (empty($mappedProjects)) {
            $mappingRequired = true;
        }
    }

    if (!$loadError) {
        ensure_no_punch_review_table($bd);
        ensure_no_punch_reason_table($bd);
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

    $reasonResult = $bd->query(
        'SELECT reason_code, reason_text, override_work_hours, override_work_code ' .
        'FROM gcc_attendance_master.attendance_no_punch_reasons ORDER BY reason_text, reason_code'
    );
    if ($reasonResult) {
        while ($row = $reasonResult->fetch_assoc()) {
            $code = trim((string) ($row['reason_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $reasonOptions[$code] = [
                'text' => trim((string) ($row['reason_text'] ?? '')),
                'override_hours' => $row['override_work_hours'] ?? null,
                'override_code' => trim((string) ($row['override_work_code'] ?? '')),
            ];
        }
        $reasonResult->free();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$mappingRequired) {
    $action = $_POST['action'] ?? '';
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        set_flash('warning', 'Invalid request token. Please try again.');
    } elseif ($action === 'save_reviews') {
        if (!ensure_no_punch_review_table($bd)) {
            set_flash('warning', 'Review table not available.');
        } else {
            $empCodes = $_POST['emp_code'] ?? [];
            $attDates = $_POST['att_date'] ?? [];
            $reasonCodes = $_POST['reason_code'] ?? [];
            $notes = $_POST['campboss_note'] ?? [];

            if (!is_array($empCodes)) {
                $empCodes = [$empCodes];
            }
            if (!is_array($attDates)) {
                $attDates = [$attDates];
            }
            if (!is_array($reasonCodes)) {
                $reasonCodes = [$reasonCodes];
            }
            if (!is_array($notes)) {
                $notes = [$notes];
            }

            $max = max(count($empCodes), count($attDates), count($reasonCodes), count($notes), 1);
            $changeDate = gmdate('Y-m-d H:i:s');

            $overrideMap = [];
            if (!empty($empCodes)) {
                $uniqueCodes = [];
                foreach ($empCodes as $code) {
                    $code = trim((string) $code);
                    if ($code !== '') {
                        $uniqueCodes[$code] = true;
                    }
                }
                $uniqueCodes = array_keys($uniqueCodes);
                if (!empty($uniqueCodes)) {
                    $placeholders = implode(',', array_fill(0, count($uniqueCodes), '?'));
                    $types = str_repeat('s', count($uniqueCodes)) . 's';
                    $params = array_merge($uniqueCodes, [$selectedDate]);
                    $sql = 'SELECT emp_code, override_work_hours, override_work_code, override_is_approved ' .
                        'FROM gcc_attendance_master.employee_att_daily_overrides ' .
                        'WHERE emp_code IN (' . $placeholders . ') AND att_date = ?';
                    $stmt = $bd->prepare($sql);
                    if ($stmt) {
                        bind_params($stmt, $types, $params);
                        if ($stmt->execute()) {
                            $result = $stmt->get_result();
                            if ($result) {
                                while ($row = $result->fetch_assoc()) {
                                    $emp = trim((string) ($row['emp_code'] ?? ''));
                                    if ($emp !== '') {
                                        $overrideMap[$emp] = $row;
                                    }
                                }
                                $result->free();
                            }
                        }
                        $stmt->close();
                    }
                }
            }

            $reviewSql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reviews` ' .
                '(emp_code, att_date, campboss_reason_code, campboss_note, campboss_email, campboss_name, campboss_reviewed_at, is_escalated, escalated_at) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE ' .
                'campboss_reason_code = VALUES(campboss_reason_code), ' .
                'campboss_note = VALUES(campboss_note), ' .
                'campboss_email = VALUES(campboss_email), ' .
                'campboss_name = VALUES(campboss_name), ' .
                'campboss_reviewed_at = VALUES(campboss_reviewed_at), ' .
                'is_escalated = VALUES(is_escalated), ' .
                'escalated_at = VALUES(escalated_at)';
            $reviewStmt = $bd->prepare($reviewSql);

            $overrideSql = 'INSERT INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
                '(emp_code, att_date, override_work_hours, override_work_code, override_change_date, ' .
                'override_changed_by_email, override_changed_by_name, override_is_approved, ' .
                'override_approved_by_email, override_approved_by_name, override_approved_date) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE ' .
                'override_work_hours = VALUES(override_work_hours), ' .
                'override_work_code = VALUES(override_work_code), ' .
                'override_change_date = VALUES(override_change_date), ' .
                'override_changed_by_email = VALUES(override_changed_by_email), ' .
                'override_changed_by_name = VALUES(override_changed_by_name), ' .
                'override_is_approved = 0, ' .
                'override_approved_by_email = NULL, ' .
                'override_approved_by_name = NULL, ' .
                'override_approved_date = NULL';
            $overrideStmt = $bd->prepare($overrideSql);

            $updated = 0;
            $errors = [];

            for ($i = 0; $i < $max; $i++) {
                $empCode = trim((string) ($empCodes[$i] ?? ''));
                $attDate = trim((string) ($attDates[$i] ?? ''));
                if ($empCode === '' || $attDate === '') {
                    continue;
                }

                $reasonCode = trim((string) ($reasonCodes[$i] ?? ''));
                $note = trim((string) ($notes[$i] ?? ''));
                $reasonMeta = $reasonCode !== '' ? ($reasonOptions[$reasonCode] ?? null) : null;
                $overrideHours = $reasonMeta['override_hours'] ?? null;
                $overrideCode = $reasonMeta['override_code'] ?? null;
                if ($overrideCode === '') {
                    $overrideCode = null;
                }

                $isEscalated = 0;
                $escalatedAt = null;
                if ($reasonCode !== '' && $overrideHours === null && $overrideCode === null) {
                    $isEscalated = 1;
                    $escalatedAt = $changeDate;
                }

                if ($reviewStmt) {
                    $emailParam = $userEmail !== '' ? $userEmail : null;
                    $nameParam = $userName !== '' ? $userName : null;
                    $reasonParam = $reasonCode !== '' ? $reasonCode : null;
                    $noteParam = $note !== '' ? $note : null;
                    $reviewedAt = $reasonCode !== '' || $note !== '' ? $changeDate : null;
                    $reviewStmt->bind_param(
                        'sssssssis',
                        $empCode,
                        $attDate,
                        $reasonParam,
                        $noteParam,
                        $emailParam,
                        $nameParam,
                        $reviewedAt,
                        $isEscalated,
                        $escalatedAt
                    );
                    if ($reviewStmt->execute()) {
                        $updated++;
                    } else {
                        $errors[] = 'Unable to save review for ' . $empCode . ' on ' . $attDate . '.';
                    }
                }

                if ($reasonCode !== '' && ($overrideHours !== null || $overrideCode !== null)) {
                    $existing = $overrideMap[$empCode] ?? [];
                    $existingHours = trim((string) ($existing['override_work_hours'] ?? ''));
                    $existingCode = trim((string) ($existing['override_work_code'] ?? ''));
                    $hasExisting = ($existingHours !== '' || $existingCode !== '');
                    if (!$hasExisting && $overrideStmt) {
                        $approved = 0;
                        $approvedByEmail = null;
                        $approvedByName = null;
                        $approvedDate = null;
                        $emailParam = $userEmail !== '' ? $userEmail : null;
                        $nameParam = $userName !== '' ? $userName : null;
                        $overrideStmt->bind_param(
                            'sssssssisss',
                            $empCode,
                            $attDate,
                            $overrideHours,
                            $overrideCode,
                            $changeDate,
                            $emailParam,
                            $nameParam,
                            $approved,
                            $approvedByEmail,
                            $approvedByName,
                            $approvedDate
                        );
                        if (!$overrideStmt->execute()) {
                            $errors[] = 'Unable to auto-override for ' . $empCode . ' on ' . $attDate . '.';
                        }
                    }
                }
            }

            if ($reviewStmt) {
                $reviewStmt->close();
            }
            if ($overrideStmt) {
                $overrideStmt->close();
            }

            if (!empty($errors)) {
                set_flash('warning', implode(' ', $errors));
            } else {
                set_flash('success', 'Saved ' . $updated . ' review(s).');
            }
        }
    }

    $redirectParams = ['date' => $selectedDate];
    if (!empty($projectFilter)) {
        $redirectParams['project_code'] = $projectFilter;
    }
    $url = admin_url('campboss_attendance_view_no_punch.php');
    if (!empty($redirectParams)) {
        $url .= '?' . http_build_query($redirectParams);
    }
    header('Location: ' . $url);
    exit;
}

if (!$loadError && !$mappingRequired) {
    if (!empty($projectFilter)) {
        $projectFilter = array_values(array_intersect($projectFilter, $mappedProjects));
    }
    if (!empty($mappedProjects)) {
        $mappedSet = array_fill_keys($mappedProjects, true);
        $projectOptions = array_intersect_key($projectOptions, $mappedSet);
    }

    $filters = ['r.att_date = ?', 'h.is_deleted = 0', 'h.st_code = "A"'];
    $params = [$selectedDate];
    $types = 's';

    if (!empty($mappedProjects)) {
        $filters[] = 'h.jbno IN (' . implode(',', array_fill(0, count($mappedProjects), '?')) . ')';
        $params = array_merge($params, $mappedProjects);
        $types .= str_repeat('s', count($mappedProjects));
    }
    if (!empty($projectFilter)) {
        $filters[] = 'h.jbno IN (' . implode(',', array_fill(0, count($projectFilter), '?')) . ')';
        $params = array_merge($params, $projectFilter);
        $types .= str_repeat('s', count($projectFilter));
    }

    $sql = 'SELECT r.emp_code, r.att_date, r.timekeeper_submitted_at, r.campboss_reason_code, ' .
        'r.campboss_note, r.campboss_reviewed_at, r.is_escalated, ' .
        'h.emp_name, h.desg_name, h.dept_name, h.jbno, h.jbdesc, ' .
        'o.override_work_hours, o.override_work_code, o.override_is_approved ' .
        'FROM gcc_attendance_master.attendance_no_punch_reviews r ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ON h.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily_overrides o ON o.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'AND o.att_date = r.att_date ' .
        'WHERE ' . implode(' AND ', $filters) .
        ' ORDER BY r.timekeeper_submitted_at DESC, r.emp_code ASC';
    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                    if (!empty($row['campboss_reason_code'])) {
                        $reviewedCount++;
                    }
                    if ((int) ($row['is_escalated'] ?? 0) === 1) {
                        $escalatedCount++;
                    }
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load camp boss reviews.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare camp boss query.';
    }
}

include __DIR__ . '/../admin/include/layout_top.php';

?>

<style>
  .campboss-card {
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
  }
  .campboss-table th,
  .campboss-table td {
    vertical-align: middle;
  }
  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(15, 23, 42, 0.08);
  }
  .status-pill.is-approved { background: rgba(34, 197, 94, 0.2); color: #166534; }
  .status-pill.is-pending { background: rgba(251, 191, 36, 0.2); color: #92400e; }
  .status-pill.is-escalated { background: rgba(239, 68, 68, 0.2); color: #b91c1c; }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Camp Boss No Punch Review</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'campboss'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php endif; ?>

    <?php if ($mappingRequired): ?>
      <div class="card campboss-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Project access needed</h3>
        </div>
        <div class="card-body">
          <p class="text-muted mb-0">No camp boss project access is configured. Contact the admin to add your projects.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="card campboss-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Filters</h3>
        </div>
        <div class="card-body">
          <form method="get" class="form-row">
            <div class="form-group col-md-3">
              <label for="date">Date (UAE)</label>
              <input id="date" name="date" type="date" class="form-control" value="<?= h($selectedDate) ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="project_code">Project</label>
              <select id="project_code" name="project_code[]" class="form-control js-searchable" multiple data-placeholder="All projects">
                <?php foreach ($projectOptions as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>" <?= in_array($code, $projectFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
          </form>
          <div class="small text-muted">Reviewed: <?= h((string) $reviewedCount) ?> | Escalated: <?= h((string) $escalatedCount) ?></div>
        </div>
      </div>

      <?php if (empty($reasonOptions)): ?>
        <div class="alert alert-warning">No camp boss reasons configured. Populate attendance_no_punch_reasons to enable selections.</div>
      <?php endif; ?>

      <div class="card campboss-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">No punch submissions</h3>
          <span class="text-muted small"><?= h(count($rows)) ?> record(s)</span>
        </div>
        <div class="card-body table-responsive p-0">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <table class="table table-bordered table-sm campboss-table mb-0">
              <thead>
                <tr>
                  <th>Emp Code</th>
                  <th>Name</th>
                  <th>Designation</th>
                  <th>Department</th>
                  <th>Project</th>
                  <th>Reason</th>
                  <th>Note</th>
                  <th>Auto override</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr>
                    <td colspan="9" class="text-center text-muted p-4">No submissions for this date.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $row): ?>
                    <?php
                      $overrideStatus = (int) ($row['override_is_approved'] ?? 0);
                      $statusLabel = 'Pending';
                      $statusClass = 'is-pending';
                      if ((int) ($row['is_escalated'] ?? 0) === 1) {
                          $statusLabel = 'Escalated';
                          $statusClass = 'is-escalated';
                      } elseif ($overrideStatus === 1) {
                          $statusLabel = 'Approved';
                          $statusClass = 'is-approved';
                      }
                      $reasonCode = trim((string) ($row['campboss_reason_code'] ?? ''));
                    ?>
                    <tr>
                      <td>
                        <?= h($row['emp_code'] ?? '') ?>
                        <input type="hidden" name="emp_code[]" value="<?= h($row['emp_code'] ?? '') ?>">
                        <input type="hidden" name="att_date[]" value="<?= h($selectedDate) ?>">
                      </td>
                      <td><?= h($row['emp_name'] ?? '') ?></td>
                      <td><?= h($row['desg_name'] ?? '') ?></td>
                      <td><?= h($row['dept_name'] ?? '') ?></td>
                      <td><?= h($row['jbno'] ?? '') ?></td>
                      <td>
                        <select class="form-control form-control-sm" name="reason_code[]">
                          <option value="">Select</option>
                          <?php foreach ($reasonOptions as $code => $meta): ?>
                            <?php $label = $meta['text'] !== '' ? ($code . ' - ' . $meta['text']) : $code; ?>
                            <option value="<?= h($code) ?>" <?= $code === $reasonCode ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <input class="form-control form-control-sm" name="campboss_note[]" value="<?= h($row['campboss_note'] ?? '') ?>">
                      </td>
                      <td>
                        <?= h($row['override_work_hours'] ?? '') ?>
                        <?php if (!empty($row['override_work_code'])): ?>
                          <div class="text-muted small"><?= h($row['override_work_code']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td><span class="status-pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            <div class="p-3">
              <button type="submit" name="action" value="save_reviews" class="btn btn-primary">Save reviews</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../admin/include/layout_bottom.php'; ?>
