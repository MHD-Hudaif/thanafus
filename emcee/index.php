<?php
declare(strict_types=1);

$pageTitle = 'Emcee Master Stage Deck';
$skipLoginCheck = true; // Allow standalone Emcee stage control without requiring admin user session

require_once __DIR__ . '/../includes/admin-helpers.php';
require_once __DIR__ . '/../includes/event-guard.php';

$_SESSION['active_workspace'] = 'emcee';

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)($activeEvent['id'] ?? 0);

// Load live display functions & settings
require_once __DIR__ . '/../live-display/includes/functions.php';
$tvSettings = tv_get_settings($activeEventId);
$showNext = ($tvSettings['show_next_participant'] ?? true);

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
        admin_redirect('/emcee/index.php');
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

    if ($action === 'update_live_timer') {
        $running = (int)($_POST['running'] ?? 0);
        $startTime = (float)($_POST['start_time'] ?? 0);
        $elapsed = (int)($_POST['elapsed'] ?? 0);

        $settings = admin_get_settings($pdo);
        $settings['live_timer_running'] = $running;
        $settings['live_timer_start_time'] = $startTime;
        $settings['live_timer_elapsed'] = $elapsed;
        save_musabaqa_settings($pdo, $settings);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'running' => $running,
            'start_time' => $startTime,
            'elapsed' => $elapsed
        ]);
        exit;
    }

    if ($action === 'toggle_next_participant') {
        $enabled = (int)($_POST['enabled'] ?? 1);
        $settings = tv_get_settings($activeEventId);
        $settings['show_next_participant'] = ($enabled === 1);
        tv_save_settings($activeEventId, $settings);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'show_next_participant' => ($enabled === 1),
            'message' => $enabled === 1 ? 'Next performer enabled on TV!' : 'Next performer disabled on TV!'
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

$globalSettings = admin_get_settings($pdo);
$initTimerRunning = (int)($globalSettings['live_timer_running'] ?? 0);
$initTimerStartTime = (float)($globalSettings['live_timer_start_time'] ?? 0.0);
$initTimerElapsed = (int)($globalSettings['live_timer_elapsed'] ?? 0);

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

        if ($isGroupProg) {
            $title = $prog['title'] ?? '';
            $chestDisplay = $eRow['entry_name'] ?? '';
            if ($title !== '') {
                $chestDisplay = trim(str_ireplace($title . ' -', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . ' - ', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . '-', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title, '', $chestDisplay));
                $chestDisplay = ltrim($chestDisplay, "- \t\n\r\0\x0B");
            }
            if (empty($chestDisplay)) {
                $chestDisplay = 'Team ' . ($partIdx + 1);
            }
        } else {
            $chestDisplay = $eRow['chest_number'] ?: '-';
        }
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

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    *, *::before, *::after {
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

    /* CSS REDESIGN FOR EMCEE MASTER STAGE DECK - CREAM AND WHITE THEME */
    html, body {
        height: 100vh !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #faf7f0 !important;
        background-image: 
            radial-gradient(at 0% 0%, rgba(180, 200, 160, 0.20) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(210, 185, 140, 0.20) 0px, transparent 50%),
            radial-gradient(at 50% 50%, rgba(235, 225, 210, 0.60) 0px, transparent 100%) !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        overflow: hidden !important;
        font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif !important;
        color: #2e2b27 !important;
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
        max-width: 440px !important;
    }

    .main-content {
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 440px !important;
        height: 100vh !important;
        padding: 16px !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        box-sizing: border-box !important;
        background: rgba(255, 250, 240, 0.75) !important;
        border-left: 1px solid rgba(200, 180, 150, 0.25) !important;
        border-right: 1px solid rgba(200, 180, 150, 0.25) !important;
        box-shadow: 0 10px 40px rgba(140, 120, 100, 0.06) !important;
        overflow: hidden !important; /* NO SCROLL */
        position: relative !important;
        scrollbar-width: none;
    }
    .main-content::-webkit-scrollbar {
        display: none;
    }
    
    @media (max-width: 440px) {
        .main-content {
            border-left: none !important;
            border-right: none !important;
            box-shadow: none !important;
            max-width: 100vw !important;
        }
    }

    /* TOP HEADER BAR */
    .app-header {
        width: 100% !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 4px 4px 12px 4px !important;
        border-bottom: 1px solid rgba(200, 180, 150, 0.25) !important;
        box-sizing: border-box !important;
        flex-shrink: 0 !important;
        margin-bottom: 16px !important;
    }

    .header-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(93, 126, 90, 0.08) !important;
        border: 1px solid rgba(93, 126, 90, 0.25) !important;
        padding: 6px 12px;
        border-radius: 999px;
    }

    .status-label {
        font-size: 11px;
        font-weight: 800;
        color: #5d7e5a !important;
        letter-spacing: 0.5px;
    }

    .status-dot {
        width: 6px;
        height: 6px;
        background-color: #5d7e5a !important;
        border-radius: 50%;
        box-shadow: 0 0 10px rgba(93, 126, 90, 0.4) !important;
        animation: statusPulse 1.8s infinite ease-in-out;
    }

    .header-title {
        font-size: 15px;
        font-weight: 800;
        color: #2e2b27 !important;
        letter-spacing: -0.2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 150px;
    }

    .grid-btn {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: rgba(93, 126, 90, 0.06) !important;
        border: 1px solid rgba(93, 126, 90, 0.15) !important;
        color: #4b6b47 !important;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .grid-btn:hover {
        background: rgba(93, 126, 90, 0.12) !important;
        border-color: rgba(93, 126, 90, 0.3) !important;
        color: #2e2b27 !important;
    }

    /* SLIDE CARD CONTAINER & STYLING */
    .slide-card-container {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        box-sizing: border-box;
        padding: 10px 0;
    }

    .slide-card {
        width: 100%;
        background: #ffffff !important;
        border: 2px solid rgba(200, 180, 150, 0.3) !important;
        border-radius: 28px;
        padding: 26px 22px;
        box-shadow: 0 10px 30px rgba(140, 120, 100, 0.06) !important;
        text-align: center;
        position: relative;
        box-sizing: border-box;
        transition: all 0.3s ease;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
    }

    .slide-card.is-live-box {
        border-color: #5d7e5a !important;
        box-shadow: 0 0 30px rgba(93, 126, 90, 0.15), inset 0 0 15px rgba(93, 126, 90, 0.05) !important;
    }

    .card-subtitle {
        font-size: 11px;
        font-weight: 800;
        color: #6b6258 !important;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        margin-bottom: 20px;
    }

    .card-icon-wrap {
        margin-bottom: 20px;
    }

    .card-icon-circle {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: rgba(93, 126, 90, 0.08) !important;
        border: 1px solid rgba(93, 126, 90, 0.2) !important;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        color: #5d7e5a !important;
    }

    .card-main-title {
        font-size: 28px;
        font-weight: 900;
        color: #3a5e44 !important;
        letter-spacing: -0.5px;
        line-height: 1.25;
        margin: 0 0 12px 0;
        text-shadow: none !important;
    }

    .card-slot-time {
        font-size: 15px;
        font-weight: 700;
        color: #5d7e5a !important;
        margin-bottom: 12px;
        letter-spacing: -0.2px;
    }

    .card-description {
        width: 100%;
        box-sizing: border-box;
    }

    .neon-chest {
        font-size: 34px;
        font-weight: 900;
        color: #c9a86c !important;
        font-family: monospace;
        letter-spacing: 1px;
        display: block;
        margin-bottom: 4px;
        text-shadow: none !important;
    }

    .participant-name {
        font-size: 18px;
        font-weight: 800;
        color: #2e2b27 !important;
        margin: 4px 0 8px 0;
    }

    .team-badge {
        display: inline-block;
        padding: 4px 14px;
        border-radius: 99px;
        background: rgba(0, 0, 0, 0.03) !important;
        border: 1px solid rgba(0, 0, 0, 0.08) !important;
        color: #6b6258 !important;
        font-size: 12px;
        font-weight: 600;
    }

    .badge {
        display: inline-block;
        font-size: 10px;
        font-weight: 800;
        padding: 4px 10px;
        border-radius: 6px;
        text-transform: uppercase;
    }
    
    .badge-success {
        background: rgba(93, 126, 90, 0.12) !important;
        color: #5d7e5a !important;
        border: 1px solid rgba(93, 126, 90, 0.3) !important;
    }

    .badge-neutral {
        background: rgba(0, 0, 0, 0.04) !important;
        color: #6b6258 !important;
        border: 1px solid rgba(0, 0, 0, 0.08) !important;
    }

    /* CONTROLS ROW */
    .app-controls {
        width: 100% !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        margin-bottom: 16px !important;
        box-sizing: border-box !important;
        flex-shrink: 0 !important;
    }

    .nav-circle-btn {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: rgba(93, 126, 90, 0.08) !important;
        border: 1px solid rgba(93, 126, 90, 0.2) !important;
        color: #4b6b47 !important;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        outline: none;
    }

    .nav-circle-btn:hover:not(.disabled) {
        background: rgba(93, 126, 90, 0.15) !important;
        border-color: rgba(93, 126, 90, 0.3) !important;
        color: #2e2b27 !important;
        transform: scale(1.05);
    }

    .nav-circle-btn:active:not(.disabled) {
        transform: scale(0.95);
    }

    .nav-circle-btn.disabled {
        opacity: 0.15;
        cursor: not-allowed;
        color: #9ca3af !important;
        background: rgba(0, 0, 0, 0.02) !important;
        border-color: rgba(0, 0, 0, 0.05) !important;
    }

    .slide-badge-pill {
        background: rgba(0, 0, 0, 0.03) !important;
        border: 1px solid rgba(180, 160, 140, 0.2) !important;
        border-radius: 999px;
        padding: 8px 18px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 700;
        color: #2e2b27 !important;
    }

    .slide-badge-pill i {
        color: #5d7e5a !important;
        font-size: 11px;
    }

    /* FOOTER ACTION BUTTON */
    .app-footer-action {
        width: 100% !important;
        box-sizing: border-box !important;
        flex-shrink: 0 !important;
    }

    .footer-action-btn {
        width: 100%;
        background: linear-gradient(135deg, #7f9e7a, #5d7e5a) !important;
        border: none;
        border-radius: 16px;
        padding: 16px;
        color: #ffffff !important;
        font-size: 14px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(93, 126, 90, 0.2) !important;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        outline: none;
    }

    .footer-action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(93, 126, 90, 0.3) !important;
    }

    .footer-action-btn:active {
        transform: scale(0.98);
    }

    .footer-action-btn.btn-live-active {
        background: linear-gradient(135deg, #c9a86c, #a8874a) !important;
        color: #ffffff !important;
        border: 1px solid rgba(255,255,255,0.3) !important;
        box-shadow: 0 4px 14px rgba(201, 168, 108, 0.3) !important;
    }

    /* STOPWATCH TIMER STYLING */
    .card-timer-section {
        width: 100%;
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px dashed rgba(180, 160, 140, 0.2) !important;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .timer-badge {
        font-size: 9px;
        font-weight: 800;
        color: #6b6258 !important;
        background: rgba(0, 0, 0, 0.04) !important;
        padding: 3px 8px;
        border-radius: 4px;
        letter-spacing: 0.05em;
        margin-bottom: 6px;
    }

    .timer-clock {
        font-size: 28px;
        font-weight: 800;
        font-family: monospace;
        color: #3a5e44 !important;
        text-shadow: none !important;
        margin-bottom: 10px;
    }

    .timer-clock.is-running {
        color: #dc2626 !important;
        text-shadow: none !important;
    }

    .timer-actions {
        display: flex;
        gap: 8px;
    }

    .timer-btn {
        padding: 6px 12px;
        border-radius: 6px;
        border: none;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s ease;
    }

    .timer-btn.btn-start {
        background: rgba(93, 126, 90, 0.1) !important;
        border: 1px solid rgba(93, 126, 90, 0.25) !important;
        color: #4b6b47 !important;
    }

    .timer-btn.btn-stop {
        background: rgba(220, 38, 38, 0.1) !important;
        border: 1px solid rgba(220, 38, 38, 0.25) !important;
        color: #dc2626 !important;
    }

    .timer-btn.btn-reset {
        background: rgba(0, 0, 0, 0.05) !important;
        color: #6b6258 !important;
    }

    .toast-notify {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: #5d7e5a !important;
        color: #fff;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 800;
        font-size: 14px;
        box-shadow: 0 8px 30px rgba(140, 120, 100, 0.2) !important;
        z-index: 999999;
        display: none;
        align-items: center;
        gap: 8px;
        backdrop-filter: blur(8px);
        width: max-content;
        max-width: 90vw;
    }

    /* ============================================================
       MOBILE RESPONSIVE — PORTRAIT MODE
       ============================================================ */

    /* Small phones (iPhone SE, Galaxy S, 320–374px) */
    @media screen and (max-width: 374px) and (orientation: portrait) {
        .main-content {
            padding: 10px !important;
            max-width: 100vw !important;
        }
        .app-header {
            padding: 2px 2px 8px 2px !important;
            margin-bottom: 10px !important;
        }
        .header-title {
            font-size: 12px !important;
            max-width: 100px !important;
        }
        .header-status {
            padding: 4px 8px !important;
        }
        .status-label {
            font-size: 9px !important;
        }
        .grid-btn {
            width: 32px !important;
            height: 32px !important;
            font-size: 13px !important;
            border-radius: 8px !important;
        }
        .slide-card {
            padding: 18px 14px !important;
            border-radius: 20px !important;
        }
        .card-subtitle {
            font-size: 9px !important;
            margin-bottom: 12px !important;
        }
        .card-icon-circle {
            width: 48px !important;
            height: 48px !important;
            font-size: 18px !important;
        }
        .card-icon-wrap {
            margin-bottom: 12px !important;
        }
        .card-main-title {
            font-size: 20px !important;
            margin-bottom: 8px !important;
        }
        .card-slot-time {
            font-size: 12px !important;
            margin-bottom: 8px !important;
        }
        .neon-chest {
            font-size: 26px !important;
        }
        .participant-name {
            font-size: 14px !important;
        }
        .team-badge {
            font-size: 10px !important;
            padding: 3px 10px !important;
        }
        .nav-circle-btn {
            width: 40px !important;
            height: 40px !important;
            font-size: 14px !important;
        }
        .slide-badge-pill {
            padding: 6px 12px !important;
            font-size: 11px !important;
        }
        .footer-action-btn {
            padding: 12px !important;
            font-size: 12px !important;
            border-radius: 12px !important;
        }
        .timer-clock {
            font-size: 22px !important;
        }
        .card-timer-section {
            margin-top: 12px !important;
            padding-top: 10px !important;
        }
    }

    /* Standard phones (375–430px) — mostly covered by defaults */
    @media screen and (min-width: 375px) and (max-width: 430px) and (orientation: portrait) {
        .main-content {
            padding: 14px !important;
            max-width: 100vw !important;
        }
    }

    /* Tall phones with short viewport height portrait (e.g. long scroll) */
    @media screen and (max-height: 640px) and (orientation: portrait) {
        .card-icon-circle {
            width: 48px !important;
            height: 48px !important;
            font-size: 18px !important;
        }
        .card-icon-wrap {
            margin-bottom: 10px !important;
        }
        .card-main-title {
            font-size: 22px !important;
            margin-bottom: 6px !important;
        }
        .card-subtitle {
            margin-bottom: 10px !important;
        }
        .card-timer-section {
            margin-top: 10px !important;
            padding-top: 8px !important;
        }
        .timer-clock {
            font-size: 22px !important;
            margin-bottom: 6px !important;
        }
        .app-header {
            padding: 2px 4px 8px 4px !important;
            margin-bottom: 8px !important;
        }
        .app-controls {
            margin-bottom: 10px !important;
        }
    }

    /* ============================================================
       LANDSCAPE MODE
       Reset body/admin-layout flex so main-content fills full width
       ============================================================ */

    @media screen and (orientation: landscape) and (max-height: 500px) {

        /* Step 1: Kill the body flex-center that causes the black gap */
        html {
            overflow: hidden !important;
        }
        body {
            display: block !important;
            width: 100vw !important;
            height: 100svh !important;
            overflow: hidden !important;
        }
        .admin-layout {
            display: block !important;
            width: 100vw !important;
            height: 100svh !important;
            overflow: hidden !important;
        }

        /*
         * Step 2: main-content fills the full screen with CSS grid.
         *
         *   ┌───────────────────────────────────┬──────┐
         *   │ header                            │header│  auto
         *   ├───────────────────────────────────┼──────┤
         *   │ card                              │  nav │  1fr
         *   ├───────────────────────────────────┴──────┤
         *   │ footer                                   │  auto
         *   └──────────────────────────────────────────┘
         */
        .main-content {
            position: relative !important;
            width: 100vw !important;
            max-width: 100vw !important;
            height: 100svh !important;
            margin: 0 !important;
            padding: 8px 12px !important;
            border: none !important;
            box-shadow: none !important;
            overflow: hidden !important;
            box-sizing: border-box !important;

            display: grid !important;
            grid-template-rows: auto 1fr auto !important;
            grid-template-columns: 1fr 50px !important;
            grid-template-areas:
                "header  header"
                "card    nav"
                "footer  footer" !important;
            gap: 4px 8px !important;
        }

        /* ── Header ── */
        .app-header {
            grid-area: header !important;
            padding: 0 0 4px 0 !important;
            margin-bottom: 0 !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
        }
        .header-title {
            font-size: 12px !important;
            max-width: 240px !important;
        }
        .header-status { padding: 3px 8px !important; }
        .status-label { font-size: 9px !important; }
        .status-dot { width: 5px !important; height: 5px !important; }
        .grid-btn {
            width: 28px !important;
            height: 28px !important;
            font-size: 12px !important;
            border-radius: 7px !important;
        }

        /* ── Slide Card ── */
        .slide-card-container {
            grid-area: card !important;
            padding: 0 !important;
            min-height: 0 !important;
            display: flex !important;
            align-items: stretch !important;
        }
        .slide-card {
            width: 100% !important;
            padding: 8px 16px !important;
            border-radius: 12px !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0 !important;
            text-align: center !important;
            overflow: hidden !important;
        }

        /* Hide decorative / low-priority elements */
        .card-subtitle        { display: none !important; }
        .card-icon-wrap       { display: none !important; }
        #textChestHeader      { display: none !important; }
        #textParticipantProgress { display: none !important; }
        #timerSubtext         { display: none !important; }

        .card-main-title {
            font-size: 16px !important;
            margin: 0 0 2px 0 !important;
            line-height: 1.1 !important;
        }
        .card-slot-time {
            font-size: 10px !important;
            margin-bottom: 3px !important;
        }
        .neon-chest {
            font-size: 24px !important;
            margin-bottom: 2px !important;
        }
        .participant-name {
            font-size: 13px !important;
            margin: 0 0 3px 0 !important;
        }
        .team-badge {
            font-size: 10px !important;
            padding: 2px 10px !important;
        }
        .badge {
            font-size: 8px !important;
            padding: 2px 6px !important;
        }

        /* Timer — horizontal inline row */
        .card-timer-section {
            margin-top: 6px !important;
            padding-top: 6px !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            border-top: 1px dashed rgba(255,255,255,0.07) !important;
        }
        .timer-badge {
            font-size: 7px !important;
            margin-bottom: 0 !important;
            padding: 2px 4px !important;
            white-space: nowrap !important;
        }
        .timer-clock {
            font-size: 18px !important;
            margin-bottom: 0 !important;
        }
        .timer-actions { gap: 5px !important; }
        .timer-btn {
            padding: 3px 7px !important;
            font-size: 10px !important;
        }

        /* ── Nav column (prev / counter / next) ── */
        .app-controls {
            grid-area: nav !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 5px !important;
            margin-bottom: 0 !important;
            width: 50px !important;
            padding: 0 !important;
        }
        .nav-circle-btn {
            width: 36px !important;
            height: 36px !important;
            font-size: 13px !important;
        }
        .slide-badge-pill {
            padding: 3px 5px !important;
            font-size: 8px !important;
            flex-direction: column !important;
            gap: 0 !important;
            text-align: center !important;
            line-height: 1.25 !important;
        }
        .slide-badge-pill i { display: none !important; }

        /* ── Footer broadcast button ── */
        .app-footer-action {
            grid-area: footer !important;
            width: 100% !important;
        }
        .footer-action-btn {
            padding: 8px !important;
            font-size: 12px !important;
            border-radius: 10px !important;
        }

        .toast-notify {
            bottom: 10px !important;
            padding: 8px 18px !important;
            font-size: 11px !important;
        }
    }

    /* Very short landscape — height ≤ 380px (iPhone SE, Galaxy S) */
    @media screen and (orientation: landscape) and (max-height: 380px) {
        .main-content {
            padding: 5px 10px !important;
            gap: 3px 6px !important;
            grid-template-columns: 1fr 44px !important;
        }
        .slide-card { padding: 5px 10px !important; border-radius: 10px !important; }
        .card-main-title { font-size: 14px !important; }
        .neon-chest { font-size: 20px !important; }
        .participant-name { font-size: 11px !important; }
        .timer-clock { font-size: 15px !important; }
        .timer-badge { display: none !important; }
        .timer-btn { padding: 2px 5px !important; font-size: 8px !important; }
        .nav-circle-btn { width: 30px !important; height: 30px !important; font-size: 11px !important; }
        .app-controls { width: 44px !important; gap: 3px !important; }
        .footer-action-btn { padding: 6px !important; font-size: 10px !important; }
        .header-title { font-size: 11px !important; }
        .slide-badge-pill { font-size: 7px !important; }
    }

    /* ============================================================
       TABLET — PORTRAIT & LANDSCAPE
       ============================================================ */


    /* Tablet portrait (e.g. iPad 768–1024px) */
    @media screen and (min-width: 441px) and (max-width: 1024px) and (orientation: portrait) {
        .main-content {
            max-width: 520px !important;
        }
        .slide-card {
            padding: 32px 28px !important;
        }
        .card-main-title {
            font-size: 32px !important;
        }
        .neon-chest {
            font-size: 42px !important;
        }
        .card-icon-circle {
            width: 76px !important;
            height: 76px !important;
            font-size: 26px !important;
        }
        .nav-circle-btn {
            width: 52px !important;
            height: 52px !important;
        }
        .footer-action-btn {
            padding: 18px !important;
            font-size: 15px !important;
        }
    }

    /* Tablet landscape */
    @media screen and (min-width: 768px) and (orientation: landscape) and (min-height: 501px) {
        .main-content {
            max-width: 600px !important;
        }
        .slide-card {
            padding: 28px 24px !important;
        }
    }

    /* ============================================================
       SAFE AREA INSETS (notched phones — iPhone X+, etc.)
       ============================================================ */
    @supports (padding: env(safe-area-inset-top)) {
        .main-content {
            padding-top: max(16px, env(safe-area-inset-top)) !important;
            padding-bottom: max(16px, env(safe-area-inset-bottom)) !important;
        }
        .toast-notify {
            bottom: max(24px, env(safe-area-inset-bottom)) !important;
        }
        .footer-action-btn {
            margin-bottom: env(safe-area-inset-bottom) !important;
        }
    }

    /* ============================================================
       CARDS GRID MODAL — RESPONSIVE
       ============================================================ */
    @media screen and (max-width: 600px) {
        #cardsGridContainer {
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)) !important;
            gap: 10px !important;
        }
    }

    @media screen and (orientation: landscape) and (max-height: 500px) {
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
        <div class="header-status">
            <span class="status-dot"></span>
            <span class="status-label">ON STAGE</span>
        </div>
        <div class="header-title">Emcee Master Stage Deck</div>
        <button type="button" class="grid-btn" onclick="openCardsModal()" title="View Stage Cards Grid">
            <i class="fa-solid fa-border-all"></i>
        </button>
    </header>

    <!-- Live Toast Notification -->
    <div id="toastNotification" class="toast-notify">
        <i class="fa-solid fa-circle-check"></i> <span id="toastText">Live Stage Updated!</span>
    </div>

    <!-- Active Performer Card -->
    <div class="slide-card-container">
        <div id="chestBox" class="slide-card" onclick="handleSlideClick()" title="Click to Broadcast Live to Stage">
            <!-- Mode badge (e.g. PROGRAM START SLIDE) -->
            <div class="card-subtitle" id="textSlideMode">PROGRAM START SLIDE</div>
            
            <!-- Circle Icon -->
            <div class="card-icon-wrap">
                <div class="card-icon-circle">
                    <i class="fa-solid fa-podium" id="cardIcon"></i>
                </div>
            </div>

            <!-- Program Title -->
            <h1 class="card-main-title" id="textProgramTitle">--</h1>
            
            <!-- Time / Category Slot -->
            <div class="card-slot-time">
                <span id="badgeClassType">--</span>
                <span id="badgeSection" style="display: none;"> - <span id="textSection">--</span></span>
            </div>
            
            <!-- Participant & Progress Details -->
            <div class="card-description">
                <div id="participantDetailsArea" style="margin-top: 8px;">
                    <span id="textChestHeader" style="font-size: 11px; text-transform: uppercase; opacity: 0.6; display: block; margin-bottom: 2px;">CHEST NUMBER</span>
                    <span id="textChestNumber" class="neon-chest">--</span>
                    <h3 id="textParticipantName" class="participant-name">--</h3>
                    <span id="pillTeam" class="team-badge">--</span>
                </div>
                <div id="textParticipantProgress" style="font-size: 11px; opacity: 0.5; margin-top: 10px;">--</div>
            </div>
            
            <!-- Broadcast Status Indicator -->
            <div style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between;">
                <span id="badgeStatus" class="badge">--</span>
                
                <!-- Toggle Next Participant Button -->
                <button type="button" id="btnToggleNextParticipant" class="timer-btn" onclick="toggleNextParticipant()" data-enabled="<?= $showNext ? '1' : '0' ?>" style="background: <?= $showNext ? 'rgba(16,185,129,0.2)' : 'rgba(239,68,68,0.2)' ?>; border: 1px solid <?= $showNext ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)' ?>; color: #fff; padding: 5px 10px; border-radius: 6px; font-size: 10.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s ease;">
                    <i class="fa-solid <?= $showNext ? 'fa-eye' : 'fa-eye-slash' ?>" id="toggleNextIcon"></i>
                    <span id="btnToggleNextParticipantText"><?= $showNext ? 'Next Performer: Visible' : 'Next Performer: Hidden' ?></span>
                </button>
            </div>

            <!-- Stopwatch Timer inside the card -->
            <div class="card-timer-section" id="timerPanel">
                <span class="timer-badge" id="timerStatusBadge">READY TO RECORD</span>
                <div class="timer-clock" id="timerClock" onclick="toggleTimerFromMobile()" title="Click to Start/Stop timer">00:00</div>
                <div class="timer-actions">
                    <button type="button" id="btnStartTimer" class="timer-btn btn-start" onclick="event.stopPropagation(); startTimer();">
                        <i class="fa-solid fa-play"></i> Start
                    </button>
                    <button type="button" id="btnStopTimer" class="timer-btn btn-stop" onclick="event.stopPropagation(); stopTimer();" style="display:none;">
                        <i class="fa-solid fa-stop"></i> Stop
                    </button>
                    <button type="button" id="btnResetTimer" class="timer-btn btn-reset" onclick="event.stopPropagation(); resetTimer();" style="display:none;">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </button>
                </div>
                <div id="timerSubtext" style="font-size: 9px; opacity: 0.4; margin-top: 6px;">Stopwatch for performance duration.</div>
            </div>
        </div>
    </div>

    <!-- Controls Pagination -->
    <div class="app-controls">
        <button type="button" id="btnPrev" class="nav-circle-btn" onclick="navigateStage(-1)" title="Previous Slide">
            <i class="fa-solid fa-arrow-left"></i>
        </button>
        
        <div class="slide-badge-pill">
            <i class="fa-solid fa-play"></i>
            <span id="textSlideBadge">Slide 3 of 12</span>
        </div>

        <button type="button" id="btnNext" class="nav-circle-btn" onclick="navigateStage(1)" title="Next Slide">
            <i class="fa-solid fa-arrow-right"></i>
        </button>
    </div>

    <!-- Footer Action Broadcast -->
    <div class="app-footer-action">
        <button type="button" id="btnSelect" class="footer-action-btn" onclick="broadcastLiveStage()">
            <i class="fa-solid fa-arrows-rotate"></i>
            <span id="btnActionText">Update Program & Participants</span>
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
                <div style="font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px;">Click any card to preview &amp; broadcast live on stage</div>
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

// TIMER STATE VARIABLES
let timerInterval = null;
let timerElapsedTime = <?= $initTimerElapsed ?>;
let isTimerRunning = <?= $initTimerRunning ? 'true' : 'false' ?>;
let timerStartTime = 0;

if (isTimerRunning) {
    const elapsedSinceStart = Date.now() - (<?= $initTimerStartTime ?> * 1000);
    timerStartTime = performance.now() - elapsedSinceStart;
    timerInterval = setInterval(() => {
        timerElapsedTime = performance.now() - timerStartTime;
        const clockEl = document.getElementById('timerClock');
        if (clockEl) clockEl.innerText = formatTimeMs(timerElapsedTime);
    }, 90);
}

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

    // Slide Counter
    document.getElementById('textSlideBadge').innerText = `SLIDE ${currentIndex + 1} / ${stageQueue.length}`;

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
    const cardIcon = document.getElementById('cardIcon');

    if (item.is_intro) {
        document.getElementById('textParticipantProgress').innerHTML = 
            `Program Intro Slide · <strong>${item.total_participants} ${item.is_group ? 'Teams' : 'Participants'} Registered</strong>`;
        textChestHeader.innerText = 'STAGE DISPLAY SLIDE';
        textChestNum.innerText = item.is_group ? '👥 GROUP INTRO' : '📋 PROGRAM INTRO';
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '24px' : '32px';
        textPartName.innerText = item.entry_name;
        textSlideMode.innerText = 'PROGRAM START SLIDE';
        if (cardIcon) cardIcon.className = 'fa-solid fa-podium';
        
        pillTeam.innerText = item.is_group ? 'Group Team Program' : 'Program Overview Mode';
        pillTeam.style.background = 'rgba(16, 185, 129, 0.2)';
        pillTeam.style.borderColor = 'rgba(16, 185, 129, 0.4)';
        pillTeam.style.color = '#34d399';
        pillTeam.style.display = 'inline-block';
    } else if (item.is_group) {
        // Group Program
        document.getElementById('textParticipantProgress').innerHTML = 
            `Team <strong>${item.participant_order}</strong> of <strong>${item.total_participants}</strong>`;
        textChestHeader.innerText = 'GROUP NAME';
        textChestNum.innerText = item.chest_number;
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '26px' : '38px';
        textPartName.innerText = 'Team Performance';
        textSlideMode.innerText = 'GROUP PERFORMANCE';
        if (cardIcon) cardIcon.className = 'fa-solid fa-users';
        
        pillTeam.innerText = item.team_name;
        pillTeam.style.background = item.team_color + '22';
        pillTeam.style.borderColor = item.team_color + '44';
        pillTeam.style.color = '#fff';
        pillTeam.style.display = 'inline-block';
    } else if (item.has_entries) {
        // Individual Program
        document.getElementById('textParticipantProgress').innerHTML = 
            `Participant <strong>${item.participant_order}</strong> of <strong>${item.total_participants}</strong>`;
        textChestHeader.innerText = 'CHEST NUMBER';
        textChestNum.innerText = item.chest_number;
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '32px' : '52px';
        textPartName.innerText = item.entry_name;
        textSlideMode.innerText = 'PARTICIPANT SLIDE';
        if (cardIcon) cardIcon.className = 'fa-solid fa-microphone-lines';
        
        pillTeam.innerText = item.team_name;
        pillTeam.style.background = item.team_color + '22';
        pillTeam.style.borderColor = item.team_color + '44';
        pillTeam.style.color = '#fff';
        pillTeam.style.display = 'inline-block';
    } else {
        document.getElementById('textParticipantProgress').innerText = 'No entries registered for this program';
        textChestHeader.innerText = 'CHEST NUMBER';
        textChestNum.innerText = '-';
        textChestNum.style.fontSize = '52px';
        textPartName.innerText = 'No Participants Registered';
        textSlideMode.innerText = 'EMPTY INTRO';
        if (cardIcon) cardIcon.className = 'fa-solid fa-circle-info';
        pillTeam.style.display = 'none';
    }

    // Is Live State Comparison
    const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);

    const badgeStatus = document.getElementById('badgeStatus');
    const btnSelect = document.getElementById('btnSelect');
    const btnMobileActivate = document.getElementById('btnMobileActivate');

    if (isLiveItem) {
        badgeStatus.className = 'badge badge-success';
        badgeStatus.innerText = '🔴 Live On Stage';
        chestBox.classList.add('is-live-box');

        btnSelect.className = 'footer-action-btn btn-live-active';
        btnSelect.innerHTML = '<i class="fa-solid fa-circle-check"></i> LIVE ON STAGE';
            
        if (btnMobileActivate) {
            btnMobileActivate.className = 'btn btn-success';
            btnMobileActivate.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i> LIVE ON STAGE';
        }
    } else {
        badgeStatus.className = 'badge badge-neutral';
        badgeStatus.innerText = 'Previewing Slide';
        badgeStatus.style.background = 'rgba(255,255,255,0.05)';
        badgeStatus.style.border = '1px solid rgba(255,255,255,0.1)';
        badgeStatus.style.color = '#aaa';
        chestBox.classList.remove('is-live-box');

        btnSelect.className = 'footer-action-btn';
        btnSelect.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Update Program & Participants';

        if (btnMobileActivate) {
            btnMobileActivate.className = 'btn btn-success';
            btnMobileActivate.innerHTML = '⚡ ACTIVATE ON STAGE';
        }
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

    // Sync Dropdown
    const selectDropdown = document.getElementById('selectStageJump');
    if (selectDropdown) {
        selectDropdown.value = currentIndex;
    }

    renderUpcomingRibbon();
}

