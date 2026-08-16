<?php
$pageTitle = 'Judges Score Sheet';

require_once __DIR__ . '/../includes/admin-helpers.php';
$programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);
if ($programId > 0) {
    admin_redirect('/admin/score-entry/score-entry.php', ['program_id' => $programId]);
} else {
    admin_redirect('/admin/score-entry/score-entry.php');
}
exit;

function program_scores_redirect(int $programId): void
{
    admin_redirect('/judges/program-scores.php', ['program_id' => $programId]);
}

// Passkey Action Handling (Auto-detects Judge from PIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postAction = (string)$_POST['action'];
    if ($postAction === 'verify_judge_passkey') {
        $passkey = trim((string)($_POST['passkey'] ?? ''));
        $detectedNo = admin_detect_judge_by_passkey($pdo, $passkey);
        if ($detectedNo !== null) {
            $_SESSION['authenticated_judge_no'] = $detectedNo;
            admin_flash('success', "Passkey verified! Welcome, Judge {$detectedNo}.");
        } else {
            $judgeNo = (int)($_POST['judge_no'] ?? 0);
            if ($judgeNo > 0 && admin_verify_judge_passkey($pdo, $judgeNo, $passkey)) {
                $_SESSION['authenticated_judge_no'] = $judgeNo;
                admin_flash('success', "Passkey verified! Welcome, Judge {$judgeNo}.");
            } else {
                admin_flash('error', "Invalid Passkey PIN. Please try again.");
            }
        }
        program_scores_redirect($programId);
    } elseif ($postAction === 'lock_judge') {
        unset($_SESSION['authenticated_judge_no']);
        admin_flash('success', "Judge panel locked.");
        program_scores_redirect($programId);
    }
}

$activeJudgeNo = (int)($_SESSION['authenticated_judge_no'] ?? 0);
$isAdminUser = is_admin();

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

function program_scores_render_row(array $entry, array $categories, int $judgesCount, array $scoresMap, bool $scoresLocked, bool $disableScores, int $orderIndex, int $activeJudgeNo = 0, bool $isAdmin = false, int $liveEntryId = 0, bool $isGroupProgram = false, int $entriesCount = 0): string
{
    $entryId = (int)$entry['id'];
    $hasSheet = !empty($entry['score_sheet_id']);
    $isOnStage = ($liveEntryId > 0 && $entryId === $liveEntryId);
    
    ob_start();
    ?>
    <tr data-entry-row="<?= $entryId ?>" style="<?= $isOnStage ? 'background: rgba(16, 185, 129, 0.18); border-left: 4px solid #34d399;' : '' ?>">
        <td><strong><?= $orderIndex ?></strong></td>
        <td>
            <strong><?= e($isGroupProgram ? ($entry['team_name'] ?: '-') : ($entry['chest_number'] ?: '-')) ?></strong>
            <?php if ($isOnStage): ?>
                <span class="badge badge-success" style="font-size: 9px; padding: 2px 6px; margin-left: 4px;">🎤 ON STAGE</span>
            <?php endif; ?>
        </td>
        <td><?= e($isGroupProgram ? ($entry['team_name'] ?: 'Unnamed Team') : ($entry['entry_name'] ?: 'Unnamed Entry')) ?></td>
        <td>
            <span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22; color: #fff; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid <?= e($entry['team_color'] ?? '#64748b') ?>44;">
                <?= e($entry['team_name']) ?>
            </span>
        </td>
        
        <?php if ($disableScores): ?>
            <td class="score-input-cell" style="text-align: center;">
                <select class="score-grid-rank-select form-control input-sm"
                        data-entry-id="<?= $entryId ?>"
                        style="width: 130px; background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 4px 6px; border-radius: 6px; display: inline-block;"
                        <?= ($scoresLocked || ($activeJudgeNo === 0 && !$isAdmin)) ? 'disabled' : '' ?>>
                    <option value="0">None</option>
                    <?php
                    $limit = $entriesCount > 0 ? $entriesCount : 3;
                    for ($rInt = 1; $rInt <= $limit; $rInt++) {
                        $suffix = match($rInt) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
                        $selected = (int)($entry['final_rank'] ?? 0) === $rInt ? 'selected' : '';
                        echo "<option value=\"{$rInt}\" {$selected}>{$rInt}{$suffix} Place</option>";
                    }
                    ?>
                </select>
            </td>
            <td class="row-save-status" id="save-status-<?= $entryId ?>" style="text-align: center; vertical-align: middle;">
                <?php if ($scoresLocked): ?>
                    <i class="fa-solid fa-lock text-muted" title="Locked"></i>
                <?php elseif ($hasSheet || ($entry['final_rank'] ?? null) !== null): ?>
                    <i class="fa-solid fa-circle-check text-success" title="Saved"></i>
                <?php else: ?>
                    <i class="fa-solid fa-circle-minus text-warning" title="Not Scored"></i>
                <?php endif; ?>
            </td>
        <?php else: ?>
            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                <?php
                    $isJudgeEditable = $isAdmin || ($activeJudgeNo > 0 && $activeJudgeNo === $j);
                    $bgStyle = ($activeJudgeNo === $j) 
                        ? 'background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: #fff;' 
                        : ($isJudgeEditable 
                            ? 'background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); color: #fff;' 
                            : 'background: rgba(15,23,42,0.3); border: 1px solid rgba(255,255,255,0.05); color: #64748b; cursor: not-allowed;');
                ?>
                <?php foreach ($categories as $cat): ?>
                    <?php
                        $catId = (int)$cat['id'];
                        $val = isset($scoresMap[$entryId][$j][$catId]) ? (float)$scoresMap[$entryId][$j][$catId] : '';
                    ?>
                    <td class="score-input-cell" style="text-align: center;">
                        <input type="number" 
                               step="0.01" 
                               min="0" 
                               max="<?= (float)$cat['max_marks'] ?>" 
                               class="score-grid-input form-control input-sm" 
                               data-entry-id="<?= $entryId ?>" 
                               data-judge="<?= $j ?>" 
                               data-category-id="<?= $catId ?>" 
                               data-max="<?= (float)$cat['max_marks'] ?>"
                               value="<?= $val !== '' ? number_format($val, 2) : '' ?>" 
                               style="width: 75px; text-align: center; padding: 4px 6px; border-radius: 6px; display: inline-block; <?= $bgStyle ?>"
                               <?= ($scoresLocked || !$isJudgeEditable) ? 'disabled' : '' ?>
                               title="<?= !$isJudgeEditable ? ($activeJudgeNo > 0 ? 'Locked: Logged in as Judge ' . $activeJudgeNo : 'Passkey required to enter Judge ' . $j . ' marks') : 'Judge ' . $j . ' Marks' ?>">
                    </td>
                <?php endforeach; ?>
            <?php endfor; ?>
            
            <?php
                $finalScoreVal = (float)($entry['final_total'] ?? 0);
                $pctVal = ($hasSheet && $judgesCount > 0) ? round(($finalScoreVal / ($judgesCount * 100)) * 100, 1) : null;
                $entryGrade = $entry['grade'] ?? '';
                $gradePts = (float)($entry['grade_points'] ?? 0);
                if (empty($entryGrade) && $hasSheet && $pctVal !== null) {
                    $gInfo = admin_calculate_grade_info($finalScoreVal, $judgesCount);
                    $entryGrade = $gInfo['grade'];
                    $gradePts = $gInfo['grade_points'];
                }
            ?>
            <td class="row-total-score" id="total-score-<?= $entryId ?>" style="font-weight: 700; color: #34d399; font-size: 14px; text-align: center; vertical-align: middle;">
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
                    <i class="fa-solid fa-circle-minus text-warning" title="Not Scored"></i>
                <?php endif; ?>
            </td>
        <?php endif; ?>
    </tr>
    <?php
    return ob_get_clean();
}

if ($programId <= 0) {
    admin_redirect('/judges/index.php');
}

$program = program_scores_load_program($pdo, $activeEventId, $programId);
if (!$program) {
    admin_flash('error', 'Program not found for the active event.');
    admin_redirect('/judges/index.php');
}

