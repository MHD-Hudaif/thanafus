<?php
$pageTitle = 'Programs';

require_once __DIR__ . '/../includes/admin-helpers.php';
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

function programs_redirect(): void
{
    admin_redirect('/admin/programs.php');
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
        $stageTypeId = (int)($_POST['stage_type_id'] ?? 0);
        $location = trim((string)($_POST['location'] ?? ''));
        $durationMinutes = (int)($_POST['duration_minutes'] ?? 0);

        $allowedSectionsArr = (array)($_POST['allowed_sections'] ?? []);
        if (!$allowedSectionsArr) {
            throw new RuntimeException('Select at least one allowed section.');
        }
        $allowedSectionsStr = implode(',', array_map('intval', $allowedSectionsArr));
        // Keep class_type_id backward compatible
        $classTypeId = count($allowedSectionsArr) === 1 ? (int)$allowedSectionsArr[0] : null;

        $isSpecial = isset($_POST['is_special']) && $_POST['is_special'] === '1' ? 1 : 0;
        if ($isSpecial) {
            $judgesCount = max(1, min(10, (int)($_POST['judges_count'] ?? 2)));
            $totalMarks = max(1, min(1000, (int)($_POST['total_marks'] ?? 100)));
            $entriesLimit = max(1, min(1000, (int)($_POST['entries_limit'] ?? 10)));
            $redirectToTeam = isset($_POST['redirect_to_team']) ? 1 : 0;
            $disableScores = isset($_POST['disable_scores']) ? 1 : 0;
        } else {
            $judgesCount = (int)$settings['default_judges_count'];
            $totalMarks = (int)$settings['default_total_marks'];
            $entriesLimit = (int)$settings['default_entries_limit'];
            $redirectToTeam = 1;
            $disableScores = 0;
        }

        $startDt = parse_admin_datetime_local($_POST['start_time'] ?? null);
        $endDt = parse_admin_datetime_local($_POST['end_time'] ?? null);

        if ($durationMinutes > 0) {
            if (!$startDt) {
                throw new RuntimeException('Start time is required when duration is set.');
            }
            $endDt = $startDt->modify("+{$durationMinutes} minutes");
        }

        $startTime = $startDt ? $startDt->format('Y-m-d H:i:s') : null;
        $endTime = $endDt ? $endDt->format('Y-m-d H:i:s') : null;

        if ($title === '' || !in_array($programType, ['individual', 'group'], true) || $stageTypeId <= 0) {
            throw new RuntimeException('Program title, type and stage are required.');
        }

        if ($startDt && $endDt && $endDt->getTimestamp() <= $startDt->getTimestamp()) {
            throw new RuntimeException('End time must be after start time.');
        }

        if ($startTime && $endTime) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM musabaqa_programs
                WHERE event_id = ?
                  AND id <> ?
                  AND stage_type_id = ?
                  AND start_time IS NOT NULL
                  AND end_time IS NOT NULL
                  AND start_time < ?
                  AND end_time > ?
                LIMIT 1
            ");
            $stmt->execute([
                $activeEventId,
                $programId,
                $stageTypeId,
                $endTime,
                $startTime
            ]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Another program already exists during this time on the same stage.');
            }
        }

        if ($action === 'update' && $programId > 0) {
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET title = ?, program_type = ?, class_type_id = ?, stage_type_id = ?, location = ?,
                    start_time = ?, end_time = ?, is_special = ?, judges_count = ?, total_marks = ?,
                    entries_limit = ?, redirect_to_team = ?, disable_scores = ?, allowed_sections = ?
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([
                $title,
                $programType,
                $classTypeId,
                $stageTypeId,
                $location ?: null,
                $startTime,
                $endTime,
                $isSpecial,
                $judgesCount,
                $totalMarks,
                $entriesLimit,
                $redirectToTeam,
                $disableScores,
                $allowedSectionsStr,
                $programId,
                $activeEventId
            ]);

            admin_flash('success', 'Program updated successfully.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_programs
                    (event_id, title, program_type, class_type_id, stage_type_id, location, start_time, end_time, status,
                     is_special, judges_count, total_marks, entries_limit, redirect_to_team, disable_scores, allowed_sections)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $activeEventId,
                $title,
                $programType,
                $classTypeId,
                $stageTypeId,
                $location ?: null,
                $startTime,
                $endTime,
                $isSpecial,
                $judgesCount,
                $totalMarks,
                $entriesLimit,
                $redirectToTeam,
                $disableScores,
                $allowedSectionsStr
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

$stmt = $pdo->prepare("
    SELECT
        mp.*,
        mst.name AS stage_type_name,
        ct.name AS class_type_name,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT ss.id) AS score_sheet_count,
        COUNT(DISTINCT pc.id) AS category_count
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = mp.id
    LEFT JOIN musabaqa_score_sheets ss ON ss.program_id = mp.id
    LEFT JOIN musabaqa_program_categories pc ON pc.program_id = mp.id
    {$where}
    GROUP BY mp.id
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

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Programs</div>
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
                    <a class="btn btn-secondary btn-md" href="<?= APP_URL ?>/admin/programs.php">Clear</a>
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
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Type</th>
                        <th>Stage</th>
                        <th>Class</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th>Entries</th>
                        <th>Categories</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                        <?php $programCategories = $categoryRows[(int)$program['id']] ?? []; ?>
                        <tr>
                            <td>
                                <strong><?= e($program['title']) ?></strong>
                                <div class="muted"><?= e($program['location'] ?: '-') ?></div>
                            </td>
                            <td><span class="badge badge-neutral"><?= e(ucfirst($program['program_type'])) ?></span></td>
                            <td><?= e($program['stage_type_name'] ?: '-') ?></td>
                            <td>
                                <?php $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? ''); ?>
                                <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                    <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($program['start_time']): ?>
                                    <div><?= e(date('d M Y h:i A', strtotime($program['start_time']))) ?></div>
                                    <div class="muted">
                                        <?= $program['end_time'] ? e(date('h:i A', strtotime($program['end_time']))) : '-' ?>
                                    </div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= program_status_badge($program['status']) ?>"><?= e(ucfirst((string)$program['status'])) ?></span></td>
                            <td><span class="badge <?= program_approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span></td>
                            <td><?= (int)$program['entry_count'] ?></td>
                            <td><?= (int)$program['category_count'] ?></td>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <a href="<?= APP_URL ?>/admin/entries.php?program=<?= (int)$program['id'] ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list-check"></i> Entries</a>
                                    <a href="<?= APP_URL ?>/admin/program-scores.php?program_id=<?= (int)$program['id'] ?>" class="btn btn-success btn-sm"><i class="fa-solid fa-pen-to-square"></i> Score</a>
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
                    <label style="font-weight: 600; margin-bottom: 8px; display: block; color: var(--muted);">Allowed Sections (Class Types) <span class="required">*</span></label>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px;">
                        <?php foreach ($classTypes as $type): ?>
                            <label class="section-toggle-card">
                                <input type="checkbox" name="allowed_sections[]" value="<?= (int)$type['id'] ?>" class="allowed-section-chk">
                                <div class="card-inner">
                                    <i class="fa-solid fa-circle-check check-icon"></i>
                                    <span><?= e(admin_class_type_display($type['name'] ?? null, (int)$type['id'])) ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="input-group">
                    <label>Stage <span class="required">*</span></label>
                    <select name="stage_type_id" id="stageTypeId" required>
                        <option value="">Select Stage</option>
                        <?php foreach ($stageTypes as $stage): ?>
                            <option value="<?= (int)$stage['id'] ?>"><?= e($stage['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Location</label>
                    <input type="text" name="location" id="programLocation">
                </div>
                <div class="input-group">
                    <label>Start Time</label>
                    <input type="datetime-local" name="start_time" id="startTime">
                </div>
                <div class="input-group">
                    <label>Duration (Minutes)</label>
                    <input type="number" name="duration_minutes" id="durationMinutes" min="1" step="1" placeholder="e.g. 60">
                </div>
                <div class="input-group">
                    <label>End Time</label>
                    <input type="datetime-local" name="end_time" id="endTime">
                </div>
                <div class="input-group">
                    <label>Program Mode</label>
                    <select name="is_special" id="isSpecial">
                        <option value="0">Default Mode (Use global defaults)</option>
                        <option value="1">Special Mode (Custom limits &amp; redirection)</option>
                    </select>
                </div>
                
                <div id="specialFields" style="display: none; border-top: 1px solid var(--border); padding-top: 15px; margin-top: 15px; grid-column: span 2; width: 100%;">
                    <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--accent, #14b8a6);"><i class="fa-solid fa-gear"></i> Special Mode Settings</h4>
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
                        <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 10px 14px; border-radius: 10px;">
                            <div>
                                <strong style="font-size: 13.5px; display: block; color: var(--text);">Redirect to Team Total</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Redirect participants' scores to team total points</span>
                            </div>
                            <label class="toggle-switch" style="position: relative; display: inline-block;">
                                <input type="checkbox" name="redirect_to_team" id="redirectToTeam" value="1" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); padding: 10px 14px; border-radius: 10px;">
                            <div>
                                <strong style="font-size: 13.5px; display: block; color: var(--text);">Disable Scores</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Disable/hide scores (useful for semi-finales/hiding)</span>
                            </div>
                            <label class="toggle-switch" style="position: relative; display: inline-block;">
                                <input type="checkbox" name="disable_scores" id="disableScores" value="1">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="field-help mt-4">Set a duration to auto-calculate the end time.</div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-close="programModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit">Save Program</button>
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

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
function toLocalDatetime(value){return value ? String(value).replace(' ', 'T').slice(0,16) : ''}
function escapeHtml(value){return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}

function formatLocalDatetime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function updateEndTimeFromDuration() {
    const startValue = document.getElementById('startTime').value;
    const durationValue = Number(document.getElementById('durationMinutes').value);

    if (!startValue || !durationValue || durationValue <= 0) {
        return;
    }

    const startDate = new Date(startValue);
    if (Number.isNaN(startDate.getTime())) {
        return;
    }

    startDate.setMinutes(startDate.getMinutes() + durationValue);
    document.getElementById('endTime').value = formatLocalDatetime(startDate);
}

function toggleModeFields() {
    const isSp = document.getElementById('isSpecial').value === '1';
    document.getElementById('specialFields').style.display = isSp ? 'block' : 'none';
}
document.getElementById('isSpecial')?.addEventListener('change', toggleModeFields);

const latestProgramData = <?= json_encode($latestProgramData) ?>;

document.querySelector('[data-open-program]')?.addEventListener('click', () => {
    document.getElementById('programForm').reset();
    document.getElementById('programModalTitle').textContent = 'Add Program';
    document.getElementById('programAction').value = 'create';
    document.getElementById('programId').value = '';
    
    document.querySelectorAll('.allowed-section-chk').forEach(chk => chk.checked = false);
    document.getElementById('isSpecial').value = '0';
    toggleModeFields();
    
    // Auto-fill with latest program data if available
    if (latestProgramData && latestProgramData.end_time) {
        document.getElementById('startTime').value = toLocalDatetime(latestProgramData.end_time);
        document.getElementById('stageTypeId').value = latestProgramData.stage_type_id || '';
        document.getElementById('programLocation').value = latestProgramData.location || '';
    }
    
    openModal('programModal');
});

document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));

