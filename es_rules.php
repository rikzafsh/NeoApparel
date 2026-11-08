<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
$u = need_role($pdo, ['manager', 'admin']);
$uid = (int)$u['id'];
$isAdmin = has_role('admin');
$tab = $_GET['tab'] ?? 'rules';
if (!in_array($tab, ['rules', 'params', 'console', 'api'], true)) { $tab = 'rules'; }
$DOMAINS = ['TPS', 'OAS', 'KMS', 'MIS', 'DSS', 'STYLE', 'CROSS'];
$SUBJECTS = ['variation', 'order', 'supplier', 'task', 'approval', 'knowledge_request', 'global', 'style_session'];
$KINDS = ['derive' => 'Derive (menurunkan fakta baru)', 'inference' => 'Inference (menghasilkan kesimpulan)', 'recommend' => 'Recommend (pembobotan Neo Stylist)'];
$SEV = ['info', 'low', 'medium', 'high', 'critical'];

function es_json_field(string $raw, bool $nullable = true): ?array
{
    $raw = trim($raw);
    if ($raw === '') { if ($nullable) return null; throw new RuntimeException('Kolom JSON wajib diisi.'); }
    $d = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON tidak valid: ' . json_last_error_msg());
    return is_array($d) ? $d : throw new RuntimeException('JSON harus berupa objek atau larik.');
}
function es_validate_condition(array $n, string $path = 'conditions'): void
{
    foreach (['all', 'any'] as $g) { if (isset($n[$g])) { if (!is_array($n[$g]) || !$n[$g]) throw new RuntimeException("$path.$g harus larik berisi kondisi."); foreach ($n[$g] as $i => $c) es_validate_condition((array)$c, "$path.$g[$i]"); return; } }
    if (isset($n['not'])) { es_validate_condition((array)$n['not'], "$path.not"); return; }
    if (empty($n['f'])) throw new RuntimeException("$path: kondisi daun wajib punya 'f' (nama fakta).");
    if (!in_array($n['op'] ?? '==', ['==', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between', 'contains', 'exists', 'is_null'], true)) throw new RuntimeException("$path: operator '" . ($n['op'] ?? '') . "' tidak dikenal.");
}
function es_test_rule(PDO $pdo, array $rule, int $limit = 8): array
{
    $params = es_params($pdo);
    $others = array_filter(es_load_rules($pdo), static fn($r) => $r['code'] !== $rule['code']);
    $all = array_merge($others, [$rule]);
    $ents = es_collect($pdo, $all, $params);
    es_forward_chain($ents, $all, $params); es_aggregate_global($ents); es_forward_chain($ents, $all, $params);
    $subset = array_values(array_filter($ents, static fn($e) => $e['type'] === $rule['subject_type']));
    $hits = [];
    if ($rule['rule_kind'] === 'derive') {
        foreach ($subset as $e) { $used = []; [$ok] = es_eval($rule['conditions'], $e, $params, $used); if ($ok) $hits[] = ['label' => $e['label'], 'message' => 'menurunkan ' . json_encode($rule['derives'], JSON_UNESCAPED_UNICODE)]; }
    } else {
        foreach (es_infer($subset, [$rule], $params)['results'] as $r) { $hits[] = ['label' => $r['entity']['label'], 'message' => $r['title'] . ' · CF ' . $r['cf']]; }
    }
    return ['subjects' => count($subset), 'matches' => count($hits), 'sample' => array_slice($hits, 0, $limit)];
}

$editRule = null; $testResult = null; $consoleOut = null; $newToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'save_rule' || $a === 'test_rule') {
            $id = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim($_POST['code'] ?? ''));
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,58}$/', $code)) throw new RuntimeException('Kode aturan: huruf besar/angka/-/_ , 3-59 karakter.');
            $domain = in_array($_POST['domain'] ?? '', $DOMAINS, true) ? $_POST['domain'] : throw new RuntimeException('Domain tidak valid.');
            $subject = in_array($_POST['subject_type'] ?? '', $SUBJECTS, true) ? $_POST['subject_type'] : throw new RuntimeException('Subjek tidak valid.');
            $kind = isset($KINDS[$_POST['rule_kind'] ?? '']) ? $_POST['rule_kind'] : throw new RuntimeException('Jenis aturan tidak valid.');
            $sev = in_array($_POST['severity'] ?? '', $SEV, true) ? $_POST['severity'] : 'medium';
            $cf = max(0.01, min(1.0, (float)str_replace(',', '.', (string)($_POST['cf'] ?? 0.8))));
            $prio = max(0, min(999, (int)($_POST['priority'] ?? 50)));
            $cond = es_json_field($_POST['conditions'] ?? '', false); es_validate_condition($cond);
            $derives = es_json_field($_POST['derives'] ?? '');
            $actions = es_json_field($_POST['actions'] ?? '');
            if ($kind === 'derive' && !$derives) throw new RuntimeException('Aturan derive wajib memiliki "derives" (mis. {"stock_risk":"critical"}).');
            $row = ['code' => $code, 'name' => trim($_POST['name'] ?? '') ?: $code, 'domain' => $domain, 'subject_type' => $subject, 'rule_kind' => $kind, 'description' => trim($_POST['description'] ?? '') ?: null,
                'conditions' => $cond, 'derives' => $derives ?: [], 'severity' => $sev, 'title_tpl' => trim($_POST['title_tpl'] ?? '') ?: null, 'message_tpl' => trim($_POST['message_tpl'] ?? '') ?: null,
                'recommendation_tpl' => trim($_POST['recommendation_tpl'] ?? '') ?: null, 'actions' => $actions ?: [], 'kms_keywords' => trim($_POST['kms_keywords'] ?? '') ?: null, 'cf' => $cf, 'priority' => $prio, 'is_active' => !empty($_POST['is_active']) ? 1 : 0];
            if ($a === 'test_rule') {
                $editRule = $row + ['id' => $id]; $testResult = es_test_rule($pdo, $row); $tab = 'rules';
            } else {
                $args = [$row['code'], $row['name'], $domain, $subject, $kind, $row['description'], json_encode($cond, JSON_UNESCAPED_UNICODE), $derives ? json_encode($derives, JSON_UNESCAPED_UNICODE) : null, $sev,
                    $row['title_tpl'], $row['message_tpl'], $row['recommendation_tpl'], $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null, $row['kms_keywords'], $cf, $prio, $row['is_active']];
                if ($id) {
                    $pdo->prepare('UPDATE es_rules SET code=?,name=?,domain=?,subject_type=?,rule_kind=?,description=?,conditions=?,derives=?,severity=?,title_tpl=?,message_tpl=?,recommendation_tpl=?,actions=?,kms_keywords=?,cf=?,priority=?,is_active=? WHERE id=?')->execute(array_merge($args, [$id]));
                    audit($pdo, $uid, 'UPDATE', 'es_rules', $id, null, ['code' => $code]);
                } else {
                    $pdo->prepare('INSERT INTO es_rules(code,name,domain,subject_type,rule_kind,description,conditions,derives,severity,title_tpl,message_tpl,recommendation_tpl,actions,kms_keywords,cf,priority,is_active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($args, [$uid]));
                    audit($pdo, $uid, 'CREATE', 'es_rules', (int)$pdo->lastInsertId(), null, ['code' => $code]);
                }
                flash('success', 'Aturan ' . $code . ' disimpan. Jalankan inferensi dari Pusat Pakar untuk melihat dampaknya.');
                go('es_rules.php');
            }
        }
        if ($a === 'toggle_rule') { $pdo->prepare('UPDATE es_rules SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_POST['id']]); flash('success', 'Status aturan diubah.'); go('es_rules.php?' . http_build_query(['domain' => $_GET['domain'] ?? ''])); }
        if ($a === 'delete_rule') {
            $s = $pdo->prepare('SELECT is_system FROM es_rules WHERE id=?'); $s->execute([(int)$_POST['id']]); $r = $s->fetch();
            if ($r && (int)$r['is_system'] === 1) throw new RuntimeException('Aturan bawaan tidak dapat dihapus; nonaktifkan saja.');
            $pdo->prepare('DELETE FROM es_rules WHERE id=? AND is_system=0')->execute([(int)$_POST['id']]); flash('success', 'Aturan dihapus.'); go('es_rules.php');
        }
        if ($a === 'save_params') {
            $st = $pdo->prepare('UPDATE es_parameters SET param_value=?, updated_by=? WHERE param_key=?');
            foreach (($_POST['param'] ?? []) as $k => $v) { if (is_numeric(str_replace(',', '.', (string)$v))) $st->execute([(float)str_replace(',', '.', (string)$v), $uid, (string)$k]); }
            audit($pdo, $uid, 'UPDATE', 'es_parameters', null, null, ['bulk' => true]);
            flash('success', 'Parameter disimpan.'); go('es_rules.php?tab=params');
        }
        if ($a === 'console') {
            $tab = 'console';
            $facts = es_json_field($_POST['facts'] ?? '', false);
            $subj = in_array($_POST['subject'] ?? '', $SUBJECTS, true) ? $_POST['subject'] : 'variation';
            $consoleOut = es_consult($pdo, $subj, $facts);
        }
        if ($a === 'token_create' && $isAdmin) {
            $name = trim($_POST['name'] ?? ''); $scopes = implode(',', array_intersect(['read', 'run', 'consult', 'stylist'], (array)($_POST['scopes'] ?? [])));
            if ($name === '' || $scopes === '') throw new RuntimeException('Nama dan minimal satu cakupan wajib diisi.');
            $plain = 'neo_' . bin2hex(random_bytes(20));
            $pdo->prepare('INSERT INTO es_api_tokens(name,token_hash,token_hint,scopes,owner_user_id) VALUES(?,?,?,?,?)')->execute([$name, hash('sha256', $plain), substr($plain, 0, 8) . '…', $scopes, $uid]);
            $newToken = $plain; $tab = 'api';
        }
        if ($a === 'token_toggle' && $isAdmin) { $pdo->prepare('UPDATE es_api_tokens SET is_active = 1 - is_active WHERE id=?')->execute([(int)$_POST['id']]); go('es_rules.php?tab=api'); }
        if ($a === 'token_delete' && $isAdmin) { $pdo->prepare('DELETE FROM es_api_tokens WHERE id=?')->execute([(int)$_POST['id']]); flash('success', 'Token dihapus.'); go('es_rules.php?tab=api'); }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        if (in_array($a, ['save_rule', 'test_rule'], true)) { $editRule = $_POST; $editRule['conditions'] = $_POST['conditions'] ?? ''; $tab = 'rules'; }
        elseif ($a === 'console') { $tab = 'console'; }
        else { go('es_rules.php?tab=' . $tab); }
    }
}
if (!$editRule && (isset($_GET['edit']) || isset($_GET['new']))) {
    if (isset($_GET['edit'])) { $s = $pdo->prepare('SELECT * FROM es_rules WHERE id=?'); $s->execute([(int)$_GET['edit']]); $editRule = $s->fetch() ?: null; }
    else { $editRule = ['id' => 0, 'code' => '', 'name' => '', 'domain' => 'TPS', 'subject_type' => 'variation', 'rule_kind' => 'inference', 'severity' => 'medium', 'cf' => 0.8, 'priority' => 50, 'is_active' => 1,
        'conditions' => '{"all":[{"f":"stock","op":"<=","v":5}]}', 'actions' => '', 'derives' => '']; }
}
function rv(?array $r, string $k, string $d = ''): string { $v = $r[$k] ?? $d; if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); } return (string)($v ?? ''); }

