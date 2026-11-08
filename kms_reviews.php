<?php
declare(strict_types=1);
require 'kms_functions.php';
$user=kms_require_role(['manager','admin']);
$msg='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $id=(int)($_POST['knowledge_id']??0); $status=$_POST['status']??''; $comment=trim($_POST['comments']??'');
 if(!in_array($status,['approved','rejected','revision_required'],true)) $err='Review tidak valid.';
 else{
  try{
   $pdoKms->beginTransaction();
   $pdoKms->prepare("INSERT INTO kms_knowledge_reviews(knowledge_id,reviewer_user_id,status,comments,reviewed_at) VALUES(?,?,?,?,NOW())")->execute([$id,$user['id'],$status,$comment?:null]);
   $newStatus=$status==='approved'?'published':($status==='rejected'?'archived':'under_review');
   $pdoKms->prepare("UPDATE kms_knowledge_items SET status=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE published_at END,updated_by_user_id=? WHERE id=?")->execute([$newStatus,$newStatus,$user['id'],$id]);
   $pdoKms->commit(); $msg='Review berhasil diproses.';
  }catch(Throwable $e){if($pdoKms->inTransaction())$pdoKms->rollBack();$err=$e->getMessage();}
 }
}
$rows=$pdoKms->query("SELECT k.*,c.name category_name FROM kms_knowledge_items k LEFT JOIN kms_categories c ON c.id=k.category_id WHERE k.status='under_review' ORDER BY k.updated_at DESC")->fetchAll();
$pageTitle='KMS Reviews';include 'kms_layout_top.php';
?>
<div class="page-headline"><div><div class="eyebrow">KNOWLEDGE GOVERNANCE</div><h1>KMS Reviews</h1><p>Manager/Admin memvalidasi knowledge sebelum tersedia untuk pembaca.</p></div></div>
<?php if($msg):?><div class="alert alert-success"><?=e($msg)?></div><?php endif;?><?php if($err):?><div class="alert alert-error"><?=e($err)?></div><?php endif;?>
<?php if(!$rows):?><div class="empty-state large"><div class="empty-illustration">✓</div><h2>Review queue kosong</h2><p>Tidak ada knowledge yang menunggu review.</p></div><?php endif;?>
<div class="review-grid"><?php foreach($rows as $r):?><article class="review-card"><div class="split-line"><span class="type-pill"><?=e($r['knowledge_type'])?></span><span class="status-pill status-under_review">under review</span></div><h2><?=e($r['title'])?></h2><p><?=e($r['summary']?:'Tanpa ringkasan.')?></p><small>Category · <?=e($r['category_name']?:'Uncategorized')?></small><div class="action-row"><a class="btn btn-ghost btn-sm" href="knowledge_view.php?id=<?=$r['id']?>">Open</a></div><form class="review-form" method="POST"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="knowledge_id" value="<?=$r['id']?>"><textarea name="comments" rows="3" placeholder="Review comment"></textarea><div class="action-row"><button class="btn btn-success btn-sm" name="status" value="approved">Approve</button><button class="btn btn-secondary btn-sm" name="status" value="revision_required">Revision</button><button class="btn btn-danger btn-sm" name="status" value="rejected">Reject</button></div></form></article><?php endforeach;?></div>
<?php include 'kms_layout_bottom.php'; ?>
