<?php
require_once __DIR__ . '/../config/database.php';

$pdo = $GLOBALS['musabaqa_pdo'];

try {
    echo "Dropping constraint fk_program_class_type if it exists...\n";
    $pdo->exec("ALTER TABLE musabaqa_programs DROP FOREIGN KEY fk_program_class_type");
    echo "Successfully dropped fk_program_class_type!\n";
} catch (Exception $e) {
    echo "Info: " . $e->getMessage() . "\n";
}

// Ensure any invalid class_type_id values (like 0) are set to NULL
try {
    $dashboardName = DB_MAIN_NAME;
    $pdo->exec("UPDATE musabaqa_programs SET class_type_id = NULL WHERE class_type_id IS NOT NULL AND class_type_id NOT IN (SELECT id FROM {$dashboardName}.class_types)");
    echo "Cleaned up invalid class_type_id values in musabaqa_programs!\n";
} catch (Exception $e) {
    echo "Info: " . $e->getMessage() . "\n";
}
