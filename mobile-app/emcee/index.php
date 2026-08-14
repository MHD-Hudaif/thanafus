<?php
declare(strict_types=1);

$pageTitle = 'Emcee Master Stage Deck';
$skipLoginCheck = true; // Allow standalone Emcee stage control without requiring admin user session

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';

// Session authentication check for Emcee
if (empty($_SESSION['emcee_authenticated']) || $_SESSION['emcee_authenticated'] !== true) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

$_SESSION['active_workspace'] = 'emcee';

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)($activeEvent['id'] ?? 0);

// AJAX / Live Action Handling (Zero Refresh)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'broadcast_stage') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $entryId = (int)($_POST['entry_id'] ?? 0);
        if ($programId > 0) {
            admin_set_live_stage_control($pdo, $programId, $entryId);
        }
        if (admin_is_ajax() || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'live_program_id' => $programId,
                'live_entry_id' => $entryId,
                'message' => $entryId > 0 ? 'Stage contestant set live!' : 'Program Intro set live on stage!'
            ]);
            exit;
        }
        admin_flash('success', "Live Stage updated successfully!");
        admin_redirect('/mobile-app/emcee/index.php');
    }

    if ($action === 'save_recorded_time') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $durationSeconds = (int)($_POST['duration_seconds'] ?? 0);
        $formattedTime = trim((string)($_POST['formatted_time'] ?? '00:00'));

        if ($programId > 0) {
            admin_save_recorded_time($pdo, $programId, $entryId, $durationSeconds, $formattedTime);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'program_id' => $programId,
            'entry_id' => $entryId,
            'duration_seconds' => $durationSeconds,
            'formatted_time' => $formattedTime,
            'message' => 'Participant duration saved successfully!'
        ]);
        exit;
    }
}

// Live Status Query API (for polling)
if (isset($_GET['ajax_status'])) {
    header('Content-Type: application/json');
    $status = admin_get_live_stage_control($pdo);
    $recordedTimes = admin_get_recorded_times($pdo);
    echo json_encode(['success' => true, 'live_control' => $status, 'recorded_times' => $recordedTimes]);
    exit;
}

$flash = admin_take_flash();
$liveControl = admin_get_live_stage_control($pdo);
$liveProgramId = $liveControl['program_id'];
$liveEntryId = $liveControl['entry_id'];
$recordedTimes = admin_get_recorded_times($pdo);

