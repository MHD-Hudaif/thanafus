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

    echo "=== GROUP PROGRAMS IN REMOTE DB ===\n";
    $stmt = $remotePdo->query("SELECT id, title, program_type, only_team_marks FROM musabaqa_programs WHERE program_type = 'group' OR only_team_marks = 1");
    $programs = $stmt->fetchAll();
    foreach ($programs as $p) {
        echo "ID: {$p['id']} | Title: {$p['title']} | Type: {$p['program_type']} | Only Team Marks: {$p['only_team_marks']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