function handleSlideClick() {
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
    const textMobileTimer = document.getElementById('textMobileTimer');

    if (isLiveItem) {
        timerPanel.classList.add('is-active-stage');
    } else {
        timerPanel.classList.remove('is-active-stage');
    }

    if (isTimerRunning) {
        // Active Running Timer
        timerStatusBadge.style.background = 'rgba(239, 68, 68, 0.25)';
        timerStatusBadge.style.color = '#f87171';
        timerStatusBadge.style.border = '1px solid rgba(239, 68, 68, 0.5)';
        timerStatusBadge.innerHTML = '<i class="fa-solid fa-circle-dot mr-1"></i> RECORDING TIME';
        
        timerClock.classList.add('is-running');
        timerSubtext.innerText = 'Stopwatch actively recording performance time...';

        btnStart.style.display = 'none';
        btnStop.style.display = 'inline-flex';
        btnReset.style.display = 'none';

        if (textMobileTimer) textMobileTimer.innerText = 'STOP TIMER';
        return;
    }

    timerClock.classList.remove('is-running');

    if (item.recorded_time) {
        // Has Saved Recorded Time
        timerStatusBadge.style.background = 'rgba(16, 185, 129, 0.25)';
        timerStatusBadge.style.color = '#34d399';
        timerStatusBadge.style.border = '1px solid rgba(16, 185, 129, 0.5)';
        timerStatusBadge.innerHTML = '<i class="fa-solid fa-check mr-1"></i> TIME RECORDED';

        timerClock.innerText = item.recorded_time;
        timerSubtext.innerHTML = `Saved Duration: <strong>${item.recorded_time}</strong> (${item.duration_seconds}s)`;

        btnStart.style.display = 'inline-flex';
        btnStart.innerHTML = '<i class="fa-solid fa-play"></i> RE-RECORD TIME';
        btnStop.style.display = 'none';
        btnReset.style.display = 'inline-block';

        if (textMobileTimer) textMobileTimer.innerText = 'RECORDED';
    } else {
        // No Time Recorded Yet
        timerStatusBadge.style.background = 'rgba(255, 255, 255, 0.1)';
        timerStatusBadge.style.color = '#ccc';
        timerStatusBadge.style.border = '1px solid rgba(255, 255, 255, 0.15)';
        timerStatusBadge.innerText = isLiveItem ? 'READY TO RECORD' : 'PREVIEW MODE';

        timerClock.innerText = formatTimeMs(timerElapsedTime);
        
        if (isLiveItem) {
            timerSubtext.innerText = 'Participant active on stage. Click Start Timer when performance begins.';
            btnStart.style.display = 'inline-flex';
            btnStart.innerHTML = '<i class="fa-solid fa-play"></i> START TIMER';
            btnStop.style.display = 'none';
            btnReset.style.display = (timerElapsedTime > 0) ? 'inline-block' : 'none';
            if (textMobileTimer) textMobileTimer.innerText = 'START TIMER';
        } else {
            timerSubtext.innerText = 'Activate participant on stage to start recording time.';
            btnStart.style.display = 'inline-flex';
            btnStart.innerHTML = '<i class="fa-solid fa-bolt"></i> ACTIVATE TO RECORD';
            btnStop.style.display = 'none';
            btnReset.style.display = 'none';
            if (textMobileTimer) textMobileTimer.innerText = 'TIMER';
        }
    }
}

