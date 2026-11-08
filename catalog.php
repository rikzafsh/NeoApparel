<?php
require_once 'db.php';
require_once 'functions.php';
$u = login_required($pdo);

$rows = $pdo->query("SELECT p.id pid, p.name, p.description, p.created_at, c.name category, b.name brand,
        v.id vid, v.sku, v.color, v.size, v.price, v.stock, v.image
    FROM products p
    JOIN product_variations v ON v.product_id = p.id AND v.is_active = 1
    LEFT JOIN product_categories pc ON pc.product_id = p.id LEFT JOIN categories c ON c.id = pc.category_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE p.is_active = 1 ORDER BY p.created_at DESC, p.id, v.id")->fetchAll();

$sold = [];
foreach ($pdo->query("SELECT oi.product_name, SUM(oi.quantity) q FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE o.payment_status = 'paid' AND o.status <> 'cancelled' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY oi.product_name")->fetchAll() as $r) { $sold[$r['product_name']] = (int)$r['q']; }
$rate = [];
foreach ($pdo->query("SELECT product_id, AVG(rating) a, COUNT(*) n FROM product_reviews WHERE status='published' GROUP BY product_id")->fetchAll() as $r) { $rate[(int)$r['product_id']] = $r; }
$attrs = [];
try { foreach ($pdo->query('SELECT product_id, attribute, value FROM es_product_attributes')->fetchAll() as $r) { $attrs[(int)$r['product_id']][] = $r['value']; } } catch (Throwable $e) {}

$products = [];
foreach ($rows as $r) {
    $pid = (int)$r['pid'];
    if (!isset($products[$pid])) {
        $products[$pid] = ['id' => $pid, 'name' => $r['name'], 'desc' => $r['description'], 'cat' => $r['category'] ?: 'Lainnya', 'brand' => $r['brand'], 'created' => $r['created_at'], 'vars' => [], 'sold' => $sold[$r['name']] ?? 0];
    }
    $products[$pid]['vars'][] = ['id' => (int)$r['vid'], 'color' => $r['color'] ?: '-', 'size' => $r['size'] ?: '-', 'price' => (float)$r['price'], 'stock' => (int)$r['stock'], 'hex' => color_hex($r['color']), 'image' => $r['image']];
}
$topSold = $sold ? max($sold) : 0;
$cats = array_values(array_unique(array_map(static fn($p) => $p['cat'], $products)));
sort($cats);
$pageTitle = 'Katalog produk';
include 'layout_top.php';
?>
<section class="shop-hero">
    <div><h1>Katalog Neo Apparel</h1><p>Pilih warna dan ukuran langsung dari kartu produk. Stok ditampilkan real-time.</p></div>
    <a class="stylist-cta" href="es_stylist.php"><span class="ic"><?= icon('sparkles') ?></span><span><b>Bingung memilih?</b><span>Biarkan Neo Stylist merekomendasikan outfit →</span></span></a>
</section>

<div class="filter-bar">
    <div class="search"><?= icon('search') ?><input type="search" id="q" placeholder="Cari produk, mis. hoodie, kemeja…" aria-label="Cari produk"></div>
    <select id="sort" aria-label="Urutkan"><option value="new">Terbaru</option><option value="hot">Terlaris</option><option value="asc">Harga terendah</option><option value="desc">Harga tertinggi</option></select>
    <label class="row" style="margin:0;gap:6px;font-weight:650"><input type="checkbox" id="instock" checked> Hanya yang tersedia</label>
</div>
<div class="chips" id="chips"><button type="button" class="chip on" data-cat="">Semua</button><?php foreach ($cats as $c): ?><button type="button" class="chip" data-cat="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?></div>

<div class="product-grid" id="grid">
<?php foreach ($products as $p):
    $prices = array_column($p['vars'], 'price'); $totalStock = array_sum(array_column($p['vars'], 'stock'));
    $avg = $rate[$p['id']]['a'] ?? null; $n = $rate[$p['id']]['n'] ?? 0;
    $isNew = strtotime($p['created']) > strtotime('-30 days');
    $hot = $topSold > 0 && $p['sold'] >= max(8, $topSold * 0.6);
    $data = ['vars' => $p['vars'], 'cat' => $p['cat']];
?>
    <article class="p-card" data-name="<?= e(mb_strtolower($p['name'] . ' ' . $p['cat'] . ' ' . ($p['brand'] ?? ''))) ?>" data-cat="<?= e($p['cat']) ?>" data-price="<?= min($prices) ?>" data-sold="<?= (int)$p['sold'] ?>" data-new="<?= strtotime($p['created']) ?>" data-stock="<?= (int)$totalStock ?>" data-product='<?= e(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>'>
        <div class="visual">
            <div class="p-badges"><?php if ($hot): ?><span class="pill hot">Terlaris</span><?php endif; ?><?php if ($isNew): ?><span class="pill new">Baru</span><?php endif; ?><?php if ($totalStock <= 0): ?><span class="pill warn">Habis</span><?php endif; ?></div>
            <a class="vis-link" href="product.php?id=<?= (int)$p['id'] ?>" aria-label="Lihat <?= e($p['name']) ?>"><?= garment_svg($p['cat'], $p['vars'][0]['color'], $p['name']) ?></a>
        </div>
        <div class="p-body">
            <span class="p-cat"><?= e($p['cat']) ?><?= $p['brand'] ? ' · ' . e($p['brand']) : '' ?></span>
            <h3 class="p-name"><a href="product.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></h3>
            <div class="p-meta"><?php if ($avg !== null): ?><span class="stars">★</span><b><?= number_format((float)$avg, 1, ',', '') ?></b><span>(<?= (int)$n ?>)</span><?php else: ?><span>Belum ada ulasan</span><?php endif; ?><?php if ($p['sold'] > 0): ?><span>· <?= (int)$p['sold'] ?> terjual</span><?php endif; ?></div>
            <div class="swatches" role="group" aria-label="Pilih warna"></div>
            <div class="sizes" role="group" aria-label="Pilih ukuran"></div>
            <div class="p-foot">
                <div><div class="price">–</div><div class="stock-note"></div></div>
                <form method="post" action="cart.php"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="return" value="catalog.php"><input type="hidden" name="variation_id" value="">
                    <button class="btn primary add" type="submit"><?= icon('cart', 'sm') ?> Tambah</button></form>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>
<div class="card empty-state" id="empty" style="display:none"><?= icon('search') ?><p>Tidak ada produk yang cocok dengan filter Anda.</p></div>

<script>
(function () {
  var fmt = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };
  var cards = [].slice.call(document.querySelectorAll('.p-card'));
  cards.forEach(function (card) {
    var data = JSON.parse(card.getAttribute('data-product'));
    var vars = data.vars, colors = [], seen = {};
    vars.forEach(function (v) { if (!seen[v.color]) { seen[v.color] = 1; colors.push({ name: v.color, hex: v.hex }); } });
    var sw = card.querySelector('.swatches'), sz = card.querySelector('.sizes');
    var state = { color: null, size: null };
    function colorHasStock(c) { return vars.some(function (v) { return v.color === c && v.stock > 0; }); }
    function render() {
      sw.innerHTML = '';
      colors.forEach(function (c) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'swatch' + (c.name === state.color ? ' on' : '');
        b.style.background = c.hex; b.title = c.name; b.setAttribute('aria-label', 'Warna ' + c.name); b.disabled = !colorHasStock(c.name);
        b.onclick = function () { state.color = c.name; var ok = vars.filter(function (v) { return v.color === c.name && v.stock > 0; }); if (!ok.some(function (v) { return v.size === state.size; })) state.size = ok.length ? ok[0].size : null; render(); };
        sw.appendChild(b);
      });
      sz.innerHTML = '';
      vars.filter(function (v) { return v.color === state.color; }).forEach(function (v) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'size' + (v.size === state.size ? ' on' : ''); b.textContent = v.size; b.disabled = v.stock <= 0;
        b.onclick = function () { state.size = v.size; render(); }; sz.appendChild(b);
      });
      var cur = vars.filter(function (v) { return v.color === state.color && v.size === state.size; })[0];
      var price = card.querySelector('.price'), note = card.querySelector('.stock-note'), btn = card.querySelector('.add'), hid = card.querySelector('input[name=variation_id]');
      if (cur) {
        price.textContent = fmt(cur.price); hid.value = cur.id; btn.disabled = cur.stock <= 0;
        note.className = 'stock-note ' + (cur.stock <= 0 ? 'out' : cur.stock <= 5 ? 'low' : 'ok');
        note.textContent = cur.stock <= 0 ? 'Stok habis' : cur.stock <= 5 ? 'Sisa ' + cur.stock + ' unit' : 'Stok tersedia';
      } else { price.textContent = fmt(Math.min.apply(null, vars.map(function (v) { return v.price; }))); hid.value = ''; btn.disabled = true; note.className = 'stock-note out'; note.textContent = 'Stok habis'; }
      var svg = card.querySelector('.garment-svg');
      if (svg && state.color) { var hx = (colors.filter(function (c) { return c.name === state.color; })[0] || {}).hex; if (hx) { svg.style.setProperty('--gf', hx); svg.style.setProperty('--gh', shade(hx, 0.22)); svg.style.setProperty('--ge', shade(hx, -0.28)); } }
    }
    function shade(hex, f) { hex = hex.replace('#', ''); var c = [0, 2, 4].map(function (i) { return parseInt(hex.substr(i, 2), 16); }); c = c.map(function (x) { return Math.max(0, Math.min(255, Math.round(f < 0 ? x * (1 + f) : x + (255 - x) * f))); }); return '#' + c.map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
    var firstOk = vars.filter(function (v) { return v.stock > 0; })[0] || vars[0];
    state.color = firstOk.color; state.size = firstOk.size;
    render();
  });

  // filter / urutkan
  var params = new URLSearchParams(location.search);
  var q = document.getElementById('q'), sort = document.getElementById('sort'), inst = document.getElementById('instock'), grid = document.getElementById('grid'), empty = document.getElementById('empty'), cat = params.get('cat') || '';
  q.value = params.get('q') || '';
  [].forEach.call(document.querySelectorAll('.chip'), function (c) { c.classList.toggle('on', c.getAttribute('data-cat') === cat); });
  document.getElementById('chips').addEventListener('click', function (e) { var b = e.target.closest('.chip'); if (!b) return; cat = b.getAttribute('data-cat'); [].forEach.call(document.querySelectorAll('.chip'), function (c) { c.classList.toggle('on', c === b); }); apply(); });
  [q, sort, inst].forEach(function (el) { el.addEventListener('input', apply); el.addEventListener('change', apply); });
  function apply() {
    var term = q.value.trim().toLowerCase(), shown = 0;
    var list = cards.slice().sort(function (a, b) {
      var s = sort.value;
      if (s === 'asc') return a.dataset.price - b.dataset.price; if (s === 'desc') return b.dataset.price - a.dataset.price;
      if (s === 'hot') return b.dataset.sold - a.dataset.sold; return b.dataset.new - a.dataset.new;
    });
    list.forEach(function (c) {
      var ok = (!term || c.dataset.name.indexOf(term) > -1) && (!cat || c.dataset.cat === cat) && (!inst.checked || +c.dataset.stock > 0);
      c.style.display = ok ? '' : 'none'; if (ok) shown++; grid.appendChild(c);
    });
    empty.style.display = shown ? 'none' : 'block';
  }
  apply();
})();
</script>
<?php include 'layout_bottom.php'; ?>
