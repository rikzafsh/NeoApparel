<?php
declare(strict_types=1);
require 'kms_functions.php';

$user=kms_require_role(['customer','seller','staff','manager','admin']);
$id=(int)($_GET['id']??0);

$stmt=$pdoKms->prepare("SELECT k.*,c.name category_name FROM kms_knowledge_items k LEFT JOIN kms_categories c ON c.id=k.category_id WHERE k.id=?");
$stmt->execute([$id]);
$item=$stmt->fetch();
if(!$item || !kms_can_view($user,$item)){http_response_code(404);die('Knowledge tidak ditemukan atau belum tersedia untuk akun ini.');}

$pdoKms->prepare("INSERT INTO kms_access_logs(user_id,knowledge_id,action,ip_address) VALUES(?,?,'view',?)")->execute([$user['id'],$id,$_SERVER['REMOTE_ADDR']??null]);

$rev=$pdoKms->prepare("SELECT * FROM kms_knowledge_revisions WHERE knowledge_id=? ORDER BY version_number DESC LIMIT 1");$rev->execute([$id]);$rev=$rev->fetch();
$tags=$pdoKms->prepare("SELECT t.name FROM kms_tags t JOIN kms_knowledge_tags kt ON kt.tag_id=t.id WHERE kt.knowledge_id=? ORDER BY t.name");$tags->execute([$id]);$tags=$tags->fetchAll();
$experts=$pdoKms->prepare("SELECT e.* FROM kms_experts e JOIN kms_knowledge_experts ke ON ke.expert_id=e.id WHERE ke.knowledge_id=? AND e.is_available=1 ORDER BY e.expertise_level DESC");$experts->execute([$id]);$experts=$experts->fetchAll();
$ratingStmt=$pdoKms->prepare("SELECT COALESCE(AVG(rating),0) avg_rating,COUNT(*) rating_count FROM kms_knowledge_ratings WHERE knowledge_id=?");$ratingStmt->execute([$id]);$rating=$ratingStmt->fetch();
$commentsStmt=$pdoKms->prepare("SELECT * FROM kms_knowledge_comments WHERE knowledge_id=? ORDER BY created_at DESC LIMIT 20");$commentsStmt->execute([$id]);$comments=$commentsStmt->fetchAll();
$bookStmt=$pdoKms->prepare("SELECT COUNT(*) FROM kms_knowledge_bookmarks WHERE user_id=? AND knowledge_id=?");$bookStmt->execute([$user['id'],$id]);$bookmarked=(bool)$bookStmt->fetchColumn();
$msg=''; $err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action']??'';

  if($action==='bookmark'){
    if($bookmarked){ $pdoKms->prepare("DELETE FROM kms_knowledge_bookmarks WHERE user_id=? AND knowledge_id=?")->execute([$user['id'],$id]); }
    else { $pdoKms->prepare("INSERT IGNORE INTO kms_knowledge_bookmarks(user_id,knowledge_id) VALUES(?,?)")->execute([$user['id'],$id]); }
    go('knowledge_view.php?id='.$id);
  }

  if($action==='comment' && trim($_POST['comment']??'')!==''){
    $pdoKms->prepare("INSERT INTO kms_knowledge_comments(knowledge_id,user_id,comment) VALUES(?,?,?)")->execute([$id,$user['id'],trim($_POST['comment'])]);
    go('knowledge_view.php?id='.$id.'&commented=1');
  }

  if($action==='rate'){
    $rate=(int)($_POST['rating']??0);
    if($rate>=1&&$rate<=5){
      $pdoKms->prepare("INSERT INTO kms_knowledge_ratings(knowledge_id,user_id,rating,feedback) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),feedback=VALUES(feedback),updated_at=CURRENT_TIMESTAMP")
        ->execute([$id,$user['id'],$rate,trim($_POST['feedback']??'')?:null]);
      $msg='Rating tersimpan.';
    }
  }

  if($action==='publish' && user_has_any_role(['manager','admin'])){
    $pdoKms->prepare("UPDATE kms_knowledge_items SET status='published',published_at=COALESCE(published_at,NOW()),updated_by_user_id=? WHERE id=?")->execute([$user['id'],$id]);
    $msg='Knowledge dipublikasikan.';
  }
}