// TIMER ENGINE FUNCTIONS
function startTimer() {
    const item = stageQueue[currentIndex];
    const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);

    if (!isLiveItem) {
        broadcastLiveStage();
    }

    if (isTimerRunning) return;

    isTimerRunning = true;
    timerStartTime = performance.now() - timerElapsedTime;

    // Send update to backend settings
    const nowUnix = Date.now() / 1000;
    const elapsedSec = timerElapsedTime / 1000;
    const startUnix = nowUnix - elapsedSec;
    updateLiveTimerBackend(1, startUnix, timerElapsedTime);

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

    // Send update to backend settings
    updateLiveTimerBackend(0, 0, timerElapsedTime);

    // Save to backend via AJAX
    saveRecordedTimeBackend(item.program_id, item.entry_id, durationSec, formatted);

    syncTimerUI(item, true);
    showToast(`Recorded time: ${formatted} saved!`);
}

function resetTimer() {
    if (isTimerRunning) {
        clearInterval(timerInterval);
        timerInterval = null;
        isTimerRunning = false;
    }
    timerElapsedTime = 0;
    const item = stageQueue[currentIndex];
    item.recorded_time = null;
    item.duration_seconds = 0;

    // Send update to backend settings
    updateLiveTimerBackend(0, 0, 0);

    // Save to backend via AJAX (deletes/clears the recorded time data)
    saveRecordedTimeBackend(item.program_id, item.entry_id, 0, '');

    syncTimerUI(item, (item.program_id === liveProgramId && item.entry_id === liveEntryId));
    showToast("Recorded time deleted!");
}

