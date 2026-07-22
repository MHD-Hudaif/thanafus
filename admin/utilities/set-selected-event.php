<?php
require_once __DIR__ . '/../../config/auth.php';

require_login();

$musabaqa_pdo = $GLOBALS['musabaqa_pdo'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . app_url('/admin/dashboard.php'));
    exit;
}

$stmt = $musabaqa_pdo->prepare("SELECT id FROM musabaqa_events WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if ($event) {
    $_SESSION['selected_event_id'] = $id;
    unset($_SESSION['active_team_id']);
}

header('Location: ' . app_url('/admin/dashboard.php'));
exit;
