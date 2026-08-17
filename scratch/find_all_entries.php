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

    $pids = [110, 111, 118, 119];
    foreach ($pids as $pid) {
        $stmtProg = $remotePdo->prepare("SELECT title, approval_status FROM musabaqa_programs WHERE id = ?");
        $stmtProg->execute([$pid]);
        $prog = $stmtProg->fetch();
        if (!$prog) continue;
        
        echo "=== Program ID: {$pid} | Title: {$prog['title']} | Status: {$prog['approval_status']} ===\n";
        
        // Find entries
        $stmtEntries = $remotePdo->prepare("
            SELECT pe.id, pe.entry_name, pe.final_score, pe.final_rank, pe.team_score, t.team_name, pe.status
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.program_id = ?
        ");
        $stmtEntries->execute([$pid]);
        $entries = $stmtEntries->fetchAll();
        foreach ($entries as $e) {
            echo "  Entry ID: {$e['id']} | Name: {$e['entry_name']} | Status: {$e['status']} | Final Score: {$e['final_score']} | Final Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Team: {$e['team_name']}\n";
        }
        echo "----------------------------------------\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
