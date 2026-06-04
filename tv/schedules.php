<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('schedule_color')) {
    function schedule_color(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '#1fe08a';
        }

        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return $value;
        }

        if (preg_match('/^rgba?\(\s*[\d.\s,]+\)$/i', $value)) {
            return $value;
        }

        return '#1fe08a';
    }
}

if (!function_exists('schedule_rank_suffix')) {
    function schedule_rank_suffix(int $rank): string
    {
        return match ($rank) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}

if (!function_exists('schedule_dt')) {
    function schedule_dt(?string $value): ?DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('schedule_fmt')) {
    function schedule_fmt(?string $value, string $format = 'h:i A D'): string
    {
        $dt = schedule_dt($value);
        if (!$dt) {
            return '—';
        }

        return $dt->format($format);
    }
}

if (!function_exists('schedule_date_fmt')) {
    function schedule_date_fmt(?string $value): string
    {
        $dt = schedule_dt($value);
        if (!$dt) {
            return '—';
        }

        return $dt->format('D, M j, Y');
    }
}

if (!function_exists('schedule_json')) {
    function schedule_json(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function schedule_active_event(PDO $pdo): ?array
{
    $eventId = (int) ($_SESSION['active_event_id'] ?? 0);

    if ($eventId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, title, start_date, end_date, status
            FROM musabaqa_events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event) {
            return $event;
        }
    }

    $stmt = $pdo->query("
        SELECT id, title, start_date, end_date, status
        FROM musabaqa_events
        ORDER BY (status = 'active') DESC, id DESC
        LIMIT 1
    ");

    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    return $event ?: null;
}

function schedule_fetch_items(PDO $pdo, int $eventId): array
{
    $programStmt = $pdo->prepare("
        SELECT
            p.id,
            p.title,
            p.program_type,
            p.location,
            CONCAT(DATE(ev.start_date), ' ', TIME(p.start_time)) AS start_time,
            CONCAT(DATE(ev.start_date), ' ', TIME(p.end_time)) AS end_time,
            p.status,
            p.approval_status,
            st.name AS stage_type_name
        FROM musabaqa_programs p
        INNER JOIN musabaqa_events ev ON ev.id = p.event_id
        LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
        WHERE p.event_id = :event_id
        ORDER BY COALESCE(p.start_time, p.end_time, p.created_at) ASC, p.id ASC
    ");
    $programStmt->execute([':event_id' => $eventId]);
    $programs = $programStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $breakStmt = $pdo->prepare("
        SELECT
            b.id,
            b.name,
            b.description,
            CONCAT(DATE(ev.start_date), ' ', TIME(b.start_datetime)) AS start_datetime,
            CONCAT(DATE(ev.start_date), ' ', TIME(b.end_datetime)) AS end_datetime,
            st.name AS stage_type_name
        FROM musabaqa_breaks b
        INNER JOIN musabaqa_events ev ON ev.id = b.event_id
        LEFT JOIN musabaqa_stage_types st ON st.id = b.stage_type_id
        WHERE b.event_id = :event_id
        ORDER BY b.start_datetime ASC, b.id ASC
    ");
    $breakStmt->execute([':event_id' => $eventId]);
    $breaks = $breakStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rankStmt = $pdo->prepare("
        SELECT
            pe.program_id,
            pe.final_rank,
            pe.final_score,
            t.team_name,
            t.short_name,
            t.team_color
        FROM musabaqa_program_entries pe
        INNER JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.event_id = :event_id
          AND pe.final_rank IS NOT NULL
          AND pe.final_rank IN (1,2,3)
        ORDER BY pe.program_id ASC, pe.final_rank ASC
    ");
    $rankStmt->execute([':event_id' => $eventId]);
    $rankRows = $rankStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rankMap = [];
    foreach ($rankRows as $row) {
        $programId = (int) ($row['program_id'] ?? 0);
        $rank = (int) ($row['final_rank'] ?? 0);
        if ($programId <= 0 || $rank <= 0) {
            continue;
        }

        if (!isset($rankMap[$programId])) {
            $rankMap[$programId] = [];
        }

        $rankMap[$programId][$rank] = [
            'final_rank' => $rank,
            'final_score' => (float) ($row['final_score'] ?? 0),
            'team_name' => (string) ($row['team_name'] ?? 'Team'),
            'short_name' => (string) ($row['short_name'] ?? ''),
            'team_color' => schedule_color($row['team_color'] ?? ''),
        ];
    }

    $items = [];

    foreach ($programs as $program) {
        $id = (int) ($program['id'] ?? 0);
        $items[] = [
            'kind' => 'program',
            'id' => $id,
            'title' => (string) ($program['title'] ?? 'Program'),
            'place' => trim((string) ($program['location'] ?? '')),
            'start_time' => $program['start_time'] ?? null,
            'end_time' => $program['end_time'] ?? null,
            'program_type' => (string) ($program['program_type'] ?? ''),
            'status' => (string) ($program['status'] ?? ''),
            'approval_status' => (string) ($program['approval_status'] ?? ''),
            'stage_type_name' => (string) ($program['stage_type_name'] ?? ''),
            'results' => array_values($rankMap[$id] ?? []),
        ];
    }

    foreach ($breaks as $break) {
        $items[] = [
            'kind' => 'break',
            'id' => (int) ($break['id'] ?? 0),
            'title' => (string) ($break['name'] ?? 'Break'),
            'place' => trim((string) ($break['stage_type_name'] ?? '')),
            'description' => (string) ($break['description'] ?? ''),
            'start_time' => $break['start_datetime'] ?? null,
            'end_time' => $break['end_datetime'] ?? null,
            'stage_type_name' => (string) ($break['stage_type_name'] ?? ''),
            'status' => 'break',
            'approval_status' => 'approved',
            'results' => [],
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $ta = schedule_dt($a['start_time'] ?? null);
        $tb = schedule_dt($b['start_time'] ?? null);

        $va = $ta ? $ta->getTimestamp() : PHP_INT_MAX;
        $vb = $tb ? $tb->getTimestamp() : PHP_INT_MAX;

        return $va <=> $vb ?: (($a['kind'] ?? '') <=> ($b['kind'] ?? '')) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });

    return $items;
}

function schedule_group_by_day(array $items): array
{
    $dateKeys = [];
    foreach ($items as $item) {
        $dt = schedule_dt($item['start_time'] ?? null) ?? schedule_dt($item['end_time'] ?? null);
        $dateKey = $dt ? $dt->format('Y-m-d') : 'unknown';
        if (!isset($dateKeys[$dateKey])) {
            $dateKeys[$dateKey] = true;
        }
    }

    $orderedDates = array_keys($dateKeys);
    usort($orderedDates, static function (string $a, string $b): int {
        if ($a === 'unknown') return 1;
        if ($b === 'unknown') return -1;
        return strcmp($a, $b);
    });

    $dateToDay = [];
    $dayIndex = 1;
    foreach ($orderedDates as $dateKey) {
        $dateToDay[$dateKey] = $dayIndex++;
    }

    $groups = [];
    foreach ($items as $item) {
        $dt = schedule_dt($item['start_time'] ?? null) ?? schedule_dt($item['end_time'] ?? null);
        $dateKey = $dt ? $dt->format('Y-m-d') : 'unknown';
        $dayNumber = $dateToDay[$dateKey] ?? $dayIndex++;
        if (!isset($groups[$dateKey])) {
            $groups[$dateKey] = [
                'day_number' => $dayNumber,
                'date_key' => $dateKey,
                'display_date' => $dt ? $dt->format('D, M j, Y') : '—',
                'items' => [],
            ];
        }
        $groups[$dateKey]['items'][] = $item;
    }

    $groups = array_values($groups);
    usort($groups, static function (array $a, array $b): int {
        $da = $a['date_key'] ?? 'unknown';
        $db = $b['date_key'] ?? 'unknown';
        if ($da === 'unknown') return 1;
        if ($db === 'unknown') return -1;
        return strcmp($da, $db);
    });

    foreach ($groups as &$group) {
        $group['pages'] = array_values(array_chunk($group['items'], 8));
    }
    unset($group);

    return $groups;
}

function schedule_pages_from_groups(array $dayGroups): array
{
    $pages = [];
    foreach ($dayGroups as $group) {
        $dayNumber = (int) ($group['day_number'] ?? 1);
        $displayDate = (string) ($group['display_date'] ?? '—');
        $pagesForDay = $group['pages'] ?? [];
        $pageTotal = max(1, count($pagesForDay));

        foreach ($pagesForDay as $pageIndex => $items) {
            $pages[] = [
                'day_number' => $dayNumber,
                'day_label' => 'Day ' . $dayNumber,
                'display_date' => $displayDate,
                'page_index' => $pageIndex + 1,
                'page_total' => $pageTotal,
                'items' => $items,
            ];
        }
    }

    return $pages;
}

function schedule_payload(PDO $pdo): array
{
    $event = schedule_active_event($pdo);
    $eventId = (int) ($event['id'] ?? 0);

    $items = $eventId > 0 ? schedule_fetch_items($pdo, $eventId) : [];
    $dayGroups = schedule_group_by_day($items);
    $pages = schedule_pages_from_groups($dayGroups);

    return [
        'event' => $event,
        'items' => $items,
        'day_groups' => $dayGroups,
        'pages' => $pages,
        'page_size' => 8,
        'generated_at' => date('c'),
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'schedule') {
    try {
        schedule_json([
            'success' => true,
            'data' => schedule_payload($musabaqa_pdo),
        ]);
    } catch (Throwable $e) {
        schedule_json([
            'success' => false,
            'message' => 'Unable to load schedule.',
        ]);
    }
}

$payload = schedule_payload($musabaqa_pdo);
$event = $payload['event'] ?? null;
$pages = $payload['pages'] ?? [];
$pageTitle = (($event['title'] ?? 'Schedule') . ' • Schedule');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#05070a">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(APP_URL) ?>/tv/assets/css/schedules.css">
</head>
<body>
<div class="schedule-root" id="scheduleRoot">
    <div class="schedule-bg schedule-bg-a"></div>
    <div class="schedule-bg schedule-bg-b"></div>
    <div class="schedule-noise"></div>

    <header class="schedule-topbar">
        <div class="schedule-brand">
            <img src="<?= e(APP_URL) ?>/tv/assets/thanafus-logo.png" alt="Thanafus Logo" class="schedule-logo">
            <div class="schedule-brand-copy">
                <div class="schedule-kicker">Kauzariyya Digital Musabaqa</div>
                <div class="schedule-title"><?= e($event['title'] ?? 'Schedule') ?></div>
            </div>
        </div>

        <div class="schedule-meta">
            <div class="schedule-pill" id="scheduleClock">--:--</div>
            <div class="schedule-pill schedule-pill--soft" id="scheduleProgress">0 / 0</div>
        </div>
    </header>

    <main class="schedule-stage">
        <section class="schedule-window" aria-live="polite">
            <div class="schedule-page-shell" id="schedulePageShell">
                <?php foreach ($pages as $pageIndex => $page): ?>
                    <?php
                        $dayLabel = (string) ($page['day_label'] ?? 'Day 1');
                        $displayDate = (string) ($page['display_date'] ?? '—');
                        $pageItems = $page['items'] ?? [];
                        $pageNumber = (int) ($page['page_index'] ?? 1);
                        $pageTotal = (int) ($page['page_total'] ?? 1);
                    ?>
                    <section class="schedule-page <?= $pageIndex === 0 ? 'is-active' : '' ?>" data-page="<?= (int) $pageIndex ?>">
                        <div class="schedule-page-head">
                            <div>
                                <div class="schedule-day-label"><?= e($dayLabel) ?></div>
                                <div class="schedule-page-date"><?= e($displayDate) ?></div>
                            </div>
                            <div class="schedule-page-count">Part <?= (int) $pageNumber ?> / <?= (int) $pageTotal ?></div>
                        </div>

                        <div class="schedule-card-grid">
                            <?php foreach ($pageItems as $item): ?>
                                <?php if (($item['kind'] ?? '') === 'break'): ?>
                                    <article class="schedule-card schedule-card--break" data-kind="break">
                                        <div class="schedule-card-head">
                                            <div class="schedule-card-title"><?= e($item['title'] ?? 'Break') ?></div>
                                            <div class="schedule-card-time"><?= e(
                                                schedule_fmt($item['start_time'] ?? null, 'h:i A')
                                                . ' - '
                                                . schedule_fmt($item['end_time'] ?? null, 'h:i A')
                                            ) ?></div>
                                        </div>

                                        <?php if (!empty($item['place'])): ?>
                                            <div class="schedule-card-place"><?= e($item['place']) ?></div>
                                        <?php endif; ?>

                                        <div class="schedule-card-body schedule-card-body--center">
                                            <div class="schedule-break-badge">—</div>
                                        </div>
                                    </article>
                                <?php else: ?>
                                    <article class="schedule-card" data-kind="program">
                                        <div class="schedule-card-head">
                                            <div class="schedule-card-title"><?= e($item['title'] ?? 'Program') ?></div>
                                            <div class="schedule-card-time"><?= e(
                                                schedule_fmt($item['start_time'] ?? null, 'h:i A')
                                                . ' - '
                                                . schedule_fmt($item['end_time'] ?? null, 'h:i A')
                                            ) ?></div>
                                        </div>

                                        <?php if (!empty($item['place'])): ?>
                                            <div class="schedule-card-place"><?= e($item['place']) ?></div>
                                        <?php endif; ?>

                                        <div class="schedule-card-subhead">
                                            <span class="schedule-status schedule-status--<?= e(($item['status'] === 'completed') ? 'done' : (($item['status'] === 'scoring') ? 'live' : 'pending')) ?>">
                                                <?= e(($item['status'] === 'completed') ? 'Completed' : (($item['status'] === 'scoring') ? 'Scoring' : 'Pending')) ?>
                                            </span>
                                        </div>

                                        <div class="schedule-card-body">
                                            <?php if (!empty($item['results'])): ?>
                                                <div class="schedule-ranks">
                                                    <?php foreach ($item['results'] as $result): ?>
                                                        <?php
                                                            $rank = (int) ($result['final_rank'] ?? 0);
                                                            $teamName = (string) ($result['team_name'] ?? 'Team');
                                                            $teamColor = (string) ($result['team_color'] ?? '#1fe08a');
                                                        ?>
                                                        <span class="schedule-rank-pill schedule-rank-pill--<?= (int) $rank ?>"
                                                              style="background: <?= e($teamColor) ?>;">
                                                            <?= e($rank . schedule_rank_suffix($rank)) ?>
                                                            <span class="schedule-rank-team"><?= e($teamName) ?></span>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <section class="schedule-page is-empty <?= empty($pages) ? 'is-active' : '' ?>" data-page="empty" style="<?= empty($pages) ? '' : 'display:none;' ?>">
                    <div class="schedule-empty">
                        <h2>No schedule items found</h2>
                        <p>There are no programs or breaks available for the current event.</p>
                    </div>
                </section>
            </div>
        </section>
    </main>
</div>

<script>
window.TVSlide = window.TVSlide || {};
window.TVSlide.complete = false;
window.TVSlide.kind = 'schedule';
window.TVSlide.totalPages = <?= json_encode(count($pages), JSON_UNESCAPED_SLASHES) ?>;
window.TVSlide.currentPage = 0;
window.TVSlide.batchSize = 8;
window.TV_SCHEDULE_DATA = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="<?= e(APP_URL) ?>/tv/assets/js/schedules.js"></script>
</body>
</html>
