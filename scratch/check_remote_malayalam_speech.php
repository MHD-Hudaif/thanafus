<?php
$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';
$DB_DASHBOARD = 'ensplpmy_kauzariyya_dashboard';

try {
    echo "=== CONNECTING TO REMOTE BLUEHOST DATABASE ===\n";
    $remotePdo = new PDO("mysql:host={$DB_HOST};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);
    echo "Connected.\n";

    echo "=== MALAYALAM SPEECH PROGRAMS ===\n";
    $stmt = $remotePdo->prepare("
        SELECT p.id, p.title, p.event_id, p.status, p.approval_status, p.judges_count, p.class_type_id,
               ct.name as class_type_name
        FROM {$DB_MUSABAQA}.musabaqa_programs p
        LEFT JOIN {$DB_DASHBOARD}.class_types ct ON ct.id = p.class_type_id
        WHERE p.title LIKE '%malayalam%'
    ");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    foreach ($programs as $p) {
        echo "ID: {$p['id']} | Title: {$p['title']} | Class: {$p['class_type_name']} (ID: {$p['class_type_id']}) | Status: {$p['status']} | Approval: {$p['approval_status']} | Judges: {$p['judges_count']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
