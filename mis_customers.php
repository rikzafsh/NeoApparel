<?php
require_once __DIR__ . '/mis_functions.php';
$user = mis_require_employee(['manager', 'admin']);
[$start, $end] = mis_period();
[$from, $to] = mis_bounds($start, $end);
$stmt = $pdo->prepare("SELECT u.id, u.name, u.email,
        COUNT(DISTINCT o.id) AS order_count,
        COALESCE(SUM(o.total_price),0) AS total_spent,
        MAX(o.created_at) AS last_order
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id AND r.name = 'customer'
    JOIN orders o ON o.user_id = u.id
    WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
    GROUP BY u.id, u.name, u.email
    ORDER BY total_spent DESC, u.name ASC");
$stmt->execute([$from, $to]);
$customers = $stmt->fetchAll();
$totalCustomerSales = array_sum(array_map(static fn($r) => (float)$r['total_spent'], $customers));
$totalCustomerOrders = array_sum(array_map(static fn($r) => (int)$r['order_count'], $customers));
$averageCustomerSpend = count($customers) > 0 ? $totalCustomerSales / count($customers) : 0;
$pageTitle = 'Ringkasan pelanggan';
include __DIR__ . '/layout_top.php';
?>
<header class="page-header"><div><div class="eyebrow">MIS / PELANGGAN</div><h1>Ringkasan pelanggan</h1><p class="muted">Rangkuman pembelian berdasarkan transaksi terbayar untuk membantu analisis pelanggan secara agregat.</p></div></header>
<section class="mis-filter-card"><form method="get" class="mis-filter-form"><div><label for="start">Tanggal mulai</label><input id="start" type="date" name="start" value="<?= e($start) ?>" required></div><div><label for="end">Tanggal akhir</label><input id="end" type="date" name="end" value="<?= e($end) ?>" required></div><button class="btn primary" type="submit">Terapkan filter</button><a class="btn ghost" href="mis_customers.php">30 hari terakhir</a></form><div class="mis-period-note">Periode: <strong><?= e(mis_date_label($start, $end)) ?></strong></div></section>
<section class="mis-stat-grid small"><article class="stat-card"><span class="stat-icon purple">♙</span><div><small>Pelanggan bertransaksi</small><strong><?= mis_number(count($customers)) ?></strong><em>Pelanggan dengan order lunas</em></div></article><article class="stat-card"><span class="stat-icon blue">▤</span><div><small>Total pesanan</small><strong><?= mis_number($totalCustomerOrders) ?></strong><em>Dalam periode laporan</em></div></article><article class="stat-card"><span class="stat-icon green">Rp</span><div><small>Belanja rata-rata pelanggan</small><strong><?= rupiah($averageCustomerSpend) ?></strong><em>Omzet ÷ pelanggan aktif</em></div></article></section>
<section class="panel-card"><div class="privacy-note"><strong>Penggunaan data pelanggan</strong><span>Halaman ini hanya tersedia untuk manager dan admin. Informasi digunakan untuk pelaporan agregat internal; bukan untuk mengubah akun atau transaksi pelanggan.</span></div><div class="panel-heading"><div><h2>Pelanggan berdasarkan nilai pembelian</h2><p>Hanya order berstatus lunas dan tidak dibatalkan.</p></div><?php if(has_any(['manager','admin'])): ?><a class="btn ghost" href="mis_export.php?report=customers&start=<?= e($start) ?>&end=<?= e($end) ?>">Unduh CSV ↓</a><?php endif; ?></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Pelanggan</th><th>Email</th><th>Pesanan</th><th>Total pembelian</th><th>Rata-rata / pesanan</th><th>Pembelian terakhir</th></tr></thead><tbody><?php foreach($customers as $customer): ?><tr><td><strong><?= e($customer['name']) ?></strong></td><td><?= e($customer['email']) ?></td><td><?= mis_number($customer['order_count']) ?></td><td><strong><?= rupiah($customer['total_spent']) ?></strong></td><td><?= rupiah((int)$customer['order_count']>0?(float)$customer['total_spent']/(int)$customer['order_count']:0) ?></td><td><?= $customer['last_order']?e(date('d M Y H:i',strtotime($customer['last_order']))):'—' ?></td></tr><?php endforeach; ?><?php if(!$customers): ?><tr><td colspan="6" class="center muted">Belum ada pembelian terbayar pada periode ini.</td></tr><?php endif; ?></tbody></table></div></section>
<?php include __DIR__ . '/layout_bottom.php'; ?>
