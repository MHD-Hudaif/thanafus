<?php

require_once __DIR__ . '/../../config/auth.php';

require_login();

$musabaqa_pdo = $GLOBALS['musabaqa_pdo'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . app_url('/admin/events.php'));
    exit;
}

$stmt = $musabaqa_pdo->prepare("SELECT id FROM musabaqa_events WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: ' . app_url('/admin/events.php'));
    exit;
}

try {
    $musabaqa_pdo->beginTransaction();
    
    // Demote all other active events to completed
    $musabaqa_pdo->exec("UPDATE musabaqa_events SET status = 'completed' WHERE status = 'active'");
    
    // Set this event to active globally in the database
    $stmt = $musabaqa_pdo->prepare("UPDATE musabaqa_events SET status = 'active' WHERE id = ?");
    $stmt->execute([$id]);
    
    $musabaqa_pdo->commit();
} catch (Throwable $e) {
    if ($musabaqa_pdo->inTransaction()) {
        $musabaqa_pdo->rollBack();
    }
}

$_SESSION['active_event_id'] = $id;
unset($_SESSION['active_team_id']);

header('Location: ' . app_url('/admin/dashboard.php'));
exit;
