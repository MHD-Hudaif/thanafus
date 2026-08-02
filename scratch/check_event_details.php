<?php
require_once __DIR__ . '/../config/bootstrap.php';

$eventId = 13;

echo "=== TEAMS FOR EVENT {$eventId} ===\n";
$teams = $musabaqa_pdo->prepare("SELECT * FROM musabaqa_teams WHERE event_id = ?");
$teams->execute([$eventId]);
$teamsList = $teams->fetchAll();
foreach ($teamsList as $team) {
    echo "ID: {$team['id']} | Name: {$team['team_name']} | Color: {$team['team_color']}\n";
}

echo "\n=== PROGRAMS FOR EVENT {$eventId} ===\n";
$progs = $musabaqa_pdo->prepare("SELECT id, title, program_type, entries_limit FROM musabaqa_programs WHERE event_id = ?");
$progs->execute([$eventId]);
$progsList = $progs->fetchAll();
foreach ($progsList as $prog) {
    // Count current entries
    $entCountStmt = $musabaqa_pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ?");
    $entCountStmt->execute([$prog['id']]);
    $currentEntriesCount = $entCountStmt->fetchColumn();
    
    echo "Prog ID: {$prog['id']} | Title: {$prog['title']} | Type: {$prog['program_type']} | Limit: {$prog['entries_limit']} | Current Entries: {$currentEntriesCount}\n";
}
