<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
$u = login_required($pdo);
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $oid = (int)($_POST['order_id'] ?? 0);
    $chk = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND user_id = ?'); $chk->execute([$oid, $uid]);
    if ($chk->fetch() && ($_POST['action'] ?? '') === 'cancel') {
        [$ok, $msg] = cancel_unpaid_order($pdo, $oid, $uid, 'Dibatalkan oleh pembeli');
        flash($ok ? 'success' : 'warning', $msg);
        if ($ok) {
            $v = $pdo->prepare('SELECT variation_id FROM order_items WHERE order_id = ?'); $v->execute([$oid]);
            es_fire_event($pdo, 'order.cancelled', ['variation' => array_map('intval', array_column($v->fetchAll(), 'variation_id'))], $uid);
        }
    }
    go('my_orders.php');
}

$s = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC'); $s->execute([$uid]); $orders = $s->fetchAll();
$items = []; $ships = []; $addrs = [];
if ($orders) {
    $ids = implode(',', array_map(static fn($o) => (int)$o['id'], $orders));
    foreach ($pdo->query("SELECT * FROM order_items WHERE order_id IN ($ids)")->fetchAll() as $r) { $items[$r['order_id']][] = $r; }
    foreach ($pdo->query("SELECT * FROM shipments WHERE order_id IN ($ids)")->fetchAll() as $r) { $ships[$r['order_id']] = $r; }
    foreach ($pdo->query("SELECT * FROM order_addresses WHERE order_id IN ($ids)")->fetchAll() as $r) { $addrs[$r['order_id']] = $r; }
}
function order_steps(array $o): array
{
    $cancelled = $o['status'] === 'cancelled';
    $paid = $o['payment_status'] === 'paid';
    $shipped = in_array($o['status'], ['shipped', 'completed'], true);
    $done = $o['status'] === 'completed';
    return [['Dibuat', true, false], [$cancelled ? 'Dibatalkan' : 'Dibayar', $paid || $cancelled, $cancelled], ['Dikirim', $shipped, false], ['Selesai', $done, false]];
}
$pageTitle = 'Pesanan saya';
include 'layout_top.php';
?>
<div class="head"><div><h1>Pesanan saya</h1><p>Lacak pembayaran dan pengiriman pesanan Anda.</p></div><a class="btn ghost" href="catalog.php">Belanja lagi</a></div>
<?php if (!$orders): ?>
    <div class="card empty-state"><?= icon('receipt') ?><h3>Belum ada pesanan</h3><p>Pesanan pertama Anda akan muncul di sini.</p><a class="btn primary" href="catalog.php">Mulai belanja</a></div>
<?php endif; ?>
<div class="order-list">
<?php foreach ($orders as $o): $its = $items[$o['id']] ?? []; $sh = $ships[$o['id']] ?? null; $ad = $addrs[$o['id']] ?? null; $unpaid = $o['status'] === 'pending' && $o['payment_status'] === 'pending'; ?>
    <article class="order-card" id="o<?= (int)$o['id'] ?>">
        <header>
            <div><b><?= e($o['order_number']) ?></b> <span class="muted small">· <?= e(fmt_dt($o['created_at'])) ?></span></div>
            <div class="row"><?= badge($o['status']) ?><?= badge($o['payment_status']) ?><b><?= rupiah($o['total_price']) ?></b></div>
        </header>
        <div class="order-items"><?php foreach ($its as $i): ?><span class="tag"><?= e($i['product_name']) ?> · <?= e($i['color']) ?>/<?= e($i['size']) ?> ×<?= (int)$i['quantity'] ?></span><?php endforeach; ?></div>
        <div class="timeline"><?php foreach (order_steps($o) as [$t, $on, $cx]): ?><div class="step <?= $on ? ($cx ? 'cancel' : 'done') : '' ?>"><span class="dot"><?= $cx ? icon('x', 'sm') : icon('check', 'sm') ?></span><?= e($t) ?></div><?php endforeach; ?></div>
        <details style="margin-top:10px"><summary class="text-link" style="cursor:pointer">Rincian pesanan</summary>
            <dl class="kv" style="margin-top:12px">
                <dt>Subtotal</dt><dd><?= rupiah($o['subtotal']) ?></dd><dt>Ongkos kirim</dt><dd><?= rupiah($o['shipping_cost']) ?></dd>
                <?php if ($o['discount_amount'] > 0): ?><dt>Diskon</dt><dd>− <?= rupiah($o['discount_amount']) ?></dd><?php endif; ?>
                <dt>Alamat</dt><dd><?= $ad ? e($ad['recipient_name'] . ', ' . $ad['address'] . ', ' . $ad['city']) : '-' ?></dd>
                <?php if ($sh): ?><dt>Kurir</dt><dd><?= e($sh['courier']) ?> <?= e($sh['service']) ?> · Resi <b><?= e($sh['tracking_number']) ?></b></dd><?php endif; ?>
            </dl>
        </details>
        <?php if ($unpaid): ?>
            <div class="action-row">
                <a class="btn primary" href="payment.php?order_id=<?= (int)$o['id'] ?>">Bayar sekarang</a>
                <form method="post" data-confirm="Batalkan pesanan ini? Stok akan dikembalikan."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn ghost" type="submit">Batalkan</button></form>
            </div>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
<?php include 'layout_bottom.php'; ?>
