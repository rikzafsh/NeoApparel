<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
require_once 'es_actions.php';
require_once 'kms_functions.php';
$u = need_role($pdo, ['seller', 'staff', 'manager', 'admin']);
$uid = (int)$u['id'];
$canAct = has_any(['staff', 'manager', 'admin']);
$id = (int)($_GET['id'] ?? 0);
$s = $pdo->prepare('SELECT i.*, r.actions rule_actions, r.kms_keywords, r.description rule_desc, r.name rule_name, r.priority rule_priority, r.cf rule_cf FROM es_inferences i LEFT JOIN es_rules r ON r.id = i.rule_id WHERE i.id = ?');
$s->execute([$id]); $inf = $s->fetch();
if (!$inf) { flash('error', 'Kesimpulan tidak ditemukan.'); go('es_dashboard.php'); }
$ruleActions = $inf['rule_actions'] ? (json_decode($inf['rule_actions'], true) ?: []) : [];

$LABELS = ['oas_task' => ['Buat tugas (OAS)', 'check'], 'oas_notify' => ['Kirim notifikasi (OAS)', 'bell'], 'dss_start_evaluation' => ['Mulai evaluasi supplier (DSS)', 'truck'],
           'kms_request' => ['Ajukan permintaan pengetahuan (KMS)', 'help'], 'kms_save_lesson' => ['Simpan sebagai pembelajaran (KMS)', 'book'], 'tps_cancel_unpaid_order' => ['Batalkan pesanan & kembalikan stok (TPS)', 'x']];

// entitas aktual untuk konteks eksekusi tindakan manual
function es_rebuild_inference_ctx(PDO $pdo, array $inf): array
{
    $facts = json_decode((string)$inf['facts'], true) ?: [];
    $ent = ['type' => $inf['subject_type'], 'id' => (int)$inf['subject_id'], 'label' => $inf['subject_label'], 'facts' => $facts];
    if ($inf['subject_type'] === 'variation') {   // pakai fakta terkini
        $cur = es_collect_variations($pdo, es_params($pdo), [(int)$inf['subject_id']]);
        if ($cur) { $ent = $cur[0]; }
    }
    return ['id' => (int)$inf['id'], 'title' => $inf['title'], 'message' => $inf['message'], 'recommendation' => $inf['recommendation'], 'severity' => $inf['severity'],
        'entity' => $ent, 'rule' => ['name' => $inf['rule_name'], 'kms_keywords' => $inf['kms_keywords']]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canAct) {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'status') {
        $to = $_POST['to'] ?? ''; $note = mb_substr(trim($_POST['note'] ?? ''), 0, 480);
        if ($to === 'resolved') { $pdo->prepare("UPDATE es_inferences SET status='resolved', resolved_by=?, resolved_at=NOW(), resolution_note=? WHERE id=?")->execute([$uid, $note ?: 'Diselesaikan manual', $id]); flash('success', 'Kesimpulan ditandai selesai.'); }
        elseif ($to === 'acknowledged') { $pdo->prepare("UPDATE es_inferences SET status='acknowledged', assigned_to=COALESCE(assigned_to,?) WHERE id=?")->execute([$uid, $id]); flash('success', 'Ditandai sudah diketahui.'); }
        elseif ($to === 'open') { $pdo->prepare("UPDATE es_inferences SET status='open', resolved_at=NULL, resolved_by=NULL, resolution_note=NULL WHERE id=?")->execute([$id]); flash('info', 'Dibuka kembali.'); }
        elseif ($to === 'dismissed') { $pdo->prepare("UPDATE es_inferences SET status='dismissed', snoozed_until=DATE_ADD(NOW(), INTERVAL 7 DAY), resolved_by=?, resolved_at=NOW(), resolution_note=? WHERE id=?")->execute([$uid, $note ?: 'Diabaikan 7 hari', $id]); flash('success', 'Diabaikan selama 7 hari.'); }
        go('es_inference.php?id=' . $id);
    }
    if ($a === 'exec') {
        $type = $_POST['type'] ?? '';
        $act = null;
        foreach ($ruleActions as $ra) { if (($ra['type'] ?? '') === $type) { $act = $ra; break; } }
        if (!$act && in_array($type, ['oas_task', 'kms_request', 'kms_save_lesson', 'oas_notify'], true)) { $act = ['type' => $type]; }
        if (!$act) { flash('error', 'Tindakan tidak tersedia untuk kesimpulan ini.'); go('es_inference.php?id=' . $id); }
        if ($type === 'tps_cancel_unpaid_order' && !has_any(['staff', 'manager', 'admin'])) { flash('error', 'Akses ditolak.'); go('es_inference.php?id=' . $id); }
        $res = es_execute_action($pdo, es_rebuild_inference_ctx($pdo, $inf), $act, ['user_id' => $uid, 'note' => trim($_POST['note'] ?? '')]);
        flash($res['status'] === 'done' ? 'success' : ($res['status'] === 'failed' ? 'error' : 'warning'), $res['detail'] ?? 'Selesai.');
        go('es_inference.php?id=' . $id);
    }
}

