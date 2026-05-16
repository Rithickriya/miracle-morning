<?php
// ═══════════════════════════════════════════════════════════════
// auth.php — Miracle Morning session guard & role config
// Include at the TOP of any protected page.
// ═══════════════════════════════════════════════════════════════

// ── Load credentials from separate file (gitignored) ────────────
require_once __DIR__ . '/credentials.php';

// ── Tabs the Desk role can access ───────────────────────────────
define('DESK_ALLOWED_TABS', ['queue', 'live', 'summary', 'print', 'range']);

// ── Security headers (sent on every page) ───────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Session bootstrap ────────────────────────────────────────────
function hm_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('miracle_morning_sess');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
}

// ── Redirect to login if not authenticated ────────────────────────
function hm_require_login(): void {
    hm_session();
    if (empty($_SESSION['hm_role'])) {
        $here = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: /api/login.php' . ($here ? '?next='.$here : ''));
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────
function hm_role(): string   { return $_SESSION['hm_role'] ?? 'desk'; }
function hm_name(): string   { return $_SESSION['hm_name'] ?? 'User'; }
function hm_is_admin(): bool { return hm_role() === 'admin'; }

function hm_can_tab(string $tab): bool {
    if (hm_is_admin()) return true;
    return in_array($tab, DESK_ALLOWED_TABS);
}

// ═══════════════════════════════════════════════════════════════
// CSRF Protection
// ═══════════════════════════════════════════════════════════════

function hm_csrf_token(): string {
    hm_session();
    if (empty($_SESSION['hm_csrf'])) {
        $_SESSION['hm_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['hm_csrf'];
}

function hm_csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . hm_csrf_token() . '">';
}

function hm_csrf_check(): bool {
    hm_session();
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['hm_csrf'] ?? '', $token);
}

function hm_csrf_require(): void {
    if (!hm_csrf_check()) {
        // Always return JSON — this function is only called from API endpoints
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Security token expired. Please refresh the page and try again.']);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════
// Rate Limiting (session-based)
// ═══════════════════════════════════════════════════════════════

/**
 * Check if action is rate-limited.
 * @param string $key   Unique key for this action (e.g., 'register', 'login')
 * @param int    $max   Max attempts allowed in window
 * @param int    $window Seconds for the window
 * @return bool  true if allowed, false if rate-limited
 */
function hm_rate_check(string $key, int $max = 10, int $window = 60): bool {
    hm_session();
    $now = time();
    $rk = 'hm_rate_' . $key;
    if (!isset($_SESSION[$rk])) $_SESSION[$rk] = [];

    // Clean old entries
    $_SESSION[$rk] = array_filter($_SESSION[$rk], function($t) use ($now, $window) {
        return ($now - $t) < $window;
    });

    if (count($_SESSION[$rk]) >= $max) {
        return false; // rate limited
    }

    $_SESSION[$rk][] = $now;
    return true;
}

// ═══════════════════════════════════════════════════════════════
// Audit Logging
// ═══════════════════════════════════════════════════════════════

/**
 * Log an admin action to the audit_log table.
 * Creates the table automatically if it doesn't exist.
 */
function hm_audit(PDO $pdo, string $action, string $details = '', ?int $target_id = null): void {
    try {
        // Auto-create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_role VARCHAR(20) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            target_id INT DEFAULT NULL,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("INSERT INTO audit_log (user_role, user_name, action, details, target_id, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            hm_role(),
            hm_name(),
            $action,
            $details,
            $target_id,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}
