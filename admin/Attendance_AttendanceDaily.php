<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Monthly Attendance';

$monthParam = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

$displayStart = $monthParam . '-01';
$displayEnd = date('Y-m-t', strtotime($displayStart));

$exportStart = trim((string) ($_GET['export_start'] ?? $displayStart));
$exportEnd = trim((string) ($_GET['export_end'] ?? $displayEnd));
$export = isset($_GET['export']) && $_GET['export'] !== '';

$displayStart = normalize_date($displayStart) ?: date('Y-m-01');
$displayEnd = normalize_date($displayEnd) ?: date('Y-m-t');

$exportStart = normalize_date($exportStart) ?: $displayStart;
$exportEnd = normalize_date($exportEnd) ?: $displayEnd;

if ($exportStart > $exportEnd) {
    $swap = $exportStart;
    $exportStart = $exportEnd;
    $exportEnd = $swap;
}

$attendanceDb = resolve_attendance_db();
ensure_attendance_tables($bd, $attendanceDb);

if ($export) {
    $exportDates = build_date_range($exportStart, $exportEnd);
    $exportRows = load_attendance_rows($bd, $attendanceDb, $exportStart, $exportEnd);
    $exportRows = apply_hrms_names($exportRows);
    output_csv($exportRows, $exportDates, $exportStart, $exportEnd);
    exit;
}

$displayGrid = build_month_grid($displayStart);
$rows = load_attendance_rows($bd, $attendanceDb, $displayStart, $displayEnd);
$rows = apply_hrms_names($rows);

include __DIR__ . '/include/layout_top.php';

?>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Monthly Attendance</h1>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php include __DIR__ . '/include/admin_nav.php'; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Filters</h3>
      </div>
      <div class="card-body">
        <form class="row" method="get">
          <div class="form-group col-md-3">
            <label for="month">Month</label>
            <input id="month" name="month" type="month" class="form-control" value="<?= h($monthParam) ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="export_start">Export start</label>
            <input id="export_start" name="export_start" type="date" class="form-control" value="<?= h($exportStart) ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="export_end">Export end</label>
            <input id="export_end" name="export_end" type="date" class="form-control" value="<?= h($exportEnd) ?>">
          </div>
          <div class="form-group col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary mr-2">Apply</button>
            <button type="submit" name="export" value="1" class="btn btn-outline-secondary">Export CSV</button>
          </div>
        </form>
        <p class="text-muted small mb-0">
          Showing <?= h($displayStart) ?> to <?= h($displayEnd) ?> in ascending employee order.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Attendance</h3>
        <span class="text-muted small"><?= count($rows) ?> employees</span>
      </div>
      <div class="card-body table-responsive p-0">
        <style>
          .attendance-table th,
          .attendance-table td {
            white-space: nowrap;
            font-size: 12px;
          }
          .attendance-table thead th {
            background: #f8f9fa;
          }
        </style>
        <table class="table table-bordered table-sm attendance-table">
          <thead>
            <tr>
              <th>LNo</th>
              <th>Employee Code</th>
              <th>Employee Name</th>
              <th>Job</th>
              <?php foreach ($displayGrid as $cell): ?>
                <th title="<?= h($cell['date'] ?? '') ?>">D<?= h($cell['day']) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): ?>
              <?php $line = 1; ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= $line++ ?></td>
                  <td><?= h($row['emp_code']) ?></td>
                  <td><?= h($row['full_name'] ?: '-') ?></td>
                  <td><?= h($row['job']) ?></td>
                  <?php foreach ($displayGrid as $cell): ?>
                    <?php $date = $cell['date']; ?>
                    <td><?= $date ? h($row['days'][$date] ?? '-') : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="<?= 4 + count($displayGrid) ?>" class="text-center text-muted">No attendance data for this month.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>

<?php

function normalize_date(string $value): ?string {
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value;
}

function resolve_attendance_db(): string {
    $default = 'gcc_attendance_master';
    $configPath = dirname(__DIR__) . '/api/employee-att-daily/config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config) && !empty($config['db_name'])) {
            $name = (string) $config['db_name'];
            if (preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                return $name;
            }
        }
    }
    return $default;
}

