<?php
// logout.php — destroy session and go to login
require_once __DIR__ . '/auth.php';
hm_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /api/login.php');
exit;
