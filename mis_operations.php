<?php
require_once __DIR__ . '/mis_functions.php';
$user = mis_require_employee();
[$start, $end] = mis_period();
[$from, $to] = mis_bounds($start, $end);
$statusStmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY total DESC");
$statusStmt->execute([$from, $to]);
$statusRows = $statusStmt->fetchAll();
$totalOrders = array_sum(array_map(static fn($r) => (int)$r['total'], $statusRows));
$pendingCount = 0;
$processingCount = 0;
$completed = 0;
$cancelled = 0;
foreach ($statusRows as $statusRow) {
    if ($statusRow['status'] === 'pending') $pendingCount = (int)$statusRow['total'];
    if ($statusRow['status'] === 'processing') $processingCount = (int)$statusRow['total'];
    if ($statusRow['status'] === 'completed') $completed = (int)$statusRow['total'];
    if ($statusRow['status'] === 'cancelled') $cancelled = (int)$statusRow['total'];
}
$pendingStmt = $pdo->prepare("SELECT o.order_number, u.name AS buyer_name, o.total_price, o.status, o.payment_status,
        TIMESTAMPDIFF(HOUR, o.created_at, NOW()) AS age_hours, o.created_at
    FROM orders o JOIN users u ON u.id = o.user_id
    WHERE o.created_at BETWEEN ? AND ? AND o.status IN ('pending','processing')
    ORDER BY o.created_at ASC LIMIT 20");
$pendingStmt->execute([$from, $to]);
$pendingOrders = $pendingStmt->fetchAll();
$paymentStmt = $pdo->prepare("SELECT payment_status, COUNT(*) AS total, COALESCE(SUM(total_price),0) AS amount
    FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY payment_status ORDER BY total DESC");
$paymentStmt->execute([$from, $to]);
$paymentRows = $paymentStmt->fetchAll();
$pageTitle = 'Alur pesanan';
include __DIR__ . '/layout_top.php';
?>
<header class="page-header"><div><div class="eyebrow">MIS / OPERASIONAL</div><h1>Alur pesanan</h1><p class="muted">Ringkasan status pesanan membantu tim menentukan pekerjaan yang perlu ditindaklanjuti.</p></div><a class="btn primary" href="office.php?tab=orders">Buka operasi pesanan ↗</a></header>
<section class="mis-filter-card"><form method="get" class="mis-filter-form"><div><label for="start">Tanggal mulai</label><input id="start" type="date" name="start" value="<?= e($start) ?>" required></div><div><label for="end">Tanggal akhir</label><input id="end" type="date" name="end" value="<?= e($end) ?>" required></div><button class="btn primary" type="submit">Terapkan filter</button><a class="btn ghost" href="mis_operations.php">30 hari terakhir</a></form><div class="mis-period-note">Periode: <strong><?= e(mis_date_label($start, $end)) ?></strong> · Semua status pesanan, tidak hanya pesanan lunas.</div></section>
<section class="mis-stat-grid"><article class="stat-card"><span class="stat-icon purple">▤</span><div><small>Total pesanan</small><strong><?= mis_number($totalOrders) ?></strong><em>Semua status</em></div></article><article class="stat-card"><span class="stat-icon blue">◷</span><div><small>Menunggu diproses</small><strong><?= mis_number($pendingCount + $processingCount) ?></strong><em><?= mis_number($pendingCount) ?> menunggu · <?= mis_number($processingCount) ?> diproses</em></div></article><article class="stat-card"><span class="stat-icon green">↗</span><div><small>Selesai</small><strong><?= mis_number($completed) ?></strong><em>Pesanan berstatus selesai</em></div></article><article class="stat-card"><span class="stat-icon red">!</span><div><small>Dibatalkan</small><strong><?= mis_number($cancelled) ?></strong><em>Perlu dilihat sebagai indikator operasional</em></div></article></section>
<section class="panel-card"><div class="panel-heading"><div><h2>Distribusi status pesanan</h2><p>Jumlah pesanan menurut status saat ini, berdasarkan tanggal pembuatan dalam periode terpilih.</p></div></div><div class="mis-funnel-grid"><?php $statusOrder=['pending','processing','shipped','completed','cancelled']; foreach($statusOrder as $known): $value=0; foreach($statusRows as $r){if($r['status']===$known){$value=(int)$r['total'];break;}} ?><div class="mis-funnel-card"><span><?= e(mis_status_label($known)) ?></span><strong><?= mis_number($value) ?></strong></div><?php endforeach; ?></div></section>
<section class="mis-grid-2"><article class="panel-card"><div class="panel-heading"><div><h2>Status pembayaran</h2><p>Jumlah dan nilai order sesuai status pembayaran.</p></div></div><?php if($paymentRows): ?><div class="mis-mini-grid"><?php foreach($paymentRows as $row): ?><div class="mini-report-card"><span><?= e(mis_status_label($row['payment_status'])) ?></span><strong><?= mis_number($row['total']) ?></strong><small><?= rupiah($row['amount']) ?></small></div><?php endforeach; ?></div><?php else: ?><div class="mis-empty-inline">Belum ada transaksi.</div><?php endif; ?></article>
<article class="panel-card"><div class="panel-heading"><div><h2>Rekomendasi tindak lanjut</h2><p>Panduan awal dari ringkasan status.</p></div></div><div class="mis-simple-list"><div><strong>Pesanan menunggu</strong><small>Pastikan pembayaran dan detail order telah sesuai sebelum diproses.</small><b>1</b></div><div><strong>Pesanan diproses</strong><small>Periksa kesiapan barang dan pembaruan status pengiriman.</small><b>2</b></div><div><strong>Pesanan dibatalkan</strong><small>Gunakan riwayat pembatalan untuk mengevaluasi penyebab yang sering muncul.</small><b>3</b></div></div></article></section>
<section class="panel-card"><div class="panel-heading"><div><h2>Pesanan yang perlu perhatian</h2><p>Pesanan berstatus menunggu atau diproses, diurutkan dari yang paling lama.</p></div><span class="status-pill warning"><?= mis_number(count($pendingOrders)) ?> ditampilkan</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Nomor pesanan</th><th>Pelanggan</th><th>Total</th><th>Pembayaran</th><th>Status order</th><th>Usia pesanan</th></tr></thead><tbody><?php foreach($pendingOrders as $order): ?><tr><td><strong><?= e($order['order_number']) ?></strong><small class="table-secondary"><?= e(date('d M Y H:i', strtotime($order['created_at']))) ?></small></td><td><?= e($order['buyer_name']) ?></td><td><strong><?= rupiah($order['total_price']) ?></strong></td><td><span class="status-pill <?= $order['payment_status']==='paid'?'success':($order['payment_status']==='failed'?'danger':'warning') ?>"><?= e(mis_status_label($order['payment_status'])) ?></span></td><td><span class="status-pill neutral"><?= e(mis_status_label($order['status'])) ?></span></td><td><?= mis_number(max(0,(int)$order['age_hours'])) ?> jam</td></tr><?php endforeach; ?><?php if(!$pendingOrders): ?><tr><td colspan="6" class="center muted">Tidak ada pesanan menunggu atau diproses dalam periode ini.</td></tr><?php endif; ?></tbody></table></div></section>
<?php include __DIR__ . '/layout_bottom.php'; ?>