function updateLiveTimerBackend(running, startTime, elapsed) {
    const formData = new FormData();
    formData.append('action', 'update_live_timer');
    formData.append('running', running);
    formData.append('start_time', startTime);
    formData.append('elapsed', elapsed);
    formData.append('csrf_token', csrfToken);

    fetch(window.location.pathname + '?ajax=1', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .catch(err => console.error('Failed to sync live timer:', err));
}

function toggleNextParticipant() {
    const btn = document.getElementById('btnToggleNextParticipant');
    if (!btn) return;

    const currentEnabled = btn.dataset.enabled === '1';
    const nextEnabled = !currentEnabled;
    const nextVal = nextEnabled ? 1 : 0;

    const formData = new FormData();
    formData.append('action', 'toggle_next_participant');
    formData.append('enabled', nextVal);
    formData.append('csrf_token', csrfToken);

    fetch(window.location.pathname + '?ajax=1', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            btn.dataset.enabled = nextVal;
            const icon = document.getElementById('toggleNextIcon');
            const text = document.getElementById('btnToggleNextParticipantText');
            
            if (nextEnabled) {
                btn.style.background = 'rgba(16,185,129,0.2)';
                btn.style.borderColor = 'rgba(16,185,129,0.4)';
                if (icon) {
                    icon.className = 'fa-solid fa-eye';
                }
                if (text) {
                    text.innerText = 'Next Performer: Visible';
                }
                showToast("Next Performer enabled on TV display");
            } else {
                btn.style.background = 'rgba(239,68,68,0.2)';
                btn.style.borderColor = 'rgba(239,68,68,0.4)';
                if (icon) {
                    icon.className = 'fa-solid fa-eye-slash';
                }
                if (text) {
                    text.innerText = 'Next Performer: Hidden';
                }
                showToast("Next Performer disabled on TV display");
            }
        }
    })
    .catch(err => {
        console.error('Failed to toggle next participant:', err);
        showToast("Error updating TV settings");
    });
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

