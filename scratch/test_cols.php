<?php
require_once __DIR__ . '/../config/database.php';
$cols = $musabaqa_pdo->query("SHOW COLUMNS FROM musabaqa_team_members")->fetchAll(PDO::FETCH_ASSOC);
echo "=== musabaqa_team_members ===\n";
foreach ($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}

$tables = $musabaqa_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== All Musabaqa Tables ===\n";
print_r($tables);
