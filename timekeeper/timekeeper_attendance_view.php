<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'Attendance Daily';

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

function build_date_range(string $start, string $end): array {
    $dates = [];
    try {
        $cursor = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
    } catch (Exception $e) {
        return $dates;
    }
    if ($cursor > $last) {
        return $dates;
    }
    while ($cursor <= $last) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

function format_date_label(string $date): string {
    try {
        $dt = new DateTimeImmutable($date);
    } catch (Exception $e) {
        return $date;
    }
    return $dt->format('D, d M');
}

function format_time_value(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return $value;
    }
    return $dt->format('H:i');
}

function calculate_work_hours(?string $start, ?string $end): ?string {
    $start = trim((string) $start);
    $end = trim((string) $end);
    if ($start === '' || $end === '') {
        return null;
    }
    try {
        $startDt = new DateTimeImmutable($start);
        $endDt = new DateTimeImmutable($end);
    } catch (Exception $e) {
        return null;
    }
    $diff = $endDt->getTimestamp() - $startDt->getTimestamp();
    if ($diff < 0) {
        return null;
    }
    $hours = $diff / 3600;
    return number_format($hours, 2, '.', '');
}

function format_work_duration(?string $start, ?string $end): string {
    $start = trim((string) $start);
    $end = trim((string) $end);
    if ($start === '' || $end === '') {
        return '';
    }
    try {
        $startDt = new DateTimeImmutable($start);
        $endDt = new DateTimeImmutable($end);
    } catch (Exception $e) {
        return '';
    }
    $diff = $endDt->getTimestamp() - $startDt->getTimestamp();
    if ($diff < 0) {
        return '';
    }
    $minutes = (int) floor($diff / 60);
    $hours = (int) floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%d:%02d', $hours, $mins);
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
    return '';
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

function normalize_search_terms(?string $value): array {
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/[\\s,;]+/', $value);
    if (!$parts) {
        return [];
    }
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $clean[$part] = true;
    }
    return array_keys($clean);
}

