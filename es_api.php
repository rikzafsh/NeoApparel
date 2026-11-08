<?php
/**
 * Neo Apparel - REST API Expert System (JSON).
 * Autentikasi: sesi login (POST wajib header X-CSRF-Token) atau "Authorization: Bearer <token>".
 * Aksi: health, inferences, inference, rules, facts, run, consult, stylist, inference_status
 */
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
require_once 'es_stylist_engine.php';

header('X-Content-Type-Options: nosniff');
$action = $_GET['action'] ?? 'health';
$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode($raw, true) ?? []) : $_POST;
    if (!is_array($body)) { $body = []; }
}

// ---- autentikasi ----
$scopes = []; $actor = null; $via = null;
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strtolower($k) === 'authorization') { $auth = $v; } } }
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    $st = $pdo->prepare('SELECT * FROM es_api_tokens WHERE token_hash = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())');
    $st->execute([hash('sha256', trim($m[1]))]);
    $tok = $st->fetch();
    if (!$tok) { json_out(['ok' => false, 'error' => 'Token tidak valid atau tidak aktif.'], 401); }
    $scopes = array_filter(explode(',', (string)$tok['scopes']));
    $actor = $tok['owner_user_id'] ? (int)$tok['owner_user_id'] : null;
    $pdo->prepare('UPDATE es_api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$tok['id']]);
    $via = 'token:' . $tok['name'];
} elseif (session_user($pdo)) {
    if ($method === 'POST') { check_csrf(); }
    $actor = (int)$_SESSION['user_id'];
    if (is_employee()) { $scopes = ['read', 'consult']; if (has_any(['staff', 'manager', 'admin'])) { $scopes[] = 'run'; } }
    if (has_role('customer') || is_employee()) { $scopes[] = 'stylist'; }
    $via = 'session';
} elseif ($action !== 'health') {
    json_out(['ok' => false, 'error' => 'Autentikasi diperlukan (sesi login atau Bearer token).'], 401);
}
$need = static function (string $scope) use (&$scopes): void {
    if (!in_array($scope, $scopes, true)) { json_out(['ok' => false, 'error' => "Cakupan '$scope' diperlukan."], 403); }
};

