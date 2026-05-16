<?php
// Public visitor completion page.
// Anyone with this link can view paid visitors and mark them completed.

require_once __DIR__ . '/db_config.php';

date_default_timezone_set('Asia/Kolkata');

$today = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$defaultSunday = ((int)$today->format('N') === 7)
    ? $today->format('Y-m-d')
    : (clone $today)->modify('next sunday')->format('Y-m-d');
$selectedSunday = $defaultSunday;
if (!empty($_GET['date'])) {
    $dateCheck = DateTime::createFromFormat('Y-m-d', $_GET['date']);
    if ($dateCheck && $dateCheck->format('Y-m-d') === $_GET['date']) {
        $selectedSunday = $_GET['date'];
    }
}
$weekStart = date('Y-m-d', strtotime($selectedSunday . ' -6 days'));

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visitor_completion (
            id INT AUTO_INCREMENT PRIMARY KEY,
            txn_id INT NOT NULL UNIQUE,
            completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_completed_at (completed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    http_response_code(500);
    die('Could not initialize completion tracker.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    header('Content-Type: application/json');
    $txnId = (int)($_POST['txn_id'] ?? 0);
    if ($txnId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid visitor record.']);
        exit;
    }

    try {
        $check = $pdo->prepare("SELECT id FROM transactions WHERE id=? AND type='Visitor' AND status='Paid' LIMIT 1");
        $check->execute([$txnId]);
        if (!$check->fetchColumn()) {
            echo json_encode(['ok' => false, 'msg' => 'Visitor is not approved/paid.']);
            exit;
        }

        $done = $pdo->prepare("INSERT IGNORE INTO visitor_completion (txn_id, completed_at) VALUES (?, NOW())");
        $done->execute([$txnId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Could not mark completed.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retrieve') {
    header('Content-Type: application/json');
    $txnId = (int)($_POST['txn_id'] ?? 0);
    if ($txnId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid visitor record.']);
        exit;
    }

    try {
        $done = $pdo->prepare("DELETE FROM visitor_completion WHERE txn_id=?");
        $done->execute([$txnId]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Could not retrieve visitor.']);
    }
    exit;
}

$paidVisitors = [];
$completedVisitors = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.visitor_name, t.referrer_name, t.verified_at, t.submitted_at
        FROM transactions t
        LEFT JOIN visitor_completion vc ON vc.txn_id = t.id
        WHERE t.type='Visitor'
          AND t.status='Paid'
          AND vc.id IS NULL
          AND DATE(COALESCE(t.verified_at, t.submitted_at)) BETWEEN ? AND ?
        ORDER BY COALESCE(t.verified_at, t.submitted_at) DESC, t.id DESC
    ");
    $stmt->execute([$weekStart, $selectedSunday]);
    $paidVisitors = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmt = $pdo->prepare("
        SELECT t.id, t.visitor_name, t.referrer_name, vc.completed_at
        FROM visitor_completion vc
        JOIN transactions t ON t.id = vc.txn_id
        WHERE t.type='Visitor'
          AND t.status='Paid'
          AND DATE(COALESCE(t.verified_at, t.submitted_at)) BETWEEN ? AND ?
        ORDER BY vc.completed_at DESC
    ");
    $stmt->execute([$weekStart, $selectedSunday]);
    $completedVisitors = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $paidVisitors = [];
    $completedVisitors = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visitor Completion</title>
<style>
:root {
    --red: #D90429;
    --green: #126c2f;
    --blue: #1565c0;
    --text: #151515;
    --muted: #666;
    --border: #dddddd;
    --bg: #f1f3f6;
    --white: #fff;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 14px;
}
.vc-top {
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--white);
    border-bottom: 2px solid var(--red);
    padding: 10px 14px;
    display: grid;
    grid-template-columns: auto minmax(150px, 180px) minmax(220px, 520px) auto;
    gap: 12px;
    align-items: center;
}
.vc-brand {
    font-weight: 900;
    color: var(--red);
    font-size: 1.05rem;
    white-space: nowrap;
}
.vc-search {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
    font: inherit;
    outline: none;
    background: #fafafa;
}
.vc-date {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 10px;
    font: inherit;
    outline: none;
    background: #fafafa;
}
.vc-week {
    color: var(--muted);
    font-size: .72rem;
    font-weight: 700;
    white-space: nowrap;
}
.vc-search:focus {
    border-color: var(--red);
    background: #fff;
}
.vc-counts {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    white-space: nowrap;
}
.vc-pill {
    border: 1px solid var(--border);
    border-radius: 999px;
    background: #fff;
    padding: 5px 10px;
    font-size: .76rem;
    font-weight: 700;
}
.vc-wrap {
    padding: 14px;
}
.vc-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 14px;
    align-items: start;
}
.vc-panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}
.vc-head {
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 0 12px;
    border-bottom: 1px solid var(--border);
    font-size: .75rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .7px;
}
.vc-table-wrap {
    width: 100%;
    overflow-x: auto;
}
.vc-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.vc-table th {
    background: #f7f7f7;
    color: #777;
    font-size: .66rem;
    font-weight: 900;
    letter-spacing: .6px;
    text-align: left;
    text-transform: uppercase;
    padding: 9px 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.vc-table td {
    padding: 10px;
    border-bottom: 1px solid #eeeeee;
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.vc-table tr:last-child td { border-bottom: none; }
.vc-name {
    font-weight: 800;
    overflow: hidden;
    text-overflow: ellipsis;
}
.vc-muted {
    color: var(--muted);
}
.vc-btn {
    background: var(--green);
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 7px 13px;
    font-size: .78rem;
    font-weight: 900;
    cursor: pointer;
    white-space: nowrap;
}
.vc-btn.retrieve {
    background: #fff;
    color: var(--blue);
    border: 1px solid #9ecbff;
}
.vc-btn:disabled {
    opacity: .55;
    cursor: wait;
}
.vc-empty {
    padding: 28px 12px;
    text-align: center;
    color: var(--muted);
    font-size: .86rem;
}
.vc-row-moving {
    opacity: .35;
    pointer-events: none;
}
@media (max-width: 860px) {
    .vc-top {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .vc-brand,
    .vc-search,
    .vc-counts {
        grid-column: 1 / -1;
    }
    .vc-counts {
        justify-content: flex-start;
        overflow-x: auto;
        padding-bottom: 1px;
    }
    .vc-grid {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 8px;
    }
}
@media (max-width: 520px) {
    body { font-size: 13px; }
    .vc-top {
        position: static;
        padding: 10px;
    }
    .vc-brand {
        font-size: 1rem;
    }
    .vc-search {
        height: 42px;
        font-size: 16px;
    }
    .vc-wrap {
        padding: 8px;
    }
    .vc-panel {
        border-radius: 8px;
    }
    .vc-head {
        height: 40px;
        padding: 0 10px;
    }
    .vc-table-wrap {
        overflow-x: visible;
    }
    .vc-table,
    .vc-table thead,
    .vc-table tbody,
    .vc-table tr,
    .vc-table td {
        display: block;
        width: 100%;
    }
    .vc-table thead {
        display: none;
    }
    .vc-table tr {
        padding: 10px;
        border-bottom: 1px solid #eeeeee;
    }
    .vc-table tr:last-child {
        border-bottom: none;
    }
    .vc-table td {
        border-bottom: none;
        padding: 3px 0;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
    }
    .vc-table td:nth-child(1)::before {
        content: "Visitor";
        display: block;
        color: #888;
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .5px;
        text-transform: uppercase;
        margin-bottom: 2px;
    }
    .vc-table td:nth-child(2)::before {
        content: "Invited By";
        display: block;
        color: #888;
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .5px;
        text-transform: uppercase;
        margin-top: 6px;
        margin-bottom: 2px;
    }
    #paidTable td:nth-child(3)::before {
        content: "";
        display: none;
    }
    .vc-name {
        font-size: .88rem;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
        line-height: 1.25;
    }
    .vc-muted {
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
    }
    .vc-btn {
        width: 100%;
        min-height: 38px;
        margin-top: 10px;
        padding: 10px 12px;
        font-size: .82rem;
    }
    .vc-empty-row {
        padding: 0 !important;
    }
    .vc-empty-row td::before {
        display: none !important;
    }
}
</style>
</head>
<body>
<div class="vc-top">
    <div class="vc-brand">Miracle Morning Visitors</div>
    <div>
        <input class="vc-date" id="SundayDate" type="date" value="<?=htmlspecialchars($selectedSunday)?>" onchange="changeSunday(this.value)">
        <div class="vc-week"><?=date('d M', strtotime($weekStart))?> - <?=date('d M Y', strtotime($selectedSunday))?></div>
    </div>
    <input class="vc-search" id="searchBox" type="search" placeholder="Search visitor or invited by..." oninput="filterRows()">
    <div class="vc-counts">
        <span class="vc-pill">Paid <strong id="paidCount"><?=count($paidVisitors)?></strong></span>
        <span class="vc-pill">Completed <strong id="completedCount"><?=count($completedVisitors)?></strong></span>
    </div>
</div>

<div class="vc-wrap">
    <div class="vc-grid">
        <section class="vc-panel">
            <div class="vc-head" style="color:var(--blue)">
                <span>Paid Visitor</span>
                <span id="paidPanelCount"><?=count($paidVisitors)?></span>
            </div>
            <div class="vc-table-wrap">
                <table class="vc-table" id="paidTable">
                    <thead>
                        <tr>
                            <th style="width:42%">Visitor Name</th>
                            <th style="width:34%">Invited By</th>
                            <th style="width:24%">Complete</th>
                        </tr>
                    </thead>
                    <tbody id="paidBody">
                    <?php if (!$paidVisitors): ?>
                        <tr class="vc-empty-row"><td colspan="3"><div class="vc-empty">No approved visitors waiting.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($paidVisitors as $v): ?>
                        <tr data-id="<?=(int)$v['id']?>" data-name="<?=htmlspecialchars(strtolower(($v['visitor_name'] ?? '').' '.($v['referrer_name'] ?? '')))?>">
                            <td><div class="vc-name" title="<?=htmlspecialchars($v['visitor_name'] ?? '')?>"><?=htmlspecialchars($v['visitor_name'] ?? '')?></div></td>
                            <td class="vc-muted" title="<?=htmlspecialchars($v['referrer_name'] ?? '')?>"><?=htmlspecialchars($v['referrer_name'] ?: '-')?></td>
                            <td><button class="vc-btn" onclick="completeVisitor(this, <?=(int)$v['id']?>)">Complete</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="vc-panel">
            <div class="vc-head" style="color:var(--green)">
                <span>Completed</span>
                <span id="completedPanelCount"><?=count($completedVisitors)?></span>
            </div>
            <div class="vc-table-wrap">
                <table class="vc-table" id="completedTable">
                    <thead>
                        <tr>
                            <th style="width:42%">Visitor Name</th>
                            <th style="width:34%">Invited By</th>
                            <th style="width:24%">Retrieve</th>
                        </tr>
                    </thead>
                    <tbody id="completedBody">
                    <?php if (!$completedVisitors): ?>
                        <tr class="vc-empty-row"><td colspan="3"><div class="vc-empty">No completed visitors yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($completedVisitors as $v): ?>
                        <tr data-id="<?=(int)$v['id']?>" data-name="<?=htmlspecialchars(strtolower(($v['visitor_name'] ?? '').' '.($v['referrer_name'] ?? '')))?>">
                            <td><div class="vc-name" title="<?=htmlspecialchars($v['visitor_name'] ?? '')?>"><?=htmlspecialchars($v['visitor_name'] ?? '')?></div></td>
                            <td class="vc-muted" title="<?=htmlspecialchars($v['referrer_name'] ?? '')?>"><?=htmlspecialchars($v['referrer_name'] ?: '-')?></td>
                            <td><button class="vc-btn retrieve" onclick="retrieveVisitor(this, <?=(int)$v['id']?>)">Retrieve</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
function changeSunday(dateValue) {
    if (!dateValue) return;
    window.location.href = 'visitor_completion.php?date=' + encodeURIComponent(dateValue);
}

function postComplete(txnId) {
    var body = new URLSearchParams();
    body.append('action', 'complete');
    body.append('txn_id', txnId);
    return fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    }).then(function(r){ return r.json(); });
}

function postRetrieve(txnId) {
    var body = new URLSearchParams();
    body.append('action', 'retrieve');
    body.append('txn_id', txnId);
    return fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    }).then(function(r){ return r.json(); });
}

function completeVisitor(btn, txnId) {
    var row = btn.closest('tr');
    if (!row) return;
    btn.disabled = true;
    row.classList.add('vc-row-moving');
    postComplete(txnId).then(function(d) {
        if (!d.ok) {
            alert(d.msg || 'Could not complete visitor.');
            btn.disabled = false;
            row.classList.remove('vc-row-moving');
            return;
        }
        moveToCompleted(row);
    }).catch(function(e) {
        alert('Request failed: ' + e.message);
        btn.disabled = false;
        row.classList.remove('vc-row-moving');
    });
}

function moveToCompleted(row) {
    var visitor = row.cells[0].innerHTML;
    var invited = row.cells[1].innerHTML;
    var dataName = row.getAttribute('data-name') || '';
    row.remove();

    var completedBody = document.getElementById('completedBody');
    removeEmptyRows(completedBody);

    var newRow = document.createElement('tr');
    newRow.setAttribute('data-name', dataName);
    var txnId = row.getAttribute('data-id') || '';
    newRow.setAttribute('data-id', txnId);
    newRow.innerHTML = '<td>' + visitor + '</td><td class="vc-muted">' + invited + '</td><td><button class="vc-btn retrieve" onclick="retrieveVisitor(this, ' + txnId + ')">Retrieve</button></td>';
    completedBody.insertBefore(newRow, completedBody.firstChild);

    syncEmptyStates();
    filterRows();
}

function retrieveVisitor(btn, txnId) {
    var row = btn.closest('tr');
    if (!row) return;
    btn.disabled = true;
    row.classList.add('vc-row-moving');
    postRetrieve(txnId).then(function(d) {
        if (!d.ok) {
            alert(d.msg || 'Could not retrieve visitor.');
            btn.disabled = false;
            row.classList.remove('vc-row-moving');
            return;
        }
        moveToPaid(row);
    }).catch(function(e) {
        alert('Request failed: ' + e.message);
        btn.disabled = false;
        row.classList.remove('vc-row-moving');
    });
}

function moveToPaid(row) {
    var visitor = row.cells[0].innerHTML;
    var invited = row.cells[1].innerHTML;
    var dataName = row.getAttribute('data-name') || '';
    var txnId = row.getAttribute('data-id') || '';
    row.remove();

    var paidBody = document.getElementById('paidBody');
    removeEmptyRows(paidBody);

    var newRow = document.createElement('tr');
    newRow.setAttribute('data-id', txnId);
    newRow.setAttribute('data-name', dataName);
    newRow.innerHTML = '<td>' + visitor + '</td><td class="vc-muted">' + invited + '</td><td><button class="vc-btn" onclick="completeVisitor(this, ' + txnId + ')">Complete</button></td>';
    paidBody.insertBefore(newRow, paidBody.firstChild);

    syncEmptyStates();
    filterRows();
}

function removeEmptyRows(body) {
    body.querySelectorAll('.vc-empty-row').forEach(function(r){ r.remove(); });
}

function syncEmptyStates() {
    var paidBody = document.getElementById('paidBody');
    var completedBody = document.getElementById('completedBody');
    removeEmptyRows(paidBody);
    removeEmptyRows(completedBody);

    var paidRows = paidBody.querySelectorAll('tr:not(.vc-empty-row)');
    var completedRows = completedBody.querySelectorAll('tr:not(.vc-empty-row)');

    if (!paidRows.length) {
        paidBody.innerHTML = '<tr class="vc-empty-row"><td colspan="3"><div class="vc-empty">No approved visitors waiting.</div></td></tr>';
    }
    if (!completedRows.length) {
        completedBody.innerHTML = '<tr class="vc-empty-row"><td colspan="3"><div class="vc-empty">No completed visitors yet.</div></td></tr>';
    }

    document.getElementById('paidCount').textContent = paidRows.length;
    document.getElementById('paidPanelCount').textContent = paidRows.length;
    document.getElementById('completedCount').textContent = completedRows.length;
    document.getElementById('completedPanelCount').textContent = completedRows.length;
}

function filterRows() {
    var q = (document.getElementById('searchBox').value || '').trim().toLowerCase();
    document.querySelectorAll('#paidBody tr:not(.vc-empty-row), #completedBody tr:not(.vc-empty-row)').forEach(function(row) {
        var hay = row.getAttribute('data-name') || '';
        row.style.display = !q || hay.indexOf(q) >= 0 ? '' : 'none';
    });
}
</script>
</body>
</html>
