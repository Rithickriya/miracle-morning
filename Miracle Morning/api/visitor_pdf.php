<?php
// visitor_pdf.php — Generate visitor report as printable HTML page
// Access: /api/visitor_pdf.php?vis_week=2026-04-03&vis_stat=Paid
// Serves a full standalone HTML page with A3 print layout

require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';

$filterWeek = trim($_GET['vis_week'] ?? 'all');
$filterStat = trim($_GET['vis_stat'] ?? 'all');

// Build query
$where  = ["type='Visitor'"];
$params = [];
if ($filterWeek !== 'all' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterWeek)) {
    $where[]  = 'friday_date = ?';
    $params[] = $filterWeek;
}
if (in_array($filterStat, ['Paid','Pending','Rejected'])) {
    $where[]  = 'status = ?';
    $params[] = $filterStat;
}

$q = $pdo->prepare(
    "SELECT id, visitor_name, visitor_mobile, visitor_email,
            visitor_company, visitor_profession, referrer_name,
            amount, payment_method, status, friday_date, business_card
     FROM transactions
     WHERE " . implode(' AND ', $where) . "
     ORDER BY friday_date DESC, submitted_at DESC"
);
$q->execute($params);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

$weekLabel  = $filterWeek !== 'all' ? date('d M Y', strtotime($filterWeek)) : 'All Time';
$totalCount = count($rows);

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Visitor Report — <?=htmlspecialchars($weekLabel)?></title>
<style>
/* ── Gigantic Screen styles ──────────────────────── */
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:Arial,sans-serif;
  font-size:16px;
  color:#000;
  background:#f5f5f5;
  padding:20px;
}
.page{
  background:#fff;
  max-width:1400px;
  margin:0 auto;
  padding:40px 50px;
  box-shadow:0 4px 20px rgba(0,0,0,.15);
  border-radius:12px;
}
.print-btn{
  display:block;
  text-align:center;
  margin:0 auto 24px;
}
.print-btn button{
  background:#D90429;color:#fff;border:none;
  padding:16px 40px;border-radius:10px;
  cursor:pointer;font-size:20px;font-weight:800;
  box-shadow:0 4px 12px rgba(217,4,41,.4);
}