$fDomain = $_GET['domain'] ?? '';
$q = 'SELECT r.*, (SELECT COUNT(*) FROM es_inferences i WHERE i.rule_id = r.id AND i.status IN (\'open\',\'acknowledged\',\'in_progress\')) active_inf FROM es_rules r' . ($fDomain && in_array($fDomain, $DOMAINS, true) ? ' WHERE r.domain = ' . $pdo->quote($fDomain) : '') . ' ORDER BY r.domain, r.rule_kind, r.priority DESC, r.code';
$rules = $pdo->query($q)->fetchAll();
$params = $pdo->query('SELECT * FROM es_parameters ORDER BY param_key')->fetchAll();
$tokens = $isAdmin && $tab === 'api' ? $pdo->query('SELECT t.*, u.name owner FROM es_api_tokens t LEFT JOIN users u ON u.id = t.owner_user_id ORDER BY t.id DESC')->fetchAll() : [];
$catalog = [];
if ($editRule || $tab === 'console') {
    $allRules = es_load_rules($pdo); $ents = es_collect($pdo, $allRules, es_params($pdo)); es_forward_chain($ents, $allRules, es_params($pdo)); es_aggregate_global($ents);
    foreach ($ents as $e) { if (!isset($catalog[$e['type']])) { $catalog[$e['type']] = ['sample' => $e['label'], 'facts' => $e['facts'], 'derived' => array_keys($e['src'])]; } }
    foreach ($ents as $e) { foreach ($e['src'] as $k => $_) { if (!in_array($k, $catalog[$e['type']]['derived'], true)) { $catalog[$e['type']]['derived'][] = $k; $catalog[$e['type']]['facts'][$k] = $e['facts'][$k]; } } }
}
$pageTitle = 'Basis aturan';
$pageKicker = APP_NAME . ' · knowledge base expert system';
include 'layout_top.php';
?>
<div class="head"><div><h1>Basis aturan Expert System</h1><p>Kelola pengetahuan pakar: aturan JIKA-MAKA, ambang batas, uji coba, dan akses API.</p></div>
<?php if ($tab === 'rules' && !$editRule): ?><a class="btn primary" href="es_rules.php?new=1"><?= icon('plus', 'sm') ?> Aturan baru</a><?php endif; ?></div>
<nav class="tabs-nav"><?php foreach (['rules' => 'Aturan', 'params' => 'Parameter ambang', 'console' => 'Konsol uji', 'api' => 'Integrasi API'] as $k => $l): ?><a class="<?= $tab === $k && !$editRule ? 'on' : '' ?>" href="es_rules.php?tab=<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></nav>

