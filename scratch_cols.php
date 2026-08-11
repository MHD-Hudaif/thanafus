<?php
require_once __DIR__ . '/includes/admin-helpers.php';
$pdo = $GLOBALS['musabaqa_pdo'];

// Replicate the exact function logic
$cols = $pdo->query("SHOW COLUMNS FROM musabaqa_programs")->fetchAll(PDO::FETCH_COLUMN);
$available = array_map('strtolower', $cols);

$start = in_array('start_datetime', $available, true) ? 'start_datetime' : 'start_time';
$end = in_array('end_datetime', $available, true) ? 'end_datetime' : 'end_time';

echo "startExpr: '$start'\n";
echo "endExpr: '$end'\n";

// Test the UPDATE query that's failing
$sql = "UPDATE musabaqa_programs SET stage_type_id = ?, location = ?, {$start} = ?, {$end} = ?, section_id = ? WHERE id = ? AND event_id = ?";
echo "\nSQL: $sql\n";