// 1. Fetch All Programs in Schedule Order
$stmtProg = $pdo->prepare("
    SELECT p.*, ct.name AS class_type_name,
           mss.name AS schedule_section_name
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
    WHERE p.event_id = ?
    ORDER BY 
        COALESCE(mss.sort_order, 999) ASC,
        COALESCE(p.start_time, '23:59:59') ASC,
        p.id ASC
");
$stmtProg->execute([$activeEventId]);
$programs = $stmtProg->fetchAll(PDO::FETCH_ASSOC);

// 2. Build Sequential Flat Stage Queue (Program Intro Slide FIRST, then Participants/Teams)
$stageQueue = [];
$liveQueueIndex = 0;
$queueCounter = 0;

$completedProgramsCount = 0;
foreach ($programs as $prog) {
    if ($prog['status'] === 'completed') {
        $completedProgramsCount++;
    }

    $pId = (int)$prog['id'];
    $isGroupProg = ($prog['program_type'] === 'group' || !empty($prog['only_team_marks']));
    
    // Fetch entries for this program
    $stmtEntries = $pdo->prepare("
        SELECT pe.*, t.team_name, t.team_color,
               (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_number
        FROM musabaqa_program_entries pe
        LEFT JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.program_id = ? AND pe.event_id = ?
        ORDER BY pe.performance_order ASC, pe.id ASC
    ");
    $stmtEntries->execute([$pId, $activeEventId]);
    $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
    $totalEntries = count($entries);

    // SLIDE A: Program Intro Slide
    $introKey = "p{$pId}_e0";
    $introRec = $recordedTimes[$introKey] ?? null;

    $introItem = [
        'queue_index' => $queueCounter,
        'program_id' => $pId,
        'program_title' => $prog['title'],
        'class_type_name' => $prog['class_type_name'] ?? 'General',
        'schedule_section_name' => $prog['schedule_section_name'] ?? '',
        'entry_id' => 0,
        'is_intro' => true,
        'is_group' => $isGroupProg,
        'chest_number' => $isGroupProg ? 'GROUP OVERVIEW' : 'PROGRAM OVERVIEW',
        'entry_name' => $totalEntries > 0 
            ? ("Ready to begin · " . ($isGroupProg ? "{$totalEntries} Teams" : "{$totalEntries} Participants")) 
            : "No entries registered",
        'team_name' => $isGroupProg ? 'Group Program' : 'Program Details Only',
        'team_color' => '#10b981',
        'participant_order' => 0,
        'total_participants' => $totalEntries,
        'has_entries' => ($totalEntries > 0),
        'recorded_time' => $introRec['formatted_time'] ?? null,
        'duration_seconds' => (int)($introRec['duration_seconds'] ?? 0)
    ];

    if ($pId === $liveProgramId && $liveEntryId === 0) {
        $liveQueueIndex = $queueCounter;
    }

    $stageQueue[] = $introItem;
    $queueCounter++;

    // SLIDES B: Participant / Team Slides
    foreach ($entries as $partIdx => $eRow) {
        $eId = (int)$eRow['id'];

        $chestDisplay = $isGroupProg ? ($eRow['team_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['chest_number'] ?: '-');
        $entryDisplay = $isGroupProg ? ($eRow['team_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['entry_name'] ?: 'Unnamed Participant');

        $recKey = "p{$pId}_e{$eId}";
        $recInfo = $recordedTimes[$recKey] ?? null;

        $item = [
            'queue_index' => $queueCounter,
            'program_id' => $pId,
            'program_title' => $prog['title'],
            'class_type_name' => $prog['class_type_name'] ?? 'General',
            'schedule_section_name' => $prog['schedule_section_name'] ?? '',
            'entry_id' => $eId,
            'is_intro' => false,
            'is_group' => $isGroupProg,
            'chest_number' => $chestDisplay,
            'entry_name' => $entryDisplay,
            'team_name' => $eRow['team_name'] ?: 'Team ' . ($partIdx + 1),
            'team_color' => $eRow['team_color'] ?: '#64748b',
            'participant_order' => $partIdx + 1,
            'total_participants' => $totalEntries,
            'has_entries' => true,
            'recorded_time' => $recInfo['formatted_time'] ?? null,
            'duration_seconds' => (int)($recInfo['duration_seconds'] ?? 0)
        ];

        if ($pId === $liveProgramId && $eId === $liveEntryId) {
            $liveQueueIndex = $queueCounter;
        }

        $stageQueue[] = $item;
        $queueCounter++;
    }
}

$totalQueueItems = count($stageQueue);
$totalProgramsCount = count($programs);
$selectedIndex = isset($_GET['q_idx']) ? max(0, min($totalQueueItems - 1, (int)$_GET['q_idx'])) : $liveQueueIndex;
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
    /* STAGEDECK LIVE BROADCAST CONSOLE STYLING */
    html, body {
        height: 100vh !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #090e0c !important;
        background-image: 
            radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.05) 0%, transparent 70%),
            linear-gradient(to bottom, #070b09, #020403) !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        overflow: hidden !important;
        font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif !important;
        color: #e2e8f0 !important;
    }
    
    .admin-layout {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        width: 100% !important;
        height: 100vh !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
    }

    body.layout-sidebar-enabled .main-content {
        margin-left: auto !important;
        margin-right: auto !important;
        padding-top: 16px !important;
        width: 100% !important;
        max-width: 1100px !important;
    }

    .main-content {
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 1100px !important;
        height: 100vh !important;
        padding: 20px !important;
        display: flex !important;
        flex-direction: column !important;
        box-sizing: border-box !important;
        background: #080c0a !important;
        border-left: 1px solid rgba(16, 185, 129, 0.08) !important;
        border-right: 1px solid rgba(16, 185, 129, 0.08) !important;
        box-shadow: 0 0 100px rgba(0, 0, 0, 0.9) !important;
        overflow-y: auto !important;
        position: relative !important;
        scrollbar-width: none;
    }
    .main-content::-webkit-scrollbar {
        display: none;
    }

    /* TOP HEADER BAR */
    .app-header {
        width: 100% !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-bottom: 14px !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.04) !important;
        box-sizing: border-box !important;
        flex-shrink: 0 !important;
        margin-bottom: 20px !important;
    }

    .header-status-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 99px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        transition: all 0.3s ease;
    }

    .status-badge.status-connected {
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.2);
        color: #34d399;
    }

    .status-badge.status-delayed {
        background: rgba(245, 158, 11, 0.08);
        border: 1px solid rgba(245, 158, 11, 0.2);
        color: #fbbf24;
    }

    .status-badge.status-offline {
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.2);
        color: #f87171;
    }

    .status-dot-pulse {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        animation: statusPulse 1.8s infinite ease-in-out;
    }
    .status-connected .status-dot-pulse { background: #10b981; box-shadow: 0 0 10px #10b981; }
    .status-delayed .status-dot-pulse { background: #fbbf24; box-shadow: 0 0 10px #fbbf24; }
    .status-offline .status-dot-pulse { background: #ef4444; box-shadow: 0 0 10px #ef4444; }

    @keyframes statusPulse {
        0%, 100% { transform: scale(0.95); opacity: 0.6; }
        50% { transform: scale(1.3); opacity: 1; }
    }

    .header-title {
        font-size: 16px;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: -0.3px;
    }

    .header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .grid-btn {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .grid-btn:hover {
        background: rgba(16, 185, 129, 0.12);
        border-color: rgba(16, 185, 129, 0.25);
        color: #34d399;
    }

    /* CONSOLE GRID SYSTEM */
    .console-grid {
        display: grid;
        grid-template-columns: 1.4fr 1fr;
        gap: 24px;
        flex: 1;
        min-height: 0;
        margin-bottom: 20px;
    }

    @media (max-width: 820px) {
        .console-grid {
            grid-template-columns: 1fr;
            overflow-y: auto;
        }
    }

    /* ACTIVE CONSOLE COLUMN */
    .active-console-panel {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* BROADCAST STYLES (PREVIEW vs LIVE) */
    .broadcast-container {
        width: 100%;
        border-radius: 24px;
        padding: 24px;
        box-sizing: border-box;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 280px;
    }

    .broadcast-container.state-preview {
        background: #111513;
        border: 2px solid rgba(148, 163, 184, 0.15);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }

    .broadcast-container.state-live {
        background: radial-gradient(circle at 50% 0%, rgba(239, 68, 68, 0.08) 0%, #161011 100%);
        border: 2px solid #ef4444;
        box-shadow: 0 0 35px rgba(239, 68, 68, 0.15), inset 0 0 20px rgba(239, 68, 68, 0.05);
    }

    .state-banner {
        font-size: 10px;
        font-weight: 900;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        padding: 4px 12px;
        border-radius: 6px;
        align-self: center;
        margin-bottom: 16px;
    }

    .state-preview .state-banner {
        background: rgba(148, 163, 184, 0.1);
        color: #94a3b8;
        border: 1px solid rgba(148, 163, 184, 0.2);
    }

    .state-live .state-banner {
        background: rgba(239, 68, 68, 0.15);
        color: #f87171;
        border: 1px solid rgba(239, 68, 68, 0.3);
        animation: livePulse 1.5s ease-in-out infinite;
    }

    @keyframes livePulse {
        0%, 100% { opacity: 0.8; }
        50% { opacity: 1; }
    }

    .program-title {
        font-size: 26px;
        font-weight: 900;
        color: #ffffff;
        letter-spacing: -0.5px;
        margin: 0 0 10px 0;
        line-height: 1.25;
    }
    .state-live .program-title {
        color: #fca5a5;
        text-shadow: 0 0 20px rgba(239, 68, 68, 0.15);
    }

    .program-subtitle {
        font-size: 13px;
        font-weight: 700;
        color: #10b981;
        margin-bottom: 14px;
    }
    .state-preview .program-subtitle {
        color: #94a3b8;
    }

    .chest-display {
        font-size: 40px;
        font-weight: 900;
        color: #ffffff;
        font-family: monospace;
        letter-spacing: 1px;
        margin-bottom: 4px;
        display: block;
    }
    .state-live .chest-display {
        color: #ef4444;
        text-shadow: 0 0 15px rgba(239, 68, 68, 0.4);
    }

    .performer-name {
        font-size: 20px;
        font-weight: 800;
        color: #ffffff;
        margin: 4px 0 10px 0;
    }

    .team-badge-pill {
        display: inline-block;
        padding: 4px 14px;
        border-radius: 99px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        color: #cbd5e1;
        font-size: 12px;
        font-weight: 600;
    }

    /* CONTROL PANEL STYLES */
    .controls-panel {
        background: #111513;
        border: 1px solid rgba(16, 185, 129, 0.1);
        border-radius: 20px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    /* INDEPENDENT PERFORMANCE TIMER */
    .timer-console {
        background: rgba(0, 0, 0, 0.4);
        border: 1px solid rgba(255, 255, 255, 0.04);
        border-radius: 16px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }

    .timer-header {
        font-size: 10px;
        font-weight: 800;
        color: #94a3b8;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .timer-clock-large {
        font-size: 38px;
        font-weight: 900;
        font-family: monospace;
        color: #34d399;
        text-shadow: 0 0 15px rgba(52, 211, 153, 0.25);
    }
    .timer-clock-large.recording {
        color: #ef4444;
        text-shadow: 0 0 20px rgba(239, 68, 68, 0.4);
    }

    .timer-control-buttons {
        display: flex;
        gap: 10px;
        width: 100%;
    }

    .console-btn {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.2s ease;
        border: none;
    }

    .console-btn.btn-primary-live {
        background: #ef4444;
        color: #ffffff;
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.25);
    }
    .console-btn.btn-primary-live:hover {
        background: #f87171;
    }

    .console-btn.btn-timer-start {
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #34d399;
    }
    .console-btn.btn-timer-start:hover {
        background: rgba(16, 185, 129, 0.2);
    }

    .console-btn.btn-timer-stop {
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #f87171;
    }
    .console-btn.btn-timer-stop:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .console-btn.btn-timer-reset {
        background: rgba(255, 255, 255, 0.05);
        color: #94a3b8;
    }
    .console-btn.btn-timer-reset:hover {
        background: rgba(255, 255, 255, 0.08);
    }

    /* RIGHT DETAIL PANEL COLUMN */
    .status-side-panel {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .detail-card {
        background: #111513;
        border: 1px solid rgba(255, 255, 255, 0.04);
        border-radius: 20px;
        padding: 20px;
    }

    .section-label {
        font-size: 11px;
        font-weight: 900;
        color: #10b981;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* PROGRESS TRACKERS */
    .progress-bar-wrap {
        background: rgba(255, 255, 255, 0.04);
        border-radius: 99px;
        height: 6px;
        overflow: hidden;
        margin-top: 6px;
    }

    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #34d399);
        border-radius: 99px;
        transition: width 0.3s ease;
    }

    /* NEXT UP COMPONENT */
    .next-up-preview {
        background: rgba(255, 255, 255, 0.02);
        border: 1px dashed rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 14px;
        margin-top: 10px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .next-up-preview:hover {
        background: rgba(16, 185, 129, 0.03);
        border-color: rgba(16, 185, 129, 0.2);
    }

    /* TOGGLE SWITCHES (LIVE LOCK) */
    .switch-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 14px;
        background: rgba(255, 255, 255, 0.02);
        border-radius: 12px;
        margin-top: 8px;
    }

    .switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: rgba(255,255,255,0.1);
        transition: .3s;
        border-radius: 24px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 4px;
        bottom: 4px;
        background-color: #94a3b8;
        transition: .3s;
        border-radius: 50%;
    }

    input:checked + .slider {
        background-color: #ef4444;
    }

    input:checked + .slider:before {
        transform: translateX(20px);
        background-color: #ffffff;
    }

    /* PAGINATION AND JUMP ROW */
    .pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #111513;
        border: 1px solid rgba(255, 255, 255, 0.04);
        border-radius: 16px;
        padding: 8px 16px;
        margin-top: auto;
    }

    .pagination-btn {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        color: #10b981;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .pagination-btn:hover:not(.disabled) {
        background: rgba(16, 185, 129, 0.1);
        border-color: rgba(16, 185, 129, 0.2);
        color: #fff;
    }
    .pagination-btn.disabled {
        opacity: 0.15;
        cursor: not-allowed;
    }

    /* TOAST NOTIFIER */
    .toast-notify {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(16, 185, 129, 0.95);
        color: #fff;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 800;
        font-size: 14px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.5);
        z-index: 999999;
        display: none;
        align-items: center;
        gap: 8px;
        backdrop-filter: blur(8px);
        width: max-content;
        max-width: 90vw;
    }

    /* CARD GRID FILTER TABS */
    .grid-filter-bar {
        display: flex;
        gap: 8px;
        margin: 10px 0;
        flex-wrap: wrap;
    }

    .filter-tab-btn {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 700;
        color: #cbd5e1;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .filter-tab-btn.active {
        background: rgba(16, 185, 129, 0.15);
        border-color: rgba(16, 185, 129, 0.4);
        color: #34d399;
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 12px !important;
        }
        .program-title {
            font-size: 20px !important;
        }
        .chest-display {
            font-size: 32px !important;
        }
        #cardsGridContainer {
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)) !important;
            gap: 10px !important;
        }
        #cardsModal > div {
            padding: 12px !important;
            border-radius: 14px !important;
        }
    }
</style>

<div class="main-content">
    <!-- Top Header Bar -->
    <header class="app-header">
        <div class="header-status-group">
            <div id="connectionStatusBadge" class="status-badge status-connected">
                <span class="status-dot-pulse"></span>
                <span id="connectionStatusText">CONNECTED</span>
            </div>
        </div>
        <div class="header-title">Emcee Master Stage Deck</div>
        <div class="header-actions">
            <button type="button" class="grid-btn" onclick="openCardsModal()" title="View Stage Cards Grid">
                <i class="fa-solid fa-border-all"></i>
            </button>
        </div>
    </header>

    <!-- Live Toast Notification -->
    <div id="toastNotification" class="toast-notify">
        <i class="fa-solid fa-circle-check"></i> <span id="toastText">Live Stage Updated!</span>
    </div>

    <!-- MAIN TWO-COLUMN CONSOLE GRID -->
    <div class="console-grid">
        
        <!-- LEFT COLUMN: ACTIVE CONTROL DECK -->
        <div class="active-console-panel">
            
            <!-- Active Performer Card Container (Preview vs Live states) -->
            <div id="chestBox" class="broadcast-container state-preview" onclick="handleSlideClick()">
                <div class="state-banner" id="textSlideMode">PREVIEWING</div>
                
                <div style="text-align: center; width: 100%;">
                    <h1 class="program-title" id="textProgramTitle">--</h1>
                    <div class="program-subtitle">
                        <span id="badgeClassType">--</span>
                        <span id="badgeSection" style="display: none;"> - <span id="textSection">--</span></span>
                    </div>
                    
                    <div id="participantDetailsArea" style="margin-top: 15px;">
                        <span id="textChestHeader" style="font-size: 11px; text-transform: uppercase; opacity: 0.6; display: block; margin-bottom: 4px;">CHEST NUMBER</span>
                        <span id="textChestNumber" class="chest-display">--</span>
                        <h3 id="textParticipantName" class="performer-name">--</h3>
                        <span id="pillTeam" class="team-badge-pill">--</span>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 15px; width: 100%;">
                    <span id="badgeStatus" class="badge">Previewing Slide</span>
                </div>
            </div>

            <!-- GO LIVE MAIN ACTION SWITCH -->
            <button type="button" id="btnSelect" class="console-btn btn-primary-live" onclick="broadcastLiveStage()" style="padding: 16px; font-size: 15px;">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span id="btnActionText">GO LIVE</span>
            </button>

            <!-- INDEPENDENT PERFORMANCE TIMER -->
            <div class="timer-console" id="timerPanel">
                <div class="timer-header">
                    <i class="fa-solid fa-stopwatch"></i>
                    <span id="timerStatusBadge">READY TO RECORD</span>
                </div>
                <div class="timer-clock-large" id="timerClock" onclick="toggleTimerFromMobile()">00:00.0</div>
                <div class="timer-control-buttons">
                    <button type="button" id="btnStartTimer" class="console-btn btn-timer-start" onclick="event.stopPropagation(); startTimer();">
                        <i class="fa-solid fa-play"></i> START TIMER
                    </button>
                    <button type="button" id="btnStopTimer" class="console-btn btn-timer-stop" onclick="event.stopPropagation(); stopTimer();" style="display:none;">
                        <i class="fa-solid fa-stop"></i> STOP TIMER
                    </button>
                    <button type="button" id="btnResetTimer" class="console-btn btn-timer-reset" onclick="event.stopPropagation(); resetTimer();" style="display:none;">
                        <i class="fa-solid fa-rotate-left"></i> RESET
                    </button>
                </div>
                <div id="timerSubtext" style="font-size: 11px; opacity: 0.5; text-align: center;">Click START TIMER when participant begins performance.</div>
            </div>

        </div>

        <!-- RIGHT COLUMN: STATUS & EVENT PROGRESS -->
        <div class="status-side-panel">
            
            <!-- PROGRESS TRACKER -->
            <div class="detail-card">
                <div class="section-label">
                    <span>PARTICIPANT PROGRESS</span>
                    <span id="textParticipantProgress">--</span>
                </div>
                <div class="progress-bar-wrap">
                    <div id="progressBarFill" class="progress-bar-fill" style="width: 0%;"></div>
                </div>

                <div class="section-label" style="margin-top: 20px; margin-bottom: 0;">
                    <span>EVENT QUEUE POSITION</span>
                    <span id="textEventQueue">--</span>
                </div>
            </div>

            <!-- NEXT UP PARTICIPANT PREVIEW -->
            <div class="detail-card">
                <div class="section-label">NEXT UP</div>
                <div id="nextUpCard" class="next-up-preview" onclick="previewNextItem()">
                    <div style="font-size: 12px; color: #10b981; font-weight: 700; margin-bottom: 4px;" id="textNextHeader">CHEST #--</div>
                    <div style="font-size: 16px; font-weight: 800; color: #fff; margin-bottom: 2px;" id="textNextName">--</div>
                    <div style="font-size: 13px; color: #cbd5e1; font-weight: 600;" id="textNextTitle">--</div>
                    <div style="font-size: 11px; color: #94a3b8; margin-top: 6px;" id="textNextProgress">--</div>
                </div>
                <button type="button" class="console-btn btn-timer-reset" onclick="previewNextItem()" style="width: 100%; margin-top: 12px; font-size: 12px;">
                    <i class="fa-solid fa-eye"></i> PREVIEW NEXT
                </button>
            </div>

            <!-- GUARD CONTROLS -->
            <div class="detail-card" style="display: flex; flex-direction: column; gap: 12px;">
                <div class="section-label">GUARD CONTROLS</div>
                
                <div class="switch-container">
                    <span style="font-size: 12px; font-weight: 700; color: #cbd5e1; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-lock" id="lockStatusIcon"></i> <span id="lockStatusText">LOCK GO LIVE</span>
                    </span>
                    <label class="switch">
                        <input type="checkbox" id="toggleLiveLock" onchange="handleLiveLockToggle(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <button type="button" id="btnUndoBroadcast" class="console-btn btn-timer-reset" onclick="undoLastBroadcast()" style="font-size: 12px; width: 100%;" disabled>
                    <i class="fa-solid fa-arrow-rotate-left"></i> UNDO LAST LIVE CHANGE
                </button>
            </div>

        </div>

    </div>

    <!-- Bottom Queue Pagination Bar -->
    <div class="pagination-bar">
        <button type="button" id="btnPrev" class="pagination-btn" onclick="navigateStage(-1)" title="Previous Slide">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        
        <div style="text-align: center;">
            <div class="slide-badge-pill" style="padding: 6px 16px;">
                <i class="fa-solid fa-play"></i>
                <span id="textSlideBadge">Slide -- of --</span>
            </div>
        </div>

        <button type="button" id="btnNext" class="pagination-btn" onclick="navigateStage(1)" title="Next Slide">
            <i class="fa-solid fa-chevron-right"></i>
        </button>
    </div>
</div>

<!-- FULL SCREEN FIXED STAGE CARDS GRID GALLERY MODAL -->
<div id="cardsModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.94); backdrop-filter: blur(20px); z-index: 99999; padding: 16px; align-items: center; justify-content: center;">
    <div style="width: 100%; max-width: 1400px; height: 100%; max-height: calc(100vh - 32px); margin: 0 auto; display: flex; flex-direction: column; background: rgba(5, 25, 14, 0.94); border: 1px solid rgba(16, 185, 129, 0.35); border-radius: 20px; padding: 20px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9);">
        
        <!-- Modal Fixed Top Bar -->
        <div style="flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(16,185,129,0.25); padding-bottom: 14px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2 style="margin: 0; font-size: 20px; color: #fff; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-border-all" style="color: #34d399;"></i> Stage Cards Grid
                </h2>
                <div style="font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px;">Click any card to preview &amp; manage participant</div>
                
                <!-- Filter Tabs -->
                <div class="grid-filter-bar" id="modalFilterTabs">
                    <button class="filter-tab-btn active" data-filter="all" onclick="changeGridFilter('all')">ALL</button>
                    <button class="filter-tab-btn" data-filter="upcoming" onclick="changeGridFilter('upcoming')">UPCOMING</button>
                    <button class="filter-tab-btn" data-filter="live" onclick="changeGridFilter('live')">LIVE</button>
                    <button class="filter-tab-btn" data-filter="recorded" onclick="changeGridFilter('recorded')">RECORDED</button>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 12px; width: 100%; max-width: 460px;">
                <input type="text" id="cardsSearchInput" class="form-control" placeholder="🔍 Search team, chest #, or program..." onkeyup="renderCardsGrid(this.value)" style="background: rgba(0,0,0,0.8); border: 1px solid rgba(16,185,129,0.3); color: #fff; padding: 8px 14px; border-radius: 10px; font-size: 13.5px;">
                <button type="button" class="btn btn-secondary btn-md" onclick="closeCardsModal()" style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #fff; border-radius: 10px; padding: 8px 16px; font-size: 14px; font-weight: 700;">
                    <i class="fa-solid fa-xmark mr-1"></i> Close
                </button>
            </div>
        </div>

        <!-- Scrollable Cards Grid Area -->
        <div id="cardsGridContainer" style="flex: 1; overflow-y: auto; padding: 14px 4px 4px 4px; margin-top: 10px; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; align-content: start;">
        </div>

    </div>
</div>

<script>
const stageQueue = <?= json_encode($stageQueue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const csrfToken = <?= json_encode(generate_csrf_token()) ?>;

let currentIndex = <?= (int)$selectedIndex ?>;
let liveProgramId = <?= (int)$liveProgramId ?>;
let liveEntryId = <?= (int)$liveEntryId ?>;

// GUARD & HISTORY STATES
let isLiveLocked = false;
let previousLiveState = { programId: null, entryId: null };
let activeFilter = 'all';

// TIMER STATE VARIABLES
let timerInterval = null;
let timerStartTime = 0;
let timerElapsedTime = 0;
let isTimerRunning = false;

// CONNECTION WATCHDOG
let lastSuccessPoll = Date.now();
let pollIntervalId = null;

function renderStageDeck(index, direction = 'next') {
    if (!stageQueue || stageQueue.length === 0) return;
    
    currentIndex = Math.max(0, Math.min(stageQueue.length - 1, index));
    const item = stageQueue[currentIndex];

    // Trigger Slide Sliding Transition Animation
    const chestBox = document.getElementById('chestBox');
    if (chestBox) {
        chestBox.classList.remove('slide-enter-next', 'slide-enter-prev');
        void chestBox.offsetWidth; // Trigger DOM Reflow
        chestBox.classList.add(direction === 'prev' ? 'slide-enter-prev' : 'slide-enter-next');
    }

    // Slide Counter / Pagination
    document.getElementById('textSlideBadge').innerText = `Slide ${currentIndex + 1} of ${stageQueue.length}`;

    // Program Info
    document.getElementById('textProgramTitle').innerText = item.program_title;
    document.getElementById('badgeClassType').innerText = item.class_type_name;

    const badgeSec = document.getElementById('badgeSection');
    if (item.schedule_section_name) {
        badgeSec.style.display = 'inline-block';
        document.getElementById('textSection').innerText = item.schedule_section_name;
    } else {
        badgeSec.style.display = 'none';
    }

    const textChestHeader = document.getElementById('textChestHeader');
    const textChestNum = document.getElementById('textChestNumber');
    const textPartName = document.getElementById('textParticipantName');
    const pillTeam = document.getElementById('pillTeam');
    const textSlideMode = document.getElementById('textSlideMode');

    if (item.is_intro) {
        textChestHeader.innerText = 'STAGE DISPLAY SLIDE';
        textChestNum.innerText = item.is_group ? '👥 GROUP INTRO' : '📋 PROGRAM INTRO';
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '24px' : '32px';
        textPartName.innerText = item.entry_name;
        
        pillTeam.innerText = item.is_group ? 'Group Team Program' : 'Program Overview Mode';
        pillTeam.style.background = 'rgba(16, 185, 129, 0.2)';
        pillTeam.style.borderColor = 'rgba(16, 185, 129, 0.4)';
        pillTeam.style.color = '#34d399';
        pillTeam.style.display = 'inline-block';
    } else if (item.is_group) {
        textChestHeader.innerText = 'STAGE GROUP TEAM';
        textChestNum.innerText = item.team_name;
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '26px' : '38px';
        textPartName.innerText = 'Team Performance';
        
        pillTeam.innerText = item.team_name;
        pillTeam.style.background = item.team_color + '22';
        pillTeam.style.borderColor = item.team_color + '44';
        pillTeam.style.color = '#fff';
        pillTeam.style.display = 'inline-block';
    } else if (item.has_entries) {
        textChestHeader.innerText = 'CHEST NUMBER';
        textChestNum.innerText = item.chest_number;
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '32px' : '52px';
        textPartName.innerText = item.entry_name;
        
        pillTeam.innerText = item.team_name;
        pillTeam.style.background = item.team_color + '22';
        pillTeam.style.borderColor = item.team_color + '44';
        pillTeam.style.color = '#fff';
        pillTeam.style.display = 'inline-block';
    } else {
        textChestHeader.innerText = 'CHEST NUMBER';
        textChestNum.innerText = '-';
        textChestNum.style.fontSize = '52px';
        textPartName.innerText = 'No Participants Registered';
        pillTeam.style.display = 'none';
    }

    // Is Live State Comparison
    const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);

    const badgeStatus = document.getElementById('badgeStatus');
    const btnSelect = document.getElementById('btnSelect');
    const btnActionText = document.getElementById('btnActionText');

    if (isLiveItem) {
        // STATE: LIVE
        chestBox.className = 'broadcast-container state-live';
        textSlideMode.innerText = '● LIVE ON STAGE';
        badgeStatus.className = 'badge badge-success';
        badgeStatus.innerText = '🔴 Live On Stage';
        
        btnSelect.className = 'console-btn btn-timer-reset';
        btnSelect.style.background = 'rgba(16, 185, 129, 0.1)';
        btnSelect.style.color = '#34d399';
        btnActionText.innerText = 'LIVE NOW';
    } else {
        // STATE: PREVIEWING
        chestBox.className = 'broadcast-container state-preview';
        textSlideMode.innerText = 'PREVIEWING';
        badgeStatus.className = 'badge badge-neutral';
        badgeStatus.innerText = 'Previewing Slide';
        
        btnSelect.className = 'console-btn btn-primary-live';
        btnSelect.style.background = '';
        btnSelect.style.color = '';
        btnActionText.innerText = 'GO LIVE';
    }

    // Progress Trackers
    const textParticipantProgress = document.getElementById('textParticipantProgress');
    const progressBarFill = document.getElementById('progressBarFill');
    if (item.is_intro) {
        textParticipantProgress.innerText = '0 / ' + item.total_participants;
        progressBarFill.style.width = '0%';
    } else {
        textParticipantProgress.innerText = item.participant_order + ' / ' + item.total_participants;
        progressBarFill.style.width = ((item.participant_order / item.total_participants) * 100) + '%';
    }

    document.getElementById('textEventQueue').innerText = (currentIndex + 1) + ' / ' + stageQueue.length;

    // NEXT UP Section Builder
    const nextUpCard = document.getElementById('nextUpCard');
    const nextItem = stageQueue[currentIndex + 1];
    if (nextItem) {
        nextUpCard.style.display = 'block';
        const nextHeader = document.getElementById('textNextHeader');
        const nextName = document.getElementById('textNextName');
        const nextTitle = document.getElementById('textNextTitle');
        const nextProgress = document.getElementById('textNextProgress');

        if (nextItem.is_intro) {
            nextHeader.innerText = '📋 PROGRAM INTRO';
            nextName.innerText = nextItem.entry_name;
        } else if (nextItem.is_group) {
            nextHeader.innerText = '👥 GROUP TEAM';
            nextName.innerText = nextItem.team_name;
        } else {
            nextHeader.innerText = 'CHEST #' + nextItem.chest_number;
            nextName.innerText = nextItem.entry_name;
        }
        nextTitle.innerText = nextItem.program_title;
        nextProgress.innerText = nextItem.is_intro ? 'Intro Slide' : ('Slot ' + nextItem.participant_order + ' of ' + nextItem.total_participants);
    } else {
        nextUpCard.style.display = 'none';
        const nextName = document.getElementById('textNextName');
        nextName.innerText = 'End of stage queue';
    }

    // Update Stage Timer State
    syncTimerUI(item, isLiveItem);

    // Arrows Disable State
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');

    if (currentIndex <= 0) {
        btnPrev.classList.add('disabled');
    } else {
        btnPrev.classList.remove('disabled');
    }

    if (currentIndex >= stageQueue.length - 1) {
        btnNext.classList.add('disabled');
    } else {
        btnNext.classList.remove('disabled');
    }

    // Sync Jump Dropdown if any
    const selectDropdown = document.getElementById('selectStageJump');
    if (selectDropdown) {
        selectDropdown.value = currentIndex;
    }
}

function handleSlideClick() {
    if (isLiveLocked) {
        showToast('Console is Live Locked. Unlock to broadcast.');
        return;
    }
    broadcastLiveStage();
}

function syncTimerUI(item, isLiveItem) {
    const timerPanel = document.getElementById('timerPanel');
    if (!timerPanel) return;
    const timerStatusBadge = document.getElementById('timerStatusBadge');
    const timerClock = document.getElementById('timerClock');
    const timerSubtext = document.getElementById('timerSubtext');
    const btnStart = document.getElementById('btnStartTimer');
    const btnStop = document.getElementById('btnStopTimer');
    const btnReset = document.getElementById('btnResetTimer');

    if (isTimerRunning) {
        timerStatusBadge.innerHTML = '<span style="color:#ef4444; font-weight:900;">● RECORDING</span>';
        timerClock.className = 'timer-clock-large recording';
        timerSubtext.innerText = 'Stopwatch actively timing performance...';
        btnStart.style.display = 'none';
        btnStop.style.display = 'inline-flex';
        btnReset.style.display = 'none';
        return;
    }

    timerClock.className = 'timer-clock-large';

    if (item.recorded_time) {
        timerStatusBadge.innerHTML = '<span style="color:#10b981; font-weight:900;">⏱ PERFORMANCE COMPLETE</span>';
        timerClock.innerText = item.recorded_time.includes('.') ? item.recorded_time : item.recorded_time + '.0';
        timerSubtext.innerHTML = `Recorded Duration: <strong>${item.recorded_time}</strong>`;
        btnStart.style.display = 'inline-flex';
        btnStart.innerHTML = '<i class="fa-solid fa-rotate mr-1"></i> RE-RECORD';
        btnStop.style.display = 'none';
        btnReset.style.display = 'inline-flex';
    } else {
        timerStatusBadge.innerText = 'PERFORMANCE TIMER';
        timerClock.innerText = formatTimeMs(timerElapsedTime);
        timerSubtext.innerText = isLiveItem ? 'Participant is active. Click START TIMER.' : 'Participant is not LIVE. Go Live first.';
        btnStart.style.display = 'inline-flex';
        btnStart.innerHTML = '<i class="fa-solid fa-play mr-1"></i> START TIMER';
        btnStop.style.display = 'none';
        btnReset.style.display = (timerElapsedTime > 0) ? 'inline-flex' : 'none';
    }
}

// TIMER ENGINE FUNCTIONS
function startTimer() {
    const item = stageQueue[currentIndex];
    const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);

    if (!isLiveItem) {
        showToast('Participant is not LIVE. Go Live first.');
        return;
    }

    if (isTimerRunning) return;

    isTimerRunning = true;
    timerStartTime = performance.now() - timerElapsedTime;

    timerInterval = setInterval(() => {
        timerElapsedTime = performance.now() - timerStartTime;
        document.getElementById('timerClock').innerText = formatTimeMs(timerElapsedTime);
    }, 90);

    syncTimerUI(item, true);
}

