<?php
require_once __DIR__ . '/mis_functions.php';
$user = mis_require_employee();
[$start, $end] = mis_period();
[$from, $to] = mis_bounds($start, $end);

$stmt = $pdo->prepare("SELECT v.id, p.name AS product_name, v.sku, v.color, v.size, v.price, v.stock,
        COALESCE(m.stock_in,0) AS stock_in, COALESCE(m.stock_out,0) AS stock_out,
        COALESCE(m.movements,0) AS movement_count
    FROM product_variations v JOIN products p ON p.id = v.product_id
    LEFT JOIN (
        SELECT variation_id,
            SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS stock_in,
            SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END) AS stock_out,
            COUNT(*) AS movements
        FROM inventory_movements
        WHERE created_at BETWEEN ? AND ?
        GROUP BY variation_id
    ) m ON m.variation_id = v.id
    WHERE v.is_active = 1 AND p.is_active = 1
    ORDER BY v.stock ASC, p.name ASC, v.sku ASC");
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();
$totalStock = 0;
$stockValue = 0.0;
$lowCount = 0;
$outCount = 0;
$totalIn = 0;
$totalOut = 0;
foreach ($rows as $row) {
    $stock = (int)$row['stock'];
    $totalStock += $stock;
    $stockValue += (float)$row['price'] * $stock;
    $totalIn += (int)$row['stock_in'];
    $totalOut += (int)$row['stock_out'];
    if ($stock <= 5) $lowCount++;
    if ($stock === 0) $outCount++;
}
$topMovements = $rows;
usort($topMovements, static fn($a, $b) => (int)$b['stock_out'] <=> (int)$a['stock_out']);
$topMovements = array_slice(array_values(array_filter($topMovements, static fn($r) => (int)$r['stock_out'] > 0)), 0, 8);
$maxStockOut = max(1, ...array_map(static fn($r) => (int)$r['stock_out'], $topMovements ?: [['stock_out' => 0]]));
$pageTitle = 'Analisis persediaan';
include __DIR__ . '/layout_top.php';
?>
<header class="page-header"><div><div class="eyebrow">MIS / PERSEDIAAN</div><h1>Analisis stok</h1><p class="muted">Lihat saldo stok saat ini serta barang masuk dan keluar pada periode yang dipilih.</p></div><div class="page-actions"><a class="btn ghost" href="inventory.php">Buka pengelolaan stok ↗</a><?php if (has_any(['manager','admin'])): ?><a class="btn primary" href="mis_export.php?report=inventory&start=<?= e($start) ?>&end=<?= e($end) ?>">Unduh CSV ↓</a><?php endif; ?></div></header>
<section class="mis-filter-card"><form method="get" class="mis-filter-form"><div><label for="start">Tanggal mulai pergerakan</label><input id="start" type="date" name="start" value="<?= e($start) ?>" required></div><div><label for="end">Tanggal akhir pergerakan</label><input id="end" type="date" name="end" value="<?= e($end) ?>" required></div><button class="btn primary" type="submit">Terapkan filter</button><a class="btn ghost" href="mis_inventory.php">30 hari terakhir</a></form><div class="mis-period-note">Periode pergerakan: <strong><?= e(mis_date_label($start, $end)) ?></strong>. Saldo stok merupakan posisi saat ini dan tidak berubah mengikuti filter tanggal.</div></section>
<section class="mis-stat-grid"><article class="stat-card"><span class="stat-icon purple">▦</span><div><small>Total unit saat ini</small><strong><?= mis_number($totalStock) ?></strong><em>Semua variasi aktif</em></div></article><article class="stat-card"><span class="stat-icon blue">Rp</span><div><small>Estimasi nilai stok</small><strong><?= rupiah($stockValue) ?></strong><em>Harga variasi × saldo stok</em></div></article><article class="stat-card"><span class="stat-icon green">↑</span><div><small>Stok masuk pada periode</small><strong><?= mis_number($totalIn) ?></strong><em>Gerakan dengan kuantitas positif</em></div></article><article class="stat-card"><span class="stat-icon red">↓</span><div><small>Stok keluar pada periode</small><strong><?= mis_number($totalOut) ?></strong><em><?= mis_number($lowCount) ?> variasi stok rendah · <?= mis_number($outCount) ?> habis</em></div></article></section>
<section class="mis-grid-2"><article class="panel-card"><div class="panel-heading"><div><h2>Variasi dengan stok keluar terbanyak</h2><p>Pergerakan stok pada rentang waktu terpilih.</p></div></div><?php if ($topMovements): ?><div class="mis-hbar-list"><?php foreach ($topMovements as $r): $width=((int)$r['stock_out']/$maxStockOut)*100; ?><div class="mis-hbar-item"><div class="mis-hbar-label"><strong><?= e($r['product_name']) ?></strong><b><?= mis_number($r['stock_out']) ?> unit</b></div><div class="mis-hbar-track"><span class="mis-hbar-fill" style="width:<?= e(number_format($width,2,'.','')) ?>%"></span></div><div class="mis-hbar-meta"><?= e($r['sku']) ?> · Stok sekarang <?= mis_number($r['stock']) ?></div></div><?php endforeach; ?></div><?php else: ?><div class="mis-empty-chart">Belum ada pergerakan stok keluar pada periode ini.</div><?php endif; ?></article><article class="panel-card"><div class="panel-heading"><div><h2>Ringkasan kondisi stok</h2><p>Distribusi variasi menurut saldo saat ini.</p></div></div><?php $levels=['Habis'=>0,'Rendah (1–5)'=>0,'Aman (>5)'=>0]; foreach ($rows as $r) { if ((int)$r['stock']===0) $levels['Habis']++; elseif ((int)$r['stock']<=5) $levels['Rendah (1–5)']++; else $levels['Aman (>5)']++; } $levelTotal=max(1,count($rows)); foreach($levels as $label=>$count): $width=($count/$levelTotal)*100; $klass=$label==='Habis'?'danger':($label==='Rendah (1–5)'?'warning':'success'); ?><div class="mis-progress-row"><span><?= e($label) ?></span><b><?= mis_number($count) ?></b><div class="mis-progress-track"><span class="mis-progress-fill" style="width:<?= e(number_format($width,2,'.','')) ?>%;background:<?= $klass==='danger'?'#dc0141':($klass==='warning'?'#e7a12b':'#009339') ?>"></span></div></div><?php endforeach; ?><p class="status-caption">Jumlah variasi aktif: <?= mis_number(count($rows)) ?>.</p></article></section>
<section class="panel-card"><div class="panel-heading"><div><h2>Rincian stok per variasi</h2><p>Stok saat ini beserta gerakan masuk dan keluar dalam rentang filter.</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Produk</th><th>SKU</th><th>Warna / ukuran</th><th>Stok saat ini</th><th>Stok masuk</th><th>Stok keluar</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $row): $stock=(int)$row['stock']; $status=$stock===0?'Habis':($stock<=5?'Perlu restok':'Aman'); $statusClass=$stock===0?'danger':($stock<=5?'warning':'success'); ?><tr><td><strong><?= e($row['product_name']) ?></strong></td><td><?= e($row['sku']) ?></td><td><?= e(($row['color'] ?: '—').' / '.($row['size'] ?: '—')) ?></td><td class="<?= $stock<=5?'low-stock':'' ?>"><?= mis_number($stock) ?></td><td><?= mis_number($row['stock_in']) ?></td><td><?= mis_number($row['stock_out']) ?></td><td><span class="status-pill <?= $statusClass ?>"><?= e($status) ?></span></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7" class="center muted">Belum ada variasi aktif.</td></tr><?php endif; ?></tbody></table></div></section>
<?php include __DIR__ . '/layout_bottom.php'; ?>
