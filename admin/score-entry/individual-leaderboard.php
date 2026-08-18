<?php
$pageTitle = 'Individual Standings & Leaderboard';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Filter parameters
$selectedSection = trim((string)($_GET['section'] ?? 'all'));
$selectedType = trim((string)($_GET['type'] ?? 'all'));
$selectedTeamId = (int)($_GET['team_id'] ?? 0);
$selectedPointsFilter = trim((string)($_GET['points_filter'] ?? 'has_points'));
$searchQuery = trim((string)($_GET['search'] ?? ''));

// Fetch class type IDs for the selected section (Junior/Senior/Sub Junior)
$sectionIds = [];
if (in_array($selectedSection, ['senior', 'junior', 'subjunior'], true)) {
    $sectionIds = admin_class_type_ids_for_tier($dashboardPdo, $selectedSection);
}

// Build SQL parts
$subqueryTypeSql = "";
if ($selectedType === 'individual') {
    $subqueryTypeSql = " AND p.program_type = 'individual'";
} elseif ($selectedType === 'group') {
    $subqueryTypeSql = " AND p.program_type = 'group'";
}

$sectionSql = "";
if (!empty($sectionIds)) {
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    $sectionSql = " AND ct.id IN ($placeholders)";
}

$teamSql = "";
if ($selectedTeamId > 0) {
    $teamSql = " AND tm.team_id = ?";
}

$pointsFilterSql = "";
if ($selectedPointsFilter === 'has_points') {
    $pointsFilterSql = " AND COALESCE(points_data.total_points, 0) > 0";
}

$searchSql = "";
if ($searchQuery !== '') {
    $searchSql = " AND (COALESCE(NULLIF(s.display_name, ''), s.full_name) LIKE ? OR tm.chest_number LIKE ?)";
}

// Prepare parameters
$queryParams = [];
// 1. Subquery event_id
$queryParams[] = $activeEventId;
// 2. Outer query event_id
$queryParams[] = $activeEventId;

// 3. Section IDs
if (!empty($sectionIds)) {
    foreach ($sectionIds as $id) {
        $queryParams[] = $id;
    }
}
// 4. Team ID
if ($selectedTeamId > 0) {
    $queryParams[] = $selectedTeamId;
}
// 5. Search query
if ($searchQuery !== '') {
    $like = '%' . $searchQuery . '%';
    $queryParams[] = $like;
    $queryParams[] = $like;
}

$sql = "
    SELECT 
        tm.id AS team_member_id,
        tm.chest_number,
        tm.student_id,
        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS student_name,
        t.team_name,
        t.team_color,
        c.name AS class_name,
        ct.name AS class_type_name,
        COALESCE(points_data.total_points, 0) AS total_points,
        COALESCE(points_data.programs_count, 0) AS programs_count
    FROM musabaqa_team_members tm
    JOIN musabaqa_teams t ON t.id = tm.team_id
    JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
    LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = c.class_type_id
    LEFT JOIN (
        SELECT 
            em.team_member_id,
            SUM(pe.team_score) AS total_points,
            COUNT(pe.id) AS programs_count
        FROM musabaqa_entry_members em
        JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.event_id = ?
          AND p.approval_status = 'approved'
          {$subqueryTypeSql}
        GROUP BY em.team_member_id
    ) points_data ON points_data.team_member_id = tm.id
    WHERE tm.event_id = ?
      AND tm.status = 'active'
      {$sectionSql}
      {$teamSql}
      {$pointsFilterSql}
      {$searchSql}
    ORDER BY total_points DESC, student_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($queryParams);
$leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Ranks
$rank = 1;
$prevPoints = null;
foreach ($leaderboard as $idx => &$student) {
    $pts = (float)$student['total_points'];
    if ($prevPoints !== null && $pts < $prevPoints) {
        $rank = $idx + 1;
    }
    $student['rank'] = $rank;
    $prevPoints = $pts;
}
unset($student);

// CSV Export Handling
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="individual_leaderboard_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Rank', 'Chest #', 'Student Name', 'Team Name', 'Class Name', 'Section', 'Programs Count', 'Total Points Earned']);
    
    foreach ($leaderboard as $row) {
        fputcsv($output, [
            $row['rank'],
            $row['chest_number'] !== null && $row['chest_number'] !== '' ? '#' . str_pad((string)$row['chest_number'], 3, '0', STR_PAD_LEFT) : '—',
            $row['student_name'],
            $row['team_name'],
            $row['class_name'] ?: '—',
            $row['class_type_name'] ?: '—',
            $row['programs_count'],
            number_format((float)$row['total_points'], 1),
        ]);
    }
    fclose($output);
    exit;
}

