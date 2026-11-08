<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'shop_functions.php';
require_once 'es_actions.php';
$u = login_required($pdo);
$uid = (int)$u['id'];
$pid = (int)($_GET['id'] ?? 0);
$list = shop_products($pdo, ['id' => $pid]);
if (!$list) { flash('error', 'Produk tidak ditemukan atau sudah tidak dijual.'); go('catalog.php'); }
$p = $list[$pid];
$canBuy = has_role('customer');

/* ---------- kirim ulasan ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    check_csrf();
    $oiId = (int)($_POST['order_item_id'] ?? 0); $rating = (int)($_POST['rating'] ?? 0); $text = trim((string)($_POST['review'] ?? ''));
    $chk = $pdo->prepare("SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN product_variations v ON v.id = oi.variation_id
        WHERE oi.id = ? AND o.user_id = ? AND o.status = 'completed' AND v.product_id = ? AND NOT EXISTS (SELECT 1 FROM product_reviews r WHERE r.order_item_id = oi.id)");
    $chk->execute([$oiId, $uid, $pid]);
    if ($rating < 1 || $rating > 5) { flash('error', 'Pilih jumlah bintang 1 sampai 5.'); }
    elseif (!$chk->fetch()) { flash('error', 'Ulasan hanya dapat diberikan untuk pesanan selesai yang belum diulas.'); }
    else {
        $pdo->prepare("INSERT INTO product_reviews(user_id, product_id, order_item_id, rating, review, status) VALUES(?,?,?,?,?, 'published')")->execute([$uid, $pid, $oiId, $rating, mb_substr($text, 0, 1500) ?: null]);
        flash('success', 'Terima kasih! Ulasan Anda sudah tayang.');
    }
    go('product.php?id=' . $pid . '#ulasan');
}
shop_remember_view($pid);

/* ---------- atribut & spesifikasi ---------- */
$attrs = [];
foreach ($pdo->query('SELECT product_id, attribute, value FROM es_product_attributes')->fetchAll() as $r) { $attrs[(int)$r['product_id']][$r['attribute']][] = $r['value']; }
$myAttrs = $attrs[$pid] ?? [];
$colors = []; $sizes = [];
foreach ($p['vars'] as $v) { $colors[$v['color']] = $v['hex']; $sizes[$v['size']] = true; }
$weights = array_filter(array_column($p['vars'], 'weight'));
$ATTR_LABEL = ['material' => 'Bahan', 'fit' => 'Potongan', 'occasion' => 'Cocok untuk acara', 'style' => 'Gaya', 'climate' => 'Cuaca ideal'];