$facts = json_decode((string)$inf['facts'], true) ?: [];
$trace = json_decode((string)$inf['trace'], true) ?: [];
$acts = $pdo->prepare('SELECT * FROM es_actions WHERE inference_id = ? ORDER BY id DESC'); $acts->execute([$id]); $acts = $acts->fetchAll();
$kmsItems = []; $kmsIds = [];
foreach ($acts as $a) { if ($a['ref_table'] === 'kms_knowledge_items' && $a['action_type'] === 'kms_lookup') { $kmsIds[] = (int)$a['ref_id']; } }
if ($kmsIds) {
    $k = es_kms();
    if ($k) {
        $in = implode(',', array_map('intval', $kmsIds));
        foreach ($k->query("SELECT id,title,summary,status,visibility FROM kms_knowledge_items WHERE id IN ($in)")->fetchAll() as $it) { if (kms_can_view($u, $it)) $kmsItems[] = $it; }
    }
}
$derivedKeys = [];
foreach ($trace as $t) { if (($t['type'] ?? '') === 'derived') { $derivedKeys[$t['fact']] = true; } }
$subjectLink = null;
if ($inf['subject_type'] === 'variation') { $subjectLink = ['products.php', 'Kelola produk']; }
elseif ($inf['subject_type'] === 'order') { $subjectLink = ['office.php?tab=orders', 'Buka operasional pesanan']; }
elseif ($inf['subject_type'] === 'supplier') { $subjectLink = ['dss_suppliers.php', 'Data supplier']; }
elseif ($inf['subject_type'] === 'global') { $subjectLink = ['mis_dashboard.php', 'Buka ringkasan MIS']; }
$pageTitle = 'Penjelasan kesimpulan #' . $id;
$pageKicker = APP_NAME . ' · expert system';
include 'layout_top.php';
?>
<div class="head"><div><a class="text-link" href="es_dashboard.php">← Pusat Pakar</a>
    <h1 style="margin-top:6px"><?= e($inf['title']) ?></h1>
    <div class="row"><span class="sev <?= e($inf['severity']) ?>"><?= e($inf['severity']) ?></span><span class="dom <?= e($inf['domain']) ?>"><?= e($inf['domain']) ?></span><?= badge($inf['status']) ?><span class="tag"><?= e($inf['subject_label']) ?></span>
        <span class="cf"><span class="bar"><i style="width:<?= (int)round($inf['certainty'] * 100) ?>%"></i></span>Keyakinan <?= (int)round($inf['certainty'] * 100) ?>%</span></div></div>
    <?php if ($subjectLink): ?><a class="btn ghost" href="<?= e($subjectLink[0]) ?>"><?= e($subjectLink[1]) ?></a><?php endif; ?></div>

<div class="grid-2" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);align-items:start">
<div>
    <div class="card"><h2 style="margin-top:0">Kesimpulan</h2><p><?= e($inf['message']) ?></p>
        <?php if ($inf['recommendation']): ?><div class="rec" style="background:#f4f1ff;border-radius:12px;padding:12px 14px"><b>Rekomendasi:</b> <?= e($inf['recommendation']) ?></div><?php endif; ?>
        <?php if ($inf['resolution_note']): ?><p class="muted small" style="margin-bottom:0">Catatan: <?= e($inf['resolution_note']) ?> <?= $inf['resolved_at'] ? '· ' . e(fmt_dt($inf['resolved_at'])) : '' ?></p><?php endif; ?></div>

    <div class="card"><h2 style="margin-top:0">Mengapa sistem menyimpulkan ini?</h2>
        <p class="muted small">Rantai penalaran (forward chaining). Fakta turunan dihasilkan oleh aturan derive, lalu dipakai aturan inferensi.</p>
        <ul class="trace">
            <?php foreach ($trace as $t): if ($t['type'] === 'derived'): ?>
                <li><b>Fakta turunan</b> <span class="mono"><?= e($t['fact']) ?> = <?= e((string)$t['value']) ?></span> oleh aturan <span class="mono"><?= e($t['rule']) ?></span> (<?= e($t['name']) ?>) · CF <?= number_format((float)$t['cf'], 2, ',', '') ?><?php if (!empty($t['also'])): ?> · diperkuat oleh <?= e(implode(', ', $t['also'])) ?><?php endif; ?></li>
            <?php else: ?>
                <li class="concl"><b>Kesimpulan</b> oleh aturan <span class="mono"><?= e($t['rule']) ?></span> (<?= e($t['name']) ?>) · CF akhir <?= number_format((float)$t['cf'], 2, ',', '') ?></li>
            <?php endif; endforeach; ?>
        </ul>
        <h3 style="font-size:13px;margin:6px 0 10px">Fakta yang dipakai</h3>
        <div class="fact-grid"><?php foreach ($facts as $k => $v): ?><div class="fact <?= isset($derivedKeys[$k]) ? 'derived' : '' ?>"><small><?= e($k) ?><?= isset($derivedKeys[$k]) ? ' · turunan' : '' ?></small><b><?= e(is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string)$v) ?></b></div><?php endforeach; ?></div>
        <?php if ($inf['rule_desc']): ?><p class="muted small" style="margin-top:12px"><b>Tentang aturan:</b> <?= e($inf['rule_desc']) ?> (prioritas <?= (int)$inf['rule_priority'] ?>, CF aturan <?= number_format((float)$inf['rule_cf'], 2, ',', '') ?>)</p><?php endif; ?>
    </div>
