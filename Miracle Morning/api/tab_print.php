<?php
require_once __DIR__ . '/name_match_helper.php';
// ═══════════════════════════════════════════════════════════════
// tab_print.php — Weekly Report  (80mm thermal-printer ready)
// Sections: Summary | Payment Modes | Members Paid | Visitors |
//           Observers | Members Unpaid | Visitor Dues Pending
// ═══════════════════════════════════════════════════════════════

// ── Snap $sel to the correct reporting Sunday ─────────────────────────────────
// Rule: payments from (prev Sunday + 1 day) to (this Sunday) belong to this Sunday.
// So Apr 4 (Mon) → next sunday = Apr 10.  Apr 7 (Tue) → next sunday = Apr 10.
// If $sel is already a Sunday, keep it as-is.
$_selDt = new DateTime($sel, new DateTimeZone('Asia/Kolkata'));
if ((int)$_selDt->format('N') !== 7) {
    $_selDt->modify('next sunday');
    $sel = $_selDt->format('Y-m-d');
}
// $sel is now always a Sunday — safe to use in all queries below.

// ── 1. Members paid this Sunday ──────────────────────────────────────────────
// Members paid this Sunday — matches Live Desk exactly
// Week-window filter excludes advance-paid future-week rows
// Week window: $sel is Sunday (last day of week). Week runs Mon-Sun.
// Payments submitted Mon($sel-6) through Sun($sel) belong to this week.
$_weekStart = date('Y-m-d', strtotime($sel . ' -6 days')); // Previous Monday
$_weekEnd   = $sel;                                          // This Sunday

