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
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Sora:wght@300;400;500;600;700&display=swap">
  <style>
    :root {
      --att-ink: #0f172a;
      --att-muted: #475569;
      --att-accent: #f97316;
      --att-accent-2: #0ea5e9;
      --att-sand: #fef3c7;
      --att-card: #ffffff;
      --att-card-border: rgba(15, 23, 42, 0.08);
      --att-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
    }
    body {
      font-family: "Sora", "Montserrat", sans-serif;
      color: var(--att-ink);
      background: #f8f4ef;
    }
    .content-wrapper {
      background: radial-gradient(circle at top left, rgba(251, 191, 36, 0.2), transparent 55%),
        radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.15), transparent 60%),
        linear-gradient(135deg, #fff7ed 0%, #fef3c7 45%, #e0f2fe 100%);
      position: relative;
      overflow: hidden;
    }
    .content-wrapper::before {
      content: "";
      position: absolute;
      inset: 0;
      background-image: radial-gradient(rgba(15, 23, 42, 0.05) 1px, transparent 1px);
      background-size: 24px 24px;
      opacity: 0.5;
      pointer-events: none;
    }
    .content-wrapper > .content,
    .content-wrapper > .content-header {
      position: relative;
      z-index: 1;
    }
    .content-header h1 {
      font-family: "Bebas Neue", "Sora", sans-serif;
      font-size: 2.4rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--att-ink);
      margin-bottom: 0;
    }
    .content-header .badge {
      font-family: "Sora", "Montserrat", sans-serif;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    .card {
      border-radius: 18px;
      border: 1px solid var(--att-card-border);
      box-shadow: var(--att-shadow);
      background: var(--att-card);
      animation: att-fade-in 0.45s ease both;
    }
    .card-header {
      border-bottom: 1px solid rgba(15, 23, 42, 0.06);
      background: linear-gradient(90deg, rgba(251, 191, 36, 0.12), rgba(14, 165, 233, 0.08));
    }
    .card-title {
      font-weight: 600;
      color: var(--att-ink);
    }
    .btn {
      border-radius: 12px;
      font-weight: 600;
      letter-spacing: 0.01em;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 18px rgba(15, 23, 42, 0.15);
    }
    .btn-primary {
      background: linear-gradient(135deg, #f97316, #fbbf24);
      border: none;
      color: #0f172a;
    }
    .btn-outline-primary {
      border-color: rgba(15, 23, 42, 0.25);
      color: var(--att-ink);
    }
    .table thead th {
      background: rgba(15, 23, 42, 0.04);
      border-bottom: 1px solid rgba(15, 23, 42, 0.1);
      font-weight: 600;
    }
    .table tbody tr {
      transition: background 0.2s ease;
    }
    .table tbody tr:hover {
      background: rgba(14, 165, 233, 0.08);
    }
    .badge-primary {
      background: linear-gradient(135deg, #0ea5e9, #38bdf8);
      border: none;
      color: #0f172a;
    }
    @keyframes att-fade-in {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 768px) {
      .content-header h1 {
        font-size: 1.9rem;
      }
      .card {
        border-radius: 14px;
      }
    }
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
  <?php include 'include/top_bar.php'; ?>
  <?php include 'include/side_bar.php'; ?>
  <div class="content-wrapper">