/* Gigantic Header */
.rpt-title{font-size:32px;font-weight:900;color:#D90429;letter-spacing:.5px;margin-bottom:8px}
.rpt-sub{font-size:16px;color:#444;margin-bottom:20px}
.rpt-pills{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px}
.pill{border-radius:20px;padding:6px 18px;font-size:14px;font-weight:800}
.p-total{background:#f3f4f6;color:#374151}

/* Gigantic Table */
table{width:100%;border-collapse:collapse}
thead th{
  background:#D90429;color:#fff;
  padding:15px 12px;text-align:left;
  font-size:14px;text-transform:uppercase;letter-spacing:1px;
}
tbody td{
  padding:20px 12px;
  border-bottom:2px solid #f0f0f0;
  vertical-align:middle;
  font-size:18px;
}
tbody tr:nth-child(even) td{background:#fafafa}

.card-img{
  width:400px; height:270px;
  object-fit:contain;
  background:#f9f9f9;
  border-radius:8px;
  border:2px solid #ccc;
  cursor:pointer;
}

/* Lightbox */
#lb{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:9999;align-items:center;justify-content:center}
#lb img{max-width:95vw;max-height:90vh;border-radius:10px;object-fit:contain}
#lb-close{position:absolute;top:20px;right:30px;color:#fff;font-size:40px;cursor:pointer;font-weight:700}

/* ── Gigantic PRINT/PDF styles ───────────────────── */
@media print{
  @page{size:A3 landscape;margin:10mm}
  body{background:#fff;padding:0}
  .print-btn{display:none!important}
  .page{max-width:none;width:100%;padding:0;margin:0;box-shadow:none}
  
  table{font-size:18px}
  thead th{font-size:15px;padding:12px 10px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  tbody td{font-size:18px;padding:15px 10px}
  
  .rpt-title{font-size:36px}
  .rpt-sub{font-size:18px}
  .pill{font-size:16px}
  
  .card-img{width:380px; height:260px; border:1px solid #aaa}
  tbody tr{page-break-inside:avoid}
}
</style>
</head>
<body>

<div class="print-btn">
  <button onclick="window.print()">🖨 CLICK HERE TO PRINT GIGANTIC PDF</button>
</div>

<div class="page">
  <div class="rpt-title">Miracle Morning — Visitor Report</div>
  <div class="rpt-sub">
    Week: <strong><?=htmlspecialchars($weekLabel)?></strong> &nbsp;·&nbsp;
    Status: <strong><?=htmlspecialchars($filterStat==='all'?'All':$filterStat)?></strong>
  </div>
  
  <div class="rpt-pills">
    <span class="pill p-total"><?=$totalCount?> Total Visitors</span>
  </div>

  <?php if(!$rows): ?>
  <div style="text-align:center;padding:100px;color:#999;font-size:24px">No visitor records found.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:40px">#</th>
        <th>Visitor Details</th>
        <th>Contact Information</th>
        <th>Invited By / Date</th>
        <th style="min-width:410px;text-align:center">Business Card (Gigantic)</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($rows as $i=>$r): ?>
    <tr>
      <td style="color:#999;text-align:center;font-weight:bold"><?=$i+1?></td>
      <td>
        <div style="font-size:22px;margin-bottom:5px"><strong><?=htmlspecialchars($r['visitor_name']??'')?></strong></div>
        <div style="color:#D90429;font-weight:700"><?=htmlspecialchars($r['visitor_company']??'No Company')?></div>
        <div style="font-size:16px;color:#666"><?=htmlspecialchars($r['visitor_profession']??'')?></div>
      </td>
      <td>
        <div style="margin-bottom:5px">📞 <?=htmlspecialchars($r['visitor_mobile']??'—')?></div>
        <div style="font-size:15px;color:#444">✉ <?=htmlspecialchars($r['visitor_email']??'—')?></div>
      </td>
      <td>
        <div style="margin-bottom:5px">👤 <?=htmlspecialchars($r['referrer_name']??'—')?></div>
        <div style="font-size:15px;color:#666;font-weight:bold">📅 <?=date('d M Y',strtotime($r['friday_date']))?></div>
      </td>
      <td style="text-align:center">
        <?php if($r['business_card']??''): ?>
          <?php $cf = '/api/uploads/cards/'.htmlspecialchars($r['business_card']); ?>
          <?php if(preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $r['business_card'])): ?>
          <img src="<?=$cf?>" alt="Card" class="card-img" onclick="showLB('<?=$cf?>','<?=htmlspecialchars(addslashes($r['visitor_name']))?>')">
          <?php else: ?>
          <a href="<?=$cf?>" target="_blank" style="color:#1565c0;font-weight:900;font-size:18px;text-decoration:none;border:2px solid #1565c0;padding:10px 20px;border-radius:8px">📄 VIEW PDF CARD</a>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:#ddd;font-size:14px">NO IMAGE AVAILABLE</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div style="text-align:right;margin-top:30px;font-size:14px;color:#999;font-weight:bold">
    Miracle Morning · Coimbatore Chapter
  </div>
  <?php endif; ?>
</div>

<div id="lb" onclick="if(event.target===this)closeLB()">
  <span id="lb-close" onclick="closeLB()">×</span>
  <img id="lb-img" src="" alt="">
</div>

<script>
function showLB(src,name){
    document.getElementById('lb-img').src=src;
    document.getElementById('lb').style.display='flex';
}
function closeLB(){
    document.getElementById('lb').style.display='none';
    document.getElementById('lb-img').src='';
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeLB();});
</script>
</body>
</html>