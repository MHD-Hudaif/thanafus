<?php
$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';

try {
    $remotePdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_MUSABAQA};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);

    // Get active event
    $event = $remotePdo->query("SELECT id, title FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
    $eventId = (int)$event['id'];
    echo "=== Active Event: {$event['title']} (ID: {$eventId}) ===\n\n";

    // Fetch all programs for active event
    $stmt = $remotePdo->prepare("
        SELECT id, title, status, approval_status, redirect_to_team, disable_scores
        FROM musabaqa_programs
        WHERE event_id = ?
    ");
    $stmt->execute([$eventId]);
    $programs = $stmt->fetchAll();

    echo "=== ALL PROGRAMS ===\n";
    foreach ($programs as $p) {
        echo "ID: {$p['id']} | Title: {$p['title']} | Status: {$p['status']} | Approval: {$p['approval_status']} | Redirect: {$p['redirect_to_team']} | Disable: {$p['disable_scores']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
