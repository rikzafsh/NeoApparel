<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
$u = login_required($pdo);
$uid = (int)$u['id'];
$err = '';

/** Validasi kupon terhadap subtotal. Mengembalikan [kupon|null, diskon, pesan]. */
function coupon_check(PDO $pdo, string $code, float $subtotal, int $uid): array
{
    $code = strtoupper(trim($code));
    if ($code === '') return [null, 0.0, ''];
    $s = $pdo->prepare('SELECT * FROM coupons WHERE code = ? AND is_active = 1 AND (start_at IS NULL OR start_at <= NOW()) AND (end_at IS NULL OR end_at >= NOW())');
    $s->execute([$code]);
    $c = $s->fetch();
    if (!$c) return [null, 0.0, 'Kode kupon tidak valid atau sudah berakhir.'];
    if ($c['usage_limit'] !== null && (int)$c['usage_count'] >= (int)$c['usage_limit']) return [null, 0.0, 'Kuota kupon sudah habis.'];
    if ($subtotal < (float)$c['minimum_order']) return [null, 0.0, 'Minimal belanja untuk kupon ini ' . rupiah($c['minimum_order']) . '.'];
    $d = $c['discount_type'] === 'percentage' ? $subtotal * (float)$c['discount_value'] / 100 : (float)$c['discount_value'];
    if ($c['maximum_discount'] !== null && (float)$c['maximum_discount'] > 0) $d = min($d, (float)$c['maximum_discount']);
    return [$c, round(min($d, $subtotal)), ''];
}
function back_to(string $fallback): never
{
    $r = $_POST['return'] ?? '';
    go(in_array($r, ['catalog.php', 'es_stylist.php', 'cart.php'], true) ? $r : $fallback);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'add') {
        $vid = (int)($_POST['variation_id'] ?? 0);
        $qtyAdd = max(1, min(20, (int)($_POST['quantity'] ?? 1)));
        $s = $pdo->prepare('SELECT v.stock, p.name FROM product_variations v JOIN products p ON p.id = v.product_id WHERE v.id = ? AND v.is_active = 1 AND p.is_active = 1');
        $s->execute([$vid]); $v = $s->fetch();
        if (!$v || $v['stock'] < 1) { flash('error', 'Variasi tidak tersedia.'); back_to('catalog.php'); }
        $s = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE user_id = ? AND variation_id = ?'); $s->execute([$uid, $vid]); $c = $s->fetch();
        if ($c) {
            $n = $c['quantity'] + $qtyAdd;
            if ($n > $v['stock']) { flash('warning', 'Stok ' . $v['name'] . ' hanya ' . $v['stock'] . ' unit; jumlah di keranjang sudah ' . $c['quantity'] . '.'); back_to('catalog.php'); }
            $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?')->execute([$n, $c['id']]);
        } else {
            $pdo->prepare('INSERT INTO cart_items(user_id, variation_id, quantity) VALUES(?,?,?)')->execute([$uid, $vid, min($qtyAdd, (int)$v['stock'])]);
        }
        flash('success', $v['name'] . ' ditambahkan ke keranjang.');
        back_to('catalog.php');
    }
    if ($a === 'qty') {
        $cid = (int)$_POST['id']; $q = (int)$_POST['quantity'];
        $s = $pdo->prepare('SELECT c.id, v.stock FROM cart_items c JOIN product_variations v ON v.id = c.variation_id WHERE c.id = ? AND c.user_id = ?'); $s->execute([$cid, $uid]); $c = $s->fetch();
        if ($c) {
            if ($q <= 0) $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')->execute([$cid, $uid]);
            elseif ($q <= $c['stock']) $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?')->execute([$q, $cid, $uid]);
            else flash('warning', 'Jumlah melebihi stok yang tersedia.');
        }
        go('cart.php');
    }
    if ($a === 'remove') { $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')->execute([(int)$_POST['id'], $uid]); flash('info', 'Item dihapus dari keranjang.'); go('cart.php'); }
    if ($a === 'coupon') {
        $code = strtoupper(trim($_POST['coupon_code'] ?? ''));
        if ($code === '') { unset($_SESSION['coupon']); flash('info', 'Kupon dilepas.'); }
        else { $_SESSION['coupon'] = $code; }
        go('cart.php');
    }
    if ($a === 'checkout') {
        $s = $pdo->prepare("SELECT c.quantity, v.*, p.name product_name FROM cart_items c JOIN product_variations v ON v.id = c.variation_id JOIN products p ON p.id = v.product_id WHERE c.user_id = ?");
        $s->execute([$uid]); $items = $s->fetchAll();
        $s = $pdo->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1'); $s->execute([$uid]); $addr = $s->fetch();
        if (!$items) { $err = 'Keranjang kosong.'; }
        elseif (!$addr) { flash('warning', 'Tambahkan alamat pengiriman terlebih dahulu.'); go('addresses.php?missing=1'); }
        else {
            try {
                $pdo->beginTransaction();
                $sub = 0.0;
                foreach ($items as $it) {
                    $s = $pdo->prepare('SELECT stock FROM product_variations WHERE id = ? AND is_active = 1 FOR UPDATE'); $s->execute([$it['id']]); $v = $s->fetch();
                    if (!$v || $v['stock'] < $it['quantity']) throw new RuntimeException('Stok ' . $it['product_name'] . ' (' . $it['color'] . '/' . $it['size'] . ') berubah. Periksa kembali keranjang Anda.');
                    $sub += (float)$it['price'] * $it['quantity'];
                }
                [$coupon, $disc, $cmsg] = coupon_check($pdo, $_SESSION['coupon'] ?? '', $sub, $uid);
                $ship = (float)neo_config('shipping_flat', SHIPPING_FLAT_RATE);
                $total = $sub + $ship - $disc;
                $no = 'NA-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $pdo->prepare("INSERT INTO orders(order_number,user_id,subtotal,shipping_cost,discount_amount,total_price,status,payment_status,shipping_status,coupon_id) VALUES(?,?,?,?,?,?,'pending','pending','pending',?)")
                    ->execute([$no, $uid, $sub, $ship, $disc, $total, $coupon['id'] ?? null]);
                $oid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO order_addresses(order_id,recipient_name,phone,address,village,district,city,province,postal_code,country) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$oid, $addr['recipient_name'], $addr['phone'], $addr['address'], $addr['village'], $addr['district'], $addr['city'], $addr['province'], $addr['postal_code'], $addr['country']]);
                foreach ($items as $it) {
                    $pdo->prepare('INSERT INTO order_items(order_id,variation_id,product_name,variation_sku,color,size,quantity,unit_price,subtotal) VALUES(?,?,?,?,?,?,?,?,?)')
                        ->execute([$oid, $it['id'], $it['product_name'], $it['sku'], $it['color'], $it['size'], $it['quantity'], $it['price'], $it['price'] * $it['quantity']]);
                    $before = (int)$it['stock']; $after = $before - (int)$it['quantity'];
                    $pdo->prepare('UPDATE product_variations SET stock = ? WHERE id = ?')->execute([$after, $it['id']]);
                    $pdo->prepare("INSERT INTO inventory_movements(variation_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,notes,created_by) VALUES(?,?,?,?,?,'order',?,?,?)")
                        ->execute([$it['id'], 'sale', -$it['quantity'], $before, $after, $oid, 'Penjualan ' . $no, $uid]);
                }
                if ($coupon) {
                    $pdo->prepare('UPDATE coupons SET usage_count = usage_count + 1 WHERE id = ?')->execute([$coupon['id']]);
                    $pdo->prepare('INSERT INTO coupon_usages(coupon_id,user_id,order_id,discount_amount) VALUES(?,?,?,?)')->execute([$coupon['id'], $uid, $oid, $disc]);
                }
                $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
                audit($pdo, $uid, 'CREATE', 'orders', $oid, null, ['order_number' => $no, 'total_price' => $total]);
                $pdo->commit();
                unset($_SESSION['coupon']);
                notify($pdo, $uid, 'order', 'Pesanan dibuat', "Pesanan {$no} menunggu pembayaran.", 'order', $oid);
                es_fire_event($pdo, 'order.created', ['variation' => array_map(static fn($i) => (int)$i['id'], $items), 'order' => [$oid]], $uid);
                go('payment.php?order_id=' . $oid);
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $err = $e->getMessage(); }
        }
    }
}

