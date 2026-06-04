<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reveal.php';

ignore_user_abort(true);
set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$event = reveal_best_event();
$eventId = $event ? (int) $event['id'] : 0;
$cursor = isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;

echo "retry: 3000\n\n";
echo "event: hello\n";
echo 'data: ' . json_encode([
    'ok' => true,
    'event_id' => $eventId,
    'cursor' => $cursor,
]) . "\n\n";
flush();

$gap = reveal_config()['stream']['batch_gap_seconds'];
$poll = reveal_config()['stream']['poll_interval'];
$lastSentId = $cursor;

$heartbeat = time();

while (!connection_aborted()) {
    if ($eventId <= 0) {
        echo ": waiting for event\n\n";
        flush();
        sleep($poll);
        $event = reveal_best_event();
        $eventId = $event ? (int) $event['id'] : 0;
        continue;
    }

    $batches = reveal_approval_batches_since($eventId, $lastSentId, $gap);

    if ($batches) {
        foreach ($batches as $batch) {
            $lastSentId = max($lastSentId, (int) $batch['batch_id']);
            echo 'id: ' . $lastSentId . "\n";
            echo "event: batch\n";
            echo 'data: ' . json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        }
    }

    if ((time() - $heartbeat) >= 15) {
        echo ": ping " . time() . "\n\n";
        flush();
        $heartbeat = time();
    }

    sleep($poll);
}