</div>

<aside>
    <?php if ($canAct): ?>
    <div class="card"><h2 style="margin-top:0">Tindakan</h2>
        <?php
        $manual = []; $seen = [];
        foreach ($ruleActions as $ra) { $t = $ra['type'] ?? ''; if (isset($LABELS[$t]) && !isset($seen[$t])) { $seen[$t] = 1; $manual[] = $t; } }
        foreach (['oas_task', 'kms_save_lesson'] as $t) { if (!isset($seen[$t])) { $manual[] = $t; } }
        ?>
        <div class="stack">
        <?php foreach ($manual as $t): if ($t === 'oas_notify') continue; ?>
            <form method="post" data-confirm="<?= $t === 'tps_cancel_unpaid_order' ? 'Batalkan pesanan ini dan kembalikan stok?' : '' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="exec"><input type="hidden" name="type" value="<?= e($t) ?>">
                <?php if ($t === 'kms_save_lesson'): ?><input name="note" placeholder="Langkah penyelesaian (opsional)" style="margin-bottom:6px"><?php endif; ?>
                <button class="btn <?= $t === 'tps_cancel_unpaid_order' ? 'danger' : 'ghost' ?>" style="width:100%;justify-content:flex-start"><?= icon($LABELS[$t][1], 'sm') ?> <?= e($LABELS[$t][0]) ?></button></form>
        <?php endforeach; ?>
        </div>
        <hr style="border:0;border-top:1px solid var(--line);margin:14px 0">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status">
            <input name="note" placeholder="Catatan penyelesaian (opsional)">
            <div class="action-row">
                <?php if ($inf['status'] === 'open'): ?><button class="btn secondary sm" name="to" value="acknowledged">Sudah tahu</button><?php endif; ?>
                <?php if (!in_array($inf['status'], ['resolved', 'dismissed'], true)): ?><button class="btn success sm" name="to" value="resolved">Selesai</button><button class="btn ghost sm" name="to" value="dismissed">Abaikan 7 hari</button><?php else: ?><button class="btn ghost sm" name="to" value="open">Buka kembali</button><?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card"><h2 style="margin-top:0">Jejak integrasi</h2>
        <?php $shown = 0; foreach ($acts as $a): if ($a['action_type'] === 'kms_lookup') continue; $shown++; ?>
            <div class="link-card"><span class="ic"><?= icon(['OAS' => 'check', 'DSS' => 'truck', 'KMS' => 'book', 'TPS' => 'box'][$a['target_system']] ?? 'link') ?></span>
                <div><b><span class="dom <?= e($a['target_system']) ?>"><?= e($a['target_system']) ?></span> <?= e($a['action_type']) ?> <?= $a['status'] !== 'done' ? '· ' . e($a['status']) : '' ?></b><small><?= e($a['detail']) ?> · <?= e(time_ago($a['created_at'])) ?></small>
                <?php if ($a['ref_table'] === 'dss_evaluations' && $a['ref_id']): ?><br><a class="text-link" href="dss_evaluation.php?id=<?= (int)$a['ref_id'] ?>">Buka evaluasi DSS →</a><?php endif; ?>
                <?php if ($a['ref_table'] === 'tasks'): ?><br><a class="text-link" href="office.php?tab=tasks">Lihat tugas →</a><?php endif; ?>
                <?php if ($a['ref_table'] === 'kms_knowledge_items' && $a['action_type'] !== 'kms_lookup'): ?><br><a class="text-link" href="knowledge_view.php?id=<?= (int)$a['ref_id'] ?>">Buka draf artikel →</a><?php endif; ?></div></div>
        <?php endforeach; if (!$shown): ?><p class="muted small">Belum ada tindakan terintegrasi.</p><?php endif; ?>
    </div>

    <div class="card"><h2 style="margin-top:0">Rujukan pengetahuan (KMS)</h2>
        <?php foreach ($kmsItems as $it): ?><a class="link-card" href="knowledge_view.php?id=<?= (int)$it['id'] ?>"><span class="ic"><?= icon('book') ?></span><div><b><?= e($it['title']) ?></b><small><?= e(mb_strimwidth((string)$it['summary'], 0, 110, '…')) ?></small></div></a><?php endforeach; ?>
        <?php if (!$kmsItems): ?><p class="muted small">Belum ada artikel yang cocok<?= $inf['kms_keywords'] ? ' untuk kata kunci “' . e($inf['kms_keywords']) . '”' : '' ?>. Celah ini dicatat sebagai permintaan pengetahuan.</p><?php endif; ?>
    </div>
</aside>
</div>
<?php include 'layout_bottom.php'; ?>
