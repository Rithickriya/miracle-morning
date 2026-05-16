<?php
// tab_exports.php — handles ?export=excel|pdf|weekly_excel
$exp_type = $_GET['export'];

// Validate export date — must be a real Y-m-d date
$_rawDate = $_GET['date'] ?? $sel;
$_dCheck  = DateTime::createFromFormat('Y-m-d', $_rawDate);
$exp_date = ($_dCheck && $_dCheck->format('Y-m-d') === $_rawDate) ? $_rawDate : $sel;

if ($exp_type === 'weekly_excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="weekly_report_'.date('d-M-Y',strtotime($exp_date)).'.xls"');
    echo "\xEF\xBB\xBF";

    $d = $exp_date;

    // ── Totals
    $_tE = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND friday_date=? AND type=?");
    $_tE->execute([$d,'Member']);   $xMemTot = (float)$_tE->fetchColumn();
    $_tE->execute([$d,'Visitor']);  $xVisTot = (float)$_tE->fetchColumn();
    $_tE->execute([$d,'Observer']); $xObsTot = (float)$_tE->fetchColumn();
    $xGrand = $xMemTot + $xVisTot + $xObsTot;

    echo "Miracle Morning — WEEKLY REPORT\tDATE:\t".date('d/m/Y',strtotime($d))."\n";
    echo "Grand Total:\t₹".$xGrand."\tMembers:\t₹".$xMemTot."\tVisitors:\t₹".$xVisTot."\tObservers:\t₹".$xObsTot."\n\n";

    // ── Payment Mode Breakdown
    echo "PAYMENT MODE BREAKDOWN\n";
    echo "Mode\tCount\tAmount\n";
    $xModes = $pdo->prepare("SELECT payment_method,SUM(amount) t,COUNT(*) n FROM transactions WHERE status='Paid' AND friday_date=? GROUP BY payment_method ORDER BY t DESC");
    $xModes->execute([$d]);
    foreach ($xModes->fetchAll() as $r) echo $r['payment_method']."\t".$r['n']."\t₹".$r['t']."\n";
    echo "\n";

    // ── Members Paid
    echo "MEMBERS PAID\n";
    echo "#\tName\tCategory\tWeeks\tMode\tAmount Paid\n";
    $xMP = $pdo->prepare("SELECT m.name,m.category,MAX(t.payment_method) AS mode,COUNT(*) wks,SUM(CASE WHEN t.is_partial=1 THEN t.partial_paid ELSE t.amount END) amt FROM transactions t JOIN members m ON t.member_id=m.id WHERE t.type='Member' AND t.status='Paid' AND t.friday_date=? GROUP BY m.id,m.name,m.category ORDER BY m.name");
    $xMP->execute([$d]); $xi=1;
    foreach ($xMP->fetchAll() as $r) { echo "$xi\t".$r['name']."\t".($r['category']??'')."\t".$r['wks']."\t".$r['mode']."\t₹".$r['amt']."\n"; $xi++; }
    echo "\n";

    // ── Visitors Paid
    echo "VISITORS PAID\n";
    echo "#\tName\tCategory\tInvited By\tPaid By Member\tMode\tAmount\n";
    try {
        $xVP = $pdo->prepare("SELECT t.visitor_name,t.visitor_profession,t.referrer_name,t.payment_method,t.amount,m2.name AS paid_by FROM transactions t LEFT JOIN visitor_dues vd ON vd.txn_id=t.id LEFT JOIN members m2 ON m2.id=vd.member_id WHERE t.type='Visitor' AND t.status='Paid' AND t.friday_date=? ORDER BY t.visitor_name");
    } catch (Exception $e) {
        $xVP = $pdo->prepare("SELECT visitor_name,visitor_profession,referrer_name,payment_method,amount,NULL AS paid_by FROM transactions WHERE type='Visitor' AND status='Paid' AND friday_date=? ORDER BY visitor_name");
    }
    $xVP->execute([$d]); $xi=1;
    foreach ($xVP->fetchAll() as $r) { echo "$xi\t".$r['visitor_name']."\t".($r['visitor_profession']??'')."\t".($r['referrer_name']??'')."\t".($r['paid_by']??'')."\t".$r['payment_method']."\t₹".$r['amount']."\n"; $xi++; }
    echo "\n";

    // ── Observers Paid
    echo "OBSERVERS PAID\n";
    echo "#\tName\tChapter\tCategory\tMode\tAmount\n";
    $xOP = $pdo->prepare("SELECT visitor_name,observer_chapter,observer_category,payment_method,amount FROM transactions WHERE type='Observer' AND status='Paid' AND friday_date=? ORDER BY visitor_name");
    $xOP->execute([$d]); $xi=1;
    foreach ($xOP->fetchAll() as $r) { echo "$xi\t".$r['visitor_name']."\t".($r['observer_chapter']??'')."\t".($r['observer_category']??'')."\t".$r['payment_method']."\t₹".$r['amount']."\n"; $xi++; }
    echo "\n";

    // ── Members NOT Paid
    echo "MEMBERS NOT PAID\n";
    echo "#\tName\tCategory\tDue Amount\n";
    $xPaidQ = $pdo->prepare("SELECT DISTINCT member_id FROM transactions WHERE type='Member' AND status='Paid' AND friday_date=?");
    $xPaidQ->execute([$d]);
    $xPaidIds = array_flip($xPaidQ->fetchAll(PDO::FETCH_COLUMN));
    $xAllMem = $pdo->query("SELECT id,name,category FROM members WHERE status='Active' ORDER BY name")->fetchAll();
    $xi=1;
    foreach (array_filter($xAllMem, fn($m)=>!isset($xPaidIds[$m['id']])) as $m) {
        echo "$xi\t".$m['name']."\t".($m['category']??'')."\t₹1,450\n"; $xi++;
    }
    echo "\n";

    // ── Visitor Dues Pending
    echo "VISITOR DUES PENDING\n";
    echo "#\tVisitor Name\tCollect From Member\tAmount\n";
    try {
        $xDues = $pdo->query("SELECT vd.visitor_name,vd.amount,m.name AS mname FROM visitor_dues vd JOIN members m ON m.id=vd.member_id WHERE vd.status='Pending' ORDER BY m.name,vd.visitor_name");
        $xi=1;
        foreach (($xDues?$xDues->fetchAll():[]) as $r) { echo "$xi\t".$r['visitor_name']."\t".$r['mname']."\t₹".$r['amount']."\n"; $xi++; }
    } catch (Exception $e) { echo "(visitor_dues table not found)\n"; }
    exit;
}

