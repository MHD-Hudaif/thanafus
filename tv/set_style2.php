<?php
require_once __DIR__ . '/../../tv/includes/functions.php';

// Get the active event id
$event = tv_active_event();
$eventId = (int)($event['id'] ?? 0);

echo "Active Event ID: $eventId\n";

// Update style of 'leaderboard' to 'style2'
$stmt = $pdo->prepare("UPDATE musabaqa_tv_components SET style = 'style2' WHERE slide_key = 'leaderboard'");
$stmt->execute();

echo "Updated style of leaderboard to style2. Affected rows: " . $stmt->rowCount() . "\n";
