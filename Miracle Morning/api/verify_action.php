<?php
// verify_action.php — Handles all admin actions
// Always returns JSON, never crashes silently

require_once __DIR__ . '/auth.php';
hm_require_login();
// Both Admin and Desk users can perform actions (Desk is limited to live/summary/print tabs)

require_once __DIR__ . '/db_config.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Reject GET requests — all actions must use POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'msg'=>'POST method required']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$id     = (int)($_POST['id']   ?? 0);
$table  = trim($_POST['tbl']   ?? 'transactions');
$tbl    = ($table === 'kitty') ? 'kitty_payments' : 'transactions';

if (!$id && !in_array($action, ['settle_due','edit_due_collection','unsettle_due','verify_batch','reject_batch'])) {
    echo json_encode(['ok'=>false,'msg'=>'Missing ID']);
    exit;
}

// Helper: does visitor_dues table exist?
function visitorDuesExists(PDO $pdo) {
    try {
        $pdo->query("SELECT 1 FROM visitor_dues LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

try {
    switch ($action) {

        // ── Approve (member / observer / kitty) ─────────────────────────
        case 'verify':
            $mode = trim($_POST['mode'] ?? '');
            if ($mode) {
                $pdo->prepare("UPDATE $tbl SET status='Paid', payment_method=?, verified_at=NOW() WHERE id=?")
                    ->execute([$mode, $id]);
            } else {
                $pdo->prepare("UPDATE $tbl SET status='Paid', verified_at=NOW() WHERE id=?")
                    ->execute([$id]);
            }
            hm_audit($pdo, 'approve', "Approved $table #$id", $id);
            echo json_encode(['ok'=>true]);
            break;

        // ── Visitor paid directly ────────────────────────────────────────
        case 'visitor_paid':
            $mode    = trim($_POST['mode'] ?? '');
            $allowed = ['Cash','Card','UPI','QR Code (UPI)','FinCloud'];
            if (!in_array($mode, $allowed)) {
                echo json_encode(['ok'=>false,'msg'=>'Please select a payment method']);
                break;
            }
            $rows = $pdo->prepare("UPDATE transactions SET status='Paid', payment_method=?, verified_at=NOW() WHERE id=? AND type='Visitor'");
            $rows->execute([$mode, $id]);
            if ($rows->rowCount() === 0) {
                echo json_encode(['ok'=>false,'msg'=>'Visitor record not found or already processed']);
                break;
            }
            echo json_encode(['ok'=>true]);
            break;

        // ── Visitor paid by member ───────────────────────────────────────
        case 'visitor_paid_by_member':
            $member_id = (int)($_POST['member_id'] ?? 0);
            $pay_now   = ($_POST['pay_now'] ?? '0') === '1';
            $mode      = trim($_POST['mode'] ?? 'Cash');
            $allowed   = ['Cash','Card','UPI','QR Code (UPI)','FinCloud'];

            if (!$member_id) {
                echo json_encode(['ok'=>false,'msg'=>'Select a member']);
                break;
            }

            $vq = $pdo->prepare("SELECT visitor_name, amount FROM transactions WHERE id=? AND type='Visitor' LIMIT 1");
            $vq->execute([$id]);
            $visitor = $vq->fetch(PDO::FETCH_ASSOC);
            if (!$visitor) {
                echo json_encode(['ok'=>false,'msg'=>'Visitor not found']);
                break;
            }

            $mq = $pdo->prepare("SELECT name FROM members WHERE id=? LIMIT 1");
            $mq->execute([$member_id]);
            $memberName = $mq->fetchColumn();
            if (!$memberName) {
                echo json_encode(['ok'=>false,'msg'=>'Member not found']);
                break;
            }

            $payMethod = ($pay_now && in_array($mode, $allowed)) ? $mode : 'Pending-Member';
            $pdo->prepare("UPDATE transactions SET member_id=?, referrer_name=?, status='Paid', payment_method=?, verified_at=NOW() WHERE id=?")
                ->execute([$member_id, $memberName, $payMethod, $id]);

            if (visitorDuesExists($pdo)) {
                try {
                    if ($pay_now && in_array($mode, $allowed)) {
                        $pdo->prepare("INSERT INTO visitor_dues (member_id, txn_id, visitor_name, amount, status, paid_at, payment_method, notes) VALUES (?, ?, ?, ?, 'Paid', NOW(), ?, 'Paid immediately at desk')")
                            ->execute([$member_id, $id, $visitor['visitor_name'], $visitor['amount'], $mode]);
                    } else {
                        $pdo->prepare("INSERT INTO visitor_dues (member_id, txn_id, visitor_name, amount, status, notes) VALUES (?, ?, ?, ?, 'Pending', 'Collect from member')")
                            ->execute([$member_id, $id, $visitor['visitor_name'], $visitor['amount']]);
                    }
                } catch (Exception $e) {
                    error_log('visitor_dues insert error: ' . $e->getMessage());
                }
            }

            echo json_encode(['ok'=>true]);
            break;

        // ── Settle pending visitor due ───────────────────────────────────
        case 'settle_due':
            $due_id = (int)($_POST['due_id'] ?? 0);
            $mode   = trim($_POST['mode'] ?? 'Cash');
            $paid_date = trim($_POST['paid_date'] ?? '');
            $modeKey = strtolower(str_replace([' ', '-', '_', '/', '(', ')'], '', $mode));
            if (in_array($modeKey, ['upi', 'qr', 'qrcodeupi', 'upiqr'], true)) $mode = 'UPI';
            elseif ($modeKey === 'cash') $mode = 'Cash';
            elseif ($modeKey === 'card') $mode = 'Card';
            elseif ($modeKey === 'fincloud') $mode = 'FinCloud';
            $allowed = ['Cash','Card','UPI','QR Code (UPI)','FinCloud'];
            if (!$due_id) {
                echo json_encode(['ok'=>false,'msg'=>'Missing due ID']);
                break;
            }
            if (!in_array($mode, $allowed, true)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid payment method']);
                break;
            }
            $paidAt = date('Y-m-d H:i:s');
            if ($paid_date !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) {
                    echo json_encode(['ok'=>false,'msg'=>'Invalid paid date']);
                    break;
                }
                $paidAt = $paid_date . ' ' . date('H:i:s');
            }
            if (visitorDuesExists($pdo)) {
                $pdo->prepare("UPDATE visitor_dues SET status='Paid', paid_at=?, payment_method=? WHERE id=?")
                    ->execute([$paidAt, $mode, $due_id]);
                $pdo->prepare("
                    UPDATE transactions t
                    JOIN visitor_dues vd ON vd.txn_id = t.id
                    SET t.status='Paid', t.payment_method='Pending-Member'
                    WHERE vd.id=? AND t.type='Visitor'
                ")->execute([$due_id]);
            }
            echo json_encode(['ok'=>true]);
            break;

        case 'edit_due_collection':
            $due_id = (int)($_POST['due_id'] ?? 0);
            $amount = (int)($_POST['amount'] ?? 0);
            $mode = trim($_POST['mode'] ?? 'Cash');
            $paid_date = trim($_POST['paid_date'] ?? '');
            $modeKey = strtolower(str_replace([' ', '-', '_', '/', '(', ')'], '', $mode));
            if (in_array($modeKey, ['upi', 'qr', 'qrcodeupi', 'upiqr'], true)) $mode = 'UPI';
            elseif ($modeKey === 'cash') $mode = 'Cash';
            elseif ($modeKey === 'card') $mode = 'Card';
            elseif ($modeKey === 'fincloud') $mode = 'FinCloud';
            $allowed = ['Cash','Card','UPI','QR Code (UPI)','FinCloud'];
            if (!$due_id) { echo json_encode(['ok'=>false,'msg'=>'Missing due ID']); break; }
            if ($amount <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid amount']); break; }
            if (!in_array($mode, $allowed, true)) { echo json_encode(['ok'=>false,'msg'=>'Invalid payment method']); break; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) { echo json_encode(['ok'=>false,'msg'=>'Invalid paid date']); break; }
            $pdo->prepare("UPDATE visitor_dues SET amount=?, payment_method=?, paid_at=? WHERE id=?")
                ->execute([$amount, $mode, $paid_date . ' 00:00:00', $due_id]);
            echo json_encode(['ok'=>true]);
            break;

        case 'unsettle_due':
            $due_id = (int)($_POST['due_id'] ?? 0);
            if (!$due_id) { echo json_encode(['ok'=>false,'msg'=>'Missing due ID']); break; }
            $pdo->prepare("UPDATE visitor_dues SET status='Pending', paid_at=NULL, payment_method=NULL WHERE id=?")
                ->execute([$due_id]);
            echo json_encode(['ok'=>true]);
            break;

        // ── Reject / Remove ──────────────────────────────────────────────
        case 'reject':
            $pdo->prepare("UPDATE $tbl SET status='Rejected' WHERE id=?")->execute([$id]);
            hm_audit($pdo, 'reject', "Rejected $table #$id", $id);
            echo json_encode(['ok'=>true]);
            break;

        // ── Edit amount ──────────────────────────────────────────────────
        case 'edit':
            $amount = (int)($_POST['amount'] ?? 0);
            if ($amount <= 0) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid amount']);
                break;
            }
            $pdo->prepare("UPDATE $tbl SET amount=? WHERE id=?")->execute([$amount, $id]);
            echo json_encode(['ok'=>true]);
            break;

        // ── Edit payment mode ────────────────────────────────────────────
        case 'edit_mode':
            $mode    = trim($_POST['mode'] ?? '');
            $allowed = ['Cash','Card','UPI','FinCloud','QR Code (UPI)','Pending-Member'];
            if (!in_array($mode, $allowed)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid mode']);
                break;
            }
            $pdo->prepare("UPDATE $tbl SET payment_method=? WHERE id=?")->execute([$mode, $id]);
            echo json_encode(['ok'=>true]);
            break;

        // ── Batch approve ────────────────────────────────────────────────
        case 'verify_batch':
            $idsRaw = trim($_POST['ids'] ?? '');
            $ids    = json_decode($idsRaw, true);
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['ok'=>false,'msg'=>'No IDs provided']);
                break;
            }
            $ids = array_values(array_filter(array_map('intval', $ids), function($i){ return $i > 0; }));
            if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Invalid IDs']); break; }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE transactions SET status='Paid', verified_at=NOW() WHERE id IN ($ph)")
                ->execute($ids);
            hm_audit($pdo, 'approve_batch', "Batch approved ".count($ids)." transactions: ".implode(',',$ids));
            echo json_encode(['ok'=>true]);
            break;

        // ── Batch reject ─────────────────────────────────────────────────
        case 'reject_batch':
            $idsRaw = trim($_POST['ids'] ?? '');
            $ids    = json_decode($idsRaw, true);
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['ok'=>false,'msg'=>'No IDs provided']);
                break;
            }
            $ids = array_values(array_filter(array_map('intval', $ids), function($i){ return $i > 0; }));
            if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Invalid IDs']); break; }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE $tbl SET status='Rejected' WHERE id IN ($ph)")
                ->execute($ids);
            hm_audit($pdo, 'reject_batch', "Batch rejected ".count($ids)." transactions: ".implode(',',$ids));
            echo json_encode(['ok'=>true]);
            break;

        // ── Delete ───────────────────────────────────────────────────────
        case 'delete':
            $pdo->prepare("DELETE FROM $tbl WHERE id=?")->execute([$id]);
            hm_audit($pdo, 'delete', "Deleted $table #$id", $id);
            echo json_encode(['ok'=>true]);
            break;

        // ── Delete business card file ─────────────────────────────────────────
        case 'delete_card':
            $crow = $pdo->prepare("SELECT business_card FROM transactions WHERE id=? LIMIT 1");
            $crow->execute([$id]);
            $cr = $crow->fetch(PDO::FETCH_ASSOC);
            if ($cr && !empty($cr['business_card'])) {
                $cpath = __DIR__ . '/uploads/cards/' . basename($cr['business_card']);
                if (file_exists($cpath)) @unlink($cpath);
            }
            $pdo->prepare("UPDATE transactions SET business_card=NULL WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
            break;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Unknown action: '.$action]);
    }

} catch (PDOException $e) {
    error_log('verify_action PDO error: ' . $e->getMessage());
    error_log('DB error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'A database error occurred. Please try again.']);
} catch (Exception $e) {
    error_log('verify_action error: ' . $e->getMessage());
    error_log('Error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'An error occurred. Please try again.']);
}
