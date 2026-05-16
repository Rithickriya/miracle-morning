<?php
// ============================================================
// process_all.php — Miracle Morning · All form submissions
// ============================================================

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ── Rate limiting (max 10 submissions per minute per session) ──
hm_session();
if (!hm_rate_check('registration', 10, 60)) {
    header('Location: /register.php?msg=' . urlencode('Too many submissions. Please wait a minute.'));
    exit;
}

// ── Helpers ──────────────────────────────────────────────────
function getSunday(): string {
    $d = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return (int)$d->format('N') === 7 ? $d->format('Y-m-d')
         : (clone $d)->modify('next sunday')->format('Y-m-d');
}

function saveBusinessCard(string $field = 'business_card'): ?string {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $file    = $_FILES[$field];
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) return null;
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) return null;
    $ext = match($mime) {
        'application/pdf' => 'pdf',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        default           => 'jpg',
    };
    $dir = __DIR__ . '/uploads/cards/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = uniqid('card_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) return null;
    return $fname;
}

function findMember(PDO $pdo, string $raw): ?int {
    $name = trim(explode(' - ', $raw)[0]);
    $s = $pdo->prepare("SELECT id FROM members WHERE name = ? AND status='Active' LIMIT 1");
    $s->execute([$name]);
    $r = $s->fetch();
    if ($r) return (int)$r['id'];
    $s = $pdo->prepare("SELECT id FROM members WHERE name LIKE ? AND status='Active' LIMIT 1");
    $s->execute(["%$name%"]);
    $r = $s->fetch();
    return $r ? (int)$r['id'] : null;
}

function redir(string $screen, string $msg): void {
    $screens = ['member','visitor','observer','kitty'];
    $s = in_array($screen, $screens) ? $screen : 'home';
    header('Location: /register.php?msg=' . urlencode($msg) . '&screen=' . $s);
    exit;
}

$form_type = trim($_POST['form_type'] ?? '');
if (empty($form_type)) redir('home', 'error_missing');

// ── MEMBER — meeting fee ──────────────────────────────────────────────────
// Each form submission creates an INDEPENDENT session.
// Never updates existing rows. Sunday splitting is for display only.
if ($form_type === 'member') {
    $raw_name        = trim($_POST['member_name']    ?? '');
    $method          = trim($_POST['method']         ?? 'Cash');
    $total_amount    = (int)($_POST['amount']        ?? 0);
    $paid_date       = trim($_POST['paid_date']      ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date)) $paid_date = date('Y-m-d');

    if (!$raw_name || $total_amount <= 0) redir('member', 'error_missing');
    $member_id = findMember($pdo, $raw_name);
    if (!$member_id) redir('member', 'error_member');

    $week_fee = 1450;
    $remaining = $total_amount;

    // Get global first Sunday (earliest meeting)
    $globalFirst = $pdo->query("SELECT MIN(friday_date) FROM transactions WHERE type='Member'")->fetchColumn();
    if (!$globalFirst) {
        $tmp = new DateTime($paid_date);
        if ((int)$tmp->format('N') !== 7) $tmp->modify('next sunday');
        $globalFirst = $tmp->format('Y-m-d');
    }

    // Find ALL Sundays that already have a row for this member (any status)
    $existingSundays = [];
    $eQ = $pdo->prepare("SELECT DISTINCT friday_date FROM transactions WHERE member_id=? AND type='Member'");
    $eQ->execute([$member_id]);
    while ($row = $eQ->fetch(PDO::FETCH_ASSOC)) {
        $existingSundays[$row['friday_date']] = true;
    }

    // Build list of EMPTY Sundays (no existing rows) from globalFirst onwards
    $emptySundays = [];
    $cur = new DateTime($globalFirst);
    $endDate = new DateTime($paid_date);
    $endDate->modify('+2 years'); // generous range
    while ($cur <= $endDate) {
        $fd = $cur->format('Y-m-d');
        if (!isset($existingSundays[$fd])) {
            $emptySundays[] = $fd;
        }
        $cur->modify('+7 days');
    }

    try {
        $pdo->beginTransaction();
        $submitted_at = $paid_date . ' ' . date('H:i:s');

        foreach ($emptySundays as $fd) {
            if ($remaining <= 0) break;
            if ($remaining >= $week_fee) {
                // Full week
                $pdo->prepare("INSERT INTO transactions (member_id, type, amount, payment_method, friday_date, status, submitted_at, original_total) VALUES (?, 'Member', ?, ?, ?, 'Pending', ?, ?)")
                    ->execute([$member_id, $week_fee, $method, $fd, $submitted_at, $total_amount]);
                $remaining -= $week_fee;
            } else {
                // Partial week
                $newBalance = $week_fee - $remaining;
                $pdo->prepare("INSERT INTO transactions (member_id, type, amount, payment_method, friday_date, status, submitted_at, is_partial, partial_paid, partial_balance, original_total) VALUES (?, 'Member', ?, ?, ?, 'Pending', ?, 1, ?, ?, ?)")
                    ->execute([$member_id, $remaining, $method, $fd, $submitted_at, $remaining, $newBalance, $total_amount]);
                $remaining = 0;
            }
        }

        $pdo->commit();
        redir('member', 'success');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('MEMBER ERROR: ' . $e->getMessage());
        redir('member', 'error_db');
    }
}