if ($exp_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="miracle_morning_report_'.date('d-M-Y',strtotime($exp_date)).'.xls"');
    echo "\xEF\xBB\xBF";
    echo "MEMBER PAYMENTS\nName\tAmount\tPayment Mode\tStatus\tDate\n";
    $ms=$pdo->prepare("SELECT m.name,t.amount,t.payment_method,t.status,t.friday_date FROM transactions t JOIN members m ON t.member_id=m.id WHERE t.type='Member' AND t.friday_date=? ORDER BY t.status,m.name");
    $ms->execute([$exp_date]);foreach($ms->fetchAll() as $r)echo implode("\t",[$r['name'],'₹'.$r['amount'],$r['payment_method'],$r['status'],date('d M Y',strtotime($r['friday_date']))])."\n";
    echo "\nVISITOR PAYMENTS\nName\tCategory\tInvited By\tAmount\tMode\tStatus\n";
    $vs=$pdo->prepare("SELECT visitor_name,visitor_profession,referrer_name,amount,payment_method,status FROM transactions WHERE type='Visitor' AND friday_date=? ORDER BY status");
    $vs->execute([$exp_date]);foreach($vs->fetchAll() as $r)echo implode("\t",[$r['visitor_name'],$r['visitor_profession']??'',$r['referrer_name']??'',$r['amount'],$r['payment_method'],$r['status']])."\n";
    echo "\nOBSERVER PAYMENTS\nName\tChapter\tCategory\tAmount\tMode\tStatus\n";
    $os=$pdo->prepare("SELECT visitor_name,observer_chapter,observer_category,amount,payment_method,status FROM transactions WHERE type='Observer' AND friday_date=? ORDER BY status");
    $os->execute([$exp_date]);foreach($os->fetchAll() as $r)echo implode("\t",[$r['visitor_name'],$r['observer_chapter']??'',$r['observer_category']??'',$r['amount'],$r['payment_method'],$r['status']])."\n";
    echo "\nKITTY PAYMENTS\nMember\tAmount\tMode\tStatus\tDate\n";
    $ks=$pdo->query("SELECT m.name,k.amount,k.payment_method,k.status,k.submitted_at FROM kitty_payments k JOIN members m ON k.member_id=m.id ORDER BY k.submitted_at DESC");
    foreach($ks->fetchAll() as $r)echo implode("\t",[$r['name'],'₹'.$r['amount'],$r['payment_method'],$r['status'],date('d M Y',strtotime($r['submitted_at']))])."\n";
    exit;
}

