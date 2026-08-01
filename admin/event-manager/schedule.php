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
    admin_redirect('/admin/event-manager/schedule.php');
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
        throw new RuntimeException('Break time overlaps another program.');
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
        throw new RuntimeException('A break already exists in this gap.');
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
        if ($action === 'add_break') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $previousProgramId = (int)($_POST['previous_program_id'] ?? 0);
            $nextProgramId = (int)($_POST['next_program_id'] ?? 0);
            $stageTypeId = (int)($_POST['stage_type_id'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Break name is required.');
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
            admin_flash('success', 'Break added to timeline.');
        } elseif ($action === 'delete_break') {
            $breakId = (int)($_POST['break_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM musabaqa_breaks WHERE id = ? AND event_id = ?');
            $stmt->execute([$breakId, $activeEventId]);
            admin_flash('success', 'Break removed.');
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
    LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN kauzariyya.teachers t ON t.id = mp.responsible_teacher_id
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
           t.full_name AS responsible_teacher_name, mp.allowed_sections
    FROM musabaqa_programs mp
    LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN kauzariyya.teachers t ON t.id = mp.responsible_teacher_id
    WHERE mp.event_id = ?
      AND (mp.{$startExpr} IS NULL OR mp.{$endExpr} IS NULL OR mp.stage_type_id IS NULL)
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
            <div class="page-subtitle">Programs appear chronologically; breaks fill gaps between programs</div>
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

    <div style="width: 100%;">
        <!-- Interactive search/filter panel inside the timeline container -->
        <div class="panel mb-6" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: rgba(255,255,255,0.015); border-color: rgba(255,255,255,0.03);">
            <div class="dashboard-heading" style="margin: 0; font-size: 15px;">Live Timeline Filter</div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div style="position: relative; display: flex; align-items: center;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                    <input type="text" id="timelineSearch" placeholder="Search scheduled..." class="form-input" style="padding-left: 34px; height: 38px; font-size: 13px; width: 220px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
                </div>
                <select id="timelineSectionFilter" class="form-input" style="height: 38px; font-size: 13px; width: 160px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff; padding: 0 10px;">
                    <option value="all">All Sections</option>
                    <option value="senior">Senior</option>
                    <option value="junior">Junior</option>
                    <option value="subjunior">Sub Junior</option>
                    <option value="general">General / Other</option>
                </select>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 24px;">
            <?php foreach ($stageTypes as $stage): ?>
                <?php 
                $stageId = (int)$stage['id'];
                $stageProgs = $programsByStage[$stageId] ?? [];
                $stageBreakMap = $breakMapByStage[$stageId] ?? [];
                ?>
                <div class="panel" style="padding: 24px; background: rgba(255,255,255,0.01); border-color: rgba(255,255,255,0.04);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 12px;">
                        <div class="dashboard-heading" style="margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-map-pin" style="color: var(--accent);"></i>
                            <?= e($stage['name']) ?>
                            <span style="font-size: 12px; color: var(--muted); font-weight: 500;">(<?= count($stageProgs) ?> Scheduled)</span>
                        </div>
                    </div>

                    <?php if (empty($stageProgs)): ?>
                        <div class="empty-state" style="padding: 40px 20px;">
                            <div class="empty-icon" style="font-size: 32px;"><i class="fa-solid fa-calendar-xmark"></i></div>
                            <div class="empty-title" style="font-size: 14px;">No Scheduled Programs</div>
                            <div class="empty-subtitle" style="font-size: 12px;">Use the Unscheduled list to schedule programs.</div>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4" style="position: relative; padding-left: 10px;">
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
                                                        <div class="dashboard-heading" style="font-size: 12.5px; margin: 0;"><i class="fa-solid fa-mug-hot mr-2" style="color: #facc15;"></i> <?= e($break['name']) ?></div>
                                                        <div class="page-subtitle" style="font-size: 10.5px;"><?= e($break['description'] ?: 'Break') ?></div>
                                                    </div>
                                                    <div class="flex gap-2 flex-wrap">
                                                        <span class="badge badge-warning" style="font-size: 9.5px; padding: 1.5px 5px;"><?= e(date('h:i A', strtotime($break['start_datetime']))) ?> - <?= e(date('h:i A', strtotime($break['end_datetime']))) ?></span>
                                                        <form method="POST">
                                                            <?= admin_csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_break">
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
                                                    data-open-break
                                                    data-stage-id="<?= $stageId ?>"
                                                    data-previous-program="<?= (int)$program['id'] ?>"
                                                    data-next-program="<?= (int)$next['id'] ?>"
                                                    data-gap-label="<?= e(date('h:i A', strtotime($gapStartSql)) . ' - ' . date('h:i A', strtotime($gapEndSql))) ?>"
                                                    style="padding: 3px 6px; font-size: 10px;"
                                                >
                                                    <i class="fa-solid fa-plus"></i> Break
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
</div>

<div class="modal-overlay" id="breakModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div>
                <div class="modal-title">Add Break</div>
                <div class="page-subtitle" id="breakGapLabel"></div>
            </div>
            <button class="modal-close" type="button" data-close="breakModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="add_break">
            <input type="hidden" name="stage_type_id" id="breakStageTypeId">
            <input type="hidden" name="previous_program_id" id="previousProgramId">
            <input type="hidden" name="next_program_id" id="nextProgramId">
            <div class="form-grid">
                <div class="input-group full-width"><label>Break Name</label><input type="text" name="name" required class="form-input"></div>
                <div class="input-group full-width"><label>Description</label><textarea name="description" rows="4" class="form-input"></textarea></div>
            </div>
            <div class="form-actions"><button class="btn btn-secondary btn-md" type="button" data-close="breakModal">Cancel</button><button class="btn btn-success btn-md" type="submit">Save Break</button></div>
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
                                        <option value="<?= (int)$prog['id'] ?>"><?= e($prog['title']) ?> (<?= e($sectionDisplay) ?>)</option>
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
                    <input type="number" name="duration_minutes" id="scheduleDurationMinutes" min="1" placeholder="e.g. 60" class="form-input">
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
                                        <div class="unscheduled-card panel" data-title="<?= e($prog['title']) ?>" style="padding: 10px 12px; background: rgba(255,255,255,0.01); border-color: rgba(255,255,255,0.04); display: flex; flex-direction: column; gap: 8px;">
                                            <div style="min-width: 0; flex: 1;">
                                                <strong style="display: block; font-size: 13px; line-height: 1.3;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                                <span class="page-subtitle" style="font-size: 11px; margin-top: 4px; display: inline-block;">
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
                                                    <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 9.5px; padding: 2px 6px;">
                                                        <?= e($sectionDisplay) ?>
                                                    </span>
                                                    <?php if (!empty($prog['responsible_teacher_name'])): ?>
                                                        · <span style="color: var(--muted);"><i class="fa-solid fa-chalkboard-user"></i> <?= e($prog['responsible_teacher_name']) ?></span>
                                                    <?php endif; ?>
                                                </span>
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

<style>
/* Unscheduled Sidebar Accordion Styles */
.accordion-item {
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.015);
    border: 1px solid rgba(255, 255, 255, 0.04);
}
.accordion-header:hover {
    background: rgba(255, 255, 255, 0.04) !important;
}
.accordion-item.is-open .accordion-header {
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    background: rgba(255, 255, 255, 0.03) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

/* Timeline Layout Visual Connector Style */
.timeline-item-container {
    position: relative;
    padding-left: 20px;
}
.timeline-item-container::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 2px;
    background: rgba(255, 255, 255, 0.05);
}
.timeline-item-container::after {
    content: '';
    position: absolute;
    left: -4px;
    top: 24px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--bg);
    border: 2px solid rgba(255, 255, 255, 0.3);
    z-index: 1;
}

/* Specific connector dot colors for divisions */
.timeline-item-container[data-section="senior"]::after {
    border-color: #a78bfa;
}
.timeline-item-container[data-section="junior"]::after {
    border-color: #38bdf8;
}
.timeline-item-container[data-section="subjunior"]::after {
    border-color: #34d399;
}
.timeline-item-container[data-section="general"]::after {
    border-color: #94a3b8;
}

/* Custom styles for select dropdown list options in dark mode */
#scheduleProgramSelect optgroup {
    background: #0f172a;
    color: #94a3b8;
    font-weight: 700;
}
#scheduleProgramSelect option {
    background: #0f172a;
    color: #fff;
    font-weight: 400;
}
</style>

