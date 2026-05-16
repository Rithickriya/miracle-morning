<?php
// tab_visitors.php — All Visitor Records with week filter + PDF

// ── Filters ──────────────────────────────────────────────────
$filterWeek = trim($_GET['vis_week'] ?? 'all');
$filterStat = trim($_GET['vis_stat'] ?? 'all');

// Build all available Sundays that have visitor records
$weekList = $pdo->query("
    SELECT DISTINCT friday_date FROM transactions
    WHERE type='Visitor'
    ORDER BY friday_date DESC
")->fetchAll(PDO::FETCH_COLUMN);

// Build query
$where  = ["type='Visitor'"];
$params = [];
if ($filterWeek !== 'all' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterWeek)) {
    $where[]  = "friday_date = ?";
    $params[] = $filterWeek;
}
if (in_array($filterStat, ['Paid','Pending','Rejected'])) {
    $where[]  = "status = ?";
    $params[] = $filterStat;
}

$sql = "SELECT id, visitor_name, visitor_mobile, visitor_email,
               visitor_company, visitor_profession, referrer_name,
               amount, payment_method, status, friday_date, business_card
        FROM transactions
        WHERE " . implode(' AND ', $where) . "
        ORDER BY friday_date DESC, submitted_at DESC";

$q = $pdo->prepare($sql);
$q->execute($params);
$allvis = $q->fetchAll(PDO::FETCH_ASSOC);

// Summary counts
$totalPaid     = count(array_filter($allvis, function($r){ return $r['status']==='Paid'; }));
$totalPending  = count(array_filter($allvis, function($r){ return $r['status']==='Pending'; }));
$totalRejected = count(array_filter($allvis, function($r){ return $r['status']==='Rejected'; }));
$totalAmt      = array_sum(array_column(array_filter($allvis, function($r){ return $r['status']==='Paid'; }), 'amount'));

// Label for PDF title
$weekLabel = $filterWeek !== 'all' ? date('d M Y', strtotime($filterWeek)) : 'All Time';

// Prev/Next week navigation
$currentIdx = $filterWeek !== 'all' ? array_search($filterWeek, $weekList) : -1;
$prevWeek   = ($currentIdx > 0)                          ? $weekList[$currentIdx - 1] : null;
$nextWeek   = ($currentIdx >= 0 && $currentIdx < count($weekList)-1) ? $weekList[$currentIdx + 1] : null;
// If on "All" view, first/last weeks
if ($filterWeek === 'all') {
    $prevWeek = count($weekList) > 0 ? $weekList[0] : null; // newest
    $nextWeek = null;
}
?>

<div class="content">