// Fetch Teams for filtering dropdown
$teamsStmt = $pdo->prepare("
    SELECT id, team_name 
    FROM musabaqa_teams 
    WHERE event_id = ? 
    ORDER BY team_name ASC
");
$teamsStmt->execute([$activeEventId]);
$filterTeams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Approved Programs Count
$approvedStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ? AND approval_status = 'approved'");
$approvedStmt->execute([$activeEventId]);
$totalApprovedPrograms = (int)$approvedStmt->fetchColumn();

// Summary Metrics
$topLeader = $leaderboard[0] ?? null;
$totalParticipants = count($leaderboard);
$totalPointsEarned = array_sum(array_column($leaderboard, 'total_points'));

// Pagination
if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['leaderboard_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['leaderboard_limit']) ? $_SESSION['leaderboard_limit'] : 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedLeaderboard = array_slice($leaderboard, $offset, $perPage);

$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

// Helper to render high-contrast premium rank badges
if (!function_exists('render_leaderboard_rank_badge')) {
    function render_leaderboard_rank_badge(?int $rank): string {
        if ($rank === 1) {
            return '<span class="badge" style="background: rgba(251, 191, 36, 0.18); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.35); font-weight: 800; padding: 4px 10px;"><i class="fa-solid fa-trophy mr-1"></i> 1st Rank</span>';
        }
        if ($rank === 2) {
            return '<span class="badge" style="background: rgba(148, 163, 184, 0.18); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); font-weight: 800; padding: 4px 10px;"><i class="fa-solid fa-trophy mr-1"></i> 2nd Rank</span>';
        }
        if ($rank === 3) {
            return '<span class="badge" style="background: rgba(180, 83, 9, 0.18); color: #fb923c; border: 1px solid rgba(180, 83, 9, 0.3); font-weight: 800; padding: 4px 10px;"><i class="fa-solid fa-trophy mr-1"></i> 3rd Rank</span>';
        }
        return '<span class="badge badge-neutral" style="padding: 4px 10px; font-weight: 600;">Rank #' . ($rank ?: '—') . '</span>';
    }
}
?>

<style>
/* Leaderboard page custom style tokens */
.leaderboard-metric-card {
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

.leaderboard-metric-card:hover {
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

.leaderboard-table-row {
    transition: background 0.15s ease;
}

.leaderboard-table-row:hover {
    background: rgba(99, 102, 241, 0.07) !important;
}

.team-badge-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.team-badge-pill {
    background: rgba(255, 255, 255, 0.03);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.07);
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.team-badge-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    display: inline-block;
}
</style>

<div class="main-content">
    
    <!-- Topbar Header -->
    <div class="topbar flex-between items-center mb-6">
        <div>
            <div class="page-title flex items-center gap-2">
                <i class="fa-solid fa-ranking-star mr-1" style="color: #fbbf24; font-size: 22px;"></i> 
                Individual Student Leaderboard
            </div>
            <div class="page-subtitle" style="margin-top: 2px;">Overall points earned by individual participants for their respective teams</div>
        </div>
        <div class="flex gap-2">
            <?php 
                $exportQuery = array_merge($_GET, ['export' => 'csv']); 
                $exportUrl = '?' . http_build_query($exportQuery);
            ?>
            <a class="btn btn-success btn-md" href="<?= e($exportUrl) ?>">
                <i class="fa-solid fa-file-csv mr-1"></i> Export Leaderboard CSV
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- Summary Metrics Cards -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
        
        <!-- Card 1: Top Individual Leader -->
        <div class="leaderboard-metric-card" style="border-color: rgba(251, 191, 36, 0.25); background: radial-gradient(circle at top right, rgba(251, 191, 36, 0.12), rgba(251, 191, 36, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #fbbf24;">Current Leader</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(251, 191, 36, 0.15); color: #fbbf24; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-crown"></i>
                </span>
            </div>
            <div>
                <?php if ($topLeader): ?>
                    <div style="font-size: 20px; font-weight: 800; color: #fff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; line-height: 1.2;">
                        <?= e($topLeader['student_name']) ?>
                    </div>
                    <div style="font-size: 12px; color: var(--muted); margin-top: 3px;" class="team-badge-container">
                        <span class="team-badge-dot" style="background-color: <?= e($topLeader['team_color'] ?: '#6366f1') ?>; box-shadow: 0 0 6px <?= e($topLeader['team_color'] ?: '#6366f1') ?>;"></span>
                        <span><?= e($topLeader['team_name']) ?></span>
                    </div>
                <?php else: ?>
                    <span style="font-size: 20px; font-weight: 700; color: var(--muted);">No Data</span>
                <?php endif; ?>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(251, 191, 36, 0.2); padding-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                <span>🥇 Rank #1 Student</span>
                <strong style="color: #fbbf24; font-size: 13.5px;"><?= $topLeader ? number_format((float)$topLeader['total_points'], 1) : 0 ?> pts</strong>
            </div>
        </div>

        <!-- Card 2: Total Listed Students -->
        <div class="leaderboard-metric-card" style="border-color: rgba(99, 102, 241, 0.25); background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.12), rgba(99, 102, 241, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #818cf8;">Listed Students</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(99, 102, 241, 0.15); color: #818cf8; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-user-graduate"></i>
                </span>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: 800; color: #818cf8; line-height: 1.1;">
                    <?= $totalParticipants ?>
                </div>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(99, 102, 241, 0.2); padding-top: 8px;">
                <i class="fa-solid fa-users mr-1" style="color: #818cf8;"></i> Active in filtered view
            </div>
        </div>

        <!-- Card 3: Total Individual Points -->
        <div class="leaderboard-metric-card" style="border-color: rgba(16, 185, 129, 0.25); background: radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #34d399;">Points Distributed</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(16, 185, 129, 0.15); color: #34d399; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-award"></i>
                </span>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: 800; color: #10b981; line-height: 1.1;">
                    <?= number_format((float)$totalPointsEarned, 0) ?>
                    <span style="font-size: 14px; font-weight: 600; color: rgba(52, 211, 153, 0.85); margin-left: 2px;">Pts</span>
                </div>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(16, 185, 129, 0.2); padding-top: 8px;">
                <i class="fa-solid fa-calculator mr-1" style="color: #34d399;"></i> Sum of all individual points
            </div>
        </div>

        <!-- Card 4: Finalized Programs -->
        <div class="leaderboard-metric-card" style="border-color: rgba(245, 158, 11, 0.25); background: radial-gradient(circle at top right, rgba(245, 158, 11, 0.12), rgba(245, 158, 11, 0.02));">
            <div class="flex-between items-center">
                <span style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #fbbf24;">Finalized Programs</span>
                <span style="width: 32px; height: 32px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); color: #fbbf24; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: 800; color: #f59e0b; line-height: 1.1;">
                    <?= $totalApprovedPrograms ?>
                </div>
            </div>
            <div style="font-size: 11px; color: var(--muted); border-top: 1px dashed rgba(245, 158, 11, 0.2); padding-top: 8px;">
                <i class="fa-solid fa-lock mr-1" style="color: #fbbf24;"></i> Approved event standings
            </div>
        </div>

    </div>

    <!-- Filter Control Bar -->
    <div class="filter-panel">
        <form method="GET" class="flex gap-3 flex-wrap items-center">
            
            <div class="input-group" style="min-width: 140px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Section (Tier)</label>
                <select name="section" class="form-input" onchange="this.form.submit()">
                    <option value="all">-- All Sections --</option>
                    <option value="senior" <?= $selectedSection === 'senior' ? 'selected' : '' ?>>Senior</option>
                    <option value="junior" <?= $selectedSection === 'junior' ? 'selected' : '' ?>>Junior</option>
                    <option value="subjunior" <?= $selectedSection === 'subjunior' ? 'selected' : '' ?>>Sub Junior</option>
                </select>
            </div>

            <div class="input-group" style="min-width: 150px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Program Type</label>
                <select name="type" class="form-input" onchange="this.form.submit()">
                    <option value="all">-- All Programs --</option>
                    <option value="individual" <?= $selectedType === 'individual' ? 'selected' : '' ?>>Individual Only</option>
                    <option value="group" <?= $selectedType === 'group' ? 'selected' : '' ?>>Group Only</option>
                </select>
            </div>

            <div class="input-group" style="min-width: 180px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Filter by Team</label>
                <select name="team_id" class="form-input" onchange="this.form.submit()">
                    <option value="0">-- All Teams --</option>
                    <?php foreach ($filterTeams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $selectedTeamId === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= e($t['team_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="min-width: 170px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Point Status</label>
                <select name="points_filter" class="form-input" onchange="this.form.submit()">
                    <option value="has_points" <?= $selectedPointsFilter === 'has_points' ? 'selected' : '' ?>>With Points Only</option>
                    <option value="all" <?= $selectedPointsFilter === 'all' ? 'selected' : '' ?>>All Students</option>
                </select>
            </div>

            <div class="input-group" style="flex: 1; min-width: 180px;">
                <label style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Search Student</label>
                <input type="text" name="search" class="form-input" value="<?= e($searchQuery) ?>" placeholder="Search name or chest number...">
            </div>

            <div style="margin-top: 18px;" class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-md"><i class="fa-solid fa-magnifying-glass mr-1"></i> Filter</button>
                <?php if ($selectedSection !== 'all' || $selectedType !== 'all' || $selectedTeamId > 0 || $selectedPointsFilter !== 'has_points' || $searchQuery !== ''): ?>
                    <a href="?" class="btn btn-secondary btn-md" title="Reset Filters"><i class="fa-solid fa-rotate-left mr-1"></i> Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Leaderboard Table Section -->
    <div class="panel mb-6" style="padding: 24px; border-radius: 16px; background: rgba(15, 23, 42, 0.5); border: 1px solid rgba(255,255,255,0.08);">
        <div class="flex-between items-center mb-5 pb-4" style="border-bottom: 1px solid rgba(255,255,255,0.08);">
            <div>
                <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-ranking-star" style="color: #fbbf24;"></i>
                    Rank Standings List
                </h3>
                <span style="font-size: 12.5px; color: var(--muted); display: block; margin-top: 3px;">
                    Displaying <strong><?= count($paginatedLeaderboard) ?></strong> students (Page <?= $page ?> of <?= max(1, (int)ceil($totalParticipants / $perPage)) ?>).
                </span>
            </div>
        </div>

        <?php if (empty($leaderboard)): ?>
            <div class="text-center" style="padding: 50px 10px; color: var(--muted);">
                <i class="fa-solid fa-folder-open mb-3" style="font-size: 36px; opacity: 0.4;"></i><br>
                <strong style="font-size: 15px; color: #fff;">No Student Standings Found</strong><br>
                <span style="font-size: 12.5px;">No students matched your selected filter criteria or have points recorded yet.</span>
            </div>
        <?php else: ?>
            <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0; background: transparent;">
                <table class="table" style="margin: 0; font-size: 13px;">
                    <thead>
                        <tr style="background: rgba(255,255,255,0.03);">
                            <th style="width: 140px; padding: 12px 16px;">Rank</th>
                            <th style="width: 100px; padding: 12px 16px;">Chest #</th>
                            <th style="padding: 12px 16px;">Student / Participant Name</th>
                            <th style="padding: 12px 16px; width: 220px;">Team</th>
                            <th style="padding: 12px 16px; width: 140px;">Class</th>
                            <th style="padding: 12px 16px; width: 140px;">Section</th>
                            <th style="text-align: center; width: 120px; padding: 12px 16px;">Programs</th>
                            <th style="text-align: right; width: 150px; padding: 12px 16px; padding-right: 24px;">Total Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginatedLeaderboard as $row): ?>
                            <tr class="leaderboard-table-row">
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <?= render_leaderboard_rank_badge($row['rank']) ?>
                                </td>
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <span class="badge badge-neutral" style="font-family: monospace; font-size: 12px; font-weight: 700; padding: 3px 8px;">
                                        <?= $row['chest_number'] !== null && $row['chest_number'] !== '' ? '#' . str_pad((string)$row['chest_number'], 3, '0', STR_PAD_LEFT) : '—' ?>
                                    </span>
                                </td>
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <strong style="color: #fff; font-size: 14px; font-weight: 700;"><?= e($row['student_name']) ?></strong>
                                </td>
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <div class="team-badge-container">
                                        <span class="team-badge-pill">
                                            <span class="team-badge-dot" style="background-color: <?= e($row['team_color'] ?: '#6366f1') ?>; box-shadow: 0 0 6px <?= e($row['team_color'] ?: '#6366f1') ?>;"></span>
                                            <?= e($row['team_name']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td style="padding: 14px 16px; color: var(--muted); vertical-align: middle;">
                                    <?= e($row['class_name'] ?: '—') ?>
                                </td>
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <?php if ($row['class_type_name']): ?>
                                        <?php 
                                            $tier = admin_class_type_tier_from_name($row['class_type_name']); 
                                            $badgeClass = admin_class_type_badge_class($tier);
                                        ?>
                                        <span class="badge <?= $badgeClass ?>" style="font-size: 11px; font-weight: 600; padding: 3px 8px;">
                                            <?= e(admin_class_type_tier_label($tier)) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #fff; padding: 14px 16px; vertical-align: middle;">
                                    <?= (int)$row['programs_count'] ?>
                                </td>
                                <td style="text-align: right; padding: 14px 16px; padding-right: 24px; vertical-align: middle;">
                                    <?php if ($row['total_points'] > 0): ?>
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.18); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); font-size: 14px; font-weight: 800; padding: 4px 14px; min-width: 75px; text-align: center; display: inline-block;">
                                            <?= number_format((float)$row['total_points'], 1) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral" style="font-size: 13px; font-weight: 600; padding: 4px 14px; min-width: 75px; text-align: center; display: inline-block; opacity: 0.6;">
                                            0.0
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination controls -->
            <div id="pagination-container" style="margin-top: 20px;">
                <?= admin_render_pagination_html($page, $perPage, $totalParticipants) ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php admin_close_page(); ?>