function stopTimer() {
    if (!isTimerRunning) return;

    clearInterval(timerInterval);
    timerInterval = null;
    isTimerRunning = false;

    const item = stageQueue[currentIndex];
    const durationSec = Math.round(timerElapsedTime / 1000);
    const formatted = formatTimeMs(timerElapsedTime, false);

    item.recorded_time = formatted;
    item.duration_seconds = durationSec;

    // Save to backend via AJAX
    saveRecordedTimeBackend(item.program_id, item.entry_id, durationSec, formatted);

    syncTimerUI(item, true);
    showToast(`Recorded time: ${formatted} saved!`);
}

function resetTimer() {
    if (confirm("Are you sure you want to reset the stopwatch for this performer?")) {
        if (isTimerRunning) {
            clearInterval(timerInterval);
            timerInterval = null;
            isTimerRunning = false;
        }
        timerElapsedTime = 0;
        const item = stageQueue[currentIndex];
        item.recorded_time = null;
        item.duration_seconds = 0;

        syncTimerUI(item, (item.program_id === liveProgramId && item.entry_id === liveEntryId));
    }
}

function toggleTimerFromMobile() {
    if (isTimerRunning) {
        stopTimer();
    } else {
        startTimer();
    }
}

function saveRecordedTimeBackend(programId, entryId, durationSec, formattedTime) {
    const formData = new FormData();
    formData.append('action', 'save_recorded_time');
    formData.append('program_id', programId);
    formData.append('entry_id', entryId);
    formData.append('duration_seconds', durationSec);
    formData.append('formatted_time', formattedTime);
    formData.append('csrf_token', csrfToken);

    fetch(window.location.pathname + '?ajax=1', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        console.log('Timer saved:', data);
    })
    .catch(err => {
        console.error('Failed to save timer:', err);
    });
}

