<?php

require_once __DIR__ . '/env.php';

$DB_HOST = env('DB_HOST', '127.0.0.1');
$DB_USER = env('DB_USERNAME', 'root');
$DB_PASS = env('DB_PASSWORD', '');
$DB_CHARSET = env('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| MAIN DASHBOARD DATABASE
|--------------------------------------------------------------------------
*/

$dashboard_dsn =
"mysql:host={$DB_HOST};dbname=" . env('DB_DATABASE', 'kauzariyya') . ";charset={$DB_CHARSET}";

$dashboard_pdo = new PDO(
    $dashboard_dsn,
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

/*
|--------------------------------------------------------------------------
| MUSABAQA DATABASE
|--------------------------------------------------------------------------
*/

$musabaqa_host = env('MUSABAQA_DB_HOST', $DB_HOST);
$musabaqa_user = env('MUSABAQA_DB_USERNAME', $DB_USER);
$musabaqa_pass = env('MUSABAQA_DB_PASSWORD', $DB_PASS);

$musabaqa_dsn =
"mysql:host={$musabaqa_host};dbname=" . env('MUSABAQA_DB_DATABASE', 'kauzariyya_musabaqa') . ";charset={$DB_CHARSET}";

$musabaqa_pdo = new PDO(
    $musabaqa_dsn,
    $musabaqa_user,
    $musabaqa_pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
