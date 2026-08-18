<?php
declare(strict_types=1);

$skipLoginCheck = true; // standalone passcode-based auth
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/public-data.php';

if (empty($_SESSION['special_authenticated']) || $_SESSION['special_authenticated'] !== true) {
    if (!empty($_COOKIE['KAUZARIYYA_SPECIAL_AUTH'])) {
        $expected = hash_hmac('sha256', '7777', 'kauzariyya_special_secret');
        if (hash_equals($expected, $_COOKIE['KAUZARIYYA_SPECIAL_AUTH'])) {
            $_SESSION['special_authenticated'] = true;
        }
    }
}

if (empty($_SESSION['special_authenticated']) || $_SESSION['special_authenticated'] !== true) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = tv_active_event();
$eventId = (int)($activeEvent['id'] ?? 0);
$eventTitle = trim((string)($activeEvent['title'] ?? 'Special Dashboard'));
$settings = admin_get_settings($pdo);

// ---------------------------------------------------------
// QUERY 1: Standings Data (Overall & Section-wise)
// ---------------------------------------------------------
$stmtTeams = $pdo->prepare("SELECT id, team_name, team_color FROM musabaqa_teams WHERE event_id = ? ORDER BY id ASC");
$stmtTeams->execute([$eventId]);
$teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

