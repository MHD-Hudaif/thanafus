<?php
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== COLUMNS OF musabaqa_score_sheets ===\n";
$stmt = $musabaqa_pdo->query("DESCRIBE musabaqa_score_sheets");
while ($row = $stmt->fetch()) {
    echo "Field: {$row['Field']} | Type: {$row['Type']} | Null: {$row['Null']} | Default: {$row['Default']}\n";
}
