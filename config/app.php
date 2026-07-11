<?php

require_once __DIR__ . '/env.php';

define('APP_NAME', env('APP_NAME', 'Kauzariyya Musabaqa'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Kolkata'));
define('APP_ROOT', dirname(__DIR__));
define('APP_PUBLIC_PATH', APP_ROOT);

require_once APP_ROOT . '/app/Support/url.php';

$configuredBaseUrl = env('APP_BASE_URL', env('APP_URL', '/kauzariyya-musabaqa'));

define('APP_BASE_URL', app_normalize_base_url($configuredBaseUrl));

// Backward-compatible alias for existing pages and scripts.
define('APP_URL', APP_BASE_URL);

// Google OAuth Configuration
define('GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET', ''));

// Security Configuration
define('CSRF_TOKEN_LENGTH', env('CSRF_TOKEN_LENGTH', 32));
define('SESSION_LIFETIME', env('SESSION_LIFETIME', 120) * 60); // Convert minutes to seconds
