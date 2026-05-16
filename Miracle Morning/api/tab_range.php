<?php
require_once __DIR__ . '/name_match_helper.php';
require_once __DIR__ . '/report_calc_helper.php';

function hm_range_date(string $key, string $fallback): string {
    $raw = $_GET[$key] ?? $fallback;
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : $fallback;
}

$rangeTo = hm_range_date('to', date('Y-m-d'));
$rangeFrom = hm_range_date('from', date('Y-m-d', strtotime($rangeTo . ' -7 days')));
if ($rangeFrom > $rangeTo) {
    [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
}

$allRangeMembers = $pdo->query("SELECT id, name, category FROM members WHERE status='Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$rangeSundays = [];
$rf = new DateTime($rangeFrom);
$rt = new DateTime($rangeTo);
if ((int)$rf->format('N') !== 7) $rf->modify('next sunday');
while ($rf <= $rt) {
    $rangeSundays[] = $rf->format('Y-m-d');
    $rf->modify('+7 days');
}

$memberSessionsQ = $pdo->prepare("
    SELECT  m.id AS member_id, m.name, m.category,
            DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s') AS session_id,
            DATE(t.submitted_at) AS paid_date,
            GROUP_CONCAT(t.friday_date ORDER BY t.friday_date ASC SEPARATOR ',') AS Sundays,
            COUNT(*) AS week_count,
            MIN(t.payment_method) AS payment_method,
            MAX(t.status) AS status,
            COALESCE(MAX(t.original_total), SUM(t.amount)) AS total_amount
    FROM transactions t
    JOIN members m ON m.id = t.member_id
    WHERE t.type='Member'
      AND t.status = 'Paid'
      AND DATE(t.submitted_at) BETWEEN ? AND ?
    GROUP BY m.id, m.name, m.category, DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s')
    ORDER BY m.name ASC, DATE(t.submitted_at) ASC, session_id ASC
");
$memberSessionsQ->execute([$rangeFrom, $rangeTo]);
$memberSessions = $memberSessionsQ->fetchAll(PDO::FETCH_ASSOC);

$memberPaidTotal = 0;
$memberSessionsByMember = [];
foreach ($memberSessions as $s) {
    $memberPaidTotal += (float)$s['total_amount'];
    $mid = (int)$s['member_id'];
    if (!isset($memberSessionsByMember[$mid])) {
        $memberSessionsByMember[$mid] = [
            'name' => $s['name'],
            'category' => $s['category'],
            'sessions' => [],
            'total' => 0,
        ];
    }
    $memberSessionsByMember[$mid]['sessions'][] = $s;
    $memberSessionsByMember[$mid]['total'] += (float)$s['total_amount'];
}

$unpaidMembers = [];
$memberDueTotal = 0;
foreach ($allRangeMembers as $m) {
    $calc = recalc_member_payments($pdo, (int)$m['id']);
    $coveredSundays = $calc['Sundays'] ?? [];
    $firstCoveredSunday = $coveredSundays ? min(array_keys($coveredSundays)) : null;
    $dueDates = [];
    foreach ($rangeSundays as $fd) {
        // If a member already has a payment history, do not count dates before
        // their first covered Sunday. This matches the member-card/report view.
        if ($firstCoveredSunday && $fd < $firstCoveredSunday) {
            continue;
        }
        $covered = isset($coveredSundays[$fd])
            && ($coveredSundays[$fd]['status'] ?? '') === 'Paid'
            && (float)($coveredSundays[$fd]['balance'] ?? 1450) <= 0;
        if (!$covered) {
            $dueDates[] = $fd;
        }
    }
    if ($dueDates) {
        $dueAmount = count($dueDates) * 1450;
        $unpaidMembers[] = [
            'id' => (int)$m['id'],
            'name' => $m['name'],
            'category' => $m['category'] ?? '',
            'due_dates' => $dueDates,
            'due_weeks' => count($dueDates),
            'due_amount' => $dueAmount,
        ];
        $memberDueTotal += $dueAmount;
    }
}

$refExpr = hm_member_name_sql_expr('t.referrer_name');
$memExpr = hm_member_name_sql_expr('mref.name');
$visitorQ = $pdo->prepare("
    SELECT  t.id, t.member_id, t.visitor_name, t.visitor_profession, t.visitor_company,
            t.referrer_name, COALESCE(vd.payment_method, t.payment_method) AS payment_method, t.amount, t.status,
            t.friday_date, DATE(t.submitted_at) AS entry_date,
            vd.status AS due_status, vd.id AS due_id,
            COALESCE(vdm.id, mt.id, mref.id) AS report_member_id,
            COALESCE(vdm.name, mt.name, mref.name, t.referrer_name, 'Unmatched') AS report_member_name
    FROM transactions t
    LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
    LEFT JOIN members vdm ON vdm.id = vd.member_id
    LEFT JOIN members mt ON mt.id = t.member_id
    LEFT JOIN members mref ON mref.status='Active' AND $refExpr = $memExpr
    WHERE t.type='Visitor'
      AND t.status = 'Paid'
      AND DATE(t.submitted_at) BETWEEN ? AND ?
    ORDER BY report_member_name ASC, t.visitor_name ASC
");
$visitorQ->execute([$rangeFrom, $rangeTo]);
$visitorRows = $visitorQ->fetchAll(PDO::FETCH_ASSOC);

$visitorsByMember = [];
$visitorPaidTotal = 0;
foreach ($visitorRows as $v) {
    $key = trim($v['report_member_name'] ?? '') ?: 'Unmatched';
    if (!isset($visitorsByMember[$key])) $visitorsByMember[$key] = [];
    $visitorsByMember[$key][] = $v;
    // All visitors counted in gross total; pending dues subtracted via visitorDueTotal
    if ($v['status'] === 'Paid') {
        $visitorPaidTotal += (float)$v['amount'];
    }
}

$kittyQ = $pdo->prepare("
    SELECT k.id, m.name, m.category, k.amount, k.payment_method, k.status, DATE(k.submitted_at) AS paid_date, COALESCE(k.notes,'') AS notes
    FROM kitty_payments k
    JOIN members m ON m.id = k.member_id
    WHERE k.status = 'Paid'
      AND DATE(k.submitted_at) BETWEEN ? AND ?
    ORDER BY m.name ASC, DATE(k.submitted_at) ASC
");
$kittyQ->execute([$rangeFrom, $rangeTo]);
$kittyRows = $kittyQ->fetchAll(PDO::FETCH_ASSOC);
$kittyPaidTotal = 0;
foreach ($kittyRows as $k) if ($k['status'] === 'Paid') $kittyPaidTotal += (float)$k['amount'];

$kittyPaidAll = [];
try {
    $kpQ = $pdo->query("SELECT member_id, COALESCE(SUM(amount),0) AS paid FROM kitty_payments WHERE status='Paid' GROUP BY member_id");
    foreach ($kpQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $kittyPaidAll[(int)$row['member_id']] = (float)$row['paid'];
    }
} catch (Exception $e) {}
$kittyDueRows = [];
$kittyDueTotal = 0;
foreach ($allRangeMembers as $m) {
    $paid = $kittyPaidAll[(int)$m['id']] ?? 0;
    $due = max(0, 3000 - $paid);
    if ($due > 0) {
        $kittyDueRows[] = [
            'id' => (int)$m['id'],
            'name' => $m['name'],
            'category' => $m['category'] ?? '',
            'paid' => $paid,
            'due' => $due,
        ];
        $kittyDueTotal += $due;
    }
}

$observerQ = $pdo->prepare("
    SELECT id, visitor_name, observer_chapter, observer_category, payment_method, amount, status,
           friday_date, DATE(submitted_at) AS entry_date
    FROM transactions
    WHERE type='Observer'
      AND status = 'Paid'
      AND DATE(submitted_at) BETWEEN ? AND ?
    ORDER BY visitor_name ASC
");
$observerQ->execute([$rangeFrom, $rangeTo]);
$observerRows = $observerQ->fetchAll(PDO::FETCH_ASSOC);
$observerPaidTotal = 0;
foreach ($observerRows as $o) if ($o['status'] === 'Paid') $observerPaidTotal += (float)$o['amount'];

$duesQ = $pdo->prepare("
    SELECT vd.id, vd.txn_id, vd.visitor_name, vd.amount, m.id AS member_id, m.name AS member_name,
           DATE(t.submitted_at) AS entry_date
    FROM visitor_dues vd
    JOIN members m ON m.id = vd.member_id
    JOIN transactions t ON t.id = vd.txn_id
    WHERE vd.status='Pending'
      AND t.status = 'Paid'
      AND DATE(t.submitted_at) BETWEEN ? AND ?
    ORDER BY m.name ASC, vd.visitor_name ASC
");
$duesQ->execute([$rangeFrom, $rangeTo]);
$visitorDues = $duesQ->fetchAll(PDO::FETCH_ASSOC);
$duesByMember = [];
$visitorDueTotal = 0;
foreach ($visitorDues as $d) {
    $key = $d['member_name'] ?: 'Unmatched';
    if (!isset($duesByMember[$key])) $duesByMember[$key] = [];
    $duesByMember[$key][] = $d;
    $visitorDueTotal += (float)$d['amount'];
}

$collectedDuesQ = $pdo->prepare("
    SELECT vd.id, vd.txn_id, 'due' AS source_type, COALESCE(vd.visitor_name, t.visitor_name) AS visitor_name, vd.amount, vd.payment_method, DATE(vd.paid_at) AS paid_date,
           t.friday_date, COALESCE(vd.member_id, t.member_id) AS member_id, COALESCE(m.name, mt.name, t.referrer_name, 'Unmatched') AS member_name
    FROM visitor_dues vd
    JOIN transactions t ON t.id = vd.txn_id
    LEFT JOIN members m ON m.id = vd.member_id
    LEFT JOIN members mt ON mt.id = t.member_id
    WHERE vd.status='Paid'
      AND vd.paid_at IS NOT NULL
      AND DATE(vd.paid_at) BETWEEN ? AND ?
    ORDER BY member_name ASC, friday_date ASC, visitor_name ASC
");
$collectedDuesQ->execute([$rangeFrom, $rangeTo]);
$collectedDues = $collectedDuesQ->fetchAll(PDO::FETCH_ASSOC);
$collectedDuesTotal = array_sum(array_column($collectedDues, 'amount'));

$grossTotal = $memberPaidTotal + $visitorPaidTotal + $kittyPaidTotal + $observerPaidTotal + $collectedDuesTotal;
$netTotal = $grossTotal - $visitorDueTotal;

function hm_range_mode(string $m): string {
    return match(strtolower(trim($m))) {
        'fincloud' => 'FinCloud',
        'cash' => 'Cash',
        'card' => 'Card',
        'upi', 'qr code (upi)' => 'UPI/QR',
        'pending-member' => 'Via Member',
        default => $m,
    };
}
?>

<div class="content">
<div class="no-print d-flex align-items-center gap-3 mb-3 p-3" style="background:#fff;border-radius:10px;border:1px solid var(--bdr);flex-wrap:wrap">
  <div>
    <div style="font-size:.9rem;font-weight:800">Date Range Report</div>
    <div style="font-size:.75rem;color:var(--gry)">Entries/payments submitted from <?=date('d M Y', strtotime($rangeFrom))?> to <?=date('d M Y', strtotime($rangeTo))?></div>
  </div>
  <form method="GET" style="display:flex;align-items:end;gap:8px;flex-wrap:wrap;margin:0 0 0 auto">
    <input type="hidden" name="tab" value="range">
    <label style="font-size:.72rem;font-weight:700;color:var(--gry)">From<br><input type="date" name="from" value="<?=htmlspecialchars($rangeFrom)?>" class="date-input"></label>
    <label style="font-size:.72rem;font-weight:700;color:var(--gry)">To<br><input type="date" name="to" value="<?=htmlspecialchars($rangeTo)?>" class="date-input"></label>
    <button class="btn-exp" type="submit">Apply</button>
    <button class="btn-exp outline" type="button" onclick="setRangePrintMode('a4');window.print()">PDF / Print A4</button>
    <button class="btn-exp outline" type="button" onclick="setRangePrintMode('80mm');window.print()">PDF / Print 80mm</button>
  </form>
</div>

<div id="range-print-area">
<style>
#range-print-area{font-family:Arial,sans-serif;font-size:9.5pt;color:#000;background:#fff;width:100%;max-width:840px;margin:0 auto}
.rr-sec{font-size:8.5pt;font-weight:800;text-transform:uppercase;letter-spacing:.5px;padding:3px 7px;margin:10px 0 4px;background:#111;color:#fff}
.rr-total{float:right;font-weight:500;background:rgba(255,255,255,.18);padding:0 6px;border-radius:3px}
.rr-tbl{width:100%;border-collapse:collapse;margin-bottom:4px}
.rr-tbl th{font-size:7.3pt;font-weight:800;text-transform:uppercase;background:#eee;border:1px solid #bbb;padding:3px 5px;text-align:center;white-space:nowrap}
.rr-tbl td{border:1px solid #ccc;padding:3px 5px;font-size:8.4pt;vertical-align:middle}
.rr-tbl .num{text-align:center;color:#777;width:20px}
.rr-tbl .amt{text-align:right;font-weight:800;white-space:nowrap}
.rr-badge{display:inline-block;font-size:7pt;font-weight:800;background:#f3f4f6;border:1px solid #ccc;border-radius:3px;padding:0 4px}
.rr-empty{text-align:center;color:#777;font-style:italic;border:1px solid #ccc;padding:7px;font-size:8.5pt}
.rr-member-row td{background:#f1f1f1;font-weight:800;text-transform:uppercase;color:#333}
.rr-actions{white-space:nowrap;text-align:center}
body.range-print-80mm #range-print-area{width:74mm;font-size:8pt}
body.range-print-80mm .rr-tbl td{font-size:7.4pt;padding:2px 3px}
body.range-print-80mm .rr-tbl th{font-size:6.4pt;padding:1px 2px}
body.range-print-80mm .rr-sec{font-size:7pt;padding:2px 4px;margin:7px 0 2px}
@media print{
  body *{visibility:hidden}
  #range-print-area,#range-print-area *{visibility:visible}
  #range-print-area{position:absolute;top:0;left:0;margin:0;padding:0;width:100%}
  .no-print{display:none!important}
  .rr-tbl tr{page-break-inside:avoid}
  .rr-sec{page-break-after:avoid}
}
</style>

<div style="text-align:center;font-size:14pt;font-weight:800;letter-spacing:.6px;margin:0 0 2px">Miracle Morning</div>
<div style="text-align:center;font-size:8.5pt;color:#444;margin:0 0 6px">
  Coimbatore Chapter | Date Range Report | <strong><?=date('d M Y', strtotime($rangeFrom))?> - <?=date('d M Y', strtotime($rangeTo))?></strong>
</div>

<table style="width:100%;border-collapse:collapse;margin-bottom:5px;border:1px solid #bbb">
  <tr style="background:#eee"><th style="padding:4px 8px;border:1px solid #bbb;text-align:left">Overview</th><th style="padding:4px 8px;border:1px solid #bbb;text-align:center">Count</th><th style="padding:4px 8px;border:1px solid #bbb;text-align:right">Paid Amount</th></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700">Member Payment Sessions</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center"><?=count($memberSessions)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800">&#8377;<?=number_format($memberPaidTotal)?></td></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700;color:#c62828">Members Unpaid / Due</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center;color:#c62828;font-weight:800"><?=count($unpaidMembers)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800;color:#c62828">&#8377;<?=number_format($memberDueTotal)?></td></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700">Visitors Paid</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center"><?=count($visitorRows)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800">&#8377;<?=number_format($visitorPaidTotal)?></td></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700;color:#1b5e20">Visitor Dues Collected</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center;color:#1b5e20;font-weight:800"><?=count($collectedDues)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800;color:#1b5e20">&#8377;<?=number_format($collectedDuesTotal)?></td></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700">Kitty Payments</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center"><?=count($kittyRows)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800">&#8377;<?=number_format($kittyPaidTotal)?></td></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700;color:#c47800">Kitty Due</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center;color:#c47800;font-weight:800"><?=count($kittyDueRows)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800;color:#c47800">&#8377;<?=number_format($kittyDueTotal)?></td></tr>
  <tr><td style="padding:4px 8px;border:1px solid #ccc;font-weight:700">Observers</td><td style="padding:4px 8px;border:1px solid #ccc;text-align:center"><?=count($observerRows)?></td><td style="padding:4px 8px;border:1px solid #ccc;text-align:right;font-weight:800">&#8377;<?=number_format($observerPaidTotal)?></td></tr>
</table>
<div style="background:#111;color:#fff;text-align:center;padding:7px 10px;font-weight:800;font-size:11pt;margin-bottom:7px">
  GRAND TOTAL &#8377;<?=number_format($netTotal)?>
  <?php if ($visitorDueTotal > 0): ?><div style="font-size:8pt;font-weight:400;opacity:.8">Gross &#8377;<?=number_format($grossTotal)?> - Visitor Dues &#8377;<?=number_format($visitorDueTotal)?></div><?php endif; ?>
</div>

<div class="rr-sec">Member Payments <span class="rr-total"><?=count($memberSessions)?> | &#8377;<?=number_format($memberPaidTotal)?></span></div>
<?php if ($memberSessions): ?>
<table class="rr-tbl">
  <thead><tr><th class="num">#</th><th>Paid Date</th><th>Sundays Covered</th><th>Wks</th><th>Mode</th><th>Status</th><th style="text-align:right">Session Amount</th></tr></thead>
  <tbody>
  <?php $mi = 0; foreach ($memberSessionsByMember as $memberGroup): $mi++; ?>
  <tr class="rr-member-row">
    <td colspan="7">
      <?=$mi?>. <?=htmlspecialchars($memberGroup['name'])?>
      <?php if($memberGroup['category']): ?><span style="font-weight:400;text-transform:none;color:#777"> - <?=htmlspecialchars($memberGroup['category'])?></span><?php endif; ?>
    </td>
  </tr>
  <?php foreach ($memberGroup['sessions'] as $si => $s): ?>
  <tr>
    <td class="num"><?=$si+1?></td>
    <td style="text-align:center"><?=date('d M Y', strtotime($s['paid_date']))?></td>
    <td style="font-size:7.4pt"><?=htmlspecialchars(implode(', ', array_map(fn($d) => date('d M', strtotime($d)), explode(',', $s['Sundays']))))?></td>
    <td style="text-align:center"><?=(int)$s['week_count']?></td>
    <td style="text-align:center"><span class="rr-badge"><?=htmlspecialchars(hm_range_mode($s['payment_method']))?></span></td>
    <td style="text-align:center;font-weight:800;color:<?=$s['status']==='Paid'?'#1b5e20':'#c47800'?>"><?=htmlspecialchars($s['status'])?></td>
    <td class="amt">&#8377;<?=number_format($s['total_amount'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#f8f8f8">
    <td colspan="6" style="text-align:right;font-weight:800">Total for <?=htmlspecialchars($memberGroup['name'])?></td>
    <td class="amt">&#8377;<?=number_format($memberGroup['total'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#fff0f2"><td colspan="6" style="text-align:right;font-weight:800">Grand total: all member payment sessions</td><td class="amt" style="color:#D90429">&#8377;<?=number_format($memberPaidTotal)?></td></tr>
  </tbody>
</table>
<?php else: ?><div class="rr-empty">No member payments in this date range</div><?php endif; ?>

<div class="rr-sec" style="background:#c62828">Members Unpaid / Due <span class="rr-total"><?=count($unpaidMembers)?> | &#8377;<?=number_format($memberDueTotal)?></span></div>
<?php if ($unpaidMembers): ?>
<table class="rr-tbl">
  <thead><tr><th class="num">#</th><th style="text-align:left">Member</th><th>Due Weeks</th><th style="text-align:left">Sunday Dates Due</th><th style="text-align:right">Due Amount</th></tr></thead>
  <tbody>
  <?php foreach ($unpaidMembers as $i => $m): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><strong><?=htmlspecialchars($m['name'])?></strong><?php if($m['category']): ?><span style="font-size:7pt;color:#777;display:block"><?=htmlspecialchars($m['category'])?></span><?php endif; ?></td>
    <td style="text-align:center;font-weight:800;color:#c62828"><?=(int)$m['due_weeks']?></td>
    <td style="font-size:7.4pt"><?=htmlspecialchars(implode(', ', array_map(fn($d) => date('d M', strtotime($d)), $m['due_dates'])))?></td>
    <td class="amt" style="color:#c62828">&#8377;<?=number_format($m['due_amount'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#ffebee">
    <td colspan="4" style="text-align:right;font-weight:800;color:#c62828">Total unpaid member dues</td>
    <td class="amt" style="color:#c62828">&#8377;<?=number_format($memberDueTotal)?></td>
  </tr>
  </tbody>
</table>
<?php else: ?><div class="rr-empty">No unpaid members for Sundays in this date range</div><?php endif; ?>

<div class="rr-sec">Visitor Entries <span class="rr-total"><?=count($visitorRows)?> | &#8377;<?=number_format($visitorPaidTotal)?></span></div>
<div class="no-print" style="text-align:right;margin:4px 0 6px">
  <button type="button" onclick="openRangeVisitorAdd()" class="btn-exp" style="padding:4px 10px;font-size:8pt">+ Add Visitor</button>
</div>
<?php if ($visitorRows): ?>
<table class="rr-tbl">
  <thead><tr><th class="num">#</th><th style="text-align:left">Visitor</th><th style="text-align:left">Member Invited</th><th>Entry Date</th><th>Mode</th><th>Status</th><th style="text-align:right">Amount</th><th class="no-print">Actions</th></tr></thead>
  <tbody>
  <?php $vi=0; foreach ($visitorsByMember as $memberName => $rows): ?>
  <tr class="rr-member-row"><td colspan="8"><?=htmlspecialchars($memberName)?> <span style="font-weight:400">(<?=count($rows)?>)</span></td></tr>
  <?php foreach ($rows as $v): $vi++;
    $editJson = htmlspecialchars(json_encode([
        'id'=>(int)$v['id'],
        'member_id'=>(int)($v['report_member_id'] ?? $v['member_id'] ?? 0),
        'visitor_name'=>$v['visitor_name'] ?? '',
        'visitor_profession'=>$v['visitor_profession'] ?? '',
        'friday_date'=>$v['friday_date'] ?? $rangeTo,
        'paid_date'=>$v['entry_date'] ?? $rangeTo,
        'amount'=>(int)($v['amount'] ?? 1450),
        'payment_method'=>$v['payment_method'] ?? 'Cash',
        'status'=>$v['status'] ?? 'Paid',
    ]), ENT_QUOTES, 'UTF-8');
  ?>
  <tr>
    <td class="num"><?=$vi?></td>
    <td><strong><?=htmlspecialchars($v['visitor_name'])?></strong><?php if($v['visitor_profession']): ?><span style="font-size:7pt;color:#777;display:block"><?=htmlspecialchars($v['visitor_profession'])?></span><?php endif; ?></td>
    <td><?=htmlspecialchars($memberName)?></td>
    <td style="text-align:center"><?=date('d M Y', strtotime($v['entry_date']))?></td>
    <td style="text-align:center"><?php if(($v['due_status']??'')==''): ?><span class="rr-badge"><?=htmlspecialchars(hm_range_mode($v['payment_method']))?></span><?php endif; ?></td>
    <td style="text-align:center;font-weight:800;color:<?=($v['due_status']??'')=='Pending'?'#6a1b9a':($v['status']=='Paid'?'#1b5e20':'#c47800')?>"><?=($v['due_status']??'')=='Pending'?'Due from Member':htmlspecialchars($v['status'])?></td>
    <td class="amt">&#8377;<?=number_format($v['amount'])?></td>
    <td class="no-print rr-actions">
      <?php if(($v['due_status']??'')===''): ?>
      <button type="button" data-row="<?=$editJson?>" onclick="openRangeVisitorEdit(this)" class="btn-edi" style="font-size:7pt;padding:2px 7px">Edit</button>
      <button type="button" onclick="deleteRangeVisitor(<?=(int)$v['id']?>)" class="btn-del" style="font-size:7pt;padding:2px 7px">Delete</button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; endforeach; ?>
  </tbody>
</table>
<?php else: ?><div class="rr-empty">No visitor entries in this date range</div><?php endif; ?>

<div class="rr-sec">Kitty Payments <span class="rr-total"><?=count($kittyRows)?> | &#8377;<?=number_format($kittyPaidTotal)?></span></div>
<?php if ($kittyRows): ?>
<table class="rr-tbl"><thead><tr><th class="num">#</th><th style="text-align:left">Member</th><th>Paid Date</th><th>Mode</th><th>Status</th><th style="text-align:right">Amount</th></tr></thead><tbody>
<?php foreach ($kittyRows as $i => $k): ?><tr><td class="num"><?=$i+1?></td><td><strong><?=htmlspecialchars($k['name'])?></strong></td><td style="text-align:center"><?=date('d M Y', strtotime($k['paid_date']))?></td><td style="text-align:center"><span class="rr-badge"><?=htmlspecialchars(hm_range_mode($k['payment_method']))?></span></td><td style="text-align:center;font-weight:800"><?=htmlspecialchars($k['status'])?></td><td class="amt">&#8377;<?=number_format($k['amount'])?></td></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?><div class="rr-empty">No kitty payments in this date range</div><?php endif; ?>

<div class="rr-sec" style="background:#c47800">Kitty Due <span class="rr-total"><?=count($kittyDueRows)?> | &#8377;<?=number_format($kittyDueTotal)?></span></div>
<?php if ($kittyDueRows): ?>
<table class="rr-tbl">
  <thead><tr><th class="num">#</th><th style="text-align:left">Member</th><th style="text-align:right">Kitty Paid</th><th style="text-align:right">Balance Due</th></tr></thead>
  <tbody>
  <?php foreach ($kittyDueRows as $i => $k): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><strong><?=htmlspecialchars($k['name'])?></strong><?php if($k['category']): ?><span style="font-size:7pt;color:#777;display:block"><?=htmlspecialchars($k['category'])?></span><?php endif; ?></td>
    <td class="amt">&#8377;<?=number_format($k['paid'])?></td>
    <td class="amt" style="color:#c47800">&#8377;<?=number_format($k['due'])?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#fff8e1">
    <td colspan="3" style="text-align:right;font-weight:800;color:#c47800">Total kitty due</td>
    <td class="amt" style="color:#c47800">&#8377;<?=number_format($kittyDueTotal)?></td>
  </tr>
  </tbody>
</table>
<?php else: ?><div class="rr-empty">No kitty dues pending</div><?php endif; ?>

<div class="rr-sec">Observers <span class="rr-total"><?=count($observerRows)?> | &#8377;<?=number_format($observerPaidTotal)?></span></div>
<?php if ($observerRows): ?>
<table class="rr-tbl"><thead><tr><th class="num">#</th><th style="text-align:left">Observer</th><th>Entry Date</th><th>Chapter</th><th>Mode</th><th>Status</th><th style="text-align:right">Amount</th></tr></thead><tbody>
<?php foreach ($observerRows as $i => $o): ?><tr><td class="num"><?=$i+1?></td><td><strong><?=htmlspecialchars($o['visitor_name'])?></strong><?php if($o['observer_category']): ?><span style="font-size:7pt;color:#777;display:block"><?=htmlspecialchars($o['observer_category'])?></span><?php endif; ?></td><td style="text-align:center"><?=date('d M Y', strtotime($o['entry_date']))?></td><td><?=htmlspecialchars($o['observer_chapter'] ?? '')?></td><td style="text-align:center"><span class="rr-badge"><?=htmlspecialchars(hm_range_mode($o['payment_method']))?></span></td><td style="text-align:center;font-weight:800"><?=htmlspecialchars($o['status'])?></td><td class="amt">&#8377;<?=number_format($o['amount'])?></td></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?><div class="rr-empty">No observers in this date range</div><?php endif; ?>

<div class="rr-sec" style="background:#1b5e20">Visitor Dues Collected <span class="rr-total"><?=count($collectedDues)?> | &#8377;<?=number_format($collectedDuesTotal)?></span></div>
<?php if ($collectedDues): ?>
<table class="rr-tbl"><thead><tr><th class="num">#</th><th style="text-align:left">Visitor</th><th style="text-align:left">Member</th><th>Meeting Sunday</th><th>Collected Date</th><th>Mode</th><th style="text-align:right">Amount</th></tr></thead><tbody>
<?php foreach ($collectedDues as $i => $d): ?><tr><td class="num"><?=$i+1?></td><td><?=htmlspecialchars($d['visitor_name'])?></td><td><strong><?=htmlspecialchars($d['member_name'])?></strong></td><td style="text-align:center"><?=date('d M Y', strtotime($d['friday_date']))?></td><td style="text-align:center"><?=date('d M Y', strtotime($d['paid_date']))?></td><td style="text-align:center"><span class="rr-badge"><?=htmlspecialchars(hm_range_mode($d['payment_method'] ?: 'Cash'))?></span></td><td class="amt" style="color:#1b5e20">&#8377;<?=number_format($d['amount'])?></td></tr><?php endforeach; ?>
<tr style="background:#e8f5e9"><td colspan="6" style="text-align:right;font-weight:800;color:#1b5e20">Total visitor dues collected</td><td class="amt" style="color:#1b5e20">&#8377;<?=number_format($collectedDuesTotal)?></td></tr>
</tbody></table>
<?php else: ?><div class="rr-empty">No visitor dues collected in this date range</div><?php endif; ?>

<div class="rr-sec">Visitor Dues Pending <span class="rr-total"><?=count($visitorDues)?> | &#8377;<?=number_format($visitorDueTotal)?></span></div>
<?php if ($visitorDues): ?>
<table class="rr-tbl"><thead><tr><th class="num">#</th><th style="text-align:left">Visitor</th><th style="text-align:left">Collect From</th><th>Entry Date</th><th style="text-align:right">Amount</th></tr></thead><tbody>
<?php $di=0; foreach ($duesByMember as $memberName => $rows): ?><tr class="rr-member-row"><td colspan="5"><?=htmlspecialchars($memberName)?> <span style="font-weight:400">(<?=count($rows)?>)</span></td></tr><?php foreach ($rows as $d): $di++; ?><tr><td class="num"><?=$di?></td><td><?=htmlspecialchars($d['visitor_name'])?></td><td style="font-weight:800;color:#6a1b9a"><?=htmlspecialchars($memberName)?></td><td style="text-align:center"><?=date('d M Y', strtotime($d['entry_date']))?></td><td class="amt" style="color:#6a1b9a">&#8377;<?=number_format($d['amount'])?></td></tr><?php endforeach; endforeach; ?>
</tbody></table>
<?php else: ?><div class="rr-empty">No pending visitor dues in this date range</div><?php endif; ?>

</div>
</div>

<div class="no-print" id="rangeVisitorEditBg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)closeRangeVisitorEdit()">
  <div style="background:#fff;border-radius:10px;padding:18px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 id="rve_title" style="font-size:1rem;margin:0 0 12px;font-weight:800">Edit Visitor</h3>
    <input type="hidden" id="rve_id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <label style="font-size:.75rem;font-weight:700">Visitor Name<input id="rve_name" type="text" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Profession<input id="rve_prof" type="text" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Member<select id="rve_member" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"><option value="">Select member</option><?php foreach ($allRangeMembers as $m): ?><option value="<?=(int)$m['id']?>"><?=htmlspecialchars($m['name'])?></option><?php endforeach; ?></select></label>
      <label style="font-size:.75rem;font-weight:700">Mode<select id="rve_mode" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"><option value="Cash">Cash</option><option value="UPI">QR Code (UPI)</option><option value="Card">Card</option><option value="FinCloud">FinCloud</option><option value="Pending-Member">Pending-Member</option></select></label>
      <label style="font-size:.75rem;font-weight:700">Sunday Date<input id="rve_Sunday" type="date" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Entry/Paid Date<input id="rve_paid" type="date" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Amount<input id="rve_amount" type="number" min="1" step="1" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Status<select id="rve_status" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"><option value="Paid">Paid</option><option value="Pending">Pending</option></select></label>
    </div>
    <div id="rve_err" style="display:none;margin-top:10px;background:#fff0f2;color:#b00020;border-radius:7px;padding:8px;font-size:.82rem"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px"><button type="button" onclick="closeRangeVisitorEdit()" class="btn-edt">Cancel</button><button type="button" onclick="saveRangeVisitorEdit()" class="btn-exp">Save</button></div>
  </div>
</div>

<script>
function setRangePrintMode(mode) {
    document.body.classList.remove('range-print-80mm','range-print-a4');
    document.body.classList.add('range-print-' + mode);
    var old = document.getElementById('hm_range_page_style');
    if (old) old.remove();
    var s = document.createElement('style');
    s.id = 'hm_range_page_style';
    s.textContent = mode === 'a4'
        ? '@media print { @page { size: A4 portrait; margin: 12mm 15mm; } }'
        : '@media print { @page { size: 80mm auto; margin: 4mm 3mm; } }';
    document.head.appendChild(s);
}
setRangePrintMode('a4');

function openRangeVisitorAdd() {
    document.getElementById('rve_title').textContent = 'Add Visitor';
    document.getElementById('rve_id').value = '';
    document.getElementById('rve_name').value = '';
    document.getElementById('rve_prof').value = '';
    document.getElementById('rve_member').value = '';
    document.getElementById('rve_mode').value = 'Cash';
    document.getElementById('rve_Sunday').value = '<?=$rangeTo?>';
    document.getElementById('rve_paid').value = '<?=$rangeTo?>';
    document.getElementById('rve_amount').value = 1450;
    document.getElementById('rve_status').value = 'Paid';
    document.getElementById('rve_err').style.display = 'none';
    document.getElementById('rangeVisitorEditBg').style.display = 'flex';
}

function openRangeVisitorEdit(btn) {
    var row = JSON.parse(btn.getAttribute('data-row') || '{}');
    document.getElementById('rve_title').textContent = 'Edit Visitor';
    document.getElementById('rve_id').value = row.id || '';
    document.getElementById('rve_name').value = row.visitor_name || '';
    document.getElementById('rve_prof').value = row.visitor_profession || '';
    document.getElementById('rve_member').value = row.member_id || '';
    document.getElementById('rve_mode').value = row.payment_method || 'Cash';
    document.getElementById('rve_Sunday').value = row.friday_date || '<?=$rangeTo?>';
    document.getElementById('rve_paid').value = row.paid_date || '<?=$rangeTo?>';
    document.getElementById('rve_amount').value = row.amount || 1450;
    document.getElementById('rve_status').value = row.status || 'Paid';
    document.getElementById('rve_err').style.display = 'none';
    document.getElementById('rangeVisitorEditBg').style.display = 'flex';
}

function closeRangeVisitorEdit() {
    document.getElementById('rangeVisitorEditBg').style.display = 'none';
}

function saveRangeVisitorEdit() {
    var err = document.getElementById('rve_err');
    if (!document.getElementById('rve_name').value.trim()) { err.textContent = 'Visitor name is required.'; err.style.display = 'block'; return; }
    if (!document.getElementById('rve_member').value) { err.textContent = 'Please select the member.'; err.style.display = 'block'; return; }
    var fd = new FormData();
    fd.append('action', 'edit_visitor_txn');
    fd.append('id', document.getElementById('rve_id').value);
    fd.append('member_id', document.getElementById('rve_member').value);
    fd.append('visitor_name', document.getElementById('rve_name').value);
    fd.append('visitor_profession', document.getElementById('rve_prof').value);
    fd.append('friday_date', document.getElementById('rve_Sunday').value);
    fd.append('paid_date', document.getElementById('rve_paid').value);
    fd.append('amount', document.getElementById('rve_amount').value);
    fd.append('mode', document.getElementById('rve_mode').value);
    fd.append('status', document.getElementById('rve_status').value);
    fd.append('_csrf', _csrf);
    fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (!d.ok) { err.textContent = d.msg || 'Could not save visitor.'; err.style.display = 'block'; return; } location.reload(); })
        .catch(function(e){ err.textContent = 'Request failed: ' + e.message; err.style.display = 'block'; });
}

function deleteRangeVisitor(txnId) {
    if (!confirm('Delete this visitor from report and member card?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_visitor_txn');
    fd.append('id', txnId);
    fd.append('_csrf', _csrf);
    fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) { alert(d.msg || 'Could not delete visitor.'); return; }
            if (!d.deleted_rows) { alert('No database row was deleted. Please refresh and try again.'); return; }
            location.reload();
        })
        .catch(function(e){ alert('Request failed: ' + e.message); });
}
</script>
