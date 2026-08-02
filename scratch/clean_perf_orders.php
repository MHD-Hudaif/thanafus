<?php
require_once __DIR__ . '/../config/database.php';

$pdo = $GLOBALS['musabaqa_pdo'];
$stmt = $pdo->query("SELECT id, program_id, performance_order FROM musabaqa_program_entries WHERE performance_order > 1000 ORDER BY program_id ASC, id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$progCounts = [];
foreach ($rows as $r) {
    $pId = (int)$r['program_id'];
    $progCounts[$pId] = ($progCounts[$pId] ?? 0) + 1;
    $up = $pdo->prepare("UPDATE musabaqa_program_entries SET performance_order = ? WHERE id = ?");
    $up->execute([$progCounts[$pId], (int)$r['id']]);
}

echo "Cleaned " . count($rows) . " performance orders in DB.\n";