// Enforce sequential scoring lock rule (unless printing blank score sheets)
if (!isset($_GET['mode']) || $_GET['mode'] !== 'print') {
    $blockingProg = admin_check_program_scoring_locked($pdo, $activeEventId, $programId);
    if ($blockingProg) {
        admin_flash('error', 'Scoring is locked for "' . $program['title'] . '". Please complete scoring for the previous program ("' . $blockingProg['title'] . '") first.');
        admin_redirect('/judges/index.php');
    }
}

$categories = program_scores_load_categories($pdo, $programId);
$categoryTotal = program_scores_categories_total($categories);
$categoriesValid = $categories && abs($categoryTotal - 100.0) <= 0.01;

$stmt = $pdo->prepare("
    SELECT pe.*, t.team_name, t.team_color,
           (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
            FROM musabaqa_entry_members em
            JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
            WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_number
    FROM musabaqa_program_entries pe
    JOIN musabaqa_teams t ON t.id = pe.team_id
    WHERE pe.event_id = ? AND pe.program_id = ?
    ORDER BY pe.performance_order ASC, pe.id ASC
");
$stmt->execute([$activeEventId, $programId]);
$entriesForPrint = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['mode']) && $_GET['mode'] === 'emcee') {
    $tier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
    $sectionLabel = $tier ? admin_class_type_tier_label($tier) : '';

    if (!$sectionLabel && !empty($program['allowed_sections'])) {
        $secParts = array_filter(array_map('trim', explode(',', $program['allowed_sections'])));
        if (count($secParts) === 1) {
            $sectionLabel = reset($secParts);
        }
    }

    if ($sectionLabel && !in_array(strtolower($sectionLabel), ['general', 'all classes', 'general / multi-section'], true)) {
        $programHeading = $program['title'] . ' - ' . $sectionLabel;
    } else {
        $programHeading = $program['title'];
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Emcee Sheet - <?= e($program['title']) ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            @page {
                size: A4 portrait;
                margin: 10mm 12mm;
            }
            * {
                box-sizing: border-box;
            }
            body {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: #000;
                background: #fff;
                margin: 0;
                padding: 12px;
                line-height: 1.4;
            }
            .emcee-card {
                border: 2px solid #0f172a;
                padding: 18px 20px;
                border-radius: 10px;
                background: #fff;
            }
            .print-header {
                border-bottom: 2px solid #0f172a;
                padding-bottom: 10px;
                margin-bottom: 14px;
            }
            .program-title-banner {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .program-title {
                font-size: 19px;
                font-weight: 800;
                color: #0f172a;
            }
            .emcee-badge {
                background: #0f172a;
                color: #fff;
                font-size: 12px;
                font-weight: 800;
                padding: 4px 12px;
                border-radius: 6px;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .meta-bar {
                display: flex;
                gap: 24px;
                font-size: 12px;
                color: #334155;
                margin-top: 8px;
                font-weight: 600;
            }
            .emcee-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .emcee-table th, .emcee-table td {
                border: 1.5px solid #1e293b;
                padding: 9px 12px;
                font-size: 13px;
                vertical-align: middle;
            }
            .emcee-table th {
                background: #f1f5f9;
                font-weight: 800;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.03em;
            }
            .order-col {
                width: 50px;
                text-align: center;
                font-weight: 800;
            }
            .chest-col {
                width: 110px;
                text-align: center;
                font-size: 16px;
                font-weight: 900;
                color: #000;
            }
            .name-col {
                font-weight: 700;
                color: #0f172a;
            }
            .team-col {
                width: 160px;
                font-weight: 600;
            }
            .check-col {
                width: 85px;
                text-align: center;
            }
            .check-box-square {
                display: inline-block;
                width: 18px;
                height: 18px;
                border: 2px solid #334155;
                border-radius: 3px;
            }
            .no-print-btn {
                position: fixed;
                top: 20px;
                right: 20px;
                background: #0284c7;
                color: #fff;
                border: none;
                padding: 10px 20px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
                display: inline-flex;
                align-items: center;
                gap: 8px;
                z-index: 9999;
            }
            .no-print-btn:hover {
                background: #0369a1;
            }
            @media print {
                .no-print-btn {
                    display: none !important;
                }
                body {
                    padding: 0;
                }
            }
        </style>
    </head>
    <body>
        <button onclick="window.print()" class="no-print-btn">
            <i class="fa-solid fa-print"></i> Print Emcee Sheet
        </button>

        <div class="emcee-card">
            <div class="print-header">
                <div class="program-title-banner">
                    <span class="program-title"><?= e($programHeading) ?></span>
                    <span class="emcee-badge"><i class="fa-solid fa-microphone mr-1"></i> EMCEE STAGE SHEET</span>
                </div>
                <div class="meta-bar">
                    <?php if (!empty($program['schedule_section_name'])): ?>
                        <div><i class="fa-solid fa-clock"></i> Session: <?= e($program['schedule_section_name']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($program['start_time'])): ?>
                        <div><i class="fa-solid fa-hourglass-start"></i> Time: <?= date('h:i A', strtotime($program['start_time'])) ?></div>
                    <?php endif; ?>
                    <div><i class="fa-solid fa-users"></i> Total Entries: <?= count($entriesForPrint) ?></div>
                </div>
            </div>

            <table class="emcee-table">
                <thead>
                    <tr>
                        <th class="order-col">#</th>
                        <th class="chest-col">Chest #</th>
                        <th class="name-col">Participant Name</th>
                        <th class="team-col">Team</th>
                        <th class="check-col">Announced</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entriesForPrint)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 25px; color: #64748b;">No entries registered for this program.</td>
                        </tr>
                    <?php else: ?>
                        <?php $orderIndex = 1; ?>
                        <?php foreach ($entriesForPrint as $entry): ?>
                            <?php
                                $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                $entryName = $entry['entry_name'] ?: 'Unnamed Entry';
                                $isGroup = (($program['program_type'] ?? '') === 'group' || !empty($program['only_team_marks']));
                                if ($isGroup) {
                                    if (str_contains($entryName, ' - ')) {
                                        $parts = explode(' - ', $entryName);
                                        $entryName = trim(end($parts));
                                    }
                                    $entryName = preg_replace('/\s*\(\d+\)\s*$/', '', $entryName);
                                }
                            ?>
                            <tr>
                                <td class="order-col"><?= $orderIndex++ ?></td>
                                <td class="chest-col">#<?= e($formattedChest) ?></td>
                                <td class="name-col"><?= e($entryName) ?></td>
                                <td class="team-col"><?= e($entry['team_name']) ?></td>
                                <td class="check-col"><span class="check-box-square"></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    window.print();
                }, 500);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['mode']) && $_GET['mode'] === 'print') {
    $pType = strtolower((string)($program['program_type'] ?? 'individual'));
    $entryCount = count($entriesForPrint);
    $rowHeight = ($entryCount <= 6) ? 40 : (($entryCount <= 12) ? 32 : 26);

    $tier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
    $sectionLabel = $tier ? admin_class_type_tier_label($tier) : '';

    if (!$sectionLabel && !empty($program['allowed_sections'])) {
        $secParts = array_filter(array_map('trim', explode(',', $program['allowed_sections'])));
        if (count($secParts) === 1) {
            $sectionLabel = reset($secParts);
        }
    }

    if ($sectionLabel && !in_array(strtolower($sectionLabel), ['general', 'all classes', 'general / multi-section'], true)) {
        $programHeading = $program['title'] . ' - ' . $sectionLabel;
    } else {
        $programHeading = $program['title'];
    }

    $totalMaxMarks = 0;
    foreach ($categories as $cat) {
        $totalMaxMarks += (float)($cat['max_marks'] ?? 0);
    }
    $maxJudgesLimit = 6;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Score Sheet - <?= e($program['title']) ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="<?= asset_url('css/event-id-cards.css') ?>">
        <script src="<?= asset_url('js/print-helpers.js') ?>" defer></script>
        <style>
            @page {
                size: A4 landscape;
                margin: 5mm 7mm;
            }
            * { box-sizing: border-box; }
            body {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: #000;
                background: #f8fafc;
                margin: 0;
                padding: 16px;
                line-height: 1.3;
            }

            .no-print-bar {
                background: #0f172a;
                padding: 14px 20px;
                border-radius: 12px;
                margin-bottom: 20px;
                color: #fff;
                box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            }
            .toolbar-inner {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 14px;
            }
            .toolbar-title-group h3 {
                margin: 0;
                font-size: 16px;
                color: #fff;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .toolbar-title-group small {
                color: #94a3b8;
                font-size: 12px;
            }
            .toolbar-controls {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .control-select, .control-checkbox-label {
                background: #1e293b;
                color: #fff;
                border: 1px solid #334155;
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                cursor: pointer;
            }
            .control-select:focus {
                outline: none;
                border-color: #3b82f6;
            }
            .control-checkbox-label input[type="checkbox"] {
                accent-color: #3b82f6;
                width: 14px;
                height: 14px;
                cursor: pointer;
            }

            .judge-landscape-sheet {
                border: 1.5px solid #cbd5e1;
                padding: 16px 20px;
                border-radius: 12px;
                background: #fff;
                width: 100%;
                min-height: calc(100vh - 80px);
                margin-bottom: 24px;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                page-break-after: always !important;
                break-after: page !important;
            }
            .judge-landscape-sheet:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .page-break {
                page-break-before: always !important;
                break-before: page !important;
            }

            .print-header {
                border-bottom: 2px solid #000;
                padding-bottom: 8px;
                margin-bottom: 8px;
                flex-shrink: 0;
            }
            .event-kicker {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: #475569;
                margin-bottom: 3px;
            }
            .program-title-banner {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
            }
            .program-title {
                font-size: 18px;
                font-weight: 900;
                color: #000;
                letter-spacing: -0.01em;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .program-meta-badges {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .meta-badge {
                border: 1px solid #000;
                font-size: 10.5px;
                font-weight: 800;
                padding: 2px 8px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .judge-badge {
                background: #0f172a;
                color: #fff;
                font-size: 12px;
                font-weight: 900;
                padding: 5px 14px;
                border-radius: 6px;
                text-transform: uppercase;
                letter-spacing: 0.08em;
            }

            .sheet-table-wrapper {
                flex: 1 1 auto;
                display: flex;
                flex-direction: column;
                margin-top: 4px;
                margin-bottom: 8px;
                min-height: 0;
            }
            .sheet-table {
                width: 100%;
                height: 100%;
                table-layout: fixed;
                border-collapse: collapse;
            }
            .sheet-table th, .sheet-table td {
                border: 1.5px solid #000;
                text-align: center;
                vertical-align: middle;
                padding: 6px 8px;
                font-size: 12px;
            }
            .sheet-table th {
                background: #f1f5f9;
                font-weight: 900;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.03em;
                padding: 8px 6px;
            }
            .chest-col {
                width: 90px;
                font-weight: 900;
                font-size: 14.5px;
            }
            .participant-col {
                text-align: left !important;
                padding-left: 10px !important;
                width: 220px;
            }
            .team-indicator-dot {
                display: inline-block;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                margin-right: 6px;
                vertical-align: middle;
                border: 1px solid rgba(0,0,0,0.2);
            }
            .col-total {
                width: 80px;
                font-weight: 900;
                background: #fafafa;
            }
            .col-rank {
                width: 80px;
                display: none;
            }
            .col-notes {
                width: 140px;
            }

            body.hide-participant-names .participant-col {
                display: none !important;
            }
            body.hide-total-column .col-total {
                display: none !important;
            }
            body.show-rank-column .col-rank {
                display: table-cell !important;
            }
            body.hide-notes-column .col-notes {
                display: none !important;
            }
            body.hide-sheet-footer .sheet-footer {
                display: none !important;
            }

            .sheet-footer {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1.5px solid #cbd5e1;
                font-size: 12.5px;
                font-weight: 700;
                flex-shrink: 0;
            }
            .signature-box {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .signature-label {
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                color: #475569;
            }
            .signature-line {
                border-bottom: 1.5px solid #000;
                display: inline-block;
                width: 180px;
                height: 20px;
            }
            .confidential-notice {
                font-size: 10px;
                color: #64748b;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                text-align: center;
                margin-top: 4px;
            }

            @media print {
                @page {
                    size: A4 landscape;
                    margin: 5mm 7mm;
                }
                html, body {
                    height: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #fff !important;
                }
                .no-print-bar, .no-print-hide {
                    display: none !important;
                }
                .judge-landscape-sheet {
                    border: none !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    border-radius: 0 !important;
                    box-shadow: none !important;
                    width: 100% !important;
                    height: 100vh !important;
                    max-height: 100vh !important;
                    display: flex !important;
                    flex-direction: column !important;
                    justify-content: space-between !important;
                    page-break-after: always !important;
                    break-after: page !important;
                    box-sizing: border-box !important;
                }
                .judge-landscape-sheet:last-child {
                    page-break-after: auto !important;
                    break-after: auto !important;
                }
                .sheet-table-wrapper {
                    flex: 1 1 auto !important;
                    display: flex !important;
                    flex-direction: column !important;
                    margin: 4px 0 !important;
                    min-height: 0 !important;
                }
                .sheet-table {
                    height: 100% !important;
                }
                .sheet-footer {
                    flex-shrink: 0 !important;
                    margin-top: 6px !important;
                    padding-top: 6px !important;
                }
                .sheet-table th {
                    background: #f1f5f9 !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .col-total {
                    background: #fafafa !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body data-print-orientation="landscape">
        <!-- INTERACTIVE SCREEN CONTROL BAR -->
        <div class="no-print-bar">
            <div class="toolbar-inner">
                <div class="toolbar-title-group">
                    <h3><i class="fa-solid fa-gavel" style="color:#a855f7;"></i> <?= e($programHeading) ?> Score Sheet</h3>
                    <small>Official Judges Print Sheet — <?= $entryCount ?> Entries</small>
                </div>
                
                <div class="toolbar-controls">
                    <select data-print-select="orientation" class="control-select" title="Page Orientation">
                        <option value="landscape" selected>🖼️ Landscape</option>
                        <option value="portrait">📄 Portrait</option>
                    </select>

                    <select data-print-select="judges" class="control-select" title="Number of Judges">
                        <option value="1">👨‍⚖️ 1 Judge</option>
                        <option value="2" selected>👨‍⚖️ 2 Judges</option>
                        <option value="3">👨‍⚖️ 3 Judges</option>
                        <option value="4">👨‍⚖️ 4 Judges</option>
                        <option value="5">👨‍⚖️ 5 Judges</option>
                        <option value="6">👨‍⚖️ 6 Judges</option>
                    </select>

                    <label class="control-checkbox-label" title="Show/Hide Participant Names for Blind Scoring">
                        <input type="checkbox" data-print-toggle="names" checked>
                        <span>Names</span>
                    </label>

                    <label class="control-checkbox-label" title="Show/Hide Total Column">
                        <input type="checkbox" data-print-toggle="total" checked>
                        <span>Total</span>
                    </label>

                    <label class="control-checkbox-label" title="Show/Hide Rank Placement Column">
                        <input type="checkbox" data-print-toggle="rank">
                        <span>Rank</span>
                    </label>

                    <label class="control-checkbox-label" title="Show/Hide Judge Notes Column">
                        <input type="checkbox" data-print-toggle="notes" checked>
                        <span>Notes</span>
                    </label>

                    <label class="control-checkbox-label" title="Show/Hide Signature Footer Block">
                        <input type="checkbox" data-print-toggle="footer" checked>
                        <span>Signature</span>
                    </label>

                    <select data-print-select="blank-rows" class="control-select" title="Add Extra Blank Rows">
                        <option value="0" selected>+0 Blank Rows</option>
                        <option value="2">+2 Blank Rows</option>
                        <option value="5">+5 Blank Rows</option>
                        <option value="10">+10 Blank Rows</option>
                    </select>

                    <select data-print-select="scale" class="control-select" title="Print Zoom Fit">
                        <option value="1">100% Fit</option>
                        <option value="0.9">90% Fit</option>
                        <option value="0.85">85% Fit</option>
                        <option value="0.8">80% Fit</option>
                    </select>

                    <button data-print-action="print" class="btn btn-success" style="padding: 6px 16px; background: #10b981; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class="fa-solid fa-print"></i> Print Sheet (Ctrl+P)
                    </button>
                </div>
            </div>
        </div>

        <?php for ($j = 1; $j <= $maxJudgesLimit; $j++): ?>
            <div class="judge-landscape-sheet <?= ($j > 1) ? 'page-break' : '' ?>" data-judge-number="<?= $j ?>">
                <div class="print-header">
                    <div class="event-kicker">
                        <span><?= e($activeEvent['title'] ?? 'Musabaqa Contest') ?> — Official Judge Score Sheet</span>
                        <span>Total Max Marks: <strong><?= number_format($totalMaxMarks, 0) ?> PTS</strong></span>
                    </div>
                    <div class="program-title-banner">
                        <span class="program-title">
                            <i class="fa-solid fa-gavel" style="color: #475569; font-size: 16px;"></i>
                            <?= e($programHeading) ?>
                        </span>
                        <div class="program-meta-badges">
                            <span class="meta-badge"><?= ucfirst($pType) ?> Program</span>
                            <span class="judge-badge">JUDGE <?= $j ?> SCORE SHEET</span>
                        </div>
                    </div>
                </div>

                <div class="sheet-table-wrapper">
                    <table class="sheet-table">
                        <thead>
                            <tr>
                                <?php if ($pType === 'group'): ?>
                                    <th class="chest-col">Team Name</th>
                                <?php else: ?>
                                    <th class="chest-col">Chest #</th>
                                <?php endif; ?>
                                <th class="participant-col">Participant / Team Name</th>
                                <?php foreach ($categories as $cat): ?>
                                    <th class="score-col">
                                        <?= e($cat['name']) ?><br>
                                        <small style="font-weight:700; font-size:9.5px; text-transform:none; color: #475569;">(Max <?= number_format($cat['max_marks'], 0) ?>)</small>
                                    </th>
                                <?php endforeach; ?>
                                <th class="col-total">Total</th>
                                <th class="col-rank">Rank</th>
                                <th class="col-notes">Judge Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entriesForPrint)): ?>
                                <tr>
                                    <td colspan="<?= 2 + count($categories) + 3 ?>" style="padding: 24px; color: #666; font-size: 14px;">No registered entries found for this program.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($entriesForPrint as $entry): ?>
                                    <?php
                                        $chestNo = !empty($entry['chest_number']) ? $entry['chest_number'] : $entry['entry_number'];
                                        $formattedChest = is_numeric($chestNo) ? str_pad((string)$chestNo, 3, '0', STR_PAD_LEFT) : (string)$chestNo;
                                        $pName = !empty($entry['participant_name']) ? $entry['participant_name'] : (!empty($entry['team_name']) ? $entry['team_name'] : '—');
                                        $teamColor = !empty($entry['team_color']) ? $entry['team_color'] : null;
                                    ?>
                                    <tr>
                                        <?php if ($pType === 'group'): ?>
                                            <td class="chest-col" rowspan="2" style="font-weight: 900; vertical-align: middle; border-bottom: 1.5px solid #000; text-align: center;">
                                                <?php if ($teamColor): ?>
                                                    <span class="team-indicator-dot" style="background: <?= e($teamColor) ?>; width: 10px; height: 10px; display: inline-block; border-radius: 50%; margin-right: 4px; vertical-align: middle;"></span>
                                                <?php endif; ?>
                                                <?= e(!empty($entry['team_name']) ? $entry['team_name'] : $pName) ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="chest-col" rowspan="2" style="font-weight: 900; vertical-align: middle; border-bottom: 1.5px solid #000;">
                                                #<?= e($formattedChest) ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="participant-col" rowspan="2" style="font-weight: 700; font-size: 13px; height: <?= $rowHeight ?>px; vertical-align: middle; border-bottom: 1.5px solid #000;">
                                            <?php if ($teamColor): ?>
                                                <span class="team-indicator-dot" style="background: <?= e($teamColor) ?>;"></span>
                                            <?php endif; ?>
                                            <?= e($pName) ?>
                                        </td>
                                        <?php foreach ($categories as $cat): ?>
                                            <td style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        <?php endforeach; ?>
                                        <td class="col-total" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        <td class="col-rank" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                        <td class="col-notes" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                    </tr>
                                    <tr class="notes-row">
                                        <?php $notesColspan = count($categories) + 1; ?>
                                        <td colspan="<?= $notesColspan ?>" class="notes-span-cell" style="height: 40px; border-bottom: 1.5px solid #000; border-top: none; border-left: none; border-right: 1.5px solid #000; text-align: left; padding: 4px 8px; font-size: 10px; color: #888; font-style: italic; letter-spacing: 0.05em; vertical-align: top;">
                                            Notes: 
                                        </td>
                                        <td class="col-rank" style="height: 40px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                        <td class="col-notes" style="height: 40px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Extra Blank Rows -->
                            <?php for ($b = 1; $b <= 10; $b++): ?>
                                <tr class="extra-blank-row" data-blank-index="<?= $b ?>" style="display: none;">
                                    <td class="chest-col" rowspan="2" style="height: <?= $rowHeight ?>px; vertical-align: middle; border-bottom: 1.5px solid #000;"></td>
                                    <td class="participant-col" rowspan="2" style="height: <?= $rowHeight ?>px; vertical-align: middle; border-bottom: 1.5px solid #000;"></td>
                                    <?php foreach ($categories as $cat): ?>
                                        <td style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                    <?php endforeach; ?>
                                    <td class="col-total" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                    <td class="col-rank" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                    <td class="col-notes" style="height: <?= $rowHeight ?>px; border-bottom: 1px dashed #ccc;"></td>
                                </tr>
                                <tr class="extra-blank-row notes-row" data-blank-index="<?= $b ?>" style="display: none;">
                                    <?php $notesColspan = count($categories) + 1; ?>
                                    <td colspan="<?= $notesColspan ?>" class="notes-span-cell" style="height: 40px; border-bottom: 1.5px solid #000; border-top: none; border-left: none; border-right: 1.5px solid #000; text-align: left; padding: 4px 8px; font-size: 10px; color: #888; font-style: italic; letter-spacing: 0.05em; vertical-align: top;">
                                        Notes:
                                    </td>
                                    <td class="col-rank" style="height: 40px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                    <td class="col-notes" style="height: 40px; border-bottom: 1.5px solid #000; border-top: none; border-left: none;"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sheet-footer">
                    <div class="signature-box">
                        <span class="signature-label">Judge Name:</span>
                        <span class="signature-line"></span>
                    </div>
                    <div class="signature-box">
                        <span class="signature-label">Judge Signature:</span>
                        <span class="signature-line"></span>
                    </div>
                    <div class="signature-box">
                        <span class="signature-label">Date & Time:</span>
                        <span class="signature-line" style="width: 140px;"></span>
                    </div>
                    <div class="signature-box">
                        <span class="signature-label">Chief Judge Sign:</span>
                        <span class="signature-line" style="width: 140px;"></span>
                    </div>
                </div>
                <div class="confidential-notice">
                    Official Musabaqa Score Document — Scores once written and signed are final. Please submit directly to Tabulation Control.
                </div>
            </div>
        <?php endfor; ?>

        <script>
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('auto_print') === '1') {
                        window.print();
                    }
                }, 400);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'score_data') {
    header('Content-Type: application/json; charset=utf-8');
    $entryId = (int)($_GET['entry_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("
            SELECT pe.*, t.team_name, t.team_color,
                   (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                    FROM musabaqa_entry_members em
                    JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                    WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_number
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

        // Find next and previous entries for modal quick navigation
        $allEntriesStmt = $pdo->prepare("
            SELECT id FROM musabaqa_program_entries
            WHERE program_id = ? AND event_id = ?
            ORDER BY performance_order ASC, id ASC
        ");
        $allEntriesStmt->execute([$programId, $activeEventId]);
        $allIds = array_map('intval', $allEntriesStmt->fetchAll(PDO::FETCH_COLUMN));

        $currPos = array_search((int)$entryId, $allIds, true);
        $prevEntryId = ($currPos !== false && $currPos > 0) ? $allIds[$currPos - 1] : null;
        $nextEntryId = ($currPos !== false && $currPos < count($allIds) - 1) ? $allIds[$currPos + 1] : null;
        $entryPosition = ($currPos !== false) ? ($currPos + 1) . ' of ' . count($allIds) : '';

        echo json_encode([
            'success' => true,
            'entry' => $entry,
            'program' => $program,
            'categories' => $categories,
            'sheet' => $sheet,
            'scores' => $scores,
            'locked' => program_scores_entry_locked($program, $sheet),
            'categories_valid' => $categoriesValid,
            'prev_entry_id' => $prevEntryId,
            'next_entry_id' => $nextEntryId,
            'entry_position' => $entryPosition,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load score sheet.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
            exit;
        }
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

            admin_db_transaction($pdo, function ($pdo) use ($programId, $rows, $currentUserId, $activeEventId) {
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
            });

            admin_flash('success', 'Categories saved. Existing editable score sheets were reset for re-scoring.');
            program_scores_redirect($programId);
        }

        if ($action === 'submit_program') {
            if (!empty($program['disable_scores'])) {
                admin_db_transaction($pdo, function ($pdo) use ($activeEventId, $programId, $currentUserId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO musabaqa_score_sheets (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by)
                        SELECT pe.id, pe.program_id, 0.00, 0.00, 0.00, 'completed', ?
                        FROM musabaqa_program_entries pe
                        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
                        WHERE pe.event_id = ? AND pe.program_id = ? AND ss.id IS NULL
                    ");
                    $stmt->execute([$currentUserId, $activeEventId, $programId]);

                    $stmt = $pdo->prepare("
                        UPDATE musabaqa_score_sheets
                        SET status = 'completed', judge1_total = 0.00, judge2_total = 0.00, final_total = 0.00
                        WHERE program_id = ? AND status = 'draft'
                    ");
                    $stmt->execute([$programId]);
                });
            }

            admin_db_transaction($pdo, function ($pdo) use ($activeEventId, $programId, $currentUserId) {
                admin_submit_program_for_approval($pdo, $activeEventId, $programId, $currentUserId);
            });
            admin_flash('success', 'Program sent for approval.');
            program_scores_redirect($programId);
        }

        if ($action !== 'save_score_sheet') {
            throw new RuntimeException('Invalid scoring action.');
        }

        if (in_array((string)$program['approval_status'], ['submitted', 'approved'], true)) {
            throw new RuntimeException('Submitted or approved program scores are locked.');
        }

        $entryId = (int)($_POST['entry_id'] ?? 0);

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

        if (!empty($program['disable_scores'])) {
            // Direct rank entry
            $rankInput = (int)($_POST['placement_rank'] ?? 0);
            $finalRank = $rankInput > 0 ? $rankInput : null;

            admin_db_transaction($pdo, function ($pdo) use ($entryId, $programId, $finalRank, $currentUserId) {
                $stmt = $pdo->prepare('UPDATE musabaqa_program_entries SET final_rank = ?, final_score = 0.00 WHERE id = ?');
                $stmt->execute([$finalRank, $entryId]);

                $stmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
                $stmt->execute([$entryId]);
                $sheetId = (int)$stmt->fetchColumn();

                if ($sheetId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE musabaqa_score_sheets
                        SET program_id = ?, judge1_total = 0.00, judge2_total = 0.00, final_total = 0.00, status = 'completed'
                        WHERE id = ?
                    ");
                    $stmt->execute([$programId, $sheetId]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO musabaqa_score_sheets (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by)
                        VALUES (?, ?, 0.00, 0.00, 0.00, 'completed', ?)
                    ");
                    $stmt->execute([$entryId, $programId, $currentUserId]);
                }
            });

            admin_flash('success', 'Placement rank saved.');
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'Placement rank saved.', 'rank' => $rankInput]);
                exit;
            }
            program_scores_redirect($programId);
            exit;
        }

        if (!$categoriesValid) {
            throw new RuntimeException('Program categories must total exactly 100 before scoring.');
        }

        $postedScores = (array)($_POST['scores'] ?? []);

        // Load existing saved category scores from DB if present
        $existingSheetId = 0;
        $existingSheetStatus = null;
        $existingCategoryScores = [];

        $stmtSheet = $pdo->prepare("SELECT id, status FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1");
        $stmtSheet->execute([$entryId]);
        $sheetRow = $stmtSheet->fetch(PDO::FETCH_ASSOC);
        if ($sheetRow) {
            $existingSheetId = (int)$sheetRow['id'];
            $existingSheetStatus = (string)$sheetRow['status'];
            $stmtCat = $pdo->prepare("SELECT judge_no, category_id, score FROM musabaqa_category_scores WHERE score_sheet_id = ?");
            $stmtCat->execute([$existingSheetId]);
            while ($cRow = $stmtCat->fetch(PDO::FETCH_ASSOC)) {
                $existingCategoryScores[(int)$cRow['judge_no']][(int)$cRow['category_id']] = (float)$cRow['score'];
            }
        }

        $judgesCount = (int)($program['judges_count'] ?? 2);
        $judgeTotals = [];
        $judgeEntryComplete = [];
        for ($j = 1; $j <= $judgesCount; $j++) {
            $judgeTotals[$j] = 0.0;
            $judgeEntryComplete[$j] = true;
        }

        $categoryMap = [];
        foreach ($categories as $category) {
            $categoryMap[(int)$category['id']] = $category;
        }

        $finalCategoryScores = [];

        for ($judgeNo = 1; $judgeNo <= $judgesCount; $judgeNo++) {
            foreach ($categoryMap as $categoryId => $category) {
                $rawScore = $postedScores[$judgeNo][$categoryId] ?? null;

                if ($rawScore !== null && $rawScore !== '' && is_numeric($rawScore)) {
                    $score = (float)$rawScore;
                } elseif (isset($existingCategoryScores[$judgeNo][$categoryId])) {
                    $score = (float)$existingCategoryScores[$judgeNo][$categoryId];
                } else {
                    $score = 0.0;
                    $judgeEntryComplete[$judgeNo] = false;
                }

                $max = (float)$category['max_marks'];
                if ($score < 0) {
                    throw new RuntimeException('Category score cannot be negative.');
                }
                if ($score > $max) {
                    throw new RuntimeException($category['name'] . ' cannot exceed ' . number_format($max, 2) . ' marks.');
                }

                $finalCategoryScores[$judgeNo][$categoryId] = $score;
                $judgeTotals[$judgeNo] += $score;
            }
        }

        $allJudgesDone = true;
        foreach ($judgeEntryComplete as $isDone) {
            if (!$isDone) {
                $allJudgesDone = false;
                break;
            }
        }
        $newSheetStatus = $allJudgesDone ? 'completed' : 'scoring';

        $finalTotal = round(array_sum($judgeTotals), 2);

        admin_db_transaction($pdo, function ($pdo) use ($entryId, $program, $judgeTotals, $judgesCount, $finalCategoryScores, $categoryMap, $programId, $activeEventId, $currentUserId, $finalTotal, $newSheetStatus) {
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
                        status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$programId, $judge1Total, $judge2Total, $finalTotal, $newSheetStatus, (int)$existingSheet['id']]);
                $scoreSheetId = (int)$existingSheet['id'];
                $logType = 'score_update';
                $logText = 'Program score sheet updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO musabaqa_score_sheets
                        (entry_id, program_id, judge1_total, judge2_total, final_total, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$entryId, $programId, $judge1Total, $judge2Total, $finalTotal, $newSheetStatus, $currentUserId]);
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
                    $catScore = (float)($finalCategoryScores[$judgeNo][$categoryId] ?? 0.0);
                    $insert->execute([$scoreSheetId, $judgeNo, $categoryId, $catScore]);
                }
            }

            admin_recalculate_entry_status($pdo, $entryId);
            admin_recalculate_program_status($pdo, $programId);
            admin_log_activity($pdo, $currentUserId, $activeEventId, $logType, 'musabaqa_score_sheets', $scoreSheetId, $logText);
        });

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => 'Score sheet saved.', 'final_total' => number_format((float)$finalTotal, 2)]);
            exit;
        }

        $saveAndNext = !empty($_POST['save_and_next']);

        if ($saveAndNext) {
            $nextStmt = $pdo->prepare("
                SELECT pe.id, pe.entry_name
                FROM musabaqa_program_entries pe
                LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
                WHERE pe.program_id = ? AND pe.event_id = ? AND pe.id != ?
                  AND (ss.id IS NULL OR ss.status != 'completed')
                ORDER BY pe.performance_order ASC, pe.id ASC
                LIMIT 1
            ");
            $nextStmt->execute([$programId, $activeEventId, $entryId]);
            $nextEntry = $nextStmt->fetch(PDO::FETCH_ASSOC);

            if ($nextEntry) {
                admin_flash('success', 'Score sheet saved! Now scoring: ' . ($nextEntry['entry_name'] ?: 'Next Entry'));
                admin_redirect('/judges/program-scores.php', [
                    'program_id' => $programId,
                    'open_entry_id' => (int)$nextEntry['id']
                ]);
                exit;
            }
        }

        if (admin_program_ready_for_approval($pdo, $programId)) {
            admin_flash('ready', 'All entries for this program have been scored. This program is ready for submission.');
        } else {
            admin_flash('success', 'Score sheet saved.');
        }
    } catch (Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Unable to save score sheet.']);
            exit;
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
        ss.status AS sheet_status,
        (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
         FROM musabaqa_entry_members em
         JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
         WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_number
    FROM musabaqa_program_entries pe
    JOIN musabaqa_teams t ON t.id = pe.team_id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
    WHERE pe.event_id = ?
      AND pe.program_id = ?
    ORDER BY pe.performance_order ASC, pe.id ASC
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
        && stripos((string)($entry['chest_number'] ?? ''), $entrySearch) === false
        && stripos((string)$statusText, $entrySearch) === false
        && stripos((string)$scoreText, $entrySearch) === false
    ) {
        continue;
    }
    $entries[] = $entry;
}

