<?php
declare(strict_types=1);
require 'kms_functions.php';

$user=kms_require_role(['customer','seller','staff','manager','admin']);
$cats=$pdoKms->query("SELECT id,name FROM kms_categories WHERE is_active=1 ORDER BY name")->fetchAll();
$employees=employee_users($pdo);
$msg='';$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $title=trim($_POST['title']??'');$desc=trim($_POST['description']??'');$priority=$_POST['priority']??'medium';$cat=(int)($_POST['category_id']??0);$ass=(int)($_POST['assigned_to_user_id']??0);
  if($title===''||$desc==='') $err='Title dan description wajib diisi.';
  elseif(!in_array($priority,['low','medium','high','urgent'],true)) $err='Priority tidak valid.';
  else{
    $assignee=user_has_any_role(['manager','admin'])?$ass:0;
    $pdoKms->prepare("INSERT INTO kms_knowledge_requests(requester_user_id,category_id,title,description,priority,status,assigned_to_user_id) VALUES(?,?,?,?,?,'open',?)")->execute([$user['id'],$cat?:null,$title,$desc,$priority,$assignee?:null]);
    $rid=(int)$pdoKms->lastInsertId();
    if($assignee) notify($pdo,$assignee,'knowledge_request','Knowledge request baru',"Request baru: {$title}.",'kms_request',$rid);
    $msg='Request berhasil dikirim.';
  }
}

$stmt=$pdoKms->prepare("SELECT r.*,c.name category_name FROM kms_knowledge_requests r LEFT JOIN kms_categories c ON c.id=r.category_id WHERE r.requester_user_id=? ORDER BY r.created_at DESC");$stmt->execute([$user['id']]);$mine=$stmt->fetchAll();
$pageTitle='Knowledge Requests'; include 'kms_layout_top.php';
?>
<div class="page-headline"><div><div class="eyebrow">KNOWLEDGE GAP</div><h1>Knowledge Requests</h1><p>Ajukan informasi yang belum tersedia atau perlu diperjelas.</p></div></div>
<?php if($msg):?><div class="alert alert-success"><?=e($msg)?></div><?php endif;?><?php if($err):?><div class="alert alert-error"><?=e($err)?></div><?php endif;?>
<div class="request-layout"><section class="card"><h2>New Request</h2><p class="muted">Contoh: “Bagaimana prosedur menangani retur?”</p><form method="POST"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="form-group"><label>Title</label><input name="title" required></div><div class="form-group"><label>Question / details</label><textarea name="description" rows="7" required></textarea></div><div class="form-row"><div class="form-group"><label>Category</label><select name="category_id"><option value="0">Uncategorized</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Priority</label><select name="priority"><option>low</option><option selected>medium</option><option>high</option><option>urgent</option></select></div></div><?php if(user_has_any_role(['manager','admin'])):?><div class="form-group"><label>Assign to employee</label><select name="assigned_to_user_id"><option value="0">Unassigned</option><?php foreach($employees as $emp):?><option value="<?=$emp['id']?>"><?=e($emp['name'])?> · <?=e($emp['roles'])?></option><?php endforeach;?></select></div><?php endif;?><button class="btn btn-primary">Submit Request</button></form></section><section class="card"><div class="section-header"><div><h2>My Requests</h2><p class="muted">Riwayat request yang kamu buat.</p></div></div><?php foreach($mine as $r):?><div class="request-row"><div><strong><?=e($r['title'])?></strong><span><?=e($r['category_name']?:'Uncategorized')?> · <?=e($r['priority'])?></span></div><span class="status-pill status-<?=e($r['status'])?>"><?=e(str_replace('_',' ',$r['status']))?></span></div><?php endforeach;?><?php if(!$mine):?><div class="empty-state">Belum ada request.</div><?php endif;?></section></div>
<?php include 'kms_layout_bottom.php'; ?>