// ── VISITOR (unchanged) ───────────────────────────────────────────────────
elseif ($form_type === 'visitor') {
    $name       = trim($_POST['name']       ?? '');
    $mobile     = trim($_POST['mobile']     ?? '');
    $email      = trim($_POST['email']      ?? '');
    $company    = trim($_POST['company']    ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $referrer   = trim($_POST['referrer']   ?? '');
    $method     = trim($_POST['method']     ?? 'Cash');
    $amount     = max(1450, (int)($_POST['amount'] ?? 1450));
    $Sunday     = getSunday();

    if (!$name || !$mobile || !$referrer) redir('visitor', 'error_missing');
    $businessCard = saveBusinessCard('business_card');
    try {
        $pdo->prepare("
            INSERT INTO transactions
                (visitor_name, visitor_mobile, visitor_email, visitor_company,
                 visitor_profession, referrer_name, type, amount,
                 payment_method, friday_date, status, business_card, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Visitor', ?, ?, ?, 'Pending', ?, NOW())"
        )->execute([$name, $mobile, $email, $company, $profession,
                    $referrer, $amount, $method, $Sunday, $businessCard]);
        redir('visitor', 'success');
    } catch (Exception $e) {
        error_log('VISITOR ERROR: ' . $e->getMessage());
        redir('visitor', 'error_db');
    }
}

// ── OBSERVER (unchanged) ──────────────────────────────────────────────────
elseif ($form_type === 'observer') {
    $name     = trim($_POST['name']     ?? '');
    $mobile   = trim($_POST['mobile']   ?? '');
    $email    = trim($_POST['email']    ?? '');
    $chapter  = trim($_POST['chapter']  ?? '');
    $category = trim($_POST['category'] ?? '');
    $method   = trim($_POST['method']   ?? 'Cash');
    $amount   = max(1450, (int)($_POST['amount'] ?? 1450));
    $Sunday   = getSunday();
    if (!$name || !$mobile) redir('observer', 'error_missing');
    $businessCard = saveBusinessCard('business_card');
    try {
        $pdo->prepare("
            INSERT INTO transactions
                (visitor_name, visitor_mobile, visitor_email,
                 observer_chapter, observer_category,
                 type, amount, payment_method, friday_date, status, business_card, submitted_at)
             VALUES (?, ?, ?, ?, ?, 'Observer', ?, ?, ?, 'Pending', ?, NOW())"
        )->execute([$name, $mobile, $email, $chapter, $category,
                    $amount, $method, $Sunday, $businessCard]);
        redir('observer', 'success');
    } catch (Exception $e) {
        error_log('OBSERVER ERROR: ' . $e->getMessage());
        redir('observer', 'error_db');
    }
}

// ── KITTY (unchanged) ─────────────────────────────────────────────────────
elseif ($form_type === 'kitty') {
    $raw_name = trim($_POST['member_name'] ?? '');
    $amount   = (int)($_POST['amount']     ?? 0);
    $method   = trim($_POST['method']      ?? 'Cash');
    $notes    = trim($_POST['notes']       ?? '');
    if (!$raw_name || $amount <= 0) redir('kitty', 'error_missing');
    $member_id = findMember($pdo, $raw_name);
    if (!$member_id) redir('kitty', 'error_member');
    try {
        $pdo->prepare("INSERT INTO kitty_payments (member_id, amount, payment_method, notes, status, submitted_at) VALUES (?, ?, ?, ?, 'Pending', NOW())")
            ->execute([$member_id, $amount, $method, $notes]);
        redir('kitty', 'success');
    } catch (Exception $e) {
        error_log('KITTY ERROR: ' . $e->getMessage());
        redir('kitty', 'error_db');
    }
}

else {
    error_log('UNKNOWN form_type: ' . $form_type);
    redir('home', 'error_missing');
}
?>