try {
    switch ($action) {
        case 'health':
            json_out(['ok' => true, 'app' => APP_NAME, 'version' => APP_VERSION, 'engine' => ES_VERSION, 'time' => date('c'),
                'rules_active' => (int)$pdo->query('SELECT COUNT(*) FROM es_rules WHERE is_active=1')->fetchColumn(),
                'inferences_active' => (int)$pdo->query("SELECT COUNT(*) FROM es_inferences WHERE status IN ('open','acknowledged','in_progress')")->fetchColumn()]);

        case 'inferences':
            $need('read');
            $w = ['1=1']; $a = [];
            $status = $_GET['status'] ?? 'active';
            if ($status === 'active') { $w[] = "status IN ('open','acknowledged','in_progress')"; } elseif ($status !== 'all') { $w[] = 'status = ?'; $a[] = $status; }
            foreach (['domain', 'severity', 'subject_type'] as $f) { if (!empty($_GET[$f])) { $w[] = "$f = ?"; $a[] = $_GET[$f]; } }
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
            $st = $pdo->prepare('SELECT id, rule_code, domain, subject_type, subject_id, subject_label, severity, certainty, title, message, recommendation, status, occurrences, created_at, updated_at FROM es_inferences WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(severity,'critical','high','medium','low','info'), certainty DESC LIMIT $limit");
            $st->execute($a);
            json_out(['ok' => true, 'count' => $st->rowCount(), 'data' => $st->fetchAll()]);

        case 'inference':
            $need('read');
            $st = $pdo->prepare('SELECT * FROM es_inferences WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]); $inf = $st->fetch();
            if (!$inf) { json_out(['ok' => false, 'error' => 'Tidak ditemukan.'], 404); }
            $inf['facts'] = json_decode((string)$inf['facts'], true); $inf['trace'] = json_decode((string)$inf['trace'], true);
            $st = $pdo->prepare('SELECT action_type, target_system, ref_table, ref_id, status, detail, created_at FROM es_actions WHERE inference_id = ? ORDER BY id'); $st->execute([$inf['id']]);
            $inf['actions'] = $st->fetchAll();
            json_out(['ok' => true, 'data' => $inf]);

        case 'rules':
            $need('read');
            $rows = $pdo->query('SELECT id, code, name, domain, subject_type, rule_kind, severity, cf, priority, is_active, conditions, derives FROM es_rules ORDER BY domain, priority DESC')->fetchAll();
            foreach ($rows as &$r) { $r['conditions'] = json_decode((string)$r['conditions'], true); $r['derives'] = $r['derives'] ? json_decode((string)$r['derives'], true) : null; }
            json_out(['ok' => true, 'count' => count($rows), 'data' => $rows]);

        case 'facts':
            $need('read');
            $type = $_GET['subject'] ?? 'variation';
            $params = es_params($pdo); $rules = es_load_rules($pdo);
            $ents = es_collect($pdo, $rules, $params);
            es_forward_chain($ents, $rules, $params); es_aggregate_global($ents); es_forward_chain($ents, $rules, $params);
            $out = [];
            foreach ($ents as $e) { if ($e['type'] === $type) { $out[] = ['id' => $e['id'], 'label' => $e['label'], 'facts' => $e['facts'], 'derived' => array_keys($e['src'])]; } }
            json_out(['ok' => true, 'subject' => $type, 'count' => count($out), 'data' => array_slice($out, 0, max(1, min(200, (int)($_GET['limit'] ?? 50))))]);

        case 'run':
            if ($method !== 'POST') { json_out(['ok' => false, 'error' => 'Gunakan POST.'], 405); }
            $need('run');
            $doms = array_values(array_intersect(['TPS', 'OAS', 'KMS', 'MIS', 'DSS', 'CROSS'], (array)($body['domains'] ?? [])));
            $r = es_run($pdo, ['trigger' => 'api', 'label' => $via, 'user_id' => $actor, 'domains' => $doms ? array_merge($doms, ['STYLE']) : null, 'dry_run' => !empty($body['dry_run'])]);
            if (!empty($r['results'])) {
                $r['results'] = array_map(static fn($x) => ['rule' => $x['rule']['code'], 'subject' => $x['entity']['label'], 'severity' => $x['rule']['severity'], 'certainty' => $x['cf'], 'title' => $x['title'], 'message' => $x['message']], $r['results']);
            }
            json_out(['ok' => true, 'summary' => $r]);

        case 'consult':
            if ($method !== 'POST') { json_out(['ok' => false, 'error' => 'Gunakan POST.'], 405); }
            $need('consult');
            $facts = $body['facts'] ?? null;
            if (!is_array($facts) || !$facts) { json_out(['ok' => false, 'error' => 'Field "facts" (objek) wajib diisi.'], 422); }
            $subject = in_array($body['subject'] ?? 'variation', ['variation', 'order', 'supplier', 'task', 'approval', 'knowledge_request', 'global'], true) ? ($body['subject'] ?? 'variation') : 'variation';
            json_out(['ok' => true, 'result' => es_consult($pdo, $subject, $facts)]);

        case 'stylist':
            if ($method !== 'POST') { json_out(['ok' => false, 'error' => 'Gunakan POST.'], 405); }
            $need('stylist');
            $r = es_stylist_recommend($pdo, (array)($body['answers'] ?? $body), (int)($body['limit'] ?? 6));
            $recs = array_map(static fn($x) => ['product_id' => $x['product']['id'], 'product' => $x['product']['name'], 'category' => $x['product']['category'], 'variation_id' => (int)$x['variation']['vid'], 'color' => $x['variation']['color'],
                'size' => $x['variation']['size'], 'price' => (float)$x['variation']['price'], 'match_pct' => $x['match'], 'reasons' => $x['reasons'], 'notes' => $x['notes'], 'rules' => $x['rules']], $r['recommendations']);
            json_out(['ok' => true, 'answers' => $r['answers'], 'recommendations' => $recs, 'fired_rules' => array_column($r['fired'], 'code')]);

        case 'inference_status':
            if ($method !== 'POST') { json_out(['ok' => false, 'error' => 'Gunakan POST.'], 405); }
            $need('run');
            $to = $body['status'] ?? '';
            if (!in_array($to, ['acknowledged', 'resolved', 'dismissed', 'open'], true)) { json_out(['ok' => false, 'error' => 'Status tidak valid.'], 422); }
            $pdo->prepare("UPDATE es_inferences SET status = ?, resolved_by = IF(? IN ('resolved','dismissed'), ?, resolved_by), resolved_at = IF(? IN ('resolved','dismissed'), NOW(), resolved_at), resolution_note = ? WHERE id = ?")
                ->execute([$to, $to, $actor, $to, mb_substr((string)($body['note'] ?? ''), 0, 480) ?: null, (int)($body['id'] ?? 0)]);
            json_out(['ok' => true]);

        default:
            json_out(['ok' => false, 'error' => 'Aksi tidak dikenal.', 'actions' => ['health', 'inferences', 'inference', 'rules', 'facts', 'run', 'consult', 'stylist', 'inference_status']], 404);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => neo_config('debug') ? $e->getMessage() : 'Terjadi kesalahan pada server.'], 500);
}
