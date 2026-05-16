<?php
// ═══════════════════════════════════════════════════════════════
// tab_summary.php — Full Summary with transaction details
// ═══════════════════════════════════════════════════════════════

// ── Overall stats ───────────────────────────────────────────
$_oq = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type=? AND status='Paid'");
$_oq->execute(['Member']);   $o_mcoll = (float)$_oq->fetchColumn();
$_oq->execute(['Visitor']);  $o_vcoll = (float)$_oq->fetchColumn();
$_oq->execute(['Observer']); $o_ocoll = (float)$_oq->fetchColumn();
$o_grand = $o_mcoll + $o_vcoll + $o_ocoll;

$o_modes = $pdo->query("SELECT payment_method, SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE status='Paid' GROUP BY payment_method ORDER BY total DESC")->fetchAll();
$o_weeks = (int)$pdo->query("SELECT COUNT(DISTINCT friday_date) FROM transactions WHERE type='Member' AND status='Paid'")->fetchColumn();
$o_visitors = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE type='Visitor' AND status='Paid'")->fetchColumn();
$o_observers = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE type='Observer' AND status='Paid'")->fetchColumn();

$kittyAllPaid2 = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM kitty_payments WHERE status='Paid'")->fetchColumn();
$fullPaid2 = count(array_filter($pdo->query("SELECT COALESCE(SUM(CASE WHEN k.status='Paid' THEN k.amount ELSE 0 END),0) AS p FROM members m LEFT JOIN kitty_payments k ON k.member_id=m.id WHERE m.status='Active' GROUP BY m.id")->fetchAll(PDO::FETCH_COLUMN), function($p){ return $p >= 3000; }));

