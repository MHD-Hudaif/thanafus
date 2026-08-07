<?php
// Disable time limit to keep connection alive
set_time_limit(0);

require_once __DIR__ . '/../../includes/admin-helpers.php';

// Verify user login
require_login();

// Release session lock immediately to prevent blocking other page requests
session_write_close();

// Set headers for Server-Sent Events (SSE)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$pdo = $GLOBALS['musabaqa_pdo'];

// Get the latest log ID before starting the loop
$stmt = $pdo->query("SELECT MAX(id) FROM musabaqa_activity_logs");
$lastId = (int)($stmt->fetchColumn() ?: 0);

$startTime = time();

// Run loop for up to 25 seconds to keep connection alive safely
while (time() - $startTime < 25) {
    if (connection_aborted()) {
        break;
    }

    $stmt = $pdo->prepare("
        SELECT id, action_type, target_table
        FROM musabaqa_activity_logs
        WHERE id > ?
        ORDER BY id ASC
    ");
    $stmt->execute([$lastId]);
    $newLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($newLogs) {
        foreach ($newLogs as $log) {
            echo "data: " . json_encode([
                'id' => (int)$log['id'],
                'action_type' => (string)$log['action_type'],
                'target_table' => (string)$log['target_table']
            ]) . "\n\n";

            $lastId = max($lastId, (int)$log['id']);
        }
    } else {
        // Send keep-alive comment to prevent timeouts
        echo ": keepalive\n\n";
    }

    ob_flush();
    flush();
    sleep(1);
}
