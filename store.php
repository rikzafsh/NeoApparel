<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'shop_functions.php';
$u = login_required($pdo);
$sid = (int)($_GET['seller'] ?? 0);
$s = $pdo->prepare("SELECT u.id, u.name, u.created_at FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.id = ? AND r.name IN ('seller','manager','admin') AND u.is_active = 1");
$s->execute([$sid]); $seller = $s->fetch();
if (!$seller) { flash('error', 'Toko tidak ditemukan.'); go('catalog.php'); }
$products = shop_products($pdo, ['seller_id' => $sid]);
$cat = $_GET['cat'] ?? ''; $sort = in_array($_GET['sort'] ?? '', ['hot', 'new', 'asc', 'desc'], true) ? $_GET['sort'] : 'hot'; $q = trim($_GET['q'] ?? '');
$cats = array_values(array_unique(array_column($products, 'cat'))); sort($cats);
$view = array_filter($products, static fn($p) => ($cat === '' || $p['cat'] === $cat) && ($q === '' || mb_stripos($p['name'], $q) !== false));
uasort($view, static function ($a, $b) use ($sort) {
    return match ($sort) { 'new' => strtotime($b['created']) <=> strtotime($a['created']), 'asc' => $a['min'] <=> $b['min'], 'desc' => $b['min'] <=> $a['min'], default => $b['sold'] <=> $a['sold'] };
});
$tot = ['sold' => array_sum(array_column($products, 'sold')), 'n' => array_sum(array_column($products, 'n'))];
$ratings = array_filter(array_column($products, 'avg'), static fn($x) => $x !== null);
$avg = $ratings ? round(array_sum($ratings) / count($ratings), 1) : null;
$link = static fn(array $o = []) => 'store.php?' . http_build_query(array_filter(array_merge(['seller' => $sid, 'cat' => $cat, 'sort' => $sort !== 'hot' ? $sort : null, 'q' => $q], $o), static fn($v) => $v !== null && $v !== ''));
$pageTitle = 'Toko ' . $seller['name'];
$pageKicker = 'Toko';
include 'layout_top.php';
?>
<section class="store-hero">
    <span class="avatar xl"><?= e(mb_strtoupper(mb_substr($seller['name'], 0, 1))) ?></span>
    <div><h1><?= e($seller['name']) ?></h1><p class="muted">Penjual resmi di <?= e(APP_NAME) ?> · bergabung <?= e(date('M Y', strtotime($seller['created_at']))) ?></p></div>
    <dl class="seller-stats"><div><dt>Produk</dt><dd><?= count($products) ?></dd></div><div><dt>Penilaian</dt><dd><?= $avg !== null ? '★ ' . number_format($avg, 1, ',', '') : '-' ?></dd></div><div><dt>Terjual</dt><dd><?= e(shop_sold_label($tot['sold'])) ?></dd></div><div><dt>Ulasan</dt><dd><?= (int)$tot['n'] ?></dd></div></dl>
</section>
<form method="get" class="filter-bar"><input type="hidden" name="seller" value="<?= $sid ?>"><input type="hidden" name="cat" value="<?= e($cat) ?>">
    <div class="search"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari di toko ini…"></div>
    <select name="sort" onchange="this.form.submit()"><?php foreach (['hot' => 'Terlaris', 'new' => 'Terbaru', 'asc' => 'Harga terendah', 'desc' => 'Harga tertinggi'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
<div class="chips"><a class="chip <?= $cat === '' ? 'on' : '' ?>" href="<?= e($link(['cat' => null])) ?>">Semua</a><?php foreach ($cats as $c): ?><a class="chip <?= $cat === $c ? 'on' : '' ?>" href="<?= e($link(['cat' => $c])) ?>"><?= e($c) ?></a><?php endforeach; ?></div>
<?php if (!$view): ?><div class="card empty-state"><?= icon('search') ?><p>Tidak ada produk yang cocok.</p></div><?php endif; ?>
<div class="mini-grid"><?php foreach ($view as $p) { echo shop_mini_card($p); } ?></div>
<?php include 'layout_bottom.php'; ?>