function ensure_timekeeper_project_map_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`timekeeper_project_map` (' .
        '`user_id` varchar(50) NOT NULL,' .
        '`project_code` varchar(20) NOT NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`user_id`, `project_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function load_timekeeper_projects(mysqli $bd, string $userId): array {
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

function build_query_url(array $params): string {
    $base = admin_url('timekeeper_attendance_view.php');
    $query = http_build_query($params);
    if ($query === '') {
        return $base;
    }
    return $base . '?' . $query;
}

function build_page_window(int $current, int $total, int $radius = 2): array {
    if ($total <= 1) {
        return [1];
    }
    if ($total <= 7) {
        return range(1, $total);
    }
    $pages = [1];
    $start = max(2, $current - $radius);
    $end = min($total - 1, $current + $radius);
    if ($start > 2) {
        $pages[] = '...';
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < ($total - 1)) {
        $pages[] = '...';
    }
    $pages[] = $total;
    return $pages;
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

function export_job_dir(): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gcc_attendance_exports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

// Enable/disable export debug logging (set false after troubleshooting)
$EXPORT_DEBUG = true;

function export_log_dir(): string {
    return 'E:\\XAAMP_29_Nov\\htdocs\\gcc_attendance_master\\logs';
}

function export_log_path(): string {
    $dir = export_log_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . 'export_debug.log';
}

function export_log(string $label, array $data = []): void {
    global $EXPORT_DEBUG;
    if (!$EXPORT_DEBUG) {
        return;
    }
    $entry = [
        'ts' => date('c'),
        'label' => $label,
        'user_id' => $_SESSION['user_id'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'data' => $data,
    ];
    @file_put_contents(export_log_path(), json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function export_job_path(string $jobId): string {
    return export_job_dir() . DIRECTORY_SEPARATOR . $jobId . '.json';
}

function export_job_csv_path(string $jobId): string {
    return export_job_dir() . DIRECTORY_SEPARATOR . $jobId . '.csv';
}

function sanitize_export_job_id(?string $jobId): ?string {
    $jobId = strtolower(trim((string) $jobId));
    if ($jobId === '') {
        return null;
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        return null;
    }
    return $jobId;
}

function read_export_job(string $jobId): ?array {
    $path = export_job_path($jobId);
    if (!is_file($path)) {
        export_log('job_missing', ['job' => $jobId, 'path' => $path]);
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        export_log('job_read_failed', ['job' => $jobId, 'path' => $path]);
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        export_log('job_json_invalid', [
            'job' => $jobId,
            'path' => $path,
            'len' => strlen($raw),
            'json_error' => json_last_error_msg(),
        ]);
        return null;
    }
    return $data;
}

function write_export_job(string $jobId, array $data): void {
    $path = export_job_path($jobId);
    $data['updated_at'] = gmdate('c');
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        export_log('job_json_encode_failed', ['job' => $jobId, 'path' => $path]);
        return;
    }
    $tmp = $path . '.tmp';
    $written = @file_put_contents($tmp, $payload);
    if ($written === false) {
        export_log('job_write_failed', ['job' => $jobId, 'tmp' => $tmp]);
        return;
    }
    if (!@rename($tmp, $path)) {
        export_log('job_rename_failed', ['job' => $jobId, 'tmp' => $tmp, 'path' => $path]);
    }
}

function render_attendance_results(array $context): string {
    extract($context, EXTR_SKIP);
    ob_start();
    ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h3 class="card-title">Weekly attendance</h3>
        <div class="attendance-quick-ranges">
          <span class="text-muted small"><?= h($showingStart) ?>-<?= h($showingEnd) ?> of <?= h($totalEmployees) ?> employees | <?= h(count($dateRange)) ?> day(s)</span>
          <div class="btn-group btn-group-sm" role="group" aria-label="Quick ranges">
            <a class="btn <?= $isLast30Days ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($last30DaysUrl) ?>">Last 30 days</a>
            <a class="btn <?= $isLast60Days ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($last60DaysUrl) ?>">Last 60 days</a>
          </div>
          <div class="override-actions">
            <button type="button" class="btn btn-sm btn-success js-save-overrides">Save overrides</button>
            <span class="text-muted small override-save-status" aria-live="polite"></span>
          </div>
        </div>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="attendance-pager" data-total-pages="<?= h($totalPages) ?>">
          <div class="pager-meta">
            Showing <?= h($showingStart) ?>-<?= h($showingEnd) ?> of <?= h($totalEmployees) ?> employees
          </div>
          <div class="pager-controls">
            <button type="button" class="pager-btn <?= $page <= 1 ? 'is-disabled' : '' ?>" data-page="<?= h(max(1, $page - 1)) ?>">
              ‹ Prev
            </button>
            <div class="pager-field">
              <input type="number" class="pager-page" min="1" max="<?= h($totalPages) ?>" value="<?= h($page) ?>">
              <span class="text-muted">/ <?= h($totalPages) ?></span>
              <button type="button" class="pager-btn pager-go">Go</button>
            </div>
            <select class="pager-select pager-size">
              <?php foreach ($allowedPerPage as $size): ?>
                <option value="<?= h($size) ?>" <?= (int) $perPage === (int) $size ? 'selected' : '' ?>><?= h($size) ?> / page</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="pager-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>" data-page="<?= h(min($totalPages, $page + 1)) ?>">
              Next ›
            </button>
          </div>
        </div>
      <?php endif; ?>
      <div class="card-body p-0">
        <?php if (!empty($dateRange)): ?>
          <div class="attendance-day-scroller">
            <button type="button" class="day-nav day-nav-prev" aria-label="Previous day">&#8249;</button>
            <div class="day-strip" role="tablist" aria-label="Days">
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php
                  $chipDay = $date;
                  $chipDate = '';
                  try {
                      $chipDt = new DateTimeImmutable($date);
                      $chipDay = $chipDt->format('D');
                      $chipDate = $chipDt->format('d M');
                  } catch (Exception $e) {
                      $chipDay = $date;
                  }
                ?>
                <button type="button" class="day-chip" data-day-index="<?= h($dayIndex) ?>" data-date="<?= h($date) ?>">
                  <span class="chip-day"><?= h($chipDay) ?></span>
                  <span class="chip-date"><?= h($chipDate !== '' ? $chipDate : $date) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="day-nav day-nav-next" aria-label="Next day">&#8250;</button>
          </div>
        <?php endif; ?>
        <div class="table-responsive attendance-scroll">
          <table class="table table-bordered table-sm attendance-daily-table">
          <thead>
            <tr>
              <th rowspan="2" class="col-fixed col-fixed-1">Emp Code</th>
              <th rowspan="2" class="col-fixed col-fixed-2">
                Emp Name
                <button type="button" class="meta-toggle" id="toggleMetaColumns" aria-expanded="false" title="Show details">+</button>
              </th>
              <th rowspan="2" class="col-adv">Designation</th>
              <th rowspan="2" class="col-adv">Department</th>
              <th rowspan="2" class="col-adv">Cost center company</th>
              <th rowspan="2" class="col-adv">Employee Type</th>
              <th rowspan="2" class="col-adv">Project Code</th>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <th colspan="<?= h($collapsedDayColumns) ?>" class="text-center date-header day-header" data-day-index="<?= h($dayIndex) ?>" data-collapsed-colspan="<?= h($collapsedDayColumns) ?>" data-expanded-colspan="<?= h($expandedDayColumns) ?>">
                  <button type="button" class="day-toggle" data-day-index="<?= h($dayIndex) ?>" aria-expanded="false">
                    <span class="day-label"><?= h(format_date_label($date)) ?></span>
                    <span class="toggle-icon" aria-hidden="true">+</span>
                  </button>
                </th>
              <?php endforeach; ?>
            </tr>
            <tr>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php $dayClass = 'day-' . $dayIndex; ?>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-project-login">Project login (U)</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-leave">Leave code (H)</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-work-code">Work code (W)</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-login">Login</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-logout">Logout</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-work-hrs">Work hrs</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-override-hrs">Override hrs</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-override-code">Override code</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-final-work-code">Final work code</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-final-work-hrs">Final work hrs</th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($employees) && !empty($dateRange)): ?>
              <?php foreach ($employees as $employee): ?>
                <?php
                  $empCode = trim((string) ($employee['emp_code'] ?? ''));
                  $empName = trim((string) ($employee['emp_name'] ?? ''));
                  $designation = trim((string) ($employee['desg_name'] ?? ''));
                  $department = trim((string) ($employee['dept_name'] ?? ''));
                  $costCenterCode = trim((string) ($employee['cc_code'] ?? ''));
                  $costCenterName = trim((string) ($employee['cc_name'] ?? ''));
                  $costCenter = $costCenterName !== '' ? $costCenterName : $costCenterCode;
                  if ($costCenterName !== '' && $costCenterCode !== '' && stripos($costCenterName, $costCenterCode) === false) {
                      $costCenter = $costCenterCode . ' - ' . $costCenterName;
                  }
                  $employeeType = trim((string) ($employee['ty_desc'] ?? ''));
                  $projectCode = trim((string) ($employee['jbno'] ?? ''));
                ?>
                <tr>
                  <td class="col-fixed col-fixed-1"><?= h($empCode) ?></td>
                  <td class="col-fixed col-fixed-2"><?= h($empName) ?></td>
                  <td class="col-adv"><?= h($designation) ?></td>
                  <td class="col-adv"><?= h($department) ?></td>
                  <td class="col-adv"><?= h($costCenter) ?></td>
                  <td class="col-adv"><?= h($employeeType) ?></td>
                  <td class="col-adv"><?= h($projectCode) ?></td>
                  <?php foreach ($dateRange as $dayIndex => $date): ?>
                    <?php $dayClass = 'day-' . $dayIndex; ?>
                    <?php
                      $punch = ($empCode !== '' && isset($dailyPunch[$empCode][$date])) ? $dailyPunch[$empCode][$date] : null;
                      $att = ($empCode !== '' && isset($attDaily[$empCode][$date])) ? $attDaily[$empCode][$date] : null;

                      $firstLog = is_array($punch) ? ($punch['first_log'] ?? null) : null;
                      $lastLog = is_array($punch) ? ($punch['last_log'] ?? null) : null;
                      $firstSn = is_array($punch) ? trim((string) ($punch['first_terminal_sn'] ?? '')) : '';
                      $loginProject = $firstSn !== '' ? trim((string) ($deviceProjectMap[$firstSn] ?? '')) : '';
                      $leaveCode = is_array($att) ? trim((string) ($att['pending_leave_code'] ?? '')) : '';
                      $workCode = is_array($att) ? trim((string) ($att['work_code'] ?? '')) : '';
                      $workHours = format_work_duration($firstLog, $lastLog);
                      $overrideHours = is_array($att) ? trim((string) ($att['override_work_hours'] ?? '')) : '';
                      $overrideCode = is_array($att) ? trim((string) ($att['override_work_code'] ?? '')) : '';
                      $overrideStatus = is_array($att) ? (int) ($att['override_is_approved'] ?? 0) : 0;
                      $overrideClass = '';
                      if ($overrideStatus === 1) {
                          $overrideClass = ' override-approved';
                      } elseif ($overrideStatus === 2) {
                          $overrideClass = ' override-rejected';
                      }
                      $finalWorkCode = ($overrideStatus === 1) ? $overrideCode : $workCode;
                      $finalWorkHours = ($overrideStatus === 1) ? $overrideHours : $workHours;
                    ?>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-project-login"><?= h($loginProject) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-leave"><?= h($leaveCode) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-work-code"><?= h($workCode) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-login"><?= h(format_time_value($firstLog)) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-logout"><?= h(format_time_value($lastLog)) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-work-hrs"><?= h($workHours) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-override-hrs<?= $overrideClass ?>">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        class="override-input override-hours"
                        value="<?= h($overrideHours) ?>"
                        data-override-key="<?= h($empCode . '|' . $date) ?>"
                        data-override-field="hours"
                        data-emp-code="<?= h($empCode) ?>"
                        data-att-date="<?= h($date) ?>"
                        data-original="<?= h($overrideHours) ?>"
                      >
                    </td>
                    <td class="day-col <?= h($dayClass) ?> col-extra col-override-code<?= $overrideClass ?>">
                      <input
                        type="text"
                        class="override-input override-code"
                        value="<?= h($overrideCode) ?>"
                        data-override-key="<?= h($empCode . '|' . $date) ?>"
                        data-override-field="code"
                        data-emp-code="<?= h($empCode) ?>"
                        data-att-date="<?= h($date) ?>"
                        data-original="<?= h($overrideCode) ?>"
                      >
                    </td>
                    <td class="day-col <?= h($dayClass) ?> col-final-work-code"><?= h($finalWorkCode) ?></td>
                    <td class="day-col <?= h($dayClass) ?> col-final-work-hrs"><?= h($finalWorkHours) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="attendance-empty-row">
                <td colspan="<?= h(6 + (count($dateRange) * $collapsedDayColumns)) ?>" class="text-center text-muted">No employees found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <th rowspan="2" class="col-fixed col-fixed-1">Emp Code</th>
              <th rowspan="2" class="col-fixed col-fixed-2">Emp Name</th>
              <th rowspan="2" class="col-adv">Designation</th>
              <th rowspan="2" class="col-adv">Department</th>
              <th rowspan="2" class="col-adv">Cost center company</th>
              <th rowspan="2" class="col-adv">Employee Type</th>
              <th rowspan="2" class="col-adv">Project Code</th>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <th colspan="<?= h($collapsedDayColumns) ?>" class="text-center date-header day-footer-header" data-day-index="<?= h($dayIndex) ?>" data-collapsed-colspan="<?= h($collapsedDayColumns) ?>" data-expanded-colspan="<?= h($expandedDayColumns) ?>">
                  <span class="day-label"><?= h(format_date_label($date)) ?></span>
                </th>
              <?php endforeach; ?>
            </tr>
            <tr>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php $dayClass = 'day-' . $dayIndex; ?>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-project-login">Project login (U)</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-leave">Leave code (H)</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-work-code">Work code (W)</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-login">Login</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-logout">Logout</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-work-hrs">Work hrs</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-override-hrs">Override hrs</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-extra col-override-code">Override code</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-final-work-code">Final work code</th>
                <th class="sub-header day-col <?= h($dayClass) ?> col-final-work-hrs">Final work hrs</th>
              <?php endforeach; ?>
            </tr>
          </tfoot>
          </table>
        </div>
        <?php if (!empty($dateRange)): ?>
          <div class="attendance-day-scroller is-bottom">
            <button type="button" class="day-nav day-nav-prev" aria-label="Previous day">&#8249;</button>
            <div class="day-strip" role="tablist" aria-label="Days">
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php
                  $chipDay = $date;
                  $chipDate = '';
                  try {
                      $chipDt = new DateTimeImmutable($date);
                      $chipDay = $chipDt->format('D');
                      $chipDate = $chipDt->format('d M');
                  } catch (Exception $e) {
                      $chipDay = $date;
                  }
                ?>
                <button type="button" class="day-chip" data-day-index="<?= h($dayIndex) ?>" data-date="<?= h($date) ?>">
                  <span class="chip-day"><?= h($chipDay) ?></span>
                  <span class="chip-date"><?= h($chipDate !== '' ? $chipDate : $date) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="day-nav day-nav-next" aria-label="Next day">&#8250;</button>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="attendance-pager" data-total-pages="<?= h($totalPages) ?>">
          <div class="pager-meta">
            Page <?= h($page) ?> of <?= h($totalPages) ?>
          </div>
          <div class="pager-controls">
            <button type="button" class="pager-btn <?= $page <= 1 ? 'is-disabled' : '' ?>" data-page="<?= h(max(1, $page - 1)) ?>">
              ‹ Prev
            </button>
            <div class="pager-field">
              <input type="number" class="pager-page" min="1" max="<?= h($totalPages) ?>" value="<?= h($page) ?>">
              <span class="text-muted">/ <?= h($totalPages) ?></span>
              <button type="button" class="pager-btn pager-go">Go</button>
            </div>
            <select class="pager-select pager-size">
              <?php foreach ($allowedPerPage as $size): ?>
                <option value="<?= h($size) ?>" <?= (int) $perPage === (int) $size ? 'selected' : '' ?>><?= h($size) ?> / page</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="pager-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>" data-page="<?= h(min($totalPages, $page + 1)) ?>">
              Next ›
            </button>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function export_attendance_csv(
    mysqli $bd,
    array $filters,
    array $params,
    string $types,
    array $dateRange,
    string $startDate,
    string $endDate,
    string $reportType,
    $output,
    ?callable $progress = null,
    int $batchSize = 200
): ?string {
    $reportType = strtolower(trim($reportType));
    if (!in_array($reportType, ['detailed', 'final'], true)) {
        $reportType = 'detailed';
    }

    $baseHeader = ['Emp Code', 'Emp Name', 'Designation', 'Department', 'Cost center company', 'Employee Type', 'Project Code'];
    if ($reportType === 'final') {
        $headerRow1 = $baseHeader;
        $headerRow2 = array_fill(0, count($baseHeader), '');
        foreach ($dateRange as $date) {
            $headerRow1[] = $date;
            $headerRow1[] = '';
            $headerRow2[] = 'Final work code';
            $headerRow2[] = 'Final work hrs';
        }
        fputcsv($output, $headerRow1);
        fputcsv($output, $headerRow2);
    } else {
        $header = $baseHeader;
        foreach ($dateRange as $date) {
            $header[] = $date . ' Project login (U)';
            $header[] = $date . ' Leave code (H)';
            $header[] = $date . ' Work code (W)';
            $header[] = $date . ' Login';
            $header[] = $date . ' Logout';
            $header[] = $date . ' Work hrs';
            $header[] = $date . ' Override hrs';
            $header[] = $date . ' Override code';
            $header[] = $date . ' Final work code';
            $header[] = $date . ' Final work hrs';
        }
        fputcsv($output, $header);
    }

    $exportSql = 'SELECT hr.emp_code, ' .
        'COALESCE(NULLIF(hr.emp_name, ""), NULLIF(hr.name, "")) AS emp_name, ' .
        'hr.desg_name, hr.dept_name, hr.cc_code, hr.cc_name, hr.ty_desc, hr.jbno, hr.jbdesc ' .
        'FROM gcc_attendance_master.hrmsvw_sync hr';
    if (!empty($filters)) {
        $exportSql .= ' WHERE ' . implode(' AND ', $filters);
    }
    $exportSql .= ' ORDER BY CAST(hr.emp_code AS UNSIGNED), hr.emp_code LIMIT ? OFFSET ?';

    $processed = 0;
    $exportOffset = 0;

    while (true) {
        $batchEmployees = [];

        $batchParams = $params;
        $batchTypes = $types;
        $batchParams[] = $batchSize;
        $batchParams[] = $exportOffset;
        $batchTypes .= 'ii';

        $stmt = $bd->prepare($exportSql);
        if (!$stmt) {
            return 'Unable to prepare export query.';
        }
        bind_params($stmt, $batchTypes, $batchParams);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $batchEmployees[] = $row;
                }
                $result->free();
            }
        } else {
            $stmt->close();
            return 'Unable to load employees for export.';
        }
        $stmt->close();

        if (empty($batchEmployees)) {
            break;
        }

        $empCodes = [];
        foreach ($batchEmployees as $row) {
            $code = trim((string) ($row['emp_code'] ?? ''));
            if ($code !== '') {
                $empCodes[] = $code;
            }
        }
        $empCodes = array_values(array_unique($empCodes, SORT_STRING));

        $dailyPunch = [];
        $attDaily = [];
        $deviceProjectMap = [];

        if (!empty($empCodes)) {
            $placeholders = implode(',', array_fill(0, count($empCodes), '?'));
            $rangeTypes = str_repeat('s', count($empCodes)) . 'ss';
            $rangeParams = array_merge($empCodes, [$startDate, $endDate]);

            $punchSql = 'SELECT emp_code, punch_date, first_log, last_log, first_terminal_sn, last_terminal_sn ' .
                'FROM gcc_attendance_master.employee_daily_punch ' .
                'WHERE emp_code IN (' . $placeholders . ') AND punch_date BETWEEN ? AND ?';
            $stmt = $bd->prepare($punchSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['punch_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($dailyPunch[$emp])) {
                                $dailyPunch[$emp] = [];
                            }
                            $dailyPunch[$emp][$date] = $row;
                        }
                        $result->free();
                    }
                } else {
                    $stmt->close();
                    return 'Unable to load daily punches for export.';
                }
                $stmt->close();
            } else {
                return 'Unable to prepare daily punch export query.';
            }

            $attSql = 'SELECT d.emp_code, d.att_date, d.work_code, d.pending_leave_code ' .
                'FROM gcc_attendance_master.employee_att_daily d ' .
                'WHERE d.emp_code IN (' . $placeholders . ') AND d.att_date BETWEEN ? AND ? ' .
                'AND (d.is_delete = 0 OR d.is_delete IS NULL) AND (d.is_deleted = 0 OR d.is_deleted IS NULL)';
            $stmt = $bd->prepare($attSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['att_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($attDaily[$emp])) {
                                $attDaily[$emp] = [];
                            }
                            $attDaily[$emp][$date] = $row;
                        }
                        $result->free();
                    }
                } else {
                    $stmt->close();
                    return 'Unable to load attendance details for export.';
                }
                $stmt->close();
            } else {
                return 'Unable to prepare attendance export query.';
            }

            $overrideSql = 'SELECT o.emp_code, o.att_date, o.override_work_hours, o.override_work_code, o.override_is_approved ' .
                'FROM gcc_attendance_master.employee_att_daily_overrides o ' .
                'WHERE o.emp_code IN (' . $placeholders . ') AND o.att_date BETWEEN ? AND ?';
            $stmt = $bd->prepare($overrideSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['att_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($attDaily[$emp])) {
                                $attDaily[$emp] = [];
                            }
                            if (!isset($attDaily[$emp][$date])) {
                                $attDaily[$emp][$date] = [
                                    'emp_code' => $emp,
                                    'att_date' => $date,
                                    'work_code' => null,
                                    'pending_leave_code' => null,
                                ];
                            }
                            $attDaily[$emp][$date]['override_work_hours'] = $row['override_work_hours'] ?? null;
                            $attDaily[$emp][$date]['override_work_code'] = $row['override_work_code'] ?? null;
                            $attDaily[$emp][$date]['override_is_approved'] = $row['override_is_approved'] ?? null;
                        }
                        $result->free();
                    }
                } else {
                    $stmt->close();
                    return 'Unable to load override details for export.';
                }
                $stmt->close();
            } else {
                return 'Unable to prepare override export query.';
            }

            if (!empty($dailyPunch)) {
                $deviceSn = [];
                foreach ($dailyPunch as $dates) {
                    foreach ($dates as $row) {
                        $sn = trim((string) ($row['first_terminal_sn'] ?? ''));
                        if ($sn !== '') {
                            $deviceSn[$sn] = true;
                        }
                    }
                }
                if (!empty($deviceSn)) {
                    $deviceList = array_keys($deviceSn);
                    $devicePlaceholders = implode(',', array_fill(0, count($deviceList), '?'));
                    $deviceTypes = str_repeat('s', count($deviceList));
                    $deviceSql = 'SELECT d.device_sn, p.pro_code ' .
                        'FROM gcc_attendance_master.device_project_map d ' .
                        'LEFT JOIN gcc_it.projects p ON p.id = d.project_id ' .
                        'WHERE d.device_sn IN (' . $devicePlaceholders . ')';
                    $stmt = $bd->prepare($deviceSql);
                    if ($stmt) {
                        bind_params($stmt, $deviceTypes, $deviceList);
                        if ($stmt->execute()) {
                            $result = $stmt->get_result();
                            if ($result) {
                                while ($row = $result->fetch_assoc()) {
                                    $sn = trim((string) ($row['device_sn'] ?? ''));
                                    if ($sn === '') {
                                        continue;
                                    }
                                    $deviceProjectMap[$sn] = trim((string) ($row['pro_code'] ?? ''));
                                }
                                $result->free();
                            }
                        }
                        $stmt->close();
                    }
                }
            }
        }

        foreach ($batchEmployees as $employee) {
            $empCode = trim((string) ($employee['emp_code'] ?? ''));
            $empName = trim((string) ($employee['emp_name'] ?? ''));
            $designation = trim((string) ($employee['desg_name'] ?? ''));
            $department = trim((string) ($employee['dept_name'] ?? ''));
            $costCenterCode = trim((string) ($employee['cc_code'] ?? ''));
            $costCenterName = trim((string) ($employee['cc_name'] ?? ''));
            $costCenter = $costCenterName !== '' ? $costCenterName : $costCenterCode;
            if ($costCenterName !== '' && $costCenterCode !== '' && stripos($costCenterName, $costCenterCode) === false) {
                $costCenter = $costCenterCode . ' - ' . $costCenterName;
            }
            $employeeType = trim((string) ($employee['ty_desc'] ?? ''));
            $projectCode = trim((string) ($employee['jbno'] ?? ''));

            $row = [
                $empCode,
                $empName,
                $designation,
                $department,
                $costCenter,
                $employeeType,
                $projectCode,
            ];

            foreach ($dateRange as $date) {
                $punch = ($empCode !== '' && isset($dailyPunch[$empCode][$date])) ? $dailyPunch[$empCode][$date] : null;
                $att = ($empCode !== '' && isset($attDaily[$empCode][$date])) ? $attDaily[$empCode][$date] : null;

                $firstLog = is_array($punch) ? ($punch['first_log'] ?? null) : null;
                $lastLog = is_array($punch) ? ($punch['last_log'] ?? null) : null;
                $firstSn = is_array($punch) ? trim((string) ($punch['first_terminal_sn'] ?? '')) : '';
                $loginProject = $firstSn !== '' ? trim((string) ($deviceProjectMap[$firstSn] ?? '')) : '';
                $leaveCode = is_array($att) ? trim((string) ($att['pending_leave_code'] ?? '')) : '';
                $workCode = is_array($att) ? trim((string) ($att['work_code'] ?? '')) : '';
                $workHours = calculate_work_hours($firstLog, $lastLog);
                $overrideHours = is_array($att) ? trim((string) ($att['override_work_hours'] ?? '')) : '';
                $overrideCode = is_array($att) ? trim((string) ($att['override_work_code'] ?? '')) : '';
                $overrideStatus = is_array($att) ? (int) ($att['override_is_approved'] ?? 0) : 0;
                $finalWorkCode = ($overrideStatus === 1) ? $overrideCode : $workCode;
                $finalWorkHours = ($overrideStatus === 1) ? $overrideHours : ($workHours !== null ? $workHours : '');

                $row[] = $finalWorkCode;
                $row[] = $finalWorkHours;
                if ($reportType !== 'final') {
                    array_splice($row, -2, 0, [
                        $loginProject,
                        $leaveCode,
                        $workCode,
                        format_time_value($firstLog),
                        format_time_value($lastLog),
                        $workHours !== null ? $workHours : '',
                        $overrideHours,
                        $overrideCode,
                    ]);
                }
            }

            fputcsv($output, $row);
        }

        $processed += count($batchEmployees);
        if ($progress) {
            $progress($processed);
        }

        $exportOffset += count($batchEmployees);
        if (count($batchEmployees) < $batchSize) {
            break;
        }
    }

    return null;
}