document.getElementById('startTime')?.addEventListener('change', updateEndTimeFromDuration);
document.getElementById('durationMinutes')?.addEventListener('input', updateEndTimeFromDuration);

document.querySelectorAll('[data-edit-program]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.editProgram);

    document.getElementById('programModalTitle').textContent = 'Edit Program';
    document.getElementById('programAction').value = 'update';
    document.getElementById('programId').value = p.id || '';
    document.getElementById('programTitle').value = p.title || '';
    document.getElementById('programType').value = p.program_type || '';
    document.getElementById('stageTypeId').value = p.stage_type_id || '';
    document.getElementById('programLocation').value = p.location || '';
    document.getElementById('startTime').value = toLocalDatetime(p.start_time);
    document.getElementById('endTime').value = toLocalDatetime(p.end_time);

    // Populate checkboxes for allowed sections
    const allowed = (p.allowed_sections || '').split(',').map(s => s.trim());
    document.querySelectorAll('.allowed-section-chk').forEach(chk => {
        chk.checked = allowed.includes(chk.value) || (p.class_type_id && String(p.class_type_id) === String(chk.value));
    });

    // Populate special mode values
    document.getElementById('isSpecial').value = String(p.is_special || '0');
    document.getElementById('judgesCount').value = String(p.judges_count || '2');
    document.getElementById('totalMarks').value = String(p.total_marks || '100');
    document.getElementById('entriesLimit').value = String(p.entries_limit || '10');
    document.getElementById('redirectToTeam').checked = p.redirect_to_team !== 0 && p.redirect_to_team !== '0';
    document.getElementById('disableScores').checked = p.disable_scores === 1 || p.disable_scores === '1';
    
    toggleModeFields();

    if (p.start_time && p.end_time) {
        const start = new Date(toLocalDatetime(p.start_time));
        const end = new Date(toLocalDatetime(p.end_time));
        const diffMinutes = Math.round((end - start) / 60000);
        document.getElementById('durationMinutes').value = diffMinutes > 0 ? diffMinutes : '';
    } else {
        document.getElementById('durationMinutes').value = '';
    }

    openModal('programModal');
}));