<!-- ── Filters ── -->
<div class="scard mb-3" style="padding:12px 16px">

  <!-- Row 1: Week navigation + dropdowns -->
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">

    <!-- Prev week -->
    <?php if($prevWeek): ?>
    <a href="?tab=visitors&date=<?=$sel?>&vis_week=<?=$prevWeek?>&vis_stat=<?=$filterStat?>"
       class="btn-exp outline" style="padding:5px 10px;font-size:.78rem">&larr;</a>
    <?php else: ?>
    <button disabled style="background:#f5f5f5;border:1px solid var(--bdr);color:#ccc;border-radius:8px;padding:5px 10px;font-size:.78rem;cursor:default">&larr;</button>
    <?php endif; ?>

    <!-- Week label -->
    <div style="font-weight:700;font-size:.88rem;min-width:110px;text-align:center">
      <span class="vis-week-label"><?=$filterWeek==='all' ? 'All Weeks' : date('d M Y', strtotime($filterWeek))?></span>
    </div>

    <!-- Next week -->
    <?php if($nextWeek): ?>
    <a href="?tab=visitors&date=<?=$sel?>&vis_week=<?=$nextWeek?>&vis_stat=<?=$filterStat?>"
       class="btn-exp outline" style="padding:5px 10px;font-size:.78rem">&rarr;</a>
    <?php else: ?>
    <button disabled style="background:#f5f5f5;border:1px solid var(--bdr);color:#ccc;border-radius:8px;padding:5px 10px;font-size:.78rem;cursor:default">&rarr;</button>
    <?php endif; ?>

    <div style="width:1px;height:20px;background:var(--bdr);margin:0 2px"></div>

    <!-- Week dropdown -->
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0">
      <input type="hidden" name="tab" value="visitors">
      <input type="hidden" name="date" value="<?=htmlspecialchars($sel)?>">
      <input type="hidden" name="vis_stat" value="<?=htmlspecialchars($filterStat)?>">
      <select name="vis_week" onchange="this.form.submit()"
              style="border:1px solid var(--bdr);border-radius:8px;padding:5px 10px;font-size:.8rem;outline:none;cursor:pointer">
        <option value="all" <?=$filterWeek==='all'?'selected':''?>>📅 All weeks</option>
        <?php foreach($weekList as $wk): ?>
        <option value="<?=$wk?>" <?=$filterWeek===$wk?'selected':''?>><?=date('d M Y',strtotime($wk))?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <!-- Status dropdown -->
    <form method="GET" style="display:flex;gap:8px;align-items:center;margin:0">
      <input type="hidden" name="tab" value="visitors">
      <input type="hidden" name="date" value="<?=htmlspecialchars($sel)?>">
      <input type="hidden" name="vis_week" value="<?=htmlspecialchars($filterWeek)?>">
      <select name="vis_stat" onchange="this.form.submit()"
              style="border:1px solid var(--bdr);border-radius:8px;padding:5px 10px;font-size:.8rem;outline:none;cursor:pointer">
        <option value="all"      <?=$filterStat==='all'?'selected':''?>>All status</option>
        <option value="Paid"     <?=$filterStat==='Paid'?'selected':''?>>✓ Paid</option>
        <option value="Pending"  <?=$filterStat==='Pending'?'selected':''?>>⏳ Pending</option>
        <option value="Rejected" <?=$filterStat==='Rejected'?'selected':''?>>✗ Rejected</option>
      </select>
    </form>

    <?php if($filterWeek!=='all' || $filterStat!=='all'): ?>
    <a href="?tab=visitors&date=<?=$sel?>" style="font-size:.78rem;color:var(--red);text-decoration:none;font-weight:600">✕ Clear</a>
    <?php endif; ?>

    <!-- PDF button — OUTSIDE all forms so it never submits -->
    <a id="pdf_link"
       href="visitor_pdf.php?vis_week=<?=urlencode($filterWeek)?>&vis_stat=<?=urlencode($filterStat)?>"
       target="_blank"
       class="btn-exp" style="font-size:.75rem;padding:5px 12px;white-space:nowrap;margin-left:auto;text-decoration:none">
      ⬇ Print / PDF
    </a>
  </div>

  <!-- Row 2: Summary pills -->
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <span style="background:#e8f5e9;color:#1b5e20;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700"><?=$totalPaid?> Paid</span>
    <span style="background:#fff8e1;color:#c47800;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700"><?=$totalPending?> Pending</span>
    <span style="background:#ffebee;color:#c62828;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700"><?=$totalRejected?> Rejected</span>
    <?php if($totalAmt>0): ?>
    <span style="background:var(--rlt);color:var(--red);border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700">₹<?=number_format($totalAmt)?> collected</span>
    <?php endif; ?>
    <span style="background:#f5f5f5;color:#555;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:600"><?=count($allvis)?> total</span>
  </div>
</div>

