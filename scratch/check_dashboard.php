<?php
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== COLUMNS OF events IN kauzariyya ===\n";
$stmt = $dashboard_pdo->query("DESCRIBE events");
while ($row = $stmt->fetch()) {
    echo "Field: {$row['Field']} | Type: {$row['Type']} | Null: {$row['Null']} | Default: {$row['Default']}\n";
}

echo "\n=== ALL ROWS IN events ===\n";
$rows = $dashboard_pdo->query("SELECT * FROM events")->fetchAll();
foreach ($rows as $row) {
    print_r($row);
}
