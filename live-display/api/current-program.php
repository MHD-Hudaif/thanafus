<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

try {
    tv_json_success([
        'event' => tv_event_payload(tv_active_event()),
        'current' => tv_current_program(),
    ]);
} catch (Throwable $exception) {
    tv_log($exception, 'TV current program API');
    tv_json_error('Current program is temporarily unavailable.');
}