$stmtEntries = $pdo->prepare("
    SELECT 
        pe.team_id,
        pe.team_score,
        ct.name AS class_type_name
    FROM musabaqa_program_entries pe
    JOIN musabaqa_programs p ON p.id = pe.program_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    WHERE pe.event_id = ?
      AND p.approval_status = 'approved'
");
$stmtEntries->execute([$eventId]);
$entriesForStandings = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

$standings = [];
foreach ($teams as $team) {
    $tId = (int)$team['id'];
    $standings[$tId] = [
        'name' => $team['team_name'],
        'color' => $team['team_color'] ?: '#64748b',
        'overall' => 0.0,
        'subjunior' => 0.0,
        'junior' => 0.0,
        'senior' => 0.0,
        'general' => 0.0
    ];
}

foreach ($entriesForStandings as $entry) {
    $tId = (int)$entry['team_id'];
    if (!isset($standings[$tId])) continue;
    
    $score = (float)$entry['team_score'];
    $tier = admin_class_type_tier_from_name($entry['class_type_name'] ?? '');
    if (!$tier) {
        $tier = 'general';
    }
    
    $standings[$tId]['overall'] += $score;
    $standings[$tId][$tier] += $score;
}

// Sort standings by overall descending
uasort($standings, function ($a, $b) {
    return $b['overall'] <=> $a['overall'];
});

// ---------------------------------------------------------
// QUERY 2: Programs and selected scorecard
// ---------------------------------------------------------
$stmtPrograms = $pdo->prepare("
    SELECT p.id, p.title, p.status, p.approval_status, ct.name AS category_name
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    WHERE p.event_id = ?
    ORDER BY p.title ASC
");
$programs = [];
if ($eventId > 0) {
    $stmtPrograms->execute([$eventId]);
    $programs = $stmtPrograms->fetchAll(PDO::FETCH_ASSOC);
}

$selectedProgramId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : ($programs[0]['id'] ?? 0);
$selectedProgram = null;
$scorecardEntries = [];

if ($selectedProgramId > 0) {
    $stmtSelProg = $pdo->prepare("SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1");
    $stmtSelProg->execute([$selectedProgramId, $eventId]);
    $selectedProgram = $stmtSelProg->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedProgram) {
        $stmtScorecard = $pdo->prepare("
            SELECT 
                pe.*,
                t.team_name,
                t.team_color,
                (
                    SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                    FROM musabaqa_entry_members em
                    JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                    WHERE em.entry_id = pe.id
                ) AS chest_number,
                (
                    SELECT GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ''), s.full_name) SEPARATOR ', ')
                    FROM musabaqa_entry_members em
                    JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                    JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                    WHERE em.entry_id = pe.id
                ) AS member_names
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.program_id = ?
            ORDER BY pe.final_rank ASC, pe.final_score DESC, pe.id ASC
        ");
        $stmtScorecard->execute([$selectedProgramId]);
        $scorecardEntries = $stmtScorecard->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ---------------------------------------------------------
// QUERY 3: Individual student championship standings
// ---------------------------------------------------------
$stmtIndStandings = $pdo->prepare("
    SELECT 
        tm.id AS member_id,
        s.id AS student_id,
        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS student_name,
        tm.chest_number,
        t.team_name,
        t.team_color,
        ct.name AS student_class_type_name,
        COUNT(pe.id) AS programs_count,
        SUM(pe.team_score) AS total_points,
        SUM(pe.final_score) AS total_marks
    FROM musabaqa_team_members tm
    JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
    JOIN musabaqa_teams t ON t.id = tm.team_id
    LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = c.class_type_id
    LEFT JOIN musabaqa_entry_members em ON em.team_member_id = tm.id
    LEFT JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
    LEFT JOIN musabaqa_programs p ON p.id = pe.program_id AND p.approval_status = 'approved'
    WHERE tm.event_id = ?
    GROUP BY tm.id, s.id, s.display_name, s.full_name, tm.chest_number, t.team_name, t.team_color, ct.name
    HAVING programs_count > 0
    ORDER BY total_points DESC, total_marks DESC, student_name ASC
");
$individualStandings = [];
if ($eventId > 0) {
    $stmtIndStandings->execute([$eventId]);
    $individualStandings = $stmtIndStandings->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------
// QUERY 4: Bulk Marks (Contestant Performance Log)
// ---------------------------------------------------------
$stmtBulk = $pdo->prepare("
    SELECT 
        s.id AS student_id,
        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS student_name,
        tm.chest_number,
        t.team_name,
        t.team_color,
        p.title AS program_title,
        p.approval_status AS program_status,
        ct.name AS category_name,
        pe.final_score,
        pe.final_rank,
        pe.grade,
        pe.team_score
    FROM musabaqa_entry_members em
    JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
    JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
    JOIN musabaqa_teams t ON t.id = tm.team_id
    JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
    JOIN musabaqa_programs p ON p.id = pe.program_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    WHERE tm.event_id = ?
      AND p.program_type != 'group'
      AND (p.only_team_marks IS NULL OR p.only_team_marks = 0)
    ORDER BY t.team_name ASC, s.full_name ASC, p.title ASC
");
$bulkMarks = [];
if ($eventId > 0) {
    $stmtBulk->execute([$eventId]);
    $bulkMarks = $stmtBulk->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Special Dashboard | <?= htmlspecialchars($eventTitle) ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            -webkit-touch-callout: none !important;
            -webkit-user-select: none !important;
            -khtml-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-tap-highlight-color: transparent !important;
        }

        input, textarea, select, [contenteditable="true"] {
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }

        :root {
            --brand-green: #6f9e7a;
            --brand-green-dark: #3a5e44;
            --brand-gold: #c9a86c;
            --bg-dark: #faf7f0;           /* warm cream base */
            --card-glass: rgba(255, 250, 240, 0.85);
            --border-glass: rgba(200, 180, 150, 0.25);
            --text-main: #2e2b27;
            --text-muted: #6b6258;
            --shadow-soft: 0 10px 30px rgba(140, 120, 100, 0.08);
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: #faf7f0;
            background-image: 
                radial-gradient(at 0% 0%, rgba(180, 200, 160, 0.20) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(210, 185, 140, 0.20) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(235, 225, 210, 0.60) 0px, transparent 100%);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            padding-bottom: 60px;
        }

        header {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            background: rgba(255, 250, 240, 0.7);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(180, 160, 140, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(150, 180, 140, 0.25), rgba(200, 175, 130, 0.20));
            border: 1px solid rgba(160, 180, 140, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5d7e5a;
            font-size: 16px;
        }

        .logo-text {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 1.15rem;
            color: #2e2b27;
            letter-spacing: -0.5px;
        }

        .logo-subtext {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #7b7266;
            margin-top: 1px;
            display: block;
        }

        .back-btn {
            background: rgba(160, 180, 145, 0.15);
            border: 1px solid rgba(150, 170, 135, 0.3);
            color: #4b6b47;
            padding: 8px 16px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .back-btn:active {
            transform: scale(0.95);
        }

        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px;
        }

        .nav-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 24px;
            scrollbar-width: none;
        }
        .nav-tabs::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            background: var(--card-glass);
            border: 1px solid var(--border-glass);
            color: var(--text-muted);
            padding: 10px 18px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 13.5px;
            white-space: nowrap;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(140, 120, 100, 0.04);
        }

        .tab-btn.active {
            background: #5d7e5a;
            border-color: #5d7e5a;
            color: #fff;
            box-shadow: 0 6px 16px rgba(93, 126, 90, 0.25);
        }

        .tab-content {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .glass-card {
            background: var(--card-glass);
            border: 1px solid var(--border-glass);
            border-radius: 24px;
            padding: 24px;
            box-shadow: var(--shadow-soft);
            margin-bottom: 24px;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid rgba(180, 160, 140, 0.15);
            background: rgba(255, 255, 255, 0.4);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: rgba(160, 180, 145, 0.12);
            color: #4b6b47;
            font-weight: 800;
            padding: 14px 16px;
            border-bottom: 1.5px solid rgba(180, 160, 140, 0.2);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.8px;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(180, 160, 140, 0.12);
            color: var(--text-main);
            font-weight: 600;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .team-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .badge-rank {
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
        }
        .badge-rank-1 { background: #dcfce7; color: #15803d; }
        .badge-rank-2 { background: #fef3c7; color: #b45309; }
        .badge-rank-3 { background: #eff6ff; color: #1d4ed8; }

        .badge-grade {
            background: rgba(160, 180, 145, 0.15);
            color: #4b6b47;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
        }

        .badge-status {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .badge-status.approved { background: rgba(34, 197, 94, 0.12); color: #16a34a; }
        .badge-status.submitted { background: rgba(245, 158, 11, 0.12); color: #d97706; }
        .badge-status.draft { background: rgba(100, 116, 139, 0.12); color: #475569; }

        .search-container {
            position: relative;
            width: 100%;
            max-width: 320px;
        }

        .search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            background: rgba(250, 245, 235, 0.8);
            border: 1.5px solid rgba(180, 165, 140, 0.3);
            border-radius: 14px;
            color: var(--text-main);
            font-size: 13.5px;
            font-weight: 700;
            outline: none;
            transition: all 0.25s ease;
        }

        .search-input:focus {
            border-color: #5d7e5a;
            box-shadow: 0 0 0 3px rgba(93, 126, 90, 0.15);
            background: #fff;
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
            pointer-events: none;
        }

        .select-styled {
            width: 100%;
            max-width: 320px;
            padding: 10px 14px;
            background: rgba(250, 245, 235, 0.8);
            border: 1.5px solid rgba(180, 165, 140, 0.3);
            border-radius: 14px;
            color: var(--text-main);
            font-size: 14px;
            font-weight: 700;
            outline: none;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .select-styled:focus {
            border-color: #5d7e5a;
            box-shadow: 0 0 0 3px rgba(93, 126, 90, 0.15);
        }

        .points-box {
            background: #5d7e5a;
            color: #fff;
            padding: 3px 8px;
            border-radius: 8px;
            font-family: monospace;
            font-weight: 800;
            font-size: 13px;
        }
        
        .score-pill {
            background: #f1f5f9;
            color: #334155;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
        }

        /* Standings Cards UI */
        .standings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .standing-card {
            background: var(--card-glass);
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            padding: 20px;
            box-shadow: var(--shadow-soft);
            display: flex;
            flex-direction: column;
            gap: 12px;
            border-top: 5px solid var(--team-color);
        }

        .standing-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .standing-card-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.15rem;
            margin: 0;
        }

        .standing-card-points {
            font-size: 1.5rem;
            font-weight: 900;
            color: #5d7e5a;
        }

        .standing-sub-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed rgba(180, 160, 140, 0.15);
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
        }
        .standing-sub-row:last-child {
            border-bottom: none;
        }

        .standing-sub-val {
            color: var(--text-main);
            font-weight: 800;
        }

        .bulk-total-count {
            font-size: 11px;
            font-weight: 800;
            color: #5d7e5a;
            background: rgba(93, 126, 90, 0.1);
            padding: 4px 10px;
            border-radius: 99px;
            display: inline-block;
        }

        /* Responsive Table Columns */
        .desktop-only {
            /* visible on desktop */
        }
        @media (max-width: 768px) {
            .desktop-only {
                display: none !important;
            }
            .clickable-row {
                cursor: pointer;
            }
            .clickable-row:active {
                background: rgba(93, 126, 90, 0.1) !important;
            }
        }

        /* Premium Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(46, 43, 39, 0.45);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-content {
            background: #faf7f0;
            border: 1px solid rgba(180, 160, 140, 0.3);
            border-radius: 24px;
            width: 92%;
            max-width: 450px;
            box-shadow: 0 20px 50px rgba(100, 80, 60, 0.2);
            overflow: hidden;
            transform: translateY(20px);
            transition: transform 0.25s ease;
        }
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        .modal-header {
            padding: 16px 20px;
            background: rgba(160, 180, 145, 0.15);
            border-bottom: 1px solid rgba(180, 160, 140, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
        }
        .modal-close {
            background: transparent;
            border: none;
            font-size: 24px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            line-height: 1;
            outline: none;
        }
        .modal-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .modal-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(180, 160, 140, 0.12);
        }
        .modal-detail-row:last-child {
            border-bottom: none;
        }
        .modal-detail-label {
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .modal-detail-value {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--text-main);
            text-align: right;
            max-width: 65%;
        }
        .mobile-tip {
            display: none;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: -8px;
            margin-bottom: 12px;
            align-items: center;
            gap: 6px;
        }
        @media (max-width: 768px) {
            .mobile-tip {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo-wrap">
            <div class="logo-badge"><i class="fa-solid fa-chart-pie"></i></div>
            <div>
                <span class="logo-text">SPECIAL REPORTS</span>
                <span class="logo-subtext">Musabaqa 2026</span>
            </div>
        </div>
        <a href="../index.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i> Standings
        </a>
    </header>

    <main>

        <!-- TAB 1: Championship Standings -->
        <div id="tabStandings" class="tab-content active">
            <div class="glass-card">
                <h3 class="card-title"><i class="fa-solid fa-crown" style="color: var(--brand-gold);"></i> Team Standings Summary</h3>
                <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 4px; margin-bottom: 20px;">
                    This overview lists overall scores and points won inside each class type tier (Sub-Junior, Junior, Senior) in the current marking system (1st=10pts, 2nd=7pts, 3rd=5pts).
                </p>
                
                <div class="standings-grid">
                    <?php 
                    $rankIndex = 1;
                    foreach ($standings as $teamId => $scoreData): 
                        $badgeColor = match ($rankIndex) {
                            1 => '#dcfce7',
                            2 => '#fef3c7',
                            3 => '#eff6ff',
                            default => '#f1f5f9'
                        };
                        $badgeText = match ($rankIndex) {
                            1 => '#15803d',
                            2 => '#b45309',
                            3 => '#1d4ed8',
                            default => '#475569'
                        };
                    ?>
                        <div class="standing-card" style="--team-color: <?= htmlspecialchars((string)$scoreData['color']) ?>;">
                            <div class="standing-card-header">
                                <h4 class="standing-card-title"><?= htmlspecialchars((string)$scoreData['name']) ?></h4>
                                <span class="badge-rank" style="background: <?= $badgeColor ?>; color: <?= $badgeText ?>;">
                                    Rank #<?= $rankIndex++ ?>
                                </span>
                            </div>
                            
                            <div style="display: flex; align-items: flex-baseline; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 12px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Total Score Points</span>
                                <span class="standing-card-points"><?= number_format((float)$scoreData['overall'], 1) ?></span>
                            </div>

                            <div class="standing-sub-row">
                                <span>Sub Junior Section Points</span>
                                <span class="standing-sub-val"><?= number_format((float)$scoreData['subjunior'], 1) ?></span>
                            </div>
                            <div class="standing-sub-row">
                                <span>Junior Section Points</span>
                                <span class="standing-sub-val"><?= number_format((float)$scoreData['junior'], 1) ?></span>
                            </div>
                            <div class="standing-sub-row">
                                <span>Senior Section Points</span>
                                <span class="standing-sub-val"><?= number_format((float)$scoreData['senior'], 1) ?></span>
                            </div>
                            <div class="standing-sub-row">
                                <span>General/Other Points</span>
                                <span class="standing-sub-val"><?= number_format((float)$scoreData['general'], 1) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- TAB 2: Program Scorecards -->
        <div id="tabPrograms" class="tab-content">
            <div class="glass-card">
                <div class="card-header-row">
                    <h3 class="card-title"><i class="fa-solid fa-list-check" style="color: var(--brand-green);"></i> Select Program</h3>
                    <select id="programSelector" class="select-styled" onchange="loadProgramScorecard(this.value)">
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?= (int)$prog['id'] ?>" <?= $selectedProgramId === (int)$prog['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$prog['title']) ?> (<?= htmlspecialchars((string)($prog['category_name'] ?? 'General')) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($selectedProgram): ?>
                    <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
                        <span class="bulk-total-count" style="font-size: 12px;">
                            Status: <span class="badge-status <?= htmlspecialchars((string)$selectedProgram['approval_status']) ?>"><?= htmlspecialchars((string)($selectedProgram['approval_status'] ?: 'draft')) ?></span>
                        </span>
                        <span class="bulk-total-count" style="font-size: 12px;">
                            Type: <strong><?= htmlspecialchars(strtoupper((string)($selectedProgram['program_type'] ?: 'individual'))) ?></strong>
                        </span>
                        <span class="bulk-total-count" style="font-size: 12px;">
                            Entries: <strong><?= count($scorecardEntries) ?></strong>
                        </span>
                    </div>

                    <div class="mobile-tip"><i class="fa-solid fa-circle-info"></i> Tap any row to view full participant details.</div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 70px;" class="desktop-only">Rank</th>
                                    <th style="width: 100px;" class="desktop-only">Chest #</th>
                                    <th>Entry Name / Members</th>
                                    <th>Team</th>
                                    <th style="text-align: center; width: 100px;">Marks</th>
                                    <th style="text-align: center; width: 90px;" class="desktop-only">Grade</th>
                                    <th style="text-align: right; width: 120px;" class="desktop-only">Points Won</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($scorecardEntries): ?>
                                    <?php foreach ($scorecardEntries as $idx => $entry): 
                                        $entryRank = $entry['final_rank'] !== null ? (int)$entry['final_rank'] : null;
                                        $rankClass = $entryRank ? "badge-rank-{$entryRank}" : "";
                                    ?>
                                        <tr class="clickable-row" onclick="showProgramEntryDetails(this)" 
                                            data-rank="<?= $entryRank ? '#'.$entryRank : '—' ?>" 
                                            data-chest="<?= htmlspecialchars((string)($entry['chest_number'] ?: '—')) ?>" 
                                            data-name="<?= htmlspecialchars((string)($entry['entry_name'] ?: 'Team Performance')) ?>" 
                                            data-members="<?= htmlspecialchars((string)$entry['member_names']) ?>" 
                                            data-team="<?= htmlspecialchars((string)$entry['team_name']) ?>" 
                                            data-team-color="<?= htmlspecialchars((string)$entry['team_color']) ?>"
                                            data-marks="<?= number_format((float)$entry['final_score'], 0) ?>" 
                                            data-grade="<?= $entry['grade'] ? 'Grade '.$entry['grade'] : '—' ?>" 
                                            data-points="+<?= number_format((float)$entry['team_score'], 1) ?> pts">
                                            <td class="desktop-only">
                                                <?php if ($entryRank): ?>
                                                    <span class="badge-rank <?= $rankClass ?>">#<?= $entryRank ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted)">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="desktop-only"><strong><?= htmlspecialchars((string)($entry['chest_number'] ?: '—')) ?></strong></td>
                                            <td>
                                                <div>
                                                    <strong style="color: #1e293b;"><?= htmlspecialchars((string)($entry['entry_name'] ?: 'Team Performance')) ?></strong>
                                                    <?php if (!empty($entry['member_names']) && $entry['member_names'] !== $entry['entry_name']): ?>
                                                        <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 3px; font-weight: 500;">
                                                            <?= htmlspecialchars((string)$entry['member_names']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="team-badge" style="background: <?= htmlspecialchars((string)$entry['team_color']) ?>18; color: <?= htmlspecialchars((string)$entry['team_color']) ?>; border-color: <?= htmlspecialchars((string)$entry['team_color']) ?>33;">
                                                    <?= htmlspecialchars((string)$entry['team_name']) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="score-pill"><?= number_format((float)$entry['final_score'], 0) ?></span>
                                            </td>
                                            <td style="text-align: center;" class="desktop-only">
                                                <?php if ($entry['grade']): ?>
                                                    <span class="badge-grade">Grade <?= htmlspecialchars((string)$entry['grade']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted)">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;" class="desktop-only">
                                                <span class="points-box">+<?= number_format((float)$entry['team_score'], 1) ?> pts</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">No participants scored or registered for this program.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 24px;">No programs found.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 3: Individual Rankings -->
        <div id="tabIndividuals" class="tab-content">
            <div class="glass-card">
                <div class="card-header-row">
                    <h3 class="card-title"><i class="fa-solid fa-user-graduate" style="color: var(--brand-green);"></i> Individual Student Championships</h3>
                    <div class="search-container">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="indSearch" class="search-input" placeholder="Search student name or chest #..." oninput="filterIndividualTable()">
                    </div>
                </div>

                <div class="mobile-tip"><i class="fa-solid fa-circle-info"></i> Tap any row to view full participant details.</div>
                <div class="table-responsive">
                    <table id="indTable">
                        <thead>
                            <tr>
                                <th style="width: 60px;" class="desktop-only">Rank</th>
                                <th style="width: 100px;" class="desktop-only">Chest #</th>
                                <th>Student Name</th>
                                <th>Team</th>
                                <th class="desktop-only">Class / Section</th>
                                <th style="text-align: center; width: 100px;" class="desktop-only">Events Count</th>
                                <th style="text-align: center; width: 110px;">Total Marks</th>
                                <th style="text-align: right; width: 120px;" class="desktop-only">Points Won</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($individualStandings): ?>
                                <?php 
                                $rIdx = 1;
                                foreach ($individualStandings as $ind): 
                                    $rankBadge = $rIdx <= 3 ? "badge-rank-{$rIdx}" : "";
                                ?>
                                    <tr class="ind-row clickable-row" onclick="showIndividualDetails(this)" 
                                        data-rank="#<?= $rIdx ?>" 
                                        data-chest="<?= htmlspecialchars((string)$ind['chest_number']) ?>" 
                                        data-name="<?= htmlspecialchars((string)$ind['student_name']) ?>" 
                                        data-team="<?= htmlspecialchars((string)$ind['team_name']) ?>" 
                                        data-team-color="<?= htmlspecialchars((string)$ind['team_color']) ?>"
                                        data-class="<?= htmlspecialchars((string)($ind['student_class_type_name'] ?? 'General')) ?>" 
                                        data-events="<?= (int)$ind['programs_count'] ?>" 
                                        data-marks="<?= number_format((float)$ind['total_marks'], 0) ?>" 
                                        data-points="+<?= number_format((float)$ind['total_points'], 1) ?> pts">
                                        <td class="desktop-only">
                                            <span class="badge-rank <?= $rankBadge ?>">#<?= $rIdx++ ?></span>
                                        </td>
                                        <td class="ind-chest desktop-only"><strong><?= htmlspecialchars((string)$ind['chest_number']) ?></strong></td>
                                        <td class="ind-name"><strong style="color: #1e293b;"><?= htmlspecialchars((string)$ind['student_name']) ?></strong></td>
                                        <td>
                                            <span class="team-badge" style="background: <?= htmlspecialchars((string)$ind['team_color']) ?>18; color: <?= htmlspecialchars((string)$ind['team_color']) ?>; border-color: <?= htmlspecialchars((string)$ind['team_color']) ?>33;">
                                                <?= htmlspecialchars((string)$ind['team_name']) ?>
                                            </span>
                                        </td>
                                        <td class="desktop-only"><?= htmlspecialchars((string)($ind['student_class_type_name'] ?? 'General')) ?></td>
                                        <td style="text-align: center;" class="desktop-only"><?= (int)$ind['programs_count'] ?></td>
                                        <td style="text-align: center;">
                                            <span class="score-pill"><?= number_format((float)$ind['total_marks'], 0) ?></span>
                                        </td>
                                        <td style="text-align: right;" class="desktop-only">
                                            <span class="points-box">+<?= number_format((float)$ind['total_points'], 1) ?> pts</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 24px;">No individual contestant records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 4: Bulk Marks Log -->
        <div id="tabBulk" class="tab-content">
            <div class="glass-card">
                <div class="card-header-row">
                    <div>
                        <h3 class="card-title"><i class="fa-solid fa-database" style="color: var(--brand-green);"></i> Bulk Marks Log</h3>
                        <span class="bulk-total-count" style="margin-top: 6px;">
                            Total Records: <strong><?= count($bulkMarks) ?></strong> (Excluding group programs)
                        </span>
                    </div>
                    
                    <div class="search-container">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="bulkSearch" class="search-input" placeholder="Search name, program, chest #..." oninput="filterBulkTable()">
                    </div>
                </div>

                <div class="mobile-tip"><i class="fa-solid fa-circle-info"></i> Tap any row to view full participant details.</div>
                <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                    <table id="bulkTable">
                        <thead style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th style="width: 100px;" class="desktop-only">Chest #</th>
                                <th>Contestant Name</th>
                                <th>Team</th>
                                <th class="desktop-only">Program Title</th>
                                <th class="desktop-only">Category / Group</th>
                                <th style="text-align: center; width: 80px;">Marks</th>
                                <th style="text-align: center; width: 60px;" class="desktop-only">Rank</th>
                                <th style="text-align: center; width: 60px;" class="desktop-only">Grade</th>
                                <th style="text-align: right; width: 100px;" class="desktop-only">Points Won</th>
                                <th style="text-align: center; width: 100px;" class="desktop-only">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bulkMarks): ?>
                                <?php foreach ($bulkMarks as $row): 
                                    $pRank = $row['final_rank'] !== null ? (int)$row['final_rank'] : null;
                                    $rankBadge = $pRank ? "badge-rank-{$pRank}" : "";
                                ?>
                                    <tr class="bulk-row clickable-row" onclick="showBulkDetails(this)" 
                                        data-chest="<?= htmlspecialchars((string)$row['chest_number']) ?>" 
                                        data-name="<?= htmlspecialchars((string)$row['student_name']) ?>" 
                                        data-team="<?= htmlspecialchars((string)$row['team_name']) ?>" 
                                        data-team-color="<?= htmlspecialchars((string)$row['team_color']) ?>"
                                        data-program="<?= htmlspecialchars((string)$row['program_title']) ?>" 
                                        data-category="<?= htmlspecialchars((string)($row['category_name'] ?? 'General')) ?>" 
                                        data-marks="<?= number_format((float)$row['final_score'], 0) ?>" 
                                        data-rank="<?= $pRank ? '#'.$pRank : '—' ?>" 
                                        data-grade="<?= $row['grade'] ?: '—' ?>" 
                                        data-points="+<?= number_format((float)$row['team_score'], 1) ?> pts" 
                                        data-status="<?= htmlspecialchars((string)($row['program_status'] ?: 'draft')) ?>">
                                        <td class="bulk-chest desktop-only"><strong><?= htmlspecialchars((string)$row['chest_number']) ?></strong></td>
                                        <td class="bulk-name"><strong style="color: #1e293b;"><?= htmlspecialchars((string)$row['student_name']) ?></strong></td>
                                        <td class="bulk-team">
                                            <span class="team-badge" style="background: <?= htmlspecialchars((string)$row['team_color']) ?>18; color: <?= htmlspecialchars((string)$row['team_color']) ?>; border-color: <?= htmlspecialchars((string)$row['team_color']) ?>33;">
                                                <?= htmlspecialchars((string)$row['team_name']) ?>
                                            </span>
                                        </td>
                                        <td class="bulk-program desktop-only"><?= htmlspecialchars((string)$row['program_title']) ?></td>
                                        <td class="desktop-only"><?= htmlspecialchars((string)($row['category_name'] ?? 'General')) ?></td>
                                        <td style="text-align: center;">
                                            <span class="score-pill"><?= number_format((float)$row['final_score'], 0) ?></span>
                                        </td>
                                        <td style="text-align: center;" class="desktop-only">
                                            <?php if ($pRank): ?>
                                                <span class="badge-rank <?= $rankBadge ?>">#<?= $pRank ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted)">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;" class="desktop-only">
                                            <?php if ($row['grade']): ?>
                                                <span class="badge-grade"><?= htmlspecialchars((string)$row['grade']) ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted)">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;" class="desktop-only">
                                            <span class="points-box">+<?= number_format((float)$row['team_score'], 1) ?> pts</span>
                                        </td>
                                        <td style="text-align: center;" class="desktop-only">
                                            <span class="badge-status <?= htmlspecialchars((string)$row['program_status']) ?>">
                                                <?= htmlspecialchars((string)($row['program_status'] ?: 'draft')) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 24px;">No contestant mark records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Details View</h4>
                <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content injected dynamically -->
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        const modalEl = document.getElementById('detailsModal');
        const modalBody = document.getElementById('modalBody');

        function openModal() {
            modalEl.style.display = 'flex';
            // Force redraw for transition
            modalEl.offsetHeight; 
            modalEl.classList.add('active');
        }

        function closeDetailsModal() {
            modalEl.classList.remove('active');
            setTimeout(() => {
                modalEl.style.display = 'none';
            }, 250);
        }

        // Close on clicking overlay
        modalEl.addEventListener('click', (e) => {
            if (e.target === modalEl) {
                closeDetailsModal();
            }
        });

        function showProgramEntryDetails(row) {
            const d = row.dataset;
            let memberRow = '';
            if (d.members && d.members !== d.name) {
                memberRow = `
                    <div class="modal-detail-row">
                        <span class="modal-detail-label">Members</span>
                        <span class="modal-detail-value">${d.members}</span>
                    </div>
                `;
            }
            modalBody.innerHTML = `
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Rank</span>
                    <span class="modal-detail-value"><span class="badge-rank">${d.rank}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Chest Number</span>
                    <span class="modal-detail-value"><strong>${d.chest}</strong></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Name / Entry</span>
                    <span class="modal-detail-value">${d.name}</span>
                </div>
                ${memberRow}
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Team</span>
                    <span class="modal-detail-value">
                        <span class="team-badge" style="background: ${d.teamColor}18; color: ${d.teamColor}; border-color: ${d.teamColor}33;">
                            ${d.team}
                        </span>
                    </span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Marks</span>
                    <span class="modal-detail-value"><span class="score-pill">${d.marks}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Grade</span>
                    <span class="modal-detail-value"><span class="badge-grade">${d.grade}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Points Won</span>
                    <span class="modal-detail-value"><span class="points-box">${d.points}</span></span>
                </div>
            `;
            openModal();
        }

        function showIndividualDetails(row) {
            const d = row.dataset;
            modalBody.innerHTML = `
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Rank</span>
                    <span class="modal-detail-value"><span class="badge-rank">${d.rank}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Chest Number</span>
                    <span class="modal-detail-value"><strong>${d.chest}</strong></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Student Name</span>
                    <span class="modal-detail-value">${d.name}</span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Team</span>
                    <span class="modal-detail-value">
                        <span class="team-badge" style="background: ${d.teamColor}18; color: ${d.teamColor}; border-color: ${d.teamColor}33;">
                            ${d.team}
                        </span>
                    </span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Class / Section</span>
                    <span class="modal-detail-value">${d.class}</span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Events Participated</span>
                    <span class="modal-detail-value"><strong>${d.events}</strong></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Total Marks</span>
                    <span class="modal-detail-value"><span class="score-pill">${d.marks}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Points Won</span>
                    <span class="modal-detail-value"><span class="points-box">${d.points}</span></span>
                </div>
            `;
            openModal();
        }

        function showBulkDetails(row) {
            const d = row.dataset;
            modalBody.innerHTML = `
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Chest Number</span>
                    <span class="modal-detail-value"><strong>${d.chest}</strong></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Contestant Name</span>
                    <span class="modal-detail-value">${d.name}</span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Team</span>
                    <span class="modal-detail-value">
                        <span class="team-badge" style="background: ${d.teamColor}18; color: ${d.teamColor}; border-color: ${d.teamColor}33;">
                            ${d.team}
                        </span>
                    </span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Program Title</span>
                    <span class="modal-detail-value">${d.program}</span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Category / Group</span>
                    <span class="modal-detail-value">${d.category}</span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Marks</span>
                    <span class="modal-detail-value"><span class="score-pill">${d.marks}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Rank</span>
                    <span class="modal-detail-value"><span class="badge-rank">${d.rank}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Grade</span>
                    <span class="modal-detail-value"><span class="badge-grade">${d.grade}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Points Won</span>
                    <span class="modal-detail-value"><span class="points-box">${d.points}</span></span>
                </div>
                <div class="modal-detail-row">
                    <span class="modal-detail-label">Status</span>
                    <span class="modal-detail-value"><span class="badge-status ${d.status}">${d.status}</span></span>
                </div>
            `;
            openModal();
        }

        // Function to reload page with selected program scorecard
        function loadProgramScorecard(programId) {
            window.location.href = '?program_id=' + programId + '#tabPrograms';
        }

        // Filter individual student championships table
        function filterIndividualTable() {
            const query = document.getElementById('indSearch').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#indTable .ind-row');

            rows.forEach(row => {
                const name = row.querySelector('.ind-name').textContent.toLowerCase();
                const chest = row.querySelector('.ind-chest').textContent.toLowerCase();

                if (name.includes(query) || chest.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Filter bulk marks table
        function filterBulkTable() {
            const query = document.getElementById('bulkSearch').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#bulkTable .bulk-row');

            rows.forEach(row => {
                const name = row.querySelector('.bulk-name').textContent.toLowerCase();
                const chest = row.querySelector('.bulk-chest').textContent.toLowerCase();
                const team = row.querySelector('.bulk-team').textContent.toLowerCase();
                const program = row.querySelector('.bulk-program').textContent.toLowerCase();

                if (name.includes(query) || chest.includes(query) || team.includes(query) || program.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
