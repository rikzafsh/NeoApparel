<?php
declare(strict_types=1);
require_once __DIR__ . '/dss_functions.php';
$admin = dss_require_admin();
$pageTitle = 'DSS Pemilihan Supplier';
$error = '';
$success = '';
$schemaMissing = dss_missing_tables();

if (!$schemaMissing && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        if (($_POST['action'] ?? '') !== 'create_evaluation') {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        $title = trim((string)($_POST['title'] ?? ''));
        $targetVariationId = (int)($_POST['target_variation_id'] ?? 0);
        $date = trim((string)($_POST['evaluation_date'] ?? date('Y-m-d')));
        $method = (string)($_POST['method'] ?? 'SAW');
        if ($title === '' || mb_strlen($title) > 180) {
            throw new RuntimeException('Nama evaluasi wajib diisi dan maksimal 180 karakter.');
        }
        $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            throw new RuntimeException('Tanggal evaluasi tidak valid.');
        }
        if (!in_array($method, ['SAW'], true)) {
            throw new RuntimeException('Versi frontend ini mendukung perhitungan SAW. Metode lain disiapkan untuk pengembangan berikutnya.');
        }
        if ($targetVariationId > 0) {
            $check = $pdo->prepare('SELECT id FROM product_variations WHERE id = ? AND is_active = 1');
            $check->execute([$targetVariationId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('SKU yang dipilih tidak ditemukan atau sudah nonaktif.');
            }
        } else {
            $targetVariationId = 0;
        }
        $criteria = $pdo->query('SELECT id, default_weight FROM dss_criteria WHERE is_active = 1 ORDER BY id')->fetchAll();
        if (!$criteria) {
            throw new RuntimeException('Belum ada kriteria aktif. Jalankan seed kriteria pada migration_dss_supplier.sql.');
        }
        $weightTotal = array_sum(array_map(static fn(array $row): float => (float)$row['default_weight'], $criteria));
        if (abs($weightTotal - 100.0) > 0.02) {
            throw new RuntimeException('Total bobot default kriteria harus berjumlah 100%. Saat ini: ' . dss_number($weightTotal) . '%. Perbaiki bobot di tabel dss_criteria sebelum membuat evaluasi.');
        }

        $pdo->beginTransaction();
        try {
            $code = 'DSS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $insert = $pdo->prepare('INSERT INTO dss_evaluations (evaluation_code, title, target_variation_id, method, evaluation_date, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$code, $title, $targetVariationId ?: null, $method, $date, (int)$admin['id']]);
            $evaluationId = (int)$pdo->lastInsertId();
            $insertWeight = $pdo->prepare('INSERT INTO dss_evaluation_criteria (evaluation_id, criterion_id, weight_percent) VALUES (?, ?, ?)');
            foreach ($criteria as $criterion) {
                $insertWeight->execute([$evaluationId, (int)$criterion['id'], (float)$criterion['default_weight']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        dss_audit($pdo, (int)$admin['id'], 'create', 'dss_evaluations', $evaluationId, null, ['evaluation_code' => $code, 'title' => $title, 'method' => $method]);
        go('dss_evaluation.php?id=' . $evaluationId . '&created=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stats = ['suppliers' => 0, 'offers' => 0, 'evaluations' => 0, 'approved' => 0];
$evaluations = [];
$variations = [];
if (!$schemaMissing) {
    $stats['suppliers'] = (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'active'")->fetchColumn();
    $stats['offers'] = (int)$pdo->query('SELECT COUNT(*) FROM supplier_products WHERE is_active = 1 AND (quote_valid_until IS NULL OR quote_valid_until >= CURDATE())')->fetchColumn();
    $stats['evaluations'] = (int)$pdo->query("SELECT COUNT(*) FROM dss_evaluations WHERE status <> 'cancelled'")->fetchColumn();
    $stats['approved'] = (int)$pdo->query("SELECT COUNT(*) FROM dss_evaluations WHERE status = 'approved'")->fetchColumn();
    $evaluations = $pdo->query("SELECT e.*, p.name AS product_name, v.sku, v.color, v.size, u.name AS creator_name,
            (SELECT COUNT(*) FROM dss_evaluation_candidates c WHERE c.evaluation_id = e.id AND c.candidate_status = 'included') AS candidate_count,
            (SELECT r.supplier_id FROM dss_evaluation_results er JOIN dss_evaluation_candidates r ON r.id = er.candidate_id WHERE r.evaluation_id = e.id AND er.is_recommended = 1 LIMIT 1) AS recommended_supplier_id,
            (SELECT s.name FROM dss_evaluation_results er JOIN dss_evaluation_candidates c ON c.id = er.candidate_id JOIN suppliers s ON s.id = c.supplier_id WHERE c.evaluation_id = e.id AND er.is_recommended = 1 LIMIT 1) AS recommended_supplier
        FROM dss_evaluations e
        LEFT JOIN product_variations v ON v.id = e.target_variation_id
        LEFT JOIN products p ON p.id = v.product_id
        LEFT JOIN users u ON u.id = e.created_by
        ORDER BY e.evaluation_date DESC, e.id DESC LIMIT 30")->fetchAll();
    $variations = dss_variations($pdo);
}
include __DIR__ . '/layout_top.php';
?>
<section class="page-header">
    <div><div class="eyebrow">PENGADAAN · DSS</div><h1>Pemilihan supplier</h1><p class="muted">Bandingkan alternatif supplier secara terukur, telusuri nilai setiap kriteria, dan simpan keputusan administrator.</p></div>
    <div class="page-actions"><a class="btn ghost" href="dss_suppliers.php">Kelola supplier</a><a class="btn secondary" href="dss_purchase_orders.php">Riwayat pengadaan</a><a class="btn primary" href="dss_offers.php">Kelola penawaran</a></div>
</section>
<?php if ($schemaMissing): ?>
    <div class="alert error"><strong>Database DSS belum siap.</strong><br>Impor file <code>sql/01_schema_main.sql</code> ke database <code>db_neo_apparel</code>. Tabel yang belum ditemukan: <?= e(implode(', ', $schemaMissing)) ?>.</div>
<?php else: ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <section class="dss-stats-grid">
        <article class="dss-stat-card"><span class="dss-stat-icon purple">▣</span><div><small>Supplier aktif</small><strong><?= number_format($stats['suppliers']) ?></strong><span>Siap dievaluasi</span></div></article>
        <article class="dss-stat-card"><span class="dss-stat-icon blue">↔</span><div><small>Penawaran aktif</small><strong><?= number_format($stats['offers']) ?></strong><span>Harga dan ketentuan supplier</span></div></article>
        <article class="dss-stat-card"><span class="dss-stat-icon green">◎</span><div><small>Evaluasi tersimpan</small><strong><?= number_format($stats['evaluations']) ?></strong><span>Draf maupun sudah dihitung</span></div></article>
        <article class="dss-stat-card"><span class="dss-stat-icon gold">✓</span><div><small>Keputusan disetujui</small><strong><?= number_format($stats['approved']) ?></strong><span>Disahkan administrator</span></div></article>
    </section>

    <section class="dss-grid-home">
        <article class="panel-card dss-create-panel">
            <div class="panel-heading"><div><h2>Buat evaluasi baru</h2><p>Setiap evaluasi menyimpan salinan bobot kriteria agar riwayatnya tetap konsisten.</p></div><span class="dss-step-badge">01</span></div>
            <form method="post" class="dss-form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="create_evaluation">
                <div class="dss-field full"><label for="title">Nama evaluasi <span>*</span></label><input id="title" name="title" maxlength="180" required placeholder="Contoh: Seleksi supplier kaus polos November"></div>
                <div class="dss-field full"><label for="target_variation_id">Produk / SKU yang akan dibeli</label><select id="target_variation_id" name="target_variation_id"><option value="0">Evaluasi supplier umum (tanpa SKU tertentu)</option><?php foreach ($variations as $variation): ?><option value="<?= (int)$variation['id'] ?>"><?= e(dss_variation_label($variation)) ?> · stok <?= (int)$variation['stock'] ?></option><?php endforeach; ?></select><small>Memilih SKU membantu mencocokkan penawaran supplier untuk produk yang sama.</small></div>
                <div class="dss-field"><label for="evaluation_date">Tanggal evaluasi <span>*</span></label><input id="evaluation_date" type="date" name="evaluation_date" value="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="dss-field"><label for="method">Metode perhitungan</label><select id="method" name="method"><option value="SAW">SAW · Simple Additive Weighting</option></select><small>Metode TOPSIS/WP disiapkan untuk pengembangan berikutnya.</small></div>
                <div class="dss-field full"><button class="btn primary" type="submit">Buat evaluasi <span aria-hidden="true">→</span></button></div>
            </form>
        </article>

        <aside class="dss-side-stack">
            <article class="panel-card dss-method-card"><span class="dss-method-kicker">METODE SAW</span><h2>Skor yang bisa dijelaskan</h2><p>Nilai cost dinormalisasi dengan membandingkan nilai minimum terhadap nilai supplier. Nilai benefit dibandingkan terhadap nilai maksimum.</p><div class="dss-formula">Skor akhir = Σ (nilai normalisasi × bobot)</div><small>Bobot per evaluasi harus berjumlah tepat 100%.</small></article>
            <article class="panel-card"><div class="panel-heading"><div><h2>Alur kerja</h2><p>Mulai dari data supplier sampai keputusan.</p></div></div><div class="dss-steps-list"><div><b>1</b><span><strong>Kelola supplier</strong><small>Identitas dan status</small></span></div><div><b>2</b><span><strong>Catat penawaran</strong><small>Harga, MOQ, lead time</small></span></div><div><b>3</b><span><strong>Isi nilai & bobot</strong><small>Data aktual atau penilaian admin</small></span></div><div><b>4</b><span><strong>Hitung dan setujui</strong><small>Rekomendasi disertai rincian</small></span></div></div></article>
        </aside>
    </section>

    <section class="panel-card" style="margin-top:16px">
        <div class="panel-heading"><div><h2>Riwayat evaluasi</h2><p>Evaluasi terbaru ditampilkan lebih dahulu. Hasil tidak mengubah data transaksi pelanggan TPS.</p></div><span class="dss-table-count"><?= count($evaluations) ?> evaluasi</span></div>
        <div class="table-wrap"><table class="data-table dss-table"><thead><tr><th>Kode / evaluasi</th><th>Produk target</th><th>Alternatif</th><th>Status</th><th>Rekomendasi</th><th>Tanggal</th><th></th></tr></thead><tbody>
        <?php foreach ($evaluations as $evaluation): $target = $evaluation['product_name'] ? $evaluation['product_name'] . ' · ' . $evaluation['sku'] : 'Supplier umum'; ?>
            <tr><td><strong><?= e($evaluation['title']) ?></strong><span class="table-secondary"><?= e($evaluation['evaluation_code']) ?> · <?= e($evaluation['method']) ?></span></td><td><?= e($target) ?></td><td><?= (int)$evaluation['candidate_count'] ?> supplier</td><td><span class="dss-status <?= e(dss_status_class($evaluation['status'])) ?>"><?= e(dss_status_label($evaluation['status'])) ?></span></td><td><?php if ($evaluation['recommended_supplier']): ?><strong><?= e($evaluation['recommended_supplier']) ?></strong><?php elseif ($evaluation['status'] === 'evaluated'): ?>Belum ada hasil<?php else: ?><span class="muted">Belum dihitung</span><?php endif; ?></td><td><?= e(date('d M Y', strtotime($evaluation['evaluation_date']))) ?></td><td><a class="btn-sm edit" href="dss_evaluation.php?id=<?= (int)$evaluation['id'] ?>">Buka evaluasi →</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$evaluations): ?><tr><td colspan="7"><div class="dss-empty"><span>◎</span><strong>Belum ada evaluasi supplier</strong><p>Buat evaluasi pertama, kemudian tambahkan supplier pembandingnya.</p></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
<?php endif; ?>
<?php include __DIR__ . '/layout_bottom.php'; ?>