$_mpQ = $pdo->prepare("
    SELECT  m.id, m.name, m.category,
            MAX(t.payment_method) AS payment_method,
            COALESCE(
                (SELECT MAX(t2.original_total)
                 FROM   transactions t2
                 WHERE  t2.member_id = m.id
                   AND  t2.type = 'Member'
                   AND  t2.status = 'Paid'
                   AND  DATE_FORMAT(t2.submitted_at,'%Y-%m-%d %H:%i:%s') = DATE_FORMAT(t.submitted_at,'%Y-%m-%d %H:%i:%s')
                   AND  t2.original_total IS NOT NULL
                ),
                (SELECT SUM(t2.amount)
                 FROM   transactions t2
                 WHERE  t2.member_id = m.id
                   AND  t2.type = 'Member'
                   AND  t2.status = 'Paid'
                   AND  DATE_FORMAT(t2.submitted_at,'%Y-%m-%d %H:%i:%s') = DATE_FORMAT(t.submitted_at,'%Y-%m-%d %H:%i:%s')
                )
            ) AS paid_amt,
            (SELECT COUNT(*)
             FROM   transactions t2
             WHERE  t2.member_id = m.id
               AND  t2.type = 'Member'
               AND  t2.status = 'Paid'
               AND  DATE_FORMAT(t2.submitted_at,'%Y-%m-%d %H:%i:%s') = DATE_FORMAT(t.submitted_at,'%Y-%m-%d %H:%i:%s')
            ) AS week_cnt
    FROM    transactions t
    JOIN    members m ON t.member_id = m.id
    WHERE   t.type = 'Member'
      AND   t.status = 'Paid'
      AND   DATE(t.submitted_at) BETWEEN ? AND ?
    GROUP BY m.id, m.name, m.category, DATE_FORMAT(t.submitted_at,'%Y-%m-%d %H:%i:%s')
    ORDER BY m.name ASC
");
$_mpQ->execute([$_weekStart, $_weekEnd]);
$paidMembersList = $_mpQ->fetchAll(PDO::FETCH_ASSOC);
$paidMidSet      = array_flip(array_column($paidMembersList, 'id'));

// ── 2. Members NOT paid this Sunday ─────────────────────────────────────────
$allMemP  = $pdo->query("SELECT id,name,category FROM members WHERE status='Active' ORDER BY name ASC")->fetchAll();
$unpaidP  = array_values(array_filter($allMemP, fn($m) => !isset($paidMidSet[$m['id']])));

// ── 3. Visitors paid — with "paid by member" attribution ────────────────────
// Uses both friday_date match AND submitted_at window to catch all visitors
try {
    $refExpr = hm_member_name_sql_expr('t.referrer_name');
    $memExpr = hm_member_name_sql_expr('mref.name');
    $_vQ = $pdo->prepare("
        SELECT  t.id, t.member_id, t.visitor_name, t.visitor_profession, t.visitor_company,
                t.referrer_name, COALESCE(vd.payment_method, t.payment_method) AS payment_method, t.amount, t.status,
                t.friday_date, DATE(t.submitted_at) AS paid_date,
                m2.name AS paid_by_member,
                COALESCE(m2.id, mt.id, mref.id) AS report_member_id,
                COALESCE(m2.name, mt.name, mref.name, t.referrer_name, 'Unmatched') AS report_member_name,
                vd.status AS due_status
        FROM    transactions t
        LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
        LEFT JOIN members m2      ON m2.id = vd.member_id
        LEFT JOIN members mt      ON mt.id = t.member_id
        LEFT JOIN members mref    ON mref.status='Active' AND $refExpr = $memExpr
        WHERE   t.type='Visitor' AND t.status='Paid'
          AND   t.friday_date=?
        ORDER BY report_member_name ASC, t.visitor_name ASC
    ");
    $_vQ->execute([$sel]);
    $visitorsList = $_vQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_vQ = $pdo->prepare("
        SELECT id, member_id, visitor_name, visitor_profession, visitor_company,
               referrer_name, payment_method, amount, status,
               friday_date, DATE(submitted_at) AS paid_date,
               NULL AS paid_by_member, member_id AS report_member_id,
               COALESCE(referrer_name, 'Unmatched') AS report_member_name
        FROM   transactions
        WHERE  type='Visitor' AND status='Paid'
          AND  friday_date=?
        ORDER  BY report_member_name ASC, visitor_name ASC
    ");
    $_vQ->execute([$sel]);
    $visitorsList = $_vQ->fetchAll(PDO::FETCH_ASSOC);
}

// ── 4. Observers paid ────────────────────────────────────────────────────────
$visitorsByMember = [];
foreach ($visitorsList as $v) {
    $memberKey = trim($v['report_member_name'] ?? '') ?: 'Unmatched';
    if (!isset($visitorsByMember[$memberKey])) $visitorsByMember[$memberKey] = [];
    $visitorsByMember[$memberKey][] = $v;
}

$_oQ = $pdo->prepare("
    SELECT visitor_name, observer_chapter, observer_category, payment_method, amount
    FROM   transactions
    WHERE  type='Observer' AND status='Paid'
      AND  (friday_date=? OR DATE(submitted_at) BETWEEN ? AND ?)
    ORDER  BY visitor_name ASC
");
$_oQ->execute([$sel, $_weekStart, $_weekEnd]);
$observersList = $_oQ->fetchAll(PDO::FETCH_ASSOC);

// ── 4b. Kitty payments this week — Mon-Sun window via submitted_at ───────────
// Week runs Mon-Sun. $sel is Sunday. Window = ($sel - 6 days Mon) to $sel (Sun).
$kittyList = [];
try {
    $kq = $pdo->prepare("
        SELECT m.name, m.category, k.id, k.amount, k.payment_method,
               k.submitted_at, DATE(k.submitted_at) AS sub_date
        FROM kitty_payments k
        JOIN members m ON k.member_id = m.id
        WHERE k.status = 'Paid'
          AND DATE(k.submitted_at) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
        ORDER BY m.name ASC
    ");
    $kq->execute([$sel, $sel]);
    $kittyList = $kq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $kittyList = []; }
$kittyTot = array_sum(array_column($kittyList, 'amount'));

$collectedDues = [];
try {
    $cdRefExpr = hm_member_name_sql_expr('t.referrer_name');
    $cdMemExpr = hm_member_name_sql_expr('mref.name');
    $_cdQ = $pdo->prepare("
        SELECT vd.id, vd.txn_id, 'due' AS source_type, COALESCE(vd.visitor_name, t.visitor_name) AS visitor_name, vd.amount, vd.payment_method, DATE(vd.paid_at) AS paid_date,
               COALESCE(vd.member_id, t.member_id) AS member_id, COALESCE(m.name, mt.name, t.referrer_name, 'Unmatched') AS member_name, t.friday_date, t.visitor_profession, t.status
        FROM visitor_dues vd
        JOIN transactions t ON t.id = vd.txn_id
        LEFT JOIN members m ON m.id = vd.member_id
        LEFT JOIN members mt ON mt.id = t.member_id
        WHERE vd.status = 'Paid'
          AND vd.paid_at IS NOT NULL
          AND DATE(vd.paid_at) BETWEEN ? AND ?
          AND t.friday_date < ?
        ORDER BY member_name ASC, friday_date ASC, visitor_name ASC
    ");
    $_cdQ->execute([$_weekStart, $_weekEnd, $_weekStart]);
    $collectedDues = $_cdQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $collectedDues = []; }
$collectedDuesTotal = array_sum(array_column($collectedDues, 'amount'));

// ── 5. Visitor dues pending — only for visitors who came THIS Sunday ──────────
// Logic: show dues where the visitor's transaction friday_date = $sel
// This way Apr 3 report shows Apr 3 visitors' dues, Apr 10 shows Apr 10 visitors' dues
try {
    $_pdQ = $pdo->prepare("
        SELECT vd.visitor_name, vd.amount, m.name AS member_name, t.friday_date
        FROM   visitor_dues vd
        JOIN   members m ON m.id = vd.member_id
        JOIN   transactions t ON t.id = vd.txn_id
        WHERE  vd.status = 'Pending'
          AND  t.friday_date = ?
        ORDER  BY t.friday_date ASC, m.name ASC, vd.visitor_name ASC
    ");
    $_pdQ->execute([$sel]);
    $pendingDues = $_pdQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: no date filter
    try {
        $_pdQ = $pdo->query("
            SELECT vd.visitor_name, vd.amount, m.name AS member_name, t.friday_date
            FROM visitor_dues vd
            JOIN members m ON m.id = vd.member_id
            JOIN transactions t ON t.id = vd.txn_id
            WHERE vd.status='Pending'
              AND t.friday_date = '$sel'
            ORDER BY t.friday_date ASC, m.name ASC, vd.visitor_name ASC
        ");
        $pendingDues = $_pdQ ? $_pdQ->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e2) { $pendingDues = []; }
}

// ── 6. Payment mode breakdown (this Sunday, all types) ───────────────────────
// Mode breakdown: member amounts by submitted_at session; guests by friday_date
// Mode breakdown — members use session totals (same as Live Desk / grand total)
// Each member counted once with their full session payment, grouped by their mode
$modeBreakdown = [];
foreach ($paidMembersList as $m) {
    $pm = $m['payment_method'];
    if (!isset($modeBreakdown[$pm])) $modeBreakdown[$pm] = ['payment_method'=>$pm,'total'=>0,'cnt'=>0];
    $modeBreakdown[$pm]['total'] += (float)$m['paid_amt'];
    $modeBreakdown[$pm]['cnt']   += 1;
}
// Visitors + Observers
$_gmQ = $pdo->prepare("
    SELECT payment_method, SUM(amount) AS total, COUNT(*) AS cnt
    FROM   transactions
    WHERE  status='Paid'
      AND  (
             (type='Observer' AND (friday_date=? OR DATE(submitted_at) BETWEEN ? AND ?))
             OR (type='Visitor' AND friday_date=? AND COALESCE(payment_method,'') <> 'Pending-Member')
           )
    GROUP  BY payment_method
");
$_gmQ->execute([$sel, $_weekStart, $_weekEnd, $sel]);
foreach ($_gmQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pm = $row['payment_method'];
    if (!isset($modeBreakdown[$pm])) $modeBreakdown[$pm] = ['payment_method'=>$pm,'total'=>0,'cnt'=>0];
    $modeBreakdown[$pm]['total'] += (float)$row['total'];
    $modeBreakdown[$pm]['cnt']   += (int)$row['cnt'];
}
// Kitty payments within this week window (already filtered in $kittyList)
foreach ($kittyList as $k) {
    $pm = $k['payment_method'];
    if (!isset($modeBreakdown[$pm])) $modeBreakdown[$pm] = ['payment_method'=>$pm,'total'=>0,'cnt'=>0];
    $modeBreakdown[$pm]['total'] += (float)$k['amount'];
    $modeBreakdown[$pm]['cnt']   += 1;
}
foreach ($collectedDues as $d) {
    $pm = $d['payment_method'] ?: 'Cash';
    if (!isset($modeBreakdown[$pm])) $modeBreakdown[$pm] = ['payment_method'=>$pm,'total'=>0,'cnt'=>0];
    $modeBreakdown[$pm]['total'] += (float)$d['amount'];
    $modeBreakdown[$pm]['cnt']   += 1;
}
$modeBreakdown = array_values($modeBreakdown);
usort($modeBreakdown, function($a,$b){ return $b['total'] <=> $a['total']; });

// ── 7. Grand totals ──────────────────────────────────────────────────────────
// Member total from session-summed list (matches Live Desk)
$memTot = (float)array_sum(array_column($paidMembersList, 'paid_amt'));
// Derive visitor total from the already-fetched list — exclude pending dues (fee not yet collected)
$visTot = (float)array_sum(array_column($visitorsList, 'amount')); // ALL visitors incl. pending dues (gross)
$_tQ = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND type=? AND (friday_date=? OR DATE(submitted_at) BETWEEN ? AND ?)");
$_tQ->execute(['Observer', $sel, $_weekStart, $_weekEnd]); $obsTot = (float)$_tQ->fetchColumn();
$grandP    = $memTot + $visTot + $obsTot + $collectedDuesTotal;
// Visitor dues pending (only this Sunday's visitors)
$duesPend = 0;
try {
    $dq = $pdo->prepare("
        SELECT COALESCE(SUM(vd.amount),0)
        FROM visitor_dues vd
        JOIN transactions t ON t.id = vd.txn_id
        WHERE vd.status='Pending' AND t.friday_date = ?
    ");
    $dq->execute([$sel]);
    $duesPend = (float)$dq->fetchColumn();
} catch (Exception $e) {
    // Fallback: all pending
    try {
        $dq = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM visitor_dues WHERE status='Pending'");
        $duesPend = (float)$dq->fetchColumn();
    } catch (Exception $e2) { $duesPend = 0; }
}
$netTotal  = $memTot + $visTot + $obsTot + $kittyTot + $collectedDuesTotal - $duesPend;

// Helper — readable payment mode
function shortMode(string $m): string {
    return match(strtolower(trim($m))) {
        'fincloud'          => 'FinCloud',
        'cash'              => 'Cash',
        'card'              => 'Card',
        'upi','qr code (upi)' => 'UPI/QR',
        'pending-member'    => 'Via Member',
        default             => $m,
    };
}
?>

<div class="content">

<!-- ══ SCREEN TOP BAR ════════════════════════════════════════════════════════ -->
<div class="no-print d-flex align-items-center gap-3 mb-3 p-3"
     style="background:#fff;border-radius:10px;border:1px solid var(--bdr)">
  <div>
    <div style="font-size:.88rem;font-weight:700">Weekly Report &mdash; <?=date('d M Y',strtotime($sel))?></div>
    <div style="font-size:.75rem;color:var(--gry);margin-top:3px">
      🟢 Live DB &nbsp;&middot;&nbsp;
      <strong><?=count($paidMembersList)?></strong> members paid &nbsp;&middot;&nbsp;
      <strong><?=count($unpaidP)?></strong> unpaid &nbsp;&middot;&nbsp;
      <strong><?=count($visitorsList)?></strong> visitors &nbsp;&middot;&nbsp;
      <strong><?=count($observersList)?></strong> observers &nbsp;&middot;&nbsp;
      Total <strong>&#8377;<?=number_format($grandP)?></strong>
    </div>
  </div>
  <div class="d-flex gap-2 ms-auto">
    <a href="?date=<?=$sel?>&tab=print&export=weekly_excel" class="btn-exp outline">&#8595; Excel</a>
    <button onclick="setPrintMode('80mm');window.print()" class="btn-exp outline" style="border-color:#555;color:#555">&#128438; Print 80mm</button>
    <button onclick="setPrintMode('a4');window.print()"  class="btn-exp">&#128438; Print A4</button>
  </div>
</div>

<!-- ══ PRINT AREA ════════════════════════════════════════════════════════════ -->
<div id="print-area">
<style>
/* ─── Base ───────────────────────────────────────────────── */
#print-area{
  font-family:Arial,sans-serif;font-size:9.5pt;color:#000;
  background:#fff;width:100%;max-width:740px;margin:0 auto;
}

/* ─── Section header ─────────────────────────────────────── */
.pr-sec{
  font-size:8.5pt;font-weight:700;text-transform:uppercase;
  letter-spacing:.6px;padding:2px 7px;margin:10px 0 3px;
  background:#111;color:#fff;display:block;
}
.pr-sec-total{
  float:right;font-size:8pt;font-weight:400;
  background:rgba(255,255,255,.18);padding:0 5px;border-radius:3px;
}

/* ─── Tables ─────────────────────────────────────────────── */
.pr-tbl{width:100%;border-collapse:collapse;margin-bottom:2px}
.pr-tbl th{
  font-size:7.5pt;font-weight:700;text-transform:uppercase;
  background:#eee;border:1px solid #bbb;padding:2px 5px;
  text-align:center;white-space:nowrap;
}
.pr-tbl td{border:1px solid #ccc;padding:3px 5px;font-size:8.5pt;vertical-align:middle}
.pr-tbl .num{text-align:center;color:#777;width:18px;font-size:8pt}
.pr-tbl .amt{text-align:right;font-weight:700;white-space:nowrap}
.pr-tbl .mode-badge{
  display:inline-block;font-size:7pt;font-weight:700;
  background:#f0f0f0;border:1px solid #ccc;border-radius:3px;padding:0 4px;
}
.pr-tbl .via{font-size:7.5pt;color:#6a1b9a;font-weight:600;display:block;margin-top:1px}
.pr-empty{
  text-align:center;padding:6px;font-size:8.5pt;
  color:#777;font-style:italic;border:1px solid #ccc;
}

/* ─── Summary grid ───────────────────────────────────────── */
.pr-summary-grid{
  display:grid;grid-template-columns:1fr 1fr;
  border:1px solid #bbb;margin-bottom:5px;
}
.pr-summary-cell{
  padding:5px 10px;border-right:1px solid #ccc;border-bottom:1px solid #ccc;
}
.pr-summary-cell:nth-child(even){border-right:none}
.pr-summary-cell:nth-child(n+5){border-bottom:none}
.pr-summary-val{font-size:13pt;font-weight:800;color:#D90429}
.pr-summary-lbl{font-size:7.5pt;text-transform:uppercase;color:#555;letter-spacing:.4px}

/* ─── Mode table ─────────────────────────────────────────── */
.mode-tbl{width:100%;border-collapse:collapse;margin-bottom:3px}
.mode-tbl th{background:#eee;border:1px solid #bbb;padding:2px 6px;font-size:7.5pt;text-transform:uppercase;font-weight:700}
.mode-tbl td{border:1px solid #ccc;padding:3px 6px;font-size:8.5pt;vertical-align:middle}
.mode-tbl .mode-total{font-weight:700;background:#f8f8f8}

/* ─── Denom ──────────────────────────────────────────────── */
.denom-wrap{border:1px solid #bbb;padding:6px 10px;margin-bottom:5px}
.denom-row{display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid #eee;font-size:8.5pt}
.denom-row:last-child{border-bottom:none;font-weight:700;margin-top:3px}
.denom-box{display:inline-block;border-bottom:1.5px solid #333;min-width:55px;margin:0 4px;text-align:center}

/* ─── Dividers ───────────────────────────────────────────── */
.pr-divider{border:none;border-top:1px dashed #aaa;margin:8px 0}
.pr-divider-solid{border:none;border-top:1.5px solid #000;margin:5px 0}

/* ─── PRINT RULES ────────────────────────────────────────── */
/* ── SCREEN: default sizing ──────────────────────────────── */
body.print-80mm #print-area { width:74mm; font-size:8pt }
body.print-a4   #print-area { width:190mm; font-size:10pt }

/* ── PRINT: universal rules ──────────────────────────────── */
@media print {
  body * { visibility:hidden }
  #print-area, #print-area * { visibility:visible }
  #print-area {
    position:absolute;
    top:0; left:0;
    width:100%;
    margin:0; padding:0;
  }
  .no-print { display:none !important }
  .pr-tbl tr { page-break-inside:avoid }
  .pr-sec    { page-break-after:avoid }
}

/* ── 80mm PRINT ───────────────────────────────────────────── */
@media print {
  body.print-80mm #print-area { font-size:8pt !important; width:74mm !important }
}

/* ── A4 PRINT ─────────────────────────────────────────────── */
@media print {
  body.print-a4 #print-area  { font-size:10pt !important; width:180mm !important }
  body.print-a4 .pr-tbl td   { font-size:9pt; padding:4px 6px }
  body.print-a4 .pr-tbl th   { font-size:8pt; padding:4px 6px }
  body.print-a4 .mode-tbl td,
  body.print-a4 .mode-tbl th { font-size:9pt }
}

/* ── 80mm compact sizes (screen preview too) ──────────────── */
body.print-80mm {

  .pr-title{font-size:11pt}
  .pr-subtitle{font-size:7pt}
  .pr-sec{font-size:7pt;padding:1px 4px;margin:7px 0 2px}
  .pr-tbl th{font-size:6.5pt;padding:1px 3px}
  .pr-tbl td{font-size:7.5pt;padding:2px 3px}
  .pr-tbl .mode-badge{font-size:6pt;padding:0 2px}
  .pr-tbl .via{font-size:6.5pt}
  .pr-summary-val{font-size:10pt}
  .pr-summary-lbl{font-size:6.5pt}
  .pr-summary-cell{padding:3px 6px}
  .mode-tbl td,.mode-tbl th{font-size:7pt;padding:2px 3px}
  .denom-row{font-size:7.5pt}
  .denom-box{min-width:38px}
  .pr-divider,.pr-divider-solid{margin:4px 0}
  .pr-tbl tr{page-break-inside:avoid}
  .pr-sec{page-break-after:avoid}
}
</style>

<!-- HEADER -->
<div style="text-align:center;font-size:14pt;font-weight:800;letter-spacing:.6px;margin:0 0 2px">Miracle Morning</div>
<div style="text-align:center;font-size:8.5pt;color:#444;margin:0 0 6px">
  Coimbatore Chapter &nbsp;|&nbsp; Weekly Report &nbsp;|&nbsp;
  <strong><?=date('D, d M Y',strtotime($sel))?></strong>
</div>
<hr class="pr-divider-solid">

<!-- OVERVIEW TABLE -->
<table style="width:100%;border-collapse:collapse;margin-bottom:4px;border:1px solid #bbb">
  <thead>
    <tr style="background:#eee">
      <th style="padding:4px 8px;text-align:left;font-size:8pt;border:1px solid #bbb;text-transform:uppercase;letter-spacing:.4px">Overview</th>
      <th style="padding:4px 8px;text-align:center;font-size:8pt;border:1px solid #bbb;text-transform:uppercase;letter-spacing:.4px">Count</th>
      <th style="padding:4px 8px;text-align:right;font-size:8pt;border:1px solid #bbb;text-transform:uppercase;letter-spacing:.4px">Total Amount</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $FEE = 1450;
    $memSimple   = count($paidMembersList) * $FEE;
    $visSimple   = count($visitorsList)    * $FEE;
    $obsSimple   = count($observersList)   * $FEE;
    $kittyCnt    = count($kittyList);
    $grandSimple = $memSimple + $visSimple + $obsSimple + $kittyTot + $collectedDuesTotal;
    ?>
    <tr>
      <td style="padding:4px 8px;border:1px solid #ccc;font-weight:600">Total Members</td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:center;font-size:11pt;font-weight:800;color:#D90429"><?=count($paidMembersList)?><span style="font-size:7.5pt;font-weight:400;color:#888"> / <?=count($allMemP)?></span></td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:right">
        <strong style="font-size:10pt;color:#D90429">&#8377;<?=number_format($memTot)?></strong>
      </td>
    </tr>
    <tr>
      <td style="padding:4px 8px;border:1px solid #ccc;font-weight:600">Total Visitors</td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:center;font-size:11pt;font-weight:800;color:#1565c0"><?=count($visitorsList)?></td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:right">
        <span style="font-size:7.5pt;color:#888"><?=count($visitorsList)?> &times; &#8377;<?=number_format($FEE)?> = </span>
        <strong style="font-size:10pt;color:#1565c0">&#8377;<?=number_format($visTot)?></strong>
      </td>
    </tr>
    <tr>
      <td style="padding:4px 8px;border:1px solid #ccc;font-weight:600">Total Observers</td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:center;font-size:11pt;font-weight:800;color:#444"><?=count($observersList)?></td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:right">
        <span style="font-size:7.5pt;color:#888"><?=count($observersList)?> &times; &#8377;<?=number_format($FEE)?> = </span>
        <strong style="font-size:10pt;color:#444">&#8377;<?=number_format($obsSimple)?></strong>
      </td>
    </tr>
    <tr style="background:#fffde7">
      <td style="padding:4px 8px;border:1px solid #ccc;font-weight:600;color:#c47800">Kitty Cash</td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:center;font-size:11pt;font-weight:800;color:#c47800"><?=$kittyCnt?></td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:right">
        <strong style="font-size:10pt;color:#c47800">&#8377;<?=number_format($kittyTot)?></strong>
      </td>
    </tr>
    <?php if ($collectedDuesTotal > 0): ?>
    <tr style="background:#f1f8f1">
      <td style="padding:4px 8px;border:1px solid #ccc;font-weight:600;color:#1b5e20">Visitor Dues Collected This Week</td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:center;font-size:11pt;font-weight:800;color:#1b5e20"><?=count($collectedDues)?></td>
      <td style="padding:4px 8px;border:1px solid #ccc;text-align:right">
        <strong style="font-size:10pt;color:#1b5e20">&#8377;<?=number_format($collectedDuesTotal)?></strong>
      </td>
    </tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- GRAND TOTAL STRIP -->
<?php $grossTotal = $memTot + $visTot + $obsTot + $kittyTot + $collectedDuesTotal; ?>
<div style="background:#111;color:#fff;text-align:center;padding:6px 10px;font-weight:800;font-size:11pt;margin-bottom:6px">
  <?php if($duesPend > 0): ?>
  <div style="font-size:8pt;font-weight:400;opacity:.7;margin-bottom:3px">
    &#8377;<?=number_format($grossTotal)?> &minus; Dues &#8377;<?=number_format($duesPend)?> = <strong>&#8377;<?=number_format($netTotal)?></strong>
  </div>
  <?php endif; ?>
  GRAND TOTAL &#8377;<?=number_format($netTotal)?>
  <div style="font-size:7.5pt;font-weight:400;opacity:.75;margin-top:1px">
    Members &#8377;<?=number_format($memTot)?> &nbsp;|&nbsp;
    Visitors &#8377;<?=number_format($visTot)?> &nbsp;|&nbsp;
    Observers &#8377;<?=number_format($obsTot)?> &nbsp;|&nbsp;
    Kitty &#8377;<?=number_format($kittyTot)?>
    <?php if($collectedDuesTotal > 0): ?> &nbsp;|&nbsp; Dues Collected &#8377;<?=number_format($collectedDuesTotal)?><?php endif; ?>
    <?php if($duesPend > 0): ?> &nbsp;|&nbsp; <span style="color:#ffd0d0">Dues &minus;&#8377;<?=number_format($duesPend)?></span><?php endif; ?>
  </div>
</div>

<!-- PAYMENT MODE BREAKDOWN -->
<div class="pr-sec">Payment Mode Breakdown</div>
<table class="mode-tbl">
  <thead>
    <tr>
      <th style="text-align:left">Mode</th>
      <th style="text-align:center">Count</th>
      <th style="text-align:right">Amount</th>
    </tr>
  </thead>
  <tbody>
<?php if ($modeBreakdown): ?>
  <?php foreach ($modeBreakdown as $md): ?>
  <tr>
    <td><strong><?=htmlspecialchars(shortMode($md['payment_method']))?></strong></td>
    <td style="text-align:center"><?=(int)$md['cnt']?></td>
    <td style="text-align:right;font-weight:700">&#8377;<?=number_format($md['total'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="mode-total">
    <td><strong>TOTAL</strong></td>
    <td style="text-align:center"><strong><?=array_sum(array_column($modeBreakdown,'cnt'))?></strong></td>
    <td style="text-align:right;color:#D90429"><strong>&#8377;<?=number_format(array_sum(array_column($modeBreakdown,'total')))?></strong></td>
  </tr>
  <?php if($duesPend > 0): ?>
  <tr style="background:#fdf7ff">
    <td style="color:#6a1b9a;font-weight:600">Visitor Dues Pending</td>
    <td style="text-align:center;color:#6a1b9a"><?=count($pendingDues)?></td>
    <td style="text-align:right;font-weight:700;color:#6a1b9a">&minus;&#8377;<?=number_format($duesPend)?></td>
  </tr>
  <?php endif; ?>
  <tr style="background:#f5f5f5;border-top:2px solid #333">
    <td><strong>NET TOTAL</strong></td>
    <td></td>
    <td style="text-align:right;color:#1b5e20;font-weight:800;font-size:10pt">&#8377;<?=number_format($netTotal)?></td>
  </tr>
<?php else: ?>
  <tr><td colspan="3" class="pr-empty">No payments recorded</td></tr>
<?php endif; ?>
  </tbody>
</table>

<!-- CASH DENOMINATION — synced from Live Desk localStorage -->
<div class="pr-sec">Cash Denomination Counter</div>
<div class="denom-wrap">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:6px">
    <?php foreach (['500'=>'&#8377;500','200'=>'&#8377;200','100'=>'&#8377;100','50'=>'&#8377;50'] as $dv=>$dl): ?>
    <div style="border:1px solid #ccc;border-radius:5px;padding:4px 6px">
      <div style="font-size:7pt;color:#888"><?=$dl?> notes</div>
      <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
        <input type="number" class="pr-denom-inp" id="pdi<?=$dv?>" data-val="<?=$dv?>" min="0" placeholder="0"
               style="width:48px;border:1.5px solid #bbb;border-radius:4px;padding:2px 4px;font-size:8.5pt;font-weight:700;text-align:center;outline:none"
               oninput="calcDenomPrint()">
        <span style="font-size:7.5pt;color:#555">&times; <?=$dv?> = <span id="pds<?=$dv?>" style="font-weight:700">&#8377;0</span></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="denom-row" style="padding-top:5px;border-top:1.5px solid #000;margin-top:2px">
    <span style="font-weight:700;font-size:9.5pt">TOTAL CASH IN HAND</span>
    <span style="font-weight:800;color:#D90429;font-size:12pt" id="pds_total">&#8377;0</span>
  </div>
</div>

<hr class="pr-divider">

<!-- ══ SECTION 1: MEMBERS PAID ══ -->
<div class="pr-sec">
  Members Paid
  <span class="pr-sec-total"><?=count($paidMembersList)?></span>
</div>
<?php if ($paidMembersList): ?>
<table class="pr-tbl">
  <thead>
    <tr>
      <th class="num">#</th>
      <th style="text-align:left">Name</th>
      <th>Wks</th>
      <th>Mode</th>
      <th style="text-align:right">Paid</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($paidMembersList as $i => $r): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><strong><?=htmlspecialchars($r['name'])?></strong>
      <?php if (!empty($r['category'])): ?><span style="font-size:7pt;color:#888;display:block"><?=htmlspecialchars($r['category'])?></span><?php endif; ?>
    </td>
    <td style="text-align:center;color:#666"><?=(int)$r['week_cnt']?></td>
    <td style="text-align:center"><span class="mode-badge"><?=htmlspecialchars(shortMode($r['payment_method']))?></span></td>
    <td class="amt">&#8377;<?=number_format($r['paid_amt'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#f8f8f8">
    <td colspan="4" style="text-align:right;font-weight:700;font-size:8pt">Total</td>
    <td class="amt" style="color:#D90429">&#8377;<?=number_format($memTot)?></td>
  </tr>
  </tbody>
</table>
<?php else: ?>
<div class="pr-empty">No members paid this week</div>
<?php endif; ?>

<hr class="pr-divider">

<!-- ══ SECTION 2: VISITORS PAID ══ -->
<div class="pr-sec">
  Visitors Paid
  <span class="pr-sec-total"><?=count($visitorsList)?> &middot; &#8377;<?=number_format($visTot)?></span>
</div>
<div class="no-print" style="text-align:right;margin:4px 0 6px">
  <button type="button" onclick="openWeeklyVisitorAdd()"
          style="background:#D90429;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:8pt;font-weight:700;cursor:pointer">+ Add Visitor</button>
</div>
<?php if ($visitorsList): ?>
<table class="pr-tbl">
  <thead>
    <tr>
      <th class="num">#</th>
      <th style="text-align:left">Name</th>
      <th style="text-align:left">Via / Mode</th>
      <th style="text-align:right">Amt</th>
      <th class="no-print" style="text-align:center">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php $vi = 0; foreach ($visitorsByMember as $memberName => $memberVisitors): ?>
  <tr style="background:#f1f1f1">
    <td colspan="5" style="font-weight:800;font-size:8pt;text-transform:uppercase;color:#333">
      <?=htmlspecialchars($memberName)?> <span style="font-weight:400;color:#777">(<?=count($memberVisitors)?>)</span>
    </td>
  </tr>
  <?php foreach ($memberVisitors as $v):
    $vi++;
    $isDue = ($v['due_status'] ?? '') === 'Pending';
    $vm = $isDue ? '' : shortMode($v['payment_method']);
    $visitorEditJson = htmlspecialchars(json_encode([
        'id' => (int)($v['id'] ?? 0),
        'member_id' => (int)($v['report_member_id'] ?? $v['member_id'] ?? 0),
        'visitor_name' => $v['visitor_name'] ?? '',
        'visitor_profession' => $v['visitor_profession'] ?? '',
        'friday_date' => $v['friday_date'] ?? $sel,
        'paid_date' => $v['paid_date'] ?? $sel,
        'amount' => (int)($v['amount'] ?? 1450),
        'payment_method' => $v['payment_method'] ?? 'Cash',
        'status' => $v['status'] ?? 'Paid',
    ]), ENT_QUOTES, 'UTF-8');
  ?>
  <tr>
    <td class="num"><?=$vi?></td>
    <td>
      <strong><?=htmlspecialchars($v['visitor_name'])?></strong>
      <?php if (!empty($v['visitor_profession'])): ?><span style="font-size:7pt;color:#666;display:block"><?=htmlspecialchars($v['visitor_profession'])?></span><?php endif; ?>
      <span class="via">Paid by <?=htmlspecialchars($memberName)?></span>
    </td>
    <td style="font-size:7.5pt;color:#444">
      <?=htmlspecialchars($memberName)?>
      <?php if ($vm): ?><span class="mode-badge" style="display:block;margin-top:2px"><?=htmlspecialchars($vm)?></span><?php endif; ?>
    </td>
    <td class="amt">&#8377;<?=number_format($v['amount'])?></td>
    <td class="no-print" style="text-align:center;white-space:nowrap">
      <?php if (!$isDue): ?>
      <button type="button" data-row="<?=$visitorEditJson?>" onclick="openWeeklyVisitorEdit(this)"
              style="border:1px solid #bbb;background:#fff;border-radius:5px;padding:2px 7px;font-size:7pt;cursor:pointer">Edit</button>
      <button type="button" onclick="deleteWeeklyVisitor(<?=(int)($v['id'] ?? 0)?>)"
              style="border:1px solid #ffcdd2;background:#ffebee;color:#c62828;border-radius:5px;padding:2px 7px;font-size:7pt;cursor:pointer;margin-left:2px">Delete</button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <tr style="background:#f8f8f8">
    <td colspan="3" style="text-align:right;font-weight:700;font-size:8pt">Total</td>
    <td class="amt" style="color:#1565c0">&#8377;<?=number_format($visTot)?></td>
    <td class="no-print"></td>
  </tr>
  </tbody>
</table>
<?php else: ?>
<div class="pr-empty">No visitors paid this week</div>
<?php endif; ?>

<hr class="pr-divider">

<!-- ══ SECTION 3: OBSERVERS PAID ══ -->
<div class="pr-sec">
  Observers Paid
  <span class="pr-sec-total"><?=count($observersList)?> &middot; &#8377;<?=number_format($obsTot)?></span>
</div>
<?php if ($observersList): ?>
<table class="pr-tbl">
  <thead>
    <tr>
      <th class="num">#</th>
      <th style="text-align:left">Name</th>
      <th style="text-align:left">Chapter</th>
      <th>Mode</th>
      <th style="text-align:right">Amt</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($observersList as $i => $o): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td>
      <strong><?=htmlspecialchars($o['visitor_name'])?></strong>
      <?php if (!empty($o['observer_category'])): ?><span style="font-size:7.5pt;color:#666;display:block"><?=htmlspecialchars($o['observer_category'])?></span><?php endif; ?>
    </td>
    <td style="font-size:8pt;color:#444"><?=htmlspecialchars($o['observer_chapter'] ?? '—')?></td>
    <td style="text-align:center"><span class="mode-badge"><?=htmlspecialchars(shortMode($o['payment_method']))?></span></td>
    <td class="amt">&#8377;<?=number_format($o['amount'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#f8f8f8">
    <td colspan="4" style="text-align:right;font-weight:700;font-size:8pt">Total</td>
    <td class="amt" style="color:#444">&#8377;<?=number_format($obsTot)?></td>
  </tr>
  </tbody>
</table>
<?php else: ?>
<div class="pr-empty">No observers this week</div>
<?php endif; ?>

<hr class="pr-divider">

<!-- ══ SECTION 4: KITTY CASH COLLECTED ══ -->
<?php if ($kittyList): ?>
<div class="pr-sec" style="background:#c47800">
  Kitty Cash Collected
  <span class="pr-sec-total"><?=count($kittyList)?> &middot; &#8377;<?=number_format($kittyTot)?></span>
</div>
<table class="pr-tbl">
  <thead>
    <tr>
      <th class="num">#</th>
      <th style="text-align:left">Member</th>
      <th style="text-align:left">Category</th>
      <th style="text-align:center">Mode</th>
      <th style="text-align:right">Amount</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($kittyList as $i => $k): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><strong><?=htmlspecialchars($k['name'])?></strong></td>
    <td style="font-size:7.5pt;color:#666"><?=htmlspecialchars($k['category']??'—')?></td>
    <td style="text-align:center"><span class="mode-badge"><?=htmlspecialchars(shortMode($k['payment_method']))?></span></td>
    <td class="amt" style="color:#c47800">&#8377;<?=number_format($k['amount'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#fff8e1">
    <td colspan="4" style="text-align:right;font-weight:700;font-size:8pt">Total</td>
    <td class="amt" style="color:#c47800;font-weight:800">&#8377;<?=number_format($kittyTot)?></td>
  </tr>
  </tbody>
</table>
<hr class="pr-divider">
<?php endif; ?>

<!-- ══ SECTION 5: VISITOR DUES COLLECTED THIS WEEK ══ -->
<?php if ($collectedDues): ?>
<div class="pr-sec" style="background:#1b5e20">
  Visitor Dues Collected This Week
  <span class="pr-sec-total"><?=count($collectedDues)?> &middot; &#8377;<?=number_format($collectedDuesTotal)?></span>
</div>
<div style="font-size:7.5pt;padding:2px 5px;background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20;margin-bottom:3px">
  Past meeting visitor fees collected from their member this week
</div>
<table class="pr-tbl">
  <thead>
    <tr>
      <th class="num">#</th>
      <th style="text-align:left">Visitor Name</th>
      <th style="text-align:left">Collect From</th>
      <th>Meeting Sunday</th>
      <th>Collected On</th>
      <th>Mode</th>
      <th style="text-align:right">Amount</th>
      <th class="no-print" style="text-align:center">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($collectedDues as $i => $d): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><?=htmlspecialchars($d['visitor_name'])?></td>
    <td style="font-weight:700;color:#1b5e20"><?=htmlspecialchars($d['member_name'])?></td>
    <td style="text-align:center;font-size:7.5pt"><?=date('d M Y', strtotime($d['friday_date']))?></td>
    <td style="text-align:center;font-size:7.5pt"><?=date('d M Y', strtotime($d['paid_date']))?></td>
    <td style="text-align:center"><span class="mode-badge"><?=htmlspecialchars(shortMode($d['payment_method'] ?: 'Cash'))?></span></td>
    <td class="amt" style="color:#1b5e20">&#8377;<?=number_format($d['amount'])?></td>
    <td class="no-print" style="text-align:center;white-space:nowrap">
      <button type="button" onclick="editWeeklyDueCollection(<?=(int)$d['id']?>, '<?=htmlspecialchars(addslashes($d['visitor_name']))?>', <?=(int)$d['amount']?>, '<?=htmlspecialchars($d['payment_method'] ?: 'Cash')?>', '<?=htmlspecialchars($d['paid_date'])?>')"
              style="border:1px solid #bbb;background:#fff;border-radius:5px;padding:2px 7px;font-size:7pt;cursor:pointer">Edit</button>
      <button type="button" onclick="unsettleWeeklyDue(<?=(int)$d['id']?>)"
              style="border:1px solid #ffcdd2;background:#ffebee;color:#c62828;border-radius:5px;padding:2px 7px;font-size:7pt;cursor:pointer;margin-left:2px">Undo</button>
    </td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#e8f5e9">
    <td colspan="6" style="text-align:right;font-weight:700;font-size:8pt">Total</td>
    <td class="amt" style="color:#1b5e20">&#8377;<?=number_format($collectedDuesTotal)?></td>
    <td class="no-print"></td>
  </tr>
  </tbody>
</table>
<hr class="pr-divider">
<?php endif; ?>

<?php $duesTotal = array_sum(array_column($pendingDues,'amount')); ?>
<div class="pr-sec">
  Visitor Dues Pending
  <span class="pr-sec-total"><?=count($pendingDues)?> &middot; &#8377;<?=number_format($duesTotal)?></span>
</div>
<div style="font-size:7.5pt;padding:2px 5px;background:#fdf7ff;border:1px solid #e1bee7;color:#6a1b9a;margin-bottom:3px">
  Visitors entered — fee pending collection from their member
</div>
<?php if ($pendingDues): ?>
<table class="pr-tbl">
  <thead>
    <tr>
      <th class="num">#</th>
      <th style="text-align:left">Visitor Name</th>
      <th style="text-align:left">Collect From</th>
      <th style="text-align:right">Amount</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($pendingDues as $i => $pd): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><?=htmlspecialchars($pd['visitor_name'])?></td>
    <td style="font-weight:700;color:#6a1b9a"><?=htmlspecialchars($pd['member_name'])?></td>
    <td class="amt" style="color:#6a1b9a">&#8377;<?=number_format($pd['amount'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#fdf7ff">
    <td colspan="3" style="text-align:right;font-weight:700;font-size:8pt">Total</td>
    <td class="amt" style="color:#6a1b9a">&#8377;<?=number_format($duesTotal)?></td>
  </tr>
  </tbody>
</table>
<?php else: ?>
<div class="pr-empty">No pending visitor dues</div>
<?php endif; ?>

<!-- FOOTER -->
<hr class="pr-divider-solid" style="margin-top:10px">
<div style="text-align:center;font-size:7.5pt;color:#666;padding-bottom:4px">
  Miracle Morning &nbsp;|&nbsp; Coimbatore Chapter &nbsp;|&nbsp;
  Printed <?=date('d M Y, H:i')?> &nbsp;|&nbsp; Week of <?=date('d M Y',strtotime($sel))?>
</div>
</div><!-- end #print-area -->
</div><!-- end .content -->

<div class="no-print" id="weeklyVisitorEditBg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)closeWeeklyVisitorEdit()">
  <div style="background:#fff;border-radius:10px;padding:18px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 id="wve_title" style="font-size:1rem;margin:0 0 12px;font-weight:800">Edit Visitor Payment</h3>
    <input type="hidden" id="wve_id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <label style="font-size:.75rem;font-weight:700">Visitor Name<input id="wve_name" type="text" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Profession<input id="wve_prof" type="text" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Member
        <select id="wve_member" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px">
          <option value="">Select member</option>
          <?php foreach ($allMemP as $m): ?>
          <option value="<?=(int)$m['id']?>"><?=htmlspecialchars($m['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:.75rem;font-weight:700">Mode
        <select id="wve_mode" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px">
          <option value="Cash">Cash</option>
          <option value="UPI">QR Code (UPI)</option>
          <option value="Card">Card</option>
          <option value="FinCloud">FinCloud</option>
          <option value="Pending-Member">Pending-Member</option>
        </select>
      </label>
      <label style="font-size:.75rem;font-weight:700">Sunday Date<input id="wve_Sunday" type="date" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Paid Date<input id="wve_paid" type="date" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Amount<input id="wve_amount" type="number" min="1" step="1" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Status
        <select id="wve_status" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px">
          <option value="Paid">Paid</option>
          <option value="Pending">Pending</option>
          <option value="Rejected">Rejected</option>
        </select>
      </label>
    </div>
    <div id="wve_err" style="display:none;margin-top:10px;background:#fff0f2;color:#b00020;border-radius:7px;padding:8px;font-size:.82rem"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
      <button type="button" onclick="closeWeeklyVisitorEdit()" style="background:#fff;border:1px solid #bbb;border-radius:7px;padding:7px 14px;cursor:pointer">Cancel</button>
      <button type="button" onclick="saveWeeklyVisitorEdit()" style="background:#D90429;color:#fff;border:none;border-radius:7px;padding:7px 16px;font-weight:700;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<script>
function openWeeklyVisitorAdd() {
    document.getElementById('wve_title').textContent = 'Add Visitor Payment';
    document.getElementById('wve_id').value = '';
    document.getElementById('wve_name').value = '';
    document.getElementById('wve_prof').value = '';
    document.getElementById('wve_member').value = '';
    document.getElementById('wve_mode').value = 'Cash';
    document.getElementById('wve_Sunday').value = '<?=$sel?>';
    document.getElementById('wve_paid').value = '<?=$sel?>';
    document.getElementById('wve_amount').value = 1450;
    document.getElementById('wve_status').value = 'Paid';
    document.getElementById('wve_err').style.display = 'none';
    document.getElementById('weeklyVisitorEditBg').style.display = 'flex';
    setTimeout(function(){ document.getElementById('wve_name').focus(); }, 80);
}

function openWeeklyVisitorEdit(btn) {
    var row = JSON.parse(btn.getAttribute('data-row') || '{}');
    document.getElementById('wve_title').textContent = 'Edit Visitor Payment';
    document.getElementById('wve_id').value = row.id || '';
    document.getElementById('wve_name').value = row.visitor_name || '';
    document.getElementById('wve_prof').value = row.visitor_profession || '';
    document.getElementById('wve_member').value = row.member_id || '';
    document.getElementById('wve_mode').value = row.payment_method || 'Cash';
    document.getElementById('wve_Sunday').value = row.friday_date || '<?=$sel?>';
    document.getElementById('wve_paid').value = row.paid_date || '<?=$sel?>';
    document.getElementById('wve_amount').value = row.amount || 1450;
    document.getElementById('wve_status').value = row.status || 'Paid';
    document.getElementById('wve_err').style.display = 'none';
    document.getElementById('weeklyVisitorEditBg').style.display = 'flex';
}

function closeWeeklyVisitorEdit() {
    document.getElementById('weeklyVisitorEditBg').style.display = 'none';
}

function saveWeeklyVisitorEdit() {
    var err = document.getElementById('wve_err');
    if (!document.getElementById('wve_name').value.trim()) {
        err.textContent = 'Visitor name is required.';
        err.style.display = 'block';
        return;
    }
    if (!document.getElementById('wve_member').value) {
        err.textContent = 'Please select the member.';
        err.style.display = 'block';
        return;
    }
    var fd = new FormData();
    fd.append('action', 'edit_visitor_txn');
    fd.append('id', document.getElementById('wve_id').value);
    fd.append('member_id', document.getElementById('wve_member').value);
    fd.append('visitor_name', document.getElementById('wve_name').value);
    fd.append('visitor_profession', document.getElementById('wve_prof').value);
    fd.append('friday_date', document.getElementById('wve_Sunday').value);
    fd.append('paid_date', document.getElementById('wve_paid').value);
    fd.append('amount', document.getElementById('wve_amount').value);
    fd.append('mode', document.getElementById('wve_mode').value);
    fd.append('status', document.getElementById('wve_status').value);
    fd.append('_csrf', _csrf);
    fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                err.textContent = d.msg || 'Could not save visitor.';
                err.style.display = 'block';
                return;
            }
            location.reload();
        })
        .catch(function(e){
            err.textContent = 'Request failed: ' + e.message;
            err.style.display = 'block';
        });
}

function deleteWeeklyVisitor(txnId) {
    if (!txnId) return;
    if (!confirm('Delete this visitor from the report and member card?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_visitor_txn');
    fd.append('id', txnId);
    fd.append('_csrf', _csrf);
    fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                alert(d.msg || 'Could not delete visitor.');
                return;
            }
            if (!d.deleted_rows) {
                alert('No database row was deleted. Please refresh and try again.');
                return;
            }
            location.reload();
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

function editWeeklyDueCollection(dueId, name, amount, mode, paidDate) {
    var nextAmount = prompt('Amount for ' + name, amount);
    if (nextAmount === null) return;
    nextAmount = parseInt(nextAmount, 10) || 0;
    if (nextAmount <= 0) { alert('Enter a valid amount.'); return; }
    var nextMode = prompt('Payment mode (Cash / UPI / Card / FinCloud)', mode || 'Cash');
    if (!nextMode) return;
    var nextDate = prompt('Collected date (YYYY-MM-DD)', paidDate || '<?=$sel?>');
    if (!nextDate) return;
    var fd = new FormData();
    fd.append('action', 'edit_due_collection');
    fd.append('due_id', dueId);
    fd.append('amount', nextAmount);
    fd.append('mode', nextMode);
    fd.append('paid_date', nextDate);
    fetch('verify_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) { alert('Error: ' + (d.msg || 'Failed')); return; }
            location.reload();
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

function unsettleWeeklyDue(dueId) {
    if (!confirm('Move this visitor due back to pending?')) return;
    var fd = new FormData();
    fd.append('action', 'unsettle_due');
    fd.append('due_id', dueId);
    fetch('verify_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) { alert('Error: ' + (d.msg || 'Failed')); return; }
            location.reload();
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}

/* Live denom counter (screen only) */
function setPrintMode(mode) {
    document.body.classList.remove('print-80mm','print-a4');
    document.body.classList.add('print-' + mode);
    // Inject @page rule dynamically (only reliable cross-browser method)
    var old = document.getElementById('hm_page_style');
    if (old) old.parentNode.removeChild(old);
    var s = document.createElement('style');
    s.id = 'hm_page_style';
    if (mode === 'a4') {
        s.textContent = '@media print { @page { size: A4 portrait; margin: 12mm 15mm; } }';
    } else {
        s.textContent = '@media print { @page { size: 80mm auto; margin: 4mm 3mm; } }';
    }
    document.head.appendChild(s);
    localStorage && localStorage.setItem('hm_print_mode', mode);
}
// Restore last mode on load
(function(){
    var last = localStorage && localStorage.getItem && localStorage.getItem('hm_print_mode');
    setPrintMode(last || '80mm');
})();

function calcDenomPrint() {
    var total = 0;
    [500,200,100,50].forEach(function(v) {
        var inp = document.getElementById('pdi'+v);
        var cnt = parseInt((inp||{}).value) || 0;
        var sub = cnt * v;
        var el  = document.getElementById('pds'+v);
        if (el) el.textContent = '₹' + sub.toLocaleString('en-IN');
        total += sub;
    });
    var tot = document.getElementById('pds_total');
    if (tot) tot.textContent = '₹' + total.toLocaleString('en-IN');
    // Save to localStorage for persistence
    try {
        var vals={};
        [500,200,100,50].forEach(function(v){
            var inp=document.getElementById('pdi'+v);
            vals[v]=parseInt((inp||{}).value)||0;
        });
        localStorage.setItem('hm_denom_<?=$sel?>',JSON.stringify(vals));
    } catch(e){}
}

// Auto-load from Live Desk localStorage on page load
(function(){
    try {
        var saved = localStorage.getItem('hm_denom_<?=$sel?>');
        if (!saved) return;
        var vals = JSON.parse(saved);
        [500,200,100,50].forEach(function(v){
            var inp = document.getElementById('pdi'+v);
            if (inp && vals[v]) inp.value = vals[v];
        });
        calcDenomPrint();
    } catch(e){}
})();
</script>
