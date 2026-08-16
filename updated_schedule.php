<?php
declare(strict_types=1);
if (file_exists(__DIR__ . '/config/bootstrap.php')) {
    require_once __DIR__ . '/config/bootstrap.php';
} else {
    require_once __DIR__ . '/../config/bootstrap.php';
}

function e($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

function translateCategory(?string $name): string {
    if (!$name) {
        return 'General';
    }
    $name = trim($name);
    if ($name === 'العالية') {
        return 'Senior';
    }
    if ($name === 'الثانوية') {
        return 'Junior';
    }
    if ($name === 'التحصص' || $name === 'حفظ') {
        return 'Sub Junior';
    }
    return $name;
}

function map_stage_for_heading(?string $stageName): string {
    $stageName = trim((string)$stageName);
    if (stripos($stageName, '3') !== false || stripos($stageName, 'on') !== false || stripos($stageName, 'darul') !== false) {
        return 'ON STAGE';
    }
    if (stripos($stageName, '4') !== false || stripos($stageName, 'masjid') !== false) {
        return 'OFF STAGE (KAUZARIYYA MASJID)';
    }
    if (stripos($stageName, '5') !== false || stripos($stageName, 'library') !== false) {
        return 'OFF STAGE (KAUZARIYYA LIBRARY)';
    }
    return strtoupper($stageName);
}

// 1. Fetch active event
$event = $musabaqa_pdo->query("SELECT * FROM musabaqa_events ORDER BY (status='active') DESC,id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$items = [];
$sectionsMap = [];

if ($event) {
    // Fetch sessions
    $stmt = $musabaqa_pdo->prepare("
        SELECT id, name
        FROM musabaqa_schedule_sections
        WHERE event_id = ?
        ORDER BY section_date ASC, sort_order ASC, id ASC
    ");
    $stmt->execute([$event['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $sectionsMap[(int)$sec['id']] = $sec['name'];
    }

    // Fetch programs
    $stmt = $musabaqa_pdo->prepare("
        SELECT 
            'program' AS kind,
            p.id,
            p.title,
            p.location,
            p.start_time,
            p.end_time,
            p.status,
            st.name AS stage_type_name,
            ct.name AS class_name,
            t.full_name AS responsible_teacher_name,
            p.section_id
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = p.responsible_teacher_id
        WHERE p.event_id = ? AND p.start_time IS NOT NULL
    ");
    $stmt->execute([$event['id']]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $musabaqa_pdo->prepare("
        SELECT 
            'break' AS kind,
            b.id,
            b.name AS title,
            NULL AS location,
            b.description AS responsible_teacher_name,
            b.start_datetime AS start_time,
            b.end_datetime AS end_time,
            'break' AS status,
            st.name AS stage_type_name,
            b.section_id
        FROM musabaqa_breaks b
        LEFT JOIN musabaqa_stage_types st ON st.id = b.stage_type_id
        WHERE b.event_id = ?
    ");
    $stmt->execute([$event['id']]);
    $breaks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_merge($programs, $breaks);

    // Sort chronologically
    usort($items, function(array $a, array $b): int {
        $ta = strtotime($a['start_time']);
        $tb = strtotime($b['start_time']);
        if ($ta === $tb) {
            return ($a['kind'] <=> $b['kind']) ?: ((int)$a['id'] <=> (int)$b['id']);
        }
        return $ta <=> $tb;
    });
}

// Group by Day
$days = [];
foreach ($items as $item) {
    // Override the first three elements in the printout exactly as requested
    $dateKey = date('Y-m-d', strtotime($item['start_time']));
    $timeKey = date('H:i', strtotime($item['start_time']));
    if ($dateKey === '2026-08-17') {
        if ($item['title'] === 'Qirath' && $timeKey === '14:35') {
            $item['responsible_teacher_name'] = 'All Asathiza';
            $item['location'] = 'Darul Qur\'an';
            $item['stage_type_name'] = 'Darul Qur\'an';
        } elseif ($item['title'] === 'Nath' && $timeKey === '14:40') {
            $item['responsible_teacher_name'] = 'All Asathiza';
            $item['location'] = 'Darul Qur\'an';
            $item['stage_type_name'] = 'Darul Qur\'an';
        } elseif ($item['title'] === 'Inauguration Speech' && $timeKey === '14:45') {
            $item['responsible_teacher_name'] = 'All Asathiza';
            $item['location'] = 'Darul Qur\'an';
            $item['stage_type_name'] = 'Darul Qur\'an';
        }
    }

    $dayLabel = date('l, M j, Y', strtotime($item['start_time']));
    $days[$dayLabel][] = $item;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Printable Schedule</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Plus Jakarta Sans', Arial, sans-serif;
        background-color: #ffffff;
        color: #1e293b;
        margin: 0;
        padding: 40px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .schedule-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    .day-title-bar {
        background-color: #0f172a;
        color: #ffffff;
        padding: 16px 24px;
        border-radius: 8px;
        font-size: 24px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 20px;
        margin-top: 40px;
    }
    .day-title-bar:first-of-type {
        margin-top: 0;
    }
    .schedule-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 40px;
    }
    .schedule-table th {
        color: #475569;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 14px 18px;
        border-bottom: 2px solid #e2e8f0;
        text-align: left;
    }
    .schedule-row td {
        padding: 18px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 15px;
        vertical-align: middle;
    }
    .schedule-row:nth-child(even) td {
        background-color: #f8fafc;
    }
    .time-cell {
        font-size: 16px;
        font-weight: 800;
        color: #0f172a;
        width: 22%;
    }
    .program-cell {
        width: 38%;
    }
    .program-title {
        font-weight: 800;
        color: #0f172a;
        font-size: 16px;
    }
    .program-category {
        font-size: 13px;
        color: #64748b;
        margin-top: 4px;
        font-weight: 600;
    }
    .venue-cell {
        width: 20%;
        font-weight: 700;
        color: #475569;
    }
    .incharge-cell {
        width: 20%;
        font-weight: 600;
        color: #475569;
        font-style: italic;
    }
    /* Session subheading row */
    .session-header-row td {
        padding: 12px 18px;
        background-color: #f1f5f9 !important;
        border-bottom: 1px solid #cbd5e1;
    }
    .session-badge {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .session-badge.on-stage {
        background-color: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }
    .session-badge.off-stage-library {
        background-color: #fffbeb;
        color: #b45309;
        border: 1px solid #fde68a;
    }
    .session-badge.off-stage-masjid {
        background-color: #f0fdf4;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }
    /* Break row */
    .break-row td {
        background-color: #f8fafc !important;
        padding: 18px;
        border-bottom: 1px solid #e2e8f0;
    }
    .break-time-cell {
        font-size: 16px;
        font-weight: 800;
        color: #64748b;
        width: 22%;
    }
    .break-text-cell {
        font-weight: 800;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.05em;
        font-size: 15px;
        text-align: center;
        background-color: rgba(241, 245, 249, 0.5);
    }
    @media print {
        body {
            padding: 0;
        }
        .page-break {
            page-break-before: always;
        }
    }
</style>
</head>
<body>
<div class="schedule-container">
    <?php foreach ($days as $dayLabel => $dayItems): ?>
        <div class="day-title-bar"><?= e(strtoupper($dayLabel)) ?></div>
        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="time-cell">TIMING</th>
                    <th class="program-cell">COMPETITION / EVENT</th>
                    <th class="venue-cell">STAGE / VENUE</th>
                    <th class="incharge-cell">IN-CHARGE (ASATHIZA)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $lastSessionId = -999;
                foreach ($dayItems as $item):
                    $date = date('Y-m-d', strtotime($item['start_time']));
                    $sessionId = $item['section_id'] ? (int)$item['section_id'] : null;

                    // Skip the top-level outer session wrapper breaks if they contain actual parallel items
                    if ($item['kind'] === 'break' && $item['title'] === 'AFTER ZUHAR' && $date === '2026-08-17') {
                        continue;
                    }

                    // Check if this is a full-width break
                    $isFullWidthBreak = ($item['kind'] === 'break' && (empty($item['responsible_teacher_name']) || $item['responsible_teacher_name'] === 'Break' || stripos($item['title'], 'break') !== false || stripos($item['title'], 'prayer') !== false));

                    // Do not show session header for the opening ceremony extras on Day 1 (before 3:00 PM)
                    $isOpeningExtra = ($date === '2026-08-17' && $sessionId === 120 && strtotime($item['start_time']) < strtotime($date . ' 15:00:00'));

                    // Output session subheading only if the session changes AND it is NOT a full-width break AND it is NOT an opening extra
                    if (!$isFullWidthBreak && !$isOpeningExtra && $sessionId !== $lastSessionId && $sessionId !== null) {
                        $sessionName = $sectionsMap[$sessionId] ?? 'General';
                        $stageHeading = map_stage_for_heading($item['stage_type_name'] ?? '');
                        $badgeClass = 'on-stage';
                        if (stripos($stageHeading, 'LIBRARY') !== false) {
                            $badgeClass = 'off-stage-library';
                        } elseif (stripos($stageHeading, 'MASJID') !== false) {
                            $badgeClass = 'off-stage-masjid';
                        }
                        ?>
                        <tr class="session-header-row">
                            <td colspan="4">
                                <span class="session-badge <?= $badgeClass ?>"><?= e($sessionName) ?> &bull; <?= e($stageHeading) ?></span>
                            </td>
                        </tr>
                        <?php
                        $lastSessionId = $sessionId;
                    }

                    if ($isFullWidthBreak) {
                        // Reset lastSessionId so that if the next item is in the same session, we still show the subheading!
                        $lastSessionId = -999;
                        ?>
                        <tr class="break-row">
                            <td class="break-time-cell"><?= date('h:i A', strtotime($item['start_time'])) ?> – <?= date('h:i A', strtotime($item['end_time'])) ?></td>
                            <td colspan="3" class="break-text-cell"><?= e($item['title']) ?></td>
                        </tr>
                        <?php
                    } else {
                        // Render normal program/extra row
                        if ($sessionId === null || $isOpeningExtra) {
                            $lastSessionId = -999;
                        }
                        ?>
                        <tr class="schedule-row">
                            <td class="time-cell"><?= date('h:i A', strtotime($item['start_time'])) ?> – <?= date('h:i A', strtotime($item['end_time'])) ?></td>
                            <td class="program-cell">
                                <div class="program-title"><?= e($item['title']) ?></div>
                                <?php if (!empty($item['class_name'])): ?>
                                    <div class="program-category"><?= e(translateCategory($item['class_name'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="venue-cell"><?= e($item['location'] ?: $item['stage_type_name'] ?: '—') ?></td>
                            <td class="incharge-cell"><?= e($item['responsible_teacher_name'] ?: '—') ?></td>
                        </tr>
                        <?php
                    }
                endforeach;
                ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</div>
</body>
</html>