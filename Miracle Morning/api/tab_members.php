<?php
// ═══════════════════════════════════════════════════════════════════
// tab_members.php
// Uses the EXACT original queries that were working before.
// No mobile/email in SQL — those columns may not exist.
// ═══════════════════════════════════════════════════════════════════
require_once __DIR__ . '/report_calc_helper.php';

// ── Queries (identical to the original working version) ────────────
$allMD = $pdo->query("
    SELECT m.id, m.name, m.company_name, m.category,
           COALESCE(SUM(CASE WHEN t.status='Paid' THEN t.amount ELSE 0 END), 0) AS total_paid,
           COUNT(CASE WHEN t.status='Paid' AND t.is_partial=0 THEN 1 END) AS full_weeks,
           COUNT(CASE WHEN t.status='Paid' AND t.is_partial=1 THEN 1 END) AS partial_weeks,
           COUNT(CASE WHEN t.status='Pending' THEN 1 END) AS pending_cnt,
           MAX(CASE WHEN t.status='Paid' THEN t.friday_date END) AS last_paid_date
    FROM members m
    LEFT JOIN transactions t ON t.member_id=m.id AND t.type='Member'
    WHERE m.status='Active'
    GROUP BY m.id, m.name, m.company_name, m.category
    ORDER BY m.name ASC
")->fetchAll();

$kittyMp2 = [];
foreach ($pdo->query("SELECT member_id, SUM(amount) AS p FROM kitty_payments WHERE status='Paid' GROUP BY member_id")->fetchAll() as $kr)
    $kittyMp2[$kr['member_id']] = (int)$kr['p'];

$memberTxns2 = [];
foreach ($pdo->query("SELECT member_id, friday_date, amount, payment_method, status, is_partial, partial_paid, partial_balance, submitted_at, COALESCE(original_total,0) AS original_total FROM transactions WHERE type='Member' ORDER BY member_id ASC, friday_date ASC")->fetchAll() as $tx)
    $memberTxns2[$tx['member_id']][] = $tx;

// ── Recalculate per-member totals with carry-over ───────────────────
$memberRecalc = [];
foreach ($memberTxns2 as $mid => $txns) {
    $memberRecalc[$mid] = recalc_from_txns($txns);
}
// Override allMD with recalculated values
foreach ($allMD as &$_m) {
    if (isset($memberRecalc[$_m['id']])) {
        $rc = $memberRecalc[$_m['id']];
        $_m['total_paid']     = $rc['totalPaid'];
        $_m['full_weeks']     = $rc['fullWeeks'];
        $_m['partial_weeks']  = $rc['partialWeeks'];
    }
}
unset($_m);

// ── Visitor count per member (by referrer_name) ─────────────────────
$visitorCounts = [];
try {
    foreach ($pdo->query("
        SELECT m.id, COUNT(t.id) AS vcnt
        FROM members m
        LEFT JOIN transactions t
          ON t.type = 'Visitor'
         AND (
              t.member_id = m.id
              OR LOWER(TRIM(REPLACE(REPLACE(REPLACE(t.referrer_name, '  ', ' '), '  ', ' '), '  ', ' ')))
               = LOWER(TRIM(REPLACE(REPLACE(REPLACE(m.name, '  ', ' '), '  ', ' '), '  ', ' ')))
         )
        WHERE m.status='Active'
        GROUP BY m.id
    ")->fetchAll() as $vc)
        $visitorCounts[$vc['id']] = (int)$vc['vcnt'];
} catch (Exception $e) {}

// ── Week filter param ───────────────────────────────────────────────
$memWeekFilter = isset($_GET['mem_week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['mem_week'])
    ? $_GET['mem_week'] : 'all';

// ── Next 8 upcoming Sundays ─────────────────────────────────────────
$upcoming8 = [];
$_fd = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
if ((int)$_fd->format('N') !== 7) $_fd->modify('next sunday');
for ($i = 0; $i < 8; $i++) { $upcoming8[] = $_fd->format('Y-m-d'); $_fd->modify('+7 days'); }
$thisWeekDate = $upcoming8[0];

// ── All distinct Sundays (for filter dropdown) ──────────────────────
$allSundays = array_unique(array_map(function($tx){ return $tx['friday_date']; },
    call_user_func_array('array_merge', array_values($memberTxns2) ?: [[]])));
rsort($allSundays);

// ── Stats ───────────────────────────────────────────────────────────
$today = date('Y-m-d');

// Past Sundays where at least one member paid (= meetings that happened)
$_pfQ = $pdo->prepare("SELECT DISTINCT friday_date FROM transactions WHERE type='Member' AND status='Paid' AND friday_date < ? ORDER BY friday_date ASC");
$_pfQ->execute([$today]);
$pastSundays = $_pfQ->fetchAll(PDO::FETCH_COLUMN);

// Per-member paid dates + first paid date (used to calculate due weeks correctly)
$memberPaidDates     = [];
$memberFirstPaidDate = [];
foreach ($memberTxns2 as $mid => $txns) {
    $memberPaidDates[$mid] = [];
    foreach ($txns as $tx) {
        if ($tx['status'] === 'Paid') {
            $memberPaidDates[$mid][$tx['friday_date']] = 1;
            // Track earliest paid Sunday
            if (!isset($memberFirstPaidDate[$mid]) || $tx['friday_date'] < $memberFirstPaidDate[$mid]) {
                $memberFirstPaidDate[$mid] = $tx['friday_date'];
            }
        }
    }
}

$memNeverPaid  = 0;
$memPaidThisWk = 0;
$totalFeeColl  = 0;
$totalDueAmt   = 0;
foreach ($allMD as $m) {
    if ((float)$m['total_paid'] === 0.0) $memNeverPaid++;
    $totalFeeColl += (float)$m['total_paid'];
    $txns = isset($memberTxns2[$m['id']]) ? $memberTxns2[$m['id']] : [];
    foreach ($txns as $tx) {
        if ($tx['friday_date'] === $thisWeekDate && $tx['status'] === 'Paid') { $memPaidThisWk++; break; }
    }
    // Due amount for this member
    $paidDates = isset($memberPaidDates[$m['id']]) ? $memberPaidDates[$m['id']] : [];
    $due = 0; foreach ($pastSundays as $pf) { if (!isset($paidDates[$pf])) $due++; }
    $totalDueAmt += $due * 1450;
}
$memUnpaidThisWk = count($allMD) - $memPaidThisWk;

// ── Build monthly summary per member (grouped by year-month) ───────
// Used in expanded detail rows
?>

<style>
/* ── week chips ───────────────────────────────────────────── */
.wchip{display:inline-flex;align-items:center;justify-content:center;min-width:52px;padding:2px 7px;border-radius:20px;font-size:.66rem;font-weight:700;white-space:nowrap;border:1px solid transparent;margin:1px 1px;cursor:default}
.wc-paid   {background:#e8f5e9;color:#1b5e20;border-color:#a5d6a7}
.wc-part   {background:#fff3e0;color:#e65100;border-color:#ffcc80}
.wc-pend   {background:#fff8e1;color:#c47800;border-color:#ffe082}
.wc-rej    {background:#ffebee;color:#c62828;border-color:#ffcdd2}
.wc-future {background:#f5f5f5;color:#bbb;border-color:#e0e0e0}
/* ── rows ─────────────────────────────────────────────────── */
.mem-row{cursor:pointer}
.mem-row:hover td{background:#fafafa}
.mem-unpaid td{background:#fff8f8!important}
.mem-paid td{background:#f2fdf3!important}
.mem-paid:hover td{background:#e8f9ea!important}
/* ── dialog ───────────────────────────────────────────────── */
.mem-dlg-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center}
.mem-dlg-box{background:#fff;border-radius:16px;padding:22px 24px;width:460px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.22)}
.mem-dlg-box h3{font-size:.95rem;font-weight:700;margin-bottom:14px}
.mfg{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:11px}
.mfg > div > label{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:4px}
.mfg input,.mfg select{width:100%;border:1.5px solid var(--bdr);border-radius:9px;padding:8px 11px;font-size:.88rem;font-family:inherit;outline:none}
.mfg input:focus,.mfg select:focus{border-color:var(--red)}
/* ── monthly view ─────────────────────────────────────────── */
.month-block{margin-bottom:10px}
.month-hdr{font-size:.72rem;font-weight:700;color:#fff;background:#555;padding:2px 8px;border-radius:4px;margin-bottom:4px;display:inline-block}
</style>

<div class="content">

<!-- ── STATS ROW (Image 2 style) ── -->
<div class="row g-3 mb-3">
  <div class="col-3"><div class="scard">
    <div class="val" style="color:var(--red)">₹<?=number_format($totalFeeColl)?></div>
    <div class="lbl">Total Fee Collected</div>
  </div></div>
  <div class="col-3"><div class="scard">
    <div class="val text-success"><?=$memPaidThisWk?></div>
    <div class="lbl">Paid This Week</div>
  </div></div>
  <div class="col-3"><div class="scard">
    <div class="val" style="color:var(--red)"><?=$memUnpaidThisWk?></div>
    <div class="lbl">Unpaid This Week</div>
  </div></div>
  <div class="col-3"><div class="scard">
    <div class="val text-muted">₹<?=number_format($totalDueAmt)?></div>
    <div class="lbl">Total Outstanding Due</div>
  </div></div>
</div>

<div class="scard">

  <!-- ── TOP BAR ── -->
  <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <strong>All Members (<?=count($allMD)?>)</strong>

    <button onclick="memFilter('thisweek')" id="pill_thisweek"
            style="background:#f5f5f5;border:1px solid var(--bdr);border-radius:20px;padding:2px 10px;font-size:.74rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;cursor:pointer"
            title="Click to show members unpaid this week">
      Unpaid this week
      <span style="background:#c62828;color:#fff;border-radius:10px;padding:0 7px;font-size:.7rem"><?=$memUnpaidThisWk?></span>
    </button>
    <button onclick="memFilter('neverpaid')" id="pill_neverpaid"
            style="background:#f5f5f5;border:1px solid var(--bdr);border-radius:20px;padding:2px 10px;font-size:.74rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;cursor:pointer"
            title="Click to show members who never paid">
      Never paid
      <span style="background:var(--red);color:#fff;border-radius:10px;padding:0 7px;font-size:.7rem"><?=$memNeverPaid?></span>
    </button>

    <input type="text" id="mem_srch" placeholder="Search name or category…"
           oninput="memFilter()"
           style="border:1px solid var(--bdr);border-radius:8px;padding:5px 12px;font-size:.82rem;outline:none;width:220px">

    <!-- Week filter -->
    <form method="GET" style="display:inline-flex;align-items:center;gap:6px;margin:0">
      <input type="hidden" name="tab" value="members">
      <input type="hidden" name="date" value="<?=htmlspecialchars($sel)?>">
      <select name="mem_week" onchange="this.form.submit()"
              style="border:1px solid var(--bdr);border-radius:8px;padding:4px 10px;font-size:.8rem;outline:none;cursor:pointer">
        <option value="all" <?=$memWeekFilter==='all'?'selected':''?>>All weeks</option>
        <?php foreach($allSundays as $fw): ?>
        <option value="<?=$fw?>" <?=$memWeekFilter===$fw?'selected':''?>><?=date('d M Y',strtotime($fw))?></option>
        <?php endforeach; ?>
      </select>
      <?php if($memWeekFilter!=='all'): ?>
      <a href="?tab=members&date=<?=htmlspecialchars($sel)?>"
         style="font-size:.78rem;color:var(--red);text-decoration:none;font-weight:600">✕</a>
      <?php endif; ?>
    </form>

    <span style="font-size:.75rem;color:var(--gry)">Click row to expand</span>

    <button onclick="memDlgOpen()"
            style="margin-left:auto;background:var(--red);color:#fff;border:none;border-radius:8px;padding:5px 14px;font-size:.8rem;font-weight:700;cursor:pointer">
      + Add Member
    </button>
    <button onclick="downloadAllReports()"
            style="background:#fff;color:#555;border:1px solid #ccc;border-radius:8px;padding:5px 12px;font-size:.8rem;font-weight:600;cursor:pointer"
            title="Download all member PDFs individually as ZIP">
      ⬇ All PDFs (ZIP)
    </button>
  </div>

  <!-- ── TABLE ── -->
  <table class="tbl" id="mem_tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Member</th>
        <th>Category</th>
        <th style="text-align:center">Paid Wks</th>
        <th style="text-align:center;color:#c62828">Due Wks</th>
        <th style="text-align:right">Total paid</th>
        <th style="text-align:center">Pending</th>
        <th style="text-align:center">Kitty</th>
        <th style="text-align:center">Kitty bal</th>
        <th>Last paid</th>
        <th style="text-align:center">Visitors</th>
        <th style="text-align:center">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($allMD as $mi => $m):
      $txns  = isset($memberTxns2[$m['id']]) ? $memberTxns2[$m['id']] : [];
      $kpaid = isset($kittyMp2[$m['id']]) ? $kittyMp2[$m['id']] : 0;
      $kbal  = max(0, 3000 - $kpaid);
      $total = (float)$m['total_paid'];

      // Week filter: skip row if week filter active and no paid txn for that week
      if ($memWeekFilter !== 'all') {
          $hasWeekPaid = false;
          foreach ($txns as $tx) {
              if ($tx['friday_date'] === $memWeekFilter && $tx['status'] === 'Paid') { $hasWeekPaid = true; break; }
          }
          if (!$hasWeekPaid) continue;
      }

      // Due weeks = past Sundays SINCE member's first payment with no paid transaction
      $paidDates  = isset($memberPaidDates[$m['id']])     ? $memberPaidDates[$m['id']]     : [];
      $firstPaid  = isset($memberFirstPaidDate[$m['id']]) ? $memberFirstPaidDate[$m['id']] : null;
      $dueWeeks   = 0;
      if ($firstPaid) {
          foreach ($pastSundays as $pf) {
              if ($pf >= $firstPaid && !isset($paidDates[$pf])) $dueWeeks++;
          }
      }

      $paidNow = false;
      // Check recalculated data first (handles carry-over)
      if (isset($memberRecalc[$m['id']]['SundayBal'][$thisWeekDate])) {
          $fb = $memberRecalc[$m['id']]['SundayBal'][$thisWeekDate];
          if ($fb['paid'] > 0 && $fb['balance'] <= 0) $paidNow = true;
      }
      // Fallback: also check raw transactions
      if (!$paidNow) {
          foreach ($txns as $tx) {
              if ($tx['friday_date'] === $thisWeekDate && $tx['status'] === 'Paid') { $paidNow = true; break; }
          }
      }
      $isNeverPaid = ((float)$m['total_paid'] === 0.0);
    ?>
    <tr class="mem-row <?=$paidNow?'mem-paid':(!$isNeverPaid?'mem-unpaid':'')?>"
        data-name="<?=strtolower(htmlspecialchars($m['name']))?>"
        data-cat="<?=strtolower(htmlspecialchars($m['category']??''))?>"
        data-thisweek="<?=$paidNow?'1':'0'?>"
        data-neverpaid="<?=$isNeverPaid?'1':'0'?>"
        data-id="<?=$m['id']?>"
        onclick="toggleMem(<?=$m['id']?>)">
      <td class="text-muted"><?=$mi+1?></td>
      <td>
        <strong style="cursor:pointer;color:var(--red);text-decoration:underline dotted"
                onclick="event.stopPropagation();openMemCard(<?=$m['id']?>)"
                title="View full details"><?=htmlspecialchars($m['name'])?></strong>
        <div style="font-size:.7rem;color:var(--gry)"><?=htmlspecialchars($m['company_name']??'')?></div>
        <?php if(!$paidNow && !$isNeverPaid && $dueWeeks > 0): ?>
        <span style="font-size:.63rem;background:#fff3e0;color:#e65100;border-radius:4px;padding:0 5px;font-weight:700"><?=$dueWeeks?> wk<?=$dueWeeks>1?'s':''?> due</span>
        <?php elseif(!$paidNow && $isNeverPaid): ?>
        <span style="font-size:.63rem;background:#ffebee;color:#c62828;border-radius:4px;padding:0 5px;font-weight:700">Never paid</span>
        <?php elseif(!$paidNow): ?>
        <span style="font-size:.63rem;background:#fff8e1;color:#c47800;border-radius:4px;padding:0 5px;font-weight:700">Not paid this week</span>
        <?php endif; ?>
      </td>
      <td style="font-size:.75rem;color:var(--gry)"><?=htmlspecialchars($m['category']??'—')?></td>
      <td style="text-align:center;font-weight:600">
        <?=(int)$m['full_weeks']?>
        <?php if((int)$m['partial_weeks']>0): ?>
        <span class="badge-part">+<?=$m['partial_weeks']?>p</span>
        <?php endif; ?>
      </td>
      <td style="text-align:center;font-weight:700;color:<?=$dueWeeks>0?'#c62828':'#1b5e20'?>">
        <?=$dueWeeks>0?$dueWeeks.' wk'.($dueWeeks>1?'s':'').'  due':'—'?>
      </td>
      <td style="text-align:right;font-weight:700;color:<?=$total>0?'var(--red)':'var(--gry)'?>"><?=$total>0?'₹'.number_format($total):'—'?></td>
      <td style="text-align:center"><?=(int)$m['pending_cnt']>0?'<span class="badge-pend">'.(int)$m['pending_cnt'].'</span>':'—'?></td>
      <td style="text-align:center;font-weight:600"><?=$kpaid>0?'₹'.number_format($kpaid):'—'?></td>
      <td style="text-align:center;color:<?=$kbal>0?'var(--red)':'#1b5e20'?>;font-weight:600"><?=$kbal>0?'₹'.number_format($kbal):'✓ Full'?></td>
      <td style="font-size:.75rem;color:var(--gry)"><?=$m['last_paid_date']?date('d M Y',strtotime($m['last_paid_date'])):'No history'?></td>
      <?php $vCnt = isset($visitorCounts[$m['id']]) ? $visitorCounts[$m['id']] : 0; ?>
      <td style="text-align:center;font-weight:600;color:<?=$vCnt>0?'#1565c0':'var(--gry)'?>">
        <?=$vCnt>0?$vCnt:'—'?>
      </td>
      <td style="text-align:center" onclick="event.stopPropagation()">
        <div style="display:flex;gap:3px;justify-content:center">
            
          <!-- ✎ Edit now opens member card popup (full editing) -->
          <button class="btn-edi"
            onclick="event.stopPropagation();openMemCard(<?=$m['id']?>)">✎</button>
          <button class="btn-del"
            onclick="memDlgDel(<?=$m['id']?>,<?=json_encode($m['name'])?>)">✕</button>
          <a href="member_report.php?id=<?=$m['id']?>" target="_blank"
             style="background:none;color:#555;border:1px solid #ccc;border-radius:6px;padding:3px 6px;font-size:.72rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center"
             title="Print / PDF report">🖨</a>
        </div>
      </td>
    </tr>

    <!-- ── EXPANDED DETAIL ── -->
    <tr id="mem_detail_<?=$m['id']?>" style="display:none;background:#f8f9fa">
      <td colspan="12" style="padding:0">
        <div style="padding:12px 20px 18px;border-top:2px solid #fff0f2">

          <!-- Summary strip -->
          <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:.82rem;padding-bottom:8px;margin-bottom:12px;border-bottom:1px solid var(--bdr)">
            <span>Total paid <strong style="color:var(--red)">₹<?=number_format($total)?></strong></span>
            <span>Kitty <strong style="color:<?=$kbal>0?'var(--red)':'#1b5e20'?>">₹<?=number_format($kpaid)?>/3,000<?=$kbal<=0?' ✓':''?></strong></span>
            <span>Weeks paid <strong><?=(int)$m['full_weeks']?><?=(int)$m['partial_weeks']>0?' +'.$m['partial_weeks'].'p':''?></strong></span>
            <?php if($m['last_paid_date']): ?>
            <span>Last paid <strong><?=date('d M Y',strtotime($m['last_paid_date']))?></strong></span>
            <?php endif; ?>
          </div>

          <?php if($txns): ?>

          <!-- ── ALL PAYMENT HISTORY: week chips ── -->
          <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:5px">
            Week-by-Week History
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:2px;margin-bottom:14px">
            <?php foreach($txns as $tx):
              if ($tx['status']==='Paid' && !$tx['is_partial'])   { $wc='wc-paid'; $wi='✓'; $wa='₹'.number_format($tx['amount']); }
              elseif($tx['status']==='Paid' && $tx['is_partial']) { $wc='wc-part'; $wi='⚠'; $wa='₹'.number_format($tx['partial_paid']).' partial'; }
              elseif($tx['status']==='Pending')                   { $wc='wc-pend'; $wi='⏳';$wa='₹'.number_format($tx['amount']); }
              else                                                 { $wc='wc-rej';  $wi='✗'; $wa='Rejected'; }
              $wt = date('d M Y',strtotime($tx['friday_date'])).' · '.$tx['payment_method'].' · '.$wa;
            ?>
            <span class="wchip <?=$wc?>" title="<?=htmlspecialchars($wt)?>"><?=$wi?> <?=date('d M',strtotime($tx['friday_date']))?></span>
            <?php endforeach; ?>
          </div>

          <!-- ── MONTHLY VIEW ── -->
          <?php
          // Group transactions by year-month
          $byMonth = [];
          foreach ($txns as $tx) {
              $mk = date('Y-m', strtotime($tx['friday_date']));
              $byMonth[$mk][] = $tx;
          }
          ksort($byMonth);
          // Reverse for newest first
          $byMonth = array_reverse($byMonth, true);
          ?>
          <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:8px">
            Month-by-Month View
          </div>
          <?php foreach($byMonth as $mk => $mTxns):
            $mLabel      = date('F Y', strtotime($mk.'-01'));
            $mPaidTotal  = 0;
            $mWeeksCount = 0;
            foreach($mTxns as $tx) {
                if($tx['status']==='Paid') {
                    $mPaidTotal += $tx['is_partial'] ? (int)$tx['partial_paid'] : (int)$tx['amount'];
                    $mWeeksCount++;
                }
            }
          ?>
          <div class="month-block">
            <span class="month-hdr"><?=$mLabel?></span>
            <?php if($mWeeksCount > 0): ?>
            <span style="font-size:.72rem;color:var(--red);font-weight:700;margin-left:6px">₹<?=number_format($mPaidTotal)?></span>
            <span style="font-size:.7rem;color:var(--gry);margin-left:4px"><?=$mWeeksCount?> wk<?=$mWeeksCount>1?'s':''?></span>
            <?php endif; ?>
            <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:4px">
              <?php foreach($mTxns as $tx):
                if ($tx['status']==='Paid' && !$tx['is_partial'])   { $wc='wc-paid'; $wi='✓'; }
                elseif($tx['status']==='Paid' && $tx['is_partial']) { $wc='wc-part'; $wi='⚠'; }
                elseif($tx['status']==='Pending')                   { $wc='wc-pend'; $wi='⏳'; }
                else                                                 { $wc='wc-rej';  $wi='✗'; }
                $amt = $tx['is_partial'] ? '₹'.number_format($tx['partial_paid']).' partial' : '₹'.number_format($tx['amount']);
              ?>
              <span class="wchip <?=$wc?>"
                    title="<?=date('d M Y',strtotime($tx['friday_date'])).' · '.$tx['payment_method'].' · '.$amt?>">
                <?=$wi?> <?=date('d',strtotime($tx['friday_date']))?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- ── PAYMENT SESSIONS TABLE ── -->
          <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:5px;margin-top:10px">
            Payment Sessions
          </div>
          <?php
          $batches2 = [];
          foreach ($txns as $tx) {
              $bk = $tx['submitted_at']; // full timestamp = unique session
              if (!isset($batches2[$bk])) $batches2[$bk] = ['total'=>0,'count'=>0,'mode'=>$tx['payment_method'],'weeks'=>[],'status'=>$tx['status'],'date'=>date('Y-m-d',strtotime($tx['submitted_at'])),'orig_total'=>0];
              $batches2[$bk]['count']++;
              $batches2[$bk]['weeks'][] = date('d M', strtotime($tx['friday_date']));
              // Use original_total if available (stores the actual session payment amount)
              if (!empty($tx['original_total']) && (int)$tx['original_total'] > $batches2[$bk]['orig_total']) {
                  $batches2[$bk]['orig_total'] = (int)$tx['original_total'];
              }
              $batches2[$bk]['total'] += $tx['is_partial'] ? (int)$tx['partial_paid'] : (int)$tx['amount'];
          }
          // Use original_total when available (more accurate for session amount)
          foreach ($batches2 as &$_bt) {
              if ($_bt['orig_total'] > 0) $_bt['total'] = $_bt['orig_total'];
          }
          unset($_bt);
          ?>
          <table style="font-size:.78rem;border-collapse:collapse;width:auto;max-width:100%;margin-bottom:4px">
            <tr style="background:#f0f0f0">
              <th style="padding:3px 8px;border:1px solid #ddd;text-align:left">Date paid</th>
              <th style="padding:3px 8px;border:1px solid #ddd;text-align:center">Wks</th>
              <th style="padding:3px 8px;border:1px solid #ddd;text-align:left">Sundays covered</th>
              <th style="padding:3px 8px;border:1px solid #ddd;text-align:left">Mode</th>
              <th style="padding:3px 8px;border:1px solid #ddd;text-align:right">Amount</th>
            </tr>
            <?php foreach($batches2 as $bd => $bt): ?>
            <tr>
              <td style="padding:3px 8px;border:1px solid #eee"><?=date('d M Y',strtotime($bt['date']))?></td>
              <td style="padding:3px 8px;border:1px solid #eee;text-align:center"><?=$bt['count']?></td>
              <td style="padding:3px 8px;border:1px solid #eee;font-size:.72rem;color:#666"><?=htmlspecialchars(implode(', ',$bt['weeks']))?></td>
              <td style="padding:3px 8px;border:1px solid #eee"><?=htmlspecialchars($bt['mode'])?></td>
              <td style="padding:3px 8px;border:1px solid #eee;text-align:right;font-weight:700;color:var(--red)">₹<?=number_format($bt['total'])?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#fff0f2;font-weight:700">
              <td colspan="4" style="padding:3px 8px;border:1px solid #ddd;text-align:right">Total</td>
              <td style="padding:3px 8px;border:1px solid #ddd;text-align:right;color:var(--red)">₹<?=number_format($total)?></td>
            </tr>
           </table>

          <?php else: ?>
          <div style="font-size:.82rem;color:var(--gry);padding:4px 0 8px">No payment history yet.</div>
          <?php endif; ?>

          <!-- ── NEXT 8 Sundays ── -->
          <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:4px;margin-top:10px">
            Upcoming Sundays
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:3px">
            <?php foreach($upcoming8 as $ufd):
              $ust = null;
              foreach($txns as $tx) { if($tx['friday_date']===$ufd){$ust=$tx['status'];break;} }
              if ($ust==='Paid')       { $uc='wc-paid'; $ui='✓'; }
              elseif($ust==='Pending') { $uc='wc-pend'; $ui='⏳'; }
              elseif($ust!==null)      { $uc='wc-rej';  $ui='✗'; }
              else                     { $uc='wc-future';$ui=''; }
            ?>
            <span class="wchip <?=$uc?>" style="min-width:58px"><?=$ui?> <?=date('d M',strtotime($ufd))?></span>
            <?php endforeach; ?>
          </div>

        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

</div><!-- scard -->
</div><!-- content -->

<!-- ── ADD / EDIT DIALOG (for adding new member only) ── -->
<div class="mem-dlg-bg" id="memDlgBg" style="display:none" onclick="if(event.target===this)memDlgClose()">
  <div class="mem-dlg-box">
    <h3 id="memDlgH">Add Member</h3>
    <div class="mfg">
      <div><label>Full Name *</label><input type="text" id="mf_name" placeholder="Full name" maxlength="150"></div>
      <div><label>Company</label><input type="text" id="mf_co" placeholder="Company / business" maxlength="200"></div>
    </div>
    <div class="mfg">
      <div><label>Category</label><input type="text" id="mf_cat" placeholder="e.g. CA, Architect…" maxlength="150"></div>
      <div><label>Mobile</label><input type="tel" id="mf_mob" placeholder="10-digit number" maxlength="15"></div>
    </div>
    <div class="mfg">
      <div><label>Email</label><input type="email" id="mf_em" placeholder="email@example.com" maxlength="200"></div>
      <div id="mf_swrap" style="display:none">
        <label>Status</label>
        <select id="mf_stat"><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
      </div>
    </div>
    <div id="memDlgErr" style="display:none;padding:8px 12px;background:#fff0f2;border:1.5px solid #ffd6dc;border-radius:8px;font-size:.82rem;color:var(--rdk);margin-bottom:10px"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
      <button onclick="memDlgClose()" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 18px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button id="memDlgBtn" onclick="memDlgSave()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:7px 20px;font-size:.82rem;font-weight:700;cursor:pointer">Add Member</button>
    </div>
  </div>
</div>

<!-- ── MEMBER CARD POPUP (full editing) ── -->
<div class="mem-dlg-bg" id="memCardBg" style="display:none" onclick="if(event.target===this)closeMemCard()">
  <div class="mem-dlg-box" style="width:620px;max-width:96vw;max-height:88vh">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
      <div>
        <div id="mc_name" style="font-size:1.1rem;font-weight:800;color:var(--blk)"></div>
        <div id="mc_co"   style="font-size:.8rem;color:var(--gry);margin-top:2px"></div>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <a id="mc_pdf" href="#" target="_blank" style="background:var(--red);color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:.78rem;font-weight:700;text-decoration:none">🖨 Report</a>
        <button onclick="closeMemCard()" style="background:none;border:1px solid var(--bdr);border-radius:7px;padding:5px 10px;font-size:.82rem;cursor:pointer">✕ Close</button>
      </div>
    </div>

    <!-- Editable member info will be inserted here by JS -->
    <div id="mc_info" style="background:#f8f8f8;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem"></div>

    <!-- Tab nav -->
    <div style="display:flex;gap:0;border-bottom:2px solid var(--bdr);margin-bottom:12px">
      <button onclick="mcTab('payments')" id="mct_payments" class="mc-tab mc-tab-on">Payments</button>
      <button onclick="mcTab('kitty')"    id="mct_kitty"    class="mc-tab">Kitty</button>
      <button onclick="mcTab('visitors')" id="mct_visitors"  class="mc-tab">Visitors</button>
    </div>

    <!-- Payments tab -->
    <div id="mc_payments">
      <div style="font-size:.75rem;color:var(--gry);margin-bottom:6px">Click any row to edit date, amount or mode. Click ✕ to delete.</div>
      <table class="tbl" id="mc_pay_tbl" style="font-size:.8rem">
        <thead><tr><th>Paid Date</th><th>Weeks</th><th>Sundays Covered</th><th>Mode</th><th>Status</th><th style="text-align:right">Total</th><th></th></tr></thead>
        <tbody id="mc_pay_body"></tbody>
      </table>
      <button onclick="mcAddPayment()" style="margin-top:8px;background:var(--red);color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:.78rem;font-weight:700;cursor:pointer">+ Add Payment Session</button>
    </div>

    <!-- Kitty tab -->
    <div id="mc_kitty" style="display:none">
      <div style="font-size:.75rem;color:var(--gry);margin-bottom:6px">Click any row to edit. Click ✕ to delete.</div>
      <table class="tbl" id="mc_kit_tbl" style="font-size:.8rem">
        <thead><tr><th>Date</th><th>Mode</th><th>Status</th><th style="text-align:right">Amount</th><th>Notes</th><th></th></tr></thead>
        <tbody id="mc_kit_body"></tbody>
      </table>
      <button onclick="mcAddKitty()" style="margin-top:8px;background:#c47800;color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:.78rem;font-weight:700;cursor:pointer">+ Add Kitty Row</button>
    </div>

    <!-- Visitors tab -->
    <div id="mc_visitors" style="display:none">
      <table class="tbl" id="mc_vis_tbl" style="font-size:.8rem">
        <thead><tr><th>Visitor</th><th>Category</th><th>Date</th><th>Mode</th><th>Status</th><th style="text-align:right">Amt</th><th></th></tr></thead>
        <tbody id="mc_vis_body"></tbody>
      </table>
      <button onclick="mcAddVisitor()" style="margin-top:8px;background:var(--red);color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:.78rem;font-weight:700;cursor:pointer">+ Add Visitor Row</button>
    </div>

    <div id="mc_loading" style="text-align:center;padding:20px;color:var(--gry)">Loading…</div>
  </div>
</div>

<!-- ── INLINE EDIT ROW DIALOG (for payments/kitty/visitors) ── -->
<div class="mem-dlg-bg" id="mcEditBg" style="display:none" onclick="if(event.target===this)mcEditClose()">
  <div class="mem-dlg-box" style="width:380px">
    <h3 id="mcEditH">Edit Payment</h3>
    <input type="hidden" id="mce_id">
    <input type="hidden" id="mce_session_id">
    <input type="hidden" id="mce_amount" value="0">
    <input type="hidden" id="mce_friday_date" value="">
    <div class="mfg" style="grid-template-columns:1fr;gap:10px">
      <div id="mce_paid_wrap" style="display:none"><label>Paid Date</label><input type="date" id="mce_paid_date"></div>
      <div id="mce_date_wrap" style="display:none"><label>Sunday Date</label><input type="date" id="mce_date"></div>
      <div id="mce_amount_wrap" style="display:none"><label>Total Amount (₹)</label><input type="number" id="mce_total_amount" min="1" step="1" value="1450"></div>
      <div>
        <label>Payment Mode</label>
        <select id="mce_mode">
          <option value="Cash">Cash</option>
          <option value="UPI">QR Code (UPI)</option>
          <option value="Card">Card</option>
          <option value="FinCloud">FinCloud</option>
        </select>
      </div>
      <div>
        <label>Status</label>
        <select id="mce_status">
          <option value="Paid">Paid</option>
          <option value="Pending">Pending</option>
          <option value="Rejected">Rejected</option>
        </select>
      </div>
      <div id="mce_notes_wrap" style="display:none"><label>Notes</label><input type="text" id="mce_notes" maxlength="200"></div>
      <div id="mce_visitor_name_wrap" style="display:none"><label>Visitor Name</label><input type="text" id="mce_visitor_name" maxlength="150"></div>
      <div id="mce_visitor_prof_wrap" style="display:none"><label>Profession</label><input type="text" id="mce_visitor_prof" maxlength="150"></div>
    </div>
    <div id="mcEditErr" style="display:none;padding:7px 10px;background:#fff0f2;border-radius:7px;font-size:.8rem;color:var(--rdk);margin-bottom:8px"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
      <button onclick="mcEditClose()" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="mcEditSave()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:.82rem;font-weight:700;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<style>
.mc-tab { background:none;border:none;padding:7px 16px;font-size:.82rem;font-weight:600;color:var(--gry);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px }
.mc-tab-on { color:var(--red);border-bottom-color:var(--red) }
</style>


<script>
// ── Override dashboard's toggleMem (runs after dashboard script) ──────────────
function toggleMem(id) {
    var r = document.getElementById('mem_detail_' + id);
    if (!r) return;
    r.style.display = (r.style.display === 'table-row') ? 'none' : 'table-row';
}

// ── memFilter — owns all filtering, never conflicts with dashboard ────────────
window._memPill = null;

function memFilter(pill) {
    if (pill !== undefined) {
        if (window._memPill === pill) {
            window._memPill = null;
        } else {
            window._memPill = pill;
        }
        var p1 = document.getElementById('pill_thisweek');
        var p2 = document.getElementById('pill_neverpaid');
        if (p1) p1.style.background = (window._memPill === 'thisweek')  ? '#e8f5e9' : '#f5f5f5';
        if (p2) p2.style.background = (window._memPill === 'neverpaid') ? '#ffebee' : '#f5f5f5';
    }

    var q    = (document.getElementById('mem_srch') ? document.getElementById('mem_srch').value : '').toLowerCase();
    var rows = document.querySelectorAll('#mem_tbl tbody tr.mem-row');
    for (var i = 0; i < rows.length; i++) {
        var r    = rows[i];
        var textOk = !q || (r.dataset.name||'').indexOf(q) >= 0 || (r.dataset.cat||'').indexOf(q) >= 0;
        var pillOk = true;
        if (window._memPill === 'thisweek')  pillOk = (r.dataset.thisweek  === '0');
        if (window._memPill === 'neverpaid') pillOk = (r.dataset.neverpaid === '1');
        var show = textOk && pillOk;
        r.style.display = show ? '' : 'none';
        var det = document.getElementById('mem_detail_' + (r.dataset.id||''));
        if (det && !show) det.style.display = 'none';
    }
}

function filterMembers() { if (document.getElementById('mem_tbl')) memFilter(); }

function downloadAllReports() {
    var ids = [];
    var rows = document.querySelectorAll('#mem_tbl tbody tr.mem-row');
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        if (r.style.display !== 'none' && r.dataset.id) ids.push(r.dataset.id);
    }
    if (!ids.length) { alert('No members visible to download.'); return; }
    var filter = window._memPill || 'all';
    if (!confirm('Download ' + ids.length + ' separate PDFs as a ZIP file?')) return;
    window.location.href = 'member_zip_download.php?ids=' + ids.join(',') + '&filter=' + encodeURIComponent(filter);
}

var _mda = 'add', _mdi = null;

function memDlgOpen() {
    _mda = 'add'; _mdi = null;
    document.getElementById('memDlgH').textContent = '+ Add Member';
    ['mf_name','mf_co','mf_cat','mf_mob','mf_em'].forEach(function(id){ document.getElementById(id).value = ''; });
    document.getElementById('mf_swrap').style.display = 'none';
    document.getElementById('memDlgErr').style.display = 'none';
    document.getElementById('memDlgBtn').textContent = 'Add Member';
    document.getElementById('memDlgBg').style.display = 'flex';
    setTimeout(function(){ document.getElementById('mf_name').focus(); }, 80);
}

function memDlgClose() { document.getElementById('memDlgBg').style.display = 'none'; }

function memDlgSave() {
    var name = document.getElementById('mf_name').value.trim();
    if (!name) { memDlgShowErr('Full name is required.'); return; }
    var btn = document.getElementById('memDlgBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('action',       _mda);
    fd.append('name',         name);
    fd.append('company_name', document.getElementById('mf_co').value.trim());
    fd.append('category',     document.getElementById('mf_cat').value.trim());
    fd.append('mobile',       document.getElementById('mf_mob').value.trim());
    fd.append('email',        document.getElementById('mf_em').value.trim());
    if (_mdi) { fd.append('id', _mdi); fd.append('status', document.getElementById('mf_stat').value); }
    fd.append('_csrf', _csrf); fetch('member_action.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            btn.textContent = _mda === 'add' ? 'Add Member' : 'Save Changes';
            if (!d.ok) { memDlgShowErr(d.msg || 'Error'); return; }
            memDlgClose(); location.reload();
        })
        .catch(function(e){
            btn.disabled = false;
            btn.textContent = _mda === 'add' ? 'Add Member' : 'Save Changes';
            memDlgShowErr('Request failed: ' + e.message);
        });
}

function memDlgShowErr(msg) {
    var el = document.getElementById('memDlgErr');
    el.textContent = msg; el.style.display = 'block';
}

function memDlgDel(id, name) {
    if (!confirm('Deactivate "' + name + '"?\n\nAll payment history is preserved.')) return;
    var fd = new FormData();
    fd.append('action', 'delete'); fd.append('id', id);
    fd.append('_csrf', _csrf); fetch('member_action.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                var rows = document.querySelectorAll('#mem_tbl tbody tr.mem-row');
                for (var i = 0; i < rows.length; i++) {
                    if ((rows[i].getAttribute('onclick')||'').indexOf('toggleMem(' + id + ')') >= 0) {
                        rows[i].style.opacity = '.4';
                        var det = document.getElementById('mem_detail_' + id);
                        (function(r, d){ setTimeout(function(){ r.remove(); if (d) d.remove(); }, 400); })(rows[i], det);
                        break;
                    }
                }
            } else { alert('Error: ' + (d.msg || 'Failed')); }
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { memDlgClose(); closeMemCard(); mcEditClose(); } });

var _mcId = null;
var _mceType = null;

function openMemCard(id) {
    _mcId = id;
    document.getElementById('memCardBg').style.display = 'flex';
    document.getElementById('mc_loading').style.display = 'block';
    document.getElementById('mc_payments').style.display = 'none';
    document.getElementById('mc_kitty').style.display = 'none';
    document.getElementById('mc_visitors').style.display = 'none';
    mcTab('payments');
    fetch('member_card.php?id=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) { alert('Failed to load member data'); return; }
            document.getElementById('mc_loading').style.display = 'none';
            document.getElementById('mc_payments').style.display = 'block';
            document.getElementById('mc_name').textContent = d.name;
            document.getElementById('mc_co').textContent   = [d.company, d.category].filter(Boolean).join(' · ');
            document.getElementById('mc_pdf').href = 'member_report.php?id=' + id;
            
            var infoHtml = `
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-bottom:10px">
              <div><label style="font-size:.7rem;display:block">Name</label><input type="text" id="edit_name" value="${escapeHtml(d.name)}" style="width:100%;border:1px solid #ccc;border-radius:5px;padding:4px"></div>
              <div><label style="font-size:.7rem;display:block">Company</label><input type="text" id="edit_company" value="${escapeHtml(d.company||'')}" style="width:100%;border:1px solid #ccc;border-radius:5px;padding:4px"></div>
              <div><label style="font-size:.7rem;display:block">Category</label><input type="text" id="edit_category" value="${escapeHtml(d.category||'')}" style="width:100%;border:1px solid #ccc;border-radius:5px;padding:4px"></div>
              <div><label style="font-size:.7rem;display:block">Mobile</label><input type="text" id="edit_mobile" value="${escapeHtml(d.mobile||'')}" style="width:100%;border:1px solid #ccc;border-radius:5px;padding:4px"></div>
              <div><label style="font-size:.7rem;display:block">Email</label><input type="email" id="edit_email" value="${escapeHtml(d.email||'')}" style="width:100%;border:1px solid #ccc;border-radius:5px;padding:4px"></div>
              <div><label style="font-size:.7rem;display:block">Status</label>
                <select id="edit_status" style="width:100%;border:1px solid #ccc;border-radius:5px;padding:4px">
                  <option value="Active" ${d.status==='Active'?'selected':''}>Active</option>
                  <option value="Inactive" ${d.status==='Inactive'?'selected':''}>Inactive</option>
                </select>
              </div>
            </div>
            <div style="text-align:right"><button onclick="saveMemberInfo(${d.id})" class="btn-edi" style="padding:4px 12px">Save Info</button></div>
            `;
            var _mcInfo = document.getElementById('mc_info');
            if (_mcInfo) { _mcInfo.innerHTML = infoHtml; }

            renderMcPayments(d.payment_sessions || []);
            renderMcKitty(d.kitty || []);
            renderMcVisitors(d.visitors || []);
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

function closeMemCard() { document.getElementById('memCardBg').style.display = 'none'; }

function mcTab(tab) {
    ['payments','kitty','visitors'].forEach(function(t) {
        document.getElementById('mc_' + t).style.display = (t === tab) ? 'block' : 'none';
        var btn = document.getElementById('mct_' + t);
        if (btn) { btn.className = 'mc-tab' + (t === tab ? ' mc-tab-on' : ''); }
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function saveMemberInfo(id) {
    var data = {
        action: 'edit',
        id: id,
        name: document.getElementById('edit_name').value,
        company_name: document.getElementById('edit_company').value,
        category: document.getElementById('edit_category').value,
        mobile: document.getElementById('edit_mobile').value,
        email: document.getElementById('edit_email').value,
        status: document.getElementById('edit_status').value
    };
    var formData = new FormData();
    for (var k in data) formData.append(k, data[k]);
    formData.append('_csrf', _csrf); fetch('member_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                alert('Member info updated');
                openMemCard(id);
            } else {
                alert('Error: ' + (res.msg || 'Unknown'));
            }
        })
        .catch(e => alert('Request failed: ' + e.message));
}

// ── RENDER PAYMENT SESSIONS (multi-week, using payment_sessions) ────────────────
function renderMcPayments(sessions) {
    var html = '<thead><tr><th>Paid Date</th><th>Weeks</th><th>Sundays Covered</th><th>Mode</th><th>Status</th><th style="text-align:right">Total</th><th></th></tr></thead><tbody>';
    var totalPaid = 0; var totalWeeks = 0;
    sessions.forEach(function(s) {
        if (s.status === 'Paid') { totalPaid += s.total_amount; totalWeeks += s.week_count; }
        var sc = s.status==='Paid'?'#1b5e20':s.status==='Pending'?'#c47800':'#c62828';
        html += '<tr>' +
            '<td style="white-space:nowrap">' + s.paid_date + '</td>' +
            '<td style="text-align:center;font-weight:700">' + s.week_count + '</td>' +
            '<td style="font-size:.75rem;color:#666">' + (s.Sundays || []).map(function(d){ return d.slice(5); }).join(', ') + '</td>' +
            '<td>' + (s.payment_method||'—') + '</td>' +
            '<td style="color:' + sc + ';font-weight:700">' + s.status + '</td>' +
            '<td style="text-align:right;font-weight:700;color:var(--red)">₹' + s.total_amount.toLocaleString('en-IN') + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button onclick="mcEditRowBtn(this)" data-t="member_session" data-r=\'' + JSON.stringify(s) + '\' class="btn-edi" style="font-size:.7rem;padding:2px 5px">&#9998;</button>' +
              '<button onclick="mcDeleteSession(\'' + s.session_id + '\')" class="btn-del" style="font-size:.7rem;padding:2px 5px;margin-left:2px">✕</button>' +
            '</td>' +
        '</tr>';
    });
    if (sessions.length) {
        html += '<tr style="background:#fff0f2;font-weight:700">' +
            '<td colspan="5" style="text-align:right">Total weeks paid: <strong>' + totalWeeks + '</strong></td>' +
            '<td style="text-align:right;color:var(--red)">₹' + totalPaid.toLocaleString('en-IN') + '</td>' +
            '<td></td>' +
        '</tr>';
    }
    document.getElementById('mc_pay_tbl').innerHTML = html;
    if (!sessions.length) {
        document.getElementById('mc_pay_tbl').innerHTML = '<tbody><tr><td colspan="7" style="text-align:center;color:#aaa;padding:10px">No payments</td></tr></tbody>';
    }
}

function renderMcKitty(rows) {
    var html = '';
    rows.forEach(function(r) {
        var sc = r.status==='Paid'?'#1b5e20':r.status==='Pending'?'#c47800':'#c62828';
        html += '<tr>' +
            '<td>' + r.submitted_at + '</td>' +
            '<td>' + (r.payment_method||'—') + '</td>' +
            '<td style="color:' + sc + ';font-weight:700">' + r.status + '</td>' +
            '<td style="text-align:right;font-weight:700">₹' + Number(r.amount).toLocaleString('en-IN') + '</td>' +
            '<td style="font-size:.72rem;color:#888">' + (r.notes||'') + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button onclick="mcEditRowBtn(this)" data-r=\'' + JSON.stringify(r) + '\' data-t="kitty" class="btn-edi" style="font-size:.7rem;padding:2px 5px">&#9998;</button>' +
              '<button onclick="mcDeleteRow(' + r.id + ',\'kitty\')" class="btn-del" style="font-size:.7rem;padding:2px 5px;margin-left:2px">✕</button>' +
            '</td>' +
        '</tr>';
    });
    document.getElementById('mc_kit_body').innerHTML = html || '<tr><td colspan="6" style="text-align:center;color:#aaa;padding:10px">No kitty payments</td></tr>';
}

function renderMcVisitors(rows) {
    var html = '';
    rows.forEach(function(r) {
        var ds  = r.display_status || r.txn_status || r.status || '—';
        var due = ds === 'Due from Member';
        var sc  = ds === 'Paid' ? '#1b5e20' : (due ? '#c62828' : '#c47800');
        html += '<tr>' +
            '<td><strong>' + escapeHtml(r.visitor_name) + '</strong></td>' +
            '<td style="font-size:.75rem;color:#666">' + escapeHtml(r.visitor_profession||'—') + '</td>' +
            '<td>' + r.friday_date + '</td>' +
            '<td>' + (r.payment_method||'—') + '</td>' +
            '<td style="color:' + sc + ';font-weight:700">' + ds + '</td>' +
            '<td style="text-align:right;font-weight:700;color:' + (due?'#c62828':'inherit') + '">₹' + Number(r.amount).toLocaleString('en-IN') + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button onclick="editVisitorRow(' + JSON.stringify(r).replace(/"/g, '&quot;') + ')" class="btn-edi" style="font-size:.7rem;padding:2px 5px">&#9998;</button>' +
              '<button onclick="deleteVisitorTxn(' + (r.id||0) + ')" class="btn-del" style="font-size:.7rem;padding:2px 5px;margin-left:2px">&#10005;</button>' +
            '</tr>';
    });
    document.getElementById('mc_vis_body').innerHTML = html || '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:10px">No visitors referred</td></tr>';
}

function editVisitorRow(visitor) {
    _mceType = 'visitor';
    document.getElementById('mce_id').value = visitor.id || '';
    document.getElementById('mce_amount').value = visitor.amount || 1450;
    document.getElementById('mce_mode').value = visitor.payment_method || 'Cash';
    document.getElementById('mce_status').value = visitor.txn_status || 'Pending';
    document.getElementById('mce_visitor_name').value = visitor.visitor_name || '';
    document.getElementById('mce_visitor_prof').value = visitor.visitor_profession || '';
    document.getElementById('mce_friday_date').value = visitor.friday_date || '';
    document.getElementById('mce_date').value = visitor.friday_date || '';
    document.getElementById('mce_paid_date').value = visitor.submitted_at ? visitor.submitted_at.slice(0,10) : new Date().toISOString().slice(0,10);
    document.getElementById('mcEditErr').style.display = 'none';
    document.getElementById('mcEditH').textContent = 'Edit Visitor';
    document.getElementById('mce_date_wrap').style.display = 'block';
    var startWrap = document.getElementById('mce_start_wrap');
    if (startWrap) startWrap.style.display = 'none';
    document.getElementById('mce_amount_wrap').style.display = 'none';
    document.getElementById('mce_paid_wrap').style.display = 'block';
    document.getElementById('mce_notes_wrap').style.display = 'none';
    document.getElementById('mce_visitor_name_wrap').style.display = 'block';
    document.getElementById('mce_visitor_prof_wrap').style.display = 'block';
    document.getElementById('mcEditBg').style.display = 'flex';
}

function deleteVisitorTxn(txnId) {
    if (!confirm('Delete this visitor record permanently? This will also remove any due records.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_visitor_txn');
    fd.append('id', txnId);
    fd.append('_csrf', _csrf); fetch('member_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                openMemCard(_mcId);
            } else {
                alert('Error: ' + (res.msg || 'Failed'));
            }
        });
}

var _dashSel = '<?=htmlspecialchars($sel)?>';  // MUST be at the top of the script

function mcAddPayment() {
    console.log('Add Payment button clicked');
    _mceType = 'member_session';
    
    var el;
    if ((el = document.getElementById('mce_id'))) el.value = '';
    if ((el = document.getElementById('mce_session_id'))) el.value = '';
    if ((el = document.getElementById('mce_paid_date'))) el.value = new Date().toISOString().slice(0,10);
    if ((el = document.getElementById('mce_total_amount'))) el.value = 1450;
    if ((el = document.getElementById('mce_mode'))) el.value = 'Cash';
    if ((el = document.getElementById('mce_status'))) el.value = 'Pending';
    if ((el = document.getElementById('mcEditH'))) el.textContent = 'Add Payment Session';
    
    // Hide/show wrappers (only if they exist)
    if ((el = document.getElementById('mce_date_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_start_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_amount_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_paid_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_notes_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_visitor_name_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_visitor_prof_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mcEditErr'))) el.style.display = 'none';
    
    var dialog = document.getElementById('mcEditBg');
    if (dialog) dialog.style.display = 'flex';
}

function mcAddKitty() {
    console.log('Add Kitty button clicked');
    _mceType = 'kitty';
    
    var el;
    if ((el = document.getElementById('mce_id'))) el.value = '';
    if ((el = document.getElementById('mce_amount'))) el.value = 3000;
    if ((el = document.getElementById('mce_mode'))) el.value = 'Cash';
    if ((el = document.getElementById('mce_status'))) el.value = 'Pending';
    if ((el = document.getElementById('mce_paid_date'))) el.value = new Date().toISOString().slice(0,10);
    if ((el = document.getElementById('mce_notes'))) el.value = '';
    if ((el = document.getElementById('mcEditH'))) el.textContent = 'Add Kitty Payment';
    
    if ((el = document.getElementById('mce_date_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_start_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_amount_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_paid_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_notes_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_visitor_name_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_visitor_prof_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mcEditErr'))) el.style.display = 'none';
    
    var dialog = document.getElementById('mcEditBg');
    if (dialog) dialog.style.display = 'flex';
}

function mcAddVisitor() {
    console.log('Add Visitor button clicked');
    _mceType = 'visitor';
    
    var el;
    if ((el = document.getElementById('mce_id'))) el.value = '';
    if ((el = document.getElementById('mce_visitor_name'))) el.value = '';
    if ((el = document.getElementById('mce_visitor_prof'))) el.value = '';
    if ((el = document.getElementById('mce_amount'))) el.value = 1450;
    if ((el = document.getElementById('mce_mode'))) el.value = 'Cash';
    if ((el = document.getElementById('mce_status'))) el.value = 'Pending';
    if ((el = document.getElementById('mce_friday_date'))) el.value = _dashSel;
    if ((el = document.getElementById('mce_paid_date'))) el.value = new Date().toISOString().slice(0,10);
    if ((el = document.getElementById('mcEditH'))) el.textContent = 'Add Visitor';
    
    if ((el = document.getElementById('mce_date_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_start_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_amount_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_paid_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_notes_wrap'))) el.style.display = 'none';
    if ((el = document.getElementById('mce_visitor_name_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mce_visitor_prof_wrap'))) el.style.display = 'block';
    if ((el = document.getElementById('mcEditErr'))) el.style.display = 'none';
    
    var dialog = document.getElementById('mcEditBg');
    if (dialog) dialog.style.display = 'flex';
}

function mcEditRowBtn(el) {
    var r = JSON.parse(el.getAttribute('data-r') || '{}');
    var type = el.getAttribute('data-t') || 'txn';
    if (type === 'member_session') {
        _mceType = 'member_session';
        document.getElementById('mce_session_id').value = r.session_id || '';
        document.getElementById('mce_paid_date').value = r.paid_date || '';
        document.getElementById('mce_total_amount').value = r.total_amount || 0;
        document.getElementById('mce_mode').value = r.payment_method || 'Cash';
        document.getElementById('mce_status').value = r.status || 'Pending';
        document.getElementById('mcEditH').textContent = 'Edit Payment Session';
        document.getElementById('mce_date_wrap').style.display = 'none';
        var startWrap = document.getElementById('mce_start_wrap');
        if (startWrap) startWrap.style.display = 'none';
        document.getElementById('mce_amount_wrap').style.display = 'block';
        document.getElementById('mce_paid_wrap').style.display = 'block';
        document.getElementById('mce_notes_wrap').style.display = 'none';
        document.getElementById('mce_visitor_name_wrap').style.display = 'none';
        document.getElementById('mce_visitor_prof_wrap').style.display = 'none';
        document.getElementById('mcEditErr').style.display = 'none';
        document.getElementById('mcEditBg').style.display = 'flex';
    } else if (type === 'kitty') {
        _mceType = 'kitty';
        document.getElementById('mce_id').value = r.id || '';
        document.getElementById('mce_amount').value = r.amount || '';
        document.getElementById('mce_mode').value = r.payment_method || 'Cash';
        document.getElementById('mce_status').value = r.status || 'Pending';
        document.getElementById('mce_paid_date').value = r.submitted_at ? r.submitted_at.slice(0,10) : new Date().toISOString().slice(0,10);
        document.getElementById('mce_notes').value = r.notes || '';
        document.getElementById('mcEditH').textContent = 'Edit Kitty Payment';
        document.getElementById('mce_date_wrap').style.display = 'none';
        var startWrap = document.getElementById('mce_start_wrap');
        if (startWrap) startWrap.style.display = 'none';
        document.getElementById('mce_amount_wrap').style.display = 'none';
        document.getElementById('mce_paid_wrap').style.display = 'block';
        document.getElementById('mce_notes_wrap').style.display = 'block';
        document.getElementById('mce_visitor_name_wrap').style.display = 'none';
        document.getElementById('mce_visitor_prof_wrap').style.display = 'none';
        document.getElementById('mcEditErr').style.display = 'none';
        document.getElementById('mcEditBg').style.display = 'flex';
    } else if (type === 'visitor') {
        editVisitorRow(r);
    }
}

function mcEditClose() {
    document.getElementById('mcEditBg').style.display = 'none';
}

function mcEditSave() {
    var fd = new FormData();
    if (_mceType === 'member_session') {
        var totalAmt = parseInt(document.getElementById('mce_total_amount').value);
        if (isNaN(totalAmt) || totalAmt <= 0) {
            document.getElementById('mcEditErr').textContent = 'Please enter a valid total amount (greater than 0)';
            document.getElementById('mcEditErr').style.display = 'block';
            return;
        }
        fd.append('action', 'edit_member_session');
        fd.append('member_id', _mcId);
        fd.append('session_id', document.getElementById('mce_session_id').value);
        fd.append('paid_date', document.getElementById('mce_paid_date').value);
        fd.append('total_amount', totalAmt);
        fd.append('mode', document.getElementById('mce_mode').value);
        fd.append('status', document.getElementById('mce_status').value);
    } else if (_mceType === 'visitor') {
        fd.append('action', 'edit_visitor_txn');
        fd.append('id', document.getElementById('mce_id').value);
        fd.append('member_id', _mcId);
        fd.append('visitor_name', document.getElementById('mce_visitor_name').value);
        fd.append('visitor_profession', document.getElementById('mce_visitor_prof').value);
        fd.append('friday_date', document.getElementById('mce_date').value || document.getElementById('mce_friday_date').value);
        fd.append('amount', document.getElementById('mce_amount').value);
        fd.append('mode', document.getElementById('mce_mode').value);
        fd.append('status', document.getElementById('mce_status').value);
        fd.append('paid_date', document.getElementById('mce_paid_date').value);
    } else if (_mceType === 'kitty') {
        fd.append('action', 'edit_kitty');
        fd.append('id', document.getElementById('mce_id').value);
        fd.append('member_id', _mcId);
        fd.append('amount', document.getElementById('mce_amount').value);
        fd.append('mode', document.getElementById('mce_mode').value);
        fd.append('status', document.getElementById('mce_status').value);
        fd.append('notes', document.getElementById('mce_notes').value);
        fd.append('paid_date', document.getElementById('mce_paid_date').value);
    }

    fd.append('_csrf', _csrf); fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                document.getElementById('mcEditErr').textContent = d.msg || 'Error';
                document.getElementById('mcEditErr').style.display = 'block';
                return;
            }
            mcEditClose();
            openMemCard(_mcId);
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

function mcDeleteSession(sessionId) {
    if (!confirm('Delete this entire payment session? This will remove all weekly entries.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_member_session');
    fd.append('member_id', _mcId);
    fd.append('session_id', sessionId);
    fd.append('_csrf', _csrf); fetch('member_action.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) openMemCard(_mcId);
            else alert('Error: ' + (d.msg||'Failed'));
        });
}

function mcDeleteRow(id, type) {
    if (!confirm('Delete this record permanently?')) return;
    var fd = new FormData();
    fd.append('action', type === 'txn' ? 'delete_txn' : 'delete_kitty');
    fd.append('id', id);
    fd.append('_csrf', _csrf); fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) openMemCard(_mcId);
            else alert('Error: ' + (d.msg||'Failed'));
        });
}

function editMemberSession(btn) {
    var memberId = btn.getAttribute('data-member');
    var sessionId = btn.getAttribute('data-session');
    var currentTotal = btn.getAttribute('data-total');
    var currentPaidDate = btn.getAttribute('data-paiddate');
    var currentMode = btn.getAttribute('data-mode');
    var currentStatus = btn.getAttribute('data-status');

    var newTotal = prompt('Edit total amount (₹):', currentTotal);
    if (newTotal === null) return;
    newTotal = parseInt(newTotal);
    if (isNaN(newTotal) || newTotal <= 0) {
        alert('Invalid amount.');
        return;
    }
    var newPaidDate = prompt('Edit paid date (YYYY-MM-DD):', currentPaidDate);
    if (newPaidDate === null) return;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(newPaidDate)) {
        alert('Invalid date format. Use YYYY-MM-DD.');
        return;
    }
    var newMode = prompt('Edit payment mode (Cash/UPI/Card/FinCloud):', currentMode);
    if (newMode === null) return;
    var newStatus = prompt('Edit status (Paid/Pending/Rejected):', currentStatus);
    if (newStatus === null) return;
    if (!['Paid','Pending','Rejected'].includes(newStatus)) {
        alert('Invalid status.');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'edit_member_session');
    fd.append('member_id', memberId);
    fd.append('session_id', sessionId);
    fd.append('paid_date', newPaidDate);
    fd.append('total_amount', newTotal);
    fd.append('mode', newMode);
    fd.append('status', newStatus);

    fd.append('_csrf', _csrf); fetch('member_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                location.reload();
            } else {
                alert('Error: ' + (d.msg || 'Failed'));
            }
        })
        .catch(e => alert('Request failed: ' + e.message));
}

</script>
