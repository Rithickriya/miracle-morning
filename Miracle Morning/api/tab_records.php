<?php
$fdate=trim($_GET['filter_date']??'');$fsearch=trim($_GET['search']??'');
$dc=$fdate?"AND t.friday_date=:fd":"";
$sc=$fsearch?"AND m.name LIKE :s":"";
$sc2=$fsearch?"AND visitor_name LIKE :s":"";
$mrecs=$pdo->prepare("SELECT m.name,t.id,t.amount,t.payment_method,t.status,t.friday_date,t.is_partial,t.partial_paid,t.partial_balance FROM transactions t JOIN members m ON t.member_id=m.id WHERE 1=1 $dc $sc ORDER BY t.friday_date DESC,m.name");
if($fdate)$mrecs->bindValue(':fd',$fdate);if($fsearch)$mrecs->bindValue(':s',"%$fsearch%");$mrecs->execute();$mrecs=$mrecs->fetchAll();
$vrecs=$pdo->prepare("SELECT visitor_name,visitor_profession,referrer_name,amount,payment_method,status,friday_date FROM transactions WHERE type='Visitor' $dc $sc2 ORDER BY friday_date DESC");
if($fdate)$vrecs->bindValue(':fd',$fdate);if($fsearch)$vrecs->bindValue(':s',"%$fsearch%");$vrecs->execute();$vrecs=$vrecs->fetchAll();
$orecs=$pdo->prepare("SELECT visitor_name,observer_chapter,observer_category,amount,payment_method,status,friday_date FROM transactions WHERE type='Observer' $dc $sc2 ORDER BY friday_date DESC");
if($fdate)$orecs->bindValue(':fd',$fdate);if($fsearch)$orecs->bindValue(':s',"%$fsearch%");$orecs->execute();$orecs=$orecs->fetchAll();
?>
<div class="content">
<div class="d-flex gap-2 align-items-end mb-3 flex-wrap">
  <div><div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:var(--gry);margin-bottom:3px">Search name</div><input type="text" id="rsearch" value="<?=htmlspecialchars($fsearch)?>" placeholder="Search..." style="border:1px solid var(--bdr);border-radius:8px;padding:5px 10px;font-size:.82rem;outline:none"></div>
  <div><div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:var(--gry);margin-bottom:3px">Sunday Date</div><input type="date" id="rdate" value="<?=htmlspecialchars($fdate)?>" style="border:1px solid var(--bdr);border-radius:8px;padding:5px 10px;font-size:.82rem;outline:none"></div>
  <button onclick="applyFilter()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:6px 16px;font-size:.8rem;font-weight:600;cursor:pointer">Apply</button>
  <a href="?tab=records" style="background:#fff;border:1px solid var(--bdr);border-radius:8px;padding:6px 14px;font-size:.8rem;color:var(--gry);text-decoration:none">Clear</a>
</div>
<div class="row g-3">
  <div class="col-4"><div class="panel"><div class="panel-hdr" style="background:var(--red)">Members (<?=count($mrecs)?>)</div><div class="panel-body">
  <table class="tbl"><thead><tr><th>#</th><th>Name</th><th>Date</th><th>Amt</th><th>Status</th></tr></thead><tbody>
  <?php foreach($mrecs as $i=>$r): ?><tr><td class="text-muted"><?=$i+1?></td><td><strong><?=htmlspecialchars($r['name'])?></strong><?php if($r['is_partial']): ?><br><span style="font-size:.68rem;color:#e65100">Partial</span><?php endif; ?></td><td class="text-muted"><?=date('d M',strtotime($r['friday_date']))?></td><td>₹<?=number_format($r['amount'])?></td><td><?=$r['status']==='Paid'?'<span class="badge-paid">Paid</span>':($r['status']==='Pending'?'<span class="badge-pend">Pend</span>':'<span class="badge-unp">Rej</span>')?></td></tr><?php endforeach;
  if(!$mrecs): ?><tr><td colspan="5" class="text-center text-muted py-3">No records</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
  <div class="col-4"><div class="panel"><div class="panel-hdr" style="background:#1b1b1b">Visitors (<?=count($vrecs)?>)</div><div class="panel-body">
  <table class="tbl"><thead><tr><th>#</th><th>Name</th><th>Via</th><th>Date</th><th>Status</th></tr></thead><tbody>
  <?php foreach($vrecs as $i=>$r): ?><tr><td class="text-muted"><?=$i+1?></td><td><strong><?=htmlspecialchars($r['visitor_name'])?></strong></td><td class="text-muted"><?=htmlspecialchars($r['referrer_name']??'')?></td><td class="text-muted"><?=date('d M',strtotime($r['friday_date']))?></td><td><?=$r['status']==='Paid'?'<span class="badge-paid">Paid</span>':($r['status']==='Pending'?'<span class="badge-pend">Pend</span>':'<span class="badge-unp">Rej</span>')?></td></tr><?php endforeach;
  if(!$vrecs): ?><tr><td colspan="5" class="text-center text-muted py-3">No records</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
  <div class="col-4"><div class="panel"><div class="panel-hdr" style="background:#444">Observers (<?=count($orecs)?>)</div><div class="panel-body">
  <table class="tbl"><thead><tr><th>#</th><th>Name</th><th>Chapter</th><th>Date</th><th>Status</th></tr></thead><tbody>
  <?php foreach($orecs as $i=>$r): ?><tr><td class="text-muted"><?=$i+1?></td><td><strong><?=htmlspecialchars($r['visitor_name'])?></strong></td><td class="text-muted"><?=htmlspecialchars($r['observer_chapter']??'')?></td><td class="text-muted"><?=date('d M',strtotime($r['friday_date']))?></td><td><?=$r['status']==='Paid'?'<span class="badge-paid">Paid</span>':($r['status']==='Pending'?'<span class="badge-pend">Pend</span>':'<span class="badge-unp">Rej</span>')?></td></tr><?php endforeach;
  if(!$orecs): ?><tr><td colspan="5" class="text-center text-muted py-3">No records</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
</div>
</div>
