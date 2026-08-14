<?php
$pageTitle = 'Programs';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function get_musabaqa_settings($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    
    $defaults = [
        'default_judges_count' => 2,
        'default_total_marks' => 100,
        'default_entries_limit' => 10,
        'active_sections' => [],
        'section_limits' => []
    ];
    
    if ($row) {
        $data = json_decode($row['setting_value'], true);
        if (is_array($data)) {
            return array_merge($defaults, $data);
        }
    }
    
    return $defaults;
}

$settings = get_musabaqa_settings($pdo);

$scheduleSections = $pdo->prepare("SELECT id, name FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY sort_order ASC, name ASC");
$scheduleSections->execute([$activeEventId]);
$scheduleSections = $scheduleSections->fetchAll(PDO::FETCH_ASSOC);

$stageTypes = $pdo->query("SELECT id, name, category FROM musabaqa_stage_types ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

function programs_redirect(): void
{
    if (admin_is_ajax() || isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        $flash = admin_take_flash();
        echo json_encode([
            'success' => ($flash['type'] ?? '') !== 'error',
            'message' => $flash['message'] ?? 'Program updated successfully.'
        ]);
        exit;
    }
    admin_redirect('/admin/event-manager/programs.php');
}

function program_status_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function program_approval_badge(?string $status): string
{
    return match ((string)$status) {
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'submitted' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function parse_admin_datetime_local(?string $value): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    return null;
}

function format_admin_datetime_for_input(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($value, 0, 16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        programs_redirect();
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'manage_stages') {
        $stageAction = (string)($_POST['stage_action'] ?? '');
        try {
            if ($stageAction === 'create') {
                $name = trim((string)($_POST['stage_name'] ?? ''));
                $category = trim((string)($_POST['stage_category'] ?? 'on_stage'));
                if ($name === '') {
                    throw new RuntimeException('Stage name is required.');
                }
                if (!in_array($category, ['on_stage', 'off_stage'], true)) {
                    throw new RuntimeException('Invalid stage category.');
                }
                // Check for duplicate stage name
                $chk = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_stage_types WHERE name = ?');
                $chk->execute([$name]);
                if ($chk->fetchColumn() > 0) {
                    throw new RuntimeException("A stage with the name '{$name}' already exists.");
                }
                $stmt = $pdo->prepare('INSERT INTO musabaqa_stage_types (name, category) VALUES (?, ?)');
                $stmt->execute([$name, $category]);
                admin_flash('success', 'New stage created successfully.');
            } elseif ($stageAction === 'delete') {
                $stageId = (int)($_POST['stage_id'] ?? 0);
                
                admin_db_transaction($pdo, function ($pdo) use ($stageId) {
                    // Find a fallback stage ID (any stage that is not the one being deleted)
                    $fallbackStmt = $pdo->prepare('SELECT id FROM musabaqa_stage_types WHERE id != ? LIMIT 1');
                    $fallbackStmt->execute([$stageId]);
                    $fallbackId = $fallbackStmt->fetchColumn();
                    
                    if ($fallbackId === false) {
                        throw new RuntimeException('Cannot delete the last remaining stage in the system.');
                    }
                    
                    // Update programs using this stage: set to fallback stage and clear location string
                    $upProg = $pdo->prepare('UPDATE musabaqa_programs SET stage_type_id = ?, location = NULL WHERE stage_type_id = ?');
                    $upProg->execute([(int)$fallbackId, $stageId]);
                    
                    // Update breaks using this stage: set to fallback stage
                    $upBreak = $pdo->prepare('UPDATE musabaqa_breaks SET stage_type_id = ? WHERE stage_type_id = ?');
                    $upBreak->execute([(int)$fallbackId, $stageId]);
                    
                    // Now delete the stage itself
                    $delStage = $pdo->prepare('DELETE FROM musabaqa_stage_types WHERE id = ?');
                    $delStage->execute([$stageId]);
                });
                
                admin_flash('success', 'Stage deleted and removed from all associated programs.');
            }
        } catch (Throwable $e) {
            admin_flash('error', $e->getMessage());
        }
        admin_redirect('/admin/event-manager/programs.php', ['open_stages' => 1]);
    }
    $programId = (int)($_POST['program_id'] ?? 0);

    try {
        if ($action === 'delete') {
            admin_db_transaction($pdo, function ($pdo) use ($programId, $activeEventId) {
                // 1. Get entries associated with this program
                $entryStmt = $pdo->prepare('SELECT id FROM musabaqa_program_entries WHERE program_id = ? AND event_id = ?');
                $entryStmt->execute([$programId, $activeEventId]);
                $entryIds = $entryStmt->fetchAll(PDO::FETCH_COLUMN);

                // 2. Delete member scores linked to program
                $pdo->prepare('DELETE FROM musabaqa_member_scores WHERE program_id = ?')->execute([$programId]);

                // 3. Delete program scores linked to program
                $pdo->prepare('DELETE FROM musabaqa_scores WHERE program_id = ? AND event_id = ?')->execute([$programId, $activeEventId]);

                // 4. Delete score sheets & category scores for entries under this program
                if ($entryIds) {
                    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
                    $sheetStmt = $pdo->prepare("SELECT id FROM musabaqa_score_sheets WHERE entry_id IN ($placeholders)");
                    $sheetStmt->execute($entryIds);
                    $sheetIds = $sheetStmt->fetchAll(PDO::FETCH_COLUMN);

                    if ($sheetIds) {
                        $sheetPlaceholders = implode(',', array_fill(0, count($sheetIds), '?'));
                        $pdo->prepare("DELETE FROM musabaqa_category_scores WHERE score_sheet_id IN ($sheetPlaceholders)")->execute($sheetIds);
                    }

                    $pdo->prepare("DELETE FROM musabaqa_score_sheets WHERE entry_id IN ($placeholders)")->execute($entryIds);
                    $pdo->prepare("DELETE FROM musabaqa_entry_members WHERE entry_id IN ($placeholders)")->execute($entryIds);
                }

                // 5. Delete scoring categories for this program
                $pdo->prepare('DELETE FROM musabaqa_program_categories WHERE program_id = ?')->execute([$programId]);

                // 6. Delete program entries
                $pdo->prepare('DELETE FROM musabaqa_program_entries WHERE program_id = ? AND event_id = ?')->execute([$programId, $activeEventId]);

                // 7. Delete the program itself
                $pdo->prepare('DELETE FROM musabaqa_programs WHERE id = ? AND event_id = ?')->execute([$programId, $activeEventId]);

                // 8. Recalculate event team totals to undo any team marks contributed by this program
                admin_recalculate_team_totals($pdo, $activeEventId);

                admin_log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), $activeEventId, 'delete_program', 'musabaqa_programs', $programId, 'Deleted program and reset all associated entries, marks, and leaderboard totals.');
            });

            admin_flash('success', 'Program deleted successfully. All associated entries, scores, and team marks have been undone.');
            programs_redirect();
        }

        if ($action === 'save_categories') {
            $names = (array)($_POST['category_name'] ?? []);
            $marks = (array)($_POST['category_marks'] ?? []);
            $rows = [];
            $total = 0.0;

            foreach ($names as $index => $name) {
                $name = trim((string)$name);
                $max = (float)($marks[$index] ?? 0);

                if ($name === '' && $max <= 0) {
                    continue;
                }
                if ($name === '' || $max <= 0) {
                    throw new RuntimeException('Every category needs a name and positive max marks.');
                }

                $total += $max;
                $rows[] = [$name, $max, count($rows) + 1];
            }

            if (!$rows) {
                throw new RuntimeException('Add at least one scoring category.');
            }
            if (abs($total - 100.0) > 0.01) {
                throw new RuntimeException('Category max marks must total 100.');
            }

            admin_db_transaction($pdo, function ($pdo) use ($programId, $activeEventId, $rows) {
                $stmt = $pdo->prepare('SELECT approval_status FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
                $stmt->execute([$programId, $activeEventId]);
                $approvalStatus = $stmt->fetchColumn();

                if ($approvalStatus === false) {
                    throw new RuntimeException('Program not found.');
                }

                if (in_array((string)$approvalStatus, ['submitted', 'approved'], true)) {
                    throw new RuntimeException('Categories are read-only after program submission or approval.');
                }

                $pdo->prepare('DELETE FROM musabaqa_program_categories WHERE program_id = ?')->execute([$programId]);

                $insert = $pdo->prepare('INSERT INTO musabaqa_program_categories (program_id, name, max_marks, sort_order) VALUES (?, ?, ?, ?)');
                foreach ($rows as $row) {
                    $insert->execute([$programId, $row[0], $row[1], $row[2]]);
                }

                $stmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE program_id = ?');
                $stmt->execute([$programId]);
                $scoreSheetIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

                if ($scoreSheetIds) {
                    $placeholders = implode(',', array_fill(0, count($scoreSheetIds), '?'));
                    $pdo->prepare("DELETE FROM musabaqa_category_scores WHERE score_sheet_id IN ($placeholders)")
                        ->execute($scoreSheetIds);

                    $pdo->prepare("
                        UPDATE musabaqa_score_sheets
                        SET judge1_total = 0,
                            judge2_total = 0,
                            final_total = 0,
                            status = 'draft'
                        WHERE program_id = ?
                          AND status NOT IN ('submitted','approved')
                    ")->execute([$programId]);
                }

                admin_recalculate_program_status($pdo, $programId);
                admin_log_activity(
                    $pdo,
                    (int)($_SESSION['user_id'] ?? 0),
                    $activeEventId,
                    'category_update',
                    'musabaqa_program_categories',
                    $programId,
                    'Program scoring categories updated.'
                );
            });

            admin_flash('success', 'Scoring categories saved.');
            programs_redirect();
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $programType = trim((string)($_POST['program_type'] ?? ''));
        $isSpecial = 1;

        $allowedSectionsArr = (array)($_POST['allowed_sections'] ?? []);
        if (!$allowedSectionsArr) {
            throw new RuntimeException('Select at least one allowed section.');
        }
        $allowedSectionsStr = implode(',', array_map('intval', $allowedSectionsArr));
        // Keep class_type_id backward compatible
        $classTypeId = count($allowedSectionsArr) === 1 ? (int)$allowedSectionsArr[0] : null;
        if ($classTypeId !== null && $classTypeId <= 0) {
            $classTypeId = null;
        }
        if ($classTypeId !== null) {
            $chkStmt = $dashboardPdo->prepare("SELECT id FROM class_types WHERE id = ? LIMIT 1");
            $chkStmt->execute([$classTypeId]);
            if (!$chkStmt->fetchColumn()) {
                $classTypeId = null;
            }
        }

        $judgesCount = max(1, min(10, (int)($_POST['judges_count'] ?? 2)));
        $totalMarks = max(1, min(1000, (int)($_POST['total_marks'] ?? 100)));
        $entriesLimit = max(1, min(1000, (int)($_POST['entries_limit'] ?? 10)));
        $redirectToTeam = isset($_POST['redirect_to_team']) ? 1 : 0;
        $disableScores = isset($_POST['disable_scores']) ? 1 : 0;
        $onlyTeamMarks = isset($_POST['only_team_marks']) ? 1 : 0;
        $teamPointsConfig = trim((string)($_POST['team_points_config'] ?? ''));

        if ($title === '' || !in_array($programType, ['individual', 'group'], true)) {
            throw new RuntimeException('Program title and type are required.');
        }

        $respTeacherIdsArr = $_POST['responsible_teacher_ids'] ?? [];
        if (!is_array($respTeacherIdsArr)) {
            $respTeacherIdsArr = [];
        }
        $respTeacherIdsArr = array_filter(array_map('intval', $respTeacherIdsArr));
        $responsibleTeacherIds = $respTeacherIdsArr ? implode(',', $respTeacherIdsArr) : null;
        $responsibleTeacherId = $respTeacherIdsArr ? $respTeacherIdsArr[0] : null;

        $sectionId = isset($_POST['section_id']) && $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
        $firstStageId = (int)$pdo->query('SELECT id FROM musabaqa_stage_types LIMIT 1')->fetchColumn();
        $stageTypeId = isset($_POST['stage_type_id']) && $_POST['stage_type_id'] !== '' ? (int)$_POST['stage_type_id'] : $firstStageId;
        $location = isset($_POST['location']) && $_POST['location'] !== '' ? trim((string)$_POST['location']) : null;

        if ($action === 'update' && $programId > 0) {
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET title = ?, program_type = ?, class_type_id = ?,
                    is_special = ?, judges_count = ?, total_marks = ?,
                    entries_limit = ?, redirect_to_team = ?, disable_scores = ?, 
                    team_points_config = ?, only_team_marks = ?,
                    allowed_sections = ?,
                    responsible_teacher_id = ?, responsible_teacher_ids = ?,
                    section_id = ?, stage_type_id = ?, location = ?
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([
                $title,
                $programType,
                $classTypeId,
                $isSpecial,
                $judgesCount,
                $totalMarks,
                $entriesLimit,
                $redirectToTeam,
                $disableScores,
                $teamPointsConfig !== '' ? $teamPointsConfig : null,
                $onlyTeamMarks,
                $allowedSectionsStr,
                $responsibleTeacherId,
                $responsibleTeacherIds,
                $sectionId,
                $stageTypeId,
                $location,
                $programId,
                $activeEventId
            ]);

            // Recalculate results for this program in case the points configuration was changed
            admin_recalculate_program_results($pdo, $activeEventId, $programId);
            admin_recalculate_team_totals($pdo, $activeEventId);

            admin_flash('success', 'Program updated successfully.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_programs
                    (event_id, title, program_type, class_type_id, status,
                     is_special, judges_count, total_marks, entries_limit, redirect_to_team, disable_scores, 
                     team_points_config, only_team_marks,
                     allowed_sections, responsible_teacher_id, responsible_teacher_ids,
                     section_id, stage_type_id, location)
                VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $activeEventId,
                $title,
                $programType,
                $classTypeId,
                $isSpecial,
                $judgesCount,
                $totalMarks,
                $entriesLimit,
                $redirectToTeam,
                $disableScores,
                $teamPointsConfig !== '' ? $teamPointsConfig : null,
                $onlyTeamMarks,
                $allowedSectionsStr,
                $responsibleTeacherId,
                $responsibleTeacherIds,
                $sectionId,
                $stageTypeId,
                $location
            ]);
            $programId = (int)$pdo->lastInsertId();

            $defaultCats = admin_get_default_categories_for_program_title($title);
            $catStmt = $pdo->prepare("INSERT INTO musabaqa_program_categories (program_id, name, max_marks, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($defaultCats as $cat) {
                $catStmt->execute([$programId, $cat['name'], $cat['max_marks'], $cat['sort_order']]);
            }

            admin_flash('success', 'Program created successfully. Multi-criteria scoring categories initialized.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to save program.');
    }

    programs_redirect();
}

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
$classFilter = trim((string)($_GET['class'] ?? 'all'));

$classTypes = $dashboardPdo->query('SELECT id, name FROM class_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$stageTypes = $pdo->query('SELECT id, name, category FROM musabaqa_stage_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$where = 'WHERE mp.event_id = ?';
$params = [$activeEventId];

if ($search !== '') {
    $where .= ' AND (mp.title LIKE ? OR mp.location LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'scoring', 'completed'], true)) {
    $where .= ' AND mp.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== 'all' && in_array($typeFilter, ['individual', 'group'], true)) {
    $where .= ' AND mp.program_type = ?';
    $params[] = $typeFilter;
}
[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'mp');
$where .= $classSql;
array_push($params, ...$classParams);

$teachers = $dashboardPdo->query("SELECT id, full_name FROM teachers WHERE status = 'active' ORDER BY full_name ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$teachersMap = [];
foreach ($teachers as $teacherRow) {
    $teachersMap[(int)$teacherRow['id']] = $teacherRow['full_name'];
}

$stmt = $pdo->prepare("
    SELECT
        mp.*,
        mst.name AS stage_type_name,
        mst.category AS stage_category,
        ct.name AS class_type_name,
        t.full_name AS responsible_teacher_name,
        mss.name AS schedule_section_name,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT ss.id) AS score_sheet_count,
        COUNT(DISTINCT pc.id) AS category_count
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = mp.responsible_teacher_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = mp.section_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = mp.id AND pe.event_id = mp.event_id
    LEFT JOIN musabaqa_score_sheets ss ON ss.program_id = mp.id
    LEFT JOIN musabaqa_program_categories pc ON pc.program_id = mp.id
    {$where}
    GROUP BY mp.id, mst.id, ct.id, t.id, mss.id
    ORDER BY COALESCE(mp.start_time, '9999-12-31') ASC, mp.id DESC
");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$latestProgram = null;
if ($programs) {
    // Find the program with the latest end_time
    foreach ($programs as $program) {
        if ($program['end_time']) {
            if (!$latestProgram || $program['end_time'] > $latestProgram['end_time']) {
                $latestProgram = $program;
            }
        }
    }
}

$categoryRows = [];
if ($programs) {
    $ids = array_map('intval', array_column($programs, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, program_id, name, max_marks, sort_order
        FROM musabaqa_program_categories
        WHERE program_id IN ($placeholders)
        ORDER BY program_id ASC, sort_order ASC, id ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $category) {
        $categoryRows[(int)$category['program_id']][] = $category;
    }
}

// Get the latest program for auto-filling start time
$latestProgramData = null;
if ($latestProgram) {
    $latestProgramData = [
        'end_time' => $latestProgram['end_time'],
        'stage_type_id' => $latestProgram['stage_type_id'],
        'location' => $latestProgram['location']
    ];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title" style="display: flex; align-items: center; gap: 8px;">
                Programs
                <span class="badge badge-neutral" style="font-size: 14px; font-weight: 600; padding: 2px 8px; border-radius: 6px; vertical-align: middle;"><?= count($programs) ?></span>
            </div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?> timetable and scoring categories</div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <button class="btn btn-secondary btn-md" type="button" onclick="openModal('stagesModal')"><i class="fa-solid fa-layer-group"></i> Stage Management</button>
            <button class="btn btn-success btn-md" data-open-program><i class="fa-solid fa-plus"></i> Add Program</button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Title or location">
            </div>
            <div class="input-group">
                <label>Type</label>
                <select name="type">
                    <option value="all">All Types</option>
                    <option value="individual" <?= $typeFilter === 'individual' ? 'selected' : '' ?>>Individual</option>
                    <option value="group" <?= $typeFilter === 'group' ? 'selected' : '' ?>>Group</option>
                </select>
            </div>
            <div class="input-group">
                <label>Status</label>
                <select name="status">
                    <option value="all">All Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="scoring" <?= $statusFilter === 'scoring' ? 'selected' : '' ?>>Scoring</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <div class="input-group">
                <label>Class</label>
                <select name="class">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($search !== '' || $typeFilter !== 'all' || $statusFilter !== 'all' || $classFilter !== 'all'): ?>
                    <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/event-manager/programs.php') ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div id="programsTableContainer">
    <?php if (!$programs): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-layer-group"></i></div>
            <div class="empty-title">No Programs Found</div>
            <div class="empty-subtitle">Create the first program for this event.</div>
        </div>
    <?php else: ?>
        <?php
        $classTypesMap = [];
        foreach ($classTypes as $type) {
            $classTypesMap[(int)$type['id']] = $type['name'];
        }

        $tierNames = [
            'subjunior' => 'Sub Junior Division',
            'junior'    => 'Junior Division',
            'senior'    => 'Senior Division',
            'general'   => 'General / Open Division'
        ];

        $panels = [];

        // 1. Grouped Programs (Single Panel)
        $panels['group'] = [
            'title' => 'Group Programs',
            'icon'  => 'fa-people-group',
            'color' => '#818cf8',
            'programs' => []
        ];

        // 2. Off-Stage Programs (Single Panel)
        $panels['offstage'] = [
            'title' => 'Off-Stage Programs',
            'icon'  => 'fa-pen-ruler',
            'color' => '#f59e0b',
            'programs' => []
        ];

        // 3. Individual Normal Stage Programs (Separated by Division)
        foreach ($tierNames as $tierKey => $tierLabel) {
            $panels['normal_' . $tierKey] = [
                'title' => "{$tierLabel} (Normal Stage)",
                'icon'  => 'fa-layer-group',
                'color' => '#34d399',
                'programs' => []
            ];
        }

        foreach ($programs as $program) {
            $isGroup = strtolower((string)$program['program_type']) === 'group';
            $isOffStage = ($program['stage_category'] ?? '') === 'off_stage';

            if ($isGroup) {
                $panels['group']['programs'][] = $program;
                continue;
            }

            if ($isOffStage) {
                $panels['offstage']['programs'][] = $program;
                continue;
            }

            $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
            $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;

            if ($allowedCount > 1 || !$classTier) {
                $tierKey = 'general';
            } else {
                $tierKey = $classTier;
            }

            $panels['normal_' . $tierKey]['programs'][] = $program;
        }
        ?>

        <?php foreach ($panels as $panelKey => $panel): ?>
            <?php $tierPrograms = $panel['programs']; ?>
            <?php if (!$tierPrograms) continue; ?>

            <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.04); border-radius: 12px; overflow: hidden; padding: 0;">
                <div style="background: rgba(255,255,255,0.015); padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.04); display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 15px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid <?= $panel['icon'] ?>" style="color: <?= $panel['color'] ?>;"></i>
                        <?= e($panel['title']) ?>
                    </h3>
                    <span style="font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 99px; background: rgba(255,255,255,0.04); color: var(--muted); border: 1px solid rgba(255,255,255,0.02);">
                        <?= count($tierPrograms) ?> <?= count($tierPrograms) === 1 ? 'Program' : 'Programs' ?>
                    </span>
                </div>
                <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Type</th>
                                <th>Stage</th>
                                <th>Section</th>
                                <th>Teacher Incharge</th>
                                <th>Entries</th>
                                <th>Categories</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tierPrograms as $program): ?>
                                <?php $programCategories = $categoryRows[(int)$program['id']] ?? []; ?>
                                <tr>
                                    <td>
                                        <strong><?= e($program['title']) ?></strong>
                                        <div class="muted"><?= ($program['stage_category'] ?? '') === 'off_stage' ? 'Off Stage' : 'On Stage' ?></div>
                                    </td>
                                    <td><span class="badge badge-neutral"><?= e(ucfirst($program['program_type'])) ?></span></td>
                                    <td>
                                        <?= e($program['stage_type_name'] ?: '-') ?>
                                        <?php if (!empty($program['schedule_section_name'])): ?>
                                            <div class="muted" style="font-size: 11.5px; margin-top: 2.5px;">
                                                <i class="fa-solid fa-clock" style="margin-right: 4px; color: var(--accent);"></i> <?= e($program['schedule_section_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
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
                                        ?>
                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                            <?= e($sectionDisplay) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $tNames = [];
                                        if (!empty($program['responsible_teacher_ids'])) {
                                            $tIds = array_filter(array_map('intval', explode(',', $program['responsible_teacher_ids'])));
                                            foreach ($tIds as $tid) {
                                                if (isset($teachersMap[$tid])) {
                                                    $tNames[] = $teachersMap[$tid];
                                                }
                                            }
                                        } elseif (!empty($program['responsible_teacher_id'])) {
                                            if (isset($teachersMap[(int)$program['responsible_teacher_id']])) {
                                                $tNames[] = $teachersMap[(int)$program['responsible_teacher_id']];
                                            }
                                        }
                                        echo e(implode(', ', $tNames) ?: '-');
                                        ?>
                                    </td>
                                    <td><?= (int)$program['entry_count'] ?></td>
                                    <td><?= (int)$program['category_count'] ?></td>
                                    <td>
                                        <div class="flex gap-2 flex-wrap">
                                            <a href="<?= app_url('/admin/registrar/entries.php?program=' . (int)$program['id']) ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list-check"></i> Entries</a>
                                            <a href="<?= app_url('/admin/score-entry/program-scores.php?program_id=' . (int)$program['id']) ?>" class="btn btn-success btn-sm"><i class="fa-solid fa-pen-to-square"></i> Score</a>
                                            <button class="btn btn-secondary btn-sm" data-edit-program='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i></button>
                                            <button class="btn btn-info btn-sm" data-categories='<?= e(json_encode(['program' => $program, 'categories' => $programCategories], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' <?= in_array((string)$program['approval_status'], ['submitted', 'approved'], true) ? 'disabled' : '' ?>><i class="fa-solid fa-sliders"></i> Categories</button>
                                            <button class="btn btn-secondary btn-sm" data-copy-categories='<?= e(json_encode(['title' => $program['title'], 'categories' => $programCategories], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Copy Categories"><i class="fa-solid fa-copy"></i></button>
                                            <button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$program['id'] ?>" data-delete-name="<?= e($program['title']) ?>"><i class="fa-solid fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<!-- STAGE MANAGEMENT MODAL -->
<div class="modal-overlay" id="stagesModal">
    <div class="modal-box" style="max-height: calc(100vh - 60px); display: flex; flex-direction: column; overflow: hidden; padding: 24px; width: 480px; background: #0b0f19; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); position: relative;">
        
        <!-- Header -->
        <div class="modal-header" style="flex-shrink: 0; margin-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
            <div class="modal-title" style="font-size: 16px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px;">
                <div style="background: rgba(20, 184, 166, 0.1); color: #14b8a6; padding: 8px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-layer-group" style="font-size: 16px;"></i>
                </div>
                Stage Management
            </div>
            <button class="modal-close" type="button" data-close="stagesModal" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); color: #94a3b8; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <!-- Content List -->
        <div style="flex: 1; overflow-y: auto; padding-right: 4px; display: flex; flex-direction: column; gap: 20px;">
            
            <!-- Category: On Stage -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid rgba(16, 185, 129, 0.15); padding-bottom: 6px;">
                    <h4 style="font-size: 13px; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <i class="fa-solid fa-microphone"></i> On Stage (Normal Stage)
                    </h4>
                    <span style="font-size: 11px; background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 2px 8px; border-radius: 99px; font-weight: 700;">
                        <?= count(array_filter($stageTypes, fn($s) => ($s['category'] ?? 'on_stage') === 'on_stage')) ?>
                    </span>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php
                    $onStages = array_filter($stageTypes, fn($s) => ($s['category'] ?? 'on_stage') === 'on_stage');
                    if (empty($onStages)):
                    ?>
                        <div style="font-size: 12px; color: #64748b; font-style: italic; text-align: center; padding: 12px; border: 1px dashed rgba(255,255,255,0.05); border-radius: 8px;">No on-stage venues configured.</div>
                    <?php
                    else:
                        foreach ($onStages as $st):
                    ?>
                        <div class="stage-item-row" style="display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.02); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); transition: all 0.2s;">
                            <span style="font-weight: 600; color: #f1f5f9; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                                <?= e($st['name']) ?>
                            </span>
                            <button type="button" onclick="confirmDeleteStage(<?= $st['id'] ?>)" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.1); color: #ef4444; cursor: pointer; padding: 6px; border-radius: 6px; transition: all 0.2s; font-size: 12px;" title="Delete Stage" onmouseover="this.style.background='rgba(239, 68, 68, 0.15)'" onmouseout="this.style.background='rgba(239, 68, 68, 0.05)'"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
            </div>

            <!-- Category: Off Stage -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid rgba(245, 158, 11, 0.15); padding-bottom: 6px;">
                    <h4 style="font-size: 13px; font-weight: 700; color: #f59e0b; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <i class="fa-solid fa-door-closed"></i> Off Stage
                    </h4>
                    <span style="font-size: 11px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 2px 8px; border-radius: 99px; font-weight: 700;">
                        <?= count(array_filter($stageTypes, fn($s) => ($s['category'] ?? 'on_stage') === 'off_stage')) ?>
                    </span>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php
                    $offStages = array_filter($stageTypes, fn($s) => ($s['category'] ?? 'on_stage') === 'off_stage');
                    if (empty($offStages)):
                    ?>
                        <div style="font-size: 12px; color: #64748b; font-style: italic; text-align: center; padding: 12px; border: 1px dashed rgba(255,255,255,0.05); border-radius: 8px;">No off-stage venues configured.</div>
                    <?php
                    else:
                        foreach ($offStages as $st):
                    ?>
                        <div class="stage-item-row" style="display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.02); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); transition: all 0.2s;">
                            <span style="font-weight: 600; color: #f1f5f9; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #f59e0b; display: inline-block;"></span>
                                <?= e($st['name']) ?>
                            </span>
                            <button type="button" onclick="confirmDeleteStage(<?= $st['id'] ?>)" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.1); color: #ef4444; cursor: pointer; padding: 6px; border-radius: 6px; transition: all 0.2s; font-size: 12px;" title="Delete Stage" onmouseover="this.style.background='rgba(239, 68, 68, 0.15)'" onmouseout="this.style.background='rgba(239, 68, 68, 0.05)'"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
            </div>
            
        </div>

        <!-- Add New Stage Action Button -->
        <div style="flex-shrink: 0; margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 16px;">
            <button type="button" class="btn btn-success" onclick="openModal('addStageModal')" style="width: 100%; padding: 10px; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15); transition: all 0.2s;"><i class="fa-solid fa-plus"></i> Add New Stage</button>
        </div>

    </div>
</div>

<!-- ADD STAGE MODAL (MODAL-IN-MODAL) -->
<div class="modal-overlay" id="addStageModal" style="z-index: 1085;">
    <div class="modal-box" style="width: 420px; padding: 24px; background: #0b0f19; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);">
        <div class="modal-header" style="flex-shrink: 0; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
            <div class="modal-title" style="font-size: 15px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-plus-circle" style="color: #14b8a6;"></i> Add New Stage
            </div>
            <button class="modal-close" type="button" onclick="closeModal('addStageModal')" style="background: none; border: none; color: #94a3b8; font-size: 16px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" style="margin: 0; display: flex; flex-direction: column; gap: 16px;">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="manage_stages">
            <input type="hidden" name="stage_action" value="create">
            
            <div style="display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 11px; font-weight: 600; color: #94a3b8;">Stage / Venue Name</label>
                <input type="text" name="stage_name" placeholder="e.g. Stage 3, Masjid, Classroom B" required style="width: 100%; padding: 10px 12px; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; color: #fff; font-size: 13px; outline: none; transition: all 0.2s;" onfocus="this.style.borderColor='#14b8a6'; this.style.boxShadow='0 0 0 2px rgba(20, 184, 166, 0.15)'" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none'">
            </div>

            <div style="display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 11px; font-weight: 600; color: #94a3b8;">Stage Category</label>
                <select name="stage_category" style="width: 100%; padding: 10px 12px; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; color: #fff; font-size: 13px; outline: none; transition: all 0.2s;" onfocus="this.style.borderColor='#14b8a6'" onblur="this.style.borderColor='rgba(255,255,255,0.12)'">
                    <option value="on_stage">On Stage (Normal Stage)</option>
                    <option value="off_stage">Off Stage</option>
                </select>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 8px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addStageModal')" style="padding: 9px 16px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                <button type="submit" class="btn btn-success" style="padding: 9px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);">Create Stage</button>
            </div>
        </form>
    </div>
</div>v>

<!-- DELETE STAGE CONFIRMATION MODAL -->
<div class="modal-overlay" id="deleteStageConfirmModal" style="z-index: 1100;">
    <div class="modal-box" style="width: 400px; padding: 24px; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);">
        <div style="font-size: 40px; color: #ef4444; margin-bottom: 16px;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <h4 style="font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 8px;">Confirm Stage Deletion</h4>
        <p style="font-size: 13px; color: #94a3b8; line-height: 1.5; margin-bottom: 24px;">
            Are you sure you want to delete this stage? This will remove the stage/venue name from all programs using it.
        </p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('deleteStageConfirmModal')" style="padding: 8px 16px; font-size: 13px; font-weight: 600;">Cancel</button>
            <form method="POST" style="margin: 0;">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="manage_stages">
                <input type="hidden" name="stage_action" value="delete">
                <input type="hidden" name="stage_id" id="deleteStageConfirmId">
                <button type="submit" class="btn btn-danger" style="padding: 8px 16px; font-size: 13px; font-weight: 600; background: #ef4444; border: none; color: #fff;">Yes, Delete</button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="programModal">
    <div class="modal-box modal-lg" style="max-height: calc(100vh - 50px); display: flex; flex-direction: column; overflow: hidden; padding: 20px;">
        <div class="modal-header" style="flex-shrink: 0; margin-bottom: 14px;">
            <div class="modal-title" id="programModalTitle">Add Program</div>
            <button class="modal-close" type="button" data-close="programModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="programForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="programAction" value="create">
            <input type="hidden" name="program_id" id="programId">
            
            <div style="flex: 1; overflow-y: auto; padding-right: 6px; padding-bottom: 8px;">
                <div class="form-grid">
                    <div class="input-group">
                        <label>Program Title <span class="required">*</span></label>
                        <input type="text" name="title" id="programTitle" required>
                    </div>
                    <div class="input-group">
                        <label>Program Type <span class="required">*</span></label>
                        <select name="program_type" id="programType" required>
                            <option value="">Select Type</option>
                            <option value="individual">Individual</option>
                            <option value="group">Group</option>
                        </select>
                    </div>
                    <div class="input-group full-width" style="grid-column: span 2;">
                        <label>Schedule Section (Timing Group)</label>
                        <select name="section_id" id="programSectionId">
                            <option value="">-- No Section (Auto-detect by Timing) --</option>
                            <?php foreach ($scheduleSections as $sec): ?>
                                <option value="<?= (int)$sec['id'] ?>"><?= e($sec['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-help" style="margin-top: 4px;">Assign to group programs into Morning, Evening, or Night slots. If set to Auto-detect, the TV display will place it based on program timing.</div>
                    </div>

                    <?php
                    $firstStageId = (int)$pdo->query('SELECT id FROM musabaqa_stage_types LIMIT 1')->fetchColumn();
                    $defaultStageId = $latestProgramData ? $latestProgramData['stage_type_id'] : $firstStageId;
                    $defaultLocation = $latestProgramData ? $latestProgramData['location'] : '';
                    ?>

                    <div class="input-group">
                        <label>Stage Type <span class="required">*</span></label>
                        <select id="programStageTypeFilter" required style="width: 100%;">
                            <option value="on_stage">On Stage (Normal Stage)</option>
                            <option value="off_stage">Off Stage</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Specific Venue / Stage <span class="required">*</span></label>
                        <select name="stage_type_id" id="programStageTypeId" required style="width: 100%;">
                            <option value="">-- Select Venue --</option>
                            <?php foreach ($stageTypes as $st): ?>
                                <option value="<?= (int)$st['id'] ?>" data-category="<?= e($st['category'] ?? 'on_stage') ?>" data-name="<?= e($st['name']) ?>"><?= e($st['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="location" id="programLocation">
                    </div>

                    <div class="input-group full-width" style="grid-column: span 2;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block; color: var(--muted);">Allowed Sections (Class Types) <span class="required">*</span></label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px;">
                            <?php 
                            $activeSections = $settings['active_sections'] ?? [];
                            $allSectionsActive = empty($activeSections);
                            foreach ($classTypes as $type): 
                                $classTypeId = (int)$type['id'];
                                if (!$allSectionsActive && !in_array($classTypeId, $activeSections, true)) {
                                    continue;
                                }
                                ?>
                                <label class="section-toggle-card">
                                    <input type="checkbox" name="allowed_sections[]" value="<?= $classTypeId ?>" class="allowed-section-chk">
                                    <div class="card-inner">
                                        <i class="fa-solid fa-circle-check check-icon"></i>
                                        <span><?= e(admin_class_type_display($type['name'] ?? null, $classTypeId)) ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="input-group full-width" style="grid-column: span 2;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block; color: var(--muted);">Responsible Teachers (Incharges)</label>
                        <div style="display: flex; align-items: center; gap: 15px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 10px 14px; border-radius: 10px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('teacherSelectModal')">
                                <i class="fa-solid fa-user-plus"></i> Select Teachers
                            </button>
                            <span id="selectedTeachersSummary" style="font-size: 13px; color: var(--muted); font-weight: 500;">No teachers selected</span>
                        </div>
                    </div>
                    
                    <div id="specialFields" style="border-top: 1px solid var(--border); padding-top: 15px; margin-top: 15px; grid-column: span 2; width: 100%;">
                        <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--accent, #14b8a6);"><i class="fa-solid fa-gear"></i> Customization & Scoring Rules</h4>
                        <div class="form-grid-3" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="input-group">
                                <label>Judges Count</label>
                                <input type="number" name="judges_count" id="judgesCount" min="1" max="10" value="2">
                            </div>
                            <div class="input-group">
                                <label>Total Marks (per Judge)</label>
                                <input type="number" name="total_marks" id="totalMarks" min="1" max="1000" value="100">
                            </div>
                            <div class="input-group">
                                <label>Entries Limit</label>
                                <input type="number" name="entries_limit" id="entriesLimit" min="1" max="1000" value="10">
                            </div>
                        </div>
                        <div style="display: grid; gap: 12px; margin-top: 15px;">
                            <div id="rowDisableScores" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 10px 14px; border-radius: 10px;">
                                <div>
                                    <strong style="font-size: 13.5px; display: block; color: var(--text);">Disable Scores</strong>
                                    <span style="font-size: 11.5px; color: var(--muted);">Disable/hide scores (useful for semi-finales/hiding)</span>
                                </div>
                                <label class="toggle-switch" style="position: relative; display: inline-block;">
                                    <input type="checkbox" name="disable_scores" id="disableScores" value="1">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div id="rowRedirectToTeam" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 10px 14px; border-radius: 10px;">
                                <div>
                                    <strong style="font-size: 13.5px; display: block; color: var(--text);">Redirect to Team Total</strong>
                                    <span style="font-size: 11.5px; color: var(--muted);">Redirect participants' scores to team total points</span>
                                </div>
                                <label class="toggle-switch" style="position: relative; display: inline-block;">
                                    <input type="checkbox" name="redirect_to_team" id="redirectToTeam" value="1" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div id="rowOnlyTeamMarks" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 10px 14px; border-radius: 10px;">
                                <div>
                                    <strong style="font-size: 13.5px; display: block; color: var(--text);">Only Team Marks (No Individual Marks)</strong>
                                    <span style="font-size: 11.5px; color: var(--muted);">Award team placement points only, skip student individual score calculation</span>
                                </div>
                                <label class="toggle-switch" style="position: relative; display: inline-block;">
                                    <input type="checkbox" name="only_team_marks" id="onlyTeamMarks" value="1">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <!-- Rank configurations -->
                            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 14px; border-radius: 10px;">
                                <strong style="font-size: 13.5px; display: block; color: var(--text); margin-bottom: 4px;">Placement Team Points Configuration</strong>
                                <span style="font-size: 11.5px; color: var(--muted); display: block; margin-bottom: 12px;">Define team points awarded for each placing rank.</span>
                                
                                <input type="hidden" name="team_points_config" id="teamPointsConfigInput">
                                <div id="ranksContainer" style="display: grid; gap: 8px;">
                                    <!-- Ranks dynamic rows go here -->
                                </div>
                                
                                <button type="button" class="btn btn-secondary btn-xs mt-3" id="addRankBtn" style="padding: 6px 12px; font-size:11.5px;">
                                    <i class="fa-solid fa-plus mr-1"></i> Add Rank Position
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-actions" style="flex-shrink: 0; padding-top: 14px; margin-top: 12px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary btn-md" data-close="programModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit">Save Program</button>
            </div>

        </form>
    </div>
</div>

<!-- TEACHER SELECT SUB-MODAL (INDEPENDENT STANDALONE MODAL OVERLAY) -->
<div class="modal-overlay" id="teacherSelectModal" style="z-index: 100005;">
    <div class="modal-box modal-md" style="max-width: 500px; width: 95%; border-radius: 14px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); background: #0e1726; box-shadow: 0 25px 50px rgba(0,0,0,0.6);">
        <div class="modal-header" style="padding: 16px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; justify-content: space-between;">
            <div class="modal-title" style="font-size: 16px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-check" style="color: #6366f1;"></i>
                <span>Select Incharge Teachers</span>
            </div>
            <button class="modal-close" type="button" data-close="teacherSelectModal" style="background: none; border: none; color: var(--muted); font-size: 16px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <div style="display: flex; flex-direction: column; gap: 10px; max-height: 320px; overflow-y: auto; padding-right: 5px;">
                <?php if (empty($teachers)): ?>
                    <div style="color: var(--muted); font-size: 13px; text-align: center; padding: 20px 0;">No active teachers found.</div>
                <?php else: ?>
                    <?php foreach ($teachers as $teacher): ?>
                        <label class="section-toggle-card" style="display: flex; width: 100%; cursor: pointer;">
                            <input type="checkbox" form="programForm" name="responsible_teacher_ids[]" value="<?= (int)$teacher['id'] ?>" class="responsible-teacher-chk" onchange="updateSelectedTeachersDisplay()">
                            <div class="card-inner" style="display: flex; width: 100%; justify-content: flex-start; box-sizing: border-box; gap: 10px; align-items: center;">
                                <i class="fa-solid fa-circle-check check-icon"></i>
                                <span class="teacher-name-span" style="font-weight: 600; font-size: 13.5px;"><?= e($teacher['full_name']) ?></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer" style="padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.015); display: flex; justify-content: flex-end; gap: 10px;">
            <button class="btn btn-primary btn-md" type="button" data-close="teacherSelectModal" style="padding: 8px 20px; font-size: 13px; font-weight: 600; border-radius: 8px;">Done</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="categoryModal">
    <div class="modal-box" style="max-width: 560px; width: 95%; padding: 0; border-radius: 14px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); background: #0e1726; box-shadow: 0 20px 45px rgba(0,0,0,0.5);">
        <div class="modal-header" style="padding: 16px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
            <div>
                <div class="modal-title" style="font-size: 16px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-sliders" style="color: #34d399;"></i>
                    <span>Scoring Categories</span>
                </div>
                <div id="categoryModalSubTitle" style="font-size: 12px; color: var(--muted, #94a3b8); margin-top: 2px;">Configure breakdown criteria for judge scoring</div>
            </div>
            
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn btn-secondary btn-xs" id="btnCopyCategories" title="Copy categories to clipboard" style="padding: 5px 11px; font-size: 12px; border-radius: 6px; font-weight: 600;">
                    <i class="fa-solid fa-copy" style="margin-right: 4px;"></i> Copy
                </button>
                <button type="button" class="btn btn-secondary btn-xs" id="btnPasteCategories" title="Paste categories from clipboard or buffer" style="padding: 5px 11px; font-size: 12px; border-radius: 6px; font-weight: 600;">
                    <i class="fa-solid fa-paste" style="margin-right: 4px;"></i> Paste
                </button>
                <button class="modal-close" type="button" data-close="categoryModal" style="background: none; border: none; color: var(--muted); font-size: 16px; cursor: pointer; margin-left: 4px;"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <form method="POST" id="categoryForm" style="padding: 20px; display: flex; flex-direction: column; max-height: calc(100dvh - 120px); overflow-y: auto;">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_categories">
            <input type="hidden" name="program_id" id="categoryProgramId">
            
            <!-- Quick Options / Presets Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); flex-wrap: wrap;">
                <div style="font-size: 11.5px; font-weight: 600; color: #cbd5e1; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #a855f7;"></i> Quick Presets:
                </div>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <select id="presetCategoriesSelect" style="height: 30px; font-size: 11.5px; padding: 2px 8px; background: #1e293b; border: 1px solid #334155; border-radius: 6px; color: #fff; cursor: pointer;">
                        <option value="">Select Preset...</option>
                        <option value="speech">Standard Speech (Pitch 30 / Tajweed 30 / Voice 40)</option>
                        <option value="qiraat">Qira'at (Tajweed 50 / Voice 30 / Style 20)</option>
                        <option value="recitation">Recitation (Fluency 40 / Pronunciation 30 / Memory 30)</option>
                        <option value="quiz">Quiz (Accuracy 60 / Speed 20 / Rules 20)</option>
                        <option value="single">Single Category (Total 100)</option>
                    </select>
                    <button type="button" class="btn btn-secondary btn-xs" id="btnToggleRawPaste" style="height: 30px; font-size: 11px; padding: 0 8px; border-radius: 6px;" title="Open bulk text paste box">
                        <i class="fa-solid fa-code mr-1"></i> Bulk Text
                    </button>
                </div>
            </div>

            <!-- Bulk Raw Text Paste Box (Hidden by default) -->
            <div id="rawPasteContainer" style="display: none; margin-bottom: 14px; background: rgba(15, 23, 42, 0.9); padding: 12px; border-radius: 8px; border: 1px dashed rgba(52, 211, 153, 0.4);">
                <label style="font-size: 11px; font-weight: 700; color: #34d399; display: block; margin-bottom: 4px;">
                    Paste Categories (JSON or line format like "Pitch 30", "Tajweed 30", "Voice 40"):
                </label>
                <textarea id="rawCategoriesTextarea" rows="3" class="form-input" placeholder="Pitch 30&#10;Tajweed 30&#10;Voice 40" style="width: 100%; font-family: monospace; font-size: 12px; padding: 8px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff;"></textarea>
                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px;">
                    <button type="button" class="btn btn-secondary btn-xs" id="btnCancelRawPaste">Cancel</button>
                    <button type="button" class="btn btn-primary btn-xs" id="btnApplyRawPaste"><i class="fa-solid fa-check mr-1"></i> Parse & Apply</button>
                </div>
            </div>

            <!-- Compact Table Header -->
            <div style="display: grid; grid-template-columns: 1fr 100px 34px; gap: 10px; padding: 0 6px 8px 6px; border-bottom: 1px solid rgba(255,255,255,0.06); margin-bottom: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted, #94a3b8);">
                <span>Category Name</span>
                <span style="text-align: right;">Max Marks</span>
                <span></span>
            </div>

            <!-- Dynamic Category List -->
            <div id="categoryRows" style="display: flex; flex-direction: column; gap: 8px; max-height: 280px; overflow-y: auto; padding-right: 4px;"></div>
            
            <!-- Controls & Total Summary -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06);">
                <button type="button" class="btn btn-secondary btn-sm" id="addCategoryRow" style="font-size: 12px; padding: 6px 12px; border-radius: 8px;">
                    <i class="fa-solid fa-plus" style="margin-right: 4px;"></i> Add Category
                </button>
                <div id="categoryTotalBadge" style="font-size: 12.5px; font-weight: 700; padding: 5px 14px; border-radius: 20px; transition: all 0.2s ease;">
                    Total: <span id="categoryTotal">0</span> / 100
                </div>
            </div>

            <div class="form-actions" style="margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary btn-md" data-close="categoryModal" style="padding: 8px 16px; font-size: 13px;">Cancel</button>
                <button class="btn btn-success btn-md" type="submit" id="saveCategoriesBtn" style="padding: 8px 18px; font-size: 13px; font-weight: 600;"><i class="fa-solid fa-check" style="margin-right: 4px;"></i> Save Categories</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div class="modal-title">Delete Program</div>
            <button class="modal-close" type="button" data-close="deleteModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="panel">
            <p style="font-size: 15px; margin-bottom: 12px;">Are you sure you want to delete <strong id="deleteName"></strong>?</p>
            <div class="alert alert-warning" style="margin: 0; font-size: 13px; display: flex; gap: 8px; align-items: flex-start;">
                <i class="fa-solid fa-triangle-exclamation" style="margin-top: 2px;"></i>
                <div>
                    <strong>Warning:</strong> Deleting this program will permanently remove all associated student entries, judge scores, and undo any team or student marks earned from this program.
                </div>
            </div>
        </div>
        <form method="POST" id="deleteForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="program_id" id="deleteId">
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-close="deleteModal">Cancel</button>
                <button class="btn btn-danger btn-md" type="submit"><i class="fa-solid fa-trash"></i> Delete & Undo Marks</button>
            </div>
        </form>
    </div>
</div>

<script>function openModal(id) {
    if (typeof window.openModal === 'function') {
        window.openModal(id);
    } else {
        const modal = document.getElementById(id);
        if (modal) modal.classList.add('active');
    }
}

function closeModal(id) {
    if (typeof window.closeModal === 'function') {
        window.closeModal(id);
    } else {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('active');
    }
}

window.GLOBAL_DEFAULT_POINTS = {
    1: <?= (int)($settings['first_place_points'] ?? 10) ?>,
    2: <?= (int)($settings['second_place_points'] ?? 7) ?>,
    3: <?= (int)($settings['third_place_points'] ?? 5) ?>
};
window.GLOBAL_DEFAULT_JUDGES = <?= (int)($settings['default_judges_count'] ?? 2) ?>;

function toLocalDatetime(value){return value ? String(value).replace(' ', 'T').slice(0,16) : ''}
function escapeHtml(value){return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}

function renderRanks(config) {
    const container = document.getElementById('ranksContainer');
    if (!container) return;
    container.innerHTML = '';
    
    const entries = Object.entries(config || {});
    if (entries.length === 0) {
        config = window.GLOBAL_DEFAULT_POINTS;
    }
    
    Object.entries(config).forEach(([rank, points]) => {
        addRankRow(parseInt(rank, 10), parseInt(points, 10));
    });
    reindexRanks();
}

function addRankRow(rank, points = 0) {
    const container = document.getElementById('ranksContainer');
    if (!container) return;
    const row = document.createElement('div');
    row.className = 'rank-row';
    row.style.cssText = 'display: flex; gap: 10px; align-items: center; margin-bottom: 4px;';
    
    row.innerHTML = `
        <span style="font-size: 13px; min-width: 80px; font-weight:600; color:var(--muted);">Rank <span class="rank-index">${rank}</span>:</span>
        <input type="number" class="form-input rank-points-input" min="0" value="${points}" placeholder="Points" style="width: 100px; height: 32px; font-size: 13px;" required>
        <button type="button" class="btn btn-danger btn-xs remove-rank-btn" style="padding: 4px 8px; height: 32px;" title="Remove Rank">
            <i class="fa-solid fa-trash"></i>
        </button>
    `;
    container.appendChild(row);
    
    row.querySelector('.remove-rank-btn')?.addEventListener('click', () => {
        row.remove();
        reindexRanks();
    });
}

function reindexRanks() {
    document.querySelectorAll('#ranksContainer .rank-row').forEach((row, idx) => {
        const rankIndexSpan = row.querySelector('.rank-index');
        if (rankIndexSpan) {
            rankIndexSpan.textContent = String(idx + 1) + getRankSuffix(idx + 1);
        }
    });
}

function getRankSuffix(rank) {
    if (rank === 1) return 'st';
    if (rank === 2) return 'nd';
    if (rank === 3) return 'rd';
    return 'th';
}

function formatLocalDatetime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function updateSelectedTeachersDisplay() {
    const selected = [];
    document.querySelectorAll('.responsible-teacher-chk:checked').forEach(chk => {
        const name = chk.closest('.section-toggle-card').querySelector('.teacher-name-span').textContent;
        selected.push(name);
    });
    const summary = document.getElementById('selectedTeachersSummary');
    if (summary) {
        if (selected.length === 0) {
            summary.textContent = 'No teachers selected';
            summary.style.color = 'var(--muted)';
        } else {
            summary.textContent = selected.join(', ');
            summary.style.color = '#fff';
        }
    }
}

// Function to sync Specific Venue / Stage dropdown based on selected Category filter
function syncVenues(category, selectedVal = '') {
    const stageSelect = document.getElementById('programStageTypeId');
    if (!stageSelect) return;
    
    // Store all options on first call
    if (!window.ALL_STAGE_OPTIONS) {
        window.ALL_STAGE_OPTIONS = Array.from(stageSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            category: opt.getAttribute('data-category'),
            name: opt.getAttribute('data-name')
        }));
    }
    
    // Clear and rebuild options list
    stageSelect.innerHTML = '<option value="">-- Select Venue --</option>';
    
    const filtered = window.ALL_STAGE_OPTIONS.filter(opt => opt.value === '' || opt.category === category);
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

document.addEventListener('DOMContentLoaded', () => {
    const filterSelect = document.getElementById('programStageTypeFilter');
    const stageSelect = document.getElementById('programStageTypeId');
    const locationInput = document.getElementById('programLocation');

    if (filterSelect && stageSelect) {
        // Cache initial options
        if (!window.ALL_STAGE_OPTIONS) {
            window.ALL_STAGE_OPTIONS = Array.from(stageSelect.options).map(opt => ({
                value: opt.value,
                text: opt.text,
                category: opt.getAttribute('data-category'),
                name: opt.getAttribute('data-name')
            }));
        }

        // When Stage Type changes, filter specific venues
        filterSelect.addEventListener('change', () => {
            syncVenues(filterSelect.value);
            if (locationInput) {
                locationInput.value = stageSelect.options[stageSelect.selectedIndex]?.getAttribute('data-name') || '';
            }
        });

        // When venue changes, update the hidden location input
        stageSelect.addEventListener('change', () => {
            if (locationInput) {
                const opt = stageSelect.options[stageSelect.selectedIndex];
                locationInput.value = opt ? (opt.getAttribute('data-name') || '') : '';
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_stages') === '1') {
        openModal('stagesModal');
    }
});

function confirmDeleteStage(id) {
    const input = document.getElementById('deleteStageConfirmId');
    if (input) {
        input.value = id;
    }
    openModal('deleteStageConfirmModal');
}

// Special Fields always visible

document.addEventListener('click', (e) => {
    const closeBtn = e.target.closest('[data-close]');
    if (closeBtn) {
        closeModal(closeBtn.dataset.close);
        return;
    }

    const openBtn = e.target.closest('[data-open-program]');
    if (openBtn) {
        document.getElementById('programForm')?.reset();
        const titleEl = document.getElementById('programModalTitle');
        if (titleEl) titleEl.textContent = 'Add Program';
        const actionEl = document.getElementById('programAction');
        if (actionEl) actionEl.value = 'create';
        const idEl = document.getElementById('programId');
        if (idEl) idEl.value = '';
        
        document.querySelectorAll('.allowed-section-chk').forEach(chk => chk.checked = false);
        document.querySelectorAll('.responsible-teacher-chk').forEach(chk => chk.checked = false);
        const secEl = document.getElementById('programSectionId');
        if (secEl) secEl.value = '';

        const filterSelect = document.getElementById('programStageTypeFilter');
        if (filterSelect) filterSelect.value = 'on_stage';
        syncVenues('on_stage', '1');

        const pStageType = document.getElementById('programStageTypeId');
        if (pStageType) pStageType.value = '1';
        const pLocation = document.getElementById('programLocation');
        if (pLocation) pLocation.value = 'Normal Stage';
        
        const jCount = document.getElementById('judgesCount');
        if (jCount) jCount.value = window.GLOBAL_DEFAULT_JUDGES || '2';
        const tMarks = document.getElementById('totalMarks');
        if (tMarks) tMarks.value = '100';
        const eLimit = document.getElementById('entriesLimit');
        if (eLimit) eLimit.value = '10';
        const rTeam = document.getElementById('redirectToTeam');
        if (rTeam) rTeam.checked = true;
        const dScores = document.getElementById('disableScores');
        if (dScores) dScores.checked = false;
        const oTeam = document.getElementById('onlyTeamMarks');
        if (oTeam) oTeam.checked = false;
        syncDisableScoresState();
        
        renderRanks(window.GLOBAL_DEFAULT_POINTS);
        
        updateSelectedTeachersDisplay();
        
        openModal('programModal');
        return;
    }

    const editBtn = e.target.closest('[data-edit-program]');
    if (editBtn) {
        try {
            const p = JSON.parse(editBtn.dataset.editProgram);
            const titleEl = document.getElementById('programModalTitle');
            if (titleEl) titleEl.textContent = 'Edit Program';
            const actionEl = document.getElementById('programAction');
            if (actionEl) actionEl.value = 'update';
            const idEl = document.getElementById('programId');
            if (idEl) idEl.value = p.id || '';
            const pTitle = document.getElementById('programTitle');
            if (pTitle) pTitle.value = p.title || '';
            
            const progType = document.getElementById('programType');
            if (progType) {
                let pType = p.program_type || '';
                if (pType.endsWith('_special')) {
                    pType = pType.replace('_special', '');
                }
                progType.value = pType;
            }

            const teacherIds = (p.responsible_teacher_ids || '').split(',').map(s => s.trim());
            document.querySelectorAll('.responsible-teacher-chk').forEach(chk => {
                chk.checked = teacherIds.includes(chk.value) || (p.responsible_teacher_id && String(p.responsible_teacher_id) === String(chk.value));
            });
            updateSelectedTeachersDisplay();

            const allowed = (p.allowed_sections || '').split(',').map(s => s.trim());
            document.querySelectorAll('.allowed-section-chk').forEach(chk => {
                chk.checked = allowed.includes(chk.value) || (p.class_type_id && String(p.class_type_id) === String(chk.value));
            });

            const filterSelect = document.getElementById('programStageTypeFilter');
            const catVal = p.stage_category || 'on_stage';
            if (filterSelect) filterSelect.value = catVal;
            
            syncVenues(catVal, p.stage_type_id || '1');

            const pStageType = document.getElementById('programStageTypeId');
            if (pStageType) pStageType.value = String(p.stage_type_id || '1');
            const pLocation = document.getElementById('programLocation');
            if (pLocation) pLocation.value = p.location || '';

            const jCount = document.getElementById('judgesCount');
            if (jCount) jCount.value = String(p.judges_count || '2');
            const tMarks = document.getElementById('totalMarks');
            if (tMarks) tMarks.value = String(p.total_marks || '100');
            const eLimit = document.getElementById('entriesLimit');
            if (eLimit) eLimit.value = String(p.entries_limit || '10');
            const rTeam = document.getElementById('redirectToTeam');
            if (rTeam) rTeam.checked = p.redirect_to_team !== 0 && p.redirect_to_team !== '0';
            const dScores = document.getElementById('disableScores');
            if (dScores) dScores.checked = p.disable_scores === 1 || p.disable_scores === '1';
            const oTeam = document.getElementById('onlyTeamMarks');
            if (oTeam) oTeam.checked = p.only_team_marks === 1 || p.only_team_marks === '1';
            syncDisableScoresState();
            
            let config = {};
            if (p.team_points_config) {
                try {
                    config = JSON.parse(p.team_points_config);
                } catch(e) {
                    config = window.GLOBAL_DEFAULT_POINTS;
                }
            } else {
                config = window.GLOBAL_DEFAULT_POINTS;
            }
            renderRanks(config);

            const pSec = document.getElementById('programSectionId');
            if (pSec) pSec.value = p.section_id || '';
            
            openModal('programModal');
        } catch (err) {
            console.error('Error parsing program metadata:', err);
        }
        return;
    }

    const deleteBtn = e.target.closest('[data-delete-id]');
    if (deleteBtn) {
        const dId = document.getElementById('deleteId');
        if (dId) dId.value = deleteBtn.dataset.deleteId;
        const dName = document.getElementById('deleteName');
        if (dName) dName.textContent = deleteBtn.dataset.deleteName || 'this program';
        openModal('deleteModal');
        return;
    }

    const catBtn = e.target.closest('[data-categories]');
    if (catBtn) {
        try {
            const payload = JSON.parse(catBtn.dataset.categories);
            const cSub = document.getElementById('categoryModalSubTitle');
            if (cSub) cSub.textContent = `Criteria breakdown for: ${payload.program.title || 'Program'}`;
            const cId = document.getElementById('categoryProgramId');
            if (cId) cId.value = payload.program.id || '';

            const rows = payload.categories && payload.categories.length ? payload.categories : [{name: 'Total', max_marks: 100}];
            const catRowsEl = document.getElementById('categoryRows');
            if (catRowsEl) catRowsEl.innerHTML = rows.map(row => categoryRow(row.name, row.max_marks)).join('');

            bindCategoryRows();
            refreshCategoryTotal();
            openModal('categoryModal');
        } catch (err) {
            console.error('Error parsing categories metadata:', err);
        }
        return;
    }
});

document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));

function categoryRow(name = '', marks = '') {
    return `
        <div class="score-category-row" style="display: grid; grid-template-columns: 1fr 100px 34px; gap: 10px; align-items: center; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 5px 8px; border-radius: 8px;">
            <input type="text" class="form-input" name="category_name[]" value="${escapeHtml(name)}" placeholder="Category Name (e.g. Pitch / Tajweed)" style="height: 34px; font-size: 13px; padding: 4px 10px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; color: #fff;" required>
            <input type="number" class="form-input category-marks-input" name="category_marks[]" min="0" max="100" step="0.01" value="${escapeHtml(marks)}" placeholder="100" style="height: 34px; font-size: 13px; text-align: right; padding: 4px 10px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; color: #fff;" required>
            <button class="btn btn-danger btn-sm" type="button" data-remove-category style="height: 34px; width: 34px; padding: 0; display: grid; place-items: center; border-radius: 6px;" title="Remove Category">
                <i class="fa-solid fa-trash" style="font-size: 11px;"></i>
            </button>
        </div>
    `;
}

function refreshCategoryTotal() {
    const total = Array.from(document.querySelectorAll('input[name="category_marks[]"]')).reduce((sum, input) => sum + Number(input.value || 0), 0);
    const catTot = document.getElementById('categoryTotal');
    if (catTot) catTot.textContent = total.toFixed(2);
    
    const badge = document.getElementById('categoryTotalBadge');
    if (badge) {
        if (Math.abs(total - 100.0) < 0.01) {
            badge.style.background = 'rgba(16, 185, 129, 0.15)';
            badge.style.color = '#34d399';
            badge.style.border = '1px solid rgba(16, 185, 129, 0.3)';
        } else {
            badge.style.background = 'rgba(239, 68, 68, 0.15)';
            badge.style.color = '#f87171';
            badge.style.border = '1px solid rgba(239, 68, 68, 0.3)';
        }
    }
}

function bindCategoryRows() {
    document.querySelectorAll('[data-remove-category]').forEach(btn => btn.onclick = () => {
        btn.closest('.score-category-row')?.remove();
        refreshCategoryTotal();
    });
    document.querySelectorAll('input[name="category_marks[]"]').forEach(input => input.oninput = refreshCategoryTotal);
}

function getCategoriesData() {
    const names = Array.from(document.querySelectorAll('input[name="category_name[]"]')).map(i => i.value.trim());
    const marks = Array.from(document.querySelectorAll('input[name="category_marks[]"]')).map(i => parseFloat(i.value) || 0);
    const result = [];
    names.forEach((name, idx) => {
        if (name !== '' || marks[idx] > 0) {
            result.push({ name: name || 'Category', max_marks: marks[idx] });
        }
    });
    return result;
}

function renderCategoryRows(rows) {
    const catRowsEl = document.getElementById('categoryRows');
    if (!catRowsEl) return;
    if (!rows || !rows.length) {
        rows = [{ name: 'Total', max_marks: 100 }];
    }
    catRowsEl.innerHTML = rows.map(r => categoryRow(r.name, r.max_marks)).join('');
    bindCategoryRows();
    refreshCategoryTotal();
}

function parseRawCategoriesText(text) {
    if (!text || !text.trim()) return null;
    text = text.trim();
    
    // Try JSON first
    if (text.startsWith('[') || text.startsWith('{')) {
        try {
            const data = JSON.parse(text);
            const list = Array.isArray(data) ? data : [data];
            const parsed = [];
            list.forEach(item => {
                if (typeof item === 'object' && item !== null) {
                    const name = String(item.name || item.title || item.category || '').trim();
                    const marks = parseFloat(item.max_marks || item.marks || item.points || 0);
                    if (name || marks > 0) {
                        parsed.push({ name: name || 'Category', max_marks: marks });
                    }
                }
            });
            if (parsed.length) return parsed;
        } catch(e) {}
    }
    
    // Try multi-line format e.g. "Pitch 30", "Tajweed: 30", "Voice - 40" or tab separated
    const lines = text.split(/\r?\n/);
    const parsed = [];
    lines.forEach(line => {
        line = line.trim();
        if (!line) return;
        const match = line.match(/^(.*?)(?:[:=\-–\t\s]+)(\d+(?:\.\d+)?)$/);
        if (match) {
            const name = match[1].trim();
            const marks = parseFloat(match[2]);
            if (name && marks > 0) {
                parsed.push({ name: name, max_marks: marks });
            }
        } else {
            parsed.push({ name: line, max_marks: 10 });
        }
    });
    return parsed.length ? parsed : null;
}

// Copy Categories button in modal
document.getElementById('btnCopyCategories')?.addEventListener('click', () => {
    const cats = getCategoriesData();
    if (!cats.length) {
        showAdminToast('No categories to copy.', true);
        return;
    }
    const jsonStr = JSON.stringify(cats, null, 2);
    try {
        localStorage.setItem('musabaqa_copied_categories', jsonStr);
    } catch(e) {}
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(jsonStr).then(() => {
            showAdminToast(`Copied ${cats.length} categories to clipboard!`);
        }).catch(() => {
            showAdminToast(`Saved ${cats.length} categories to buffer!`);
        });
    } else {
        showAdminToast(`Saved ${cats.length} categories to buffer!`);
    }
});

// Direct Copy button on table rows
document.addEventListener('click', (e) => {
    const copyRowBtn = e.target.closest('[data-copy-categories]');
    if (copyRowBtn) {
        try {
            const payload = JSON.parse(copyRowBtn.dataset.copyCategories);
            const cats = payload.categories && payload.categories.length ? payload.categories.map(c => ({ name: c.name, max_marks: parseFloat(c.max_marks) })) : [];
            if (!cats.length) {
                showAdminToast(`No categories in "${payload.title || 'Program'}" to copy.`, true);
                return;
            }
            const jsonStr = JSON.stringify(cats, null, 2);
            localStorage.setItem('musabaqa_copied_categories', jsonStr);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(jsonStr).catch(() => {});
            }
            showAdminToast(`Copied ${cats.length} categories from "${payload.title}"!`);
        } catch(err) {
            console.error('Error copying row categories:', err);
        }
    }
});

// Paste Categories button in modal
document.getElementById('btnPasteCategories')?.addEventListener('click', async () => {
    let textToParse = '';
    if (navigator.clipboard && navigator.clipboard.readText) {
        try {
            textToParse = await navigator.clipboard.readText();
        } catch(e) {}
    }
    if (!textToParse) {
        try {
            textToParse = localStorage.getItem('musabaqa_copied_categories') || '';
        } catch(e) {}
    }
    
    if (textToParse) {
        const parsed = parseRawCategoriesText(textToParse);
        if (parsed && parsed.length) {
            renderCategoryRows(parsed);
            showAdminToast(`Pasted ${parsed.length} categories!`);
            return;
        }
    }
    
    const rawBox = document.getElementById('rawPasteContainer');
    if (rawBox) {
        rawBox.style.display = 'block';
        document.getElementById('rawCategoriesTextarea')?.focus();
    }
});

// Presets select
document.getElementById('presetCategoriesSelect')?.addEventListener('change', (e) => {
    const val = e.target.value;
    if (!val) return;
    let presets = [];
    if (val === 'speech') {
        presets = [{name: 'Pitch / Voice Quality', max_marks: 30}, {name: 'Tajweed & Diction', max_marks: 30}, {name: 'Presentation & Style', max_marks: 40}];
    } else if (val === 'qiraat') {
        presets = [{name: 'Tajweed Rules', max_marks: 50}, {name: 'Voice & Melody', max_marks: 30}, {name: 'Performance & Style', max_marks: 20}];
    } else if (val === 'recitation') {
        presets = [{name: 'Fluency & Memory', max_marks: 40}, {name: 'Pronunciation', max_marks: 30}, {name: 'Voice Modulation', max_marks: 30}];
    } else if (val === 'quiz') {
        presets = [{name: 'Accuracy', max_marks: 60}, {name: 'Speed', max_marks: 20}, {name: 'Rule Compliance', max_marks: 20}];
    } else if (val === 'single') {
        presets = [{name: 'Total Score', max_marks: 100}];
    }
    if (presets.length) {
        renderCategoryRows(presets);
        showAdminToast('Preset categories applied.');
    }
    e.target.value = '';
});

// Bulk raw text box toggle and apply
document.getElementById('btnToggleRawPaste')?.addEventListener('click', () => {
    const rawBox = document.getElementById('rawPasteContainer');
    if (rawBox) {
        rawBox.style.display = rawBox.style.display === 'none' ? 'block' : 'none';
        if (rawBox.style.display === 'block') {
            document.getElementById('rawCategoriesTextarea')?.focus();
        }
    }
});
document.getElementById('btnCancelRawPaste')?.addEventListener('click', () => {
    const rawBox = document.getElementById('rawPasteContainer');
    if (rawBox) rawBox.style.display = 'none';
});
document.getElementById('btnApplyRawPaste')?.addEventListener('click', () => {
    const txt = document.getElementById('rawCategoriesTextarea')?.value || '';
    const parsed = parseRawCategoriesText(txt);
    if (parsed && parsed.length) {
        renderCategoryRows(parsed);
        const area = document.getElementById('rawCategoriesTextarea');
        if (area) area.value = '';
        document.getElementById('rawPasteContainer').style.display = 'none';
        showAdminToast(`Applied ${parsed.length} categories!`);
    } else {
        showAdminToast('Could not parse categories. Try line format like "Pitch 30"', true);
    }
});

document.getElementById('addCategoryRow')?.addEventListener('click', () => {
    document.getElementById('categoryRows')?.insertAdjacentHTML('beforeend', categoryRow());
    bindCategoryRows();
    refreshCategoryTotal();
});

document.getElementById('addRankBtn')?.addEventListener('click', () => {
    const nextRank = document.querySelectorAll('#ranksContainer .rank-row').length + 1;
    addRankRow(nextRank, 0);
    reindexRanks();
});

function showAdminToast(message, isError = false) {
    let toast = document.getElementById('adminToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'adminToast';
        toast.style.cssText = 'position:fixed; bottom:24px; right:24px; padding:12px 24px; border-radius:12px; font-weight:700; font-size:14px; color:#fff; z-index:999999; box-shadow:0 10px 25px rgba(0,0,0,0.5); backdrop-filter:blur(8px); display:flex; align-items:center; gap:8px;';
        document.body.appendChild(toast);
    }
    toast.style.background = isError ? 'rgba(239, 68, 68, 0.92)' : 'rgba(16, 185, 129, 0.92)';
    toast.innerHTML = (isError ? '<i class="fa-solid fa-circle-xmark"></i> ' : '<i class="fa-solid fa-circle-check"></i> ') + message;
    toast.style.display = 'flex';
    setTimeout(() => { toast.style.display = 'none'; }, 3000);
}

function refreshProgramsTable() {
    fetch(window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newContainer = doc.getElementById('programsTableContainer');
        const currentContainer = document.getElementById('programsTableContainer');
        if (newContainer && currentContainer) {
            currentContainer.innerHTML = newContainer.innerHTML;
        }
    })
    .catch(err => console.error('Error refreshing programs table:', err));
}

function attachZeroRefreshForm(formId, modalId) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener('submit', (e) => {
        e.preventDefault();

        if (formId === 'programForm') {
            const jCount = document.getElementById('judgesCount');
            if (jCount) jCount.disabled = false;
            const tMarks = document.getElementById('totalMarks');
            if (tMarks) tMarks.disabled = false;
            const rTeam = document.getElementById('redirectToTeam');
            if (rTeam) rTeam.disabled = false;
            const oTeam = document.getElementById('onlyTeamMarks');
            if (oTeam) oTeam.disabled = false;

            const config = {};
            document.querySelectorAll('#ranksContainer .rank-row').forEach((row, idx) => {
                const rankNo = idx + 1;
                const val = parseInt(row.querySelector('.rank-points-input').value || '0', 10);
                config[rankNo] = val;
            });
            const configEl = document.getElementById('teamPointsConfigInput');
            if (configEl) configEl.value = JSON.stringify(config);
        }

        const formData = new FormData(form);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (modalId) closeModal(modalId);
                showAdminToast(data.message || 'Program updated successfully.');
                refreshProgramsTable();
            } else {
                showAdminToast(data.message || 'Unable to save changes.', true);
            }
        })
        .catch(err => {
            console.error('Error submitting form:', err);
            form.submit();
        });
    });
}