document.querySelectorAll('[data-delete-id]').forEach(btn => btn.addEventListener('click', () => {
    document.getElementById('deleteId').value = btn.dataset.deleteId;
    document.getElementById('deleteName').textContent = btn.dataset.deleteName || 'this program';
    openModal('deleteModal');
}));

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
    document.getElementById('categoryTotal').textContent = total.toFixed(2);
}

function bindCategoryRows() {
    document.querySelectorAll('[data-remove-category]').forEach(btn => btn.onclick = () => {
        btn.closest('.score-category-row')?.remove();
        refreshCategoryTotal();
    });
    document.querySelectorAll('input[name="category_marks[]"]').forEach(input => input.oninput = refreshCategoryTotal);
}

document.getElementById('addCategoryRow').addEventListener('click', () => {
    document.getElementById('categoryRows').insertAdjacentHTML('beforeend', categoryRow());
    bindCategoryRows();
    refreshCategoryTotal();
});

document.querySelectorAll('[data-categories]').forEach(btn => btn.addEventListener('click', () => {
    const payload = JSON.parse(btn.dataset.categories);
    document.getElementById('categoryModalTitle').textContent = `Scoring Categories · ${payload.program.title || 'Program'}`;
    document.getElementById('categoryProgramId').value = payload.program.id || '';

    const rows = payload.categories && payload.categories.length ? payload.categories : [{name: 'Total', max_marks: 100}];
    document.getElementById('categoryRows').innerHTML = rows.map(row => categoryRow(row.name, row.max_marks)).join('');

    bindCategoryRows();
    refreshCategoryTotal();
    openModal('categoryModal');
}));
</script>
<?php admin_close_page(); ?>