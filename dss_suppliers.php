<?php
declare(strict_types=1);
require_once __DIR__ . '/dss_functions.php';
$admin = dss_require_admin();
$pageTitle = 'Data Supplier';
$error = '';
$success = '';
$schemaMissing = dss_missing_tables();
$editing = null;

if (!$schemaMissing && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

if (!$schemaMissing && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_supplier') {
            $id = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['supplier_code'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $contact = trim((string)($_POST['contact_person'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $province = trim((string)($_POST['province'] ?? ''));
            $terms = filter_var($_POST['payment_terms_days'] ?? 0, FILTER_VALIDATE_INT);
            $status = (string)($_POST['status'] ?? 'active');
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($code === '' || mb_strlen($code) > 30 || $name === '' || mb_strlen($name) > 180) throw new RuntimeException('Kode supplier dan nama supplier wajib diisi.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email supplier tidak valid.');
            if ($terms === false || $terms < 0 || $terms > 365) throw new RuntimeException('Termin pembayaran harus antara 0–365 hari.');
            if (!in_array($status, ['active', 'inactive', 'blacklisted'], true)) throw new RuntimeException('Status supplier tidak valid.');
            if (mb_strlen($contact) > 120 || mb_strlen($email) > 190 || mb_strlen($phone) > 30 || mb_strlen($address) > 255 || mb_strlen($city) > 100 || mb_strlen($province) > 100) throw new RuntimeException('Ada kolom yang melebihi panjang yang diizinkan.');
            $old = null;
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?'); $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) throw new RuntimeException('Supplier yang akan diperbarui tidak ditemukan.');
                $stmt = $pdo->prepare('UPDATE suppliers SET supplier_code=?, name=?, contact_person=?, email=?, phone=?, address=?, city=?, province=?, payment_terms_days=?, notes=?, status=? WHERE id=?');
                $stmt->execute([$code, $name, $contact ?: null, $email ?: null, $phone ?: null, $address ?: null, $city ?: null, $province ?: null, $terms, $notes ?: null, $status, $id]);
                dss_audit($pdo, (int)$admin['id'], 'update', 'suppliers', $id, $old, ['supplier_code'=>$code,'name'=>$name,'status'=>$status]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO suppliers (supplier_code, name, contact_person, email, phone, address, city, province, payment_terms_days, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$code, $name, $contact ?: null, $email ?: null, $phone ?: null, $address ?: null, $city ?: null, $province ?: null, $terms, $notes ?: null, $status, (int)$admin['id']]);
                $id = (int)$pdo->lastInsertId();
                dss_audit($pdo, (int)$admin['id'], 'create', 'suppliers', $id, null, ['supplier_code'=>$code,'name'=>$name,'status'=>$status]);
            }
            go('dss_suppliers.php?saved=1');
        }
        if ($action === 'quick_status') {
            $id = (int)($_POST['id'] ?? 0); $status = (string)($_POST['status'] ?? 'inactive');
            if (!in_array($status, ['active','inactive','blacklisted'], true)) throw new RuntimeException('Status tidak valid.');
            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?'); $stmt->execute([$id]); $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Supplier tidak ditemukan.');
            $pdo->prepare('UPDATE suppliers SET status=? WHERE id=?')->execute([$status,$id]);
            dss_audit($pdo, (int)$admin['id'], 'update_status', 'suppliers', $id, ['status'=>$old['status']], ['status'=>$status]);
            go('dss_suppliers.php?saved=1');
        }
        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException && str_contains($e->getMessage(), 'uq_suppliers_code') ? 'Kode supplier sudah digunakan.' : $e->getMessage();
    }
}
if (isset($_GET['saved'])) $success = 'Data supplier berhasil disimpan.';

$suppliers = [];
if (!$schemaMissing) {
    $suppliers = $pdo->query("SELECT s.*,
        (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id=s.id) AS offer_count,
        (SELECT COUNT(*) FROM dss_evaluation_candidates c WHERE c.supplier_id=s.id) AS evaluation_count
        FROM suppliers s ORDER BY FIELD(s.status,'active','inactive','blacklisted'), s.name")->fetchAll();
}
include __DIR__ . '/layout_top.php';
?>
<section class="page-header"><div><div class="eyebrow">DSS · MASTER DATA</div><h1>Supplier</h1><p class="muted">Kelola informasi pemasok, kontak, termin pembayaran, dan status kelayakan. Supplier yang pernah dipakai evaluasi tidak dihapus agar riwayat tetap utuh.</p></div><div class="page-actions"><a class="btn ghost" href="dss_dashboard.php">← Kembali ke DSS</a><a class="btn primary" href="dss_offers.php">Kelola penawaran →</a></div></section>
<?php if ($schemaMissing): ?><div class="alert error">Impor <code>sql/01_schema_main.sql</code> ke <code>db_neo_apparel</code> terlebih dahulu. Tabel belum tersedia: <?= e(implode(', ', $schemaMissing)) ?>.</div><?php else: ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
<section class="dss-grid-supplier">
    <article class="panel-card"><div class="panel-heading"><div><h2><?= $editing ? 'Ubah data supplier' : 'Tambah supplier' ?></h2><p>Isi data yang diketahui. Kolom kontak tambahan bersifat opsional.</p></div><span class="dss-step-badge">01</span></div>
        <form method="post" class="dss-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <div class="dss-field"><label for="supplier_code">Kode supplier <span>*</span></label><input id="supplier_code" name="supplier_code" maxlength="30" required placeholder="SUP-001" value="<?= e($editing['supplier_code'] ?? '') ?>"></div>
            <div class="dss-field"><label for="name">Nama supplier <span>*</span></label><input id="name" name="name" maxlength="180" required placeholder="CV Sandang Nusantara" value="<?= e($editing['name'] ?? '') ?>"></div>
            <div class="dss-field"><label for="contact_person">Kontak person</label><input id="contact_person" name="contact_person" maxlength="120" value="<?= e($editing['contact_person'] ?? '') ?>"></div>
            <div class="dss-field"><label for="phone">Nomor telepon</label><input id="phone" name="phone" maxlength="30" value="<?= e($editing['phone'] ?? '') ?>"></div>
            <div class="dss-field full"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" value="<?= e($editing['email'] ?? '') ?>"></div>
            <div class="dss-field full"><label for="address">Alamat</label><input id="address" name="address" maxlength="255" value="<?= e($editing['address'] ?? '') ?>"></div>
            <div class="dss-field"><label for="city">Kota</label><input id="city" name="city" maxlength="100" value="<?= e($editing['city'] ?? '') ?>"></div>
            <div class="dss-field"><label for="province">Provinsi</label><input id="province" name="province" maxlength="100" value="<?= e($editing['province'] ?? '') ?>"></div>
            <div class="dss-field"><label for="payment_terms_days">Termin pembayaran (hari)</label><input id="payment_terms_days" type="number" name="payment_terms_days" min="0" max="365" value="<?= e($editing['payment_terms_days'] ?? 0) ?>"><small>Contoh: 0 berarti tunai.</small></div>
            <div class="dss-field"><label for="status">Status</label><select id="status" name="status"><?php foreach (['active'=>'Aktif','inactive'=>'Nonaktif','blacklisted'=>'Diblokir'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($editing['status'] ?? 'active')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="dss-field full"><label for="notes">Catatan internal</label><textarea id="notes" name="notes" rows="3" placeholder="Catatan yang perlu diketahui admin"><?= e($editing['notes'] ?? '') ?></textarea></div>
            <div class="dss-field full dss-form-actions"><button class="btn primary" type="submit"><?= $editing ? 'Simpan perubahan' : 'Tambah supplier' ?></button><?php if ($editing): ?><a class="btn ghost" href="dss_suppliers.php">Batal</a><?php endif; ?></div>
        </form>
    </article>
    <aside class="panel-card dss-tip-card"><span class="dss-tip-icon">ⓘ</span><h2>Praktik yang disarankan</h2><p>Gunakan kode unik untuk setiap supplier. Jangan hapus pemasok yang sudah ikut evaluasi atau memiliki pesanan pengadaan; ubah statusnya menjadi nonaktif atau diblokir.</p><ul><li><strong>Aktif</strong> — dapat digunakan pada evaluasi baru.</li><li><strong>Nonaktif</strong> — disimpan untuk riwayat, tidak tersedia untuk pilihan supplier baru.</li><li><strong>Diblokir</strong> — tidak direkomendasikan untuk transaksi berikutnya.</li></ul><a class="btn secondary" href="dss_offers.php">Lihat penawaran supplier →</a></aside>
</section>
<section class="panel-card dss-table-panel"><div class="panel-heading"><div><h2>Daftar supplier</h2><p>Gunakan pencarian untuk mempersempit daftar, tanpa menghapus riwayat lama.</p></div><span class="dss-table-count"><?= count($suppliers) ?> supplier</span></div>
    <div class="dss-search-row"><label for="supplierSearch">Cari supplier</label><input type="search" id="supplierSearch" placeholder="Cari kode, nama, kota, atau kontak..."></div>
    <div class="table-wrap"><table class="data-table dss-table" id="supplierTable"><thead><tr><th>Supplier</th><th>Kontak</th><th>Lokasi</th><th>Termin</th><th>Penawaran</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($suppliers as $supplier): ?><tr data-search="<?= e(mb_strtolower(implode(' ', [$supplier['supplier_code'],$supplier['name'],$supplier['contact_person'],$supplier['city'],$supplier['phone']]))) ?>"><td><strong><?= e($supplier['name']) ?></strong><span class="table-secondary"><?= e($supplier['supplier_code']) ?> · <?= (int)$supplier['evaluation_count'] ?> evaluasi</span></td><td><?= e($supplier['contact_person'] ?: '—') ?><span class="table-secondary"><?= e($supplier['phone'] ?: $supplier['email'] ?: 'Kontak belum diisi') ?></span></td><td><?= e(trim(($supplier['city'] ?? '') . (empty($supplier['province']) ? '' : ', ' . $supplier['province'])) ?: '—') ?></td><td><?= (int)$supplier['payment_terms_days'] ?> hari</td><td><?= (int)$supplier['offer_count'] ?></td><td><span class="dss-status <?= e(dss_status_class($supplier['status'])) ?>"><?= e(dss_status_label($supplier['status'])) ?></span></td><td><div class="dss-actions"><a class="btn-sm edit" href="dss_suppliers.php?edit=<?= (int)$supplier['id'] ?>">Ubah</a><?php if ($supplier['status'] === 'active'): ?><form method="post" onsubmit="return confirm('Nonaktifkan supplier ini? Data historisnya tetap tersimpan.');"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>"><input type="hidden" name="status" value="inactive"><button class="btn-sm danger" type="submit">Nonaktifkan</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>"><input type="hidden" name="status" value="active"><button class="btn-sm" type="submit">Aktifkan</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
    <?php if (!$suppliers): ?><tr><td colspan="7" class="muted">Belum ada data supplier.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script>document.getElementById('supplierSearch')?.addEventListener('input', function(){const q=this.value.toLocaleLowerCase('id');document.querySelectorAll('#supplierTable tbody tr[data-search]').forEach(row=>row.hidden=!row.dataset.search.includes(q));});</script>
<?php endif; ?>
<?php include __DIR__ . '/layout_bottom.php'; ?>