function ensure_attendance_tables(mysqli $bd, string $database): void {
    $dbName = preg_match('/^[A-Za-z0-9_]+$/', $database) ? $database : 'gcc_attendance_master';
    $bd->query(
        'CREATE TABLE IF NOT EXISTS ' . $dbName . '.employee_att_daily (' .
        'emp_code varchar(10) NOT NULL,' .
        'job varchar(10) NOT NULL,' .
        'att_date date NOT NULL,' .
        'work_hours decimal(9,2) NULL,' .
        'work_code varchar(10) NULL,' .
        'pending_leave tinyint(1) NOT NULL DEFAULT 0,' .
        'pending_leave_code varchar(10) NULL,' .
        'pending_leave_doc_no varchar(20) NULL,' .
        'is_deleted tinyint(1) NOT NULL DEFAULT 0,' .
        'last_change_id bigint NOT NULL DEFAULT 0,' .
        'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (emp_code, job, att_date)' .
        ') ENGINE=InnoDB'
    );
    $bd->query(
        'CREATE TABLE IF NOT EXISTS ' . $dbName . '.employee_att_daily_inbox (' .
        'change_id bigint NOT NULL,' .
        'received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        "status enum('applied','skipped','error') NOT NULL," .
        'error_message varchar(1024) NULL,' .
        'PRIMARY KEY (change_id)' .
        ') ENGINE=InnoDB'
    );
}

function build_date_range(string $start, string $end): array {
    $dates = [];
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    $endDate = $endDate->modify('+1 day');
    $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate);
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

function build_month_grid(string $start): array {
    $startDate = new DateTimeImmutable($start);
    $monthLabel = $startDate->format('Y-m');
    $lastDay = (int) $startDate->format('t');
    $grid = [];
    for ($day = 1; $day <= 31; $day++) {
        $date = null;
        if ($day <= $lastDay) {
            $date = $monthLabel . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        }
        $grid[] = ['day' => $day, 'date' => $date];
    }
    return $grid;
}

function load_attendance_rows(mysqli $bd, string $database, string $start, string $end): array {
    $dbName = preg_match('/^[A-Za-z0-9_]+$/', $database) ? $database : 'gcc_attendance_master';
    $sql = 'SELECT a.emp_code, a.job, a.att_date, a.work_hours, a.work_code, a.pending_leave, ' .
        'a.pending_leave_code, a.pending_leave_doc_no, a.is_deleted ' .
        'FROM ' . $dbName . '.employee_att_daily a ' .
        'WHERE a.att_date BETWEEN ? AND ? ' .
        'ORDER BY a.emp_code ASC, a.job ASC, a.att_date ASC';

    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();

    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $empCode = (string) ($row['emp_code'] ?? '');
        $job = (string) ($row['job'] ?? '');
        $key = $empCode . '|' . $job;
        if (!isset($employees[$key])) {
            $employees[$key] = [
                'emp_code' => $empCode,
                'job' => $job,
                'full_name' => '',
                'days' => [],
            ];
        }
        $date = (string) ($row['att_date'] ?? '');
        if ($date !== '') {
            $employees[$key]['days'][$date] = format_attendance_cell($row);
        }
    }
    $stmt->close();

    return array_values($employees);
}

