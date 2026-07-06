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

function tv_settings_trigger_celebration(int $eventId, array $settings): array
{
    $programId = (int)($_POST['program_id'] ?? 0);
    $winner = null;

    foreach (tv_dashboard_winner_options($eventId) as $option) {
        if ((int)$option['program_id'] === $programId) {
            $winner = $option;
            break;
        }
    }

    if (!$winner) {
        $winner = [
            'program_id' => null,
            'title' => trim((string)($_POST['title'] ?? 'Winner Celebration')),
            'winner' => trim((string)($_POST['winner'] ?? 'Champion')),
            'team' => trim((string)($_POST['team'] ?? 'Winning Team')),
            'team_color' => tv_color($_POST['team_color'] ?? null, '#d6b25e'),
            'score' => is_numeric($_POST['score'] ?? null) ? (float)$_POST['score'] : null,
        ];
    }

    $settings['celebration'] = [
        'id' => bin2hex(random_bytes(8)),
        'program_id' => $winner['program_id'],
        'title' => $winner['title'],
        'winner' => $winner['winner'],
        'team' => $winner['team'],
        'team_color' => tv_color($winner['team_color'] ?? null, '#d6b25e'),
        'score' => $winner['score'],
        'triggered_at' => date(DATE_ATOM),
    ];

    return $settings;
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
        } elseif ($action === 'announcement') {
            $settings['announcement'] = [
                'enabled' => isset($_POST['enabled']),
                'type' => (string)($_POST['type'] ?? 'static'),
                'message' => trim((string)($_POST['message'] ?? '')),
            ];
        } elseif ($action === 'emergency') {
            $settings['emergency'] = [
                'enabled' => isset($_POST['enabled']),
                'message' => trim((string)($_POST['message'] ?? '')),
            ];
        } elseif ($action === 'clear_emergency') {
            $settings['emergency'] = ['enabled' => false, 'message' => ''];
        } elseif ($action === 'celebration') {
            $settings = tv_settings_trigger_celebration($eventId, $settings);
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
