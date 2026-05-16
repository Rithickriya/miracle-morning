<?php
// tab_live.php — Live Desk
// Two modes: exact date (default) or weekly (Sunday payment)

// ── Determine mode (exact or weekly) ───────────────────────────────────────
$mode = isset($_GET['view']) && $_GET['view'] === 'weekly' ? 'weekly' : 'exact';

// ── $sel is already set by the main dashboard (via $_GET['date']) ──
// For weekly mode, we need to snap to Sunday and compute week range
if ($mode === 'weekly') {
    $tmpDate = new DateTime($sel);
    if ((int)$tmpDate->format('N') !== 7) {
        $tmpDate->modify('next sunday');
        $sel = $tmpDate->format('Y-m-d');
    }
    $weekStart = date('Y-m-d', strtotime($sel . ' -6 days'));
    $weekEnd   = $sel;
} else {
    // Exact mode: use the selected date as-is
    // Still compute week range (Mon-Sun) for the Visitor Dues Collected section
    $selDow = (int)date('N', strtotime($sel)); // 1=Mon … 7=Sun
    // Snap to the Sunday that ends this week
    $selSunday = ($selDow === 7) ? $sel : date('Y-m-d', strtotime($sel . ' next sunday'));
    $weekStart = date('Y-m-d', strtotime($selSunday . ' -6 days')); // Monday
    $weekEnd   = $selSunday;                                          // Sunday
}

