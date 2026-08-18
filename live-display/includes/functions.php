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

if (!defined('LIVE_DISPLAY_SETTINGS_GLOBAL_KEY')) {
    define('LIVE_DISPLAY_SETTINGS_GLOBAL_KEY', 'live_display.global.settings');
}

function live_display_pdo(): PDO
{
    return $GLOBALS['musabaqa_pdo'];
}

function live_display_dashboard_pdo(): PDO
{
    if (function_exists('get_dashboard_pdo')) {
        return get_dashboard_pdo();
    }
    return $GLOBALS['dashboard_pdo'];
}

function live_display_json(array $payload, int $status = 200): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $etag = '"' . md5($json) . '"';

    header('ETag: ' . $etag);
    header('Cache-Control: private, must-revalidate, max-age=0');

    $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($status === 200 && $clientEtag === $etag) {
        http_response_code(304);
        exit;
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

function live_display_json_success(array $data = [], array $extra = []): void
{
    live_display_json(array_merge([
        'success' => true,
        'data' => $data,
        'timestamp' => time(),
    ], $extra));
}

function live_display_json_error(string $message = 'TV display data is unavailable.', int $status = 500): void
{
    live_display_json([
        'success' => false,
        'message' => $message,
        'timestamp' => time(),
    ], $status);
}

function live_display_log(Throwable $exception, string $context = 'TV'): void
{
    error_log(sprintf(
        '[%s] %s in %s:%d',
        $context,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
}

function live_display_table_exists(PDO $pdo, string $table): bool
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

function live_display_is_list_array(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function live_display_merge_settings(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (
            isset($base[$key])
            && is_array($base[$key])
            && is_array($value)
            && !live_display_is_list_array($base[$key])
            && !live_display_is_list_array($value)
        ) {
            $base[$key] = live_display_merge_settings($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function live_display_default_slides(): array
{
    return [
        'intro' => [
            'key'        => 'intro',
            'title'      => 'Grand Opening',
            'duration'   => 10000,
            'enabled'    => true,
            'sort_order' => 1,
        ],
        'leaderboard' => [
            'key'        => 'leaderboard',
            'title'      => 'Live Leaderboard',
            'duration'   => 5000,
            'enabled'    => true,
            'sort_order' => 2,
        ],
        'schedule' => [
            'key'        => 'schedule',
            'title'      => 'Upcoming Programs',
            'duration'   => 5000,
            'enabled'    => true,
            'sort_order' => 3,
        ],
        'current-program' => [
            'key'        => 'current-program',
            'title'      => 'Current Stage',
            'duration'   => 5000,
            'enabled'    => true,
            'sort_order' => 4,
        ]
    ];
}

function live_display_default_settings(): array
{
    return [
        'is_playing' => true,
        'mode' => 'auto',
        'active_slide' => 'intro',
        'theme' => 'emerald',
        'performance_mode' => 'quality',
        'show_next_participant' => true,
        'refresh_interval' => 5000,
        'slides' => live_display_default_slides(),
        'sponsors' => [],
        'quotes' => [
            'Indeed, with hardship comes ease.',
            'And say: My Lord, increase me in knowledge.',
            'The best among you are those who learn and teach.',
        ],
        'updated_at' => date(DATE_ATOM),
    ];
}

function live_display_setting_key(int $eventId): string
{
    return $eventId > 0 ? 'live_display.event.' . $eventId . '.settings' : LIVE_DISPLAY_SETTINGS_GLOBAL_KEY;
}

function live_display_decode_settings(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function live_display_read_settings_row(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT setting_value FROM musabaqa_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);

    return live_display_decode_settings($stmt->fetchColumn() ?: null);
}

function live_display_legacy_component_settings(PDO $pdo, int $eventId): array
{
    if (live_display_table_exists($pdo, 'musabaqa_live_display_components') && !live_display_table_exists($pdo, 'musabaqa_live_display_components')) {
        try {
            $pdo->exec("RENAME TABLE musabaqa_live_display_components TO musabaqa_live_display_components");
        } catch (Throwable $e) {}
    }

    if (!live_display_table_exists($pdo, 'musabaqa_live_display_components')) {
        return [];
    }

    // Dynamic database column self-healing initialization
    try {
        $pdo->exec("ALTER TABLE musabaqa_live_display_components ADD COLUMN style VARCHAR(50) NOT NULL DEFAULT 'classic'");
    } catch (PDOException $e) {
        // Suppress error if column already exists
    }

    $params = [];
    if ($eventId > 0) {
        $where = 'event_id = ? OR (event_id IS NULL AND NOT EXISTS (SELECT 1 FROM musabaqa_live_display_components WHERE event_id = ?))';
        $params = [$eventId, $eventId];
    } else {
        $where = 'event_id IS NULL';
    }

    $stmt = $pdo->prepare("
        SELECT slide_key, title, duration, is_enabled, sort_order, style
        FROM musabaqa_live_display_components
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
            'duration'   => max(1000, (int)$row['duration']),
            'enabled' => (int)$row['is_enabled'] === 1,
            'sort_order' => (int)$row['sort_order'],
            'style' => (string)($row['style'] ?? 'classic'),
        ];
    }

    return $slides ? ['slides' => $slides] : [];
}

function live_display_normalize_settings(array $settings): array
{
    $defaults = live_display_default_settings();
    $settings = live_display_merge_settings($defaults, $settings);

    $settings['mode'] = in_array((string)$settings['mode'], ['auto', 'manual'], true)
        ? (string)$settings['mode']
        : 'auto';
    $settings['theme'] = in_array((string)$settings['theme'], ['emerald', 'royal', 'midnight'], true)
        ? (string)$settings['theme']
        : 'emerald';
    $settings['performance_mode'] = in_array((string)($settings['performance_mode'] ?? 'quality'), ['quality', 'performance'], true)
        ? (string)$settings['performance_mode']
        : 'quality';
    $settings['show_next_participant'] = isset($settings['show_next_participant']) ? (bool)$settings['show_next_participant'] : true;
    $settings['is_playing'] = (bool)$settings['is_playing'];
    $settings['refresh_interval'] = max(2000, min(30000, (int)$settings['refresh_interval']));

    $cleanSlides = [];
    foreach ($defaults['slides'] as $key => $slide) {
        $cleanSlides[$key] = live_display_merge_settings($slide, $settings['slides'][$key] ?? []);
        $cleanSlides[$key]['key'] = $key;
        $cleanSlides[$key]['duration'] = max(1000, min(120000, (int)$cleanSlides[$key]['duration']));
        $cleanSlides[$key]['enabled'] = (bool)$cleanSlides[$key]['enabled'];
        $cleanSlides[$key]['sort_order'] = (int)$cleanSlides[$key]['sort_order'];
        if ($key === 'leaderboard') {
            $cleanSlides[$key]['style'] = in_array($cleanSlides[$key]['style'] ?? 'classic', ['classic', 'orbit', 'podium', 'staggered', 'style2'], true)
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

function live_display_get_settings(?int $eventId = null): array
{
    $pdo = live_display_pdo();
    $eventId = $eventId ?? live_display_active_event_id();

    $settings = live_display_default_settings();
    $settings = live_display_merge_settings($settings, live_display_read_settings_row($pdo, live_display_setting_key($eventId)));
    $settings = live_display_merge_settings($settings, live_display_legacy_component_settings($pdo, $eventId));

    return live_display_normalize_settings($settings);
}

function live_display_save_settings(int $eventId, array $settings): array
{
    $pdo = live_display_pdo();
    $settings['updated_at'] = date(DATE_ATOM);
    $settings = live_display_normalize_settings($settings);

    $stmt = $pdo->prepare("
        INSERT INTO musabaqa_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        live_display_setting_key($eventId),
        json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return $settings;
}

function live_display_active_event(): ?array
{
    static $event = null;
    static $loaded = false;

    if ($loaded) {
        return $event;
    }

    $loaded = true;
    $pdo = live_display_pdo();

    $stmt = $pdo->query("
        SELECT *
        FROM musabaqa_events
        WHERE status = 'active'
        ORDER BY COALESCE(start_date, '1900-01-01') DESC, id DESC
        LIMIT 1
    ");
    $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($event) {
        return $event;
    }

    $stmt = $pdo->query("
        SELECT *
        FROM musabaqa_events
        ORDER BY id DESC
        LIMIT 1
    ");
    $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return $event;
}

function live_display_active_event_id(): int
{
    $event = live_display_active_event();

    return $event ? (int)$event['id'] : 0;
}

function live_display_event_payload(?array $event): array
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

function live_display_format_datetime(?string $value, string $format): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date($format, $timestamp) : '';
}

function live_display_program_datetime_columns(PDO $pdo): array
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

function live_display_color(?string $value, string $fallback = '#00ff88'): string
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

function live_display_leaderboard(?int $eventId = null): array
{
    $event = live_display_active_event();
    $eventId = $eventId ?? (int)($event['id'] ?? 0);
    if ($eventId <= 0) {
        return [];
    }

    $pdo = live_display_pdo();
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
            SELECT pe.team_id, SUM(pe.team_score) AS total_score
            FROM musabaqa_program_entries pe
            JOIN musabaqa_programs p ON p.id = pe.program_id
            WHERE pe.event_id = ?
              AND p.approval_status = 'approved'
              AND (p.redirect_to_team IS NULL OR p.redirect_to_team = 1)
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
            'team_color' => live_display_color($row['team_color'] ?? null),
            'total_score' => round($score, 2),
            'logo_url' => '',
        ];
    }

    return $rows;
}

function live_display_latest_score_update(?int $eventId = null): ?array
{
    $eventId = $eventId ?? live_display_active_event_id();
    if ($eventId <= 0) {
        return null;
    }

    $pdo = live_display_pdo();
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
        'team_color' => live_display_color($row['team_color'] ?? null),
        'score' => round((float)$row['total_mark'], 2),
        'approved_time' => $row['approved_time'],
    ];
}

function live_display_score_reveal_event(?int $eventId = null): ?array
{
    $pdo = live_display_pdo();
    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'live_score_reveal_event' LIMIT 1");
    $stmt->execute();
    $raw = $stmt->fetchColumn();

    return $raw ? json_decode((string)$raw, true) : null;
}

function live_display_program_rows(int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }

    $pdo = live_display_pdo();
    [$startColumn, $endColumn] = live_display_program_datetime_columns($pdo);

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            p.{$startColumn} AS live_display_start_time,
            p.{$endColumn} AS live_display_end_time,
            st.name AS stage_name,
            ct.name AS class_type_name,
            COUNT(DISTINCT pe.id) AS entry_count,
            COUNT(DISTINCT CASE WHEN pe.status = 'completed' THEN pe.id END) AS completed_entry_count
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id AND pe.event_id = p.event_id
        WHERE p.event_id = ?
        GROUP BY p.id, st.id, ct.id
        ORDER BY
            CASE WHEN p.{$startColumn} IS NULL THEN 1 ELSE 0 END ASC,
            p.{$startColumn} ASC,
            p.id ASC
    ");
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function live_display_program_payload(array $row): array
{
    $pdo = live_display_pdo();
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.team_name,
            t.team_color,
            pe.final_rank,
            COALESCE(SUM(s.total_mark), 0) AS total_points
        FROM musabaqa_teams t
        LEFT JOIN musabaqa_program_entries pe ON pe.team_id = t.id AND pe.program_id = ?
        LEFT JOIN musabaqa_scores s ON s.entry_id = pe.id AND s.judge_name = 'System Final'
        GROUP BY t.id, pe.final_rank
        ORDER BY t.id ASC
    ");
    $stmt->execute([(int)$row['id']]);
    $teamMarks = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tm) {
        $teamMarks[] = [
            'team_name' => $tm['team_name'],
            'team_color' => live_display_color($tm['team_color']),
            'total_points' => (float)$tm['total_points'],
            'final_rank' => $tm['final_rank'] !== null ? (int)$tm['final_rank'] : null,
        ];
    }

    $results = [];
    // Schedule results are public only after the program has been approved.
    if (($row['approval_status'] ?? '') === 'approved') {
        $stmtResults = $pdo->prepare("
            SELECT
                pe.final_rank,
                t.team_name,
                t.short_name,
                t.team_color
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.program_id = ?
              AND pe.final_rank IS NOT NULL
            ORDER BY pe.final_rank ASC, pe.id ASC
        ");
        $stmtResults->execute([(int)$row['id']]);
        foreach ($stmtResults->fetchAll(PDO::FETCH_ASSOC) as $res) {
            $results[] = [
                'rank' => (int)$res['final_rank'],
                'team_name' => $res['team_name'],
                'team_short' => $res['short_name'] ?: $res['team_name'],
                'team_color' => live_display_color($res['team_color']),
            ];
        }
    }

    return [
        'id' => (int)$row['id'],
        'title' => $row['title'] ?? 'Program',
        'program_type' => $row['program_type'] ?? '',
        'category' => $row['class_type_name'] ?? 'All Classes',
        'stage' => $row['stage_name'] ?? 'Stage',
        'location' => $row['location'] ?? '',
        'start_time' => $row['live_display_start_time'] ?? $row['start_time'] ?? $row['start_datetime'] ?? null,
        'end_time' => $row['live_display_end_time'] ?? $row['end_time'] ?? $row['end_datetime'] ?? null,
        'start_label' => live_display_format_datetime($row['live_display_start_time'] ?? $row['start_time'] ?? $row['start_datetime'] ?? null, 'h:i A'),
        'end_label' => live_display_format_datetime($row['live_display_end_time'] ?? $row['end_time'] ?? $row['end_datetime'] ?? null, 'h:i A'),
        'status' => $row['status'] ?? 'active',
        'approval_status' => $row['approval_status'] ?? 'none',
        'entry_count' => (int)($row['entry_count'] ?? 0),
        'completed_entry_count' => (int)($row['completed_entry_count'] ?? 0),
        'section_id' => isset($row['section_id']) ? (int)$row['section_id'] : null,
        'team_marks' => $teamMarks,
        'results' => $results,
    ];
}

function live_display_program_entries(int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    $pdo = live_display_pdo();
    $stmt = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.short_name,
            t.team_color,
            p.program_type,
            p.only_team_marks,
            p.title AS program_title,
            CASE 
                WHEN p.program_type = 'group' OR p.only_team_marks = 1 THEN pe.entry_name
                ELSE COALESCE(
                    (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                     FROM musabaqa_entry_members em
                     JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                     WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> ''),
                    pe.entry_number
                )
            END AS chest_number
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.program_id = ?
        ORDER BY
            COALESCE(pe.performance_order, pe.entry_number, pe.id) ASC,
            pe.id ASC
    ");
    $stmt->execute([$programId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        if (($row['program_type'] ?? '') === 'group' || !empty($row['only_team_marks'])) {
            $title = $row['program_title'] ?? '';
            $entryName = $row['entry_name'] ?? '';
            $cleanName = $entryName;
            if ($title !== '') {
                $cleanName = trim(str_ireplace($title . ' -', '', $cleanName));
                $cleanName = trim(str_ireplace($title . ' - ', '', $cleanName));
                $cleanName = trim(str_ireplace($title . '-', '', $cleanName));
                $cleanName = trim(str_ireplace($title, '', $cleanName));
                $cleanName = ltrim($cleanName, "- \t\n\r\0\x0B");
            }
            $cleanName = preg_replace('/\s*\(\d+\)\s*$/u', '', $cleanName);
            $row['chest_number'] = $cleanName ?: $row['entry_name'];
        }
    }
    return $rows;
}

function live_display_current_program(?int $eventId = null): array
{
    $eventId = $eventId ?? live_display_active_event_id();
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

    $programs = live_display_program_rows($eventId);
    $now = time();
    $selected = null;

    $pdo = live_display_pdo();
    $globalSettings = live_display_read_settings_row($pdo, 'global_musabaqa_settings');
    $liveProgramId = (int)($globalSettings['live_program_id'] ?? 0);
    $liveEntryId = isset($globalSettings['live_entry_id']) ? (int)$globalSettings['live_entry_id'] : -1;

    if ($liveProgramId > 0) {
        foreach ($programs as $program) {
            if ((int)$program['id'] === $liveProgramId) {
                $selected = $program;
                break;
            }
        }
    }

    if (!$selected) {
        foreach ($programs as $program) {
            $startValue = $program['live_display_start_time'] ?? $program['start_time'] ?? $program['start_datetime'] ?? null;
            $endValue = $program['live_display_end_time'] ?? $program['end_time'] ?? $program['end_datetime'] ?? null;
            $start = !empty($startValue) ? strtotime((string)$startValue) : null;
            $end = !empty($endValue) ? strtotime((string)$endValue) : null;
            if ($start && $end && $start <= $now && $end >= $now) {
                $selected = $program;
                break;
            }
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

    $entries = live_display_program_entries((int)$selected['id']);
    $current = null;
    $next = null;

    if ($liveEntryId === 0) {
        // Emcee set stage to Program Intro Slide
        $current = null;
        $next = isset($entries[0]) ? $entries[0] : null;
    } elseif ($liveEntryId > 0) {
        // Emcee explicitly set a participant live
        $currentIndex = null;
        foreach ($entries as $index => $entry) {
            if ((int)$entry['id'] === $liveEntryId) {
                $currentIndex = $index;
                break;
            }
        }
        if ($currentIndex !== null) {
            $current = $entries[$currentIndex];
            $next = isset($entries[$currentIndex + 1]) ? $entries[$currentIndex + 1] : null;
        }
    }

    // Fallback if no explicit liveEntryId matched
    if ($current === null && $liveEntryId !== 0) {
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
        if ($currentIndex !== null && isset($entries[$currentIndex + 1])) {
            $next = $entries[$currentIndex + 1];
        }
    }

    $nextProgram = null;
    foreach ($programs as $program) {
        if ((int)$program['id'] === (int)$selected['id']) {
            continue;
        }
        if (($program['status'] ?? '') !== 'completed') {
            $startValue = $program['live_display_start_time'] ?? $program['start_time'] ?? $program['start_datetime'] ?? null;
            $start = !empty($startValue) ? strtotime((string)$startValue) : PHP_INT_MAX;
            if (!$nextProgram || $start < (int)($nextProgram['_sort'] ?? PHP_INT_MAX)) {
                $nextProgram = live_display_program_payload($program);
                $nextProgram['_sort'] = $start;
            }
        }
    }
    if ($nextProgram) {
        unset($nextProgram['_sort']);
    }

    $pdo = live_display_pdo();
    $judges = [];
    if (live_display_table_exists($pdo, 'musabaqa_judges')) {
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
            'name' => $entry['entry_name'] ?: ('Entry #' . ($entry['chest_number'] ?? $entry['entry_number'])),
            'number' => $entry['chest_number'] ?? $entry['entry_number'],
            'chest_number' => $entry['chest_number'] ?? $entry['entry_number'],
            'team' => $entry['team_name'],
            'team_short' => $entry['short_name'] ?: $entry['team_name'],
            'team_color' => live_display_color($entry['team_color'] ?? null),
            'status' => $entry['status'] ?? 'approved',
            'score' => (float)($entry['final_score'] ?? 0),
        ];
    };

    $isIntro = ($liveEntryId === 0 || $current === null);
    return [
        'is_break' => false,
        'is_intro' => $isIntro,
        'program' => live_display_program_payload($selected),
        'performer' => $entryPayload($current),
        'next_performer' => $entryPayload($next),
        'next_program' => $nextProgram,
        'judges' => $judges,
        'status' => $isIntro ? 'PROGRAM OVERVIEW' : (($selected['status'] ?? '') === 'scoring' ? 'Scoring Live' : 'NOW PERFORMING'),
    ];
}

function live_display_schedule(?int $eventId = null, int $limit = 9): array
{
    $eventId = $eventId ?? live_display_active_event_id();
    if ($eventId <= 0) {
        return ['timeline' => [], 'upcoming' => [], 'completed' => []];
    }

    $pdo = live_display_pdo();
    $programs = array_map('live_display_program_payload', live_display_program_rows($eventId));
    $breaks = [];

    if (live_display_table_exists($pdo, 'musabaqa_breaks')) {
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
                'start_label' => live_display_format_datetime($break['start_datetime'] ?? null, 'h:i A'),
                'end_label' => live_display_format_datetime($break['end_datetime'] ?? null, 'h:i A'),
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
    if (live_display_table_exists($pdo, 'musabaqa_schedule_sections')) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM musabaqa_schedule_sections
            WHERE event_id = ?
            ORDER BY CASE WHEN section_date IS NULL THEN 1 ELSE 0 END ASC, section_date ASC, start_time ASC, sort_order ASC, id ASC
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
            'section_date' => $sec['section_date'] ?? null,
            'start_time' => $sec['start_time'],
            'end_time' => $sec['end_time'],
            'time_label' => date('h:i A', strtotime($sec['start_time'])) . ' - ' . date('h:i A', strtotime($sec['end_time'])),
            'items' => []
        ];
    }
    
    $unassignedData = [
        'id' => 0,
        'name' => 'Other Programs',
        'section_date' => null,
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
                $itemDate = date('Y-m-d', strtotime($itemTime));
                foreach ($sections as $sec) {
                    if (!empty($sec['section_date']) && $sec['section_date'] !== $itemDate) {
                        continue;
                    }
                    if ($tvTimeInRange($itemTime, $sec['start_time'], $sec['end_time'])) {
                        $assignedSecId = (int)$sec['id'];
                        break;
                    }
                }
            }
        }

        if ($assignedSecId !== null && isset($sectionsData[$assignedSecId])) {
            $sectionItem = $item;
            $sectionItem['schedule_section_name'] = $sectionsData[$assignedSecId]['name'];
            $sectionItem['schedule_date'] = $sectionsData[$assignedSecId]['section_date']
                ?: (!empty($item['start_time']) ? date('Y-m-d', strtotime((string)$item['start_time'])) : null);
            $sectionsData[$assignedSecId]['items'][] = $sectionItem;
        } else {
            $item['schedule_section_name'] = $unassignedData['name'];
            $item['schedule_date'] = !empty($item['start_time']) ? date('Y-m-d', strtotime((string)$item['start_time'])) : null;
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

function live_display_winners(?int $eventId = null, int $limit = 8): array
{
    $eventId = $eventId ?? live_display_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = live_display_pdo();
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            st.name AS stage_name,
            ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
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
            COALESCE(pe.team_score, 0) AS team_score,
            COALESCE(NULLIF(pe.final_score, 0), MAX(CASE WHEN ms.status = 'approved' THEN ms.total_mark END), 0) AS score,
            t.team_name,
            t.short_name,
            t.team_color
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN musabaqa_scores ms ON ms.entry_id = pe.id AND ms.program_id = pe.program_id
        WHERE pe.program_id = ?
        GROUP BY pe.id, t.id
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
                'team_color' => live_display_color($winner['team_color'] ?? null, '#d6b25e'),
                'score' => round((float)$winner['score'], 2),
                'team_score' => (int)$winner['team_score'],
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

function live_display_announcements(?int $eventId = null, ?array $settings = null): array
{
    $eventId = $eventId ?? live_display_active_event_id();
    $settings = $settings ?? live_display_get_settings($eventId);
    $items = [];

    $activeBreak = live_display_active_break($eventId);
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

function live_display_sponsors(?int $eventId = null, ?array $settings = null): array
{
    $settings = $settings ?? live_display_get_settings($eventId ?? live_display_active_event_id());
    $sponsors = $settings['sponsors'];

    if (!$sponsors) {
        $sponsors = [
            [
                'name' => 'Kauzariyya',
                'logo_url' => live_display_asset_url('kauzariyya-logo.png'),
                'message' => 'Official event host',
                'enabled' => true,
            ],
            [
                'name' => 'Thanafus',
                'logo_url' => live_display_asset_url('thanafus-logo.png'),
                'message' => 'Digital Musabaqa System',
                'enabled' => true,
            ],
        ];
    }

    return array_values($sponsors);
}

function live_display_active_break(int $eventId): ?array
{
    if ($eventId <= 0 || !live_display_table_exists(live_display_pdo(), 'musabaqa_breaks')) {
        return null;
    }

    $stmt = live_display_pdo()->prepare("
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

function live_display_break_info(?int $eventId = null, ?array $settings = null): array
{
    $eventId = $eventId ?? live_display_active_event_id();
    $settings = $settings ?? live_display_get_settings($eventId);
    $quotes = (array)($settings['quotes'] ?? []);
    $quote = $quotes ? $quotes[(int)date('z') % count($quotes)] : 'وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ';
    $schedule = live_display_schedule($eventId, 1);

    return [
        'clock' => date('h:i A'),
        'date' => date('l, d M Y'),
        'quote' => $quote,
        'active_break' => live_display_active_break($eventId),
        'next_item' => $schedule['upcoming'][0] ?? null,
    ];
}

function live_display_stats(?int $eventId = null): array
{
    $eventId = $eventId ?? live_display_active_event_id();
    if ($eventId <= 0) {
        return [
            'teams' => 0,
            'programs' => 0,
            'completed_programs' => 0,
            'entries' => 0,
        ];
    }

    $pdo = live_display_pdo();
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

function live_display_bootstrap_data(): array
{
    $event = live_display_active_event();
    $eventId = (int)($event['id'] ?? 0);
    $settings = live_display_get_settings($eventId);

    // Fetch the global settings to retrieve live_stage_start_time and live_timer parameters
    $pdo = live_display_pdo();
    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
    $stmt->execute();
    $globalRow = $stmt->fetch();
    $liveStageStartTime = 0;
    $liveTimerRunning = 0;
    $liveTimerStartTime = 0.0;
    $liveTimerElapsed = 0;
    if ($globalRow) {
        $globalSettings = json_decode($globalRow['setting_value'], true);
        $liveStageStartTime = (int)($globalSettings['live_stage_start_time'] ?? 0);
        $liveTimerRunning = (int)($globalSettings['live_timer_running'] ?? 0);
        $liveTimerStartTime = (float)($globalSettings['live_timer_start_time'] ?? 0.0);
        $liveTimerElapsed = (int)($globalSettings['live_timer_elapsed'] ?? 0);
    }

    return [
        'event' => live_display_event_payload($event),
        'settings' => $settings,
        'stats' => live_display_stats($eventId),
        'leaderboard' => live_display_leaderboard($eventId),
        'latest_score_update' => live_display_latest_score_update($eventId),
        'score_reveal' => live_display_score_reveal_event($eventId),
        'current' => live_display_current_program($eventId),
        'schedule' => live_display_schedule($eventId),
        'winners' => live_display_winners($eventId),
        'announcements' => live_display_announcements($eventId, $settings),
        'sponsors' => live_display_sponsors($eventId, $settings),
        'break' => live_display_break_info($eventId, $settings),
        'server_time' => date(DATE_ATOM),
        'server_time_ms' => (int)round(microtime(true) * 1000),
        'live_stage_start_time' => $liveStageStartTime,
        'live_timer_running' => $liveTimerRunning,
        'live_timer_start_time' => $liveTimerStartTime,
        'live_timer_elapsed' => $liveTimerElapsed,
    ];
}

function live_display_sanitize_dashboard_settings(array $post, array $current): array
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
        $settings['slides'][$key]['duration'] = max(1, (int)($slide['duration'] ?? 5)) * 1000;
        $settings['slides'][$key]['sort_order'] = (int)($slide['sort_order'] ?? $settings['slides'][$key]['sort_order']);
        $settings['slides'][$key]['enabled'] = isset($slide['enabled']);
    }

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

    return live_display_normalize_settings($settings);
}

/* =====================================================
   BACKWARD COMPATIBILITY ALIASES (tv_ -> live_display_)
   ===================================================== */
if (!defined('TV_SETTINGS_GLOBAL_KEY')) { define('TV_SETTINGS_GLOBAL_KEY', LIVE_DISPLAY_SETTINGS_GLOBAL_KEY); }

if (!function_exists('tv_pdo')) { function tv_pdo(): PDO { return live_display_pdo(); } }
if (!function_exists('tv_dashboard_pdo')) { function tv_dashboard_pdo(): PDO { return live_display_dashboard_pdo(); } }
if (!function_exists('tv_json')) { function tv_json(array $p, int $s = 200): void { live_display_json($p, $s); } }
if (!function_exists('tv_json_success')) { function tv_json_success(array $d = [], array $e = []): void { live_display_json_success($d, $e); } }
if (!function_exists('tv_json_error')) { function tv_json_error(string $m = 'Live display data unavailable.', int $s = 500): void { live_display_json_error($m, $s); } }
if (!function_exists('tv_log')) { function tv_log(Throwable $e, string $c = 'LiveDisplay'): void { live_display_log($e, $c); } }
if (!function_exists('tv_table_exists')) { function tv_table_exists(PDO $p, string $t): bool { return live_display_table_exists($p, $t); } }
if (!function_exists('tv_is_list_array')) { function tv_is_list_array(array $v): bool { return live_display_is_list_array($v); } }
if (!function_exists('tv_merge_settings')) { function tv_merge_settings(array $b, array $o): array { return live_display_merge_settings($b, $o); } }
if (!function_exists('tv_default_slides')) { function tv_default_slides(): array { return live_display_default_slides(); } }
if (!function_exists('tv_default_settings')) { function tv_default_settings(): array { return live_display_default_settings(); } }
if (!function_exists('tv_setting_key')) { function tv_setting_key(int $id): string { return live_display_setting_key($id); } }
if (!function_exists('tv_decode_settings')) { function tv_decode_settings(?string $j): array { return live_display_decode_settings($j); } }
if (!function_exists('tv_read_settings_row')) { function tv_read_settings_row(PDO $p, string $k): array { return live_display_read_settings_row($p, $k); } }
if (!function_exists('tv_legacy_component_settings')) { function tv_legacy_component_settings(PDO $p, int $id): array { return live_display_legacy_component_settings($p, $id); } }
if (!function_exists('tv_normalize_settings')) { function tv_normalize_settings(array $s): array { return live_display_normalize_settings($s); } }
if (!function_exists('tv_get_settings')) { function tv_get_settings(?int $id = null): array { return live_display_get_settings($id); } }
if (!function_exists('tv_save_settings')) { function tv_save_settings(int $id, array $s): array { return live_display_save_settings($id, $s); } }
if (!function_exists('tv_active_event')) { function tv_active_event(): ?array { return live_display_active_event(); } }
if (!function_exists('tv_active_event_id')) { function tv_active_event_id(): int { return live_display_active_event_id(); } }
if (!function_exists('tv_event_payload')) { function tv_event_payload(?array $e): array { return live_display_event_payload($e); } }
if (!function_exists('tv_format_datetime')) { function tv_format_datetime(?string $v, string $f): string { return live_display_format_datetime($v, $f); } }
if (!function_exists('tv_program_datetime_columns')) { function tv_program_datetime_columns(PDO $p): array { return live_display_program_datetime_columns($p); } }
if (!function_exists('tv_color')) { function tv_color(?string $v, string $fb = '#00ff88'): string { return live_display_color($v, $fb); } }
if (!function_exists('tv_leaderboard')) { function tv_leaderboard(?int $id = null): array { return live_display_leaderboard($id); } }
if (!function_exists('tv_latest_score_update')) { function tv_latest_score_update(?int $id = null): ?array { return live_display_latest_score_update($id); } }
if (!function_exists('tv_program_rows')) { function tv_program_rows(int $id): array { return live_display_program_rows($id); } }
if (!function_exists('tv_program_payload')) { function tv_program_payload(array $r): array { return live_display_program_payload($r); } }
if (!function_exists('tv_program_entries')) { function tv_program_entries(int $id): array { return live_display_program_entries($id); } }
if (!function_exists('tv_current_program')) { function tv_current_program(?int $id = null): array { return live_display_current_program($id); } }
if (!function_exists('tv_schedule')) { function tv_schedule(?int $id = null, int $l = 9): array { return live_display_schedule($id, $l); } }
if (!function_exists('tv_winners')) { function tv_winners(?int $id = null, int $l = 8): array { return live_display_winners($id, $l); } }
if (!function_exists('tv_announcements')) { function tv_announcements(?int $id = null, ?array $s = null): array { return live_display_announcements($id, $s); } }
if (!function_exists('tv_sponsors')) { function tv_sponsors(?int $id = null, ?array $s = null): array { return live_display_sponsors($id, $s); } }
if (!function_exists('tv_active_break')) { function tv_active_break(int $id): ?array { return live_display_active_break($id); } }
if (!function_exists('tv_break_info')) { function tv_break_info(?int $id = null, ?array $s = null): array { return live_display_break_info($id, $s); } }
if (!function_exists('tv_stats')) { function tv_stats(?int $id = null): array { return live_display_stats($id); } }
if (!function_exists('tv_bootstrap_data')) { function tv_bootstrap_data(): array { return live_display_bootstrap_data(); } }
if (!function_exists('tv_sanitize_dashboard_settings')) { function tv_sanitize_dashboard_settings(array $p, array $c): array { return live_display_sanitize_dashboard_settings($p, $c); } }

