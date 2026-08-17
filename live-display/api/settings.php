<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

function tv_settings_require_admin(): void
{
    require_once __DIR__ . '/../../includes/admin-helpers.php';
    require_login();

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        tv_json_error('Invalid security token.', 403);
    }
}



try {
    $event = tv_active_event();
    $eventId = (int)($event['id'] ?? 0);
    $settings = tv_get_settings($eventId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        tv_settings_require_admin();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'play') {
            $settings['is_playing'] = true;
        } elseif ($action === 'pause') {
            $settings['is_playing'] = false;
        } elseif ($action === 'mode') {
            $settings['mode'] = (string)($_POST['mode'] ?? 'auto');
        } elseif ($action === 'slide') {
            $settings['mode'] = 'manual';
            $settings['active_slide'] = str_replace('_', '-', (string)($_POST['slide'] ?? 'intro'));
        } elseif ($action === 'theme') {
            $settings['theme'] = (string)($_POST['theme'] ?? 'emerald');
        } elseif ($action === 'performance_mode') {
            $settings['performance_mode'] = (string)($_POST['performance_mode'] ?? 'quality');
        } else {
            tv_json_error('Unknown TV control action.', 422);
        }

        $settings = tv_save_settings($eventId, $settings);
        tv_json_success([
            'event' => tv_event_payload($event),
            'settings' => $settings,
        ]);
    }

    tv_json_success(tv_bootstrap_data());
} catch (Throwable $exception) {
    tv_log($exception, 'TV settings API');
    tv_json_error('TV settings are temporarily unavailable.');
}
