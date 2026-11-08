<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
$u = login_required($pdo);
$oid = (int)($_GET['order_id'] ?? 0);
$s = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?'); $s->execute([$oid, $u['id']]); $o = $s->fetch();
if (!$o) { flash('error', 'Pesanan tidak ditemukan.'); go('my_orders.php'); }
$err = '';
$methods = ['bank_transfer' => ['Transfer bank', 'Konfirmasi otomatis'], 'e_wallet' => ['E-Wallet', 'GoPay, OVO, Dana'], 'virtual_account' => ['Virtual Account', 'BCA, BNI, Mandiri'],
            'credit_card' => ['Kartu kredit', 'Visa / Mastercard'], 'debit_card' => ['Kartu debit', 'Jaringan GPN'], 'cash_on_delivery' => ['Bayar di tempat (COD)', 'Tunai ke kurir']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $m = $_POST['payment_method'] ?? '';
    if (!isset($methods[$m])) $err = 'Metode pembayaran tidak valid.';
    elseif ($o['status'] !== 'pending' || $o['payment_status'] !== 'pending') $err = 'Pesanan ini sudah diproses.';
    else {
        try {
            $pdo->beginTransaction();
            $tx = 'SIM-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $pdo->prepare("INSERT INTO payments(order_id,payment_method,payment_gateway,transaction_id,amount,status,paid_at) VALUES(?,?,'simulation',?,?,'success',NOW())")->execute([$oid, $m, $tx, $o['total_price']]);
            $pdo->prepare("UPDATE orders SET status='processing',payment_status='paid',shipping_status='processing' WHERE id=?")->execute([$oid]);
            $pdo->commit();
            notify($pdo, (int)$u['id'], 'payment', 'Pembayaran berhasil', "Pembayaran {$o['order_number']} berhasil. Pesanan sedang disiapkan.", 'order', $oid);
            notify_roles($pdo, ['staff'], 'order', 'Pesanan baru siap diproses', "{$o['order_number']} sudah dibayar (" . rupiah($o['total_price']) . ').', 'order', $oid);
            $vids = $pdo->prepare('SELECT variation_id FROM order_items WHERE order_id = ?'); $vids->execute([$oid]);
            es_fire_event($pdo, 'order.paid', ['variation' => array_map('intval', array_column($vids->fetchAll(), 'variation_id')), 'order' => [$oid]], (int)$u['id']);
            flash('success', 'Pembayaran berhasil. Pesanan Anda segera diproses.');
            go('my_orders.php');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $err = 'Pembayaran gagal diproses. Silakan coba lagi.'; }
    }
}
$items = $pdo->prepare('SELECT product_name, color, size, quantity, subtotal FROM order_items WHERE order_id = ?'); $items->execute([$oid]); $items = $items->fetchAll();
$pageTitle = 'Pembayaran';
include 'layout_top.php';
?>
<div class="narrow">
<div class="card">
    <span class="eyebrow">Pembayaran</span>
    <h1 style="margin:6px 0 4px"><?= rupiah($o['total_price']) ?></h1>
    <p class="muted">Pesanan <b><?= e($o['order_number']) ?></b> · batas pembayaran 24 jam sejak pesanan dibuat.</p>
    <?php if ($o['payment_status'] === 'paid'): ?><div class="alert success">Pesanan ini sudah dibayar. <a class="text-link" href="my_orders.php">Lihat pesanan</a></div><?php endif; ?>
    <?php if ($o['status'] === 'cancelled'): ?><div class="alert error">Pesanan ini sudah dibatalkan.</div><?php endif; ?>
    <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
    <ul style="padding-left:18px;color:#4b4f7a;font-size:13px"><?php foreach ($items as $i): ?><li><?= e($i['product_name']) ?> · <?= e($i['color']) ?>/<?= e($i['size']) ?> × <?= (int)$i['quantity'] ?></li><?php endforeach; ?></ul>
    <?php if ($o['status'] === 'pending' && $o['payment_status'] === 'pending'): ?>
    <form method="post"><?= csrf_field() ?>
        <div class="pay-methods">
            <?php $first = true; foreach ($methods as $k => [$t, $d]): ?>
                <label><input type="radio" name="payment_method" value="<?= e($k) ?>" <?= $first ? 'checked' : '' ?>><span><?= e($t) ?><br><small class="muted"><?= e($d) ?></small></span></label>
            <?php $first = false; endforeach; ?>
        </div>
        <button class="btn primary" style="width:100%">Simulasikan pembayaran berhasil</button>
        <p class="muted small center">Ini mode simulasi: tidak ada transaksi uang sungguhan.</p>
    </form>
    <?php endif; ?>
</div></div>
<?php include 'layout_bottom.php'; ?>