// Sort entries: 1st, 2nd, 3rd Place first, then Grade A, Grade B, Grade C, Grade D (by Score DESC)
usort($entries, static function ($a, $b) use ($judgesCount) {
    $rankA = isset($a['final_rank']) && $a['final_rank'] !== null && (int)$a['final_rank'] > 0 ? (int)$a['final_rank'] : 999;
    $rankB = isset($b['final_rank']) && $b['final_rank'] !== null && (int)$b['final_rank'] > 0 ? (int)$b['final_rank'] : 999;

    if ($rankA !== $rankB) {
        return $rankA - $rankB;
    }

    $scoreA = (float)($a['final_total'] ?? $a['final_score'] ?? 0);
    $scoreB = (float)($b['final_total'] ?? $b['final_score'] ?? 0);

    $hasSheetA = !empty($a['score_sheet_id']) || $scoreA > 0;
    $hasSheetB = !empty($b['score_sheet_id']) || $scoreB > 0;

    $gradeAStr = $a['grade'] ?? '';
    if (empty($gradeAStr) && $hasSheetA && $judgesCount > 0) {
        $gInfoA = admin_calculate_grade_info($scoreA, $judgesCount);
        $gradeAStr = $gInfoA['grade'];
    }

    $gradeBStr = $b['grade'] ?? '';
    if (empty($gradeBStr) && $hasSheetB && $judgesCount > 0) {
        $gInfoB = admin_calculate_grade_info($scoreB, $judgesCount);
        $gradeBStr = $gInfoB['grade'];
    }

    $gradeOrder = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4];
    $gOrderA = isset($gradeOrder[strtoupper((string)$gradeAStr)]) ? $gradeOrder[strtoupper((string)$gradeAStr)] : 99;
    $gOrderB = isset($gradeOrder[strtoupper((string)$gradeBStr)]) ? $gradeOrder[strtoupper((string)$gradeBStr)] : 99;

    if ($gOrderA !== $gOrderB) {
        return $gOrderA - $gOrderB;
    }

    if (abs($scoreA - $scoreB) > 0.001) {
        return ($scoreB > $scoreA) ? 1 : -1;
    }

    $perfA = (int)($a['performance_order'] ?? $a['id'] ?? 0);
    $perfB = (int)($b['performance_order'] ?? $b['id'] ?? 0);
    return $perfA - $perfB;
});