<?php if ($editRule): $er = $editRule; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($er['id'] ?? 0) ?>">
    <h2 style="margin-top:0"><?= !empty($er['id']) ? 'Edit aturan ' . e(rv($er, 'code')) : 'Aturan baru' ?></h2>
    <div class="grid-3"><div><label>Kode</label><input name="code" required value="<?= e(rv($er, 'code')) ?>" placeholder="TPS-I-CONTOH"></div><div style="grid-column:span 2"><label>Nama</label><input name="name" value="<?= e(rv($er, 'name')) ?>"></div></div>
    <div class="grid-3"><div><label>Domain</label><select name="domain"><?php foreach ($DOMAINS as $d): ?><option <?= rv($er, 'domain') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
        <div><label>Subjek</label><select name="subject_type"><?php foreach ($SUBJECTS as $d): ?><option <?= rv($er, 'subject_type') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
        <div><label>Jenis</label><select name="rule_kind"><?php foreach ($KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= rv($er, 'rule_kind') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
    <div class="grid-3"><div><label>Tingkat</label><select name="severity"><?php foreach ($SEV as $d): ?><option <?= rv($er, 'severity') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
        <div><label>Certainty factor (0,01–1)</label><input name="cf" value="<?= e(rv($er, 'cf', '0.8')) ?>"></div><div><label>Prioritas (makin besar makin dulu)</label><input type="number" name="priority" value="<?= e(rv($er, 'priority', '50')) ?>"></div></div>
    <label>Deskripsi</label><input name="description" value="<?= e(rv($er, 'description')) ?>">
    <div class="grid-2"><div><label>Kondisi (JSON) <small class="muted">· all / any / not / daun {"f","op","v"}</small></label><textarea class="code-area" name="conditions" rows="9"><?= e(rv($er, 'conditions')) ?></textarea></div>
        <div><label>Menurunkan fakta (JSON, untuk jenis derive)</label><textarea class="code-area" name="derives" rows="4"><?= e(rv($er, 'derives') === '[]' ? '' : rv($er, 'derives')) ?></textarea>
        <label>Tindakan (JSON larik)</label><textarea class="code-area" name="actions" rows="4" placeholder='[{"type":"oas_task","auto":true,"assign_role":"staff","title":"Tindak lanjut {subject}"}]'><?= e(rv($er, 'actions') === '[]' ? '' : rv($er, 'actions')) ?></textarea></div></div>
    <div class="grid-2"><div><label>Judul (templat)</label><input name="title_tpl" value="<?= e(rv($er, 'title_tpl')) ?>" placeholder="Restock mendesak: {product_name}"></div><div><label>Kata kunci KMS (koma)</label><input name="kms_keywords" value="<?= e(rv($er, 'kms_keywords')) ?>"></div></div>
    <label>Pesan (templat)</label><input name="message_tpl" value="<?= e(rv($er, 'message_tpl')) ?>" placeholder="Stok {stock} unit cukup {days_of_cover|n1} hari">
    <label>Rekomendasi (templat)</label><input name="recommendation_tpl" value="<?= e(rv($er, 'recommendation_tpl')) ?>">
    <label class="row" style="margin-top:14px"><span class="switch"><input type="checkbox" name="is_active" value="1" <?= !empty($er['is_active']) ? 'checked' : '' ?>><span></span></span> Aturan aktif</label>
    <div class="action-row"><button class="btn primary" name="action" value="save_rule">Simpan aturan</button><button class="btn ghost" name="action" value="test_rule" type="submit"><?= icon('play', 'sm') ?> Uji pada data saat ini</button><a class="btn ghost" href="es_rules.php">Batal</a></div>
    <p class="muted small">Templat memakai <span class="mono">{nama_fakta}</span> dan format: <span class="mono">|rp</span> <span class="mono">|int</span> <span class="mono">|n1</span> <span class="mono">|n2</span> <span class="mono">|pct</span> <span class="mono">|days</span>. Nilai pembanding dapat berupa <span class="mono">{"param":"nama_parameter"}</span> atau <span class="mono">{"ref":"fakta_lain"}</span>.</p>
</form>
<?php if ($testResult): ?><div class="card"><h2 style="margin-top:0">Hasil uji</h2><p><b><?= (int)$testResult['matches'] ?></b> dari <?= (int)$testResult['subjects'] ?> subjek (<?= e($er['subject_type']) ?>) memenuhi aturan ini.</p>
    <?php foreach ($testResult['sample'] as $h): ?><div class="link-card"><span class="ic"><?= icon('check') ?></span><div><b><?= e($h['label']) ?></b><small><?= e($h['message']) ?></small></div></div><?php endforeach; ?></div><?php endif; ?>
<div class="card"><h2 style="margin-top:0">Kamus fakta (memori kerja saat ini)</h2>
    <?php $subj = rv($er, 'subject_type', 'variation'); $cat = $catalog[$subj] ?? null; ?>
    <?php if ($cat): ?><p class="muted small">Contoh entitas <?= e($subj) ?>: <b><?= e($cat['sample']) ?></b>. Fakta dengan label <span class="mono">turunan</span> berasal dari aturan derive.</p>
    <div class="fact-grid"><?php foreach ($cat['facts'] as $k => $v): ?><div class="fact <?= in_array($k, $cat['derived'], true) ? 'derived' : '' ?>"><small><?= e($k) ?><?= in_array($k, $cat['derived'], true) ? ' · turunan' : '' ?></small><b><?= e(is_scalar($v) || $v === null ? (string)$v : json_encode($v)) ?></b></div><?php endforeach; ?></div>
    <?php else: ?><p class="muted">Tidak ada entitas bertipe ini saat ini.</p><?php endif; ?></div>

<?php elseif ($tab === 'rules'): ?>
<form method="get" class="filter-bar"><select name="domain" onchange="this.form.submit()"><option value="">Semua domain</option><?php foreach ($DOMAINS as $d): ?><option <?= $fDomain === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select><span class="muted small"><?= count($rules) ?> aturan</span></form>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Kode</th><th>Aturan</th><th>Domain</th><th>Subjek</th><th>Jenis</th><th>CF</th><th>Prio</th><th>Aktif</th><th></th></tr></thead><tbody>
<?php foreach ($rules as $r): ?><tr>
    <td><span class="mono"><?= e($r['code']) ?></span></td><td><b><?= e($r['name']) ?></b><?php if ($r['active_inf']): ?> <span class="tag"><?= (int)$r['active_inf'] ?> aktif</span><?php endif; ?></td>
    <td><span class="dom <?= e($r['domain']) ?>"><?= e($r['domain']) ?></span></td><td><?= e($r['subject_type']) ?></td><td><?= e($r['rule_kind']) ?></td><td><?= number_format((float)$r['cf'], 2, ',', '') ?></td><td><?= (int)$r['priority'] ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><label class="switch" style="margin:0"><input type="checkbox" <?= $r['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Aktif"><span></span></label></form></td>
    <td class="actions"><a class="btn-sm" href="es_rules.php?edit=<?= (int)$r['id'] ?>">Edit</a><?php if (!$r['is_system']): ?><form method="post" data-confirm="Hapus aturan ini?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm danger">Hapus</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'params'): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save_params"><h2 style="margin-top:0">Parameter ambang batas</h2><p class="muted">Dipakai aturan melalui <span class="mono">{"param":"..."}</span>. Mengubah angka di sini langsung mengubah perilaku pakar pada siklus berikutnya, tanpa menyunting aturan.</p>
    <div class="grid-2"><?php foreach ($params as $p): ?><div><label><?= e($p['label']) ?> <small class="muted">· <?= e($p['param_key']) ?><?= $p['unit'] ? ' (' . e($p['unit']) . ')' : '' ?></small></label><input name="param[<?= e($p['param_key']) ?>]" value="<?= e(rtrim(rtrim(number_format((float)$p['param_value'], 2, '.', ''), '0'), '.')) ?>"><small class="muted"><?= e($p['description']) ?></small></div><?php endforeach; ?></div>
    <div class="action-row"><button class="btn primary">Simpan parameter</button></div></form>

<?php elseif ($tab === 'console'): ?>
<div class="grid-2" style="align-items:start"><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="console"><h2 style="margin-top:0">Konsultasi ad-hoc</h2>
    <p class="muted">Berikan fakta (JSON) dan lihat kesimpulan pakar tanpa menyimpan apa pun. Sama dengan endpoint <span class="mono">POST es_api.php?action=consult</span>.</p>
    <label>Jenis subjek</label><select name="subject"><?php foreach ($SUBJECTS as $d): ?><option <?= ($_POST['subject'] ?? 'variation') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select>
    <label>Fakta (JSON)</label><textarea class="code-area" name="facts" rows="14"><?= e($_POST['facts'] ?? json_encode(['product_name' => 'Kaos Uji', 'variant_label' => 'Hitam / M', 'stock' => 3, 'price' => 150000, 'velocity_30d' => 1.1, 'days_of_cover' => 2.7, 'sold_7d' => 12, 'sold_prev_7d' => 5, 'sales_trend_pct' => 95, 'sold_30d' => 33, 'days_since_last_sale' => 0, 'has_incoming_po' => 0, 'incoming_po_qty' => 0, 'offer_count' => 2, 'dss_has_approved_supplier' => 0, 'dss_has_open_evaluation' => 0, 'suggested_restock_qty' => 40, 'lead_time_days' => 7], JSON_PRETTY_PRINT)) ?></textarea>
    <div class="action-row"><button class="btn primary"><?= icon('play', 'sm') ?> Jalankan penalaran</button></div></form>
    <div class="card"><h2 style="margin-top:0">Hasil</h2>
    <?php if ($consoleOut): ?>
        <?php foreach ($consoleOut['derived_facts'] as $k => $d): ?><span class="tag" style="margin:2px">turunan: <?= e($k) ?> = <b><?= e((string)$d['value']) ?></b> (CF <?= number_format($d['certainty'], 2, ',', '') ?>)</span><?php endforeach; ?>
        <?php foreach ($consoleOut['conclusions'] as $c): ?><article class="inf <?= e($c['severity']) ?>" style="margin-top:12px"><header><span class="sev <?= e($c['severity']) ?>"><?= e($c['severity']) ?></span><span class="dom <?= e($c['domain']) ?>"><?= e($c['domain']) ?></span><span class="cf"><span class="bar"><i style="width:<?= (int)round($c['certainty'] * 100) ?>%"></i></span><?= (int)round($c['certainty'] * 100) ?>%</span></header><h3><?= e($c['title']) ?></h3><p><?= e($c['message']) ?></p><?php if ($c['recommendation']): ?><div class="rec"><?= e($c['recommendation']) ?></div><?php endif; ?></article><?php endforeach; ?>
        <?php if (!$consoleOut['conclusions']): ?><p class="muted">Tidak ada aturan inferensi yang terpenuhi oleh fakta tersebut.</p><?php endif; ?>
        <details style="margin-top:12px"><summary class="text-link" style="cursor:pointer">Respons JSON mentah</summary><pre class="json"><?= e(json_encode($consoleOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
    <?php else: ?><p class="muted">Hasil muncul di sini.</p><?php endif; ?></div></div>
<?php if ($catalog): ?><div class="card"><h2 style="margin-top:0">Kamus fakta per subjek</h2><?php foreach ($catalog as $type => $c): ?><details><summary style="cursor:pointer;font-weight:700;margin:6px 0"><?= e($type) ?> <small class="muted">· <?= count($c['facts']) ?> fakta</small></summary><div class="fact-grid" style="margin:8px 0 14px"><?php foreach ($c['facts'] as $k => $v): ?><div class="fact <?= in_array($k, $c['derived'], true) ? 'derived' : '' ?>"><small><?= e($k) ?></small><b><?= e(is_scalar($v) || $v === null ? (string)$v : json_encode($v)) ?></b></div><?php endforeach; ?></div></details><?php endforeach; ?></div><?php endif; ?>

<?php elseif ($tab === 'api'): ?>
<?php if ($newToken): ?><div class="alert success"><div><b>Token baru (tampil sekali, simpan sekarang):</b><br><span class="mono" style="user-select:all"><?= e($newToken) ?></span></div></div><?php endif; ?>
<div class="grid-2" style="align-items:start">
<div class="card"><h2 style="margin-top:0">REST API Expert System</h2><p class="muted">Endpoint <span class="mono">es_api.php</span> mengembalikan JSON. Autentikasi: sesi login (header <span class="mono">X-CSRF-Token</span> untuk POST) atau <span class="mono">Authorization: Bearer &lt;token&gt;</span>.</p>
<table><thead><tr><th>Aksi</th><th>Metode</th><th>Cakupan</th></tr></thead><tbody>
<tr><td class="mono">health</td><td>GET</td><td>-</td></tr><tr><td class="mono">inferences</td><td>GET</td><td>read</td></tr><tr><td class="mono">inference&amp;id=</td><td>GET</td><td>read</td></tr>
<tr><td class="mono">rules</td><td>GET</td><td>read</td></tr><tr><td class="mono">facts&amp;subject=</td><td>GET</td><td>read</td></tr><tr><td class="mono">run</td><td>POST</td><td>run</td></tr>
<tr><td class="mono">consult</td><td>POST</td><td>consult</td></tr><tr><td class="mono">stylist</td><td>POST</td><td>stylist</td></tr><tr><td class="mono">inference_status</td><td>POST</td><td>run</td></tr></tbody></table>
<pre class="json">curl -H "Authorization: Bearer neo-demo-token-2026" \
  "http://localhost/neo_apparel/es_api.php?action=inferences&amp;severity=high"

curl -X POST -H "Authorization: Bearer neo-demo-token-2026" \
  -H "Content-Type: application/json" \
  -d '{"subject":"variation","facts":{"stock":3,"velocity_30d":1.1,"days_of_cover":2.7}}' \
  "http://localhost/neo_apparel/es_api.php?action=consult"</pre></div>
<div class="card"><h2 style="margin-top:0">Token API</h2>
<?php if ($isAdmin): ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="token_create"><input name="name" placeholder="Nama integrasi, mis. Dashboard BI" required>
        <div class="row"><?php foreach (['read', 'run', 'consult', 'stylist'] as $s): ?><label class="row" style="margin:0"><input type="checkbox" name="scopes[]" value="<?= $s ?>" <?= $s === 'read' ? 'checked' : '' ?>> <?= $s ?></label><?php endforeach; ?></div><button class="btn primary">Buat token</button></form>
    <table style="margin-top:14px"><thead><tr><th>Nama</th><th>Cakupan</th><th>Terakhir dipakai</th><th></th></tr></thead><tbody>
    <?php foreach ($tokens as $t): ?><tr><td><b><?= e($t['name']) ?></b><br><span class="mono"><?= e($t['token_hint']) ?></span> <?= $t['is_active'] ? '' : badge('cancelled', 'Nonaktif') ?></td><td><?= e($t['scopes']) ?></td><td><?= $t['last_used_at'] ? e(time_ago($t['last_used_at'])) : '-' ?></td>
        <td class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="token_toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn-sm"><?= $t['is_active'] ? 'Cabut' : 'Aktifkan' ?></button></form><form method="post" data-confirm="Hapus token ini?"><?= csrf_field() ?><input type="hidden" name="action" value="token_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn-sm danger">Hapus</button></form></td></tr><?php endforeach; ?></tbody></table>
    <p class="muted small">Token demo bawaan seed (<span class="mono">neo-demo-token-2026</span>) sebaiknya dihapus di lingkungan produksi.</p>
<?php else: ?><p class="muted">Hanya administrator yang dapat mengelola token.</p><?php endif; ?></div></div>
<?php endif; ?>
<?php include 'layout_bottom.php'; ?>
