<?php
$nav_mode = $nav_mode ?? 'admin';
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if ($script === '') {
    if ($nav_mode === 'timekeeper') {
        $base = '/gcc_attendance_master/timekeeper';
    } elseif ($nav_mode === 'campboss') {
        $base = '/gcc_attendance_master/campboss';
    } else {
        $base = '/gcc_attendance_master/admin';
    }
} else {
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
}
$links = ($nav_mode === 'timekeeper')
    ? [
        ['label' => 'Attendance Daily', 'path' => 'timekeeper_attendance_view.php', 'icon' => 'fas fa-calendar-alt'],
        ['label' => 'No Punch', 'path' => 'timekeeper_attendance_view_no_punch.php', 'icon' => 'fas fa-user-times'],
        ['label' => 'Access Requests', 'path' => 'timekeeper_project_request.php', 'icon' => 'fas fa-key'],
      ]
    : (($nav_mode === 'campboss')
        ? [
            ['label' => 'No Punch Review', 'path' => 'campboss_attendance_view_no_punch.php', 'icon' => 'fas fa-clipboard-check'],
          ]
        : [
            ['label' => 'Dashboard', 'path' => 'Attendance_Dashboard.php', 'icon' => 'fas fa-home'],
            ['label' => 'HR Insights', 'path' => 'Attendance_HRDashboard.php', 'icon' => 'fas fa-heartbeat'],
            ['label' => 'Employees', 'path' => 'Attendance_Employees.php', 'icon' => 'fas fa-users'],
            ['label' => 'Attendance Daily', 'path' => 'Attendance_AttendanceDaily.php', 'icon' => 'fas fa-calendar-alt'],
            ['label' => 'Adjust Time', 'path' => 'Attendance_AttendanceAdjustTime.php', 'icon' => 'fas fa-clock'],
            ['label' => 'Approvals', 'path' => 'Attendance_AttendanceApproval.php', 'icon' => 'fas fa-check-circle'],
            ['label' => 'Device Mapping', 'path' => 'Attendance_DeviceMapping.php', 'icon' => 'fas fa-project-diagram'],
            ['label' => 'Timekeeper Requests', 'path' => 'Attendance_TimekeeperAccess.php', 'icon' => 'fas fa-user-check'],
          ]);
$currentPage = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600&family=Space+Grotesk:wght@400;500;600&display=swap');
  .att-nav-wrap {
    position: relative;
    margin-bottom: 1rem;
    padding: 1rem 1.25rem;
    border-radius: 16px;
    background: linear-gradient(135deg, #0ea5e9 0%, #22c55e 25%, #f97316 55%, #f43f5e 100%);
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.2);
    overflow: hidden;
  }
  .att-nav-wrap::before {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.45), transparent 55%);
    opacity: 0.8;
    pointer-events: none;
  }
  .att-nav-wrap::after {
    content: "";
    position: absolute;
    inset: 0;
    background-image: linear-gradient(120deg, rgba(15, 23, 42, 0.15), rgba(255, 255, 255, 0));
    opacity: 0.5;
    pointer-events: none;
  }
  .att-nav-head {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.85rem;
    gap: 1rem;
  }
  .att-nav-title {
    font-family: "Oswald", "Segoe UI", sans-serif;
    font-size: 1.35rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #0f172a;
    margin: 0;
  }
  .att-nav-sub {
    font-family: "Space Grotesk", "Segoe UI", sans-serif;
    font-size: 0.9rem;
    color: #1f2937;
    opacity: 0.9;
  }
  .att-nav-links {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.65rem;
  }
  .att-nav-link {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.78);
    border: 1px solid rgba(255, 255, 255, 0.65);
    color: #0f172a;
    font-family: "Space Grotesk", "Segoe UI", sans-serif;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    text-decoration: none;
    box-shadow: 0 10px 22px rgba(15, 23, 42, 0.14);
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, color 0.2s ease;
  }
  .att-nav-link span {
    background: linear-gradient(120deg, #0ea5e9, #22c55e, #f97316);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
  }
  .att-nav-link i {
    font-size: 1.05rem;
    color: #1d4ed8;
  }
  .att-nav-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 26px rgba(15, 23, 42, 0.18);
    background: rgba(255, 255, 255, 0.92);
    text-decoration: none;
    color: #0f172a;
  }
  .att-nav-link:hover i {
    color: #0ea5e9;
  }
  .att-nav-link:hover span {
    background: linear-gradient(120deg, #2563eb, #7c3aed, #f97316);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
  }
  .att-nav-link.is-active {
    background: linear-gradient(135deg, #0f172a, #1d4ed8, #7c3aed);
    color: #f8fafc;
    border-color: transparent;
    box-shadow: 0 16px 28px rgba(15, 23, 42, 0.35);
  }
  .att-nav-link.is-active span {
    background: none;
    -webkit-text-fill-color: #ffffff;
    color: #ffffff;
  }
  .att-nav-link.is-active i {
    color: #facc15;
  }
  @media (max-width: 576px) {
    .att-nav-wrap {
      padding: 0.9rem;
    }
    .att-nav-head {
      flex-direction: column;
      align-items: flex-start;
    }
  }
</style>

<div class="att-nav-wrap">
  <div class="att-nav-head"></div>
  <div class="att-nav-links">
    <?php foreach ($links as $link): ?>
      <?php $isActive = strtolower($link['path']) === $currentPage; ?>
      <a class="att-nav-link <?= $isActive ? 'is-active' : '' ?>" href="<?= h($base . '/' . ltrim($link['path'], '/')) ?>">
        <i class="<?= h($link['icon']) ?>"></i>
        <span><?= h($link['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
