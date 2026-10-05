<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions.php';
requireRole('admin', 'supervisor');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Realtime Activity</title><link rel="stylesheet" href="/assets/style.css"></head>
<body class="admin-body">
<?php include __DIR__ . '/_nav.php'; ?>
<main class="admin-main"><div class="page-head"><div><span class="eyebrow">REALTIME MONITOR</span><h1>Your team. Live<span class="title-dot">.</span></h1><p class="muted">Login, availability and breaks, updated every 2 seconds.</p></div><a class="btn secondary" href="/admin/report.php?type=sessions">Login / logout report &nearr;</a></div>
<?php include __DIR__ . '/_live.php'; ?>
</main></body></html>
