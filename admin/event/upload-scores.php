<?php
$pageTitle = 'Upload Scores';

define('EVENT_AUTHORITY_SCOPE', 'upload-scores');
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);

function program_scores_redirect(int $programId): void
{
    admin_redirect('/admin/event/upload-scores.php', ['program_id' => $programId]);
}

function upload_scores_redirect(array $query = []): void
{
    admin_redirect('/admin/event/upload-scores.php', $query);
}

function program_scores_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-info',
    };
}

function score_entry_status_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function score_entry_approval_badge(?string $status): string
{
    return match ((string)$status) {
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'submitted' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function program_scores_load_program(PDO $pdo, int $eventId, int $programId): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name
        FROM musabaqa_programs p
        LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
        WHERE p.id = ? AND p.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
    return $program ?: null;
}

function program_scores_load_categories(PDO $pdo, int $programId): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, max_marks, sort_order
        FROM musabaqa_program_categories
        WHERE program_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$programId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function program_scores_categories_total(array $categories): float
{
    return array_reduce($categories, static fn ($sum, $row) => $sum + (float)$row['max_marks'], 0.0);
}

function program_scores_categories_editable(array $program): bool
{
    return in_array((string)$program['approval_status'], ['none', 'rejected'], true);
}

function program_scores_entry_locked(array $program, ?array $sheet): bool
{
    if (in_array((string)$program['approval_status'], ['submitted', 'approved'], true)) {
        return true;
    }
    return in_array((string)($sheet['status'] ?? ''), ['submitted', 'approved'], true);
}

// Handle GET AJAX actions
if (isset($_GET['action']) && $_GET['action'] === 'score_data') {
    header('Content-Type: application/json; charset=utf-8');
    $entryId = (int)($_GET['entry_id'] ?? 0);

    try {
        $program = program_scores_load_program($pdo, $activeEventId, $programId);
        if (!$program) {
            throw new RuntimeException('Program not found.');
        }

        $stmt = $pdo->prepare("
            SELECT pe.*, t.team_name, t.team_color
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.id = ?
              AND pe.event_id = ?
              AND pe.program_id = ?
            LIMIT 1
        ");
        $stmt->execute([$entryId, $activeEventId, $programId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            echo json_encode(['success' => false, 'message' => 'Entry not found.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $categories = program_scores_load_categories($pdo, $programId);
        $categoryTotal = program_scores_categories_total($categories);
        $categoriesValid = $categories && abs($categoryTotal - 100.0) <= 0.01;

        $stmt = $pdo->prepare('SELECT * FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
        $stmt->execute([$entryId]);
        $sheet = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $scores = [];
        if ($sheet) {
            $stmt = $pdo->prepare("
                SELECT judge_no, category_id, score
                FROM musabaqa_category_scores
                WHERE score_sheet_id = ?
            ");
            $stmt->execute([(int)$sheet['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $score) {
                $scores[(int)$score['judge_no']][(int)$score['category_id']] = (string)$score['score'];
            }
        }

        echo json_encode([
            'success' => true,
            'entry' => $entry,
            'program' => $program,
            'categories' => $categories,
            'sheet' => $sheet,
            'scores' => $scores,
            'locked' => program_scores_entry_locked($program, $sheet),
            'categories_valid' => $categoriesValid,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load score sheet: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        if ($programId > 0) {
            program_scores_redirect($programId);
        } else {
            upload_scores_redirect();
        }
    }

    $action = (string)($_POST['action'] ?? '');
    $program = program_scores_load_program($pdo, $activeEventId, $programId);

    if (!$program) {
        admin_flash('error', 'Program not found.');
        upload_scores_redirect();
    }

    $categories = program_scores_load_categories($pdo, $programId);
    $categoryTotal = program_scores_categories_total($categories);
    $categoriesValid = $categories && abs($categoryTotal - 100.0) <= 0.01;

    try {
        if ($action === 'submit_program') {
            $pdo->beginTransaction();
            admin_submit_program_for_approval($pdo, $activeEventId, $programId, $currentUserId);
            $pdo->commit();
            admin_flash('success', 'Program scores sent for approval.');
            program_scores_redirect($programId);
        }

        if ($action === 'save_score_sheet') {
            if (!$categoriesValid) {
                throw new RuntimeException('Program categories must total exactly 100 before scoring.');
            }
            if (in_array((string)$program['approval_status'], ['submitted', 'approved'], true)) {
                throw new RuntimeException('Submitted or approved program scores are locked.');
            }

            $entryId = (int)($_POST['entry_id'] ?? 0);
            $postedScores = (array)($_POST['scores'] ?? []);

            $stmt = $pdo->prepare("
                SELECT pe.id
                FROM musabaqa_program_entries pe
                WHERE pe.id = ?
                  AND pe.event_id = ?
                  AND pe.program_id = ?
                LIMIT 1
            ");
            $stmt->execute([$entryId, $activeEventId, $programId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Entry not found for this program.');
            }

            $judgeTotals = [1 => 0.0, 2 => 0.0];
            $categoryMap = [];
            foreach ($categories as $category) {
                $categoryMap[(int)$category['id']] = $category;
            }

            foreach ([1, 2] as $judgeNo) {
                foreach ($categoryMap as $categoryId => $category) {
                    $rawScore = $postedScores[$judgeNo][$categoryId] ?? null;
                    if ($rawScore === null || $rawScore === '' || !is_numeric($rawScore)) {
                        throw new RuntimeException('Every judge category score is required.');
                    }

                    $score = (float)$rawScore;
                    $max = (float)$category['max_marks'];
                    if ($score < 0) {
                        throw new RuntimeException('Category score cannot be negative.');
                    }
                    if ($score > $max) {
                        throw new RuntimeException($category['name'] . ' cannot exceed ' . number_format($max, 2) . ' marks.');
                    }

                    $judgeTotals[$judgeNo] += $score;
                }
            }

            $finalTotal = round($judgeTotals[1] + $judgeTotals[2], 2);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
            $stmt->execute([$entryId]);
            $existingSheet = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (program_scores_entry_locked($program, $existingSheet)) {
                throw new RuntimeException('This score sheet is locked.');
            }

            if ($existingSheet) {
                $stmt = $pdo->prepare("
                    UPDATE musabaqa_score_sheets
                    SET program_id = ?,
                        judge1_total = ?,
                        judge2_total = ?,
                        final_total = ?,
                        status = 'completed'
                    WHERE id = ?
                ");
                $stmt->execute([$programId, $judgeTotals[1], $judgeTotals[2], $finalTotal, (int)$existingSheet['id']]);
                $scoreSheetId = (int)$existingSheet['id'];
                $logType = 'score_update';
                $logText = 'Program score sheet updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO musabaqa_score_sheets
                        (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by)
                    VALUES (?, ?, ?, ?, ?, 'completed', ?)
                ");
                $stmt->execute([$entryId, $programId, $judgeTotals[1], $judgeTotals[2], $finalTotal, $currentUserId]);
                $scoreSheetId = (int)$pdo->lastInsertId();
                $logType = 'score_creation';
                $logText = 'Program score sheet created.';
            }

            $pdo->prepare('DELETE FROM musabaqa_category_scores WHERE score_sheet_id = ?')->execute([$scoreSheetId]);
            $insert = $pdo->prepare("
                INSERT INTO musabaqa_category_scores (score_sheet_id, judge_no, category_id, score)
                VALUES (?, ?, ?, ?)
            ");
            foreach ([1, 2] as $judgeNo) {
                foreach ($categoryMap as $categoryId => $category) {
                    $insert->execute([$scoreSheetId, $judgeNo, $categoryId, (float)$postedScores[$judgeNo][$categoryId]]);
                }
            }

            admin_recalculate_entry_status($pdo, $entryId);
            admin_recalculate_program_status($pdo, $programId);
            admin_log_activity($pdo, $currentUserId, $activeEventId, $logType, 'musabaqa_score_sheets', $scoreSheetId, $logText);

            $pdo->commit();
            if (admin_program_ready_for_approval($pdo, $programId)) {
                admin_flash('success', 'All entries scored. Program is ready for submission.');
            } else {
                admin_flash('success', 'Score sheet saved successfully.');
            }
            program_scores_redirect($programId);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to update scores.');
        program_scores_redirect($programId);
    }
}

// Load List of Programs OR details of a Program
$program = null;
$programs = [];
$entries = [];
$categories = [];
$categoriesValid = false;

if ($programId > 0) {
    $program = program_scores_load_program($pdo, $activeEventId, $programId);
}

if ($program) {
    $categories = program_scores_load_categories($pdo, $programId);
    $categoryTotal = program_scores_categories_total($categories);
    $categoriesValid = $categories && abs($categoryTotal - 100.0) <= 0.01;

    // Load program entries with scores
    $stmt = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.team_color,
            ss.id AS score_sheet_id,
            ss.judge1_total,
            ss.judge2_total,
            ss.final_total,
            ss.status AS sheet_status
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE pe.event_id = ?
          AND pe.program_id = ?
        ORDER BY pe.entry_number ASC, pe.id ASC
    ");
    $stmt->execute([$activeEventId, $programId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $readyForSubmission = admin_program_ready_for_approval($pdo, $programId);
    $scoresLocked = in_array((string)$program['approval_status'], ['submitted', 'approved'], true);
    $canSubmit = $readyForSubmission && !$scoresLocked && $categoriesValid;
} else {
    // List programs
    $statusFilter = trim((string)($_GET['status'] ?? 'all'));
    $approvalFilter = trim((string)($_GET['approval'] ?? 'all'));
    $classFilter = trim((string)($_GET['class'] ?? 'all'));
    $search = trim((string)($_GET['search'] ?? ''));

    $where = 'WHERE p.event_id = ?';
    $params = [$activeEventId];

    if ($search !== '') {
        $where .= ' AND (p.title LIKE ? OR p.location LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like);
    }
    if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'scoring', 'completed'], true)) {
        $where .= ' AND p.status = ?';
        $params[] = $statusFilter;
    }
    if ($approvalFilter !== 'all' && in_array($approvalFilter, ['none', 'submitted', 'rejected', 'approved'], true)) {
        $where .= ' AND p.approval_status = ?';
        $params[] = $approvalFilter;
    }
    [$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'p');
    $where .= $classSql;
    array_push($params, ...$classParams);

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            ct.name AS class_type_name,
            COUNT(DISTINCT pe.id) AS entry_count,
            COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count,
            COALESCE(category_data.category_count, 0) AS category_count,
            COALESCE(category_data.category_total, 0) AS category_total
        FROM musabaqa_programs p
        LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        LEFT JOIN (
            SELECT program_id, COUNT(*) AS category_count, SUM(max_marks) AS category_total
            FROM musabaqa_program_categories
            GROUP BY program_id
        ) category_data ON category_data.program_id = p.id
        {$where}
        GROUP BY p.id
        ORDER BY COALESCE(p.start_time, '9999-12-31') ASC, p.title ASC
    ");
    $stmt->execute($params);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$useTopNavigation = true;
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/event-role-sidebar.php';
?>
<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">

<style>
.score-entry-layout {
    display: grid;
    grid-template-columns: 1fr 1.2fr;
    gap: 24px;
    align-items: start;
}
@media(max-width: 960px) {
    .score-entry-layout {
        grid-template-columns: 1fr;
    }
}
.score-category-stack {
    display: grid;
    gap: 12px;
}
</style>

<main class="main-content event-workspace-content">
    <?php if ($program): ?>
        <!-- SCORE WORKSPACE PANEL -->
        <section class="workspace-hero">
            <div>
                <span class="eyebrow"><i class="fa-solid fa-pen-to-square"></i> Upload Scores</span>
                <h1><?= e($program['title']) ?></h1>
                <p>
                    <?= e(ucfirst((string)$program['program_type'])) ?> &middot; 
                    <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                </p>
            </div>
            <div class="hero-actions">
                <a href="<?= app_url('/admin/event/upload-scores.php') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-arrow-left"></i> Back to Programs
                </a>
                <?php if ($canSubmit): ?>
                    <form method="POST" style="display: inline-block;">
                        <?= admin_csrf_field() ?>
                        <input type="hidden" name="action" value="submit_program">
                        <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
                        <button class="btn btn-success btn-md" type="submit">
                            <i class="fa-solid fa-paper-plane"></i> Send for Approval
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$categoriesValid): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i> 
                Scoring categories must sum to exactly 100. Current total is <strong><?= (float)$categoryTotal ?></strong>. Please ask an Administrator to update them.
            </div>
        <?php endif; ?>

        <div class="score-entry-layout">
            <!-- Left Side: Participants list -->
            <div class="panel">
                <div class="page-subtitle mb-4">Participant Entries</div>
                <?php if (!$entries): ?>
                    <p class="text-muted text-sm">No team entries registered for this program.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Chest</th>
                                    <th>Name</th>
                                    <th>Team</th>
                                    <th>Score (200)</th>
                                    <th style="width: 80px; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $entry): ?>
                                    <?php $hasSheet = !empty($entry['score_sheet_id']); ?>
                                    <tr>
                                        <td><strong>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                                        <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                                        <td>
                                            <span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;">
                                                <?= e($entry['team_name']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $hasSheet ? '<strong>' . e(number_format((float)$entry['final_total'], 2)) . '</strong>' : '<span class="text-muted text-xs">Missing</span>' ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <button class="btn btn-primary btn-xs" type="button" data-score-entry="<?= (int)$entry['id'] ?>" <?= $categoriesValid ? '' : 'disabled' ?>>
                                                <?= $hasSheet ? ($scoresLocked ? 'View' : 'Edit') : 'Score' ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Side: Score Card Entry -->
            <div class="panel hidden" id="scorePanel">
                <div class="flex justify-between items-center mb-4" style="border-bottom: 1px solid var(--border); padding-bottom: 12px;">
                    <div>
                        <div class="modal-title" id="scorePanelTitle">Score Entry</div>
                        <div class="page-subtitle" id="scorePanelSubtitle"></div>
                    </div>
                    <button type="button" class="modal-close" id="closeScorePanel"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <form method="POST">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="save_score_sheet">
                    <input type="hidden" name="entry_id" id="scoreEntryId">
                    <input type="hidden" name="program_id" value="<?= (int)$programId ?>">

                    <div class="form-grid mb-6">
                        <div class="input-group">
                            <label>Participant</label>
                            <input type="text" class="form-control" id="panelEntryName" readonly>
                        </div>
                        <div class="input-group">
                            <label>Team</label>
                            <input type="text" class="form-control" id="panelTeamName" readonly>
                        </div>
                        <div class="input-group full-width">
                            <label>Chest Number</label>
                            <input type="text" class="form-control" id="panelEntryNumber" readonly>
                        </div>
                    </div>

                    <div id="judgeScoreBlocks" class="grid gap-6"></div>

                    <div class="panel mt-6" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.08);">
                        <div class="flex justify-between items-center">
                            <div>
                                <strong>Final Combined Total Score</strong>
                                <div class="text-xs text-muted">Sum of Judge 1 and Judge 2 scores (Max 200)</div>
                            </div>
                            <span class="badge badge-success" style="font-size: 18px; padding: 8px 16px;" id="finalTotal">0.00</span>
                        </div>
                    </div>

                    <div class="form-actions mt-6">
                        <button type="button" class="btn btn-secondary btn-md" id="cancelScorePanel">Cancel</button>
                        <button type="submit" class="btn btn-success btn-md" id="saveScoreButton">Save Score Sheet</button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- ALL PROGRAMS SCORES HUB -->
        <section class="workspace-hero">
            <div>
                <span class="eyebrow"><i class="fa-solid fa-pen-to-square"></i> Upload Scores</span>
                <h1>Scoring Hub</h1>
                <p>Select a program to review and upload judge sheets.</p>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="panel">
            <form method="GET" class="form-grid mb-6">
                <div class="input-group">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <?php foreach (['active', 'scoring', 'completed'] as $status): ?>
                            <option value="<?= $status ?>" <?= ($statusFilter ?? 'all') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Approval</label>
                    <select name="approval" onchange="this.form.submit()">
                        <option value="all">All Approval</option>
                        <?php foreach (['none', 'submitted', 'rejected', 'approved'] as $status): ?>
                            <option value="<?= $status ?>" <?= ($approvalFilter ?? 'all') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Class</label>
                    <select name="class" onchange="this.form.submit()">
                        <option value="all">All Classes</option>
                        <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($classFilter ?? 'all') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Program Title</th>
                            <th>Class Type</th>
                            <th>Categories</th>
                            <th>Status</th>
                            <th>Approval</th>
                            <th>Progress</th>
                            <th style="width: 150px; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$programs): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: var(--muted-2);">
                                    No programs matching the filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($programs as $prog): ?>
                                <?php
                                $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                $catsValid = (int)$prog['category_count'] > 0 && abs((float)$prog['category_total'] - 100.0) <= 0.01;
                                ?>
                                <tr>
                                    <td><strong><?= e($prog['title']) ?></strong></td>
                                    <td>
                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                            <?= e(admin_class_type_display($prog['class_type_name'] ?? null, (int)($prog['class_type_id'] ?? 0))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($catsValid): ?>
                                            <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Ready</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" title="Categories must total exactly 100"><i class="fa-solid fa-triangle-exclamation"></i> Warning</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= score_entry_status_badge($prog['status']) ?>"><?= e(ucfirst($prog['status'])) ?></span></td>
                                    <td><span class="badge <?= score_entry_approval_badge($prog['approval_status']) ?>"><?= e(ucfirst($prog['approval_status'] ?: 'None')) ?></span></td>
                                    <td>
                                        <strong><?= (int)$prog['scored_count'] ?></strong> / <?= (int)$prog['entry_count'] ?> scored
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="?program_id=<?= (int)$prog['id'] ?>" class="btn btn-primary btn-sm">
                                            <i class="fa-solid fa-pen-to-square"></i> Upload Scores
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
(() => {
    const PROGRAM_SCORE_URL = <?= json_encode(app_url('/admin/event/upload-scores.php'), JSON_UNESCAPED_SLASHES) ?>;
    const PROGRAM_ID = <?= (int)$programId ?>;

    function escapeHtml(value){return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
    function formatNumber(num) { return Number(num || 0).toFixed(2); }

    function calculateTotals() {
        const totals = {1: 0, 2: 0};
        document.querySelectorAll('[data-judge-score]').forEach(input => {
            const judge = input.dataset.judgeScore;
            totals[judge] += Number(input.value || 0);
        });
        document.querySelectorAll('[data-judge-total]').forEach(el => {
            el.textContent = formatNumber(totals[el.dataset.judgeTotal] || 0);
        });
        document.getElementById('finalTotal').textContent = formatNumber((totals[1] || 0) + (totals[2] || 0));
    }

    function renderJudgeBlock(judgeNo, categories, scores, locked) {
        let html = `<div class="panel" style="background: rgba(255,255,255,0.01);"><div class="page-subtitle">Judge ${judgeNo} Card</div><div class="score-category-stack mt-4">`;
        categories.forEach(category => {
            const value = scores?.[judgeNo]?.[category.id] ?? '';
            html += `
                <div class="input-group">
                    <label>${escapeHtml(category.name)} <span class="text-muted text-xs">/ max ${formatNumber(category.max_marks)}</span></label>
                    <input type="number"
                           name="scores[${judgeNo}][${escapeHtml(category.id)}]"
                           class="form-control"
                           min="0"
                           max="${escapeHtml(category.max_marks)}"
                           step="0.01"
                           value="${escapeHtml(value)}"
                           data-judge-score="${judgeNo}"
                           ${locked ? 'readonly' : 'required'}>
                </div>`;
        });
        html += `</div><div class="flex-between mt-4" style="border-top: 1px solid var(--border); padding-top: 10px; display: flex; justify-content: space-between; align-items: center;"><strong>Judge ${judgeNo} subtotal</strong><span class="badge badge-neutral" data-judge-total="${judgeNo}">0.00</span></div></div>`;
        return html;
    }

    async function openScoreModal(entryId) {
        const panel = document.getElementById('scorePanel');
        if (!panel) return;
        panel.classList.remove('hidden');
        document.getElementById('judgeScoreBlocks').innerHTML = '<div class="panel">Loading score details...</div>';
        document.getElementById('saveScoreButton').disabled = true;
        panel.scrollIntoView({behavior: 'smooth', block: 'start'});

        try {
            const response = await fetch(`${PROGRAM_SCORE_URL}?program_id=${PROGRAM_ID}&action=score_data&entry_id=${encodeURIComponent(entryId)}`, {cache: 'no-store'});
            const data = await response.json();
            if (!data.success) throw new Error(data.message || 'Unable to load score sheet.');

            document.getElementById('scoreEntryId').value = data.entry.id || '';
            document.getElementById('scorePanelTitle').textContent = data.locked ? 'View Score Sheet' : 'Enter Scores';
            document.getElementById('scorePanelSubtitle').textContent = data.program.title || '';
            document.getElementById('panelEntryName').value = data.entry.entry_name || 'Unnamed Entry';
            document.getElementById('panelTeamName').value = data.entry.team_name || '';
            document.getElementById('panelEntryNumber').value = data.entry.entry_number ? `#${String(data.entry.entry_number).padStart(3, '0')}` : '';
            
            document.getElementById('judgeScoreBlocks').innerHTML =
                renderJudgeBlock(1, data.categories || [], data.scores || {}, data.locked) +
                renderJudgeBlock(2, data.categories || [], data.scores || {}, data.locked);
                
            document.getElementById('saveScoreButton').style.display = data.locked ? 'none' : '';
            document.getElementById('saveScoreButton').disabled = !!data.locked;
            document.querySelectorAll('[data-judge-score]').forEach(input => input.addEventListener('input', calculateTotals));
            calculateTotals();
        } catch (error) {
            document.getElementById('judgeScoreBlocks').innerHTML = `<div class="alert alert-error">${escapeHtml(error.message)}</div>`;
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-score-entry]');
        if (btn) {
            openScoreModal(btn.dataset.scoreEntry);
        }
    });

    document.getElementById('closeScorePanel')?.addEventListener('click', () => document.getElementById('scorePanel')?.classList.add('hidden'));
    document.getElementById('cancelScorePanel')?.addEventListener('click', () => document.getElementById('scorePanel')?.classList.add('hidden'));
})();
</script>

<?php admin_close_page(); ?>