$totalEntries = count($entries);

$scoresMap = [];
if ($programId > 0) {
    $stmtCS = $pdo->prepare("
        SELECT ss.entry_id, cs.judge_no, cs.category_id, cs.score
        FROM musabaqa_category_scores cs
        JOIN musabaqa_score_sheets ss ON ss.id = cs.score_sheet_id
        WHERE ss.program_id = ?
    ");
    $stmtCS->execute([$programId]);
    foreach ($stmtCS->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scoresMap[(int)$row['entry_id']][(int)$row['judge_no']][(int)$row['category_id']] = (float)$row['score'];
    }
}

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

$judgesCount = (int)($program['judges_count'] ?? 2);
$liveStageControl = admin_get_live_stage_control($pdo);
$liveStageEntryId = ($programId === (int)$liveStageControl['program_id']) ? (int)$liveStageControl['entry_id'] : 0;
$isGroupProgram = ($program['program_type'] === 'group' || !empty($program['only_team_marks']));

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedEntries) {
        $colspan = !empty($program['disable_scores']) ? 6 : (6 + count($categories) * $judgesCount);
        echo '<tr><td colspan="' . $colspan . '" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Entries Found</div></td></tr>';
    } else {
        $orderIndex = $offset + 1;
        foreach ($paginatedEntries as $entry) {
            echo program_scores_render_row($entry, $categories, $judgesCount, $scoresMap, $scoresLocked, !empty($program['disable_scores']), $orderIndex++, $activeJudgeNo, $isAdminUser, $liveStageEntryId, $isGroupProgram, $totalEntries);
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
?>

<style>
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 1400px !important;
        margin: 0 auto !important;
        padding: 24px !important;
    }
</style>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-gavel mr-2" style="color:var(--accent);"></i> Judges Score Sheet</div>
            <div class="page-subtitle" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span><?= e($program['title']) ?></span>
                <span>·</span>
                <span><?= e(ucfirst((string)$program['program_type'])) ?></span>
                <span>·</span>
                <span><?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?></span>
                <?php if (!empty($program['schedule_section_name'])): ?>
                    <span>·</span>
                    <span class="badge badge-info" style="font-size: 11px;">
                        <i class="fa-solid fa-clock mr-1"></i> <?= e($program['schedule_section_name']) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($program['start_time'])): ?>
                    <span style="color: #34d399; font-weight: 700; font-size: 12px;">
                        <i class="fa-solid fa-clock mr-1"></i><?= date('h:i A', strtotime($program['start_time'])) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($program['only_team_marks'])): ?>
                    <span class="badge badge-info" style="font-size: 11px; background: rgba(14, 165, 233, 0.15); color: #0ea5e9; border: 1px solid rgba(14, 165, 233, 0.3); padding: 2px 8px; border-radius: 9999px;">
                        <i class="fa-solid fa-people-group"></i> Only Team Marks (No Indiv. Marks)
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex gap-2 flex-wrap" style="align-items: center;">
            <?php if ($activeJudgeNo > 0): ?>
                <span class="badge badge-success" style="font-size: 13px; padding: 6px 14px; background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 9999px;">
                    <i class="fa-solid fa-user-check mr-1"></i> Judge <?= $activeJudgeNo ?> Active
                </span>
                <form method="POST" style="margin: 0;">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="lock_judge">
                    <button type="submit" class="btn btn-secondary btn-md" title="Lock session or switch judge identity">
                        <i class="fa-solid fa-lock mr-1"></i> Switch Judge
                    </button>
                </form>
            <?php else: ?>
                <button type="button" class="btn btn-primary btn-md" onclick="document.getElementById('passkeyModal').style.display='flex'">
                    <i class="fa-solid fa-key mr-1"></i> Passkey Login
                </button>
            <?php endif; ?>
            <a class="btn btn-secondary btn-md" href="<?= app_url('/judges/index.php') ?>"><i class="fa-solid fa-arrow-left"></i> Programs</a>
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

    <!-- Passkey Authentication Modal -->
    <div class="modal-overlay" id="passkeyModal" style="display: <?= ($activeJudgeNo === 0 && !$isAdminUser) ? 'flex' : 'none' ?>; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(12px); z-index: 9999; justify-content: center; align-items: center;">
        <div class="modal-box" style="background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(255, 255, 255, 0.12); padding: 28px; border-radius: 16px; width: 100%; max-width: 420px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
            <div style="text-align: center; margin-bottom: 20px;">
                <div style="width: 54px; height: 54px; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; margin: 0 auto 12px auto;">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h3 style="margin: 0; font-size: 20px; color: #fff;">Judge Passkey Unlock</h3>
                <p style="font-size: 13px; color: var(--muted); margin-top: 4px;">Enter your Judge Passkey PIN to unlock criteria mark entry for this program</p>
            </div>

            <form method="POST">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="verify_judge_passkey">

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px; color: var(--muted);">Select Judge Identity</label>
                    <select name="judge_no" class="form-control" style="background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 10px; font-size: 15px; border-radius: 8px;">
                        <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                            <option value="<?= $j ?>">⚖️ Judge <?= $j ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div style="margin-bottom: 24px;">
                    <label class="form-label" style="font-size: 12px; color: var(--muted);">4-Digit Passkey PIN</label>
                    <input type="password" name="passkey" class="form-control" placeholder="••••" maxlength="8" required autofocus style="background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); color: #fff; font-size: 22px; tracking: 4px; text-align: center; padding: 10px; border-radius: 8px;">
                </div>

                <div style="display: flex; gap: 10px;">
                    <?php if ($activeJudgeNo > 0 || $isAdminUser): ?>
                        <button type="button" class="btn btn-secondary flex-1" onclick="document.getElementById('passkeyModal').style.display='none'">Cancel</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary flex-1" style="background: var(--accent); border: none; font-weight: 700; padding: 12px;">
                        <i class="fa-solid fa-key mr-1"></i> Unlock Sheet
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= in_array($flash['type'], ['success', 'ready'], true) ? 'alert-success' : 'alert-error' ?>" id="<?= $flash['type'] === 'ready' ? 'programReadyAlert' : '' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="grid grid-auto gap-5 mb-6">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-list-check"></i></div><div class="stat-value"><?= count($entries) ?></div><div class="stat-label">Entries</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-clipboard-check"></i></div><div class="stat-value"><?= count(array_filter($entries, static fn ($row) => in_array((string)($row['sheet_status'] ?? ''), ['completed','submitted','approved','rejected'], true))) ?></div><div class="stat-label">Scored</div></div>
    </div>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" id="search-form">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
            <div class="input-group full-width">
                <label>Search Entries</label>
                <input type="text" name="search" value="<?= e($entrySearch) ?>" placeholder="Chest number, entry name, team, status or score">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($entrySearch !== ''): ?>
                    <a href="<?= app_url('/judges/program-scores.php') ?>?program_id=<?= (int)$programId ?>" class="btn btn-secondary btn-md">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="modal-overlay" id="scorePanel">
        <div class="modal-box modal-lg">
            <div class="modal-header flex-between" style="align-items: center;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="modal-title" id="scorePanelTitle">Score Entry</div>
                        <span class="badge badge-neutral" id="scoreEntryPositionBadge" style="font-weight: 700; font-size: 11px; display: none;"></span>
                    </div>
                    <div class="page-subtitle" id="scorePanelSubtitle"></div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="btn btn-secondary btn-sm" id="prevEntryBtn" title="Previous Entry" style="display: none;">
                        <i class="fa-solid fa-chevron-left"></i> Prev
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" id="nextEntryBtn" title="Next Entry" style="display: none;">
                        Next <i class="fa-solid fa-chevron-right"></i>
                    </button>
                    <button class="modal-close" type="button" id="closeScorePanel"><i class="fa-solid fa-xmark"></i></button>
                </div>
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
                        <div class="input-group"><label>Chest Number</label><input type="text" id="panelEntryNumber" readonly></div>
                    </div>
                </div>

                <div id="judgeScoreBlocks" class="grid grid-2 gap-4"></div>

                <div class="panel mt-4">
                    <div class="flex-between">
                        <div><strong>Final Score</strong><div class="field-help">Judge 1 total + Judge 2 total</div></div>
                        <div class="stat-value" id="finalTotal">0.00</div>
                    </div>
                </div>

                <div class="form-actions" style="display: flex; justify-content: space-between; align-items: center; gap: 10px; width: 100%;">
                    <button class="btn btn-secondary btn-md" type="button" id="cancelScorePanel">Cancel</button>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-success btn-md" type="submit" name="save_and_next" value="0" id="saveScoreButton" style="font-weight: 700;">
                            <i class="fa-solid fa-check mr-1"></i> Save Score
                        </button>
                        <button class="btn btn-glow-success btn-md" type="submit" name="save_and_next" value="1" id="saveAndNextButton" style="background: linear-gradient(135deg, #10b981, #059669); font-weight: 800; border-color: #10b981;">
                            <i class="fa-solid fa-forward-step mr-1"></i> Save & Next Entry
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php if (!$entries): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-list-check"></i></div><div class="empty-title">No Entries Found</div><div class="empty-subtitle"><?= $entrySearch !== '' ? 'No entries match your search.' : 'Add entries to this program before scoring.' ?></div></div>
    <?php else: ?>
        <div class="table-wrapper" style="overflow-x: auto;">
            <table class="table table-glass">
                <thead>
                    <?php if (!empty($program['disable_scores'])): ?>
                        <tr>
                            <th style="width: 60px;">Order</th>
                            <th style="width: 100px;">Chest #</th>
                            <th>Entry Name</th>
                            <th>Team</th>
                            <th style="width: 160px; text-align: center;">Placement Rank</th>
                            <th style="width: 80px; text-align: center;">Status</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th rowspan="2" style="width: 60px; vertical-align: middle;">Order</th>
                            <th rowspan="2" style="width: 100px; vertical-align: middle;">Chest #</th>
                            <th rowspan="2" style="vertical-align: middle;">Entry Name</th>
                            <th rowspan="2" style="vertical-align: middle;">Team</th>
                            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                                <th colspan="<?= count($categories) ?>" style="text-align: center; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.08); font-weight: 700; color: #fff;">
                                    Judge <?= $j ?>
                                </th>
                            <?php endfor; ?>
                            <th rowspan="2" style="width: 100px; text-align: center; vertical-align: middle;">Final Score</th>
                            <th rowspan="2" style="width: 90px; text-align: center; vertical-align: middle;">Percentage</th>
                            <th rowspan="2" style="width: 90px; text-align: center; vertical-align: middle;">Grade</th>
                            <th rowspan="2" style="width: 80px; text-align: center; vertical-align: middle;">Status</th>
                        </tr>
                        <tr>
                            <?php for ($j = 1; $j <= $judgesCount; $j++): ?>
                                <?php foreach ($categories as $cat): ?>
                                    <th style="text-align: center; font-size: 11px; font-weight: 600; padding: 8px 4px; min-width: 85px;">
                                        <?= e($cat['name']) ?><br>
                                        <small style="color: var(--muted); font-size: 9px;">(Max <?= (int)$cat['max_marks'] ?>)</small>
                                    </th>
                                <?php endforeach; ?>
                            <?php endfor; ?>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody id="table-body">
                    <?php $orderIndex = $offset + 1; ?>
                    <?php foreach ($paginatedEntries as $entry): ?>
                        <?= program_scores_render_row($entry, $categories, $judgesCount, $scoresMap, $scoresLocked, !empty($program['disable_scores']), $orderIndex++, $activeJudgeNo, $isAdminUser, $liveStageEntryId, $isGroupProgram, $totalEntries) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="pagination-container">
            <?= admin_render_pagination_html($page, $perPage, $totalEntries) ?>
        </div>
    <?php endif; ?>

