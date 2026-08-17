<?php
$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';

try {
    $remotePdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_MUSABAQA};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);

    $stmt = $remotePdo->query("SELECT * FROM musabaqa_events WHERE id = 8");
    $event = $stmt->fetch();
    echo "=== EVENT 8 SETTINGS ===\n";
    foreach ($event as $k => $v) {
        echo "$k: $v\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
