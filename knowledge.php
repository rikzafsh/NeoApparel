<?php
declare(strict_types=1);
require 'kms_functions.php';

$user = kms_require_role(['customer','seller','staff','manager','admin']);
$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

$where=[]; $params=[];
if($q!==''){ $where[]='(k.title LIKE ? OR k.summary LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; }
if(in_array($type,['article','sop','guide','faq','best_practice','lesson_learned','troubleshooting','policy','other'],true)){ $where[]='k.knowledge_type=?'; $params[]=$type; }
if(user_has_any_role(['manager','admin'])){
  if(in_array($status,['draft','under_review','published','archived'],true)){ $where[]='k.status=?'; $params[]=$status; }
}elseif(user_has_any_role(['seller','staff'])){
  $where[]="k.status='published' AND k.visibility IN('public','employees')";
}else{
  $where[]="k.status='published' AND k.visibility='public'";
}

$sql="SELECT k.*,c.name category_name,
      COALESCE(ROUND(AVG(r.rating),1),0) avg_rating,
      COUNT(DISTINCT CASE WHEN a.action='view' THEN a.id END) view_count
      FROM kms_knowledge_items k
      LEFT JOIN kms_categories c ON c.id=k.category_id
      LEFT JOIN kms_knowledge_ratings r ON r.knowledge_id=k.id
      LEFT JOIN kms_access_logs a ON a.knowledge_id=k.id
      ".($where?' WHERE '.implode(' AND ',$where):'')."
      GROUP BY k.id ORDER BY k.updated_at DESC";

$stmt=$pdoKms->prepare($sql);
$stmt->execute($params);
$rows=$stmt->fetchAll();

if($q!==''){
  $pdoKms->prepare("INSERT INTO kms_search_logs(user_id,keyword,filters,result_count) VALUES(?,?,?,?)")
    ->execute([$user['id'],$q,json_encode(['type'=>$type,'status'=>$status]),count($rows)]);
}

$pageTitle='Knowledge Base';
include 'kms_layout_top.php';
?>
<div class="page-headline"><div><div class="eyebrow">KNOWLEDGE REPOSITORY</div><h1>Knowledge Base</h1><p><?=user_has_role('customer')?'Informasi publik untuk membantu pelanggan.':'SOP, guide, FAQ, best practice, troubleshooting, dan lesson learned perusahaan.'?></p></div><?php if(is_employee()): ?><a class="btn btn-primary" href="knowledge_create.php">＋ New Knowledge</a><?php endif; ?></div>
<section class="search-panel"><form class="knowledge-search" method="GET"><div class="search-box"><span>⌕</span><input name="q" value="<?=e($q)?>" placeholder="Cari knowledge..."></div><select name="type"><option value="">All types</option><?php foreach(['article','sop','guide','faq','best_practice','lesson_learned','troubleshooting','policy','other'] as $o): ?><option value="<?=$o?>" <?=$type===$o?'selected':''?>><?=e(ucwords(str_replace('_',' ',$o)))?></option><?php endforeach; ?></select><?php if(user_has_any_role(['manager','admin'])):?><select name="status"><option value="">All status</option><?php foreach(['draft','under_review','published','archived'] as $o): ?><option value="<?=$o?>" <?=$status===$o?'selected':''?>><?=e(ucwords(str_replace('_',' ',$o)))?></option><?php endforeach; ?></select><?php endif; ?><button class="btn btn-primary" type="submit">Search</button></form></section>
<div class="knowledge-count"><?=count($rows)?> knowledge item<?=count($rows)!==1?'s':''?></div>
<div class="knowledge-card-grid"><?php foreach($rows as $it): ?><article class="knowledge-card"><div class="knowledge-card-top"><span class="type-pill"><?=e(ucwords(str_replace('_',' ',$it['knowledge_type'])))?></span><span class="status-pill status-<?=e($it['status'])?>"><?=e($it['status'])?></span></div><h2><?=e($it['title'])?></h2><p><?=e($it['summary']?:'Belum ada ringkasan.')?></p><div class="card-tags"><span><?=e($it['category_name']?:'Uncategorized')?></span><span><?=e($it['knowledge_nature'])?></span><span><?=e($it['visibility'])?></span></div><div class="knowledge-card-footer"><div class="metrics"><span>◉ <?=$it['view_count']?></span><span>★ <?=number_format((float)$it['avg_rating'],1)?></span></div><a class="text-link" href="knowledge_view.php?id=<?=$it['id']?>">Open →</a></div></article><?php endforeach;?></div>
<?php if(!$rows): ?><div class="empty-state large"><div class="empty-illustration">K</div><h2>Knowledge belum ditemukan</h2><p>Coba ubah kata kunci.</p></div><?php endif; ?>
<?php include 'kms_layout_bottom.php'; ?>