function formatTimeMs(ms, includeTenths = true) {
    const totalSec = Math.floor(ms / 1000);
    const mins = Math.floor(totalSec / 60);
    const secs = totalSec % 60;
    const tenths = Math.floor((ms % 1000) / 100);

    const mm = String(mins).padStart(2, '0');
    const ss = String(secs).padStart(2, '0');

    return includeTenths ? `${mm}:${ss}.${tenths}` : `${mm}:${ss}`;
}

function populateJumpDropdown() {
    const selectDropdown = document.getElementById('selectStageJump');
    if (!selectDropdown || !stageQueue) return;

    selectDropdown.innerHTML = '';
    stageQueue.forEach((qItem) => {
        const opt = document.createElement('option');
        opt.value = qItem.queue_index;
        const isLive = (qItem.program_id === liveProgramId && qItem.entry_id === liveEntryId);
        
        let label = (isLive ? '🔴 ' : '');
        if (qItem.recorded_time) {
            label += `[⏱ ${qItem.recorded_time}] `;
        }

        if (qItem.is_intro) {
            label += `📋 [INTRO] ${qItem.program_title} (${qItem.total_participants} ${qItem.is_group ? 'Teams' : 'Entries'})`;
        } else if (qItem.is_group) {
            label += `${qItem.program_title} - Team ${qItem.team_name}`;
        } else {
            label += `${qItem.program_title} - Chest #${qItem.chest_number} (${qItem.entry_name})`;
        }

        opt.innerText = label;
        selectDropdown.appendChild(opt);
    });
    selectDropdown.value = currentIndex;
}

