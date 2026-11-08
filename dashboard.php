<?php
require_once 'db.php';
require_once 'functions.php';
$u = login_required($pdo);
$uid = (int)$u['id'];
$isCustomer = has_role('customer');
$isEmployee = is_employee();

$q = static function (string $sql, array $args = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchColumn(); };
$activeProducts = (int)$q('SELECT COUNT(*) FROM products WHERE is_active=1');
$myOrders = (int)$q('SELECT COUNT(*) FROM orders WHERE user_id=?', [$uid]);
$cartQty = (int)$q('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE user_id=?', [$uid]);

$kpi = [];
if ($isCustomer) {
    $kpi[] = ['Pesanan saya', $myOrders, 'receipt', ''];
    $kpi[] = ['Item di keranjang', $cartQty, 'cart', 'teal'];
}
if ($isEmployee) {
    $kpi[] = ['Perlu diproses', (int)$q("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')"), 'clipboard', 'amber'];
    $kpi[] = ['Variasi stok ≤ 5', (int)$q('SELECT COUNT(*) FROM product_variations WHERE is_active=1 AND stock<=5'), 'layers', 'rose'];
    $kpi[] = ['Tugas saya', (int)$q("SELECT COUNT(*) FROM tasks WHERE assignee_id=? AND status IN ('pending','in_progress')", [$uid]), 'check', 'teal'];
    $kpi[] = ['Peringatan pakar (tinggi+)', (int)$q("SELECT COUNT(*) FROM es_inferences WHERE status IN ('open','in_progress') AND severity IN ('critical','high')"), 'cpu', 'rose'];
}
if (has_any(['manager', 'admin'])) {
    $kpi[] = ['Persetujuan menunggu', (int)$q("SELECT COUNT(*) FROM approvals WHERE status='pending'"), 'shield', 'amber'];
    $kpi[] = ['Omzet bulan ini', rupiah((float)$q("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE payment_status='paid' AND status<>'cancelled' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")), 'chart', ''];
}
$kpi[] = ['Produk aktif', $activeProducts, 'box', ''];

$alerts = [];
if ($isEmployee) {
    $alerts = $pdo->query("SELECT id,domain,severity,title,message,certainty,subject_label FROM es_inferences WHERE status IN ('open','in_progress')
        ORDER BY FIELD(severity,'critical','high','medium','low','info'), certainty DESC, id DESC LIMIT 5")->fetchAll();
}
$recent = [];
if ($isCustomer) {
    $s = $pdo->prepare('SELECT id,order_number,total_price,status,payment_status,created_at FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 4');
    $s->execute([$uid]); $recent = $s->fetchAll();
}
$hour = (int)date('G');
$greet = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 19 ? 'Selamat sore' : 'Selamat malam'));
$quick = [];
if ($isCustomer) {
    $quick[] = ['Jelajahi katalog', 'Pilih warna & ukuran langsung dari kartu produk.', 'catalog.php', 'grid'];
    $quick[] = ['Neo Stylist', 'Jawab 5 pertanyaan, dapatkan rekomendasi outfit beserta alasannya.', 'es_stylist.php', 'sparkles'];
    $quick[] = ['Pesanan saya', 'Lacak status pembayaran dan pengiriman.', 'my_orders.php', 'receipt'];
}
if (has_any(['staff', 'manager', 'admin'])) {
    $quick[] = ['Proses pesanan', 'Verifikasi, kemas, dan kirim pesanan.', 'office.php?tab=orders', 'clipboard'];
    $quick[] = ['Persediaan', 'Pantau dan sesuaikan stok.', 'inventory.php', 'layers'];
    $quick[] = ['Ringkasan MIS', 'Grafik penjualan dan stok.', 'mis_dashboard.php', 'chart'];
}
if (is_employee()) { $quick[] = ['Pusat Pakar', 'Rekomendasi otomatis lintas TPS, OAS, KMS, MIS, DSS.', 'es_dashboard.php', 'cpu']; }
if (has_role('admin')) { $quick[] = ['Pemilihan supplier', 'Evaluasi supplier dengan DSS (SAW).', 'dss_dashboard.php', 'truck']; $quick[] = ['Pengguna & peran', 'Kelola akun buyer dan karyawan.', 'users.php', 'users']; }
if (has_any(['seller', 'manager', 'admin'])) { $quick[] = ['Kelola produk', 'Tambah produk dan variasi.', 'products.php', 'box']; }
$quick[] = ['Pustaka pengetahuan', 'SOP, panduan, dan pelajaran dari tim.', 'knowledge.php', 'book'];