/* ---------- ringkasan toko ---------- */
$seller = $pdo->prepare("SELECT u.name, u.created_at,
        (SELECT COUNT(*) FROM products WHERE seller_id = u.id AND is_active = 1) products,
        (SELECT ROUND(AVG(r.rating),1) FROM product_reviews r JOIN products x ON x.id = r.product_id WHERE x.seller_id = u.id AND r.status = 'published') rating,
        (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN product_variations v ON v.id = oi.variation_id JOIN products x ON x.id = v.product_id
            WHERE x.seller_id = u.id AND o.payment_status = 'paid' AND o.status <> 'cancelled') sold
    FROM users u WHERE u.id = ?");
$seller->execute([$p['seller_id']]); $seller = $seller->fetch();

/* ---------- ulasan: filter, urutan, halaman ---------- */
$dist = array_fill(1, 5, 0);
foreach ($pdo->query("SELECT rating, COUNT(*) n FROM product_reviews WHERE product_id = $pid AND status = 'published' GROUP BY rating")->fetchAll() as $r) { $dist[(int)$r['rating']] = (int)$r['n']; }
$withText = (int)$pdo->query("SELECT COUNT(*) FROM product_reviews WHERE product_id = $pid AND status = 'published' AND review IS NOT NULL AND review <> ''")->fetchColumn();
$fRating = (int)($_GET['r'] ?? 0); $fText = !empty($_GET['c']); $fSort = in_array($_GET['s'] ?? '', ['new', 'high', 'low'], true) ? $_GET['s'] : 'new';
$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 5;
$w = "r.product_id = ? AND r.status = 'published'"; $a = [$pid];
if ($fRating >= 1 && $fRating <= 5) { $w .= ' AND r.rating = ?'; $a[] = $fRating; }
if ($fText) { $w .= " AND r.review IS NOT NULL AND r.review <> ''"; }
$total = $pdo->prepare("SELECT COUNT(*) FROM product_reviews r WHERE $w"); $total->execute($a); $total = (int)$total->fetchColumn();
$order = ['new' => 'r.created_at DESC', 'high' => 'r.rating DESC, r.created_at DESC', 'low' => 'r.rating ASC, r.created_at DESC'][$fSort];
$off = ($page - 1) * $perPage;
$rv = $pdo->prepare("SELECT r.*, u.name uname, oi.color, oi.size FROM product_reviews r JOIN users u ON u.id = r.user_id LEFT JOIN order_items oi ON oi.id = r.order_item_id WHERE $w ORDER BY $order LIMIT $perPage OFFSET $off");
$rv->execute($a); $reviews = $rv->fetchAll();
$pages = max(1, (int)ceil($total / $perPage));
$qs = static fn(array $over = []) => 'product.php?' . http_build_query(array_filter(array_merge(['id' => $pid, 'r' => $fRating ?: null, 'c' => $fText ? 1 : null, 's' => $fSort !== 'new' ? $fSort : null], $over), static fn($x) => $x !== null && $x !== '')) . '#ulasan';

$eligible = [];
if ($canBuy) {
    $st = $pdo->prepare("SELECT oi.id, oi.color, oi.size, o.order_number, o.created_at FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN product_variations v ON v.id = oi.variation_id
        WHERE o.user_id = ? AND o.status = 'completed' AND v.product_id = ? AND NOT EXISTS (SELECT 1 FROM product_reviews r WHERE r.order_item_id = oi.id) ORDER BY o.created_at DESC");
    $st->execute([$uid, $pid]); $eligible = $st->fetchAll();
}

/* ---------- produk lain ---------- */
$all = shop_products($pdo, ['exclude' => [$pid]]);
$similar = [];
foreach ($all as $q) {
    $score = 0;
    if ($q['cat'] === $p['cat']) { $score += 3; }
    foreach (['occasion', 'style', 'climate', 'fit', 'material'] as $k) { $score += count(array_intersect($myAttrs[$k] ?? [], $attrs[$q['id']][$k] ?? [])); }
    if ($score >= 3) { $similar[$q['id']] = [$score, $q]; }
}
uasort($similar, static fn($x, $y) => [$y[0], $y[1]['sold']] <=> [$x[0], $x[1]['sold']]);
$similar = array_slice(array_map(static fn($x) => $x[1], $similar), 0, 6, true);
$sameStore = array_filter($all, static fn($q) => $q['seller_id'] === $p['seller_id'] && !isset($similar[$q['id']]));
uasort($sameStore, static fn($x, $y) => [(int)($y['cat'] !== $p['cat']), $y['sold']] <=> [(int)($x['cat'] !== $p['cat']), $x['sold']]);
$sameStore = array_slice($sameStore, 0, 6, true);

$TOPS = ['T-Shirt', 'Shirt', 'Hoodie', 'Jacket'];
$wantCats = in_array($p['cat'], $TOPS, true) ? ['Trousers'] : ['T-Shirt', 'Shirt', 'Hoodie'];
$NEUTRAL = ['Hitam', 'Putih', 'Abu-abu', 'Krem', 'Navy', 'Khaki', 'Biru Denim'];
$pairs = [];
foreach ($all as $q) {
    if (!in_array($q['cat'], $wantCats, true) || $q['stock'] <= 0) { continue; }
    $s = 0; foreach (['occasion', 'style', 'climate'] as $k) { $s += count(array_intersect($myAttrs[$k] ?? [], $attrs[$q['id']][$k] ?? [])); }
    $neutral = in_array($q['lead']['color'], $NEUTRAL, true) || in_array($p['lead']['color'], $NEUTRAL, true);
    if ($neutral && $s > 0) { $pairs[] = [$s + $q['sold30'] / 100, $q]; }
}
usort($pairs, static fn($x, $y) => $y[0] <=> $x[0]);
$pairs = array_slice(array_column($pairs, 1), 0, 3);

$recent = [];
$rid = array_values(array_diff($_SESSION['recent_products'] ?? [], [$pid]));
if ($rid) { $rp = shop_products($pdo, ['ids' => $rid]); foreach ($rid as $i) { if (isset($rp[$i])) { $recent[] = $rp[$i]; } } $recent = array_slice($recent, 0, 6); }

/* ---------- rujukan KMS & insight pakar ---------- */
$kmsGuide = es_kms_search(['ukuran', 'perawatan'], 3);
$kmsReturn = es_kms_search(['retur'], 1);
$kmsGuide = array_values(array_filter(array_merge($kmsGuide, $kmsReturn), static fn($i) => $i['visibility'] === 'public' || is_employee()));
$insights = [];
if (is_employee()) {
    $ids = implode(',', array_column($p['vars'], 'id'));
    $insights = $pdo->query("SELECT id, severity, title, message FROM es_inferences WHERE subject_type = 'variation' AND subject_id IN ($ids) AND status IN ('open','acknowledged','in_progress') ORDER BY FIELD(severity,'critical','high','medium','low','info') LIMIT 5")->fetchAll();
}
$cartQty = 0;
$cartMap = $pdo->prepare('SELECT variation_id, quantity FROM cart_items WHERE user_id = ?'); $cartMap->execute([$uid]);
$inCart = []; foreach ($cartMap->fetchAll() as $r) { $inCart[(int)$r['variation_id']] = (int)$r['quantity']; }

$js = ['vars' => $p['vars'], 'inCart' => $inCart, 'cat' => $p['cat']];
$pageTitle = $p['name'];
$pageKicker = $p['cat'];
include 'layout_top.php';
?>
<nav class="crumbs" aria-label="Breadcrumb"><a href="dashboard.php">Beranda</a><span>›</span><a href="catalog.php">Katalog</a><span>›</span><a href="catalog.php?cat=<?= e(urlencode($p['cat'])) ?>"><?= e($p['cat']) ?></a><span>›</span><b><?= e($p['name']) ?></b></nav>

<section class="pd" id="pd" data-js='<?= e(json_encode($js, JSON_UNESCAPED_UNICODE)) ?>'>
    <div class="pd-gallery">
        <div class="pd-main" id="mainVis">
            <?= garment_svg($p['cat'], $p['lead']['color'], $p['name']) ?>
            <img id="mainImg" alt="<?= e($p['name']) ?>" hidden>
            <?php if ($p['sold30'] >= 10): ?><span class="pill hot" style="position:absolute;top:14px;left:14px">Laris minggu ini</span><?php endif; ?>
        </div>
        <div class="pd-thumbs" id="thumbs" role="tablist" aria-label="Pilih warna">
            <?php foreach ($colors as $cn => $hex): $cv = null; foreach ($p['vars'] as $v) { if ($v['color'] === $cn) { $cv = $v; break; } } ?>
                <button type="button" class="thumb" data-color="<?= e($cn) ?>" title="<?= e($cn) ?>"><?= product_visual($cv['image'], $p['cat'], $cn, $p['name'] . ' ' . $cn) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="row" style="margin-top:10px"><button type="button" class="btn-sm" id="copyLink"><?= icon('link', 'sm') ?> Salin tautan</button><a class="btn-sm" href="es_stylist.php"><?= icon('sparkles', 'sm') ?> Minta saran Neo Stylist</a></div>
    </div>

    <div class="pd-info">
        <span class="p-cat"><?= e($p['cat']) ?><?= $p['brand'] ? ' · ' . e($p['brand']) : '' ?></span>
        <h1><?= e($p['name']) ?></h1>
        <div class="pd-meta">
            <a href="#ulasan"><?= shop_stars($p['avg']) ?><?php if ($p['n']): ?> <span class="muted">(<?= (int)$p['n'] ?> ulasan)</span><?php endif; ?></a>
            <span class="sep"></span><span><b><?= e(shop_sold_label($p['sold'])) ?></b> terjual</span>
            <?php if ($p['sold30'] > 0): ?><span class="sep"></span><span><b><?= (int)$p['sold30'] ?></b> dalam 30 hari</span><?php endif; ?>
        </div>
        <div class="pd-price"><span id="price"><?= e(shop_price_label($p)) ?></span><small id="priceNote" class="muted"></small></div>

        <form method="post" action="cart.php" id="buyForm">
            <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="return" value="product.php?id=<?= $pid ?>">
            <input type="hidden" name="variation_id" id="fVar" value=""><input type="hidden" name="quantity" id="fQty" value="1">
            <div class="pd-option"><div class="lbl">Warna: <b id="colorName"></b></div><div class="swatches" id="swatches" role="group" aria-label="Warna"></div></div>
            <div class="pd-option"><div class="lbl">Ukuran: <b id="sizeName"></b> <a class="text-link" href="#spesifikasi" style="margin-left:8px">Panduan ukuran</a></div><div class="sizes" id="sizes" role="group" aria-label="Ukuran"></div></div>
            <div class="pd-option"><div class="lbl">Jumlah</div>
                <div class="row"><div class="qty"><button type="button" id="qMinus" aria-label="Kurangi">−</button><span id="qVal">1</span><button type="button" id="qPlus" aria-label="Tambah">+</button></div><span id="stockNote" class="stock-note"></span></div></div>
            <?php if ($canBuy): ?>
            <div class="pd-actions"><button class="btn ghost lg" type="submit" id="btnAdd"><?= icon('cart', 'sm') ?> Tambah ke keranjang</button><button class="btn primary lg" type="submit" name="buy_now" value="1" id="btnBuy">Beli sekarang</button></div>
            <?php else: ?><div class="alert info">Akun karyawan tidak dapat berbelanja. Gunakan akun pembeli untuk mencoba checkout. <a class="text-link" href="products.php?product_id=<?= $pid ?>">Kelola produk ini →</a></div><?php endif; ?>
        </form>
        <ul class="pd-assure">
            <li><?= icon('shield') ?><span><b>Pembayaran aman</b><small>Status pesanan terlacak sampai tiba</small></span></li>
            <li><?= icon('truck') ?><span><b>Dikirim 1–2 hari kerja</b><small>Ongkir flat <?= rupiah(neo_config('shipping_flat', 15000)) ?>, pesanan lunas dikemas ≤ 48 jam</small></span></li>
            <li><?= icon('check') ?><span><b>Retur 7 hari</b><small>Untuk barang cacat atau salah kirim</small></span></li>
        </ul>
    </div>
</section>

<?php if ($insights): ?>
<section class="card es-peek"><div class="section-title" style="margin-top:0"><h2><?= icon('cpu', 'sm') ?> Insight Expert System untuk produk ini</h2><a class="text-link" href="es_dashboard.php">Pusat Pakar →</a></div>
    <?php foreach ($insights as $i): ?><a class="link-card" href="es_inference.php?id=<?= (int)$i['id'] ?>"><span class="ic"><span class="sev <?= e($i['severity']) ?>" style="padding:3px 7px"><?= e(mb_substr($i['severity'], 0, 1)) ?></span></span><div><b><?= e($i['title']) ?></b><small><?= e($i['message']) ?></small></div></a><?php endforeach; ?></section>
<?php endif; ?>

<section class="seller-card">
    <div class="seller-id"><span class="avatar"><?= e(mb_strtoupper(mb_substr($seller['name'], 0, 1))) ?></span><div><b><?= e($seller['name']) ?></b><small class="muted">Penjual · bergabung <?= e(date('M Y', strtotime($seller['created_at']))) ?></small>
        <div class="row" style="margin-top:8px"><a class="btn ghost sm" href="store.php?seller=<?= (int)$p['seller_id'] ?>">Kunjungi toko</a></div></div></div>
    <dl class="seller-stats"><div><dt>Produk</dt><dd><?= (int)$seller['products'] ?></dd></div><div><dt>Penilaian toko</dt><dd><?= $seller['rating'] !== null ? '★ ' . number_format((float)$seller['rating'], 1, ',', '') : '-' ?></dd></div><div><dt>Terjual</dt><dd><?= e(shop_sold_label((int)$seller['sold'])) ?></dd></div></dl>
</section>

<nav class="pd-tabs" id="pdTabs"><a href="#deskripsi" class="on">Deskripsi</a><a href="#spesifikasi">Spesifikasi</a><a href="#ulasan">Ulasan <small>(<?= (int)$p['n'] ?>)</small></a><a href="#serupa">Produk serupa</a></nav>

<section class="card pd-block" id="deskripsi"><h2>Deskripsi produk</h2>
    <p><?= nl2br(e($p['desc'] !== '' ? $p['desc'] : 'Penjual belum menambahkan deskripsi.')) ?></p>
    <?php if ($myAttrs): ?><div class="fit-chips"><?php foreach (['occasion', 'style', 'climate'] as $k): foreach ($myAttrs[$k] ?? [] as $val): ?><span class="tag"><?= e($ATTR_LABEL[$k]) ?>: <b><?= e(ucfirst($val)) ?></b></span><?php endforeach; endforeach; ?></div><?php endif; ?>
    <ul class="bullets"><li>Tersedia <?= count($colors) ?> warna dan <?= count($sizes) ?> ukuran; stok diperbarui langsung dari sistem persediaan.</li><li>Pesanan dibayar sebelum pukul 16.00 diproses pada hari kerja yang sama.</li><li>Cocok dipadukan dengan koleksi lain, lihat bagian <a class="text-link" href="#lengkapi">Lengkapi gayamu</a>.</li></ul>
</section>

<section class="card pd-block" id="spesifikasi"><h2>Spesifikasi</h2>
    <dl class="spec">
        <dt>Kategori</dt><dd><a class="text-link" href="catalog.php?cat=<?= e(urlencode($p['cat'])) ?>"><?= e($p['cat']) ?></a></dd>
        <?php if ($p['brand']): ?><dt>Merek</dt><dd><?= e($p['brand']) ?></dd><?php endif; ?>
        <dt>Warna</dt><dd><?= e(implode(', ', array_keys($colors))) ?></dd><dt>Ukuran</dt><dd><?= e(implode(', ', array_keys($sizes))) ?></dd>
        <?php foreach (['material', 'fit', 'style', 'occasion', 'climate'] as $k): if (!empty($myAttrs[$k])): ?><dt><?= e($ATTR_LABEL[$k]) ?></dt><dd><?= e(implode(', ', array_map('ucfirst', $myAttrs[$k]))) ?></dd><?php endif; endforeach; ?>
        <?php if ($weights): ?><dt>Berat</dt><dd><?= e(min($weights) === max($weights) ? min($weights) : min($weights) . '–' . max($weights)) ?> gram</dd><?php endif; ?>
        <dt>Stok total</dt><dd><?= (int)$p['stock'] ?> unit</dd>
        <?php if (is_employee()): ?><dt>SKU</dt><dd><?= e(implode(', ', array_column($p['vars'], 'sku'))) ?></dd><?php endif; ?>
    </dl>
    <?php if ($kmsGuide): ?><h3 style="font-size:13px;margin:16px 0 8px">Panduan terkait (pustaka pengetahuan)</h3><?php foreach ($kmsGuide as $g): ?><a class="link-card" href="knowledge_view.php?id=<?= (int)$g['id'] ?>"><span class="ic"><?= icon('book') ?></span><div><b><?= e($g['title']) ?></b><small><?= e(mb_strimwidth((string)$g['summary'], 0, 120, '…')) ?></small></div></a><?php endforeach; endif; ?>
</section>

<section class="card pd-block" id="ulasan"><h2>Ulasan pembeli</h2>
    <div class="rev-summary">
        <div class="rev-score"><b><?= $p['avg'] !== null ? number_format($p['avg'], 1, ',', '') : '–' ?></b><span class="stars big"><?= $p['avg'] !== null ? str_repeat('★', (int)round($p['avg'])) . str_repeat('☆', 5 - (int)round($p['avg'])) : '☆☆☆☆☆' ?></span><small class="muted"><?= (int)$p['n'] ?> ulasan</small></div>
        <div class="rev-bars"><?php for ($s = 5; $s >= 1; $s--): $pct = $p['n'] ? $dist[$s] / $p['n'] * 100 : 0; ?><a href="<?= e($qs(['r' => $s, 'page' => null])) ?>" class="<?= $fRating === $s ? 'on' : '' ?>"><span><?= $s ?> ★</span><i><em style="width:<?= round($pct) ?>%"></em></i><span><?= (int)$dist[$s] ?></span></a><?php endfor; ?></div>
    </div>
    <div class="chips" style="margin-top:14px">
        <a class="chip <?= !$fRating && !$fText ? 'on' : '' ?>" href="<?= e('product.php?id=' . $pid . '#ulasan') ?>">Semua</a>
        <a class="chip <?= $fText ? 'on' : '' ?>" href="<?= e($qs(['c' => $fText ? null : 1, 'page' => null])) ?>">Dengan komentar (<?= $withText ?>)</a>
        <?php foreach ([5, 4, 3, 2, 1] as $s): ?><a class="chip <?= $fRating === $s ? 'on' : '' ?>" href="<?= e($qs(['r' => $fRating === $s ? null : $s, 'page' => null])) ?>"><?= $s ?> ★ (<?= (int)$dist[$s] ?>)</a><?php endforeach; ?>
        <span class="spacer"></span>
        <select onchange="location.href=this.value" aria-label="Urutkan ulasan" style="width:auto"><?php foreach (['new' => 'Terbaru', 'high' => 'Rating tertinggi', 'low' => 'Rating terendah'] as $k => $l): ?><option value="<?= e($qs(['s' => $k === 'new' ? null : $k, 'page' => null])) ?>" <?= $fSort === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </div>

    <?php if ($eligible): ?>
    <form method="post" class="review-form"><?= csrf_field() ?><input type="hidden" name="action" value="review">
        <b>Bagikan pengalaman Anda</b>
        <div class="grid-2"><div><label>Pesanan</label><select name="order_item_id"><?php foreach ($eligible as $el): ?><option value="<?= (int)$el['id'] ?>"><?= e($el['order_number']) ?> · <?= e($el['color']) ?>/<?= e($el['size']) ?></option><?php endforeach; ?></select></div>
        <div><label>Penilaian</label><div class="star-input"><?php for ($s = 5; $s >= 1; $s--): ?><input type="radio" id="st<?= $s ?>" name="rating" value="<?= $s ?>" <?= $s === 5 ? 'checked' : '' ?>><label for="st<?= $s ?>" title="<?= $s ?> bintang">★</label><?php endfor; ?></div></div></div>
        <label>Ulasan (opsional)</label><textarea name="review" rows="3" maxlength="1500" placeholder="Bagaimana bahan, ukuran, dan kualitasnya?"></textarea>
        <div class="action-row"><button class="btn primary sm">Kirim ulasan</button></div>
    </form>
    <?php endif; ?>

    <?php if (!$reviews): ?><div class="empty-state"><?= icon('star') ?><p>Belum ada ulasan untuk filter ini.</p></div><?php endif; ?>
    <?php foreach ($reviews as $r): ?>
    <article class="review">
        <span class="avatar sm"><?= e(mb_strtoupper(mb_substr($r['uname'], 0, 1))) ?></span>
        <div><div class="row" style="gap:8px"><b><?= e(shop_mask_name($r['uname'])) ?></b><?php if ($r['order_item_id']): ?><span class="tag ok"><?= icon('check', 'sm') ?> Pembelian terverifikasi</span><?php endif; ?></div>
            <div class="stars"><?= str_repeat('★', (int)$r['rating']) ?><span class="off"><?= str_repeat('★', 5 - (int)$r['rating']) ?></span> <small class="muted"><?= e(date('d M Y', strtotime($r['created_at']))) ?><?= $r['color'] ? ' · Varian: ' . e($r['color'] . '/' . $r['size']) : '' ?></small></div>
            <?php if ($r['review']): ?><p><?= nl2br(e($r['review'])) ?></p><?php endif; ?></div>
    </article>
    <?php endforeach; ?>
    <?php if ($pages > 1): ?><div class="pager"><?php if ($page > 1): ?><a href="<?= e($qs(['page' => $page - 1])) ?>">‹</a><?php endif; ?><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="<?= e($qs(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?><?php if ($page < $pages): ?><a href="<?= e($qs(['page' => $page + 1])) ?>">›</a><?php endif; ?></div><?php endif; ?>
</section>

<?php if ($similar): ?>
<section class="pd-rail" id="serupa"><div class="section-title"><h2>Produk serupa</h2><a class="text-link" href="catalog.php?cat=<?= e(urlencode($p['cat'])) ?>">Lihat kategori →</a></div><div class="rail"><?php foreach ($similar as $q) { echo shop_mini_card($q); } ?></div></section>
<?php endif; ?>
<?php if ($pairs): ?>
<section class="pd-rail" id="lengkapi"><div class="section-title"><h2>Lengkapi gayamu</h2><span class="muted small">Dipilih mesin pakar berdasarkan kesamaan acara, gaya, dan harmoni warna</span></div><div class="rail"><?php foreach ($pairs as $q) { echo shop_mini_card($q); } ?></div></section>
<?php endif; ?>
<?php if ($sameStore): ?>
<section class="pd-rail"><div class="section-title"><h2>Lainnya dari <?= e($seller['name']) ?></h2><a class="text-link" href="store.php?seller=<?= (int)$p['seller_id'] ?>">Semua produk toko →</a></div><div class="rail"><?php foreach ($sameStore as $q) { echo shop_mini_card($q); } ?></div></section>
<?php endif; ?>
<?php if ($recent): ?>
<section class="pd-rail"><div class="section-title"><h2>Baru saja Anda lihat</h2></div><div class="rail"><?php foreach ($recent as $q) { echo shop_mini_card($q); } ?></div></section>
<?php endif; ?>

<script>
(function () {
  var root = document.getElementById('pd'), data = JSON.parse(root.getAttribute('data-js')), vars = data.vars, inCart = data.inCart || {};
  var fmt = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };
  var colors = [], seen = {};
  vars.forEach(function (v) { if (!seen[v.color]) { seen[v.color] = 1; colors.push({ name: v.color, hex: v.hex }); } });
  var first = vars.filter(function (v) { return v.stock > 0; })[0] || vars[0];
  var st = { color: first.color, size: first.size, qty: 1 };
  var $ = function (id) { return document.getElementById(id); };
  var svg = document.querySelector('#mainVis .garment-svg'), img = $('mainImg');
  function shade(hex, f) { hex = hex.replace('#', ''); var c = [0, 2, 4].map(function (i) { return parseInt(hex.substr(i, 2), 16); }); c = c.map(function (x) { return Math.max(0, Math.min(255, Math.round(f < 0 ? x * (1 + f) : x + (255 - x) * f))); }); return '#' + c.map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
  function colorHasStock(c) { return vars.some(function (v) { return v.color === c && v.stock > 0; }); }
  function cur() { return vars.filter(function (v) { return v.color === st.color && v.size === st.size; })[0]; }
  function render() {
    var sw = $('swatches'); sw.innerHTML = '';
    colors.forEach(function (c) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'swatch lgsw' + (c.name === st.color ? ' on' : ''); b.style.background = c.hex; b.title = c.name; b.disabled = !colorHasStock(c.name);
      b.setAttribute('aria-label', 'Warna ' + c.name);
      b.onclick = function () { pickColor(c.name); }; sw.appendChild(b);
    });
    var sz = $('sizes'); sz.innerHTML = '';
    vars.filter(function (v) { return v.color === st.color; }).forEach(function (v) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'size' + (v.size === st.size ? ' on' : ''); b.textContent = v.size; b.disabled = v.stock <= 0;
      b.onclick = function () { st.size = v.size; st.qty = 1; render(); }; sz.appendChild(b);
    });
    var v = cur(); $('colorName').textContent = st.color; $('sizeName').textContent = st.size;
    document.querySelectorAll('#thumbs .thumb').forEach(function (t) { t.classList.toggle('on', t.dataset.color === st.color); });
    var note = $('stockNote'), add = $('btnAdd'), buy = $('btnBuy');
    if (v) {
      var max = Math.max(0, v.stock - (inCart[v.id] || 0));
      st.qty = Math.max(1, Math.min(st.qty, Math.max(max, 1)));
      $('price').textContent = fmt(v.price); $('priceNote').textContent = ' / unit';
      $('fVar').value = v.id; $('fQty').value = st.qty; $('qVal').textContent = st.qty;
      $('qMinus').disabled = st.qty <= 1; $('qPlus').disabled = st.qty >= max;
      note.className = 'stock-note ' + (v.stock <= 0 ? 'out' : v.stock <= 5 ? 'low' : 'ok');
      note.textContent = v.stock <= 0 ? 'Stok habis' : (v.stock <= 5 ? 'Tersisa ' + v.stock + ' unit' : 'Tersedia ' + v.stock + ' unit') + (inCart[v.id] ? ' · ' + inCart[v.id] + ' di keranjang Anda' : '');
      var off = v.stock <= 0 || max <= 0; if (add) add.disabled = off; if (buy) buy.disabled = off;
      if (v.image) { img.src = 'uploads/' + encodeURIComponent(v.image); img.hidden = false; if (svg) svg.style.display = 'none'; }
      else { img.hidden = true; if (svg) { svg.style.display = ''; svg.style.setProperty('--gf', v.hex); svg.style.setProperty('--gh', shade(v.hex, 0.22)); svg.style.setProperty('--ge', shade(v.hex, -0.28)); } }
    }
  }
  function pickColor(name) {
    st.color = name; var ok = vars.filter(function (v) { return v.color === name && v.stock > 0; });
    if (!ok.some(function (v) { return v.size === st.size; })) st.size = ok.length ? ok[0].size : vars.filter(function (v) { return v.color === name; })[0].size;
    st.qty = 1; render();
  }
  document.querySelectorAll('#thumbs .thumb').forEach(function (t) { t.onclick = function () { pickColor(t.dataset.color); }; });
  $('qMinus').onclick = function () { st.qty = Math.max(1, st.qty - 1); render(); };
  $('qPlus').onclick = function () { st.qty += 1; render(); };
  var cp = $('copyLink'); if (cp) cp.onclick = function () { var u = location.href.split('#')[0]; (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(function () { cp.textContent = 'Tautan disalin'; }, function () { prompt('Salin tautan:', u); }); };
  // tab aktif mengikuti posisi gulir
  var tabs = document.querySelectorAll('#pdTabs a'), targets = [].map.call(tabs, function (a) { return document.querySelector(a.getAttribute('href')); });
  window.addEventListener('scroll', function () { var y = window.scrollY + 140, idx = 0; targets.forEach(function (t, i) { if (t && t.offsetTop <= y) idx = i; }); tabs.forEach(function (a, i) { a.classList.toggle('on', i === idx); }); }, { passive: true });
  render();
})();
</script>
<?php include 'layout_bottom.php'; ?>
