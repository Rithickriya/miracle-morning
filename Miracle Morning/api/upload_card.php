<?php
// upload_card.php — Upload / replace business card for an existing transaction
// Called via fetch() POST from tab_visitors admin panel

require_once __DIR__ . '/auth.php';
hm_require_login();
if (!hm_is_admin()) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Admin access required']); exit; }

require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$id = (int)($_POST['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing ID']); exit; }

// ── Validate file ─────────────────────────────────────────────
if (empty($_FILES['card']['tmp_name']) || $_FILES['card']['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
        UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
        UPLOAD_ERR_PARTIAL    => 'Upload incomplete',
        UPLOAD_ERR_NO_FILE    => 'No file received',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing',
        UPLOAD_ERR_CANT_WRITE => 'Server write error',
    ];
    $code = $_FILES['card']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['ok'=>false,'msg'=>$errMap[$code] ?? 'Upload error']);
    exit;
}

$file = $_FILES['card'];

if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'msg'=>'File exceeds 5 MB limit']);
    exit;
}

$allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
$mime    = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowed)) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid file type. JPG/PNG/GIF/WEBP/PDF only.']);
    exit;
}

$ext = match($mime) {
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
    default           => 'bin',
};

// ── Delete old file if exists ─────────────────────────────────
try {
    $old = $pdo->prepare("SELECT business_card FROM transactions WHERE id=? LIMIT 1");
    $old->execute([$id]);
    $row = $old->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['business_card'])) {
        $oldPath = __DIR__ . '/uploads/cards/' . basename($row['business_card']);
        if (file_exists($oldPath)) @unlink($oldPath);
    }
} catch (Exception $e) { /* continue */ }

// ── Save new file ─────────────────────────────────────────────
$dir = __DIR__ . '/uploads/cards/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$fname = 'card_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) {
    echo json_encode(['ok'=>false,'msg'=>'Failed to save file on server']);
    exit;
}

// ── Update DB ─────────────────────────────────────────────────
try {
    $pdo->prepare("UPDATE transactions SET business_card=? WHERE id=?")->execute([$fname, $id]);
    $isImg   = in_array($ext, ['jpg','png','gif','webp']);
    $cardUrl = '/api/uploads/cards/' . $fname;
    echo json_encode(['ok'=>true, 'fname'=>$fname, 'url'=>$cardUrl, 'is_img'=>$isImg]);
} catch (Exception $e) {
    error_log('upload_card DB error: ' . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Database error']);
}
