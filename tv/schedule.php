<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('schedule_dt')) {
    function schedule_dt(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('schedule_time_fmt')) {
    function schedule_time_fmt(?string $value): string
    {
        $dt = schedule_dt($value);
        return $dt ? $dt->format('h:i A') : '—';
    }
}

if (!function_exists('schedule_date_fmt')) {
    function schedule_date_fmt(?string $value): string
    {
        $dt = schedule_dt($value);
        return $dt ? $dt->format('l, M j, Y') : '—';
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
    $eventId = (int)($_SESSION['active_event_id'] ?? 0);

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

    $event = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return $event ?: null;
}

function schedule_fetch_programs(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.title,
            p.location,
            p.start_time,
            p.status,
            p.approval_status,
            p.program_type
        FROM musabaqa_programs p
        WHERE p.event_id = :event_id
        ORDER BY
            COALESCE(p.start_time, p.created_at) ASC,
            p.id ASC
    ");
    $stmt->execute([':event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $programs = [];
    foreach ($rows as $row) {
        $dt = schedule_dt($row['start_time'] ?? null);

        $programs[] = [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? 'Program'),
            'location' => trim((string)($row['location'] ?? '')),
            'start_time' => $row['start_time'] ?? null,
            'date_key' => $dt ? $dt->format('Y-m-d') : 'unknown',
            'date_label' => $dt ? $dt->format('l, M j, Y') : 'Unknown date',
            'time_label' => schedule_time_fmt($row['start_time'] ?? null),
            'status' => (string)($row['status'] ?? ''),
            'approval_status' => (string)($row['approval_status'] ?? ''),
            'program_type' => (string)($row['program_type'] ?? ''),
            'is_past' => $dt ? $dt->getTimestamp() < time() : false,
        ];
    }

    usort($programs, static function (array $a, array $b): int {
        $ta = schedule_dt($a['start_time'] ?? null);
        $tb = schedule_dt($b['start_time'] ?? null);

        $va = $ta ? $ta->getTimestamp() : PHP_INT_MAX;
        $vb = $tb ? $tb->getTimestamp() : PHP_INT_MAX;

        return $va <=> $vb ?: ($a['id'] <=> $b['id']);
    });

    return $programs;
}

function schedule_payload(PDO $pdo): array
{
    $event = schedule_active_event($pdo);
    $eventId = (int)($event['id'] ?? 0);
    $programs = $eventId > 0 ? schedule_fetch_programs($pdo, $eventId) : [];

    return [
        'event' => $event,
        'programs' => $programs,
        'generated_at' => date('c'),
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'schedule') {
    try {
        schedule_json([
            'success' => true,
            'data' => schedule_payload($musabaqa_pdo),
        ]);
    } catch (Throwable) {
        schedule_json([
            'success' => false,
            'message' => 'Unable to load schedule.',
        ]);
    }
}

$payload = schedule_payload($musabaqa_pdo);
$event = $payload['event'] ?? null;
$programs = $payload['programs'] ?? [];
$pageTitle = (($event['title'] ?? 'Schedule') . ' • Schedule');
$eventDateRange = '';
if (!empty($event['start_date']) && !empty($event['end_date'])) {
    $start = schedule_dt((string)$event['start_date']);
    $end = schedule_dt((string)$event['end_date']);
    if ($start && $end) {
        $eventDateRange = $start->format('M j, Y') . ' — ' . $end->format('M j, Y');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#05070a">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(app_url('/tv/schedules.css')) ?>">
</head>
<body>
<div class="schedule-app" id="scheduleApp">
    <div class="schedule-glow schedule-glow-a"></div>
    <div class="schedule-glow schedule-glow-b"></div>

    <header class="schedule-header">
        <div class="schedule-brand">
            <div class="schedule-kicker">Kauzariyya Digital Musabaqa</div>
            <h1 class="schedule-title"><?= e($event['title'] ?? 'Schedule') ?></h1>
            <?php if ($eventDateRange !== ''): ?>
                <div class="schedule-subtitle"><?= e($eventDateRange) ?></div>
            <?php endif; ?>
        </div>

        <div class="schedule-clock-wrap">
            <div class="schedule-clock-label">Current Time</div>
            <div class="schedule-clock" id="scheduleClock">--:--:--</div>
        </div>
    </header>

    <main class="schedule-main">
        <section class="schedule-board" aria-label="Musabaqa schedule table">
            <div class="schedule-table-shell">
                <table class="schedule-table" id="scheduleTable">
                    <colgroup>
                        <col class="col-time">
                        <col class="col-program">
                        <col class="col-venue">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Time</th>
                            <th scope="col">Program</th>
                            <th scope="col">Venue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($programs)): ?>
                            <tr class="schedule-empty-row">
                                <td colspan="3">
                                    <div class="schedule-empty">
                                        No schedule items found
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $prevDateKey = null; ?>
                            <?php foreach ($programs as $index => $program): ?>
                                <?php
                                    $dateKey = (string)($program['date_key'] ?? 'unknown');
                                    $dateLabel = (string)($program['date_label'] ?? '');
                                    $isCurrentDay = $dateKey !== $prevDateKey;
                                    $prevDateKey = $dateKey;
                                    $rowClass = 'schedule-row' . (($program['is_past'] ?? false) ? ' is-past' : '');
                                ?>
                                <?php if ($isCurrentDay): ?>
                                    <tr class="schedule-date-row">
                                        <td colspan="3"><?= e($dateLabel) ?></td>
                                    </tr>
                                <?php endif; ?>

                                <tr class="<?= e($rowClass) ?>"
                                    data-start-time="<?= e((string)($program['start_time'] ?? '')) ?>">
                                    <td class="schedule-time-cell" data-label="Time">
                                        <span class="schedule-time"><?= e((string)($program['time_label'] ?? '—')) ?></span>
                                    </td>
                                    <td class="schedule-program-cell" data-label="Program">
                                        <?= e((string)($program['title'] ?? 'Program')) ?>
                                    </td>
                                    <td class="schedule-venue-cell" data-label="Venue">
                                        <?= e((string)($program['location'] ?? '—')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<script>
window.TV_SCHEDULE_DATA = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(app_url('/tv/schedules.js')) ?>"></script>
</body>
</html>