function apply_hrms_names(array $rows): array {
    if (empty($rows)) {
        return $rows;
    }
    $codes = [];
    foreach ($rows as $row) {
        $code = trim((string) ($row['emp_code'] ?? ''));
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    $nameMap = load_hrms_employee_name_map(array_keys($codes), 100);
    if (empty($nameMap)) {
        return $rows;
    }
    foreach ($rows as &$row) {
        $code = trim((string) ($row['emp_code'] ?? ''));
        if ($code !== '' && isset($nameMap[$code])) {
            $row['full_name'] = $nameMap[$code];
        }
    }
    unset($row);
    return $rows;
}

function load_hrms_employee_name_map(array $employeeCodes, int $chunkSize = 100): array {
    $codes = [];
    foreach ($employeeCodes as $code) {
        $code = trim((string) $code);
        if ($code === '' || isset($codes[$code])) {
            continue;
        }
        $codes[$code] = true;
    }
    if (empty($codes)) {
        return [];
    }

    $map = [];
    $chunks = array_chunk(array_keys($codes), max(1, $chunkSize));
    foreach ($chunks as $chunk) {
        $result = hrms_api_post_json('/api/employees/details', array_values($chunk), 20);
        if (!$result['ok'] || !is_array($result['data'])) {
            continue;
        }
        $rows = $result['data'];
        if (isset($rows['employees']) && is_array($rows['employees'])) {
            $rows = $rows['employees'];
        } elseif (isset($rows['rows']) && is_array($rows['rows'])) {
            $rows = $rows['rows'];
        } elseif (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }
        if (!is_array($rows)) {
            continue;
        }
        if (array_values($rows) !== $rows) {
            $rows = array_values($rows);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $employee = (isset($row['employee']) && is_array($row['employee'])) ? $row['employee'] : $row;
            $code = trim((string) ($employee['EMP_CODE']
                ?? $employee['empCode']
                ?? $employee['employeeCode']
                ?? $employee['code']
                ?? $row['EMP_CODE']
                ?? $row['empCode']
                ?? $row['employeeCode']
                ?? $row['code']
                ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($employee['EMP_NAME']
                ?? $employee['empName']
                ?? $employee['name']
                ?? $row['EMP_NAME']
                ?? $row['empName']
                ?? $row['name']
                ?? ''));
            if ($name === '') {
                $firstName = trim((string) ($employee['EMP_FIRSTNAME']
                    ?? $employee['EMP_FIRST_NAME']
                    ?? $employee['EMP_FNAME']
                    ?? $employee['FIRST_NAME']
                    ?? $employee['empFirstName']
                    ?? $employee['firstName']
                    ?? $employee['first_name']
                    ?? $row['EMP_FIRSTNAME']
                    ?? $row['EMP_FIRST_NAME']
                    ?? $row['EMP_FNAME']
                    ?? $row['FIRST_NAME']
                    ?? $row['empFirstName']
                    ?? $row['firstName']
                    ?? $row['first_name']
                    ?? ''));
                $lastName = trim((string) ($employee['EMP_LASTNAME']
                    ?? $employee['EMP_LAST_NAME']
                    ?? $employee['EMP_LNAME']
                    ?? $employee['LAST_NAME']
                    ?? $employee['empLastName']
                    ?? $employee['lastName']
                    ?? $employee['last_name']
                    ?? $row['EMP_LASTNAME']
                    ?? $row['EMP_LAST_NAME']
                    ?? $row['EMP_LNAME']
                    ?? $row['LAST_NAME']
                    ?? $row['empLastName']
                    ?? $row['lastName']
                    ?? $row['last_name']
                    ?? ''));
                $name = trim(trim($firstName) . ' ' . trim($lastName));
            }
            if ($name !== '') {
                $map[$code] = $name;
            }
        }
    }

    return $map;
}

function format_attendance_cell(array $row): string {
    if (!empty($row['is_deleted'])) {
        return '-';
    }

    $pendingLeave = !empty($row['pending_leave']);
    $pendingCode = trim((string) ($row['pending_leave_code'] ?? ''));
    $workCode = trim((string) ($row['work_code'] ?? ''));
    $code = $pendingLeave && $pendingCode !== '' ? $pendingCode : $workCode;

    $hours = $row['work_hours'] ?? null;
    $hoursText = '';
    if ($hours !== null && $hours !== '') {
        $hoursText = format_hours($hours);
    }

    if ($code !== '' && $hoursText !== '') {
        return $code . ' ' . $hoursText;
    }
    if ($code !== '') {
        return $code;
    }
    if ($hoursText !== '') {
        return $hoursText;
    }
    return '-';
}

function format_hours($value): string {
    if (!is_numeric($value)) {
        return (string) $value;
    }
    $text = number_format((float) $value, 2, '.', '');
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '' ? '0' : $text;
}

function output_csv(array $rows, array $dates, string $start, string $end): void {
    $filename = 'employee_attendance_' . $start . '_to_' . $end . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        return;
    }

    $header = ['LNo', 'Employee Code', 'Employee Name', 'Job'];
    foreach ($dates as $date) {
        $header[] = $date;
    }
    fputcsv($output, $header);

    $line = 1;
    foreach ($rows as $row) {
        $record = [
            $line++,
            $row['emp_code'],
            $row['full_name'] ?: '',
            $row['job'],
        ];
        foreach ($dates as $date) {
            $record[] = $row['days'][$date] ?? '';
        }
        fputcsv($output, $record);
    }

    fclose($output);
}

?>