attachZeroRefreshForm('programForm', 'programModal');
attachZeroRefreshForm('categoryForm', 'categoryModal');
attachZeroRefreshForm('deleteForm', 'deleteModal');

function syncDisableScoresState() {
    const dScores = document.getElementById('disableScores');
    const rTeam = document.getElementById('redirectToTeam');
    const oTeam = document.getElementById('onlyTeamMarks');
    const jCount = document.getElementById('judgesCount');
    const tMarks = document.getElementById('totalMarks');

    if (dScores) {
        const isDisabled = dScores.checked;

        if (jCount) {
            jCount.disabled = isDisabled;
            const jGroup = jCount.closest('.input-group');
            if (jGroup) {
                jGroup.style.opacity = isDisabled ? '0.45' : '1';
                jGroup.style.pointerEvents = isDisabled ? 'none' : 'auto';
            }
        }

        if (tMarks) {
            tMarks.disabled = isDisabled;
            const tGroup = tMarks.closest('.input-group');
            if (tGroup) {
                tGroup.style.opacity = isDisabled ? '0.45' : '1';
                tGroup.style.pointerEvents = isDisabled ? 'none' : 'auto';
            }
        }

        if (rTeam && oTeam) {
            const rRow = document.getElementById('rowRedirectToTeam');
            const oRow = document.getElementById('rowOnlyTeamMarks');
            if (isDisabled) {
                rTeam.disabled = true;
                oTeam.disabled = true;
                rTeam.checked = false;
                oTeam.checked = false;
                if (rRow) {
                    rRow.style.opacity = '0.5';
                    rRow.style.pointerEvents = 'none';
                }
                if (oRow) {
                    oRow.style.opacity = '0.5';
                    oRow.style.pointerEvents = 'none';
                }
            } else {
                rTeam.disabled = false;
                oTeam.disabled = false;
                if (rRow) {
                    rRow.style.opacity = '1';
                    rRow.style.pointerEvents = 'auto';
                }
                if (oRow) {
                    oRow.style.opacity = '1';
                    oRow.style.pointerEvents = 'auto';
                }
            }
        }
    }
}

document.getElementById('disableScores')?.addEventListener('change', syncDisableScoresState);
</script>
<?php admin_close_page(); ?>