<style>
.score-grid-input {
    transition: all 0.15s ease-in-out;
}
.score-grid-input:focus {
    background: rgba(255, 255, 255, 0.08) !important;
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25) !important;
    outline: none;
}
.score-input-cell {
    padding: 6px 4px !important;
}
.score-grid-rank-select:focus {
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25) !important;
    outline: none;
}
</style>

<script>
const CSRF_TOKEN = <?= json_encode(generate_csrf_token()) ?>;
const PROGRAM_ID = <?= (int)$programId ?>;
const SCORES_LOCKED = <?= json_encode($scoresLocked) ?>;

// --- LocalStorage Draft Caching & Auto-Restoration Engine ---
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
        if (!cache[entryId]) cache[entryId] = {};
        cache[entryId][fieldKey] = val;
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

function clearAllProgramDraftCache() {
    try {
        localStorage.removeItem(DRAFT_CACHE_KEY);
    } catch (e) {}
}

function restoreDraftScoresFromCache() {
    if (SCORES_LOCKED) return;
    const cache = getDraftCache();
    let restoredCount = 0;
    const touchedEntries = new Set();

    Object.keys(cache).forEach(entryId => {
        const entryDrafts = cache[entryId];
        if (!entryDrafts) return;

        const row = document.querySelector(`tr[data-entry-row="${entryId}"]`);
        if (!row) return;

        Object.keys(entryDrafts).forEach(fieldKey => {
            const val = entryDrafts[fieldKey];
            if (val === undefined || val === null) return;

            if (fieldKey === 'rank') {
                const rankSelect = row.querySelector('.score-grid-rank-select');
                if (rankSelect && !rankSelect.disabled) {
                    rankSelect.value = val;
                    touchedEntries.add(entryId);
                    restoredCount++;
                }
            } else {
                const parts = fieldKey.match(/^j(\d+)_cat(\d+)$/);
                if (parts) {
                    const jNo = parts[1];
                    const catId = parts[2];
                    const input = row.querySelector(`.score-grid-input[data-judge="${jNo}"][data-category-id="${catId}"]`);
                    if (input && !input.disabled) {
                        input.value = val;
                        touchedEntries.add(entryId);
                        restoredCount++;
                    }
                }
            }
        });
    });

    if (restoredCount > 0) {
        touchedEntries.forEach(entryId => {
            recalculateRowTotal(entryId);
            saveRowScore(entryId); // Auto-sync restored scores to database
        });
    }
}