<!-- ── Table ── -->
<div class="scard" style="padding:0;overflow:hidden">
  <?php if(!$allvis): ?>
  <div class="text-center text-muted py-5" style="font-size:.85rem">No visitor records found for selected filter.</div>
  <?php else: ?>
  <table class="tbl" id="vis_table">
    <thead>
      <tr>
        <th style="width:28px">#</th>
        <th>Name</th>
        <th>Mobile / Email</th>
        <th>Company / Category</th>
        <th>Invited By</th>
        <th>Date</th>
        <th>Amt</th>
        <th>Mode</th>
        <th>Business Card</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($allvis as $i=>$r): ?>
    <tr>
      <td class="text-muted"><?=$i+1?></td>
      <td>
        <strong><?=htmlspecialchars($r['visitor_name']??'')?></strong>
        <?php if($r['visitor_email']??''): ?>
        <div style="font-size:.7rem;color:var(--gry)"><?=htmlspecialchars($r['visitor_email'])?></div>
        <?php endif; ?>
      </td>
      <td style="font-size:.8rem">
        <?=htmlspecialchars($r['visitor_mobile']??'—')?>
        <?php if($r['visitor_email']??''): ?>
        <div style="font-size:.7rem;color:var(--gry)"><?=htmlspecialchars($r['visitor_email'])?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if($r['visitor_company']??''): ?><div style="font-weight:600;font-size:.82rem"><?=htmlspecialchars($r['visitor_company'])?></div><?php endif; ?>
        <?php if($r['visitor_profession']??''): ?><div style="font-size:.72rem;color:var(--gry)"><?=htmlspecialchars($r['visitor_profession'])?></div><?php endif; ?>
      </td>
      <td style="font-size:.82rem"><?=htmlspecialchars($r['referrer_name']??'—')?></td>
      <td style="font-size:.78rem;color:var(--gry);white-space:nowrap"><?=date('d M Y',strtotime($r['friday_date']))?></td>
      <td style="font-weight:600;white-space:nowrap">₹<?=number_format($r['amount']??0)?></td>
      <td><span class="badge-mode"><?=htmlspecialchars($r['payment_method']??'—')?></span></td>
      <td style="text-align:center" id="card_cell_<?=(int)$r['id']?>">
        <?php if($r['business_card']??''): ?>
          <?php $cardPath = '/api/uploads/cards/'.htmlspecialchars($r['business_card']); ?>
          <?php $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $r['business_card']); ?>
          <?php if($isImg): ?>
          <img src="<?=$cardPath?>" alt="Card"
               style="width:52px;height:36px;object-fit:cover;border-radius:4px;border:1px solid var(--bdr);cursor:pointer"
               onclick="viewCard('<?=$cardPath?>','<?=htmlspecialchars(addslashes($r['visitor_name']))?>')"
               title="Click to enlarge">
          <?php else: ?>
          <a href="<?=$cardPath?>" target="_blank"
             style="font-size:.75rem;font-weight:600;color:#1565c0;text-decoration:none">📄 PDF</a>
          <?php endif; ?>
          <div style="display:flex;gap:4px;justify-content:center;margin-top:3px">
            <a href="<?=$cardPath?>" download title="Download"
               style="font-size:.68rem;color:#1b5e20;font-weight:600;text-decoration:none">⬇</a>
            <label title="Replace card" style="font-size:.68rem;color:#1565c0;cursor:pointer;font-weight:600">
              ✎<input type="file" accept="image/*,application/pdf" style="display:none"
                      onchange="uploadCard(<?=(int)$r['id']?>,this)">
            </label>
            <button onclick="deleteCard(<?=(int)$r['id']?>,this)"
                    style="background:none;border:none;font-size:.68rem;cursor:pointer;color:#c62828;padding:0" title="Delete">✕</button>
          </div>
        <?php else: ?>
          <label style="cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;gap:2px;padding:6px 8px;border:1.5px dashed #bbb;border-radius:8px;transition:border-color .18s"
                 title="Upload business card"
                 onmouseover="this.style.borderColor='var(--red)'"
                 onmouseout="this.style.borderColor='#bbb'">
            <span style="font-size:1rem">📷</span>
            <span style="font-size:.65rem;color:var(--gry);font-weight:600">Upload</span>
            <input type="file" accept="image/*,application/pdf" style="display:none"
                   onchange="uploadCard(<?=(int)$r['id']?>,this)">
          </label>
        <?php endif; ?>
      </td>
      <td>
        <?php if($r['status']==='Paid'): ?><span class="badge-paid">Paid</span>
        <?php elseif($r['status']==='Pending'): ?><span class="badge-pend">Pending</span>
        <?php else: ?><span class="badge-unp">Rejected</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</div><!-- end content -->

