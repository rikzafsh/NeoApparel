<?php
declare(strict_types=1);
/**
 * Neo Apparel - Expert System Engine
 * ------------------------------------------------------------------
 * Arsitektur:
 *   1. Fact collectors   : membaca TPS, OAS, MIS, DSS, KMS menjadi "working memory" (entitas + fakta).
 *   2. Knowledge base    : aturan IF-THEN (tabel es_rules, JSON) + parameter ambang (es_parameters).
 *   3. Inference engine  : forward chaining (derive rules berantai sampai stabil) lalu inference rules,
 *                          dengan certainty factor gaya MYCIN dan resolusi konflik berdasar prioritas.
 *   4. Explanation       : setiap kesimpulan menyimpan fakta pendukung dan jejak aturan ("mengapa").
 *   5. Action executor   : (es_actions.php) mengirim tindakan ke OAS, DSS, KMS dan TPS.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const ES_VERSION = '1.0';

/** Koneksi ke database KMS (null bila tidak tersedia); tidak menghentikan aplikasi saat gagal. */
if (!function_exists('es_kms')) {
    function es_kms(): ?PDO
    {
        static $k = false;
        if ($k === false) {
            try { $k = neo_connect((string)neo_config('db_kms_name')); } catch (Throwable $e) { $k = null; }
        }
        return $k;
    }
}

/* =====================================================================
 * Parameter & aturan
 * ===================================================================== */
function es_params(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        foreach ($pdo->query('SELECT param_key, param_value FROM es_parameters')->fetchAll() as $r) {
            $cache[$key][$r['param_key']] = (float)$r['param_value'];
        }
    }
    return $cache[$key];
}

function es_load_rules(PDO $pdo, ?array $domains = null, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM es_rules WHERE 1=1';
    $args = [];
    if ($onlyActive) { $sql .= ' AND is_active = 1'; }
    if ($domains) {
        $sql .= ' AND domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')';
        $args = array_values($domains);
    }
    $sql .= ' ORDER BY priority DESC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rules = [];
    foreach ($st->fetchAll() as $r) {
        $r['conditions'] = json_decode((string)$r['conditions'], true) ?: [];
        $r['derives'] = $r['derives'] ? (json_decode((string)$r['derives'], true) ?: []) : [];
        $r['actions'] = $r['actions'] ? (json_decode((string)$r['actions'], true) ?: []) : [];
        $r['cf'] = (float)$r['cf'];
        $rules[] = $r;
    }
    return $rules;
}

/* =====================================================================
 * Evaluasi kondisi
 * ===================================================================== */
function es_resolve_value(mixed $v, array $facts, array $params): mixed
{
    if (is_array($v)) {
        if (isset($v['param'])) { return $params[$v['param']] ?? null; }
        if (isset($v['ref'])) { return $facts[$v['ref']] ?? null; }
    }
    return $v;
}

/** Mengembalikan [terpenuhi?, certainty]. $used diisi nama fakta yang dipakai. */
function es_eval(array $node, array $entity, array $params, array &$used): array
{
    $facts = $entity['facts'];
    if (isset($node['all'])) {
        $cf = 1.0;
        foreach ($node['all'] as $child) {
            [$ok, $c] = es_eval($child, $entity, $params, $used);
            if (!$ok) { return [false, 0.0]; }
            $cf = min($cf, $c);
        }
        return [true, $cf];
    }
    if (isset($node['any'])) {
        $best = 0.0; $hit = false;
        foreach ($node['any'] as $child) {
            [$ok, $c] = es_eval($child, $entity, $params, $used);
            if ($ok) { $hit = true; $best = max($best, $c); }
        }
        return [$hit, $hit ? $best : 0.0];
    }
    if (isset($node['not'])) {
        $tmp = [];
        [$ok] = es_eval($node['not'], $entity, $params, $tmp);
        return [!$ok, 1.0];
    }
    $f = (string)($node['f'] ?? '');
    $op = (string)($node['op'] ?? '==');
    $actual = $facts[$f] ?? null;
    $want = es_resolve_value($node['v'] ?? null, $facts, $params);
    $used[$f] = $actual;
    if ($op === 'exists') { return [$actual !== null, 1.0]; }
    if ($op === 'is_null') { return [$actual === null, 1.0]; }
    if ($actual === null) { return [false, 0.0]; }
    $ok = match ($op) {
        '==' => is_numeric($actual) && is_numeric($want) ? (float)$actual == (float)$want : (string)$actual === (string)$want,
        '!=' => is_numeric($actual) && is_numeric($want) ? (float)$actual != (float)$want : (string)$actual !== (string)$want,
        '>' => (float)$actual > (float)$want,
        '>=' => (float)$actual >= (float)$want,
        '<' => (float)$actual < (float)$want,
        '<=' => (float)$actual <= (float)$want,
        'in' => in_array(is_numeric($actual) ? (string)$actual : $actual, array_map(static fn($x) => is_numeric($x) ? (string)$x : $x, (array)$want), true),
        'not_in' => !in_array(is_numeric($actual) ? (string)$actual : $actual, array_map(static fn($x) => is_numeric($x) ? (string)$x : $x, (array)$want), true),
        'between' => is_array($want) && count($want) === 2 && (float)$actual >= (float)$want[0] && (float)$actual <= (float)$want[1],
        'contains' => mb_stripos((string)$actual, (string)$want) !== false,
        default => false,
    };
    return [$ok, $ok ? (float)($entity['cf'][$f] ?? 1.0) : 0.0];
}

