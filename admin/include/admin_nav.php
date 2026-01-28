<?php
$links = [
    ['label' => 'Dashboard', 'path' => 'Attendance_Dashboard.php', 'icon' => 'fas fa-home'],
    ['label' => 'Employees', 'path' => 'Attendance_Employees.php', 'icon' => 'fas fa-users'],
    ['label' => 'Attendance Daily', 'path' => 'Attendance_AttendanceDaily.php', 'icon' => 'fas fa-calendar-alt'],
    ['label' => 'Adjust Time', 'path' => 'Attendance_AttendanceAdjustTime.php', 'icon' => 'fas fa-clock'],
    ['label' => 'Approvals', 'path' => 'Attendance_AttendanceApproval.php', 'icon' => 'fas fa-check-circle'],
    ['label' => 'Device Mapping', 'path' => 'Attendance_DeviceMapping.php', 'icon' => 'fas fa-project-diagram'],
];
$currentPage = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600&family=Space+Grotesk:wght@400;500;600&display=swap');
  .att-nav-wrap {
    position: relative;
    margin-bottom: 1rem;
    padding: 1rem 1.25rem;
    border-radius: 16px;
    background: radial-gradient(circle at top left, #fef9c3 0%, #fde68a 28%, #fca5a5 65%, #f97316 100%);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.15);
    overflow: hidden;
  }
  .att-nav-wrap::after {
    content: "";
    position: absolute;
    inset: 0;
    background-image: linear-gradient(120deg, rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0));
    opacity: 0.6;
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
    background: rgba(15, 23, 42, 0.06);
    border: 1px solid rgba(15, 23, 42, 0.12);
    color: #0f172a;
    font-family: "Space Grotesk", "Segoe UI", sans-serif;
    font-weight: 600;
    text-decoration: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  }
  .att-nav-link i {
    font-size: 1.05rem;
  }
  .att-nav-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.15);
    background: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    color: #0f172a;
  }
  .att-nav-link.is-active {
    background: #0f172a;
    color: #f8fafc;
    border-color: transparent;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.35);
  }
  .att-nav-link.is-active i {
    color: #fbbf24;
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
      <a class="att-nav-link <?= $isActive ? 'is-active' : '' ?>" href="<?= h(admin_url($link['path'])) ?>">
        <i class="<?= h($link['icon']) ?>"></i>
        <span><?= h($link['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
