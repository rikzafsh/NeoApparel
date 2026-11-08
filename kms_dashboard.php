<?php
declare(strict_types=1);
require 'kms_functions.php';

$user = kms_require_role(['customer','seller','staff','manager','admin']);
$scope = user_has_role('admin') ? 'admin' : (user_has_role('manager') ? 'manager' : (user_has_role('staff') ? 'staff' : (user_has_role('seller') ? 'seller' : 'customer')));

if ($scope === 'customer') {
    $pub = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_items WHERE status='published' AND visibility='public'")->fetchColumn();
    $stmt = $pdoKms->prepare("SELECT COUNT(*) FROM kms_knowledge_bookmarks WHERE user_id=?");
    $stmt->execute([$user['id']]);
    $bookmarks = (int)$stmt->fetchColumn();
    $stmt = $pdoKms->prepare("SELECT COUNT(*) FROM kms_knowledge_requests WHERE requester_user_id=? AND status IN('open','in_progress')");
    $stmt->execute([$user['id']]);
    $requests = (int)$stmt->fetchColumn();
    $items = $pdoKms->query("SELECT k.id,k.title,k.summary,k.knowledge_type,COUNT(a.id) views FROM kms_knowledge_items k LEFT JOIN kms_access_logs a ON a.knowledge_id=k.id AND a.action='view' WHERE k.status='published' AND k.visibility='public' GROUP BY k.id ORDER BY views DESC,k.updated_at DESC LIMIT 6")->fetchAll();
} else {
    $total = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_items")->fetchColumn();
    $pub = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_items WHERE status='published'")->fetchColumn();
    $review = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_items WHERE status IN('draft','under_review')")->fetchColumn();
    $requests = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_knowledge_requests WHERE status IN('open','in_progress')")->fetchColumn();
    $experts = (int)$pdoKms->query("SELECT COUNT(*) FROM kms_experts WHERE is_available=1")->fetchColumn();
    $avg = (float)$pdoKms->query("SELECT COALESCE(AVG(rating),0) FROM kms_knowledge_ratings")->fetchColumn();
    $items = $pdoKms->query("SELECT k.id,k.title,k.status,k.knowledge_type,k.updated_at,c.name category_name FROM kms_knowledge_items k LEFT JOIN kms_categories c ON c.id=k.category_id ORDER BY k.updated_at DESC LIMIT 6")->fetchAll();
}

$pageTitle='KMS Overview';
include 'kms_layout_top.php';
?>
<div class="kms-hero">
  <div>
    <div class="eyebrow">KNOWLEDGE MANAGEMENT SYSTEM</div>
    <h1><?= $scope==='customer'?'Knowledge Center':'Knowledge Hub' ?></h1>
    <p><?= $scope==='customer'?'Informasi publik, panduan, dan FAQ yang dapat membantu pelanggan.':'Temukan, dokumentasikan, validasi, dan bagikan pengetahuan perusahaan.' ?></p>
  </div>
  <div class="hero-actions">
    <a class="btn btn-primary" href="knowledge.php">Explore Knowledge</a>
    <a class="btn btn-soft" href="knowledge_request.php">Ask a Question</a>
  </div>
</div>

<?php if($scope==='customer'): ?>
<div class="kms-stat-grid customer">
  <div class="kms-stat-card"><span>Public Knowledge</span><strong><?=$pub?></strong><small>Informasi tersedia</small></div>
  <div class="kms-stat-card"><span>My Knowledge</span><strong><?=$bookmarks?></strong><small>Bookmark tersimpan</small></div>
  <div class="kms-stat-card"><span>Open Requests</span><strong><?=$requests?></strong><small>Pertanyaan aktif</small></div>
</div>
<?php else: ?>
<div class="kms-stat-grid">
  <div class="kms-stat-card"><span>Total Knowledge</span><strong><?=$total?></strong><small>Semua item</small></div>
  <div class="kms-stat-card"><span>Published</span><strong><?=$pub?></strong><small>Siap digunakan</small></div>
  <div class="kms-stat-card"><span>Draft / Review</span><strong><?=$review?></strong><small>Perlu perhatian</small></div>
  <div class="kms-stat-card"><span>Open Requests</span><strong><?=$requests?></strong><small>Knowledge gap</small></div>
  <div class="kms-stat-card"><span>Experts</span><strong><?=$experts?></strong><small>Available</small></div>
  <div class="kms-stat-card"><span>Avg. Rating</span><strong><?=number_format($avg,1)?><small>/5</small></strong><small>Quality signal</small></div>
</div>
<?php endif; ?>

<div class="kms-grid-2">
<section class="card">
  <div class="section-header"><div><h2><?= $scope==='customer'?'Popular Knowledge':'Recently Updated' ?></h2><p class="muted"><?= $scope==='customer'?'Informasi publik yang sering dibuka.':'Knowledge yang baru dibuat atau diperbarui.' ?></p></div><a class="text-link" href="knowledge.php">View all →</a></div>
  <div class="knowledge-list">
  <?php foreach($items as $it): ?>
    <a class="knowledge-row" href="knowledge_view.php?id=<?=$it['id']?>">
      <div class="knowledge-symbol">K</div>
      <div class="knowledge-row-main"><strong><?=e($it['title'])?></strong><span><?=e($it['category_name']??'')?><?=isset($it['views'])?' · '.$it['views'].' views':''?></span></div>
      <?php if(isset($it['status'])): ?><span class="status-pill status-<?=e($it['status'])?>"><?=e($it['status'])?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if(!$items): ?><div class="empty-state">Belum ada knowledge.</div><?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="section-header"><div><h2>Start here</h2><p class="muted">Pilih aktivitas sesuai kebutuhan.</p></div></div>
  <div class="kms-quick-actions" style="grid-template-columns:1fr">
    <?php if(is_employee()): ?><a class="quick-card" href="knowledge_create.php"><span class="quick-icon">＋</span><div><strong>Capture knowledge</strong><small>Dokumentasikan pengalaman, SOP, atau lesson learned.</small></div></a><?php endif; ?>
    <a class="quick-card" href="knowledge_request.php"><span class="quick-icon">?</span><div><strong>Request knowledge</strong><small>Ajukan pertanyaan atau knowledge gap.</small></div></a>
    <a class="quick-card" href="kms_bookmarks.php"><span class="quick-icon">☆</span><div><strong>My knowledge</strong><small>Buka kembali knowledge yang disimpan.</small></div></a>
    <?php if(is_employee()): ?><a class="quick-card" href="experts.php"><span class="quick-icon">★</span><div><strong>Find an expert</strong><small>Temukan orang yang memiliki keahlian.</small></div></a><?php endif; ?>
  </div>
</section>
</div>

<?php include 'kms_layout_bottom.php'; ?>
