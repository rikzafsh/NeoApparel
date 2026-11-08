<?php
/**
 * Navigasi atas Neo Apparel (pengganti sidebar).
 * - Mode etalase (pembeli): pencarian besar, kategori, keranjang.
 * - Mode karyawan: menu bergrup (dropdown) + sub-navigasi kontekstual untuk modul yang sedang dibuka.
 */
(function () {
    global $pdo;
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    $isEmp = is_employee(); $isCust = has_role('customer'); $storefront = $isCust && !$isEmp;
    $staffUp = has_any(['staff', 'manager', 'admin']); $mgmt = has_any(['manager', 'admin']); $admin = has_role('admin');
    $esOpen = 0; $cartQty = 0; $unread = 0; $cats = [];
    try {
        if ($isEmp) { $esOpen = (int)$pdo->query("SELECT COUNT(*) FROM es_inferences WHERE status = 'open' AND severity IN ('critical','high')")->fetchColumn(); }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($isCust) { $s = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE user_id = ?'); $s->execute([$uid]); $cartQty = (int)$s->fetchColumn(); }
        $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'); $s->execute([$uid]); $unread = (int)$s->fetchColumn();
        if ($storefront) { $cats = $pdo->query("SELECT c.name FROM categories c JOIN product_categories pc ON pc.category_id = c.id JOIN products p ON p.id = pc.product_id AND p.is_active = 1 GROUP BY c.id ORDER BY c.name")->fetchAll(PDO::FETCH_COLUMN); }
    } catch (Throwable $e) { /* tampilan tetap dirender */ }

    // [label, url, ikon, deskripsi, halaman aktif, badge]
    $G = [];
    $shop = [['Katalog produk', 'catalog.php', 'grid', 'Jelajahi semua koleksi', ['catalog.php', 'product.php', 'store.php']], ['Neo Stylist', 'es_stylist.php', 'sparkles', 'Rekomendasi outfit dari sistem pakar', ['es_stylist.php']]];
    if ($isCust) { $shop[] = ['Keranjang', 'cart.php', 'cart', 'Periksa dan checkout', ['cart.php']]; }
    $G['shop'] = ['Toko', 'grid', $shop];
    if ($isCust) {
        $G['orders'] = ['Pesanan', 'receipt', [['Pesanan saya', 'my_orders.php', 'receipt', 'Lacak pembayaran & pengiriman', ['my_orders.php', 'payment.php']], ['Alamat pengiriman', 'addresses.php', 'pin', 'Kelola alamat tujuan', ['addresses.php']]]];
    }
    if ($isEmp) {
        $ops = [];
        if (has_any(['seller', 'manager', 'admin'])) { $ops[] = ['Kelola produk', 'products.php', 'box', 'Produk, variasi, atribut gaya', ['products.php', 'variations.php']]; }
        if ($staffUp) {
            $ops[] = ['Persediaan', 'inventory.php', 'layers', 'Stok dan mutasi', ['inventory.php']];
            $ops[] = ['Pesanan & operasional', 'office.php?tab=orders', 'clipboard', 'Verifikasi, kemas, kirim', ['order_management.php', 'office.php']];
            $ops[] = ['Tugas', 'tasks.php', 'check', 'Tugas tim dan tenggat', ['tasks.php']];
            $ops[] = ['Dokumen', 'documents.php', 'file', 'Dokumen kerja', ['documents.php']];
            if ($mgmt) { $ops[] = ['Persetujuan', 'approvals.php', 'shield', 'Approval dokumen', ['approvals.php']]; }
            $ops[] = ['Notifikasi', 'notifications.php', 'bell', 'Pemberitahuan sistem', ['notifications.php']];
        }
        if ($admin) { $ops[] = ['Pengguna & peran', 'users.php', 'users', 'Akun buyer dan karyawan', ['users.php']]; }
        $G['ops'] = ['Operasional', 'clipboard', $ops];
        $es = [['Pusat Pakar', 'es_dashboard.php', 'cpu', 'Kesimpulan & rekomendasi otomatis', ['es_dashboard.php', 'es_inference.php'], $esOpen]];
        if ($mgmt) { $es[] = ['Basis aturan', 'es_rules.php', 'sliders', 'Aturan, parameter, konsol uji, API', ['es_rules.php']]; $es[] = ['Memori fakta', 'es_facts.php', 'database', 'Fakta lintas modul', ['es_facts.php']]; }
        $G['es'] = ['Pakar', 'cpu', $es];
        if ($staffUp) {
            $mis = [['Ringkasan MIS', 'mis_dashboard.php', 'chart', 'KPI & grafik', ['mis_dashboard.php']], ['Analisis penjualan', 'mis_sales.php', 'chart', 'Tren omzet', ['mis_sales.php']], ['Produk terlaris', 'mis_products.php', 'star', 'Kinerja produk', ['mis_products.php']],
                ['Analisis stok', 'mis_inventory.php', 'layers', 'Status persediaan', ['mis_inventory.php']], ['Alur pesanan', 'mis_operations.php', 'truck', 'Pembayaran & pengiriman', ['mis_operations.php']]];
            if ($mgmt) { $mis[] = ['Pelanggan', 'mis_customers.php', 'users', 'Ringkasan pelanggan', ['mis_customers.php']]; }
            $G['mis'] = ['Analitik', 'chart', $mis];
        }
        if ($admin) {
            $G['dss'] = ['Pengadaan', 'truck', [['Pemilihan supplier', 'dss_dashboard.php', 'shield', 'Evaluasi SAW', ['dss_dashboard.php', 'dss_evaluation.php']], ['Data supplier', 'dss_suppliers.php', 'users', 'Profil supplier', ['dss_suppliers.php']],
                ['Penawaran', 'dss_offers.php', 'tag', 'Harga & lead time', ['dss_offers.php']], ['Riwayat PO', 'dss_purchase_orders.php', 'truck', 'Pembelian ke supplier', ['dss_purchase_orders.php']]]];
        }
    }
    $kms = [['Ringkasan', 'kms_dashboard.php', 'book', 'Kondisi pengetahuan', ['kms_dashboard.php']], ['Pustaka pengetahuan', 'knowledge.php', 'search', 'SOP, panduan, FAQ', ['knowledge.php', 'knowledge_view.php']],
        ['Tersimpan', 'kms_bookmarks.php', 'bookmark', 'Artikel favorit', ['kms_bookmarks.php']], ['Permintaan pengetahuan', 'knowledge_request.php', 'help', 'Minta artikel baru', ['knowledge_request.php']]];
    if (has_any(['seller', 'staff', 'manager', 'admin'])) { $kms[] = ['Daftar ahli', 'experts.php', 'star', 'Siapa tahu apa', ['experts.php']]; $kms[] = ['Tambah pengetahuan', 'knowledge_create.php', 'plus', 'Tulis artikel', ['knowledge_create.php']]; }
    if ($mgmt) { $kms[] = ['Review KMS', 'kms_reviews.php', 'check', 'Tinjau artikel', ['kms_reviews.php']]; }
    $G['kms'] = [$isEmp ? 'Pengetahuan' : 'Bantuan', 'book', $kms];

    $activeKey = null;
    foreach ($G as $k => [$lbl, $ic, $items]) { foreach ($items as $it) { if (in_array($current, $it[4], true)) { $activeKey = $k; } } }
    $roleLabel = implode(' · ', array_map('label_role', $_SESSION['roles'] ?? []));
    $initial = mb_strtoupper(mb_substr((string)($_SESSION['user_name'] ?? 'U'), 0, 1));
    $q = trim((string)($_GET['q'] ?? ''));
    ?>
<header class="topnav <?= $storefront ? 'is-store' : 'is-staff' ?>">
    <div class="topnav-row">
        <button type="button" class="icon-btn nav-toggle" id="navToggle" aria-label="Buka menu" aria-controls="mainnav"><?= icon('menu') ?></button>
        <a href="dashboard.php" class="brand"><span class="brand-mark">N</span><span class="brand-copy"><strong><?= e(APP_NAME) ?></strong><small><?= e(APP_TAGLINE) ?></small></span></a>
        <nav class="mainnav" id="mainnav" aria-label="Menu utama">
            <div class="drawer-head"><span class="brand-mark">N</span><strong><?= e(APP_NAME) ?></strong><button type="button" class="icon-btn" id="navClose" aria-label="Tutup menu"><?= icon('x') ?></button></div>
            <a class="navlink <?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><?= icon('home', 'sm') ?><span>Beranda</span></a>
            <?php foreach ($G as $k => [$lbl, $ic, $items]): $badge = 0; foreach ($items as $it) { $badge += (int)($it[5] ?? 0); } ?>
            <div class="navgroup <?= $activeKey === $k ? 'active' : '' ?>">
                <button type="button" class="navlink nav-trigger" aria-expanded="false" aria-haspopup="true"><?= icon($ic, 'sm') ?><span><?= e($lbl) ?></span><?php if ($badge): ?><em class="nav-badge"><?= $badge ?></em><?php endif; ?><svg class="caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
                <div class="menu-panel" role="menu">
                    <?php foreach ($items as $it): ?>
                        <a role="menuitem" class="menu-item <?= in_array($current, $it[4], true) ? 'active' : '' ?>" href="<?= e($it[1]) ?>"><span class="mi-ic"><?= icon($it[2]) ?></span><span class="mi-tx"><b><?= e($it[0]) ?><?php if (!empty($it[5])): ?> <em class="nav-badge"><?= (int)$it[5] ?></em><?php endif; ?></b><small><?= e($it[3]) ?></small></span></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </nav>
        <form class="nav-search" action="catalog.php" method="get" role="search"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari produk…" aria-label="Cari produk"></form>
        <div class="nav-actions">
            <?php if ($isCust): ?><a class="icon-btn" href="cart.php" aria-label="Keranjang" title="Keranjang"><?= icon('cart') ?><?php if ($cartQty): ?><span class="dot teal"><?= $cartQty > 99 ? '99+' : $cartQty ?></span><?php endif; ?></a><?php endif; ?>
            <a class="icon-btn" href="notifications.php" aria-label="Notifikasi" title="Notifikasi" <?= $isEmp ? '' : 'hidden' ?>><?= icon('bell') ?><?php if ($unread): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></a>
            <div class="navgroup user-menu">
                <button type="button" class="user-btn nav-trigger" aria-expanded="false" aria-haspopup="true"><span class="topbar-avatar"><?= e($initial) ?></span><span class="uname"><?= e(explode(' ', (string)($_SESSION['user_name'] ?? ''))[0]) ?></span><svg class="caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
                <div class="menu-panel right" role="menu" style="min-width:260px">
                    <div class="um-head"><span class="topbar-avatar lg"><?= e($initial) ?></span><div><b><?= e($_SESSION['user_name'] ?? '') ?></b><small><?= e($_SESSION['user_email'] ?? '') ?></small><small class="role"><?= e($roleLabel) ?></small></div></div>
                    <a role="menuitem" class="menu-item compact" href="dashboard.php"><span class="mi-ic"><?= icon('home') ?></span><span class="mi-tx"><b>Dashboard</b></span></a>
                    <?php if ($isCust): ?><a role="menuitem" class="menu-item compact" href="my_orders.php"><span class="mi-ic"><?= icon('receipt') ?></span><span class="mi-tx"><b>Pesanan saya</b></span></a><a role="menuitem" class="menu-item compact" href="addresses.php"><span class="mi-ic"><?= icon('pin') ?></span><span class="mi-tx"><b>Alamat</b></span></a><?php endif; ?>
                    <?php if ($isEmp): ?><a role="menuitem" class="menu-item compact" href="notifications.php"><span class="mi-ic"><?= icon('bell') ?></span><span class="mi-tx"><b>Notifikasi</b></span></a><?php endif; ?>
                    <a role="menuitem" class="menu-item compact danger" href="logout.php"><span class="mi-ic"><?= icon('logout') ?></span><span class="mi-tx"><b>Keluar</b></span></a>
                </div>
            </div>
        </div>
    </div>
    <?php if ($storefront): ?>
    <div class="subnav cats" aria-label="Kategori"><div class="subnav-in"><a class="<?= ($current === 'catalog.php' && empty($_GET['cat'])) ? 'active' : '' ?>" href="catalog.php">Semua</a><?php foreach ($cats as $c): ?><a class="<?= ($current === 'catalog.php' && ($_GET['cat'] ?? '') === $c) ? 'active' : '' ?>" href="catalog.php?cat=<?= e(urlencode($c)) ?>"><?= e($c) ?></a><?php endforeach; ?><span class="spacer"></span><a href="es_stylist.php" class="hl"><?= icon('sparkles', 'sm') ?> Neo Stylist</a></div></div>
    <?php elseif ($activeKey && count($G[$activeKey][2]) > 1): ?>
    <div class="subnav" aria-label="Sub-menu <?= e($G[$activeKey][0]) ?>"><div class="subnav-in"><span class="sub-title"><?= icon($G[$activeKey][1], 'sm') ?> <?= e($G[$activeKey][0]) ?></span><?php foreach ($G[$activeKey][2] as $it): ?><a class="<?= in_array($current, $it[4], true) ? 'active' : '' ?>" href="<?= e($it[1]) ?>"><?= e($it[0]) ?></a><?php endforeach; ?></div></div>
    <?php endif; ?>
</header>
<div class="nav-scrim" id="navScrim"></div>
<?php
})();