function renderUpcomingRibbon() {
    const upcomingContainer = document.getElementById('upcomingRibbon');
    if (!upcomingContainer || !stageQueue) return;

    upcomingContainer.innerHTML = '';
    const nextItems = stageQueue.slice(currentIndex + 1, currentIndex + 4);

    if (nextItems.length === 0) {
        upcomingContainer.innerHTML = '<div style="font-size: 11.5px; color: rgba(255,255,255,0.4); padding: 6px;">End of Stage Queue</div>';
        return;
    }

    nextItems.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'next-ribbon-card';
        card.onclick = () => selectCardIndex(item.queue_index, true);

        let titleText = item.is_intro ? ('📋 INTRO: ' + item.program_title) : (item.is_group ? item.team_name : ('Chest #' + item.chest_number + ' (' + item.entry_name + ')'));
        
        let timerBadge = item.recorded_time ? `<span style="font-size: 9px; color: #34d399; border: 1px solid rgba(16,185,129,0.4); padding: 1px 4px; border-radius: 4px; margin-left: 4px;">⏱ ${item.recorded_time}</span>` : '';

        card.innerHTML = `
            <div style="font-size: 9.5px; font-weight: 800; color: #34d399; text-transform: uppercase; margin-bottom: 2px;">
                Slide #${item.queue_index + 1} ${timerBadge}
            </div>
            <div style="font-size: 11.5px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">${escapeHtml(titleText)}</div>
            <div style="font-size: 9px; font-weight: 700; color: rgba(52,211,153,0.8); margin-top: 3px; text-transform: uppercase;">Click to Activate</div>
        `;
        upcomingContainer.appendChild(card);
    });
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
    renderCardsGrid();
}

