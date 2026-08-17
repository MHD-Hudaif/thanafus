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

    echo "=== CONVERSATION PROGRAMS ===\n";
    $stmt = $remotePdo->query("SELECT id, title, program_type, approval_status FROM musabaqa_programs WHERE title LIKE '%Conversation%' OR title LIKE '%conversation%'");
    $programs = $stmt->fetchAll();
    foreach ($programs as $p) {
        echo "ID: {$p['id']} | Title: {$p['title']} | Type: {$p['program_type']} | Status: {$p['approval_status']}\n";
        
        // Find entries with rank = 3
        $stmtEntries = $remotePdo->prepare("
            SELECT pe.id, pe.entry_name, pe.final_score, pe.final_rank, pe.team_score, t.team_name
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.program_id = ? AND pe.final_rank = 3
        ");
        $stmtEntries->execute([$p['id']]);
        $entries = $stmtEntries->fetchAll();
        if ($entries) {
            echo "  Ranks = 3:\n";
            foreach ($entries as $e) {
                echo "    Entry ID: {$e['id']} | Name: {$e['entry_name']} | Score: {$e['final_score']} | Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Team: {$e['team_name']}\n";
            }
        } else {
            echo "  No entries with rank = 3.\n";
        }
        echo "----------------------------------------\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
