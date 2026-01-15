<?php

require __DIR__ . '/include/bootstrap.php';
require __DIR__ . '/include/attendance_api.php';

$page_title = 'Attendance Dashboard';
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? ($_SESSION['usr_type'] ?? '');
$apiBaseUrl = attendance_api_base();
$docsUrl = '/gcc_attendance_master/docs/attendance-UTIME-api.md';
$flash = get_flash();

include __DIR__ . '/include/layout_top.php';

?>

<section class="content-header">
  <div class="container-fluid">
    <?php include __DIR__ . '/include/admin_nav.php'; ?>
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Attendance Dashboard</h1>
      </div>
      <div class="col-sm-6 text-sm-right">
        <span class="badge badge-primary">GCC Attendance</span>
      </div>
    </div>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Access summary</h3>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-4">User</dt>
              <dd class="col-sm-8"><?= h($userName) ?></dd>
              <dt class="col-sm-4">Email</dt>
              <dd class="col-sm-8"><?= h($userEmail ?: 'n/a') ?></dd>
              <dt class="col-sm-4">Role</dt>
              <dd class="col-sm-8"><?= h($userRole ?: 'n/a') ?></dd>
            </dl>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Attendance API v2</h3>
          </div>
          <div class="card-body">
            <p class="mb-2">Base URL: <code><?= h($apiBaseUrl) ?></code></p>
            <ul class="list-unstyled mb-0">
              <li><code>GET /attendance</code> for employee or badge ranges</li>
              <li><code>GET /employees</code> for employee summary lists</li>
              <li><code>GET /devices</code> for device inventory</li>
              <li><code>GET /attendance/stream</code> for live punches</li>
            </ul>
            <div class="mt-3">
              <a class="btn btn-sm btn-outline-primary" href="<?= h($docsUrl) ?>">Open API catalog</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Live punches</h3>
          </div>
          <div class="card-body">
            <p class="mb-3">Monitor the latest attendance punches in near real-time.</p>
            <a class="btn btn-sm btn-outline-primary" href="<?= h(admin_url('Attendance_Live.php')) ?>">Open live view</a>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Employee lookup</h3>
          </div>
          <div class="card-body">
            <p class="mb-3">Search employees by badge, ID, or department.</p>
            <a class="btn btn-sm btn-outline-primary" href="<?= h(admin_url('Attendance_Employees.php')) ?>">Search employees</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Next steps</h3>
      </div>
      <div class="card-body">
        <p class="mb-3">
          This module reuses HRSmart authentication, sessions, and access controls.
          Add attendance views or reports here as needed.
        </p>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(admin_url('Attendance_Dashboard.php')) ?>">Refresh</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
