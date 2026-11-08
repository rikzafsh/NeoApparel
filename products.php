<?php
require_once 'db.php';
require_once 'functions.php';
$u = need_role($pdo, ['seller', 'manager', 'admin']);
$uid = (int)$u['id'];
$pid = (int)($_GET['product_id'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$editVar = (int)($_GET['edit_var'] ?? 0);

function own_product(PDO $pdo, int $pid, int $uid): bool
{
    if (has_any(['admin', 'manager'])) return true;
    $s = $pdo->prepare('SELECT id FROM products WHERE id=? AND seller_id=?'); $s->execute([$pid, $uid]);
    return (bool)$s->fetch();
}
const STYLE_ATTRS = ['occasion' => 'Acara (kerja, kuliah, santai, hangout, acara formal, olahraga)', 'style' => 'Gaya (minimalis, streetwear, klasik, sporty)',
    'climate' => 'Cuaca (panas, ber-ac, sejuk, hujan)', 'fit' => 'Potongan (slim, regular, oversize)', 'material' => 'Bahan (katun, linen, denim, fleece, ...)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'product') {
            $id = (int)$_POST['id']; $name = trim($_POST['name'] ?? ''); $desc = trim($_POST['description'] ?? ''); $base = (float)($_POST['base_price'] ?? 0);
            $brand = (int)($_POST['brand_id'] ?? 0); $cat = (int)($_POST['category_id'] ?? 0);
            if ($name === '' || $base < 0) throw new RuntimeException('Nama dan harga dasar produk wajib valid.');
            if ($id && !own_product($pdo, $id, $uid)) throw new RuntimeException('Akses ditolak.');
            $pdo->beginTransaction();
            if ($id) {
                $slug = unique_slug($pdo, slugify($name), $id);
                $old = $pdo->prepare('SELECT * FROM products WHERE id=?'); $old->execute([$id]); $od = $old->fetch();
                $pdo->prepare('UPDATE products SET brand_id=?,name=?,slug=?,description=?,base_price=? WHERE id=?')->execute([$brand ?: null, $name, $slug, $desc ?: null, $base, $id]);
                $pdo->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$id]);
                if ($cat) $pdo->prepare('INSERT INTO product_categories(product_id,category_id) VALUES(?,?)')->execute([$id, $cat]);
                audit($pdo, $uid, 'UPDATE', 'products', $id, $od, ['name' => $name, 'base_price' => $base]);
                flash('success', 'Produk diperbarui.');
            } else {
                $slug = unique_slug($pdo, slugify($name));
                $pdo->prepare('INSERT INTO products(seller_id,brand_id,name,slug,description,base_price) VALUES(?,?,?,?,?,?)')->execute([$uid, $brand ?: null, $name, $slug, $desc ?: null, $base]);
                $id = (int)$pdo->lastInsertId();
                if ($cat) $pdo->prepare('INSERT INTO product_categories(product_id,category_id) VALUES(?,?)')->execute([$id, $cat]);
                audit($pdo, $uid, 'CREATE', 'products', $id, null, ['name' => $name]);
                flash('success', 'Produk dibuat. Tambahkan variasi (warna/ukuran) di bawah.');
            }
            $pdo->commit();
            go('products.php?product_id=' . $id);
        }
        if ($a === 'toggle_product') {
            $id = (int)$_POST['id'];
            if (!own_product($pdo, $id, $uid)) throw new RuntimeException('Akses ditolak.');
            $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
            flash('success', 'Status produk diperbarui.');
            go('products.php');
        }
        if ($a === 'variation') {
            $id = (int)$_POST['id']; $product = (int)$_POST['product_id']; $sku = trim($_POST['sku'] ?? ''); $color = trim($_POST['color'] ?? ''); $size = trim($_POST['size'] ?? '');
            $price = (float)($_POST['price'] ?? 0); $stock = (int)($_POST['stock'] ?? 0); $weight = (int)($_POST['weight_gram'] ?? 0);
            if (!own_product($pdo, $product, $uid)) throw new RuntimeException('Akses ditolak.');
            if ($sku === '' || $price < 0 || $stock < 0) throw new RuntimeException('SKU, harga, dan stok wajib valid.');
            $image = save_uploaded_image($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            $pdo->beginTransaction();
            if ($id) {
                $s = $pdo->prepare('SELECT * FROM product_variations WHERE id=? AND product_id=?'); $s->execute([$id, $product]); $od = $s->fetch();
                if (!$od) throw new RuntimeException('Variasi tidak ditemukan.');
                $q = 'UPDATE product_variations SET sku=?,color=?,size=?,price=?,stock=?,weight_gram=?'; $p = [$sku, $color ?: null, $size ?: null, $price, $stock, $weight ?: null];
                if ($image) { $q .= ',image=?'; $p[] = $image; }
                $q .= ' WHERE id=? AND product_id=?'; $p[] = $id; $p[] = $product;
                $pdo->prepare($q)->execute($p);
                if ((int)$od['stock'] !== $stock) {
                    $pdo->prepare("INSERT INTO inventory_movements(variation_id,movement_type,quantity,stock_before,stock_after,reference_type,notes,created_by) VALUES(?,'adjustment',?,?,?,'manual','Perubahan stok dari manajemen produk',?)")->execute([$id, $stock - (int)$od['stock'], $od['stock'], $stock, $uid]);
                }
                flash('success', 'Variasi diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO product_variations(product_id,sku,color,size,price,stock,image,weight_gram) VALUES(?,?,?,?,?,?,?,?)')->execute([$product, $sku, $color ?: null, $size ?: null, $price, $stock, $image, $weight ?: null]);
                $vid = (int)$pdo->lastInsertId();
                if ($stock > 0) $pdo->prepare("INSERT INTO inventory_movements(variation_id,movement_type,quantity,stock_before,stock_after,reference_type,notes,created_by) VALUES(?,'restock',?,0,?,'manual','Stok awal variasi baru',?)")->execute([$vid, $stock, $stock, $uid]);
                audit($pdo, $uid, 'CREATE', 'product_variations', $vid, null, ['sku' => $sku]);
                flash('success', 'Variasi ditambahkan.');
            }
            $pdo->commit();
            go('products.php?product_id=' . $product);
        }
        if ($a === 'toggle_variation') {
            $id = (int)$_POST['id']; $product = (int)$_POST['product_id'];
            if (!own_product($pdo, $product, $uid)) throw new RuntimeException('Akses ditolak.');
            $pdo->prepare('UPDATE product_variations SET is_active = 1 - is_active WHERE id=? AND product_id=?')->execute([$id, $product]);
            flash('success', 'Status variasi diperbarui.');
            go('products.php?product_id=' . $product);
        }
        if ($a === 'attrs') {
            $product = (int)$_POST['product_id'];
            if (!own_product($pdo, $product, $uid)) throw new RuntimeException('Akses ditolak.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM es_product_attributes WHERE product_id=?')->execute([$product]);
            $ins = $pdo->prepare('INSERT IGNORE INTO es_product_attributes(product_id,attribute,value) VALUES(?,?,?)');
            foreach (array_keys(STYLE_ATTRS) as $attr) {
                foreach (preg_split('/\s*,\s*/', mb_strtolower(trim($_POST['attr_' . $attr] ?? ''))) as $val) { if ($val !== '') $ins->execute([$product, $attr, mb_substr($val, 0, 60)]); }
            }
            $pdo->commit();
            flash('success', 'Atribut gaya disimpan. Neo Stylist akan memakainya untuk rekomendasi.');
            go('products.php?product_id=' . $product);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        go('products.php' . ($pid ? '?product_id=' . $pid : ''));
    }
}

