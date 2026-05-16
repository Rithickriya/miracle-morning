<?php
require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/name_match_helper.php';

// Dates
$today = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$isSunday = (int)$today->format('N') === 7;
$defaultSunday = $isSunday ? $today->format('Y-m-d') : (clone $today)->modify('last sunday')->format('Y-m-d');

// Validate date to prevent SQL injection via GET param
if (isset($_GET['date'])) {
    $dateCheck = DateTime::createFromFormat('Y-m-d', $_GET['date']);
    $sel = ($dateCheck && $dateCheck->format('Y-m-d') === $_GET['date'])
           ? $_GET['date'] : $defaultSunday;
} else {
    $sel = $defaultSunday;
}

$tab  = isset($_GET['tab'])  ? $_GET['tab']  : 'live';

// Week window for $sel (Mon-Sun, same logic as live/print tabs)
$_selDtDb = new DateTime($sel, new DateTimeZone('Asia/Kolkata'));
if ((int)$_selDtDb->format('N') !== 7) $_selDtDb->modify('next sunday');
$_selSunday = $_selDtDb->format('Y-m-d');
$_weekStartDb = date('Y-m-d', strtotime($_selSunday . ' -6 days'));

// Top bar stats — match by friday_date OR by submitted_at within the week window
try {
    $ts = $pdo->prepare("
        SELECT
            SUM(CASE WHEN type='Member'   AND status='Paid' AND is_partial=0 AND (friday_date=:fd OR (DATE(submitted_at) BETWEEN :ws AND :fd)) THEN 1 ELSE 0 END) AS mem_paid,
            SUM(CASE WHEN type='Visitor'  AND status='Paid' AND (friday_date=:fd OR (DATE(submitted_at) BETWEEN :ws AND :fd)) THEN 1 ELSE 0 END) AS vis_paid,
            SUM(CASE WHEN type='Observer' AND status='Paid' AND (friday_date=:fd OR (DATE(submitted_at) BETWEEN :ws AND :fd)) THEN 1 ELSE 0 END) AS obs_paid,
            SUM(CASE WHEN status='Paid'   AND (friday_date=:fd OR (DATE(submitted_at) BETWEEN :ws AND :fd)) THEN amount ELSE 0 END) AS total_cash,
            SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending_cnt
        FROM transactions
        WHERE (friday_date = :fd OR DATE(submitted_at) BETWEEN :ws AND :fd)
    ");
    $ts->execute([':fd' => $_selSunday, ':ws' => $_weekStartDb]);
    $S = $ts->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $S = ['mem_paid'=>0,'vis_paid'=>0,'obs_paid'=>0,'total_cash'=>0,'pending_cnt'=>0];
}

$total_active  = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn();
$kitty_pending = (int)$pdo->query("SELECT COUNT(*) FROM kitty_payments WHERE status='Pending'")->fetchColumn();
$kitty_all_paid= (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM kitty_payments WHERE status='Paid'")->fetchColumn();
$dues_pending = 0;
try {
    $dues_pending = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM visitor_dues WHERE status='Pending'")->fetchColumn();
} catch (Exception $e) {
    $dues_pending = 0;
}
$queue_pending = (int)($S['pending_cnt'] ?? 0);
$kitty_dues_pending = $kitty_pending + $dues_pending;
$total_pending = (int)($S['pending_cnt'] ?? 0) + $kitty_pending;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Miracle Morning — Admin Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
:root{--red:#D90429;--rdk:#a8001f;--rlt:#fff0f2;--blk:#111;--gry:#666;--bdr:#e0e0e0}
*{box-sizing:border-box}
body{background:#f0f2f5;font-family:'Segoe UI',sans-serif;font-size:.87rem;color:var(--blk);overflow-x:hidden}
.topbar{background:#fff;border-bottom:2px solid var(--red);padding:8px 18px;position:sticky;top:0;z-index:100;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.brand{font-weight:800;color:var(--red);font-size:1.1rem}
.stat-pills{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sp{display:inline-flex;align-items:center;gap:5px;background:#f8f8f8;border:1px solid var(--bdr);border-radius:20px;padding:3px 10px;font-size:.75rem;font-weight:600}
.sp .v{background:var(--red);color:#fff;border-radius:10px;padding:1px 7px;font-size:.7rem}
.sp .v.dk{background:var(--blk)}
.sp .v.am{background:#c47800}
.topbar-right{display:flex;align-items:center;gap:8px}
.logo{height:26px;width:auto;max-width:110px;object-fit:contain;opacity:.75}
.date-input{font-size:.78rem;border:1px solid var(--bdr);border-radius:8px;padding:4px 8px;outline:none}
.refresh-btn{background:var(--red);color:#fff;border:none;border-radius:8px;padding:4px 12px;font-size:.75rem;font-weight:600;cursor:pointer}
.tabstrip{background:#fff;border-bottom:1px solid var(--bdr);padding:0 18px;display:flex;gap:0;overflow-x:auto;scrollbar-width:none}
.tabstrip::-webkit-scrollbar{display:none}
.tlink{padding:10px 16px;font-weight:600;font-size:.8rem;border:none;background:none;color:var(--gry);border-bottom:3px solid transparent;cursor:pointer;text-decoration:none;display:inline-block;white-space:nowrap;transition:all .18s}
.tlink:hover{color:var(--blk)}
.tlink.on{color:var(--red);border-color:var(--red)}
.tlink .dot{background:var(--red);color:#fff;border-radius:50%;width:16px;height:16px;font-size:.6rem;display:inline-flex;align-items:center;justify-content:center;margin-left:4px;vertical-align:middle}
.content{padding:14px 16px;height:calc(100vh - 96px);overflow-y:auto}
.panel{background:#fff;border-radius:12px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.panel-hdr{padding:10px 14px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#fff}
.panel-body{flex:1;overflow-y:auto;padding:10px}
.split{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;height:100%}
.tbl{width:100%;border-collapse:collapse;font-size:.82rem}
.tbl th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);padding:6px 8px;border-bottom:1.5px solid var(--bdr);white-space:nowrap}
.tbl td{padding:7px 8px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#fafafa}
.acard{background:#f8f8f8;border:1px solid var(--bdr);border-radius:10px;padding:10px 12px;margin-bottom:8px}
.acard-name{font-weight:700;font-size:.87rem}
.acard-meta{font-size:.7rem;color:var(--gry);margin-top:2px}
.acard-amt{font-weight:700;color:var(--red);font-size:.95rem}
.badge-paid{background:#e8f5e9;color:#2e7d32;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:10px}
.badge-pend{background:#fff8e1;color:#c47800;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:10px}
.badge-unp{background:#ffebee;color:#c62828;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:10px}
.badge-part{background:#fff3e0;color:#e65100;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:10px}
.badge-mode{background:#f3f4f6;color:#374151;font-size:.67rem;font-weight:600;padding:2px 8px;border-radius:10px}
.scard{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.scard .val{font-size:1.6rem;font-weight:700}
.scard .lbl{font-size:.7rem;color:var(--gry);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.prog-track{height:8px;background:#f0f0f0;border-radius:4px;overflow:hidden}
.prog-fill{height:100%;background:var(--red);border-radius:4px;transition:width .3s}
.btn-app{background:#1b5e20;color:#fff;border:none;border-radius:6px;padding:3px 10px;font-size:.72rem;font-weight:600;cursor:pointer}
.btn-rej{background:none;color:var(--red);border:1px solid var(--red);border-radius:6px;padding:3px 10px;font-size:.72rem;font-weight:600;cursor:pointer}
.btn-edt{background:none;color:var(--gry);border:1px solid var(--bdr);border-radius:6px;padding:3px 10px;font-size:.72rem;font-weight:600;cursor:pointer}
.btn-del{background:none;color:#c62828;border:1px solid #ffcdd2;border-radius:6px;padding:3px 10px;font-size:.72rem;font-weight:600;cursor:pointer}
.btn-edi{background:none;color:#1565c0;border:1px solid #bbdefb;border-radius:6px;padding:3px 10px;font-size:.72rem;font-weight:600;cursor:pointer}
.btn-exp{display:inline-flex;align-items:center;gap:4px;background:var(--red);color:#fff;border:none;border-radius:8px;padding:5px 14px;font-size:.75rem;font-weight:600;cursor:pointer;text-decoration:none}
.btn-exp.outline{background:#fff;color:var(--red);border:1.5px solid var(--red)}
.mode-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f5f5f5;font-size:.83rem}
.mode-row:last-child{border-bottom:none}
.denom-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.denom-card{background:#f8f8f8;border:1px solid var(--bdr);border-radius:10px;padding:12px}
.denom-note{font-size:.75rem;font-weight:700;color:var(--gry);margin-bottom:4px}
.denom-input{width:70px;border:1.5px solid var(--bdr);border-radius:6px;padding:4px 8px;font-size:.85rem;font-weight:600;text-align:center;outline:none}
.denom-input:focus{border-color:var(--red)}
.row-del{opacity:.35;pointer-events:none;transition:opacity .3s}
.edit-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;display:flex;align-items:center;justify-content:center}
.edit-modal{background:#fff;border-radius:14px;padding:22px 24px;width:360px;box-shadow:0 16px 48px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto}
.edit-modal h3{font-size:.95rem;font-weight:700;margin-bottom:14px}
.edit-field{margin-bottom:12px}
.edit-field label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gry);margin-bottom:4px}
.edit-field input,.edit-field select{width:100%;border:1.5px solid var(--bdr);border-radius:8px;padding:7px 10px;font-size:.88rem;outline:none;font-family:inherit}
.edit-field input:focus,.edit-field select:focus{border-color:var(--red)}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:#ccc;border-radius:2px}
.sum-table td{padding:8px 12px;border-bottom:1px solid #f0f0f0;font-size:.85rem}
.sum-table tr:last-child td{font-weight:700;border-top:2px solid var(--bdr);border-bottom:none;font-size:.9rem}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="d-flex align-items-center gap-3">
    <span class="brand">Miracle Morning</span>
    <div class="stat-pills">
      <span class="sp">Members paid <span class="v"><?=(int)($S['mem_paid']??0)?>/<?=$total_active?></span></span>
      <span class="sp">Visitors <span class="v dk"><?=(int)($S['vis_paid']??0)?></span></span>
      <span class="sp">Observers <span class="v dk"><?=(int)($S['obs_paid']??0)?></span></span>
      <span class="sp">Collection <span class="v">₹<?=number_format($S['total_cash']??0)?></span></span>
      <span class="sp">Kitty pool <span class="v am">₹<?=number_format($kitty_all_paid)?></span></span>
    </div>
  </div>
  <div class="topbar-right">
    <input type="date" class="date-input" value="<?=$sel?>" id="datepick">
    <button class="refresh-btn" onclick="location.reload()">↻ Refresh</button>
    <?php if (hm_is_admin() && $tab !== 'range'): ?>
    <a href="?date=<?=$sel?>&tab=<?=$tab?>&export=excel" class="btn-exp outline ms-1" style="font-size:.72rem;padding:4px 10px">⬇ Excel</a>
    <a href="?date=<?=$sel?>&tab=<?=$tab?>&export=pdf"   class="btn-exp ms-1"         style="font-size:.72rem;padding:4px 10px">⬇ PDF</a>
    <?php endif; ?>
    <span style="display:inline-flex;align-items:center;gap:5px;background:<?=hm_is_admin()?'#111':'#e3f2fd'?>;color:<?=hm_is_admin()?'#fff':'#1565c0'?>;border-radius:20px;padding:3px 10px;font-size:.72rem;font-weight:700;white-space:nowrap">
      <?=hm_is_admin()?'🔐 Admin':'🖥 Desk'?>
    </span>
    <a href="logout.php"
       onclick="return confirm('Sign out of Miracle Morning?')"
       style="display:inline-flex;align-items:center;gap:4px;background:#fff;border:1.5px solid var(--bdr);color:var(--gry);border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:600;text-decoration:none;cursor:pointer;transition:all .18s"
       onmouseover="this.style.borderColor='var(--red)';this.style.color='var(--red)'"
       onmouseout="this.style.borderColor='var(--bdr)';this.style.color='var(--gry)'">
      ⎋ Logout
    </a>
    <img src="image/powerbi.png" alt="PowerBI" class="logo ms-1">
  </div>
</div>

<!-- TABS -->
<div class="tabstrip">
<?php
// All tabs — admin sees everything, desk sees only allowed tabs
$all_tabs = [
    'queue'     => 'Verification Queue',
    'live'      => 'Live Desk',
    'st'        => 'ST Report',
    'approvals' => 'Approvals',
    'kitty_dues'=> 'Kitty & Dues',
    'kitty'     => 'Kitty Cash',
    'summary'   => 'Summary',
    'records'   => 'Records',
    'visitors'  => 'Visitors',
    'observers' => 'Observers',
    'members'   => 'Members',
    'import'    => 'Import',
    'range'     => 'Date Range Report',
    'print'     => '🖨 Weekly Report',
];
$tabs = hm_is_admin()
    ? $all_tabs
    : array_intersect_key($all_tabs, array_flip(DESK_ALLOWED_TABS));

// If current tab is not accessible for this role, fall back to live
if (!hm_can_tab($tab)) { $tab = 'queue'; }

foreach ($tabs as $tid => $tlabel):
?>
<a href="?date=<?=$sel?>&tab=<?=$tid?>" class="tlink <?=$tab===$tid?'on':''?>">
    <?=$tlabel?>
    <?php if ($tid==='queue' && $queue_pending>0): ?><span class="dot"><?=$queue_pending?></span><?php endif; ?>
    <?php if ($tid==='kitty_dues' && $kitty_dues_pending>0): ?><span class="dot"><?=$kitty_dues_pending?></span><?php endif; ?>
    <?php if ($tid==='approvals' && $total_pending>0 && hm_is_admin()): ?><span class="dot"><?=$total_pending?></span><?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<?php
// Export handlers — check tab access first
if (isset($_GET['export'])) {
    if (!hm_can_tab($tab)) { header('Location: ?tab=live'); exit; }
    require_once __DIR__ . '/tab_exports.php';
    exit;
}

// Include the right tab — only allowed tabs
$allowed_tabs = array_keys($all_tabs);
$tab_file = (in_array($tab, $allowed_tabs) && hm_can_tab($tab))
    ? __DIR__ . '/tab_' . $tab . '.php'
    : null;

if ($tab_file && file_exists($tab_file)) {
    require $tab_file;
} else {
    echo '<div class="content"><div class="scard text-center py-5 text-muted">Tab not found.</div></div>';
}
?>

<!-- MODALS -->
<?php require_once __DIR__ . '/tab_modals.php'; ?>

<script>
var _csrf = '<?=hm_csrf_token()?>';

document.getElementById('datepick').addEventListener('change', function(){
    location.href = '?date=' + this.value + '&tab=<?=$tab?>';
});

function doFetch(url, callback) {
    fetch(url)
        .then(function(r){
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(callback)
        .catch(function(err){
            alert('Request failed: ' + err.message + '\nURL: ' + url);
        });
}

function doPost(url, params, callback) {
    params._csrf = _csrf;
    var body = new URLSearchParams(params);
    fetch(url, { method: 'POST', body: body, headers: {'Content-Type':'application/x-www-form-urlencoded'} })
        .then(function(r){
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(callback)
        .catch(function(err){
            alert('Request failed: ' + err.message);
        });
}

function act(id, action, tbl) {
    var el = document.getElementById((tbl==='kitty'?'ap_k':'ap_') + id);
    doPost('verify_action.php', {action:action, id:id, tbl:tbl}, function(d){
        if (d.ok) {
            if (el) {
                el.style.opacity = '.35';
                el.innerHTML += '<div style="margin-top:6px;font-weight:700;font-size:.78rem;color:'+(action==='verify'?'#2e7d32':'#c62828')+'">'+(action==='verify'?'✓ Approved':'✗ Rejected')+'</div>';
            }
            setTimeout(function(){ location.reload(); }, 800);
        } else { alert('Error: ' + (d.msg||'Failed')); }
    });
}

function actBatch(idsJson, action, tbl) {
    var label = action === 'verify' ? 'Approve' : 'Reject';
    if (!confirm(label + ' all entries in this group?')) return;
    var batchAction = action === 'verify' ? 'verify_batch' : 'reject_batch';
    doPost('verify_action.php', {action:batchAction, ids:idsJson, tbl:tbl}, function(d) {
        if (d.ok) {
            setTimeout(function() { location.reload(); }, 800);
        } else {
            alert('Error: ' + (d.msg || 'Failed'));
        }
    });
}

function delRow(id, tbl, rowId) {
    if (!confirm('Delete this record permanently?')) return;
    doPost('verify_action.php', {action:'delete', id:id, tbl:tbl}, function(d){
        if (d.ok) {
            var row = document.getElementById(rowId);
            if (row) { row.classList.add('row-del'); setTimeout(function(){row.remove();},400); }
        } else { alert('Error: '+(d.msg||'Failed')); }
    });
}

var _editId=null,_editTbl=null;
function openEdit(id,tbl,name,amt,mode){
    _editId=id;_editTbl=tbl;
    document.getElementById('editTitle').textContent='Edit — '+name;
    document.getElementById('editAmt').value=amt;
    document.getElementById('editMode').value=mode;
    document.getElementById('editOverlay').style.display='flex';
}
function closeEdit(){document.getElementById('editOverlay').style.display='none';}
function saveEdit(){
    var amt=parseInt(document.getElementById('editAmt').value)||0;
    var mode=document.getElementById('editMode').value;
    if(amt<=0){alert('Enter a valid amount.');return;}
    doPost('verify_action.php', {action:'edit', id:_editId, tbl:_editTbl, amount:amt}, function(d){
        if(!d.ok){alert('Error: '+(d.msg||'Failed'));return;}
        doPost('verify_action.php', {action:'edit_mode', id:_editId, tbl:_editTbl, mode:mode}, function(d2){
            closeEdit();location.reload();
        });
    });
}

function calcDenom(){
    var total=0;
    [500,200,100,50].forEach(function(v){
        var cnt=parseInt((document.getElementById('dn'+v)||{}).value)||0;
        var sub=cnt*v;
        var el=document.getElementById('dt'+v);
        if(el) el.textContent='= ₹'+sub.toLocaleString('en-IN');
        total+=sub;
    });
    var tot=document.getElementById('dtotal');
    if(tot) tot.textContent='₹'+total.toLocaleString('en-IN');
}

function applyFilter(){
    var s=(document.getElementById('rsearch')||{value:''}).value||'';
    var d=(document.getElementById('rdate')||{value:''}).value||'';
    var url='?tab=records';
    if(s) url+='&search='+encodeURIComponent(s);
    if(d) url+='&filter_date='+d;
    location.href=url;
}

// Visitor payment modals
var _vpayId=null,_mpayId=null,_settleId=null,_payWhen='now';
function openVisitorPay(id,name){
    _vpayId=id;
    var el=document.getElementById('vpay_name');
    if(el) el.textContent='Visitor: '+name+' · ₹1,450';
    document.getElementById('visitorPayOverlay').style.display='flex';
}
function closeVisitorPay(){document.getElementById('visitorPayOverlay').style.display='none';}
function confirmVisitorPay(){
    var mode=document.getElementById('vpay_mode').value;
    if(!_vpayId){alert('No visitor selected.');return;}
    closeVisitorPay();
    doPost('verify_action.php', {action:'visitor_paid', id:_vpayId, mode:mode}, function(d){
        if(d.ok){setTimeout(function(){location.reload();},600);}
        else{alert('Error: '+(d.msg||'Failed'));}
    });
}
function openMemberPay(id,name,referrer){
    _mpayId=id;_payWhen='now';
    var el=document.getElementById('mpay_name');
    if(el) el.textContent='Visitor: '+name+' · ₹1,450';
    document.getElementById('mpay_search').value='';
    document.getElementById('mpay_member_id').value='';
    document.getElementById('mpay_selected').style.display='none';
    document.getElementById('mpay_list').style.display='none';
    setPayWhen('now');
    document.getElementById('memberPayOverlay').style.display='flex';
    // Auto-fill invited-by member if available
    if(referrer && referrer.trim()) {
        var q=referrer.trim().toLowerCase();
        var match=(window.ML||[]).find(function(m){return m.n.toLowerCase().indexOf(q)>=0;});
        if(match){
            selectMpayMember(match.id,match.n);
        } else {
            document.getElementById('mpay_search').value=referrer;
            filterMpayList(referrer);
        }
    }
}
function closeMemberPay(){document.getElementById('memberPayOverlay').style.display='none';}
function setPayWhen(when){
    _payWhen=when;
    var nb=document.getElementById('mpay_now_btn'),lb=document.getElementById('mpay_later_btn');
    var mf=document.getElementById('mpay_mode_field'),ln=document.getElementById('mpay_later_note');
    if(when==='now'){
        nb.style.borderColor='var(--red)';nb.style.background='var(--rlt)';nb.style.color='var(--red)';
        lb.style.borderColor='var(--bdr)';lb.style.background='#fff';lb.style.color='var(--gry)';
        mf.style.display='block';ln.style.display='none';
    }else{
        lb.style.borderColor='#6a1b9a';lb.style.background='#fdf7ff';lb.style.color='#6a1b9a';
        nb.style.borderColor='var(--bdr)';nb.style.background='#fff';nb.style.color='var(--gry)';
        mf.style.display='none';ln.style.display='block';
    }
}
function filterMpayList(q){
    var list=document.getElementById('mpay_list');
    if(!q.trim()){list.style.display='none';return;}
    var ms=(window.ML||[]).filter(function(m){return m.n.toLowerCase().indexOf(q.toLowerCase())>=0;});
    if(!ms.length){list.style.display='none';return;}
    var html='';
    ms.slice(0,10).forEach(function(m){
        var esc=m.n.replace(/'/g,"\\'");
        html+='<div onclick="selectMpayMember('+m.id+',\''+esc+'\');" style="padding:9px 12px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f5f5f5;" onmouseover="this.style.background=\'#f5f5f5\';" onmouseout="this.style.background=\'\';"><strong>'+m.n+'</strong>';
        if(m.c) html+=' <span style="color:var(--gry);font-size:.72rem">'+m.c+'</span>';
        html+='</div>';
    });
    list.innerHTML=html;
    list.style.display='block';
}

function selectMpayMember(id,name){
    document.getElementById('mpay_member_id').value=id;
    document.getElementById('mpay_search').value=name;
    document.getElementById('mpay_selected').textContent='✓ '+name;
    document.getElementById('mpay_selected').style.display='block';
    document.getElementById('mpay_list').style.display='none';
}
function confirmMemberPay(){
    var mid=document.getElementById('mpay_member_id').value;
    if(!mid){alert('Please select a member.');return;}
    if(!_mpayId){alert('No visitor selected.');return;}
    var payNow=_payWhen==='now'?1:0;
    var mode=document.getElementById('mpay_mode').value;
    closeMemberPay();
    doPost('verify_action.php', {action:'visitor_paid_by_member', id:_mpayId, member_id:mid, pay_now:payNow, mode:mode}, function(d){
        if(d.ok){setTimeout(function(){location.reload();},600);}
        else{alert('Error: '+(d.msg||'Failed'));}
    });
}
function settleDue(dueId){
    _settleId=dueId;
    document.getElementById('settleOverlay').style.display='flex';
}
function closeSettle(){document.getElementById('settleOverlay').style.display='none';}
function confirmSettle(){
    var mode=document.getElementById('settle_mode').value;
    var paidDate=(document.getElementById('datepick')||{value:''}).value;
    closeSettle();
    doPost('verify_action.php', {action:'settle_due', id:1, due_id:_settleId, mode:mode, paid_date:paidDate}, function(d){
        if(d.ok){setTimeout(function(){location.reload();},600);}
        else{alert('Error: '+(d.msg||'Failed'));}
    });
}

// Settle ALL dues for one member + auto-print receipt
function settleAllDues(idsJson, memberId, memberName, visitorsJson) {
    var ids      = JSON.parse(idsJson);
    var visitors = JSON.parse(visitorsJson || '[]');
    if (!ids || !ids.length) return;
    var mode = prompt('Payment method for collecting all dues?\n(Cash / UPI / Card / FinCloud)', 'Cash');
    if (!mode) return;
    var paidDate = (document.getElementById('datepick')||{value:''}).value;
    var done = 0;
    var total = 0;
    visitors.forEach(function(v){ total += parseFloat(v.amount||0); });
    ids.forEach(function(dueId) {
        doPost('verify_action.php', {action:'settle_due', id:1, due_id:dueId, mode:mode, paid_date:paidDate}, function(d){
            done++;
            if (!d.ok) {
                alert('Error: ' + (d.msg || 'Failed to collect visitor due'));
                return;
            }
            if (done === ids.length) {
                // Print receipt then reload
                printMemberDues(memberName, visitorsJson, total, mode, new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'}));
                setTimeout(function(){ location.reload(); }, 800);
            }
        });
    });
}

// Print receipt: member paid for multiple visitors
function printMemberDues(memberName, visitorsJson, total, mode, time) {
    var visitors = [];
    try { visitors = JSON.parse(visitorsJson); } catch(e) {}
    var date = new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
    if (!time || time==='—') time = new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});

    var rows = '';
    visitors.forEach(function(v, i) {
        var attended = v.friday_date ? '<div style="font-size:10px;color:#777;margin-top:2px">Meeting: '+v.friday_date+'</div>' : '';
        rows += '<tr>' +
            '<td style="padding:5px 10px;border-bottom:1px solid #eee">'+(i+1)+'.</td>' +
            '<td style="padding:5px 10px;border-bottom:1px solid #eee;font-weight:600">'+v.name+attended+'</td>' +
            '<td style="padding:5px 10px;border-bottom:1px solid #eee;text-align:right;font-weight:700;color:#D90429">₹'+parseFloat(v.amount||0).toLocaleString('en-IN')+'</td>' +
            '</tr>';
    });

    var w = window.open('', '_blank', 'width=440,height=500');
    if (!w) {
        alert('Collected successfully. Please allow popups to print the receipt.');
        return;
    }
    w.document.write(
        '<html><head><title>Visitor Due Receipt</title>' +
        '<style>' +
        'body{font-family:Arial,sans-serif;font-size:13px;padding:20px;margin:0;color:#000}' +
        'h2{color:#6a1b9a;margin:0 0 4px;font-size:17px}' +
        '.sub{color:#888;font-size:11px;margin-bottom:16px}' +
        '.info{display:flex;justify-content:space-between;background:#f9f4ff;border-radius:8px;padding:10px 14px;margin-bottom:14px}' +
        '.info-label{font-size:10px;color:#999;text-transform:uppercase;letter-spacing:.5px}' +
        '.info-val{font-weight:700;font-size:13px;margin-top:2px}' +
        'table{width:100%;border-collapse:collapse;margin-bottom:14px}' +
        'th{background:#6a1b9a;color:#fff;padding:6px 10px;text-align:left;font-size:12px}' +
        '.total-row{background:#f9f4ff;font-size:16px;font-weight:800;color:#6a1b9a;text-align:right;padding:10px 14px;border-radius:8px;margin-bottom:16px}' +
        '.note{font-size:10px;color:#999;text-align:center;margin-top:10px}' +
        '</style></head><body>' +
        '<h2>Miracle Morning — Visitor Due Receipt</h2>' +
        '<div class="sub">Coimbatore Chapter · ' + date + '</div>' +
        '<div class="info">' +
            '<div><div class="info-label">Paid by member</div><div class="info-val">' + memberName + '</div></div>' +
            '<div><div class="info-label">Mode</div><div class="info-val">' + (mode||'—') + '</div></div>' +
            '<div><div class="info-label">Time</div><div class="info-val">' + time + '</div></div>' +
        '</div>' +
        '<table>' +
            '<tr><th>#</th><th>Visitor Name</th><th style="text-align:right">Amount</th></tr>' +
            rows +
        '</table>' +
        '<div class="total-row">Total Collected: ₹' + parseFloat(total||0).toLocaleString('en-IN') + '</div>' +
        '<div style="text-align:center">' +
            '<button onclick="window.print()" style="background:#6a1b9a;color:#fff;border:none;padding:9px 24px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700">🖨 Print Receipt</button>' +
        '</div>' +
        '<div class="note">This receipt is for visitor entry fees paid by the member.<br>Separate from weekly meeting fee.</div>' +
        '</body></html>'
    );
    w.document.close();
}

// Print a single row slip
function printRow(name, type, mode, amount, time) {
    var w = window.open('','_blank','width=400,height=300');
    if (!w) {
        alert('Please allow popups to print the receipt.');
        return;
    }
    var date = new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
    w.document.write(
        '<html><head><title>Receipt</title><style>' +
        'body{font-family:Arial,sans-serif;font-size:13px;padding:20px;margin:0}' +
        'h2{color:#D90429;margin:0 0 12px;font-size:16px}' +
        '.row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #eee}' +
        '.lbl{color:#666}.val{font-weight:bold}' +
        '.total{font-size:18px;font-weight:bold;color:#D90429;text-align:center;margin-top:14px;padding:10px;background:#fff0f2;border-radius:8px}' +
        '</style></head><body>' +
        '<h2>Miracle Morning — Payment Receipt</h2>' +
        '<div class="row"><span class="lbl">Name</span><span class="val">' + name + '</span></div>' +
        '<div class="row"><span class="lbl">Type</span><span class="val">' + type + '</span></div>' +
        '<div class="row"><span class="lbl">Mode</span><span class="val">' + mode + '</span></div>' +
        '<div class="row"><span class="lbl">Date</span><span class="val">' + date + '</span></div>' +
        '<div class="row"><span class="lbl">Time</span><span class="val">' + time + '</span></div>' +
        '<div class="total">₹' + amount + '</div>' +
        '<div style="text-align:center;margin-top:16px">' +
        '<button onclick="window.print()" style="background:#D90429;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-size:13px">🖨 Print</button>' +
        '</div></body></html>'
    );
    w.document.close();
}
function filterLiveMembers(q) {
    q = (q||'').toLowerCase();
    document.querySelectorAll('tr[data-name]').forEach(function(r){
        r.style.display = (!q || r.dataset.name.indexOf(q) >= 0) ? '' : 'none';
    });
}
function filterSTTable(){
    var q=(document.getElementById('st_search')?document.getElementById('st_search').value:'').toLowerCase();
    document.querySelectorAll('#st_tbl tbody tr').forEach(function(r){
        r.style.display=(!q||(r.dataset.name||'').includes(q))?'':'none';
    });
}
function toggleMem(id){
    var row=document.getElementById('mem_detail_'+id);
    if(row) row.style.display=row.style.display==='none'?'table-row':'none';
}
function filterMembers(){
    var q=(document.getElementById('mem_srch')?document.getElementById('mem_srch').value:'').toLowerCase();
    document.querySelectorAll('#mem_tbl tbody tr.mem-row').forEach(function(r){
        var show=!q||(r.dataset.name||'').includes(q)||(r.dataset.cat||'').includes(q);
        r.style.display=show?'':'none';
        var onc=r.getAttribute('onclick')||'';
        var m=onc.match(/\d+/);
        if(m){var dr=document.getElementById('mem_detail_'+m[0]);if(dr&&!show)dr.style.display='none';}
    });
}
<?php if($tab==='live' || $tab==='queue'): ?>
var autoRef=setInterval(function(){location.reload();},30000);
document.querySelector('.refresh-btn').addEventListener('click',function(e){e.preventDefault();clearInterval(autoRef);location.reload();});
<?php endif; ?>
</script>
</body>
</html>
