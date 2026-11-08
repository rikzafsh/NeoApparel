<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
$u = need_role($pdo, ['seller', 'staff', 'manager', 'admin']);
$uid = (int)$u['id'];
$canAct = has_any(['staff', 'manager', 'admin']);
$DOMAINS = ['TPS' => 'Transaksi', 'OAS' => 'Perkantoran', 'KMS' => 'Pengetahuan', 'MIS' => 'Manajemen', 'DSS' => 'Keputusan', 'CROSS' => 'Lintas modul'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canAct) {
    check_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'run') {
        $doms = array_values(array_intersect(array_keys($DOMAINS), (array)($_POST['domains'] ?? [])));
        try {
            $r = es_run($pdo, ['trigger' => 'manual', 'label' => 'Dijalankan dari dashboard', 'user_id' => $uid, 'domains' => (count($doms) && count($doms) < count($DOMAINS)) ? array_merge($doms, ['STYLE']) : null]);
            flash('success', "Inferensi selesai dalam {$r['duration_ms']} ms: {$r['entities']} entitas, {$r['facts']} fakta, {$r['inferences']} kesimpulan ({$r['new']} baru, {$r['resolved']} selesai otomatis, {$r['actions']} tindakan terintegrasi).");
        } catch (Throwable $e) { flash('error', 'Inferensi gagal: ' . $e->getMessage()); }
        go('es_dashboard.php');
    }
    if (in_array($a, ['ack', 'dismiss'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        if ($a === 'ack') { $pdo->prepare("UPDATE es_inferences SET status='acknowledged' WHERE id=? AND status='open'")->execute([$id]); }
        else { $pdo->prepare("UPDATE es_inferences SET status='dismissed', snoozed_until=DATE_ADD(NOW(), INTERVAL 7 DAY), resolved_by=?, resolved_at=NOW(), resolution_note='Diabaikan 7 hari' WHERE id=?")->execute([$uid, $id]); }
        flash('success', $a === 'ack' ? 'Ditandai sudah diketahui.' : 'Diabaikan selama 7 hari.');
        go('es_dashboard.php?' . http_build_query(array_filter(['status' => $_GET['status'] ?? '', 'domain' => $_GET['domain'] ?? '', 'severity' => $_GET['severity'] ?? ''])));
    }
}

$fStatus = $_GET['status'] ?? 'active'; $fDomain = $_GET['domain'] ?? ''; $fSev = $_GET['severity'] ?? ''; $fQ = trim($_GET['q'] ?? '');
$where = ['1=1']; $args = [];
if ($fStatus === 'active') { $where[] = "status IN ('open','acknowledged','in_progress')"; }
elseif (in_array($fStatus, ['open', 'acknowledged', 'in_progress', 'resolved', 'dismissed'], true)) { $where[] = 'status = ?'; $args[] = $fStatus; }
if (isset($DOMAINS[$fDomain])) { $where[] = 'domain = ?'; $args[] = $fDomain; }
if (in_array($fSev, ['critical', 'high', 'medium', 'low', 'info'], true)) { $where[] = 'severity = ?'; $args[] = $fSev; }
if ($fQ !== '') { $where[] = '(title LIKE ? OR subject_label LIKE ? OR rule_code LIKE ?)'; array_push($args, "%$fQ%", "%$fQ%", "%$fQ%"); }
$st = $pdo->prepare('SELECT * FROM es_inferences WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(severity,'critical','high','medium','low','info'), certainty DESC, updated_at DESC LIMIT 60");
$st->execute($args);
$list = $st->fetchAll();

$perDomain = [];
foreach ($pdo->query("SELECT domain, COUNT(*) n, SUM(severity IN ('critical','high')) hi FROM es_inferences WHERE status IN ('open','acknowledged','in_progress') GROUP BY domain")->fetchAll() as $r) { $perDomain[$r['domain']] = $r; }
$sevCount = [];
foreach ($pdo->query("SELECT severity, COUNT(*) n FROM es_inferences WHERE status IN ('open','acknowledged','in_progress') GROUP BY severity")->fetchAll() as $r) { $sevCount[$r['severity']] = (int)$r['n']; }
$lastRuns = $pdo->query("SELECT r.*, u.name uname FROM es_runs r LEFT JOIN users u ON u.id = r.started_by ORDER BY r.id DESC LIMIT 6")->fetchAll();
$totals = $pdo->query("SELECT (SELECT COUNT(*) FROM es_rules WHERE is_active=1) rules, (SELECT COUNT(*) FROM es_actions WHERE status='done') actions, (SELECT COUNT(*) FROM es_inferences WHERE status='resolved') resolved")->fetch();
$modules = [
    'TPS' => ['Transaksi', 'Stok, order, ulasan, kecepatan jual'], 'OAS' => ['Perkantoran', 'Tugas & persetujuan → tugas, notifikasi'],
    'KMS' => ['Pengetahuan', 'Rujukan SOP; celah → permintaan KMS'], 'MIS' => ['Manajemen', 'Target, tren omzet, pembatalan'], 'DSS' => ['Keputusan', 'Penawaran & kinerja supplier → evaluasi SAW'],
];
$pageTitle = 'Pusat Pakar';
$pageKicker = APP_NAME . ' · expert system';
include 'layout_top.php';
?>
<section class="es-hero">
    <div>
        <span class="kicker">EXPERT SYSTEM · BASIS PENGETAHUAN <?= (int)$totals['rules'] ?> ATURAN</span>
        <h1>Pusat Pakar Neo Apparel</h1>
        <p>Mesin pakar membaca fakta dari TPS, OAS, KMS, MIS, dan DSS, menalar dengan aturan JIKA-MAKA berantai (forward chaining + certainty factor), lalu menghasilkan kesimpulan yang dapat dijelaskan dan tindakan terintegrasi.</p>
        <?php if ($canAct): ?>
        <form method="post" class="row" style="gap:10px"><?= csrf_field() ?><input type="hidden" name="action" value="run">
            <details style="color:#fff"><summary class="btn ghost" style="cursor:pointer">Pilih domain</summary>
                <div class="card" style="position:absolute;z-index:5;margin-top:6px;color:var(--ink);min-width:230px"><?php foreach ($DOMAINS as $k => $d): ?><label class="row" style="margin:4px 0"><input type="checkbox" name="domains[]" value="<?= e($k) ?>" checked> <span class="dom <?= e($k) ?>"><?= e($k) ?></span> <?= e($d) ?></label><?php endforeach; ?></div></details>
            <button class="btn primary" type="submit"><?= icon('play', 'sm') ?> Jalankan inferensi sekarang</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="es-flow"><span>TPS</span><span>OAS</span><span>KMS</span><span>MIS</span><span>DSS</span><i>→</i><span class="core">Fakta</span><i>→</i><span class="core">Aturan JIKA-MAKA</span><i>→</i><span class="core">Kesimpulan + CF</span><i>→</i><span>Tugas</span><span>Evaluasi DSS</span><span>Artikel KMS</span><span>Notifikasi</span></div>
</section>

<div class="module-strip">
<?php foreach ($modules as $k => [$name, $desc]): $d = $perDomain[$k] ?? ['n' => 0, 'hi' => 0]; ?>
    <a class="module" href="es_dashboard.php?domain=<?= e($k) ?>"><div class="top"><span class="dom <?= e($k) ?>"><?= e($k) ?></span><?php if ((int)$d['hi'] > 0): ?><span class="sev high"><?= (int)$d['hi'] ?> penting</span><?php endif; ?></div><b><?= (int)$d['n'] ?></b><small><?= e($name) ?> · <?= e($desc) ?></small></a>
<?php endforeach; ?>
</div>

<div class="grid-2" style="grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);align-items:start">
<div>
    <form method="get" class="filter-bar">
        <select name="status" onchange="this.form.submit()"><?php foreach (['active' => 'Aktif', 'open' => 'Terbuka', 'acknowledged' => 'Diakui', 'in_progress' => 'Dikerjakan', 'resolved' => 'Selesai', 'dismissed' => 'Diabaikan', 'all' => 'Semua'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select name="domain" onchange="this.form.submit()"><option value="">Semua domain</option><?php foreach ($DOMAINS as $k => $l): ?><option value="<?= e($k) ?>" <?= $fDomain === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
        <select name="severity" onchange="this.form.submit()"><option value="">Semua tingkat</option><?php foreach (['critical' => 'Kritis', 'high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah', 'info' => 'Info'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $fSev === $k ? 'selected' : '' ?>><?= e($l) ?> <?= isset($sevCount[$k]) ? '(' . $sevCount[$k] . ')' : '' ?></option><?php endforeach; ?></select>
        <div class="search" style="min-width:160px"><?= icon('search') ?><input type="search" name="q" value="<?= e($fQ) ?>" placeholder="Cari kesimpulan…"></div>
    </form>
    <?php if (!$list): ?>
        <div class="card empty-state"><?= icon('cpu') ?><h3>Tidak ada kesimpulan</h3><p>Ubah filter atau jalankan inferensi untuk memperbarui kesimpulan.</p></div>
    <?php endif; ?>
    <div class="inf-list">
    <?php foreach ($list as $i): ?>
        <article class="inf <?= e($i['severity']) ?>">
            <header><span class="sev <?= e($i['severity']) ?>"><?= e($i['severity']) ?></span><span class="dom <?= e($i['domain']) ?>"><?= e($i['domain']) ?></span><?= badge($i['status']) ?>
                <span class="cf" title="Certainty factor"><span class="bar"><i style="width:<?= (int)round($i['certainty'] * 100) ?>%"></i></span><?= (int)round($i['certainty'] * 100) ?>%</span></header>
            <h3><a href="es_inference.php?id=<?= (int)$i['id'] ?>"><?= e($i['title']) ?></a></h3>
            <p><?= e($i['message']) ?></p>
            <?php if ($i['recommendation']): ?><div class="rec"><b>Rekomendasi:</b> <?= e($i['recommendation']) ?></div><?php endif; ?>
            <footer><span class="mono"><?= e($i['rule_code']) ?></span><span>· <?= e(time_ago($i['updated_at'])) ?></span><span>· dilihat <?= (int)$i['occurrences'] ?>×</span><span class="spacer"></span>
                <a class="btn-sm primary" href="es_inference.php?id=<?= (int)$i['id'] ?>">Penjelasan & tindakan</a>
                <?php if ($canAct && $i['status'] === 'open'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn-sm" name="action" value="ack">Sudah tahu</button></form><?php endif; ?>
                <?php if ($canAct && in_array($i['status'], ['open', 'acknowledged'], true)): ?><form method="post" data-confirm="Abaikan kesimpulan ini selama 7 hari?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn-sm" name="action" value="dismiss">Abaikan</button></form><?php endif; ?></footer>
        </article>
    <?php endforeach; ?>
    </div>
</div>
<aside>
    <div class="card"><h2 style="margin-top:0">Ringkasan</h2>
        <dl class="kv"><dt>Aturan aktif</dt><dd><?= (int)$totals['rules'] ?> <a class="text-link" href="es_rules.php">kelola</a></dd><dt>Tindakan terintegrasi</dt><dd><?= (int)$totals['actions'] ?></dd><dt>Selesai</dt><dd><?= (int)$totals['resolved'] ?></dd></dl></div>
    <div class="card"><h2 style="margin-top:0">Siklus inferensi terakhir</h2>
        <?php if (!$lastRuns): ?><p class="muted">Belum pernah dijalankan.</p><?php endif; ?>
        <?php foreach ($lastRuns as $r): ?>
            <div class="link-card"><span class="ic"><?= icon($r['trigger_type'] === 'event' ? 'zap' : 'cpu') ?></span><div><b><?= e(ucfirst($r['trigger_type'])) ?><?= $r['trigger_label'] ? ' · ' . e($r['trigger_label']) : '' ?></b>
                <small><?= e(time_ago($r['started_at'])) ?> · <?= (int)$r['inferences_new'] ?> baru · <?= (int)$r['inferences_resolved'] ?> selesai · <?= (int)$r['actions_executed'] ?> tindakan · <?= (int)$r['duration_ms'] ?> ms<?= $r['status'] === 'failed' ? ' · GAGAL' : '' ?></small></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><h2 style="margin-top:0">Cara mesin bekerja</h2>
        <ol class="muted" style="padding-left:18px;font-size:12.5px;line-height:1.7">
            <li><b>Fakta</b> dikumpulkan dari lima modul.</li><li><b>Aturan derive</b> menyimpulkan fakta baru (mis. <span class="mono">stock_risk=critical</span>) hingga stabil.</li>
            <li><b>Aturan inferensi</b> memakai fakta turunan itu untuk menghasilkan kesimpulan + <b>certainty factor</b>.</li><li><b>Tindakan</b> dikirim ke OAS, DSS, KMS dan dicatat untuk audit.</li></ol></div>
</aside>
</div>
<?php include 'layout_bottom.php'; ?>
