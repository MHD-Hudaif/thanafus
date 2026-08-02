<?php
require_once __DIR__ . '/../config/bootstrap.php';

$stmt = $musabaqa_pdo->query("
    SELECT pc.*, p.title as program_title
    FROM musabaqa_program_categories pc
    JOIN musabaqa_programs p ON p.id = pc.program_id
    WHERE p.event_id = 8
");
while ($row = $stmt->fetch()) {
    echo "Prog: {$row['program_title']} (ID: {$row['program_id']}) | Category Name: '{$row['name']}' | Max Marks: {$row['max_marks']} | Sort: {$row['sort_order']}\n";
}