$pageTitle = 'Dashboard';
include 'layout_top.php';
?>
<section class="hello">
    <div>
        <span class="kicker"><?= e(mb_strtoupper(date('l, d F Y'))) ?></span>
        <h1><?= e($greet) ?>, <?= e(explode(' ', trim($u['name']))[0]) ?>.</h1>
        <p><?= $isEmployee ? 'Berikut kondisi toko hari ini dan hal-hal yang menurut Pusat Pakar perlu perhatian Anda.' : 'Temukan pakaian yang pas untuk harimu, atau biarkan Neo Stylist membantu memilih.' ?></p>
    </div>
    <div class="art"><?= garment_svg('T-Shirt', 'Putih') ?><?= garment_svg('Hoodie', 'Abu-abu') ?><?= garment_svg('Jacket', 'Olive') ?></div>
</section>

<div class="kpi-grid">
    <?php foreach ($kpi as [$label, $val, $ic, $tone]): ?>
        <div class="kpi"><span class="ic <?= e($tone) ?>"><?= icon($ic) ?></span><div><small><?= e($label) ?></small><b><?= is_int($val) ? number_format($val, 0, ',', '.') : e($val) ?></b></div></div>
    <?php endforeach; ?>
</div>

<?php if ($isEmployee): ?>
<div class="section-title"><h2>Insight dari Pusat Pakar</h2><a class="text-link" href="es_dashboard.php">Lihat semua →</a></div>
<?php if ($alerts): ?>
    <div class="inf-list">
        <?php foreach ($alerts as $a): ?>
            <article class="inf <?= e($a['severity']) ?>">
                <header><span class="sev <?= e($a['severity']) ?>"><?= e($a['severity']) ?></span><span class="dom <?= e($a['domain']) ?>"><?= e($a['domain']) ?></span>
                    <span class="cf"><span class="bar"><i style="width:<?= (int)round($a['certainty'] * 100) ?>%"></i></span><?= (int)round($a['certainty'] * 100) ?>% yakin</span></header>
                <h3><a href="es_inference.php?id=<?= (int)$a['id'] ?>"><?= e($a['title']) ?></a></h3>
                <p><?= e($a['message']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card empty-state"><?= icon('cpu') ?><p>Belum ada peringatan. Jalankan inferensi dari <a class="text-link" href="es_dashboard.php">Pusat Pakar</a>.</p></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($isCustomer && $recent): ?>
<div class="section-title"><h2>Pesanan terakhir</h2><a class="text-link" href="my_orders.php">Semua pesanan →</a></div>
<div class="card"><table><thead><tr><th>Pesanan</th><th>Tanggal</th><th>Total</th><th>Status</th></tr></thead><tbody>
<?php foreach ($recent as $o): ?><tr><td><a class="text-link" href="my_orders.php#o<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td><td><?= e(fmt_dt($o['created_at'])) ?></td><td><?= rupiah($o['total_price']) ?></td><td><?= badge($o['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<div class="section-title"><h2>Pintasan</h2></div>
<div class="quick-grid">
    <?php foreach ($quick as [$t, $d, $href, $ic]): ?>
        <a class="quick" href="<?= e($href) ?>"><span class="ic"><?= icon($ic) ?></span><div><b><?= e($t) ?></b><span><?= e($d) ?></span></div></a>
    <?php endforeach; ?>
</div>
<?php include 'layout_bottom.php'; ?>
