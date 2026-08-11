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

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* SCROLL-PROOF FULL VIEWPORT CONTAINER */
    html, body {
        height: 100vh !important;
        overflow: hidden !important;
        background: #020804 !important;
        background-image: 
            radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.15) 0%, transparent 65%),
            radial-gradient(circle at 10% 90%, rgba(6, 78, 42, 0.2) 0%, transparent 50%),
            radial-gradient(circle at 90% 90%, rgba(6, 78, 42, 0.2) 0%, transparent 50%),
            linear-gradient(to bottom, rgba(0, 0, 0, 0.85), #020804) !important;
        background-attachment: fixed !important;
        padding-left: 0 !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
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

    .main-content {
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 1200px !important;
        height: 100vh !important;
        padding: 14px 16px 14px 16px !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        box-sizing: border-box !important;
        overflow-y: auto !important;
        scrollbar-width: thin;
        scrollbar-color: rgba(16,185,129,0.3) transparent;
    }

    /* TOP STUDIO BAR */
    .studio-navbar {
        width: 100% !important;
        background: rgba(5, 25, 14, 0.85) !important;
        border: 1px solid rgba(16, 185, 129, 0.3) !important;
        border-radius: 18px !important;
        padding: 12px 20px !important;
        margin-bottom: 12px !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.7), 0 0 20px rgba(16, 185, 129, 0.1) !important;
        backdrop-filter: blur(16px) !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        gap: 12px !important;
        box-sizing: border-box !important;
        flex-shrink: 0 !important;
    }

    /* PPT SLIDE CANVAS STYLING */
    .ppt-deck-container {
        width: 100% !important;
        box-sizing: border-box !important;
        background: linear-gradient(160deg, rgba(6, 38, 21, 0.9) 0%, rgba(2, 12, 6, 0.98) 100%);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 22px;
        padding: 20px 20px 16px 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.9), 0 0 45px rgba(16, 185, 129, 0.15);
        text-align: center;
        position: relative;
        backdrop-filter: blur(20px);
        touch-action: pan-y;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 0;
    }

    /* PPT Slide Widescreen Viewport */
    .ppt-slide-viewport {
        background: radial-gradient(circle at 50% 10%, rgba(16, 185, 129, 0.15) 0%, rgba(3, 16, 9, 0.95) 80%);
        border: 2px solid rgba(16, 185, 129, 0.35);
        border-radius: 18px;
        padding: 20px 16px 16px 16px;
        box-shadow: inset 0 2px 25px rgba(0,0,0,0.9), 0 10px 30px rgba(0,0,0,0.5);
        transition: border-color 0.3s ease, background 0.3s ease, box-shadow 0.3s ease;
        width: 100%;
        max-width: 680px;
        margin: 0 auto;
        position: relative;
        overflow: hidden;
        cursor: pointer;
    }

    /* SLIDING TRANSITION ANIMATIONS */
    .slide-enter-next {
        animation: slideNext 0.32s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
    }
    .slide-enter-prev {
        animation: slidePrev 0.32s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
    }

    @keyframes slideNext {
        0% {
            opacity: 0.15;
            transform: translateX(55px) scale(0.96);
        }
        100% {
            opacity: 1;
            transform: translateX(0) scale(1);
        }
    }

    @keyframes slidePrev {
        0% {
            opacity: 0.15;
            transform: translateX(-55px) scale(0.96);
        }
        100% {
            opacity: 1;
            transform: translateX(0) scale(1);
        }
    }

    .ppt-slide-viewport.is-live-box {
        border-color: #34d399;
        background: radial-gradient(circle at 50% 10%, rgba(16, 185, 129, 0.3) 0%, rgba(2, 18, 9, 0.98) 85%);
        box-shadow: 0 0 50px rgba(16, 185, 129, 0.45), inset 0 2px 25px rgba(16, 185, 129, 0.25);
    }

    .slide-number-badge {
        position: absolute;
        top: 12px;
        left: 14px;
        background: rgba(0, 0, 0, 0.65);
        border: 1px solid rgba(16, 185, 129, 0.4);
        color: #34d399;
        font-size: 10.5px;
        font-weight: 800;
        letter-spacing: 1px;
        padding: 3px 9px;
        border-radius: 999px;
        text-transform: uppercase;
    }

    .slide-mode-badge {
        position: absolute;
        top: 12px;
        right: 14px;
        background: rgba(0, 0, 0, 0.65);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: rgba(255, 255, 255, 0.7);
        font-size: 10.5px;
        font-weight: 700;
        padding: 3px 9px;
        border-radius: 999px;
    }

    .chest-number-title {
        font-size: 52px;
        font-weight: 900;
        color: #34d399;
        letter-spacing: 2px;
        line-height: 1.05;
        margin: 8px 0 4px 0;
        text-shadow: 0 0 25px rgba(52, 211, 153, 0.45);
    }

    .participant-name-title {
        font-size: 22px;
        font-weight: 800;
        color: #fff;
        margin-top: 2px;
    }

    .arrow-btn {
        width: 68px;
        height: 145px;
        border-radius: 18px;
        background: linear-gradient(145deg, rgba(0, 0, 0, 0.85) 0%, rgba(5, 30, 16, 0.95) 100%);
        border: 1px solid rgba(16, 185, 129, 0.35);
        color: #34d399;
        font-size: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        user-select: none;
        outline: none;
        box-shadow: 0 10px 25px rgba(0,0,0,0.6);
        flex-shrink: 0;
    }

    .arrow-btn:hover:not(.disabled) {
        background: linear-gradient(145deg, rgba(16, 185, 129, 0.35) 0%, rgba(4, 40, 22, 0.98) 100%);
        border-color: #34d399;
        color: #fff;
        transform: translateY(-3px) scale(1.03);
        box-shadow: 0 14px 35px rgba(16, 185, 129, 0.4);
    }

    .arrow-btn:active:not(.disabled) {
        transform: scale(0.96);
    }

    .arrow-btn.disabled {
        opacity: 0.18;
        cursor: not-allowed;
        border-color: rgba(255, 255, 255, 0.08);
        color: #64748b;
    }

    .arrow-key-hint {
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: rgba(52, 211, 153, 0.7);
        background: rgba(0, 0, 0, 0.6);
        padding: 2px 6px;
        border-radius: 4px;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }

    /* ACTION BUTTONS */
    .select-btn {
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 12.5px;
        font-weight: 800;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        border: none;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
        outline: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .select-btn-unselected {
        background: linear-gradient(135deg, rgba(6, 60, 32, 0.85) 0%, rgba(0, 0, 0, 0.95) 100%);
        color: #34d399;
        border: 2px solid rgba(16, 185, 129, 0.5);
    }

    .select-btn-unselected:hover {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.35) 0%, rgba(0, 0, 0, 0.95) 100%);
        box-shadow: 0 15px 35px rgba(16, 185, 129, 0.4);
        transform: translateY(-2px);
        border-color: #34d399;
        color: #fff;
    }

    .select-btn-live {
        background: linear-gradient(135deg, #10b981 0%, #047857 100%);
        color: #fff;
        border: 2px solid #34d399;
        box-shadow: 0 0 35px rgba(16, 185, 129, 0.6);
    }

    .select-btn-live:hover {
        background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
        transform: translateY(-2px);
        box-shadow: 0 16px 40px rgba(16, 185, 129, 0.7);
    }

    /* RECORD TIME / STAGE TIMER WIDGET */
    .timer-card-panel {
        width: 100%;
        max-width: 680px;
        margin: 14px auto 0 auto;
        background: rgba(4, 20, 11, 0.9);
        border: 2px solid rgba(16, 185, 129, 0.35);
        border-radius: 18px;
        padding: 14px 18px;
        box-sizing: border-box;
        text-align: center;
        box-shadow: 0 8px 25px rgba(0,0,0,0.6);
        position: relative;
    }

    .timer-card-panel.is-active-stage {
        border-color: #34d399;
        box-shadow: 0 0 30px rgba(16, 185, 129, 0.3);
    }

    .timer-display-clock {
        font-family: 'Courier New', Courier, monospace, sans-serif;
        font-size: 42px;
        font-weight: 900;
        color: #34d399;
        letter-spacing: 3px;
        margin: 4px 0;
        text-shadow: 0 0 20px rgba(52, 211, 153, 0.5);
    }

    .timer-display-clock.is-running {
        color: #ef4444;
        text-shadow: 0 0 25px rgba(239, 68, 68, 0.7);
        animation: timerPulse 1s infinite alternate;
    }

    @keyframes timerPulse {
        0% { opacity: 1; }
        100% { opacity: 0.85; }
    }

    .btn-timer-trigger {
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 800;
        letter-spacing: 1px;
        text-transform: uppercase;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.6);
    }

    .btn-timer-start {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: #fff;
        border: 2px solid #34d399;
    }
    .btn-timer-start:hover {
        background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(16, 185, 129, 0.5);
    }

    .btn-timer-stop {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: #fff;
        border: 2px solid #f87171;
        animation: stopPulse 1.2s infinite alternate;
    }
    .btn-timer-stop:hover {
        background: linear-gradient(135deg, #f87171 0%, #ef4444 100%);
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(239, 68, 68, 0.6);
    }

    @keyframes stopPulse {
        0% { box-shadow: 0 0 15px rgba(239, 68, 68, 0.4); }
        100% { box-shadow: 0 0 35px rgba(239, 68, 68, 0.8); }
    }

    .next-ribbon-card {
        background: rgba(0, 0, 0, 0.65);
        border: 1px solid rgba(16, 185, 129, 0.25);
        border-radius: 12px;
        padding: 10px 14px;
        text-align: left;
        cursor: pointer;
        transition: all 0.2s ease;
        flex: 1;
        min-width: 160px;
    }
    .next-ribbon-card:hover {
        background: rgba(16, 185, 129, 0.25);
        border-color: #34d399;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
    }

    .toast-notify {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: rgba(16, 185, 129, 0.95);
        color: #fff;
        padding: 14px 28px;
        border-radius: 14px;
        font-weight: 800;
        font-size: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.8);
        z-index: 999999;
        display: none;
        align-items: center;
        gap: 10px;
        backdrop-filter: blur(8px);
    }

    /* MOBILE STICKY BOTTOM ACTION BAR */
    .mobile-sticky-bar {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(3, 16, 9, 0.96);
        border-top: 2px solid rgba(16, 185, 129, 0.4);
        padding: 12px 16px;
        z-index: 9999;
        backdrop-filter: blur(16px);
        box-shadow: 0 -10px 30px rgba(0,0,0,0.8);
        gap: 12px;
        justify-content: center;
        align-items: center;
    }

    /* RESPONSIVE MEDIA QUERIES */
    @media (max-width: 768px) {
        html, body, .admin-layout {
            height: auto !important;
            overflow-y: auto !important;
        }
        .main-content {
            height: auto !important;
            padding: 10px 10px 90px 10px !important;
        }
        .studio-navbar {
            padding: 12px 14px !important;
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 10px !important;
            border-radius: 16px !important;
        }
        .studio-navbar > div:first-child {
            justify-content: center !important;
        }
        .ppt-deck-container {
            padding: 16px 12px !important;
            border-radius: 18px !important;
        }
        .stage-deck-row {
            gap: 8px !important;
        }
        .arrow-btn {
            width: 46px !important;
            height: 110px !important;
            border-radius: 14px !important;
            font-size: 22px !important;
        }
        .arrow-key-hint {
            display: none !important;
        }
        .ppt-slide-viewport {
            padding: 16px 10px !important;
            min-height: 170px !important;
        }
        .chest-number-title {
            font-size: 38px !important;
        }
        .participant-name-title {
            font-size: 18px !important;
        }
        .timer-display-clock {
            font-size: 34px !important;
        }
        .select-btn {
            padding: 12px 16px !important;
            font-size: 15px !important;
            border-radius: 12px !important;
        }
        .btn-timer-trigger {
            padding: 10px 18px !important;
            font-size: 14px !important;
        }
        .mobile-sticky-bar {
            display: flex !important;
        }
        #cardsGridContainer {
            grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)) !important;
            gap: 10px !important;
        }
    }

    @media (max-width: 480px) {
        #textProgramTitle {
            font-size: 18px !important;
        }
        .chest-number-title {
            font-size: 30px !important;
        }
        .participant-name-title {
            font-size: 15px !important;
        }
        .timer-display-clock {
            font-size: 30px !important;
        }
        .arrow-btn {
            width: 38px !important;
            height: 95px !important;
            font-size: 18px !important;
        }
    }
