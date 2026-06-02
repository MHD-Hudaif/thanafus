<?php

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

/*
|--------------------------------------------------------------------------
| MAIN DASHBOARD DATABASE
|--------------------------------------------------------------------------
*/

$dashboard_dsn =
"mysql:host={$DB_HOST};dbname=kauzariyya;charset={$DB_CHARSET}";

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

$musabaqa_dsn =
"mysql:host={$DB_HOST};dbname=kauzariyya_musabaqa;charset={$DB_CHARSET}";

$musabaqa_pdo = new PDO(
    $musabaqa_dsn,
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);