<script>
// Accordion Toggles
document.querySelectorAll('.accordion-header').forEach(header => {
    header.addEventListener('click', () => {
        const item = header.parentElement;
        const content = item.querySelector('.accordion-content');
        const icon = header.querySelector('.accordion-icon');
        const isOpen = item.classList.toggle('is-open');
        
        if (isOpen) {
            content.style.maxHeight = content.scrollHeight + 'px';
            icon.style.transform = 'rotate(180deg)';
        } else {
            content.style.maxHeight = '0';
            icon.style.transform = 'rotate(0deg)';
        }
    });
});

// Client-side Unscheduled programs search & auto-open accordions
const unscheduledSearchInput = document.getElementById('unscheduledSearchInput');
unscheduledSearchInput?.addEventListener('input', () => {
    const query = unscheduledSearchInput.value.toLowerCase().trim();
    
    document.querySelectorAll('.accordion-item').forEach(item => {
        let matchCount = 0;
        const cards = item.querySelectorAll('.unscheduled-card');
        const content = item.querySelector('.accordion-content');
        const icon = item.querySelector('.accordion-icon');
        
        cards.forEach(card => {
            const title = card.dataset.title.toLowerCase();
            if (title.includes(query)) {
                card.style.display = '';
                matchCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        if (query !== '' && matchCount > 0) {
            item.classList.add('is-open');
            content.style.maxHeight = content.scrollHeight + 'px';
            icon.style.transform = 'rotate(180deg)';
            item.style.display = '';
        } else if (query !== '' && matchCount === 0) {
            item.style.display = 'none';
        } else {
            item.style.display = '';
            item.classList.remove('is-open');
            content.style.maxHeight = '0';
            icon.style.transform = 'rotate(0deg)';
        }
    });
});

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
    
    // Hide all gaps/breaks if any filter is active, otherwise show them
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

document.querySelectorAll('[data-open-break]').forEach(button => button.addEventListener('click', () => {
    document.getElementById('breakStageTypeId').value = button.dataset.stageId || '';
    document.getElementById('previousProgramId').value = button.dataset.previousProgram || '';
    document.getElementById('nextProgramId').value = button.dataset.nextProgram || '';
    document.getElementById('breakGapLabel').textContent = button.dataset.gapLabel || '';
    openModal('breakModal');
}));

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

document.getElementById('scheduleStartTime')?.addEventListener('change', updateScheduleEndTime);
document.getElementById('scheduleDurationMinutes')?.addEventListener('input', updateScheduleEndTime);

// Modal Form configurations (Toggles select dropdown vs static display)
function setModalCreateMode() {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Schedule Program';
    
    // Show select dropdown and enable it
    document.getElementById('modalProgramSelectGroup').style.display = '';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = false;
    selectEl.required = true;
    selectEl.name = 'program_id';
    
    // Hide static block and disable hidden input
    document.getElementById('modalProgramStaticGroup').style.display = 'none';
    const hiddenEl = document.getElementById('scheduleProgramId');
    hiddenEl.disabled = true;
    hiddenEl.name = 'program_id_hidden';
    
    document.getElementById('scheduleStageTypeId').value = '<?= $stageTypes ? (int)$stageTypes[0]['id'] : '' ?>';
}

function setModalEditMode(p) {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Edit Schedule';
    
    // Hide select dropdown and disable it
    document.getElementById('modalProgramSelectGroup').style.display = 'none';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = true;
    selectEl.required = false;
    selectEl.name = 'program_id_disabled';
    
    // Show static block and enable hidden input
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
    openModal('scheduleModal');
});

// 1.5. Click Unscheduled programs modal button
document.getElementById('openUnscheduledModalBtn')?.addEventListener('click', () => {
    openModal('unscheduledProgramsModal');
});

// 2. Click Schedule card action button
document.querySelectorAll('[data-schedule-btn]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.scheduleBtn);
    closeModal('unscheduledProgramsModal'); // Close unscheduled programs list modal
    setModalCreateMode();
    
    // Pre-select the program in the dropdown
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.value = p.id;
    
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
