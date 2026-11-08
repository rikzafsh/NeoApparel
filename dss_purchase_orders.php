<?php
declare(strict_types=1);
require_once __DIR__ . '/dss_functions.php';
$admin = dss_require_admin();
$pageTitle = 'Riwayat Pengadaan Supplier';
$error = '';
$success = isset($_GET['saved']) ? 'Riwayat pengadaan berhasil disimpan.' : '';
$schemaMissing = dss_missing_tables();
$editing = null;
$editingItem = null;
$editingHasMultipleItems = false;
$suppliers = [];
$variations = [];
$offers = [];
$purchaseOrders = [];

if (!$schemaMissing) {
    $suppliers = $pdo->query("SELECT id, supplier_code, name, status FROM suppliers ORDER BY name")->fetchAll();
    $variations = dss_variations($pdo);
    $offers = $pdo->query("SELECT sp.id, sp.supplier_id, sp.variation_id, sp.purchase_price, s.supplier_code, s.name AS supplier_name, p.name AS product_name, v.sku
        FROM supplier_products sp JOIN suppliers s ON s.id=sp.supplier_id
        JOIN product_variations v ON v.id=sp.variation_id JOIN products p ON p.id=v.product_id
        WHERE sp.is_active=1 AND (sp.quote_valid_until IS NULL OR sp.quote_valid_until >= CURDATE()) AND s.status='active' ORDER BY p.name,s.name")->fetchAll();
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare('SELECT * FROM supplier_purchase_orders WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $editing = $stmt->fetch() ?: null;
        if ($editing) {
            $itemsStmt = $pdo->prepare('SELECT * FROM supplier_purchase_order_items WHERE purchase_order_id=? ORDER BY id'); $itemsStmt->execute([(int)$editing['id']]); $items = $itemsStmt->fetchAll();
            $editingHasMultipleItems = count($items) > 1;
            $editingItem = $items[0] ?? null;
        }
    }
}

if (!$schemaMissing && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        if (($_POST['action'] ?? '') !== 'save_purchase_order') throw new RuntimeException('Aksi tidak dikenal.');
        $id = (int)($_POST['id'] ?? 0);
        $poNumber = strtoupper(trim((string)($_POST['po_number'] ?? '')));
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'draft');
        $orderDate = trim((string)($_POST['order_date'] ?? date('Y-m-d')));
        $expectedDate = trim((string)($_POST['expected_delivery_date'] ?? ''));
        $receivedDate = trim((string)($_POST['received_date'] ?? ''));
        $variationId = (int)($_POST['variation_id'] ?? 0);
        $offerId = (int)($_POST['supplier_product_id'] ?? 0);
        $ordered = filter_var($_POST['quantity_ordered'] ?? null, FILTER_VALIDATE_INT);
        $received = filter_var($_POST['quantity_received'] ?? 0, FILTER_VALIDATE_INT);
        $accepted = filter_var($_POST['quantity_accepted'] ?? 0, FILTER_VALIDATE_INT);
        $unitCost = filter_var($_POST['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);
        $shipping = filter_var($_POST['shipping_cost'] ?? 0, FILTER_VALIDATE_FLOAT);
        $otherCost = filter_var($_POST['other_cost'] ?? 0, FILTER_VALIDATE_FLOAT);
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($poNumber === '') $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        if ($orderDate === '') throw new RuntimeException('Tanggal pemesanan wajib diisi.');
        if (mb_strlen($poNumber) > 80) throw new RuntimeException('Nomor PO maksimal 80 karakter.');
        if ($supplierId < 1 || $variationId < 1) throw new RuntimeException('Pilih supplier dan variasi produk.');
        if (!in_array($status, ['draft','ordered','partially_received','received','cancelled'], true)) throw new RuntimeException('Status pengadaan tidak valid.');
        foreach (['tanggal pemesanan'=>$orderDate,'tanggal estimasi penerimaan'=>$expectedDate,'tanggal penerimaan'=>$receivedDate] as $label=>$dateValue) {
            if ($dateValue !== '') { $dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$dateValue); if(!$dateObj || $dateObj->format('Y-m-d')!==$dateValue) throw new RuntimeException('Format '.$label.' tidak valid.'); }
        }
        if ($ordered === false || $ordered < 1 || $received === false || $received < 0 || $accepted === false || $accepted < 0) throw new RuntimeException('Jumlah pesanan/penerimaan harus berupa bilangan bulat yang valid.');
        if ($received > $ordered) throw new RuntimeException('Jumlah diterima tidak boleh melebihi jumlah dipesan.');
        if ($accepted > $received) throw new RuntimeException('Jumlah diterima dengan kualitas baik tidak boleh melebihi jumlah yang diterima.');
        if ($unitCost === false || $unitCost < 0 || $shipping === false || $shipping < 0 || $otherCost === false || $otherCost < 0) throw new RuntimeException('Biaya harus berupa angka valid dan tidak negatif.');
        if (mb_strlen($notes) > 6000) throw new RuntimeException('Catatan maksimal 6.000 karakter.');
        if (in_array($status,['partially_received','received'],true) && $received < 1) throw new RuntimeException('Status penerimaan mengharuskan jumlah diterima lebih dari nol.');
        if ($status === 'partially_received' && $received >= $ordered) throw new RuntimeException('Untuk status diterima sebagian, jumlah diterima harus lebih kecil dari jumlah dipesan.');
        if ($status === 'received' && $received !== $ordered) throw new RuntimeException('Status diterima penuh memerlukan jumlah diterima sama dengan jumlah dipesan.');
        if ($received > 0 && $receivedDate === '') throw new RuntimeException('Isi tanggal penerimaan jika barang sudah diterima.');
        if ($receivedDate !== '' && $receivedDate < $orderDate) throw new RuntimeException('Tanggal penerimaan tidak boleh sebelum tanggal pemesanan.');
        if ($expectedDate !== '' && $expectedDate < $orderDate) throw new RuntimeException('Tanggal estimasi penerimaan tidak boleh sebelum tanggal pemesanan.');

        $supplierCheck = $pdo->prepare("SELECT id, status FROM suppliers WHERE id=?"); $supplierCheck->execute([$supplierId]); $supplier = $supplierCheck->fetch();
        if (!$supplier || ($supplier['status'] === 'blacklisted' && $id === 0)) throw new RuntimeException('Supplier tidak ditemukan atau diblokir.');
        if ($id === 0 && $supplier['status'] !== 'active') throw new RuntimeException('Pengadaan baru hanya dapat dibuat untuk supplier aktif.');
        $variationCheck = $pdo->prepare('SELECT id FROM product_variations WHERE id=? AND is_active=1'); $variationCheck->execute([$variationId]);
        if (!$variationCheck->fetchColumn()) throw new RuntimeException('Variasi produk tidak ditemukan atau nonaktif.');

        if ($offerId > 0) {
            $offerCheck = $pdo->prepare('SELECT * FROM supplier_products WHERE id=? AND is_active=1'); $offerCheck->execute([$offerId]); $offer = $offerCheck->fetch();
            if (!$offer || (int)$offer['supplier_id'] !== $supplierId || (int)$offer['variation_id'] !== $variationId) throw new RuntimeException('Penawaran terkait harus cocok dengan supplier dan variasi yang dipilih.');
        } else {
            $offerId = 0;
        }
        $subtotal = (float)$ordered * (float)$unitCost;
        $total = $subtotal + (float)$shipping + (float)$otherCost;

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT * FROM supplier_purchase_orders WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) throw new RuntimeException('PO tidak ditemukan.');
                $itemCountStmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_purchase_order_items WHERE purchase_order_id=?'); $itemCountStmt->execute([$id]);
                if ((int)$itemCountStmt->fetchColumn() !== 1) throw new RuntimeException('Edit otomatis hanya tersedia untuk PO yang dibuat dari halaman ini dan memiliki satu item. PO multi-item tidak diubah untuk mencegah kehilangan data.');
                $stmt = $pdo->prepare('UPDATE supplier_purchase_orders SET po_number=?, supplier_id=?, status=?, order_date=?, expected_delivery_date=?, received_date=?, subtotal=?, shipping_cost=?, other_cost=?, total_amount=?, notes=? WHERE id=?');
                $stmt->execute([$poNumber,$supplierId,$status,$orderDate,$expectedDate?:null,$receivedDate?:null,$subtotal,$shipping,$otherCost,$total,$notes?:null,$id]);
                $itemStmt = $pdo->prepare('UPDATE supplier_purchase_order_items SET variation_id=?, supplier_product_id=?, quantity_ordered=?, quantity_received=?, quantity_accepted=?, unit_cost=?, line_total=?, notes=? WHERE purchase_order_id=?');
                $itemStmt->execute([$variationId,$offerId?:null,$ordered,$received,$accepted,$unitCost,$subtotal,$notes?:null,$id]);
                dss_audit($pdo, (int)$admin['id'], 'update', 'supplier_purchase_orders', $id, $old, ['po_number'=>$poNumber,'supplier_id'=>$supplierId,'status'=>$status,'total_amount'=>$total]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO supplier_purchase_orders (po_number,supplier_id,status,order_date,expected_delivery_date,received_date,subtotal,shipping_cost,other_cost,total_amount,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$poNumber,$supplierId,$status,$orderDate,$expectedDate?:null,$receivedDate?:null,$subtotal,$shipping,$otherCost,$total,$notes?:null,(int)$admin['id']]);
                $id = (int)$pdo->lastInsertId();
                $itemStmt = $pdo->prepare('INSERT INTO supplier_purchase_order_items (purchase_order_id,variation_id,supplier_product_id,quantity_ordered,quantity_received,quantity_accepted,unit_cost,line_total,notes) VALUES (?,?,?,?,?,?,?,?,?)');
                $itemStmt->execute([$id,$variationId,$offerId?:null,$ordered,$received,$accepted,$unitCost,$subtotal,$notes?:null]);
                dss_audit($pdo, (int)$admin['id'], 'create', 'supplier_purchase_orders', $id, null, ['po_number'=>$poNumber,'supplier_id'=>$supplierId,'status'=>$status,'total_amount'=>$total]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        go('dss_purchase_orders.php?saved=1');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException && str_contains($e->getMessage(), 'uq_supplier_po_number') ? 'Nomor PO sudah digunakan.' : $e->getMessage();
    }
}

