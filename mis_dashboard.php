<?php
require_once __DIR__ . '/mis_functions.php';

$user = mis_require_employee();
[$start, $end] = mis_period();
[$from, $to] = mis_bounds($start, $end);
$days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
$previousEnd = date('Y-m-d', strtotime($start . ' -1 day'));
$previousStart = date('Y-m-d', strtotime($previousEnd . ' -' . ($days - 1) . ' days'));
[$previousFrom, $previousTo] = mis_bounds($previousStart, $previousEnd);

// Data pesanan dan pendapatan dihitung tanpa JOIN ke detail item agar tidak terduplikasi.
$summaryStmt = $pdo->prepare("SELECT COUNT(*) AS total_orders,
        COALESCE(SUM(o.total_price), 0) AS total_sales,
        COUNT(DISTINCT o.user_id) AS active_buyers
    FROM orders o
    WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o'));
$summaryStmt->execute([$from, $to]);
$summary = $summaryStmt->fetch() ?: ['total_orders' => 0, 'total_sales' => 0, 'active_buyers' => 0];

$unitsStmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity), 0)
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o'));
$unitsStmt->execute([$from, $to]);
$unitsSold = (int)$unitsStmt->fetchColumn();

$previousStmt = $pdo->prepare("SELECT COALESCE(SUM(o.total_price), 0)
    FROM orders o WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o'));
$previousStmt->execute([$previousFrom, $previousTo]);
$previousSales = (float)$previousStmt->fetchColumn();
$currentSales = (float)$summary['total_sales'];
$salesGrowth = $previousSales > 0 ? (($currentSales - $previousSales) / $previousSales) * 100 : null;
$averageOrder = (int)$summary['total_orders'] > 0 ? $currentSales / (int)$summary['total_orders'] : 0;

$dailyRows = mis_fetch_daily_sales($pdo, $start, $end);
$topProducts = mis_fetch_top_products($pdo, $start, $end, 'units', 7);
$topMax = max(1, ...array_map(static fn($r) => (int)$r['units_sold'], $topProducts ?: [['units_sold' => 0]]));

$statusStmt = $pdo->prepare("SELECT o.status, COUNT(*) AS total FROM orders o
    WHERE o.created_at BETWEEN ? AND ? GROUP BY o.status ORDER BY total DESC");
$statusStmt->execute([$from, $to]);
$orderStatuses = $statusStmt->fetchAll();
$statusTotal = max(1, array_sum(array_map(static fn($r) => (int)$r['total'], $orderStatuses)));

$paymentStmt = $pdo->prepare("SELECT o.payment_status, COUNT(*) AS total,
        COALESCE(SUM(o.total_price), 0) AS amount
    FROM orders o WHERE o.created_at BETWEEN ? AND ?
    GROUP BY o.payment_status ORDER BY total DESC");
$paymentStmt->execute([$from, $to]);
$payments = $paymentStmt->fetchAll();

$methodStmt = $pdo->prepare("SELECT p.payment_method, COUNT(DISTINCT p.order_id) AS order_count,
        COALESCE(SUM(p.amount), 0) AS amount
    FROM payments p JOIN orders o ON o.id = p.order_id
    WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
      AND p.status = 'success'
    GROUP BY p.payment_method ORDER BY amount DESC LIMIT 4");
$methodStmt->execute([$from, $to]);
$paymentMethods = $methodStmt->fetchAll();

$lowStock = $pdo->query("SELECT p.name AS product_name, v.sku, v.color, v.size, v.stock
    FROM product_variations v JOIN products p ON p.id = v.product_id
    WHERE v.is_active = 1 AND p.is_active = 1 AND v.stock <= 5
    ORDER BY v.stock ASC, p.name ASC LIMIT 6")->fetchAll();

$monthStart = date('Y-m-01');
$targetTableMissing = false;
$targetRow = null;
try {
    $targetStmt = $pdo->prepare('SELECT target_amount, notes FROM sales_targets WHERE target_month = ? LIMIT 1');
    $targetStmt->execute([$monthStart]);
    $targetRow = $targetStmt->fetch() ?: null;
} catch (PDOException $e) {
    // Tabel target mungkin belum dibuat pada instalasi lama. Jangan biarkan ini
    // mematikan seluruh dashboard; indikator target akan menampilkan petunjuk migrasi.
    if ($e->getCode() !== '42S02') {
        throw $e;
    }
    $targetTableMissing = true;
}
$targetAmount = (float)($targetRow['target_amount'] ?? 0);
$monthSalesStmt = $pdo->prepare("SELECT COALESCE(SUM(o.total_price),0) FROM orders o
    WHERE o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 MONTH)
      AND " . mis_sales_condition('o'));
$monthSalesStmt->execute([$monthStart . ' 00:00:00', $monthStart . ' 00:00:00']);
$monthSales = (float)$monthSalesStmt->fetchColumn();
$targetPercent = $targetAmount > 0 ? ($monthSales / $targetAmount) * 100 : 0;

$pageTitle = 'Ringkasan MIS';
include __DIR__ . '/layout_top.php';
?>

<header class="page-header">
    <div>
        <div class="eyebrow">MANAGEMENT INFORMATION SYSTEM</div>
        <h1>Ringkasan bisnis</h1>
        <p class="muted">Pantau penjualan, produk terlaris, persediaan, dan alur pesanan dari data TPS pada satu halaman.</p>
    </div>
    <div class="page-actions">
        <a class="btn ghost" href="mis_sales.php?start=<?= e($start) ?>&end=<?= e($end) ?>">Laporan penjualan <span aria-hidden="true">↗</span></a>
        <?php if (has_any(['manager', 'admin'])): ?>
            <a class="btn primary" href="mis_export.php?report=sales&start=<?= e($start) ?>&end=<?= e($end) ?>">Unduh CSV ↓</a>
        <?php endif; ?>
    </div>
</header>

<?php
$esInsights = [];
try {
    $esInsights = $pdo->query("SELECT id,domain,severity,title,message,certainty FROM es_inferences WHERE status IN ('open','acknowledged','in_progress') AND (domain IN ('MIS','CROSS') OR subject_type = 'global') ORDER BY FIELD(severity,'critical','high','medium','low','info'), certainty DESC LIMIT 4")->fetchAll();
} catch (Throwable $e) { $esInsights = []; }
?>
<?php if ($esInsights): ?>
<section class="card" style="margin-bottom:18px"><div class="section-title" style="margin-top:0"><h2><?= icon('cpu', 'sm') ?> Insight Expert System untuk manajemen</h2><a class="text-link" href="es_dashboard.php?domain=MIS">Semua →</a></div>
    <div class="inf-list"><?php foreach ($esInsights as $i): ?><article class="inf <?= e($i['severity']) ?>"><header><span class="sev <?= e($i['severity']) ?>"><?= e($i['severity']) ?></span><span class="dom <?= e($i['domain']) ?>"><?= e($i['domain']) ?></span><span class="cf"><span class="bar"><i style="width:<?= (int)round($i['certainty'] * 100) ?>%"></i></span><?= (int)round($i['certainty'] * 100) ?>%</span></header><h3><a href="es_inference.php?id=<?= (int)$i['id'] ?>"><?= e($i['title']) ?></a></h3><p><?= e($i['message']) ?></p></article><?php endforeach; ?></div></section>
<?php endif; ?>

<section class="mis-filter-card" aria-label="Filter periode laporan">
    <form method="get" class="mis-filter-form">
        <div><label for="start">Tanggal mulai</label><input id="start" type="date" name="start" value="<?= e($start) ?>" required></div>
        <div><label for="end">Tanggal akhir</label><input id="end" type="date" name="end" value="<?= e($end) ?>" required></div>
        <button class="btn primary" type="submit">Terapkan filter</button>
        <a class="btn ghost" href="mis_dashboard.php">30 hari terakhir</a>
    </form>
    <div class="mis-period-note">Periode laporan: <strong><?= e(mis_date_label($start, $end)) ?></strong> · <?= mis_number($days) ?> hari · hanya order lunas dan tidak dibatalkan untuk indikator penjualan.
        <?php if (!empty($GLOBALS['mis_period_limited'])): ?><strong> Rentang dibatasi maksimal 366 hari.</strong><?php endif; ?>
    </div>
    <div class="mis-period-presets">
        <?php foreach ([7 => '7 hari', 30 => '30 hari', 90 => '90 hari', 365 => '1 tahun'] as $range => $label): ?>
            <a href="mis_dashboard.php?start=<?= e(date('Y-m-d', strtotime('-' . ($range - 1) . ' days'))) ?>&end=<?= e(date('Y-m-d')) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<div class="mis-source-strip"><span>SUMBER TPS</span><b>orders</b><b>order_items</b><b>payments</b><b>products</b><b>product_variations</b><b>inventory_movements</b><b>users</b></div>

<section class="mis-stat-grid" aria-label="Indikator utama">
    <article class="stat-card"><span class="stat-icon purple">Rp</span><div><small>Pendapatan terbayar</small><strong><?= rupiah($currentSales) ?></strong><em class="<?= $salesGrowth !== null && $salesGrowth < 0 ? 'trend-down' : 'trend-up' ?>"><?php if ($salesGrowth === null): ?>Belum ada pembanding<?php else: ?><?= $salesGrowth >= 0 ? '↑' : '↓' ?> <?= e(mis_percent(abs($salesGrowth))) ?> dibanding periode sebelumnya<?php endif; ?></em></div></article>
    <article class="stat-card"><span class="stat-icon blue">▤</span><div><small>Pesanan lunas</small><strong><?= mis_number((int)$summary['total_orders']) ?></strong><em>Rata-rata <?= rupiah($averageOrder) ?> per pesanan</em></div></article>
    <article class="stat-card"><span class="stat-icon green">▦</span><div><small>Unit terjual</small><strong><?= mis_number($unitsSold) ?></strong><em>Jumlah item dari order lunas</em></div></article>
    <article class="stat-card"><span class="stat-icon red">♙</span><div><small>Pelanggan aktif</small><strong><?= mis_number((int)$summary['active_buyers']) ?></strong><em>Pelanggan dengan pembelian terbayar</em></div></article>
</section>

<section class="mis-layout-main">
    <article class="panel-card">
        <div class="panel-heading"><div><h2>Tren penjualan</h2><p>Perubahan nilai pendapatan setiap hari selama periode terpilih.</p></div><a class="text-link" href="mis_sales.php?start=<?= e($start) ?>&end=<?= e($end) ?>">Lihat detail →</a></div>
        <div class="mis-chart-wrap"><?= mis_svg_sales_chart($dailyRows) ?></div>
        <div class="mis-chart-legend"><span><i class="legend-dot"></i>Pendapatan terbayar per hari</span></div>
    </article>

    <article class="panel-card">
        <div class="panel-heading"><div><h2>Produk paling laris</h2><p>Diurutkan berdasarkan jumlah unit terjual.</p></div><a class="text-link" href="mis_products.php?start=<?= e($start) ?>&end=<?= e($end) ?>">Semua produk →</a></div>
        <?php if ($topProducts): ?>
            <div class="mis-hbar-list">
                <?php foreach ($topProducts as $i => $product): $width = ((int)$product['units_sold'] / $topMax) * 100; ?>
                    <div class="mis-hbar-item">
                        <div class="mis-hbar-label"><strong><span class="mis-rank-inline"><?= $i + 1 ?>.</span> <?= e($product['product_name']) ?></strong><b><?= mis_number($product['units_sold']) ?> unit</b></div>
                        <div class="mis-hbar-track"><span class="mis-hbar-fill" style="width:<?= e(number_format($width, 2, '.', '')) ?>%"></span></div>
                        <div class="mis-hbar-meta">Pendapatan produk: <?= rupiah($product['revenue']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?><div class="mis-empty-chart">Belum ada penjualan produk pada rentang tanggal ini.</div><?php endif; ?>
    </article>
</section>

<section class="mis-layout-secondary" style="margin-top:16px">
    <article class="panel-card">
        <div class="panel-heading"><div><h2>Status pesanan</h2><p>Semua pesanan pada periode terpilih.</p></div><a class="text-link" href="mis_operations.php?start=<?= e($start) ?>&end=<?= e($end) ?>">Alur pesanan →</a></div>
        <?php if ($orderStatuses): foreach ($orderStatuses as $row): $percent = ((int)$row['total'] / $statusTotal) * 100; ?>
            <div class="mis-progress-row"><span><?= e(mis_status_label($row['status'])) ?></span><b><?= mis_number($row['total']) ?></b><div class="mis-progress-track"><span class="mis-progress-fill" style="width:<?= e(number_format($percent, 2, '.', '')) ?>%"></span></div></div>
        <?php endforeach; else: ?><div class="mis-empty-inline">Belum ada pesanan untuk periode ini.</div><?php endif; ?>
    </article>

    <article class="panel-card">
        <div class="panel-heading"><div><h2>Status pembayaran</h2><p>Nilai order menurut status pembayarannya.</p></div></div>
        <?php if ($payments): ?><div class="mis-mini-grid">
            <?php foreach ($payments as $payment): ?>
                <div class="mini-report-card"><span><?= e(mis_status_label($payment['payment_status'])) ?></span><strong><?= mis_number($payment['total']) ?></strong><small><?= rupiah($payment['amount']) ?></small></div>
            <?php endforeach; ?>
        </div><?php else: ?><div class="mis-empty-inline">Belum ada data pembayaran.</div><?php endif; ?>
        <?php if ($paymentMethods): ?><div class="mis-section-heading" style="margin-top:18px"><h2 style="font-size:13px">Metode pembayaran pesanan lunas</h2></div>
            <?php foreach ($paymentMethods as $method): ?><div class="mis-hbar-item" style="margin-bottom:11px"><div class="mis-hbar-label"><strong><?= e(ucwords(str_replace('_', ' ', $method['payment_method']))) ?></strong><b><?= mis_number($method['order_count']) ?> pesanan</b></div><div class="mis-hbar-meta"><?= rupiah($method['amount']) ?></div></div><?php endforeach; ?>
        <?php endif; ?>
    </article>

    <article class="panel-card">
        <div class="panel-heading"><div><h2>Perlu restok</h2><p>Variasi aktif dengan stok 5 unit atau kurang.</p></div><a class="text-link" href="mis_inventory.php">Analisis stok →</a></div>
        <div class="mis-simple-list">
            <?php foreach ($lowStock as $item): ?><div><strong><?= e($item['product_name']) ?></strong><small><?= e($item['sku']) ?> · <?= e($item['color'] ?: 'Warna bebas') ?> / <?= e($item['size'] ?: 'Ukuran bebas') ?></small><b><?= mis_number($item['stock']) ?></b></div><?php endforeach; ?>
            <?php if (!$lowStock): ?><div class="mis-empty-inline">Semua variasi aktif memiliki stok lebih dari 5 unit.</div><?php endif; ?>
        </div>
    </article>
</section>

<section style="margin-top:16px">
    <?php if ($targetTableMissing): ?>
        <article class="mis-note-card"><strong>Tabel target penjualan belum tersedia</strong><p>Ringkasan penjualan tetap bisa digunakan, tetapi bagian target bulanan memerlukan tabel <code>sales_targets</code>. Di phpMyAdmin, pilih database <code>db_neo_apparel</code> lalu impor file <code>sql/migration_sales_targets.sql</code> dari paket aplikasi.</p></article>
    <?php elseif ($targetAmount > 0): ?>
        <article class="mis-target-box">
            <div class="target-kicker">Target penjualan bulan ini · <?= e(date('F Y')) ?></div>
            <h3><?= rupiah($monthSales) ?> <span style="font-size:13px;font-weight:600;color:#d7e5ff">dari <?= rupiah($targetAmount) ?></span></h3>
            <p><?= e($targetRow['notes'] ?? 'Target penjualan ditetapkan di tabel sales_targets.') ?></p>
            <div class="mis-target-progress"><span style="width:<?= e(number_format(min(100, $targetPercent), 2, '.', '')) ?>%"></span></div>
            <div class="mis-target-foot"><span><?= e(mis_percent($targetPercent)) ?> tercapai</span><span>Sisa target: <?= rupiah(max(0, $targetAmount - $monthSales)) ?></span></div>
        </article>
    <?php else: ?>
        <article class="mis-note-card"><strong>Target penjualan belum ditetapkan</strong><p>Tabel <code>sales_targets</code> dapat menyimpan target bulanan. Setelah target tersedia, pencapaian bulan berjalan akan muncul di area ini.</p></article>
    <?php endif; ?>
</section>

<section class="mis-explain-card" style="margin-top:16px"><div><div class="eyebrow">CARA KERJA MIS</div><h2>Catat di TPS, pahami lewat MIS.</h2><p>Transaksi tidak disalin ke tabel MIS. Sistem membaca pesanan dan item TPS, menghitung metrik tanpa menggandakan nilai order, lalu menyajikannya sebagai ringkasan untuk keputusan bisnis.</p></div><div class="mis-flow"><span>TPS</span><b>→</b><span>Agregasi data</span><b>→</b><span>MIS</span><b>→</b><span>Keputusan</span></div></section>

<?php include __DIR__ . '/layout_bottom.php'; ?>
