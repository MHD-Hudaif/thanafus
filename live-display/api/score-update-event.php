<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = live_display_pdo();
    $event = live_display_active_event();
    $eventId = (int)($event['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'live_score_reveal_event' LIMIT 1");
    $stmt->execute();
    $raw = $stmt->fetchColumn();

    $revealData = $raw ? json_decode((string)$raw, true) : null;

    live_display_json_success([
        'event_id' => $eventId,
        'reveal' => $revealData,
        'leaderboard' => live_display_leaderboard($eventId),
    ]);
} catch (Throwable $e) {
    live_display_json_error($e->getMessage());
}
