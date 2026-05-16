<?php
// tab_st.php — ST Report

$selDt  = new DateTime($sel, new DateTimeZone('Asia/Kolkata'));
$prevSunday = (clone $selDt)->modify('-7 days')->format('Y-m-d');
$nextSunday = (clone $selDt)->modify('+7 days')->format('Y-m-d');

// Members paid/unpaid this week (only those who submitted on this day)
$_piq = $pdo->prepare("SELECT DISTINCT member_id FROM transactions WHERE type='Member' AND status='Paid' AND friday_date=? AND DATE(submitted_at)=?");
$_piq->execute([$sel,$sel]);
$paidIds = array_flip($_piq->fetchAll(PDO::FETCH_COLUMN));

$allMem      = $pdo->query("SELECT id,name,category FROM members WHERE status='Active' ORDER BY name ASC")->fetchAll();
$paidThisWeek   = array_values(array_filter($allMem, function($m) use($paidIds){ return  isset($paidIds[$m['id']]); }));
$unpaidThisWeek = array_values(array_filter($allMem, function($m) use($paidIds){ return !isset($paidIds[$m['id']]); }));

// Per-member total paid this session
$memPaidDetail = [];
$_mpd = $pdo->prepare("
    SELECT t.member_id, m.name, m.category, t.payment_method, t.verified_at,
           (SELECT SUM(t2.amount) FROM transactions t2
            WHERE t2.member_id=t.member_id AND t2.type='Member' AND t2.status='Paid'
            AND DATE(t2.submitted_at)=DATE(t.submitted_at)) AS total_session,
           (SELECT COUNT(*) FROM transactions t3
            WHERE t3.member_id=t.member_id AND t3.type='Member' AND t3.status='Paid'
            AND DATE(t3.submitted_at)=DATE(t.submitted_at)) AS weeks_covered
    FROM transactions t JOIN members m ON t.member_id=m.id
    WHERE t.type='Member' AND t.status='Paid' AND t.friday_date=? AND DATE(t.submitted_at)=?
    ORDER BY t.verified_at DESC
");
$_mpd->execute([$sel,$sel]);
foreach ($_mpd->fetchAll() as $r) $memPaidDetail[$r['member_id']] = $r;

// Payment summary — actual cash collected this Sunday
$rcvdByType = [];
$paidMids = array_keys($paidIds);
if (!empty($paidMids)) {
    $ph = implode(',', array_fill(0, count($paidMids), '?'));
    $bq = $pdo->prepare("
        SELECT payment_method, SUM(amount) AS total, COUNT(DISTINCT member_id) AS nos
        FROM transactions
        WHERE type='Member' AND status='Paid' AND member_id IN ($ph) AND DATE(submitted_at)=?
        GROUP BY payment_method
    ");
    $bq->execute(array_merge($paidMids, [$sel]));
    foreach ($bq->fetchAll() as $r) {
        $m = $r['payment_method'];
        if (!isset($rcvdByType['Member'])) $rcvdByType['Member'] = ['nos'=>0,'cash'=>0,'card'=>0,'upi'=>0,'fc'=>0,'total'=>0];
        $rcvdByType['Member']['nos']   += $r['nos'];
        $rcvdByType['Member']['total'] += $r['total'];
        if ($m==='Cash') $rcvdByType['Member']['cash']+=$r['total'];
        elseif ($m==='Card') $rcvdByType['Member']['card']+=$r['total'];
        elseif (in_array($m,['UPI','QR Code (UPI)'])) $rcvdByType['Member']['upi']+=$r['total'];
        elseif ($m==='FinCloud') $rcvdByType['Member']['fc']+=$r['total'];
    }
}

$guestQ = $pdo->prepare("SELECT type, payment_method, SUM(amount) t, COUNT(*) n FROM transactions WHERE status='Paid' AND type IN('Visitor','Observer') AND friday_date=? GROUP BY type, payment_method");
$guestQ->execute([$sel]);
foreach ($guestQ->fetchAll() as $r) {
    $t=$r['type']; $m=$r['payment_method'];
    if (!isset($rcvdByType[$t])) $rcvdByType[$t]=['nos'=>0,'cash'=>0,'card'=>0,'upi'=>0,'fc'=>0,'total'=>0];
    $rcvdByType[$t]['nos']+=$r['n']; $rcvdByType[$t]['total']+=$r['t'];
    if($m==='Cash') $rcvdByType[$t]['cash']+=$r['t'];
    elseif($m==='Card') $rcvdByType[$t]['card']+=$r['t'];
    elseif(in_array($m,['UPI','QR Code (UPI)'])) $rcvdByType[$t]['upi']+=$r['t'];
    elseif($m==='FinCloud') $rcvdByType[$t]['fc']+=$r['t'];
}
$rcvdGrand = 0; foreach ($rcvdByType as $rt) $rcvdGrand += $rt['total'];

// ── Kitty payments this week — ONLY on actual Sundays ────────────────────────
// If sel is not a Sunday, kitty data belongs to the next sunday's report
$kittyRows = [];
$rcvdByType['Kitty'] = ['nos'=>0,'cash'=>0,'card'=>0,'upi'=>0,'fc'=>0,'total'=>0];
$isSunday = ((int)$selDt->format('N') === 7);
if ($isSunday) {
    try {
        $kq = $pdo->prepare("
            SELECT k.id, m.name, m.category, k.amount, k.payment_method, k.verified_at, k.notes
            FROM kitty_payments k
            JOIN members m ON k.member_id = m.id
            WHERE k.status = 'Paid'
              AND k.verified_at >= DATE_SUB(?, INTERVAL 6 DAY)
              AND k.verified_at <  DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY k.verified_at DESC
        ");
        $kq->execute([$sel, $sel]);
        $kittyRows = $kq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($kittyRows as $k) {
            $m = $k['payment_method'];
            $rcvdByType['Kitty']['nos']++;
            $rcvdByType['Kitty']['total'] += (float)$k['amount'];
            if ($m==='Cash') $rcvdByType['Kitty']['cash'] += $k['amount'];
            elseif ($m==='Card') $rcvdByType['Kitty']['card'] += $k['amount'];
            elseif (in_array($m,['UPI','QR Code (UPI)'])) $rcvdByType['Kitty']['upi'] += $k['amount'];
            elseif ($m==='FinCloud') $rcvdByType['Kitty']['fc'] += $k['amount'];
        }
    } catch (Exception $e) {}
}
$kittyTotal = $rcvdByType['Kitty']['total'];
$rcvdGrandWithKitty = $rcvdGrand + $kittyTotal;

// ── Visitor dues pending for this Sunday's visitors ────────────────────────────
$visDues = [];
try {
    $vdQ = $pdo->prepare("
        SELECT vd.visitor_name, vd.amount, m.name AS member_name
        FROM visitor_dues vd
        JOIN members m ON m.id = vd.member_id
        JOIN transactions t ON t.id = vd.txn_id
        WHERE vd.status = 'Pending' AND t.friday_date = ?
        ORDER BY m.name ASC, vd.visitor_name ASC
    ");
    $vdQ->execute([$sel]);
    $visDues = $vdQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$visDuesTotal = array_sum(array_column($visDues, 'amount'));

// Visitors & Observers
$vis_rows = $pdo->prepare("SELECT id,visitor_name,visitor_profession,referrer_name,payment_method,amount,verified_at,business_card FROM transactions WHERE type='Visitor' AND status='Paid' AND friday_date=? ORDER BY verified_at DESC");
$vis_rows->execute([$sel]); $vis_rows=$vis_rows->fetchAll();
$obs_rows = $pdo->prepare("SELECT id,visitor_name,observer_chapter,observer_category,payment_method,amount,verified_at,business_card FROM transactions WHERE type='Observer' AND status='Paid' AND friday_date=? ORDER BY verified_at DESC");
$obs_rows->execute([$sel]); $obs_rows=$obs_rows->fetchAll();
?>
<div class="content">

<!-- Week nav -->
<div style="background:#fff;border:1px solid var(--bdr);border-radius:12px;padding:10px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
  <a href="?tab=st&date=<?=$prevSunday?>" class="btn-exp outline" style="font-size:.78rem;padding:5px 12px">&larr; Prev</a>
  <div style="flex:1;text-align:center;font-weight:700;font-size:.92rem"><?=date('l, d M Y',strtotime($sel))?></div>
  <form method="GET" style="display:flex;gap:6px">
    <input type="hidden" name="tab" value="st">
    <input type="date" name="date" value="<?=$sel?>" class="date-input" onchange="this.form.submit()">
  </form>
  <a href="?tab=st&date=<?=$nextSunday?>" class="btn-exp outline" style="font-size:.78rem;padding:5px 12px">Next &rarr;</a>
  <a href="?tab=st" class="btn-exp" style="font-size:.78rem;padding:5px 12px">Today</a>
</div>

<!-- Stat pills -->
<div class="row g-2 mb-3">
  <div class="col"><div class="scard text-center py-2"><div class="val" style="color:#1b5e20;font-size:1.3rem"><?=count($paidThisWeek)?></div><div class="lbl">Paid</div></div></div>
  <div class="col"><div class="scard text-center py-2"><div class="val" style="color:var(--red);font-size:1.3rem"><?=count($unpaidThisWeek)?></div><div class="lbl">Unpaid</div></div></div>
  <div class="col"><div class="scard text-center py-2"><div class="val" style="color:var(--red);font-size:1.3rem"><?=count($vis_rows)?></div><div class="lbl">Visitors</div></div></div>
  <div class="col"><div class="scard text-center py-2"><div class="val" style="color:var(--red);font-size:1.3rem"><?=count($obs_rows)?></div><div class="lbl">Observers</div></div></div>
  <div class="col"><div class="scard text-center py-2"><div class="val" style="color:#c47800;font-size:1.1rem"><?=count($kittyRows)?></div><div class="lbl">Kitty</div></div></div>
  <div class="col"><div class="scard text-center py-2"><div class="val" style="color:var(--red);font-size:1rem">₹<?=number_format($rcvdGrandWithKitty)?></div><div class="lbl">Collected</div></div></div>
</div>

<!-- Payment summary + Unpaid list -->
<div class="row g-3 mb-3">
  <div class="col-8">
    <div class="scard">
      <div class="d-flex justify-content-between mb-2">
        <div class="lbl">Payment summary &mdash; <?=date('d M Y',strtotime($sel))?></div>
        <span style="font-size:.68rem;color:var(--gry);background:#f5f5f5;padding:2px 8px;border-radius:8px">Total cash collected incl. advance payments</span>
      </div>
      <table class="tbl">
        <thead><tr><th>Type</th><th style="text-align:center">Nos</th><th style="text-align:right">Cash</th><th style="text-align:right">Card</th><th style="text-align:right">UPI/QR</th><th style="text-align:right">FinCloud</th><th style="text-align:right">Total</th></tr></thead>
        <tbody>
        <?php $totN=$totC=$totCd=$totU=$totF=$totA=0;
        foreach (['Member'=>'Members','Visitor'=>'Visitors','Observer'=>'Observers','Kitty'=>'Kitty Cash'] as $tk=>$tl):
          $r=$rcvdByType[$tk]??['nos'=>0,'cash'=>0,'card'=>0,'upi'=>0,'fc'=>0,'total'=>0];
          $totN+=$r['nos'];$totC+=$r['cash'];$totCd+=$r['card'];$totU+=$r['upi'];$totF+=$r['fc'];$totA+=$r['total'];
          $rowStyle = $tk==='Kitty' ? 'background:#fffde7' : '';
        ?>
        <tr style="<?=$rowStyle?>">
          <td style="font-weight:600;<?=$tk==='Kitty'?'color:#c47800':''?>"><?=$tl?></td>
          <td style="text-align:center"><?=$r['nos']?:0?></td>
          <td style="text-align:right"><?=$r['cash']>0?'₹'.number_format($r['cash']):'—'?></td>
          <td style="text-align:right"><?=$r['card']>0?'₹'.number_format($r['card']):'—'?></td>
          <td style="text-align:right"><?=$r['upi']>0?'₹'.number_format($r['upi']):'—'?></td>
          <td style="text-align:right"><?=$r['fc']>0?'₹'.number_format($r['fc']):'—'?></td>
          <td style="text-align:right;font-weight:700;<?=$tk==='Kitty'?'color:#c47800':''?>">₹<?=number_format($r['total']??0)?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid var(--bdr);font-weight:700;background:#fafafa">
          <td>Grand Total</td><td style="text-align:center"><?=$totN?></td>
          <td style="text-align:right"><?=$totC>0?'₹'.number_format($totC):'—'?></td>
          <td style="text-align:right"><?=$totCd>0?'₹'.number_format($totCd):'—'?></td>
          <td style="text-align:right"><?=$totU>0?'₹'.number_format($totU):'—'?></td>
          <td style="text-align:right"><?=$totF>0?'₹'.number_format($totF):'—'?></td>
          <td style="text-align:right;color:var(--red)">₹<?=number_format($totA)?></td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-4">
    <div class="scard" style="height:100%">
      <div class="lbl mb-2">Unpaid this week (<?=count($unpaidThisWeek)?>)</div>
      <div style="max-height:180px;overflow-y:auto">
      <?php if(!$unpaidThisWeek): ?><div style="color:#1b5e20;font-size:.82rem;text-align:center;padding:16px">🎉 All paid!</div>
      <?php else: foreach($unpaidThisWeek as $ui=>$m): ?>
      <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f5f5f5;font-size:.8rem">
        <span><?=$ui+1?>. <strong><?=htmlspecialchars($m['name'])?></strong></span>
        <span style="font-size:.7rem;color:var(--gry)"><?=htmlspecialchars($m['category']??'')?></span>
      </div>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 3 columns -->
<div class="row g-3">
  <!-- Paid Members -->
  <div class="col-4">
    <div class="panel" style="height:46vh">
      <div class="panel-hdr" style="background:#1b5e20">
        Paid (<?=count($paidThisWeek)?>)
        <?php $sesTotal=array_sum(array_column($memPaidDetail,'total_session')); ?>
        <span style="float:right;font-size:.72rem;opacity:.85">₹<?=number_format($sesTotal)?></span>
      </div>
      <div class="panel-body" style="padding:0">
      <?php if(!$paidThisWeek): ?><div class="text-center text-muted py-3" style="font-size:.78rem">None yet</div>
      <?php else: ?>
      <table class="tbl">
        <thead><tr><th>#</th><th>Member</th><th>Mode</th><th style="text-align:right">Total paid</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach($paidThisWeek as $pi=>$m):
          $pd=$memPaidDetail[$m['id']]??null;
          $tot=(float)($pd['total_session']??0); $wks=(int)($pd['weeks_covered']??1);
        ?>
        <tr>
          <td class="text-muted"><?=$pi+1?></td>
          <td><strong><?=htmlspecialchars($m['name'])?></strong><?php if($wks>1): ?><div style="font-size:.68rem;color:var(--gry)"><?=$wks?> weeks</div><?php endif; ?></td>
          <td><?=$pd?'<span class="badge-mode">'.htmlspecialchars($pd['payment_method']).'</span>':'—'?></td>
          <td style="text-align:right;font-weight:700;color:var(--red)"><?=$tot>0?'₹'.number_format($tot):'—'?></td>
          <td style="font-size:.7rem;color:var(--gry)"><?=$pd&&$pd['verified_at']?date('d M H:i',strtotime($pd['verified_at'])):'—'?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Visitors -->
  <div class="col-4">
    <div class="panel" style="height:46vh">
      <div class="panel-hdr" style="background:#1b1b1b">Visitors (<?=count($vis_rows)?>) <span style="float:right;font-size:.72rem;opacity:.85">₹<?=number_format(array_sum(array_column($vis_rows,'amount')))?></span></div>
      <div class="panel-body" style="padding:0">
      <?php if(!$vis_rows): ?><div class="text-center text-muted py-3" style="font-size:.78rem">No visitors</div>
      <?php else: ?>
      <table class="tbl">
        <thead><tr><th>#</th><th>Name</th><th>Category</th><th>Invited by</th><th>Mode</th><th style="text-align:right">Amt</th><th>Card</th></tr></thead>
        <tbody>
        <?php foreach($vis_rows as $vi=>$v): ?>
        <tr>
          <td class="text-muted"><?=$vi+1?></td>
          <td><strong><?=htmlspecialchars($v['visitor_name'])?></strong></td>
          <td style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($v['visitor_profession']??'—')?></td>
          <td><?=htmlspecialchars($v['referrer_name']??'—')?></td>
          <td><span class="badge-mode"><?=htmlspecialchars($v['payment_method'])?></span></td>
          <td style="text-align:right;font-weight:600">₹<?=number_format($v['amount'])?></td>
          <td style="text-align:center;white-space:nowrap">
            <?php if($v['business_card']??''): ?>
            <a href="/api/uploads/cards/<?=htmlspecialchars($v['business_card'])?>" target="_blank"
               style="font-size:.7rem;font-weight:600;color:#1565c0;text-decoration:none;margin-right:3px"
               title="View card">👁</a>
            <a href="/api/uploads/cards/<?=htmlspecialchars($v['business_card'])?>" download
               style="font-size:.7rem;font-weight:600;color:#1b5e20;text-decoration:none;margin-right:3px"
               title="Download">⬇</a>
            <button onclick="deleteCard(<?=(int)$v['id']?>,'st_vis_<?=$vi?>')"
                    style="background:none;border:none;font-size:.75rem;cursor:pointer;color:#c62828" title="Delete card">✕</button>
            <?php else: ?>
            <span style="font-size:.7rem;color:#bbb">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:1.5px solid var(--bdr);font-weight:700;background:#fafafa"><td colspan="6" style="text-align:right">Total</td><td style="text-align:right;color:var(--red)">₹<?=number_format(array_sum(array_column($vis_rows,'amount')))?></td></tr>
        </tbody>
      </table>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Observers -->
  <div class="col-4">
    <div class="panel" style="height:46vh">
      <div class="panel-hdr" style="background:#444">Observers (<?=count($obs_rows)?>) <span style="float:right;font-size:.72rem;opacity:.85">₹<?=number_format(array_sum(array_column($obs_rows,'amount')))?></span></div>
      <div class="panel-body" style="padding:0">
      <?php if(!$obs_rows): ?><div class="text-center text-muted py-3" style="font-size:.78rem">No observers</div>
      <?php else: ?>
      <table class="tbl">
        <thead><tr><th>#</th><th>Name</th><th>Chapter</th><th>Category</th><th>Mode</th><th style="text-align:right">Amt</th><th>Card</th></tr></thead>
        <tbody>
        <?php foreach($obs_rows as $oi=>$o): ?>
        <tr>
          <td class="text-muted"><?=$oi+1?></td>
          <td><strong><?=htmlspecialchars($o['visitor_name'])?></strong></td>
          <td style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($o['observer_chapter']??'—')?></td>
          <td style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($o['observer_category']??'—')?></td>
          <td><span class="badge-mode"><?=htmlspecialchars($o['payment_method'])?></span></td>
          <td style="text-align:right;font-weight:600">₹<?=number_format($o['amount'])?></td>
          <td style="text-align:center;white-space:nowrap">
            <?php if($o['business_card']??''): ?>
            <a href="/api/uploads/cards/<?=htmlspecialchars($o['business_card'])?>" target="_blank"
               style="font-size:.7rem;font-weight:600;color:#1565c0;text-decoration:none;margin-right:3px"
               title="View card">👁</a>
            <a href="/api/uploads/cards/<?=htmlspecialchars($o['business_card'])?>" download
               style="font-size:.7rem;font-weight:600;color:#1b5e20;text-decoration:none;margin-right:3px"
               title="Download">⬇</a>
            <button onclick="deleteCard(<?=(int)$o['id']?>,'st_obs_<?=$oi?>')"
                    style="background:none;border:none;font-size:.75rem;cursor:pointer;color:#c62828" title="Delete card">✕</button>
            <?php else: ?>
            <span style="font-size:.7rem;color:#bbb">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:1.5px solid var(--bdr);font-weight:700;background:#fafafa"><td colspan="6" style="text-align:right">Total</td><td style="text-align:right;color:var(--red)">₹<?=number_format(array_sum(array_column($obs_rows,'amount')))?></td></tr>
        </tbody>
      </table>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($kittyRows): ?>
<!-- ── KITTY CASH THIS WEEK ── -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="scard">
      <div class="panel-hdr" style="background:#c47800;border-radius:8px;margin-bottom:10px">
        Kitty Cash Collected &nbsp;
        <span style="opacity:.85">(<?=count($kittyRows)?>)</span>
        <span style="float:right;font-size:.82rem;opacity:.9">₹<?=number_format($kittyTotal)?></span>
      </div>
      <table class="tbl">
        <thead>
          <tr>
            <th style="text-align:center">#</th>
            <th style="text-align:left">Member</th>
            <th style="text-align:left">Category</th>
            <th style="text-align:center">Mode</th>
            <th style="text-align:right">Amount</th>
            <th>Date Paid</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($kittyRows as $ki => $k): ?>
        <tr>
          <td style="text-align:center;color:#888"><?=$ki+1?></td>
          <td><strong><?=htmlspecialchars($k['name'])?></strong></td>
          <td style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($k['category']??'—')?></td>
          <td style="text-align:center"><span class="badge-mode"><?=htmlspecialchars($k['payment_method'])?></span></td>
          <td style="text-align:right;font-weight:700;color:#c47800">₹<?=number_format($k['amount'])?></td>
          <td style="font-size:.72rem;color:var(--gry)"><?=$k['verified_at']?date('d M Y H:i',strtotime($k['verified_at'])):'—'?></td>
          <td style="font-size:.72rem;color:#888"><?=htmlspecialchars($k['notes']??'')?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:1.5px solid var(--bdr);font-weight:700;background:#fffde7">
          <td colspan="6" style="text-align:right">Total Kitty Collected</td>
          <td style="text-align:right;color:#c47800">₹<?=number_format($kittyTotal)?></td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($visDues): ?>
<!-- ── VISITOR DUES PENDING ── -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="scard">
      <div class="panel-hdr" style="background:#6a1b9a;border-radius:8px;margin-bottom:10px">
        Visitor Dues Pending &nbsp;
        <span style="opacity:.85">(<?=count($visDues)?>)</span>
        <span style="float:right;font-size:.82rem;opacity:.9">₹<?=number_format($visDuesTotal)?></span>
      </div>
      <div style="font-size:.75rem;padding:4px 6px;background:#fdf7ff;border:1px solid #e1bee7;color:#6a1b9a;border-radius:5px;margin-bottom:8px">
        Visitors entered — fee pending collection from their member
      </div>
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th>
            <th style="text-align:left">Visitor Name</th>
            <th style="text-align:left">Collect From (Member)</th>
            <th style="text-align:right">Amount</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($visDues as $di => $d): ?>
        <tr>
          <td style="text-align:center;color:#888"><?=$di+1?></td>
          <td><?=htmlspecialchars($d['visitor_name'])?></td>
          <td style="font-weight:700;color:#6a1b9a"><?=htmlspecialchars($d['member_name'])?></td>
          <td style="text-align:right;font-weight:700;color:#6a1b9a">₹<?=number_format($d['amount'])?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:#fdf7ff;font-weight:700;border-top:1.5px solid var(--bdr)">
          <td colspan="3" style="text-align:right">Total Pending</td>
          <td style="text-align:right;color:#6a1b9a">₹<?=number_format($visDuesTotal)?></td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
function deleteCard(txnId, rowPrefix) {
    if (!confirm('Delete this business card? This cannot be undone.')) return;
    fetch('verify_action.php?action=delete_card&id=' + txnId)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                // Replace card cell with —
                var btns = document.querySelectorAll('[onclick*="deleteCard(' + txnId + ')"]');
                btns.forEach(function(b){
                    var cell = b.closest('td');
                    if (cell) cell.innerHTML = '<span style="font-size:.7rem;color:#bbb">—</span>';
                });
            } else { alert('Error: ' + (d.msg||'Failed')); }
        }).catch(function(e){ alert('Request failed: ' + e.message); });
}
</script>
