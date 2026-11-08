<?php
require_once __DIR__ . '/mis_functions.php';
$user = mis_require_employee();
[$start, $end] = mis_period();
$metric = (($_GET['metric'] ?? 'units') === 'revenue') ? 'revenue' : 'units';
$metricLabel = $metric === 'revenue' ? 'pendapatan' : 'unit terjual';
$rankedProducts = mis_fetch_top_products($pdo, $start, $end, $metric, 12);
$rankValues = array_map(static fn($r) => $metric === 'revenue' ? (float)$r['revenue'] : (int)$r['units_sold'], $rankedProducts);
$rankMaximum = $rankValues ? max(1, ...$rankValues) : 1;

// Ringkasan penjualan dibuat dahulu secara terpisah dari stok supaya join tidak menggandakan quantity atau saldo stok.
[$from, $to] = mis_bounds($start, $end);
$productsStmt = $pdo->prepare("SELECT p.id, p.name, u.name AS seller_name,
        (SELECT COUNT(*) FROM product_variations v1 WHERE v1.product_id = p.id AND v1.is_active = 1) AS variation_count,
        (SELECT COALESCE(SUM(v2.stock),0) FROM product_variations v2 WHERE v2.product_id = p.id AND v2.is_active = 1) AS current_stock,
        COALESCE(s.units_sold,0) AS units_sold, COALESCE(s.revenue,0) AS revenue
    FROM products p JOIN users u ON u.id = p.seller_id
    LEFT JOIN (
        SELECT oi.product_name, SUM(oi.quantity) AS units_sold, SUM(oi.subtotal) AS revenue
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        GROUP BY oi.product_name
    ) s ON s.product_name = p.name
    WHERE p.is_active = 1
    ORDER BY s.units_sold DESC, s.revenue DESC, p.name ASC");
$productsStmt->execute([$from, $to]);
$products = $productsStmt->fetchAll();
$totalsStmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity),0) AS units_sold, COALESCE(SUM(oi.subtotal),0) AS revenue
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o'));
$totalsStmt->execute([$from, $to]);
$productTotals = $totalsStmt->fetch() ?: ['units_sold' => 0, 'revenue' => 0];
$grandUnits = (int)$productTotals['units_sold'];
$grandRevenue = (float)$productTotals['revenue'];
$fastMovers = count(array_filter($products, static fn($r) => (int)$r['units_sold'] >= 10));
$needsRestock = count(array_filter($products, static fn($r) => (int)$r['current_stock'] <= 5));

