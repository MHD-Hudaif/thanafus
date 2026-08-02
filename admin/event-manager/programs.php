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

function programs_redirect(): void
{
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
    $programId = (int)($_POST['program_id'] ?? 0);

    try {
        if ($action === 'delete') {
            $pdo->beginTransaction();

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

            admin_log_activity($pdo, (int)$user['id'], $activeEventId, 'delete_program', 'musabaqa_programs', $programId, 'Deleted program and reset all associated entries, marks, and leaderboard totals.');

            $pdo->commit();

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

            $pdo->beginTransaction();

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
            $pdo->commit();

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

        if ($action === 'update' && $programId > 0) {
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET title = ?, program_type = ?, class_type_id = ?,
                    is_special = ?, judges_count = ?, total_marks = ?,
                    entries_limit = ?, redirect_to_team = ?, disable_scores = ?, 
                    team_points_config = ?, only_team_marks = ?,
                    allowed_sections = ?,
                    responsible_teacher_id = ?, responsible_teacher_ids = ?,
                    section_id = ?
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
                $programId,
                $activeEventId
            ]);

            admin_flash('success', 'Program updated successfully.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_programs
                    (event_id, title, program_type, class_type_id, status,
                     is_special, judges_count, total_marks, entries_limit, redirect_to_team, disable_scores, 
                     team_points_config, only_team_marks,
                     allowed_sections, responsible_teacher_id, responsible_teacher_ids,
                     section_id)
                VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $sectionId
            ]);
            $programId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO musabaqa_program_categories (program_id, name, max_marks, sort_order) VALUES (?, 'Total', 100.00, 1)");
            $stmt->execute([$programId]);

            admin_flash('success', 'Program created successfully. Default 100-mark category added.');
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
$stageTypes = $pdo->query('SELECT id, name FROM musabaqa_stage_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

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
        ct.name AS class_type_name,
        t.full_name AS responsible_teacher_name,
        mss.name AS schedule_section_name,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT ss.id) AS score_sheet_count,
        COUNT(DISTINCT pc.id) AS category_count
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN kauzariyya.teachers t ON t.id = mp.responsible_teacher_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = mp.section_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = mp.id
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
        <button class="btn btn-success btn-md" data-open-program><i class="fa-solid fa-plus"></i> Add Program</button>
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

    <?php if (!$programs): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-layer-group"></i></div>
            <div class="empty-title">No Programs Found</div>
            <div class="empty-subtitle">Create the first program for this event.</div>
        </div>
    <?php else: ?>
        <?php
        // Group the programs by tier
        $tiers = [
            'senior' => 'Senior',
            'junior' => 'Junior',
            'subjunior' => 'Sub Junior',
            'general' => 'General / Other'
        ];

        $classTypesMap = [];
        foreach ($classTypes as $type) {
            $classTypesMap[(int)$type['id']] = $type['name'];
        }

        $grouped = [
            'subjunior' => [],
            'junior' => [],
            'senior' => [],
            'general' => []
        ];

        foreach ($programs as $program) {
            $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
            
            // If the program allows multiple sections, it's considered General/Multi-Section
            $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;
            
            if ($allowedCount > 1 || !$classTier) {
                $grouped['general'][] = $program;
            } else {
                $grouped[$classTier][] = $program;
            }
        }
        ?>

        <?php foreach ($tiers as $tierKey => $tierLabel): ?>
            <?php $tierPrograms = $grouped[$tierKey] ?? []; ?>
            <?php if (!$tierPrograms) continue; ?>

            <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.04); border-radius: 12px; overflow: hidden; padding: 0;">
                <div style="background: rgba(255,255,255,0.015); padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.04); display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 15px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-layer-group" style="color: #facc15;"></i>
                        <?= e($tierLabel) ?> Division
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
                                        <div class="muted"><?= e($program['location'] ?: '-') ?></div>
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

