<?php
declare(strict_types=1);
require_once __DIR__ . '/dss_functions.php';
$admin = dss_require_admin();
$pageTitle = 'Detail Evaluasi DSS';
$evaluationId = (int)($_GET['id'] ?? 0);
$error = '';
$success = isset($_GET['created']) ? 'Evaluasi berhasil dibuat. Tambahkan alternatif supplier untuk memulai penilaian.' : '';
$schemaMissing = dss_missing_tables();
$evaluation = null;
$criteria = [];
$candidates = [];
$scoresMap = [];
$results = [];
$suppliers = [];
$offers = [];

if (!$schemaMissing && $evaluationId > 0) {
    $evaluation = dss_fetch_evaluation($pdo, $evaluationId);
    if (!$evaluation) {
        http_response_code(404);
        $error = 'Evaluasi tidak ditemukan.';
    }
}
if (!$schemaMissing && $evaluation && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        if (!dss_can_edit($evaluation) && !in_array($_POST['action'] ?? '', ['approve'], true)) {
            throw new RuntimeException('Evaluasi yang sudah disetujui atau dibatalkan tidak dapat diubah.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_weights') {
            $weights = $_POST['weight_percent'] ?? [];
            if (!is_array($weights)) throw new RuntimeException('Format bobot tidak valid.');
            $criteriaCurrent = dss_evaluation_criteria($pdo, $evaluationId);
            if (count($weights) !== count($criteriaCurrent)) throw new RuntimeException('Data bobot tidak lengkap. Muat ulang halaman dan coba lagi.');
            $save = [];
            $total = 0.0;
            foreach ($criteriaCurrent as $criterion) {
                $criterionId = (int)$criterion['id'];
                if (!array_key_exists($criterionId, $weights)) throw new RuntimeException('Bobot kriteria tidak lengkap.');
                $weight = filter_var($weights[$criterionId], FILTER_VALIDATE_FLOAT);
                if ($weight === false || $weight < 0 || $weight > 100) throw new RuntimeException('Bobot setiap kriteria harus berada pada rentang 0–100%.');
                $save[$criterionId] = (float)$weight;
                $total += (float)$weight;
            }
            if (abs($total - 100.0) > 0.02) throw new RuntimeException('Total bobot harus berjumlah 100%. Total saat ini ' . dss_number($total) . '%.');
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE dss_evaluation_criteria SET weight_percent=? WHERE evaluation_id=? AND criterion_id=?');
                foreach ($save as $criterionId => $weight) $stmt->execute([$weight, $evaluationId, $criterionId]);
                dss_touch_draft($pdo, $evaluationId);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            dss_audit($pdo, (int)$admin['id'], 'update_weights', 'dss_evaluation_criteria', $evaluationId, null, ['weights'=>$save]);
            go('dss_evaluation.php?id=' . $evaluationId . '&weights_saved=1');
        }

        if ($action === 'add_candidate') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $offerId = (int)($_POST['supplier_product_id'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($supplierId < 1) throw new RuntimeException('Pilih supplier yang akan dievaluasi.');
            if (mb_strlen($notes) > 500) throw new RuntimeException('Catatan maksimal 500 karakter.');
            $supplierStmt = $pdo->prepare("SELECT id, status FROM suppliers WHERE id = ?"); $supplierStmt->execute([$supplierId]); $supplier = $supplierStmt->fetch();
            if (!$supplier || $supplier['status'] !== 'active') throw new RuntimeException('Supplier harus berstatus aktif untuk alternatif baru.');
            $offer = null;
            if ($offerId > 0) {
                if ($evaluation['target_variation_id'] === null) throw new RuntimeException('Penawaran harga hanya dapat dipakai pada evaluasi yang memiliki SKU target. Untuk evaluasi umum, isi nilai manual atau buat evaluasi baru dengan SKU yang dipilih.');
                $offerStmt = $pdo->prepare("SELECT sp.*, s.status AS supplier_status FROM supplier_products sp JOIN suppliers s ON s.id=sp.supplier_id WHERE sp.id=? AND sp.is_active=1 AND (sp.quote_valid_until IS NULL OR sp.quote_valid_until >= CURDATE())");
                $offerStmt->execute([$offerId]); $offer = $offerStmt->fetch();
                if (!$offer) throw new RuntimeException('Penawaran tidak ditemukan atau tidak aktif.');
                if ((int)$offer['supplier_id'] !== $supplierId) throw new RuntimeException('Penawaran yang dipilih tidak sesuai dengan supplier.');
                if ($evaluation['target_variation_id'] !== null && (int)$offer['variation_id'] !== (int)$evaluation['target_variation_id']) throw new RuntimeException('Penawaran harus cocok dengan SKU target evaluasi.');
            }
            if (in_array($evaluation['status'], ['approved','cancelled'], true)) throw new RuntimeException('Evaluasi ini tidak dapat diubah lagi.');
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare("INSERT INTO dss_evaluation_candidates (evaluation_id, supplier_id, supplier_product_id, candidate_status, notes) VALUES (?, ?, ?, 'included', ?)");
                $insert->execute([$evaluationId, $supplierId, $offerId ?: null, $notes ?: null]);
                $candidateId = (int)$pdo->lastInsertId();
                dss_seed_offer_scores($pdo, $candidateId, $supplierId, $offer ?: null, (int)$admin['id']);
                dss_touch_draft($pdo, $evaluationId);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            dss_audit($pdo, (int)$admin['id'], 'add_candidate', 'dss_evaluation_candidates', $candidateId, null, ['evaluation_id'=>$evaluationId,'supplier_id'=>$supplierId,'supplier_product_id'=>$offerId ?: null]);
            go('dss_evaluation.php?id=' . $evaluationId . '&candidate_added=1');
        }

        if ($action === 'save_scores') {
            $postedScores = $_POST['scores'] ?? [];
            if (!is_array($postedScores)) throw new RuntimeException('Format nilai kriteria tidak valid.');
            $criteriaCurrent = dss_evaluation_criteria($pdo, $evaluationId);
            $candidateStmt = $pdo->prepare('SELECT id FROM dss_evaluation_candidates WHERE evaluation_id=?');
            $candidateStmt->execute([$evaluationId]);
            $candidateIds = array_map('intval', array_column($candidateStmt->fetchAll(), 'id'));
            $currentScores = dss_evaluation_scores_map($pdo, $candidateIds);
            $scoreUpsert = $pdo->prepare("INSERT INTO dss_evaluation_scores (candidate_id, criterion_id, raw_value, input_source, created_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE raw_value=VALUES(raw_value), input_source=VALUES(input_source), created_by=VALUES(created_by), updated_at=CURRENT_TIMESTAMP");
            $changed = 0;
            $pdo->beginTransaction();
            try {
                foreach ($candidateIds as $candidateId) {
                    foreach ($criteriaCurrent as $criterion) {
                        $criterionId = (int)$criterion['id'];
                        $raw = $postedScores[$candidateId][$criterionId] ?? '';
                        if (is_array($raw)) throw new RuntimeException('Nilai kriteria tidak valid.');
                        $raw = trim((string)$raw);
                        if ($raw === '') continue;
                        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
                        if ($value === false || $value < 0 || !is_finite((float)$value)) throw new RuntimeException('Semua nilai harus berupa angka valid dan tidak negatif. Kriteria: ' . $criterion['criterion_name'] . '.');
                        if ($criterion['value_type'] === 'percentage' && $value > 100) throw new RuntimeException('Nilai persentase untuk ' . $criterion['criterion_name'] . ' tidak boleh melebihi 100.');
                        if ($criterion['value_type'] === 'rating' && ($value < 1 || $value > 5)) throw new RuntimeException('Rating untuk ' . $criterion['criterion_name'] . ' harus berada pada skala 1–5.');
                        $existing = $currentScores[$candidateId][$criterionId] ?? null;
                        $source = 'manual';
                        if ($existing && abs((float)$existing['raw_value'] - (float)$value) < 0.00005) $source = $existing['input_source'];
                        $scoreUpsert->execute([$candidateId, $criterionId, $value, $source, (int)$admin['id']]);
                        $changed++;
                    }
                }
                dss_touch_draft($pdo, $evaluationId);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            dss_audit($pdo, (int)$admin['id'], 'save_scores', 'dss_evaluation_scores', $evaluationId, null, ['saved_fields'=>$changed]);
            go('dss_evaluation.php?id=' . $evaluationId . '&scores_saved=1');
        }

        if ($action === 'refresh_sources') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, supplier_id, supplier_product_id FROM dss_evaluation_candidates WHERE id=? AND evaluation_id=?');
            $stmt->execute([$candidateId, $evaluationId]);
            $candidate = $stmt->fetch();
            if (!$candidate) throw new RuntimeException('Alternatif supplier tidak ditemukan.');
            $offer = null;
            if ($candidate['supplier_product_id']) {
                $offerStmt = $pdo->prepare('SELECT * FROM supplier_products WHERE id=? AND is_active=1 AND (quote_valid_until IS NULL OR quote_valid_until >= CURDATE())');
                $offerStmt->execute([(int)$candidate['supplier_product_id']]);
                $offer = $offerStmt->fetch() ?: null;
            }
            $pdo->beginTransaction();
            try {
                dss_seed_offer_scores($pdo, (int)$candidate['id'], (int)$candidate['supplier_id'], $offer, (int)$admin['id']);
                dss_touch_draft($pdo, $evaluationId);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            dss_audit($pdo, (int)$admin['id'], 'refresh_sources', 'dss_evaluation_scores', $candidateId, null, ['evaluation_id'=>$evaluationId]);
            go('dss_evaluation.php?id=' . $evaluationId . '&sources_refreshed=1');
        }

        if ($action === 'toggle_candidate') {
            $candidateId = (int)($_POST['candidate_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, candidate_status, supplier_id FROM dss_evaluation_candidates WHERE id=? AND evaluation_id=?'); $stmt->execute([$candidateId,$evaluationId]); $candidate = $stmt->fetch();
            if (!$candidate) throw new RuntimeException('Alternatif supplier tidak ditemukan.');
            $newStatus = $candidate['candidate_status'] === 'included' ? 'excluded' : 'included';
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE dss_evaluation_candidates SET candidate_status=? WHERE id=?')->execute([$newStatus,$candidateId]);
                dss_touch_draft($pdo, $evaluationId);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            dss_audit($pdo, (int)$admin['id'], 'toggle_candidate', 'dss_evaluation_candidates', $candidateId, ['candidate_status'=>$candidate['candidate_status']], ['candidate_status'=>$newStatus]);
            go('dss_evaluation.php?id=' . $evaluationId . '&candidate_status=1');
        }

        if ($action === 'calculate') {
            if (!dss_can_edit($evaluation)) throw new RuntimeException('Evaluasi tidak dapat dihitung ulang karena sudah disetujui atau dibatalkan.');
            $calculated = dss_calculate_saw($pdo, $evaluationId);
            dss_audit($pdo, (int)$admin['id'], 'calculate_saw', 'dss_evaluation_results', $evaluationId, null, ['method'=>'SAW','candidate_count'=>count($calculated),'winner'=>$calculated[0]['candidate']['supplier_name'] ?? null]);
            go('dss_evaluation.php?id=' . $evaluationId . '&calculated=1');
        }

        if ($action === 'approve') {
            if ($evaluation['status'] !== 'evaluated') throw new RuntimeException('Hitung evaluasi terlebih dahulu sebelum memberikan persetujuan.');
            $notes = trim((string)($_POST['decision_notes'] ?? ''));
            if (mb_strlen($notes) > 6000) throw new RuntimeException('Catatan keputusan maksimal 6.000 karakter.');
            $winner = $pdo->prepare("SELECT s.name FROM dss_evaluation_results r JOIN dss_evaluation_candidates c ON c.id=r.candidate_id JOIN suppliers s ON s.id=c.supplier_id WHERE c.evaluation_id=? AND r.is_recommended=1 LIMIT 1");
            $winner->execute([$evaluationId]);
            $winnerName = $winner->fetchColumn();
            if (!$winnerName) throw new RuntimeException('Hasil rekomendasi belum tersedia. Hitung ulang evaluasi.');
            $pdo->prepare("UPDATE dss_evaluations SET status='approved', decision_notes=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='evaluated'")->execute([$notes ?: null,(int)$admin['id'],$evaluationId]);
            dss_audit($pdo, (int)$admin['id'], 'approve', 'dss_evaluations', $evaluationId, ['status'=>'evaluated'], ['status'=>'approved','recommended_supplier'=>$winnerName,'decision_notes'=>$notes]);
            if (!empty($evaluation['target_variation_id'])) { require_once __DIR__ . '/es_engine.php'; es_fire_event($pdo, 'dss.approved', ['variation' => [(int)$evaluation['target_variation_id']]], (int)$admin['id']); }
            go('dss_evaluation.php?id=' . $evaluationId . '&approved=1');
        }
        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'uq_dss_eval_supplier')) {
            $error = 'Supplier ini sudah menjadi alternatif dalam evaluasi tersebut.';
        } else {
            $error = $e->getMessage();
        }
    }
}

// Muat ulang evaluasi agar status dan hasil mengikuti database setelah aksi.
if (!$schemaMissing && $evaluationId > 0) {
    $evaluation = dss_fetch_evaluation($pdo, $evaluationId);
    if ($evaluation) {
        $criteria = dss_evaluation_criteria($pdo, $evaluationId);
        $candidates = dss_evaluation_candidates($pdo, $evaluationId);
        $candidateIds = array_map(static fn(array $candidate): int => (int)$candidate['id'], $candidates);
        $scoresMap = dss_evaluation_scores_map($pdo, $candidateIds);
        $resultStmt = $pdo->prepare("SELECT r.*, c.supplier_id, c.candidate_status, s.name AS supplier_name, s.supplier_code
            FROM dss_evaluation_results r JOIN dss_evaluation_candidates c ON c.id=r.candidate_id
            JOIN suppliers s ON s.id=c.supplier_id WHERE c.evaluation_id=? ORDER BY r.rank_position");
        $resultStmt->execute([$evaluationId]);
        $results = $resultStmt->fetchAll();
        foreach ($results as &$result) {
            $result['details_array'] = json_decode((string)($result['calculation_details'] ?? '[]'), true) ?: [];
        }
        unset($result);
        $suppliers = $pdo->query("SELECT id, supplier_code, name, payment_terms_days FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();
        if ($evaluation['target_variation_id'] !== null) {
            $offerStmt = $pdo->prepare("SELECT sp.id, sp.supplier_id, sp.purchase_price, sp.minimum_order_qty, sp.lead_time_days,
                    sp.shipping_cost, s.supplier_code, s.name AS supplier_name
                FROM supplier_products sp JOIN suppliers s ON s.id=sp.supplier_id
                WHERE sp.variation_id=? AND sp.is_active=1 AND (sp.quote_valid_until IS NULL OR sp.quote_valid_until >= CURDATE()) AND s.status='active' ORDER BY s.name");
            $offerStmt->execute([(int)$evaluation['target_variation_id']]);
            $offers = $offerStmt->fetchAll();
        } else {
            // Hindari perbandingan harga lintas-SKU dalam evaluasi umum.
            $offers = [];
        }
    }
}

if (isset($_GET['weights_saved'])) $success = 'Bobot evaluasi berhasil disimpan. Hasil sebelumnya dihapus agar tidak menggunakan bobot lama.';
if (isset($_GET['candidate_added'])) $success = 'Alternatif supplier ditambahkan. Nilai yang tersedia dari penawaran/riwayat juga telah diisikan sebagai nilai awal.';
if (isset($_GET['scores_saved'])) $success = 'Nilai kriteria berhasil disimpan. Evaluasi perlu dihitung ulang untuk memperbarui peringkat.';
if (isset($_GET['candidate_status'])) $success = 'Status alternatif diperbarui. Hitung ulang untuk memperbarui peringkat.';
if (isset($_GET['sources_refreshed'])) $success = 'Nilai dari penawaran dan riwayat pengadaan diperbarui. Nilai yang sudah diubah manual tetap dipertahankan.';
if (isset($_GET['calculated'])) $success = 'Perhitungan SAW selesai. Periksa hasil dan rincian normalisasi di bawah.';
if (isset($_GET['approved'])) $success = 'Rekomendasi berhasil disetujui dan keputusan dicatat.';

include __DIR__ . '/layout_top.php';
?>
<section class="page-header"><div><div class="eyebrow">DSS · WORKSPACE EVALUASI</div><h1><?= e($evaluation['title'] ?? 'Evaluasi tidak ditemukan') ?></h1><p class="muted">Kode <?= e($evaluation['evaluation_code'] ?? '—') ?> · <?= e($evaluation['method'] ?? 'SAW') ?> · Tanggal <?= $evaluation ? e(date('d M Y', strtotime($evaluation['evaluation_date']))) : '—' ?></p></div><div class="page-actions"><a class="btn ghost" href="dss_dashboard.php">← Semua evaluasi</a><a class="btn secondary" href="dss_suppliers.php">Supplier</a></div></section>
<?php if ($schemaMissing): ?><div class="alert error">Impor <code>sql/01_schema_main.sql</code> ke database <code>db_neo_apparel</code> terlebih dahulu. Tabel belum tersedia: <?= e(implode(', ', $schemaMissing)) ?>.</div>
<?php elseif (!$evaluation): ?><div class="alert error"><?= e($error ?: 'Evaluasi tidak ditemukan.') ?></div>
<?php else: ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
<?php $canEdit = dss_can_edit($evaluation); $weightTotal = array_sum(array_map(static fn(array $c): float => (float)$c['weight_percent'], $criteria)); $targetLabel = $evaluation['product_name'] ? $evaluation['product_name'].' · '.$evaluation['sku'].(trim((string)$evaluation['color'].' '.(string)$evaluation['size'])!==''?' · '.trim((string)$evaluation['color'].' '.(string)$evaluation['size']):'') : 'Supplier umum'; ?>
<section class="dss-eval-summary">
    <article class="dss-eval-summary-main"><span class="dss-eval-icon">◎</span><div><small>PRODUK / SKU TARGET</small><h2><?= e($targetLabel) ?></h2><p><?= $evaluation['created_by'] ? 'Dibuat oleh ' . e($evaluation['creator_name'] ?? 'Administrator') : 'Tidak ada pembuat tercatat' ?><?= $evaluation['approved_at'] ? ' · Disetujui ' . e(date('d M Y H:i', strtotime($evaluation['approved_at']))) . ' oleh ' . e($evaluation['approver_name'] ?? 'Administrator') : '' ?></p></div></article>
    <article class="dss-eval-status-card"><span>Status evaluasi</span><strong><span class="dss-status <?= e(dss_status_class($evaluation['status'])) ?>"><?= e(dss_status_label($evaluation['status'])) ?></span></strong><small><?= count(array_filter($candidates,static fn(array $c):bool=>$c['candidate_status']==='included')) ?> alternatif diikutkan dari <?= count($candidates) ?> supplier</small></article>
</section>
<?php if (!$canEdit): ?><div class="dss-locked-note"><strong>Evaluasi terkunci.</strong> Status <?= e(dss_status_label($evaluation['status'])) ?> membuat bobot, alternatif, dan nilai tidak dapat diubah lagi. Untuk koreksi, buat evaluasi baru agar jejak keputusan tetap utuh.</div><?php endif; ?>

<section class="dss-evaluation-grid">
    <article class="panel-card"><div class="panel-heading"><div><h2>1. Atur bobot kriteria</h2><p>Bobot salinan khusus evaluasi ini. Total wajib tepat 100% sebelum perhitungan.</p></div><span class="dss-step-badge">01</span></div>
        <form method="post" id="dssWeightForm"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_weights">
        <div class="dss-weight-list"><?php foreach($criteria as $criterion): ?><label class="dss-weight-row"><span><strong><?= e($criterion['criterion_name']) ?></strong><small><?= e($criterion['description'] ?? '') ?> · <?= $criterion['criterion_type']==='cost'?'Cost · lebih kecil lebih baik':'Benefit · lebih besar lebih baik' ?></small></span><span class="dss-weight-input"><input class="weight-input" type="number" name="weight_percent[<?= (int)$criterion['id'] ?>]" min="0" max="100" step="0.01" value="<?= e(number_format((float)$criterion['weight_percent'],2,'.','')) ?>" <?= $canEdit?'':'disabled' ?>><em>%</em></span></label><?php endforeach; ?></div>
        <div class="dss-weight-total"><span>Total bobot</span><strong id="dssWeightTotal"><?= dss_number($weightTotal) ?>%</strong><span id="dssWeightStatus" class="<?= abs($weightTotal-100)<0.02?'is-valid':'is-invalid' ?>"><?= abs($weightTotal-100)<0.02?'Siap dihitung':'Harus tepat 100%' ?></span></div>
        <?php if($canEdit): ?><div class="dss-form-actions"><button type="submit" class="btn ghost">Simpan bobot</button></div><?php endif; ?>
        </form>
    </article>

    <article class="panel-card"><div class="panel-heading"><div><h2>2. Tambahkan alternatif supplier</h2><p>Pilih supplier aktif. Untuk evaluasi SKU tertentu, gunakan penawaran yang cocok dengan SKU target.</p></div><span class="dss-step-badge">02</span></div>
        <?php if($canEdit): ?><form method="post" class="dss-form-grid dss-add-candidate"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_candidate">
            <div class="dss-field full"><label for="supplier_id">Supplier <span>*</span></label><select name="supplier_id" id="supplier_id" required><option value="">Pilih supplier aktif</option><?php foreach($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>"><?= e($supplier['supplier_code'].' · '.$supplier['name']) ?></option><?php endforeach; ?></select></div>
            <div class="dss-field full"><label for="supplier_product_id">Penawaran terkait</label><select name="supplier_product_id" id="supplier_product_id"><option value="0">Tanpa penawaran · isi nilai manual</option><?php foreach($offers as $offer): $offerLabel = ($offer['supplier_code'].' · '.$offer['supplier_name'].' — '.dss_money($offer['purchase_price']).', MOQ '.(int)$offer['minimum_order_qty'].', '.(int)$offer['lead_time_days'].' hari'); if(!empty($offer['product_name'])) $offerLabel = $offer['product_name'].' / '.$offer['sku'].' · '.$offerLabel; ?><option value="<?= (int)$offer['id'] ?>" data-supplier="<?= (int)$offer['supplier_id'] ?>"><?= e($offerLabel) ?></option><?php endforeach; ?></select><small><?= $evaluation['target_variation_id']!==null?'Daftar penawaran difilter ke SKU target.':'Evaluasi umum tidak mengisi harga otomatis; gunakan nilai yang sebanding.' ?></small></div>
            <div class="dss-field full"><label for="candidate_notes">Catatan alternatif</label><input name="notes" id="candidate_notes" maxlength="500" placeholder="Contoh: bersedia menyediakan sampel"></div>
            <div class="dss-field full"><button class="btn primary" type="submit">Tambah alternatif supplier</button></div>
        </form><?php else: ?><div class="dss-empty-inline">Alternatif terkunci setelah keputusan disetujui.</div><?php endif; ?>
        <?php if(!$suppliers): ?><div class="dss-inline-warning">Belum ada supplier aktif. <a href="dss_suppliers.php">Tambahkan supplier terlebih dahulu.</a></div><?php endif; ?>
    </article>
</section>

<section class="panel-card dss-table-panel"><div class="panel-heading"><div><h2>3. Nilai kriteria setiap alternatif</h2><p>Masukkan data numerik asli, misalnya harga dalam rupiah, lead time dalam hari, persentase penerimaan 0–100, dan rating layanan 1–5.</p></div><span class="dss-table-count"><?= count($candidates) ?> supplier</span></div>
<?php if($candidates && $criteria): ?>
<div class="dss-score-candidates">
<?php foreach($candidates as $candidate): $candidateId=(int)$candidate['id']; $candidateIncluded=$candidate['candidate_status']==='included'; ?>
    <article class="dss-candidate-card <?= $candidateIncluded?'':'is-excluded' ?>">
        <div class="dss-candidate-heading"><div class="dss-candidate-id"><span class="dss-rank-mark"><?= $candidateIncluded?'✓':'–' ?></span><div><h3><?= e($candidate['supplier_name']) ?></h3><small><?= e($candidate['supplier_code']) ?><?php if($candidate['supplier_product_id']): ?> · Penawaran <?= dss_money($candidate['purchase_price']) ?> / unit<?php endif; ?></small></div></div><div class="dss-candidate-tools"><span class="dss-status <?= e(dss_status_class($candidate['candidate_status'])) ?>"><?= e(dss_status_label($candidate['candidate_status'])) ?></span><?php if($canEdit): ?><?php if($candidateIncluded): ?><form method="post" onsubmit="return confirm('Perbarui nilai otomatis dari penawaran dan riwayat pengadaan? Nilai manual yang sudah diedit tetap dipertahankan. Hasil lama akan dihapus.');"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="refresh_sources"><input type="hidden" name="candidate_id" value="<?= $candidateId ?>"><button type="submit" class="btn-sm">Perbarui data</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Ubah status supplier ini? Hasil penilaian sebelumnya akan dihapus.');"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle_candidate"><input type="hidden" name="candidate_id" value="<?= $candidateId ?>"><button type="submit" class="btn-sm <?= $candidateIncluded?'danger':'' ?>"><?= $candidateIncluded?'Kecualikan':'Ikutkan lagi' ?></button></form><?php endif; ?></div></div>
        <?php if($candidate['notes']): ?><p class="dss-candidate-note"><?= e($candidate['notes']) ?></p><?php endif; ?>
        <form method="post" class="dss-candidate-scores-form"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="save_scores">
        <div class="dss-score-grid">
            <?php foreach($criteria as $criterion): $criterionId=(int)$criterion['id']; $saved=$scoresMap[$candidateId][$criterionId]??null; $value=$saved['raw_value']??''; $source=$saved['input_source']??'Belum diisi'; $sourceLabel=['supplier_offer'=>'Dari penawaran','purchase_history'=>'Dari riwayat','manual'=>'Input admin','Belum diisi'=>'Belum diisi'][$source]??$source; $minValue=$criterion['value_type']==='rating'?1:0; $maxValue=$criterion['value_type']==='rating'?5:($criterion['value_type']==='percentage'?100:null); ?>
                <div class="dss-score-field"><label for="score_<?= $candidateId ?>_<?= $criterionId ?>"><?= e($criterion['criterion_name']) ?><span class="dss-type-tag <?= e($criterion['criterion_type']) ?>"><?= $criterion['criterion_type']==='cost'?'Cost':'Benefit' ?></span></label><div class="dss-score-input-wrap"><input id="score_<?= $candidateId ?>_<?= $criterionId ?>" type="number" min="<?= $minValue ?>" <?= $maxValue!==null?'max="'.e((string)$maxValue).'"':'' ?> step="0.0001" name="scores[<?= $candidateId ?>][<?= $criterionId ?>]" value="<?= $value!==''?e(number_format((float)$value,4,'.','')):'' ?>" placeholder="Isi nilai" <?= ($canEdit && $candidateIncluded)?'':'disabled' ?>></div><small class="dss-source-label <?= $saved?'':'missing' ?>"><?= e($sourceLabel) ?></small></div>
            <?php endforeach; ?>
        </div>
        <?php if($canEdit && $candidateIncluded): ?><div class="dss-candidate-save"><span>Nilai kosong dibiarkan tanpa perubahan.</span><button class="btn ghost" type="submit">Simpan nilai supplier</button></div><?php endif; ?>
        </form>
    </article>
<?php endforeach; ?>
</div>
<?php else: ?><div class="dss-empty"><span>＋</span><strong>Belum ada alternatif supplier</strong><p>Tambahkan minimal dua supplier aktif untuk membandingkan nilai dan menghitung rekomendasi.</p></div><?php endif; ?>
</section>

<section class="panel-card dss-calculate-panel"><div><span class="dss-step-badge">04</span><h2>4. Hitung rekomendasi SAW</h2><p>Normalisasi cost/benefit dan penjumlahan berbobot dilakukan dari nilai yang tersimpan. Setiap perubahan bobot, nilai, atau alternatif menghapus hasil lama dan mengembalikan evaluasi ke draf.</p></div><?php if($canEdit): ?><form method="post" onsubmit="return confirm('Hitung peringkat menggunakan bobot dan nilai yang tersimpan?');"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="calculate"><button type="submit" class="btn primary" <?= count(array_filter($candidates,static fn(array $c):bool=>$c['candidate_status']==='included'))<2?'disabled':'' ?>>Hitung SAW <span aria-hidden="true">→</span></button></form><?php elseif($evaluation['status']==='approved'): ?><span class="dss-status success">Keputusan disetujui</span><?php endif; ?></section>

<?php if($results): $bestScore=max(array_map(static fn(array $r):float=>(float)$r['total_score'],$results)); ?>
<section class="panel-card dss-results-panel"><div class="panel-heading"><div><div class="eyebrow">HASIL PERHITUNGAN · SAW-1.0</div><h2>Peringkat supplier</h2><p>Skor akhir merupakan hasil penjumlahan nilai normalisasi yang dikalikan bobot kriteria. Skor mendekati 1 berarti lebih dekat ke alternatif terbaik dalam kumpulan ini.</p></div><span class="dss-status <?= e(dss_status_class($evaluation['status'])) ?>"><?= e(dss_status_label($evaluation['status'])) ?></span></div>
    <div class="dss-results-highlight"><span class="dss-winner-medal">★</span><div><small>REKOMENDASI BERDASARKAN SAW</small><h3><?= e($results[0]['supplier_name']) ?></h3><p><?= e($results[0]['supplier_code']) ?> · Skor <?= dss_number($results[0]['total_score'],4) ?> dari maksimum teoritis 1,0000.</p></div><div class="dss-winner-score"><?= dss_number((float)$results[0]['total_score']*100,2) ?><small>poin</small></div></div>
    <div class="dss-ranking-chart"><?php foreach($results as $result): $width=$bestScore>0?((float)$result['total_score']/$bestScore)*100:0; ?><div class="dss-ranking-row"><div class="dss-ranking-label"><span class="dss-ranking-number <?= (int)$result['rank_position']===1?'first':'' ?>"><?= (int)$result['rank_position'] ?></span><strong><?= e($result['supplier_name']) ?></strong><b><?= dss_number($result['total_score'],4) ?></b></div><div class="dss-ranking-track"><span class="<?= (int)$result['rank_position']===1?'winner':'' ?>" style="width:<?= e(number_format($width,2,'.','')) ?>%"></span></div></div><?php endforeach; ?></div>
    <div class="table-wrap"><table class="data-table dss-table"><thead><tr><th>Peringkat</th><th>Supplier</th><th>Skor total</th><th>Skor poin</th><th>Status</th><th>Rincian perhitungan</th></tr></thead><tbody><?php foreach($results as $result): ?><tr><td><span class="dss-ranking-number <?= (int)$result['rank_position']===1?'first':'' ?>">#<?= (int)$result['rank_position'] ?></span></td><td><strong><?= e($result['supplier_name']) ?></strong><span class="table-secondary"><?= e($result['supplier_code']) ?></span></td><td><strong><?= dss_number($result['total_score'],6) ?></strong></td><td><?= dss_number((float)$result['total_score']*100,2) ?></td><td><?php if($result['is_recommended']): ?><span class="dss-status success">Rekomendasi</span><?php else: ?><span class="dss-status neutral">Alternatif</span><?php endif; ?></td><td><details class="dss-details"><summary>Lihat rincian</summary><div class="table-wrap"><table class="data-table dss-detail-table"><thead><tr><th>Kriteria</th><th>Nilai asli</th><th>Normalisasi</th><th>Bobot</th><th>Kontribusi</th></tr></thead><tbody><?php foreach($result['details_array'] as $detail): ?><tr><td><?= e($detail['criterion_name']??'') ?><span class="table-secondary"><?= ($detail['type']??'')==='cost'?'Cost':'Benefit' ?></span></td><td><?= dss_number($detail['raw_value']??0,4) ?></td><td><?= dss_number($detail['normalized_value']??0,6) ?></td><td><?= dss_number($detail['weight_percent']??0,2) ?>%</td><td><?= dss_number($detail['weighted_score']??0,6) ?></td></tr><?php endforeach; ?></tbody></table></div></details></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="dss-calculation-note"><strong>Catatan interpretasi.</strong> SAW merangking alternatif relatif terhadap supplier yang diikutkan di evaluasi ini. Mengubah kumpulan alternatif atau bobot dapat mengubah skor. Nilai kualitas dan layanan yang dimasukkan manual sebaiknya diberi dasar penilaian konsisten.</div>
</section>
<?php if($evaluation['status']==='evaluated'): ?>
<section class="panel-card dss-approve-panel"><div><div class="eyebrow">TINJAUAN ADMINISTRATOR</div><h2>Setujui keputusan</h2><p>Pastikan nilai sumber, bobot dan penjelasan peringkat sudah ditinjau. Persetujuan akan mengunci evaluasi agar riwayat keputusan tidak berubah.</p></div><form method="post" class="dss-approve-form" onsubmit="return confirm('Setujui rekomendasi dan kunci evaluasi ini? Setelah disetujui, data tidak dapat diedit.');"><input type="hidden" name="csrf_token" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="approve"><label for="decision_notes">Catatan keputusan (opsional)</label><textarea name="decision_notes" id="decision_notes" rows="3" maxlength="6000" placeholder="Alasan persetujuan, syarat, atau catatan tindak lanjut..."><?= e($evaluation['decision_notes'] ?? '') ?></textarea><button type="submit" class="btn success">Setujui rekomendasi</button></form></section>
<?php elseif($evaluation['status']==='approved'): ?><section class="panel-card dss-approved-note"><div class="dss-approved-icon">✓</div><div><h2>Keputusan disetujui</h2><p><?= nl2br(e($evaluation['decision_notes'] ?: 'Tidak ada catatan keputusan.')) ?></p><small>Disetujui pada <?= e(date('d M Y H:i', strtotime($evaluation['approved_at']))) ?> oleh <?= e($evaluation['approver_name'] ?? 'Administrator') ?>.</small></div></section><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<script>
(function(){
  const weightInputs=[...document.querySelectorAll('.weight-input')];
  const totalEl=document.getElementById('dssWeightTotal');
  const statusEl=document.getElementById('dssWeightStatus');
  function updateWeights(){let sum=weightInputs.reduce((n,input)=>n+(parseFloat(input.value)||0),0);if(totalEl)totalEl.textContent=sum.toLocaleString('id-ID',{minimumFractionDigits:2,maximumFractionDigits:2})+'%';if(statusEl){const okay=Math.abs(sum-100)<0.02;statusEl.textContent=okay?'Siap dihitung':'Harus tepat 100%';statusEl.className=okay?'is-valid':'is-invalid';}}
  weightInputs.forEach(input=>input.addEventListener('input',updateWeights));
  const supplierSelect=document.getElementById('supplier_id');const offerSelect=document.getElementById('supplier_product_id');
  offerSelect?.addEventListener('change',function(){const option=this.options[this.selectedIndex];const supplierId=option?.dataset?.supplier;if(supplierId&&supplierSelect){supplierSelect.value=supplierId;}});
})();
</script>
<?php include __DIR__ . '/layout_bottom.php'; ?>