$pageTitle=$item['title']; include 'kms_layout_top.php';
?>
<div class="knowledge-detail-top"><div><a href="knowledge.php" class="back-link">← Back to Knowledge Base</a><div class="detail-labels"><span class="type-pill"><?=e($item['knowledge_type'])?></span><span class="status-pill status-<?=e($item['status'])?>"><?=e($item['status'])?></span><span class="type-pill"><?=e($item['visibility'])?></span></div><h1><?=e($item['title'])?></h1><p><?=e($item['summary']?:'')?></p></div><div class="action-row"><?php if(user_has_any_role(['manager','admin'])): ?><a class="btn btn-ghost" href="knowledge_create.php?edit=<?=$id?>">Edit</a><?php if($item['status']!=='published'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="publish"><button class="btn btn-primary">Publish</button></form><?php endif;?><?php endif; ?><form method="POST"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="bookmark"><button class="btn btn-ghost" type="submit"><?= $bookmarked?'★ Saved':'☆ Save' ?></button></form></div></div>
<?php if($msg): ?><div class="alert alert-success"><?=e($msg)?></div><?php endif; ?><?php if(isset($_GET['commented'])):?><div class="alert alert-success">Komentar berhasil ditambahkan.</div><?php endif; ?>
<div class="detail-layout"><article class="card knowledge-article"><div class="article-meta"><span>Category · <?=e($item['category_name']?:'Uncategorized')?></span><span>Nature · <?=e($item['knowledge_nature'])?></span><span>Version · <?=e($rev['version_number']??'—')?></span></div><?php if($rev): ?><div class="article-content"><?=nl2br(e($rev['content']))?></div><div class="article-foot">Updated <?=e($item['updated_at'])?> · <?=e($rev['change_summary']?:'Revision')?></div><?php else: ?><div class="empty-state">Belum ada content revision.</div><?php endif; ?><?php if($tags): ?><div class="tag-cloud"><?php foreach($tags as $tag): ?><span>#<?=e($tag['name'])?></span><?php endforeach;?></div><?php endif; ?></article>
<aside class="detail-side"><section class="card"><h2>Quality</h2><div class="rating-big"><strong><?=number_format((float)$rating['avg_rating'],1)?></strong><div><span>★★★★★</span><small><?=e($rating['rating_count'])?> ratings</small></div></div><form method="POST"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="rate"><div class="form-group"><label>Your rating</label><select name="rating" required><option value="">Choose</option><option value="5">5 — Very useful</option><option value="4">4 — Useful</option><option value="3">3 — Neutral</option><option value="2">2 — Needs improvement</option><option value="1">1 — Not useful</option></select></div><textarea name="feedback" rows="3" placeholder="Optional feedback"></textarea><button class="btn btn-primary wide" type="submit">Submit rating</button></form></section>
<section class="card"><h2>Subject experts</h2><?php foreach($experts as $ex): $en=kms_user_name($pdo,(int)$ex['user_id']); ?><div class="expert-row"><div class="avatar-md"><?=e(strtoupper(substr($en,0,1)))?></div><div><strong><?=e($en)?></strong><span><?=e($ex['expertise_title'])?></span></div></div><?php endforeach; ?><?php if(!$experts): ?><p class="muted">Belum ada expert terhubung.</p><?php endif; ?></section></aside></div>
<section class="card comments-card"><div class="section-header"><div><h2>Discussion</h2><p class="muted">Tambahkan pengalaman atau pertanyaan.</p></div></div><form method="POST" class="comment-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="comment"><textarea name="comment" rows="3" required placeholder="Tulis komentar..."></textarea><div class="right"><button class="btn btn-primary">Post comment</button></div></form><?php foreach($comments as $c): $cn=kms_user_name($pdo,(int)$c['user_id']); ?><div class="comment-item"><div class="avatar-md"><?=e(strtoupper(substr($cn,0,1)))?></div><div><strong><?=e($cn)?></strong><small><?=e($c['created_at'])?></small><p><?=nl2br(e($c['comment']))?></p></div></div><?php endforeach; ?><?php if(!$comments): ?><div class="empty-state">Belum ada komentar.</div><?php endif; ?></section>
<?php include 'kms_layout_bottom.php'; ?>
