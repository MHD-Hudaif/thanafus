<?php

define('APP_NAME', 'Kauzariyya Musabaqa');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_ROOT', dirname(__DIR__));
define('APP_PUBLIC_PATH', APP_ROOT);

require_once APP_ROOT . '/app/Support/url.php';

$configuredBaseUrl = app_env('APP_BASE_URL', app_env('APP_URL', '/kauzariyya-musabaqa'));

define('APP_BASE_URL', app_normalize_base_url($configuredBaseUrl));

// Backward-compatible alias for existing pages and scripts.
define('APP_URL', APP_BASE_URL);