$pageTitle = 'Analisis produk terlaris';
include __DIR__ . '/layout_top.php';
?>
<header class="page-header"><div><div class="eyebrow">MIS / PERFORMA PRODUK</div><h1>Produk terlaris</h1><p class="muted">Bandingkan produk berdasarkan unit yang terjual atau pendapatan pada rentang tanggal tertentu.</p></div><?php if (has_any(['manager','admin'])): ?><div class="page-actions"><a class="btn ghost" href="mis_export.php?report=products&start=<?= e($start) ?>&end=<?= e($end) ?>">Unduh rincian CSV ↓</a></div><?php endif; ?></header>
<section class="mis-filter-card"><form method="get" class="mis-filter-form"><div><label for="start">Tanggal mulai</label><input id="start" type="date" name="start" value="<?= e($start) ?>" required></div><div><label for="end">Tanggal akhir</label><input id="end" type="date" name="end" value="<?= e($end) ?>" required></div><div><label for="metric">Urutkan grafik</label><select id="metric" name="metric"><option value="units" <?= $metric === 'units' ? 'selected' : '' ?>>Unit terjual terbanyak</option><option value="revenue" <?= $metric === 'revenue' ? 'selected' : '' ?>>Pendapatan terbesar</option></select></div><button class="btn primary" type="submit">Terapkan filter</button></form><div class="mis-period-note">Periode analisis: <strong><?= e(mis_date_label($start, $end)) ?></strong></div></section>
<section class="mis-stat-grid"><article class="stat-card"><span class="stat-icon purple">▦</span><div><small>Unit terjual</small><strong><?= mis_number($grandUnits) ?></strong><em>Item dari order lunas</em></div></article><article class="stat-card"><span class="stat-icon blue">Rp</span><div><small>Pendapatan produk</small><strong><?= rupiah($grandRevenue) ?></strong><em>Total subtotal item terjual</em></div></article><article class="stat-card"><span class="stat-icon green">↗</span><div><small>Produk bergerak cepat</small><strong><?= mis_number($fastMovers) ?></strong><em>Minimal 10 unit selama periode</em></div></article><article class="stat-card"><span class="stat-icon red">!</span><div><small>Produk stok rendah</small><strong><?= mis_number($needsRestock) ?></strong><em>Stok total variasi ≤ 5 unit</em></div></article></section>
<section class="panel-card"><div class="panel-heading"><div><h2>Grafik peringkat produk</h2><p>Diurutkan berdasarkan <?= e($metricLabel) ?>, maksimal 12 produk teratas.</p></div><div class="page-actions"><a class="btn <?= $metric === 'units' ? 'primary' : 'ghost' ?>" href="mis_products.php?start=<?= e($start) ?>&end=<?= e($end) ?>&metric=units">Unit terjual</a><a class="btn <?= $metric === 'revenue' ? 'primary' : 'ghost' ?>" href="mis_products.php?start=<?= e($start) ?>&end=<?= e($end) ?>&metric=revenue">Pendapatan</a></div></div><?php if ($rankedProducts): ?><div class="mis-hbar-list"><?php foreach ($rankedProducts as $i => $row): $value = $metric === 'revenue' ? (float)$row['revenue'] : (int)$row['units_sold']; $width = ($value / $rankMaximum) * 100; ?><div class="mis-hbar-item"><div class="mis-hbar-label"><strong><span class="mis-rank-inline"><?= $i + 1 ?>.</span> <?= e($row['product_name']) ?></strong><b><?= $metric === 'revenue' ? rupiah($value) : mis_number($value) . ' unit' ?></b></div><div class="mis-hbar-track"><span class="mis-hbar-fill" style="width:<?= e(number_format($width, 2, '.', '')) ?>%"></span></div><div class="mis-hbar-meta"><?= mis_number($row['units_sold']) ?> unit · Pendapatan <?= rupiah($row['revenue']) ?></div></div><?php endforeach; ?></div><?php else: ?><div class="mis-empty-chart">Belum ada penjualan produk pada rentang ini. Pilih rentang tanggal yang berbeda atau pastikan transaksi telah berstatus lunas.</div><?php endif; ?></section>
<section class="panel-card"><div class="panel-heading"><div><h2>Performa produk & ketersediaan stok</h2><p>Gabungan laporan detail penjualan dengan stok produk saat ini. Stok bukan angka historis.</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Produk</th><th>Penjual</th><th>Variasi aktif</th><th>Unit terjual</th><th>Pendapatan</th><th>Stok saat ini</th><th>Indikator</th></tr></thead><tbody><?php foreach ($products as $product): $stock=(int)$product['current_stock']; $sold=(int)$product['units_sold']; if ($stock <= 5) { $signal='Perlu restok'; $signalClass='danger'; } elseif ($sold >= 10) { $signal='Produk laris'; $signalClass='success'; } else { $signal='Normal'; $signalClass='neutral'; } ?><tr><td><strong><?= e($product['name']) ?></strong></td><td><?= e($product['seller_name']) ?></td><td><?= mis_number($product['variation_count']) ?></td><td><?= mis_number($sold) ?></td><td><strong><?= rupiah($product['revenue']) ?></strong></td><td class="<?= $stock <= 5 ? 'low-stock' : '' ?>"><?= mis_number($stock) ?></td><td><span class="status-pill <?= $signalClass ?>"><?= e($signal) ?></span></td></tr><?php endforeach; ?><?php if (!$products): ?><tr><td colspan="7" class="center muted">Belum ada produk aktif.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="mis-note-card"><strong>Bagaimana produk terlaris dihitung?</strong><p>Grafik membaca <code>order_items</code> yang terhubung ke <code>orders</code>. Hanya order lunas dan tidak dibatalkan yang dihitung. Total pendapatan diambil dari subtotal item, sedangkan stok dibaca terpisah dari <code>product_variations</code> agar hasilnya tidak berlipat karena JOIN.</p></section>
<?php include __DIR__ . '/layout_bottom.php'; ?>
