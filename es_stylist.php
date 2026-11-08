<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_stylist_engine.php';
$u = login_required($pdo);

$result = null;
$answers = ['occasion' => 'kuliah', 'climate' => 'ber-ac', 'style_pref' => 'bebas', 'fit_pref' => 'bebas', 'color_pref' => 'bebas', 'budget' => 400000, 'size' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $result = es_stylist_recommend($pdo, $_POST);
    $answers = $result['answers'];
    try {
        $save = $result;
        foreach ($save['recommendations'] as &$r) { $r = ['product_id' => $r['product']['id'], 'variation_id' => (int)$r['variation']['vid'], 'match' => $r['match'], 'rules' => $r['rules']]; }
        unset($r);
        $save['outfits'] = []; 
        $pdo->prepare('INSERT INTO es_style_sessions(user_id, answers, results) VALUES(?,?,?)')->execute([(int)$u['id'], json_encode($answers), json_encode(['recommendations' => $save['recommendations'], 'fired' => array_column($result['fired'], 'code')])]);
    } catch (Throwable $e) { /* riwayat bersifat opsional */ }
}
$pageTitle = 'Neo Stylist';
$pageKicker = APP_NAME . ' · sistem pakar untuk pembeli';
include 'layout_top.php';
?>
<div class="head"><div><h1>Neo Stylist <span class="tag"><?= icon('sparkles', 'sm') ?> sistem pakar</span></h1><p>Jawab beberapa pertanyaan singkat. Mesin pakar akan mencocokkan kebutuhan Anda dengan katalog dan menjelaskan alasan setiap rekomendasi.</p></div></div>
<div class="stylist-wrap">
<form method="post" class="card"><?= csrf_field() ?>
    <?php foreach (STYLE_OPTIONS as $key => $meta): ?>
        <div class="q-block"><h3><?= e($meta['label']) ?></h3>
            <div class="opt-grid">
            <?php foreach ($meta['opts'] as $val => $label): ?>
                <label class="opt"><input type="radio" name="<?= e($key) ?>" value="<?= e($val) ?>" <?= ($answers[$key] ?? '') === $val ? 'checked' : '' ?>><span><?= icon($meta['icon']) ?><?= e($label) ?></span></label>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="q-block"><h3>Anggaran per item: <b id="bval"><?= rupiah($answers['budget']) ?></b></h3>
        <input type="range" name="budget" min="100000" max="1000000" step="25000" value="<?= (int)$answers['budget'] ?>" oninput="document.getElementById('bval').textContent='Rp '+Number(this.value).toLocaleString('id-ID')"></div>
    <div class="q-block"><h3>Ukuran (opsional)</h3>
        <select name="size"><option value="">Belum tahu / semua ukuran</option><optgroup label="Atasan"><?php foreach (['S', 'M', 'L', 'XL'] as $s): ?><option <?= $answers['size'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></optgroup><optgroup label="Celana"><?php foreach (['30', '32', '34'] as $s): ?><option <?= $answers['size'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></optgroup></select></div>
    <button class="btn primary" style="width:100%"><?= icon('sparkles', 'sm') ?> Rekomendasikan untuk saya</button>
</form>

<div>
<?php if (!$result): ?>
    <div class="card empty-state"><?= icon('sparkles') ?><h3>Hasil rekomendasi muncul di sini</h3><p>Setiap rekomendasi dilengkapi tingkat kecocokan dan alasan, bukan sekadar daftar acak.</p></div>
<?php elseif (!$result['recommendations']): ?>
    <div class="card empty-state"><?= icon('search') ?><h3>Belum ada yang cocok</h3><p>Coba longgarkan anggaran atau pilih "Bebas" pada beberapa pertanyaan.</p></div>
<?php else: ?>
    <?php if ($result['outfits']): ?>
        <h2 style="margin-top:0">Padu padan siap pakai</h2>
        <?php foreach ($result['outfits'] as $o): ?>
        <div class="outfit">
            <div class="piece"><?= garment_svg($o['top']['product']['category'], $o['top']['variation']['color']) ?></div><span class="plus">+</span><div class="piece"><?= garment_svg($o['bottom']['product']['category'], $o['bottom']['variation']['color']) ?></div>
            <div style="flex:1;min-width:0"><b><?= e($o['top']['product']['name']) ?> (<?= e($o['top']['variation']['color']) ?>)</b><br><b><?= e($o['bottom']['product']['name']) ?> (<?= e($o['bottom']['variation']['color']) ?>)</b>
                <div class="muted small" style="margin-top:4px"><?= e($o['why']) ?> · Total <b><?= rupiah($o['total']) ?></b></div></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <h2>Rekomendasi untuk Anda</h2>
    <?php foreach ($result['recommendations'] as $r): $v = $r['variation']; ?>
    <article class="rec-card">
        <div class="vis"><?= product_visual($v['image'], $r['product']['category'], $v['color'], $r['product']['name']) ?></div>
        <div>
            <div class="row" style="justify-content:space-between"><span class="p-cat"><?= e($r['product']['category']) ?></span><span class="match"><?= icon('sparkles', 'sm') ?> <?= (int)$r['match'] ?>% cocok</span></div>
            <h3 style="margin:4px 0"><?= e($r['product']['name']) ?></h3>
            <div class="muted small"><?= e($v['color']) ?> · Ukuran <?= e($v['size']) ?> · stok <?= (int)$v['stock'] ?> · <b style="color:var(--ink)"><?= rupiah($v['price']) ?></b></div>
            <ul class="why"><?php foreach ($r['reasons'] as $why): ?><li><?= e($why) ?></li><?php endforeach; ?><?php foreach ($r['notes'] as $n): ?><li style="color:#b45309"><?= e($n) ?></li><?php endforeach; ?></ul>
            <div class="row" style="margin-top:10px">
                <form method="post" action="cart.php"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="return" value="es_stylist.php"><input type="hidden" name="variation_id" value="<?= (int)$v['vid'] ?>"><button class="btn primary sm" <?= has_role('customer') ? '' : 'disabled title="Hanya akun pembeli"' ?>><?= icon('cart', 'sm') ?> Tambah ke keranjang</button></form>
                <details><summary class="text-link" style="cursor:pointer">Aturan yang dipakai</summary><div style="margin-top:6px"><?php foreach ($r['rules'] as $c): ?><span class="mono"><?= e($c) ?></span> <?php endforeach; ?></div></details>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
    <p class="muted small">Dihitung dari <?= count($result['fired']) ?> aturan pakar yang menyala. Kecocokan = bobot aturan × certainty factor, dibandingkan skor maksimum yang mungkin.</p>
<?php endif; ?>
</div>
</div>
<?php include 'layout_bottom.php'; ?>
