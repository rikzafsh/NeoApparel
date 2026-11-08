<?php
declare(strict_types=1);
require 'kms_functions.php';
$user=kms_require_role(['customer','seller','staff','manager','admin']);

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $id=(int)($_POST['knowledge_id']??0);
  if(($_POST['action']??'')==='remove') $pdoKms->prepare("DELETE FROM kms_knowledge_bookmarks WHERE user_id=? AND knowledge_id=?")->execute([$user['id'],$id]);
  go('kms_bookmarks.php');
}

$stmt=$pdoKms->prepare("SELECT k.id,k.title,k.summary,k.knowledge_type,k.visibility,k.status FROM kms_knowledge_bookmarks b JOIN kms_knowledge_items k ON k.id=b.knowledge_id WHERE b.user_id=? ORDER BY b.created_at DESC");$stmt->execute([$user['id']]);$rows=$stmt->fetchAll();
$pageTitle='My Knowledge';include 'kms_layout_top.php';
?>
<div class="page-headline"><div><div class="eyebrow">PERSONAL KNOWLEDGE</div><h1>My Knowledge</h1><p>Knowledge yang kamu simpan untuk dibaca kembali.</p></div><a class="btn btn-primary" href="knowledge.php">Explore Knowledge</a></div>
<div class="knowledge-card-grid"><?php foreach($rows as $r):?><article class="knowledge-card"><div class="knowledge-card-top"><span class="type-pill"><?=e($r['knowledge_type'])?></span><span class="status-pill status-<?=e($r['status'])?>"><?=e($r['status'])?></span></div><h2><?=e($r['title'])?></h2><p><?=e($r['summary']?:'Belum ada ringkasan.')?></p><div class="card-tags"><span><?=e($r['visibility'])?></span></div><div class="knowledge-card-footer"><a class="text-link" href="knowledge_view.php?id=<?=$r['id']?>">Open →</a><form method="POST"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="knowledge_id" value="<?=$r['id']?>"><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form></div></article><?php endforeach;?></div>
<?php if(!$rows):?><div class="empty-state large"><div class="empty-illustration">☆</div><h2>Belum ada knowledge tersimpan</h2><p>Buka knowledge lalu tekan Save.</p></div><?php endif;?>
<?php include 'kms_layout_bottom.php'; ?>
