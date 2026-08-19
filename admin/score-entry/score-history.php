<?php
$pageTitle = 'Score History';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Filter Parameters
$filterTeamId = (int)($_GET['team_id'] ?? 0);
$filterProgramId = (int)($_GET['program_id'] ?? 0);
$filterRank = trim((string)($_GET['rank'] ?? 'all'));
$searchQuery = trim((string)($_GET['search'] ?? ''));
$sortOrder = trim((string)($_GET['sort'] ?? 'latest'));

// Fetch Teams
$teamsStmt = $pdo->prepare("
    SELECT id, team_name, short_name, team_color, total_score 
    FROM musabaqa_teams 
    WHERE event_id = ? 
    ORDER BY total_score DESC, team_name ASC
");
$teamsStmt->execute([$activeEventId]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
$teamsById = [];
foreach ($teams as $t) {
    $teamsById[(int)$t['id']] = $t;
}

// Show the four leading teams with points earned by their entries.
$teamMarksStmt = $pdo->prepare(" 
    SELECT
        t.id,
        t.team_name,
        t.team_color,
        t.total_score,
        COALESCE(SUM(CASE WHEN p.approval_status = 'approved' AND ss.status = 'approved' THEN pe.team_score ELSE 0 END), 0) AS approved_points,
        0 AS submitted_points,
        COALESCE(SUM(CASE WHEN p.approval_status = 'approved' AND ss.status = 'approved' THEN pe.team_score ELSE 0 END), 0) AS total_points
    FROM musabaqa_teams t
    LEFT JOIN musabaqa_program_entries pe
        ON pe.team_id = t.id AND pe.event_id = t.event_id
    LEFT JOIN musabaqa_programs p
        ON p.id = pe.program_id AND p.event_id = pe.event_id
    LEFT JOIN musabaqa_score_sheets ss
        ON ss.entry_id = pe.id AND ss.program_id = pe.program_id
    WHERE t.event_id = ?
    GROUP BY t.id, t.team_name, t.team_color, t.total_score
    ORDER BY t.total_score DESC, t.team_name ASC
    LIMIT 4
");
$teamMarksStmt->execute([$activeEventId]);
$teamMarks = $teamMarksStmt->fetchAll(PDO::FETCH_ASSOC);

// Submitted programs do not receive pe.team_score until approval, so calculate
// their provisional rank points from the submitted score sheets.
$submittedPointsByTeam = [];
$submittedStmt = $pdo->prepare(" 
    SELECT
        p.id AS program_id,
        p.team_points_config,
        pe.team_id,
        pe.final_rank,
        ss.final_total
    FROM musabaqa_programs p
    JOIN musabaqa_program_entries pe ON pe.program_id = p.id AND pe.event_id = p.event_id
    JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id AND ss.program_id = p.id
    WHERE p.event_id = ?
      AND p.approval_status = 'submitted'
      AND ss.status = 'submitted'
    ORDER BY p.id ASC, ss.final_total DESC, pe.id ASC
");
$submittedStmt->execute([$activeEventId]);
$submittedEntriesByProgram = [];
foreach ($submittedStmt->fetchAll(PDO::FETCH_ASSOC) as $submittedEntry) {
    $submittedEntriesByProgram[(int)$submittedEntry['program_id']][] = $submittedEntry;
}

$settings = admin_get_settings($pdo);
$defaultPointConfig = [
    1 => (int)($settings['first_place_points'] ?? 10),
    2 => (int)($settings['second_place_points'] ?? 7),
    3 => (int)($settings['third_place_points'] ?? 5),
];

foreach ($submittedEntriesByProgram as $submittedEntries) {
    $pointConfig = $defaultPointConfig;
    $customConfig = json_decode((string)($submittedEntries[0]['team_points_config'] ?? ''), true);
    if (is_array($customConfig)) {
        $pointConfig = [];
        foreach ($customConfig as $rank => $points) {
            $pointConfig[(int)$rank] = (float)$points;
        }
    }

    $previousScore = null;
    foreach ($submittedEntries as $entryIndex => $submittedEntry) {
        $score = (float)$submittedEntry['final_total'];
        $rank = $entryIndex + 1;
        if ($previousScore !== null && abs($score - $previousScore) < 0.001) {
            $rank = $entryIndex;
        }
        $teamId = (int)$submittedEntry['team_id'];
        $submittedPointsByTeam[$teamId] = ($submittedPointsByTeam[$teamId] ?? 0) + (float)($pointConfig[$rank] ?? 0);
        $previousScore = $score;
    }
}

foreach ($teamMarks as &$teamMark) {
    $teamId = (int)$teamMark['id'];
    $teamMark['submitted_points'] = (float)($submittedPointsByTeam[$teamId] ?? 0);
    $teamMark['total_points'] = (float)$teamMark['approved_points'] + $teamMark['submitted_points'];
}
unset($teamMark);

// Fetch Approved Programs for filter dropdown
$progsStmt = $pdo->prepare("
    SELECT id, title, program_type 
    FROM musabaqa_programs 
    WHERE event_id = ? AND approval_status = 'approved' 
    ORDER BY title ASC
");
$progsStmt->execute([$activeEventId]);
$approvedPrograms = $progsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Chronological Approved Entry Scores (with team_score > 0)
$rawLogsStmt = $pdo->prepare("
    SELECT 
        pe.id AS entry_id,
        pe.program_id,
        pe.team_id,
        pe.entry_name,
        pe.entry_number,
        pe.final_score,
        pe.final_rank,
        pe.team_score,
        p.title AS program_title,
        p.program_type,
        p.disable_scores,
        COALESCE(p.reviewed_at, p.submitted_at, p.created_at) AS approved_at,
        u.full_name AS approved_by_name,
        t.team_name,
        t.team_color
    FROM musabaqa_program_entries pe
    JOIN musabaqa_teams t ON t.id = pe.team_id
    JOIN musabaqa_programs p ON p.id = pe.program_id
    LEFT JOIN " . DB_MAIN_NAME . ".users u ON u.id = p.reviewed_by
    WHERE pe.event_id = ?
      AND p.approval_status = 'approved'
      AND pe.team_score > 0
    ORDER BY COALESCE(p.reviewed_at, p.submitted_at, p.created_at) ASC, pe.final_rank ASC, pe.id ASC
");
$rawLogsStmt->execute([$activeEventId]);
$rawLogs = $rawLogsStmt->fetchAll(PDO::FETCH_ASSOC);

// Compute Running Totals & Per-Team History
$teamRunningTotals = [];
$teamStats = [];
$allLogsWithCumulative = [];

foreach ($teams as $t) {
    $tId = (int)$t['id'];
    $teamRunningTotals[$tId] = 0.0;
    $teamStats[$tId] = [
        'team_name' => $t['team_name'],
        'team_color' => $t['team_color'],
        'current_total' => (float)$t['total_score'],
        'calculated_total' => 0.0,
        'first_count' => 0,
        'second_count' => 0,
        'third_count' => 0,
        'other_count' => 0,
        'entries_count' => 0,
    ];
}

foreach ($rawLogs as $log) {
    $tId = (int)$log['team_id'];
    $pts = (float)$log['team_score'];
    $rank = (int)$log['final_rank'];

    $prevTotal = $teamRunningTotals[$tId] ?? 0.0;
    $newTotal = $prevTotal + $pts;
    $teamRunningTotals[$tId] = $newTotal;

    if (isset($teamStats[$tId])) {
        $teamStats[$tId]['calculated_total'] += $pts;
        $teamStats[$tId]['entries_count']++;
        if ($rank === 1) $teamStats[$tId]['first_count']++;
        elseif ($rank === 2) $teamStats[$tId]['second_count']++;
        elseif ($rank === 3) $teamStats[$tId]['third_count']++;
        else $teamStats[$tId]['other_count']++;
    }

    $enhancedLog = array_merge($log, [
        'previous_total' => $prevTotal,
        'new_total' => $newTotal,
    ]);

    $allLogsWithCumulative[] = $enhancedLog;
}

// Apply Filtering
$filteredLogs = array_filter($allLogsWithCumulative, function ($item) use ($filterTeamId, $filterProgramId, $filterRank, $searchQuery) {
    if ($filterTeamId > 0 && (int)$item['team_id'] !== $filterTeamId) {
        return false;
    }
    if ($filterProgramId > 0 && (int)$item['program_id'] !== $filterProgramId) {
        return false;
    }
    if ($filterRank !== 'all') {
        if ($filterRank === '1' && (int)$item['final_rank'] !== 1) return false;
        if ($filterRank === '2' && (int)$item['final_rank'] !== 2) return false;
        if ($filterRank === '3' && (int)$item['final_rank'] !== 3) return false;
        if ($filterRank === 'other' && (int)$item['final_rank'] <= 3) return false;
    }
    if ($searchQuery !== '') {
        $q = mb_strtolower($searchQuery);
        $nameMatch = str_contains(mb_strtolower((string)$item['entry_name']), $q);
        $progMatch = str_contains(mb_strtolower((string)$item['program_title']), $q);
        $teamMatch = str_contains(mb_strtolower((string)$item['team_name']), $q);
        if (!$nameMatch && !$progMatch && !$teamMatch) {
            return false;
        }
    }
    return true;
});

// Apply Sorting
usort($filteredLogs, function ($a, $b) use ($sortOrder) {
    $rA = (int)$a['final_rank'];
    $rB = (int)$b['final_rank'];
    $valA = $rA === 0 ? 999999 : $rA;
    $valB = $rB === 0 ? 999999 : $rB;

    return match ($sortOrder) {
        'oldest' => strcmp((string)$a['approved_at'], (string)$b['approved_at']) ?: ($valA <=> $valB) ?: ($a['entry_id'] <=> $b['entry_id']),
        'points_desc' => ($b['team_score'] <=> $a['team_score']) ?: strcmp((string)$b['approved_at'], (string)$a['approved_at']) ?: ($valA <=> $valB) ?: ($b['entry_id'] <=> $a['entry_id']),
        'points_asc' => ($a['team_score'] <=> $b['team_score']) ?: strcmp((string)$a['approved_at'], (string)$b['approved_at']) ?: ($valA <=> $valB) ?: ($a['entry_id'] <=> $b['entry_id']),
        default => strcmp((string)$b['approved_at'], (string)$a['approved_at']) ?: ($valA <=> $valB) ?: ($b['entry_id'] <=> $a['entry_id']),
    };
});

// Group filtered logs by program for dropdown accordion view
$programGroups = [];
foreach ($filteredLogs as $log) {
    $pId = (int)$log['program_id'];
    if (!isset($programGroups[$pId])) {
        $programGroups[$pId] = [
            'program_id' => $pId,
            'program_title' => $log['program_title'],
            'program_type' => $log['program_type'],
            'approved_at' => $log['approved_at'],
            'approved_by_name' => $log['approved_by_name'],
            'total_points_awarded' => 0,
            'entries' => [],
        ];
    }
    $programGroups[$pId]['total_points_awarded'] += (float)$log['team_score'];
    $programGroups[$pId]['entries'][] = $log;
}

// Sort entries within each program group by final_rank ASC (rank order)
foreach ($programGroups as $pId => &$pData) {
    usort($pData['entries'], function ($a, $b) {
        $rA = (int)$a['final_rank'];
        $rB = (int)$b['final_rank'];
        $valA = $rA === 0 ? 999999 : $rA;
        $valB = $rB === 0 ? 999999 : $rB;
        if ($valA === $valB) {
            // Tie breaker: final score desc, then entry_id asc
            $sA = (float)$a['final_score'];
            $sB = (float)$b['final_score'];
            if ($sA == $sB) {
                return (int)$a['entry_id'] <=> (int)$b['entry_id'];
            }
            return $sB <=> $sA;
        }
        return $valA <=> $valB;
    });
}
unset($pData);

// CSV Export Handling
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="score_history_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['#', 'Date & Time', 'Team Name', 'Program Title', 'Participant / Entry Name', 'Rank', 'Judge Mark', 'Team Points Added', 'Team Running Total', 'Approved By']);
    
    foreach ($filteredLogs as $idx => $row) {
        fputcsv($output, [
            $idx + 1,
            date('Y-m-d H:i:s', strtotime($row['approved_at'])),
            $row['team_name'],
            $row['program_title'],
            $row['entry_name'],
            'Rank ' . $row['final_rank'],
            number_format((float)$row['final_score'], 2),
            '+' . (int)$row['team_score'],
            number_format((float)$row['new_total'], 1),
            $row['approved_by_name'] ?: 'System Administrator',
        ]);
    }
    fclose($output);
    exit;
}

// Summary Metrics
$totalPointsAwarded = array_sum(array_column($allLogsWithCumulative, 'team_score'));
$totalApprovedCount = count($approvedPrograms);
$totalEventsLogged = count($allLogsWithCumulative);
$topTeam = $teams[0] ?? null;

$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
/* Page & Card Custom Styles */
.score-metric-card {
    aspect-ratio: 1 / 1;
    min-height: 165px;
    padding: 20px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.008));
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.25s ease, box-shadow 0.25s ease;
}

.score-metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4), 0 0 20px rgba(99, 102, 241, 0.1);
}

.filter-panel {
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 24px;
}

.program-card-accordion {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.015);
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.25s ease, box-shadow 0.25s ease;
}

