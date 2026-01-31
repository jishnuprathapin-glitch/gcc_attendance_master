
<?php
require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'HR Insights Dashboard';
$flash = get_flash();

function normalize_date(?string $value, string $fallback): string {
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function date_add_days(string $date, int $days): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        $dt = new DateTimeImmutable('today');
    }
    return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function parse_time_minutes(?string $value): ?int {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
    try {
        $tzName = date_default_timezone_get() ?: 'UTC';
        $tz = new DateTimeZone($tzName);
        $dt = $dt->setTimezone($tz);
    } catch (Exception $e) {
        // Keep original timezone.
    }
    return ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
}

function format_time_minutes(?int $minutes): string {
    if ($minutes === null) {
        return '-';
    }
    $hours = (int) floor($minutes / 60);
    $mins = (int) ($minutes % 60);
    $dt = new DateTimeImmutable('today');
    $dt = $dt->setTime($hours, $mins);
    return $dt->format('h:i A');
}

function extract_count_value($data, array $keys): ?int {
    if (is_numeric($data)) {
        return (int) $data;
    }
    if (!is_array($data)) {
        return null;
    }
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            return (int) $data[$key];
        }
    }
    return null;
}

function build_date_series(string $startDate, string $endDate): array {
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end) {
        return [];
    }
    if ($start > $end) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    $dates = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

$today = new DateTimeImmutable('today');
$defaultEnd = $today->format('Y-m-d');
$defaultStart = $today->modify('-6 days')->format('Y-m-d');
$startDate = normalize_date($_GET['startDate'] ?? null, $defaultStart);
$endDate = normalize_date($_GET['endDate'] ?? null, $defaultEnd);
if ($startDate > $endDate) {
    $tmp = $startDate;
    $startDate = $endDate;
    $endDate = $tmp;
}
$endDateParam = date_add_days($endDate, 1);

$deviceSnInput = trim((string) ($_GET['deviceSn'] ?? ''));
$deviceSnList = [];
if ($deviceSnInput !== '') {
    foreach (explode(',', $deviceSnInput) as $sn) {
        $sn = trim($sn);
        if ($sn !== '') {
            $deviceSnList[] = $sn;
        }
    }
}
$deviceSnParam = !empty($deviceSnList) ? implode(',', $deviceSnList) : '';

$isAjax = ($_GET['ajax'] ?? '') === '1';
$ajaxSection = strtolower(trim((string) ($_GET['ajax_section'] ?? '')));

