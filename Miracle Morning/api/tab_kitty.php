<?php
$kAll = $pdo->query("
    SELECT m.id, m.name, m.company_name,
           COALESCE(SUM(CASE WHEN k.status='Paid' THEN k.amount ELSE 0 END),0) AS paid,
           COALESCE(SUM(CASE WHEN k.status='Pending' THEN k.amount ELSE 0 END),0) AS pend_amt,
           COUNT(CASE WHEN k.status='Paid' THEN 1 END) AS txns
    FROM members m LEFT JOIN kitty_payments k ON k.member_id=m.id
    WHERE m.status='Active' GROUP BY m.id,m.name,m.company_name ORDER BY paid ASC,m.name
")->fetchAll();

// All kitty transactions for inline edit
$kTxnsAll = [];
foreach ($pdo->query("SELECT k.id, k.member_id, k.amount, k.payment_method, k.status, k.notes, DATE(k.submitted_at) AS paid_date FROM kitty_payments k ORDER BY k.member_id, k.submitted_at DESC")->fetchAll() as $kt)
    $kTxnsAll[$kt['member_id']][] = $kt;

$totalKPaid = (float)array_sum(array_column($kAll,'paid'));
$fullPaid   = count(array_filter($kAll, function($m){ return $m['paid'] >= 3000; }));
$kGoal      = $total_active * 3000;
?>
<style>
.kit-row{cursor:pointer}
.kit-row:hover td{background:#fafafa}
.kit-detail{display:none;background:#f8f9fa}
.kit-detail.open{display:table-row}
</style>

<div class="content">
<div class="row g-3 mb-3">
  <div class="col-3"><div class="scard"><div class="val" style="color:var(--red)">₹<?=number_format($totalKPaid)?></div><div class="lbl">Total collected</div></div></div>
  <div class="col-3"><div class="scard"><div class="val text-success"><?=$fullPaid?></div><div class="lbl">Fully paid</div></div></div>
  <div class="col-3"><div class="scard"><div class="val" style="color:var(--red)"><?=$total_active-$fullPaid?></div><div class="lbl">Balance pending</div></div></div>
  <div class="col-3"><div class="scard"><div class="val text-muted">₹<?=number_format($kGoal-$totalKPaid)?></div><div class="lbl">Outstanding</div></div></div>
</div>
<div class="scard">
<table class="tbl">
  <thead><tr><th>#</th><th>Member</th><th style="width:140px">Progress</th><th>Paid</th><th>Balance</th><th>Pending</th><th>Txns</th><th>Status</th><th style="text-align:center">Edit</th></tr></thead>
  <tbody>
  <?php foreach($kAll as $i => $m):
    $paid = (float)$m['paid']; $bal = max(0,3000-$paid); $pct = min(100,round($paid/3000*100));
    $txns = isset($kTxnsAll[$m['id']]) ? $kTxnsAll[$m['id']] : [];
    if($paid>=3000)  $badge='<span class="badge-paid">Full ✓</span>';
    elseif($paid>0)  $badge='<span class="badge-part">Partial</span>';
    else             $badge='<span class="badge-unp">Not started</span>';
  ?>
  <tr class="kit-row" onclick="kitToggle(<?=$m['id']?>)">
    <td class="text-muted"><?=$i+1?></td>
    <td><strong><?=htmlspecialchars($m['name'])?></strong><br><span style="font-size:.7rem;color:var(--gry)"><?=htmlspecialchars($m['company_name']??'')?></span></td>
    <td><div class="prog-track"><div class="prog-fill" style="width:<?=$pct?>%"></div></div><span style="font-size:.68rem;color:var(--gry)"><?=$pct?>%</span></td>
    <td>₹<?=number_format($paid)?></td>
    <td style="color:<?=$bal>0?'var(--red)':'#2e7d32'?>"><?=$bal>0?'₹'.number_format($bal):'—'?></td>
    <td><?=$m['pend_amt']>0?'<span class="badge-pend">₹'.number_format($m['pend_amt']).'</span>':'—'?></td>
    <td class="text-muted"><?=$m['txns']?></td>
    <td><?=$badge?></td>
    <td style="text-align:center" onclick="event.stopPropagation()">
      <?php if($txns): ?>
      <button class="btn-edi" onclick="kitToggle(<?=$m['id']?>)" title="View & edit payments" style="font-size:.72rem">✎</button>
      <?php endif; ?>
    </td>
  </tr>
  <!-- Detail/edit row -->
  <tr class="kit-detail" id="kit_d_<?=$m['id']?>">
    <td colspan="9" style="padding:0">
      <div style="padding:10px 18px 14px;border-top:2px solid #fff8e1">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:var(--gry);margin-bottom:6px">
          Kitty Payments · <?=htmlspecialchars($m['name'])?>
        </div>
        <?php if($txns): ?>
        <table style="font-size:.8rem;border-collapse:collapse;width:auto">
          <tr style="background:#f0f0f0">
            <th style="padding:3px 8px;border:1px solid #ddd">Date</th>
            <th style="padding:3px 8px;border:1px solid #ddd">Mode</th>
            <th style="padding:3px 8px;border:1px solid #ddd">Status</th>
            <th style="padding:3px 8px;border:1px solid #ddd;text-align:right">Amount</th>
            <th style="padding:3px 8px;border:1px solid #ddd">Notes</th>
            <th style="padding:3px 8px;border:1px solid #ddd"></th>
          </tr>
          <?php foreach($txns as $kt):
            $ksc = $kt['status']==='Paid'?'#1b5e20':($kt['status']==='Pending'?'#c47800':'#c62828');
          ?>
          <tr>
            <td style="padding:3px 8px;border:1px solid #eee"><?=htmlspecialchars($kt['paid_date']??'')?></td>
            <td style="padding:3px 8px;border:1px solid #eee"><?=htmlspecialchars($kt['payment_method']??'')?></td>
            <td style="padding:3px 8px;border:1px solid #eee;color:<?=$ksc?>;font-weight:700"><?=$kt['status']?></td>
            <td style="padding:3px 8px;border:1px solid #eee;text-align:right;font-weight:700">₹<?=number_format($kt['amount'])?></td>
            <td style="padding:3px 8px;border:1px solid #eee;font-size:.75rem;color:#888"><?=htmlspecialchars($kt['notes']??'')?></td>
            <td style="padding:3px 8px;border:1px solid #eee;white-space:nowrap">
              <button class="btn-edi"
                      onclick="kitEditOpen(<?=json_encode($kt)?>)"
                      style="font-size:.72rem;padding:2px 6px">✎ Edit</button>
              <button class="btn-del"
                      onclick="kitDelete(<?=$kt['id']?>,<?=$m['id']?>)"
                      style="font-size:.72rem;padding:2px 6px;margin-left:3px">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php else: ?>
        <div style="font-size:.82rem;color:var(--gry)">No payments recorded yet.</div>
        <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>

<!-- KITTY EDIT MODAL -->
<div id="kitEditBg" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);z-index:1000;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)kitEditClose()">
  <div style="background:#fff;border-radius:14px;padding:20px 22px;width:360px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.22)">
    <h3 style="font-size:.95rem;font-weight:700;margin-bottom:14px">Edit Kitty Payment</h3>
    <input type="hidden" id="kit_edit_id">
    <input type="hidden" id="kit_edit_mid">
    <div style="margin-bottom:10px"><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;text-transform:uppercase;color:#888">Date</label>
      <input type="date" id="kit_edit_date" style="width:100%;border:1.5px solid #ccc;border-radius:8px;padding:7px 10px;font-size:.88rem"></div>
    <div style="margin-bottom:10px"><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;text-transform:uppercase;color:#888">Amount (₹)</label>
      <input type="number" id="kit_edit_amt" min="1" style="width:100%;border:1.5px solid #ccc;border-radius:8px;padding:7px 10px;font-size:.88rem"></div>
    <div style="margin-bottom:10px"><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;text-transform:uppercase;color:#888">Payment Mode</label>
      <select id="kit_edit_mode" style="width:100%;border:1.5px solid #ccc;border-radius:8px;padding:7px 10px;font-size:.88rem">
        <option value="Cash">Cash</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Card">Card</option>
        <option value="FinCloud">FinCloud</option>
      </select></div>
    <div style="margin-bottom:10px"><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;text-transform:uppercase;color:#888">Status</label>
      <select id="kit_edit_status" style="width:100%;border:1.5px solid #ccc;border-radius:8px;padding:7px 10px;font-size:.88rem">
        <option value="Paid">Paid</option>
        <option value="Pending">Pending</option>
        <option value="Rejected">Rejected</option>
      </select></div>
    <div style="margin-bottom:12px"><label style="font-size:.72rem;font-weight:700;display:block;margin-bottom:3px;text-transform:uppercase;color:#888">Notes</label>
      <input type="text" id="kit_edit_notes" maxlength="200" style="width:100%;border:1.5px solid #ccc;border-radius:8px;padding:7px 10px;font-size:.88rem"></div>
    <div id="kit_edit_err" style="display:none;padding:7px 10px;background:#fff0f2;border-radius:7px;font-size:.82rem;color:#c62828;margin-bottom:10px"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px">
      <button onclick="kitEditClose()" style="background:none;border:1px solid #ccc;border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="kitEditSave()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:.82rem;font-weight:700;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<script>
function kitToggle(id) {
    var r = document.getElementById('kit_d_' + id);
    if (!r) return;
    r.classList.toggle('open');
}

function kitEditOpen(r) {
    document.getElementById('kit_edit_id').value     = r.id || '';
    document.getElementById('kit_edit_mid').value    = r.member_id || '';
    document.getElementById('kit_edit_date').value   = r.paid_date || '';
    document.getElementById('kit_edit_amt').value    = r.amount || '';
    document.getElementById('kit_edit_mode').value   = r.payment_method || 'Cash';
    document.getElementById('kit_edit_status').value = r.status || 'Paid';
    document.getElementById('kit_edit_notes').value  = r.notes || '';
    document.getElementById('kit_edit_err').style.display = 'none';
    document.getElementById('kitEditBg').style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center';
}

function kitEditClose() {
    document.getElementById('kitEditBg').style.display = 'none';
}

function kitEditSave() {
    var fd = new FormData();
    fd.append('action',       'edit_kitty');
    fd.append('id',           document.getElementById('kit_edit_id').value);
    fd.append('member_id',    document.getElementById('kit_edit_mid').value);
    fd.append('amount',       document.getElementById('kit_edit_amt').value);
    fd.append('mode',         document.getElementById('kit_edit_mode').value);
    fd.append('status',       document.getElementById('kit_edit_status').value);
    fd.append('notes',        document.getElementById('kit_edit_notes').value);
    fd.append('submitted_at', document.getElementById('kit_edit_date').value);
    fetch('member_action.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                document.getElementById('kit_edit_err').textContent = d.msg || 'Error';
                document.getElementById('kit_edit_err').style.display = 'block';
                return;
            }
            kitEditClose();
            location.reload();
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

function kitDelete(id, memberId) {
    if (!confirm('Delete this kitty payment permanently?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_kitty');
    fd.append('id', id);
    fetch('member_action.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) location.reload();
            else alert('Error: ' + (d.msg || 'Failed'));
        });
}

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') kitEditClose();
});
</script>
