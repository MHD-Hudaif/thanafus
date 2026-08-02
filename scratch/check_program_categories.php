<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Check category count in musabaqa_program_categories
$cntStmt = $musabaqa_pdo->query("SELECT COUNT(*) FROM musabaqa_program_categories");
$count = $cntStmt->fetchColumn();
echo "Total categories in musabaqa_program_categories: $count\n";

// Group by event
$stmt = $musabaqa_pdo->query("
    SELECT p.event_id, COUNT(*) as cnt 
    FROM musabaqa_program_categories pc
    JOIN musabaqa_programs p ON p.id = pc.program_id
    GROUP BY p.event_id
");
while ($row = $stmt->fetch()) {
    echo "Event ID: {$row['event_id']} | Categories: {$row['cnt']}\n";
}

// Check which programs have categories in event 13
echo "\n=== PROGRAMS WITH CATEGORIES IN EVENT 13 ===\n";
$stmt = $musabaqa_pdo->query("
    SELECT p.id, p.title, COUNT(pc.id) as cnt
    FROM musabaqa_programs p
    LEFT JOIN musabaqa_program_categories pc ON pc.program_id = p.id
    WHERE p.event_id = 13
    GROUP BY p.id
");
while ($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Title: {$row['title']} | Categories Count: {$row['cnt']}\n";
}
