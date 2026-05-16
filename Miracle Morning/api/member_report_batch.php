<?php
// member_report_batch.php — All members in one printable/PDF page
require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/report_calc_helper.php';
ini_set('display_errors', 0);

$rawIds = trim($_GET['ids'] ?? '');
$filter = trim($_GET['filter'] ?? 'all');
$ids = [];
foreach (explode(',', $rawIds) as $v) {
    $n = (int)trim($v);
    if ($n > 0) $ids[] = $n;
}
if (empty($ids)) { http_response_code(400); echo 'No member IDs provided.'; exit; }

// Pre‑compute global Sundays for due weeks calculation
$globalFirst = $pdo->query("SELECT MIN(friday_date) FROM transactions WHERE type='Member'")->fetchColumn();
if (!$globalFirst) {
    $tmp = new DateTime();
    if ((int)$tmp->format('N') !== 7) $tmp->modify('next sunday');
    $globalFirst = $tmp->format('Y-m-d');
}
$today = date('Y-m-d');
$allSundays = [];
$cur = new DateTime($globalFirst);
while ($cur->format('Y-m-d') <= $today) {
    $allSundays[] = $cur->format('Y-m-d');
    $cur->modify('+7 days');
}

$members = [];
foreach ($ids as $id) {
    $mQ = $pdo->prepare("SELECT * FROM members WHERE id=? LIMIT 1");
    $mQ->execute([$id]);
    $mem = $mQ->fetch(PDO::FETCH_ASSOC);
    if (!$mem) continue;

    // Recalculate weekly payments with session carry-over
    $calc = recalc_member_payments($pdo, $id);
    $memPaid      = $calc['totalPaid'];
    $memWeeks     = $calc['fullWeeks'];
    $byMonth      = $calc['byMonth'];
    $SundayData   = $calc['Sundays'];
    $sessions     = $calc['sessions'];

    // Visitors
    try {
        $vQ = $pdo->prepare("
            SELECT t.visitor_name, t.visitor_profession, t.amount,
                   t.payment_method, t.status AS txn_status, t.friday_date,
                   vd.status AS due_status
            FROM transactions t
            LEFT JOIN visitor_dues vd ON vd.txn_id=t.id AND vd.member_id=?
            WHERE t.type='Visitor' AND t.referrer_name=?
            ORDER BY t.friday_date DESC
        ");
        $vQ->execute([$id, $mem['name']]);
        $visitors = $vQ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $vQ = $pdo->prepare("SELECT visitor_name, visitor_profession, amount, payment_method, status AS txn_status, friday_date, NULL AS due_status FROM transactions WHERE type='Visitor' AND referrer_name=? ORDER BY friday_date DESC");
        $vQ->execute([$mem['name']]);
        $visitors = $vQ->fetchAll(PDO::FETCH_ASSOC);
    }

    // Kitty
    $kQ = $pdo->prepare("SELECT amount, payment_method, status, notes, submitted_at FROM kitty_payments WHERE member_id=? ORDER BY submitted_at ASC");
    $kQ->execute([$id]);
    $kittyTxns = $kQ->fetchAll(PDO::FETCH_ASSOC);

    $kittyPaid = 0;
    foreach ($kittyTxns as $k) if ($k['status'] === 'Paid') $kittyPaid += (int)$k['amount'];
    $kittyBal = max(0, 3000 - $kittyPaid);

    $visPaid = 0; $visTot = 0; $visDue = 0; $visDueTot = 0;
    foreach ($visitors as $v) {
        $isDue = ($v['txn_status'] === 'Paid') && ($v['due_status'] === 'Pending');
        $isOk  = ($v['txn_status'] === 'Paid') && !$isDue;
        if ($isOk)  { $visPaid++; $visTot    += (int)$v['amount']; }
        if ($isDue) { $visDue++;  $visDueTot += (int)$v['amount']; }
    }

    // Due weeks using recalculated data
    $fullyPaidSundays = [];
    foreach ($SundayData as $fd => $data) {
        if ($data['status'] === 'Paid' && $data['balance'] <= 0) {
            $fullyPaidSundays[$fd] = true;
        }
    }
    $dueCount = 0;
    foreach ($allSundays as $fd) {
        if (!isset($fullyPaidSundays[$fd])) $dueCount++;
    }

    // Keep txns as empty array for backward compat (display uses byMonth now)
    $txns = [];

    $members[] = compact('id','mem','txns','visitors','kittyTxns','byMonth','SundayData','sessions',
                         'memPaid','memWeeks','kittyPaid','kittyBal',
                         'visPaid','visTot','visDue','visDueTot','dueCount');
}

$printDate = date('d M Y, h:i A');
$filterLabel = $filter === 'neverpaid' ? 'Never Paid Members' : ($filter === 'thisweek' ? 'Unpaid This Week' : 'All Members');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Member Reports — <?=htmlspecialchars($filterLabel)?> (<?=count($members)?>)</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:9.5pt;color:#000;background:#f0f2f5;padding:12px}
.toolbar{max-width:800px;margin:0 auto 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{padding:7px 18px;border:none;border-radius:7px;font-size:9pt;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.btn-print{background:#D90429;color:#fff}
.btn-close{background:#fff;color:#555;border:1px solid #ccc}
.report-page{background:#fff;max-width:800px;margin:0 auto 0;padding:20px 24px 24px;page-break-after:always}
.report-page:last-child{page-break-after:auto}
.rpt-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid #D90429}
.rpt-org{font-size:14pt;font-weight:800;color:#D90429}
.rpt-sub{font-size:7.5pt;color:#666;margin-top:2px}
.rpt-date{font-size:7.5pt;color:#666;text-align:right}
.mem-card{background:#fff8f8;border:1.5px solid #ffd6dc;border-radius:7px;padding:10px 14px;margin-bottom:10px;display:flex;gap:16px;flex-wrap:wrap}
.mem-card .mf{flex:1;min-width:140px}
.mem-card .mf label{font-size:7pt;text-transform:uppercase;letter-spacing:.5px;color:#888;display:block;margin-bottom:2px}
.mem-card .mf strong{font-size:10pt;color:#111}
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:10px}
.stat-box{background:#f8f8f8;border:1px solid #e0e0e0;border-radius:5px;padding:7px 8px;text-align:center}
.stat-val{font-size:12pt;font-weight:800;color:#D90429}
.stat-lbl{font-size:6.5pt;text-transform:uppercase;letter-spacing:.3px;color:#777;margin-top:1px}
.sec{font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#fff;background:#111;padding:2px 7px;margin:10px 0 5px;display:block}
.sec.amber{background:#c47800}.sec.blue{background:#1565c0}
table{width:100%;border-collapse:collapse;font-size:8pt;margin-bottom:2px}
th{background:#eee;border:1px solid #ccc;padding:2px 6px;font-size:7pt;text-transform:uppercase;font-weight:700;text-align:center;white-space:nowrap}
td{border:1px solid #ddd;padding:2px 6px;vertical-align:middle}
.tot-row td{background:#fff0f2;font-weight:700}
.b-paid{color:#1b5e20;font-weight:700}.b-pend{color:#c47800;font-weight:700}
.b-rej{color:#c62828;font-weight:700}.b-due{color:#c62828;font-weight:700;background:#fff0f2;padding:0 4px;border-radius:3px}
.month-lbl{font-size:7.5pt;font-weight:700;color:#555;background:#f5f5f5;border-left:3px solid #D90429;padding:2px 5px;margin:6px 0 3px;display:block}
.page-num{font-size:7pt;color:#999;text-align:right;margin-top:8px;padding-top:6px;border-top:1px dashed #ddd}
@media print {
    @page{size:A4;margin:8mm 10mm}
    body{background:#fff;padding:0}
    .toolbar{display:none}
    .report-page{box-shadow:none;border-radius:0;padding:0;max-width:none;margin-bottom:0}
    .sec,.stat-val,.tot-row td,.mem-card{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn btn-print" onclick="window.print()">🖨 Print / Save All as PDF</button>
  <button class="btn btn-close" onclick="window.close()">✕ Close</button>
  <span style="font-size:8pt;color:#666">
    <strong><?=count($members)?> members</strong> · <?=htmlspecialchars($filterLabel)?> · <?=$printDate?>
  </span>
  <span style="font-size:8pt;color:#888;margin-left:auto">Tip: Print → Save as PDF → gets all <?=count($members)?> reports in one file</span>
</div>

<?php foreach ($members as $idx => $d):
  extract($d);
  $today2 = date('Y-m-d');
?>
<div class="report-page">
  <div class="rpt-head">
    <div><div class="rpt-org">Miracle Morning</div><div class="rpt-sub">Coimbatore Chapter &nbsp;|&nbsp; Member Payment Report</div></div>
    <div class="rpt-date">Printed: <?=$printDate?><br>Member ID: #<?=$id?> &nbsp;·&nbsp; <?=$idx+1?>/<?=count($members)?></div>
  </div>

  <div class="mem-card">
    <div class="mf"><label>Member Name</label><strong><?=htmlspecialchars($mem['name'])?></strong></div>
    <div class="mf"><label>Company</label><strong><?=htmlspecialchars($mem['company_name']??'—')?></strong></div>
    <div class="mf"><label>Category</label><strong><?=htmlspecialchars($mem['category']??'—')?></strong></div>
    <?php if(!empty($mem['mobile'])): ?><div class="mf"><label>Mobile</label><strong><?=htmlspecialchars($mem['mobile'])?></strong></div><?php endif; ?>
    <div class="mf"><label>Status</label><strong style="color:<?=$mem['status']==='Active'?'#1b5e20':'#c62828'?>"><?=$mem['status']?></strong></div>
  </div>

  <div class="stats">
    <div class="stat-box"><div class="stat-val"><?=$memWeeks?></div><div class="stat-lbl">Weeks Paid</div></div>
    <div class="stat-box"><div class="stat-val" style="color:<?=$dueCount>0?'#c62828':'#1b5e20'?>"><?=$dueCount?></div><div class="stat-lbl">Weeks Due</div><?php if($dueCount>0): ?><div style="font-size:7pt;color:#c62828;font-weight:700">₹<?=number_format($dueCount*1450)?></div><?php endif; ?></div>
    <div class="stat-box"><div class="stat-val">₹<?=number_format($memPaid)?></div><div class="stat-lbl">Fee Collected</div></div>
    <div class="stat-box"><div class="stat-val" style="color:<?=$kittyBal>0?'#D90429':'#1b5e20'?>">₹<?=number_format($kittyPaid)?></div><div class="stat-lbl">Kitty / ₹3,000</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#1565c0"><?=$visPaid?></div><div class="stat-lbl">Visitors</div></div>
  </div>

  <span class="sec">1. Weekly Meeting Fee History</span>
  <?php if($byMonth): ?>
    <?php foreach($byMonth as $mLabel => $mRows):
      $mPaid=0;$mWks=0;
      foreach($mRows as $r){
        $mPaid += $r['amount_paid'];
        if($r['balance'] <= 0) $mWks++;
      }
    ?>
    <span class="month-lbl"><?=$mLabel?> — <?=$mWks?> wk<?=$mWks!=1?'s':''?> · ₹<?=number_format($mPaid)?></span>
    <table>
      <thead><tr><th>Sunday Date</th><th>Status</th><th>Mode</th><th style="text-align:right">Amount</th><th>Paid On</th></tr></thead>
      <tbody>
      <?php foreach($mRows as $r):
        if ($r['status'] === 'Paid' && $r['balance'] <= 0) { $ss = 'Paid'; }
        elseif ($r['status'] === 'Paid' && $r['balance'] > 0) { $ss = 'Partial (Bal: ₹'.number_format($r['balance']).')'; }
        elseif ($r['status'] === 'Pending') { $ss = ($r['friday_date'] < $today2) ? 'Due' : 'Pending'; }
        else { $ss = $r['status']; }
      ?>
      <tr>
        <td class="center"><?=date('d M Y',strtotime($r['friday_date']))?></td>
        <td class="center"><?=$ss?></td>
        <td class="center"><?=htmlspecialchars($r['payment_method']??'—')?></td>
        <td class="right bold">₹<?=number_format($r['amount_paid'])?></td>
        <td class="center"><?=$r['paid_date'] ? date('d M Y', strtotime($r['paid_date'])) : '—'?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>
    <table style="width:auto;margin-left:auto;margin-top:4px">
      <tr class="tot-row"><td style="padding:3px 10px;border:1px solid #ddd">Total weeks paid</td><td style="padding:3px 10px;border:1px solid #ddd;font-weight:700"><?=$memWeeks?></td><td style="padding:3px 10px;border:1px solid #ddd">Total collected</td><td style="padding:3px 10px;border:1px solid #ddd;font-weight:700;color:#D90429">₹<?=number_format($memPaid)?></td></tr>
    </table>
  <?php else: ?><div style="padding:6px;font-size:8.5pt;color:#888;font-style:italic">No weekly fee payments recorded.</div><?php endif; ?>

  <span class="sec amber">2. Kitty Cash Payments</span>
  <?php $pct = $kittyPaid>0 ? round($kittyPaid/3000*100) : 0; ?>
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px">
    <div style="flex:1;height:8px;background:#f0f0f0;border-radius:4px;overflow:hidden;border:1px solid #e0e0e0"><div style="height:100%;background:<?=$kittyBal<=0?'#1b5e20':'#c47800'?>;width:<?=$pct?>%"></div></div>
    <span style="font-size:8pt;font-weight:700;color:<?=$kittyBal<=0?'#1b5e20':'#c47800'?>">₹<?=number_format($kittyPaid)?>/₹3,000 (<?=$pct?>%)<?=$kittyBal<=0?' ✓ Full':'  — ₹'.number_format($kittyBal).' due'?></span>
  </div>
  <?php if($kittyTxns): ?>
  <td>
    <thead><tr><th>Date</th><th>Mode</th><th>Status</th><th style="text-align:right">Amount</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach($kittyTxns as $k): $ksc=$k['status']==='Paid'?'b-paid':($k['status']==='Pending'?'b-pend':'b-rej'); ?>
    <tr><td class="center"><?=date('d M Y',strtotime($k['submitted_at']))?></td><td class="center"><?=htmlspecialchars($k['payment_method'])?></td><td class="center"><span class="<?=$ksc?>"><?=$k['status']?></span></td><td class="right bold">₹<?=number_format($k['amount'])?></td><td style="font-size:7.5pt;color:#666"><?=htmlspecialchars($k['notes']??'')?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><div style="padding:6px;font-size:8.5pt;color:#888;font-style:italic">No kitty payments recorded.</div><?php endif; ?>

  <span class="sec blue">3. Visitors Referred</span>
  <?php if($visDue>0): ?><div style="background:#fff0f2;border:1px solid #ffd6dc;border-radius:5px;padding:5px 10px;margin-bottom:5px;font-size:8pt;color:#c62828;font-weight:700">⚠ <?=$visDue?> visitor fee<?=$visDue>1?'s':''?> due — ₹<?=number_format($visDueTot)?> to collect from member</div><?php endif; ?>
  <?php if($visitors): ?>
  <tr>
    <thead><tr><th>#</th><th>Visitor</th><th>Date</th><th>Mode</th><th>Status</th><th style="text-align:right">Amount</th></tr></thead>
    <tbody>
    <?php foreach($visitors as $vi=>$v):
      $isDue=($v['txn_status']==='Paid')&&($v['due_status']==='Pending');
      if($isDue){$vss='Due from Member';}
      elseif($v['txn_status']==='Paid'){$vss='Paid';}
      elseif($v['txn_status']==='Pending'){$vss='Pending';}
      else{$vss='Rejected';}
    ?>
    <tr><td class="center"><?=$vi+1?></td><td><strong><?=htmlspecialchars($v['visitor_name'])?></strong><?php if(!empty($v['visitor_profession'])): ?><div style="font-size:7pt;color:#888"><?=htmlspecialchars($v['visitor_profession'])?></div><?php endif; ?></td><td class="center"><?=date('d M Y',strtotime($v['friday_date']))?></td><td class="center"><?=htmlspecialchars($v['payment_method']??'—')?></td><td class="center"><?=$vss?></td><td class="right bold">₹<?=number_format($v['amount'])?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><div style="padding:6px;font-size:8.5pt;color:#888;font-style:italic">No visitors referred yet.</div><?php endif; ?>

  <div class="page-num">Miracle Morning · Coimbatore Chapter &nbsp;·&nbsp; <?=$printDate?> &nbsp;·&nbsp; Member: <?=htmlspecialchars($mem['name'])?> (#<?=$id?>) &nbsp;·&nbsp; Page <?=$idx+1?>/<?=count($members)?></div>
</div>
<?php endforeach; ?>

<script>
if (window.location.search.indexOf('print=1') >= 0) {
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });
}
</script>
</body>
</html>