if ($exp_type === 'pdf') {
    $pdate = date('d M Y', strtotime($exp_date));
    $ms2=$pdo->prepare("SELECT m.name,t.payment_method,t.status,(SELECT SUM(t2.amount) FROM transactions t2 WHERE t2.member_id=t.member_id AND t2.type='Member' AND t2.status='Paid' AND DATE_FORMAT(t2.submitted_at,'%Y-%m-%d %H:%i:%s')=DATE_FORMAT(t.submitted_at,'%Y-%m-%d %H:%i:%s')) AS total FROM transactions t JOIN members m ON t.member_id=m.id WHERE t.type='Member' AND t.friday_date=? GROUP BY t.member_id,DATE_FORMAT(t.submitted_at,'%Y-%m-%d %H:%i:%s'),t.payment_method,t.status,m.name ORDER BY t.status,m.name");
    $ms2->execute([$exp_date]);$mrows=$ms2->fetchAll();
    $vs2=$pdo->prepare("SELECT visitor_name,visitor_profession,referrer_name,amount,payment_method,status FROM transactions WHERE type='Visitor' AND friday_date=? ORDER BY status");$vs2->execute([$exp_date]);$vrows=$vs2->fetchAll();
    $os2=$pdo->prepare("SELECT visitor_name,observer_chapter,observer_category,amount,payment_method,status FROM transactions WHERE type='Observer' AND friday_date=? ORDER BY status");$os2->execute([$exp_date]);$orows=$os2->fetchAll();
    $mcash=array_sum(array_column(array_filter($mrows,function($r){return $r['status']==='Paid';}),'total'));
    $vcash=array_sum(array_column(array_filter($vrows,function($r){return $r['status']==='Paid';}),'amount'));
    $ocash=array_sum(array_column(array_filter($orows,function($r){return $r['status']==='Paid';}),'amount'));
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report — <?=$pdate?></title>
<style>body{font-family:Arial,sans-serif;font-size:11px}h2{color:#D90429;margin:14px 0 6px}table{width:100%;border-collapse:collapse;margin-bottom:14px}th{background:#D90429;color:#fff;padding:5px 8px;text-align:left}td{padding:4px 8px;border-bottom:1px solid #eee}.no-print{padding:10px;background:#f0f0f0;margin-bottom:16px}@media print{.no-print{display:none}}</style>
</head><body>
<div class="no-print"><button onclick="window.print()" style="background:#D90429;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">Print / Save as PDF</button></div>
<div style="text-align:center;border-bottom:2px solid #D90429;margin-bottom:14px;padding-bottom:8px"><div style="font-size:14px;font-weight:bold">Miracle Morning — WEEKLY PAYMENT REPORT</div><div>Coimbatore Chapter · <?=$pdate?></div></div>
<h2>Member Payments</h2><table><tr><th>#</th><th>Name</th><th>Amount</th><th>Mode</th><th>Status</th></tr>
<?php foreach($mrows as $i=>$r):?><tr><td><?=$i+1?></td><td><?=$r['name']?></td><td>₹<?=number_format($r['total']??$r['amount']??0)?></td><td><?=$r['payment_method']?></td><td><?=$r['status']?></td></tr><?php endforeach;?>
</table>
<h2>Visitor Records</h2><table><tr><th>#</th><th>Name</th><th>Category</th><th>Invited By</th><th>Amount</th><th>Mode</th><th>Status</th></tr>
<?php foreach($vrows as $i=>$r):?><tr><td><?=$i+1?></td><td><?=$r['visitor_name']?></td><td><?=$r['visitor_profession']??''?></td><td><?=$r['referrer_name']??''?></td><td>₹<?=$r['amount']?></td><td><?=$r['payment_method']?></td><td><?=$r['status']?></td></tr><?php endforeach;?>
</table>
<h2>Observer Records</h2><table><tr><th>#</th><th>Name</th><th>Chapter</th><th>Category</th><th>Amount</th><th>Mode</th><th>Status</th></tr>
<?php foreach($orows as $i=>$r):?><tr><td><?=$i+1?></td><td><?=$r['visitor_name']?></td><td><?=$r['observer_chapter']??''?></td><td><?=$r['observer_category']??''?></td><td>₹<?=$r['amount']?></td><td><?=$r['payment_method']?></td><td><?=$r['status']?></td></tr><?php endforeach;?>
</table>
<table style="width:300px;margin-left:auto"><tr><th colspan="2">Collection Summary</th></tr>
<tr><td>Members</td><td>₹<?=number_format($mcash)?></td></tr><tr><td>Visitors</td><td>₹<?=number_format($vcash)?></td></tr><tr><td>Observers</td><td>₹<?=number_format($ocash)?></td></tr><tr><td><strong>Grand Total</strong></td><td><strong>₹<?=number_format($mcash+$vcash+$ocash)?></strong></td></tr></table>
</body></html><?php exit; }
