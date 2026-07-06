<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

try {
    tv_json_success([
        'event' => tv_event_payload(tv_active_event()),
        'winners' => tv_winners(),
    ]);
} catch (Throwable $exception) {
    tv_log($exception, 'TV winners API');
    tv_json_error('Results are temporarily unavailable.');
}
