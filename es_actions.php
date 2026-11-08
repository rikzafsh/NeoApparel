<?php
declare(strict_types=1);
/**
 * Neo Apparel - Eksekutor tindakan Expert System.
 * Setiap tindakan dicatat pada es_actions agar jejak integrasi dapat diaudit.
 *   OAS : oas_task, oas_notify
 *   DSS : dss_start_evaluation
 *   KMS : kms_lookup, kms_request, kms_save_lesson
 *   TPS : tps_cancel_unpaid_order
 */
require_once __DIR__ . '/functions.php';

function es_log_action(PDO $pdo, int $inferenceId, string $type, string $system, ?string $refTable, ?int $refId, string $status, string $detail, ?int $userId): void
{
    $pdo->prepare('INSERT INTO es_actions (inference_id, action_type, target_system, ref_table, ref_id, status, detail, executed_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$inferenceId, $type, $system, $refTable, $refId, $status, mb_substr($detail, 0, 500), $userId]);
}

function es_action_ctx_tpl(array $inf, ?string $tpl, string $fallback): string
{
    $facts = $inf['entity']['facts'] ?? [];
    $facts['subject'] = $inf['entity']['label'] ?? '';
    $facts['title'] = $inf['title'] ?? ($facts['title'] ?? '');
    $text = $tpl ? es_tpl($tpl, $facts) : $fallback;
    return $text !== '' ? $text : $fallback;
}

/** Memilih staf dengan beban tugas terbuka paling sedikit pada peran tertentu. */
function es_pick_assignee(PDO $pdo, string $role): ?int
{
    $st = $pdo->prepare("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
        WHERE r.name = ? AND u.is_active = 1
        ORDER BY (SELECT COUNT(*) FROM tasks t WHERE t.assignee_id = u.id AND t.status IN ('pending','in_progress')) ASC, u.id ASC LIMIT 1");
    $st->execute([$role]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function es_execute_action(PDO $pdo, array $inf, array $action, array $ctx = []): array
{
    $type = (string)($action['type'] ?? '');
    try {
        $res = match ($type) {
            'oas_task' => es_action_oas_task($pdo, $inf, $action, $ctx),
            'oas_notify' => es_action_oas_notify($pdo, $inf, $action, $ctx),
            'dss_start_evaluation' => es_action_dss_start($pdo, $inf, $action, $ctx),
            'kms_lookup' => es_action_kms_lookup($pdo, $inf, $action, $ctx),
            'kms_request' => es_action_kms_request($pdo, $inf, $action, $ctx),
            'kms_save_lesson' => es_action_kms_lesson($pdo, $inf, $action, $ctx),
            'tps_cancel_unpaid_order' => es_action_cancel_order($pdo, $inf, $action, $ctx),
            default => ['status' => 'skipped', 'detail' => 'Jenis tindakan tidak dikenal: ' . $type, 'system' => 'OAS'],
        };
    } catch (Throwable $e) {
        $res = ['status' => 'failed', 'detail' => $e->getMessage(), 'system' => 'OAS'];
    }
    if (empty($res['logged']) && !($type === 'kms_lookup')) {
        es_log_action($pdo, (int)$inf['id'], $type, $res['system'] ?? 'OAS', $res['ref_table'] ?? null, $res['ref_id'] ?? null, $res['status'], $res['detail'] ?? '', $ctx['user_id'] ?? null);
    }
    return $res;
}

/* ---------------- OAS ---------------- */
function es_action_oas_task(PDO $pdo, array $inf, array $a, array $ctx): array
{
    $title = mb_substr(es_action_ctx_tpl($inf, $a['title'] ?? null, 'Tindak lanjut: ' . ($inf['title'] ?? 'Inferensi pakar')), 0, 190);
    // dedupe: jangan membuat tugas yang sama saat tugas lama masih terbuka
    $dup = $pdo->prepare("SELECT id FROM tasks WHERE title = ? AND status IN ('pending','in_progress') LIMIT 1");
    $dup->execute([$title]);
    if ($row = $dup->fetch()) {
        return ['status' => 'skipped', 'system' => 'OAS', 'ref_table' => 'tasks', 'ref_id' => (int)$row['id'], 'detail' => 'Tugas serupa masih terbuka (#' . $row['id'] . ').'];
    }
    $assignee = es_pick_assignee($pdo, (string)($a['assign_role'] ?? 'staff'));
    $assigner = es_system_user($pdo, $ctx['user_id'] ?? null);
    $priority = in_array($a['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $a['priority'] : (match ($inf['severity'] ?? 'medium') { 'critical' => 'urgent', 'high' => 'high', 'low', 'info' => 'low', default => 'medium' });
    $days = max(0, (int)($a['due_days'] ?? 2));
    $desc = trim(($inf['message'] ?? '') . "\n\nRekomendasi sistem pakar:\n" . ($inf['recommendation'] ?? '') . "\n\nSumber: Expert System (inferensi #" . $inf['id'] . ')');
    $pdo->prepare('INSERT INTO tasks (assigner_id, assignee_id, title, description, priority, status, due_date) VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))')
        ->execute([$assigner, $assignee, $title, $desc, $priority, 'pending', $days]);
    $taskId = (int)$pdo->lastInsertId();
    if ($assignee) { notify($pdo, $assignee, 'task', 'Tugas dari Expert System', $title, 'task', $taskId); }
    $pdo->prepare("UPDATE es_inferences SET assigned_to = COALESCE(assigned_to, ?), status = IF(status = 'open', 'in_progress', status) WHERE id = ?")->execute([$assignee, $inf['id']]);
    return ['status' => 'done', 'system' => 'OAS', 'ref_table' => 'tasks', 'ref_id' => $taskId, 'detail' => 'Tugas dibuat: ' . $title];
}

function es_action_oas_notify(PDO $pdo, array $inf, array $a, array $ctx): array
{
    $roles = (array)($a['roles'] ?? ['manager', 'admin']);
    $sev = strtoupper((string)($inf['severity'] ?? 'medium'));
    $n = notify_roles($pdo, $roles, 'expert_system', '[' . $sev . '] ' . ($inf['title'] ?? 'Peringatan Expert System'),
        mb_substr((string)($inf['message'] ?? ''), 0, 240), 'es_inference', (int)$inf['id']);
    return ['status' => $n > 0 ? 'done' : 'skipped', 'system' => 'OAS', 'ref_table' => 'notifications', 'ref_id' => null, 'detail' => "Notifikasi dikirim ke $n pengguna (" . implode(', ', $roles) . ').'];
}

/* ---------------- DSS ---------------- */
function es_action_dss_start(PDO $pdo, array $inf, array $a, array $ctx): array
{
    if (($inf['entity']['type'] ?? '') !== 'variation') {
        return ['status' => 'skipped', 'system' => 'DSS', 'detail' => 'Evaluasi DSS hanya untuk subjek variasi produk.'];
    }
    $vid = (int)$inf['entity']['id'];
    $open = $pdo->prepare("SELECT id, evaluation_code FROM dss_evaluations WHERE target_variation_id = ? AND status IN ('draft','evaluated') ORDER BY id DESC LIMIT 1");
    $open->execute([$vid]);
    if ($row = $open->fetch()) {
        return ['status' => 'skipped', 'system' => 'DSS', 'ref_table' => 'dss_evaluations', 'ref_id' => (int)$row['id'], 'detail' => 'Sudah ada evaluasi terbuka ' . $row['evaluation_code'] . '.'];
    }
    $offers = $pdo->prepare("SELECT sp.* FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
        WHERE sp.variation_id = ? AND sp.is_active = 1 AND s.status = 'active' AND (sp.quote_valid_until IS NULL OR sp.quote_valid_until >= CURDATE())
        ORDER BY sp.purchase_price ASC");
    $offers->execute([$vid]);
    $list = $offers->fetchAll();
    if (count($list) < 2) {
        return ['status' => 'skipped', 'system' => 'DSS', 'detail' => 'Penawaran aktif kurang dari 2; perbandingan supplier belum memungkinkan.'];
    }
    require_once __DIR__ . '/dss_functions.php';
    $userId = es_system_user($pdo, $ctx['user_id'] ?? null);
    $facts = $inf['entity']['facts'];
    $title = 'Evaluasi supplier otomatis: ' . $facts['product_name'] . ' (' . $facts['variant_label'] . ')';
    $code = 'DSS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO dss_evaluations (evaluation_code, title, target_variation_id, method, evaluation_date, decision_notes, created_by) VALUES (?,?,?,'SAW',CURDATE(),?,?)")
            ->execute([$code, mb_substr($title, 0, 190), $vid, 'Dibuat otomatis oleh Expert System (inferensi #' . $inf['id'] . '). Lengkapi kriteria SERVICE sebelum menghitung.', $userId]);
        $eid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO dss_evaluation_criteria (evaluation_id, criterion_id, weight_percent) VALUES (?,?,?)');
        foreach ($pdo->query('SELECT id, default_weight FROM dss_criteria WHERE is_active = 1')->fetchAll() as $c) { $ins->execute([$eid, $c['id'], $c['default_weight']]); }
        $cand = $pdo->prepare("INSERT INTO dss_evaluation_candidates (evaluation_id, supplier_id, supplier_product_id, candidate_status) VALUES (?,?,?,'included')");
        $seen = [];
        foreach ($list as $o) {
            if (isset($seen[$o['supplier_id']])) { continue; }
            $seen[$o['supplier_id']] = true;
            $cand->execute([$eid, $o['supplier_id'], $o['id']]);
            dss_seed_offer_scores($pdo, (int)$pdo->lastInsertId(), (int)$o['supplier_id'], $o, $userId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    notify_roles($pdo, ['admin'], 'dss', 'Evaluasi supplier dibuat otomatis', $title . '. Lengkapi skor SERVICE lalu hitung SAW.', 'dss_evaluation', $eid);
    return ['status' => 'done', 'system' => 'DSS', 'ref_table' => 'dss_evaluations', 'ref_id' => $eid, 'detail' => "Evaluasi $code dibuat dengan " . count($seen) . ' kandidat supplier.'];
}

/* ---------------- KMS ---------------- */
if (!function_exists('es_kms')) {
    function es_kms(): ?PDO
    {
        static $k = false;
        if ($k === false) {
            try { require_once __DIR__ . '/db.php'; $k = neo_connect((string)neo_config('db_kms_name')); } catch (Throwable $e) { $k = null; }
        }
        return $k;
    }
}

/** Cari artikel KMS relevan untuk kata kunci aturan; hasil dicatat sebagai tautan pada inferensi. */
function es_action_kms_lookup(PDO $pdo, array $inf, array $a, array $ctx): array
{
    $keywords = array_values(array_filter(array_map('trim', explode(',', (string)($inf['rule']['kms_keywords'] ?? '')))));
    if (!$keywords) { return ['status' => 'skipped', 'system' => 'KMS', 'detail' => 'Aturan tidak memiliki kata kunci KMS.', 'logged' => true]; }
    $items = es_kms_search($keywords, 3);
    $pdo->prepare("DELETE FROM es_actions WHERE inference_id = ? AND action_type = 'kms_lookup'")->execute([$inf['id']]);
    foreach ($items as $it) {
        es_log_action($pdo, (int)$inf['id'], 'kms_lookup', 'KMS', 'kms_knowledge_items', (int)$it['id'], 'done', $it['title'], $ctx['user_id'] ?? null);
    }
    if (!$items) {
        // celah pengetahuan: ajukan permintaan artikel agar KMS terisi
        return es_action_kms_request($pdo, $inf, ['type' => 'kms_request'], $ctx);
    }
    return ['status' => 'done', 'system' => 'KMS', 'detail' => count($items) . ' artikel KMS terkait ditautkan.', 'logged' => true];
}

function es_kms_search(array $keywords, int $limit = 3): array
{
    $k = es_kms();
    if (!$k) { return []; }
    $score = [];
    $st = $k->prepare("SELECT DISTINCT i.id, i.title, i.summary, i.visibility FROM kms_knowledge_items i
        LEFT JOIN kms_knowledge_tags kt ON kt.knowledge_id = i.id LEFT JOIN kms_tags t ON t.id = kt.tag_id
        WHERE i.status = 'published' AND (i.title LIKE ? OR i.summary LIKE ? OR t.name LIKE ?)");
    foreach ($keywords as $kw) {
        $like = '%' . $kw . '%';
        $st->execute([$like, $like, $like]);
        foreach ($st->fetchAll() as $r) {
            $id = (int)$r['id'];
            $score[$id] = ['row' => $r, 'score' => ($score[$id]['score'] ?? 0) + 1];
        }
    }
    uasort($score, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_map(static fn($s) => $s['row'], array_slice($score, 0, $limit));
}

function es_action_kms_request(PDO $pdo, array $inf, array $a, array $ctx): array
{
    $k = es_kms();
    if (!$k) { return ['status' => 'skipped', 'system' => 'KMS', 'detail' => 'Database KMS tidak tersedia.', 'logged' => true]; }
    $kw = trim((string)explode(',', (string)($inf['rule']['kms_keywords'] ?? 'panduan'))[0]);
    $title = mb_substr('Butuh panduan: ' . ($inf['rule']['name'] ?? $kw), 0, 190);
    $dup = $k->prepare("SELECT id FROM kms_knowledge_requests WHERE title = ? AND status IN ('open','in_progress') LIMIT 1");
    $dup->execute([$title]);
    if ($row = $dup->fetch()) {
        es_log_action($pdo, (int)$inf['id'], 'kms_request', 'KMS', 'kms_knowledge_requests', (int)$row['id'], 'skipped', 'Permintaan serupa masih terbuka.', $ctx['user_id'] ?? null);
        return ['status' => 'skipped', 'system' => 'KMS', 'detail' => 'Permintaan serupa sudah ada.', 'logged' => true];
    }
    $uid = es_system_user($pdo, $ctx['user_id'] ?? null);
    $k->prepare("INSERT INTO kms_knowledge_requests (requester_user_id, title, description, priority, status) VALUES (?,?,?,?, 'open')")
        ->execute([$uid, $title, "Expert System menemukan kasus yang belum punya panduan di KMS.\nKasus: " . ($inf['title'] ?? '') . "\nKata kunci: " . ($inf['rule']['kms_keywords'] ?? ''), in_array($inf['severity'] ?? '', ['critical', 'high'], true) ? 'high' : 'medium']);
    $rid = (int)$k->lastInsertId();
    es_log_action($pdo, (int)$inf['id'], 'kms_request', 'KMS', 'kms_knowledge_requests', $rid, 'done', 'Permintaan pengetahuan dibuat: ' . $title, $ctx['user_id'] ?? null);
    return ['status' => 'done', 'system' => 'KMS', 'ref_table' => 'kms_knowledge_requests', 'ref_id' => $rid, 'detail' => 'Celah pengetahuan dicatat di KMS.', 'logged' => true];
}

/** Simpan penyelesaian inferensi sebagai draf artikel "pembelajaran" di KMS (organizational learning). */
function es_action_kms_lesson(PDO $pdo, array $inf, array $a, array $ctx): array
{
    $k = es_kms();
    if (!$k) { return ['status' => 'skipped', 'system' => 'KMS', 'detail' => 'Database KMS tidak tersedia.']; }
    $uid = es_system_user($pdo, $ctx['user_id'] ?? null);
    $note = trim((string)($ctx['note'] ?? ''));
    $title = mb_substr('Pembelajaran: ' . ($inf['title'] ?? 'Kasus Expert System'), 0, 190);
    $slug = slugify($title) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $cat = $k->query("SELECT id FROM kms_categories ORDER BY id LIMIT 1")->fetchColumn() ?: null;
    $content = "## Kasus\n" . ($inf['message'] ?? '') . "\n\n## Rekomendasi sistem pakar\n" . ($inf['recommendation'] ?? '') . "\n\n## Penyelesaian\n" . ($note ?: '(isi langkah penyelesaian yang dilakukan)') . "\n\n_Dibuat otomatis dari inferensi #" . $inf['id'] . '._';
    $summary = mb_substr((string)($inf['message'] ?? ''), 0, 240);
    $k->prepare("INSERT INTO kms_knowledge_items (title, slug, summary, knowledge_nature, knowledge_type, category_id, status, visibility, owner_user_id, created_by_user_id)
        VALUES (?,?,?, 'combined', 'lesson_learned', ?, 'draft', 'employees', ?, ?)")->execute([$title, $slug, $summary, $cat, $uid, $uid]);
    $kid = (int)$k->lastInsertId();
    $k->prepare("INSERT INTO kms_knowledge_revisions (knowledge_id, version_number, title, content, change_summary, created_by_user_id, is_current) VALUES (?,1,?,?,?,?,1)")
        ->execute([$kid, $title, $content, 'Dibuat dari Expert System', $uid]);
    return ['status' => 'done', 'system' => 'KMS', 'ref_table' => 'kms_knowledge_items', 'ref_id' => $kid, 'detail' => 'Draf artikel pembelajaran dibuat di KMS.'];
}

/* ---------------- TPS ---------------- */
function es_action_cancel_order(PDO $pdo, array $inf, array $a, array $ctx): array
{
    if (($inf['entity']['type'] ?? '') !== 'order') { return ['status' => 'skipped', 'system' => 'TPS', 'detail' => 'Bukan subjek order.']; }
    $oid = (int)$inf['entity']['id'];
    [$ok, $msg] = cancel_unpaid_order($pdo, $oid, $ctx['user_id'] ?? null, 'Dibatalkan via Expert System (pembayaran kedaluwarsa)');
    return ['status' => $ok ? 'done' : 'skipped', 'system' => 'TPS', 'ref_table' => 'orders', 'ref_id' => $oid, 'detail' => $msg];
}
