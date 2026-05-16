<?php
// tab_queue.php - Verification Queue
// Pending Member, Visitor, and Observer entries in table/list view.

$memList = [];
try {
    $q = $pdo->query("SELECT id, name, company_name FROM members WHERE status='Active' ORDER BY name ASC");
    if ($q) $memList = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$mlJson = json_encode(array_map(function($m){
    return ['id'=>(int)$m['id'], 'n'=>$m['name'], 'c'=>($m['company_name']??'')];
}, $memList));

$prows = [];
try {
    $q = $pdo->query("
        SELECT t.*, m.name AS mname, m.company_name AS member_company
        FROM transactions t
        LEFT JOIN members m ON t.member_id = m.id
        WHERE t.status = 'Pending'
        ORDER BY t.type ASC, t.submitted_at DESC
    ");
    if ($q) $prows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$memberPendGroups = [];
$visPend = [];
$obsPend = [];
foreach ($prows as $r) {
    if ($r['type'] === 'Member') {
        $mid = (int)$r['member_id'];
        $sessionKey = $mid . '_' . $r['submitted_at'];
        if (!isset($memberPendGroups[$sessionKey])) {
            $memberPendGroups[$sessionKey] = [
                'mname'          => $r['mname'],
                'company'        => $r['member_company'] ?? '',
                'member_id'      => $mid,
                'payment_method' => $r['payment_method'],
                'submitted_at'   => $r['submitted_at'],
                'rows'           => [],
                'total'          => 0,
                'ids'            => [],
                'has_partial'    => false,
            ];
        }
        $memberPendGroups[$sessionKey]['rows'][] = $r;
        $memberPendGroups[$sessionKey]['total'] += (float)$r['amount'];
        $memberPendGroups[$sessionKey]['ids'][] = (int)$r['id'];
        if ($r['is_partial']) $memberPendGroups[$sessionKey]['has_partial'] = true;
    } elseif ($r['type'] === 'Visitor') {
        $visPend[] = $r;
    } elseif ($r['type'] === 'Observer') {
        $obsPend[] = $r;
    }
}

$pendTotal = count($memberPendGroups) + count($visPend) + count($obsPend);
?>

<style>
.content.queue-shell {
    height: calc(100vh - 96px);
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 16px 18px;
}
.queue-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(160px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
.queue-stat {
    min-height: 58px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 10px;
    padding: 10px 14px;
    box-sizing: border-box;
}
.queue-stat strong { font-size: 1.25rem; line-height: 1; }
.queue-stat span { font-size: .76rem; color: var(--gry); line-height: 1.25; }
.queue-columns {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    align-items: start;
}
.queue-panel {
    min-width: 0;
    background: #fff;
    border: 1px solid var(--bdr);
    border-radius: 10px;
    overflow: hidden;
}
.queue-panel-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    height: 38px;
    padding: 0 12px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .7px;
    border-bottom: 1px solid var(--bdr);
}
.queue-table-wrap { width: 100%; overflow-x: auto; }
.queue-table {
    width: 100%;
    min-width: 0;
    border-collapse: collapse;
    table-layout: fixed;
}
.queue-table th {
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
.queue-table td {
    padding: 7px 6px;
    border-bottom: 1px solid #ececec;
    vertical-align: middle;
    font-size: .62rem;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.queue-table tr:last-child td { border-bottom: none; }
.queue-primary {
    font-weight: 800;
    color: #222;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.queue-sub {
    display: none;
    color: var(--gry);
    font-size: .56rem;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
.queue-amt { font-weight: 900; white-space: nowrap; text-align: right; }
.queue-chipline {
    display: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    gap: 4px;
    margin-top: 3px;
}
.queue-chip {
    display: inline-flex;
    align-items: center;
    border: 1px solid #ffd6dc;
    border-radius: 12px;
    background: #fff0f2;
    color: var(--red);
    padding: 1px 5px;
    font-size: .52rem;
    white-space: nowrap;
}
.queue-actions {
    display: flex;
    flex-wrap: nowrap;
    gap: 3px;
    align-items: center;
    overflow: hidden;
}
.queue-actions .btn-app,
.queue-actions .btn-rej,
.queue-actions .btn-edt,
.queue-actions button {
    min-height: 25px;
    padding: 4px 7px !important;
    font-size: .58rem !important;
    font-weight: 800 !important;
    border-radius: 5px !important;
    white-space: nowrap;
}
.queue-empty {
    padding: 28px 10px;
    text-align: center;
    color: var(--gry);
    font-size: .82rem;
}
@media (max-width: 1200px) {
    .queue-columns { grid-template-columns: 1fr; }
    .queue-table { min-width: 760px; }
}
@media (max-width: 768px) {
    .content.queue-shell { height: auto; min-height: calc(100vh - 96px); }
    .queue-summary { grid-template-columns: 1fr; }
}
</style>

<div class="content queue-shell">

<div class="queue-summary">
    <div class="queue-stat" style="background:var(--rlt);border:1.5px solid #ffd6dc">
        <strong style="color:var(--red)"><?=$pendTotal?></strong>
        <span>Total Pending</span>
    </div>
    <div class="queue-stat" style="background:#fff;border:1px solid var(--bdr)">
        <strong><?=count($memberPendGroups)?></strong>
        <span>Member Payments</span>
    </div>
    <div class="queue-stat" style="background:#f8fcff;border:1px solid #bbdefb">
        <strong style="color:#1565c0"><?=count($visPend)?></strong>
        <span>Visitors</span>
    </div>
    <div class="queue-stat" style="background:#f8f8f8;border:1px solid var(--bdr)">
        <strong><?=count($obsPend)?></strong>
        <span>Observers</span>
    </div>
</div>

<?php if ($pendTotal === 0): ?>
    <div class="queue-empty">No pending member, visitor, or observer entries.</div>
<?php else: ?>

<div class="queue-columns">
    <section class="queue-panel">
        <div class="queue-panel-hdr" style="color:var(--red)"><span>Member Payments</span><span><?=count($memberPendGroups)?></span></div>
        <div class="queue-table-wrap">
        <table class="queue-table">
            <thead>
                <tr><th style="width:24%">Member</th><th style="width:17%">Company</th><th style="width:15%">Date</th><th style="width:12%;text-align:right">Amount</th><th style="width:32%">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$memberPendGroups): ?>
                <tr><td colspan="5"><div class="queue-empty">No member payments pending.</div></td></tr>
            <?php endif; ?>
            <?php $grpIdx = 0; foreach ($memberPendGroups as $grp):
                $grpIdx++;
                $weekCount = count($grp['rows']);
                $idsJson = htmlspecialchars(json_encode(array_values($grp['ids'])));
                $nm = $grp['mname'];
            ?>
                <tr id="ap_grp_<?=$grpIdx?>">
                    <td>
                        <div class="queue-primary" title="<?=htmlspecialchars($nm)?>"><?=htmlspecialchars($nm)?> <?php if($grp['has_partial']): ?><span class="badge-part" style="font-size:.55rem">Partial</span><?php endif; ?></div>
                        <div class="queue-sub"><?=htmlspecialchars($grp['payment_method']??'')?> · <?=$weekCount?> week<?=$weekCount>1?'s':''?></div>
                        <div class="queue-chipline">
                            <?php foreach($grp['rows'] as $row): ?>
                            <span class="queue-chip"><?=date('d M', strtotime($row['friday_date']))?> · &#8377;<?=number_format((float)$row['amount'])?><?=$row['is_partial']?' partial':''?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td title="<?=htmlspecialchars($grp['company'] ?: '-')?>"><?=htmlspecialchars($grp['company'] ?: '-')?></td>
                    <td><?=date('d M y H:i', strtotime($grp['submitted_at']))?></td>
                    <td class="queue-amt" style="color:var(--red)">&#8377;<?=number_format($grp['total'])?></td>
                    <td>
                        <div class="queue-actions">
                            <button class="btn-app" onclick="actBatch('<?=$idsJson?>','verify','transactions')">Approve<?=$weekCount>1?' All':''?></button>
                            <button class="btn-rej" onclick="actBatch('<?=$idsJson?>','reject','transactions')">Reject</button>
                            <?php if($weekCount === 1): ?>
                            <button class="btn-edt" onclick="openEdit(<?=(int)$grp['ids'][0]?>,'transactions','<?=htmlspecialchars(addslashes($nm))?>',<?=(int)$grp['total']?>,'<?=htmlspecialchars($grp['payment_method']??'')?>')">Edit</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="queue-panel">
        <div class="queue-panel-hdr" style="color:#1565c0"><span>Visitors</span><span><?=count($visPend)?></span></div>
        <div class="queue-table-wrap">
        <table class="queue-table">
            <thead>
                <tr><th style="width:22%">Visitor</th><th style="width:18%">Invited By</th><th style="width:15%">Date</th><th style="width:12%;text-align:right">Amount</th><th style="width:33%">Payment</th></tr>
            </thead>
            <tbody>
            <?php if (!$visPend): ?>
                <tr><td colspan="5"><div class="queue-empty">No visitors pending.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($visPend as $r): ?>
                <tr id="ap_<?=(int)$r['id']?>">
                    <td><div class="queue-primary" style="color:#1565c0" title="<?=htmlspecialchars($r['visitor_name']??'')?>"><?=htmlspecialchars($r['visitor_name']??'')?></div></td>
                    <td title="<?=htmlspecialchars($r['referrer_name'] ?: '-')?>"><?=htmlspecialchars($r['referrer_name'] ?: '-')?></td>
                    <td><?=date('d M y H:i', strtotime($r['submitted_at']))?></td>
                    <td class="queue-amt" style="color:#1565c0">&#8377;<?=number_format($r['amount'])?></td>
                    <td>
                        <div class="queue-actions">
                            <button class="btn-app" onclick="openVisitorPay(<?=(int)$r['id']?>,'<?=htmlspecialchars(addslashes($r['visitor_name']??''))?>')">Approve</button>
                            <button style="background:none;color:#6a1b9a;border:1px solid #ce93d8;cursor:pointer" onclick="openMemberPay(<?=(int)$r['id']?>,'<?=htmlspecialchars(addslashes($r['visitor_name']??''))?>','<?=htmlspecialchars(addslashes($r['referrer_name']??''))?>')">Member</button>
                            <button class="btn-rej" onclick="if(confirm('Remove this visitor entry?'))act(<?=(int)$r['id']?>,'reject','transactions')">Reject</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="queue-panel">
        <div class="queue-panel-hdr" style="color:#444"><span>Observers</span><span><?=count($obsPend)?></span></div>
        <div class="queue-table-wrap">
        <table class="queue-table">
            <thead>
                <tr><th style="width:22%">Name</th><th style="width:18%">Chapter</th><th style="width:15%">Date</th><th style="width:12%;text-align:right">Amount</th><th style="width:33%">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$obsPend): ?>
                <tr><td colspan="5"><div class="queue-empty">No observers pending.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($obsPend as $r):
                $nm = $r['visitor_name'] ?? '';
            ?>
                <tr id="ap_<?=(int)$r['id']?>">
                    <td><div class="queue-primary" title="<?=htmlspecialchars($nm)?>"><?=htmlspecialchars($nm)?></div></td>
                    <td title="<?=htmlspecialchars($r['observer_chapter'] ?: '-')?>"><?=htmlspecialchars($r['observer_chapter'] ?: '-')?></td>
                    <td><?=date('d M y H:i', strtotime($r['submitted_at']))?></td>
                    <td class="queue-amt">&#8377;<?=number_format($r['amount'])?></td>
                    <td>
                        <div class="queue-actions">
                            <button class="btn-app" onclick="act(<?=(int)$r['id']?>,'verify','transactions')">Approve</button>
                            <button class="btn-rej" onclick="act(<?=(int)$r['id']?>,'reject','transactions')">Reject</button>
                            <button class="btn-edt" onclick="openEdit(<?=(int)$r['id']?>,'transactions','<?=htmlspecialchars(addslashes($nm))?>',<?=(int)$r['amount']?>,'<?=htmlspecialchars($r['payment_method']??'')?>')">Edit</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
</div>

<?php endif; ?>
</div>

<script>window.ML = <?=$mlJson?>;</script>
