<?php
$pageTitle = 'Program Scores';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);

function program_scores_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-info',
    };
}

// ---------------------------------------------------------
// IF PROGRAM_ID > 0: READ-ONLY SCORES OVERVIEW FOR PROGRAM
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
        admin_redirect('/admin/score-entry/program-scores.php');
    }

    $stmtCat = $pdo->prepare("
        SELECT id, name, max_marks, sort_order
        FROM musabaqa_program_categories
        WHERE program_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmtCat->execute([$programId]);
    $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

    $judgesCount = (int)($program['judges_count'] ?? 2);

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
                <div class="page-title"><i class="fa-solid fa-trophy mr-2" style="color:var(--accent);"></i> Program Scores Overview</div>
                <div class="page-subtitle"><?= e($program['title']) ?> (Read-Only)</div>
            </div>
            <div class="flex gap-2">
                <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/score-entry/program-scores.php') ?>"><i class="fa-solid fa-arrow-left"></i> Back to All Scores</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> mb-6"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="alert alert-info flex items-center justify-between mb-6" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #93c5fd; padding: 14px 18px; border-radius: 10px;">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-circle-info" style="font-size: 18px;"></i>
                <div>
                    <strong style="display: block; font-size: 14px; color: #fff;">Read-Only Score Summary</strong>
                    <span style="font-size: 12px; opacity: 0.85;">This page shows recorded scores, totals, and grades.</span>
                </div>
            </div>
        </div>

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
                            <th style="width: 100px; text-align: center;">Status</th>
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
                            <th rowspan="2" style="width: 100px; text-align: center; vertical-align: middle;">Status</th>
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
                        <tr>
                            <td><strong><?= $orderIdx++ ?></strong></td>
                            <td><strong><?= e($entry['chest_number'] ?: '-') ?></strong></td>
                            <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                            <td>
                                <span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22; color: #fff; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid <?= e($entry['team_color'] ?? '#64748b') ?>44;">
                                    <?= e($entry['team_name']) ?>
                                </span>
                            </td>

                            <?php if (!empty($program['disable_scores'])): ?>
                                <td style="text-align: center; font-weight: 700; color: #facc15;">
                                    <?php
                                        $rank = (int)($entry['final_rank'] ?? 0);
                                        echo match ($rank) {
                                            1 => '1st Place 🥇',
                                            2 => '2nd Place 🥈',
                                            3 => '3rd Place 🥉',
                                            default => ($rank > 0 ? $rank . 'th Place' : '—')
                                        };
                                    ?>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <span class="badge <?= program_scores_badge($entry['sheet_status'] ?? $program['status']) ?>">
                                        <?= e(ucfirst((string)($entry['sheet_status'] ?? $program['status']))) ?>
                                    </span>
                                </td>
                            <?php else: ?>
                                <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                                    <?php $cIdx = 0; foreach ($categories as $cat): $cIdx++; ?>
                                        <?php
                                            $catId = (int)$cat['id'];
                                            $val = isset($scoresMap[$entryId][$j][$catId]) ? (float)$scoresMap[$entryId][$j][$catId] : null;
                                            $borderLeft = ($j > 1 && $cIdx === 1) ? 'border-left: 2px solid rgba(99, 102, 241, 0.5) !important;' : ($cIdx === 1 ? 'border-left: 1px solid rgba(255,255,255,0.1) !important;' : '');
                                        ?>
                                        <td style="text-align: center; font-size: 13px; <?= $borderLeft ?>">
                                            <?php if ($val !== null): ?>
                                                <span style="font-weight: 600; color: #fff;"><?= number_format($val, 0) ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--muted); opacity: 0.4;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endfor; ?>

                                <td style="font-weight: 700; color: #34d399; font-size: 14px; text-align: center; vertical-align: middle; border-left: 1px solid rgba(255,255,255,0.1);">
                                    <?= $hasSheet ? number_format($finalScoreVal, 0) : '0' ?>
                                </td>

                                <td style="font-weight: 600; color: #60a5fa; font-size: 13px; text-align: center; vertical-align: middle;">
                                    <?= $pctVal !== null ? number_format($pctVal, 1) . '%' : '—' ?>
                                </td>

                                <td style="text-align: center; vertical-align: middle;">
                                    <?php if (!empty($entryGrade)): ?>
                                        <span class="badge badge-<?= match($entryGrade) { 'A' => 'success', 'B' => 'info', 'C' => 'warning', 'D' => 'neutral', default => 'neutral' } ?>" style="font-size: 11px; padding: 3px 8px; font-weight: 800;">
                                            Grade <?= e($entryGrade) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: center; vertical-align: middle;">
                                    <span class="badge <?= program_scores_badge($entry['sheet_status'] ?? $program['status']) ?>">
                                        <?= e(ucfirst((string)($entry['sheet_status'] ?? $program['status']))) ?>
                                    </span>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    admin_close_page();
    exit;
}

// ---------------------------------------------------------
// IF PROGRAM_ID == 0: OVERVIEW OF ALL PROGRAM SCORES
// ---------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT p.*, ct.name AS class_type_name,
           mss.id AS schedule_section_id, mss.name AS schedule_section_name,
           COUNT(DISTINCT pe.id) AS entry_count,
           COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
    WHERE p.event_id = ?
    GROUP BY p.id, ct.id, mss.id
    ORDER BY p.title ASC
");
$stmt->execute([$activeEventId]);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = admin_take_flash();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-trophy mr-2" style="color:var(--accent);"></i> All Program Scores</div>
            <div class="page-subtitle">Read-only overview of recorded program marks and approval progress</div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="table table-glass">
            <thead>
                <tr>
                    <th>Program Title</th>
                    <th>Schedule Session</th>
                    <th>Class</th>
                    <th>Entries</th>
                    <th>Scored</th>
                    <th>Status</th>
                    <th>Approval</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 24px;">No programs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($programs as $prog): ?>
                        <?php
                            $eCount = (int)$prog['entry_count'];
                            $sCount = (int)$prog['scored_count'];
                        ?>
                        <tr>
                            <td><strong style="color: #fff; font-size: 14px;"><?= e($prog['title']) ?></strong></td>
                            <td><?= e($prog['schedule_section_name'] ?: 'Unassigned') ?></td>
                            <td><?= e($prog['class_type_name'] ?: 'General') ?></td>
                            <td><?= $eCount ?></td>
                            <td>
                                <span class="badge <?= $sCount === $eCount && $eCount > 0 ? 'badge-success' : 'badge-neutral' ?>">
                                    <?= $sCount ?> / <?= $eCount ?>
                                </span>
                            </td>
                            <td><span class="badge <?= program_scores_badge($prog['status']) ?>"><?= e(ucfirst((string)$prog['status'])) ?></span></td>
                            <td><span class="badge <?= program_scores_badge($prog['approval_status']) ?>"><?= e(ucfirst((string)$prog['approval_status'])) ?></span></td>
                            <td style="text-align: right;">
                                <div class="flex gap-2" style="justify-content: flex-end;">
                                    <a class="btn btn-secondary btn-sm" href="<?= app_url('/admin/score-entry/program-scores.php?program_id=' . (int)$prog['id']) ?>">
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
