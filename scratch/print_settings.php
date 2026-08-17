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

    $stmt = $remotePdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'live_display_settings' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    $settings = json_decode($val ?: '', true) ?: [];

    echo "=== LIVE DISPLAY SETTINGS ===\n";
    foreach ($settings as $k => $v) {
        if (is_array($v)) {
            echo "$k: " . json_encode($v) . "\n";
        } else {
            echo "$k: $v\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
