<?php

if (!isset($page_title)) {
    $page_title = 'Attendance';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <base href="/HRSmart/">
  <title>GCC Attendance | <?= h($page_title) ?></title>
  <?php include 'include/css.php'; ?>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
  <?php include 'include/top_bar.php'; ?>
  <?php include 'include/side_bar.php'; ?>
  <div class="content-wrapper">
