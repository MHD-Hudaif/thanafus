<?php
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== TEAMS FOR EVENT 8 ===\n";
$teams = $musabaqa_pdo->query("SELECT * FROM musabaqa_teams WHERE event_id = 8")->fetchAll();
foreach ($teams as $team) {
    echo "ID: {$team['id']} | Name: {$team['team_name']} | Color: {$team['team_color']}\n";
}

echo "\n=== TEAMS FOR EVENT 13 ===\n";
$teams = $musabaqa_pdo->query("SELECT * FROM musabaqa_teams WHERE event_id = 13")->fetchAll();
foreach ($teams as $team) {
    echo "ID: {$team['id']} | Name: {$team['team_name']} | Color: {$team['team_color']}\n";
}

echo "\n=== TEAM MEMBER COUNTS ===\n";
$stmt = $musabaqa_pdo->query("SELECT event_id, COUNT(*) as cnt FROM musabaqa_team_members GROUP BY event_id");
while ($row = $stmt->fetch()) {
    echo "Event ID: {$row['event_id']} | Members: {$row['cnt']}\n";
}
