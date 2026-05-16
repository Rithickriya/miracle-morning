<?php
$allPend  = $pdo->query("SELECT t.*,m.name AS mname FROM transactions t LEFT JOIN members m ON t.member_id=m.id WHERE t.status='Pending' ORDER BY t.submitted_at DESC")->fetchAll();
$allKPend = $pdo->query("SELECT k.*,m.name AS mname FROM kitty_payments k JOIN members m ON k.member_id=m.id WHERE k.status='Pending' ORDER BY k.submitted_at DESC")->fetchAll();
?>
<div class="content">
<div class="row g-3">
  <div class="col-8">
    <strong>Payment approvals (<?=count($allPend)?>)</strong>
    <div class="mt-2">
    <?php if(!$allPend): ?>
    <div class="scard text-center text-muted py-4">✓ No pending payments</div>
    <?php else: foreach($allPend as $r):
      $nm=$r['mname']?:$r['visitor_name'];
      $tc=['Member'=>'var(--red)','Visitor'=>'#1565c0','Observer'=>'#555'][$r['type']]??'#333';
    ?>
    <div class="acard mb-2" id="ap_<?=$r['id']?>">
      <div class="d-flex justify-content-between align-items-start">
        <div style="flex:1">
          <div class="acard-name"><?=htmlspecialchars($nm)?>
            <span style="color:<?=$tc?>;font-size:.72rem;font-weight:700;margin-left:6px"><?=$r['type']?></span>
            <?php if($r['is_partial']??0): ?><span class="badge-part ms-1">Partial</span><?php endif; ?>
          </div>
          <div class="acard-meta">
            <?=date('d M Y',strtotime($r['friday_date']??date('Y-m-d')))?> &middot;
            <?=$r['payment_method']?>
            <?php if($r['referrer_name']??''): ?>&middot; via <?=htmlspecialchars($r['referrer_name'])?><?php endif; ?>
            <?php if($r['visitor_profession']??''): ?>&middot; <?=htmlspecialchars($r['visitor_profession'])?><?php endif; ?>
            <?php if($r['observer_chapter']??''): ?>&middot; <?=htmlspecialchars($r['observer_chapter'])?><?php endif; ?>
            <?php if($r['is_partial']??0): ?>&middot; Paid ₹<?=$r['partial_paid']?> &middot; Bal ₹<?=$r['partial_balance']?><?php endif; ?>
            <br>Submitted: <?=date('d M H:i',strtotime($r['submitted_at']))?>
          </div>
        </div>
        <div class="acard-amt ms-3">₹<?=number_format($r['amount'])?></div>
      </div>
      <div class="d-flex gap-2 mt-2">
        <button class="btn-app" onclick="act(<?=$r['id']?>,'verify','transactions')">✓ Approve</button>
        <button class="btn-rej" onclick="act(<?=$r['id']?>,'reject','transactions')">✗ Reject</button>
        <button class="btn-edt" onclick="openEdit(<?=$r['id']?>,'transactions','<?=htmlspecialchars(addslashes($nm))?>',<?=(int)$r['amount']?>,'<?=$r['payment_method']?>')">Edit</button>
      </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="col-4">
    <strong>Kitty approvals (<?=count($allKPend)?>)</strong>
    <div class="mt-2">
    <?php if(!$allKPend): ?>
    <div class="scard text-center text-muted py-4">✓ No kitty pending</div>
    <?php else: foreach($allKPend as $r): ?>
    <div class="acard mb-2" id="ap_k<?=$r['id']?>">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="acard-name"><?=htmlspecialchars($r['mname'])?></div>
          <div class="acard-meta"><?=$r['payment_method']?> &middot; <?=date('d M H:i',strtotime($r['submitted_at']))?><?php if($r['notes']??''): ?> &middot; "<?=htmlspecialchars($r['notes'])?>"<?php endif; ?></div>
        </div>
        <div class="acard-amt" style="color:#c47800">₹<?=number_format($r['amount'])?></div>
      </div>
      <div class="d-flex gap-2 mt-2">
        <button class="btn-app" onclick="act(<?=$r['id']?>,'verify','kitty')">✓ Approve</button>
        <button class="btn-rej" onclick="act(<?=$r['id']?>,'reject','kitty')">✗ Reject</button>
      </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
  </div>
</div>
</div>
