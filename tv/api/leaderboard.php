<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

try {
    $event = tv_active_event();
    $eventId = (int)($event['id'] ?? 0);
    tv_json_success([
        'event' => tv_event_payload($event),
        'leaderboard' => tv_leaderboard($eventId),
        'stats' => tv_stats($eventId),
    ]);
} catch (Throwable $exception) {
    tv_log($exception, 'TV leaderboard API');
    tv_json_error('Leaderboard is temporarily unavailable.');
}
