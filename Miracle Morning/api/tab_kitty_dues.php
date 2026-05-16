<?php
// tab_kitty_dues.php - Pending Kitty payments and Visitor Dues in table/list view.

$kpend = [];
try {
    $q = $pdo->query("
        SELECT k.*, m.name AS mname, m.company_name
        FROM kitty_payments k
        JOIN members m ON k.member_id = m.id
        WHERE k.status = 'Pending'
        ORDER BY k.submitted_at DESC
    ");
    if ($q) $kpend = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$pendDues = [];
try {
    $q = $pdo->query("
        SELECT vd.id, vd.member_id, vd.visitor_name, vd.amount, vd.created_at,
               t.friday_date, m.name AS mname
        FROM visitor_dues vd
        JOIN members m ON vd.member_id = m.id
        JOIN transactions t ON t.id = vd.txn_id
        WHERE vd.status = 'Pending'
        ORDER BY t.friday_date ASC, m.name ASC, vd.created_at ASC
    ");
    if ($q) $pendDues = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$duesByMember = [];
$duesBySunday = [];
foreach ($pendDues as $d) {
    $mid = (int)$d['member_id'];
    if (!isset($duesByMember[$mid])) {
        $duesByMember[$mid] = ['mname' => $d['mname'], 'visitors' => [], 'total' => 0, 'ids' => []];
    }
    $duesByMember[$mid]['visitors'][] = ['name' => $d['visitor_name'], 'amount' => (float)$d['amount']];
    $duesByMember[$mid]['total'] += (float)$d['amount'];
    $duesByMember[$mid]['ids'][] = (int)$d['id'];

    $fd = $d['friday_date'] ?: '0000-00-00';
    if (!isset($duesBySunday[$fd])) $duesBySunday[$fd] = [];
    if (!isset($duesBySunday[$fd][$mid])) {
        $duesBySunday[$fd][$mid] = ['mname' => $d['mname'], 'visitors' => [], 'total' => 0, 'ids' => []];
    }
    $duesBySunday[$fd][$mid]['visitors'][] = [
        'name' => $d['visitor_name'],
        'amount' => (float)$d['amount'],
        'friday_date' => $d['friday_date'],
    ];
    $duesBySunday[$fd][$mid]['total'] += (float)$d['amount'];
    $duesBySunday[$fd][$mid]['ids'][] = (int)$d['id'];
}
?>

<style>
.content.kd-shell {
    height: calc(100vh - 96px);
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 16px 18px;
}
.kd-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}
.kd-panel {
    min-width: 0;
    background: #fff;
    border: 1px solid var(--bdr);
    border-radius: 10px;
    overflow: hidden;
}
.kd-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    height: 38px;
    padding: 0 12px;
    border-bottom: 1px solid var(--bdr);
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .7px;
}
.kd-table-wrap { width: 100%; overflow-x: auto; }
.kd-table {
    width: 100%;
    min-width: 0;
    border-collapse: collapse;
    table-layout: fixed;
}
.kd-table th {
    background: #f7f7f7;
    color: #777;
    font-size: .56rem;
    font-weight: 800;
    letter-spacing: .7px;
    text-transform: uppercase;
    text-align: left;
    padding: 7px 6px;
    border-bottom: 1px solid var(--bdr);
}
.kd-table td {
    padding: 7px 6px;
    border-bottom: 1px solid #ececec;
    vertical-align: middle;
    font-size: .62rem;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kd-table tr:last-child td { border-bottom: none; }
.kd-primary {
    font-weight: 800;
    color: #222;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.kd-sub {
    display: none;
    color: var(--gry);
    font-size: .56rem;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.kd-amt { font-weight: 900; white-space: nowrap; text-align: right; }
.kd-actions {
    display: flex;
    flex-wrap: nowrap;
    gap: 3px;
    align-items: center;
    overflow: hidden;
}
.kd-actions .btn-app,
.kd-actions .btn-rej,
.kd-actions button {
    min-height: 25px;
    padding: 4px 7px !important;
    font-size: .58rem !important;
    font-weight: 800 !important;
    border-radius: 5px !important;
    white-space: nowrap;
}
.kd-empty {
    padding: 28px 10px;
    color: var(--gry);
    text-align: center;
    font-size: .82rem;
}
@media (max-width: 980px) {
    .content.kd-shell { height: auto; min-height: calc(100vh - 96px); }
    .kd-grid { grid-template-columns: 1fr; }
}
</style>

<div class="content kd-shell">
<div class="kd-grid">
    <section class="kd-panel">
        <div class="kd-hdr" style="color:#c47800"><span>Kitty Pending</span><span><?=count($kpend)?></span></div>
        <div class="kd-table-wrap">
        <table class="kd-table">
            <thead>
                <tr><th style="width:22%">Member</th><th style="width:20%">Company</th><th style="width:15%">Date</th><th style="width:10%">Payment</th><th style="width:11%;text-align:right">Amount</th><th style="width:22%">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$kpend): ?>
                <tr><td colspan="6"><div class="kd-empty">No kitty payments pending.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($kpend as $r): ?>
                <tr id="ap_k<?=(int)$r['id']?>">
                    <td><div class="kd-primary" title="<?=htmlspecialchars($r['mname']??'')?>"><?=htmlspecialchars($r['mname']??'')?></div></td>
                    <td title="<?=htmlspecialchars($r['company_name'] ?: '-')?>"><?=htmlspecialchars($r['company_name'] ?: '-')?></td>
                    <td><?=date('d M y H:i', strtotime($r['submitted_at']))?></td>
                    <td><span class="badge-mode"><?=htmlspecialchars($r['payment_method']??'')?></span></td>
                    <td class="kd-amt" style="color:#c47800">&#8377;<?=number_format($r['amount'])?></td>
                    <td>
                        <div class="kd-actions">
                            <button class="btn-app" onclick="act(<?=(int)$r['id']?>,'verify','kitty')">Approve</button>
                            <button class="btn-rej" onclick="act(<?=(int)$r['id']?>,'reject','kitty')">Reject</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="kd-panel">
        <div class="kd-hdr" style="color:#6a1b9a"><span>Visitor Dues Pending</span><span><?=count($duesByMember)?> member<?=count($duesByMember)===1?'':'s'?></span></div>
        <div class="kd-table-wrap">
        <table class="kd-table">
            <thead>
                <tr><th style="width:22%">Member</th><th style="width:38%">Visitors</th><th style="width:12%;text-align:right">Total</th><th style="width:28%">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$duesBySunday): ?>
                <tr><td colspan="4"><div class="kd-empty">No visitor dues pending.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($duesBySunday as $SundayDate => $membersForSunday): ?>
                <tr>
                    <td colspan="4" style="background:#f3e5f5;color:#4a148c;font-weight:900;text-transform:uppercase;letter-spacing:.4px">
                        Meeting attended: <?= $SundayDate && $SundayDate !== '0000-00-00' ? date('d M Y', strtotime($SundayDate)) : 'No date' ?>
                    </td>
                </tr>
            <?php foreach ($membersForSunday as $mid => $dm):
                $visCount = count($dm['visitors']);
                $idsParam = htmlspecialchars(json_encode(array_values($dm['ids'])), ENT_QUOTES, 'UTF-8');
                $visJson = htmlspecialchars(json_encode($dm['visitors']), ENT_QUOTES, 'UTF-8');
            ?>
                <tr>
                    <td><div class="kd-primary" style="color:#6a1b9a" title="<?=htmlspecialchars($dm['mname'])?>"><?=htmlspecialchars($dm['mname'])?></div></td>
                    <td title="<?php foreach($dm['visitors'] as $vi => $v): ?><?=htmlspecialchars($v['name'])?> &#8377;<?=number_format($v['amount'])?><?=$vi < $visCount-1 ? ', ' : ''?><?php endforeach; ?>">
                        <?php foreach($dm['visitors'] as $vi => $v): ?>
                            <strong><?=htmlspecialchars($v['name'])?></strong> &#8377;<?=number_format($v['amount'])?><?=$vi < $visCount-1 ? ', ' : ''?>
                        <?php endforeach; ?>
                    </td>
                    <td class="kd-amt" style="color:#6a1b9a">&#8377;<?=number_format($dm['total'])?></td>
                    <td>
                        <div class="kd-actions">
                            <button style="background:#6a1b9a;color:#fff;border:none;cursor:pointer" onclick="openCollectDuesModal('<?=$idsParam?>',<?=$mid?>,'<?=htmlspecialchars(addslashes($dm['mname']))?>','<?=$visJson?>')">Collect all &#8377;<?=number_format($dm['total'])?></button>
                            <button style="background:none;color:#6a1b9a;border:1px solid #ce93d8;cursor:pointer" onclick="printMemberDues('<?=htmlspecialchars(addslashes($dm['mname']))?>','<?=$visJson?>',<?=$dm['total']?>,'-','-')">Print</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
</div>
</div>

<!-- ══ COLLECT DUES MODAL ══════════════════════════════════════════════════ -->
<div id="collectDuesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:3000;align-items:center;justify-content:center" onclick="if(event.target===this)closeCollectDuesModal()">
  <div style="background:#fff;border-radius:12px;padding:20px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="font-size:1rem;font-weight:800;margin-bottom:4px;color:#222">Collect Visitor Dues</div>
    <div id="cdm_member_name" style="font-size:.8rem;color:#6a1b9a;font-weight:700;margin-bottom:12px"></div>

    <!-- Visitor list -->
    <div style="border:1px solid #e1bee7;border-radius:8px;overflow:hidden;margin-bottom:14px">
      <table style="width:100%;border-collapse:collapse;font-size:.8rem">
        <thead><tr style="background:#f3e5f5">
          <th style="padding:5px 8px;text-align:left;color:#6a1b9a;font-weight:700">#</th>
          <th style="padding:5px 8px;text-align:left;color:#6a1b9a;font-weight:700">Visitor</th>
          <th style="padding:5px 8px;text-align:right;color:#6a1b9a;font-weight:700">Amount</th>
        </tr></thead>
        <tbody id="cdm_visitor_list"></tbody>
        <tfoot><tr style="background:#f3e5f5;border-top:1px solid #ce93d8">
          <td colspan="2" style="padding:5px 8px;text-align:right;font-weight:800;color:#4a148c">Total</td>
          <td id="cdm_total" style="padding:5px 8px;text-align:right;font-weight:900;color:#4a148c"></td>
        </tr></tfoot>
      </table>
    </div>

    <!-- Mode + Date -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
      <label style="font-size:.75rem;font-weight:700;color:#444">
        Payment Mode
        <select id="cdm_mode" style="display:block;width:100%;margin-top:4px;border:1.5px solid #ce93d8;border-radius:7px;padding:8px;font-size:.82rem;outline:none;background:#fff">
          <option value="UPI">QR Code (UPI)</option>
          <option value="Cash">Cash</option>
          <option value="Card">Card</option>
          <option value="FinCloud">FinCloud</option>
        </select>
      </label>
      <label style="font-size:.75rem;font-weight:700;color:#444">
        Paid Date
        <input type="date" id="cdm_date" style="display:block;width:100%;margin-top:4px;border:1.5px solid #ce93d8;border-radius:7px;padding:7px;font-size:.82rem;outline:none;box-sizing:border-box">
      </label>
    </div>

    <div id="cdm_err" style="display:none;background:#fff0f2;color:#b00020;border-radius:7px;padding:8px;font-size:.78rem;margin-bottom:10px"></div>

    <div style="display:flex;justify-content:flex-end;gap:8px">
      <button onclick="closeCollectDuesModal()" style="background:#fff;border:1.5px solid #ccc;border-radius:8px;padding:8px 16px;font-size:.82rem;cursor:pointer;font-weight:600">Cancel</button>
      <button id="cdm_confirm" onclick="confirmCollectDues()" style="background:#6a1b9a;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:.82rem;font-weight:800;cursor:pointer">Collect</button>
    </div>
  </div>
</div>

<script>
// Override any existing settleAllDues globally
function settleAllDues(idsJson, memberId, memberName, visitorsJson) {
    openCollectDuesModal(idsJson, memberId, memberName, visitorsJson);
}

function openCollectDuesModal(idsJson, memberId, memberName, visitorsJson) {
    var ids      = typeof idsJson === 'string'      ? JSON.parse(idsJson)      : idsJson;
    var visitors = typeof visitorsJson === 'string' ? JSON.parse(visitorsJson) : visitorsJson;
    var total    = visitors.reduce(function(s, v){ return s + parseFloat(v.amount || 0); }, 0);

    document.getElementById('cdm_member_name').textContent = memberName;

    var rows = visitors.map(function(v, i){
        var amt = parseFloat(v.amount || 0);
        return '<tr style="border-top:1px solid #f3e5f5"><td style="padding:4px 8px;color:#777">'+(i+1)+'</td>'
             + '<td style="padding:4px 8px;font-weight:600">'+escHtml(v.name || v.visitor_name || '')+'</td>'
             + '<td style="padding:4px 8px;text-align:right">&#8377;'+amt.toLocaleString('en-IN')+'</td></tr>';
    }).join('');
    document.getElementById('cdm_visitor_list').innerHTML = rows;

    document.getElementById('cdm_total').textContent = '₹' + total.toLocaleString('en-IN');

    // Default date = today
    var today = new Date();
    var dd = String(today.getDate()).padStart(2,'0');
    var mm = String(today.getMonth()+1).padStart(2,'0');
    var yyyy = today.getFullYear();
    document.getElementById('cdm_date').value = yyyy+'-'+mm+'-'+dd;
    document.getElementById('cdm_mode').value = 'UPI';
    document.getElementById('cdm_err').style.display = 'none';
    document.getElementById('cdm_confirm').disabled = false;
    document.getElementById('cdm_confirm').textContent = 'Collect';

    window._cdmIds = ids;
    document.getElementById('collectDuesModal').style.display = 'flex';
}

function closeCollectDuesModal() {
    document.getElementById('collectDuesModal').style.display = 'none';
}

async function confirmCollectDues() {
    var mode = document.getElementById('cdm_mode').value;
    var date = document.getElementById('cdm_date').value;
    var err  = document.getElementById('cdm_err');
    var btn  = document.getElementById('cdm_confirm');
    var ids  = window._cdmIds || [];

    err.style.display = 'none';
    if (!mode) { err.textContent = 'Please select a payment mode.'; err.style.display='block'; return; }
    if (!date) { err.textContent = 'Please select the paid date.';  err.style.display='block'; return; }
    if (!ids.length) { err.textContent = 'No dues to collect.'; err.style.display='block'; return; }

    btn.disabled = true;
    btn.textContent = 'Processing…';

    var csrf = (typeof _csrf !== 'undefined') ? _csrf
             : (document.querySelector('meta[name="csrf-token"]') || {content:''}).content
             || (document.querySelector('input[name="_csrf"]') || {value:''}).value;

    var failed = 0;
    for (var i = 0; i < ids.length; i++) {
        var fd = new FormData();
        fd.append('action',    'settle_due');
        fd.append('due_id',    ids[i]);
        fd.append('mode',      mode);
        fd.append('paid_date', date);
        fd.append('_csrf',     csrf);
        try {
            var res = await fetch('verify_action.php', { method:'POST', body:fd });
            var data = await res.json();
            if (!data.ok) failed++;
        } catch(e) { failed++; }
    }

    if (failed > 0) {
        err.textContent = failed + ' due(s) could not be collected. Please refresh and retry.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Collect';
    } else {
        closeCollectDuesModal();
        location.reload();
    }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