$brands = $pdo->query('SELECT id,name FROM brands ORDER BY name')->fetchAll();
$cats = $pdo->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
$editProduct = null;
if ($editId && own_product($pdo, $editId, $uid)) { $s = $pdo->prepare('SELECT * FROM products WHERE id=?'); $s->execute([$editId]); $editProduct = $s->fetch(); }
$selected = null; $vars = []; $editV = null; $attrVals = [];
if ($pid && own_product($pdo, $pid, $uid)) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id=?'); $s->execute([$pid]); $selected = $s->fetch();
    $s = $pdo->prepare('SELECT * FROM product_variations WHERE product_id=? ORDER BY color,size,id'); $s->execute([$pid]); $vars = $s->fetchAll();
    foreach ($vars as $v) { if ($editVar && (int)$v['id'] === $editVar) $editV = $v; }
    $s = $pdo->prepare('SELECT attribute,value FROM es_product_attributes WHERE product_id=?'); $s->execute([$pid]);
    foreach ($s->fetchAll() as $r) { $attrVals[$r['attribute']][] = $r['value']; }
}
$where = has_any(['admin', 'manager']) ? '1=1' : 'p.seller_id=?';
$s = $pdo->prepare("SELECT p.*, u.name seller_name, GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') categories, COUNT(DISTINCT v.id) variation_count, COALESCE(SUM(v.stock),0) total_stock
    FROM products p JOIN users u ON u.id=p.seller_id LEFT JOIN product_categories pc ON pc.product_id=p.id LEFT JOIN categories c ON c.id=pc.category_id
    LEFT JOIN product_variations v ON v.product_id=p.id WHERE $where GROUP BY p.id ORDER BY p.id DESC");
$s->execute(has_any(['admin', 'manager']) ? [] : [$uid]);
$products = $s->fetchAll();
$selCat = 0;
if ($editProduct) { $cs = $pdo->prepare('SELECT category_id FROM product_categories WHERE product_id=? LIMIT 1'); $cs->execute([$editProduct['id']]); $selCat = (int)$cs->fetchColumn(); }
$pageTitle = 'Kelola produk';
include 'layout_top.php';
?>
<div class="head"><div><h1>Kelola produk</h1><p>Produk, variasi (warna/ukuran), stok awal, dan atribut gaya untuk rekomendasi Neo Stylist.</p></div>
<?php if ($selected || $editProduct): ?><a class="btn ghost" href="products.php">← Semua produk</a><?php endif; ?></div>

<div class="card">
    <h2 style="margin-top:0"><?= $editProduct ? 'Edit produk' : ($selected ? 'Produk: ' . e($selected['name']) : 'Tambah produk baru') ?></h2>
    <?php if (!$selected || $editProduct): $ep = $editProduct; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product"><input type="hidden" name="id" value="<?= (int)($ep['id'] ?? 0) ?>">
        <div class="grid-2"><div><label>Nama produk</label><input name="name" required value="<?= e($ep['name'] ?? '') ?>"></div><div><label>Harga dasar (Rp)</label><input type="number" min="0" step="1000" name="base_price" required value="<?= e($ep['base_price'] ?? '0') ?>"></div></div>
        <div class="grid-2"><div><label>Merek</label><select name="brand_id"><option value="0">-</option><?php foreach ($brands as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $ep && $ep['brand_id'] == $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Kategori</label><select name="category_id"><option value="0">-</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $selCat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div></div>
        <label>Deskripsi</label><textarea name="description" rows="3"><?= e($ep['description'] ?? '') ?></textarea>
        <div class="action-row"><button class="btn primary"><?= $ep ? 'Perbarui produk' : 'Simpan produk' ?></button></div>
    </form>
    <?php else: ?>
        <p class="muted"><?= e($selected['description'] ?: 'Tanpa deskripsi.') ?> <a class="text-link" href="products.php?edit=<?= (int)$selected['id'] ?>">Edit produk</a></p>
    <?php endif; ?>
</div>

<?php if ($selected): ?>
<div class="card">
    <h2 style="margin-top:0"><?= $editV ? 'Edit variasi' : 'Tambah variasi' ?></h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="variation"><input type="hidden" name="product_id" value="<?= (int)$pid ?>"><input type="hidden" name="id" value="<?= (int)($editV['id'] ?? 0) ?>">
        <div class="grid-3"><div><label>SKU</label><input name="sku" required value="<?= e($editV['sku'] ?? '') ?>"></div><div><label>Warna</label><input name="color" value="<?= e($editV['color'] ?? '') ?>" placeholder="Hitam, Navy, Krem…"></div><div><label>Ukuran</label><input name="size" value="<?= e($editV['size'] ?? '') ?>" placeholder="S, M, L / 30, 32"></div></div>
        <div class="grid-3"><div><label>Harga (Rp)</label><input type="number" min="0" step="500" name="price" required value="<?= e($editV['price'] ?? $selected['base_price']) ?>"></div><div><label>Stok</label><input type="number" min="0" name="stock" required value="<?= e($editV['stock'] ?? 0) ?>"></div><div><label>Berat (gram)</label><input type="number" min="0" name="weight_gram" value="<?= e($editV['weight_gram'] ?? '') ?>"></div></div>
        <label>Foto (opsional, JPG/PNG/WEBP maks 3 MB)</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
        <p class="muted small">Tanpa foto, katalog menampilkan ilustrasi otomatis sesuai kategori dan warna.</p>
        <div class="action-row"><button class="btn primary"><?= $editV ? 'Simpan perubahan' : 'Tambah variasi' ?></button><?php if ($editV): ?><a class="btn ghost" href="products.php?product_id=<?= (int)$pid ?>">Batal</a><?php endif; ?></div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0">Variasi (<?= count($vars) ?>)</h2>
    <div class="table-wrap"><table><thead><tr><th></th><th>SKU</th><th>Warna</th><th>Ukuran</th><th>Harga</th><th>Stok</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($vars as $v): ?><tr>
        <td style="width:46px"><span style="display:inline-block;width:22px;height:22px;border-radius:50%;background:<?= e(color_hex($v['color'])) ?>;border:1px solid #ddd"></span></td>
        <td><?= e($v['sku']) ?></td><td><?= e($v['color'] ?: '-') ?></td><td><?= e($v['size'] ?: '-') ?></td><td><?= rupiah($v['price']) ?></td>
        <td class="<?= $v['stock'] <= 5 ? 'low-stock' : '' ?>"><?= (int)$v['stock'] ?></td><td><?= $v['is_active'] ? badge('paid', 'Aktif') : badge('cancelled', 'Nonaktif') ?></td>
        <td class="actions"><a class="btn-sm" href="products.php?product_id=<?= (int)$pid ?>&edit_var=<?= (int)$v['id'] ?>">Edit</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_variation"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="product_id" value="<?= (int)$pid ?>"><button class="btn-sm" type="submit"><?= $v['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></td>
    </tr><?php endforeach; ?>
    <?php if (!$vars): ?><tr><td colspan="8" class="center muted">Belum ada variasi.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div class="card">
    <h2 style="margin-top:0">Atribut gaya <span class="tag"><?= icon('sparkles', 'sm') ?> Neo Stylist</span></h2>
    <p class="muted">Pisahkan nilai dengan koma. Atribut ini dipakai mesin pakar untuk mencocokkan produk dengan kebutuhan pembeli.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="attrs"><input type="hidden" name="product_id" value="<?= (int)$pid ?>">
        <div class="grid-2"><?php foreach (STYLE_ATTRS as $k => $hint): ?><div><label><?= e(ucfirst($k)) ?> <small class="muted">· <?= e($hint) ?></small></label><input name="attr_<?= e($k) ?>" value="<?= e(implode(', ', $attrVals[$k] ?? [])) ?>"></div><?php endforeach; ?></div>
        <div class="action-row"><button class="btn secondary">Simpan atribut</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0">Daftar produk</h2>
    <div class="table-wrap"><table><thead><tr><th>Produk</th><th>Seller</th><th>Kategori</th><th>Variasi</th><th>Total stok</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($products as $p): ?><tr>
        <td><b><?= e($p['name']) ?></b></td><td><?= e($p['seller_name']) ?></td><td><?= e($p['categories'] ?: '-') ?></td><td><?= (int)$p['variation_count'] ?></td><td><?= (int)$p['total_stock'] ?></td>
        <td><?= $p['is_active'] ? badge('paid', 'Aktif') : badge('cancelled', 'Nonaktif') ?></td>
        <td class="actions"><a class="btn-sm primary" href="products.php?product_id=<?= (int)$p['id'] ?>">Variasi</a><a class="btn-sm" href="products.php?edit=<?= (int)$p['id'] ?>">Edit</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-sm" type="submit"><?= $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include 'layout_bottom.php'; ?>
