<?php
// member_check.php — Returns member payment history + kitty status
// Called via AJAX from index.php: /api/member_check.php?name=...&Sunday=...

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$name   = trim($_GET['name']   ?? '');
$Sunday = trim($_GET['Sunday'] ?? '');

if (!$name) {
    echo json_encode(['ok' => false]);
    exit;
}

// Validate Sunday Date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $Sunday)) {
    $d = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $Sunday = ((int)$d->format('N') === 7)
        ? $d->format('Y-m-d')
        : (clone $d)->modify('next sunday')->format('Y-m-d');
}

// Strip company suffix if present: "Name - Company" → "Name"
$cleanName = trim(explode(' - ', $name)[0]);

try {
    // Exact match first
    $stmt = $pdo->prepare(
        "SELECT id, name FROM members WHERE name = ? AND status='Active' LIMIT 1"
    );
    $stmt->execute([$cleanName]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        // LIKE fallback — prefix search (safer than full LIKE)
        $stmt = $pdo->prepare(
            "SELECT id, name FROM members WHERE name LIKE ? AND status='Active' ORDER BY name ASC LIMIT 1"
        );
        $stmt->execute([$cleanName . '%']);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$member) {
        echo json_encode(['ok' => false]);
        exit;
    }

    $mid = (int)$member['id'];

    // Payment history — all transactions for this member
    $histQ = $pdo->prepare("
        SELECT friday_date, status, amount, payment_method,
               is_partial, partial_paid, partial_balance
        FROM   transactions
        WHERE  member_id = ? AND type = 'Member'
        ORDER  BY friday_date ASC
    ");
    $histQ->execute([$mid]);
    $history = $histQ->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields properly
    foreach ($history as &$h) {
        $h['amount']          = (int)$h['amount'];
        $h['is_partial']      = (bool)$h['is_partial'];
        $h['partial_paid']    = (int)$h['partial_paid'];
        $h['partial_balance'] = (int)$h['partial_balance'];
    }
    unset($h);

    // Kitty info
    $kittyQ = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END), 0) AS paid
        FROM   kitty_payments
        WHERE  member_id = ?
    ");
    $kittyQ->execute([$mid]);
    $kittyRow = $kittyQ->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'         => true,
        'member_id'  => $mid,
        'name'       => $member['name'],
        'history'    => $history,
        'kitty_paid' => (int)$kittyRow['paid'],
        'kitty_goal' => 3000,
    ]);

} catch (Exception $e) {
    error_log('member_check.php error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Server error']);
}
