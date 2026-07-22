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

    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('KAUZARIYYA_SESSID');
    session_start();
}

// Automatically synchronize session active_event_id with the globally active event from DB
if (isset($musabaqa_pdo)) {
    try {
        $stmt = $musabaqa_pdo->query("SELECT id FROM musabaqa_events WHERE status = 'active' LIMIT 1");
        $dbActiveId = (int)($stmt->fetchColumn() ?: 0);
        if ($dbActiveId > 0) {
            $_SESSION['active_event_id'] = $dbActiveId;
        } else {
            // Fallback to the latest event if none is explicitly active
            $stmt = $musabaqa_pdo->query("SELECT id FROM musabaqa_events ORDER BY id DESC LIMIT 1");
            $latestId = (int)($stmt->fetchColumn() ?: 0);
            if ($latestId > 0) {
                $_SESSION['active_event_id'] = $latestId;
            } else {
                unset($_SESSION['active_event_id']);
            }
        }
    } catch (Throwable $e) {
        // Ignore errors during installation/setup
    }

    // Self-healing schema initialization for musabaqa_reviews
    try {
        $musabaqa_pdo->exec("
            CREATE TABLE IF NOT EXISTS musabaqa_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NULL,
                rating TINYINT NOT NULL DEFAULT 5,
                comment TEXT NOT NULL,
                name VARCHAR(150) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                status ENUM('pending', 'approved', 'archived') NOT NULL DEFAULT 'approved',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_status (event_id, status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $e) {
        // Ignore errors if table exists or during setup
    }
}