$s = $pdo->prepare("SELECT c.id cart_id, c.quantity, v.*, p.name product_name, (SELECT c2.name FROM product_categories pc JOIN categories c2 ON c2.id = pc.category_id WHERE pc.product_id = p.id LIMIT 1) category
    FROM cart_items c JOIN product_variations v ON v.id = c.variation_id JOIN products p ON p.id = v.product_id WHERE c.user_id = ? ORDER BY c.id DESC");
$s->execute([$uid]); $rows = $s->fetchAll();
$sub = 0.0; foreach ($rows as $r) $sub += $r['price'] * $r['quantity'];
[$coupon, $disc, $cmsg] = coupon_check($pdo, $_SESSION['coupon'] ?? '', $sub, $uid);
if (!empty($_SESSION['coupon']) && !$coupon && $_SERVER['REQUEST_METHOD'] === 'GET') { $couponWarn = $cmsg; }
$ship = $rows ? (float)neo_config('shipping_flat', SHIPPING_FLAT_RATE) : 0;
$total = $sub + $ship - $disc;
$s = $pdo->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1'); $s->execute([$uid]); $addr = $s->fetch();
$pageTitle = 'Keranjang';
include 'layout_top.php';
?>
<div class="head"><div><h1>Keranjang belanja</h1><p>Periksa pesanan Anda sebelum checkout.</p></div><a class="btn ghost" href="catalog.php">← Lanjut belanja</a></div>
<?php if ($err): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
<?php if (!$rows): ?>
    <div class="card empty-state"><?= icon('cart') ?><h3>Keranjang masih kosong</h3><p>Temukan item favorit di katalog atau minta saran Neo Stylist.</p><a class="btn primary" href="catalog.php">Jelajahi katalog</a></div>
<?php else: ?>
<div class="cart-layout">
    <div class="card">
        <?php foreach ($rows as $r): ?>
            <div class="cart-item">
                <div class="thumb"><?= product_visual($r['image'], (string)$r['category'], $r['color'], $r['product_name']) ?></div>
                <div>
                    <h3><?= e($r['product_name']) ?></h3>
                    <small><?= e($r['color'] ?: '-') ?> · Ukuran <?= e($r['size'] ?: '-') ?> · <?= e($r['sku']) ?></small>
                    <div class="row" style="margin-top:9px">
                        <div class="qty">
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="qty"><input type="hidden" name="id" value="<?= (int)$r['cart_id'] ?>"><input type="hidden" name="quantity" value="<?= $r['quantity'] - 1 ?>"><button aria-label="Kurangi" <?= $r['quantity'] <= 1 ? 'disabled' : '' ?>>−</button></form>
                            <span><?= (int)$r['quantity'] ?></span>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="qty"><input type="hidden" name="id" value="<?= (int)$r['cart_id'] ?>"><input type="hidden" name="quantity" value="<?= $r['quantity'] + 1 ?>"><button aria-label="Tambah" <?= $r['quantity'] >= $r['stock'] ? 'disabled' : '' ?>>+</button></form>
                        </div>
                        <small class="<?= $r['stock'] <= 5 ? 'low-stock' : '' ?>">Stok <?= (int)$r['stock'] ?></small>
                    </div>
                </div>
                <div style="text-align:right">
                    <b><?= rupiah($r['price'] * $r['quantity']) ?></b><small><?= rupiah($r['price']) ?> / unit</small>
                    <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$r['cart_id'] ?>"><button class="btn-sm danger" type="submit">Hapus</button></form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <aside class="card summary">
        <h2 style="margin-top:0">Ringkasan</h2>
        <form method="post" class="coupon"><?= csrf_field() ?><input type="hidden" name="action" value="coupon"><input type="text" name="coupon_code" placeholder="Kode kupon" value="<?= e($_SESSION['coupon'] ?? '') ?>" aria-label="Kode kupon"><button class="btn ghost" type="submit">Pakai</button></form>
        <?php if (!empty($couponWarn)): ?><div class="alert warning"><?= e($couponWarn) ?></div><?php elseif ($coupon): ?><div class="alert success">Kupon <b><?= e($coupon['code']) ?></b> terpasang.</div><?php endif; ?>
        <div class="sum-row"><span>Subtotal (<?= array_sum(array_column($rows, 'quantity')) ?> item)</span><b><?= rupiah($sub) ?></b></div>
        <div class="sum-row"><span>Ongkos kirim</span><b><?= rupiah($ship) ?></b></div>
        <?php if ($disc > 0): ?><div class="sum-row disc"><span>Diskon kupon</span><b>− <?= rupiah($disc) ?></b></div><?php endif; ?>
        <div class="sum-row total"><span>Total</span><span><?= rupiah($total) ?></span></div>
        <div class="addr-box"><?php if ($addr): ?><b><?= e($addr['label']) ?> · <?= e($addr['recipient_name']) ?></b><?= e($addr['address']) ?>, <?= e($addr['city']) ?> <?= e($addr['postal_code']) ?><br><a class="text-link" href="addresses.php">Ubah alamat</a><?php else: ?><b>Belum ada alamat</b><a class="text-link" href="addresses.php?missing=1">Tambah alamat pengiriman</a><?php endif; ?></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><button class="btn primary" style="width:100%" <?= $addr ? '' : 'disabled' ?>>Checkout · <?= rupiah($total) ?></button></form>
        <p class="muted small center" style="margin-bottom:0">Stok ditahan selama pembayaran belum kedaluwarsa (24 jam).</p>
    </aside>
</div>
<?php endif; ?>
<?php include 'layout_bottom.php'; ?>