// Auto-restore draft scores on page load
if (document.readyState === 'complete') {
    restoreDraftScoresFromCache();
} else {
    window.addEventListener('load', restoreDraftScoresFromCache);
}

let scoreSaveDebounceTimer = null;

// Dynamic calculations & caching on input
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('score-grid-input')) {
        const input = e.target;
        const entryId = input.dataset.entryId;
        const jNo = input.dataset.judge;
        const catId = input.dataset.categoryId;
        const maxVal = parseFloat(input.dataset.max);
        const val = parseFloat(input.value || 0);

        // Save typed value to LocalStorage draft cache instantly
        saveDraftInputToCache(entryId, `j${jNo}_cat${catId}`, input.value);

        // Client-side validation check
        if (val < 0 || val > maxVal) {
            input.style.borderColor = '#f87171'; // Red highlight
            input.style.boxShadow = '0 0 0 2px rgba(239, 68, 68, 0.2)';
        } else {
            input.style.borderColor = 'rgba(255,255,255,0.1)';
            input.style.boxShadow = 'none';
        }

        // Recalculate row total
        recalculateRowTotal(entryId);

        // Auto-save to server 500ms after typing pauses
        clearTimeout(scoreSaveDebounceTimer);
        scoreSaveDebounceTimer = setTimeout(() => {
            saveRowScore(entryId);
        }, 500);
    }
});

