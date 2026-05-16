<?php
require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/report_calc_helper.php';
require_once __DIR__ . '/name_match_helper.php';
ini_set('display_errors', 0);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo '<h2>Missing member ID.</h2>';
    exit;
}

// ── Member info ───────────────────────────────────────────────────────────────
$mQ = $pdo->prepare("SELECT * FROM members WHERE id=? LIMIT 1");
$mQ->execute([$id]);
$mem = $mQ->fetch(PDO::FETCH_ASSOC);
if (!$mem) {
    echo '<h2>Member not found.</h2>';
    exit;
}

// ── Recalculate weekly payments with session carry-over ───────────────────────
$calc = recalc_member_payments($pdo, $id);
$memPaid      = $calc['totalPaid'];
$memWeeks     = $calc['fullWeeks'];
$partialWeeks = $calc['partialWeeks'];
$byMonth      = $calc['byMonth'];
$sessions     = $calc['sessions'];
$SundayData   = $calc['Sundays'];

// ── Pending amount (from raw transactions) ────────────────────────────────────
$pendQ = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE member_id=? AND type='Member' AND status='Pending'");
$pendQ->execute([$id]);
$memPending = (int)$pendQ->fetchColumn();

// ── Visitors referred by this member ─────────────────────────────────────────
$nameExprT = hm_member_name_sql_expr('t.referrer_name');
$nameExprM = hm_member_name_sql_expr('m.name');
$vQ = $pdo->prepare("
    SELECT t.id, t.visitor_name, t.visitor_profession, t.visitor_company,
           t.amount, COALESCE(vd.payment_method, t.payment_method) AS payment_method, t.status AS txn_status, t.friday_date,
           vd.status AS due_status, vd.id AS due_id
    FROM transactions t
    JOIN members m ON m.id = ?
    LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
    WHERE t.type='Visitor'
      AND (t.member_id = m.id OR ($nameExprT = $nameExprM AND t.referrer_name <> ''))
    ORDER BY t.friday_date DESC, t.submitted_at DESC
");
$vQ->execute([$id]);
$visitors = $vQ->fetchAll(PDO::FETCH_ASSOC);

$cdQ = $pdo->prepare("
    SELECT vd.visitor_name, vd.amount, vd.payment_method, DATE(vd.paid_at) AS paid_date,
           t.friday_date
    FROM visitor_dues vd
    JOIN transactions t ON t.id = vd.txn_id
WHERE vd.member_id = ?
      AND vd.status = 'Paid'
      AND vd.paid_at IS NOT NULL
    UNION ALL
    SELECT t.visitor_name, t.amount, t.payment_method, DATE(t.submitted_at) AS paid_date,
           t.friday_date
    FROM transactions t
    LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
    JOIN members m ON m.id = ?
    WHERE t.type = 'Visitor'
      AND t.status = 'Paid'
      AND vd.id IS NULL
      AND DATE(t.submitted_at) > t.friday_date
      AND (t.member_id = m.id OR ($nameExprT = $nameExprM AND t.referrer_name <> ''))
    ORDER BY paid_date DESC, friday_date DESC, visitor_name ASC
");
$cdQ->execute([$id, $id]);
$collectedDues = $cdQ->fetchAll(PDO::FETCH_ASSOC);
$collectedDuesTotal = array_sum(array_column($collectedDues, 'amount'));

// ── Kitty payments ────────────────────────────────────────────────────────────
$kQ = $pdo->prepare("
    SELECT amount, payment_method, status, notes, submitted_at
    FROM kitty_payments
    WHERE member_id=?
    ORDER BY submitted_at ASC
");
$kQ->execute([$id]);
$kittyTxns = $kQ->fetchAll(PDO::FETCH_ASSOC);

// ── Kitty totals ──────────────────────────────────────────────────────────────
$kittyPaid = 0;
$kittyPending = 0;
foreach ($kittyTxns as $k) {
    if ($k['status'] === 'Paid') $kittyPaid += (int)$k['amount'];
    elseif ($k['status'] === 'Pending') $kittyPending += (int)$k['amount'];
}
$kittyBal = max(0, 3000 - $kittyPaid);

// Visitor totals
$visPaid = 0; $visTot = 0; $visDue = 0; $visDueTot = 0; $visPending = 0; $visPendingTot = 0;
foreach ($visitors as $v) {
    $actuallyPaid = ($v['txn_status'] === 'Paid') && ($v['due_status'] !== 'Pending');
    $isDue = ($v['txn_status'] === 'Paid') && ($v['due_status'] === 'Pending');
    $isPending = ($v['txn_status'] === 'Pending');
    if ($actuallyPaid) { $visPaid++; $visTot += (int)$v['amount']; }
    if ($isDue) { $visDue++; $visDueTot += (int)$v['amount']; }
    if ($isPending) { $visPending++; $visPendingTot += (int)$v['amount']; }
}

$printDate = date('d M Y, h:i A');

// ── Due weeks (using recalculated data) ──────────────────────────────────────
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
$fullyPaidSundays = [];
foreach ($SundayData as $fd => $data) {
    if ($data['status'] === 'Paid' && $data['balance'] <= 0) {
        $fullyPaidSundays[$fd] = true;
    }
}
$dueWeeksCount = 0;
foreach ($allSundays as $fd) {
    if (!isset($fullyPaidSundays[$fd])) $dueWeeksCount++;
}
$totalOutstanding = ($dueWeeksCount * 1450) + $kittyBal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Member Report PDF</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#000;margin:0;padding:0}
.report-wrap{width:100%;max-width:800px;margin:0 auto}
.head-table,.info-table,.stats-table,.data-table,.summary-table{width:100%;border-collapse:collapse;margin-bottom:12px}
.head-table td{vertical-align:top}
.info-table td,.stats-table td,.data-table th,.data-table td,.summary-table td{border:1px solid #999;padding:6px}
.data-table th{background:#eaeaea;font-weight:bold;text-align:center}
.section-title{background:#222;color:#fff;font-weight:bold;padding:6px 8px;margin:14px 0 6px 0}
.section-title.amber{background:#c47800}
.section-title.blue{background:#1565c0}
.section-title.green{background:#2e7d32;color:#fff}
.small{font-size:10px;color:#444}
.right{text-align:right}
.center{text-align:center}
.bold{font-weight:bold}
.red{color:#c62828}
.green{color:#1b5e20}
.orange{color:#c47800}
.blue-text{color:#1565c0}
.note-box{border:1px solid #c62828;padding:8px;margin-bottom:10px}
.footer{margin-top:18px;font-size:10px;color:#666;text-align:center}
</style>
</head>
<body>
<div class="report-wrap">

    <table class="head-table">
        <tr>
            <td>
                <h1 style="color:#D90429; margin:0">Miracle Morning</h1>
                <div class="small">Coimbatore Chapter | Member Payment Report</div>
            </td>
            <td class="right">
                <div>Printed: <?=htmlspecialchars($printDate)?></div>
                <div>Member ID: #<?= (int)$id ?></div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td><span class="small">Member Name</span><br><span class="bold"><?=htmlspecialchars($mem['name'])?></span></td>
            <td><span class="small">Company</span><br><span class="bold"><?=htmlspecialchars($mem['company_name'] ?? '—')?></span></td>
            <td><span class="small">Category</span><br><span class="bold"><?=htmlspecialchars($mem['category'] ?? '—')?></span></td>
        </tr>
        <tr>
            <td><span class="small">Mobile</span><br><span class="bold"><?=htmlspecialchars($mem['mobile'] ?? '—')?></span></td>
            <td><span class="small">Email</span><br><span class="bold"><?=htmlspecialchars($mem['email'] ?? '—')?></span></td>
            <td><span class="small">Status</span><br><span class="bold"><?=htmlspecialchars($mem['status'] ?? '—')?></span></td>
        </tr>
    </table>

    <table class="stats-table">
        <tr>
            <td class="center"><div class="bold"><?= $memWeeks ?></div><div class="small">Weeks Paid</div></td>
            <td class="center"><div class="bold red"><?= $dueWeeksCount ?></div><div class="small">Weeks Due</div></td>
            <td class="center"><div class="bold">₹<?= number_format($memPaid) ?></div><div class="small">Fee Collected</div></td>
            <td class="center"><div class="bold">₹<?= number_format($kittyPaid) ?></div><div class="small">Kitty Paid</div></td>
            <td class="center"><div class="bold blue-text"><?= $visPaid ?></div><div class="small">Visitors Brought</div></td>
        </tr>
    </table>

    <?php if ($totalOutstanding > 0): ?>
        <div class="note-box">
            <div class="bold">Total Outstanding</div>
            <div class="small">Weekly Due (<?= $dueWeeksCount ?> weeks × ₹1,450) + Kitty Due</div>
            <div class="right bold red" style="margin-top:6px;">
                ₹<?= number_format($dueWeeksCount * 1450) ?> + ₹<?= number_format($kittyBal) ?> = ₹<?= number_format($totalOutstanding) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── PAYMENT SESSIONS ─────────────────────────────────────────── -->
    <?php if ($sessions): ?>
    <div class="section-title green">0. PAYMENT SESSIONS</div>
    <div class="small" style="margin-bottom:6px;">Each row is one payment made by the member. Amounts carry over to fill partial weeks.</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Paid Date</th>
                <th>Weeks</th>
                <th>Sundays Covered</th>
                <th>Mode</th>
                <th>Status</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
            <tr>
                <td class="center"><?= date('d M Y', strtotime($s['paid_date'])) ?></td>
                <td class="center bold"><?= (int)($s['applied_week_count'] ?? $s['week_count']) ?></td>
                <td class="center" style="font-size:10px;"><?php
                    if (!empty($s['applied_Sundays_label'])) {
                        echo htmlspecialchars($s['applied_Sundays_label']);
                    } else {
                        $fds = explode(',', $s['Sundays']);
                        echo implode(', ', array_map(function($f){ return date('d M', strtotime(trim($f))); }, $fds));
                    }
                ?></td>
                <td class="center"><?= htmlspecialchars($s['payment_method']) ?></td>
                <td class="center" style="color:<?= $s['status']==='Paid'?'#1b5e20':($s['status']==='Pending'?'#c47800':'#c62828') ?>;font-weight:bold">
                    <?= htmlspecialchars($s['status']) ?>
                </td>
                <td class="right bold" style="color:#D90429;">₹<?= number_format((int)$s['total_amount']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <table class="summary-table">
        <tr>
            <td class="right">Total sessions</td>
            <td class="center bold"><?= count($sessions) ?></td>
            <td class="right">Total collected</td>
            <td class="right bold">₹<?= number_format($memPaid) ?></td>
        </tr>
    </table>
    <?php endif; ?>

    <div class="section-title">1. WEEKLY MEETING FEE HISTORY</div>

    <?php if ($byMonth): ?>
        <?php foreach ($byMonth as $mLabel => $mRows):
            $mPaid = 0; $mWks = 0;
            foreach ($mRows as $r) {
                $mPaid += $r['amount_paid'];
                if ($r['balance'] <= 0) $mWks++;
            }
        ?>
            <div class="bold" style="margin:8px 0 4px 0;"><?= htmlspecialchars($mLabel) ?> — <?= $mWks ?> week<?= $mWks != 1 ? 's' : '' ?> · ₹<?= number_format($mPaid) ?></div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sunday Date</th>
                        <th>Status</th>
                        <th>Mode</th>
                        <th>Amount</th>
                        <th>Paid On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mRows as $r):
                        if ($r['status'] === 'Paid' && $r['balance'] <= 0) {
                            $ss = 'Paid';
                        } elseif ($r['status'] === 'Paid' && $r['balance'] > 0) {
                            $ss = 'Partial (Bal: ₹' . number_format($r['balance']) . ')';
                        } elseif ($r['status'] === 'Pending') {
                            $isPast = $r['friday_date'] < $today;
                            $ss = $isPast ? 'Due' : 'Pending';
                        } else {
                            $ss = $r['status'];
                        }
                    ?>
                        <tr>
                            <td class="center"><?= date('d M Y', strtotime($r['friday_date'])) ?></td>
                            <td class="center"><?= htmlspecialchars($ss) ?></td>
                            <td class="center"><?= htmlspecialchars($r['payment_method'] ?? '—') ?></td>
                            <td class="right bold">₹<?= number_format($r['amount_paid']) ?></td>
                            <td class="center"><?= $r['paid_date'] ? date('d M Y', strtotime($r['paid_date'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <table class="summary-table">
            <tr>
                <td class="right">Total weeks paid</td>
                <td class="center bold"><?= $memWeeks ?></td>
                <td class="right">Total collected</td>
                <td class="right bold">₹<?= number_format($memPaid) ?></td>
            </tr>
            <?php if ($memPending > 0): ?>
                <tr>
                    <td colspan="3" class="right orange">Pending approval</td>
                    <td class="right bold orange">₹<?= number_format($memPending) ?></td>
                </tr>
            <?php endif; ?>
        </table>
    <?php else: ?>
        <div class="small" style="margin-bottom:12px;">No weekly fee payments recorded.</div>
    <?php endif; ?>

    <div class="section-title amber">2. KITTY CASH PAYMENTS</div>

    <div style="margin-bottom:8px;">
        <span class="bold">Kitty Paid:</span> ₹<?= number_format($kittyPaid) ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <span class="bold">Kitty Target:</span> ₹3,000
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <span class="bold">Balance:</span> <?= $kittyBal > 0 ? '₹' . number_format($kittyBal) : 'Fully Paid' ?>
    </div>

    <?php if ($kittyTxns): ?>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Mode</th><th>Status</th><th>Amount</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($kittyTxns as $k): ?>
                <tr>
                    <td class="center"><?= date('d M Y', strtotime($k['submitted_at'])) ?></td>
                    <td class="center"><?= htmlspecialchars($k['payment_method'] ?? '—') ?></td>
                    <td class="center"><?= htmlspecialchars($k['status'] ?? '—') ?></td>
                    <td class="right bold">₹<?= number_format((int)$k['amount']) ?></td>
                    <td><?= htmlspecialchars($k['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <table class="summary-table">
            <tr>
                <td class="right">Total paid</td>
                <td class="right bold">₹<?= number_format($kittyPaid) ?></td>
                <td class="right">Balance</td>
                <td class="right bold"><?= $kittyBal > 0 ? '₹' . number_format($kittyBal) : 'Fully Paid' ?></td>
            </tr>
        </table>
    <?php else: ?>
        <div class="small" style="margin-bottom:12px;">No kitty payments recorded.</div>
    <?php endif; ?>

    <div class="section-title blue">3. VISITORS REFERRED BY THIS MEMBER</div>

    <?php if ($visPending > 0 || $visDue > 0): ?>
        <div class="note-box">
            <?php if ($visPending > 0): ?>
                <span class="bold orange">
                    <?= $visPending ?> visitor entr<?= $visPending > 1 ? 'ies' : 'y' ?> pending — ₹<?= number_format($visPendingTot) ?>
                </span>
            <?php endif; ?>
            <?php if ($visPending > 0 && $visDue > 0): ?><br><?php endif; ?>
            <?php if ($visDue > 0): ?>
                <span class="bold red">
                    <?= $visDue ?> visitor fee<?= $visDue > 1 ? 's' : '' ?> due from member — ₹<?= number_format($visDueTot) ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($visitors): ?>
        <table class="data-table">
            <thead><tr><th>#</th><th>Visitor Name</th><th>Category</th><th>Meeting Sunday</th><th>Mode</th><th>Status</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($visitors as $vi => $v):
                    $isDue = ($v['txn_status'] === 'Paid') && ($v['due_status'] === 'Pending');
                    $isCollected = ($v['txn_status'] === 'Paid') && ($v['due_status'] !== 'Pending');
                    if ($isDue) $vss = 'Due from Member';
                    elseif ($isCollected) $vss = 'Paid';
                    elseif ($v['txn_status'] === 'Pending') $vss = 'Pending';
                    else $vss = 'Rejected';
                ?>
                <tr>
                    <td class="center"><?= $vi + 1 ?></td>
                    <td>
                        <span class="bold"><?= htmlspecialchars($v['visitor_name']) ?></span>
                        <?php if (!empty($v['visitor_company'])): ?><br><span class="small"><?= htmlspecialchars($v['visitor_company']) ?></span><?php endif; ?>
                    </td>
                    <td class="center"><?= htmlspecialchars($v['visitor_profession'] ?? '—') ?></td>
                    <td class="center"><?= date('d M Y', strtotime($v['friday_date'])) ?></td>
                    <td class="center"><?= htmlspecialchars($v['payment_method'] ?? '—') ?></td>
                    <td class="center"><?= htmlspecialchars($vss) ?></td>
                    <td class="right bold">₹<?= number_format((int)$v['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <table class="summary-table">
            <tr>
                <td class="right">Total visitors</td>
                <td class="center bold"><?= count($visitors) ?></td>
                <td class="right">Collected</td>
                <td class="right bold">₹<?= number_format($visTot) ?></td>
                <?php if ($visPending > 0): ?>
                    <td class="right orange">Pending</td>
                    <td class="right bold orange">₹<?= number_format($visPendingTot) ?></td>
                <?php endif; ?>
                <?php if ($visDue > 0): ?>
                    <td class="right red">Due from member</td>
                    <td class="right bold red">₹<?= number_format($visDueTot) ?></td>
                <?php endif; ?>
            </tr>
        </table>
    <?php else: ?>
        <div class="small">No visitors referred yet.</div>
    <?php endif; ?>

    <div class="section-title green">4. VISITOR DUES COLLECTED FROM THIS MEMBER</div>
    <?php if ($collectedDues): ?>
        <table class="data-table">
            <thead><tr><th>#</th><th>Visitor Name</th><th>Meeting Sunday</th><th>Collected Date</th><th>Mode</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($collectedDues as $ci => $d): ?>
                <tr>
                    <td class="center"><?= $ci + 1 ?></td>
                    <td><span class="bold"><?= htmlspecialchars($d['visitor_name']) ?></span></td>
                    <td class="center"><?= date('d M Y', strtotime($d['friday_date'])) ?></td>
                    <td class="center"><?= date('d M Y', strtotime($d['paid_date'])) ?></td>
                    <td class="center"><?= htmlspecialchars($d['payment_method'] ?: 'Cash') ?></td>
                    <td class="right bold">â‚¹<?= number_format((int)$d['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <table class="summary-table">
            <tr>
                <td class="right">Total visitor dues collected</td>
                <td class="right bold">â‚¹<?= number_format($collectedDuesTotal) ?></td>
            </tr>
        </table>
    <?php else: ?>
        <div class="small">No visitor dues collected from this member yet.</div>
    <?php endif; ?>

    <div class="footer">
        Miracle Morning · Coimbatore Chapter<br>
        Generated: <?= htmlspecialchars($printDate) ?><br>
        Member: <?= htmlspecialchars($mem['name']) ?> (#<?= (int)$id ?>)
    </div>
</div>
</body>
</html>
