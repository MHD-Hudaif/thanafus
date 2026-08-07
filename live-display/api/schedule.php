<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

try {
    tv_json_success([
        'event' => tv_event_payload(tv_active_event()),
        'schedule' => tv_schedule(),
    ]);
} catch (Throwable $exception) {
    tv_log($exception, 'TV schedule API');
    tv_json_error('Schedule is temporarily unavailable.');
}
