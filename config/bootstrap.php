<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';

date_default_timezone_set(APP_TIMEZONE);

/*
|--------------------------------------------------------------------------
| SESSION CONFIGURATION
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // In production, force secure cookies
    if (env('APP_ENV') === 'production') {
        $secure = true;
    }

    $sameSite = env('SESSION_SAMESITE', 'Lax');
    if ($secure && $sameSite === 'None') {
        // Secure + SameSite=None requires explicit opt-in
        $sameSite = 'None';
    }

    session_set_cookie_params([
        'lifetime' => 31536000, // 1 year lifetime
        'path' => '/',          // Shared across whole domain
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    // Use strict session mode
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    // Match GC lifetime to cookie lifetime (1 year) so sessions aren't pruned
    ini_set('session.gc_maxlifetime', '31536000');

    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('KAUZARIYYA_SESSID');
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $length = defined('CSRF_TOKEN_LENGTH') ? (int)CSRF_TOKEN_LENGTH : 32;
    $_SESSION['csrf_token'] = bin2hex(random_bytes($length));
}

// Automatically synchronize session active_event_id with the globally active event from DB (cached in session for 60s)
if (isset($musabaqa_pdo)) {
    $now = time();
    $cacheExpiry = 60; // Refresh active event ID from DB every 60 seconds
    if (!isset($_SESSION['active_event_id']) || !isset($_SESSION['active_event_time']) || ($now - (int)$_SESSION['active_event_time']) > $cacheExpiry) {
        try {
            $stmt = $musabaqa_pdo->query("SELECT id FROM musabaqa_events WHERE status = 'active' LIMIT 1");
            $dbActiveId = (int)($stmt->fetchColumn() ?: 0);
            if ($dbActiveId > 0) {
                $_SESSION['active_event_id'] = $dbActiveId;
            } else {
                $stmt = $musabaqa_pdo->query("SELECT id FROM musabaqa_events ORDER BY id DESC LIMIT 1");
                $latestId = (int)($stmt->fetchColumn() ?: 0);
                if ($latestId > 0) {
                    $_SESSION['active_event_id'] = $latestId;
                } else {
                    unset($_SESSION['active_event_id']);
                }
            }
            $_SESSION['active_event_time'] = $now;
        } catch (Throwable $e) {
            // Ignore errors during installation/setup
        }
    }
}

// Release session lock for GET/HEAD requests to prevent concurrent request blocking
if (session_status() === PHP_SESSION_ACTIVE && isset($_SERVER['REQUEST_METHOD']) && in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['GET', 'HEAD'], true)) {
    session_write_close();
}



