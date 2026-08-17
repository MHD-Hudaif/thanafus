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

    echo "=== TEAMS IN REMOTE DB ===\n";
    $stmt = $remotePdo->query("SELECT id, team_name, short_name, team_color, total_score FROM musabaqa_teams ORDER BY total_score DESC");
    $teams = $stmt->fetchAll();
    foreach ($teams as $t) {
        echo "ID: {$t['id']} | Name: {$t['team_name']} | Color: {$t['team_color']} | Total Score: {$t['total_score']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