$context = null;

$exportType = strtolower(trim((string) ($_GET['export'] ?? '')));
$reportType = strtolower(trim((string) ($_GET['report'] ?? 'detailed')));
if (!in_array($reportType, ['detailed', 'final'], true)) {
    $reportType = 'detailed';
}
if ($exportType === 'status') {
    export_log('status_request', ['job' => $_GET['job'] ?? null]);
    $jobId = sanitize_export_job_id($_GET['job'] ?? null);
    $job = $jobId ? read_export_job($jobId) : null;
    if (!$jobId || !$job) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        export_log('status_not_found', ['job' => $jobId]);
        echo json_encode(['ok' => false, 'message' => 'Export not found.'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $userId = (string) ($_SESSION['user_id'] ?? '');
    if (($job['user_id'] ?? '') !== $userId) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        export_log('status_forbidden', ['job' => $jobId, 'job_user' => $job['user_id'] ?? null, 'user' => $userId]);
        echo json_encode(['ok' => false, 'message' => 'Export not available.'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $total = (int) ($job['total'] ?? 0);
    $processed = (int) ($job['processed'] ?? 0);
    $status = (string) ($job['status'] ?? 'unknown');
    $percent = $total > 0 ? (int) floor(($processed / $total) * 100) : ($status === 'done' ? 100 : 0);
    if ($percent > 100) {
        $percent = 100;
    }
    header('Content-Type: application/json; charset=utf-8');
    export_log('status_response', ['job' => $jobId, 'status' => $status, 'processed' => $processed, 'total' => $total]);
    echo json_encode([
        'ok' => true,
        'status' => $status,
        'processed' => $processed,
        'total' => $total,
        'percent' => $percent,
        'message' => $job['message'] ?? null,
        'filename' => $job['filename'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($exportType === 'download') {
    export_log('download_request', ['job' => $_GET['job'] ?? null]);
    $jobId = sanitize_export_job_id($_GET['job'] ?? null);
    $job = $jobId ? read_export_job($jobId) : null;
    if (!$jobId || !$job) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        export_log('download_not_found', ['job' => $jobId]);
        echo 'Export not found.';
        exit;
    }
    $userId = (string) ($_SESSION['user_id'] ?? '');
    if (($job['user_id'] ?? '') !== $userId) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        export_log('download_forbidden', ['job' => $jobId, 'job_user' => $job['user_id'] ?? null, 'user' => $userId]);
        echo 'Export not available.';
        exit;
    }
    if (($job['status'] ?? '') !== 'done') {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        export_log('download_not_ready', ['job' => $jobId, 'status' => $job['status'] ?? null]);
        echo 'Export not ready.';
        exit;
    }
    $file = (string) ($job['file'] ?? '');
    if ($file === '' || !is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        export_log('download_file_missing', ['job' => $jobId, 'file' => $file]);
        echo 'Export file missing.';
        exit;
    }
    $filename = (string) ($job['filename'] ?? 'attendance-daily-export.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $size = filesize($file);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    export_log('download_send', ['job' => $jobId, 'file' => $file, 'size' => $size, 'filename' => $filename]);
    session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);

    $bytes = @readfile($file);
    $aborted = connection_aborted() === 1;
    export_log('download_finished', ['job' => $jobId, 'bytes' => $bytes, 'aborted' => $aborted]);

    // Only cleanup when we are confident the download was sent.
    if ($bytes !== false && (int) $bytes > 0 && !$aborted) {
        @unlink($file);
        @unlink(export_job_path($jobId));
        export_log('download_cleanup', ['job' => $jobId, 'file' => $file]);
    } else {
        export_log('download_cleanup_skipped', ['job' => $jobId, 'file' => $file]);
    }
    exit;
}

$today = new DateTimeImmutable('today');
$defaultEnd = $today->format('Y-m-d');
$defaultStart = $today->modify('-13 days')->format('Y-m-d');
$last30Start = $today->modify('-29 days')->format('Y-m-d');
$last60Start = $today->modify('-59 days')->format('Y-m-d');

$designationFilter = normalize_multi_param($_GET['designation'] ?? []);
$departmentFilter = normalize_multi_param($_GET['department'] ?? []);
$projectCodeFilter = normalize_multi_param($_GET['project_code'] ?? []);
$loginProjectFilter = normalize_multi_param($_GET['login_project'] ?? []);
$costCenterFilter = normalize_multi_param($_GET['cost_center'] ?? []);
$employeeTypeFilter = normalize_multi_param($_GET['employee_type'] ?? []);
$employeeIdInput = trim((string) ($_GET['employee_id'] ?? ''));
$employeeIdTerms = normalize_search_terms($employeeIdInput);
$startDate = normalize_date($_GET['start_date'] ?? '', $defaultStart);
$endDate = normalize_date($_GET['end_date'] ?? '', $defaultEnd);
$exportRequested = in_array($exportType, ['1', 'true', 'yes', 'csv'], true);
$exportStart = ($exportType === 'start');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 50);
$allowedPerPage = [25, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}

if ($startDate > $endDate) {
    $swap = $startDate;
    $startDate = $endDate;
    $endDate = $swap;
}

$dateRange = build_date_range($startDate, $endDate);
$collapsedDayColumns = 2;
$expandedDayColumns = 10;

$employees = [];
$dailyPunch = [];
$attDaily = [];
$deviceProjectMap = [];
$departmentOptions = [];
$designationOptions = [];
$projectOptions = [];
$loginProjectOptions = [];
$costCenterOptions = [];
$employeeTypeOptions = [];
$offset = 0;
$totalEmployees = 0;
$totalPages = 1;
$loadError = null;
$mappingRequired = false;
$mappingError = null;
$mappedProjects = [];
$userId = trim((string) ($_SESSION['user_id'] ?? ''));

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    $deptResult = $bd->query(
        'SELECT dept_cd, dept_name FROM gcc_attendance_master.hrms_departments ORDER BY dept_name, dept_cd'
    );
    if ($deptResult) {
        while ($row = $deptResult->fetch_assoc()) {
            $code = trim((string) ($row['dept_cd'] ?? ''));
            if ($code === '') {
                continue;
            }
            $departmentOptions[$code] = trim((string) ($row['dept_name'] ?? ''));
        }
        $deptResult->free();
    }

    $desgResult = $bd->query(
        'SELECT desg_cd, desg_name FROM gcc_attendance_master.hrms_designations ORDER BY desg_name, desg_cd'
    );
    if ($desgResult) {
        while ($row = $desgResult->fetch_assoc()) {
            $code = trim((string) ($row['desg_cd'] ?? ''));
            if ($code === '') {
                continue;
            }
            $designationOptions[$code] = trim((string) ($row['desg_name'] ?? ''));
        }
        $desgResult->free();
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
    $allProjectOptions = $projectOptions;

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

    $costResult = $bd->query(
        'SELECT cc_code, cc_name FROM gcc_attendance_master.hrms_cost_centers ORDER BY cc_name, cc_code'
    );
    if ($costResult) {
        while ($row = $costResult->fetch_assoc()) {
            $code = trim((string) ($row['cc_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $costCenterOptions[$code] = trim((string) ($row['cc_name'] ?? ''));
        }
        $costResult->free();
    }

    $typeResult = $bd->query(
        'SELECT ty_cd, ty_desc FROM gcc_attendance_master.hrms_employee_types ORDER BY ty_desc, ty_cd'
    );
    if ($typeResult) {
        while ($row = $typeResult->fetch_assoc()) {
            $code = trim((string) ($row['ty_cd'] ?? ''));
            if ($code === '') {
                continue;
            }
            $employeeTypeOptions[$code] = trim((string) ($row['ty_desc'] ?? ''));
        }
        $typeResult->free();
    }

    if (!ensure_timekeeper_project_map_table($bd)) {
        $loadError = 'Unable to load project access configuration.';
    } else {
        $rawBody = '';
        $jsonPayload = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $rawBody = file_get_contents('php://input');
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $jsonPayload = $decoded;
                }
            }
        }
        $action = $jsonPayload['action'] ?? ($_POST['action'] ?? '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_inline_overrides') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            if (!verify_csrf($jsonPayload['csrf'] ?? null)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Invalid request token.'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (!ensure_attendance_override_table($bd)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'Override table not available.'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            $changes = $jsonPayload['changes'] ?? null;
            if (!is_array($changes)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Invalid changes payload.'], JSON_UNESCAPED_SLASHES);
                exit;
            }

            $userEmail = trim((string) ($_SESSION['user_email'] ?? ''));
            $userName = trim((string) ($_SESSION['user_name'] ?? ''));
            $changeDate = gmdate('Y-m-d H:i:s');
            $insertSql = 'INSERT INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
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
            $deleteSql = 'DELETE FROM `gcc_attendance_master`.`employee_att_daily_overrides` WHERE emp_code = ? AND att_date = ?';
            $insertStmt = $bd->prepare($insertSql);
            $deleteStmt = $bd->prepare($deleteSql);
            if (!$insertStmt || !$deleteStmt) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'Unable to prepare override update.'], JSON_UNESCAPED_SLASHES);
                exit;
            }

            $errors = [];
            $updated = 0;
            $deleted = 0;
            $seen = [];
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $empCode = trim((string) ($change['empCode'] ?? ''));
                $attDate = trim((string) ($change['attDate'] ?? ''));
                if ($empCode === '' || $attDate === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $attDate)) {
                    $errors[] = 'Invalid employee/date in request.';
                    continue;
                }
                $key = $empCode . '|' . $attDate;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $hoursRaw = $change['overrideWorkHours'] ?? null;
                $hours = null;
                if ($hoursRaw !== null && $hoursRaw !== '') {
                    if (!is_numeric($hoursRaw)) {
                        $errors[] = 'Invalid override hours for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                    $hours = number_format((float) $hoursRaw, 2, '.', '');
                }

                $code = trim((string) ($change['overrideWorkCode'] ?? ''));
                if ($code === '') {
                    $code = null;
                }

                if ($hours === null && $code === null) {
                    $deleteStmt->bind_param('ss', $empCode, $attDate);
                    if ($deleteStmt->execute()) {
                        $deleted++;
                    } else {
                        $errors[] = 'Unable to clear override for ' . $empCode . ' on ' . $attDate . '.';
                    }
                    continue;
                }

                $approved = 0;
                $approvedByEmail = null;
                $approvedByName = null;
                $approvedDate = null;
                $emailParam = $userEmail !== '' ? $userEmail : null;
                $nameParam = $userName !== '' ? $userName : null;
                $insertStmt->bind_param(
                    'sssssssisss',
                    $empCode,
                    $attDate,
                    $hours,
                    $code,
                    $changeDate,
                    $emailParam,
                    $nameParam,
                    $approved,
                    $approvedByEmail,
                    $approvedByName,
                    $approvedDate
                );
                if ($insertStmt->execute()) {
                    $updated++;
                } else {
                    $errors[] = 'Unable to save override for ' . $empCode . ' on ' . $attDate . '.';
                }
            }
            $insertStmt->close();
            $deleteStmt->close();

            $ok = empty($errors);
            if (!$ok) {
                http_response_code(400);
            }
            echo json_encode([
                'ok' => $ok,
                'updated' => $updated,
                'deleted' => $deleted,
                'errors' => $errors,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_project_mapping') {
            if (!verify_csrf($_POST['csrf'] ?? null)) {
                $mappingError = 'Invalid request token.';
            } else {
                $selected = normalize_multi_param($_POST['mapped_projects'] ?? []);
                $valid = array_values(array_intersect($selected, array_keys($allProjectOptions ?? [])));
                if (empty($valid)) {
                    $mappingError = 'Select at least one project.';
                } else {
                    $stmt = $bd->prepare(
                        'INSERT IGNORE INTO gcc_attendance_master.timekeeper_project_map (user_id, project_code) VALUES (?, ?)'
                    );
                    if (!$stmt) {
                        $mappingError = 'Unable to save project access.';
                    } else {
                        foreach ($valid as $code) {
                            $stmt->bind_param('ss', $userId, $code);
                            if (!$stmt->execute()) {
                                $mappingError = 'Unable to save project access.';
                                break;
                            }
                        }
                        $stmt->close();
                    }
                }
            }
            if ($mappingError === null) {
                header('Location: ' . admin_url('timekeeper_attendance_view.php'));
                exit;
            }
        }

        $mappedProjects = load_timekeeper_projects($bd, $userId);
        if (empty($mappedProjects)) {
            $mappingRequired = true;
        }
    }

    if (!$loadError && !$mappingRequired) {
        $mappedProjectSet = array_fill_keys($mappedProjects, true);
        if (!empty($projectCodeFilter)) {
            $projectCodeFilter = array_values(array_intersect($projectCodeFilter, $mappedProjects));
        }
        if (!empty($loginProjectFilter)) {
            $loginProjectFilter = array_values(array_intersect($loginProjectFilter, $mappedProjects));
        }
        if (!empty($mappedProjects)) {
            $projectOptions = array_intersect_key($projectOptions, $mappedProjectSet);
            $loginProjectOptions = array_intersect_key($loginProjectOptions, $mappedProjectSet);
        }

        $filters = ['hr.is_deleted = 0', 'hr.st_code = "A"'];
        $params = [];
        $types = '';

    if (!empty($designationFilter)) {
        $placeholders = implode(',', array_fill(0, count($designationFilter), '?'));
        $filters[] = 'hr.desg_cd IN (' . $placeholders . ')';
        $params = array_merge($params, $designationFilter);
        $types .= str_repeat('s', count($designationFilter));
    }
    if (!empty($departmentFilter)) {
        $placeholders = implode(',', array_fill(0, count($departmentFilter), '?'));
        $filters[] = 'hr.dept_cd IN (' . $placeholders . ')';
        $params = array_merge($params, $departmentFilter);
        $types .= str_repeat('s', count($departmentFilter));
    }
    if (!empty($projectCodeFilter)) {
        $placeholders = implode(',', array_fill(0, count($projectCodeFilter), '?'));
        $filters[] = 'hr.jbno IN (' . $placeholders . ')';
        $params = array_merge($params, $projectCodeFilter);
        $types .= str_repeat('s', count($projectCodeFilter));
    }
    if (!empty($loginProjectFilter)) {
        $placeholders = implode(',', array_fill(0, count($loginProjectFilter), '?'));
        $filters[] = 'hr.emp_code IN (' .
            'SELECT DISTINCT dp.emp_code ' .
            'FROM gcc_attendance_master.employee_daily_punch dp ' .
            'LEFT JOIN gcc_attendance_master.device_project_map d ON d.device_sn = dp.first_terminal_sn ' .
            'LEFT JOIN gcc_it.projects p ON p.id = d.project_id ' .
            'WHERE dp.punch_date BETWEEN ? AND ? AND p.pro_code IN (' . $placeholders . ')' .
            ')';
        $params = array_merge($params, [$startDate, $endDate], $loginProjectFilter);
        $types .= 'ss' . str_repeat('s', count($loginProjectFilter));
    }
    if (!empty($costCenterFilter)) {
        $placeholders = implode(',', array_fill(0, count($costCenterFilter), '?'));
        $filters[] = 'hr.cc_code IN (' . $placeholders . ')';
        $params = array_merge($params, $costCenterFilter);
        $types .= str_repeat('s', count($costCenterFilter));
    }
    if (!empty($employeeTypeFilter)) {
        $placeholders = implode(',', array_fill(0, count($employeeTypeFilter), '?'));
        $filters[] = 'hr.ty_cd IN (' . $placeholders . ')';
        $params = array_merge($params, $employeeTypeFilter);
        $types .= str_repeat('s', count($employeeTypeFilter));
    }
    if (!empty($employeeIdTerms)) {
        $likeParts = [];
        foreach ($employeeIdTerms as $term) {
            $likeParts[] = 'hr.emp_code LIKE ?';
            $params[] = '%' . $term . '%';
            $types .= 's';
        }
        if (!empty($likeParts)) {
            $filters[] = '(' . implode(' OR ', $likeParts) . ')';
        }
    }

        if (!empty($mappedProjects)) {
            $mapPlaceholders = implode(',', array_fill(0, count($mappedProjects), '?'));
            $filters[] = '(' .
                'hr.jbno IN (' . $mapPlaceholders . ') ' .
                'OR hr.emp_code IN (' .
                    'SELECT DISTINCT dp.emp_code ' .
                    'FROM gcc_attendance_master.employee_daily_punch dp ' .
                    'LEFT JOIN gcc_attendance_master.device_project_map d ON d.device_sn = dp.first_terminal_sn ' .
                    'LEFT JOIN gcc_it.projects p ON p.id = d.project_id ' .
                    'WHERE dp.punch_date BETWEEN ? AND ? AND p.pro_code IN (' . $mapPlaceholders . ')' .
                ')' .
            ')';
            $params = array_merge($params, $mappedProjects, [$startDate, $endDate], $mappedProjects);
            $types .= str_repeat('s', count($mappedProjects)) . 'ss' . str_repeat('s', count($mappedProjects));
        }

        $countSql = 'SELECT COUNT(*) AS total ' .
            'FROM gcc_attendance_master.hrmsvw_sync hr';
        if (!empty($filters)) {
            $countSql .= ' WHERE ' . implode(' AND ', $filters);
        }

        $countStmt = $bd->prepare($countSql);
        if ($countStmt) {
            bind_params($countStmt, $types, $params);
            if ($countStmt->execute()) {
                $result = $countStmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    if ($row && isset($row['total'])) {
                        $totalEmployees = (int) $row['total'];
                    }
                    $result->free();
                }
            }
            $countStmt->close();
        }

    $totalPages = max(1, (int) ceil(max(0, $totalEmployees) / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT hr.emp_code, ' .
            'COALESCE(NULLIF(hr.emp_name, ""), NULLIF(hr.name, "")) AS emp_name, ' .
            'hr.desg_name, hr.dept_name, hr.cc_code, hr.cc_name, hr.ty_desc, hr.jbno, hr.jbdesc ' .
            'FROM gcc_attendance_master.hrmsvw_sync hr';
        if (!empty($filters)) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= ' ORDER BY CAST(hr.emp_code AS UNSIGNED), hr.emp_code LIMIT ? OFFSET ?';

    $listParams = $params;
    $listTypes = $types;
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $listTypes .= 'ii';

        $stmt = $bd->prepare($sql);
        if ($stmt) {
            bind_params($stmt, $listTypes, $listParams);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $employees[] = $row;
                    }
                    $result->free();
                }
            } else {
                $loadError = 'Unable to load employees.';
            }
            $stmt->close();
        } else {
            $loadError = 'Unable to prepare employee query.';
        }

        if (!$loadError && !empty($employees) && !empty($dateRange)) {
        $empCodes = [];
        foreach ($employees as $row) {
            $code = trim((string) ($row['emp_code'] ?? ''));
            if ($code !== '') {
                $empCodes[] = $code;
            }
        }
        $empCodes = array_values(array_unique($empCodes, SORT_STRING));

        if (!empty($empCodes)) {
            $placeholders = implode(',', array_fill(0, count($empCodes), '?'));
            $rangeTypes = str_repeat('s', count($empCodes)) . 'ss';
            $rangeParams = array_merge($empCodes, [$startDate, $endDate]);

            $punchSql = 'SELECT emp_code, punch_date, first_log, last_log, first_terminal_sn, last_terminal_sn ' .
                'FROM gcc_attendance_master.employee_daily_punch ' .
                'WHERE emp_code IN (' . $placeholders . ') AND punch_date BETWEEN ? AND ?';
            $stmt = $bd->prepare($punchSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['punch_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($dailyPunch[$emp])) {
                                $dailyPunch[$emp] = [];
                            }
                            $dailyPunch[$emp][$date] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $attSql = 'SELECT d.emp_code, d.att_date, d.work_code, d.pending_leave_code ' .
                'FROM gcc_attendance_master.employee_att_daily d ' .
                'WHERE d.emp_code IN (' . $placeholders . ') AND d.att_date BETWEEN ? AND ? ' .
                'AND (d.is_delete = 0 OR d.is_delete IS NULL) AND (d.is_deleted = 0 OR d.is_deleted IS NULL)';
            $stmt = $bd->prepare($attSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['att_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($attDaily[$emp])) {
                                $attDaily[$emp] = [];
                            }
                            $attDaily[$emp][$date] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $overrideSql = 'SELECT o.emp_code, o.att_date, o.override_work_hours, o.override_work_code, o.override_is_approved ' .
                'FROM gcc_attendance_master.employee_att_daily_overrides o ' .
                'WHERE o.emp_code IN (' . $placeholders . ') AND o.att_date BETWEEN ? AND ?';
            $stmt = $bd->prepare($overrideSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            $date = trim((string) ($row['att_date'] ?? ''));
                            if ($emp === '' || $date === '') {
                                continue;
                            }
                            if (!isset($attDaily[$emp])) {
                                $attDaily[$emp] = [];
                            }
                            if (!isset($attDaily[$emp][$date])) {
                                $attDaily[$emp][$date] = [
                                    'emp_code' => $emp,
                                    'att_date' => $date,
                                    'work_code' => null,
                                    'pending_leave_code' => null,
                                ];
                            }
                            $attDaily[$emp][$date]['override_work_hours'] = $row['override_work_hours'] ?? null;
                            $attDaily[$emp][$date]['override_work_code'] = $row['override_work_code'] ?? null;
                            $attDaily[$emp][$date]['override_is_approved'] = $row['override_is_approved'] ?? null;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $deviceSn = [];
            foreach ($dailyPunch as $emp => $dates) {
                foreach ($dates as $date => $row) {
                    $sn = trim((string) ($row['first_terminal_sn'] ?? ''));
                    if ($sn !== '') {
                        $deviceSn[$sn] = true;
                    }
                }
            }
            if (!empty($deviceSn)) {
                $deviceList = array_keys($deviceSn);
                $devicePlaceholders = implode(',', array_fill(0, count($deviceList), '?'));
                $deviceTypes = str_repeat('s', count($deviceList));
                $deviceSql = 'SELECT d.device_sn, p.pro_code ' .
                    'FROM gcc_attendance_master.device_project_map d ' .
                    'LEFT JOIN gcc_it.projects p ON p.id = d.project_id ' .
                    'WHERE d.device_sn IN (' . $devicePlaceholders . ')';
                $stmt = $bd->prepare($deviceSql);
                if ($stmt) {
                    bind_params($stmt, $deviceTypes, $deviceList);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $sn = trim((string) ($row['device_sn'] ?? ''));
                                if ($sn === '') {
                                    continue;
                                }
                                $deviceProjectMap[$sn] = trim((string) ($row['pro_code'] ?? ''));
                            }
                            $result->free();
                        }
                    }
                    $stmt->close();
                }
            }
        }
        }
    }
}

if ($totalEmployees === 0 && !empty($employees)) {
    $totalEmployees = count($employees);
    $totalPages = 1;
    $page = 1;
    $offset = 0;
}

$baseQuery = [
    'cost_center' => $costCenterFilter,
    'employee_type' => $employeeTypeFilter,
    'department' => $departmentFilter,
    'designation' => $designationFilter,
    'project_code' => $projectCodeFilter,
    'login_project' => $loginProjectFilter,
    'employee_id' => $employeeIdInput,
    'per_page' => $perPage,
    'start_date' => $startDate,
    'end_date' => $endDate,
];
$exportDetailedUrl = build_query_url(array_merge($baseQuery, [
    'export' => 'csv',
    'report' => 'detailed',
]));
$exportFinalUrl = build_query_url(array_merge($baseQuery, [
    'export' => 'csv',
    'report' => 'final',
]));
$exportStartDetailedUrl = build_query_url(array_merge($baseQuery, [
    'export' => 'start',
    'report' => 'detailed',
]));
$exportStartFinalUrl = build_query_url(array_merge($baseQuery, [
    'export' => 'start',
    'report' => 'final',
]));
$last30DaysUrl = build_query_url(array_merge($baseQuery, [
    'start_date' => $last30Start,
    'end_date' => $defaultEnd,
    'page' => 1,
]));
$last60DaysUrl = build_query_url(array_merge($baseQuery, [
    'start_date' => $last60Start,
    'end_date' => $defaultEnd,
    'page' => 1,
]));
$isLast30Days = ($startDate === $last30Start && $endDate === $defaultEnd);
$isLast60Days = ($startDate === $last60Start && $endDate === $defaultEnd);
$pageLinks = build_page_window($page, $totalPages);
$showingStart = $totalEmployees > 0 ? ($offset + 1) : 0;
$showingEnd = $totalEmployees > 0 ? min($offset + count($employees), $totalEmployees) : 0;
$prefetchUrls = [];
if ($totalPages > 1) {
    if ($page > 1) {
        $prefetchUrls[] = build_query_url(array_merge($baseQuery, ['page' => $page - 1]));
    }
    if ($page < $totalPages) {
        $prefetchUrls[] = build_query_url(array_merge($baseQuery, ['page' => $page + 1]));
    }
}

$context = [
    'employees' => $employees,
    'dateRange' => $dateRange,
    'dailyPunch' => $dailyPunch,
    'attDaily' => $attDaily,
    'deviceProjectMap' => $deviceProjectMap,
    'collapsedDayColumns' => $collapsedDayColumns,
    'expandedDayColumns' => $expandedDayColumns,
    'page' => $page,
    'totalPages' => $totalPages,
    'totalEmployees' => $totalEmployees,
    'showingStart' => $showingStart,
    'showingEnd' => $showingEnd,
    'perPage' => $perPage,
    'allowedPerPage' => $allowedPerPage,
    'last30DaysUrl' => $last30DaysUrl,
    'last60DaysUrl' => $last60DaysUrl,
    'isLast30Days' => $isLast30Days,
    'isLast60Days' => $isLast60Days,
    'exportDetailedUrl' => $exportDetailedUrl,
    'exportFinalUrl' => $exportFinalUrl,
    'exportStartDetailedUrl' => $exportStartDetailedUrl,
    'exportStartFinalUrl' => $exportStartFinalUrl,
];

if ($exportStart) {
    // Ensure clean JSON output (avoid warnings/notices corrupting the response)
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    export_log('export_start_request', [
        'report' => $reportType,
        'date_start' => $startDate,
        'date_end' => $endDate,
    ]);

    if ($mappingRequired) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        export_log('export_start_blocked', ['reason' => 'mapping_required']);
        echo json_encode(['ok' => false, 'message' => 'Project access is not configured.'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($loadError !== null) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        export_log('export_start_blocked', ['reason' => 'load_error', 'message' => $loadError]);
        echo json_encode(['ok' => false, 'message' => $loadError], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $jobId = bin2hex(random_bytes(16));
    $exportDir = export_job_dir();
    if (!is_dir($exportDir) || !is_writable($exportDir)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        export_log('export_start_blocked', ['reason' => 'dir_not_writable', 'dir' => $exportDir]);
        echo json_encode(['ok' => false, 'message' => 'Export folder is not writable.'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $reportLabel = ($reportType === 'final') ? 'final' : 'detailed';
    $filename = 'attendance-daily-' . $reportLabel . '-' . $startDate . '-to-' . $endDate . '.csv';
    $csvPath = export_job_csv_path($jobId);
    $createdAt = gmdate('c');
    $userId = (string) ($_SESSION['user_id'] ?? '');

    $job = [
        'id' => $jobId,
        'status' => 'running',
        'total' => $totalEmployees,
        'processed' => 0,
        'user_id' => $userId,
        'file' => $csvPath,
        'filename' => $filename,
        'report' => $reportType,
        'created_at' => $createdAt,
        'message' => null,
    ];
    write_export_job($jobId, $job);
    export_log('export_start', [
        'job' => $jobId,
        'report' => $reportType,
        'file' => $csvPath,
        'total' => $totalEmployees,
    ]);

    $payload = json_encode([
        'ok' => true,
        'job' => $jobId,
        'total' => $totalEmployees,
        'statusUrl' => build_query_url(['export' => 'status', 'job' => $jobId]),
        'downloadUrl' => build_query_url(['export' => 'download', 'job' => $jobId]),
        'filename' => $filename,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        $payload = '{"ok":false,"message":"Unable to start export."}';
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    echo $payload;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }

    session_write_close();
    ignore_user_abort(true);
    set_time_limit(0);

    $output = fopen($csvPath, 'w');
    if ($output === false) {
        $job['status'] = 'error';
        $job['message'] = 'Unable to create export file.';
        write_export_job($jobId, $job);
        export_log('export_error', ['job' => $jobId, 'message' => $job['message']]);
        exit;
    }

    $progress = function (int $processed) use (&$job, $jobId): void {
        $job['processed'] = $processed;
        $job['status'] = 'running';
        write_export_job($jobId, $job);
    };
    $exportError = export_attendance_csv($bd, $filters, $params, $types, $dateRange, $startDate, $endDate, $reportType, $output, $progress);
    fclose($output);

    if ($exportError !== null) {
        $job['status'] = 'error';
        $job['message'] = $exportError;
        write_export_job($jobId, $job);
        export_log('export_error', ['job' => $jobId, 'message' => $exportError]);
        exit;
    }

    $job['status'] = 'done';
    $job['processed'] = $totalEmployees;
    write_export_job($jobId, $job);
    export_log('export_done', ['job' => $jobId, 'total' => $totalEmployees]);
    exit;
}

if ($exportRequested) {
    if ($mappingRequired) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Project access is not configured.';
        exit;
    }
    if ($loadError !== null) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $loadError;
        exit;
    }

    $reportLabel = ($reportType === 'final') ? 'final' : 'detailed';
    $filename = 'attendance-daily-' . $reportLabel . '-' . $startDate . '-to-' . $endDate . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit;
    }

    $exportError = export_attendance_csv($bd, $filters, $params, $types, $dateRange, $startDate, $endDate, $reportType, $output);
    if ($exportError !== null) {
        fputcsv($output, ['ERROR', $exportError]);
    }

    fclose($output);
    exit;
}

if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if ($mappingRequired) {
        echo json_encode(['ok' => false, 'message' => 'Project access is not configured.'], JSON_UNESCAPED_SLASHES);
    } elseif ($loadError) {
        echo json_encode(['ok' => false, 'message' => $loadError], JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode(['ok' => true, 'html' => render_attendance_results($context)], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

include dirname(__DIR__) . '/admin/include/layout_top.php';

?>

<link rel="stylesheet" href="plugins/select2/css/select2.min.css">
<link rel="stylesheet" href="plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">

<style>
  .attendance-daily-table th,
  .attendance-daily-table td {
    white-space: nowrap;
    vertical-align: top;
  }
  .attendance-daily-table thead th {
    background: #f8f9fa;
  }
  .attendance-daily-table tfoot th {
    background: #f8f9fa;
  }
  .attendance-daily-table .date-header {
    font-size: 0.85rem;
  }
  .attendance-daily-table .sub-header {
    font-size: 0.75rem;
    font-weight: 600;
  }
  .attendance-day-scroller {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(90deg, #f8fafc, #eef2f7);
  }
  .attendance-day-scroller.is-bottom {
    border-top: 1px solid rgba(15, 23, 42, 0.08);
    border-bottom: 0;
  }
  .attendance-day-scroller .day-strip {
    display: flex;
    gap: 0.5rem;
    overflow-x: auto;
    padding: 0.2rem 0.1rem;
    scroll-behavior: smooth;
    scrollbar-width: none;
    flex: 1;
    cursor: grab;
  }
  .attendance-day-scroller .day-strip.is-dragging {
    cursor: grabbing;
    user-select: none;
  }
  .attendance-day-scroller .day-strip.is-centered {
    justify-content: center;
  }
  .attendance-day-scroller .day-strip::-webkit-scrollbar {
    display: none;
  }
  .attendance-day-scroller .day-chip {
    border: 1px solid rgba(15, 23, 42, 0.15);
    background: #fff;
    border-radius: 999px;
    padding: 0.35rem 0.75rem;
    min-width: 72px;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 0.1rem;
    font-weight: 600;
    font-size: 0.75rem;
    color: #0f172a;
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08);
    transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
  }
  .attendance-day-scroller .day-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.12);
  }
  .attendance-day-scroller .day-chip.is-active {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.18);
  }
  .attendance-day-scroller .chip-day {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }
  .attendance-day-scroller .chip-date {
    font-size: 0.8rem;
  }
  .attendance-day-scroller .day-nav {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: none;
    background: #0f172a;
    color: #fff;
    font-size: 1.1rem;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.2);
    transition: transform 0.15s ease, opacity 0.15s ease;
  }
  .attendance-day-scroller .day-nav:hover {
    transform: scale(1.04);
  }
  .attendance-day-scroller .day-nav:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    box-shadow: none;
  }
  .attendance-scroll {
    scroll-behavior: smooth;
  }
  .attendance-daily-table .day-header {
    border-right: 3px solid #b6c2cf;
    border-left: 3px solid #b6c2cf;
  }
  .attendance-daily-table .day-footer-header {
    border-right: 3px solid #b6c2cf;
    border-left: 3px solid #b6c2cf;
  }
  .attendance-daily-table th,
  .attendance-daily-table td {
    text-align: center;
    vertical-align: middle;
  }
  .attendance-daily-table .day-header.is-active {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.14), rgba(37, 99, 235, 0.04));
    box-shadow: inset 0 -2px 0 rgba(37, 99, 235, 0.35);
  }
  .attendance-daily-table .day-header.is-active .day-label {
    font-weight: 700;
  }
  .attendance-daily-table .day-col.col-final-work-hrs {
    border-right: 3px solid #b6c2cf;
  }
  .attendance-daily-table thead th.col-final-work-code,
  .attendance-daily-table thead th.col-final-work-hrs,
  .attendance-daily-table tfoot th.col-final-work-code,
  .attendance-daily-table tfoot th.col-final-work-hrs {
    background: #e9e9e9;
    color: #000;
  }
  .attendance-daily-table .day-col.day-expanded.col-project-login {
    border-left: 3px solid #b6c2cf;
  }
  .attendance-daily-table .day-col.col-final-work-code:not(.day-expanded) {
    border-left: 3px solid #b6c2cf;
  }
  .attendance-daily-table .day-col.day-expanded.col-work-hrs {
    border-right: 1px solid #dee2e6;
  }
  .attendance-daily-table .day-col.day-expanded.col-override-hrs,
  .attendance-daily-table .day-col.day-expanded.col-override-code,
  .attendance-daily-table .day-col.day-expanded.col-final-work-code {
    border-right: 1px solid #dee2e6;
  }
  .attendance-daily-table .day-col.day-expanded.col-final-work-hrs {
    border-right: 3px solid #b6c2cf;
  }
  .attendance-daily-table .override-approved {
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.18), rgba(16, 185, 129, 0.05));
  }
  .attendance-daily-table .override-rejected {
    background: linear-gradient(90deg, rgba(239, 68, 68, 0.18), rgba(239, 68, 68, 0.05));
  }
  .attendance-daily-table .day-col.col-extra {
    display: none;
  }
  .attendance-daily-table .day-col.col-extra.day-expanded {
    display: table-cell;
  }
  .attendance-daily-table .col-adv {
    display: none;
  }
  .attendance-daily-table .col-adv.is-visible {
    display: table-cell;
  }
  .attendance-daily-table .meta-toggle {
    margin-left: 0.4rem;
    padding: 0 0.4rem;
    border-radius: 4px;
    border: 1px solid #adb5bd;
    background: #f8f9fa;
    font-weight: 700;
    line-height: 1.2;
  }
  .attendance-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(90deg, rgba(15, 23, 42, 0.06), rgba(15, 23, 42, 0));
  }
  .attendance-pager .pager-meta {
    order: 2;
    margin-left: auto;
    font-weight: 600;
    color: #0f172a;
  }
  .attendance-pager .pager-controls {
    order: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }
  .attendance-quick-ranges {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.35rem;
    margin-left: auto;
  }
  .attendance-quick-ranges .btn-group {
    flex-wrap: wrap;
    justify-content: flex-end;
  }
  .override-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .override-input {
    width: 100%;
    min-width: 64px;
    padding: 2px 6px;
    border-radius: 6px;
    border: 1px solid rgba(15, 23, 42, 0.2);
    background: transparent;
    font-size: 0.78rem;
    text-align: center;
  }
  .override-input:focus {
    outline: none;
    background: #fff;
    border-color: rgba(59, 130, 246, 0.6);
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
  }
  .override-input.is-dirty {
    border-color: rgba(249, 115, 22, 0.7);
    background: rgba(255, 237, 213, 0.7);
  }
  .attendance-pager .pager-btn {
    border: none;
    padding: 0.45rem 0.9rem;
    border-radius: 10px;
    background: #0f172a;
    color: #f8fafc;
    font-weight: 600;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .attendance-pager .pager-btn:disabled,
  .attendance-pager .pager-btn.is-disabled {
    opacity: 0.4;
    pointer-events: none;
  }
  .attendance-pager .pager-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.2);
  }
  .attendance-pager .pager-field {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.6rem;
    border-radius: 10px;
    border: 1px solid rgba(15, 23, 42, 0.2);
    background: #fff;
  }
  .attendance-pager .pager-field input {
    width: 64px;
    border: none;
    font-weight: 600;
  }
  .attendance-pager .pager-select {
    border-radius: 10px;
    border: 1px solid rgba(15, 23, 42, 0.2);
    padding: 0.35rem 0.6rem;
    font-weight: 600;
  }
  .attendance-daily-table .day-toggle {
    background: none;
    border: none;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
  }
  .attendance-daily-table .day-toggle .toggle-icon {
    margin-left: 0.35rem;
    font-weight: 700;
  }
  .attendance-daily-table .col-fixed {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 4;
    box-shadow: 4px 0 10px rgba(15, 23, 42, 0.08);
  }
  .attendance-daily-table .col-fixed-1 {
    min-width: 90px;
    width: 90px;
    left: 0;
    z-index: 5;
  }
  .attendance-daily-table .col-fixed-2 {
    min-width: 240px;
    width: 240px;
    left: 90px;
    z-index: 5;
  }
  .attendance-daily-table thead .col-fixed {
    z-index: 6;
  }
  .select2-container {
    width: 100% !important;
  }
  .export-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(8, 12, 20, 0.72);
    backdrop-filter: blur(4px);
    z-index: 1060;
  }
  .export-overlay.is-active {
    display: flex;
  }
  .export-modal {
    position: relative;
    background: #0b1220;
    color: #f8f9fa;
    padding: 24px 28px;
    border-radius: 18px;
    min-width: 320px;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
    border: 1px solid rgba(255, 255, 255, 0.08);
    overflow: hidden;
  }
  .export-modal::before {
    content: '';
    position: absolute;
    inset: -60px;
    background: radial-gradient(circle at top, rgba(0, 209, 255, 0.25), transparent 60%);
    opacity: 0.8;
    pointer-events: none;
    z-index: 0;
  }
  .export-modal > * {
    position: relative;
    z-index: 1;
  }
  .export-title {
    font-weight: 600;
    font-size: 1.1rem;
    letter-spacing: 0.02em;
  }
  .export-ring {
    --progress: 0;
    width: 170px;
    height: 170px;
    margin: 16px auto 12px;
    border-radius: 50%;
    background: conic-gradient(#00d2ff calc(var(--progress) * 1%), #1b2a44 0);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 24px rgba(0, 210, 255, 0.35);
    transition: background 0.3s ease, box-shadow 0.3s ease;
  }
  .export-ring.is-complete {
    background: conic-gradient(#00e676 100%, #00e676 0);
    box-shadow: 0 0 28px rgba(0, 230, 118, 0.45);
  }
  .export-ring-inner {
    width: 122px;
    height: 122px;
    border-radius: 50%;
    background: #0b1220;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }
  .export-percent {
    font-size: 1.5rem;
    font-weight: 700;
  }
  .export-count {
    font-size: 0.8rem;
    color: #a7b3c2;
    margin-top: 4px;
  }
  .export-status {
    font-size: 0.9rem;
    color: #c8d3e0;
  }
  .export-actions {
    margin-top: 14px;
  }
  .export-overlay.is-active .export-modal {
    animation: exportPop 0.4s ease;
  }
  @keyframes exportPop {
    0% { transform: scale(0.96); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
  }
  @media (max-width: 576px) {
    .export-modal {
      width: 90%;
      min-width: 0;
      padding: 20px 18px;
    }
    .export-ring {
      width: 140px;
      height: 140px;
    }
    .export-ring-inner {
      width: 104px;
      height: 104px;
    }
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Attendance Daily</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'timekeeper'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php endif; ?>

    <?php if ($mappingRequired): ?>
      <div class="card mb-3">
        <div class="card-header">
          <h3 class="card-title">Project access setup</h3>
        </div>
        <div class="card-body">
          <p class="text-muted mb-3">Select the projects you belong to. This is a one-time setup and cannot be changed here.</p>
          <?php if ($mappingError): ?>
            <div class="alert alert-warning"><?= h($mappingError) ?></div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_project_mapping">
            <div class="form-group">
              <label for="mapped_projects">Projects</label>
              <select id="mapped_projects" name="mapped_projects[]" class="form-control js-searchable" data-placeholder="Select projects" multiple>
                <?php foreach (($allProjectOptions ?? []) as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Save project access</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <?php if (!empty($mappedProjects)): ?>
        <div class="alert alert-info mb-3 small">Project access: <?= h(implode(', ', $mappedProjects)) ?></div>
      <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form method="get" class="form-row">
          <div class="form-group col-md-3">
            <label for="cost_center">Cost center company</label>
            <select id="cost_center" name="cost_center[]" class="form-control js-searchable" data-placeholder="All" multiple>
              <?php foreach ($costCenterOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= in_array($code, $costCenterFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="employee_type">Employee type</label>
            <select id="employee_type" name="employee_type[]" class="form-control js-searchable" data-placeholder="All" multiple>
              <?php foreach ($employeeTypeOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= in_array($code, $employeeTypeFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="department">Department</label>
            <select id="department" name="department[]" class="form-control js-searchable" data-placeholder="All" multiple>
              <?php foreach ($departmentOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= in_array($code, $departmentFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="designation">Designation</label>
            <select id="designation" name="designation[]" class="form-control js-searchable" data-placeholder="All" multiple>
              <?php foreach ($designationOptions as $code => $name): ?>
                <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                <option value="<?= h($code) ?>" <?= in_array($code, $designationFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="project_code">Allocated Project Code</label>
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
          <div class="form-group col-md-2">
            <label for="employee_id">Employee ID</label>
            <input id="employee_id" name="employee_id" class="form-control" value="<?= h($employeeIdInput) ?>" placeholder="Employee ID">
          </div>
          <div class="form-group col-md-2">
            <label for="start_date">Start date</label>
            <input id="start_date" type="date" name="start_date" class="form-control" value="<?= h($startDate) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="end_date">End date</label>
            <input id="end_date" type="date" name="end_date" class="form-control" value="<?= h($endDate) ?>">
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Apply</button>
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-secondary btn-block" href="<?= h(admin_url('timekeeper_attendance_view.php')) ?>">Reset</a>
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-success btn-block export-btn" href="<?= h($exportDetailedUrl) ?>" data-export-start="<?= h($exportStartDetailedUrl) ?>">Export Detailed</a>
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <a class="btn btn-outline-primary btn-block export-btn" href="<?= h($exportFinalUrl) ?>" data-export-start="<?= h($exportStartFinalUrl) ?>">Export Final</a>
          </div>
        </form>
        <div class="small text-muted">
          Default week: <?= h($defaultStart) ?> to <?= h($defaultEnd) ?> |
          Showing <?= $showingStart ?>-<?= $showingEnd ?> of <?= $totalEmployees ?> employee(s) |
          Page <?= $page ?> of <?= $totalPages ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h3 class="card-title">Weekly attendance</h3>
        <div class="attendance-quick-ranges">
          <span class="text-muted small"><?= $showingStart ?>-<?= $showingEnd ?> of <?= $totalEmployees ?> employees | <?= count($dateRange) ?> day(s)</span>
          <div class="btn-group btn-group-sm" role="group" aria-label="Quick ranges">
            <a class="btn <?= $isLast30Days ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= $last30DaysUrl ?>">Last 30 days</a>
            <a class="btn <?= $isLast60Days ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= $last60DaysUrl ?>">Last 60 days</a>
          </div>
          <div class="override-actions">
            <button type="button" class="btn btn-sm btn-success js-save-overrides">Save overrides</button>
            <span class="text-muted small override-save-status" aria-live="polite"></span>
          </div>
        </div>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="attendance-pager" data-total-pages="<?= $totalPages ?>">
          <div class="pager-meta">
            Showing <?= $showingStart ?>-<?= $showingEnd ?> of <?= $totalEmployees ?> employees
          </div>
          <div class="pager-controls">
            <button type="button" class="pager-btn <?= $page <= 1 ? 'is-disabled' : '' ?>" data-page="<?= max(1, $page - 1) ?>">
              ‹ Prev
            </button>
            <div class="pager-field">
              <input type="number" class="pager-page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>">
              <span class="text-muted">/ <?= $totalPages ?></span>
              <button type="button" class="pager-btn pager-go">Go</button>
            </div>
            <select class="pager-select pager-size">
              <?php foreach ($allowedPerPage as $size): ?>
                <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> / page</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="pager-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>" data-page="<?= min($totalPages, $page + 1) ?>">
              Next ›
            </button>
          </div>
        </div>
      <?php endif; ?>
      <div class="card-body p-0">
        <?php if (!empty($dateRange)): ?>
          <div class="attendance-day-scroller">
            <button type="button" class="day-nav day-nav-prev" aria-label="Previous day">&#8249;</button>
            <div class="day-strip" role="tablist" aria-label="Days">
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php
                  $chipDay = $date;
                  $chipDate = '';
                  try {
                      $chipDt = new DateTimeImmutable($date);
                      $chipDay = $chipDt->format('D');
                      $chipDate = $chipDt->format('d M');
                  } catch (Exception $e) {
                      $chipDay = $date;
                  }
                ?>
                <button type="button" class="day-chip" data-day-index="<?= h($dayIndex) ?>" data-date="<?= h($date) ?>">
                  <span class="chip-day"><?= h($chipDay) ?></span>
                  <span class="chip-date"><?= h($chipDate !== '' ? $chipDate : $date) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="day-nav day-nav-next" aria-label="Next day">&#8250;</button>
          </div>
        <?php endif; ?>
        <div class="table-responsive attendance-scroll">
          <table class="table table-bordered table-sm attendance-daily-table">
          <thead>
            <tr>
              <th rowspan="2" class="col-fixed col-fixed-1">Emp Code</th>
              <th rowspan="2" class="col-fixed col-fixed-2">
                Emp Name
                <button type="button" class="meta-toggle" id="toggleMetaColumns" aria-expanded="false" title="Show details">+</button>
              </th>
              <th rowspan="2" class="col-adv">Designation</th>
              <th rowspan="2" class="col-adv">Department</th>
              <th rowspan="2" class="col-adv">Cost center company</th>
              <th rowspan="2" class="col-adv">Employee Type</th>
              <th rowspan="2" class="col-adv">Project Code</th>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <th colspan="<?= $collapsedDayColumns ?>" class="text-center date-header day-header" data-day-index="<?= $dayIndex ?>" data-collapsed-colspan="<?= $collapsedDayColumns ?>" data-expanded-colspan="<?= $expandedDayColumns ?>">
                  <button type="button" class="day-toggle" data-day-index="<?= $dayIndex ?>" aria-expanded="false">
                    <span class="day-label"><?= h(format_date_label($date)) ?></span>
                    <span class="toggle-icon" aria-hidden="true">+</span>
                  </button>
                </th>
              <?php endforeach; ?>
            </tr>
            <tr>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php $dayClass = 'day-' . $dayIndex; ?>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-project-login">Project login (U)</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-leave">Leave code (H)</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-work-code">Work code (W)</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-login">Login</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-logout">Logout</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-work-hrs">Work hrs</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-override-hrs">Override hrs</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-override-code">Override code</th>
                <th class="sub-header day-col <?= $dayClass ?> col-final-work-code">Final work code</th>
                <th class="sub-header day-col <?= $dayClass ?> col-final-work-hrs">Final work hrs</th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($employees) && !empty($dateRange)): ?>
              <?php foreach ($employees as $employee): ?>
                <?php
                  $empCode = trim((string) ($employee['emp_code'] ?? ''));
                  $empName = trim((string) ($employee['emp_name'] ?? ''));
                  $designation = trim((string) ($employee['desg_name'] ?? ''));
                  $department = trim((string) ($employee['dept_name'] ?? ''));
                  $costCenterCode = trim((string) ($employee['cc_code'] ?? ''));
                  $costCenterName = trim((string) ($employee['cc_name'] ?? ''));
                  $costCenter = $costCenterName !== '' ? $costCenterName : $costCenterCode;
                  if ($costCenterName !== '' && $costCenterCode !== '' && stripos($costCenterName, $costCenterCode) === false) {
                      $costCenter = $costCenterCode . ' - ' . $costCenterName;
                  }
                  $employeeType = trim((string) ($employee['ty_desc'] ?? ''));
                  $projectCode = trim((string) ($employee['jbno'] ?? ''));
                ?>
                <tr>
                  <td class="col-fixed col-fixed-1"><?= h($empCode) ?></td>
                  <td class="col-fixed col-fixed-2"><?= h($empName) ?></td>
                  <td class="col-adv"><?= h($designation) ?></td>
                  <td class="col-adv"><?= h($department) ?></td>
                  <td class="col-adv"><?= h($costCenter) ?></td>
                  <td class="col-adv"><?= h($employeeType) ?></td>
                  <td class="col-adv"><?= h($projectCode) ?></td>
                  <?php foreach ($dateRange as $dayIndex => $date): ?>
                    <?php $dayClass = 'day-' . $dayIndex; ?>
                    <?php
                      $punch = ($empCode !== '' && isset($dailyPunch[$empCode][$date])) ? $dailyPunch[$empCode][$date] : null;
                      $att = ($empCode !== '' && isset($attDaily[$empCode][$date])) ? $attDaily[$empCode][$date] : null;

                      $firstLog = is_array($punch) ? ($punch['first_log'] ?? null) : null;
                      $lastLog = is_array($punch) ? ($punch['last_log'] ?? null) : null;
                      $firstSn = is_array($punch) ? trim((string) ($punch['first_terminal_sn'] ?? '')) : '';
                      $loginProject = $firstSn !== '' ? trim((string) ($deviceProjectMap[$firstSn] ?? '')) : '';
                      $leaveCode = is_array($att) ? trim((string) ($att['pending_leave_code'] ?? '')) : '';
                      $workCode = is_array($att) ? trim((string) ($att['work_code'] ?? '')) : '';
                      $workHours = format_work_duration($firstLog, $lastLog);
                      $overrideHours = is_array($att) ? trim((string) ($att['override_work_hours'] ?? '')) : '';
                      $overrideCode = is_array($att) ? trim((string) ($att['override_work_code'] ?? '')) : '';
                      $overrideStatus = is_array($att) ? (int) ($att['override_is_approved'] ?? 0) : 0;
                      $overrideClass = '';
                      if ($overrideStatus === 1) {
                          $overrideClass = ' override-approved';
                      } elseif ($overrideStatus === 2) {
                          $overrideClass = ' override-rejected';
                      }
                      $finalWorkCode = ($overrideStatus === 1) ? $overrideCode : $workCode;
                      $finalWorkHours = ($overrideStatus === 1) ? $overrideHours : $workHours;
                    ?>
                    <td class="day-col <?= $dayClass ?> col-extra col-project-login"><?= h($loginProject) ?></td>
                    <td class="day-col <?= $dayClass ?> col-extra col-leave"><?= h($leaveCode) ?></td>
                    <td class="day-col <?= $dayClass ?> col-extra col-work-code"><?= h($workCode) ?></td>
                    <td class="day-col <?= $dayClass ?> col-extra col-login"><?= h(format_time_value($firstLog)) ?></td>
                    <td class="day-col <?= $dayClass ?> col-extra col-logout"><?= h(format_time_value($lastLog)) ?></td>
                    <td class="day-col <?= $dayClass ?> col-extra col-work-hrs"><?= h($workHours) ?></td>
                    <td class="day-col <?= $dayClass ?> col-extra col-override-hrs<?= $overrideClass ?>">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        class="override-input override-hours"
                        value="<?= h($overrideHours) ?>"
                        data-override-key="<?= h($empCode . '|' . $date) ?>"
                        data-override-field="hours"
                        data-emp-code="<?= h($empCode) ?>"
                        data-att-date="<?= h($date) ?>"
                        data-original="<?= h($overrideHours) ?>"
                      >
                    </td>
                    <td class="day-col <?= $dayClass ?> col-extra col-override-code<?= $overrideClass ?>">
                      <input
                        type="text"
                        class="override-input override-code"
                        value="<?= h($overrideCode) ?>"
                        data-override-key="<?= h($empCode . '|' . $date) ?>"
                        data-override-field="code"
                        data-emp-code="<?= h($empCode) ?>"
                        data-att-date="<?= h($date) ?>"
                        data-original="<?= h($overrideCode) ?>"
                      >
                    </td>
                    <td class="day-col <?= $dayClass ?> col-final-work-code"><?= h($finalWorkCode) ?></td>
                    <td class="day-col <?= $dayClass ?> col-final-work-hrs"><?= h($finalWorkHours) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr class="attendance-empty-row">
                <td colspan="<?= 6 + (count($dateRange) * $collapsedDayColumns) ?>" class="text-center text-muted">No employees found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <th rowspan="2" class="col-fixed col-fixed-1">Emp Code</th>
              <th rowspan="2" class="col-fixed col-fixed-2">Emp Name</th>
              <th rowspan="2" class="col-adv">Designation</th>
              <th rowspan="2" class="col-adv">Department</th>
              <th rowspan="2" class="col-adv">Cost center company</th>
              <th rowspan="2" class="col-adv">Employee Type</th>
              <th rowspan="2" class="col-adv">Project Code</th>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <th colspan="<?= $collapsedDayColumns ?>" class="text-center date-header day-footer-header" data-day-index="<?= $dayIndex ?>" data-collapsed-colspan="<?= $collapsedDayColumns ?>" data-expanded-colspan="<?= $expandedDayColumns ?>">
                  <span class="day-label"><?= h(format_date_label($date)) ?></span>
                </th>
              <?php endforeach; ?>
            </tr>
            <tr>
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php $dayClass = 'day-' . $dayIndex; ?>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-project-login">Project login (U)</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-leave">Leave code (H)</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-work-code">Work code (W)</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-login">Login</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-logout">Logout</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-work-hrs">Work hrs</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-override-hrs">Override hrs</th>
                <th class="sub-header day-col <?= $dayClass ?> col-extra col-override-code">Override code</th>
                <th class="sub-header day-col <?= $dayClass ?> col-final-work-code">Final work code</th>
                <th class="sub-header day-col <?= $dayClass ?> col-final-work-hrs">Final work hrs</th>
              <?php endforeach; ?>
            </tr>
          </tfoot>
          </table>
        </div>
        <?php if (!empty($dateRange)): ?>
          <div class="attendance-day-scroller is-bottom">
            <button type="button" class="day-nav day-nav-prev" aria-label="Previous day">&#8249;</button>
            <div class="day-strip" role="tablist" aria-label="Days">
              <?php foreach ($dateRange as $dayIndex => $date): ?>
                <?php
                  $chipDay = $date;
                  $chipDate = '';
                  try {
                      $chipDt = new DateTimeImmutable($date);
                      $chipDay = $chipDt->format('D');
                      $chipDate = $chipDt->format('d M');
                  } catch (Exception $e) {
                      $chipDay = $date;
                  }
                ?>
                <button type="button" class="day-chip" data-day-index="<?= h($dayIndex) ?>" data-date="<?= h($date) ?>">
                  <span class="chip-day"><?= h($chipDay) ?></span>
                  <span class="chip-date"><?= h($chipDate !== '' ? $chipDate : $date) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="day-nav day-nav-next" aria-label="Next day">&#8250;</button>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="attendance-pager" data-total-pages="<?= $totalPages ?>">
          <div class="pager-meta">
            Page <?= $page ?> of <?= $totalPages ?>
          </div>
          <div class="pager-controls">
            <button type="button" class="pager-btn <?= $page <= 1 ? 'is-disabled' : '' ?>" data-page="<?= max(1, $page - 1) ?>">
              ‹ Prev
            </button>
            <div class="pager-field">
              <input type="number" class="pager-page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>">
              <span class="text-muted">/ <?= $totalPages ?></span>
              <button type="button" class="pager-btn pager-go">Go</button>
            </div>
            <select class="pager-select pager-size">
              <?php foreach ($allowedPerPage as $size): ?>
                <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> / page</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="pager-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>" data-page="<?= min($totalPages, $page + 1) ?>">
              Next ›
            </button>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<div id="exportOverlay" class="export-overlay" aria-hidden="true">
  <div class="export-modal" role="dialog" aria-modal="true" aria-labelledby="exportTitle">
    <div class="export-title" id="exportTitle">Preparing export</div>
    <div class="export-ring" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
      <div class="export-ring-inner">
        <div class="export-percent">0%</div>
        <div class="export-count">0 / 0 employees</div>
      </div>
    </div>
    <div class="export-status" aria-live="polite">Starting export...</div>
    <div class="export-actions">
      <button type="button" class="btn btn-outline-light btn-sm" id="exportClose">Close</button>
    </div>
  </div>
</div>

<script defer src="plugins/select2/js/select2.full.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
      return;
    }
    jQuery('.js-searchable').each(function () {
      const $select = jQuery(this);
      const isMultiple = $select.prop('multiple');
      $select.select2({
        theme: 'bootstrap4',
        width: '100%',
        allowClear: true,
        placeholder: $select.data('placeholder') || 'All',
        minimumResultsForSearch: 0,
        closeOnSelect: !isMultiple,
      });
    });

  });
</script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const table = document.querySelector('.attendance-daily-table');
    if (!table) {
      return;
    }
    const emptyCell = table.querySelector('.attendance-empty-row td');
    const updateEmptyColspan = () => {
      if (!emptyCell) {
        return;
      }
      const headerRow = table.querySelector('thead tr');
      if (!headerRow) {
        return;
      }
      let total = 0;
      headerRow.querySelectorAll('th').forEach((th) => {
        if (th.classList.contains('col-adv') && !th.classList.contains('is-visible')) {
          return;
        }
        const span = Number(th.getAttribute('colspan')) || 1;
        total += span;
      });
      if (total > 0) {
        emptyCell.setAttribute('colspan', String(total));
      }
    };
    let refreshDayScroller = () => {};
    const setDayExpanded = (dayIndex, expanded) => {
      const dayCols = table.querySelectorAll(`.day-${dayIndex}`);
      dayCols.forEach((el) => {
        el.classList.toggle('day-expanded', expanded);
      });
      const headers = table.querySelectorAll(`.day-header[data-day-index="${dayIndex}"], .day-footer-header[data-day-index="${dayIndex}"]`);
      headers.forEach((header) => {
        const expandedColspan = header.getAttribute('data-expanded-colspan') || '7';
        const collapsedColspan = header.getAttribute('data-collapsed-colspan') || '2';
        header.setAttribute('colspan', expanded ? expandedColspan : collapsedColspan);
      });
      const toggle = table.querySelector(`.day-toggle[data-day-index="${dayIndex}"]`);
      if (toggle) {
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        const icon = toggle.querySelector('.toggle-icon');
        if (icon) {
          icon.textContent = expanded ? '-' : '+';
        }
      }
      refreshDayScroller();
    };
    table.querySelectorAll('.day-toggle').forEach((toggle) => {
      toggle.addEventListener('click', function () {
        const dayIndex = this.getAttribute('data-day-index');
        const expanded = this.getAttribute('aria-expanded') === 'true';
        setDayExpanded(dayIndex, !expanded);
        setActiveDay(Number(dayIndex), true);
        updateEmptyColspan();
      });
    });
    updateEmptyColspan();

    const scrollWrap = document.querySelector('.attendance-scroll');
    const dayHeaders = Array.from(table.querySelectorAll('.day-header'));
    const dayScrollerGroups = Array.from(document.querySelectorAll('.attendance-day-scroller'))
      .map((scroller) => ({
        scroller,
        strip: scroller.querySelector('.day-strip'),
        chips: Array.from(scroller.querySelectorAll('.day-chip')),
        prevBtn: scroller.querySelector('.day-nav-prev'),
        nextBtn: scroller.querySelector('.day-nav-next'),
      }))
      .filter((group) => group.strip && group.chips.length);
    const dayCount = dayScrollerGroups.length ? dayScrollerGroups[0].chips.length : 0;
    let activeDayIndex = 0;
    let scrollRaf = null;

    const updateDayStripAlignment = () => {
      dayScrollerGroups.forEach((group) => {
        if (!group.strip) {
          return;
        }
        const shouldCenter = group.strip.scrollWidth <= group.strip.clientWidth + 2;
        group.strip.classList.toggle('is-centered', shouldCenter);
      });
    };

    const enableDayStripScroll = (strip) => {
      if (!strip) {
        return;
      }
      let isDragging = false;
      let dragStartX = 0;
      let dragStartScroll = 0;
      let dragMoved = false;
      strip.addEventListener(
        'wheel',
        (event) => {
          if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
            return;
          }
          strip.scrollLeft += event.deltaY;
          event.preventDefault();
        },
        { passive: false }
      );
      strip.addEventListener('mousedown', (event) => {
        if (event.button !== 0) {
          return;
        }
        isDragging = true;
        dragMoved = false;
        dragStartX = event.clientX;
        dragStartScroll = strip.scrollLeft;
        strip.classList.add('is-dragging');
      });
      strip.addEventListener('mousemove', (event) => {
        if (!isDragging) {
          return;
        }
        const delta = event.clientX - dragStartX;
        if (Math.abs(delta) > 3) {
          dragMoved = true;
        }
        strip.scrollLeft = dragStartScroll - delta;
        if (dragMoved) {
          event.preventDefault();
        }
      });
      const stopDrag = () => {
        if (!isDragging) {
          return;
        }
        isDragging = false;
        strip.classList.remove('is-dragging');
      };
      strip.addEventListener('mouseup', stopDrag);
      strip.addEventListener('mouseleave', stopDrag);
      strip.addEventListener('click', (event) => {
        if (dragMoved) {
          event.preventDefault();
          event.stopPropagation();
        }
      });
    };

    const getStickyWidth = () => {
      let width = 0;
      table.querySelectorAll('thead .col-fixed').forEach((cell) => {
        width += cell.offsetWidth;
      });
      return width;
    };

    const scrollChipIntoView = (strip, chip) => {
      if (!strip || !chip) {
        return;
      }
      const chipLeft = chip.offsetLeft;
      const chipRight = chipLeft + chip.offsetWidth;
      const visibleLeft = strip.scrollLeft;
      const visibleRight = visibleLeft + strip.clientWidth;
      const padding = 16;
      if (chipLeft < visibleLeft + padding) {
        strip.scrollTo({ left: Math.max(0, chipLeft - padding), behavior: 'smooth' });
      } else if (chipRight > visibleRight - padding) {
        strip.scrollTo({ left: Math.max(0, chipRight - strip.clientWidth + padding), behavior: 'smooth' });
      }
    };

    const setActiveDay = (index, ensureChip = true) => {
      if (!dayCount) {
        return;
      }
      const bounded = Math.max(0, Math.min(index, dayCount - 1));
      activeDayIndex = bounded;
      dayScrollerGroups.forEach((group) => {
        group.chips.forEach((chip, idx) => {
          chip.classList.toggle('is-active', idx === bounded);
        });
        if (group.prevBtn) {
          group.prevBtn.disabled = bounded <= 0;
        }
        if (group.nextBtn) {
          group.nextBtn.disabled = bounded >= dayCount - 1;
        }
        if (ensureChip && group.strip && group.chips[bounded]) {
          scrollChipIntoView(group.strip, group.chips[bounded]);
        }
      });
      if (dayHeaders.length) {
        dayHeaders.forEach((header, idx) => {
          header.classList.toggle('is-active', idx === bounded);
        });
      }
      updateDayStripAlignment();
    };

    const edgePadding = 12;
    const scrollToDay = (index) => {
      if (!scrollWrap || !dayHeaders.length) {
        return;
      }
      const bounded = Math.max(0, Math.min(index, dayHeaders.length - 1));
      const header = dayHeaders[bounded];
      if (!header) {
        return;
      }
      const stickyWidth = getStickyWidth();
      const target = header.offsetLeft - stickyWidth - edgePadding;
      scrollWrap.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
      setActiveDay(bounded);
      updateDayStripAlignment();
    };

    const updateActiveFromScroll = () => {
      if (!scrollWrap || !dayHeaders.length) {
        return;
      }
      const stickyWidth = getStickyWidth();
      const marker = scrollWrap.scrollLeft + stickyWidth + edgePadding;
      let index = 0;
      dayHeaders.forEach((header, idx) => {
        if (header.offsetLeft <= marker) {
          index = idx;
        }
      });
      setActiveDay(index, true);
      updateDayStripAlignment();
    };

    if (scrollWrap && dayCount && dayHeaders.length) {
      dayScrollerGroups.forEach((group) => {
        group.chips.forEach((chip, idx) => {
          chip.addEventListener('click', () => scrollToDay(idx));
        });
        if (group.prevBtn) {
          group.prevBtn.addEventListener('click', () => scrollToDay(activeDayIndex - 1));
        }
        if (group.nextBtn) {
          group.nextBtn.addEventListener('click', () => scrollToDay(activeDayIndex + 1));
        }
      });
      scrollWrap.addEventListener('scroll', () => {
        if (scrollRaf) {
          return;
        }
        scrollRaf = window.requestAnimationFrame(() => {
          scrollRaf = null;
          updateActiveFromScroll();
        });
      });
      window.addEventListener('resize', () => {
        updateActiveFromScroll();
        updateDayStripAlignment();
      });
      refreshDayScroller = updateActiveFromScroll;
      updateActiveFromScroll();
    }

    dayScrollerGroups.forEach((group) => enableDayStripScroll(group.strip));
    updateDayStripAlignment();

    const metaToggle = document.getElementById('toggleMetaColumns');
    const metaCells = table.querySelectorAll('.col-adv');
    const setMetaVisible = (visible) => {
      metaCells.forEach((cell) => {
        cell.classList.toggle('is-visible', visible);
      });
      if (metaToggle) {
        metaToggle.textContent = visible ? '−' : '+';
        metaToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
        metaToggle.setAttribute('title', visible ? 'Hide details' : 'Show details');
      }
      updateEmptyColspan();
    };
    setMetaVisible(false);
    if (metaToggle) {
      metaToggle.addEventListener('click', function () {
        setMetaVisible(!metaCells[0]?.classList.contains('is-visible'));
      });
    }

    const pagerBase = <?= json_encode($baseQuery) ?>;
    const pagerUrl = <?= json_encode(admin_url('timekeeper_attendance_view.php')) ?>;
    const buildPagerUrl = (pageValue, perPageValue) => {
      const params = new URLSearchParams();
      Object.keys(pagerBase).forEach((key) => {
        const value = pagerBase[key];
        if (Array.isArray(value)) {
          value.forEach((item) => {
            if (item !== '') {
              params.append(`${key}[]`, item);
            }
          });
        } else if (value !== '' && value !== null && value !== undefined) {
          params.append(key, value);
        }
      });
      if (pageValue) {
        params.set('page', String(pageValue));
      }
      if (perPageValue) {
        params.set('per_page', String(perPageValue));
      }
      const query = params.toString();
      return query ? `${pagerUrl}?${query}` : pagerUrl;
    };
    document.querySelectorAll('.attendance-pager').forEach((pager) => {
      const pageInput = pager.querySelector('.pager-page');
      const perSelect = pager.querySelector('.pager-size');
      const goBtn = pager.querySelector('.pager-go');
      pager.querySelectorAll('[data-page]').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (btn.classList.contains('is-disabled')) {
            return;
          }
          const target = Number(btn.getAttribute('data-page'));
          const size = perSelect ? Number(perSelect.value) : null;
          window.location.href = buildPagerUrl(target, size);
        });
      });
      if (goBtn && pageInput) {
        goBtn.addEventListener('click', () => {
          const target = Number(pageInput.value);
          if (!Number.isFinite(target) || target < 1) {
            return;
          }
          const size = perSelect ? Number(perSelect.value) : null;
          window.location.href = buildPagerUrl(target, size);
        });
      }
      if (perSelect) {
        perSelect.addEventListener('change', () => {
          const target = pageInput ? Number(pageInput.value) : 1;
          window.location.href = buildPagerUrl(target || 1, Number(perSelect.value));
        });
      }
    });

    const overrideCsrf = <?= json_encode(csrf_token()) ?>;
    const overrideInputs = Array.from(document.querySelectorAll('.override-input'));
    const overrideButtons = Array.from(document.querySelectorAll('.js-save-overrides'));
    const overrideStatusEls = Array.from(document.querySelectorAll('.override-save-status'));

    const setOverrideStatus = (message, isError = false) => {
      overrideStatusEls.forEach((el) => {
        el.textContent = message;
        el.style.color = isError ? '#b91c1c' : '';
      });
    };

    const updateDirtyState = (input) => {
      const original = String(input.dataset.original || '').trim();
      const current = String(input.value || '').trim();
      input.classList.toggle('is-dirty', current !== original);
    };

    overrideInputs.forEach((input) => {
      updateDirtyState(input);
      input.addEventListener('input', () => updateDirtyState(input));
      input.addEventListener('change', () => updateDirtyState(input));
    });

    const collectOverrideChanges = () => {
      const groups = new Map();
      overrideInputs.forEach((input) => {
        const key = input.dataset.overrideKey || '';
        if (key === '') {
          return;
        }
        if (!groups.has(key)) {
          groups.set(key, { hours: null, code: null });
        }
        const entry = groups.get(key);
        if (input.dataset.overrideField === 'hours') {
          entry.hours = input;
        } else if (input.dataset.overrideField === 'code') {
          entry.code = input;
        }
      });

      const changes = [];
      groups.forEach((entry) => {
        const hoursInput = entry.hours;
        const codeInput = entry.code;
        const hoursValue = hoursInput ? String(hoursInput.value || '').trim() : '';
        const codeValue = codeInput ? String(codeInput.value || '').trim() : '';
        const hoursOriginal = hoursInput ? String(hoursInput.dataset.original || '').trim() : '';
        const codeOriginal = codeInput ? String(codeInput.dataset.original || '').trim() : '';
        if (hoursValue === hoursOriginal && codeValue === codeOriginal) {
          return;
        }
        const empCode = hoursInput?.dataset.empCode || codeInput?.dataset.empCode || '';
        const attDate = hoursInput?.dataset.attDate || codeInput?.dataset.attDate || '';
        if (!empCode || !attDate) {
          return;
        }
        changes.push({
          empCode,
          attDate,
          overrideWorkHours: hoursValue === '' ? null : hoursValue,
          overrideWorkCode: codeValue === '' ? null : codeValue,
        });
      });
      return changes;
    };

    const setButtonsDisabled = (disabled) => {
      overrideButtons.forEach((btn) => {
        btn.disabled = disabled;
      });
    };

    const saveOverrides = () => {
      const changes = collectOverrideChanges();
      if (!changes.length) {
        setOverrideStatus('No changes to save.');
        return;
      }
      setButtonsDisabled(true);
      setOverrideStatus('Saving...');
      fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_inline_overrides',
          csrf: overrideCsrf,
          changes,
        }),
        credentials: 'same-origin',
      })
        .then((response) => response.json().catch(() => null))
        .then((data) => {
          if (!data || data.ok !== true) {
            const message = data?.message || (Array.isArray(data?.errors) ? data.errors[0] : '') || 'Unable to save overrides.';
            setOverrideStatus(message, true);
            setButtonsDisabled(false);
            return;
          }
          setOverrideStatus('Overrides saved. Reloading...');
          window.setTimeout(() => {
            window.location.reload();
          }, 600);
        })
        .catch(() => {
          setOverrideStatus('Unable to save overrides.', true);
          setButtonsDisabled(false);
        });
    };

    overrideButtons.forEach((btn) => {
      btn.addEventListener('click', saveOverrides);
    });
  });