<div class="modal-overlay" id="programModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="programModalTitle">Add Program</div>
            <button class="modal-close" type="button" data-close="programModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="programForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="programAction" value="create">
            <input type="hidden" name="program_id" id="programId">
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
            <div class="field-help mt-4">Set a duration to auto-calculate the end time.</div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-close="programModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit">Save Program</button>
            </div>

            <!-- TEACHER SELECT SUB-MODAL INSIDE THE FORM -->
            <div class="modal-overlay" id="teacherSelectModal" style="z-index: 1100;">
                <div class="modal-box" style="max-width: 500px;">
                    <div class="modal-header">
                        <div class="modal-title">Select Incharge Teachers</div>
                        <button class="modal-close" type="button" data-close="teacherSelectModal"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div class="modal-body" style="padding: 20px;">
                        <div style="display: flex; flex-direction: column; gap: 10px; max-height: 300px; overflow-y: auto; padding-right: 5px;">
                            <?php foreach ($teachers as $teacher): ?>
                                <label class="section-toggle-card" style="display: flex; width: 100%;">
                                    <input type="checkbox" name="responsible_teacher_ids[]" value="<?= (int)$teacher['id'] ?>" class="responsible-teacher-chk" onchange="updateSelectedTeachersDisplay()">
                                    <div class="card-inner" style="display: flex; width: 100%; justify-content: flex-start; box-sizing: border-box;">
                                        <i class="fa-solid fa-circle-check check-icon"></i>
                                        <span class="teacher-name-span"><?= e($teacher['full_name']) ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding: 15px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px;">
                        <button class="btn btn-secondary btn-md" type="button" data-close="teacherSelectModal">Done</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="categoryModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="categoryModalTitle">Scoring Categories</div>
            <button class="modal-close" type="button" data-close="categoryModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="categoryForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_categories">
            <input type="hidden" name="program_id" id="categoryProgramId">
            <div id="categoryRows" class="score-category-list"></div>
            <div class="flex-between mt-4">
                <button type="button" class="btn btn-secondary btn-sm" id="addCategoryRow"><i class="fa-solid fa-plus"></i> Add Category</button>
                <div class="badge badge-neutral">Total: <span id="categoryTotal">0</span> / 100</div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-close="categoryModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit">Save Categories</button>
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
        <form method="POST">
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
    
    const isDefault = rank <= 3;
    
    row.innerHTML = `
        <span style="font-size: 13px; min-width: 80px; font-weight:600; color:var(--muted);">Rank <span class="rank-index">${rank}</span>:</span>
        <input type="number" class="form-input rank-points-input" min="0" value="${points}" placeholder="Points" style="width: 100px; height: 32px; font-size: 13px;" required>
        ${isDefault ? `
            <span style="font-size: 11px; color: rgba(255,255,255,0.2); font-style:italic;">Default Slot</span>
        ` : `
            <button type="button" class="btn btn-danger btn-xs remove-rank-btn" style="padding: 4px 8px; height: 32px;" title="Remove Rank">
                <i class="fa-solid fa-trash"></i>
            </button>
        `}
    `;
    container.appendChild(row);
    
    if (!isDefault) {
        row.querySelector('.remove-rank-btn')?.addEventListener('click', () => {
            row.remove();
            reindexRanks();
        });
    }
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
            const cTitle = document.getElementById('categoryModalTitle');
            if (cTitle) cTitle.textContent = `Scoring Categories · ${payload.program.title || 'Program'}`;
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
        <div class="score-category-row">
            <div class="input-group"><label>Name</label><input name="category_name[]" value="${escapeHtml(name)}" required></div>
            <div class="input-group"><label>Max Marks</label><input type="number" name="category_marks[]" min="0" max="100" step="0.01" value="${escapeHtml(marks)}" required></div>
            <button class="btn btn-danger btn-sm" type="button" data-remove-category><i class="fa-solid fa-trash"></i></button>
        </div>
    `;
}

function refreshCategoryTotal() {
    const total = Array.from(document.querySelectorAll('input[name="category_marks[]"]')).reduce((sum, input) => sum + Number(input.value || 0), 0);
    const catTot = document.getElementById('categoryTotal');
    if (catTot) catTot.textContent = total.toFixed(2);
}

function bindCategoryRows() {
    document.querySelectorAll('[data-remove-category]').forEach(btn => btn.onclick = () => {
        btn.closest('.score-category-row')?.remove();
        refreshCategoryTotal();
    });
    document.querySelectorAll('input[name="category_marks[]"]').forEach(input => input.oninput = refreshCategoryTotal);
}

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

document.getElementById('programForm')?.addEventListener('submit', () => {
    const config = {};
    document.querySelectorAll('#ranksContainer .rank-row').forEach((row, idx) => {
        const rankNo = idx + 1;
        const val = parseInt(row.querySelector('.rank-points-input').value || '0', 10);
        config[rankNo] = val;
    });
    const configEl = document.getElementById('teamPointsConfigInput');
    if (configEl) configEl.value = JSON.stringify(config);
});

function syncDisableScoresState() {
    const dScores = document.getElementById('disableScores');
    const rTeam = document.getElementById('redirectToTeam');
    const oTeam = document.getElementById('onlyTeamMarks');
    if (dScores && rTeam && oTeam) {
        const rRow = document.getElementById('rowRedirectToTeam');
        const oRow = document.getElementById('rowOnlyTeamMarks');
        if (dScores.checked) {
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

document.getElementById('disableScores')?.addEventListener('change', syncDisableScoresState);
</script>
<?php admin_close_page(); ?>