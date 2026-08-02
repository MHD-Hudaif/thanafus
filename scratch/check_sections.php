<?php
require_once __DIR__ . '/../config/bootstrap.php';

$stmt = $musabaqa_pdo->query("SELECT id, title, allowed_sections FROM musabaqa_programs WHERE event_id = 13");
while ($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Title: {$row['title']} | Sections: '{$row['allowed_sections']}'\n";
}