</script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const exportButtons = Array.from(document.querySelectorAll('.export-btn'));
    const overlay = document.getElementById('exportOverlay');
    if (!exportButtons.length || !overlay) {
      return;
    }
    const ring = overlay.querySelector('.export-ring');
    const percentEl = overlay.querySelector('.export-percent');
    const countEl = overlay.querySelector('.export-count');
    const statusEl = overlay.querySelector('.export-status');
    const closeBtn = document.getElementById('exportClose');

    let pollTimer = null;
    let activeJob = null;
    let activeBtn = null;

    const showOverlay = () => {
      overlay.classList.add('is-active');
      overlay.setAttribute('aria-hidden', 'false');
    };
    const hideOverlay = () => {
      overlay.classList.remove('is-active');
      overlay.setAttribute('aria-hidden', 'true');
    };
    const setProgress = (processed, total, percent) => {
      const safeTotal = Number.isFinite(total) ? total : 0;
      const safeProcessed = Number.isFinite(processed) ? processed : 0;
      const safePercent = Number.isFinite(percent)
        ? Math.max(0, Math.min(100, Math.round(percent)))
        : (safeTotal > 0 ? Math.round((safeProcessed / safeTotal) * 100) : 0);
      if (ring) {
        ring.style.setProperty('--progress', String(safePercent));
        ring.setAttribute('aria-valuenow', String(safePercent));
      }
      if (percentEl) {
        percentEl.textContent = `${safePercent}%`;
      }
      if (countEl) {
        countEl.textContent = `${safeProcessed} / ${safeTotal} employees`;
      }
    };
    const stopPolling = () => {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    };
    const parseJsonResponse = (response) => {
      return response.text().then((text) => {
        if (!text) {
          throw new Error('Empty response from server.');
        }
        try {
          return JSON.parse(text);
        } catch (err) {
          const snippet = text.length > 200 ? `${text.slice(0, 200)}...` : text;
          throw new Error(`Invalid JSON response: ${snippet}`);
        }
      });
    };
    const pollStatus = () => {
      if (!activeJob || !activeJob.statusUrl) {
        return;
      }
      fetch(activeJob.statusUrl, { credentials: 'same-origin' })
        .then(parseJsonResponse)
        .then((data) => {
          if (!data || !data.ok) {
            throw new Error(data && data.message ? data.message : 'Unable to read export status.');
          }
          setProgress(Number(data.processed), Number(data.total), Number(data.percent));
          if (data.status === 'done') {
            stopPolling();
            const downloadUrl = activeJob && activeJob.downloadUrl;
            if (activeBtn) {
              activeBtn.classList.remove('is-loading');
            }
            activeBtn = null;
            activeJob = null;
            if (ring) {
              ring.classList.add('is-complete');
            }
            if (statusEl) {
              statusEl.textContent = 'Download starting...';
            }
            if (downloadUrl) {
              setTimeout(() => {
                window.location.href = downloadUrl;
              }, 400);
            }
            setTimeout(hideOverlay, 1800);
            return;
          }
          if (data.status === 'error') {
            stopPolling();
            if (activeBtn) {
              activeBtn.classList.remove('is-loading');
            }
            activeBtn = null;
            activeJob = null;
            if (statusEl) {
              statusEl.textContent = data.message || 'Export failed.';
            }
            return;
          }
          if (statusEl) {
            statusEl.textContent = 'Exporting data...';
          }
        })
        .catch((error) => {
          stopPolling();
          if (activeBtn) {
            activeBtn.classList.remove('is-loading');
          }
          activeBtn = null;
          activeJob = null;
          if (statusEl) {
            statusEl.textContent = error && error.message ? error.message : 'Export failed.';
          }
        });
    };

    exportButtons.forEach((btn) => {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        if (activeBtn) {
          return;
        }
        const startUrl = btn.getAttribute('data-export-start');
        if (!startUrl) {
          window.location.href = btn.getAttribute('href');
          return;
        }
        activeBtn = btn;
        activeBtn.classList.add('is-loading');
        showOverlay();
        if (ring) {
          ring.classList.remove('is-complete');
        }
        setProgress(0, 0, 0);
        if (statusEl) {
          statusEl.textContent = 'Starting export...';
        }

        fetch(startUrl, { credentials: 'same-origin' })
          .then(parseJsonResponse)
          .then((data) => {
            if (!data || !data.ok) {
              throw new Error(data && data.message ? data.message : 'Unable to start export.');
            }
            activeJob = {
              job: data.job,
              statusUrl: data.statusUrl,
              downloadUrl: data.downloadUrl,
            };
            setProgress(0, Number(data.total), 0);
            pollStatus();
            pollTimer = setInterval(pollStatus, 900);
          })
          .catch((error) => {
            if (activeBtn) {
              activeBtn.classList.remove('is-loading');
            }
            if (statusEl) {
              statusEl.textContent = error && error.message ? error.message : 'Unable to start export.';
            }
            setTimeout(() => {
              window.location.href = btn.getAttribute('href');
              hideOverlay();
            }, 1200);
            activeBtn = null;
            activeJob = null;
          });
      });
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        hideOverlay();
      });
    }
  });
</script>
<script>
  window.addEventListener('load', function () {
    const urls = <?= json_encode(array_values($prefetchUrls)) ?>;
    if (!urls || !urls.length || typeof fetch !== 'function') {
      return;
    }
    const schedule = window.requestIdleCallback || function (cb) { setTimeout(cb, 500); };
    schedule(function () {
      urls.forEach(function (url) {
        try {
          fetch(url, { credentials: 'same-origin' });
        } catch (e) {
          // Ignore prefetch failures.
        }
      });
    });
  });
</script>

<?php include dirname(__DIR__) . '/admin/include/layout_bottom.php'; ?>
