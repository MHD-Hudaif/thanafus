<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $musabaqa_pdo->prepare("DELETE FROM musabaqa_team_teachers");
    $stmt->execute();
    echo "Successfully removed all entries from musabaqa_team_teachers.\n";
} catch (Throwable $e) {
    echo "Error clearing working committee: " . $e->getMessage() . "\n";
}
