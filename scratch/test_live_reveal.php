<?php
require_once __DIR__ . '/../includes/admin-helpers.php';
$pdo = $GLOBALS['musabaqa_pdo'];

$stmt = $pdo->query("SELECT id, event_id FROM musabaqa_programs WHERE approval_status = 'approved' ORDER BY id DESC LIMIT 1");
$prog = $stmt->fetch(PDO::FETCH_ASSOC);

if ($prog) {
    echo "Testing admin_trigger_live_score_reveal for Program #" . $prog['id'] . " (Event #" . $prog['event_id'] . ")...\n";
    admin_trigger_live_score_reveal($pdo, (int)$prog['event_id'], (int)$prog['id']);
    
    $checkStmt = $pdo->query("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'live_score_reveal_event' LIMIT 1");
    $val = $checkStmt->fetchColumn();
    
    echo "Saved Payload in DB:\n";
    print_r(json_decode((string)$val, true));
} else {
    echo "No approved programs found.\n";
}
