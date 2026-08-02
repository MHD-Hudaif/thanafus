<?php
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== COLUMNS OF musabaqa_programs ===\n";
$stmt = $musabaqa_pdo->query("DESCRIBE musabaqa_programs");
while ($row = $stmt->fetch()) {
    echo "Field: {$row['Field']} | Type: {$row['Type']} | Null: {$row['Null']} | Default: {$row['Default']}\n";
}