function openCardsModal() {
    document.getElementById('cardsModal').style.display = 'flex';
    document.getElementById('cardsSearchInput').value = '';
    changeGridFilter('all');
}

function closeCardsModal() {
    document.getElementById('cardsModal').style.display = 'none';
}

function changeGridFilter(filter) {
    activeFilter = filter;
    
    // Update active class on filter tab buttons
    const buttons = document.querySelectorAll('#modalFilterTabs .filter-tab-btn');
    buttons.forEach(btn => {
        if (btn.getAttribute('data-filter') === filter) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    renderCardsGrid(document.getElementById('cardsSearchInput').value);
}

function renderCardsGrid(filterText = '') {
    const container = document.getElementById('cardsGridContainer');
    if (!container || !stageQueue) return;

    container.innerHTML = '';
    const search = filterText.toLowerCase().trim();

    stageQueue.forEach(item => {
        // 1. Search text filter
        if (search) {
            const matchText = (item.program_title + ' ' + item.chest_number + ' ' + item.entry_name + ' ' + item.team_name + ' ' + item.class_type_name).toLowerCase();
            if (!matchText.includes(search)) return;
        }

        // 2. Segment status filter (All, Upcoming, Live, Recorded)
        const isLive = (item.program_id === liveProgramId && item.entry_id === liveEntryId);
        if (activeFilter === 'live' && !isLive) return;
        if (activeFilter === 'recorded' && !item.recorded_time) return;
        if (activeFilter === 'upcoming') {
            // upcoming means slot is greater than current index and not live
            if (item.queue_index <= currentIndex || isLive) return;
        }

        const card = document.createElement('div');
        card.style.cssText = `
            background: ${isLive ? 'linear-gradient(135deg, rgba(239, 68, 68, 0.2) 0%, rgba(0, 0, 0, 0.95) 100%)' : 'linear-gradient(135deg, rgba(16, 185, 129, 0.03) 0%, rgba(0, 0, 0, 0.88) 100%)'};
            border: ${isLive ? '2px solid #ef4444' : '1px solid rgba(16, 185, 129, 0.2)'};
            border-radius: 16px;
            padding: 14px;
            text-align: center;
            position: relative;
            box-shadow: ${isLive ? '0 10px 30px rgba(239, 68, 68, 0.15)' : '0 8px 24px rgba(0,0,0,0.6)'};
            transition: all 0.25s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 165px;
        `;

        card.onmouseover = () => {
            card.style.transform = 'translateY(-4px)';
            if (!isLive) {
                card.style.borderColor = '#10b981';
                card.style.boxShadow = '0 12px 30px rgba(16, 185, 129, 0.2)';
            }
        };
        card.onmouseout = () => {
            card.style.transform = 'translateY(0)';
            if (!isLive) {
                card.style.borderColor = 'rgba(16, 185, 129, 0.2)';
                card.style.boxShadow = '0 8px 24px rgba(0,0,0,0.6)';
            }
        };

        let badgeHtml = '';
        if (isLive) {
            badgeHtml = '<span class="badge" style="font-size: 9px; margin-bottom: 4px; background:rgba(239,68,68,0.2); border:1px solid rgba(239,68,68,0.4); color:#f87171;">🔴 LIVE ON STAGE</span>';
        } else if (item.is_intro) {
            badgeHtml = '<span class="badge" style="font-size: 9px; margin-bottom: 4px; background: rgba(16,185,129,0.1); color: #34d399; border: 1px solid rgba(16,185,129,0.2);">📋 INTRO</span>';
        } else if (item.is_group) {
            badgeHtml = '<span class="badge" style="font-size: 9px; margin-bottom: 4px; background: rgba(245, 158, 11, 0.1); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">👥 TEAM</span>';
        } else {
            badgeHtml = '<span class="badge" style="font-size: 9px; margin-bottom: 4px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); color: #aaa;">#' + escapeHtml(item.chest_number) + '</span>';
        }

        if (item.recorded_time) {
            badgeHtml += ` <span style="font-size: 9px; background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); padding: 1px 5px; border-radius: 4px;">⏱ ${escapeHtml(item.recorded_time)}</span>`;
        }

        let mainDisplay = '';
        if (item.is_intro) {
            mainDisplay = `<div style="font-size: 16px; font-weight: 800; color: #34d399; margin: 4px 0;">📋 PROGRAM INTRO</div>`;
        } else if (item.is_group) {
            mainDisplay = `<div style="font-size: 20px; font-weight: 900; color: #fbbf24; margin: 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(item.team_name)}</div>`;
        } else {
            mainDisplay = `<div style="font-size: 26px; font-weight: 900; color: #10b981; margin: 2px 0;">#${escapeHtml(item.chest_number)}</div>`;
        }

        let subDisplay = item.is_group ? 'Team Performance' : escapeHtml(item.entry_name);

        card.innerHTML = `
            <div>
                ${badgeHtml}
                <div style="font-size: 12.5px; font-weight: 700; color: #fff; margin-bottom: 2px; line-height: 1.25; height: 32px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                    ${escapeHtml(item.program_title)}
                </div>
                ${mainDisplay}
                <div style="font-size: 11.5px; font-weight: 600; color: rgba(255,255,255,0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    ${subDisplay}
                </div>
            </div>
            <div style="font-size: 10px; font-weight: 700; color: #34d399; margin-top: 8px; opacity: 0.8; text-transform: uppercase;">
                Select Performer
            </div>
        `;

        card.onclick = () => {
            selectCardIndex(item.queue_index, false); // selectCardIndex now defaults to preview only
        };

        container.appendChild(card);
    });
}

function selectCardIndex(idx, broadcastNow = false) {
    if (isTimerRunning) {
        stopTimer();
    }
    timerElapsedTime = 0;
    const direction = idx > currentIndex ? 'next' : 'prev';
    renderStageDeck(idx, direction);
    closeCardsModal();
    if (broadcastNow) {
        broadcastLiveStage();
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function navigateStage(delta) {
    if (isTimerRunning) {
        stopTimer();
    }
    timerElapsedTime = 0;
    const targetIdx = currentIndex + delta;
    if (targetIdx >= 0 && targetIdx < stageQueue.length) {
        renderStageDeck(targetIdx, delta > 0 ? 'next' : 'prev');
    }
}

function previewNextItem() {
    navigateStage(1);
}

function jumpToQueueIndex(idx) {
    selectCardIndex(idx, false);
}

function handleLiveLockToggle(checked) {
    isLiveLocked = checked;
    const lockStatusIcon = document.getElementById('lockStatusIcon');
    const lockStatusText = document.getElementById('lockStatusText');
    if (isLiveLocked) {
        lockStatusIcon.className = 'fa-solid fa-lock';
        lockStatusText.innerText = 'LOCK ACTIVE';
        lockStatusText.style.color = '#ef4444';
    } else {
        lockStatusIcon.className = 'fa-solid fa-lock-open';
        lockStatusText.innerText = 'LOCK GO LIVE';
        lockStatusText.style.color = '';
    }
}

function undoLastBroadcast() {
    if (!previousLiveState.programId) {
        showToast('No previous broadcast to revert to.');
        return;
    }
    const targetProgId = previousLiveState.programId;
    const targetEntryId = previousLiveState.entryId;

    // Search for match in queue to navigate back to
    let foundIdx = -1;
    for (let i = 0; i < stageQueue.length; i++) {
        if (stageQueue[i].program_id === targetProgId && stageQueue[i].entry_id === targetEntryId) {
            foundIdx = i;
            break;
        }
    }

    if (foundIdx >= 0) {
        selectCardIndex(foundIdx, false);
        // Temporarily unlock to allow revert broadcast
        const wasLocked = isLiveLocked;
        isLiveLocked = false;
        broadcastLiveStage();
        isLiveLocked = wasLocked;
        showToast('Broadcast reverted!');
    }
}

function broadcastLiveStage() {
    if (isLiveLocked) {
        showToast('Accidental broadcast locked! Unlock first.');
        return;
    }
    if (!stageQueue || !stageQueue[currentIndex]) return;
    const item = stageQueue[currentIndex];

    // Store previous live state before changing
    previousLiveState.programId = liveProgramId;
    previousLiveState.entryId = liveEntryId;
    document.getElementById('btnUndoBroadcast').disabled = false;

    const formData = new FormData();
    formData.append('action', 'broadcast_stage');
    formData.append('program_id', item.program_id);
    formData.append('entry_id', item.entry_id);
    formData.append('queue_index', item.queue_index);
    formData.append('csrf_token', csrfToken);

    fetch(window.location.pathname + '?ajax=1', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            liveProgramId = item.program_id;
            liveEntryId = item.entry_id;
            renderStageDeck(currentIndex);
            populateJumpDropdown();
            
            let msg = '';
            if (item.is_intro) {
                msg = `Live Stage set to Program Intro: ${item.program_title}`;
            } else if (item.is_group) {
                msg = `Live Stage set to Team: ${item.team_name}`;
            } else {
                msg = `Live Stage set to Chest #${item.chest_number}!`;
            }
            showToast(msg);
        }
    })
    .catch(err => {
        console.error('Broadcast failed:', err);
    });
}

// TOUCH SWIPE
let touchStartX = 0;
let touchStartY = 0;
let touchEndX = 0;
let touchEndY = 0;

const deckElement = document.getElementById('chestBox');
if (deckElement) {
    deckElement.addEventListener('touchstart', (e) => {
        if (e.touches && e.touches[0]) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
        }
    }, { passive: true });

    deckElement.addEventListener('touchend', (e) => {
        if (e.changedTouches && e.changedTouches[0]) {
            touchEndX = e.changedTouches[0].clientX;
            touchEndY = e.changedTouches[0].clientY;
            handleSwipeGesture();
        }
    }, { passive: true });
}

function handleSwipeGesture() {
    const deltaX = touchEndX - touchStartX;
    const deltaY = touchEndY - touchStartY;
    
    if (Math.abs(deltaX) > 40 && Math.abs(deltaX) > Math.abs(deltaY) * 1.2) {
        if (deltaX < 0) {
            navigateStage(1); // Swipe left -> next slide (preview only)
        } else {
            navigateStage(-1); // Swipe right -> prev slide (preview only)
        }
    }
}

// Keyboard Arrow Navigation
document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') {
        return;
    }

    if (e.key === 'Escape') {
        closeCardsModal();
        return;
    }

    const cardsModal = document.getElementById('cardsModal');
    if (cardsModal && cardsModal.style.display === 'flex') {
        return;
    }

    if (e.key === 'ArrowLeft') {
        e.preventDefault();
        navigateStage(-1);
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        navigateStage(1);
    } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        broadcastLiveStage();
    }
});

