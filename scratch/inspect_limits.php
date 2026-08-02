<?php
require_once __DIR__ . '/../config/bootstrap.php';

$stmt = $musabaqa_pdo->query("SELECT id, event_id, title, entries_limit FROM musabaqa_programs");
while ($row = $stmt->fetch()) {
    echo "Event: {$row['event_id']} | ID: {$row['id']} | Title: {$row['title']} | Limit: " . ($row['entries_limit'] === null ? 'NULL' : $row['entries_limit']) . "\n";
}
