<?php
/**
 * UPDATE JUDGE COUNT FOR EBARATH READING PROGRAM
 * This script updates the judges_count for "ebarath reading" from 1 to 2
 */

// Bluehost database credentials
$bluehost_db_host = '162.214.80.164';
$bluehost_db_user = 'ensplpmy_hudaif';
$bluehost_db_pass = 'abd527-157';
$bluehost_db_name = 'ensplpmy_kauzariyya_musabaqa';

try {
    // Connect to Bluehost database
    $pdo = new PDO(
        "mysql:host={$bluehost_db_host};dbname={$bluehost_db_name};charset=utf8mb4",
        $bluehost_db_user,
        $bluehost_db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // First, find the program
    $search_stmt = $pdo->prepare("SELECT id, title, judges_count FROM musabaqa_programs WHERE LOWER(title) LIKE LOWER(?)");
    $search_stmt->execute(['%ebarath%']);
    $results = $search_stmt->fetchAll();

    if (empty($results)) {
        echo "No programs found matching 'ebarath'.\n";
        exit(1);
    }

    echo "Found " . count($results) . " program(s) matching 'ebarath':\n";
    foreach ($results as $row) {
        echo "ID: " . $row['id'] . " | Title: " . $row['title'] . " | Current judges_count: " . $row['judges_count'] . "\n";
    }

    // Update all matching programs
    $update_stmt = $pdo->prepare("UPDATE musabaqa_programs SET judges_count = 2 WHERE LOWER(title) LIKE LOWER(?)");
    $update_stmt->execute(['%ebarath%']);
    
    $affected_rows = $update_stmt->rowCount();
    echo "\n✓ Successfully updated $affected_rows program(s) with judges_count = 2\n";

    // Verify the update
    $verify_stmt = $pdo->prepare("SELECT id, title, judges_count FROM musabaqa_programs WHERE LOWER(title) LIKE LOWER(?)");
    $verify_stmt->execute(['%ebarath%']);
    $updated_results = $verify_stmt->fetchAll();

    echo "\nVerification - Updated records:\n";
    foreach ($updated_results as $row) {
        echo "ID: " . $row['id'] . " | Title: " . $row['title'] . " | New judges_count: " . $row['judges_count'] . "\n";
    }

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
