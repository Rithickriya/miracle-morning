<?php
// member_action.php — Add / Edit / Delete members
require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/name_match_helper.php';
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$action = trim($_POST['action'] ?? '');
if (!hm_is_admin() && !in_array($action, ['edit_visitor_txn', 'delete_visitor_txn'], true)) {
    echo json_encode(['ok'=>false,'msg'=>'Admin access required']);
    exit;
}

function mclean($key, $max = 255) {
    return substr(trim($_POST[$key] ?? ''), 0, $max);
}
function mnormMobile($raw) {
    $m = preg_replace('/\s+/', '', $raw);
    if (strlen($m) === 12 && substr($m, 0, 2) === '91') $m = substr($m, 2);
    return $m;
}

// Detect which columns exist in members table (mobile/email may not exist)
$memCols = [];
try {
    $memCols = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
$hasMobile = in_array('mobile', $memCols);
$hasEmail  = in_array('email',  $memCols);

if ($action === 'add') {
    $name    = mclean('name');
    $company = mclean('company_name');
    $cat     = mclean('category');

    if (!$name) { echo json_encode(['ok'=>false,'msg'=>'Name is required.']); exit; }

    $chk = $pdo->prepare("SELECT id FROM members WHERE name=? AND status='Active' LIMIT 1");
    $chk->execute([$name]);
    if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Member with this name already exists.']); exit; }

    try {
        if ($hasMobile && $hasEmail) {
            $mob   = mnormMobile(mclean('mobile', 20));
            $email = mclean('email');
            $pdo->prepare("INSERT INTO members (name, company_name, category, mobile, email, status) VALUES (?, ?, ?, ?, ?, 'Active')")
                ->execute([$name, $company, $cat, $mob, $email]);
        } else {
            $pdo->prepare("INSERT INTO members (name, company_name, category, status) VALUES (?, ?, ?, 'Active')")
                ->execute([$name, $company, $cat]);
        }
        echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        error_log('member_action add: '.$e->getMessage());
        error_log('DB error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
    }
    exit;
}

if ($action === 'edit') {
    $id      = (int)($_POST['id'] ?? 0);
    $name    = mclean('name');
    $company = mclean('company_name');
    $cat     = mclean('category');
    $status  = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';

    if (!$id || !$name) { echo json_encode(['ok'=>false,'msg'=>'Missing fields.']); exit; }

    $chk = $pdo->prepare("SELECT id FROM members WHERE name=? AND id != ? AND status='Active' LIMIT 1");
    $chk->execute([$name, $id]);
    if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Another active member has this name.']); exit; }

    try {
        if ($hasMobile && $hasEmail) {
            $mob   = mnormMobile(mclean('mobile', 20));
            $email = mclean('email');
            $pdo->prepare("UPDATE members SET name=?, company_name=?, category=?, mobile=?, email=?, status=? WHERE id=?")
                ->execute([$name, $company, $cat, $mob, $email, $status, $id]);
        } else {
            $pdo->prepare("UPDATE members SET name=?, company_name=?, category=?, status=? WHERE id=?")
                ->execute([$name, $company, $cat, $status, $id]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        error_log('member_action edit: '.$e->getMessage());
        error_log('DB error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
    }
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing ID.']); exit; }
    try {
        $pdo->prepare("UPDATE members SET status='Inactive' WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        error_log('DB error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
    }
    exit;
}

// ── MEMBER PAYMENT SESSION (debt amortization with original_total) ──
if ($action === 'edit_member_session') {
    $member_id = (int)($_POST['member_id'] ?? 0);
    $session_id = trim($_POST['session_id'] ?? '');
    $paid_date = trim($_POST['paid_date'] ?? '');
    $total_amount = (int)($_POST['total_amount'] ?? 0);
    $mode = substr(trim($_POST['mode'] ?? ''), 0, 100);
    $session_status = in_array($_POST['status'] ?? '', ['Paid','Pending','Rejected']) ? $_POST['status'] : 'Pending';

    if (!$member_id || !$paid_date || $total_amount <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'Missing required fields or invalid amount']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid paid date format']); exit;
    }

    $week_fee = 1450;
    $remaining = $total_amount;

    // Get global first Sunday (earliest meeting)
    $globalFirst = $pdo->query("SELECT MIN(friday_date) FROM transactions WHERE type='Member'")->fetchColumn();
    if (!$globalFirst) {
        $tmp = new DateTime($paid_date);
        if ((int)$tmp->format('N') !== 7) $tmp->modify('next sunday');
        $globalFirst = $tmp->format('Y-m-d');
    }

    try {
        $pdo->beginTransaction();

        // If editing an existing session, delete its old rows
        if (!empty($session_id)) {
            // Fetch original_total before deleting
            $origQ = $pdo->prepare("SELECT original_total FROM transactions WHERE member_id=? AND type='Member' AND DATE_FORMAT(submitted_at,'%Y-%m-%d %H:%i:%s')=? LIMIT 1");
            $origQ->execute([$member_id, $session_id]);
            $origRow = $origQ->fetch();
            $original_total = ($origRow && $origRow['original_total'] !== null) ? (int)$origRow['original_total'] : $total_amount;
            
            $pdo->prepare("DELETE FROM transactions WHERE member_id=? AND type='Member' AND DATE_FORMAT(submitted_at,'%Y-%m-%d %H:%i:%s')=?")
                ->execute([$member_id, $session_id]);
        } else {
            $original_total = $total_amount;
        }

        $submitted_at = $paid_date . ' ' . date('H:i:s'); // keep date, use current time for unique session
        $now = date('Y-m-d H:i:s');

        $insertFull = $pdo->prepare("INSERT INTO transactions (member_id, type, amount, payment_method, friday_date, status, submitted_at, verified_at, original_total) VALUES (?, 'Member', ?, ?, ?, ?, ?, ?, ?)");
        $insertPartial = $pdo->prepare("INSERT INTO transactions (member_id, type, amount, payment_method, friday_date, status, submitted_at, verified_at, is_partial, partial_paid, partial_balance, original_total) VALUES (?, 'Member', ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");

        // Find ALL Sundays that already have a row for this member (any status)
        $existingSundays = [];
        $eQ = $pdo->prepare("SELECT DISTINCT friday_date FROM transactions WHERE member_id=? AND type='Member'");
        $eQ->execute([$member_id]);
        while ($row = $eQ->fetch(PDO::FETCH_ASSOC)) {
            $existingSundays[$row['friday_date']] = true;
        }

        // Build list of EMPTY Sundays (no existing rows) from globalFirst onwards
        $current = new DateTime($globalFirst);
        $endDate = new DateTime(date('Y-m-d'));
        $endDate->modify('+2 years');

        while ($current <= $endDate) {
            if ($remaining <= 0) break;
            $fd = $current->format('Y-m-d');
            $current->modify('+7 days');

            // Skip Sundays that already have a row — sessions are independent
            if (isset($existingSundays[$fd])) continue;

            if ($remaining >= $week_fee) {
                $ver_at = ($session_status === 'Paid') ? $now : null;
                $insertFull->execute([$member_id, $week_fee, $mode, $fd, $session_status, $submitted_at, $ver_at, $original_total]);
                $remaining -= $week_fee;
            } else {
                $newBalance = $week_fee - $remaining;
                $insertPartial->execute([$member_id, $remaining, $mode, $fd, $session_status, $submitted_at, $session_status === 'Paid' ? $now : null, $remaining, $newBalance, $original_total]);
                $remaining = 0;
            }
        }

        $pdo->commit();
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('edit_member_session error: ' . $e->getMessage());
        echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
    }
    exit;
}

// ── DELETE MEMBER PAYMENT SESSION (second precision) ──
if ($action === 'delete_member_session') {
    $member_id = (int)($_POST['member_id'] ?? 0);
    $session_id = trim($_POST['session_id'] ?? '');
    if (!$member_id || !$session_id) {
        echo json_encode(['ok'=>false,'msg'=>'Missing parameters']); exit;
    }
    try {
        $pdo->prepare("DELETE FROM transactions WHERE member_id=? AND type='Member' AND DATE_FORMAT(submitted_at,'%Y-%m-%d %H:%i:%s')=?")
            ->execute([$member_id, $session_id]);
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        error_log('Error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'An error occurred. Please try again.']);
    }
    exit;
}

// ── EDIT / DELETE TRANSACTIONS (single row – legacy) ─────────────────────────
if ($action === 'edit_txn')    { runTxnEdit($pdo);    exit; }
if ($action === 'delete_txn')  { runTxnDelete($pdo);  exit; }
if ($action === 'edit_kitty')  { runKittyEdit($pdo);  exit; }
if ($action === 'delete_kitty'){ runKittyDelete($pdo); exit; }

// ── DELETE VISITOR TRANSACTION ────────────────────────────────────────────────
if ($action === 'delete_visitor_txn') {
    $txnId = (int)($_POST['id'] ?? 0);
    if (!$txnId) { echo json_encode(['ok'=>false,'msg'=>'Missing transaction ID.']); exit; }
    try {
        $pdo->beginTransaction();

        try {
            $pdo->prepare("DELETE FROM visitor_completion WHERE txn_id = ?")->execute([$txnId]);
        } catch (Exception $ignored) {}

        $pdo->prepare("DELETE FROM visitor_dues WHERE txn_id = ?")->execute([$txnId]);
        $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND type = 'Visitor'");
        $stmt->execute([$txnId]);
        $deletedRows = $stmt->rowCount();

        if ($deletedRows) {
            $pdo->commit();
            echo json_encode(['ok'=>true, 'deleted_rows'=>(int)$deletedRows]);
        } else {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Visitor transaction not found or already deleted.']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('member_action delete_visitor_txn: '.$e->getMessage());
        error_log('DB error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
    }
    exit;
}

// ── ADD / EDIT VISITOR TRANSACTION (with 6-month limit + paid_date) ──────────
if ($action === 'edit_visitor_txn') {
    $id     = (int)($_POST['id'] ?? 0);
    $mid    = (int)($_POST['member_id'] ?? 0);
    $amt    = (int)($_POST['amount'] ?? 0);
    $mode   = substr(trim($_POST['mode'] ?? ''), 0, 100);
    $status = in_array($_POST['status'] ?? '', ['Paid','Pending','Rejected']) ? $_POST['status'] : 'Pending';
    $fd     = trim($_POST['friday_date'] ?? '');
    $visitorName = trim($_POST['visitor_name'] ?? '');
    $visitorProf = trim($_POST['visitor_profession'] ?? '');
    $paid_date = trim($_POST['paid_date'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) { echo json_encode(['ok'=>false,'msg'=>'Invalid Sunday Date']); exit; }
    if ($amt <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid amount']); exit; }
    if (empty($visitorName)) { echo json_encode(['ok'=>false,'msg'=>'Visitor name is required']); exit; }
    if (!$mid) { echo json_encode(['ok'=>false,'msg'=>'Missing member ID']); exit; }

    // Get member's name for referrer_name
    $memQ = $pdo->prepare("SELECT name FROM members WHERE id=?");
    $memQ->execute([$mid]);
    $memberName = $memQ->fetchColumn();
    if (!$memberName) { echo json_encode(['ok'=>false,'msg'=>'Member not found']); exit; }

    // 6-month duplicate check
    if (!canAddVisitor($pdo, $mid, $visitorName, $fd, $id)) {
        echo json_encode(['ok'=>false,'msg'=>'This visitor name has already been referred twice in the last 6 months. Only 2 visits allowed per 6-month period.']);
        exit;
    }

    $validPaidDate = ($paid_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) ? $paid_date : date('Y-m-d');
    $submitted_at = $validPaidDate . ' 00:00:00';
    $isPaidAfterMeeting = $validPaidDate > $fd;

    try {
        if ($id) {
            $oldQ = $pdo->prepare("
                SELECT t.payment_method, t.submitted_at, vd.id AS due_id, vd.status AS due_status
                FROM transactions t
                LEFT JOIN visitor_dues vd ON vd.txn_id = t.id
                WHERE t.id=? AND t.type='Visitor'
                LIMIT 1
            ");
            $oldQ->execute([$id]);
            $oldRow = $oldQ->fetch(PDO::FETCH_ASSOC) ?: [];
            // Member-due flow is ONLY triggered when admin explicitly selects
            // Pending-Member mode. isPaidAfterMeeting is NOT used here because
            // visitors entered late (submitted_at after friday_date) must not
            // be silently converted to member dues.
            $isMemberDueFlow = $mode === 'Pending-Member';

            if ($isMemberDueFlow) {
                $txnMode = 'Pending-Member';
                // Keep original submitted_at anchored to Sunday Date for member-due rows
                $txnSubmittedAt = $fd . ' 00:00:00';
            } else {
                $txnMode = $mode;
                $txnSubmittedAt = $submitted_at;
            }

            $stmt = $pdo->prepare("UPDATE transactions SET member_id=?, visitor_name=?, visitor_profession=?, referrer_name=?, friday_date=?, amount=?, payment_method=?, status=?, submitted_at=? WHERE id=? AND type='Visitor'");
            $stmt->execute([$mid, $visitorName, $visitorProf, $memberName, $fd, $amt, $txnMode, $status, $txnSubmittedAt, $id]);
            // Update visitor_dues accordingly. Member-paid visitor dues keep the
            // original visitor row as Pending-Member; paid_at records collection date.
            if ($status === 'Paid') {
                $pdo->prepare("DELETE FROM visitor_dues WHERE txn_id=?")->execute([$id]);
                if ($mode === 'Pending-Member') {
                    $pdo->prepare("INSERT INTO visitor_dues (txn_id, member_id, visitor_name, amount, status, notes) VALUES (?, ?, ?, ?, 'Pending', 'Collect from member')")
                        ->execute([$id, $mid, $visitorName, $amt]);
                } elseif ($isMemberDueFlow) {
                    $duePaidAt = $validPaidDate . ' 00:00:00';
                    $pdo->prepare("INSERT INTO visitor_dues (txn_id, member_id, visitor_name, amount, status, paid_at, payment_method, notes) VALUES (?, ?, ?, ?, 'Paid', ?, ?, 'Visitor due collected from member')")
                        ->execute([$id, $mid, $visitorName, $amt, $duePaidAt, $mode]);
                } else {
                    $pdo->prepare("DELETE FROM visitor_dues WHERE txn_id=?")->execute([$id]);
                }
            } elseif ($status === 'Rejected') {
                $pdo->prepare("DELETE FROM visitor_dues WHERE txn_id=?")->execute([$id]);
            } else { // Pending
                $pdo->prepare("DELETE FROM visitor_dues WHERE txn_id=?")->execute([$id]);
            }
        } else {
            $newIsMemberDueFlow = $mode === 'Pending-Member';
            $txnMode = $newIsMemberDueFlow ? 'Pending-Member' : $mode;
            $txnSubmittedAt = $newIsMemberDueFlow ? ($fd . ' 00:00:00') : $submitted_at;
            $stmt = $pdo->prepare("INSERT INTO transactions (member_id, type, visitor_name, visitor_profession, referrer_name, friday_date, amount, payment_method, status, submitted_at) VALUES (?, 'Visitor', ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$mid, $visitorName, $visitorProf, $memberName, $fd, $amt, $txnMode, $status, $txnSubmittedAt]);
            $newId = $pdo->lastInsertId();
            if ($status === 'Paid') {
                if ($mode === 'Pending-Member') {
                    $pdo->prepare("INSERT INTO visitor_dues (txn_id, member_id, visitor_name, amount, status, notes) VALUES (?, ?, ?, ?, 'Pending', 'Collect from member')")
                        ->execute([$newId, $mid, $visitorName, $amt]);
                } else {
                    $pdo->prepare("DELETE FROM visitor_dues WHERE txn_id=?")->execute([$newId]);
                }
            }
        }
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        error_log('member_action edit_visitor_txn: '.$e->getMessage());
        error_log('DB error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
    }
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action.']);

// ────────────────────────────── HELPER FUNCTIONS ──────────────────────────────

function canAddVisitor($pdo, $memberId, $visitorName, $newSundayDate, $excludeTxnId = 0) {
    $sixMonthsAgo = date('Y-m-d', strtotime($newSundayDate . ' -6 months'));
    $refExpr = hm_member_name_sql_expr('t.referrer_name');
    $memExpr = hm_member_name_sql_expr('m.name');
    $sql = "SELECT COUNT(*) FROM transactions t
            JOIN members m ON m.id = ?
            WHERE t.type = 'Visitor'
            AND (t.member_id = m.id OR ($refExpr = $memExpr AND t.referrer_name <> ''))
            AND t.visitor_name = ?
            AND t.friday_date BETWEEN ? AND ?";
    $params = [$memberId, $visitorName, $sixMonthsAgo, $newSundayDate];
    if ($excludeTxnId > 0) {
        $sql .= " AND t.id != ?";
        $params[] = $excludeTxnId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();
    return $count < 2;
}

function runTxnEdit($pdo) {
    $id     = (int)($_POST['id'] ?? 0);
    $mid    = (int)($_POST['member_id'] ?? 0);
    $amt    = (int)($_POST['amount'] ?? 0);
    $mode   = substr(trim($_POST['mode']   ?? ''), 0, 100);
    $status = in_array($_POST['status']??'',['Paid','Pending','Rejected']) ? $_POST['status'] : 'Pending';
    $fd     = trim($_POST['friday_date'] ?? '');
    $paid_date = trim($_POST['paid_date'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) { echo json_encode(['ok'=>false,'msg'=>'Invalid date']); return; }
    if ($amt <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid amount']); return; }

    $fdDt = new DateTime($fd);
    if ((int)$fdDt->format('N') !== 7) $fdDt->modify('next sunday');
    $fd = $fdDt->format('Y-m-d');

    $submitted_at = ($paid_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) ? $paid_date . ' 00:00:00' : date('Y-m-d H:i:s');

    if ($id) {
        $pdo->prepare("UPDATE transactions SET friday_date=?, amount=?, payment_method=?, status=?, submitted_at=?, verified_at=IF(?='Paid',NOW(),verified_at) WHERE id=? AND member_id=?")
            ->execute([$fd, $amt, $mode, $status, $submitted_at, $status, $id, $mid ?: $id]);
    } else {
        if (!$mid) { echo json_encode(['ok'=>false,'msg'=>'Missing member_id']); return; }
        $pdo->prepare("INSERT INTO transactions (member_id,type,amount,payment_method,friday_date,status,submitted_at) VALUES (?,'Member',?,?,?,?,?)")
            ->execute([$mid, $amt, $mode, $fd, $status, $submitted_at]);
    }
    echo json_encode(['ok'=>true]);
}

function runTxnDelete($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing ID']); return; }
    $pdo->prepare("DELETE FROM transactions WHERE id=? AND type='Member'")->execute([$id]);
    echo json_encode(['ok'=>true]);
}

function runKittyEdit($pdo) {
    $id     = (int)($_POST['id'] ?? 0);
    $mid    = (int)($_POST['member_id'] ?? 0);
    $amt    = (int)($_POST['amount'] ?? 0);
    $mode   = substr(trim($_POST['mode']  ?? ''), 0, 100);
    $status = in_array($_POST['status']??'',['Paid','Pending','Rejected']) ? $_POST['status'] : 'Pending';
    $notes  = substr(trim($_POST['notes'] ?? ''), 0, 200);
    $paid_date = trim($_POST['paid_date'] ?? '');

    if ($amt <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid amount']); return; }

    $submitted_at = ($paid_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) ? $paid_date . ' 00:00:00' : date('Y-m-d H:i:s');

    if ($id) {
        $pdo->prepare("UPDATE kitty_payments SET amount=?, payment_method=?, status=?, notes=?, submitted_at=? WHERE id=?")
            ->execute([$amt, $mode, $status, $notes, $submitted_at, $id]);
    } else {
        if (!$mid) { echo json_encode(['ok'=>false,'msg'=>'Missing member_id']); return; }
        $pdo->prepare("INSERT INTO kitty_payments (member_id, amount, payment_method, status, notes, submitted_at) VALUES (?,?,?,?,?,?)")
            ->execute([$mid, $amt, $mode, $status, $notes, $submitted_at]);
    }
    echo json_encode(['ok'=>true]);
}

function runKittyDelete($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing ID']); return; }
    $pdo->prepare("DELETE FROM kitty_payments WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
}
?>
