<?php
declare(strict_types=1);

// Enable CORS and SSE headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control, X-Requested-With');
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // Disable buffering for Nginx / proxies

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Turn off time limits and output buffering
@set_time_limit(0);
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/../includes/admin-helpers.php';
require_once __DIR__ . '/../live-display/includes/functions.php';

$pdo = $GLOBALS['musabaqa_pdo'];
$lastHash = '';
$startTime = time();

// Function to send SSE event formatted message
function send_sse_event(string $event, mixed $data, ?int $id = null): void
{
    if ($id !== null) {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// Send initial connection event
send_sse_event('connected', [
    'ok' => true,
    'message' => 'Connected to Kauzariyya Musabaqa Live Score Stream',
    'timestamp' => time()
]);

// SSE Event Stream Loop (runs up to 60 seconds per connection before auto-reconnect)
while (time() - $startTime < 60) {
    if (connection_aborted()) {
        break;
    }

    try {
        $activeEvent = tv_active_event();
        if ($activeEvent) {
            $eventId = (int)$activeEvent['id'];
            
            // Generate a state hash based on recent scores and manual scoreboard updates
            $stmt = $pdo->prepare("
                SELECT CONCAT(
                    COALESCE(MAX(ms.updated_at), '0'), '_',
                    COALESCE(MAX(mm.updated_at), '0'), '_',
                    COALESCE(COUNT(ms.id), '0')
                ) AS state_hash
                FROM musabaqa_scores ms
                LEFT JOIN musabaqa_manual_scoreboard mm ON mm.event_id = ?
                WHERE ms.program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)
            ");
            $stmt->execute([$eventId, $eventId]);
            $currentHash = (string)$stmt->fetchColumn();

            if ($currentHash !== $lastHash) {
                $lastHash = $currentHash;
                $leaderboard = tv_leaderboard($eventId);
                $latestUpdate = tv_latest_score_update($eventId);
                
                send_sse_event('score_update', [
                    'event_id' => $eventId,
                    'leaderboard' => $leaderboard,
                    'latest_update' => $latestUpdate,
                    'timestamp' => time()
                ]);
            }
        }
    } catch (Throwable $e) {
        send_sse_event('stream_error', ['error' => $e->getMessage()]);
    }

    // Send SSE comment ping to keep connection alive
    echo ": ping - " . time() . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();

    usleep(500000); // Check every 0.5 seconds for instant database updates
}

exit;