// Autosave on blur or change
document.addEventListener('change', (e) => {
    if (e.target.classList.contains('score-grid-input') || e.target.classList.contains('score-grid-rank-select')) {
        const entryId = e.target.dataset.entryId;
        if (e.target.classList.contains('score-grid-rank-select')) {
            saveDraftInputToCache(entryId, 'rank', e.target.value);
        }
        clearTimeout(scoreSaveDebounceTimer);
        saveRowScore(entryId);
    }
});

const JUDGES_COUNT = <?= (int)$judgesCount ?>;

function recalculateRowTotal(entryId) {
    const row = document.querySelector(`tr[data-entry-row="${entryId}"]`);
    if (!row) return;

    let sum = 0.0;
    let hasInput = false;
    row.querySelectorAll('.score-grid-input').forEach(input => {
        const val = parseFloat(input.value);
        if (!isNaN(val)) {
            sum += val;
            hasInput = true;
        }
    });

    const totalEl = document.getElementById(`total-score-${entryId}`);
    const pctEl = document.getElementById(`percentage-${entryId}`);
    const gradeEl = document.getElementById(`grade-badge-${entryId}`);

    if (hasInput && JUDGES_COUNT > 0) {
        if (totalEl) totalEl.textContent = Math.round(sum);

        const pct = (sum / (JUDGES_COUNT * 100)) * 100;
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

async function saveRowScore(entryId) {
    if (SCORES_LOCKED) return;

    const row = document.querySelector(`tr[data-entry-row="${entryId}"]`);
    if (!row) return;

    // Show saving status
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
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const data = await response.json();
        if (data.success) {
            if (statusEl) {
                statusEl.innerHTML = '<i class="fa-solid fa-circle-check text-success" title="Saved"></i>';
            }
            // Clear entry draft cache upon successful server save
            clearEntryDraftFromCache(entryId);

            // Update final total, percentage & grade from server response
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
                    gradeEl.innerHTML = `<span class="badge badge-${badgeClass}" style="font-size: 11px; padding: 3px 8px; font-weight: 800;">Grade ${data.grade}</span>`;
                } else {
                    gradeEl.innerHTML = '<span class="text-muted">—</span>';
                }
            }
            
            checkProgramCompletion();
        } else {
            throw new Error(data.message || 'Error saving score');
        }
    } catch (err) {
        if (statusEl) {
            statusEl.innerHTML = '<i class="fa-solid fa-circle-exclamation text-danger" title="' + escapeHtml(err.message) + '"></i>';
        }
    }
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function checkProgramCompletion() {
    let allScored = true;
    document.querySelectorAll('.row-save-status').forEach(el => {
        if (el.querySelector('.text-warning') || el.querySelector('.text-danger') || el.querySelector('.fa-circle-minus')) {
            allScored = false;
        }
    });

    const submitBtn = document.getElementById('sendApprovalButton');
    if (submitBtn) {
        if (allScored) {
            submitBtn.removeAttribute('disabled');
            submitBtn.classList.add('ready-submit');
        } else {
            submitBtn.setAttribute('disabled', 'disabled');
            submitBtn.classList.remove('ready-submit');
        }
    }
}

