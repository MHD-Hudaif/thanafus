<?php
require_once __DIR__ . '/../config/database.php';

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];

echo "--- 1. Check ensplpmy_kauzariyya_dashboard.class_types ---\n";
try {
    $stmt = $dashboardPdo->query("SELECT id, name FROM class_types");
    $classTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($classTypes);
} catch (Exception $e) {
    echo "Error querying dashboard class_types: " . $e->getMessage() . "\n";
}

echo "\n--- 2. Check musabaqa_programs Foreign Keys ---\n";
try {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_NAME = 'musabaqa_programs' AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error querying foreign keys: " . $e->getMessage() . "\n";
}

echo "\n--- 3. Check musabaqa_programs class_type_id values ---\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT class_type_id FROM musabaqa_programs");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error querying class_type_id: " . $e->getMessage() . "\n";
}
