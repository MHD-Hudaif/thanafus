<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reveal.php';

$event = reveal_best_event();
if (!$event) {
    reveal_json(['ok' => true, 'snapshot' => reveal_event_summary(null), 'batches' => []]);
}

$after = isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;
$payload = reveal_current_state_for_event((int) $event['id'], $after);
reveal_json([
    'ok' => true,
    'snapshot' => $payload['snapshot'],
    'batches' => $payload['batches'],
]);