// Send For Approval Handler: Auto-syncs all typed marks before submitting approval
const sendApprovalBtn = document.getElementById('sendApprovalButton');
if (sendApprovalBtn) {
    const sendApprovalForm = sendApprovalBtn.closest('form');
    if (sendApprovalForm) {
        sendApprovalForm.addEventListener('submit', async (e) => {
            const rows = document.querySelectorAll('tr[data-entry-row]');
            for (const row of rows) {
                const entryId = row.dataset.entryRow;
                if (entryId) {
                    await saveRowScore(entryId);
                }
            }
            clearAllProgramDraftCache();
        });
    }
}

// Arrow and Enter key cell navigation (Excel-style)
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

// Auto-select text on focus to make overwriting fast
document.addEventListener('focusin', (e) => {
    if (e.target.classList.contains('score-grid-input')) {
        e.target.select();
    }
});

if (document.getElementById('programReadyAlert')) {
    setTimeout(() => {
        document.getElementById('sendApprovalButton')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    }, 350);
}

// Emcee Live Speaker Auto-Sync Polling
let currentLiveEntryId = <?= (int)$liveStageEntryId ?>;
setInterval(() => {
    fetch('<?= app_url('/emcee/index.php?ajax_status=1') ?>')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.live_control) {
            const newLiveEntryId = parseInt(data.live_control.entry_id, 10);
            if (newLiveEntryId !== currentLiveEntryId) {
                currentLiveEntryId = newLiveEntryId;
                document.querySelectorAll('tr[data-entry-row]').forEach(tr => {
                    const entryId = parseInt(tr.dataset.entryRow, 10);
                    if (entryId === currentLiveEntryId) {
                        tr.style.background = 'rgba(16, 185, 129, 0.18)';
                        tr.style.borderLeft = '4px solid #34d399';
                    } else {
                        tr.style.background = '';
                        tr.style.borderLeft = '';
                    }
                });
            }
        }
    })
    .catch(() => {});
}, 3000);
</script>
</div>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