function closeCardsModal() {
    document.getElementById('cardsModal').style.display = 'none';
}

function renderCardsGrid(filterText = '') {
    const container = document.getElementById('cardsGridContainer');
    if (!container || !stageQueue) return;

    container.innerHTML = '';
    const search = filterText.toLowerCase().trim();

    stageQueue.forEach(item => {
        if (search) {
            const matchText = (item.program_title + ' ' + item.chest_number + ' ' + item.entry_name + ' ' + item.team_name + ' ' + item.class_type_name).toLowerCase();
            if (!matchText.includes(search)) return;
        }

        const isLive = (item.program_id === liveProgramId && item.entry_id === liveEntryId);
        const card = document.createElement('div');
        card.style.cssText = `
            background: ${isLive ? 'linear-gradient(135deg, rgba(16, 185, 129, 0.35) 0%, rgba(0, 0, 0, 0.95) 100%)' : 'linear-gradient(135deg, rgba(6, 40, 22, 0.6) 0%, rgba(0, 0, 0, 0.88) 100%)'};
            border: ${isLive ? '2px solid #34d399' : '1px solid rgba(16, 185, 129, 0.25)'};
            border-radius: 16px;
            padding: 14px;
            text-align: center;
            position: relative;
            box-shadow: ${isLive ? '0 10px 30px rgba(16, 185, 129, 0.35)' : '0 8px 24px rgba(0,0,0,0.6)'};
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
                card.style.borderColor = '#34d399';
                card.style.boxShadow = '0 12px 30px rgba(16, 185, 129, 0.3)';
            }
        };
        card.onmouseout = () => {
            card.style.transform = 'translateY(0)';
            if (!isLive) {
                card.style.borderColor = 'rgba(16, 185, 129, 0.25)';
                card.style.boxShadow = '0 8px 24px rgba(0,0,0,0.6)';
            }
        };

        let badgeHtml = '';
        if (isLive) {
            badgeHtml = '<span class="badge badge-success" style="font-size: 9px; margin-bottom: 4px;">🔴 ON STAGE LIVE</span>';
        } else if (item.is_intro) {
            badgeHtml = '<span class="badge badge-info" style="font-size: 9px; margin-bottom: 4px; background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">📋 INTRO</span>';
        } else if (item.is_group) {
            badgeHtml = '<span class="badge badge-warning" style="font-size: 9px; margin-bottom: 4px; background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3);">👥 TEAM</span>';
        } else {
            badgeHtml = '<span class="badge badge-neutral" style="font-size: 9px; margin-bottom: 4px; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.15); color: #aaa;">#' + escapeHtml(item.chest_number) + '</span>';
        }

        if (item.recorded_time) {
            badgeHtml += ` <span style="font-size: 9px; background: rgba(16,185,129,0.25); color: #34d399; border: 1px solid rgba(16,185,129,0.4); padding: 1px 5px; border-radius: 4px;">⏱ ${escapeHtml(item.recorded_time)}</span>`;
        }

        let mainDisplay = '';
        if (item.is_intro) {
            mainDisplay = `<div style="font-size: 16px; font-weight: 800; color: #34d399; margin: 4px 0;">📋 PROGRAM INTRO</div>`;
        } else if (item.is_group) {
            mainDisplay = `<div style="font-size: 20px; font-weight: 900; color: #fbbf24; margin: 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(item.team_name)}</div>`;
        } else {
            mainDisplay = `<div style="font-size: 26px; font-weight: 900; color: #34d399; margin: 2px 0;">#${escapeHtml(item.chest_number)}</div>`;
        }

        let subDisplay = item.is_group ? escapeHtml(item.team_name) : escapeHtml(item.entry_name);

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
                Click to Activate
            </div>
        `;

        card.onclick = () => {
            selectCardIndex(item.queue_index, true);
        };

        container.appendChild(card);
    });
}

function selectCardIndex(idx, broadcastNow = true) {
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

function jumpToQueueIndex(idx) {
    selectCardIndex(idx, true);
}

function showToast(message) {
    const toast = document.getElementById('toastNotification');
    document.getElementById('toastText').innerText = message;
    toast.style.display = 'flex';
    setTimeout(() => {
        toast.style.display = 'none';
    }, 2500);
}

function broadcastLiveStage() {
    if (!stageQueue || !stageQueue[currentIndex]) return;
    const item = stageQueue[currentIndex];

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

// Touch Swipe Gesture Handling for Mobile
let touchStartX = 0;
let touchStartY = 0;
let touchEndX = 0;
let touchEndY = 0;

const deckElement = document.querySelector('.slide-card-container');

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
    
    // Horizontal swipe threshold > 40px and dominant over vertical scroll
    if (Math.abs(deltaX) > 40 && Math.abs(deltaX) > Math.abs(deltaY) * 1.2) {
        if (deltaX < 0) {
            // Swiped Left -> Advance to Next Slide
            navigateStage(1);
        } else {
            // Swiped Right -> Back to Previous Slide
            navigateStage(-1);
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

document.addEventListener('DOMContentLoaded', () => {
    populateJumpDropdown();
    renderStageDeck(currentIndex, 'next');
});
</script>

<?php
admin_close_page();