if ($isAjax && $ajaxSection === 'summary') {
    $errors = [];
    $activeCount = null;
    $loggedCount = null;
    $deviceOnline = null;
    $deviceTotal = null;

    $hrmsResult = hrms_api_get('/api/employees/active/count');
    if ($hrmsResult['ok'] && is_array($hrmsResult['data'])) {
        $activeCount = extract_count_value($hrmsResult['data'], ['count', 'total', 'activeCount']);
    } else {
        $hrmsFallback = hrms_api_get('/api/employees/active');
        if ($hrmsFallback['ok'] && is_array($hrmsFallback['data'])) {
            $activeCount = extract_count_value($hrmsFallback['data'], ['count', 'total']);
            if ($activeCount === null) {
                $employees = $hrmsFallback['data']['employees'] ?? null;
                if (is_array($employees)) {
                    $activeCount = count($employees);
                }
            }
        } else {
            $errors[] = 'HRMS active count';
        }
    }

    $pulseDate = $endDate;
    $pulseEnd = date_add_days($pulseDate, 1);

    $badgeCountResult = attendance_api_get('attendance/badges/count', [
        'startDate' => $pulseDate,
        'endDate' => $pulseEnd,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
    ], 12);
    if ($badgeCountResult['ok'] && is_array($badgeCountResult['data'])) {
        $loggedCount = extract_count_value($badgeCountResult['data'], ['count', 'total', 'badgeCount']);
    } else {
        $errors[] = 'Logged in count';
    }

    $deviceStatusResult = attendance_api_get('devices/status/counts', [
        'startDate' => $startDate,
        'endDate' => $endDateParam,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
    ], 12);
    if ($deviceStatusResult['ok'] && is_array($deviceStatusResult['data'])) {
        $statusData = $deviceStatusResult['data'];
        if (isset($statusData['counts']) && is_array($statusData['counts'])) {
            $statusData = $statusData['counts'];
        }
        $deviceOnline = (int) ($statusData['totalActive'] ?? ($statusData['active'] ?? ($statusData['online'] ?? 0)));
        $deviceTotal = (int) ($statusData['total']
            ?? ($deviceOnline
                + (int) ($statusData['totalInactive'] ?? ($statusData['inactive'] ?? ($statusData['offline'] ?? 0)))
                + (int) ($statusData['totalUnknown'] ?? ($statusData['unknown'] ?? 0))));
    } else {
        $errors[] = 'Device status';
    }

    $badgeRows = [];
    $badgeTotal = 0;
    $badgeSampled = false;
    $badgeSampleCount = 0;
    $badgeResult = attendance_api_get('attendance/badges/with-names', [
        'startDate' => $pulseDate,
        'endDate' => $pulseEnd,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
        'page' => 1,
        'pageSize' => 200,
    ], 18);
    if ($badgeResult['ok'] && is_array($badgeResult['data'])) {
        $badgeRows = is_array($badgeResult['data']['rows'] ?? null) ? $badgeResult['data']['rows'] : [];
        $badgeSampleCount = count($badgeRows);
        $badgeTotal = (int) ($badgeResult['data']['total'] ?? $badgeSampleCount);
        $badgeSampled = $badgeTotal > $badgeSampleCount;
    } else {
        $errors[] = 'Badge details';
    }

    $lateThreshold = 10 * 60;
    $earlyLeaveThreshold = 16 * 60;
    $overtimeThreshold = 19 * 60;

    $lateCount = 0;
    $earlyLeaveCount = 0;
    $overtimeCount = 0;
    $firstMinutes = [];
    $lastMinutes = [];
    $arrivalBuckets = [
        'Before 9' => 0,
        '9-10' => 0,
        '10-11' => 0,
        'After 11' => 0,
    ];
    $completionCount = 0;

    $projectCounts = [];
    $recentRows = [];

    foreach ($badgeRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $firstMinutesValue = parse_time_minutes($row['firstLoginTime'] ?? null);
        $lastMinutesValue = parse_time_minutes($row['lastLoginTime'] ?? null);

        if ($firstMinutesValue !== null) {
            $firstMinutes[] = $firstMinutesValue;
            if ($firstMinutesValue > $lateThreshold) {
                $lateCount++;
            }
            if ($firstMinutesValue < 9 * 60) {
                $arrivalBuckets['Before 9']++;
            } elseif ($firstMinutesValue < 10 * 60) {
                $arrivalBuckets['9-10']++;
            } elseif ($firstMinutesValue < 11 * 60) {
                $arrivalBuckets['10-11']++;
            } else {
                $arrivalBuckets['After 11']++;
            }
        }
        if ($lastMinutesValue !== null) {
            $lastMinutes[] = $lastMinutesValue;
            if ($lastMinutesValue < $earlyLeaveThreshold) {
                $earlyLeaveCount++;
            }
            if ($lastMinutesValue > $overtimeThreshold) {
                $overtimeCount++;
            }
        }
        if ($firstMinutesValue !== null && $lastMinutesValue !== null) {
            $completionCount++;
        }

        $project = trim((string) ($row['lastLoginProjectName'] ?? ''));
        if ($project === '') {
            $project = trim((string) ($row['firstLoginProjectName'] ?? ''));
        }
        if ($project === '') {
            $project = 'Unassigned';
        }
        if (!isset($projectCounts[$project])) {
            $projectCounts[$project] = 0;
        }
        $projectCounts[$project]++;

        $lastTime = $row['lastLoginTime'] ?? $row['firstLoginTime'] ?? null;
        $timestamp = null;
        if ($lastTime) {
            try {
                $timestamp = (new DateTimeImmutable($lastTime))->getTimestamp();
            } catch (Exception $e) {
                $timestamp = null;
            }
        }
        $recentRows[] = [
            'badge' => trim((string) ($row['badgeNumber'] ?? '')),
            'name' => trim((string) ($row['name'] ?? '')),
            'project' => $project,
            'time' => $lastTime ? (string) $lastTime : '',
            'timestamp' => $timestamp ?? 0,
        ];
    }

    arsort($projectCounts);
    $projectLabels = [];
    $projectValues = [];
    $projectIndex = 0;
    $otherCount = 0;
    foreach ($projectCounts as $label => $count) {
        if ($projectIndex < 6) {
            $projectLabels[] = $label;
            $projectValues[] = $count;
        } else {
            $otherCount += $count;
        }
        $projectIndex++;
    }
    if ($otherCount > 0) {
        $projectLabels[] = 'Other';
        $projectValues[] = $otherCount;
    }

    usort($recentRows, function (array $a, array $b): int {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });
    $recentRows = array_slice($recentRows, 0, 6);

    $avgFirst = null;
    if (!empty($firstMinutes)) {
        $avgFirst = (int) round(array_sum($firstMinutes) / count($firstMinutes));
    }
    $avgLast = null;
    if (!empty($lastMinutes)) {
        $avgLast = (int) round(array_sum($lastMinutes) / count($lastMinutes));
    }

    $completionRate = null;
    if ($badgeSampleCount > 0) {
        $completionRate = (int) round(($completionCount / $badgeSampleCount) * 100);
    }

    $coveragePercent = null;
    if ($activeCount !== null && $activeCount > 0 && $loggedCount !== null) {
        $coveragePercent = (int) round(($loggedCount / $activeCount) * 100);
    }

    $payload = [
        'ok' => true,
        'errors' => array_values(array_unique($errors)),
        'meta' => [
            'pulseDate' => $pulseDate,
            'sampleCount' => $badgeSampleCount,
            'totalCount' => $badgeTotal,
            'sampled' => $badgeSampled,
        ],
        'kpis' => [
            'activeCount' => $activeCount,
            'loggedCount' => $loggedCount,
            'coveragePercent' => $coveragePercent,
            'deviceOnline' => $deviceOnline,
            'deviceTotal' => $deviceTotal,
        ],
        'timeMetrics' => [
            'lateCount' => $lateCount,
            'earlyLeaveCount' => $earlyLeaveCount,
            'overtimeCount' => $overtimeCount,
            'avgFirst' => format_time_minutes($avgFirst),
            'avgLast' => format_time_minutes($avgLast),
            'completionRate' => $completionRate,
        ],
        'arrivalBuckets' => [
            'labels' => array_keys($arrivalBuckets),
            'counts' => array_values($arrivalBuckets),
        ],
        'projects' => [
            'labels' => $projectLabels,
            'counts' => $projectValues,
        ],
        'recent' => array_map(function (array $row): array {
            $displayName = $row['name'] !== '' ? $row['name'] : ($row['badge'] !== '' ? $row['badge'] : 'Unknown');
            $timeLabel = $row['time'];
            if ($timeLabel !== '') {
                try {
                    $dt = new DateTimeImmutable($timeLabel);
                    $timeLabel = $dt->format('d M, h:i A');
                } catch (Exception $e) {
                    $timeLabel = $row['time'];
                }
            }
            return [
                'name' => $displayName,
                'badge' => $row['badge'],
                'project' => $row['project'],
                'time' => $timeLabel,
            ];
        }, $recentRows),
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'trend') {
    $errors = [];
    $labels = [];
    $values = [];
    $dailyResult = attendance_api_get('attendance/daily/by-devices', [
        'startDate' => $startDate,
        'endDate' => $endDateParam,
        'deviceSn' => $deviceSnParam !== '' ? $deviceSnParam : null,
    ], 12);
    if ($dailyResult['ok'] && is_array($dailyResult['data'])) {
        $rows = $dailyResult['data']['rows'] ?? [];
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $dateKey = trim((string) ($row['date'] ?? ''));
                if ($dateKey === '') {
                    continue;
                }
                $map[$dateKey] = (int) ($row['total'] ?? 0);
            }
        }
        $series = build_date_series($startDate, $endDate);
        foreach ($series as $dateKey) {
            $labels[] = $dateKey;
            $values[] = (int) ($map[$dateKey] ?? 0);
        }
    } else {
        $errors[] = 'Daily trend';
    }

    $maxValue = 0;
    $avgValue = 0;
    $delta = null;
    if (!empty($values)) {
        $maxValue = max($values);
        $avgValue = (int) round(array_sum($values) / count($values));
        if (count($values) >= 2) {
            $delta = $values[count($values) - 1] - $values[count($values) - 2];
        }
    }

    $payload = [
        'ok' => true,
        'errors' => array_values(array_unique($errors)),
        'labels' => $labels,
        'values' => $values,
        'max' => $maxValue,
        'avg' => $avgValue,
        'delta' => $delta,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjax && $ajaxSection === 'devices') {
    $errors = [];
    $deviceCounts = [];
    $deviceResult = attendance_api_get('attendance/counts', [
        'groupBy' => 'deviceSn',
        'startDate' => $startDate,
        'endDate' => $endDateParam,
    ], 12);
    if ($deviceResult['ok'] && is_array($deviceResult['data'])) {
        foreach ($deviceResult['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sn = trim((string) ($row['value'] ?? ''));
            if ($sn === '') {
                continue;
            }
            if (!empty($deviceSnList) && !in_array($sn, $deviceSnList, true)) {
                continue;
            }
            $deviceCounts[$sn] = (int) ($row['total'] ?? 0);
        }
    } else {
        $errors[] = 'Device punches';
    }

    arsort($deviceCounts);
    $labels = [];
    $values = [];
    $index = 0;
    $otherTotal = 0;
    foreach ($deviceCounts as $sn => $count) {
        if ($index < 6) {
            $labels[] = $sn;
            $values[] = $count;
        } else {
            $otherTotal += $count;
        }
        $index++;
    }
    if ($otherTotal > 0) {
        $labels[] = 'Other';
        $values[] = $otherTotal;
    }

    $payload = [
        'ok' => true,
        'errors' => array_values(array_unique($errors)),
        'labels' => $labels,
        'values' => $values,
        'total' => array_sum($deviceCounts),
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

?>
<?php include __DIR__ . '/include/layout_top.php'; ?>

<style>
  :root {
    --hr-ink: #0b1120;
    --hr-ink-soft: #2a3553;
    --hr-cream: #fff8e1;
    --hr-mint: #d1fae5;
    --hr-coral: #fecdd3;
    --hr-blue: #bae6fd;
    --hr-violet: #e9d5ff;
    --hr-glow: rgba(251, 191, 36, 0.35);
    --hr-card: #ffffff;
    --hr-shadow: 0 22px 45px rgba(15, 23, 42, 0.16);
  }
  .hr-dashboard {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
  }
  .hr-hero {
    padding: 1.6rem 1.8rem;
    border-radius: 22px;
    background: linear-gradient(120deg, #0ea5e9 0%, #38bdf8 25%, #fbbf24 60%, #f97316 100%);
    color: #0b1120;
    position: relative;
    overflow: hidden;
  }
  .hr-hero::after {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.45), transparent 60%);
    opacity: 0.8;
  }
  .hr-hero-inner {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    align-items: center;
  }
  .hr-hero h2 {
    font-family: "Bebas Neue", "Sora", sans-serif;
    font-size: 2.8rem;
    margin: 0;
    letter-spacing: 0.08em;
  }
  .hr-hero p {
    margin: 0.2rem 0 0;
    font-weight: 500;
    color: rgba(15, 23, 42, 0.8);
  }
  .hr-hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
  }
  .hr-pill {
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    font-size: 0.85rem;
  }
  .hr-toolbar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.85rem;
    align-items: end;
  }
  .hr-toolbar .form-group {
    margin-bottom: 0;
  }
  .hr-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    border: 1px solid rgba(15, 23, 42, 0.15);
    background: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    cursor: pointer;
  }
  .gap-2 {
    gap: 0.5rem;
  }
  .text-pop {
    animation: hr-pop 0.35s ease;
  }
  .hr-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
  }
  .hr-kpi {
    padding: 1rem 1.1rem;
    border-radius: 18px;
    background: var(--hr-card);
    box-shadow: var(--hr-shadow);
    position: relative;
    overflow: hidden;
  }
  .hr-kpi::after {
    content: "";
    position: absolute;
    right: -20%;
    top: -30%;
    width: 120px;
    height: 120px;
    border-radius: 999px;
    background: rgba(251, 191, 36, 0.3);
  }
  .hr-kpi h3 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--hr-ink-soft);
  }
  .hr-kpi .hr-kpi-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--hr-ink);
    letter-spacing: 0.02em;
  }
  .hr-kpi .hr-kpi-sub {
    font-size: 0.85rem;
    color: var(--att-muted);
  }
  .hr-bento {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 1rem;
  }
  .hr-card {
    grid-column: span 12;
    padding: 1rem 1.2rem;
  }
  .hr-card h4 {
    font-weight: 700;
    margin-bottom: 0.5rem;
  }
  .hr-chart {
    height: 260px;
  }
  .hr-list {
    display: grid;
    gap: 0.65rem;
  }
  .hr-list-item {
    display: flex;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.55rem 0.75rem;
    border-radius: 12px;
    background: rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(15, 23, 42, 0.08);
  }
  .hr-list-item small {
    color: var(--att-muted);
  }
  .hr-insights {
    display: grid;
    gap: 0.75rem;
  }
  .hr-insight {
    padding: 0.65rem 0.85rem;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.15), rgba(251, 191, 36, 0.12));
    border: 1px solid rgba(15, 23, 42, 0.08);
    font-weight: 600;
  }
  .hr-skeleton {
    position: relative;
    overflow: hidden;
    background: rgba(148, 163, 184, 0.2);
    border-radius: 8px;
  }
  .hr-skeleton::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.7), transparent);
    animation: hr-shimmer 1.5s infinite;
  }
  @keyframes hr-pop {
    from { transform: scale(0.96); color: #0ea5e9; }
    to { transform: scale(1); color: inherit; }
  }
  @keyframes hr-shimmer {
    from { transform: translateX(-100%); }
    to { transform: translateX(100%); }
  }
  .hr-focus .content-wrapper {
    background: #f8fafc;
  }
  .hr-focus .hr-hero {
    background: linear-gradient(120deg, #e2e8f0, #f8fafc);
  }
  .hr-focus .hr-kpi::after {
    display: none;
  }
  .hr-reduce-motion * {
    animation: none !important;
    transition: none !important;
  }
  @media (min-width: 992px) {
    .hr-card.hr-span-7 { grid-column: span 7; }
    .hr-card.hr-span-5 { grid-column: span 5; }
    .hr-card.hr-span-4 { grid-column: span 4; }
    .hr-card.hr-span-6 { grid-column: span 6; }
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>HR Insights Dashboard</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <span class="badge badge-primary">HR Focus</span>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid hr-dashboard">
    <?php include __DIR__ . '/include/admin_nav.php'; ?>

    <?php if (!empty($flash)): ?>
      <div class="alert alert-info mb-0"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="hr-hero card">
      <div class="hr-hero-inner">
        <div>
          <h2>People Pulse</h2>
          <p>High-energy overview of attendance health, coverage, and risk signals.</p>
        </div>
        <div class="hr-hero-meta">
          <span class="hr-pill">Range: <?= h($startDate) ?> to <?= h($endDate) ?></span>
          <span class="hr-pill" id="pulseDatePill">Daily pulse: <?= h($endDate) ?></span>
          <span class="hr-pill" id="sampleNotePill">Sampled: waiting</span>
        </div>
      </div>
    </div>

    <div class="card p-3">
      <form class="hr-toolbar" method="get">
        <div class="form-group">
          <label for="startDate">Start date</label>
          <input type="date" class="form-control" id="startDate" name="startDate" value="<?= h($startDate) ?>">
        </div>
        <div class="form-group">
          <label for="endDate">End date</label>
          <input type="date" class="form-control" id="endDate" name="endDate" value="<?= h($endDate) ?>">
        </div>
        <div class="form-group">
          <label for="deviceSn">Device SN filter</label>
          <input type="text" class="form-control" id="deviceSn" name="deviceSn" placeholder="Comma separated" value="<?= h($deviceSnInput) ?>">
        </div>
        <div class="form-group">
          <button type="submit" class="btn btn-primary btn-block">Apply</button>
        </div>
        <div class="form-group d-flex gap-2">
          <button type="button" class="hr-toggle" data-range="today">Today</button>
          <button type="button" class="hr-toggle" data-range="7">Last 7</button>
          <button type="button" class="hr-toggle" data-range="30">Last 30</button>
        </div>
        <div class="form-group d-flex gap-2">
          <button type="button" class="hr-toggle" id="toggleFocus"><i class="fas fa-adjust"></i> Focus mode</button>
          <button type="button" class="hr-toggle" id="toggleMotion"><i class="fas fa-running"></i> Reduce motion</button>
        </div>
      </form>
    </div>

    <div class="hr-kpi-grid">
      <div class="hr-kpi">
        <h3>Active employees</h3>
        <div class="hr-kpi-value" id="kpiActive">-</div>
        <div class="hr-kpi-sub" id="kpiActiveSub">HRMS status A</div>
      </div>
      <div class="hr-kpi">
        <h3>Logged in (pulse day)</h3>
        <div class="hr-kpi-value" id="kpiLogged">-</div>
        <div class="hr-kpi-sub" id="kpiCoverage">Coverage n/a</div>
      </div>
      <div class="hr-kpi">
        <h3>Late arrivals</h3>
        <div class="hr-kpi-value" id="kpiLate">-</div>
        <div class="hr-kpi-sub" id="kpiLateSub">After 10:00</div>
      </div>
      <div class="hr-kpi">
        <h3>Early leaves</h3>
        <div class="hr-kpi-value" id="kpiEarly">-</div>
        <div class="hr-kpi-sub" id="kpiEarlySub">Before 16:00</div>
      </div>
      <div class="hr-kpi">
        <h3>Overtime pulse</h3>
        <div class="hr-kpi-value" id="kpiOvertime">-</div>
        <div class="hr-kpi-sub" id="kpiOvertimeSub">After 19:00</div>
      </div>
      <div class="hr-kpi">
        <h3>Device uptime</h3>
        <div class="hr-kpi-value" id="kpiDevices">-</div>
        <div class="hr-kpi-sub" id="kpiDevicesSub">Online / total</div>
      </div>
    </div>

    <div class="hr-bento">
      <div class="card hr-card hr-span-7">
        <h4>Attendance pulse</h4>
        <p class="text-muted mb-2">Daily logged in counts for the selected range.</p>
        <div class="hr-chart">
          <canvas id="trendChart"></canvas>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="hr-pill" id="trendAvg">Avg: -</span>
          <span class="hr-pill" id="trendDelta">Delta: -</span>
        </div>
      </div>

      <div class="card hr-card hr-span-5">
        <h4>Arrival mix</h4>
        <p class="text-muted mb-2">Check-in distribution for the pulse day.</p>
        <div class="hr-chart">
          <canvas id="arrivalChart"></canvas>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="hr-pill" id="avgFirst">Avg first login: -</span>
          <span class="hr-pill" id="avgLast">Avg last login: -</span>
        </div>
      </div>

      <div class="card hr-card hr-span-4">
        <h4>Project spotlight</h4>
        <p class="text-muted mb-2">Where attendance energy is concentrated.</p>
        <div class="hr-chart">
          <canvas id="projectChart"></canvas>
        </div>
      </div>

      <div class="card hr-card hr-span-4">
        <h4>Device heat</h4>
        <p class="text-muted mb-2">Top devices by punches.</p>
        <div class="hr-chart">
          <canvas id="deviceChart"></canvas>
        </div>
      </div>

      <div class="card hr-card hr-span-4">
        <h4>Recent activity</h4>
        <div class="hr-list" id="recentList">
          <div class="hr-skeleton" style="height: 48px;"></div>
          <div class="hr-skeleton" style="height: 48px;"></div>
          <div class="hr-skeleton" style="height: 48px;"></div>
        </div>
      </div>

      <div class="card hr-card hr-span-6">
        <h4>Focus actions</h4>
        <div class="hr-insights" id="insightList">
          <div class="hr-skeleton" style="height: 44px;"></div>
          <div class="hr-skeleton" style="height: 44px;"></div>
          <div class="hr-skeleton" style="height: 44px;"></div>
        </div>
      </div>

      <div class="card hr-card hr-span-6">
        <h4>Attendance completeness</h4>
        <p class="text-muted mb-2">Percent of badges with both first and last login.</p>
        <div class="hr-chart">
          <canvas id="completionChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  window.addEventListener('load', function () {
    const baseUrl = window.location.pathname;
    const baseParams = new URLSearchParams({
      startDate: <?= json_encode($startDate) ?>,
      endDate: <?= json_encode($endDate) ?>,
      deviceSn: <?= json_encode($deviceSnInput) ?>
    });

    const charts = {};

    const palette = {
      blue: '#38bdf8',
      orange: '#f97316',
      gold: '#fbbf24',
      violet: '#a855f7',
      teal: '#14b8a6',
      rose: '#f43f5e'
    };

    const setText = (id, value) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = value;
      el.classList.add('text-pop');
      setTimeout(() => el.classList.remove('text-pop'), 400);
    };

    const setPill = (id, text) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = text;
    };

    const createLineChart = (ctx, labels, values) => {
      const gradient = ctx.createLinearGradient(0, 0, 0, 240);
      gradient.addColorStop(0, 'rgba(56, 189, 248, 0.55)');
      gradient.addColorStop(1, 'rgba(56, 189, 248, 0.05)');
      return new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            data: values,
            borderColor: palette.blue,
            backgroundColor: gradient,
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointBackgroundColor: palette.gold
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          },
          scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } },
            x: { ticks: { maxTicksLimit: 7 } }
          }
        }
      });
    };

    const createBarChart = (ctx, labels, values, colors) => {
      return new Chart(ctx, {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: colors,
            borderRadius: 10
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    };

    const createDonutChart = (ctx, labels, values, colors) => {
      return new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: colors,
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } },
          cutout: '65%'
        }
      });
    };

    const createHorizontalChart = (ctx, labels, values, colors) => {
      return new Chart(ctx, {
        type: 'bar',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: colors,
            borderRadius: 8
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: 'y',
          plugins: { legend: { display: false } },
          scales: {
            x: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    };

    const createCompletionChart = (ctx, value) => {
      return new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['Complete', 'Missing'],
          datasets: [{
            data: [value, Math.max(0, 100 - value)],
            backgroundColor: [palette.teal, 'rgba(148, 163, 184, 0.4)'],
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          cutout: '70%'
        }
      });
    };

    const updateChart = (key, creator) => {
      if (charts[key]) {
        charts[key].destroy();
      }
      charts[key] = creator();
    };

    const renderRecent = (rows) => {
      const list = document.getElementById('recentList');
      if (!list) return;
      list.innerHTML = '';
      if (!rows || rows.length === 0) {
        list.innerHTML = '<div class="text-muted">No recent activity available.</div>';
        return;
      }
      rows.forEach((row) => {
        const item = document.createElement('div');
        item.className = 'hr-list-item';
        item.innerHTML = `
          <div>
            <div><strong>${row.name}</strong></div>
            <small>${row.project || 'Unassigned'}</small>
          </div>
          <div class="text-right">
            <div>${row.time || '-'}</div>
            <small>${row.badge || ''}</small>
          </div>
        `;
        list.appendChild(item);
      });
    };

    const renderInsights = (summary) => {
      const list = document.getElementById('insightList');
      if (!list) return;
      list.innerHTML = '';

      const insights = [];
      const coverage = summary.kpis.coveragePercent;
      const late = summary.timeMetrics.lateCount;
      const early = summary.timeMetrics.earlyLeaveCount;
      const overtime = summary.timeMetrics.overtimeCount;
      const completion = summary.timeMetrics.completionRate;

      if (coverage !== null) {
        insights.push(`Coverage at ${coverage}%. ${coverage < 70 ? 'Needs attention.' : 'Healthy range.'}`);
      } else {
        insights.push('Coverage data unavailable. Check HRMS or attendance API.');
      }

      insights.push(`Late arrivals: ${late}. ${late > 10 ? 'High variance detected.' : 'On track today.'}`);
      insights.push(`Early leaves: ${early}. ${early > 8 ? 'Review shift adherence.' : 'Stable departures.'}`);
      insights.push(`Overtime: ${overtime}. ${overtime > 6 ? 'Check workload balance.' : 'Overtime controlled.'}`);

      if (completion !== null) {
        insights.push(`Completeness: ${completion}% of badges have both in/out.`);
      }

      insights.slice(0, 5).forEach((text) => {
        const item = document.createElement('div');
        item.className = 'hr-insight';
        item.textContent = text;
        list.appendChild(item);
      });
    };

    const fetchSection = (section) => {
      const params = new URLSearchParams(baseParams);
      params.set('ajax', '1');
      params.set('ajax_section', section);
      return fetch(`${baseUrl}?${params.toString()}`, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        });
    };

    const loadSummary = () => {
      fetchSection('summary')
        .then((data) => {
          if (!data) return;
          const kpis = data.kpis || {};
          const timeMetrics = data.timeMetrics || {};

          setText('kpiActive', kpis.activeCount !== null ? kpis.activeCount : '-');
          setText('kpiLogged', kpis.loggedCount !== null ? kpis.loggedCount : '-');
          setText('kpiLate', timeMetrics.lateCount ?? '-');
          setText('kpiEarly', timeMetrics.earlyLeaveCount ?? '-');
          setText('kpiOvertime', timeMetrics.overtimeCount ?? '-');

          if (kpis.coveragePercent !== null) {
            setText('kpiCoverage', `Coverage ${kpis.coveragePercent}%`);
          } else {
            setText('kpiCoverage', 'Coverage n/a');
          }

          if (kpis.deviceOnline !== null && kpis.deviceTotal !== null) {
            setText('kpiDevices', `${kpis.deviceOnline} / ${kpis.deviceTotal}`);
          } else {
            setText('kpiDevices', '-');
          }

          setPill('avgFirst', `Avg first login: ${timeMetrics.avgFirst || '-'}`);
          setPill('avgLast', `Avg last login: ${timeMetrics.avgLast || '-'}`);

          if (data.meta) {
            setPill('pulseDatePill', `Daily pulse: ${data.meta.pulseDate}`);
            if (data.meta.sampled) {
              setPill('sampleNotePill', `Sampled ${data.meta.sampleCount} of ${data.meta.totalCount}`);
            } else {
              setPill('sampleNotePill', `Loaded ${data.meta.sampleCount}`);
            }
          }

          if (data.arrivalBuckets) {
            updateChart('arrival', () => createBarChart(
              document.getElementById('arrivalChart').getContext('2d'),
              data.arrivalBuckets.labels || [],
              data.arrivalBuckets.counts || [],
              [palette.teal, palette.blue, palette.gold, palette.rose]
            ));
          }

          if (data.projects) {
            updateChart('projects', () => createDonutChart(
              document.getElementById('projectChart').getContext('2d'),
              data.projects.labels || [],
              data.projects.counts || [],
              [palette.blue, palette.gold, palette.orange, palette.violet, palette.teal, palette.rose, '#94a3b8']
            ));
          }

          const completion = timeMetrics.completionRate ?? 0;
          updateChart('completion', () => createCompletionChart(
            document.getElementById('completionChart').getContext('2d'),
            completion
          ));

          renderRecent(data.recent || []);
          renderInsights(data);
        })
        .catch(() => {
          setText('kpiActive', '-');
        });
    };

    const loadTrend = () => {
      fetchSection('trend')
        .then((data) => {
          if (!data) return;
          updateChart('trend', () => createLineChart(
            document.getElementById('trendChart').getContext('2d'),
            data.labels || [],
            data.values || []
          ));
          setPill('trendAvg', `Avg: ${data.avg ?? '-'}`);
          if (data.delta !== null && data.delta !== undefined) {
            const sign = data.delta >= 0 ? '+' : '';
            setPill('trendDelta', `Delta: ${sign}${data.delta}`);
          }
        })
        .catch(() => {
          setPill('trendAvg', 'Avg: -');
        });
    };

    const loadDevices = () => {
      fetchSection('devices')
        .then((data) => {
          if (!data) return;
          updateChart('devices', () => createHorizontalChart(
            document.getElementById('deviceChart').getContext('2d'),
            data.labels || [],
            data.values || [],
            [palette.orange, palette.gold, palette.blue, palette.violet, palette.teal, palette.rose, '#94a3b8']
          ));
        });
    };

    const bindQuickRanges = () => {
      const buttons = document.querySelectorAll('[data-range]');
      buttons.forEach((button) => {
        button.addEventListener('click', () => {
          const range = button.getAttribute('data-range');
          const startField = document.getElementById('startDate');
          const endField = document.getElementById('endDate');
          if (!startField || !endField) return;

          const today = new Date();
          const endDate = today.toISOString().slice(0, 10);
          let startDate = endDate;
          if (range === 'today') {
            startDate = endDate;
          } else {
            const days = parseInt(range, 10);
            if (!Number.isNaN(days)) {
              const start = new Date(today);
              start.setDate(start.getDate() - (days - 1));
              startDate = start.toISOString().slice(0, 10);
            }
          }
          startField.value = startDate;
          endField.value = endDate;
        });
      });
    };

    const bindToggles = () => {
      const root = document.documentElement;
      const focusToggle = document.getElementById('toggleFocus');
      const motionToggle = document.getElementById('toggleMotion');

      const applyStored = () => {
        if (localStorage.getItem('hrFocus') === '1') {
          root.classList.add('hr-focus');
        }
        if (localStorage.getItem('hrMotion') === '1') {
          root.classList.add('hr-reduce-motion');
        }
      };
      applyStored();

      if (focusToggle) {
        focusToggle.addEventListener('click', () => {
          root.classList.toggle('hr-focus');
          localStorage.setItem('hrFocus', root.classList.contains('hr-focus') ? '1' : '0');
        });
      }
      if (motionToggle) {
        motionToggle.addEventListener('click', () => {
          root.classList.toggle('hr-reduce-motion');
          localStorage.setItem('hrMotion', root.classList.contains('hr-reduce-motion') ? '1' : '0');
        });
      }
    };

    bindQuickRanges();
    bindToggles();
    loadSummary();
    loadTrend();
    loadDevices();
  });
</script>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