function es_cf_combine(float $a, float $b): float { return $a + $b * (1 - $a); }

function es_fmt(mixed $value, string $fmt): string
{
    if ($value === null) { return '-'; }
    return match ($fmt) {
        'rp' => rupiah($value),
        'int' => number_format((float)$value, 0, ',', '.'),
        'n1' => number_format((float)$value, 1, ',', '.'),
        'n2' => number_format((float)$value, 2, ',', '.'),
        'pct' => number_format((float)$value, 1, ',', '.') . '%',
        'days' => number_format((float)$value, 1, ',', '.') . ' hari',
        default => is_float($value) ? rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') : (string)$value,
    };
}
function es_tpl(?string $tpl, array $facts): string
{
    if ($tpl === null || $tpl === '') { return ''; }
    return (string)preg_replace_callback('/\{(\w+)(?:\|(\w+))?\}/', static function (array $m) use ($facts): string {
        return es_fmt($facts[$m[1]] ?? null, $m[2] ?? '');
    }, $tpl);
}

/* =====================================================================
 * Forward chaining
 * ===================================================================== */
/**
 * Menjalankan derive rules sampai tidak ada perubahan (fixpoint).
 * Entitas dimodifikasi in-place; mengembalikan jumlah aturan yang menyala.
 */
function es_forward_chain(array &$entities, array $rules, array $params, int $maxPass = 6): array
{
    $fired = 0; $evaluated = 0;
    $derive = array_values(array_filter($rules, static fn($r) => $r['rule_kind'] === 'derive'));
    for ($pass = 0; $pass < $maxPass; $pass++) {
        $changed = false;
        foreach ($derive as $rule) {
            foreach ($entities as $i => $ent) {
                if ($ent['type'] !== $rule['subject_type']) { continue; }
                $evaluated++;
                $used = [];
                [$ok, $cf] = es_eval($rule['conditions'], $ent, $params, $used);
                if (!$ok) { continue; }
                $ruleCf = $rule['cf'] * $cf;
                foreach ($rule['derives'] as $k => $val) {
                    $cur = $entities[$i]['src'][$k] ?? null;
                    if (array_key_exists($k, $entities[$i]['facts']) && $cur === null) { continue; } // jangan menimpa fakta dasar
                    if ($cur !== null && $cur['rule'] === $rule['code']) { continue; }               // sudah diterapkan aturan ini
                    if ($cur !== null && (string)$entities[$i]['facts'][$k] !== (string)$val) { continue; } // konflik: prioritas lebih tinggi menang
                    if ($cur !== null) {                                                              // nilai sama: gabungkan CF
                        $entities[$i]['cf'][$k] = es_cf_combine((float)($entities[$i]['cf'][$k] ?? 0), $ruleCf);
                        $entities[$i]['src'][$k]['also'][] = $rule['code'];
                    } else {
                        $entities[$i]['facts'][$k] = $val;
                        $entities[$i]['cf'][$k] = $ruleCf;
                        $entities[$i]['src'][$k] = ['rule' => $rule['code'], 'name' => $rule['name'], 'priority' => $rule['priority'], 'also' => []];
                    }
                    $changed = true;
                }
                $fired++;
            }
        }
        if (!$changed) { break; }
    }
    return ['fired' => $fired, 'evaluated' => $evaluated];
}

/** Evaluasi inference rules terhadap seluruh entitas. */
function es_infer(array $entities, array $rules, array $params): array
{
    $results = []; $evaluated = 0;
    foreach ($rules as $rule) {
        if (!in_array($rule['rule_kind'], ['inference', 'recommend'], true)) { continue; }
        foreach ($entities as $ent) {
            if ($ent['type'] !== $rule['subject_type']) { continue; }
            $evaluated++;
            $used = [];
            [$ok, $cf] = es_eval($rule['conditions'], $ent, $params, $used);
            if (!$ok) { continue; }
            $facts = $ent['facts'];
            $trace = [];
            foreach (array_keys($used) as $f) {
                if (isset($ent['src'][$f])) {
                    $s = $ent['src'][$f];
                    $trace[] = ['type' => 'derived', 'fact' => $f, 'value' => $facts[$f] ?? null, 'rule' => $s['rule'], 'name' => $s['name'], 'cf' => round((float)($ent['cf'][$f] ?? 1), 3), 'also' => $s['also'] ?? []];
                }
            }
            $trace[] = ['type' => 'inference', 'rule' => $rule['code'], 'name' => $rule['name'], 'cf' => round($rule['cf'] * $cf, 3)];
            $results[] = [
                'rule' => $rule, 'entity' => $ent, 'cf' => round($rule['cf'] * $cf, 3),
                'title' => es_tpl($rule['title_tpl'] ?: $rule['name'], $facts),
                'message' => es_tpl($rule['message_tpl'], $facts),
                'recommendation' => es_tpl($rule['recommendation_tpl'], $facts),
                'facts_used' => $used, 'trace' => $trace,
            ];
        }
    }
    return ['results' => $results, 'evaluated' => $evaluated];
}

