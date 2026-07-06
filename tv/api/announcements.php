<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

try {
    $eventId = tv_active_event_id();
    $settings = tv_get_settings($eventId);
    tv_json_success([
        'event' => tv_event_payload(tv_active_event()),
        'announcements' => tv_announcements($eventId, $settings),
        'sponsors' => tv_sponsors($eventId, $settings),
        'break' => tv_break_info($eventId, $settings),
        'settings' => $settings,
    ]);
} catch (Throwable $exception) {
    tv_log($exception, 'TV announcements API');
    tv_json_error('Announcements are temporarily unavailable.');
}