</style>

<div class="main-content">
    
    <!-- STUDIO TOPBAR & METRICS BAR -->
    <div class="studio-navbar">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.35); padding: 8px 12px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                <i class="fa-solid fa-tower-broadcast" style="font-size: 22px; color: #34d399; filter: drop-shadow(0 0 10px rgba(52, 211, 153, 0.6));"></i>
            </div>
            <div>
                <div style="font-size: 17px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                    Emcee Master Stage Deck
                    <span style="background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; text-transform: uppercase;">
                        PPT Deck
                    </span>
                </div>
                <div style="font-size: 11.5px; color: rgba(255,255,255,0.6); margin-top: 2px;">
                    <?= e($activeEvent['name'] ?? 'Kauzariyya Musabaqa Event') ?>
                </div>
            </div>
        </div>

        <!-- STAGE METRICS & CONTROLS -->
        <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap; justify-content: center;">
            
            <div style="display: flex; gap: 14px; border-right: 1px solid rgba(16,185,129,0.2); padding-right: 14px;">
                <div style="text-align: right;">
                    <div style="font-size: 9.5px; text-transform: uppercase; color: rgba(255,255,255,0.5); font-weight: 700;">Progress</div>
                    <div style="font-size: 13px; font-weight: 800; color: #34d399;">
                        <?= $completedProgramsCount ?> / <?= $totalProgramsCount ?> Done
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 9.5px; text-transform: uppercase; color: rgba(255,255,255,0.5); font-weight: 700;">Deck Queue</div>
                    <div style="font-size: 13px; font-weight: 800; color: #fff;">
                        <?= $totalQueueItems ?> Slides
                    </div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                <a href="<?= app_url('/live-display/dashboard.php') ?>" class="btn btn-secondary btn-sm" target="_blank" style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #fff; padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;">
                    <i class="fa-solid fa-tv mr-1"></i> Live TV
                </a>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" id="btnSelect" class="select-btn select-btn-unselected" onclick="broadcastLiveStage()">
                        ⚡ BROADCAST LIVE TO STAGE
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openCardsModal()" style="background: linear-gradient(135deg, #10b981, #047857); border: 1px solid #34d399; font-weight: 800; padding: 7px 13px; border-radius: 9px; box-shadow: 0 0 15px rgba(16,185,129,0.3); font-size: 12px;">
                        <i class="fa-solid fa-border-all mr-1"></i> Stage Cards
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Toast Notification -->
    <div id="toastNotification" class="toast-notify">
        <i class="fa-solid fa-circle-check"></i> <span id="toastText">Live Stage Updated!</span>
    </div>

    <!-- HERO PPT MASTER DECK CONTAINER -->
    <div class="ppt-deck-container">
        
        <!-- PROGRAM HEADER BAR -->
        <div style="margin-bottom: 12px;">
            <div style="display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 6px; flex-wrap: wrap;">
                <span id="badgeStatus" class="badge" style="font-size: 10.5px; padding: 3px 12px; text-transform: uppercase; font-weight: 800;">
                    --
                </span>

                <span id="badgeClassType" class="badge badge-info" style="font-size: 10.5px; padding: 3px 10px; background: rgba(16,185,129,0.18); color: #34d399; border: 1px solid rgba(16,185,129,0.35); font-weight: 700;">
                    --
                </span>

                <span id="badgeSection" class="badge badge-neutral" style="font-size: 10.5px; padding: 3px 10px; display: none; background: rgba(0,0,0,0.6); border: 1px solid rgba(255,255,255,0.15); color: #ccc;">
                    <i class="fa-solid fa-clock mr-1"></i> <span id="textSection">--</span>
                </span>
            </div>

            <h1 id="textProgramTitle" style="margin: 0; font-size: 26px; font-weight: 900; color: #fff; line-height: 1.2; letter-spacing: -0.5px;">
                --
            </h1>

            <div id="textParticipantProgress" style="font-size: 13px; color: rgba(255,255,255,0.65); margin-top: 4px; font-weight: 500;">
                --
            </div>
        </div>

        <!-- PPT CANVAS DECK ROW (ARROWS + SLIDE CANVAS) -->
        <div class="stage-deck-row" style="display: flex; align-items: center; justify-content: center; gap: 14px; max-width: 860px; margin: 0 auto;">
            
            <!-- PREVIOUS SLIDE ARROW BUTTON (<-) -->
            <button type="button" id="btnPrev" class="arrow-btn" title="Previous Slide (Swipe Right or Left Arrow)" onclick="navigateStage(-1)">
                <i class="fa-solid fa-chevron-left"></i>
                <span class="arrow-key-hint">LEFT</span>
            </button>

            <!-- WIDESCREEN PPT SLIDE CANVAS (With Sliding Animation) -->
            <div id="chestBox" class="ppt-slide-viewport" onclick="handleSlideClick()" title="Click to Broadcast Live to Stage">
                
                <span id="textSlideBadge" class="slide-number-badge">
                    SLIDE 1 / 1
                </span>

                <span id="textSlideMode" class="slide-mode-badge">
                    STAGE DECK
                </span>

                <div id="textChestHeader" style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: rgba(255,255,255,0.5); letter-spacing: 2px; margin-top: 14px;">
                    CHEST NUMBER
                </div>

                <div id="textChestNumber" class="chest-number-title">
                    --
                </div>

                <div id="textParticipantName" class="participant-name-title">
                    --
                </div>

                <div style="margin-top: 8px;">
                    <span id="pillTeam" class="team-color-pill" style="padding: 3px 14px; border-radius: 999px; font-size: 12.5px; font-weight: 700;">
                        --
                    </span>
                </div>

                <!-- SLIDE BROADCAST TIP -->
                <div style="font-size: 10.5px; color: rgba(52,211,153,0.7); margin-top: 10px; font-weight: 600;">
                    <i class="fa-solid fa-hand-pointer mr-1"></i> Click slide or button below to broadcast live on stage
                </div>
            </div>

            <!-- NEXT SLIDE ARROW BUTTON (->) -->
            <button type="button" id="btnNext" class="arrow-btn" title="Next Slide (Swipe Left or Right Arrow)" onclick="navigateStage(1)">
                <i class="fa-solid fa-chevron-right"></i>
                <span class="arrow-key-hint">RIGHT</span>
            </button>

        </div>

    </div>
</div>

<!-- MOBILE STICKY BOTTOM ACTION BAR -->
<div id="mobileStickyBar" class="mobile-sticky-bar">
    <button type="button" id="btnMobileActivate" class="btn btn-success" onclick="broadcastLiveStage()" style="flex: 1; padding: 12px; font-weight: 800; font-size: 15px; border-radius: 12px; background: linear-gradient(135deg, #10b981, #047857); border: 1px solid #34d399;">
        ⚡ ACTIVATE ON STAGE
    </button>
    
    <button type="button" id="btnMobileTimer" class="btn btn-primary" onclick="toggleTimerFromMobile()" style="padding: 12px 18px; font-weight: 800; font-size: 15px; border-radius: 12px; background: #059669; border: 1px solid #34d399; display: flex; align-items: center; gap: 6px;">
        <i class="fa-solid fa-stopwatch"></i> <span id="textMobileTimer">TIMER</span>
    </button>
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
let timerStartTime = 0;
let timerElapsedTime = 0;
let isTimerRunning = false;

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

    if (item.is_intro) {
        document.getElementById('textParticipantProgress').innerHTML = 
            `Program Intro Slide · <strong>${item.total_participants} ${item.is_group ? 'Teams' : 'Participants'} Registered</strong>`;
        textChestHeader.innerText = 'STAGE DISPLAY SLIDE';
        textChestNum.innerText = item.is_group ? '👥 GROUP INTRO' : '📋 PROGRAM INTRO';
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '24px' : '32px';
        textPartName.innerText = item.entry_name;
        textSlideMode.innerText = 'PROGRAM INTRO';
        
        pillTeam.innerText = item.is_group ? 'Group Team Program' : 'Program Overview Mode';
        pillTeam.style.background = 'rgba(16, 185, 129, 0.2)';
        pillTeam.style.borderColor = 'rgba(16, 185, 129, 0.4)';
        pillTeam.style.color = '#34d399';
        pillTeam.style.display = 'inline-block';
    } else if (item.is_group) {
        // Group Program
        document.getElementById('textParticipantProgress').innerHTML = 
            `Team <strong>${item.participant_order}</strong> of <strong>${item.total_participants}</strong>`;
        textChestHeader.innerText = 'STAGE GROUP TEAM';
        textChestNum.innerText = item.team_name;
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '26px' : '38px';
        textPartName.innerText = 'Team Performance';
        textSlideMode.innerText = `TEAM #${item.participant_order}`;
        
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
        textSlideMode.innerText = `ENTRY #${item.participant_order}`;
        
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
        pillTeam.style.display = 'none';
    }

    // Is Live State Comparison
    const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);

    const badgeStatus = document.getElementById('badgeStatus');
    const btnSelect = document.getElementById('btnSelect');
    const btnMobileActivate = document.getElementById('btnMobileActivate');

    if (isLiveItem) {
        badgeStatus.className = 'badge badge-success';
        badgeStatus.innerText = item.is_intro ? '🔴 PROGRAM INTRO LIVE' : '🔴 ON STAGE LIVE';
        chestBox.classList.add('is-live-box');

        btnSelect.className = 'select-btn select-btn-live';
        btnSelect.innerHTML = item.is_intro 
            ? '<i class="fa-solid fa-circle-check"></i> PROGRAM INTRO CURRENTLY LIVE ON STAGE' 
            : '<i class="fa-solid fa-circle-check"></i> PARTICIPANT CURRENTLY LIVE ON STAGE';
            
        if (btnMobileActivate) {
            btnMobileActivate.className = 'btn btn-success';
            btnMobileActivate.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i> LIVE ON STAGE';
        }
    } else {
        badgeStatus.className = 'badge badge-neutral';
        badgeStatus.innerText = 'PREVIEWING SLIDE';
        badgeStatus.style.background = 'rgba(0,0,0,0.6)';
        badgeStatus.style.border = '1px solid rgba(255,255,255,0.15)';
        badgeStatus.style.color = '#aaa';
        chestBox.classList.remove('is-live-box');

        btnSelect.className = 'select-btn select-btn-unselected';
        btnSelect.innerHTML = item.is_intro 
            ? '⚡ ACTIVATE &amp; SHOW PROGRAM INTRO ON STAGE' 
            : '⚡ ACTIVATE PARTICIPANT ON STAGE';

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

const deckElement = document.querySelector('.ppt-deck-container');

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
