<?php
// member_card.php — Returns JSON for the member card modal popup
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/name_match_helper.php';
hm_require_login();

header('Content-Type: application/json');
ini_set('display_errors', 0);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['ok'=>false,'msg'=>'Missing member ID']);
    exit;
}

try {
    // ── Member info ───────────────────────────────────────────
    $m = $pdo->prepare("SELECT id, name, company_name, category, mobile, email, status FROM members WHERE id=?");
    $m->execute([$id]);
    $mem = $m->fetch(PDO::FETCH_ASSOC);

    if (!$mem) {
        echo json_encode(['ok'=>false,'msg'=>'Member not found']);
        exit;
    }

    // ── Payment sessions (grouped by submitted_at second) ──────
    // Use COALESCE to prefer original_total, fallback to SUM(amount) for old records
    $pq = $pdo->prepare("
        SELECT 
            DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s') AS session_id,
            DATE(t.submitted_at) AS paid_date,
            GROUP_CONCAT(t.friday_date ORDER BY t.friday_date ASC SEPARATOR ',') AS Sundays,
            COUNT(*) AS week_count,
            MIN(t.payment_method) AS payment_method,
            MAX(t.status) AS status,
            COALESCE(MAX(t.original_total), SUM(t.amount)) AS total_amount
        FROM transactions t
        WHERE t.member_id = ? AND t.type = 'Member'
        GROUP BY session_id
        ORDER BY t.submitted_at DESC
    ");
    $pq->execute([$id]);
    $payment_sessions = [];
    while ($row = $pq->fetch(PDO::FETCH_ASSOC)) {
        $payment_sessions[] = [
            'session_id' => $row['session_id'],
            'paid_date' => $row['paid_date'],
            'Sundays' => explode(',', $row['Sundays']),
            'week_count' => (int)$row['week_count'],
            'payment_method' => $row['payment_method'],
            'status' => $row['status'],
            'total_amount' => (float)$row['total_amount']
        ];
    }

    // ── Kitty payments ────────────────────────────────────────
    $kq = $pdo->prepare("
        SELECT id, DATE(submitted_at) AS submitted_at,
               payment_method, status, amount,
               COALESCE(notes,'') AS notes
        FROM kitty_payments
        WHERE member_id = ?
        ORDER BY submitted_at DESC
        LIMIT 50
    ");
    $kq->execute([$id]);
    $kitty = $kq->fetchAll(PDO::FETCH_ASSOC);
    foreach ($kitty as &$k) {
        $k['id']     = (int)$k['id'];
        $k['amount'] = (float)$k['amount'];
    }
    unset($k);

    // ── Visitor records brought by this member ────────────────
    $nameExprT = hm_member_name_sql_expr('t.referrer_name');
    $nameExprM = hm_member_name_sql_expr('m.name');
    $vq = $pdo->prepare("
        SELECT t.id,
               t.visitor_name,
               COALESCE(t.visitor_profession,'') AS visitor_profession,
               t.friday_date,
               COALESCE(vd.payment_method, t.payment_method) AS payment_method,
               t.status AS txn_status,
               vd.id AS due_id,
               vd.status AS due_status,
               t.amount,
               COALESCE(DATE(vd.paid_at), DATE(t.submitted_at)) AS submitted_at,
               CASE
                 WHEN vd.id IS NOT NULL AND vd.status='Pending' THEN 'Due from Member'
                 WHEN t.status='Paid' THEN 'Paid'
                 WHEN t.status='Rejected' THEN 'Rejected'
                 ELSE t.status
               END AS display_status
        FROM transactions t
        JOIN members m ON m.id = ?
        LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
        WHERE (t.member_id = m.id OR ($nameExprT = $nameExprM AND t.referrer_name <> ''))
          AND t.type = 'Visitor'
        ORDER BY t.friday_date DESC, t.submitted_at DESC
        LIMIT 100
    ");
    $vq->execute([$id]);
    $visitors = $vq->fetchAll(PDO::FETCH_ASSOC);
    foreach ($visitors as &$v) {
        $v['id']     = (int)$v['id'];
        $v['amount'] = (float)$v['amount'];
    }
    unset($v);

    echo json_encode([
        'ok'       => true,
        'id'       => (int)$mem['id'],
        'name'     => $mem['name'],
        'company'  => $mem['company_name'] ?? '',
        'category' => $mem['category'] ?? '',
        'mobile'   => $mem['mobile'] ?? '',
        'email'    => $mem['email'] ?? '',
        'status'   => $mem['status'] ?? 'Active',
        'payment_sessions' => $payment_sessions,
        'kitty'    => $kitty,
        'visitors' => $visitors,
    ]);

} catch (Exception $e) {
    error_log('member_card.php error: ' . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Server error: '.$e->getMessage()]);
}
?>