// ── All member payments (session-level) ─────────────────────
$memPayments = $pdo->query("
    SELECT m.name, m.category,
           DATE(t.submitted_at) AS paid_date,
           t.payment_method,
           COALESCE(MAX(t.original_total), SUM(t.amount)) AS total_amount,
           COUNT(*) AS week_count,
           GROUP_CONCAT(t.friday_date ORDER BY t.friday_date ASC SEPARATOR ', ') AS Sundays
    FROM transactions t
    JOIN members m ON t.member_id = m.id
    WHERE t.type='Member' AND t.status='Paid'
    GROUP BY m.id, m.name, m.category, DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s'), t.payment_method
    ORDER BY t.submitted_at DESC
")->fetchAll();

// ── All visitor payments ────────────────────────────────────
$visPayments = $pdo->query("
    SELECT visitor_name, visitor_profession, referrer_name,
           amount, payment_method, friday_date,
           DATE(submitted_at) AS paid_date
    FROM transactions
    WHERE type='Visitor' AND status='Paid'
    ORDER BY submitted_at DESC
")->fetchAll();

// ── All observer payments ───────────────────────────────────
$obsPayments = $pdo->query("
    SELECT visitor_name, observer_chapter, observer_category,
           amount, payment_method, friday_date,
           DATE(submitted_at) AS paid_date
    FROM transactions
    WHERE type='Observer' AND status='Paid'
    ORDER BY submitted_at DESC
")->fetchAll();

// ── All kitty payments ──────────────────────────────────────
$kittyPayments = $pdo->query("
    SELECT m.name, m.category, k.amount, k.payment_method,
           k.notes, DATE(k.submitted_at) AS paid_date
    FROM kitty_payments k
    JOIN members m ON k.member_id = m.id
    WHERE k.status='Paid'
    ORDER BY k.submitted_at DESC
")->fetchAll();

// Search filter
$sq = trim($_GET['summary_search'] ?? '');
?>
<div class="content">

<!-- ═══ OVERALL STATS ═══ -->
<div class="row g-3 mb-3">
  <div class="col-8">
    <div class="scard">
      <div class="row g-2">
        <div class="col-3"><div style="background:#fff0f2;border-radius:8px;padding:10px;text-align:center"><div style="font-size:1.1rem;font-weight:800;color:var(--red)">₹<?=number_format($o_grand)?></div><div style="font-size:.63rem;color:var(--gry);text-transform:uppercase">Total Collection</div></div></div>
        <div class="col-2"><div style="background:#f0f7ff;border-radius:8px;padding:10px;text-align:center"><div style="font-size:1.1rem;font-weight:800;color:#1565c0"><?=$o_weeks?></div><div style="font-size:.63rem;color:var(--gry);text-transform:uppercase">Meetings</div></div></div>
        <div class="col-2"><div style="background:#f0faf0;border-radius:8px;padding:10px;text-align:center"><div style="font-size:1.1rem;font-weight:800;color:#1b5e20"><?=$o_visitors?></div><div style="font-size:.63rem;color:var(--gry);text-transform:uppercase">Visitors</div></div></div>
        <div class="col-2"><div style="background:#fdf7ff;border-radius:8px;padding:10px;text-align:center"><div style="font-size:1.1rem;font-weight:800;color:#6a1b9a">₹<?=number_format($kittyAllPaid2)?></div><div style="font-size:.63rem;color:var(--gry);text-transform:uppercase">Kitty Pool</div></div></div>
        <div class="col-3"><div style="background:#f8f8f8;border-radius:8px;padding:10px;text-align:center"><div style="font-size:1.1rem;font-weight:800"><?=$fullPaid2?>/<?=$total_active?></div><div style="font-size:.63rem;color:var(--gry);text-transform:uppercase">Kitty Full</div></div></div>
      </div>
    </div>
  </div>
  <div class="col-4">
    <div class="scard">
      <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:6px">Mode Breakdown (All Time)</div>
      <?php foreach($o_modes as $mr): ?>
      <div class="mode-row" style="padding:4px 0"><span style="font-size:.8rem"><?=$mr['payment_method']?> <span class="text-muted">(<?=$mr['cnt']?>)</span></span><span class="fw-bold" style="font-size:.8rem">₹<?=number_format($mr['total'])?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ SEARCH ═══ -->
<div style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
    <input type="text" id="sumSearch" placeholder="Search by name, category, mode..." value="<?=htmlspecialchars($sq)?>"
           style="flex:1;border:1.5px solid var(--bdr);border-radius:8px;padding:6px 12px;font-size:.82rem;outline:none"
           oninput="filterSummaryTables(this.value)">
    <span style="font-size:.72rem;color:var(--gry)">Showing all paid transactions</span>
</div>

<!-- ═══ MEMBER PAYMENTS ═══ -->
<div class="panel mb-3">
    <div class="panel-hdr" style="background:var(--red);display:flex;justify-content:space-between">
        <span>Member Payments (<?=count($memPayments)?>)</span>
        <span>₹<?=number_format($o_mcoll)?></span>
    </div>
    <div class="panel-body" style="max-height:400px;overflow-y:auto;padding:0">
        <table class="tbl" id="tbl_members">
            <thead><tr><th>#</th><th>Name</th><th>Category</th><th>Paid Date</th><th>Weeks</th><th>Sundays Covered</th><th>Mode</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach($memPayments as $i => $p): ?>
            <tr data-search="<?=strtolower($p['name'].' '.$p['category'].' '.$p['payment_method'])?>">
                <td class="text-muted"><?=$i+1?></td>
                <td><strong><?=htmlspecialchars($p['name'])?></strong></td>
                <td style="font-size:.75rem;color:var(--gry)"><?=htmlspecialchars($p['category']??'')?></td>
                <td><?=date('d M Y',strtotime($p['paid_date']))?></td>
                <td class="text-center"><?=$p['week_count']?></td>
                <td style="font-size:.72rem;color:var(--gry)"><?php
                    $fds = explode(', ', $p['Sundays']);
                    echo implode(', ', array_map(function($f){ return date('d M', strtotime(trim($f))); }, $fds));
                ?></td>
                <td><span class="badge-mode"><?=htmlspecialchars($p['payment_method'])?></span></td>
                <td style="text-align:right;font-weight:700;color:var(--red)">₹<?=number_format($p['total_amount'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ VISITOR PAYMENTS ═══ -->
<div class="panel mb-3">
    <div class="panel-hdr" style="background:#1565c0;display:flex;justify-content:space-between">
        <span>Visitor Payments (<?=count($visPayments)?>)</span>
        <span>₹<?=number_format($o_vcoll)?></span>
    </div>
    <div class="panel-body" style="max-height:350px;overflow-y:auto;padding:0">
        <table class="tbl" id="tbl_visitors">
            <thead><tr><th>#</th><th>Visitor Name</th><th>Profession</th><th>Referred By</th><th>Sunday Date</th><th>Paid Date</th><th>Mode</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach($visPayments as $i => $p): ?>
            <tr data-search="<?=strtolower($p['visitor_name'].' '.$p['referrer_name'].' '.$p['visitor_profession'].' '.$p['payment_method'])?>">
                <td class="text-muted"><?=$i+1?></td>
                <td><strong><?=htmlspecialchars($p['visitor_name'])?></strong></td>
                <td style="font-size:.75rem;color:var(--gry)"><?=htmlspecialchars($p['visitor_profession']??'')?></td>
                <td><?=htmlspecialchars($p['referrer_name']??'')?></td>
                <td><?=date('d M Y',strtotime($p['friday_date']))?></td>
                <td><?=date('d M Y',strtotime($p['paid_date']))?></td>
                <td><span class="badge-mode"><?=htmlspecialchars($p['payment_method'])?></span></td>
                <td style="text-align:right;font-weight:700;color:#1565c0">₹<?=number_format($p['amount'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ OBSERVER PAYMENTS ═══ -->
<?php if($obsPayments): ?>
<div class="panel mb-3">
    <div class="panel-hdr" style="background:#555;display:flex;justify-content:space-between">
        <span>Observer Payments (<?=count($obsPayments)?>)</span>
        <span>₹<?=number_format($o_ocoll)?></span>
    </div>
    <div class="panel-body" style="max-height:250px;overflow-y:auto;padding:0">
        <table class="tbl" id="tbl_observers">
            <thead><tr><th>#</th><th>Name</th><th>Chapter</th><th>Category</th><th>Sunday Date</th><th>Paid Date</th><th>Mode</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach($obsPayments as $i => $p): ?>
            <tr data-search="<?=strtolower($p['visitor_name'].' '.$p['observer_chapter'].' '.$p['payment_method'])?>">
                <td class="text-muted"><?=$i+1?></td>
                <td><strong><?=htmlspecialchars($p['visitor_name'])?></strong></td>
                <td><?=htmlspecialchars($p['observer_chapter']??'')?></td>
                <td style="font-size:.75rem;color:var(--gry)"><?=htmlspecialchars($p['observer_category']??'')?></td>
                <td><?=date('d M Y',strtotime($p['friday_date']))?></td>
                <td><?=date('d M Y',strtotime($p['paid_date']))?></td>
                <td><span class="badge-mode"><?=htmlspecialchars($p['payment_method'])?></span></td>
                <td style="text-align:right;font-weight:700">₹<?=number_format($p['amount'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ═══ KITTY PAYMENTS ═══ -->
<div class="panel mb-3">
    <div class="panel-hdr" style="background:#6a1b9a;display:flex;justify-content:space-between">
        <span>Kitty Payments (<?=count($kittyPayments)?>) — ₹<?=number_format($kittyAllPaid2)?> / ₹<?=number_format($total_active * 3000)?></span>
        <span>Outstanding: ₹<?=number_format(max(0, $total_active * 3000 - $kittyAllPaid2))?></span>
    </div>
    <div class="panel-body" style="max-height:300px;overflow-y:auto;padding:0">
        <table class="tbl" id="tbl_kitty">
            <thead><tr><th>#</th><th>Member</th><th>Category</th><th>Paid Date</th><th>Mode</th><th>Notes</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach($kittyPayments as $i => $p): ?>
            <tr data-search="<?=strtolower($p['name'].' '.$p['category'].' '.$p['payment_method'].' '.$p['notes'])?>">
                <td class="text-muted"><?=$i+1?></td>
                <td><strong><?=htmlspecialchars($p['name'])?></strong></td>
                <td style="font-size:.75rem;color:var(--gry)"><?=htmlspecialchars($p['category']??'')?></td>
                <td><?=date('d M Y',strtotime($p['paid_date']))?></td>
                <td><span class="badge-mode"><?=htmlspecialchars($p['payment_method'])?></span></td>
                <td style="font-size:.75rem;color:var(--gry)"><?=htmlspecialchars($p['notes']??'')?></td>
                <td style="text-align:right;font-weight:700;color:#6a1b9a">₹<?=number_format($p['amount'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ DENOMINATION COUNTER ═══ -->
<div class="row g-3">
    <div class="col-6">
        <div class="scard">
            <div class="lbl mb-3">Cash Denomination Counter</div>
            <div class="denom-grid">
                <?php foreach(['500'=>'₹500 notes','200'=>'₹200 notes','100'=>'₹100 notes','50'=>'₹50 notes'] as $val=>$lbl): ?>
                <div class="denom-card">
                    <div class="denom-note"><?=$lbl?></div>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <input type="number" class="denom-input" id="dn<?=$val?>" placeholder="0" min="0" oninput="calcDenom()" value="0">
                        <span class="text-muted">&times; <?=$val?></span>
                    </div>
                    <div class="denom-total mt-1" id="dt<?=$val?>">= ₹0</div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="background:var(--rlt);border:1.5px solid #ffd6dc;border-radius:10px;padding:12px;text-align:center">
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry)">Total Cash in Hand</div>
                <div style="font-size:1.5rem;font-weight:800;color:var(--red)" id="dtotal">₹0</div>
            </div>
        </div>
    </div>
</div>

<script>
function filterSummaryTables(q) {
    q = (q||'').toLowerCase();
    document.querySelectorAll('#tbl_members tbody tr, #tbl_visitors tbody tr, #tbl_observers tbody tr, #tbl_kitty tbody tr').forEach(function(r){
        r.style.display = (!q || (r.dataset.search||'').indexOf(q) >= 0) ? '' : 'none';
    });
}
</script>
</div>
