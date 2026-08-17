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

    // Find any score entries with judge score or total marks around 176 or 177
    echo "=== Searching musabaqa_program_entries for scores ===\n";
    $stmt = $remotePdo->query("
        SELECT pe.id, pe.program_id, p.title as program_title, pe.team_id, t.team_name, pe.final_score, pe.final_rank, pe.team_score, pe.status
        FROM musabaqa_program_entries pe
        JOIN musabaqa_programs p ON p.id = pe.program_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.final_score BETWEEN 170 AND 180
    ");
    $entries = $stmt->fetchAll();
    foreach ($entries as $e) {
        echo "Entry ID: {$e['id']} | Prog ID: {$e['program_id']} | Prog Title: {$e['program_title']} | Team: {$e['team_name']} | Final Score: {$e['final_score']} | Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Status: {$e['status']}\n";
    }

    echo "\n=== All score entries for program ID 96 ===\n";
    $stmt96 = $remotePdo->query("
        SELECT pe.id, pe.team_id, t.team_name, pe.final_score, pe.final_rank, pe.team_score, pe.status
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.program_id = 96
    ");
    $entries96 = $stmt96->fetchAll();
    foreach ($entries96 as $e) {
        echo "Entry ID: {$e['id']} | Team: {$e['team_name']} | Final Score: {$e['final_score']} | Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Status: {$e['status']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