.program-card-accordion:hover {
    border-color: rgba(99, 102, 241, 0.35);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35), 0 0 16px rgba(99, 102, 241, 0.12);
    transform: translateY(-2px);
}

.prog-accordion-header {
    user-select: none;
    background: rgba(255, 255, 255, 0.02);
    transition: background 0.2s ease;
}

.prog-accordion-header:hover {
    background: rgba(99, 102, 241, 0.06);
}

.prog-chevron {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: rgba(99, 102, 241, 0.15);
    color: #818cf8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), background-color 0.25s ease, color 0.25s ease;
    flex-shrink: 0;
}

.program-card-accordion.is-open .prog-chevron {
    transform: rotate(180deg);
    background-color: #6366f1;
    color: #fff;
    box-shadow: 0 0 12px rgba(99, 102, 241, 0.5);
}

.prog-accordion-wrapper {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.program-card-accordion.is-open .prog-accordion-wrapper {
    grid-template-rows: 1fr;
}

.prog-accordion-body {
    overflow: hidden;
    opacity: 0;
    border-top: 1px solid transparent;
    background: rgba(0, 0, 0, 0.25);
    transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.3s ease;
}

.program-card-accordion.is-open .prog-accordion-body {
    opacity: 1;
    border-top-color: rgba(255, 255, 255, 0.08);
}

.prog-table-row {
    transition: background 0.15s ease;
}

.prog-table-row:hover {
    background: rgba(99, 102, 241, 0.07) !important;
}
</style>

<div class="main-content">
    
    <!-- Topbar Header -->
    <div class="topbar flex-between items-center mb-6">
        <div>
            <div class="page-title flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left mr-1" style="color: #818cf8; font-size: 22px;"></i> 
                Score History &amp; Team Marks Log
            </div>
            <div class="page-subtitle" style="margin-top: 2px;">Chronological record of team points earned across all approved programs</div>
        </div>
        <div class="flex gap-2">
            <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/score-entry/score-entry.php') ?>">
                <i class="fa-solid fa-calculator mr-1"></i> Scoring Sheet
            </a>
            <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/score-entry/program-scores.php') ?>">
                <i class="fa-solid fa-list-check mr-1"></i> All Scores
            </a>
            <button type="button" class="btn btn-primary btn-md" data-modal-open="teamMarksModal">
                <i class="fa-solid fa-users-viewfinder mr-1"></i> Submitted Marks
            </button>
            <?php 
                $exportQuery = array_merge($_GET, ['export' => 'csv']); 
                $exportUrl = '?' . http_build_query($exportQuery);
            ?>
            <a class="btn btn-success btn-md" href="<?= e($exportUrl) ?>">
                <i class="fa-solid fa-file-csv mr-1"></i> Export CSV
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- Summary Metrics Cards (Single Row Square Cards) -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
        
        <!-- Card 1: Total Points -->
        <div class="score-metric-card" style="border-color: rgba(16, 185, 129, 0.25); background: radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #34d399;">Total Points</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(16, 185, 129, 0.15); color: #34d399; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-trophy"></i>
                </span>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: 800; color: #10b981; line-height: 1.1;">
                    <?= number_format((float)$totalPointsAwarded, 0) ?>
                    <span style="font-size: 14px; font-weight: 600; color: rgba(52, 211, 153, 0.85); margin-left: 2px;">Pts</span>
                </div>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(16, 185, 129, 0.2); padding-top: 8px;">
                <i class="fa-solid fa-list-check mr-1" style="color: #34d399;"></i> <?= $totalEventsLogged ?> score additions
            </div>
        </div>

        <!-- Card 2: Leading Team -->
        <div class="score-metric-card" style="border-color: rgba(99, 102, 241, 0.25); background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.12), rgba(99, 102, 241, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #818cf8;">Leading Team</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(99, 102, 241, 0.15); color: #818cf8; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-crown"></i>
                </span>
            </div>
            <div>
                <?php if ($topTeam): ?>
                    <div class="flex items-center gap-2 mb-1">
                        <span style="background-color: <?= e($topTeam['team_color'] ?: '#6366f1') ?>; width: 12px; height: 12px; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px <?= e($topTeam['team_color'] ?: '#6366f1') ?>;"></span>
                        <span style="font-size: 22px; font-weight: 800; color: #fff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; line-height: 1.2;"><?= e($topTeam['team_name']) ?></span>
                    </div>
                    <div style="font-size: 14px; font-weight: 700; color: #818cf8;">
                        <?= number_format((float)$topTeam['total_score'], 0) ?> <span style="font-size: 11px; font-weight: 500; color: var(--muted);">Points</span>
                    </div>
                <?php else: ?>
                    <span style="font-size: 20px; font-weight: 700; color: var(--muted);">No Teams</span>
                <?php endif; ?>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(99, 102, 241, 0.2); padding-top: 8px;">
                <i class="fa-solid fa-medal mr-1" style="color: #818cf8;"></i> Rank #1 Team
            </div>
        </div>

        <!-- Card 3: Approved Programs -->
        <div class="score-metric-card" style="border-color: rgba(245, 158, 11, 0.25); background: radial-gradient(circle at top right, rgba(245, 158, 11, 0.12), rgba(245, 158, 11, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #fbbf24;">Programs Approved</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); color: #fbbf24; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: 800; color: #f59e0b; line-height: 1.1;">
                    <?= $totalApprovedCount ?>
                    <span style="font-size: 14px; font-weight: 600; color: rgba(251, 191, 36, 0.85); margin-left: 2px;">Done</span>
                </div>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(245, 158, 11, 0.2); padding-top: 8px;">
                <i class="fa-solid fa-chart-line mr-1" style="color: #fbbf24;"></i> Finalized &amp; Scores Added
            </div>
        </div>

        <!-- Card 4: Latest Score Increase -->
        <div class="score-metric-card" style="border-color: rgba(236, 72, 153, 0.25); background: radial-gradient(circle at top right, rgba(236, 72, 153, 0.12), rgba(236, 72, 153, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #f472b6;">Latest Score Increase</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(236, 72, 153, 0.15); color: #f472b6; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-bolt"></i>
                </span>
            </div>
            <div>
                <?php $latestEntry = reset($allLogsWithCumulative); ?>
                <?php if ($latestEntry): ?>
                    <div style="font-size: 26px; font-weight: 800; color: #ec4899; line-height: 1.1;">
                        +<?= (int)$latestEntry['team_score'] ?> <span style="font-size: 13px; font-weight: 600; color: rgba(244, 114, 182, 0.85);">Pts</span>
                    </div>
                    <div style="font-size: 13px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 4px;">
                        <?= e($latestEntry['team_name']) ?>
                    </div>
                <?php else: ?>
                    <div style="font-size: 20px; font-weight: 700; color: var(--muted);">No Approvals</div>
                <?php endif; ?>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(236, 72, 153, 0.2); padding-top: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <?php if ($latestEntry): ?>
                    <i class="fa-solid fa-clock mr-1" style="color: #f472b6;"></i> <?= e($latestEntry['program_title']) ?>
                <?php else: ?>
                    Approve programs to view
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Filter Control Bar -->
    <div class="filter-panel">
        <form method="GET" class="flex gap-3 flex-wrap items-center">
            
            <div class="input-group" style="min-width: 180px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Filter by Team</label>
                <select name="team_id" class="form-input" onchange="this.form.submit()">
                    <option value="0">-- All Teams --</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $filterTeamId === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= e($t['team_name']) ?> (<?= number_format((float)$t['total_score'], 0) ?> pts)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="min-width: 200px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Filter by Program</label>
                <select name="program_id" class="form-input" onchange="this.form.submit()">
                    <option value="0">-- All Programs --</option>
                    <?php foreach ($approvedPrograms as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $filterProgramId === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="min-width: 130px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Position Rank</label>
                <select name="rank" class="form-input" onchange="this.form.submit()">
                    <option value="all" <?= $filterRank === 'all' ? 'selected' : '' ?>>All Ranks</option>
                    <option value="1" <?= $filterRank === '1' ? 'selected' : '' ?>>🥇 1st Rank</option>
                    <option value="2" <?= $filterRank === '2' ? 'selected' : '' ?>>🥈 2nd Rank</option>
                    <option value="3" <?= $filterRank === '3' ? 'selected' : '' ?>>🥉 3rd Rank</option>
                    <option value="other" <?= $filterRank === 'other' ? 'selected' : '' ?>>4th Rank &amp; Below</option>
                </select>
            </div>

            <div class="input-group" style="min-width: 140px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Sort Sequence</label>
                <select name="sort" class="form-input" onchange="this.form.submit()">
                    <option value="latest" <?= $sortOrder === 'latest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="oldest" <?= $sortOrder === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="points_desc" <?= $sortOrder === 'points_desc' ? 'selected' : '' ?>>Highest Points Added</option>
                    <option value="points_asc" <?= $sortOrder === 'points_asc' ? 'selected' : '' ?>>Lowest Points Added</option>
                </select>
            </div>

            <div class="input-group" style="flex: 1; min-width: 180px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Search Participant / Program</label>
                <input type="text" name="search" class="form-input" value="<?= e($searchQuery) ?>" placeholder="Search name or title...">
            </div>

            <div style="margin-top: 18px;" class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-md"><i class="fa-solid fa-magnifying-glass mr-1"></i> Filter</button>
                <?php if ($filterTeamId > 0 || $filterProgramId > 0 || $filterRank !== 'all' || $searchQuery !== '' || $sortOrder !== 'latest'): ?>
                    <a href="?" class="btn btn-secondary btn-md" title="Reset Filters"><i class="fa-solid fa-rotate-left mr-1"></i> Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Program Accordion Dropdown Section -->
    <div class="panel mb-6" style="padding: 24px; border-radius: 16px; background: rgba(15, 23, 42, 0.5); border: 1px solid rgba(255,255,255,0.08);">
        <div class="flex-between items-center mb-5 pb-4" style="border-bottom: 1px solid rgba(255,255,255,0.08);">
            <div>
                <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-layer-group" style="color: #818cf8;"></i>
                    Programs &amp; Winner Dropdowns
                </h3>
                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 3px;">
                    Showing <strong><?= count($programGroups) ?></strong> program(s) with <strong><?= count($filteredLogs) ?></strong> score additions. Click any program to expand score details.
                </span>
            </div>
            
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-secondary btn-sm" id="btnExpandAll" style="font-size: 11.5px; border-radius: 8px;">
                    <i class="fa-solid fa-angles-down mr-1"></i> Expand All
                </button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnCollapseAll" style="font-size: 11.5px; border-radius: 8px;">
                    <i class="fa-solid fa-angles-up mr-1"></i> Collapse All
                </button>
            </div>
        </div>

        <?php if (empty($programGroups)): ?>
            <div class="text-center" style="padding: 50px 10px; color: var(--muted);">
                <i class="fa-solid fa-folder-open mb-3" style="font-size: 36px; opacity: 0.4;"></i><br>
                <strong style="font-size: 15px; color: #fff;">No Score Records Found</strong><br>
                <span style="font-size: 12.5px;">No score history entries matched your selected filter criteria.</span>
            </div>
        <?php else: ?>
            <div class="program-accordion-list" style="display: flex; flex-direction: column; gap: 14px;">
                <?php foreach ($programGroups as $pId => $pData): ?>
                    <div class="program-card-accordion">
                        
                        <!-- Accordion Program Header (Click to Open Dropdown) -->
                        <div class="prog-accordion-header p-4 cursor-pointer flex-between items-center" data-target="prog_drop_<?= $pId ?>">
                            <div class="flex items-center gap-3" style="min-width: 0;">
                                <span class="prog-chevron">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </span>
                                <div style="min-width: 0;">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?= e($pData['program_title']) ?>
                                        </h4>
                                        <?php 
                                            $isGroup = strtolower((string)$pData['program_type']) === 'group';
                                            $typeBadgeStyle = $isGroup 
                                                ? 'background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3);' 
                                                : 'background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);';
                                        ?>
                                        <span class="badge" style="font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 2px 8px; <?= $typeBadgeStyle ?>">
                                            <?= e(ucfirst($pData['program_type'] ?: 'Individual')) ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 4px;" class="flex items-center gap-3 flex-wrap">
                                        <span><i class="fa-solid fa-clock mr-1" style="font-size: 10px; color: #818cf8;"></i> Approved <?= date('d M Y, h:i A', strtotime($pData['approved_at'])) ?></span>
                                        <span>&bull;</span>
                                        <span><i class="fa-solid fa-user-check mr-1" style="font-size: 10px; color: #10b981;"></i> <?= e($pData['approved_by_name'] ?: 'System Admin') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3" style="flex-shrink: 0;">
                                <span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.3); font-size: 12px; font-weight: 600; padding: 5px 12px;">
                                    <i class="fa-solid fa-users mr-1"></i> <?= count($pData['entries']) ?> Winners
                                </span>
                                <span class="badge" style="background: rgba(16, 185, 129, 0.18); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); font-size: 13.5px; font-weight: 800; padding: 5px 14px;">
                                    +<?= (int)$pData['total_points_awarded'] ?> Pts Total
                                </span>
                            </div>
                        </div>

                        <!-- Accordion Grid Slide Wrapper -->
                        <div class="prog-accordion-wrapper">
                            <!-- Dropdown Content: Shows Who Got the Scores -->
                            <div id="prog_drop_<?= $pId ?>" class="prog-accordion-body">
                                <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0; background: transparent;">
                                    <table class="table" style="margin: 0; font-size: 12.5px;">
                                        <thead>
                                            <tr style="background: rgba(255,255,255,0.03);">
                                                <th style="width: 45px; padding: 12px 16px;">#</th>
                                                <th style="padding: 12px 16px;">Participant / Entry Name</th>
                                                <th style="padding: 12px 16px;">Team</th>
                                                <th style="text-align: center; width: 160px; padding: 12px 16px;">Position Rank</th>
                                                <th style="text-align: right; width: 110px; padding: 12px 16px;">Judge Score</th>
                                                <th style="text-align: center; width: 150px; padding: 12px 16px;">Team Points Added</th>
                                                <th style="text-align: right; width: 150px; padding: 12px 16px;">Team Total After</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pData['entries'] as $eIndex => $eLog): ?>
                                                <?php 
                                                    $rank = (int)$eLog['final_rank'];
                                                    $rankBadge = match ($rank) {
                                                        1 => '<span class="badge" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.25), rgba(217, 119, 6, 0.15)); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); font-weight: 700; padding: 4px 10px;">🥇 1st Rank</span>',
                                                        2 => '<span class="badge" style="background: linear-gradient(135deg, rgba(148, 163, 184, 0.25), rgba(100, 116, 139, 0.15)); color: #e2e8f0; border: 1px solid rgba(148, 163, 184, 0.4); font-weight: 700; padding: 4px 10px;">🥈 2nd Rank</span>',
                                                        3 => '<span class="badge" style="background: linear-gradient(135deg, rgba(180, 83, 9, 0.25), rgba(146, 64, 14, 0.15)); color: #fbbf24; border: 1px solid rgba(180, 83, 9, 0.4); font-weight: 700; padding: 4px 10px;">🥉 3rd Rank</span>',
                                                        default => '<span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 600; padding: 4px 10px;"><i class="fa-solid fa-star mr-1" style="font-size: 10px;"></i> Rank ' . $rank . ' (+3 Bonus)</span>',
                                                    };
                                                    $pts = (float)$eLog['team_score'];
                                                ?>
                                                <tr class="prog-table-row">
                                                    <td style="color: var(--muted); padding: 14px 16px; font-weight: 600;"><?= $eIndex + 1 ?></td>
                                                    <td style="padding: 14px 16px;">
                                                        <strong style="color: #fff; font-size: 13.5px; font-weight: 700;"><?= e($eLog['entry_name']) ?></strong>
                                                        <?php if ($eLog['entry_number']): ?>
                                                            <span style="font-size: 10.5px; color: var(--muted); display: block; margin-top: 2px;">Chest #<?= e($eLog['entry_number']) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="padding: 14px 16px;">
                                                        <div class="flex items-center gap-2">
                                                            <span style="width: 10px; height: 10px; border-radius: 50%; background-color: <?= e($eLog['team_color'] ?: '#6366f1') ?>; display: inline-block; box-shadow: 0 0 8px <?= e($eLog['team_color'] ?: '#6366f1') ?>;"></span>
                                                            <strong style="color: #fff; font-size: 13.5px;"><?= e($eLog['team_name']) ?></strong>
                                                        </div>
                                                    </td>
                                                    <td style="text-align: center; padding: 14px 16px;">
                                                        <?= $rankBadge ?>
                                                    </td>
                                                    <td style="text-align: right; font-weight: 700; color: #fff; padding: 14px 16px; font-size: 13px;">
                                                        <?php if (!empty($eLog['disable_scores'])): ?>
                                                            <span style="color: var(--muted); font-size: 12px; font-weight: normal;">—</span>
                                                        <?php else: ?>
                                                            <?= number_format((float)$eLog['final_score'], 2) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: center; padding: 14px 16px;">
                                                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); font-size: 13.5px; font-weight: 800; padding: 4px 12px;">
                                                            +<?= (int)$pts ?> Pts
                                                        </span>
                                                    </td>
                                                    <td style="text-align: right; padding: 14px 16px;">
                                                        <div style="font-size: 13.5px; font-weight: 800; color: #10b981;">
                                                            <?= number_format((float)$eLog['new_total'], 1) ?> pts
                                                        </div>
                                                        <div style="font-size: 10.5px; color: var(--muted); margin-top: 1px;">
                                                            (was <?= number_format((float)$eLog['previous_total'], 1) ?>)
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal-overlay" id="teamMarksModal" aria-hidden="true">
    <div class="modal-box" style="width: min(760px, calc(100vw - 32px));">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-ranking-star mr-2" style="color: var(--accent);"></i> Team Points</div>
                <div style="margin-top: 4px; color: var(--muted); font-size: 12px;">Approved and submitted team points for the four leading teams.</div>
            </div>
            <button class="modal-close" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div style="padding: 20px;">
            <?php if (!$teamMarks): ?>
                <div class="text-center" style="padding: 36px 16px; color: var(--muted);">
                    <i class="fa-solid fa-users-slash" style="font-size: 32px; opacity: .55;"></i>
                    <p style="margin: 12px 0 0;">No teams found for this event.</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper" style="margin: 0;">
                    <table class="table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 64px;">Rank</th>
                                <th>Team</th>
                                <th style="text-align: right;">Approved Points</th>
                                <th style="text-align: right;">Submitted Points</th>
                                <th style="text-align: right;">Total Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teamMarks as $teamIndex => $teamMark): ?>
                                <tr>
                                    <td style="font-weight: 800; color: var(--muted);">#<?= $teamIndex + 1 ?></td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span style="width: 10px; height: 10px; border-radius: 50%; background: <?= e($teamMark['team_color'] ?: '#64748b') ?>; box-shadow: 0 0 8px <?= e($teamMark['team_color'] ?: '#64748b') ?>;"></span>
                                            <strong><?= e($teamMark['team_name']) ?></strong>
                                        </div>
                                    </td>
                                    <td style="text-align: right; color: #34d399; font-weight: 700;">
                                        <?= number_format((float)$teamMark['approved_points'], 1) ?>
                                    </td>
                                    <td style="text-align: right; color: #fbbf24; font-weight: 700;">
                                        <?= number_format((float)$teamMark['submitted_points'], 1) ?>
                                    </td>
                                    <td style="text-align: right; color: #fff; font-weight: 800; font-size: 15px;">
                                        <?= number_format((float)$teamMark['total_points'], 1) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions" style="padding: 14px 20px; border-top: 1px solid rgba(255, 255, 255, 0.08);">
            <button type="button" class="btn btn-secondary btn-md" data-modal-close>Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Accordion Toggle Handler with Class-based Smooth Animation
    document.querySelectorAll('.prog-accordion-header').forEach(header => {
        header.addEventListener('click', function() {
            const card = this.closest('.program-card-accordion');
            if (card) {
                card.classList.toggle('is-open');
            }
        });
    });

    // Expand All Dropdowns
    const btnExpand = document.getElementById('btnExpandAll');
    if (btnExpand) {
        btnExpand.addEventListener('click', function() {
            document.querySelectorAll('.program-card-accordion').forEach(card => card.classList.add('is-open'));
        });
    }

    // Collapse All Dropdowns
    const btnCollapse = document.getElementById('btnCollapseAll');
    if (btnCollapse) {
        btnCollapse.addEventListener('click', function() {
            document.querySelectorAll('.program-card-accordion').forEach(card => card.classList.remove('is-open'));
        });
    }
});
</script>

<?php admin_close_page(); ?>
