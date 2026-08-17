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

    // 1. Get active event
    $event = $remotePdo->query("SELECT id, title FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$event) {
        die("No active event found.\n");
    }
    $eventId = (int)$event['id'];
    echo "=== Active Event: {$event['title']} (ID: {$eventId}) ===\n\n";

    // 2. Fetch approved program scores for each team
    echo "=== Sum of team scores from musabaqa_program_entries (Approved programs only) ===\n";
    $stmt = $remotePdo->prepare("
        SELECT pe.team_id, t.team_name, SUM(pe.team_score) AS total_score
        FROM musabaqa_program_entries pe
        JOIN musabaqa_programs p ON p.id = pe.program_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.event_id = ?
          AND p.approval_status = 'approved'
          AND (p.redirect_to_team IS NULL OR p.redirect_to_team = 1)
        GROUP BY pe.team_id
    ");
    $stmt->execute([$eventId]);
    $totals = $stmt->fetchAll();
    foreach ($totals as $tot) {
        echo "Team ID: {$tot['team_id']} | Name: {$tot['team_name']} | Aggregated Score: {$tot['total_score']}\n";
    }

    echo "\n=== Detailed Program Scores per Team ===\n";
    $stmtDetail = $remotePdo->prepare("
        SELECT p.id AS program_id, p.title AS program_title, pe.team_id, t.team_name, pe.team_score
        FROM musabaqa_program_entries pe
        JOIN musabaqa_programs p ON p.id = pe.program_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.event_id = ?
          AND p.approval_status = 'approved'
          AND (p.redirect_to_team IS NULL OR p.redirect_to_team = 1)
        ORDER BY p.id ASC, pe.team_score DESC
    ");
    $stmtDetail->execute([$eventId]);
    $details = $stmtDetail->fetchAll();
    
    $progGroup = [];
    foreach ($details as $d) {
        $progGroup[$d['program_id']]['title'] = $d['program_title'];
        $progGroup[$d['program_id']]['scores'][] = "{$d['team_name']}: {$d['team_score']}";
    }
    
    foreach ($progGroup as $pid => $data) {
        echo "Program ID: {$pid} | Title: {$data['title']}\n";
        echo "  Scores: " . implode(" , ", $data['scores']) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
