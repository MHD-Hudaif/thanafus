<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reveal.php';

$event = reveal_best_event();
if (!$event) {
    reveal_json([
        'ok' => true,
        'snapshot' => [
            'event' => reveal_event_summary(null),
            'teams' => [],
            'leaderboard' => [],
            'completion' => ['approved_programs' => 0, 'total_programs' => 0, 'percentage' => 0],
            'latest_log_id' => 0,
            'program_count' => 0,
        ],
    ]);
}

reveal_json([
    'ok' => true,
    'snapshot' => reveal_leaderboard_snapshot((int) $event['id']),
]);
