<?php
(function () {
    global $pdo;
$current = basename($_SERVER['PHP_SELF'] ?? '');
$isStaffUp = has_any(['staff', 'manager', 'admin']);
$isMgmt = has_any(['manager', 'admin']);
$esOpen = 0;
if (isset($pdo) && is_employee()) {
    try { $esOpen = (int)$pdo->query("SELECT COUNT(*) FROM es_inferences WHERE status IN ('open') AND severity IN ('critical','high')")->fetchColumn(); } catch (Throwable $e) { $esOpen = 0; }
}
/* [label, url, ikon, halaman aktif, badge] */
$menu = [];
$menu[] = ['UTAMA', null, [['Dashboard', 'dashboard.php', 'home', ['dashboard.php']]]];
if (has_role('customer')) {
    $menu[] = ['BELANJA · TPS', null, [
        ['Katalog produk', 'catalog.php', 'grid', ['catalog.php']],
        ['Neo Stylist', 'es_stylist.php', 'sparkles', ['es_stylist.php']],
        ['Keranjang saya', 'cart.php', 'cart', ['cart.php']],
        ['Pesanan saya', 'my_orders.php', 'receipt', ['my_orders.php', 'payment.php']],
        ['Alamat', 'addresses.php', 'pin', ['addresses.php']],
    ]];
}
if (has_any(['seller', 'manager', 'admin'])) {
    $menu[] = ['PRODUK', null, [['Kelola produk', 'products.php', 'box', ['products.php', 'variations.php']]]];
}
if ($isStaffUp) {
    $ops = [
        ['Pesanan & operasional', 'office.php?tab=orders', 'clipboard', ['order_management.php', 'office.php']],
        ['Persediaan', 'inventory.php', 'layers', ['inventory.php']],
        ['Tugas', 'tasks.php', 'check', ['tasks.php']],
        ['Dokumen', 'documents.php', 'file', ['documents.php']],
    ];
    if ($isMgmt) { $ops[] = ['Persetujuan', 'approvals.php', 'shield', ['approvals.php']]; }
    $ops[] = ['Notifikasi', 'notifications.php', 'bell', ['notifications.php']];
    $menu[] = ['OPERASIONAL · OAS', null, $ops];
}
if (is_employee()) {
    $es = [['Pusat Pakar', 'es_dashboard.php', 'cpu', ['es_dashboard.php', 'es_inference.php'], $esOpen]];
    if ($isMgmt) {
        $es[] = ['Basis aturan', 'es_rules.php', 'sliders', ['es_rules.php']];
        $es[] = ['Memori fakta', 'es_facts.php', 'database', ['es_facts.php']];
    }
    $es[] = ['Neo Stylist', 'es_stylist.php', 'sparkles', ['es_stylist.php']];
    $menu[] = ['SISTEM PAKAR · EXPERT SYSTEM', null, $es];
}
if ($isStaffUp) {
    $mis = [
        ['Ringkasan MIS', 'mis_dashboard.php', 'chart', ['mis_dashboard.php']],
        ['Analisis penjualan', 'mis_sales.php', 'chart', ['mis_sales.php']],
        ['Produk terlaris', 'mis_products.php', 'star', ['mis_products.php']],
        ['Analisis stok', 'mis_inventory.php', 'layers', ['mis_inventory.php']],
        ['Alur pesanan', 'mis_operations.php', 'truck', ['mis_operations.php']],
    ];
    if ($isMgmt) { $mis[] = ['Ringkasan pelanggan', 'mis_customers.php', 'users', ['mis_customers.php']]; }
    $menu[] = ['ANALITIK · MIS', null, $mis];
}
if (has_role('admin')) {
    $menu[] = ['PENGADAAN · DSS', null, [
        ['Pemilihan supplier', 'dss_dashboard.php', 'shield', ['dss_dashboard.php', 'dss_evaluation.php']],
        ['Data supplier', 'dss_suppliers.php', 'users', ['dss_suppliers.php']],
        ['Penawaran supplier', 'dss_offers.php', 'tag', ['dss_offers.php']],
        ['Riwayat pengadaan', 'dss_purchase_orders.php', 'truck', ['dss_purchase_orders.php']],
    ]];
}
$kms = [
    ['Ringkasan pengetahuan', 'kms_dashboard.php', 'book', ['kms_dashboard.php']],
    ['Pustaka pengetahuan', 'knowledge.php', 'search', ['knowledge.php', 'knowledge_view.php']],
    ['Tersimpan', 'kms_bookmarks.php', 'bookmark', ['kms_bookmarks.php']],
    ['Permintaan pengetahuan', 'knowledge_request.php', 'help', ['knowledge_request.php']],
];
if (has_any(['seller', 'staff', 'manager', 'admin'])) {
    $kms[] = ['Daftar ahli', 'experts.php', 'star', ['experts.php']];
    $kms[] = ['Tambah pengetahuan', 'knowledge_create.php', 'plus', ['knowledge_create.php']];
}
if ($isMgmt) { $kms[] = ['Review KMS', 'kms_reviews.php', 'check', ['kms_reviews.php']]; }
$menu[] = ['PENGETAHUAN · KMS', null, $kms];
if (has_role('admin')) { $menu[] = ['ADMINISTRASI', null, [['Pengguna & peran', 'users.php', 'users', ['users.php']]]]; }
?>
<aside class="sidebar" aria-label="Navigasi utama">
    <a href="dashboard.php" class="sidebar-brand">
        <span class="brand-mark">N</span>
        <span class="brand-copy"><strong><?= e(APP_NAME) ?></strong><small><?= e(APP_TAGLINE) ?></small></span>
    </a>
    <div class="sidebar-user">
        <span class="sidebar-user-avatar"><?= e(mb_strtoupper(mb_substr((string)($_SESSION['user_name'] ?? 'U'), 0, 1))) ?></span>
        <span><strong><?= e($_SESSION['user_name'] ?? 'Pengguna') ?></strong><small><?php foreach ($_SESSION['roles'] ?? [] as $i => $role): ?><?= $i ? ' · ' : '' ?><?= e(label_role($role)) ?><?php endforeach; ?></small></span>
    </div>
    <nav>
        <ul class="nav-list">
            <?php foreach ($menu as [$section, , $items]): ?>
                <li class="nav-section"><?= e($section) ?></li>
                <?php foreach ($items as $it): ?>
                    <li><a class="<?= in_array($current, $it[3], true) ? 'active' : '' ?>" href="<?= e($it[1]) ?>"><span class="nav-icon"><?= icon($it[2]) ?></span><span><?= e($it[0]) ?></span><?php if (!empty($it[4])): ?><span class="nav-badge"><?= (int)$it[4] ?></span><?php endif; ?></a></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <li class="nav-section nav-section-last">AKUN</li>
            <li><a class="logout-link" href="logout.php"><span class="nav-icon"><?= icon('logout') ?></span><span>Keluar</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">TPS · OAS · KMS · MIS · DSS · ES <small>Expert System menghubungkan seluruh modul · v<?= e(APP_VERSION) ?></small></div>
</aside>
<?php })(); ?>