<!-- ── Business Card Lightbox ── -->
<div id="cardLightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9999;align-items:center;justify-content:center"
     onclick="if(event.target===this)closeLightbox()">
  <div style="position:relative;max-width:90vw;max-height:90vh">
    <button onclick="closeLightbox()" style="position:absolute;top:-14px;right:-14px;background:#fff;border:none;border-radius:50%;width:30px;height:30px;font-size:1.1rem;cursor:pointer;font-weight:700;line-height:1;z-index:1">&times;</button>
    <div style="font-size:.78rem;color:#fff;font-weight:600;margin-bottom:8px" id="cardLightboxName"></div>
    <img id="cardLightboxImg" src="" alt="Business Card"
         style="max-width:90vw;max-height:80vh;border-radius:8px;object-fit:contain;display:block">
    <div style="text-align:center;margin-top:10px">
      <a id="cardLightboxDl" href="" download
         style="background:#fff;color:#1b5e20;border-radius:8px;padding:6px 16px;font-size:.8rem;font-weight:700;text-decoration:none">⬇ Download</a>
    </div>
  </div>
</div>

<script>
// ── Lightbox ─────────────────────────────────────────────────
function viewCard(src, name) {
    document.getElementById('cardLightboxImg').src = src;
    document.getElementById('cardLightboxName').textContent = name + ' — Business Card';
    document.getElementById('cardLightboxDl').href = src;
    var lb = document.getElementById('cardLightbox');
    lb.style.display = 'flex';
}
function closeLightbox() {
    document.getElementById('cardLightbox').style.display = 'none';
    document.getElementById('cardLightboxImg').src = '';
}

// ── Delete card ───────────────────────────────────────────────
function deleteCard(id, btn) {
    if (!confirm('Delete this business card permanently?')) return;
    fetch('verify_action.php?action=delete_card&id=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                var cell = document.getElementById('card_cell_' + id);
                if (cell) cell.innerHTML = '<label style="cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;gap:2px;padding:6px 8px;border:1.5px dashed #bbb;border-radius:8px" title="Upload business card" onmouseover="this.style.borderColor=\'var(--red)\'" onmouseout="this.style.borderColor=\'#bbb\'"><span style="font-size:1rem">📷</span><span style="font-size:.65rem;color:var(--gry);font-weight:600">Upload</span><input type="file" accept="image/*,application/pdf" style="display:none" onchange="uploadCard(' + id + ',this)"></label>';
            } else { alert('Error: ' + (d.msg||'Failed')); }
        }).catch(function(e){ alert('Request failed: ' + e.message); });
}

// ── Upload / replace card ─────────────────────────────────────
function uploadCard(id, inp) {
    if (!inp.files || !inp.files[0]) return;
    var file = inp.files[0];
    if (file.size > 5 * 1024 * 1024) { alert('File too large. Max 5MB.'); inp.value=''; return; }

    var cell = document.getElementById('card_cell_' + id);
    if (cell) cell.innerHTML = '<span style="font-size:.72rem;color:var(--gry)">Uploading…</span>';

    var fd = new FormData();
    fd.append('id', id);
    fd.append('card', file);
    fd.append('_csrf', _csrf);

    fetch('upload_card.php', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) { alert('Upload failed: ' + (d.msg||'Error')); location.reload(); return; }
            var url = d.url;
            var isImg = d.is_img;
            var html = '';
            if (isImg) {
                html += '<img src="' + url + '" style="width:52px;height:36px;object-fit:cover;border-radius:4px;border:1px solid var(--bdr);cursor:pointer" onclick="viewCard(\'' + url + '\',\'\')" title="Click to enlarge"><br>';
            } else {
                html += '<a href="' + url + '" target="_blank" style="font-size:.75rem;font-weight:600;color:#1565c0;text-decoration:none">📄 PDF</a><br>';
            }
            html += '<div style="display:flex;gap:4px;justify-content:center;margin-top:3px">';
            html += '<a href="' + url + '" download style="font-size:.68rem;color:#1b5e20;font-weight:600;text-decoration:none">⬇</a>';
            html += '<label title="Replace card" style="font-size:.68rem;color:#1565c0;cursor:pointer;font-weight:600">✎<input type="file" accept="image/*,application/pdf" style="display:none" onchange="uploadCard(' + id + ',this)"></label>';
            html += '<button onclick="deleteCard(' + id + ',this)" style="background:none;border:none;font-size:.68rem;cursor:pointer;color:#c62828;padding:0" title="Delete">✕</button>';
            html += '</div>';
            if (cell) cell.innerHTML = html;
        })
        .catch(function(e){ alert('Request failed: ' + e.message); location.reload(); });
}


</script>
