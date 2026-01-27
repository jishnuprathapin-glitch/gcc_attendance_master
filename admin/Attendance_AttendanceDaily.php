<?php

require __DIR__ . '/include/bootstrap.php';

$page_title = 'Monthly Attendance';

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
  </div>
</section>

<?php include __DIR__ . '/include/layout_bottom.php'; ?>
