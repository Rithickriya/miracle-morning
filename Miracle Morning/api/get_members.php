<?php
// get_members.php - Get all active members
require_once __DIR__ . '/auth.php';
hm_require_login();

require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT id, name, company_name, category, status FROM members WHERE status = 'Active' ORDER BY name");
    $stmt->execute();
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['ok' => true, 'members' => $members]);
} catch (Exception $e) {
    error_log('Error: '.$e->getMessage()); echo json_encode(['ok'=>false,'msg'=>'An error occurred. Please try again.']);
}
?>