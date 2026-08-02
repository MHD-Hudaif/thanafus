<?php
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== EVENTS ===\n";
$events = $musabaqa_pdo->query("SELECT * FROM musabaqa_events ORDER BY id DESC")->fetchAll();
foreach ($events as $event) {
    echo "ID: {$event['id']} | Title: {$event['title']} | Status: {$event['status']} | Created: {$event['created_at']}\n";
}

echo "\n=== ACTIVE EVENT ID ===\n";
echo "Active Event ID (from Session/bootstrap): " . ($_SESSION['active_event_id'] ?? 'None') . "\n";

echo "\n=== TEAMS ===\n";
$teams = $musabaqa_pdo->query("SELECT * FROM musabaqa_teams")->fetchAll();
foreach ($teams as $team) {
    echo "ID: {$team['id']} | Event ID: {$team['event_id']} | Name: {$team['name']}\n";
}

echo "\n=== SETTINGS ===\n";
$settingsStmt = $musabaqa_pdo->query("SELECT * FROM musabaqa_settings");
while ($row = $settingsStmt->fetch()) {
    echo "Key: {$row['setting_key']} | Value: {$row['setting_value']}\n";
}

echo "\n=== PROGRAMS (count per event) ===\n";
$progs = $musabaqa_pdo->query("SELECT event_id, COUNT(*) as cnt FROM musabaqa_programs GROUP BY event_id")->fetchAll();
foreach ($progs as $p) {
    echo "Event ID: {$p['event_id']} | Program Count: {$p['cnt']}\n";
}

echo "\n=== ENTRIES (count per event) ===\n";
$entries = $musabaqa_pdo->query("SELECT event_id, COUNT(*) as cnt FROM musabaqa_program_entries GROUP BY event_id")->fetchAll();
foreach ($entries as $e) {
    echo "Event ID: {$e['event_id']} | Entry Count: {$e['cnt']}\n";
}
