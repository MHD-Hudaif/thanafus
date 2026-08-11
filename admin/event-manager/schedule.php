<?php
$pageTitle = 'Schedule';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function schedule_redirect(int $stageTypeId = 0): void
{
    $query = $stageTypeId > 0 ? ['stage_id' => $stageTypeId] : [];
    admin_redirect('/admin/event-manager/schedule.php', $query);
}

function schedule_program_datetime_columns(PDO $pdo): array
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
    $available = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $start = in_array('start_datetime', $available, true) ? 'start_datetime' : 'start_time';
    $end = in_array('end_datetime', $available, true) ? 'end_datetime' : 'end_time';

    return $columns = [$start, $end];
}

function schedule_load_program(PDO $pdo, int $eventId, int $programId): ?array
{
    [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
    $stmt = $pdo->prepare("
        SELECT id, title, stage_type_id, {$startExpr} AS start_at, {$endExpr} AS end_at
        FROM musabaqa_programs
        WHERE id = ?
          AND event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    return $program ?: null;
}

function schedule_validate_gap(PDO $pdo, int $eventId, int $stageTypeId, int $previousProgramId, int $nextProgramId): array
{
    $previous = schedule_load_program($pdo, $eventId, $previousProgramId);
    $next = schedule_load_program($pdo, $eventId, $nextProgramId);

    if (!$previous || !$next || empty($previous['end_at']) || empty($next['start_at'])) {
        throw new RuntimeException('Selected timeline gap is invalid.');
    }
    if ((int)$previous['stage_type_id'] !== $stageTypeId || (int)$next['stage_type_id'] !== $stageTypeId) {
        throw new RuntimeException('Selected programs are not in the chosen stage.');
    }

    $start = new DateTime((string)$previous['end_at']);
    $end = new DateTime((string)$next['start_at']);

    if ($start >= $end) {
        throw new RuntimeException('There is no time gap between these programs.');
    }

    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');

    [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM musabaqa_programs
        WHERE event_id = ?
          AND stage_type_id = ?
          AND id NOT IN (?, ?)
          AND {$startExpr} < ?
          AND {$endExpr} > ?
    ");
    $stmt->execute([$eventId, $stageTypeId, $previousProgramId, $nextProgramId, $endSql, $startSql]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException('Extra item time overlaps another program.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM musabaqa_breaks
        WHERE event_id = ?
          AND stage_type_id = ?
          AND start_datetime < ?
          AND end_datetime > ?
    ");
    $stmt->execute([$eventId, $stageTypeId, $endSql, $startSql]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException('An extra item already exists in this gap.');
    }

    return [$startSql, $endSql];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        schedule_redirect((int)($_POST['stage_type_id'] ?? 0));
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_break' || $action === 'add_extra') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $previousProgramId = (int)($_POST['previous_program_id'] ?? 0);
            $nextProgramId = (int)($_POST['next_program_id'] ?? 0);
            $stageTypeId = (int)($_POST['stage_type_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Extra title is required.');
            }
            if ($stageTypeId <= 0) {
                throw new RuntimeException('Stage is required.');
            }

            [$start, $end] = schedule_validate_gap($pdo, $activeEventId, $stageTypeId, $previousProgramId, $nextProgramId);

            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_breaks
                    (event_id, stage_type_id, name, description, start_datetime, end_datetime)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$activeEventId, $stageTypeId, $name, $description ?: null, $start, $end]);
            admin_flash('success', 'Extra item added to timeline.');
        } elseif ($action === 'delete_break' || $action === 'delete_extra') {
            $breakId = (int)($_POST['break_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM musabaqa_breaks WHERE id = ? AND event_id = ?');
            $stmt->execute([$breakId, $activeEventId]);
            admin_flash('success', 'Extra item removed.');
        } elseif ($action === 'schedule_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            $stageTypeId = (int)($_POST['stage_type_id'] ?? 0);
            $location = trim((string)($_POST['location'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $durationMinutes = (int)($_POST['duration_minutes'] ?? 0);

            if ($programId <= 0 || $stageTypeId <= 0 || $startTime === '') {
                throw new RuntimeException('Program, Stage, and Start Time are required.');
            }

            $startDt = new DateTime($startTime);
            if ($durationMinutes > 0) {
                $endDt = clone $startDt;
                $endDt->modify("+{$durationMinutes} minutes");
                $endTime = $endDt->format('Y-m-d H:i:s');
            } elseif ($endTime !== '') {
                $endDt = new DateTime($endTime);
            } else {
                throw new RuntimeException('Either duration or end time must be specified.');
            }

            if ($endDt <= $startDt) {
                throw new RuntimeException('End time must be after start time.');
            }

            $startSql = $startDt->format('Y-m-d H:i:s');
            $endSql = $endDt->format('Y-m-d H:i:s');

            // Overlap check
            [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
            $stmt = $pdo->prepare("
                SELECT id
                FROM musabaqa_programs
                WHERE event_id = ?
                  AND id <> ?
                  AND stage_type_id = ?
                  AND {$startExpr} IS NOT NULL
                  AND {$endExpr} IS NOT NULL
                  AND {$startExpr} < ?
                  AND {$endExpr} > ?
                LIMIT 1
            ");
            $stmt->execute([
                $activeEventId,
                $programId,
                $stageTypeId,
                $endSql,
                $startSql
            ]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Another program already exists during this time on the same stage.');
            }

            // Auto-detect section for this program
            $matchedSectionId = null;
            $progDate = date('Y-m-d', strtotime($startSql));
            
            $secStmt = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY sort_order ASC, start_time ASC");
            $secStmt->execute([$activeEventId]);
            $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $tvTimeInRange = static function(string $timeStr, string $start, string $end): bool {
                $time = date('H:i:s', strtotime($timeStr));
                if ($start <= $end) {
                    return $time >= $start && $time <= $end;
                } else {
                    return $time >= $start || $time <= $end;
                }
            };
            
            foreach ($sections as $sec) {
                if (!empty($sec['section_date']) && $sec['section_date'] !== $progDate) {
                    continue;
                }
                if ($tvTimeInRange($startSql, $sec['start_time'], $sec['end_time'])) {
                    $matchedSectionId = (int)$sec['id'];
                    break;
                }
            }

            // Save schedule
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET stage_type_id = ?, location = ?, {$startExpr} = ?, {$endExpr} = ?, section_id = ?
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([
                $stageTypeId,
                $location ?: null,
                $startSql,
                $endSql,
                $matchedSectionId,
                $programId,
                $activeEventId
            ]);

            admin_log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), $activeEventId, 'schedule_program', 'musabaqa_programs', $programId, 'Scheduled program.');
            admin_flash('success', 'Program scheduled successfully.');

        } elseif ($action === 'unschedule_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            if ($programId <= 0) {
                throw new RuntimeException('Invalid program ID.');
            }

            [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET stage_type_id = NULL, location = NULL, {$startExpr} = NULL, {$endExpr} = NULL
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([$programId, $activeEventId]);

            admin_log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), $activeEventId, 'unschedule_program', 'musabaqa_programs', $programId, 'Unscheduled program.');
            admin_flash('success', 'Program unscheduled.');
        } else {
            throw new RuntimeException('Invalid schedule action.');
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to update schedule.');
    }

    schedule_redirect((int)($_POST['stage_type_id'] ?? 0));
}

$flash = admin_take_flash();
[$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);

$stageTypes = $pdo->query('SELECT id, name FROM musabaqa_stage_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$activeStageId = (int)($_GET['stage_id'] ?? ($stageTypes[0]['id'] ?? 0));
$classFilter = trim((string)($_GET['class'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));

$programWhere = "
    WHERE mp.event_id = ?
      AND mp.stage_type_id IS NOT NULL
      AND mp.{$startExpr} IS NOT NULL
      AND mp.{$endExpr} IS NOT NULL
";
$programParams = [$activeEventId];
if ($search !== '') {
    $programWhere .= ' AND (mp.title LIKE ? OR mp.location LIKE ?)';
    $like = '%' . $search . '%';
    $programParams[] = $like;
    $programParams[] = $like;
}
[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'mp');
$classTypes = $dashboardPdo->query('SELECT id, name FROM class_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$classTypesMap = [];
foreach ($classTypes as $type) {
    $classTypesMap[(int)$type['id']] = $type['name'];
}

$programWhere .= $classSql;
array_push($programParams, ...$classParams);

$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.location, mp.class_type_id, ct.name AS class_type_name,
           t.full_name AS responsible_teacher_name, mp.allowed_sections, mp.stage_type_id,
           mp.{$startExpr} AS start_at, mp.{$endExpr} AS end_at
    FROM musabaqa_programs mp
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = mp.responsible_teacher_id
    {$programWhere}
    ORDER BY mp.{$startExpr} ASC, mp.{$endExpr} ASC, mp.id ASC
");
$stmt->execute($programParams);
$allScheduledPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group programs by stage
$programsByStage = [];
foreach ($stageTypes as $st) {
    $programsByStage[(int)$st['id']] = [];
}
foreach ($allScheduledPrograms as $p) {
    $programsByStage[(int)$p['stage_type_id']][] = $p;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM musabaqa_breaks
    WHERE event_id = ?
    ORDER BY start_datetime ASC, id ASC
");
$stmt->execute([$activeEventId]);
$allBreaks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group breaks by stage
$breakMapByStage = [];
foreach ($stageTypes as $st) {
    $breakMapByStage[(int)$st['id']] = [];
}
foreach ($allBreaks as $break) {
    $breakMapByStage[(int)$break['stage_type_id']][$break['start_datetime'] . '|' . $break['end_datetime']] = $break;
}

$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.program_type, mp.class_type_id, ct.name AS class_type_name,
           t.full_name AS responsible_teacher_name, mp.allowed_sections, mp.location,
           COALESCE(mp.stage_type_id, 1) AS stage_type_id
    FROM musabaqa_programs mp
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = mp.responsible_teacher_id
    WHERE mp.event_id = ?
      AND (mp.{$startExpr} IS NULL OR mp.{$endExpr} IS NULL)
    ORDER BY mp.title ASC, mp.id DESC
");
$stmt->execute([$activeEventId]);
$unscheduledPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tiers = [
    'senior' => 'Senior',
    'junior' => 'Junior',
    'subjunior' => 'Sub Junior',
    'general' => 'General / Other'
];

$unscheduledGrouped = [
    'subjunior' => [],
    'junior' => [],
    'senior' => [],
    'general' => []
];

foreach ($unscheduledPrograms as $prog) {
    $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
    
    // If the program allows multiple sections, it's considered General/Multi-Section
    $allowedCount = !empty($prog['allowed_sections']) ? count(explode(',', $prog['allowed_sections'])) : 0;
    
    if ($allowedCount > 1 || !$classTier) {
        $unscheduledGrouped['general'][] = $prog;
    } else {
        $unscheduledGrouped[$classTier][] = $prog;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Schedule</div>
            <div class="page-subtitle">Programs appear chronologically; extras fill gaps between programs</div>
        </div>
        <div class="flex gap-2">
            <button class="btn btn-success btn-md" type="button" id="scheduleNewProgramBtn"><i class="fa-solid fa-plus"></i> Schedule Program</button>
            <button class="btn btn-secondary btn-md" type="button" id="openUnscheduledModalBtn"><i class="fa-solid fa-clock"></i> Unscheduled Programs</button>
            <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-microphone-lines"></i> Programs</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="input-group">
                <label>Class Section</label>
                <select name="class" onchange="this.form.submit()">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Search Title/Location</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program title or location">
            </div>
            <div class="form-actions" style="grid-column: 1 / -1; display: flex; gap: 10px; margin-top: 10px;">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($search !== '' || $classFilter !== 'all'): ?>
                    <a href="<?= app_url('/admin/event-manager/schedule.php') ?>" class="btn btn-secondary btn-md">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- MAIN TWO-COLUMN LAYOUT: LEFT STAGE TABS/TIMELINE + RIGHT UNSCHEDULED SIDEBAR -->
    <div class="schedule-main-container" style="display: flex; gap: 24px; width: 100%; align-items: flex-start;">
        
        <!-- LEFT COLUMN: STAGE TABS & TIMELINE -->
        <div class="schedule-left-column" style="flex: 1; min-width: 0;">
            
            <!-- STAGE TABS BAR -->
            <div class="stage-tabs-bar" style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 12px; flex-wrap: wrap;">
                <?php foreach ($stageTypes as $idx => $stage): ?>
                    <?php 
                    $stId = (int)$stage['id'];
                    $stCount = count($programsByStage[$stId] ?? []);
                    $isTabActive = ($stId === $activeStageId) || ($activeStageId <= 0 && $idx === 0);
                    ?>
                    <button type="button" class="stage-tab-btn <?= $isTabActive ? 'active' : '' ?>" data-stage-tab="<?= $stId ?>" style="padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 13.5px; border: 1px solid rgba(255,255,255,0.08); background: <?= $isTabActive ? 'linear-gradient(135deg, rgba(99,102,241,0.2), rgba(168,85,247,0.2))' : 'rgba(255,255,255,0.02)' ?>; color: <?= $isTabActive ? '#fff' : 'var(--muted)' ?>; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-map-pin" style="color: var(--accent);"></i>
                        <span><?= e($stage['name']) ?></span>
                        <span class="badge badge-neutral" style="font-size: 11px; padding: 2px 7px; border-radius: 99px;"><?= $stCount ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- LIVE TIMELINE FILTER BAR -->
            <div class="panel mb-6" style="padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: rgba(255,255,255,0.015); border-color: rgba(255,255,255,0.03);">
                <div style="position: relative; display: flex; align-items: center; flex: 1; min-width: 180px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                    <input type="text" id="timelineSearch" placeholder="Search scheduled..." class="form-input" style="padding-left: 34px; height: 36px; font-size: 13px; width: 100%; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
                </div>
                <select id="timelineSectionFilter" class="form-input" style="height: 36px; font-size: 13px; width: 160px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff; padding: 0 10px;">
                    <option value="all">All Divisions</option>
                    <option value="senior">Senior</option>
                    <option value="junior">Junior</option>
                    <option value="subjunior">Sub Junior</option>
                    <option value="general">General / Open</option>
                </select>
            </div>

            <!-- STAGE PANELS -->
            <div class="stage-panels-container" style="display: flex; flex-direction: column; gap: 24px;">
                <?php foreach ($stageTypes as $idx => $stage): ?>
                    <?php 
                    $stageId = (int)$stage['id'];
                    $stageProgs = $programsByStage[$stageId] ?? [];
                    $stageBreakMap = $breakMapByStage[$stageId] ?? [];
                    $lastProg = !empty($stageProgs) ? end($stageProgs) : null;
                    $lastEndAt = $lastProg ? $lastProg['end_at'] : '';
                    $isPanelActive = ($stageId === $activeStageId) || ($activeStageId <= 0 && $idx === 0);
                    ?>
                    <div class="stage-panel-item panel" data-stage-id="<?= $stageId ?>" data-last-end-at="<?= e($lastEndAt) ?>" style="padding: 24px; background: rgba(255,255,255,0.01); border: 2px dashed rgba(255,255,255,0.06); transition: all 0.2s ease; <?= !$isPanelActive ? 'display: none;' : '' ?>">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 12px;">
                            <div class="dashboard-heading" style="margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-map-pin" style="color: var(--accent);"></i>
                                <?= e($stage['name']) ?>
                                <span style="font-size: 12px; color: var(--muted); font-weight: 500;">(<?= count($stageProgs) ?> Scheduled)</span>
                            </div>
                            <div style="font-size: 11.5px; color: var(--muted); font-style: italic;">
                                <i class="fa-solid fa-hand-pointer mr-1"></i> Drag unscheduled programs here
                            </div>
                        </div>

                        <?php if (empty($stageProgs)): ?>
                            <div class="empty-state stage-drop-zone" style="padding: 40px 20px; border: 2px dashed rgba(255,255,255,0.08); border-radius: 12px; text-align: center;">
                                <div class="empty-icon" style="font-size: 32px; color: var(--muted);"><i class="fa-solid fa-calendar-xmark"></i></div>
                                <div class="empty-title" style="font-size: 14px; margin-top: 8px;">No Scheduled Programs for <?= e($stage['name']) ?></div>
                                <div class="empty-subtitle" style="font-size: 12px; color: var(--muted);">Drag an unscheduled program card from the right sidebar and drop it here to schedule.</div>
                            </div>
                        <?php else: ?>
                            <div class="grid gap-4 stage-drop-zone" style="position: relative; padding-left: 10px;">
                                <?php foreach ($stageProgs as $index => $program): ?>
                                    <?php
                                    $secNames = [];
                                    if (!empty($program['allowed_sections'])) {
                                        $secIds = array_filter(array_map('intval', explode(',', $program['allowed_sections'])));
                                        foreach ($secIds as $sid) {
                                            if (isset($classTypesMap[$sid])) {
                                                $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                if ($label && !in_array($label, $secNames, true)) {
                                                    $secNames[] = $label;
                                                }
                                            }
                                        }
                                    }
                                    $sectionDisplay = implode(' & ', $secNames);
                                    if ($sectionDisplay === '') {
                                        $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                        $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($program['class_type_name'] ?? '—');
                                    }

                                    $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                    $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;
                                    $itemSection = ($allowedCount > 1 || !$classTier) ? 'general' : $classTier;
                                    ?>
                                    <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>">
                                        <div class="panel" style="padding: 14px 16px; border-left: 4px solid <?= $classTier ? ($classTier === 'senior' ? '#a78bfa' : ($classTier === 'junior' ? '#38bdf8' : '#34d399')) : '#94a3b8' ?>; background: rgba(255,255,255,0.015); border-color: rgba(255,255,255,0.03);">
                                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                                                    <div>
                                                        <div class="dashboard-heading" style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 2px;"><?= e($program['title']) ?></div>
                                                        <div class="page-subtitle" style="margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                            <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 9px; padding: 1px 5px;">
                                                                <?= e($sectionDisplay) ?>
                                                            </span>
                                                            <?php if (!empty($program['location'])): ?>
                                                                <span style="color: var(--accent); font-size: 11px;"><i class="fa-solid fa-location-dot"></i> <?= e($program['location']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                <span style="color: var(--muted); font-size: 11px;"><i class="fa-solid fa-chalkboard-user"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.04); padding-top: 8px; margin-top: 4px;">
                                                    <span class="badge badge-info" style="font-size: 10.5px; font-weight: 800; padding: 3px 6px; border-radius: 6px;">
                                                        <i class="fa-regular fa-clock mr-1"></i>
                                                        <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                    </span>
                                                    <div class="flex gap-2">
                                                        <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 4px 6px; font-size: 10px;"><i class="fa-solid fa-pen"></i></button>
                                                        
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule this program?');">
                                                            <?= admin_csrf_field() ?>
                                                            <input type="hidden" name="action" value="unschedule_program">
                                                            <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                            <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                            <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 4px 6px; font-size: 10px;"><i class="fa-solid fa-calendar-minus"></i></button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (isset($stageProgs[$index + 1])): ?>
                                        <?php
                                        $next = $stageProgs[$index + 1];
                                        $gapStart = new DateTime((string)$program['end_at']);
                                        $gapEnd = new DateTime((string)$next['start_at']);
                                        $hasGap = $gapStart < $gapEnd;
                                        $gapStartSql = $gapStart->format('Y-m-d H:i:s');
                                        $gapEndSql = $gapEnd->format('Y-m-d H:i:s');
                                        $break = $stageBreakMap[$gapStartSql . '|' . $gapEndSql] ?? null;
                                        ?>
                                        <?php if ($hasGap && $break): ?>
                                            <div class="timeline-gap-container timeline-row" style="margin: 8px 0; padding-left: 20px; border-left: 2px dashed rgba(250,204,21,.3); position: relative;">
                                                <div class="panel" style="border-color: rgba(250,204,21,.2); padding: 8px 10px; background: rgba(250,204,21,.03);">
                                                    <div class="flex-between">
                                                        <div>
                                                            <div class="dashboard-heading" style="font-size: 12.5px; margin: 0;"><i class="fa-solid fa-puzzle-piece mr-2" style="color: #facc15;"></i> <?= e($break['name']) ?></div>
                                                            <div class="page-subtitle" style="font-size: 10.5px;"><?= e($break['description'] ?: 'Extra Item') ?></div>
                                                        </div>
                                                        <div class="flex gap-2 flex-wrap">
                                                            <span class="badge badge-warning" style="font-size: 9.5px; padding: 1.5px 5px;"><?= e(date('h:i A', strtotime($break['start_datetime']))) ?> - <?= e(date('h:i A', strtotime($break['end_datetime']))) ?></span>
                                                            <form method="POST">
                                                                <?= admin_csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_extra">
                                                                <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                <input type="hidden" name="break_id" value="<?= (int)$break['id'] ?>">
                                                                <button class="btn btn-danger btn-sm" type="submit" style="padding: 3px 5px; font-size: 9.5px;"><i class="fa-solid fa-trash"></i></button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php elseif ($hasGap): ?>
                                            <div class="timeline-gap-container timeline-row" style="margin: 8px 0; padding-left: 20px; border-left: 2px dashed rgba(255,255,255,0.1); position: relative;">
                                                <div class="flex-between panel" style="padding: 6px 10px; background: rgba(255,255,255,0.01); border-color: rgba(255,255,255,0.04);">
                                                    <div>
                                                        <div class="page-subtitle" style="font-size: 11px; margin: 0; color: var(--muted);"><i class="fa-solid fa-hourglass-half mr-2"></i> Gap: <?= e(date('h:i A', strtotime($gapStartSql))) ?> - <?= e(date('h:i A', strtotime($gapEndSql))) ?></div>
                                                    </div>
                                                    <button
                                                        class="btn btn-success btn-sm"
                                                        type="button"
                                                        data-open-extra
                                                        data-open-break
                                                        data-stage-id="<?= $stageId ?>"
                                                        data-previous-program="<?= (int)$program['id'] ?>"
                                                        data-next-program="<?= (int)$next['id'] ?>"
                                                        data-gap-label="<?= e(date('h:i A', strtotime($gapStartSql)) . ' - ' . date('h:i A', strtotime($gapEndSql))) ?>"
                                                        style="padding: 3px 6px; font-size: 10px;"
                                                    >
                                                        <i class="fa-solid fa-plus"></i> Extra
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: DRAGGABLE UNSCHEDULED PROGRAMS PANEL -->
        <aside class="unscheduled-sidebar-panel panel" style="width: 330px; flex: 0 0 330px; position: sticky; top: 20px; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); display: flex; flex-direction: column; padding: 18px; border-color: rgba(255,255,255,0.06); background: #0e1726; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 10px;">
                <div style="font-size: 14.5px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clock" style="color: var(--warning);"></i>
                    <span>Unscheduled Programs</span>
                </div>
                <span class="badge badge-neutral" id="sidebarUnscheduledCount" style="font-size: 11px; font-weight: 700; border-radius: 99px;"><?= count($unscheduledPrograms) ?></span>
            </div>

            <div style="position: relative; display: flex; align-items: center; margin-bottom: 12px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; color: var(--muted); font-size: 12px;"></i>
                <input type="text" id="sidebarUnscheduledSearch" placeholder="Search unscheduled..." class="form-input" style="padding-left: 30px; height: 34px; font-size: 12px; width: 100%; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; color: #fff;">
            </div>

            <div style="font-size: 11px; color: var(--muted); margin-bottom: 10px; font-style: italic;">
                <i class="fa-solid fa-grip-vertical mr-1"></i> Drag card to active stage timeline to schedule
            </div>

            <div class="unscheduled-sidebar-content" style="display: flex; flex-direction: column; gap: 10px; overflow-y: auto; flex: 1; padding-right: 4px;">
                <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                    <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                    <div class="accordion-item sidebar-accordion-item" data-tier="<?= $tierKey ?>">
                        <button class="accordion-header" type="button" style="width: 100%; text-align: left; padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; color: #fff; font-weight: 700; font-size: 12.5px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: all 0.2s;">
                            <span><?= e($tierLabel) ?> <span class="tier-count" style="font-size: 11px; color: var(--muted); margin-left: 4px;">(<?= count($tierProgs) ?>)</span></span>
                            <i class="fa-solid fa-chevron-down accordion-icon" style="font-size: 10px; transition: transform 0.2s;"></i>
                        </button>
                        <div class="accordion-content" style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;">
                            <div style="padding: 8px 2px 0 2px; display: flex; flex-direction: column; gap: 8px;">
                                <?php if (empty($tierProgs)): ?>
                                    <div style="font-size: 12px; color: var(--muted); text-align: center; padding: 10px; background: rgba(0,0,0,0.05); border-radius: 6px;">No programs</div>
                                <?php else: ?>
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <?php
                                        $secNames = [];
                                        if (!empty($prog['allowed_sections'])) {
                                            $secIds = array_filter(array_map('intval', explode(',', $prog['allowed_sections'])));
                                            foreach ($secIds as $sid) {
                                                if (isset($classTypesMap[$sid])) {
                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                    if ($label && !in_array($label, $secNames, true)) {
                                                        $secNames[] = $label;
                                                    }
                                                }
                                            }
                                        }
                                        $sectionDisplay = implode(' & ', $secNames);
                                        if ($sectionDisplay === '') {
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($prog['class_type_name'] ?? '—');
                                        }
                                        $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                        ?>
                                        <div class="unscheduled-card panel draggable-program-card" draggable="true" data-title="<?= e($prog['title']) ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" data-program-json='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; cursor: grab; display: flex; flex-direction: column; gap: 8px; transition: all 0.2s ease;">
                                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                                <i class="fa-solid fa-grip-vertical" style="color: var(--muted); font-size: 13px; margin-top: 2px;"></i>
                                                <div style="min-width: 0; flex: 1;">
                                                    <strong style="display: block; font-size: 13px; line-height: 1.3; color: #fff;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                                    <span class="page-subtitle" style="font-size: 10.5px; margin-top: 4px; display: inline-block;">
                                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 9px; padding: 1px 5px;">
                                                            <?= e($sectionDisplay) ?>
                                                        </span>
                                                        <?php if (!empty($prog['responsible_teacher_name'])): ?>
                                                            · <span style="color: var(--muted);"><i class="fa-solid fa-chalkboard-user"></i> <?= e($prog['responsible_teacher_name']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <button class="btn btn-success btn-sm" type="button" data-schedule-btn='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="width: 100%; justify-content: center; font-size: 11px; padding: 4px 8px; border-radius: 6px;"><i class="fa-solid fa-calendar-plus mr-1"></i> Schedule</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</div>

<div class="modal-overlay" id="breakModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-puzzle-piece mr-2" style="color: #facc15;"></i> Add Extra Item</div>
                <div class="page-subtitle" id="breakGapLabel"></div>
            </div>
            <button class="modal-close" type="button" data-close="breakModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="add_extra">
            <input type="hidden" name="stage_type_id" id="breakStageTypeId">
            <input type="hidden" name="previous_program_id" id="previousProgramId">
            <input type="hidden" name="next_program_id" id="nextProgramId">
            <div class="form-grid">
                <div class="input-group full-width"><label>Extra Title / Name <span class="required">*</span></label><input type="text" name="name" required class="form-input" placeholder="e.g. Intermission / Segment"></div>
                <div class="input-group full-width"><label>Description</label><textarea name="description" rows="3" class="form-input" placeholder="Optional details about this extra item"></textarea></div>
            </div>
            <div class="form-actions"><button class="btn btn-secondary btn-md" type="button" data-close="breakModal">Cancel</button><button class="btn btn-success btn-md" type="submit">Save Extra</button></div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="scheduleModalTitle">Schedule Program</div>
            </div>
            <button class="modal-close" type="button" data-close="scheduleModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="scheduleForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="schedule_program">
            
            <div class="form-grid">
                <!-- PROGRAM SELECT DROP-DOWN (Shown when creating/scheduling) -->
                <div class="input-group full-width" id="modalProgramSelectGroup">
                    <label>Select Program <span class="required">*</span></label>
                    <select name="program_id" id="scheduleProgramSelect" class="form-input" required>
                        <option value="">-- Choose an Unscheduled Program --</option>
                        <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                            <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                            <?php if (!empty($tierProgs)): ?>
                                <optgroup label="<?= e($tierLabel) ?> Division">
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <?php
                                        $secNames = [];
                                        if (!empty($prog['allowed_sections'])) {
                                            $secIds = array_filter(array_map('intval', explode(',', $prog['allowed_sections'])));
                                            foreach ($secIds as $sid) {
                                                if (isset($classTypesMap[$sid])) {
                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                    if ($label && !in_array($label, $secNames, true)) {
                                                        $secNames[] = $label;
                                                    }
                                                }
                                            }
                                        }
                                        $sectionDisplay = implode(' & ', $secNames);
                                        if ($sectionDisplay === '') {
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($prog['class_type_name'] ?? '—');
                                        }
                                        ?>
                                        <option value="<?= (int)$prog['id'] ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" data-location="<?= e($prog['location'] ?? '') ?>"><?= e($prog['title']) ?> (<?= e($sectionDisplay) ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- STATIC DISPLAY (Shown when editing) -->
                <div class="input-group full-width" id="modalProgramStaticGroup" style="display: none;">
                    <label>Program</label>
                    <div id="scheduleProgramTitle" style="font-weight: 800; color: var(--accent); font-size: 15px; padding: 10px 14px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px;"></div>
                    <input type="hidden" name="program_id" id="scheduleProgramId">
                </div>

                <div class="input-group full-width">
                    <label>Stage/Venue <span class="required">*</span></label>
                    <select name="stage_type_id" id="scheduleStageTypeId" class="form-input" required>
                        <?php foreach ($stageTypes as $stage): ?>
                            <option value="<?= (int)$stage['id'] ?>"><?= e($stage['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group full-width">
                    <label>Location / Room</label>
                    <input type="text" name="location" id="scheduleLocation" placeholder="e.g. Main Hall, Stage A" class="form-input">
                </div>
                <div class="input-group">
                    <label>Start Date & Time <span class="required">*</span></label>
                    <input type="datetime-local" name="start_time" id="scheduleStartTime" class="form-input" required>
                </div>
                <div class="input-group">
                    <label>Duration (Minutes)</label>
                    <input type="number" name="duration_minutes" id="scheduleDurationMinutes" min="1" placeholder="e.g. 30" class="form-input" value="30">
                    <div style="display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="15">15m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="30">30m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="45">45m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="60">60m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="90">90m</button>
                    </div>
                </div>
                <div class="input-group full-width">
                    <label>End Date & Time</label>
                    <input type="datetime-local" name="end_time" id="scheduleEndTime" class="form-input">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary btn-md" type="button" data-close="scheduleModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit">Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="unscheduledProgramsModal">
    <div class="modal-box modal-md" style="max-height: 80vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <div>
                <div class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clock" style="color: var(--warning);"></i> Unscheduled Programs
                </div>
            </div>
            <button class="modal-close" type="button" data-close="unscheduledProgramsModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1;">
            <div style="position: relative; display: flex; align-items: center; margin-bottom: 15px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; color: var(--muted); font-size: 12px;"></i>
                <input type="text" id="unscheduledSearchInput" placeholder="Search unscheduled..." class="form-input" style="padding-left: 30px; height: 36px; font-size: 12.5px; width: 100%; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; color: #fff;">
            </div>

            <div class="unscheduled-accordion-container" style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                    <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                    <div class="accordion-item" data-tier="<?= $tierKey ?>">
                        <button class="accordion-header" type="button" style="width: 100%; text-align: left; padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; color: #fff; font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: all 0.2s;">
                            <span><?= e($tierLabel) ?> <span style="font-size: 11px; color: var(--muted); margin-left: 4px;">(<?= count($tierProgs) ?>)</span></span>
                            <i class="fa-solid fa-chevron-down accordion-icon" style="font-size: 10px; transition: transform 0.2s;"></i>
                        </button>
                        <div class="accordion-content" style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;">
                            <div style="padding: 8px 2px 0 2px; display: flex; flex-direction: column; gap: 8px;">
                                <?php if (empty($tierProgs)): ?>
                                    <div style="font-size: 12px; color: var(--muted); text-align: center; padding: 10px; background: rgba(0,0,0,0.05); border-radius: 6px;">No programs</div>
                                <?php else: ?>
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <div class="unscheduled-card panel" data-title="<?= e($prog['title']) ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" style="padding: 10px 12px; background: rgba(255,255,255,0.01); border-color: rgba(255,255,255,0.04); display: flex; flex-direction: column; gap: 8px;">
                                            <div style="min-width: 0; flex: 1;">
                                                <strong style="display: block; font-size: 13px; line-height: 1.3;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                            </div>
                                            <button class="btn btn-success btn-sm" type="button" data-schedule-btn='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="width: 100%; justify-content: center; font-size: 11.5px; padding: 6px 10px;"><i class="fa-solid fa-calendar-plus mr-1"></i> Schedule</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer" style="padding: 15px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end;">
            <button class="btn btn-secondary btn-md" type="button" data-close="unscheduledProgramsModal">Close</button>
        </div>
    </div>
</div>


<script>
let currentActiveStageId = '<?= (int)($activeStageId ?: ($stageTypes[0]['id'] ?? 0)) ?>';

function updateActiveStage(stageId) {
    currentActiveStageId = String(stageId);

    // 1. Update Stage Tab buttons active state
    document.querySelectorAll('.stage-tab-btn').forEach(btn => {
        const isTarget = btn.dataset.stageTab === currentActiveStageId;
        btn.classList.toggle('active', isTarget);
        btn.style.background = isTarget ? 'linear-gradient(135deg, rgba(99,102,241,0.2), rgba(168,85,247,0.2))' : 'rgba(255,255,255,0.02)';
        btn.style.color = isTarget ? '#fff' : 'var(--muted)';
        btn.style.borderColor = isTarget ? 'rgba(99,102,241,0.5)' : 'rgba(255,255,255,0.08)';
    });

    // 2. Toggle Stage Panels
    document.querySelectorAll('.stage-panel-item').forEach(panel => {
        panel.style.display = (panel.dataset.stageId === currentActiveStageId) ? '' : 'none';
    });

    // 3. Filter Sidebar Unscheduled Cards
    filterSidebarUnscheduled();

    // 4. Filter Modal Program Select Dropdown Options
    filterModalProgramOptions();
}

function filterSidebarUnscheduled() {
    const query = sidebarUnscheduledSearch ? sidebarUnscheduledSearch.value.toLowerCase().trim() : '';
    let totalVisible = 0;

    document.querySelectorAll('.sidebar-accordion-item').forEach(item => {
        let matchCount = 0;
        const cards = item.querySelectorAll('.draggable-program-card');
        const content = item.querySelector('.accordion-content');
        const icon = item.querySelector('.accordion-icon');
        const countSpan = item.querySelector('.tier-count');

        cards.forEach(card => {
            const title = card.dataset.title.toLowerCase();
            const cardStage = card.dataset.stageTypeId || '1';

            // Match ONLY programs belonging to the current active stage tab
            const matchesStage = (String(cardStage) === String(currentActiveStageId));
            const matchesQuery = (query === '') || title.includes(query);

            if (matchesStage && matchesQuery) {
                card.style.display = '';
                matchCount++;
                totalVisible++;
            } else {
                card.style.display = 'none';
            }
        });

        if (countSpan) {
            countSpan.textContent = '(' + matchCount + ')';
        }

        if (query !== '' && matchCount > 0) {
            item.classList.add('is-open');
            if (content) content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
            item.style.display = '';
        } else if (query !== '' && matchCount === 0) {
            item.style.display = 'none';
        } else {
            item.style.display = '';
            if (!item.classList.contains('is-open')) {
                if (content) content.style.maxHeight = '0';
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        }
    });

    const headerCount = document.getElementById('sidebarUnscheduledCount');
    if (headerCount) {
        headerCount.textContent = totalVisible;
    }
}

function filterModalProgramOptions() {
    const selectEl = document.getElementById('scheduleProgramSelect');
    if (selectEl) {
        Array.from(selectEl.options).forEach(opt => {
            if (!opt.value) return;
            const optStage = opt.dataset.stageTypeId || '1';
            const matchesStage = (String(optStage) === String(currentActiveStageId));
            opt.disabled = !matchesStage;
            opt.style.display = matchesStage ? '' : 'none';
        });
    }

    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (stageSelect && currentActiveStageId && currentActiveStageId !== '0') {
        stageSelect.value = currentActiveStageId;
    }
}

// Stage Tabs Switching
document.querySelectorAll('.stage-tab-btn').forEach(tabBtn => {
    tabBtn.addEventListener('click', () => {
        updateActiveStage(tabBtn.dataset.stageTab);
    });
});

// Accordion Toggles
document.querySelectorAll('.accordion-header').forEach(header => {
    header.addEventListener('click', () => {
        const item = header.parentElement;
        const content = item.querySelector('.accordion-content');
        const icon = header.querySelector('.accordion-icon');
        const isOpen = item.classList.toggle('is-open');
        
        if (isOpen) {
            content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            content.style.maxHeight = '0';
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    });
});

// Sidebar Unscheduled Search
const sidebarUnscheduledSearch = document.getElementById('sidebarUnscheduledSearch');
sidebarUnscheduledSearch?.addEventListener('input', () => {
    filterSidebarUnscheduled();
});

// Run stage filter on initial load
document.addEventListener('DOMContentLoaded', () => {
    updateActiveStage(currentActiveStageId);
});

// HTML5 Drag and Drop for Unscheduled Programs
document.querySelectorAll('.draggable-program-card[draggable="true"]').forEach(card => {
    card.addEventListener('dragstart', (e) => {
        e.dataTransfer.setData('text/plain', card.dataset.programJson);
        card.style.opacity = '0.5';
    });
    card.addEventListener('dragend', () => {
        card.style.opacity = '1';
    });
});

document.querySelectorAll('.stage-panel-item').forEach(stagePanel => {
    stagePanel.addEventListener('dragover', (e) => {
        e.preventDefault();
        stagePanel.style.borderColor = 'rgba(99,102,241,0.6)';
        stagePanel.style.background = 'rgba(99,102,241,0.05)';
    });
    stagePanel.addEventListener('dragleave', () => {
        stagePanel.style.borderColor = 'rgba(255,255,255,0.06)';
        stagePanel.style.background = 'rgba(255,255,255,0.01)';
    });
    stagePanel.addEventListener('drop', (e) => {
        e.preventDefault();
        stagePanel.style.borderColor = 'rgba(255,255,255,0.06)';
        stagePanel.style.background = 'rgba(255,255,255,0.01)';
        try {
            const p = JSON.parse(e.dataTransfer.getData('text/plain'));
            setModalCreateMode();
            
            const selectEl = document.getElementById('scheduleProgramSelect');
            selectEl.value = p.id;
            if (p.location) {
                document.getElementById('scheduleLocation').value = p.location;
            }
            
            const stageId = stagePanel.dataset.stageId;
            if (stageId) {
                document.getElementById('scheduleStageTypeId').value = stageId;
            }
            
            const lastEndAt = stagePanel.dataset.lastEndAt;
            if (lastEndAt) {
                document.getElementById('scheduleStartTime').value = toLocalDatetime(lastEndAt);
            } else {
                document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
            }
            
            document.getElementById('scheduleDurationMinutes').value = '30';
            updateScheduleEndTime();
            
            openModal('scheduleModal');
        } catch(err) {}
    });
});

// Duration Presets & End Time Calculation
document.querySelectorAll('.duration-preset-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('scheduleDurationMinutes').value = btn.dataset.mins;
        updateScheduleEndTime();
    });
});

function formatLocalDatetime(date) {
    const tzoffset = date.getTimezoneOffset() * 60000;
    const localISOTime = (new Date(date.getTime() - tzoffset)).toISOString().slice(0, 16);
    return localISOTime;
}

function toLocalDatetime(dbDate) {
    if (!dbDate) return '';
    const date = new Date(dbDate.replace(' ', 'T'));
    if (isNaN(date.getTime())) return '';
    return formatLocalDatetime(date);
}

function updateScheduleEndTime() {
    const startVal = document.getElementById('scheduleStartTime').value;
    const duration = Number(document.getElementById('scheduleDurationMinutes').value);
    if (!startVal || !duration || duration <= 0) return;
    const startDate = new Date(startVal);
    if (isNaN(startDate.getTime())) return;
    startDate.setMinutes(startDate.getMinutes() + duration);
    document.getElementById('scheduleEndTime').value = formatLocalDatetime(startDate);
}

function updateScheduleDurationFromEnd() {
    const startVal = document.getElementById('scheduleStartTime').value;
    const endVal = document.getElementById('scheduleEndTime').value;
    if (!startVal || !endVal) return;
    const startDate = new Date(startVal);
    const endDate = new Date(endVal);
    if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) return;
    const diffMins = Math.round((endDate - startDate) / 60000);
    if (diffMins > 0) {
        document.getElementById('scheduleDurationMinutes').value = diffMins;
    }
}

document.getElementById('scheduleStartTime')?.addEventListener('change', updateScheduleEndTime);
document.getElementById('scheduleDurationMinutes')?.addEventListener('input', updateScheduleEndTime);
document.getElementById('scheduleEndTime')?.addEventListener('change', updateScheduleDurationFromEnd);

// Client-side Timeline search and filter
const timelineSearch = document.getElementById('timelineSearch');
const timelineSectionFilter = document.getElementById('timelineSectionFilter');
const timelineItems = document.querySelectorAll('.timeline-item-container');
const timelineGaps = document.querySelectorAll('.timeline-gap-container');

function filterTimeline() {
    const searchVal = timelineSearch.value.toLowerCase().trim();
    const sectionVal = timelineSectionFilter.value;
    const isFiltering = searchVal !== '' || sectionVal !== 'all';
    
    timelineItems.forEach(item => {
        const title = item.dataset.title.toLowerCase();
        const location = item.dataset.location.toLowerCase();
        const section = item.dataset.section;
        
        const matchesSearch = title.includes(searchVal) || location.includes(searchVal);
        const matchesSection = (sectionVal === 'all') || (section === sectionVal);
        
        if (matchesSearch && matchesSection) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
    
    timelineGaps.forEach(gap => {
        gap.style.display = isFiltering ? 'none' : '';
    });
}

timelineSearch?.addEventListener('input', filterTimeline);
timelineSectionFilter?.addEventListener('change', filterTimeline);

// Modal helpers
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));

document.querySelectorAll('[data-open-extra], [data-open-break]').forEach(button => button.addEventListener('click', () => {
    document.getElementById('breakStageTypeId').value = button.dataset.stageId || '';
    document.getElementById('previousProgramId').value = button.dataset.previousProgram || '';
    document.getElementById('nextProgramId').value = button.dataset.nextProgram || '';
    document.getElementById('breakGapLabel').textContent = button.dataset.gapLabel || '';
    openModal('breakModal');
}));

// Auto-fill location when selecting a program in dropdown
document.getElementById('scheduleProgramSelect')?.addEventListener('change', (e) => {
    const opt = e.target.options[e.target.selectedIndex];
    if (opt && opt.dataset && opt.dataset.location !== undefined) {
        document.getElementById('scheduleLocation').value = opt.dataset.location;
    }
});

// Modal Form configurations (Toggles select dropdown vs static display)
function setModalCreateMode() {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Schedule Program';
    
    document.getElementById('modalProgramSelectGroup').style.display = '';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = false;
    selectEl.required = true;
    selectEl.name = 'program_id';
    
    document.getElementById('modalProgramStaticGroup').style.display = 'none';
    const hiddenEl = document.getElementById('scheduleProgramId');
    hiddenEl.disabled = true;
    hiddenEl.name = 'program_id_hidden';
    
    document.getElementById('scheduleStageTypeId').value = '<?= $stageTypes ? (int)$stageTypes[0]['id'] : '' ?>';
    document.getElementById('scheduleLocation').value = '';
}

function setModalEditMode(p) {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Edit Schedule';
    
    document.getElementById('modalProgramSelectGroup').style.display = 'none';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = true;
    selectEl.required = false;
    selectEl.name = 'program_id_disabled';
    
    document.getElementById('modalProgramStaticGroup').style.display = '';
    document.getElementById('scheduleProgramTitle').textContent = p.title;
    const hiddenEl = document.getElementById('scheduleProgramId');
    hiddenEl.disabled = false;
    hiddenEl.value = p.id;
    hiddenEl.name = 'program_id';
    
    document.getElementById('scheduleStageTypeId').value = p.stage_type_id || '';
    document.getElementById('scheduleLocation').value = p.location || '';
    document.getElementById('scheduleStartTime').value = toLocalDatetime(p.start_at);
    document.getElementById('scheduleEndTime').value = toLocalDatetime(p.end_at);
    
    if (p.start_at && p.end_at) {
        const start = new Date(toLocalDatetime(p.start_at));
        const end = new Date(toLocalDatetime(p.end_at));
        const diff = Math.round((end - start) / 60000);
        document.getElementById('scheduleDurationMinutes').value = diff > 0 ? diff : '';
    }
}

// 1. Click main button
document.getElementById('scheduleNewProgramBtn')?.addEventListener('click', () => {
    setModalCreateMode();
    const selectEl = document.getElementById('scheduleProgramSelect');
    if (selectEl.options.length > 1) {
        const firstOpt = selectEl.options[1]; // First actual program option
        if (firstOpt && firstOpt.dataset && firstOpt.dataset.location) {
            document.getElementById('scheduleLocation').value = firstOpt.dataset.location;
        }
    }
    if (!document.getElementById('scheduleStartTime').value) {
        document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
        document.getElementById('scheduleDurationMinutes').value = 30;
        updateScheduleEndTime();
    }
    openModal('scheduleModal');
});

// 1.5. Click Unscheduled programs modal button
document.getElementById('openUnscheduledModalBtn')?.addEventListener('click', () => {
    openModal('unscheduledProgramsModal');
});

// 2. Click Schedule card action button
document.querySelectorAll('[data-schedule-btn]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.scheduleBtn);
    closeModal('unscheduledProgramsModal');
    setModalCreateMode();
    
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.value = p.id;
    if (p.location) {
        document.getElementById('scheduleLocation').value = p.location;
    }
    
    if (!document.getElementById('scheduleStartTime').value) {
        document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
        document.getElementById('scheduleDurationMinutes').value = 30;
        updateScheduleEndTime();
    }
    
    openModal('scheduleModal');
}));

// 3. Click Edit program schedule button
document.querySelectorAll('[data-edit-schedule-btn]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.editScheduleBtn);
    setModalEditMode(p);
    openModal('scheduleModal');
}));
</script>
<?php admin_close_page(); ?>
