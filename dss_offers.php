<?php
declare(strict_types=1);
require_once __DIR__ . '/dss_functions.php';
$admin = dss_require_admin();
$pageTitle = 'Penawaran Supplier';
$error = '';
$success = isset($_GET['saved']) ? 'Data penawaran berhasil disimpan.' : '';
$schemaMissing = dss_missing_tables();
$editing = null;
$suppliers = [];
$variations = [];
$offers = [];

if (!$schemaMissing) {
    $suppliers = $pdo->query("SELECT id, supplier_code, name, payment_terms_days FROM suppliers WHERE status = 'active' ORDER BY name")->fetchAll();
    $variations = dss_variations($pdo);
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare('SELECT * FROM supplier_products WHERE id = ?'); $stmt->execute([(int)$_GET['edit']]); $editing = $stmt->fetch() ?: null;
    }
}

if (!$schemaMissing && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        if (($_POST['action'] ?? '') !== 'save_offer') throw new RuntimeException('Aksi tidak dikenal.');
        $id = (int)($_POST['id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $variationId = (int)($_POST['variation_id'] ?? 0);
        $supplierSku = trim((string)($_POST['supplier_sku'] ?? ''));
        $price = filter_var($_POST['purchase_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $minimumQty = filter_var($_POST['minimum_order_qty'] ?? null, FILTER_VALIDATE_INT);
        $leadTime = filter_var($_POST['lead_time_days'] ?? null, FILTER_VALIDATE_INT);
        $shipping = filter_var($_POST['shipping_cost'] ?? 0, FILTER_VALIDATE_FLOAT);
        $quoteDate = trim((string)($_POST['quote_date'] ?? ''));
        $validUntil = trim((string)($_POST['quote_valid_until'] ?? ''));
        $active = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($supplierId < 1 || $variationId < 1) throw new RuntimeException('Pilih supplier dan SKU produk.');
        if ($price === false || $price < 0 || $minimumQty === false || $minimumQty < 1 || $leadTime === false || $leadTime < 0 || $shipping === false || $shipping < 0) throw new RuntimeException('Harga, minimum pembelian, waktu pengiriman, dan biaya kirim harus berupa angka valid.');
        if (mb_strlen($supplierSku) > 100 || mb_strlen($notes) > 500) throw new RuntimeException('SKU supplier atau catatan terlalu panjang.');
        foreach (['quote_date'=>$quoteDate,'quote_valid_until'=>$validUntil] as $field=>$value) {
            if ($value !== '') { $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $value); if (!$dateObj || $dateObj->format('Y-m-d') !== $value) throw new RuntimeException('Tanggal penawaran tidak valid.'); }
        }
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ? AND status = 'active'"); $stmt->execute([$supplierId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Supplier harus berstatus aktif untuk membuat penawaran baru.');
        $stmt = $pdo->prepare('SELECT id FROM product_variations WHERE id = ? AND is_active = 1'); $stmt->execute([$variationId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('SKU produk tidak ditemukan atau nonaktif.');

        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM supplier_products WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Penawaran yang akan diperbarui tidak ditemukan.');
            $stmt = $pdo->prepare('UPDATE supplier_products SET supplier_id=?, variation_id=?, supplier_sku=?, purchase_price=?, minimum_order_qty=?, lead_time_days=?, shipping_cost=?, quote_date=?, quote_valid_until=?, is_active=?, notes=? WHERE id=?');
            $stmt->execute([$supplierId,$variationId,$supplierSku?:null,$price,$minimumQty,$leadTime,$shipping,$quoteDate?:null,$validUntil?:null,$active,$notes?:null,$id]);
            dss_audit($pdo, (int)$admin['id'], 'update', 'supplier_products', $id, $old, ['supplier_id'=>$supplierId,'variation_id'=>$variationId,'purchase_price'=>$price,'is_active'=>$active]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO supplier_products (supplier_id, variation_id, supplier_sku, purchase_price, minimum_order_qty, lead_time_days, shipping_cost, quote_date, quote_valid_until, is_active, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE supplier_sku=VALUES(supplier_sku), purchase_price=VALUES(purchase_price), minimum_order_qty=VALUES(minimum_order_qty), lead_time_days=VALUES(lead_time_days), shipping_cost=VALUES(shipping_cost), quote_date=VALUES(quote_date), quote_valid_until=VALUES(quote_valid_until), is_active=VALUES(is_active), notes=VALUES(notes), updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$supplierId,$variationId,$supplierSku?:null,$price,$minimumQty,$leadTime,$shipping,$quoteDate?:null,$validUntil?:null,$active,$notes?:null]);
            $find = $pdo->prepare('SELECT id FROM supplier_products WHERE supplier_id=? AND variation_id=?'); $find->execute([$supplierId,$variationId]); $id = (int)$find->fetchColumn();
            dss_audit($pdo, (int)$admin['id'], 'save', 'supplier_products', $id, null, ['supplier_id'=>$supplierId,'variation_id'=>$variationId,'purchase_price'=>$price,'is_active'=>$active]);
        }
        go('dss_offers.php?saved=1');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException && str_contains($e->getMessage(), 'uq_supplier_variation') ? 'Supplier tersebut sudah memiliki penawaran untuk SKU ini.' : $e->getMessage();
    }
}
if (!$schemaMissing) {
    $offers = $pdo->query("SELECT sp.*, s.supplier_code, s.name AS supplier_name, v.sku, v.color, v.size, p.name AS product_name
        FROM supplier_products sp JOIN suppliers s ON s.id=sp.supplier_id
        JOIN product_variations v ON v.id=sp.variation_id JOIN products p ON p.id=v.product_id
        ORDER BY sp.is_active DESC, p.name, s.name")->fetchAll();
}
include __DIR__ . '/layout_top.php';
?>
<section class="page-header"><div><div class="eyebrow">DSS · DATA PENAWARAN</div><h1>Penawaran supplier</h1><p class="muted">Catat harga dan ketentuan untuk setiap SKU. Nilai harga, waktu pengiriman, dan minimum pembelian dapat dipakai sebagai nilai awal dalam evaluasi.</p></div><div class="page-actions"><a class="btn ghost" href="dss_dashboard.php">← Kembali ke DSS</a><a class="btn secondary" href="dss_suppliers.php">Data supplier</a></div></section>
<?php if ($schemaMissing): ?><div class="alert error">Impor <code>sql/01_schema_main.sql</code> ke <code>db_neo_apparel</code> terlebih dahulu. Tabel belum tersedia: <?= e(implode(', ', $schemaMissing)) ?>.</div><?php else: ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
<?php if (!$suppliers): ?><div class="dss-empty-note"><strong>Tambahkan supplier aktif terlebih dahulu.</strong><p>Penawaran terhubung dengan data supplier. Tambahkan data lewat halaman master supplier.</p><a class="btn primary" href="dss_suppliers.php">Tambah supplier →</a></div><?php endif; ?>
<section class="dss-grid-supplier">
    <article class="panel-card"><div class="panel-heading"><div><h2><?= $editing ? 'Ubah penawaran' : 'Tambah penawaran' ?></h2><p>Satu supplier hanya memiliki satu penawaran terkini per variasi SKU.</p></div><span class="dss-step-badge">02</span></div>
    <form method="post" class="dss-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_offer"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="dss-field full"><label for="supplier_id">Supplier <span>*</span></label><select name="supplier_id" id="supplier_id" required><option value="">Pilih supplier aktif</option><?php foreach($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>" <?= (int)($editing['supplier_id'] ?? 0)===(int)$supplier['id']?'selected':'' ?>><?= e($supplier['supplier_code'].' · '.$supplier['name']) ?></option><?php endforeach; ?></select></div>
        <div class="dss-field full"><label for="variation_id">Produk / SKU toko <span>*</span></label><select name="variation_id" id="variation_id" required><option value="">Pilih variasi produk</option><?php foreach($variations as $variation): ?><option value="<?= (int)$variation['id'] ?>" <?= (int)($editing['variation_id'] ?? 0)===(int)$variation['id']?'selected':'' ?>><?= e(dss_variation_label($variation)) ?></option><?php endforeach; ?></select></div>
        <div class="dss-field"><label for="supplier_sku">SKU dari supplier</label><input id="supplier_sku" name="supplier_sku" maxlength="100" value="<?= e($editing['supplier_sku'] ?? '') ?>" placeholder="Opsional"></div>
        <div class="dss-field"><label for="purchase_price">Harga beli / unit (Rp) <span>*</span></label><input id="purchase_price" type="number" name="purchase_price" min="0" step="0.01" value="<?= e($editing['purchase_price'] ?? '') ?>" required></div>
        <div class="dss-field"><label for="minimum_order_qty">Minimum pembelian (MOQ) <span>*</span></label><input id="minimum_order_qty" type="number" name="minimum_order_qty" min="1" step="1" value="<?= e($editing['minimum_order_qty'] ?? 1) ?>" required></div>
        <div class="dss-field"><label for="lead_time_days">Lead time (hari) <span>*</span></label><input id="lead_time_days" type="number" name="lead_time_days" min="0" step="1" value="<?= e($editing['lead_time_days'] ?? 0) ?>" required></div>
        <div class="dss-field"><label for="shipping_cost">Biaya pengiriman (Rp)</label><input id="shipping_cost" type="number" name="shipping_cost" min="0" step="0.01" value="<?= e($editing['shipping_cost'] ?? 0) ?>"></div>
        <div class="dss-field"><label for="quote_date">Tanggal penawaran</label><input id="quote_date" type="date" name="quote_date" value="<?= e($editing['quote_date'] ?? '') ?>"></div>
        <div class="dss-field"><label for="quote_valid_until">Berlaku sampai</label><input id="quote_valid_until" type="date" name="quote_valid_until" value="<?= e($editing['quote_valid_until'] ?? '') ?>"></div>
        <div class="dss-field"><label for="is_active">Status penawaran</label><select name="is_active" id="is_active"><option value="1" <?= (int)($editing['is_active'] ?? 1)===1?'selected':'' ?>>Aktif</option><option value="0" <?= (int)($editing['is_active'] ?? 1)===0?'selected':'' ?>>Nonaktif</option></select></div>
        <div class="dss-field full"><label for="notes">Catatan penawaran</label><textarea name="notes" id="notes" rows="3" maxlength="500"><?= e($editing['notes'] ?? '') ?></textarea></div>
        <div class="dss-field full dss-form-actions"><button class="btn primary" type="submit"><?= $editing ? 'Simpan penawaran' : 'Simpan penawaran' ?></button><?php if($editing): ?><a class="btn ghost" href="dss_offers.php">Batal</a><?php endif; ?></div>
    </form></article>
    <aside class="panel-card dss-tip-card"><span class="dss-tip-icon">↗</span><h2>Penawaran bukan data transaksi</h2><p>Data di halaman ini adalah penawaran terkini yang dipakai untuk membandingkan supplier. Setelah pembelian dilakukan, histori penerimaan sebaiknya direkam di tabel purchase order agar kualitas dan ketepatan waktu dapat dihitung dari data aktual.</p><div class="dss-mini-note"><strong>Nilai otomatis saat menambah kandidat</strong><span>Harga, MOQ, lead time, dan termin pembayaran dapat disalin ke matriks penilaian. Nilai kualitas/pelayanan tetap perlu riwayat atau input administrator.</span></div></aside>
</section>
<section class="panel-card dss-table-panel"><div class="panel-heading"><div><h2>Daftar penawaran</h2><p>Gunakan pencarian berdasarkan nama produk, SKU, atau supplier.</p></div><span class="dss-table-count"><?= count($offers) ?> penawaran</span></div>
<div class="dss-search-row"><label for="offerSearch">Cari penawaran</label><input type="search" id="offerSearch" placeholder="Nama produk, SKU, supplier..."></div>
<div class="table-wrap"><table id="offerTable" class="data-table dss-table"><thead><tr><th>Produk</th><th>Supplier</th><th>Harga / unit</th><th>MOQ</th><th>Lead time</th><th>Penawaran</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($offers as $offer): $offerExpired=(int)$offer['is_active']===1 && !empty($offer['quote_valid_until']) && $offer['quote_valid_until']<date('Y-m-d'); $offerLabel=!(int)$offer['is_active']?'Nonaktif':($offerExpired?'Kedaluwarsa':'Aktif'); $offerClass=!(int)$offer['is_active']?'warning':($offerExpired?'danger':'success'); ?><tr data-search="<?= e(mb_strtolower(implode(' ',[$offer['product_name'],$offer['sku'],$offer['supplier_name'],$offer['supplier_code'],$offer['supplier_sku']??'']))) ?>"><td><strong><?= e($offer['product_name']) ?></strong><span class="table-secondary"><?= e($offer['sku']) ?><?= $offer['color']||$offer['size']?' · '.e(trim(($offer['color']??'').' '.($offer['size']??''))):'' ?></span></td><td><?= e($offer['supplier_name']) ?><span class="table-secondary"><?= e($offer['supplier_code']) ?></span></td><td><strong><?= dss_money($offer['purchase_price']) ?></strong><span class="table-secondary">Kirim: <?= dss_money($offer['shipping_cost']) ?></span></td><td><?= (int)$offer['minimum_order_qty'] ?> unit</td><td><?= (int)$offer['lead_time_days'] ?> hari</td><td><?= $offer['quote_date']?e(date('d M Y',strtotime($offer['quote_date']))):'—' ?><span class="table-secondary"><?= $offer['quote_valid_until']?'Berlaku s.d. '.e(date('d M Y',strtotime($offer['quote_valid_until']))):'Tanpa batas tanggal' ?></span></td><td><span class="dss-status <?= e($offerClass) ?>"><?= e($offerLabel) ?></span></td><td><a class="btn-sm edit" href="dss_offers.php?edit=<?= (int)$offer['id'] ?>">Ubah</a></td></tr><?php endforeach; ?>
<?php if(!$offers): ?><tr><td colspan="8" class="muted">Belum ada penawaran. Tambahkan melalui formulir di atas.</td></tr><?php endif; ?>
</tbody></table></div></section>
<script>document.getElementById('offerSearch')?.addEventListener('input',function(){const q=this.value.toLocaleLowerCase('id');document.querySelectorAll('#offerTable tbody tr[data-search]').forEach(row=>row.hidden=!row.dataset.search.includes(q));});</script>
<?php endif; ?>
<?php include __DIR__ . '/layout_bottom.php'; ?>
