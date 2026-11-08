<?php
/** Kerangka halaman Neo Apparel: set $pageTitle (dan opsional $pageKicker) sebelum meng-include file ini. */
$pageTitle = $pageTitle ?? APP_NAME;
$pageKicker = $pageKicker ?? (APP_NAME . ' · sistem terintegrasi');
$__cu = $_SESSION['user_name'] ?? 'Pengguna';
$__flash = flash_pull();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#5b3df5">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%231fbfa8'/%3E%3Cstop offset='1' stop-color='%235b3df5'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='9' fill='url(%23g)'/%3E%3Cpath d='M9 23V9l14 14V9' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="assets/neo.css">
</head>
<body class="app-body">
<?php if ($__flash): ?>
<div class="flash-stack" role="status" aria-live="polite">
    <?php foreach ($__flash as $__f): ?>
        <div class="flash <?= e($__f['type']) ?>"><span><?= e($__f['message']) ?></span><button type="button" aria-label="Tutup" onclick="this.parentNode.remove()">×</button></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="content app-content kms-content" id="main">
