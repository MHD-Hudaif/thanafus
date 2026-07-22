<?php
$pageTitle = 'Program Scores';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);

function program_scores_redirect(int $programId): void
{
    admin_redirect('/admin/program-scores.php', ['program_id' => $programId]);
}

function program_scores_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-info',
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

if ($programId <= 0) {
    admin_flash('error', 'Select a program before scoring.');
    admin_redirect('/admin/score-entry.php');
}

$program = program_scores_load_program($pdo, $activeEventId, $programId);
if (!$program) {
    admin_flash('error', 'Program not found for the active event.');
    admin_redirect('/admin/score-entry.php');
}

$categories = program_scores_load_categories($pdo, $programId);
$categoryTotal = program_scores_categories_total($categories);
$categoriesValid = $categories && abs($categoryTotal - 100.0) <= 0.01;

if (isset($_GET['action']) && $_GET['action'] === 'score_data') {
    header('Content-Type: application/json; charset=utf-8');
    $entryId = (int)($_GET['entry_id'] ?? 0);

    try {
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
        echo json_encode(['success' => false, 'message' => 'Unable to load score sheet.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        program_scores_redirect($programId);
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_categories') {
            if (!program_scores_categories_editable($program)) {
                throw new RuntimeException('Categories are read-only after program submission or approval.');
            }

            $ids = (array)($_POST['category_id'] ?? []);
            $names = (array)($_POST['category_name'] ?? []);
            $marks = (array)($_POST['category_marks'] ?? []);
            $orders = (array)($_POST['category_sort_order'] ?? []);
            $rows = [];
            $total = 0.0;

            foreach ($names as $index => $name) {
                $name = trim((string)$name);
                $max = (float)($marks[$index] ?? 0);
                $sortOrder = (int)($orders[$index] ?? ($index + 1));
                $categoryId = (int)($ids[$index] ?? 0);

                if ($name === '' && $max <= 0) {
                    continue;
                }
                if ($name === '' || $max <= 0) {
                    throw new RuntimeException('Every category needs a name and positive max marks.');
                }
                if ($max > 100) {
                    throw new RuntimeException('A category maximum cannot exceed 100.');
                }
                if ($sortOrder <= 0) {
                    $sortOrder = count($rows) + 1;
                }

                $total += $max;
                $rows[] = [
                    'id' => $categoryId,
                    'name' => $name,
                    'max_marks' => $max,
                    'sort_order' => $sortOrder,
                ];
            }

            if (!$rows) {
                throw new RuntimeException('Add at least one scoring category.');
            }
            if (abs($total - 100.0) > 0.01) {
                throw new RuntimeException('Category max marks must total exactly 100.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id FROM musabaqa_program_categories WHERE program_id = ?');
            $stmt->execute([$programId]);
            $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $keptIds = [];

            $update = $pdo->prepare('UPDATE musabaqa_program_categories SET name = ?, max_marks = ?, sort_order = ? WHERE id = ? AND program_id = ?');
            $insert = $pdo->prepare('INSERT INTO musabaqa_program_categories (program_id, name, max_marks, sort_order) VALUES (?, ?, ?, ?)');

            foreach ($rows as $row) {
                if ($row['id'] > 0 && in_array($row['id'], $existingIds, true)) {
                    $update->execute([$row['name'], $row['max_marks'], $row['sort_order'], $row['id'], $programId]);
                    $keptIds[] = $row['id'];
                } else {
                    $insert->execute([$programId, $row['name'], $row['max_marks'], $row['sort_order']]);
                    $keptIds[] = (int)$pdo->lastInsertId();
                }
            }

            $deleteIds = array_diff($existingIds, $keptIds);
            if ($deleteIds) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM musabaqa_program_categories WHERE program_id = ? AND id IN ($placeholders)");
                $stmt->execute(array_merge([$programId], array_values($deleteIds)));
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
            admin_log_activity($pdo, $currentUserId, $activeEventId, 'category_update', 'musabaqa_program_categories', $programId, 'Program scoring categories updated.');

            $pdo->commit();
            admin_flash('success', 'Categories saved. Existing editable score sheets were reset for re-scoring.');
            program_scores_redirect($programId);
        }

        if ($action === 'submit_program') {
            $pdo->beginTransaction();
            admin_submit_program_for_approval($pdo, $activeEventId, $programId, $currentUserId);
            $pdo->commit();
            admin_flash('success', 'Program sent for approval.');
            program_scores_redirect($programId);
        }

        if ($action !== 'save_score_sheet') {
            throw new RuntimeException('Invalid scoring action.');
        }

        if (!$categoriesValid) {
            throw new RuntimeException('Program categories must total exactly 100 before scoring.');
        }
        if (in_array((string)$program['approval_status'], ['submitted', 'approved'], true)) {
            throw new RuntimeException('Submitted or approved program scores are locked.');
        }

        $entryId = (int)($_POST['entry_id'] ?? 0);
        $postedScores = (array)($_POST['scores'] ?? []);

        $stmt = $pdo->prepare("
            SELECT pe.id, pe.program_id
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

        $judgesCount = (int)($program['judges_count'] ?? 2);
        $judgeTotals = [];
        for ($j = 1; $j <= $judgesCount; $j++) {
            $judgeTotals[$j] = 0.0;
        }

        $categoryMap = [];
        foreach ($categories as $category) {
            $categoryMap[(int)$category['id']] = $category;
        }

        for ($judgeNo = 1; $judgeNo <= $judgesCount; $judgeNo++) {
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

        $finalTotal = round(array_sum($judgeTotals), 2);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
        $stmt->execute([$entryId]);
        $existingSheet = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (program_scores_entry_locked($program, $existingSheet)) {
            throw new RuntimeException('This score sheet is locked.');
        }

        $judge1Total = $judgeTotals[1] ?? 0.0;
        $judge2Total = $judgeTotals[2] ?? 0.0;

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
            $stmt->execute([$programId, $judge1Total, $judge2Total, $finalTotal, (int)$existingSheet['id']]);
            $scoreSheetId = (int)$existingSheet['id'];
            $logType = 'score_update';
            $logText = 'Program score sheet updated.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_score_sheets
                    (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by)
                VALUES (?, ?, ?, ?, ?, 'completed', ?)
            ");
            $stmt->execute([$entryId, $programId, $judge1Total, $judge2Total, $finalTotal, $currentUserId]);
            $scoreSheetId = (int)$pdo->lastInsertId();
            $logType = 'score_creation';
            $logText = 'Program score sheet created.';
        }

        $pdo->prepare('DELETE FROM musabaqa_category_scores WHERE score_sheet_id = ?')->execute([$scoreSheetId]);
        $insert = $pdo->prepare("
            INSERT INTO musabaqa_category_scores (score_sheet_id, judge_no, category_id, score)
            VALUES (?, ?, ?, ?)
        ");
        for ($judgeNo = 1; $judgeNo <= $judgesCount; $judgeNo++) {
            foreach ($categoryMap as $categoryId => $category) {
                $insert->execute([$scoreSheetId, $judgeNo, $categoryId, (float)$postedScores[$judgeNo][$categoryId]]);
            }
        }

        admin_recalculate_entry_status($pdo, $entryId);
        admin_recalculate_program_status($pdo, $programId);
        admin_log_activity($pdo, $currentUserId, $activeEventId, $logType, 'musabaqa_score_sheets', $scoreSheetId, $logText);

        $pdo->commit();
        if (admin_program_ready_for_approval($pdo, $programId)) {
            admin_flash('ready', 'All entries for this program have been scored. This program is ready for submission.');
        } else {
            admin_flash('success', 'Score sheet saved.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to save score sheet.');
    }

    program_scores_redirect($programId);
}

$flash = admin_take_flash();
$entrySearch = trim((string)($_GET['search'] ?? ''));

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
$rawEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
$entries = [];
foreach ($rawEntries as $entry) {
    $statusText = $entry['status'] ?? '';
    $scoreText = !empty($entry['score_sheet_id']) ? (string)($entry['final_total'] ?? '') : 'missing';
    if (
        $entrySearch !== ''
        && stripos((string)($entry['entry_number'] ?? ''), $entrySearch) === false
        && stripos((string)($entry['entry_name'] ?? ''), $entrySearch) === false
        && stripos((string)($entry['team_name'] ?? ''), $entrySearch) === false
        && stripos((string)$statusText, $entrySearch) === false
        && stripos((string)$scoreText, $entrySearch) === false
    ) {
        continue;
    }
    $entries[] = $entry;
}

$totalEntries = count($entries);

if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['program_scores_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['program_scores_limit']) ? $_SESSION['program_scores_limit'] : 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedEntries = array_slice($entries, $offset, $perPage);

$readyForSubmission = admin_program_ready_for_approval($pdo, $programId);
$scoresLocked = in_array((string)$program['approval_status'], ['submitted', 'approved'], true);
$categoriesEditable = program_scores_categories_editable($program);
$canSubmit = $readyForSubmission && !$scoresLocked && $categoriesValid;

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedEntries) {
        echo '<tr><td colspan="6" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Entries Found</div></td></tr>';
    } else {
        foreach ($paginatedEntries as $entry) {
            $hasSheet = !empty($entry['score_sheet_id']);
            ?>
            <tr>
                <td><strong>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                <td><span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;"><?= e($entry['team_name']) ?></span></td>
                <td><span class="badge <?= program_scores_badge($entry['status']) ?>"><?= e(ucfirst((string)$entry['status'])) ?></span></td>
                <td><?= $hasSheet ? e(number_format((float)$entry['final_total'], 2)) : '<span class="badge badge-neutral">Missing</span>' ?></td>
                <td>
                    <button class="btn btn-secondary btn-sm" type="button" data-score-entry="<?= (int)$entry['id'] ?>" <?= $categoriesValid ? '' : 'disabled' ?>>
                        <i class="fa-solid fa-pen-to-square"></i> <?= $hasSheet ? ($scoresLocked ? 'View' : 'Edit') : 'Score' ?>
                    </button>
                </td>
            </tr>
            <?php
        }
    }
    $tbodyHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'html' => $tbodyHtml,
        'pagination' => admin_render_pagination_html($page, $perPage, $totalEntries)
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Program Scores</div>
            <div class="page-subtitle">
                <?= e($program['title']) ?>
                · <?= e(ucfirst((string)$program['program_type'])) ?>
                · <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
            </div>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/score-entry.php') ?>"><i class="fa-solid fa-arrow-left"></i> Programs</a>
            <form method="POST">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="submit_program">
                <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
                <button class="btn btn-success btn-md <?= $canSubmit ? 'ready-submit' : '' ?>" id="sendApprovalButton" type="submit" <?= $canSubmit ? '' : 'disabled' ?>>
                    <i class="fa-solid fa-paper-plane"></i> Send For Approval
                </button>
            </form>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= in_array($flash['type'], ['success', 'ready'], true) ? 'alert-success' : 'alert-error' ?>" id="<?= $flash['type'] === 'ready' ? 'programReadyAlert' : '' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$categoriesValid): ?>
        <div class="alert alert-warning">Program categories must total exactly 100 before scores can be saved.</div>
    <?php endif; ?>

    <div class="grid grid-auto gap-5 mb-6">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-list-check"></i></div><div class="stat-value"><?= count($entries) ?></div><div class="stat-label">Entries</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-clipboard-check"></i></div><div class="stat-value"><?= count(array_filter($entries, static fn ($row) => in_array((string)($row['sheet_status'] ?? ''), ['completed','submitted','approved','rejected'], true))) ?></div><div class="stat-label">Scored</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-sliders"></i></div><div class="stat-value"><?= number_format($categoryTotal, 2) ?></div><div class="stat-label">Category Total</div></div>
    </div>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" id="search-form">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
            <div class="input-group full-width">
                <label>Search Entries</label>
                <input type="text" name="search" value="<?= e($entrySearch) ?>" placeholder="Entry number, entry name, team, status or score">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($entrySearch !== ''): ?>
                    <a href="<?= app_url('/admin/program-scores.php') ?>?program_id=<?= (int)$programId ?>" class="btn btn-secondary btn-md">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel mb-6">
        <div class="flex-between">
            <div>
                <div class="dashboard-heading">Scoring Categories</div>
                <div class="page-subtitle">
                    <?= $categoriesEditable ? 'Add, edit, delete, and reorder categories before scoring approval.' : 'Categories are read-only for submitted or approved programs.' ?>
                </div>
            </div>
            <span class="badge <?= $categoriesValid ? 'badge-success' : 'badge-danger' ?>">Total <?= e(number_format($categoryTotal, 2)) ?> / 100</span>
        </div>

        <form method="POST" id="categoryForm" class="mt-4">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_categories">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Maximum Marks</th>
                            <th>Sort Order</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="categoryRows">
                        <?php foreach ($categories as $category): ?>
                            <tr class="category-row">
                                <td>
                                    <input type="hidden" name="category_id[]" value="<?= (int)$category['id'] ?>">
                                    <input type="text" name="category_name[]" value="<?= e($category['name']) ?>" <?= $categoriesEditable ? 'required' : 'readonly' ?>>
                                </td>
                                <td><input type="number" name="category_marks[]" min="0" max="100" step="0.01" value="<?= e((string)$category['max_marks']) ?>" <?= $categoriesEditable ? 'required' : 'readonly' ?>></td>
                                <td><input type="number" name="category_sort_order[]" min="1" step="1" value="<?= (int)$category['sort_order'] ?>" <?= $categoriesEditable ? 'required' : 'readonly' ?>></td>
                                <td>
                                    <button class="btn btn-danger btn-sm" type="button" data-remove-category <?= $categoriesEditable ? '' : 'disabled' ?>>
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex-between mt-4">
                <button class="btn btn-secondary btn-sm" type="button" id="addCategoryRow" <?= $categoriesEditable ? '' : 'disabled' ?>>
                    <i class="fa-solid fa-plus"></i> Add Category
                </button>
                <div class="badge badge-neutral">Total: <span id="categoryTotalLive"><?= e(number_format($categoryTotal, 2)) ?></span> / 100</div>
            </div>

            <?php if ($categoriesEditable): ?>
                <div class="form-actions">
                    <button class="btn btn-success btn-md" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Categories</button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel mb-6 hidden" id="scorePanel">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="scorePanelTitle">Score Entry</div>
                <div class="page-subtitle" id="scorePanelSubtitle"></div>
            </div>
            <button class="modal-close" type="button" id="closeScorePanel"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="scoreForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_score_sheet">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
            <input type="hidden" name="entry_id" id="scoreEntryId">

            <div class="panel mb-6">
                <div class="grid grid-auto gap-4">
                    <div class="input-group"><label>Program</label><input type="text" value="<?= e($program['title']) ?>" readonly></div>
                    <div class="input-group"><label>Entry</label><input type="text" id="panelEntryName" readonly></div>
                    <div class="input-group"><label>Team</label><input type="text" id="panelTeamName" readonly></div>
                    <div class="input-group"><label>Entry Number</label><input type="text" id="panelEntryNumber" readonly></div>
                </div>
            </div>

            <div id="judgeScoreBlocks" class="grid grid-2 gap-4"></div>

            <div class="panel mt-4">
                <div class="flex-between">
                    <div><strong>Final Score</strong><div class="field-help">Judge 1 total + Judge 2 total</div></div>
                    <div class="stat-value" id="finalTotal">0.00</div>
                </div>
            </div>

            <div class="form-actions form-actions-between">
                <button class="btn btn-secondary btn-md" type="button" id="cancelScorePanel">Cancel</button>
                <button class="btn btn-success btn-md" type="submit" id="saveScoreButton">Save Score</button>
            </div>
        </form>
    </div>

    <?php if (!$entries): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-list-check"></i></div><div class="empty-title">No Entries Found</div><div class="empty-subtitle"><?= $entrySearch !== '' ? 'No entries match your search.' : 'Add entries to this program before scoring.' ?></div></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Entry Number</th>
                        <th>Entry Name</th>
                        <th>Team</th>
                        <th>Status</th>
                        <th>Final Score</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php foreach ($paginatedEntries as $entry): ?>
                        <?php $hasSheet = !empty($entry['score_sheet_id']); ?>
                        <tr>
                            <td><strong>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                            <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                            <td><span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;"><?= e($entry['team_name']) ?></span></td>
                            <td><span class="badge <?= program_scores_badge($entry['status']) ?>"><?= e(ucfirst((string)$entry['status'])) ?></span></td>
                            <td><?= $hasSheet ? e(number_format((float)$entry['final_total'], 2)) : '<span class="badge badge-neutral">Missing</span>' ?></td>
                            <td>
                                <button class="btn btn-secondary btn-sm" type="button" data-score-entry="<?= (int)$entry['id'] ?>" <?= $categoriesValid ? '' : 'disabled' ?>>
                                    <i class="fa-solid fa-pen-to-square"></i> <?= $hasSheet ? ($scoresLocked ? 'View' : 'Edit') : 'Score' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="pagination-container">
            <?= admin_render_pagination_html($page, $perPage, $totalEntries) ?>
        </div>
    <?php endif; ?>


<style>
@keyframes submitPulse {
    0% { box-shadow: 0 0 0 0 rgba(20, 184, 166, 0.7); transform: translateY(0); }
    55% { box-shadow: 0 0 0 14px rgba(20, 184, 166, 0); transform: translateY(-1px); }
    100% { box-shadow: 0 0 0 0 rgba(20, 184, 166, 0); transform: translateY(0); }
}
.ready-submit {
    animation: submitPulse 1.2s ease-in-out 5;
    outline: 2px solid rgba(20, 184, 166, 0.75);
    outline-offset: 3px;
}
.score-category-stack {
    display: grid;
    gap: 12px;
}
</style>

<script>
const PROGRAM_SCORE_URL = <?= json_encode(app_url('/admin/program-scores.php?program_id=' . $programId), JSON_UNESCAPED_SLASHES) ?>;
const CATEGORIES_EDITABLE = <?= json_encode($categoriesEditable) ?>;

function escapeHtml(value){return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
function formatNumber(value){const n = Number(value); return Number.isFinite(n) ? n.toFixed(2) : '0.00'}

function refreshCategoryTotal() {
    const total = Array.from(document.querySelectorAll('input[name="category_marks[]"]')).reduce((sum, input) => sum + Number(input.value || 0), 0);
    const target = document.getElementById('categoryTotalLive');
    if (target) target.textContent = formatNumber(total);
}

function bindCategoryControls() {
    document.querySelectorAll('input[name="category_marks[]"]').forEach(input => input.oninput = refreshCategoryTotal);
    document.querySelectorAll('[data-remove-category]').forEach(button => {
        button.onclick = () => {
            if (!CATEGORIES_EDITABLE) return;
            button.closest('tr')?.remove();
            refreshCategoryTotal();
        };
    });
}

function categoryRow() {
    return `
        <tr class="category-row">
            <td>
                <input type="hidden" name="category_id[]" value="0">
                <input type="text" name="category_name[]" required>
            </td>
            <td><input type="number" name="category_marks[]" min="0" max="100" step="0.01" required></td>
            <td><input type="number" name="category_sort_order[]" min="1" step="1" value="${document.querySelectorAll('.category-row').length + 1}" required></td>
            <td><button class="btn btn-danger btn-sm" type="button" data-remove-category><i class="fa-solid fa-trash"></i> Delete</button></td>
        </tr>
    `;
}

document.getElementById('addCategoryRow')?.addEventListener('click', () => {
    if (!CATEGORIES_EDITABLE) return;
    document.getElementById('categoryRows')?.insertAdjacentHTML('beforeend', categoryRow());
    bindCategoryControls();
    refreshCategoryTotal();
});

bindCategoryControls();
refreshCategoryTotal();

function calculateTotals() {
    const totals = {};
    document.querySelectorAll('[data-judge-score]').forEach(input => {
        const judge = input.dataset.judgeScore;
        totals[judge] = (totals[judge] || 0) + Number(input.value || 0);
    });
    document.querySelectorAll('[data-judge-total]').forEach(el => {
        el.textContent = formatNumber(totals[el.dataset.judgeTotal] || 0);
    });
    const sum = Object.values(totals).reduce((a, b) => a + b, 0);
    document.getElementById('finalTotal').textContent = formatNumber(sum);
}

function renderJudgeBlock(judgeNo, categories, scores, locked) {
    let html = `<div class="panel"><div class="dashboard-heading">Judge ${judgeNo}</div><div class="score-category-stack mt-4">`;
    categories.forEach(category => {
        const value = scores?.[judgeNo]?.[category.id] ?? '';
        html += `
            <div class="input-group">
                <label>${escapeHtml(category.name)} <span class="muted">/ ${formatNumber(category.max_marks)}</span></label>
                <input type="number"
                       name="scores[${judgeNo}][${escapeHtml(category.id)}]"
                       min="0"
                       max="${escapeHtml(category.max_marks)}"
                       step="0.01"
                       value="${escapeHtml(value)}"
                       data-judge-score="${judgeNo}"
                       ${locked ? 'readonly' : 'required'}>
            </div>`;
    });
    html += `</div><div class="flex-between mt-4"><strong>Judge ${judgeNo} subtotal</strong><span class="badge badge-neutral" data-judge-total="${judgeNo}">0.00</span></div></div>`;
    return html;
}

async function openScoreModal(entryId) {
    const panel = document.getElementById('scorePanel');
    panel.classList.remove('hidden');
    document.getElementById('judgeScoreBlocks').innerHTML = '<div class="panel">Loading...</div>';
    document.getElementById('saveScoreButton').disabled = true;
    panel.scrollIntoView({behavior: 'smooth', block: 'start'});

    try {
        const response = await fetch(`${PROGRAM_SCORE_URL}&action=score_data&entry_id=${encodeURIComponent(entryId)}`, {cache: 'no-store'});
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Unable to load score sheet.');

        document.getElementById('scoreEntryId').value = data.entry.id || '';
        document.getElementById('scorePanelTitle').textContent = data.locked ? 'View Score Sheet' : 'Score Entry';
        document.getElementById('scorePanelSubtitle').textContent = data.program.title || '';
        document.getElementById('panelEntryName').value = data.entry.entry_name || 'Unnamed Entry';
        document.getElementById('panelTeamName').value = data.entry.team_name || '';
        document.getElementById('panelEntryNumber').value = data.entry.entry_number ? `#${String(data.entry.entry_number).padStart(3, '0')}` : '';
        
        const judgesCount = Number(data.program.judges_count || 2);
        let blocksHtml = '';
        for (let j = 1; j <= judgesCount; j++) {
            blocksHtml += renderJudgeBlock(j, data.categories || [], data.scores || {}, data.locked);
        }
        document.getElementById('judgeScoreBlocks').innerHTML = blocksHtml;
        
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
if (document.getElementById('programReadyAlert')) {
    setTimeout(() => {
        document.getElementById('sendApprovalButton')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    }, 350);
}
</script>
</div>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