function goToCurrentLive() {
    const startPoll = Date.now();
    fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax_status=1')
    .then(r => {
        if (!r.ok) throw new Error();
        return r.json();
    })
    .then(data => {
        lastSuccessPoll = Date.now();
        const latency = lastSuccessPoll - startPoll;
        const badge = document.getElementById('connectionStatusBadge');
        const text = document.getElementById('connectionStatusText');

        if (latency > 1500) {
            badge.className = 'status-badge status-delayed';
            text.innerText = 'DELAYED';
        } else {
            badge.className = 'status-badge status-connected';
            text.innerText = 'CONNECTED';
        }

        const lc = data.live_control;
        if (lc) {
            liveProgramId = parseInt(lc.program_id) || 0;
            liveEntryId = parseInt(lc.entry_id) || 0;
            // Update UI live checks
            const item = stageQueue[currentIndex];
            const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);
            const chestBox = document.getElementById('chestBox');
            const textSlideMode = document.getElementById('textSlideMode');
            const badgeStatus = document.getElementById('badgeStatus');
            const btnSelect = document.getElementById('btnSelect');
            const btnActionText = document.getElementById('btnActionText');

            if (isLiveItem) {
                chestBox.className = 'broadcast-container state-live';
                textSlideMode.innerText = '● LIVE ON STAGE';
                badgeStatus.className = 'badge badge-success';
                badgeStatus.innerText = '🔴 Live On Stage';
                btnSelect.className = 'console-btn btn-timer-reset';
                btnSelect.style.background = 'rgba(16, 185, 129, 0.1)';
                btnSelect.style.color = '#34d399';
                btnActionText.innerText = 'LIVE NOW';
            } else {
                chestBox.className = 'broadcast-container state-preview';
                textSlideMode.innerText = 'PREVIEWING';
                badgeStatus.className = 'badge badge-neutral';
                badgeStatus.innerText = 'Previewing Slide';
                btnSelect.className = 'console-btn btn-primary-live';
                btnSelect.style.background = '';
                btnSelect.style.color = '';
                btnActionText.innerText = 'GO LIVE';
            }
        }
    })
    .catch(() => {
        const badge = document.getElementById('connectionStatusBadge');
        const text = document.getElementById('connectionStatusText');
        badge.className = 'status-badge status-offline';
        text.innerText = 'OFFLINE';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    populateJumpDropdown();
    renderStageDeck(currentIndex, 'next');
    
    // Poll status every 3 seconds
    pollIntervalId = setInterval(goToCurrentLive, 3000);
});
</script>

<?php
admin_close_page();