/* =====================================================================
 * Fact collectors (working memory)
 * ===================================================================== */
function es_entity(string $type, int $id, string $label, array $facts): array
{
    return ['type' => $type, 'id' => $id, 'label' => $label, 'facts' => $facts, 'cf' => [], 'src' => []];
}

function es_collect_variations(PDO $pdo, array $params, ?array $ids = null): array
{
    $where = 'v.is_active = 1 AND p.is_active = 1';
    $args = [];
    if ($ids) { $where .= ' AND v.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
    $rows = $pdo->query("SELECT v.id, v.sku, v.color, v.size, v.price, v.stock, v.product_id, p.name AS product_name, c.name AS category,
            TIMESTAMPDIFF(DAY, v.created_at, NOW()) AS days_since_created
        FROM product_variations v JOIN products p ON p.id = v.product_id
        LEFT JOIN product_categories pc ON pc.product_id = p.id LEFT JOIN categories c ON c.id = pc.category_id
        WHERE $where GROUP BY v.id")->fetchAll();

    $sales = [];
    foreach ($pdo->query("SELECT oi.variation_id AS vid,
            SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN oi.quantity ELSE 0 END) AS s7,
            SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND o.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN oi.quantity ELSE 0 END) AS sp7,
            SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN oi.quantity ELSE 0 END) AS s30,
            SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN oi.subtotal ELSE 0 END) AS rev30,
            TIMESTAMPDIFF(DAY, MAX(o.created_at), NOW()) AS last_days
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.payment_status = 'paid' AND o.status <> 'cancelled' AND oi.variation_id IS NOT NULL
        GROUP BY oi.variation_id")->fetchAll() as $r) { $sales[(int)$r['vid']] = $r; }

    $cart = [];
    foreach ($pdo->query('SELECT variation_id, SUM(quantity) q FROM cart_items GROUP BY variation_id')->fetchAll() as $r) { $cart[(int)$r['variation_id']] = (int)$r['q']; }

    $incoming = [];
    foreach ($pdo->query("SELECT i.variation_id, SUM(GREATEST(i.quantity_ordered - i.quantity_received, 0)) q
        FROM supplier_purchase_order_items i JOIN supplier_purchase_orders po ON po.id = i.purchase_order_id
        WHERE po.status IN ('ordered','partially_received') GROUP BY i.variation_id")->fetchAll() as $r) { $incoming[(int)$r['variation_id']] = (int)$r['q']; }

    $ratings = [];
    foreach ($pdo->query("SELECT product_id, AVG(rating) a, COUNT(*) n FROM product_reviews WHERE status = 'published' GROUP BY product_id")->fetchAll() as $r) { $ratings[(int)$r['product_id']] = $r; }

    // DSS: penawaran aktif, keputusan yang disetujui, evaluasi terbuka
    $offers = []; $approved = []; $openEval = [];
    try {
        foreach ($pdo->query("SELECT sp.variation_id, sp.purchase_price, sp.lead_time_days, s.id sid, s.name sname
            FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
            WHERE sp.is_active = 1 AND s.status = 'active' AND (sp.quote_valid_until IS NULL OR sp.quote_valid_until >= CURDATE())
            ORDER BY sp.purchase_price ASC")->fetchAll() as $r) { $offers[(int)$r['variation_id']][] = $r; }
        foreach ($pdo->query("SELECT e.target_variation_id vid, e.id eid, s.id sid, s.name sname
            FROM dss_evaluations e JOIN dss_evaluation_candidates c ON c.evaluation_id = e.id
            JOIN dss_evaluation_results r ON r.candidate_id = c.id AND r.is_recommended = 1
            JOIN suppliers s ON s.id = c.supplier_id
            WHERE e.status = 'approved' AND e.target_variation_id IS NOT NULL ORDER BY e.id ASC")->fetchAll() as $r) { $approved[(int)$r['vid']] = $r; }
        foreach ($pdo->query("SELECT target_variation_id vid, id eid, TIMESTAMPDIFF(DAY, created_at, NOW()) age
            FROM dss_evaluations WHERE status IN ('draft','evaluated') AND target_variation_id IS NOT NULL ORDER BY id ASC")->fetchAll() as $r) { $openEval[(int)$r['vid']] = $r; }
    } catch (Throwable $e) { /* modul DSS belum terpasang: lewati */ }

    $defaultLead = (float)($params['default_lead_time_days'] ?? 7);
    $targetCover = (float)($params['target_cover_days'] ?? 30);
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $s = $sales[$id] ?? ['s7' => 0, 'sp7' => 0, 's30' => 0, 'rev30' => 0, 'last_days' => null];
        $s7 = (int)$s['s7']; $s30 = (int)$s['s30'];
        $velocity = $s30 / 30.0;
        $stock = (int)$r['stock'];
        $in = $incoming[$id] ?? 0;
        $cover = $velocity > 0 ? $stock / $velocity : 999.0;
        $effCover = $velocity > 0 ? ($stock + $in) / $velocity : 999.0;
        $trend = $velocity > 0 ? (($s7 / 7.0) - $velocity) / $velocity * 100.0 : 0.0;
        $lastDays = $s['last_days'] !== null ? (float)$s['last_days'] : (float)min((int)$r['days_since_created'], 999);
        $offerList = $offers[$id] ?? [];
        $best = $offerList[0] ?? null;
        $lead = $best ? (float)min(array_map(static fn($o) => (float)$o['lead_time_days'], $offerList)) : $defaultLead;
        $price = (float)$r['price'];
        $facts = [
            'sku' => $r['sku'], 'product_name' => $r['product_name'], 'category' => $r['category'] ?? '-',
            'variant_label' => trim(($r['color'] ?: '-') . ' / ' . ($r['size'] ?: '-')),
            'stock' => $stock, 'price' => $price,
            'sold_7d' => $s7, 'sold_prev_7d' => (int)$s['sp7'], 'sold_30d' => $s30, 'revenue_30d' => (float)$s['rev30'],
            'velocity_30d' => round($velocity, 3), 'days_of_cover' => round(min($cover, 999.0), 1), 'effective_cover_days' => round(min($effCover, 999.0), 1),
            'sales_trend_pct' => round($trend, 1), 'days_since_last_sale' => $lastDays, 'days_since_created' => (int)$r['days_since_created'],
            'cart_qty' => $cart[$id] ?? 0, 'incoming_po_qty' => $in, 'has_incoming_po' => $in > 0 ? 1 : 0,
            'lead_time_days' => $lead,
            'suggested_restock_qty' => (int)max(0, ceil($velocity * ($targetCover + $lead)) - $stock - $in),
            'offer_count' => count($offerList),
            'rating_avg' => isset($ratings[(int)$r['product_id']]) ? round((float)$ratings[(int)$r['product_id']]['a'], 2) : null,
            'review_count' => isset($ratings[(int)$r['product_id']]) ? (int)$ratings[(int)$r['product_id']]['n'] : 0,
            'dss_has_open_evaluation' => isset($openEval[$id]) ? 1 : 0,
            'dss_open_evaluation_age_days' => isset($openEval[$id]) ? (int)$openEval[$id]['age'] : null,
            'dss_has_approved_supplier' => isset($approved[$id]) ? 1 : 0,
            'dss_approved_supplier' => $approved[$id]['sname'] ?? null,
            'dss_approved_evaluation_id' => isset($approved[$id]) ? (int)$approved[$id]['eid'] : null,
            'best_offer_price' => $best ? (float)$best['purchase_price'] : null,
            'best_offer_supplier' => $best['sname'] ?? null,
            'margin_pct' => ($best && $price > 0) ? round(($price - (float)$best['purchase_price']) / $price * 100, 1) : null,
        ];
        $ent = es_entity('variation', $id, $r['product_name'] . ' - ' . $facts['variant_label'] . ' (' . $r['sku'] . ')', $facts);
        $out[] = $ent;
    }
    return $out;
}

function es_collect_orders(PDO $pdo, array $params, ?array $ids = null): array
{
    $where = "o.status IN ('pending','processing','shipped')";
    if ($ids) { $where = 'o.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
    $out = [];
    foreach ($pdo->query("SELECT o.id, o.order_number, o.status, o.payment_status, o.shipping_status, o.total_price, u.name buyer,
            TIMESTAMPDIFF(HOUR, o.created_at, NOW()) age_hours, TIMESTAMPDIFF(HOUR, o.updated_at, NOW()) idle_hours,
            (SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE order_id = o.id) units
        FROM orders o JOIN users u ON u.id = o.user_id WHERE $where")->fetchAll() as $r) {
        $out[] = es_entity('order', (int)$r['id'], 'Order ' . $r['order_number'], [
            'order_number' => $r['order_number'], 'status' => $r['status'], 'payment_status' => $r['payment_status'], 'shipping_status' => $r['shipping_status'],
            'total_price' => (float)$r['total_price'], 'buyer' => $r['buyer'], 'age_hours' => (int)$r['age_hours'], 'idle_hours' => (int)$r['idle_hours'], 'units' => (int)$r['units'],
        ]);
    }
    return $out;
}

function es_collect_tasks(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT t.id, t.title, t.priority, t.status, t.due_date, u.name assignee, t.assignee_id,
            CASE WHEN t.due_date IS NOT NULL AND t.due_date < NOW() THEN 1 ELSE 0 END overdue,
            CASE WHEN t.due_date IS NOT NULL AND t.due_date < NOW() THEN TIMESTAMPDIFF(DAY, t.due_date, NOW()) ELSE 0 END overdue_days,
            (SELECT COUNT(*) FROM tasks x WHERE x.assignee_id = t.assignee_id AND x.status IN ('pending','in_progress')) assignee_open
        FROM tasks t LEFT JOIN users u ON u.id = t.assignee_id WHERE t.status IN ('pending','in_progress')")->fetchAll() as $r) {
        $out[] = es_entity('task', (int)$r['id'], 'Tugas: ' . $r['title'], [
            'title' => $r['title'], 'priority' => $r['priority'], 'status' => $r['status'], 'assignee' => $r['assignee'] ?? '(belum ditugaskan)',
            'is_overdue' => (int)$r['overdue'], 'overdue_days' => (int)$r['overdue_days'], 'assignee_open_count' => (int)$r['assignee_open'],
        ]);
    }
    return $out;
}

function es_collect_approvals(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT a.id, d.title, u.name approver, TIMESTAMPDIFF(DAY, a.created_at, NOW()) age
        FROM approvals a JOIN documents d ON d.id = a.document_id JOIN users u ON u.id = a.approver_id WHERE a.status = 'pending'")->fetchAll() as $r) {
        $out[] = es_entity('approval', (int)$r['id'], 'Persetujuan: ' . $r['title'], [
            'document_title' => $r['title'], 'approver' => $r['approver'], 'age_days' => (int)$r['age'],
        ]);
    }
    return $out;
}

function es_collect_suppliers(PDO $pdo): array
{
    $out = [];
    try {
        $hist = [];
        foreach ($pdo->query("SELECT po.supplier_id sid, COUNT(DISTINCT po.id) po_count,
                SUM(i.quantity_received) rec, SUM(i.quantity_accepted) acc
            FROM supplier_purchase_orders po JOIN supplier_purchase_order_items i ON i.purchase_order_id = po.id
            WHERE po.status IN ('partially_received','received') GROUP BY po.supplier_id")->fetchAll() as $r) { $hist[(int)$r['sid']] = $r; }
        $timing = [];
        foreach ($pdo->query("SELECT supplier_id sid, COUNT(*) n, SUM(CASE WHEN received_date <= expected_delivery_date THEN 1 ELSE 0 END) ontime,
                AVG(GREATEST(DATEDIFF(received_date, expected_delivery_date), 0)) delay
            FROM supplier_purchase_orders WHERE status IN ('partially_received','received') AND received_date IS NOT NULL AND expected_delivery_date IS NOT NULL
            GROUP BY supplier_id")->fetchAll() as $r) { $timing[(int)$r['sid']] = $r; }
        foreach ($pdo->query("SELECT s.id, s.name, s.status,
                (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = s.id AND sp.is_active = 1 AND (sp.quote_valid_until IS NULL OR sp.quote_valid_until >= CURDATE())) offers_active,
                (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = s.id AND sp.is_active = 1 AND sp.quote_valid_until < CURDATE()) offers_expired
            FROM suppliers s WHERE s.status = 'active'")->fetchAll() as $r) {
            $id = (int)$r['id']; $h = $hist[$id] ?? null; $t = $timing[$id] ?? null;
            $out[] = es_entity('supplier', $id, 'Supplier ' . $r['name'], [
                'supplier_name' => $r['name'], 'status' => $r['status'],
                'po_count' => $h ? (int)$h['po_count'] : 0,
                'acceptance_rate' => ($h && (float)$h['rec'] > 0) ? round((float)$h['acc'] / (float)$h['rec'] * 100, 1) : null,
                'on_time_rate' => ($t && (int)$t['n'] > 0) ? round((float)$t['ontime'] / (float)$t['n'] * 100, 1) : null,
                'avg_delay_days' => $t ? round((float)$t['delay'], 1) : null,
                'offers_active' => (int)$r['offers_active'], 'offers_expired' => (int)$r['offers_expired'],
            ]);
        }
    } catch (Throwable $e) { /* DSS belum terpasang */ }
    return $out;
}

function es_collect_kms_requests(): array
{
    $out = [];
    try {
        $pdoKms = es_kms();
        if (!$pdoKms) { return []; }
        foreach ($pdoKms->query("SELECT id, title, priority, status, TIMESTAMPDIFF(DAY, created_at, NOW()) age
            FROM kms_knowledge_requests WHERE status IN ('open','in_progress')")->fetchAll() as $r) {
            $out[] = es_entity('knowledge_request', (int)$r['id'], 'Permintaan pengetahuan: ' . $r['title'], [
                'title' => $r['title'], 'priority' => $r['priority'], 'status' => $r['status'], 'age_days' => (int)$r['age'],
            ]);
        }
    } catch (Throwable $e) { /* KMS belum terpasang */ }
    return $out;
}

/** Fakta global: MIS (kinerja penjualan), KMS (kesehatan pengetahuan), ringkasan lintas modul. */
function es_collect_global(PDO $pdo, array $params): array
{
    $f = [];
    $m = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN total_price END),0) mtd,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN total_price END),0) r7,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN total_price END),0) p7,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_price END),0) r30,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) n30
        FROM orders WHERE payment_status = 'paid' AND status <> 'cancelled'")->fetch();
    $all30 = $pdo->query("SELECT COUNT(*) total, SUM(status = 'cancelled') cancelled, SUM(payment_status = 'paid' AND status <> 'cancelled') paid
        FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch();
    $target = 0.0;
    try {
        $t = $pdo->query("SELECT target_amount FROM sales_targets WHERE target_month = DATE_FORMAT(CURDATE(), '%Y-%m-01') LIMIT 1")->fetchColumn();
        $target = $t !== false ? (float)$t : 0.0;
    } catch (Throwable $e) { /* sales_targets belum ada */ }
    $dayOfMonth = (int)date('j'); $daysInMonth = (int)date('t');
    $mtd = (float)$m['mtd'];
    $attain = $target > 0 ? $mtd / $target * 100 : null;
    $expected = $dayOfMonth / $daysInMonth * 100;
    $f['mis_mtd_revenue'] = $mtd;
    $f['mis_month_target'] = $target > 0 ? $target : null;
    $f['mis_target_attainment_pct'] = $attain !== null ? round($attain, 1) : null;
    $f['mis_expected_attainment_pct'] = round($expected, 1);
    $f['mis_pace_gap_pct'] = $attain !== null ? round($attain - $expected, 1) : null;
    $f['mis_projected_revenue'] = round($mtd / max($dayOfMonth, 1) * $daysInMonth, 0);
    $f['mis_revenue_7d'] = (float)$m['r7'];
    $f['mis_revenue_prev_7d'] = (float)$m['p7'];
    $f['mis_wow_growth_pct'] = (float)$m['p7'] > 0 ? round(((float)$m['r7'] - (float)$m['p7']) / (float)$m['p7'] * 100, 1) : null;
    $f['mis_aov_30d'] = (int)$m['n30'] > 0 ? round((float)$m['r30'] / (int)$m['n30'], 0) : null;
    $f['mis_cancel_rate_30d'] = (int)$all30['total'] > 0 ? round((float)$all30['cancelled'] / (int)$all30['total'] * 100, 1) : 0.0;
    $f['mis_orders_30d'] = (int)$all30['total'];
    $topRows = $pdo->query("SELECT oi.product_name, SUM(oi.subtotal) rev FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.payment_status = 'paid' AND o.status <> 'cancelled' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY oi.product_name ORDER BY rev DESC LIMIT 1")->fetch();
    $f['mis_top_product'] = $topRows['product_name'] ?? null;
    $f['mis_top_product_share_pct'] = ($topRows && (float)$m['r30'] > 0) ? round((float)$topRows['rev'] / max((float)$m['r30'] - 0, 1) * 100, 1) : null;

    // OAS
    $f['oas_open_tasks'] = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('pending','in_progress')")->fetchColumn();
    $f['oas_pending_approvals'] = (int)$pdo->query("SELECT COUNT(*) FROM approvals WHERE status = 'pending'")->fetchColumn();

    // KMS
    $f['kms_open_requests'] = 0; $f['kms_stale_articles'] = 0; $f['kms_published'] = 0; $f['kms_avg_rating'] = null;
    try {
        $pdoKms = es_kms();
        if (!$pdoKms) { throw new RuntimeException('KMS tidak tersedia'); }
        $f['kms_open_requests'] = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_requests WHERE status IN ('open','in_progress')")->fetchColumn();
        $f['kms_published'] = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_items WHERE status = 'published'")->fetchColumn();
        $stale = (int)($params['kms_stale_days'] ?? 180);
        $f['kms_stale_articles'] = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_items WHERE status = 'published' AND updated_at < DATE_SUB(NOW(), INTERVAL $stale DAY)")->fetchColumn();
        $avg = $pdoKms->query('SELECT AVG(rating) FROM kms_knowledge_ratings')->fetchColumn();
        $f['kms_avg_rating'] = $avg !== null && $avg !== false ? round((float)$avg, 2) : null;
    } catch (Throwable $e) { /* KMS belum terpasang */ }
    return es_entity('global', 0, APP_NAME . ' (ringkasan toko)', $f);
}

/** Setelah derive pertama, hitung agregat lintas entitas ke fakta global. */
function es_aggregate_global(array &$entities): void
{
    $counts = ['critical' => 0, 'out' => 0, 'low' => 0, 'dead' => 0, 'overstock' => 0];
    foreach ($entities as $e) {
        if ($e['type'] === 'variation' && isset($e['facts']['stock_risk'], $counts[$e['facts']['stock_risk']])) { $counts[$e['facts']['stock_risk']]++; }
    }
    foreach ($entities as $i => $e) {
        if ($e['type'] !== 'global') { continue; }
        $entities[$i]['facts']['tps_critical_variations'] = $counts['critical'] + $counts['out'];
        $entities[$i]['facts']['tps_low_variations'] = $counts['low'];
        $entities[$i]['facts']['tps_dead_variations'] = $counts['dead'];
        $entities[$i]['facts']['tps_overstock_variations'] = $counts['overstock'];
    }
}

/** Kumpulkan semua entitas yang dibutuhkan oleh aturan aktif. */
function es_collect(PDO $pdo, array $rules, array $params, ?array $scope = null): array
{
    $types = array_values(array_unique(array_column($rules, 'subject_type')));
    $ents = [];
    if (in_array('variation', $types, true)) { $ents = array_merge($ents, es_collect_variations($pdo, $params, $scope['variation'] ?? null)); }
    if (in_array('order', $types, true)) { $ents = array_merge($ents, es_collect_orders($pdo, $params, $scope['order'] ?? null)); }
    if ($scope === null) {
        if (in_array('task', $types, true)) { $ents = array_merge($ents, es_collect_tasks($pdo)); }
        if (in_array('approval', $types, true)) { $ents = array_merge($ents, es_collect_approvals($pdo)); }
        if (in_array('supplier', $types, true)) { $ents = array_merge($ents, es_collect_suppliers($pdo)); }
        if (in_array('knowledge_request', $types, true)) { $ents = array_merge($ents, es_collect_kms_requests()); }
        if (in_array('global', $types, true)) { $ents[] = es_collect_global($pdo, $params); }
    }
    return $ents;
}

function es_count_facts(array $entities): int
{
    $n = 0;
    foreach ($entities as $e) { $n += count($e['facts']); }
    return $n;
}

/* =====================================================================
 * Menjalankan siklus inferensi + persistensi
 * ===================================================================== */
function es_system_user(PDO $pdo, ?int $preferred = null): int
{
    if ($preferred) { return $preferred; }
    $id = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
        WHERE r.name = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1")->fetchColumn();
    return (int)($id ?: 1);
}

/**
 * Opsi: domains[], trigger (manual|schedule|event|api), label, user_id, scope, dry_run, auto_actions
 * Mengembalikan ringkasan siklus + daftar inferensi (untuk dry run).
 */
function es_run(PDO $pdo, array $o = []): array
{
    require_once __DIR__ . '/es_actions.php';
    $t0 = microtime(true);
    $trigger = in_array($o['trigger'] ?? 'manual', ['manual', 'schedule', 'event', 'api'], true) ? $o['trigger'] : 'manual';
    $domains = !empty($o['domains']) ? array_values((array)$o['domains']) : null;
    $scope = $o['scope'] ?? null;
    $dry = !empty($o['dry_run']);
    $userId = isset($o['user_id']) ? (int)$o['user_id'] : null;

    $params = es_params($pdo);
    $rules = es_load_rules($pdo, $domains);
    $runId = 0;
    if (!$dry) {
        $pdo->prepare('INSERT INTO es_runs (trigger_type, trigger_label, domains, started_by) VALUES (?,?,?,?)')
            ->execute([$trigger, $o['label'] ?? null, $domains ? implode(',', $domains) : 'ALL', $userId]);
        $runId = (int)$pdo->lastInsertId();
    }
    try {
        $entities = es_collect($pdo, $rules, $params, $scope);
        $fc1 = es_forward_chain($entities, $rules, $params);
        es_aggregate_global($entities);
        $fc2 = es_forward_chain($entities, $rules, $params);
        $inf = es_infer($entities, $rules, $params);
        $results = $inf['results'];

        $stats = ['new' => 0, 'updated' => 0, 'resolved' => 0, 'actions' => 0];
        $produced = [];
        $created = [];
        if (!$dry) {
            $maxActions = (int)($params['max_auto_actions_per_run'] ?? 40);
            foreach ($results as $res) {
                $rule = $res['rule']; $ent = $res['entity'];
                $fp = sha1($rule['code'] . '|' . $ent['type'] . '|' . $ent['id']);
                $produced[$fp] = true;
                $facts = [];
                foreach ($res['facts_used'] as $k => $v) { $facts[$k] = $v; }
                $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $traceJson = json_encode($res['trace'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $sel = $pdo->prepare('SELECT id, status, snoozed_until FROM es_inferences WHERE fingerprint = ?');
                $sel->execute([$fp]);
                $ex = $sel->fetch();
                $isNew = false; $infId = 0;
                if (!$ex) {
                    $pdo->prepare('INSERT INTO es_inferences (fingerprint, rule_id, rule_code, domain, subject_type, subject_id, subject_label, severity, certainty, title, message, recommendation, facts, trace, status, first_run_id, last_run_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$fp, $rule['id'], $rule['code'], $rule['domain'], $ent['type'], $ent['id'], $ent['label'], $rule['severity'], $res['cf'], $res['title'], $res['message'], $res['recommendation'], $factsJson, $traceJson, 'open', $runId, $runId]);
                    $infId = (int)$pdo->lastInsertId(); $isNew = true; $stats['new']++;
                } else {
                    $infId = (int)$ex['id'];
                    $newStatus = $ex['status'];
                    if ($ex['status'] === 'resolved') { $newStatus = 'open'; $isNew = true; }
                    elseif ($ex['status'] === 'dismissed' && (!$ex['snoozed_until'] || strtotime((string)$ex['snoozed_until']) < time())) { $newStatus = 'open'; $isNew = true; }
                    $pdo->prepare('UPDATE es_inferences SET rule_id=?, severity=?, certainty=?, title=?, message=?, recommendation=?, facts=?, trace=?, subject_label=?, status=?, last_run_id=?, occurrences=occurrences+1,
                        resolved_at = IF(? = "open", NULL, resolved_at), resolved_by = IF(? = "open", NULL, resolved_by), resolution_note = IF(? = "open", NULL, resolution_note) WHERE id=?')
                        ->execute([$rule['id'], $rule['severity'], $res['cf'], $res['title'], $res['message'], $res['recommendation'], $factsJson, $traceJson, $ent['label'], $newStatus, $runId, $newStatus, $newStatus, $newStatus, $infId]);
                    $stats['updated']++;
                }
                if ($isNew && !empty($o['auto_actions']) || ($isNew && !array_key_exists('auto_actions', $o))) {
                    $inferenceRow = ['id' => $infId, 'rule' => $rule, 'entity' => $ent, 'title' => $res['title'], 'message' => $res['message'], 'recommendation' => $res['recommendation'], 'severity' => $rule['severity']];
                    foreach ($rule['actions'] as $act) {
                        if (empty($act['auto']) || $stats['actions'] >= $maxActions) { continue; }
                        $r = es_execute_action($pdo, $inferenceRow, $act, ['user_id' => $userId, 'auto' => true]);
                        if ($r['status'] === 'done') { $stats['actions']++; }
                    }
                    if ($rule['kms_keywords']) { es_action_kms_lookup($pdo, $inferenceRow, ['type' => 'kms_lookup'], ['user_id' => $userId, 'auto' => true]); }
                }
                $created[] = $infId;
            }
            // Penutupan otomatis: kondisi tidak lagi terpenuhi
            $evaluatedRuleIds = array_column(array_filter($rules, static fn($r) => in_array($r['rule_kind'], ['inference', 'recommend'], true)), 'id');
            if ($evaluatedRuleIds) {
                $in = implode(',', array_map('intval', $evaluatedRuleIds));
                $sql = "SELECT id, fingerprint, subject_type, subject_id FROM es_inferences WHERE status IN ('open','acknowledged','in_progress') AND rule_id IN ($in)";
                foreach ($pdo->query($sql)->fetchAll() as $row) {
                    if (isset($produced[$row['fingerprint']])) { continue; }
                    if ($scope !== null) {
                        $ids = $scope[$row['subject_type']] ?? null;
                        if ($ids === null || !in_array((int)$row['subject_id'], array_map('intval', $ids), true)) { continue; }
                    }
                    $pdo->prepare("UPDATE es_inferences SET status='resolved', resolved_at=NOW(), resolution_note='Kondisi tidak lagi terpenuhi (otomatis)' WHERE id=?")->execute([$row['id']]);
                    $stats['resolved']++;
                }
            }
            $pdo->prepare("UPDATE es_runs SET status='completed', entities_count=?, facts_count=?, rules_evaluated=?, rules_fired=?, inferences_new=?, inferences_updated=?, inferences_resolved=?, actions_executed=?, duration_ms=?, finished_at=NOW() WHERE id=?")
                ->execute([count($entities), es_count_facts($entities), $fc1['evaluated'] + $fc2['evaluated'] + $inf['evaluated'], $fc1['fired'] + $fc2['fired'] + count($results),
                    $stats['new'], $stats['updated'], $stats['resolved'], $stats['actions'], (int)round((microtime(true) - $t0) * 1000), $runId]);
        }
        return [
            'run_id' => $runId, 'dry_run' => $dry, 'entities' => count($entities), 'facts' => es_count_facts($entities),
            'rules_active' => count($rules), 'rules_fired' => $fc1['fired'] + $fc2['fired'] + count($results), 'inferences' => count($results),
            'new' => $stats['new'], 'updated' => $stats['updated'], 'resolved' => $stats['resolved'], 'actions' => $stats['actions'],
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000), 'results' => $dry ? $results : [],
        ];
    } catch (Throwable $e) {
        if ($runId) {
            $pdo->prepare("UPDATE es_runs SET status='failed', error_message=?, finished_at=NOW() WHERE id=?")->execute([substr($e->getMessage(), 0, 500), $runId]);
        }
        throw $e;
    }
}

/**
 * Inferensi tanpa menyimpan: konsultasi ad-hoc dengan fakta yang diberikan pemanggil (dipakai API & konsol uji).
 * Fakta dapat berasal dari sistem eksternal; hasil berisi kesimpulan beserta penjelasannya.
 */
function es_consult(PDO $pdo, string $subjectType, array $facts, ?array $domains = null): array
{
    $params = es_params($pdo);
    $rules = es_load_rules($pdo, $domains);
    $ent = es_entity($subjectType, 0, 'Konsultasi ad-hoc', $facts);
    $entities = [$ent];
    es_forward_chain($entities, $rules, $params);
    $inf = es_infer($entities, $rules, $params);
    $conclusions = [];
    foreach ($inf['results'] as $r) {
        $conclusions[] = ['rule' => $r['rule']['code'], 'name' => $r['rule']['name'], 'domain' => $r['rule']['domain'], 'severity' => $r['rule']['severity'],
            'certainty' => $r['cf'], 'title' => $r['title'], 'message' => $r['message'], 'recommendation' => $r['recommendation'], 'facts_used' => $r['facts_used'], 'trace' => $r['trace']];
    }
    $derived = [];
    foreach ($entities[0]['src'] as $k => $s) { $derived[$k] = ['value' => $entities[0]['facts'][$k], 'certainty' => round((float)$entities[0]['cf'][$k], 3), 'rule' => $s['rule']]; }
    return ['subject' => $subjectType, 'derived_facts' => $derived, 'conclusions' => $conclusions];
}

/** Hook event: dipanggil dari TPS/OAS/DSS setelah transaksi penting. Tidak pernah menggagalkan proses utama. */
function es_fire_event(PDO $pdo, string $event, array $scope = [], ?int $userId = null): void
{
    try {
        $flag = $pdo->query("SELECT param_value FROM es_parameters WHERE param_key = 'event_driven_enabled'")->fetchColumn();
        if ($flag !== false && (float)$flag < 1) { return; }
        $scope = array_filter($scope, static fn($v) => !empty($v));
        if (!$scope) { return; }
        es_run($pdo, ['trigger' => 'event', 'label' => $event, 'scope' => $scope, 'user_id' => $userId, 'domains' => ['TPS', 'DSS', 'CROSS', 'OAS']]);
    } catch (Throwable $e) {
        error_log('ES event ' . $event . ' gagal: ' . $e->getMessage());
    }
}
