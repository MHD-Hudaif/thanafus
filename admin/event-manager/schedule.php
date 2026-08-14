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
    admin_redirect('/admin/event-manager/schedule', $query);
}

function schedule_program_datetime_columns(PDO $pdo): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM musabaqa_programs")->fetchAll(PDO::FETCH_COLUMN);
        $available = array_map('strtolower', $cols);

        $start = in_array('start_datetime', $available, true) ? 'start_datetime' : 'start_time';
        $end = in_array('end_datetime', $available, true) ? 'end_datetime' : 'end_time';
        return $columns = [$start, $end];
    } catch (Throwable $e) {
        return $columns = ['start_time', 'end_time'];
    }
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

    $stageStmt = $pdo->prepare("SELECT name FROM musabaqa_stage_types WHERE id = ?");
    $stageStmt->execute([$stageTypeId]);
    $stageName = (string)$stageStmt->fetchColumn();
    $isOffStage = (stripos($stageName, 'off') !== false);

    if (!$isOffStage) {
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
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $durationMinutes = (int)($_POST['duration_minutes'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Extra item title is required.');
            }
            if ($stageTypeId <= 0) {
                throw new RuntimeException('Stage is required.');
            }

            if ($previousProgramId > 0 && $nextProgramId > 0) {
                [$start, $end] = schedule_validate_gap($pdo, $activeEventId, $stageTypeId, $previousProgramId, $nextProgramId);
            } elseif ($startTime !== '') {
                $startDt = new DateTime($startTime);
                if ($durationMinutes > 0) {
                    $endDt = clone $startDt;
                    $endDt->modify("+{$durationMinutes} minutes");
                } elseif ($endTime !== '') {
                    $endDt = new DateTime($endTime);
                } else {
                    throw new RuntimeException('Either duration or end time must be specified.');
                }
                if ($endDt <= $startDt) {
                    throw new RuntimeException('End time must be after start time.');
                }
                $start = $startDt->format('Y-m-d H:i:s');
                $end = $endDt->format('Y-m-d H:i:s');

                // Overlap checks for custom times
                $stageStmt = $pdo->prepare("SELECT name FROM musabaqa_stage_types WHERE id = ?");
                $stageStmt->execute([$stageTypeId]);
                $stageName = (string)$stageStmt->fetchColumn();
                $isOffStage = (stripos($stageName, 'off') !== false);

                if (!$isOffStage) {
                    [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM musabaqa_programs
                        WHERE event_id = ?
                          AND stage_type_id = ?
                          AND {$startExpr} < ?
                          AND {$endExpr} > ?
                    ");
                    $stmt->execute([$activeEventId, $stageTypeId, $end, $start]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new RuntimeException('Extra item time overlaps another program.');
                    }
                }

                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM musabaqa_breaks
                    WHERE event_id = ?
                      AND stage_type_id = ?
                      AND start_datetime < ?
                      AND end_datetime > ?
                ");
                $stmt->execute([$activeEventId, $stageTypeId, $end, $start]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new RuntimeException('An extra item already exists in this gap.');
                }
            } else {
                throw new RuntimeException('Start time or valid timeline gap is required.');
            }

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

            // Always resolve column names (needed for UPDATE regardless of stage type)
            [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);

            // Overlap check (Bypassed for Off Stage so programs can run concurrently)
            $stageStmt = $pdo->prepare("SELECT name FROM musabaqa_stage_types WHERE id = ?");
            $stageStmt->execute([$stageTypeId]);
            $stageName = (string)$stageStmt->fetchColumn();
            $isOffStage = (stripos($stageName, 'off') !== false);

            if (!$isOffStage) {
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
            }

            // Auto-detect section for this program
            $matchedSectionId = null;
            $progDate = date('Y-m-d', strtotime($startSql));
            
            $secStmt = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY section_date ASC, start_time ASC, sort_order ASC");
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

            // ------------------------------------------------------------------
            // Session-window enforcement:
            // If sessions exist for the program's date, the program's full time
            // window must fit entirely within a matched session.  Programs are
            // NOT allowed to span session boundaries.
            // ------------------------------------------------------------------
            $sessionsOnDate = array_filter($sections, fn($s) => ($s['section_date'] ?? '') === $progDate);

            if (!empty($sessionsOnDate)) {
                if ($matchedSectionId === null) {
                    // No session covers the start time — find closest for a helpful message
                    $sessionNames = array_map(fn($s) => '"' . $s['name'] . '" (' .
                        date('h:i A', strtotime($s['start_time'])) . '–' . date('h:i A', strtotime($s['end_time'])) . ')',
                        array_values($sessionsOnDate)
                    );
                    throw new RuntimeException(
                        'No session covers ' . date('h:i A', strtotime($startSql)) . ' on ' . date('D, d M Y', strtotime($progDate)) . '. ' .
                        'Available sessions: ' . implode(', ', $sessionNames) . '. ' .
                        'Create a session that includes this time, or adjust the program time.'
                    );
                }

                // Also verify the END time fits within the matched session
                $matchedSec = null;
                foreach ($sections as $sec) {
                    if ((int)$sec['id'] === $matchedSectionId) {
                        $matchedSec = $sec;
                        break;
                    }
                }
                if ($matchedSec) {
                    $sesEndFull = $matchedSec['section_date'] . ' ' . $matchedSec['end_time'];
                    if ($endSql > $sesEndFull) {
                        throw new RuntimeException(
                            'Program end time ' . date('h:i A', strtotime($endSql)) . ' exceeds session "' . $matchedSec['name'] . '" end (' .
                            date('h:i A', strtotime($matchedSec['end_time'])) . '). ' .
                            'Shorten the program or extend the session.'
                        );
                    }
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

$stageTypes = $pdo->query('SELECT id, name, category FROM musabaqa_stage_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
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
           mst.category AS stage_category,
           mp.{$startExpr} AS start_at, mp.{$endExpr} AS end_at
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
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
    $breakMapByStage[(int)$break['stage_type_id']][] = $break;
}

$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.program_type, mp.class_type_id, ct.name AS class_type_name,
           t.full_name AS responsible_teacher_name, mp.allowed_sections, mp.location,
           COALESCE(mp.stage_type_id, 1) AS stage_type_id,
           mst.category AS stage_category
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
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

<style>
.modal-box select,
.modal-box option,
.modal-box optgroup {
    background-color: #0f172a !important;
    color: #ffffff !important;
}
.modal-box optgroup {
    color: #38bdf8 !important;
    font-weight: 700;
}
/* Force modals to always be viewport-fixed regardless of parent stacking context */
.modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 100000 !important;
    overflow-y: auto !important;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 30px 16px;
}
.modal-overlay.active {
    display: flex !important;
}
</style>

<div class="main-content">
    <div class="topbar" style="background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 16px 24px; border-radius: 14px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(168, 85, 247, 0.2)); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; color: #a78bfa; font-size: 20px;">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div>
                <div class="page-title" style="font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 2px;">Program Schedule</div>
                <div class="page-subtitle" style="font-size: 13px; color: var(--muted);">Organize stage timelines, resolve gaps, and schedule competition programs</div>
            </div>
        </div>
        <div class="flex gap-2" style="flex-wrap: wrap;">
            <button class="btn btn-warning btn-md" type="button" id="addNewExtraBtn" style="border-radius: 10px; font-weight: 700; background: linear-gradient(135deg, rgba(245,158,11,0.2), rgba(217,119,6,0.2)); color: #facc15; border: 1px solid rgba(245,158,11,0.35); box-shadow: 0 4px 14px rgba(245,158,11,0.15);"><i class="fa-solid fa-puzzle-piece mr-1"></i> Add Extra Item</button>
            <button class="btn btn-secondary btn-md" type="button" id="openUnscheduledModalBtn" style="border-radius: 10px; font-weight: 700; background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1);"><i class="fa-solid fa-clock mr-1" style="color: var(--warning);"></i> Unscheduled (<span id="topbarUnscheduledBadge"><?= count($unscheduledPrograms) ?></span>)</button>
            <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-md" style="border-radius: 10px; font-weight: 700; background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1);"><i class="fa-solid fa-microphone-lines mr-1" style="color: #38bdf8;"></i> All Programs</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="border-radius: 10px; font-weight: 600; margin-bottom: 20px;"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="panel mb-6" style="padding: 16px 20px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 14px; margin-bottom: 24px;">
        <form method="GET" class="form-grid" style="display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="stage_id" value="<?= $activeStageId ?>">
            <div class="input-group" style="flex: 1; min-width: 180px;">
                <label style="font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: block;">Class Section Filter</label>
                <select name="class" onchange="this.form.submit()" class="form-input" style="height: 40px; font-size: 13.5px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group" style="flex: 2; min-width: 240px;">
                <label style="font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: block;">Search Title or Location</label>
                <div style="position: relative; display: flex; align-items: center;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Filter by program name or room..." class="form-input" style="padding-left: 36px; height: 40px; font-size: 13.5px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff; width: 100%;">
                </div>
            </div>
            <div style="display: flex; gap: 8px; height: 40px;">
                <button class="btn btn-secondary btn-md" type="submit" style="border-radius: 8px; font-weight: 700; height: 40px; padding: 0 18px;"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                <?php if ($search !== '' || $classFilter !== 'all'): ?>
                    <a href="<?= app_url('/admin/event-manager/schedule.php?stage_id=' . $activeStageId) ?>" class="btn btn-secondary btn-md" style="border-radius: 8px; font-weight: 700; height: 40px; padding: 0 14px; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-solid fa-xmark mr-1"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- MAIN TWO-COLUMN LAYOUT: LEFT STAGE TABS/TIMELINE + RIGHT UNSCHEDULED SIDEBAR -->
    <div class="schedule-main-container" style="display: flex; gap: 24px; width: 100%; align-items: flex-start;">
        
        <!-- LEFT COLUMN: STAGE TABS & TIMELINE -->
        <div class="schedule-left-column" style="flex: 1; min-width: 0;">
            
            <!-- STAGE TABS BAR -->
            <div class="stage-tabs-bar" style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 14px; flex-wrap: wrap;">
                <?php foreach ($stageTypes as $idx => $stage): ?>
                    <?php 
                    $stId = (int)$stage['id'];
                    $stCount = count($programsByStage[$stId] ?? []);
                    $isTabActive = ($stId === $activeStageId) || ($activeStageId <= 0 && $idx === 0);
                    ?>
                    <button type="button" class="stage-tab-btn <?= $isTabActive ? 'active' : '' ?>" data-stage-tab="<?= $stId ?>" style="padding: 11px 20px; border-radius: 12px; font-weight: 700; font-size: 14px; border: 1px solid <?= $isTabActive ? 'rgba(99,102,241,0.5)' : 'rgba(255,255,255,0.08)' ?>; background: <?= $isTabActive ? 'linear-gradient(135deg, rgba(99,102,241,0.25), rgba(168,85,247,0.25))' : 'rgba(255,255,255,0.03)' ?>; color: <?= $isTabActive ? '#fff' : 'var(--muted)' ?>; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 10px; box-shadow: <?= $isTabActive ? '0 4px 16px rgba(99,102,241,0.2)' : 'none' ?>;">
                        <i class="fa-solid fa-layer-group" style="color: <?= $isTabActive ? '#a78bfa' : 'var(--muted)' ?>;"></i>
                        <span><?= e($stage['name']) ?></span>
                        <span class="badge" style="font-size: 11px; padding: 2px 8px; border-radius: 99px; background: <?= $isTabActive ? 'rgba(255,255,255,0.2)' : 'rgba(255,255,255,0.08)' ?>; color: #fff; font-weight: 800;"><?= $stCount ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- LIVE TIMELINE FILTER BAR -->
            <div class="panel mb-6" style="padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: rgba(15,23,42,0.4); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; margin-bottom: 20px;">
                <div style="position: relative; display: flex; align-items: center; flex: 1; min-width: 200px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                    <input type="text" id="timelineSearch" placeholder="Search scheduled timeline..." class="form-input" style="padding-left: 36px; height: 38px; font-size: 13px; width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
                </div>
                <select id="timelineSectionFilter" class="form-input" style="height: 38px; font-size: 13px; width: 170px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff; padding: 0 12px;">
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
                    $isOffStage = (stripos($stage['name'], 'off') !== false);
                    $lastProg = !empty($stageProgs) ? end($stageProgs) : null;
                    $lastEndAt = $lastProg ? $lastProg['end_at'] : '';
                    $isPanelActive = ($stageId === $activeStageId) || ($activeStageId <= 0 && $idx === 0);
                    ?>
                    <div class="stage-panel-item panel" data-stage-id="<?= $stageId ?>" data-last-end-at="<?= e($lastEndAt) ?>" style="padding: 24px; background: rgba(15,23,42,0.4); border: 2px dashed rgba(255,255,255,0.08); border-radius: 16px; transition: all 0.2s ease; <?= !$isPanelActive ? 'display: none;' : '' ?>">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 14px;">
                            <div class="dashboard-heading" style="margin: 0; font-size: 16px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-layer-group" style="color: #a78bfa;"></i>
                                <?= e($stage['name']) ?>
                                <span style="font-size: 12px; color: var(--muted); font-weight: 600;">(<?= count($stageProgs) ?> Scheduled Programs)</span>
                            </div>
                            <div style="font-size: 12px; color: var(--muted); font-weight: 600; display: flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.04); padding: 4px 10px; border-radius: 99px; border: 1px solid rgba(255,255,255,0.06);">
                                <i class="fa-solid fa-hand-pointer" style="color: #38bdf8;"></i> Drag & drop unscheduled programs to schedule
                            </div>
                        </div>

                        <?php if (empty($stageProgs) && empty($stageBreakMap)): ?>
                            <div class="empty-state stage-drop-zone" style="padding: 48px 24px; border: 2px dashed rgba(255,255,255,0.1); border-radius: 14px; text-align: center; background: rgba(255,255,255,0.01); transition: all 0.2s ease;">
                                <div class="empty-icon" style="font-size: 36px; color: var(--muted); margin-bottom: 10px;"><i class="fa-solid fa-calendar-xmark"></i></div>
                                <div class="empty-title" style="font-size: 15px; font-weight: 700; color: #fff; margin-top: 8px;">No Scheduled Programs for <?= e($stage['name']) ?></div>
                                <div class="empty-subtitle" style="font-size: 12.5px; color: var(--muted); margin-top: 4px;">Drag an unscheduled program card from the sidebar or click "Schedule Program" above.</div>
                            </div>
                        <?php else: ?>
                            <div class="grid gap-4 stage-drop-zone" style="position: relative;">
                                <?php if ($isOffStage): ?>
                                    <?php
                                    $groupedProgs = [];
                                    foreach ($stageProgs as $program) {
                                        $groupedProgs[$program['start_at']][] = $program;
                                    }
                                    ?>
                                    <?php foreach ($groupedProgs as $timeKey => $progsAtTime): ?>
                                        <?php if (count($progsAtTime) > 1): ?>
                                            <!-- Stacked / Grouped parallel cards -->
                                            <div class="parallel-programs-group" style="background: rgba(30, 41, 59, 0.2); border: 1px dashed rgba(255,255,255,0.08); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 14px; width: 100%;">
                                                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 10px; margin-bottom: 2px;">
                                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="fa-regular fa-calendar-days"></i>
                                                            <?= e(date('M d, Y', strtotime($timeKey))) ?>
                                                        </span>
                                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="fa-regular fa-clock"></i>
                                                            <?= e(date('h:i A', strtotime($timeKey))) ?>
                                                        </span>
                                                        <span style="font-size: 11.5px; color: var(--muted); font-weight: 700; background: rgba(245,158,11,0.1); color: #facc15; padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(245,158,11,0.2);">
                                                            <i class="fa-solid fa-layer-group mr-1"></i> Parallel Session (<?= count($progsAtTime) ?> Programs)
                                                        </span>
                                                    </div>
                                                </div>
                                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                                                    <?php foreach ($progsAtTime as $program): ?>
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

                                                        $startDt = new DateTime((string)$program['start_at']);
                                                        $endDt = new DateTime((string)$program['end_at']);
                                                        $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                                        $borderAccent = match($classTier) {
                                                            'senior' => '#a78bfa',
                                                            'junior' => '#38bdf8',
                                                            'subjunior' => '#34d399',
                                                            default => '#f43f5e'
                                                        };
                                                        ?>
                                                        <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>" style="margin-bottom: 0;">
                                                            <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.5); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease; height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                                                                <div style="display: flex; flex-direction: column; gap: 10px; height: 100%; justify-content: space-between;">
                                                                    <div style="min-width: 0;">
                                                                        <div class="dashboard-heading" style="font-size: 14.5px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                        <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                            <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                                <?= e($sectionDisplay) ?>
                                                                            </span>
                                                                            <?php if (!empty($program['location'])): ?>
                                                                                <span style="color: #38bdf8; font-size: 11px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                                <span style="color: var(--muted); font-size: 11px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 10px; flex-wrap: wrap; gap: 8px;">
                                                                        <div style="display: flex; align-items: center; gap: 6px;">
                                                                            <span class="badge badge-info" style="font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 6px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25);">
                                                                                Ends <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                            </span>
                                                                            <span style="font-size: 10px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 6px; border-radius: 5px;">
                                                                                <?= $durMins ?>m
                                                                            </span>
                                                                        </div>
                                                                        <div class="flex gap-2">
                                                                            <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 4px 8px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen"></i></button>
                                                                            
                                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                                <?= admin_csrf_field() ?>
                                                                                <input type="hidden" name="action" value="unschedule_program">
                                                                                <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                                <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                                <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 4px 8px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus"></i></button>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <!-- Single offstage card starting at this time -->
                                            <?php 
                                            $program = $progsAtTime[0];
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

                                            $startDt = new DateTime((string)$program['start_at']);
                                            $endDt = new DateTime((string)$program['end_at']);
                                            $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                            $borderAccent = match($classTier) {
                                                'senior' => '#a78bfa',
                                                'junior' => '#38bdf8',
                                                'subjunior' => '#34d399',
                                                default => '#f43f5e'
                                            };
                                            ?>
                                            <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>">
                                                <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.4); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                                            <div style="min-width: 0; flex: 1;">
                                                                <div class="dashboard-heading" style="font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                                    <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                        <?= e($sectionDisplay) ?>
                                                                    </span>
                                                                    <span class="badge" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700; background: rgba(245, 158, 11, 0.15); color: #facc15; border: 1px solid rgba(245, 158, 11, 0.3);">
                                                                        <i class="fa-solid fa-layer-group mr-1"></i> Off-Stage Parallel
                                                                    </span>
                                                                    <?php if (!empty($program['location'])): ?>
                                                                        <span style="color: #38bdf8; font-size: 11.5px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                        <span style="color: var(--muted); font-size: 11.5px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                                <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                    <i class="fa-regular fa-calendar-days"></i>
                                                                    <?= e(date('M d, Y', strtotime($program['start_at']))) ?>
                                                                </span>
                                                                <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                    <i class="fa-regular fa-clock"></i>
                                                                    <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                </span>
                                                                <span style="font-size: 11px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;">
                                                                    <?= $durMins ?> mins
                                                                </span>
                                                            </div>
                                                            <div class="flex gap-2">
                                                                <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen mr-1"></i> Edit</button>
                                                                
                                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                    <?= admin_csrf_field() ?>
                                                                    <input type="hidden" name="action" value="unschedule_program">
                                                                    <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                    <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                    <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus mr-1"></i> Unschedule</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php
                                    $timeline = [];
                                    foreach ($stageProgs as $p) {
                                        $timeline[] = [
                                            'type' => 'program',
                                            'start' => $p['start_at'],
                                            'end' => $p['end_at'],
                                            'data' => $p
                                        ];
                                    }
                                    foreach ($stageBreakMap as $b) {
                                        $timeline[] = [
                                            'type' => 'break',
                                            'start' => $b['start_datetime'],
                                            'end' => $b['end_datetime'],
                                            'data' => $b
                                        ];
                                    }
                                    usort($timeline, function($a, $b) {
                                        $cmp = strcmp($a['start'], $b['start']);
                                        if ($cmp === 0) {
                                            return strcmp($a['end'], $b['end']);
                                        }
                                        return $cmp;
                                    });
                                    ?>
                                    <?php foreach ($timeline as $index => $item): ?>
                                        <?php if ($item['type'] === 'program'): ?>
                                            <?php
                                            $program = $item['data'];
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

                                            $startDt = new DateTime((string)$program['start_at']);
                                            $endDt = new DateTime((string)$program['end_at']);
                                            $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                            $borderAccent = match($classTier) {
                                                'senior' => '#a78bfa',
                                                'junior' => '#38bdf8',
                                                'subjunior' => '#34d399',
                                                default => '#f43f5e'
                                            };
                                            ?>
                                            <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>">
                                                <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.4); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                                            <div style="min-width: 0; flex: 1;">
                                                                <div class="dashboard-heading" style="font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                                    <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                        <?= e($sectionDisplay) ?>
                                                                    </span>
                                                                    <?php if (!empty($program['location'])): ?>
                                                                        <span style="color: #38bdf8; font-size: 11.5px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                        <span style="color: var(--muted); font-size: 11.5px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                                <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px; vertical-align: middle;">
                                                                    <i class="fa-regular fa-calendar-days"></i>
                                                                    <?= e(date('M d, Y', strtotime($program['start_at']))) ?>
                                                                </span>
                                                                <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px; vertical-align: middle;">
                                                                    <i class="fa-regular fa-clock"></i>
                                                                    <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                </span>
                                                                <span style="font-size: 11px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;">
                                                                    <?= $durMins ?> mins
                                                                </span>
                                                            </div>
                                                            <div class="flex gap-2">
                                                                <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen mr-1"></i> Edit</button>
                                                                
                                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                    <?= admin_csrf_field() ?>
                                                                    <input type="hidden" name="action" value="unschedule_program">
                                                                    <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                    <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                    <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus mr-1"></i> Unschedule</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php $break = $item['data']; ?>
                                            <div class="timeline-gap-container timeline-row" style="margin: 10px 0; padding-left: 20px; border-left: 2px dashed rgba(250,204,21,.4); position: relative;">
                                                <div class="panel" style="border-color: rgba(250,204,21,.3); padding: 10px 14px; background: rgba(250,204,21,.05); border-radius: 10px;">
                                                    <div class="flex-between" style="gap: 10px; flex-wrap: wrap;">
                                                        <div>
                                                            <div class="dashboard-heading" style="font-size: 13px; font-weight: 700; margin: 0; color: #facc15;"><i class="fa-solid fa-puzzle-piece mr-2" style="color: #facc15;"></i> <?= e($break['name']) ?></div>
                                                            <div class="page-subtitle" style="font-size: 11px; color: var(--muted); margin-top: 2px;"><?= e($break['description'] ?: 'Intermission / Schedule Gap') ?></div>
                                                        </div>
                                                        <div class="flex gap-2 flex-wrap" style="align-items: center;">
                                                            <span class="badge badge-warning" style="font-size: 10px; padding: 3px 8px; border-radius: 6px; font-weight: 800; background: rgba(250,204,21,0.15); color: #facc15; border: 1px solid rgba(250,204,21,0.3); display: inline-flex; align-items: center; gap: 4px; vertical-align: middle;">
                                                                <i class="fa-regular fa-calendar-days"></i>
                                                                <?= e(date('M d, Y', strtotime($break['start_datetime']))) ?>
                                                            </span>
                                                            <span class="badge badge-warning" style="font-size: 10px; padding: 3px 8px; border-radius: 6px; font-weight: 800; background: rgba(250,204,21,0.15); color: #facc15; border: 1px solid rgba(250,204,21,0.3); display: inline-flex; align-items: center; gap: 4px; vertical-align: middle;">
                                                                <i class="fa-regular fa-clock"></i>
                                                                <?= e(date('h:i A', strtotime($break['start_datetime']))) ?> - <?= e(date('h:i A', strtotime($break['end_datetime']))) ?>
                                                            </span>
                                                            <form method="POST">
                                                                <?= admin_csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_extra">
                                                                <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                <input type="hidden" name="break_id" value="<?= (int)$break['id'] ?>">
                                                                <button class="btn btn-danger btn-sm" type="submit" style="padding: 4px 8px; font-size: 10px; border-radius: 6px;" title="Remove Extra Item"><i class="fa-solid fa-trash"></i></button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (isset($timeline[$index + 1])): ?>
                                            <?php
                                            $nextItem = $timeline[$index + 1];
                                            $gapStart = new DateTime((string)$item['end']);
                                            $gapEnd = new DateTime((string)$nextItem['start']);
                                            $hasGap = $gapStart < $gapEnd;
                                            $gapStartSql = $gapStart->format('Y-m-d H:i:s');
                                            $gapEndSql = $gapEnd->format('Y-m-d H:i:s');
                                            ?>
                                            <?php if ($hasGap): ?>
                                                <div class="timeline-gap-container timeline-row" style="margin: 10px 0; padding-left: 20px; border-left: 2px dashed rgba(255,255,255,0.15); position: relative;">
                                                    <div class="flex-between panel" style="padding: 8px 14px; background: rgba(255,255,255,0.02); border-color: rgba(255,255,255,0.06); border-radius: 10px;">
                                                        <div>
                                                            <div class="page-subtitle" style="font-size: 12px; margin: 0; color: var(--muted); font-weight: 600;"><i class="fa-solid fa-hourglass-half mr-2" style="color: #fbbf24;"></i> Timeline Gap (<?= e(date('M d', strtotime($gapStartSql))) ?>): <?= e(date('h:i A', strtotime($gapStartSql))) ?> - <?= e(date('h:i A', strtotime($gapEndSql))) ?></div>
                                                        </div>
                                                        <button
                                                            class="btn btn-success btn-sm"
                                                            type="button"
                                                            data-open-extra
                                                            data-open-break
                                                            data-stage-id="<?= $stageId ?>"
                                                            data-previous-program="<?= $item['type'] === 'program' ? (int)$item['data']['id'] : 0 ?>"
                                                            data-next-program="<?= $nextItem['type'] === 'program' ? (int)$nextItem['data']['id'] : 0 ?>"
                                                            data-gap-label="<?= e(date('h:i A', strtotime($gapStartSql)) . ' - ' . date('h:i A', strtotime($gapEndSql))) ?>"
                                                            data-gap-start="<?= $gapStartSql ?>"
                                                            data-gap-duration="<?= max(1, (int)round(($gapEnd->getTimestamp() - $gapStart->getTimestamp()) / 60)) ?>"
                                                            style="padding: 4px 10px; font-size: 11px; border-radius: 6px; font-weight: 700;"
                                                        >
                                                            <i class="fa-solid fa-plus mr-1"></i> Add Extra Item
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: DRAGGABLE UNSCHEDULED PROGRAMS PANEL -->
        <aside class="unscheduled-sidebar-panel panel" style="width: 340px; flex: 0 0 340px; position: sticky; top: 20px; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); display: flex; flex-direction: column; padding: 20px; border-color: rgba(255,255,255,0.08); background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px); border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.4);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px;">
                <div style="font-size: 15px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clock" style="color: var(--warning);"></i>
                    <span>Unscheduled Programs</span>
                </div>
                <span class="badge" id="sidebarUnscheduledCount" style="font-size: 11px; font-weight: 800; border-radius: 99px; background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); padding: 3px 8px;"><?= count($unscheduledPrograms) ?></span>
            </div>

            <div style="position: relative; display: flex; align-items: center; margin-bottom: 14px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 12px;"></i>
                <input type="text" id="sidebarUnscheduledSearch" placeholder="Search unscheduled..." class="form-input" style="padding-left: 34px; height: 38px; font-size: 12.5px; width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
            </div>

            <div style="font-size: 11.5px; color: var(--muted); margin-bottom: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-grip-vertical" style="color: #38bdf8;"></i> Drag program card to active stage timeline
            </div>

            <div class="unscheduled-sidebar-content" style="display: flex; flex-direction: column; gap: 10px; overflow-y: auto; flex: 1; padding-right: 4px;">
                <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                    <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                    <div class="accordion-item sidebar-accordion-item" data-tier="<?= $tierKey ?>">
                        <button class="accordion-header" type="button" style="width: 100%; text-align: left; padding: 11px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; color: #fff; font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: all 0.2s;">
                            <span><?= e($tierLabel) ?> <span class="tier-count" style="font-size: 11px; color: var(--muted); margin-left: 6px; font-weight: 600;">(<?= count($tierProgs) ?>)</span></span>
                            <i class="fa-solid fa-chevron-down accordion-icon" style="font-size: 11px; transition: transform 0.2s;"></i>
                        </button>
                        <div class="accordion-content" style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;">
                            <div style="padding: 10px 2px 0 2px; display: flex; flex-direction: column; gap: 10px;">
                                <?php if (empty($tierProgs)): ?>
                                    <div style="font-size: 12px; color: var(--muted); text-align: center; padding: 12px; background: rgba(0,0,0,0.1); border-radius: 8px;">No programs in this tier</div>
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
                                        <div class="unscheduled-card panel draggable-program-card" draggable="true" data-title="<?= e($prog['title']) ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" data-program-json='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="padding: 12px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; cursor: grab; display: flex; flex-direction: column; gap: 10px; transition: all 0.2s ease;">
                                            <div style="display: flex; align-items: flex-start; gap: 10px;">
                                                <i class="fa-solid fa-grip-vertical" style="color: var(--muted); font-size: 14px; margin-top: 2px;"></i>
                                                <div style="min-width: 0; flex: 1;">
                                                    <strong style="display: block; font-size: 13.5px; line-height: 1.3; color: #fff;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                                    <span class="page-subtitle" style="font-size: 11px; margin-top: 4px; display: inline-block;">
                                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 9.5px; padding: 1px 6px;">
                                                            <?= e($sectionDisplay) ?>
                                                        </span>
                                                        <?php if (!empty($prog['responsible_teacher_name'])): ?>
                                                            · <span style="color: var(--muted);"><i class="fa-solid fa-chalkboard-user"></i> <?= e($prog['responsible_teacher_name']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <button class="btn btn-success btn-sm" type="button" data-schedule-btn='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="width: 100%; justify-content: center; font-size: 11.5px; padding: 6px 10px; border-radius: 8px; font-weight: 700;"><i class="fa-solid fa-calendar-plus mr-1"></i> Schedule Now</button>
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
    <div class="modal-box modal-md" style="border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a;">
        <div class="modal-header">
            <div>
                <div class="modal-title" style="font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 8px;"><i class="fa-solid fa-puzzle-piece" style="color: #facc15;"></i> Add Extra Item</div>
                <div class="page-subtitle" id="breakGapLabel" style="font-size: 12px; color: var(--muted); margin-top: 4px;"></div>
            </div>
            <button class="modal-close" type="button" data-close="breakModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="breakForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="add_extra">
            <input type="hidden" name="previous_program_id" id="previousProgramId">
            <input type="hidden" name="next_program_id" id="nextProgramId">
            <div class="form-grid" style="padding: 20px; gap: 16px;">
                <div class="input-group full-width">
                    <label style="font-size: 12.5px; font-weight: 700;">Extra Title / Name <span class="required">*</span></label>
                    <input type="text" name="name" id="breakNameInput" required class="form-input" placeholder="e.g. Intermission / Tea Break / Announcements" style="height: 40px; border-radius: 8px;">
                </div>
                <div class="input-group full-width" id="breakStageSelectGroup">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Stage Type <span class="required">*</span></label>
                            <select id="breakStageTypeFilter" class="form-input" required style="height: 40px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                                <option value="on_stage">On Stage (Normal Stage)</option>
                                <option value="off_stage">Off Stage</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Specific Venue / Stage <span class="required">*</span></label>
                            <select name="stage_type_id" id="breakStageTypeId" class="form-input" required style="height: 40px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                                <?php foreach ($stageTypes as $stage): ?>
                                    <option value="<?= (int)$stage['id'] ?>" data-category="<?= e($stage['category'] ?? 'on_stage') ?>" data-name="<?= e($stage['name']) ?>"><?= e($stage['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="input-group full-width" id="breakTimeFieldsGroup">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Start Date & Time</label>
                            <input type="datetime-local" name="start_time" id="breakStartTime" class="form-input" style="height: 40px; border-radius: 8px;">
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Duration (Minutes)</label>
                            <input type="number" name="duration_minutes" id="breakDurationMinutes" min="1" placeholder="e.g. 15" class="form-input" value="15" style="height: 40px; border-radius: 8px;">
                        </div>
                    </div>
                </div>
                <div class="input-group full-width">
                    <label style="font-size: 12.5px; font-weight: 700;">Description</label>
                    <textarea name="description" id="breakDescriptionInput" rows="3" class="form-input" placeholder="Optional details about this extra item..." style="border-radius: 8px;"></textarea>
                </div>
            </div>
            <div class="form-actions" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary btn-md" type="button" data-close="breakModal" style="border-radius: 8px;">Cancel</button>
                <button class="btn btn-success btn-md" type="submit" style="border-radius: 8px; font-weight: 700;">Save Extra Item</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box modal-md" style="border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a;">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="scheduleModalTitle" style="font-size: 18px; font-weight: 800; color: #fff;">Schedule Program</div>
            </div>
            <button class="modal-close" type="button" data-close="scheduleModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="scheduleForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="schedule_program">
            
            <div class="form-grid" style="padding: 20px; gap: 16px;">
                <!-- PROGRAM SELECT DROP-DOWN (Shown when creating/scheduling) -->
                <div class="input-group full-width" id="modalProgramSelectGroup">
                    <label style="font-size: 12.5px; font-weight: 700;">Select Program <span class="required">*</span></label>
                    <select name="program_id" id="scheduleProgramSelect" class="form-input" required style="height: 42px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
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
                    <label style="font-size: 12.5px; font-weight: 700;">Program</label>
                    <div id="scheduleProgramTitle" style="font-weight: 800; color: #38bdf8; font-size: 15px; padding: 12px 16px; background: rgba(56,189,248,0.08); border: 1px solid rgba(56,189,248,0.2); border-radius: 8px;"></div>
                    <input type="hidden" name="program_id" id="scheduleProgramId">
                </div>

                <div class="input-group full-width" id="modalStageGroup">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Stage Type <span class="required">*</span></label>
                            <select id="scheduleStageTypeFilter" class="form-input" required style="height: 42px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                                <option value="on_stage">On Stage (Normal Stage)</option>
                                <option value="off_stage">Off Stage</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Specific Venue / Stage <span class="required">*</span></label>
                            <select name="stage_type_id" id="scheduleStageTypeId" class="form-input" required style="height: 42px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                                <?php foreach ($stageTypes as $stage): ?>
                                    <option value="<?= (int)$stage['id'] ?>" data-category="<?= e($stage['category'] ?? 'on_stage') ?>" data-name="<?= e($stage['name']) ?>"><?= e($stage['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="input-group full-width">
                    <label style="font-size: 12.5px; font-weight: 700;">Location / Room</label>
                    <input type="text" name="location" id="scheduleLocation" placeholder="e.g. Main Auditorium, Stage 1" class="form-input" style="height: 40px; border-radius: 8px;">
                </div>
                <div class="input-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 12.5px; font-weight: 700; margin: 0;">Start Date & Time <span class="required">*</span></label>
                        <button type="button" id="scheduleUseNextSlotBtn" class="btn btn-secondary btn-xs" style="font-size: 10.5px; padding: 2px 8px; border-radius: 6px; font-weight: 700;"><i class="fa-solid fa-bolt" style="color: #facc15;"></i> Next Slot</button>
                    </div>
                    <input type="datetime-local" name="start_time" id="scheduleStartTime" class="form-input" required style="height: 40px; border-radius: 8px;">
                </div>
                <div class="input-group">
                    <label style="font-size: 12.5px; font-weight: 700;">Duration (Minutes)</label>
                    <input type="number" name="duration_minutes" id="scheduleDurationMinutes" min="1" placeholder="e.g. 30" class="form-input" value="30" style="height: 40px; border-radius: 8px;">
                    <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="15" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">15m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="30" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">30m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="45" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">45m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="60" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">60m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="90" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">90m</button>
                    </div>
                </div>
                <div class="input-group full-width">
                    <label style="font-size: 12.5px; font-weight: 700;">End Date & Time</label>
                    <input type="datetime-local" name="end_time" id="scheduleEndTime" class="form-input" style="height: 40px; border-radius: 8px;">
                </div>
            </div>
            <div class="form-actions" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary btn-md" type="button" data-close="scheduleModal" style="border-radius: 8px;">Cancel</button>
                <button class="btn btn-success btn-md" type="submit" style="border-radius: 8px; font-weight: 700;">Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="unscheduledProgramsModal">
    <div class="modal-box modal-md" style="max-height: 85vh; display: flex; flex-direction: column; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a;">
        <div class="modal-header">
            <div>
                <div class="modal-title" style="display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 800; color: #fff;">
                    <i class="fa-solid fa-clock" style="color: var(--warning);"></i> Unscheduled Programs
                </div>
            </div>
            <button class="modal-close" type="button" data-close="unscheduledProgramsModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1;">
            <div style="position: relative; display: flex; align-items: center; margin-bottom: 16px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                <input type="text" id="unscheduledSearchInput" placeholder="Search unscheduled programs..." class="form-input" style="padding-left: 36px; height: 40px; font-size: 13px; width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
            </div>

            <div class="unscheduled-accordion-container" style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                    <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                    <div class="accordion-item modal-accordion-item" data-tier="<?= $tierKey ?>">
                        <button class="accordion-header" type="button" style="width: 100%; text-align: left; padding: 12px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; color: #fff; font-weight: 700; font-size: 13.5px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: all 0.2s;">
                            <span><?= e($tierLabel) ?> <span class="tier-count" style="font-size: 11px; color: var(--muted); margin-left: 6px; font-weight: 600;">(<?= count($tierProgs) ?>)</span></span>
                            <i class="fa-solid fa-chevron-down accordion-icon" style="font-size: 11px; transition: transform 0.2s;"></i>
                        </button>
                        <div class="accordion-content" style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;">
                            <div style="padding: 10px 2px 0 2px; display: flex; flex-direction: column; gap: 10px;">
                                <?php if (empty($tierProgs)): ?>
                                    <div style="font-size: 12px; color: var(--muted); text-align: center; padding: 12px; background: rgba(0,0,0,0.1); border-radius: 8px;">No programs</div>
                                <?php else: ?>
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <div class="unscheduled-card panel" data-title="<?= e($prog['title']) ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" style="padding: 12px 14px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; display: flex; flex-direction: column; gap: 10px;">
                                            <div style="min-width: 0; flex: 1;">
                                                <strong style="display: block; font-size: 14px; line-height: 1.3; color: #fff;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                            </div>
                                            <button class="btn btn-success btn-sm" type="button" data-schedule-btn='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="width: 100%; justify-content: center; font-size: 12px; padding: 7px 12px; border-radius: 8px; font-weight: 700;"><i class="fa-solid fa-calendar-plus mr-1"></i> Schedule Program</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end;">
            <button class="btn btn-secondary btn-md" type="button" data-close="unscheduledProgramsModal" style="border-radius: 8px;">Close</button>
        </div>
    </div>
</div>


<script>
const offStageMap = {
    <?php foreach ($stageTypes as $st): ?>
        '<?= (int)$st['id'] ?>': <?= (($st['category'] ?? '') === 'off_stage') ? 'true' : 'false' ?>,
    <?php endforeach; ?>
};
let currentActiveStageId = '<?= (int)($activeStageId ?: ($stageTypes[0]['id'] ?? 0)) ?>';

// Sync Specific Venue dropdown for Schedule Modal based on Category
function syncScheduleVenues(category, selectedVal = '') {
    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (!stageSelect) return;
    
    if (!window.ALL_SCHEDULE_STAGE_OPTIONS) {
        window.ALL_SCHEDULE_STAGE_OPTIONS = Array.from(stageSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }
    
    stageSelect.innerHTML = '<option value="">-- Select Venue --</option>';
    
    const filtered = window.ALL_SCHEDULE_STAGE_OPTIONS.filter(opt => opt.value === '' || opt.category === category);
    filtered.forEach(opt => {
        if (opt.value === '') return;
        const o = document.createElement('option');
        o.value = opt.value;
        o.text = opt.text;
        o.setAttribute('data-category', opt.category);
        o.setAttribute('data-name', opt.name);
        if (String(opt.value) === String(selectedVal)) {
            o.selected = true;
        }
        stageSelect.appendChild(o);
    });
}

// Sync Specific Venue dropdown for Break Modal based on Category
function syncBreakVenues(category, selectedVal = '') {
    const stageSelect = document.getElementById('breakStageTypeId');
    if (!stageSelect) return;
    
    if (!window.ALL_BREAK_STAGE_OPTIONS) {
        window.ALL_BREAK_STAGE_OPTIONS = Array.from(stageSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }
    
    stageSelect.innerHTML = '<option value="">-- Select Venue --</option>';
    
    const filtered = window.ALL_BREAK_STAGE_OPTIONS.filter(opt => opt.value === '' || opt.category === category);
    filtered.forEach(opt => {
        if (opt.value === '') return;
        const o = document.createElement('option');
        o.value = opt.value;
        o.text = opt.text;
        o.setAttribute('data-category', opt.category);
        o.setAttribute('data-name', opt.name);
        if (String(opt.value) === String(selectedVal)) {
            o.selected = true;
        }
        stageSelect.appendChild(o);
    });
}

// Set stage helper for Schedule Modal (updates Stage Type and syncs venue)
function setScheduleStage(stageId) {
    const filterSelect = document.getElementById('scheduleStageTypeFilter');
    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (!stageSelect) return;

    if (!window.ALL_SCHEDULE_STAGE_OPTIONS) {
        window.ALL_SCHEDULE_STAGE_OPTIONS = Array.from(stageSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }

    const found = window.ALL_SCHEDULE_STAGE_OPTIONS.find(opt => String(opt.value) === String(stageId));
    const category = found ? (found.category || 'on_stage') : 'on_stage';

    if (filterSelect) {
        filterSelect.value = category;
    }
    syncScheduleVenues(category, stageId);
}

// Set stage helper for Break Modal
function setBreakStage(stageId) {
    const filterSelect = document.getElementById('breakStageTypeFilter');
    const stageSelect = document.getElementById('breakStageTypeId');
    if (!stageSelect) return;

    if (!window.ALL_BREAK_STAGE_OPTIONS) {
        window.ALL_BREAK_STAGE_OPTIONS = Array.from(stageSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }

    const found = window.ALL_BREAK_STAGE_OPTIONS.find(opt => String(opt.value) === String(stageId));
    const category = found ? (found.category || 'on_stage') : 'on_stage';

    if (filterSelect) {
        filterSelect.value = category;
    }
    syncBreakVenues(category, stageId);
}

function updateActiveStage(stageId) {
    currentActiveStageId = String(stageId);

    const isOffStage = !!offStageMap[currentActiveStageId];
    const addNewExtraBtn = document.getElementById('addNewExtraBtn');
    if (addNewExtraBtn) {
        addNewExtraBtn.style.display = isOffStage ? 'none' : '';
    }

    // 1. Update Stage Tab buttons active state
    document.querySelectorAll('.stage-tab-btn').forEach(btn => {
        const isTarget = btn.dataset.stageTab === currentActiveStageId;
        btn.classList.toggle('active', isTarget);
        btn.style.background = isTarget ? 'linear-gradient(135deg, rgba(99,102,241,0.25), rgba(168,85,247,0.25))' : 'rgba(255,255,255,0.03)';
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
    const topbarBadge = document.getElementById('topbarUnscheduledBadge');
    if (topbarBadge) {
        topbarBadge.textContent = totalVisible;
    }
}

function filterModalProgramOptions() {
    const selectEl = document.getElementById('scheduleProgramSelect');
    const stageSelect = document.getElementById('scheduleStageTypeId');
    const selectedStage = stageSelect ? stageSelect.value : currentActiveStageId;

    if (selectEl) {
        Array.from(selectEl.options).forEach(opt => {
            if (!opt.value) return;
            const optStage = opt.dataset.stageTypeId || '1';
            const matchesStage = (String(optStage) === String(selectedStage));
            opt.disabled = !matchesStage;
            opt.style.display = matchesStage ? '' : 'none';
        });
    }
}

// Initialize stage caching and register event listeners on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    const scheduleSelect = document.getElementById('scheduleStageTypeId');
    if (scheduleSelect) {
        window.ALL_SCHEDULE_STAGE_OPTIONS = Array.from(scheduleSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }

    const breakSelect = document.getElementById('breakStageTypeId');
    if (breakSelect) {
        window.ALL_BREAK_STAGE_OPTIONS = Array.from(breakSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }

    // Stage Type change listener in scheduleModal
    document.getElementById('scheduleStageTypeFilter')?.addEventListener('change', (e) => {
        syncScheduleVenues(e.target.value);
        const stageSelect = document.getElementById('scheduleStageTypeId');
        if (stageSelect) {
            const locName = stageSelect.options[stageSelect.selectedIndex]?.getAttribute('data-name') || '';
            document.getElementById('scheduleLocation').value = locName;
        }
        filterModalProgramOptions();
        applyNextAvailableSlotForStage();
    });

    // Stage Select dropdown change listener in scheduleModal
    document.getElementById('scheduleStageTypeId')?.addEventListener('change', (e) => {
        const locName = e.target.options[e.target.selectedIndex]?.getAttribute('data-name') || '';
        document.getElementById('scheduleLocation').value = locName;
        filterModalProgramOptions();
        applyNextAvailableSlotForStage();
    });

    // Stage Type change listener in breakModal
    document.getElementById('breakStageTypeFilter')?.addEventListener('change', (e) => {
        syncBreakVenues(e.target.value);
    });
});


function applyNextAvailableSlotForStage() {
    const stageId = document.getElementById('scheduleStageTypeId').value;
    const targetPanel = document.querySelector(`.stage-panel-item[data-stage-id="${stageId}"]`);
    if (targetPanel && targetPanel.dataset.lastEndAt) {
        document.getElementById('scheduleStartTime').value = toLocalDatetime(targetPanel.dataset.lastEndAt);
    } else {
        document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
    }
    updateScheduleEndTime();
}

document.getElementById('scheduleUseNextSlotBtn')?.addEventListener('click', () => {
    applyNextAvailableSlotForStage();
});

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

// Modal Unscheduled Search Fix
const unscheduledSearchInput = document.getElementById('unscheduledSearchInput');
unscheduledSearchInput?.addEventListener('input', () => {
    const query = unscheduledSearchInput.value.toLowerCase().trim();
    document.querySelectorAll('.modal-accordion-item').forEach(item => {
        let matchCount = 0;
        const cards = item.querySelectorAll('.unscheduled-card');
        const content = item.querySelector('.accordion-content');
        const icon = item.querySelector('.accordion-icon');

        cards.forEach(card => {
            const title = card.dataset.title.toLowerCase();
            if (query === '' || title.includes(query)) {
                card.style.display = '';
                matchCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (query !== '' && matchCount > 0) {
            item.classList.add('is-open');
            if (content) content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
            item.style.display = '';
        } else if (query !== '' && matchCount === 0) {
            item.style.display = 'none';
        } else {
            item.style.display = '';
        }
    });
});

// Run stage filter on initial load
document.addEventListener('DOMContentLoaded', () => {
    updateActiveStage(currentActiveStageId);
});

// HTML5 Drag and Drop for Unscheduled Programs
let currentDraggedProgramData = null;

document.querySelectorAll('.draggable-program-card[draggable="true"]').forEach(card => {
    card.addEventListener('dragstart', (e) => {
        try {
            currentDraggedProgramData = JSON.parse(card.dataset.programJson);
        } catch(err) {
            currentDraggedProgramData = null;
        }
        e.dataTransfer.setData('text/plain', card.dataset.programJson || '');
        card.style.opacity = '0.5';
    });
    card.addEventListener('dragend', () => {
        card.style.opacity = '1';
    });
});

document.querySelectorAll('.stage-panel-item').forEach(stagePanel => {
    stagePanel.addEventListener('dragover', (e) => {
        e.preventDefault();
        stagePanel.style.borderColor = '#38bdf8';
        stagePanel.style.background = 'rgba(56,189,248,0.06)';
    });
    stagePanel.addEventListener('dragleave', () => {
        stagePanel.style.borderColor = 'rgba(255,255,255,0.08)';
        stagePanel.style.background = 'rgba(15,23,42,0.4)';
    });
    stagePanel.addEventListener('drop', (e) => {
        e.preventDefault();
        stagePanel.style.borderColor = 'rgba(255,255,255,0.08)';
        stagePanel.style.background = 'rgba(15,23,42,0.4)';
        
        let p = currentDraggedProgramData;
        if (!p) {
            try {
                const raw = e.dataTransfer.getData('text/plain');
                if (raw) p = JSON.parse(raw);
            } catch(err) {}
        }
        
        if (!p || !p.id) {
            return;
        }

        // Reset form cleanly
        document.getElementById('scheduleForm').reset();
        document.getElementById('scheduleModalTitle').textContent = 'Schedule Program';

        const submitBtn = document.querySelector('#scheduleForm button[type="submit"]');
        if (submitBtn) {
            submitBtn.style.pointerEvents = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Save Schedule';
        }

        // Show static program display (no dropdown needed — user already picked via drag)
        document.getElementById('modalProgramSelectGroup').style.display = 'none';
        const selectEl = document.getElementById('scheduleProgramSelect');
        selectEl.disabled = true;
        selectEl.required = false;
        selectEl.name = 'program_id_select_unused';

        document.getElementById('modalProgramStaticGroup').style.display = '';
        document.getElementById('scheduleProgramTitle').textContent = p.title || ('Program #' + p.id);
        const hiddenEl = document.getElementById('scheduleProgramId');
        hiddenEl.disabled = false;
        hiddenEl.name = 'program_id';
        hiddenEl.value = p.id;

        // Always show Stage/Venue field
        const stageGroup = document.getElementById('modalStageGroup');
        if (stageGroup) stageGroup.style.display = '';
        const stageSelectEl = document.getElementById('scheduleStageTypeId');
        stageSelectEl.disabled = false;
        stageSelectEl.required = true;

        // Set the stage from the drop target panel (stageId == stage_type_id)
        const stageId = stagePanel.dataset.stageId;
        if (stageId) {
            setScheduleStage(stageId);
        } else if (p.stage_type_id) {
            setScheduleStage(p.stage_type_id);
        }

        // Set location from program data
        if (p.location) {
            document.getElementById('scheduleLocation').value = p.location;
        }

        // Set start time to the next available slot for this stage
        const lastEndAt = stagePanel.dataset.lastEndAt;
        if (lastEndAt) {
            document.getElementById('scheduleStartTime').value = toLocalDatetime(lastEndAt);
        } else {
            document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
        }

        document.getElementById('scheduleDurationMinutes').value = '30';
        updateScheduleEndTime();

        openModal('scheduleModal');
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
    
    // Hide/show parallel group container if all items inside are hidden
    document.querySelectorAll('.parallel-programs-group').forEach(group => {
        const hasVisible = Array.from(group.querySelectorAll('.timeline-item-container')).some(item => item.style.display !== 'none');
        group.style.display = hasVisible ? '' : 'none';
    });
    
    timelineGaps.forEach(gap => {
        gap.style.display = isFiltering ? 'none' : '';
    });
}

timelineSearch?.addEventListener('input', filterTimeline);
timelineSectionFilter?.addEventListener('change', filterTimeline);

// Modal helpers
// Move all modal overlays to <body> to escape any CSS stacking context trap
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal-overlay').forEach(el => {
        if (el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    });
});

function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active');
    // Reset scroll so the modal box is always visible at the top
    el.scrollTop = 0;
}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));

// Automatically close modal when submitting any form inside modal
document.querySelectorAll('.modal-overlay form').forEach(form => {
    form.addEventListener('submit', () => {
        const modalOverlay = form.closest('.modal-overlay');
        if (modalOverlay && modalOverlay.id) {
            closeModal(modalOverlay.id);
        }
    });
});

// Click topbar "Add Extra Item" button
document.getElementById('addNewExtraBtn')?.addEventListener('click', () => {
    document.getElementById('breakForm').reset();
    const submitBtn = document.querySelector('#breakForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save Extra Item';
    }
    document.getElementById('previousProgramId').value = '';
    document.getElementById('nextProgramId').value = '';
    document.getElementById('breakGapLabel').textContent = 'Custom Extra Item / Intermission';
    
    document.getElementById('breakStageSelectGroup').style.display = '';
    document.getElementById('breakTimeFieldsGroup').style.display = '';
    setBreakStage(currentActiveStageId || '1');
    
    const targetPanel = document.querySelector(`.stage-panel-item[data-stage-id="${currentActiveStageId}"]`);
    if (targetPanel && targetPanel.dataset.lastEndAt) {
        document.getElementById('breakStartTime').value = toLocalDatetime(targetPanel.dataset.lastEndAt);
    } else {
        document.getElementById('breakStartTime').value = formatLocalDatetime(new Date());
    }
    document.getElementById('breakDurationMinutes').value = '15';
    openModal('breakModal');
});

// Click timeline gap "Add Extra Item" button
document.querySelectorAll('[data-open-extra], [data-open-break]').forEach(button => button.addEventListener('click', () => {
    document.getElementById('breakForm').reset();
    const submitBtn = document.querySelector('#breakForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save Extra Item';
    }
    setBreakStage(button.dataset.stageId || '');
    document.getElementById('previousProgramId').value = button.dataset.previousProgram || '';
    document.getElementById('nextProgramId').value = button.dataset.nextProgram || '';
    document.getElementById('breakGapLabel').textContent = button.dataset.gapLabel ? ('Timeline Gap: ' + button.dataset.gapLabel) : '';
    
    document.getElementById('breakStageSelectGroup').style.display = 'none';
    document.getElementById('breakTimeFieldsGroup').style.display = '';
    
    if (button.dataset.gapStart) {
        document.getElementById('breakStartTime').value = toLocalDatetime(button.dataset.gapStart);
    }
    if (button.dataset.gapDuration) {
        document.getElementById('breakDurationMinutes').value = button.dataset.gapDuration;
    }
    openModal('breakModal');
}));

// Auto-fill location and venue stage when selecting a program in dropdown
document.getElementById('scheduleProgramSelect')?.addEventListener('change', (e) => {
    const opt = e.target.options[e.target.selectedIndex];
    if (opt && opt.dataset) {
        if (opt.dataset.location !== undefined && opt.dataset.location !== '') {
            document.getElementById('scheduleLocation').value = opt.dataset.location;
        }
        if (opt.dataset.stageTypeId && Number(opt.dataset.stageTypeId) > 0) {
            setScheduleStage(opt.dataset.stageTypeId);
            applyNextAvailableSlotForStage();
        }
    }
});

function applyNextAvailableSlotForStage() {
    const stageSelect = document.getElementById('scheduleStageTypeId');
    const stageId = stageSelect ? stageSelect.value : currentActiveStageId;
    const stagePanel = document.querySelector(`.stage-panel-item[data-stage-id="${stageId}"]`);
    
    if (stagePanel && stagePanel.dataset.lastEndAt) {
        document.getElementById('scheduleStartTime').value = toLocalDatetime(stagePanel.dataset.lastEndAt);
    } else {
        document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
    }
    document.getElementById('scheduleDurationMinutes').value = '30';
    updateScheduleEndTime();
}

document.getElementById('scheduleUseNextSlotBtn')?.addEventListener('click', applyNextAvailableSlotForStage);


function filterModalProgramOptions() {
    const selectEl = document.getElementById('scheduleProgramSelect');
    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (!selectEl || !stageSelect) return;
    
    const selectedStage = stageSelect.value;
    const options = Array.from(selectEl.options);
    
    options.forEach(opt => {
        if (!opt.value) return;
        opt.disabled = false;
        opt.style.display = '';
    });
}

// Modal Form configurations (Toggles select dropdown vs static display)
function setModalCreateMode() {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Schedule Program';
    
    const submitBtn = document.querySelector('#scheduleForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save Schedule';
    }
    
    document.getElementById('modalProgramSelectGroup').style.display = '';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = false;
    selectEl.required = true;
    selectEl.name = 'program_id';
    
    document.getElementById('modalProgramStaticGroup').style.display = 'none';
    const hiddenEl = document.getElementById('scheduleProgramId');
    hiddenEl.disabled = true;
    hiddenEl.name = 'program_id_hidden';
    
    setScheduleStage(currentActiveStageId || '<?= $stageTypes ? (int)$stageTypes[0]['id'] : '' ?>');
    document.getElementById('scheduleLocation').value = '';
    filterModalProgramOptions();
}

function setModalEditMode(p) {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Edit Program Schedule';
    
    const submitBtn = document.querySelector('#scheduleForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save Schedule';
    }
    
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
    
    const stageGroupEl = document.getElementById('modalStageGroup');
    if (stageGroupEl) stageGroupEl.style.display = '';
    document.getElementById('scheduleStageTypeId').disabled = false;
    document.getElementById('scheduleStageTypeId').required = true;
    setScheduleStage(p.stage_type_id || '');
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
    if (selectEl) {
        selectEl.dispatchEvent(new Event('change'));
    }
    applyNextAvailableSlotForStage();
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
    
    if (p.stage_type_id) {
        setScheduleStage(p.stage_type_id);
    }
    filterModalProgramOptions();
    
    const selectEl = document.getElementById('scheduleProgramSelect');
    if (selectEl && p && p.id) {
        const targetOpt = selectEl.querySelector(`option[value="${p.id}"]`);
        if (targetOpt) {
            targetOpt.disabled = false;
            targetOpt.style.display = '';
        }
        selectEl.value = String(p.id);
    }

    if (p.location) {
        document.getElementById('scheduleLocation').value = p.location;
    }
    
    applyNextAvailableSlotForStage();
    openModal('scheduleModal');
}));

// 3. Click Edit program schedule button
document.querySelectorAll('[data-edit-schedule-btn]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.editScheduleBtn);
    setModalEditMode(p);
    openModal('scheduleModal');
}));

// Submit handlers to close modals immediately and show saving progress
document.getElementById('scheduleForm')?.addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = 'none';
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
    }
    setTimeout(() => closeModal('scheduleModal'), 50);
});

document.getElementById('breakForm')?.addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = 'none';
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
    }
    setTimeout(() => closeModal('breakModal'), 50);
});
</script>
<?php admin_close_page(); ?>
