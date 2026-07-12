<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/rate-limiter.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!defined('TV_SETTINGS_GLOBAL_KEY')) {
    define('TV_SETTINGS_GLOBAL_KEY', 'tv.global.settings');
}

function tv_pdo(): PDO
{
    return $GLOBALS['musabaqa_pdo'];
}

function tv_dashboard_pdo(): PDO
{
    return $GLOBALS['dashboard_pdo'];
}

function tv_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tv_json_success(array $data = [], array $extra = []): void
{
    tv_json(array_merge([
        'success' => true,
        'data' => $data,
        'timestamp' => time(),
    ], $extra));
}

function tv_json_error(string $message = 'TV display data is unavailable.', int $status = 500): void
{
    tv_json([
        'success' => false,
        'message' => $message,
        'timestamp' => time(),
    ], $status);
}

function tv_log(Throwable $exception, string $context = 'TV'): void
{
    error_log(sprintf(
        '[%s] %s in %s:%d',
        $context,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
}

function tv_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$table]);

    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function tv_is_list_array(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function tv_merge_settings(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (
            isset($base[$key])
            && is_array($base[$key])
            && is_array($value)
            && !tv_is_list_array($base[$key])
            && !tv_is_list_array($value)
        ) {
            $base[$key] = tv_merge_settings($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function tv_default_slides(): array
{
    return [
        'intro' => [
            'key' => 'intro',
            'title' => 'Grand Opening',
            'duration' => 12000,
            'enabled' => true,
            'sort_order' => 1,
        ],
        'leaderboard' => [
            'key' => 'leaderboard',
            'title' => 'Live Leaderboard',
            'duration' => 16000,
            'enabled' => true,
            'sort_order' => 2,
        ],
        'schedule' => [
            'key' => 'schedule',
            'title' => 'Upcoming Programs',
            'duration' => 18000,
            'enabled' => true,
            'sort_order' => 3,
        ],
        'current-program' => [
            'key' => 'current-program',
            'title' => 'Current Stage',
            'duration' => 18000,
            'enabled' => true,
            'sort_order' => 4,
        ]
    ];
}

function tv_default_settings(): array
{
    return [
        'is_playing' => true,
        'mode' => 'auto',
        'active_slide' => 'intro',
        'theme' => 'emerald',
        'refresh_interval' => 5000,
        'slides' => tv_default_slides(),
        'announcement' => [
            'enabled' => true,
            'type' => 'static',
            'message' => 'وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ',
        ],
        'emergency' => [
            'enabled' => false,
            'message' => '',
        ],
        'celebration' => [
            'id' => '',
            'program_id' => null,
            'title' => '',
            'winner' => '',
            'team' => '',
            'team_color' => '#d6b25e',
            'score' => null,
            'triggered_at' => '',
        ],
        'sponsors' => [],
        'quotes' => [
            'Indeed, with hardship comes ease.',
            'And say: My Lord, increase me in knowledge.',
            'The best among you are those who learn and teach.',
        ],
        'updated_at' => date(DATE_ATOM),
    ];
}

function tv_setting_key(int $eventId): string
{
    return $eventId > 0 ? 'tv.event.' . $eventId . '.settings' : TV_SETTINGS_GLOBAL_KEY;
}

function tv_decode_settings(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function tv_read_settings_row(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT setting_value FROM musabaqa_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);

    return tv_decode_settings($stmt->fetchColumn() ?: null);
}

function tv_legacy_component_settings(PDO $pdo, int $eventId): array
{
    if (!tv_table_exists($pdo, 'musabaqa_tv_components')) {
        return [];
    }

    // Dynamic database column self-healing initialization
    try {
        $pdo->exec("ALTER TABLE musabaqa_tv_components ADD COLUMN style VARCHAR(50) NOT NULL DEFAULT 'classic'");
    } catch (PDOException $e) {
        // Suppress error if column already exists
    }

    $params = [];
    if ($eventId > 0) {
        $where = 'event_id = ? OR (event_id IS NULL AND NOT EXISTS (SELECT 1 FROM musabaqa_tv_components WHERE event_id = ?))';
        $params = [$eventId, $eventId];
    } else {
        $where = 'event_id IS NULL';
    }

    $stmt = $pdo->prepare("
        SELECT slide_key, title, duration, is_enabled, sort_order, style
        FROM musabaqa_tv_components
        WHERE {$where}
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute($params);

    $slides = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = str_replace('_', '-', (string)$row['slide_key']);
        $slides[$key] = [
            'key' => $key,
            'title' => (string)($row['title'] ?: ucfirst($key)),
            'duration' => max(3000, (int)$row['duration']),
            'enabled' => (int)$row['is_enabled'] === 1,
            'sort_order' => (int)$row['sort_order'],
            'style' => (string)($row['style'] ?? 'classic'),
        ];
    }

    return $slides ? ['slides' => $slides] : [];
}

function tv_normalize_settings(array $settings): array
{
    $defaults = tv_default_settings();
    $settings = tv_merge_settings($defaults, $settings);

    $settings['mode'] = in_array((string)$settings['mode'], ['auto', 'manual'], true)
        ? (string)$settings['mode']
        : 'auto';
    $settings['theme'] = in_array((string)$settings['theme'], ['emerald', 'royal', 'midnight'], true)
        ? (string)$settings['theme']
        : 'emerald';
    $settings['is_playing'] = (bool)$settings['is_playing'];
    $settings['refresh_interval'] = max(2000, min(30000, (int)$settings['refresh_interval']));

    $cleanSlides = [];
    foreach ($defaults['slides'] as $key => $slide) {
        $cleanSlides[$key] = tv_merge_settings($slide, $settings['slides'][$key] ?? []);
        $cleanSlides[$key]['key'] = $key;
        $cleanSlides[$key]['duration'] = max(3000, min(120000, (int)$cleanSlides[$key]['duration']));
        $cleanSlides[$key]['enabled'] = (bool)$cleanSlides[$key]['enabled'];
        $cleanSlides[$key]['sort_order'] = (int)$cleanSlides[$key]['sort_order'];
        if ($key === 'leaderboard') {
            $cleanSlides[$key]['style'] = in_array($cleanSlides[$key]['style'] ?? 'classic', ['classic', 'orbit', 'podium', 'staggered'], true)
                ? $cleanSlides[$key]['style']
                : 'classic';
        }
    }
    $settings['slides'] = $cleanSlides;

    uasort($settings['slides'], static function (array $a, array $b): int {
        return [$a['sort_order'], $a['key']] <=> [$b['sort_order'], $b['key']];
    });

    if (!isset($settings['slides'][(string)$settings['active_slide']])) {
        $settings['active_slide'] = 'intro';
    }

    $settings['sponsors'] = array_values(array_filter(
        array_map(static function ($sponsor): array {
            $sponsor = is_array($sponsor) ? $sponsor : [];

            return [
                'name' => trim((string)($sponsor['name'] ?? '')),
                'logo_url' => trim((string)($sponsor['logo_url'] ?? '')),
                'message' => trim((string)($sponsor['message'] ?? '')),
                'enabled' => !array_key_exists('enabled', $sponsor) || (bool)$sponsor['enabled'],
            ];
        }, (array)$settings['sponsors']),
        static fn (array $sponsor): bool => $sponsor['enabled'] && ($sponsor['name'] !== '' || $sponsor['logo_url'] !== '')
    ));

    return $settings;
}

function tv_get_settings(?int $eventId = null): array
{
    $pdo = tv_pdo();
    $eventId = $eventId ?? tv_active_event_id();

    $settings = tv_default_settings();
    $settings = tv_merge_settings($settings, tv_legacy_component_settings($pdo, $eventId));
    $settings = tv_merge_settings($settings, tv_read_settings_row($pdo, tv_setting_key($eventId)));

    return tv_normalize_settings($settings);
}

function tv_save_settings(int $eventId, array $settings): array
{
    $pdo = tv_pdo();
    $settings['updated_at'] = date(DATE_ATOM);
    $settings = tv_normalize_settings($settings);

    $stmt = $pdo->prepare("
        INSERT INTO musabaqa_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        tv_setting_key($eventId),
        json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return $settings;
}

function tv_active_event(): ?array
{
    static $event = null;
    static $loaded = false;

    if ($loaded) {
        return $event;
    }

    $loaded = true;
    $pdo = tv_pdo();
    $sessionEventId = (int)($_SESSION['active_event_id'] ?? 0);

    if ($sessionEventId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM musabaqa_events WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionEventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($event) {
            return $event;
        }
    }

    $stmt = $pdo->query("
        SELECT *
        FROM musabaqa_events
        WHERE status = 'active'
        ORDER BY COALESCE(start_date, '1900-01-01') DESC, id DESC
        LIMIT 1
    ");
    $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($event) {
        $_SESSION['active_event_id'] = (int)$event['id'];
        return $event;
    }

    $stmt = $pdo->query("
        SELECT *
        FROM musabaqa_events
        ORDER BY id DESC
        LIMIT 1
    ");
    $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($event) {
        $_SESSION['active_event_id'] = (int)$event['id'];
    }

    return $event;
}

function tv_active_event_id(): int
{
    $event = tv_active_event();

    return $event ? (int)$event['id'] : 0;
}

function tv_event_payload(?array $event): array
{
    return [
        'id' => $event ? (int)$event['id'] : 0,
        'title' => $event['title'] ?? APP_NAME,
        'slug' => $event['slug'] ?? '',
        'description' => $event['description'] ?? '',
        'status' => $event['status'] ?? '',
        'start_date' => $event['start_date'] ?? null,
        'end_date' => $event['end_date'] ?? null,
        'scoreboard_mode' => $event['scoreboard_mode'] ?? 'system',
    ];
}

function tv_format_datetime(?string $value, string $format): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date($format, $timestamp) : '';
}

function tv_program_datetime_columns(PDO $pdo): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'musabaqa_programs'
    ");
    $stmt->execute();
    $available = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $start = in_array('start_datetime', $available, true) ? 'start_datetime' : 'start_time';
    $end = in_array('end_datetime', $available, true) ? 'end_datetime' : 'end_time';

    return $columns = [$start, $end];
}

function tv_color(?string $value, string $fallback = '#00ff88'): string
{
    $value = trim((string)$value);

    if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $value)) {
        return $value;
    }

    $lowerValue = strtolower($value);
    $colorMap = [
        'green' => '#00ff88',
        'red' => '#ff2255',
        'blue' => '#00aaff',
        'yellow' => '#ffee00',
        'white' => '#e0f7ff',
        'purple' => '#d000ff',
        'orange' => '#ff8800',
        'pink' => '#ff00bb',
        'black' => '#18181e',
    ];

    if (isset($colorMap[$lowerValue])) {
        return $colorMap[$lowerValue];
    }

    if (in_array($lowerValue, ['green', 'red', 'blue', 'yellow', 'white', 'black', 'orange', 'purple', 'pink'], true)) {
        return $value;
    }

    return $fallback;
}

function tv_leaderboard(?int $eventId = null): array
{
    $event = tv_active_event();
    $eventId = $eventId ?? (int)($event['id'] ?? 0);
    if ($eventId <= 0) {
        return [];
    }

    $pdo = tv_pdo();
    $manualFirst = ($event['scoreboard_mode'] ?? 'system') === 'manual';
    $scoreExpr = $manualFirst
        ? 'COALESCE(manual_scores.score, t.total_score, approved_scores.total_score, 0)'
        : 'COALESCE(approved_scores.total_score, t.total_score, manual_scores.score, 0)';

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.team_name,
            t.short_name,
            t.team_color,
            {$scoreExpr} AS total_score
        FROM musabaqa_teams t
        LEFT JOIN (
            SELECT pe.team_id, SUM(ms.total_mark) AS total_score
            FROM musabaqa_scores ms
            JOIN musabaqa_program_entries pe ON pe.id = ms.entry_id
            WHERE ms.event_id = ?
              AND ms.status = 'approved'
            GROUP BY pe.team_id
        ) approved_scores ON approved_scores.team_id = t.id
        LEFT JOIN musabaqa_manual_scoreboard manual_scores
               ON manual_scores.team_id = t.id
              AND manual_scores.event_id = ?
        WHERE t.event_id = ?
        ORDER BY total_score DESC, t.team_name ASC, t.id ASC
    ");
    $stmt->execute([$eventId, $eventId, $eventId]);

    $rows = [];
    $rank = 0;
    $previousScore = null;
    $position = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $position++;
        $score = (float)$row['total_score'];
        if ($previousScore === null || $score < $previousScore) {
            $rank = $position;
        }
        $previousScore = $score;

        $rows[] = [
            'id' => (int)$row['id'],
            'rank' => $rank,
            'team_name' => $row['team_name'],
            'short_name' => $row['short_name'] ?: $row['team_name'],
            'team_color' => tv_color($row['team_color'] ?? null),
            'total_score' => round($score, 2),
            'logo_url' => '',
        ];
    }

    return $rows;
}

function tv_latest_score_update(?int $eventId = null): ?array
{
    $eventId = $eventId ?? tv_active_event_id();
    if ($eventId <= 0) {
        return null;
    }

    $pdo = tv_pdo();
    $stmt = $pdo->prepare("
        SELECT
            ms.id,
            ms.total_mark,
            COALESCE(ms.approved_at, ms.updated_at, ms.created_at) AS approved_time,
            p.title AS program_title,
            pe.entry_name,
            t.team_name,
            t.short_name,
            t.team_color
        FROM musabaqa_scores ms
        JOIN musabaqa_program_entries pe ON pe.id = ms.entry_id
        JOIN musabaqa_programs p ON p.id = ms.program_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE ms.event_id = ?
          AND ms.status = 'approved'
        ORDER BY COALESCE(ms.approved_at, ms.updated_at, ms.created_at) DESC, ms.id DESC
        LIMIT 1
    ");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'program_title' => $row['program_title'],
        'entry_name' => $row['entry_name'],
        'team_name' => $row['team_name'],
        'short_name' => $row['short_name'] ?: $row['team_name'],
        'team_color' => tv_color($row['team_color'] ?? null),
        'score' => round((float)$row['total_mark'], 2),
        'approved_time' => $row['approved_time'],
    ];
}

function tv_program_rows(int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }

    $pdo = tv_pdo();
    [$startColumn, $endColumn] = tv_program_datetime_columns($pdo);

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            p.{$startColumn} AS tv_start_time,
            p.{$endColumn} AS tv_end_time,
            st.name AS stage_name,
            ct.name AS class_type_name,
            COUNT(DISTINCT pe.id) AS entry_count,
            COUNT(DISTINCT CASE WHEN pe.status = 'completed' THEN pe.id END) AS completed_entry_count
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id AND pe.event_id = p.event_id
        WHERE p.event_id = ?
        GROUP BY p.id
        ORDER BY
            CASE WHEN p.{$startColumn} IS NULL THEN 1 ELSE 0 END ASC,
            p.{$startColumn} ASC,
            p.id ASC
    ");
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tv_program_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'title' => $row['title'] ?? 'Program',
        'program_type' => $row['program_type'] ?? '',
        'category' => $row['class_type_name'] ?? 'All Classes',
        'stage' => $row['stage_name'] ?? 'Stage',
        'location' => $row['location'] ?? '',
        'start_time' => $row['tv_start_time'] ?? $row['start_time'] ?? $row['start_datetime'] ?? null,
        'end_time' => $row['tv_end_time'] ?? $row['end_time'] ?? $row['end_datetime'] ?? null,
        'start_label' => tv_format_datetime($row['tv_start_time'] ?? $row['start_time'] ?? $row['start_datetime'] ?? null, 'h:i A'),
        'end_label' => tv_format_datetime($row['tv_end_time'] ?? $row['end_time'] ?? $row['end_datetime'] ?? null, 'h:i A'),
        'status' => $row['status'] ?? 'active',
        'approval_status' => $row['approval_status'] ?? 'none',
        'entry_count' => (int)($row['entry_count'] ?? 0),
        'completed_entry_count' => (int)($row['completed_entry_count'] ?? 0),
        'section_id' => isset($row['section_id']) ? (int)$row['section_id'] : null,
    ];
}

