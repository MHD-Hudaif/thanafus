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

    // Fetch live display settings for event 8
    $stmt = $remotePdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = ?");
    $stmt->execute(['live_display.event.8.settings']);
    $row = $stmt->fetch();
    if ($row) {
        $settings = json_decode($row['setting_value'], true);
        $currentTheme = $settings['theme'] ?? 'not set';
        echo "Current theme in database: {$currentTheme}\n";
        
        // Toggle theme: if emerald -> light, if light -> emerald
        $newTheme = ($currentTheme === 'emerald') ? 'light' : 'emerald';
        $settings['theme'] = $newTheme;
        
        $update = $remotePdo->prepare("UPDATE musabaqa_settings SET setting_value = ? WHERE setting_key = ?");
        $update->execute([json_encode($settings), 'live_display.event.8.settings']);
        
        echo "Successfully updated theme in database to: {$newTheme}\n";
    } else {
        echo "Settings key not found.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
