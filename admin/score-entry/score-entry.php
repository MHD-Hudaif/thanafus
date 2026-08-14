<?php
$pageTitle = 'Score Entry';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);

function score_entry_redirect(int $programId): void
{
    admin_redirect('/admin/score-entry/score-entry.php', ['program_id' => $programId]);
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

// ---------------------------------------------------------
// IF PROGRAM_ID > 0: INTERACTIVE SCORING SHEET FOR PROGRAM
// ---------------------------------------------------------
if ($programId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name,
               mss.id AS schedule_section_id, mss.name AS schedule_section_name,
               mss.start_time AS schedule_section_start, mss.end_time AS schedule_section_end,
               mss.section_date AS schedule_section_date, mss.sort_order AS schedule_section_sort
        FROM musabaqa_programs p
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
        LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
        WHERE p.id = ? AND p.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $activeEventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$program) {
        admin_flash('error', 'Program not found.');
        admin_redirect('/admin/score-entry/score-entry.php');
    }

    $stmtCat = $pdo->prepare("
        SELECT id, name, max_marks, sort_order
        FROM musabaqa_program_categories
        WHERE program_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmtCat->execute([$programId]);
    $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

    $categoryTotal = array_reduce($categories, static fn ($sum, $row) => $sum + (float)$row['max_marks'], 0.0);
    $categoriesValid = $categories && abs($categoryTotal - 100.0) <= 0.01;
    $judgesCount = (int)($program['judges_count'] ?? 2);
    $scoresLocked = in_array((string)$program['approval_status'], ['submitted', 'approved'], true);

    // AJAX Endpoint: Fetch Entry Score Data
    if (isset($_GET['action']) && $_GET['action'] === 'score_data') {
        header('Content-Type: application/json; charset=utf-8');
        $entryId = (int)($_GET['entry_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("
                SELECT pe.*, t.team_name, t.team_color,
                       " . admin_entry_chest_number_subquery() . "
                FROM musabaqa_program_entries pe
                JOIN musabaqa_teams t ON t.id = pe.team_id
                WHERE pe.id = ? AND pe.event_id = ? AND pe.program_id = ?
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
                $stmt = $pdo->prepare("SELECT judge_no, category_id, score FROM musabaqa_category_scores WHERE score_sheet_id = ?");
                $stmt->execute([(int)$sheet['id']]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $score) {
                    $scores[(int)$score['judge_no']][(int)$score['category_id']] = (string)$score['score'];
                }
            }

            echo json_encode([
                'success' => true,
                'entry' => $entry,
                'scores' => $scores,
                'locked' => $scoresLocked,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to load score sheet.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // POST Form / AJAX Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['ajax']);

        try {
            if ($action === 'submit_program') {
                $unscoredStmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM musabaqa_program_entries pe
                    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
                    WHERE pe.program_id = ? AND pe.event_id = ? 
                      AND (ss.id IS NULL OR ss.status = 'draft')
                ");
                $unscoredStmt->execute([$programId, $activeEventId]);
                $unscoredCount = (int)$unscoredStmt->fetchColumn();

                if ($unscoredCount > 0) {
                    throw new RuntimeException('Cannot send for approval: ' . $unscoredCount . ' participant(s) still have incomplete scores.');
                }

                admin_db_transaction($pdo, function ($pdo) use ($activeEventId, $programId, $currentUserId) {
                    admin_submit_program_for_approval($pdo, $activeEventId, $programId, $currentUserId);
                });
                admin_flash('success', 'Program sent for approval.');
                score_entry_redirect($programId);
            }

            if ($action !== 'save_score_sheet') {
                throw new RuntimeException('Invalid action.');
            }

            if ($scoresLocked) {
                throw new RuntimeException('Program scores are locked for approval.');
            }

            $entryId = (int)($_POST['entry_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT pe.id FROM musabaqa_program_entries pe WHERE pe.id = ? AND pe.event_id = ? AND pe.program_id = ? LIMIT 1");
            $stmt->execute([$entryId, $activeEventId, $programId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Entry not found.');
            }

            if (!empty($program['disable_scores'])) {
                $rankInput = (int)($_POST['placement_rank'] ?? 0);
                $finalTotal = 0.0;
                if ($rankInput === 1) $finalTotal = 100.0;
                elseif ($rankInput === 2) $finalTotal = 90.0;
                elseif ($rankInput === 3) $finalTotal = 80.0;

                $judge1Total = $finalTotal / 2;
                $judge2Total = $finalTotal / 2;

                admin_db_transaction($pdo, function ($pdo) use ($entryId, $programId, $judge1Total, $judge2Total, $finalTotal, $currentUserId) {
                    $stmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
                    $stmt->execute([$entryId]);
                    $sheetId = (int)$stmt->fetchColumn();

                    if ($sheetId > 0) {
                        $stmt = $pdo->prepare("UPDATE musabaqa_score_sheets SET program_id = ?, judge1_total = ?, judge2_total = ?, final_total = ?, status = 'completed' WHERE id = ?");
                        $stmt->execute([$programId, $judge1Total, $judge2Total, $finalTotal, $sheetId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO musabaqa_score_sheets (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by) VALUES (?, ?, ?, ?, ?, 'completed', ?)");
                        $stmt->execute([$entryId, $programId, $judge1Total, $judge2Total, $finalTotal, $currentUserId]);
                    }
                });

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'final_total' => number_format($finalTotal, 2)]);
                    exit;
                }

                admin_flash('success', 'Placement rank saved.');
                score_entry_redirect($programId);
            }

            if (!$categoriesValid) {
                throw new RuntimeException('Categories must total exactly 100.');
            }

            $postedScores = (array)($_POST['scores'] ?? []);
            $existingCategoryScores = [];

            $stmtSheet = $pdo->prepare("SELECT id FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1");
            $stmtSheet->execute([$entryId]);
            $sheetId = (int)$stmtSheet->fetchColumn();
            if ($sheetId > 0) {
                $stmtCat = $pdo->prepare("SELECT judge_no, category_id, score FROM musabaqa_category_scores WHERE score_sheet_id = ?");
                $stmtCat->execute([$sheetId]);
                while ($cRow = $stmtCat->fetch(PDO::FETCH_ASSOC)) {
                    $existingCategoryScores[(int)$cRow['judge_no']][(int)$cRow['category_id']] = (float)$cRow['score'];
                }
            }

            $judgeTotals = [];
            $judgeEntryComplete = [];
            for ($j = 1; $j <= $judgesCount; $j++) {
                $judgeTotals[$j] = 0.0;
                $judgeEntryComplete[$j] = true;
            }

            $categoryMap = [];
            foreach ($categories as $cat) {
                $categoryMap[(int)$cat['id']] = $cat;
            }

            $finalCategoryScores = [];
            for ($judgeNo = 1; $judgeNo <= $judgesCount; $judgeNo++) {
                $hasCategoryScore = true;
                foreach ($categoryMap as $catId => $category) {
                    $hasPosted = isset($postedScores[$judgeNo][$catId]);
                    $rawScore = $postedScores[$judgeNo][$catId] ?? null;

                    if ($hasPosted) {
                        if ($rawScore !== null && $rawScore !== '' && is_numeric($rawScore)) {
                            $score = (float)$rawScore;
                            $max = (float)$category['max_marks'];
                            if ($score < 0) throw new RuntimeException('Score cannot be negative.');
                            if ($score > $max) throw new RuntimeException($category['name'] . ' cannot exceed ' . number_format($max, 2) . ' marks.');

                            $finalCategoryScores[$judgeNo][$catId] = $score;
                            $judgeTotals[$judgeNo] += $score;
                        } else {
                            $hasCategoryScore = false;
                        }
                    } else {
                        if (isset($existingCategoryScores[$judgeNo][$catId])) {
                            $score = (float)$existingCategoryScores[$judgeNo][$catId];
                            $finalCategoryScores[$judgeNo][$catId] = $score;
                            $judgeTotals[$judgeNo] += $score;
                        } else {
                            $hasCategoryScore = false;
                        }
                    }
                }
                if (!$hasCategoryScore || count($finalCategoryScores[$judgeNo] ?? []) < count($categoryMap)) {
                    $judgeEntryComplete[$judgeNo] = false;
                }
            }

            $allJudgesDone = true;
            foreach ($judgeEntryComplete as $isDone) {
                if (!$isDone) $allJudgesDone = false;
            }
            $newSheetStatus = $allJudgesDone ? 'completed' : 'draft';
            $finalTotal = round(array_sum($judgeTotals), 2);
            $settings = admin_get_settings($pdo);
            $gradeInfo = admin_calculate_grade_info($finalTotal, $judgesCount, $settings);

            admin_db_transaction($pdo, function ($pdo) use ($entryId, $program, $judgeTotals, $judgesCount, $finalCategoryScores, $categoryMap, $programId, $activeEventId, $currentUserId, $finalTotal, $newSheetStatus, $gradeInfo) {
                $stmt = $pdo->prepare('SELECT * FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
                $stmt->execute([$entryId]);
                $existingSheet = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $judge1Total = $judgeTotals[1] ?? 0.0;
                $judge2Total = $judgeTotals[2] ?? 0.0;

                if ($existingSheet) {
                    $stmt = $pdo->prepare("UPDATE musabaqa_score_sheets SET program_id = ?, judge1_total = ?, judge2_total = ?, final_total = ?, status = ? WHERE id = ?");
                    $stmt->execute([$programId, $judge1Total, $judge2Total, $finalTotal, $newSheetStatus, (int)$existingSheet['id']]);
                    $scoreSheetId = (int)$existingSheet['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO musabaqa_score_sheets (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$entryId, $programId, $judge1Total, $judge2Total, $finalTotal, $newSheetStatus, $currentUserId]);
                    $scoreSheetId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM musabaqa_category_scores WHERE score_sheet_id = ?')->execute([$scoreSheetId]);
                $insert = $pdo->prepare("INSERT INTO musabaqa_category_scores (score_sheet_id, judge_no, category_id, score) VALUES (?, ?, ?, ?)");
                for ($judgeNo = 1; $judgeNo <= $judgesCount; $judgeNo++) {
                    foreach ($categoryMap as $catId => $category) {
                        if (isset($finalCategoryScores[$judgeNo][$catId])) {
                            $catScore = (float)$finalCategoryScores[$judgeNo][$catId];
                            $insert->execute([$scoreSheetId, $judgeNo, $catId, $catScore]);
                        }
                    }
                }

                $stmtGrade = $pdo->prepare("UPDATE musabaqa_program_entries SET grade = ?, grade_points = ? WHERE id = ?");
                $stmtGrade->execute([$gradeInfo['grade'], $gradeInfo['grade_points'], $entryId]);

                admin_recalculate_entry_status($pdo, $entryId);
                admin_recalculate_program_status($pdo, $programId);
            });

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'final_total' => number_format($finalTotal, 0),
                    'percentage' => number_format($gradeInfo['percentage'], 1) . '%',
                    'grade' => $gradeInfo['grade'] ?: '—',
                    'grade_points' => $gradeInfo['grade_points'],
                ]);
                exit;
            }

            admin_flash('success', 'Score sheet saved.');
        } catch (Throwable $e) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            admin_flash('error', $e->getMessage() ?: 'Unable to save.');
        }

        score_entry_redirect($programId);
    }

    // Fetch entries for table
    $stmtEntries = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.team_color,
            ss.id AS score_sheet_id,
            ss.final_total,
            ss.status AS sheet_status,
            " . admin_entry_chest_number_subquery() . "
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE pe.event_id = ? AND pe.program_id = ?
        ORDER BY pe.performance_order ASC, pe.id ASC
    ");
    $stmtEntries->execute([$activeEventId, $programId]);
    $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

    $scoresMap = [];
    $stmtCS = $pdo->prepare("
        SELECT ss.entry_id, cs.judge_no, cs.category_id, cs.score
        FROM musabaqa_category_scores cs
        JOIN musabaqa_score_sheets ss ON ss.id = cs.score_sheet_id
        WHERE ss.program_id = ?
    ");
    $stmtCS->execute([$programId]);
    while ($cRow = $stmtCS->fetch(PDO::FETCH_ASSOC)) {
        $scoresMap[(int)$cRow['entry_id']][(int)$cRow['judge_no']][(int)$cRow['category_id']] = (float)$cRow['score'];
    }

    $flash = admin_take_flash();
    require_once __DIR__ . '/../../includes/header.php';
    require_once __DIR__ . '/../../includes/sidebar.php';
    ?>

    <div class="main-content">
        <div class="topbar">
            <div>
                <div class="page-title"><i class="fa-solid fa-calculator mr-2" style="color:var(--accent);"></i> Score Entry Workspace</div>
                <div class="page-subtitle"><?= e($program['title']) ?></div>
            </div>
            <div class="flex gap-2">
                <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/score-entry/score-entry.php') ?>"><i class="fa-solid fa-arrow-left"></i> Back to Workspace</a>
                <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/score-entry/program-scores.php?program_id=' . $programId) ?>"><i class="fa-solid fa-eye"></i> View Program Scores</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> mb-6"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!empty($program['disable_scores'])): ?>
            <div class="alert alert-info mb-6" style="background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); color: #93c5fd; padding: 12px 18px; border-radius: 10px;">
                <i class="fa-solid fa-trophy mr-2"></i> Rank Placement Mode (Category scores disabled for this program)
            </div>
        <?php elseif (!$categoriesValid): ?>
            <div class="alert alert-error mb-6">
                Category max marks currently total <strong><?= number_format($categoryTotal, 2) ?></strong>. They must total exactly 100.00 before scoring can be submitted.
            </div>
        <?php endif; ?>

        <div class="table-wrapper">
            <table class="table table-glass">
                <thead>
                    <?php if (!empty($program['disable_scores'])): ?>
                        <tr>
                            <th style="width: 60px;">Order</th>
                            <th style="width: 100px;">Chest #</th>
                            <th>Entry Name</th>
                            <th>Team</th>
                            <th style="width: 160px; text-align: center;">Placement Rank</th>
                            <th style="width: 100px; text-align: center;">Final Score</th>
                            <th style="width: 90px; text-align: center;">Grade</th>
                            <th style="width: 80px; text-align: center;">Status</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th rowspan="2" style="width: 60px; vertical-align: middle;">Order</th>
                            <th rowspan="2" style="width: 100px; vertical-align: middle;">Chest #</th>
                            <th rowspan="2" style="vertical-align: middle;">Entry Name</th>
                            <th rowspan="2" style="vertical-align: middle;">Team</th>
                            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                                <th colspan="<?= count($categories) ?>" style="text-align: center; border-bottom: 1px solid rgba(255,255,255,0.08); font-weight: 700; <?= $j > 1 ? 'border-left: 2px solid rgba(99, 102, 241, 0.5);' : 'border-left: 1px solid rgba(255,255,255,0.1);' ?>">Judge <?= $j ?></th>
                            <?php endfor; ?>
                            <th rowspan="2" style="width: 100px; text-align: center; vertical-align: middle; border-left: 1px solid rgba(255,255,255,0.1);">Final Score</th>
                            <th rowspan="2" style="width: 90px; text-align: center; vertical-align: middle;">Percentage</th>
                            <th rowspan="2" style="width: 90px; text-align: center; vertical-align: middle;">Grade</th>
                            <th rowspan="2" style="width: 80px; text-align: center; vertical-align: middle;">Status</th>
                        </tr>
                        <tr>
                            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                                <?php $cIdx = 0; foreach ($categories as $cat): $cIdx++; ?>
                                    <th style="text-align: center; font-size: 10.5px; font-weight: 700; padding: 6px 4px; <?= ($j > 1 && $cIdx === 1) ? 'border-left: 2px solid rgba(99, 102, 241, 0.5);' : ($cIdx === 1 ? 'border-left: 1px solid rgba(255,255,255,0.1);' : '') ?>">
                                        <div><?= e($cat['name']) ?></div>
                                        <div style="font-size: 9.5px; opacity: 0.85; color: #a5b4fc; font-weight: 700; margin-top: 2px;">(Max <?= (float)$cat['max_marks'] ?>)</div>
                                    </th>
                                <?php endforeach; ?>
                            <?php endfor; ?>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php $orderIdx = 1; foreach ($entries as $entry): ?>
                        <?php
                            $entryId = (int)$entry['id'];
                            $hasSheet = !empty($entry['score_sheet_id']);
                            $finalScoreVal = (float)($entry['final_total'] ?? 0);
                            $pctVal = ($hasSheet && $judgesCount > 0) ? round(($finalScoreVal / ($judgesCount * 100)) * 100, 1) : null;
                            $entryGrade = $entry['grade'];
                            $gradePts = (float)($entry['grade_points'] ?? 0);
                            if (empty($entryGrade) && $hasSheet && $pctVal !== null) {
                                $gInfo = admin_calculate_grade_info($finalScoreVal, $judgesCount);
                                $entryGrade = $gInfo['grade'];
                                $gradePts = $gInfo['grade_points'];
                            }
                        ?>
                        <tr data-entry-row="<?= $entryId ?>">
                            <td><strong><?= $orderIdx++ ?></strong></td>
                            <td><strong><?= e($entry['chest_number'] ?: '-') ?></strong></td>
                            <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                            <td>
                                <span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22; color: #fff; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid <?= e($entry['team_color'] ?? '#64748b') ?>44;">
                                    <?= e($entry['team_name']) ?>
                                </span>
                            </td>

                            <?php if (!empty($program['disable_scores'])): ?>
                                <td class="score-input-cell" style="text-align: center;">
                                    <select class="score-grid-rank-select form-control input-sm"
                                            data-entry-id="<?= $entryId ?>"
                                            style="width: 130px; background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 4px 6px; border-radius: 6px; display: inline-block;"
                                            <?= $scoresLocked ? 'disabled' : '' ?>>
                                        <option value="0">None</option>
                                        <?php
                                        $teamPoints = [];
                                        if (!empty($program['team_points_config'])) {
                                            $teamPoints = json_decode($program['team_points_config'], true);
                                        }
                                        if (is_array($teamPoints) && count($teamPoints) > 0) {
                                            foreach ($teamPoints as $r => $pts) {
                                                $rInt = (int)$r;
                                                $suffix = match($rInt) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
                                                $selected = (int)($entry['final_rank'] ?? 0) === $rInt ? 'selected' : '';
                                                echo "<option value=\"{$rInt}\" {$selected}>{$rInt}{$suffix} Place</option>";
                                            }
                                        } else {
                                            ?>
                                            <option value="1" <?= (int)($entry['final_rank'] ?? 0) === 1 ? 'selected' : '' ?>>1st Place</option>
                                            <option value="2" <?= (int)($entry['final_rank'] ?? 0) === 2 ? 'selected' : '' ?>>2nd Place</option>
                                            <option value="3" <?= (int)($entry['final_rank'] ?? 0) === 3 ? 'selected' : '' ?>>3rd Place</option>
                                            <?php
                                        }
                                        ?>
                                    </select>
                                </td>
                            <?php else: ?>
                                <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                                    <?php $cIdx = 0; foreach ($categories as $cat): $cIdx++; ?>
                                        <?php
                                            $catId = (int)$cat['id'];
                                            $val = isset($scoresMap[$entryId][$j][$catId]) ? (float)$scoresMap[$entryId][$j][$catId] : '';
                                            $borderLeft = ($j > 1 && $cIdx === 1) ? 'border-left: 2px solid rgba(99, 102, 241, 0.5) !important;' : ($cIdx === 1 ? 'border-left: 1px solid rgba(255,255,255,0.1) !important;' : '');
                                        ?>
                                        <td class="score-input-cell" style="text-align: center; <?= $borderLeft ?>">
                                            <input type="number" 
                                                   step="1" 
                                                   min="0" 
                                                   max="<?= (float)$cat['max_marks'] ?>" 
                                                   class="score-grid-input form-control input-sm" 
                                                   data-entry-id="<?= $entryId ?>" 
                                                   data-judge="<?= $j ?>" 
                                                   data-category-id="<?= $catId ?>" 
                                                   data-max="<?= (float)$cat['max_marks'] ?>"
                                                   value="<?= $val !== '' ? number_format((float)$val, 0) : '' ?>" 
                                                   title="Category: <?= e($cat['name']) ?> | Max limit: <?= (float)$cat['max_marks'] ?> marks"
                                                   style="width: 75px; text-align: center; background: rgba(15,23,42,0.5); border: 1px solid rgba(255,255,255,0.08); color: #cbd5e1; padding: 4px 6px; border-radius: 6px; display: inline-block;"
                                                   <?= $scoresLocked ? 'disabled' : '' ?>>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endfor; ?>
                            <?php endif; ?>

                            <td class="row-total-score" id="total-score-<?= $entryId ?>" style="font-weight: 700; color: #34d399; font-size: 14px; text-align: center; vertical-align: middle; border-left: 1px solid rgba(255,255,255,0.1);">
                                <?= $hasSheet ? number_format($finalScoreVal, 0) : '0' ?>
                            </td>

                            <td class="row-percentage" id="percentage-<?= $entryId ?>" style="font-weight: 600; color: #60a5fa; font-size: 13px; text-align: center; vertical-align: middle;">
                                <?= $pctVal !== null ? number_format($pctVal, 1) . '%' : '—' ?>
                            </td>

                            <td class="row-grade-badge" id="grade-badge-<?= $entryId ?>" style="text-align: center; vertical-align: middle;">
                                <?php if (!empty($entryGrade)): ?>
                                    <span class="badge badge-<?= match($entryGrade) { 'A' => 'success', 'B' => 'info', 'C' => 'warning', 'D' => 'neutral', default => 'neutral' } ?>" style="font-size: 11px; padding: 3px 8px; font-weight: 800;">
                                        Grade <?= e($entryGrade) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td class="row-save-status" id="save-status-<?= $entryId ?>" style="text-align: center; vertical-align: middle;">
                                <?php if ($scoresLocked): ?>
                                    <i class="fa-solid fa-lock text-muted" title="Locked"></i>
                                <?php elseif ($hasSheet): ?>
                                    <i class="fa-solid fa-circle-check text-success" title="Saved"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-circle-minus text-muted" title="Not Scored"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$scoresLocked): ?>
            <div class="form-actions mt-6 flex justify-between items-center" style="padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08);">
                <div id="sendApprovalStatusText"></div>
                <form method="POST">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="submit_program">
                    <button id="sendApprovalButton" class="btn btn-success btn-lg flex items-center gap-2" type="submit" disabled style="padding: 12px 28px; font-size: 15px; font-weight: 700; opacity: 0.45; cursor: not-allowed;">
                        <i class="fa-solid fa-paper-plane"></i> Send For Approval
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    const CSRF_TOKEN = <?= json_encode(admin_csrf_value()) ?>;
    const PROGRAM_ID = <?= (int)$programId ?>;
    const SCORES_LOCKED = <?= json_encode($scoresLocked) ?>;
    const DRAFT_CACHE_KEY = `judge_draft_scores_prog_${PROGRAM_ID}`;

    function getDraftCache() {
        try {
            return JSON.parse(localStorage.getItem(DRAFT_CACHE_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    function saveDraftInputToCache(entryId, fieldKey, val) {
        if (SCORES_LOCKED) return;
        try {
            const cache = getDraftCache();
            const cleanVal = String(val || '').trim();
            if (cleanVal === '' || cleanVal === '0') {
                if (cache[entryId]) {
                    delete cache[entryId][fieldKey];
                    if (Object.keys(cache[entryId]).length === 0) {
                        delete cache[entryId];
                    }
                }
            } else {
                if (!cache[entryId]) cache[entryId] = {};
                cache[entryId][fieldKey] = cleanVal;
            }
            localStorage.setItem(DRAFT_CACHE_KEY, JSON.stringify(cache));
        } catch (e) {}
    }

    function clearEntryDraftFromCache(entryId) {
        try {
            const cache = getDraftCache();
            if (cache[entryId]) {
                delete cache[entryId];
                localStorage.setItem(DRAFT_CACHE_KEY, JSON.stringify(cache));
            }
        } catch (e) {}
    }

    function updateSendApprovalButtonState() {
        const btn = document.getElementById('sendApprovalButton');
        const statusText = document.getElementById('sendApprovalStatusText');
        if (!btn) return;

        const rankSelects = document.querySelectorAll('.score-grid-rank-select');
        const scoreInputs = document.querySelectorAll('.score-grid-input');
        
        let total = 0;
        let filled = 0;

        if (rankSelects.length > 0) {
            total = rankSelects.length;
            rankSelects.forEach(select => {
                if (select.value && select.value !== '0') {
                    filled++;
                }
            });
        } else {
            total = scoreInputs.length;
            scoreInputs.forEach(input => {
                const val = input.value.trim();
                if (val !== '' && !isNaN(parseFloat(val))) {
                    filled++;
                }
            });
        }

        const allComplete = (total > 0 && filled === total);

        if (allComplete) {
            btn.disabled = false;
            btn.style.opacity = '1.0';
            btn.style.cursor = 'pointer';
            btn.style.pointerEvents = 'auto';
            btn.title = 'Click to send program scores for approval';
            if (statusText) {
                statusText.innerHTML = `<span style="color: #34d399; font-weight: 700; font-size: 13px;"><i class="fa-solid fa-circle-check mr-1"></i> All ${total} score fields completed (${filled}/${total})</span>`;
            }
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.45';
            btn.style.cursor = 'not-allowed';
            btn.style.pointerEvents = 'auto';
            const remaining = total - filled;
            btn.title = `Cannot send for approval: ${remaining} score field(s) remaining to be filled.`;
            if (statusText) {
                statusText.innerHTML = `<span style="color: #f87171; font-weight: 700; font-size: 13px;"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Incomplete: ${remaining} of ${total} score field(s) remaining</span>`;
            }
        }
    }

    function restoreDraftScoresFromCache() {
        if (SCORES_LOCKED) return;
        const cache = getDraftCache();
        const touchedRows = new Set();

        Object.keys(cache).forEach(entryId => {
            const entryDrafts = cache[entryId];
            if (!entryDrafts) return;

            Object.keys(entryDrafts).forEach(fieldKey => {
                const val = entryDrafts[fieldKey];
                if (val === undefined || val === null) return;

                if (fieldKey === 'rank') {
                    const select = document.querySelector(`tr[data-entry-row="${entryId}"] .score-grid-rank-select`);
                    if (select && (!select.value || select.value === '0')) {
                        select.value = val;
                        touchedRows.add(entryId);
                    }
                } else if (fieldKey.startsWith('j')) {
                    const match = fieldKey.match(/^j(\d+)_c(\d+)$/);
                    if (match) {
                        const judgeNo = match[1];
                        const catId = match[2];
                        const input = document.querySelector(`tr[data-entry-row="${entryId}"] input[data-judge="${judgeNo}"][data-category-id="${catId}"]`);
                        if (input && input.value === '') {
                            input.value = val;
                            touchedRows.add(entryId);
                        }
                    }
                }
            });
        });

        // Recalculate row totals visually for touched rows
        touchedRows.forEach(entryId => {
            const row = document.querySelector(`tr[data-entry-row="${entryId}"]`);
            if (!row) return;
            let rowSum = 0;
            let hasInput = false;
            row.querySelectorAll('.score-grid-input').forEach(input => {
                const v = parseFloat(input.value);
                if (!isNaN(v)) {
                    rowSum += v;
                    hasInput = true;
                }
            });
            if (hasInput) {
                const totalEl = document.getElementById(`total-score-${entryId}`);
                if (totalEl) totalEl.textContent = Math.round(rowSum);
                const statusEl = document.getElementById(`save-status-${entryId}`);
                if (statusEl) statusEl.innerHTML = '<i class="fa-solid fa-clock-rotate-left text-warning" title="Draft restored from cache (auto-saving...)"></i>';
                saveRowScore(entryId);
            }
        });
    }

    async function saveRowScore(entryId) {
        const row = document.querySelector(`tr[data-entry-row="${entryId}"]`);
        if (!row) return;

        const statusEl = document.getElementById(`save-status-${entryId}`);
        if (statusEl) {
            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-warning" title="Saving..."></i>';
        }

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('action', 'save_score_sheet');
        formData.append('program_id', PROGRAM_ID);
        formData.append('entry_id', entryId);
        formData.append('ajax', '1');

        const rankSelect = row.querySelector('.score-grid-rank-select');
        if (rankSelect) {
            formData.append('placement_rank', rankSelect.value);
        } else {
            row.querySelectorAll('.score-grid-input').forEach(input => {
                if (!input.disabled) {
                    const judge = input.dataset.judge;
                    const catId = input.dataset.categoryId;
                    formData.append(`scores[${judge}][${catId}]`, input.value);
                }
            });
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (data.success) {
                if (statusEl) {
                    statusEl.innerHTML = '<i class="fa-solid fa-circle-check text-success" title="Saved"></i>';
                }
                clearEntryDraftFromCache(entryId);

                const totalEl = document.getElementById(`total-score-${entryId}`);
                if (totalEl && data.final_total !== undefined) {
                    totalEl.textContent = data.final_total;
                }
                const pctEl = document.getElementById(`percentage-${entryId}`);
                if (pctEl && data.percentage !== undefined) {
                    pctEl.textContent = data.percentage;
                }
                const gradeEl = document.getElementById(`grade-badge-${entryId}`);
                if (gradeEl && data.grade !== undefined) {
                    if (data.grade && data.grade !== '—') {
                        const badgeClass = data.grade === 'A' ? 'success' : (data.grade === 'B' ? 'info' : (data.grade === 'C' ? 'warning' : 'neutral'));
                        gradeEl.innerHTML = `<span class="badge badge-${badgeClass}" style="font-size: 11px; padding: 3px 8px; font-weight: 800;">Grade ${escapeHtml(data.grade)}</span>`;
                    } else {
                        gradeEl.innerHTML = '<span class="text-muted">—</span>';
                    }
                }

                updateSendApprovalButtonState();
            } else {
                throw new Error(data.message || 'Error saving score');
            }
        } catch (err) {
            if (statusEl) {
                statusEl.innerHTML = '<i class="fa-solid fa-circle-exclamation text-danger" title="' + escapeHtml(err.message) + '"></i>';
            }
        }
    }

    const JUDGES_COUNT = <?= (int)$judgesCount ?>;

    function recalculateRowLocally(entryId) {
        const row = document.querySelector(`tr[data-entry-row="${entryId}"]`);
        if (!row) return;

        let rowSum = 0;
        let hasInput = false;
        row.querySelectorAll('.score-grid-input').forEach(input => {
            const v = parseFloat(input.value);
            if (!isNaN(v)) {
                rowSum += v;
                hasInput = true;
            }
        });

        const totalEl = document.getElementById(`total-score-${entryId}`);
        const pctEl = document.getElementById(`percentage-${entryId}`);
        const gradeEl = document.getElementById(`grade-badge-${entryId}`);

        if (hasInput && JUDGES_COUNT > 0) {
            if (totalEl) totalEl.textContent = Math.round(rowSum);
            
            const pct = (rowSum / (JUDGES_COUNT * 100)) * 100;
            if (pctEl) pctEl.textContent = pct.toFixed(1) + '%';

            let grade = 'D';
            let badgeClass = 'neutral';
            if (pct >= 85) {
                grade = 'A';
                badgeClass = 'success';
            } else if (pct >= 75) {
                grade = 'B';
                badgeClass = 'info';
            } else if (pct >= 65) {
                grade = 'C';
                badgeClass = 'warning';
            }

            if (gradeEl) {
                gradeEl.innerHTML = `<span class="badge badge-${badgeClass}" style="font-size: 11px; padding: 3px 8px; font-weight: 800;">Grade ${grade}</span>`;
            }
        }
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Auto-save & draft cache on input change
    document.querySelectorAll('.score-grid-input, .score-grid-rank-select').forEach(el => {
        el.addEventListener('input', (e) => {
            const input = e.target;

            // Strip decimal characters
            if (input.value.includes('.')) {
                input.value = input.value.split('.')[0];
            }

            // Enforce max mark limit for each category box
            const maxVal = parseFloat(input.dataset.max);
            let val = parseFloat(input.value);
            if (!isNaN(maxVal) && !isNaN(val) && val > maxVal) {
                input.value = maxVal;
                input.style.borderColor = '#f87171';
                input.style.boxShadow = '0 0 0 2px rgba(248, 113, 113, 0.4)';
                setTimeout(() => {
                    input.style.borderColor = '';
                    input.style.boxShadow = '';
                }, 1200);
            }

            const row = input.closest('tr[data-entry-row]');
            if (row) {
                const entryId = row.dataset.entryRow;
                recalculateRowLocally(entryId);
                const fieldKey = input.dataset.judge ? `j${input.dataset.judge}_c${input.dataset.categoryId}` : 'rank';
                saveDraftInputToCache(entryId, fieldKey, input.value);
                updateSendApprovalButtonState();
            }
        });

        el.addEventListener('change', (e) => {
            const input = e.target;
            const row = input.closest('tr[data-entry-row]');
            if (row) {
                const entryId = row.dataset.entryRow;
                const fieldKey = input.dataset.judge ? `j${input.dataset.judge}_c${input.dataset.categoryId}` : 'rank';
                saveDraftInputToCache(entryId, fieldKey, input.value);
                updateSendApprovalButtonState();
                saveRowScore(entryId);
            }
        });
    });

    // Arrow key & Enter cell navigation (Excel-style)
    document.addEventListener('keydown', (e) => {
        if (!e.target.classList.contains('score-grid-input')) return;

        const input = e.target;
        const row = input.closest('tr');
        const tbody = row ? row.closest('tbody') : null;
        if (!row || !tbody) return;

        const inputsInRow = Array.from(row.querySelectorAll('.score-grid-input'));
        const inputColIndex = inputsInRow.indexOf(input);
        const rows = Array.from(tbody.querySelectorAll('tr[data-entry-row]'));
        const rowIndex = rows.indexOf(row);

        if (e.key === 'ArrowRight') {
            e.preventDefault();
            if (inputColIndex < inputsInRow.length - 1) {
                inputsInRow[inputColIndex + 1].focus();
                inputsInRow[inputColIndex + 1].select();
            } else if (rowIndex < rows.length - 1) {
                const nextRowInputs = rows[rowIndex + 1].querySelectorAll('.score-grid-input');
                if (nextRowInputs.length > 0) {
                    nextRowInputs[0].focus();
                    nextRowInputs[0].select();
                }
            }
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            if (inputColIndex > 0) {
                inputsInRow[inputColIndex - 1].focus();
                inputsInRow[inputColIndex - 1].select();
            } else if (rowIndex > 0) {
                const prevRowInputs = rows[rowIndex - 1].querySelectorAll('.score-grid-input');
                if (prevRowInputs.length > 0) {
                    prevRowInputs[prevRowInputs.length - 1].focus();
                    prevRowInputs[prevRowInputs.length - 1].select();
                }
            }
        } else if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            if (rowIndex < rows.length - 1) {
                const nextRowInputs = rows[rowIndex + 1].querySelectorAll('.score-grid-input');
                const targetInput = nextRowInputs[inputColIndex] || nextRowInputs[0];
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (rowIndex > 0) {
                const prevRowInputs = rows[rowIndex - 1].querySelectorAll('.score-grid-input');
                const targetInput = prevRowInputs[inputColIndex] || prevRowInputs[0];
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        }
    });

    // Auto-select text on focus
    document.addEventListener('focusin', (e) => {
        if (e.target.classList.contains('score-grid-input')) {
            e.target.select();
        }
    });

    window.addEventListener('DOMContentLoaded', () => {
        restoreDraftScoresFromCache();
        updateSendApprovalButtonState();
    });
    </script>
    <?php
    admin_close_page();
    exit;
}

// ---------------------------------------------------------
// IF PROGRAM_ID == 0: WORKSPACE PROGRAM PICKER
// ---------------------------------------------------------
$stmtSec = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY section_date ASC, start_time ASC, sort_order ASC");
$stmtSec->execute([$activeEventId]);
$scheduleSessions = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

$stageTypes = $pdo->query("SELECT id, name FROM musabaqa_stage_types ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$approvalFilter = trim((string)($_GET['approval'] ?? 'all'));
$classFilter = trim((string)($_GET['class'] ?? 'all'));
$sessionIdFilter = (int)($_GET['session_id'] ?? 0);
$stageIdFilter = trim((string)($_GET['stage_id'] ?? 'all'));
$programGroupBy = trim((string)($_GET['program_group_by'] ?? 'session'));

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
if ($sessionIdFilter > 0) {
    $where .= ' AND p.section_id = ?';
    $params[] = $sessionIdFilter;
} elseif ($sessionIdFilter === -1) {
    $where .= ' AND p.section_id IS NULL';
}

if ($stageIdFilter !== 'all') {
    if ($stageIdFilter === 'unassigned') {
        $where .= ' AND (p.stage_type_id IS NULL OR p.stage_type_id = 0)';
    } elseif (is_numeric($stageIdFilter) && (int)$stageIdFilter > 0) {
        $where .= ' AND p.stage_type_id = ?';
        $params[] = (int)$stageIdFilter;
    }
}

[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'p');
$where .= $classSql;
array_push($params, ...$classParams);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        ct.name AS class_type_name,
        mst.name AS stage_type_name,
        mss.id AS schedule_section_id, mss.name AS schedule_section_name,
        mss.start_time AS schedule_section_start, mss.end_time AS schedule_section_end,
        mss.section_date AS schedule_section_date, mss.sort_order AS schedule_section_sort,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count,
        COALESCE(category_data.category_count, 0) AS category_count,
        COALESCE(category_data.category_total, 0) AS category_total
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_stage_types mst ON mst.id = p.stage_type_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
    LEFT JOIN (
        SELECT program_id, COUNT(*) AS category_count, SUM(max_marks) AS category_total
        FROM musabaqa_program_categories
        GROUP BY program_id
    ) category_data ON category_data.program_id = p.id
    {$where}
    GROUP BY p.id, ct.id, mst.id, mss.id, category_data.category_count, category_data.category_total
    ORDER BY 
        COALESCE(mss.section_date, '9999-12-31') ASC,
        COALESCE(mss.sort_order, 999) ASC,
        COALESCE(mss.start_time, '23:59:59') ASC,
        (p.start_time IS NULL) ASC,
        p.start_time ASC,
        p.title ASC
");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = admin_take_flash();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-calculator mr-2" style="color:var(--accent);"></i> Score Entry Workspace</div>
            <div class="page-subtitle">Select a program to record participant judge scores</div>
        </div>
        <div class="flex gap-2">
            <a href="<?= app_url('/admin/event-manager/schedule.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-clock mr-1"></i> Schedule & Sessions
            </a>
            <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-microphone-lines mr-1"></i> Programs
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items: flex-end;">
            <input type="hidden" name="program_group_by" value="<?= e($programGroupBy) ?>">

            <div class="input-group">
                <label>Stage / Venue</label>
                <select name="stage_id" onchange="this.form.submit()">
                    <option value="all">All Stages</option>
                    <?php foreach ($stageTypes as $st): ?>
                        <option value="<?= (int)$st['id'] ?>" <?= $stageIdFilter === (string)$st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="unassigned" <?= $stageIdFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned Stage</option>
                </select>
            </div>

            <div class="input-group">
                <label>Schedule Session</label>
                <select name="session_id" onchange="this.form.submit()">
                    <option value="0">All Sessions</option>
                    <?php foreach ($scheduleSessions as $sec): ?>
                        <?php
                            $timeStr = '';
                            if (!empty($sec['start_time']) && !empty($sec['end_time'])) {
                                $timeStr = ' (' . date('h:i A', strtotime($sec['start_time'])) . ')';
                            }
                        ?>
                        <option value="<?= (int)$sec['id'] ?>" <?= $sessionIdFilter === (int)$sec['id'] ? 'selected' : '' ?>>
                            <?= e($sec['name']) ?><?= $timeStr ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="-1" <?= $sessionIdFilter === -1 ? 'selected' : '' ?>>Unassigned Sessions</option>
                </select>
            </div>

            <div class="input-group">
                <label>Status</label>
                <select name="status">
                    <option value="all">All Status</option>
                    <?php foreach (['active', 'scoring', 'completed'] as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Approval</label>
                <select name="approval">
                    <option value="all">All Approval</option>
                    <?php foreach (['none', 'submitted', 'rejected', 'approved'] as $status): ?>
                        <option value="<?= $status ?>" <?= $approvalFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program title...">
            </div>

            <div class="form-actions" style="grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 6px;">
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                    <?php if ($search !== '' || $statusFilter !== 'all' || $approvalFilter !== 'all' || $classFilter !== 'all' || $sessionIdFilter !== 0 || $stageIdFilter !== 'all'): ?>
                        <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="btn btn-secondary btn-md">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrapper">
        <table class="table table-glass">
            <thead>
                <tr>
                    <th>Program Title</th>
                    <th>Schedule Session</th>
                    <th>Class</th>
                    <th>Entries</th>
                    <th>Scored</th>
                    <th>Categories</th>
                    <th>Status</th>
                    <th>Approval</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="9" style="text-align:center; padding: 24px;">No programs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($programs as $program): ?>
                        <?php
                        $entryCount    = (int)$program['entry_count'];
                        $scoredCount   = (int)$program['scored_count'];
                        $categoryTotal = (float)$program['category_total'];
                        $categoryValid = (int)$program['category_count'] > 0 && abs($categoryTotal - 100.0) <= 0.01;
                        ?>
                        <tr>
                            <td>
                                <strong style="color: #fff; font-size: 14px;"><?= e($program['title']) ?></strong>
                                <?php if (!empty($program['stage_type_name'])): ?>
                                    <span class="badge badge-info" style="font-size: 11px; margin-left: 4px;">
                                        <i class="fa-solid fa-map-pin mr-1"></i><?= e($program['stage_type_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($program['schedule_section_name'] ?: 'Unassigned') ?></td>
                            <td><?= e($program['class_type_name'] ?: 'General') ?></td>
                            <td><?= $entryCount ?></td>
                            <td>
                                <span class="badge <?= $scoredCount === $entryCount && $entryCount > 0 ? 'badge-success' : 'badge-neutral' ?>">
                                    <?= $scoredCount ?> / <?= $entryCount ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $categoryValid ? 'badge-success' : 'badge-danger' ?>">
                                    <?= (int)$program['category_count'] ?> · <?= number_format($categoryTotal, 2) ?>
                                </span>
                            </td>
                            <td><span class="badge <?= score_entry_status_badge($program['status']) ?>"><?= e(ucfirst((string)$program['status'])) ?></span></td>
                            <td><span class="badge <?= score_entry_approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span></td>
                            <td style="text-align: right;">
                                <div class="flex gap-2" style="justify-content: flex-end;">
                                    <a class="btn btn-primary btn-sm" href="<?= app_url('/admin/score-entry/score-entry.php?program_id=' . (int)$program['id']) ?>">
                                        <i class="fa-solid fa-calculator mr-1"></i> Enter Scores
                                    </a>
                                    <a class="btn btn-secondary btn-sm" href="<?= app_url('/admin/score-entry/program-scores.php?program_id=' . (int)$program['id']) ?>">
                                        <i class="fa-solid fa-eye mr-1"></i> View Scores
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_close_page(); ?>