if (!$schemaMissing) {
    $purchaseOrders = $pdo->query("SELECT po.*, s.name AS supplier_name, s.supplier_code,
        COALESCE(items.item_count,0) AS item_count, COALESCE(items.quantity_ordered,0) AS quantity_ordered,
        COALESCE(items.quantity_received,0) AS quantity_received, COALESCE(items.quantity_accepted,0) AS quantity_accepted,
        items.item_labels
        FROM supplier_purchase_orders po JOIN suppliers s ON s.id=po.supplier_id
        LEFT JOIN (
            SELECT i.purchase_order_id, COUNT(i.id) AS item_count,
                SUM(i.quantity_ordered) AS quantity_ordered, SUM(i.quantity_received) AS quantity_received,
                SUM(i.quantity_accepted) AS quantity_accepted,
                GROUP_CONCAT(CONCAT(p.name,' · ',v.sku) ORDER BY p.name SEPARATOR ', ') AS item_labels
            FROM supplier_purchase_order_items i
            LEFT JOIN product_variations v ON v.id=i.variation_id
            LEFT JOIN products p ON p.id=v.product_id
            GROUP BY i.purchase_order_id
        ) items ON items.purchase_order_id=po.id
        ORDER BY po.order_date DESC, po.id DESC LIMIT 100")->fetchAll();
}
include __DIR__ . '/layout_top.php';
?>
<section class="page-header"><div><div class="eyebrow">DSS · RIWAYAT PENGADAAN</div><h1>Purchase order supplier</h1><p class="muted">Catat pesanan pembelian dan realisasi penerimaan. Riwayat ini membantu menghitung tingkat penerimaan kualitas serta ketepatan waktu supplier secara lebih objektif.</p></div><div class="page-actions"><a class="btn ghost" href="dss_dashboard.php">← Kembali ke DSS</a><a class="btn secondary" href="dss_suppliers.php">Data supplier</a></div></section>
<?php if($schemaMissing): ?><div class="alert error">Impor <code>sql/01_schema_main.sql</code> ke <code>db_neo_apparel</code> terlebih dahulu. Tabel belum tersedia: <?= e(implode(', ',$schemaMissing)) ?>.</div><?php else: ?>
<?php if($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><?php if($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
<?php if($editingHasMultipleItems): ?><div class="dss-inline-warning"><strong>PO ini memiliki lebih dari satu item.</strong> Form editor satu-item tidak dibuka untuk mencegah perubahan tidak sengaja. Kamu tetap dapat melihat detailnya di daftar bawah.</div><?php endif; ?>
<section class="dss-grid-supplier">
<article class="panel-card"><div class="panel-heading"><div><h2><?= $editing?'Perbarui purchase order':'Catat purchase order' ?></h2><p>Form ini membuat satu baris detail barang per PO. PO multi-item bisa direkam dengan nomor PO berbeda per baris, atau dikembangkan pada tahap berikutnya.</p></div><span class="dss-step-badge">PO</span></div>
<?php if($editing && $editingHasMultipleItems): ?><div class="dss-empty-inline">Edit dinonaktifkan untuk PO multi-item. Gunakan data aktual dalam daftar atau buat catatan baru untuk perubahan lanjutan.</div>
<?php else: ?>
<form method="post" class="dss-form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_purchase_order"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">
<div class="dss-field"><label for="po_number">Nomor PO</label><input id="po_number" name="po_number" maxlength="80" value="<?= e($editing['po_number']??'') ?>" placeholder="Kosongkan untuk dibuat otomatis"></div>
<div class="dss-field"><label for="supplier_id">Supplier <span>*</span></label><select name="supplier_id" id="po_supplier_id" required><option value="">Pilih supplier</option><?php foreach($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>" <?= (int)($editing['supplier_id']??0)===(int)$supplier['id']?'selected':'' ?>><?= e($supplier['supplier_code'].' · '.$supplier['name'].($supplier['status']==='active'?'':' · '.$supplier['status'])) ?></option><?php endforeach; ?></select></div>
<div class="dss-field full"><label for="variation_id">Produk / variasi <span>*</span></label><select name="variation_id" id="po_variation_id" required><option value="">Pilih SKU</option><?php foreach($variations as $variation): ?><option value="<?= (int)$variation['id'] ?>" <?= (int)($editingItem['variation_id']??0)===(int)$variation['id']?'selected':'' ?>><?= e(dss_variation_label($variation)) ?></option><?php endforeach; ?></select></div>
<div class="dss-field full"><label for="supplier_product_id">Penawaran terkait</label><select name="supplier_product_id" id="po_offer_id"><option value="0">Tidak dihubungkan ke penawaran</option><?php foreach($offers as $offer): ?><option value="<?= (int)$offer['id'] ?>" data-supplier="<?= (int)$offer['supplier_id'] ?>" data-variation="<?= (int)$offer['variation_id'] ?>" data-price="<?= e((string)$offer['purchase_price']) ?>" <?= (int)($editingItem['supplier_product_id']??0)===(int)$offer['id']?'selected':'' ?>><?= e($offer['supplier_name'].' · '.$offer['product_name'].' / '.$offer['sku'].' · '.dss_money($offer['purchase_price'])) ?></option><?php endforeach; ?></select></div>
<div class="dss-field"><label for="order_date">Tanggal pemesanan <span>*</span></label><input type="date" id="order_date" name="order_date" required value="<?= e($editing['order_date']??date('Y-m-d')) ?>"></div>
<div class="dss-field"><label for="expected_delivery_date">Estimasi penerimaan</label><input type="date" id="expected_delivery_date" name="expected_delivery_date" value="<?= e($editing['expected_delivery_date']??'') ?>"></div>
<div class="dss-field"><label for="received_date">Tanggal diterima</label><input type="date" id="received_date" name="received_date" value="<?= e($editing['received_date']??'') ?>"></div>
<div class="dss-field"><label for="status">Status PO</label><select name="status" id="status"><?php foreach(['draft'=>'Draf','ordered'=>'Dipesan','partially_received'=>'Diterima sebagian','received'=>'Diterima penuh','cancelled'=>'Dibatalkan'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($editing['status']??'draft')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<div class="dss-field"><label for="quantity_ordered">Jumlah dipesan <span>*</span></label><input type="number" id="quantity_ordered" name="quantity_ordered" min="1" step="1" required value="<?= e($editingItem['quantity_ordered']??1) ?>"></div>
<div class="dss-field"><label for="quantity_received">Jumlah diterima</label><input type="number" id="quantity_received" name="quantity_received" min="0" step="1" value="<?= e($editingItem['quantity_received']??0) ?>"></div>
<div class="dss-field"><label for="quantity_accepted">Jumlah lolos kualitas</label><input type="number" id="quantity_accepted" name="quantity_accepted" min="0" step="1" value="<?= e($editingItem['quantity_accepted']??0) ?>"></div>
<div class="dss-field"><label for="unit_cost">Harga beli / unit (Rp) <span>*</span></label><input type="number" id="unit_cost" name="unit_cost" min="0" step="0.01" required value="<?= e($editingItem['unit_cost']??'') ?>"></div>
<div class="dss-field"><label for="shipping_cost">Biaya kirim (Rp)</label><input type="number" id="shipping_cost" name="shipping_cost" min="0" step="0.01" value="<?= e($editing['shipping_cost']??0) ?>"></div>
<div class="dss-field"><label for="other_cost">Biaya lain (Rp)</label><input type="number" id="other_cost" name="other_cost" min="0" step="0.01" value="<?= e($editing['other_cost']??0) ?>"></div>
<div class="dss-field full"><label for="notes">Catatan pengadaan</label><textarea name="notes" id="notes" rows="3" maxlength="6000" placeholder="Nomor dokumen, kondisi barang, keterlambatan, atau catatan pemeriksaan"><?= e($editing['notes']??'') ?></textarea></div>
<div class="dss-field full dss-form-actions"><button type="submit" class="btn primary"><?= $editing?'Simpan pembaruan':'Simpan PO' ?></button><?php if($editing): ?><a href="dss_purchase_orders.php" class="btn ghost">Batalkan edit</a><?php endif; ?></div>
</form><?php endif; ?></article>
<aside class="panel-card dss-tip-card"><span class="dss-tip-icon">▤</span><h2>Mengapa riwayat ini penting?</h2><p>Penilaian supplier akan lebih objektif jika memakai hasil pengadaan yang benar-benar terjadi, bukan hanya angka perkiraan.</p><ul><li><strong>Tingkat diterima:</strong> jumlah lolos kualitas ÷ jumlah diterima.</li><li><strong>Tepat waktu:</strong> tanggal diterima dibanding tanggal estimasi.</li><li><strong>Audit:</strong> pencatatan dibuat atau diperbarui oleh administrator.</li></ul><div class="dss-mini-note"><strong>Catatan pencatatan</strong><span>Untuk evaluasi kualitas, isi jumlah diterima dan jumlah yang lolos pemeriksaan. Untuk menghitung ketepatan waktu, isi tanggal estimasi dan tanggal diterima.</span></div></aside>
</section>
<section class="panel-card dss-table-panel"><div class="panel-heading"><div><h2>Riwayat purchase order</h2><p>Menampilkan hingga 100 PO terbaru beserta progres penerimaannya.</p></div><span class="dss-table-count"><?= count($purchaseOrders) ?> PO</span></div>
<div class="dss-search-row"><label for="poSearch">Cari PO</label><input type="search" id="poSearch" placeholder="Nomor PO, supplier, produk, SKU..."></div>
<div class="table-wrap"><table class="data-table dss-table" id="poTable"><thead><tr><th>Nomor / tanggal</th><th>Supplier</th><th>Item</th><th>Jumlah pesan / diterima / lolos</th><th>Total biaya</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($purchaseOrders as $po): ?><tr data-search="<?= e(mb_strtolower(implode(' ',[$po['po_number'],$po['supplier_name'],$po['supplier_code'],$po['item_labels']??'']))) ?>"><td><strong><?= e($po['po_number']) ?></strong><span class="table-secondary">Dipesan <?= e(date('d M Y',strtotime($po['order_date']))) ?></span><?php if($po['expected_delivery_date']): ?><span class="table-secondary">Estimasi <?= e(date('d M Y',strtotime($po['expected_delivery_date']))) ?></span><?php endif; ?></td><td><?= e($po['supplier_name']) ?><span class="table-secondary"><?= e($po['supplier_code']) ?></span></td><td><?= e($po['item_labels']?:'Item tidak tercatat') ?><span class="table-secondary"><?= (int)$po['item_count'] ?> item</span></td><td><?= (int)$po['quantity_ordered'] ?> / <?= (int)$po['quantity_received'] ?> / <?= (int)$po['quantity_accepted'] ?></td><td><strong><?= dss_money($po['total_amount']) ?></strong></td><td><span class="dss-status <?= e(dss_status_class($po['status']==='received'?'active':($po['status']==='cancelled'?'cancelled':($po['status']==='draft'?'draft':'evaluated')))) ?>"><?= e(dss_status_label($po['status'])) ?></span></td><td><?php if((int)$po['item_count']===1): ?><a class="btn-sm edit" href="dss_purchase_orders.php?edit=<?= (int)$po['id'] ?>">Ubah</a><?php else: ?><span class="table-secondary">Multi-item</span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$purchaseOrders): ?><tr><td colspan="7" class="muted">Belum ada riwayat pengadaan supplier.</td></tr><?php endif; ?>
</tbody></table></div></section>
<script>
(function(){const supplier=document.getElementById('po_supplier_id');const variation=document.getElementById('po_variation_id');const offer=document.getElementById('po_offer_id');const cost=document.getElementById('unit_cost');offer?.addEventListener('change',function(){const o=this.options[this.selectedIndex];if(o?.dataset?.supplier){supplier.value=o.dataset.supplier;variation.value=o.dataset.variation;if(cost&&!cost.value)cost.value=o.dataset.price;}});document.getElementById('poSearch')?.addEventListener('input',function(){const q=this.value.toLocaleLowerCase('id');document.querySelectorAll('#poTable tbody tr[data-search]').forEach(row=>row.hidden=!row.dataset.search.includes(q));});})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/layout_bottom.php'; ?>
