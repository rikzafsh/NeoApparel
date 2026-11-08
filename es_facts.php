<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'es_engine.php';
$u = need_role($pdo, ['manager', 'admin']);
$types = ['variation' => 'Variasi produk (TPS · DSS)', 'order' => 'Pesanan aktif (TPS)', 'supplier' => 'Supplier (DSS)', 'task' => 'Tugas (OAS)', 'approval' => 'Persetujuan (OAS)', 'knowledge_request' => 'Permintaan pengetahuan (KMS)', 'global' => 'Fakta global (MIS · KMS · lintas modul)'];
$type = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : 'variation';
$q = mb_strtolower(trim($_GET['q'] ?? ''));
$params = es_params($pdo);
$rules = es_load_rules($pdo);
$ents = es_collect($pdo, $rules, $params);
es_forward_chain($ents, $rules, $params); es_aggregate_global($ents); es_forward_chain($ents, $rules, $params);
$counts = [];
foreach ($ents as $e) { $counts[$e['type']] = ($counts[$e['type']] ?? 0) + 1; }
$list = array_values(array_filter($ents, static fn($e) => $e['type'] === $type && ($q === '' || str_contains(mb_strtolower($e['label']), $q))));
$total = count($list);
$list = array_slice($list, 0, 40);
$pageTitle = 'Memori fakta';
$pageKicker = APP_NAME . ' · working memory expert system';
include 'layout_top.php';
?>
<div class="head"><div><h1>Memori fakta (working memory)</h1><p>Snapshot fakta yang dikumpulkan dari seluruh modul beserta fakta turunan hasil forward chaining, persis seperti yang dilihat mesin pakar saat inferensi.</p></div></div>
<nav class="tabs-nav"><?php foreach ($types as $k => $l): ?><a class="<?= $type === $k ? 'on' : '' ?>" href="es_facts.php?type=<?= e($k) ?>"><?= e(explode(' (', $l)[0]) ?> <small class="muted"><?= (int)($counts[$k] ?? 0) ?></small></a><?php endforeach; ?></nav>
<form method="get" class="filter-bar"><input type="hidden" name="type" value="<?= e($type) ?>"><div class="search"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari entitas…"></div><span class="muted small"><?= e($types[$type]) ?> · <?= $total ?> entitas<?= $total > 40 ? ' (40 pertama)' : '' ?></span></form>
<?php if (!$list): ?><div class="card empty-state"><?= icon('database') ?><p>Tidak ada entitas.</p></div><?php endif; ?>
<?php foreach ($list as $e): ?>
<details class="card" style="margin-bottom:10px"><summary style="cursor:pointer;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><b><?= e($e['label']) ?></b>
    <?php foreach (array_keys($e['src']) as $k): ?><span class="tag" style="background:#ece8ff;color:#4b33f0"><?= e($k) ?> = <?= e((string)$e['facts'][$k]) ?></span><?php endforeach; ?></summary>
    <div class="fact-grid" style="margin-top:12px"><?php foreach ($e['facts'] as $k => $v): $d = isset($e['src'][$k]); ?><div class="fact <?= $d ? 'derived' : '' ?>"><small><?= e($k) ?><?= $d ? ' · turunan oleh ' . e($e['src'][$k]['rule']) : '' ?></small><b><?= e(is_scalar($v) || $v === null ? (string)($v ?? '-') : json_encode($v)) ?></b></div><?php endforeach; ?></div></details>
<?php endforeach; ?>
<?php include 'layout_bottom.php'; ?>