function tv_program_entries(int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    $pdo = tv_pdo();
    $stmt = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.short_name,
            t.team_color
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.program_id = ?
        ORDER BY
            CASE pe.status
                WHEN 'scoring' THEN 0
                WHEN 'approved' THEN 1
                ELSE 2
            END,
            COALESCE(pe.entry_number, pe.id) ASC,
            pe.id ASC
    ");
    $stmt->execute([$programId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tv_current_program(?int $eventId = null): array
{
    $eventId = $eventId ?? tv_active_event_id();
    $empty = [
        'is_break' => true,
        'program' => null,
        'performer' => null,
        'next_performer' => null,
        'next_program' => null,
        'judges' => [],
        'status' => 'Break',
    ];

    if ($eventId <= 0) {
        return $empty;
    }

    $programs = tv_program_rows($eventId);
    $now = time();
    $selected = null;

    foreach ($programs as $program) {
        $startValue = $program['tv_start_time'] ?? $program['start_time'] ?? $program['start_datetime'] ?? null;
        $endValue = $program['tv_end_time'] ?? $program['end_time'] ?? $program['end_datetime'] ?? null;
        $start = !empty($startValue) ? strtotime((string)$startValue) : null;
        $end = !empty($endValue) ? strtotime((string)$endValue) : null;
        if ($start && $end && $start <= $now && $end >= $now) {
            $selected = $program;
            break;
        }
    }

    if (!$selected) {
        foreach ($programs as $program) {
            if (($program['status'] ?? '') === 'scoring') {
                $selected = $program;
                break;
            }
        }
    }

    if (!$selected) {
        foreach ($programs as $program) {
            if (($program['status'] ?? '') !== 'completed') {
                $selected = $program;
                break;
            }
        }
    }

    if (!$selected) {
        return $empty;
    }

    $entries = tv_program_entries((int)$selected['id']);
    $currentIndex = null;
    foreach ($entries as $index => $entry) {
        if (($entry['status'] ?? '') !== 'completed') {
            $currentIndex = $index;
            break;
        }
    }
    if ($currentIndex === null && $entries) {
        $currentIndex = 0;
    }

    $current = $currentIndex !== null ? $entries[$currentIndex] : null;
    $next = null;
    if ($currentIndex !== null) {
        for ($i = $currentIndex + 1; $i < count($entries); $i++) {
            if (($entries[$i]['status'] ?? '') !== 'completed') {
                $next = $entries[$i];
                break;
            }
        }
    }

    $nextProgram = null;
    foreach ($programs as $program) {
        if ((int)$program['id'] === (int)$selected['id']) {
            continue;
        }
        if (($program['status'] ?? '') !== 'completed') {
            $startValue = $program['tv_start_time'] ?? $program['start_time'] ?? $program['start_datetime'] ?? null;
            $start = !empty($startValue) ? strtotime((string)$startValue) : PHP_INT_MAX;
            if (!$nextProgram || $start < (int)($nextProgram['_sort'] ?? PHP_INT_MAX)) {
                $nextProgram = tv_program_payload($program);
                $nextProgram['_sort'] = $start;
            }
        }
    }
    if ($nextProgram) {
        unset($nextProgram['_sort']);
    }

    $pdo = tv_pdo();
    $judges = [];
    if (tv_table_exists($pdo, 'musabaqa_judges')) {
        $judgeRows = $pdo->query("
            SELECT name
            FROM musabaqa_judges
            WHERE active = 1
            ORDER BY name ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_COLUMN);
        $judges = $judgeRows ?: [];
    }

    $entryPayload = static function (?array $entry): ?array {
        if (!$entry) {
            return null;
        }

        return [
            'id' => (int)$entry['id'],
            'name' => $entry['entry_name'] ?: ('Entry #' . $entry['entry_number']),
            'number' => $entry['entry_number'],
            'team' => $entry['team_name'],
            'team_short' => $entry['short_name'] ?: $entry['team_name'],
            'team_color' => tv_color($entry['team_color'] ?? null),
            'status' => $entry['status'] ?? 'approved',
            'score' => (float)($entry['final_score'] ?? 0),
        ];
    };

    return [
        'is_break' => false,
        'program' => tv_program_payload($selected),
        'performer' => $entryPayload($current),
        'next_performer' => $entryPayload($next),
        'next_program' => $nextProgram,
        'judges' => $judges,
        'status' => ($selected['status'] ?? '') === 'scoring' ? 'Scoring Live' : 'On Stage',
    ];
}

function tv_schedule(?int $eventId = null, int $limit = 9): array
{
    $eventId = $eventId ?? tv_active_event_id();
    if ($eventId <= 0) {
        return ['timeline' => [], 'upcoming' => [], 'completed' => []];
    }

    $pdo = tv_pdo();
    $programs = array_map('tv_program_payload', tv_program_rows($eventId));
    $breaks = [];

    if (tv_table_exists($pdo, 'musabaqa_breaks')) {
        $stmt = $pdo->prepare("
            SELECT b.*, st.name AS stage_name
            FROM musabaqa_breaks b
            LEFT JOIN musabaqa_stage_types st ON st.id = b.stage_type_id
            WHERE b.event_id = ?
            ORDER BY b.start_datetime ASC, b.id ASC
        ");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $break) {
            $breaks[] = [
                'id' => (int)$break['id'],
                'title' => $break['name'],
                'description' => $break['description'] ?? '',
                'stage' => $break['stage_name'] ?? 'Stage',
                'start_time' => $break['start_datetime'],
                'end_time' => $break['end_datetime'],
                'start_label' => tv_format_datetime($break['start_datetime'] ?? null, 'h:i A'),
                'end_label' => tv_format_datetime($break['end_datetime'] ?? null, 'h:i A'),
                'status' => 'break',
                'type' => 'break',
            ];
        }
    }

    $timeline = [];
    foreach ($programs as $program) {
        $program['type'] = 'program';
        $timeline[] = $program;
    }
    foreach ($breaks as $break) {
        $timeline[] = $break;
    }

    usort($timeline, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['start_time'] ?? '9999-12-31 23:59:59')) ?: PHP_INT_MAX;
        $bTime = strtotime((string)($b['start_time'] ?? '9999-12-31 23:59:59')) ?: PHP_INT_MAX;

        return [$aTime, $a['id']] <=> [$bTime, $b['id']];
    });

    $now = time();
    $upcoming = [];
    $completed = [];
    foreach ($timeline as $item) {
        $end = !empty($item['end_time']) ? strtotime((string)$item['end_time']) : null;
        $isProgramCompleted = ($item['type'] ?? '') === 'program'
            && (($item['status'] ?? '') === 'completed' || ($item['approval_status'] ?? '') === 'approved');
        $isBreakCompleted = ($item['type'] ?? '') === 'break' && $end && $end < $now;
        $isCompleted = $isProgramCompleted || $isBreakCompleted;
        if ($isCompleted) {
            $completed[] = $item;
            continue;
        }
        if (count($upcoming) < $limit) {
            $upcoming[] = $item;
        }
    }

    // Load active sections
    $sections = [];
    if (tv_table_exists($pdo, 'musabaqa_schedule_sections')) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM musabaqa_schedule_sections
            WHERE event_id = ?
            ORDER BY sort_order ASC, start_time ASC, id ASC
        ");
        $stmt->execute([$eventId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Helper to check if a datetime's time fits in range
    $tvTimeInRange = static function(string $timeStr, string $start, string $end): bool {
        $time = date('H:i:s', strtotime($timeStr));
        if ($start <= $end) {
            return $time >= $start && $time <= $end;
        } else {
            return $time >= $start || $time <= $end;
        }
    };

    $sectionsData = [];
    foreach ($sections as $sec) {
        $sectionsData[(int)$sec['id']] = [
            'id' => (int)$sec['id'],
            'name' => $sec['name'],
            'start_time' => $sec['start_time'],
            'end_time' => $sec['end_time'],
            'time_label' => date('h:i A', strtotime($sec['start_time'])) . ' - ' . date('h:i A', strtotime($sec['end_time'])),
            'items' => []
        ];
    }
    
    $unassignedData = [
        'id' => 0,
        'name' => 'Other Programs',
        'start_time' => null,
        'end_time' => null,
        'time_label' => '',
        'items' => []
    ];

    foreach ($timeline as $item) {
        $assignedSecId = null;
        if (($item['type'] ?? '') === 'program' && !empty($item['section_id'])) {
            $assignedSecId = (int)$item['section_id'];
        } else {
            // Find by time range
            $itemTime = $item['start_time'] ?? null;
            if ($itemTime) {
                foreach ($sections as $sec) {
                    if ($tvTimeInRange($itemTime, $sec['start_time'], $sec['end_time'])) {
                        $assignedSecId = (int)$sec['id'];
                        break;
                    }
                }
            }
        }

        if ($assignedSecId !== null && isset($sectionsData[$assignedSecId])) {
            $sectionsData[$assignedSecId]['items'][] = $item;
        } else {
            $unassignedData['items'][] = $item;
        }
    }

    $sectionsList = array_values($sectionsData);
    if (!empty($unassignedData['items'])) {
        $sectionsList[] = $unassignedData;
    }

    // Filter out empty sections
    $sectionsList = array_filter($sectionsList, static function(array $s): bool {
        return !empty($s['items']);
    });
    $sectionsList = array_values($sectionsList);

    return [
        'sections' => $sectionsList,
        'timeline' => $timeline,
        'upcoming' => $upcoming,
        'completed' => array_slice(array_reverse($completed), 0, 6),
    ];
}

function tv_winners(?int $eventId = null, int $limit = 8): array
{
    $eventId = $eventId ?? tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = tv_pdo();
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            st.name AS stage_name,
            ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
        WHERE p.event_id = ?
          AND (p.status = 'completed' OR p.approval_status = 'approved')
        ORDER BY COALESCE(p.reviewed_at, p.end_time, p.created_at) DESC, p.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $eventId, PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    $programs = [];
    $winnerStmt = $pdo->prepare("
        SELECT
            pe.id,
            pe.entry_name,
            pe.entry_number,
            pe.final_rank,
            COALESCE(NULLIF(pe.final_score, 0), MAX(CASE WHEN ms.status = 'approved' THEN ms.total_mark END), 0) AS score,
            t.team_name,
            t.short_name,
            t.team_color
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN musabaqa_scores ms ON ms.entry_id = pe.id AND ms.program_id = pe.program_id
        WHERE pe.program_id = ?
        GROUP BY pe.id
        HAVING score > 0 OR pe.final_rank IS NOT NULL
        ORDER BY
            CASE WHEN pe.final_rank IS NULL THEN 999 ELSE pe.final_rank END ASC,
            score DESC,
            COALESCE(pe.entry_number, pe.id) ASC
        LIMIT 3
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $program) {
        $winnerStmt->execute([(int)$program['id']]);
        $winners = [];
        $place = 1;
        foreach ($winnerStmt->fetchAll(PDO::FETCH_ASSOC) as $winner) {
            $rank = (int)($winner['final_rank'] ?: $place);
            $winners[] = [
                'place' => $rank,
                'entry_id' => (int)$winner['id'],
                'name' => $winner['entry_name'] ?: ('Entry #' . $winner['entry_number']),
                'number' => $winner['entry_number'],
                'team' => $winner['team_name'],
                'team_short' => $winner['short_name'] ?: $winner['team_name'],
                'team_color' => tv_color($winner['team_color'] ?? null, '#d6b25e'),
                'score' => round((float)$winner['score'], 2),
            ];
            $place++;
        }

        if (!$winners) {
            continue;
        }

        $programs[] = [
            'id' => (int)$program['id'],
            'title' => $program['title'],
            'category' => $program['class_type_name'] ?? 'All Classes',
            'stage' => $program['stage_name'] ?? 'Stage',
            'completed_at' => $program['reviewed_at'] ?? $program['end_time'] ?? null,
            'winners' => $winners,
        ];
    }

    return $programs;
}

function tv_announcements(?int $eventId = null, ?array $settings = null): array
{
    $eventId = $eventId ?? tv_active_event_id();
    $settings = $settings ?? tv_get_settings($eventId);
    $items = [];

    if (!empty($settings['emergency']['enabled']) && trim((string)$settings['emergency']['message']) !== '') {
        $items[] = [
            'type' => 'emergency',
            'message' => trim((string)$settings['emergency']['message']),
            'priority' => 100,
        ];
    }

    if (!empty($settings['announcement']['enabled']) && trim((string)$settings['announcement']['message']) !== '') {
        $items[] = [
            'type' => (string)($settings['announcement']['type'] ?? 'static'),
            'message' => trim((string)$settings['announcement']['message']),
            'priority' => 50,
        ];
    }

    $activeBreak = tv_active_break($eventId);
    if ($activeBreak) {
        $items[] = [
            'type' => 'break',
            'message' => $activeBreak['name'] . ' is now in progress.',
            'priority' => 70,
        ];
    }

    foreach ((array)$settings['sponsors'] as $sponsor) {
        if (!empty($sponsor['message'])) {
            $items[] = [
                'type' => 'sponsor',
                'message' => (string)$sponsor['message'],
                'priority' => 20,
            ];
        }
    }

    if (!$items) {
        $items[] = [
            'type' => 'static',
            'message' => 'Competition updates will appear here automatically.',
            'priority' => 10,
        ];
    }

    usort($items, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

    return $items;
}

function tv_sponsors(?int $eventId = null, ?array $settings = null): array
{
    $settings = $settings ?? tv_get_settings($eventId ?? tv_active_event_id());
    $sponsors = $settings['sponsors'];

    if (!$sponsors) {
        $sponsors = [
            [
                'name' => 'Kauzariyya',
                'logo_url' => tv_asset_url('kauzariyya-logo.png'),
                'message' => 'Official event host',
                'enabled' => true,
            ],
            [
                'name' => 'Thanafus',
                'logo_url' => tv_asset_url('thanafus-logo.png'),
                'message' => 'Digital Musabaqa System',
                'enabled' => true,
            ],
        ];
    }

    return array_values($sponsors);
}

function tv_active_break(int $eventId): ?array
{
    if ($eventId <= 0 || !tv_table_exists(tv_pdo(), 'musabaqa_breaks')) {
        return null;
    }

    $stmt = tv_pdo()->prepare("
        SELECT b.*, st.name AS stage_name
        FROM musabaqa_breaks b
        LEFT JOIN musabaqa_stage_types st ON st.id = b.stage_type_id
        WHERE b.event_id = ?
          AND b.start_datetime <= NOW()
          AND b.end_datetime >= NOW()
        ORDER BY b.start_datetime ASC
        LIMIT 1
    ");
    $stmt->execute([$eventId]);
    $break = $stmt->fetch(PDO::FETCH_ASSOC);

    return $break ?: null;
}

function tv_break_info(?int $eventId = null, ?array $settings = null): array
{
    $eventId = $eventId ?? tv_active_event_id();
    $settings = $settings ?? tv_get_settings($eventId);
    $quotes = (array)($settings['quotes'] ?? []);
    $quote = $quotes ? $quotes[(int)date('z') % count($quotes)] : 'وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ';
    $schedule = tv_schedule($eventId, 1);

    return [
        'clock' => date('h:i A'),
        'date' => date('l, d M Y'),
        'quote' => $quote,
        'active_break' => tv_active_break($eventId),
        'next_item' => $schedule['upcoming'][0] ?? null,
    ];
}

function tv_stats(?int $eventId = null): array
{
    $eventId = $eventId ?? tv_active_event_id();
    if ($eventId <= 0) {
        return [
            'teams' => 0,
            'programs' => 0,
            'completed_programs' => 0,
            'entries' => 0,
        ];
    }

    $pdo = tv_pdo();
    $queries = [
        'teams' => 'SELECT COUNT(*) FROM musabaqa_teams WHERE event_id = ?',
        'programs' => 'SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ?',
        'completed_programs' => "SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ? AND (status = 'completed' OR approval_status = 'approved')",
        'entries' => 'SELECT COUNT(*) FROM musabaqa_program_entries WHERE event_id = ?',
    ];

    $stats = [];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$eventId]);
        $stats[$key] = (int)$stmt->fetchColumn();
    }

    return $stats;
}

function tv_bootstrap_data(): array
{
    $event = tv_active_event();
    $eventId = (int)($event['id'] ?? 0);
    $settings = tv_get_settings($eventId);

    return [
        'event' => tv_event_payload($event),
        'settings' => $settings,
        'stats' => tv_stats($eventId),
        'leaderboard' => tv_leaderboard($eventId),
        'latest_score_update' => tv_latest_score_update($eventId),
        'current' => tv_current_program($eventId),
        'schedule' => tv_schedule($eventId),
        'winners' => tv_winners($eventId),
        'announcements' => tv_announcements($eventId, $settings),
        'sponsors' => tv_sponsors($eventId, $settings),
        'break' => tv_break_info($eventId, $settings),
        'server_time' => date(DATE_ATOM),
    ];
}

function tv_sanitize_dashboard_settings(array $post, array $current): array
{
    $settings = $current;
    $settings['theme'] = (string)($post['theme'] ?? $settings['theme']);
    $settings['mode'] = (string)($post['mode'] ?? $settings['mode']);
    $settings['active_slide'] = (string)($post['active_slide'] ?? $settings['active_slide']);
    $settings['refresh_interval'] = max(2, (int)($post['refresh_interval'] ?? 5)) * 1000;
    $settings['is_playing'] = isset($post['is_playing']);

    foreach ((array)($post['slides'] ?? []) as $key => $slide) {
        $key = str_replace('_', '-', (string)$key);
        if (!isset($settings['slides'][$key])) {
            continue;
        }

        $settings['slides'][$key]['title'] = trim((string)($slide['title'] ?? $settings['slides'][$key]['title']));
        $settings['slides'][$key]['duration'] = max(3, (int)($slide['duration'] ?? 10)) * 1000;
        $settings['slides'][$key]['sort_order'] = (int)($slide['sort_order'] ?? $settings['slides'][$key]['sort_order']);
        $settings['slides'][$key]['enabled'] = isset($slide['enabled']);
    }

    $settings['announcement'] = [
        'enabled' => isset($post['announcement_enabled']),
        'type' => (string)($post['announcement_type'] ?? 'static'),
        'message' => trim((string)($post['announcement_message'] ?? '')),
    ];
    $settings['emergency'] = [
        'enabled' => isset($post['emergency_enabled']),
        'message' => trim((string)($post['emergency_message'] ?? '')),
    ];

    $names = (array)($post['sponsor_name'] ?? []);
    $logos = (array)($post['sponsor_logo_url'] ?? []);
    $messages = (array)($post['sponsor_message'] ?? []);
    $enabled = (array)($post['sponsor_enabled'] ?? []);
    $sponsors = [];
    for ($i = 0; $i < max(count($names), count($logos), count($messages)); $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $logoUrl = trim((string)($logos[$i] ?? ''));
        $message = trim((string)($messages[$i] ?? ''));
        if ($name === '' && $logoUrl === '' && $message === '') {
            continue;
        }
        $sponsors[] = [
            'name' => $name,
            'logo_url' => $logoUrl,
            'message' => $message,
            'enabled' => array_key_exists((string)$i, $enabled) || array_key_exists($i, $enabled),
        ];
    }
    $settings['sponsors'] = $sponsors;

    $quotes = preg_split('/\r\n|\r|\n/', (string)($post['quotes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    if ($quotes) {
        $settings['quotes'] = array_values(array_map('trim', $quotes));
    }

    return tv_normalize_settings($settings);
}

function tv_dashboard_winner_options(int $eventId): array
{
    $options = [];
    foreach (tv_winners($eventId, 20) as $program) {
        $winner = $program['winners'][0] ?? null;
        if (!$winner) {
            continue;
        }

        $options[] = [
            'program_id' => $program['id'],
            'label' => $program['title'] . ' - ' . $winner['team'],
            'title' => $program['title'],
            'winner' => $winner['name'],
            'team' => $winner['team'],
            'team_color' => $winner['team_color'],
            'score' => $winner['score'],
        ];
    }

    return $options;
}