// ── Member list for JS ──────────────────────────────────────────────────────
$memList = [];
try {
    $q = $pdo->query("SELECT id, name, company_name FROM members WHERE status='Active' ORDER BY name ASC");
    if ($q) $memList = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$mlJson = json_encode(array_map(function($m){
    return ['id'=>(int)$m['id'], 'n'=>$m['name'], 'c'=>($m['company_name']??'')];
}, $memList));

// ── (Queue queries moved to tab_queue.php) ──────────────────────────────────

// ── Paid members ─────────────────────────────────────────────────────────────
$mpaidRows = [];
try {
    if ($mode === 'weekly') {
        $q = $pdo->prepare("
            SELECT
                MIN(t.id) AS id,
                m.id AS member_id,
                m.name,
                m.category,
                MIN(t.payment_method) AS payment_method,
                MAX(t.verified_at) AS verified_at,
                COALESCE(MAX(t.original_total), SUM(t.amount)) AS total_session,
                MIN(t.friday_date) AS friday_date,
                MIN(t.submitted_at) AS submitted_at
            FROM transactions t
            JOIN members m ON t.member_id = m.id
            WHERE t.type = 'Member'
              AND t.status = 'Paid'
              AND (
                  EXISTS (
                      SELECT 1 FROM transactions t2
                      WHERE t2.member_id = t.member_id
                        AND t2.submitted_at = t.submitted_at
                        AND t2.friday_date = ?
                  )
                  OR DATE(t.submitted_at) BETWEEN ? AND ?
              )
            GROUP BY m.id, DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s')
            ORDER BY MAX(t.verified_at) DESC
        ");
        $q->execute([$sel, $weekStart, $weekEnd]);
    } else {
        $q = $pdo->prepare("
            SELECT 
                MIN(t.id) AS id,
                m.id AS member_id,
                m.name,
                m.category,
                MIN(t.payment_method) AS payment_method,
                MAX(t.verified_at) AS verified_at,
                COALESCE(MAX(t.original_total), SUM(t.amount)) AS total_session,
                MIN(t.friday_date) AS friday_date,
                MIN(t.submitted_at) AS submitted_at
            FROM transactions t
            JOIN members m ON t.member_id = m.id
            WHERE t.type = 'Member'
              AND t.status = 'Paid'
              AND DATE(t.submitted_at) = ?
            GROUP BY m.id, DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s')
            ORDER BY MAX(t.verified_at) DESC
        ");
        $q->execute([$sel]);
    }
    $mpaidRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Paid guests (Visitors + Observers) ───────────────────────────────────────
$gpaidRows = [];
try {
    if ($mode === 'weekly') {
        $q = $pdo->prepare("
            SELECT t.id, t.member_id, t.visitor_name, t.type, COALESCE(vd.payment_method, t.payment_method) AS payment_method,
                   t.referrer_name, t.visitor_profession, t.observer_chapter,
                   t.observer_category, t.amount, t.status, t.friday_date,
                   COALESCE(DATE(vd.paid_at), DATE(t.submitted_at)) AS paid_date, t.verified_at,
                   vd.id AS due_id, vd.status AS due_status
            FROM transactions t
            LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
            WHERE t.type IN ('Visitor','Observer')
              AND t.status = 'Paid'
              AND (
                    (t.type = 'Observer' AND (t.friday_date = ? OR DATE(t.submitted_at) BETWEEN ? AND ?))
                    OR (t.type = 'Visitor' AND t.friday_date = ? AND (vd.id IS NULL OR vd.status = 'Paid') AND COALESCE(t.payment_method,'') <> 'Pending-Member')
                  )
            ORDER BY t.type ASC, t.verified_at DESC
        ");
        $q->execute([$sel, $weekStart, $weekEnd, $sel]);
    } else {
        $q = $pdo->prepare("
            SELECT t.id, t.member_id, t.visitor_name, t.type, COALESCE(vd.payment_method, t.payment_method) AS payment_method,
                   t.referrer_name, t.visitor_profession, t.observer_chapter,
                   t.observer_category, t.amount, t.status, t.friday_date,
                   COALESCE(DATE(vd.paid_at), DATE(t.submitted_at)) AS paid_date, t.verified_at,
                   vd.id AS due_id, vd.status AS due_status
            FROM transactions t
            LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
            WHERE t.type IN ('Visitor','Observer')
              AND t.status = 'Paid'
              AND (
                    (t.type = 'Observer' AND (t.friday_date = ? OR DATE(t.submitted_at) = ?))
                    OR (t.type = 'Visitor' AND t.friday_date = ? AND (vd.id IS NULL OR vd.status = 'Paid') AND COALESCE(t.payment_method,'') <> 'Pending-Member')
                  )
            ORDER BY t.type ASC, t.verified_at DESC
        ");
        $q->execute([$sel, $sel, $sel]);
    }
    $gpaidRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$guestsTotal = array_sum(array_column($gpaidRows, 'amount'));

$collectedDuesRows = [];
try {
    // Same query as tab_print.php which is proven to work
    $dq = $pdo->prepare("
        SELECT vd.id, t.id AS txn_id, COALESCE(vd.visitor_name, t.visitor_name) AS visitor_name,
               COALESCE(vd.payment_method, t.payment_method) AS payment_method,
               DATE(vd.paid_at) AS paid_date,
               t.friday_date, COALESCE(vd.member_id, t.member_id) AS member_id,
               COALESCE(m.name, mt.name, t.referrer_name, 'Unmatched') AS member_name,
               vd.amount, t.visitor_profession
        FROM visitor_dues vd
        JOIN transactions t ON t.id = vd.txn_id
        LEFT JOIN members m  ON m.id = vd.member_id
        LEFT JOIN members mt ON mt.id = t.member_id
        WHERE vd.status = 'Paid'
          AND vd.paid_at IS NOT NULL
          AND DATE(vd.paid_at) BETWEEN ? AND ?
          AND t.friday_date < ?
        ORDER BY member_name ASC, friday_date ASC, visitor_name ASC
    ");
    $dq->execute([$weekStart, $weekEnd, $weekStart]);
    $collectedDuesRows = $dq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $collectedDuesRows = []; }
$collectedDuesTotal = array_sum(array_column($collectedDuesRows, 'amount'));

// ── Kitty payments ───────────────────────────────────────────────────────────
$kittyWeekly = [];
try {
    if ($mode === 'weekly') {
        $kq = $pdo->prepare("
            SELECT k.id, m.name, m.company_name, k.amount, k.payment_method, k.submitted_at
            FROM kitty_payments k
            JOIN members m ON k.member_id = m.id
            WHERE k.status = 'Paid'
              AND DATE(k.submitted_at) BETWEEN ? AND ?
            ORDER BY k.submitted_at DESC
        ");
        $kq->execute([$weekStart, $weekEnd]);
    } else {
        $kq = $pdo->prepare("
            SELECT k.id, m.name, m.company_name, k.amount, k.payment_method, k.submitted_at
            FROM kitty_payments k
            JOIN members m ON k.member_id = m.id
            WHERE k.status = 'Paid'
              AND DATE(k.submitted_at) = ?
            ORDER BY k.submitted_at DESC
        ");
        $kq->execute([$sel]);
    }
    $kittyWeekly = $kq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $kittyWeekly = []; }
$kittyWeekTotal = array_sum(array_column($kittyWeekly, 'amount'));

// Totals for summary bar
$membersCount = count($mpaidRows);
$membersTotal = 0;
foreach ($mpaidRows as $r) $membersTotal += (float)($r['total_session'] ?? $r['amount'] ?? 0);
$todayTotal = $membersTotal + $kittyWeekTotal + $guestsTotal + $collectedDuesTotal;

// Helper to toggle mode link
$toggleMode = $mode === 'weekly' ? 'exact' : 'weekly';
$toggleLabel = $mode === 'weekly' ? 'Switch to Exact Date View' : 'Switch to Sunday Payment (Weekly) View';
$toggleUrl = '?date=' . urlencode($_GET['date'] ?? $sel) . '&tab=live&view=' . $toggleMode;
?>
<script>var ML = <?=$mlJson?>;</script>

<style>
/* ─────────────────────────────────────────────────────────────────────────
   LIVE DESK — layout rules
   Top row  : Verification Queue 20% | Paid Members 40% | Guests 40%
              — fills viewport height, each column scrolls internally
   Bottom row: Kitty Cash + Total — natural flow, visible on scroll down
───────────────────────────────────────────────────────────────────────── */

/* Outer shell: full-page scroll container */
.content.live-shell {
  height: calc(100vh - 96px);
  overflow-y: auto;
  overflow-x: hidden;
  padding: 12px 16px 18px;
  box-sizing: border-box;
}

/* ── Shared scrollbars ── */
.live-shell,
.live-vscroll,
.live-hscroll {
  scrollbar-width: thin;
  scrollbar-color: #bdbdbd transparent;
}
.live-shell::-webkit-scrollbar,
.live-vscroll::-webkit-scrollbar,
.live-hscroll::-webkit-scrollbar { width: 5px; height: 5px; }
.live-shell::-webkit-scrollbar-thumb,
.live-vscroll::-webkit-scrollbar-thumb,
.live-hscroll::-webkit-scrollbar-thumb { background: #bdbdbd; border-radius: 6px; }
.live-shell::-webkit-scrollbar-track,
.live-vscroll::-webkit-scrollbar-track,
.live-hscroll::-webkit-scrollbar-track { background: transparent; }

/* ── Toolbar ── */
.live-toolbar {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  margin-bottom: 10px;
  flex-shrink: 0;
}

/* ── TOP ROW — fills viewport minus toolbar (~92vh), no wrap ── */
.live-top-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
  height: min(560px, calc(100vh - 170px));
  min-height: 360px;
  margin-bottom: 12px;
  box-sizing: border-box;
}

/* ── BOTTOM ROW — natural height, shows on scroll ── */
.live-bottom-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1.15fr) minmax(280px, .7fr);
  gap: 12px;
  align-items: stretch;
  width: 100%;
  box-sizing: border-box;
}

/* ── Column widths — 50 / 50 (queue moved to separate tab) ── */
.live-col-members {
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}
.live-col-guests {
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}

/* Bottom row columns */
.live-col-wide {
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}
.live-col-total {
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}

/* ── Panel shell ── */
.live-panel {
  height: 100%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-height: 0;
}

/* ── Panel header ── */
.panel-hdr {
  flex-shrink: 0;
  font-size: .72rem;
  font-weight: 700;
  padding: 6px 10px;
  line-height: 1.3;
}

/* ── Panel body — scrolls vertically ── */
.live-panel-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 0;
}
.live-panel-body-padded { padding: 8px 10px; }

/* Bottom row panels: auto height */
.live-bottom-panel-body {
  overflow-y: auto;
  overflow-x: hidden;
  max-height: 340px;
  padding: 0;
}
.live-bottom-panel-body-padded { padding: 8px 10px; }

/* ── Horizontal scroll wrapper for tables ── */
.live-table-wrap {
  width: 100%;
  overflow-x: auto;
  overflow-y: visible;
}
.live-table-wrap .tbl { min-width: 640px; table-layout: auto; }
.live-table-wrap.kitty-table .tbl { min-width: 620px; }

/* ── Action cards ── */
.acard {
  padding: 7px 9px;
  margin: 4px 6px;
  border-radius: 7px;
  border: 1px solid var(--bdr, #e0e0e0);
  background: #fff;
  box-sizing: border-box;
  width: calc(100% - 12px);
  word-break: break-word;
  overflow: visible;
}
.acard-name {
  font-size: .76rem;
  font-weight: 700;
  white-space: normal;
  overflow: visible;
  line-height: 1.3;
}
.acard-meta {
  font-size: .64rem;
  color: var(--gry, #777);
  white-space: normal;
  line-height: 1.4;
  margin-top: 2px;
}
.acard-amt {
  font-size: .88rem;
  font-weight: 800;
  white-space: nowrap;
  margin-left: 8px;
  flex-shrink: 0;
}

/* ── Table cells ── */
.tbl th,
.tbl td {
  font-size: .68rem;
  padding: 5px 6px;
  white-space: nowrap;
  vertical-align: middle;
}
.tbl td:nth-child(2),
.tbl th:nth-child(2) {
  white-space: normal;
  min-width: 150px;
}

/* ── Total card ── */
.live-total-card {
  background: linear-gradient(135deg, #1b1b1b 0%, #2d2d2d 100%);
  border-radius: 10px;
  color: #fff;
}
.live-total-card .panel-hdr {
  background: transparent;
  border-bottom: 1px solid rgba(255,255,255,0.1);
  color: #fff;
}
.live-total-value {
  font-size: 1.35rem;
  font-weight: 800;
  color: #ffcc00;
}

/* ── Tablet / mobile: stack everything ── */
@media (max-width: 1024px) {
  .content.live-shell { height: auto; min-height: calc(100vh - 96px); overflow: visible; }
  .live-top-grid,
  .live-bottom-grid  { grid-template-columns: 1fr; height: auto; }
  .live-col-members,
  .live-col-guests,
  .live-col-wide,
  .live-col-total    { width: 100%; }
  .live-panel-body   { max-height: 320px; }
  .live-toolbar      { justify-content: flex-start; }
}
@media (max-width: 768px) {
  .tbl th, .tbl td   { font-size: .62rem; padding: 4px 5px; }
  .acard             { padding: 6px 7px; }
  .acard-name        { font-size: .7rem; }
  .acard-meta        { font-size: .6rem; }
  .live-total-value  { font-size: 1.1rem; }
}
</style>

<div class="content live-shell">

<!-- Mode toggle button -->
<div class="live-toolbar">
  <a href="<?=$toggleUrl?>" style="display:inline-block; background:#f0f0f0; border:1px solid var(--bdr); border-radius:8px; padding:5px 14px; font-size:.75rem; font-weight:600; color:var(--gry); text-decoration:none; cursor:pointer">
    🔄 <?=$toggleLabel?>
  </a>
</div>

<!-- ── TOP ROW: 2 columns 50/50 (queue is in separate tab) ── -->
<div class="live-top-grid">

  <!-- Column 1 — 50%: Paid Members -->
  <div class="live-col-members">
    <div class="panel live-panel">
      <div class="panel-hdr" style="background:#1b1b1b;flex-shrink:0">
        Paid Members (<?=count($mpaidRows)?>)
        <span style="float:right;font-size:.72rem;opacity:.85">₹<?=number_format($membersTotal)?></span>
      </div>
      <div class="panel-body live-panel-body live-vscroll">
        <?php if (!$mpaidRows): ?>
        <div class="text-center text-muted py-4" style="font-size:.78rem">No paid members yet</div>
        <?php else: ?>
        <div class="live-table-wrap live-hscroll">
          <table class="tbl">
            <thead><tr><th>#</th><th>Name</th><th>Category</th><th>Mode</th><th>Total</th><th>Time</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($mpaidRows as $pi => $r):
              $tot = (float)($r['total_session'] ?? $r['amount'] ?? 0);
              $sessionId = date('Y-m-d H:i', strtotime($r['submitted_at']));
            ?>
            <tr id="mrow_<?=(int)$r['id']?>" data-name="<?=strtolower(htmlspecialchars($r['name']??''))?>">
              <td class="text-muted"><?=$pi+1?></td>
              <td><strong><?=htmlspecialchars($r['name']??'')?></strong></td>
              <td style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($r['category']??'—')?></td>
              <td><span class="badge-mode"><?=htmlspecialchars($r['payment_method']??'')?></span></td>
              <td style="font-weight:700;color:var(--red)">₹<?=number_format($tot)?></td>
              <td style="font-size:.72rem;color:var(--gry)"><?=$r['verified_at'] ? date('H:i',strtotime($r['verified_at'])) : '—'?></td>
              <td>
                <div style="display:flex;gap:3px;flex-wrap:wrap">
                  <button class="btn-edi" onclick="openEditMemberSessionModal(<?=(int)$r['member_id']?>, '<?=htmlspecialchars($r['submitted_at'])?>', <?=(int)$r['total_session']?>, '<?=date('Y-m-d', strtotime($r['submitted_at']))?>', '<?=htmlspecialchars($r['payment_method'])?>', 'Paid')">Edit</button>
                 <button class="btn-del" data-member="<?=(int)$r['member_id']?>" data-session="<?=htmlspecialchars($r['submitted_at'], ENT_QUOTES)?>" data-row="mrow_<?=(int)$r['id']?>" onclick="deleteMemberSession(this)">Del</button>
                  <button style="background:none;color:#555;border:1px solid #ccc;border-radius:6px;padding:3px 6px;font-size:.72rem;cursor:pointer"
                          onclick="printRow('<?=htmlspecialchars(addslashes($r['name']??''))?>','Member','<?=htmlspecialchars($r['payment_method']??'')?>','<?=number_format($tot)?>','<?=$r['verified_at']?date('H:i',strtotime($r['verified_at'])):'—'?>')">🖨</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Column 3 — 40%: Guests Verified -->
  <div class="live-col-guests">
    <div class="panel live-panel">
      <div class="panel-hdr" style="background:#444;flex-shrink:0">
        Guests Verified (<?=count($gpaidRows)?>) · ₹<?=number_format($guestsTotal)?>
      </div>
      <div class="panel-body live-panel-body live-vscroll">
        <?php if (!$gpaidRows): ?>
        <div class="text-center text-muted py-4" style="font-size:.78rem">No guests verified yet</div>
        <?php else: ?>
        <div class="live-table-wrap live-hscroll">
          <table class="tbl">
            <thead><tr><th>#</th><th>Name</th><th>Type</th><th>Category</th><th>Via/Chapter</th><th>Mode</th><th>Amt</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($gpaidRows as $gi => $g):
              $cat = $g['type']==='Visitor' ? ($g['visitor_profession']??'') : ($g['observer_category']??'');
              $via = $g['referrer_name'] ?? $g['observer_chapter'] ?? '—';
              $typeBg  = $g['type']==='Visitor' ? '#e3f2fd' : '#f3e5f5';
              $typeCol = $g['type']==='Visitor' ? '#1565c0' : '#6a1b9a';
              $guestEditJson = htmlspecialchars(json_encode([
                  'id' => (int)($g['id'] ?? 0),
                  'member_id' => (int)($g['member_id'] ?? 0),
                  'visitor_name' => $g['visitor_name'] ?? '',
                  'visitor_profession' => $g['visitor_profession'] ?? '',
                  'friday_date' => $g['friday_date'] ?? $sel,
                  'paid_date' => $g['paid_date'] ?? $sel,
                  'amount' => (int)($g['amount'] ?? 1450),
                  'payment_method' => $g['payment_method'] ?? 'Cash',
                  'status' => $g['status'] ?? 'Paid',
              ]), ENT_QUOTES, 'UTF-8');
            ?>
            <tr id="grow_<?=(int)$g['id']?>">
              <td class="text-muted"><?=$gi+1?></td>
              <td><strong><?=htmlspecialchars($g['visitor_name']??'')?></strong></td>
              <td><span class="badge-mode" style="background:<?=$typeBg?>;color:<?=$typeCol?>"><?=$g['type']?></span></td>
              <td style="font-size:.73rem;color:var(--gry)"><?=htmlspecialchars($cat)?></td>
              <td style="font-size:.75rem"><?=htmlspecialchars($via)?></td>
              <td><span class="badge-mode"><?=htmlspecialchars($g['payment_method']??'')?></span></td>
              <td style="font-weight:600">₹<?=number_format($g['amount']??0)?></td>
              <td>
                <div style="display:flex;gap:3px;flex-wrap:wrap">
                  <?php if (($g['type'] ?? '') === 'Visitor'): ?>
                  <button class="btn-edi" data-row="<?=$guestEditJson?>" onclick="openLiveVisitorEdit(this)">Edit</button>
                  <?php else: ?>
                  <button class="btn-edi" onclick="openEdit(<?=(int)$g['id']?>,'transactions','<?=htmlspecialchars(addslashes($g['visitor_name']??''))?>',<?=(int)($g['amount']??0)?>,'<?=htmlspecialchars($g['payment_method']??'')?>')">Edit</button>
                  <?php endif; ?>
                  <button class="btn-del" onclick="delRow(<?=(int)$g['id']?>,'transactions','grow_<?=(int)$g['id']?>')">Del</button>
                  <button style="background:none;color:#555;border:1px solid #ccc;border-radius:6px;padding:3px 6px;font-size:.72rem;cursor:pointer"
                          onclick="printRow('<?=htmlspecialchars(addslashes($g['visitor_name']??''))?>','<?=$g['type']?>','<?=htmlspecialchars($g['payment_method']??'')?>','<?=number_format($g['amount']??0)?>','<?=$g['verified_at']?date('H:i',strtotime($g['verified_at'])):'—'?>')">🖨</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- end live-top-grid -->

<!-- ── BOTTOM ROW: Kitty Cash + Total (scroll into view) ── -->
<div class="live-bottom-grid">

  <!-- Kitty Cash panel -->
  <div class="live-col-wide">
    <div class="panel" style="display:flex;flex-direction:column;overflow:hidden;border-radius:8px">
      <div class="panel-hdr" style="background:#c47800;flex-shrink:0">
        Kitty Cash (<?=count($kittyWeekly)?>)
        <span style="float:right;font-size:.72rem;opacity:.9">₹<?=number_format($kittyWeekTotal)?></span>
      </div>
      <div class="live-bottom-panel-body live-vscroll">
        <?php if ($kittyWeekly): ?>
        <div class="live-table-wrap kitty-table live-hscroll">
          <table class="tbl">
            <thead><tr><th>#</th><th>Member</th><th>Company</th><th>Mode</th><th style="text-align:right">Amount</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($kittyWeekly as $ki => $k): ?>
            <tr>
              <td class="text-muted"><?=$ki+1?></td>
              <td><strong><?=htmlspecialchars($k['name'])?></strong></td>
              <td style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($k['company_name']??'—')?></td>
              <td><span class="badge-mode"><?=htmlspecialchars($k['payment_method']??'')?></span></td>
              <td style="text-align:right;font-weight:700;color:#c47800">₹<?=number_format($k['amount'])?></td>
              <td style="font-size:.72rem;color:var(--gry)"><?=date('d M', strtotime($k['submitted_at']))?></td>
              <td style="white-space:nowrap">
                <button class="btn-edi" onclick="openEditKittyModal(<?=(int)$k['id']?>, <?=(int)$k['amount']?>, '<?=htmlspecialchars($k['payment_method'])?>')">Edit</button>
                <button class="btn-del" onclick="deleteKittyPayment(<?=(int)$k['id']?>, this)">Del</button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="background:#fff8e1">
                <td colspan="5" style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:right;font-weight:800;color:#c47800">₹<?=number_format($kittyWeekTotal)?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4" style="font-size:.8rem">No kitty payments this week</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Visitor dues collected panel -->
  <div class="live-col-wide">
    <div class="panel" style="display:flex;flex-direction:column;overflow:hidden;border-radius:8px">
      <div class="panel-hdr" style="background:#1b5e20;flex-shrink:0">
        Visitor Dues Collected (<?=count($collectedDuesRows)?>)
        <span style="float:right;font-size:.72rem;opacity:.9">₹<?=number_format($collectedDuesTotal)?></span>
      </div>
      <div class="live-bottom-panel-body live-vscroll">
        <?php if ($collectedDuesRows): ?>
        <div class="live-table-wrap live-hscroll">
          <table class="tbl">
            <thead><tr><th>#</th><th>Visitor</th><th>Member</th><th>Meeting</th><th>Mode</th><th style="text-align:right">Amount</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($collectedDuesRows as $di => $d): ?>
            <tr id="duecol_<?=(int)($d['id'] ?: $d['txn_id'])?>">
              <td class="text-muted"><?=$di+1?></td>
              <td><strong><?=htmlspecialchars($d['visitor_name'])?></strong></td>
              <td style="font-size:.72rem"><?=htmlspecialchars($d['member_name'])?></td>
              <td style="font-size:.72rem;color:var(--gry)"><?=date('d M', strtotime($d['friday_date']))?></td>
              <td><span class="badge-mode"><?=htmlspecialchars($d['payment_method'] ?: 'Cash')?></span></td>
              <td style="text-align:right;font-weight:700;color:#1b5e20">₹<?=number_format($d['amount'])?></td>
              <td style="white-space:nowrap">
                <?php if (!empty($d['id'])): ?>
                <button class="btn-edi" onclick="editDueCollection(<?=(int)$d['id']?>, '<?=htmlspecialchars(addslashes($d['visitor_name']))?>', <?=(int)$d['amount']?>, '<?=htmlspecialchars($d['payment_method'] ?: 'Cash')?>', '<?=htmlspecialchars($d['paid_date'] ?? $sel)?>')">Edit</button>
                <button class="btn-del" onclick="unsettleDue(<?=(int)$d['id']?>)">Undo</button>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="background:#e8f5e9">
                <td colspan="5" style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:right;font-weight:800;color:#1b5e20">₹<?=number_format($collectedDuesTotal)?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4" style="font-size:.8rem">No visitor dues collected</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Today's Total panel -->
  <div class="live-col-total">
    <div class="panel live-total-card" style="display:flex;flex-direction:column;overflow:hidden;border-radius:10px">
      <div class="panel-hdr">
        📊 Today's Total Collection
      </div>
      <div class="live-bottom-panel-body live-bottom-panel-body-padded live-vscroll">
        <div style="margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.15)">
          <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;opacity:.7">Meet Fee (Members)</div>
          <div style="font-size:1.2rem;font-weight:800">₹<?=number_format($membersTotal)?></div>
          <div style="font-size:.7rem;opacity:.6"><?=$membersCount?> member<?=$membersCount!=1?'s':''?> paid</div>
        </div>
        <div style="margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.15)">
          <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;opacity:.7">Guests (Visitors + Observers)</div>
          <div style="font-size:1.2rem;font-weight:800">₹<?=number_format($guestsTotal)?></div>
          <div style="font-size:.7rem;opacity:.6"><?=count($gpaidRows)?> guest<?=count($gpaidRows)!=1?'s':''?> verified</div>
        </div>
        <div style="margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.15)">
          <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;opacity:.7">Visitor Dues Collected</div>
          <div style="font-size:1.2rem;font-weight:800">₹<?=number_format($collectedDuesTotal)?></div>
          <div style="font-size:.7rem;opacity:.6"><?=count($collectedDuesRows)?> due<?=count($collectedDuesRows)!=1?'s':''?> collected</div>
        </div>
        <div style="margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.15)">
          <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;opacity:.7">Kitty Cash</div>
          <div style="font-size:1.2rem;font-weight:800">₹<?=number_format($kittyWeekTotal)?></div>
          <div style="font-size:.7rem;opacity:.6"><?=count($kittyWeekly)?> payment<?=count($kittyWeekly)!=1?'s':''?></div>
        </div>
        <div style="margin-top:8px;padding-top:8px;border-top:2px solid rgba(255,255,255,0.3)">
          <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:1px">GRAND TOTAL</div>
          <div class="live-total-value">₹<?=number_format($todayTotal)?></div>
        </div>
      </div>
    </div>
  </div>

</div><!-- end live-bottom-grid -->

</div><!-- end content live-shell -->

<div id="liveVisitorEditBg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2200;align-items:center;justify-content:center" onclick="if(event.target===this)closeLiveVisitorEdit()">
  <div style="background:#fff;border-radius:10px;padding:18px;width:430px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="font-size:1rem;margin:0 0 12px;font-weight:800">Edit Visitor Payment</h3>
    <input type="hidden" id="lve_id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <label style="font-size:.75rem;font-weight:700">Visitor Name<input id="lve_name" type="text" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Profession<input id="lve_prof" type="text" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Member
        <select id="lve_member" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px">
          <option value="">Select member</option>
          <?php foreach ($memList as $m): ?>
          <option value="<?=(int)$m['id']?>"><?=htmlspecialchars($m['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:.75rem;font-weight:700">Mode
        <select id="lve_mode" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px">
          <option value="Cash">Cash</option>
          <option value="UPI">QR Code (UPI)</option>
          <option value="Card">Card</option>
          <option value="FinCloud">FinCloud</option>
          <option value="Pending-Member">Pending-Member</option>
        </select>
      </label>
      <label style="font-size:.75rem;font-weight:700">Sunday Date<input id="lve_Sunday" type="date" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Paid Date<input id="lve_paid" type="date" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Amount<input id="lve_amount" type="number" min="1" step="1" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px"></label>
      <label style="font-size:.75rem;font-weight:700">Status
        <select id="lve_status" style="width:100%;margin-top:3px;border:1px solid #ccc;border-radius:7px;padding:7px">
          <option value="Paid">Paid</option>
          <option value="Pending">Pending</option>
          <option value="Rejected">Rejected</option>
        </select>
      </label>
    </div>
    <div id="lve_err" style="display:none;margin-top:10px;background:#fff0f2;color:#b00020;border-radius:7px;padding:8px;font-size:.82rem"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
      <button type="button" onclick="closeLiveVisitorEdit()" style="background:#fff;border:1px solid #bbb;border-radius:7px;padding:7px 14px;cursor:pointer">Cancel</button>
      <button type="button" onclick="saveLiveVisitorEdit()" style="background:#D90429;color:#fff;border:none;border-radius:7px;padding:7px 16px;font-weight:700;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<script>
// Delete entire member payment session (all weeks with same submitted_at)

function deleteMemberSession(btn) {
    var memberId = btn.getAttribute('data-member');
    var sessionId = btn.getAttribute('data-session');
    var rowId = btn.getAttribute('data-row');
    if (!confirm('Delete this entire payment session? This will remove all weekly entries.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_member_session');
    fd.append('member_id', memberId);
    fd.append('session_id', sessionId);
    fetch('member_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                var row = document.getElementById(rowId);
                if (row) row.remove();
                setTimeout(() => location.reload(), 800);
            } else {
                alert('Error: ' + (d.msg || 'Failed'));
            }
        })
        .catch(e => alert('Request failed: ' + e.message));
}

function editDueCollection(dueId, name, amount, mode, paidDate) {
    var nextAmount = prompt('Amount for ' + name, amount);
    if (nextAmount === null) return;
    nextAmount = parseInt(nextAmount, 10) || 0;
    if (nextAmount <= 0) { alert('Enter a valid amount.'); return; }
    var nextMode = prompt('Payment mode (Cash / UPI / Card / FinCloud)', mode || 'Cash');
    if (!nextMode) return;
    var nextDate = prompt('Collected date (YYYY-MM-DD)', paidDate || '<?=$sel?>');
    if (!nextDate) return;
    doPost('verify_action.php', {
        action: 'edit_due_collection',
        due_id: dueId,
        amount: nextAmount,
        mode: nextMode,
        paid_date: nextDate
    }, function(d) {
        if (!d.ok) { alert('Error: ' + (d.msg || 'Failed')); return; }
        location.reload();
    });
}

function unsettleDue(dueId) {
    if (!confirm('Move this visitor due back to pending?')) return;
    doPost('verify_action.php', {action:'unsettle_due', due_id:dueId}, function(d) {
        if (!d.ok) { alert('Error: ' + (d.msg || 'Failed')); return; }
        location.reload();
    });
}

function openLiveVisitorEdit(btn) {
    var row = JSON.parse(btn.getAttribute('data-row') || '{}');
    document.getElementById('lve_id').value = row.id || '';
    document.getElementById('lve_name').value = row.visitor_name || '';
    document.getElementById('lve_prof').value = row.visitor_profession || '';
    document.getElementById('lve_member').value = row.member_id || '';
    document.getElementById('lve_mode').value = row.payment_method || 'Cash';
    document.getElementById('lve_Sunday').value = row.friday_date || '<?=$sel?>';
    document.getElementById('lve_paid').value = row.paid_date || '<?=$sel?>';
    document.getElementById('lve_amount').value = row.amount || 1450;
    document.getElementById('lve_status').value = row.status || 'Paid';
    document.getElementById('lve_err').style.display = 'none';
    document.getElementById('liveVisitorEditBg').style.display = 'flex';
}

function closeLiveVisitorEdit() {
    document.getElementById('liveVisitorEditBg').style.display = 'none';
}

function saveLiveVisitorEdit() {
    var err = document.getElementById('lve_err');
    err.style.display = 'none';
    var fd = new FormData();
    fd.append('action', 'edit_visitor_txn');
    fd.append('id', document.getElementById('lve_id').value);
    fd.append('visitor_name', document.getElementById('lve_name').value.trim());
    fd.append('visitor_profession', document.getElementById('lve_prof').value.trim());
    fd.append('member_id', document.getElementById('lve_member').value);
    fd.append('mode', document.getElementById('lve_mode').value);
    fd.append('friday_date', document.getElementById('lve_Sunday').value);
    fd.append('paid_date', document.getElementById('lve_paid').value);
    fd.append('amount', document.getElementById('lve_amount').value);
    fd.append('status', document.getElementById('lve_status').value);
    if (typeof _csrf !== 'undefined') fd.append('_csrf', _csrf);
    fetch('member_action.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                err.textContent = d.msg || 'Failed to save visitor payment';
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

// Delete a single kitty payment
function deleteKittyPayment(id, btn) {
    if (!confirm('Delete this kitty payment?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_kitty');
    fd.append('id', id);
    fetch('member_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                var row = btn.closest('tr');
                if (row) row.remove();
            } else {
                alert('Error: ' + (d.msg || 'Failed'));
            }
        });
}


// All existing JS functions (openEdit, delRow, act, actBatch, openVisitorPay, openMemberPay, settleAllDues, printMemberDues, printRow, filterLiveMembers, etc.)
// are already defined in the main dashboard (tab_modals.php and inline). No duplicate code needed.
</script>
