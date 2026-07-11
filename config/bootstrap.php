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
        'lifetime' => 0, // Session cookie (expires on browser close)
        'path' => app_cookie_path(),
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

    session_start();
}
