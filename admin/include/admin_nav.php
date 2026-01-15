<?php
$links = [
    ['label' => 'Dashboard', 'path' => 'Attendance_Dashboard.php', 'icon' => 'fas fa-home', 'class' => 'btn-outline-primary'],
    ['label' => 'Live Punches', 'path' => 'Attendance_Live.php', 'icon' => 'fas fa-clock', 'class' => 'btn-outline-primary'],
    ['label' => 'Employees', 'path' => 'Attendance_Employees.php', 'icon' => 'fas fa-users', 'class' => 'btn-outline-primary'],
];
?>
<div class="mb-3">
  <?php foreach ($links as $link): ?>
    <a class="btn btn-sm <?= h($link['class']) ?>" href="<?= h(admin_url($link['path'])) ?>">
      <i class="<?= h($link['icon']) ?> mr-1"></i><?= h($link['label']) ?>
    </a>
  <?php endforeach; ?>
</div>
