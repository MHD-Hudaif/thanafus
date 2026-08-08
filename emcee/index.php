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
}

// Live Status Query API (for polling)
if (isset($_GET['ajax_status'])) {
    header('Content-Type: application/json');
    $status = admin_get_live_stage_control($pdo);
    echo json_encode(['success' => true, 'live_control' => $status]);
    exit;
}

$flash = admin_take_flash();
$liveControl = admin_get_live_stage_control($pdo);
$liveProgramId = $liveControl['program_id'];
$liveEntryId = $liveControl['entry_id'];

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
        'has_entries' => ($totalEntries > 0)
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
            'has_entries' => true
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
    body, body.layout-sidebar-enabled {
        background: #020804 !important;
        background-image: 
            radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.15) 0%, transparent 65%),
            radial-gradient(circle at 10% 90%, rgba(6, 78, 42, 0.2) 0%, transparent 50%),
            radial-gradient(circle at 90% 90%, rgba(6, 78, 42, 0.2) 0%, transparent 50%),
            linear-gradient(to bottom, rgba(0, 0, 0, 0.7), #020804) !important;
        background-attachment: fixed !important;
        overflow-x: hidden;
        padding-left: 0 !important;
        font-family: 'Inter', sans-serif !important;
    }
    .admin-layout {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        min-height: 100vh !important;
    }
    .main-content {
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 1180px !important;
        padding: 24px 20px 48px 20px !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        box-sizing: border-box !important;
    }
    .studio-navbar {
        width: 100% !important;
        background: rgba(5, 25, 14, 0.75) !important;
        border: 1px solid rgba(16, 185, 129, 0.25) !important;
        border-radius: 20px !important;
        padding: 16px 24px !important;
        margin-bottom: 24px !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6), 0 0 20px rgba(16, 185, 129, 0.1) !important;
        backdrop-filter: blur(16px) !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        gap: 16px !important;
        box-sizing: border-box !important;
    }
    .emcee-deck-container {
        width: 100% !important;
        box-sizing: border-box !important;
        background: linear-gradient(160deg, rgba(6, 40, 22, 0.8) 0%, rgba(2, 12, 6, 0.95) 100%);
        border: 1px solid rgba(16, 185, 129, 0.35);
        border-radius: 28px;
        padding: 36px 32px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.85), 0 0 50px rgba(16, 185, 129, 0.15);
        text-align: center;
        position: relative;
        backdrop-filter: blur(20px);
        touch-action: pan-y;
    }
    .arrow-btn {
        width: 80px;
        height: 155px;
        border-radius: 22px;
        background: linear-gradient(145deg, rgba(0, 0, 0, 0.8) 0%, rgba(5, 30, 16, 0.9) 100%);
        border: 1px solid rgba(16, 185, 129, 0.35);
        color: #34d399;
        font-size: 36px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        user-select: none;
        outline: none;
        box-shadow: 0 10px 25px rgba(0,0,0,0.6);
        flex-shrink: 0;
    }
    .arrow-btn:hover:not(.disabled) {
        background: linear-gradient(145deg, rgba(16, 185, 129, 0.3) 0%, rgba(4, 40, 22, 0.95) 100%);
        border-color: #34d399;
        color: #fff;
        transform: translateY(-3px) scale(1.04);
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
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: rgba(52, 211, 153, 0.7);
        background: rgba(0, 0, 0, 0.5);
        padding: 2px 6px;
        border-radius: 4px;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .chest-display-box {
        background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.12) 0%, rgba(0, 0, 0, 0.92) 80%);
        border: 2px solid rgba(16, 185, 129, 0.35);
        border-radius: 24px;
        padding: 28px 36px;
        min-height: 240px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        box-shadow: inset 0 2px 20px rgba(0,0,0,0.9), 0 10px 30px rgba(0,0,0,0.4);
        transition: all 0.3s ease;
        width: 100%;
        max-width: 520px;
        cursor: pointer;
        user-select: none;
    }
    .chest-display-box:hover {
        border-color: #34d399;
        box-shadow: inset 0 2px 20px rgba(16, 185, 129, 0.25), 0 12px 35px rgba(16, 185, 129, 0.3);
        transform: translateY(-2px);
    }
    .chest-display-box.is-live-box {
        border-color: #34d399;
        background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.3) 0%, rgba(0, 0, 0, 0.95) 85%);
        box-shadow: 0 0 50px rgba(16, 185, 129, 0.4), inset 0 2px 20px rgba(16, 185, 129, 0.2);
    }
    .chest-number-title {
        font-size: 64px;
        font-weight: 900;
        color: #34d399;
        letter-spacing: 2px;
        line-height: 1.05;
        margin: 6px 0;
        text-shadow: 0 0 25px rgba(52, 211, 153, 0.4);
    }
    .participant-name-title {
        font-size: 26px;
        font-weight: 800;
        color: #fff;
        margin-top: 4px;
    }
    .select-btn {
        width: 100%;
        max-width: 520px;
        padding: 20px 38px;
        border-radius: 18px;
        font-size: 21px;
        font-weight: 900;
        letter-spacing: 1px;
        text-transform: uppercase;
        border: none;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.7);
        outline: none;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }
    .select-btn-unselected {
        background: linear-gradient(135deg, rgba(6, 60, 32, 0.8) 0%, rgba(0, 0, 0, 0.95) 100%);
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
    .next-ribbon-card {
        background: rgba(0, 0, 0, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 14px;
        padding: 12px 18px;
        text-align: left;
        cursor: pointer;
        transition: all 0.2s ease;
        flex: 1;
        min-width: 180px;
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
    #cardsGridContainer::-webkit-scrollbar {
        width: 8px;
    }
    #cardsGridContainer::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 4px;
    }
    #cardsGridContainer::-webkit-scrollbar-thumb {
        background: rgba(16, 185, 129, 0.4);
        border-radius: 4px;
    }
    #cardsGridContainer::-webkit-scrollbar-thumb:hover {
        background: rgba(16, 185, 129, 0.7);
    }

    /* MOBILE RESPONSIVE MEDIA QUERIES */
    @media (max-width: 768px) {
        .main-content {
            padding: 12px 10px 32px 10px !important;
        }
        .studio-navbar {
            padding: 14px 16px !important;
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 12px !important;
            border-radius: 16px !important;
        }
        .studio-navbar > div:first-child {
            justify-content: center !important;
        }
        .emcee-deck-container {
            padding: 24px 14px !important;
            border-radius: 20px !important;
        }
        #textProgramTitle {
            font-size: 24px !important;
        }
        .stage-deck-row {
            gap: 10px !important;
        }
        .arrow-btn {
            width: 52px !important;
            height: 120px !important;
            border-radius: 14px !important;
            font-size: 26px !important;
        }
        .arrow-key-hint {
            display: none !important;
        }
        .chest-display-box {
            padding: 18px 12px !important;
            min-height: 190px !important;
        }
        .chest-number-title {
            font-size: 42px !important;
        }
        .participant-name-title {
            font-size: 19px !important;
        }
        .select-btn {
            padding: 16px 20px !important;
            font-size: 17px !important;
            border-radius: 14px !important;
        }
        #cardsModal {
            padding: 10px !important;
        }
        #cardsModal > div {
            max-height: calc(100vh - 20px) !important;
            padding: 16px 12px !important;
            border-radius: 18px !important;
        }
        #cardsGridContainer {
            grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)) !important;
            gap: 10px !important;
        }
        .next-ribbon-card {
            min-width: 140px !important;
        }
    }

    @media (max-width: 480px) {
        #textProgramTitle {
            font-size: 20px !important;
        }
        .chest-number-title {
            font-size: 32px !important;
        }
        .participant-name-title {
            font-size: 16px !important;
        }
        .arrow-btn {
            width: 44px !important;
            height: 100px !important;
            font-size: 20px !important;
        }
        .select-btn {
            font-size: 15px !important;
            padding: 14px 16px !important;
        }
    }
</style>

<div class="main-content">
    
    <!-- STUDIO TOPBAR & METRICS BAR -->
    <div class="studio-navbar">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.35); padding: 10px 14px; border-radius: 14px; display: flex; align-items: center; justify-content: center;">
                <i class="fa-solid fa-tower-broadcast" style="font-size: 24px; color: #34d399; filter: drop-shadow(0 0 10px rgba(52, 211, 153, 0.6));"></i>
            </div>
            <div>
                <div style="font-size: 18px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px;">
                    Emcee Stage Control Deck
                    <span style="background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; text-transform: uppercase;">
                        Studio Master
                    </span>
                </div>
                <div style="font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px;">
                    <?= e($activeEvent['name'] ?? 'Kauzariyya Musabaqa Event') ?>
                </div>
            </div>
        </div>

        <!-- STAGE METRICS & CONTROLS -->
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: center;">
            
            <div style="display: flex; gap: 14px; border-right: 1px solid rgba(16,185,129,0.2); padding-right: 16px;">
                <div style="text-align: right;">
                    <div style="font-size: 10px; text-transform: uppercase; color: rgba(255,255,255,0.5); font-weight: 700;">Progress</div>
                    <div style="font-size: 13.5px; font-weight: 800; color: #34d399;">
                        <?= $completedProgramsCount ?> / <?= $totalProgramsCount ?> Done
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 10px; text-transform: uppercase; color: rgba(255,255,255,0.5); font-weight: 700;">Queue</div>
                    <div style="font-size: 13.5px; font-weight: 800; color: #fff;">
                        <?= $totalQueueItems ?> Slides
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary btn-sm" onclick="openCardsModal()" style="background: linear-gradient(135deg, #10b981, #047857); border: 1px solid #34d399; font-weight: 800; padding: 8px 14px; border-radius: 10px; box-shadow: 0 0 15px rgba(16,185,129,0.3);">
                    <i class="fa-solid fa-border-all mr-1"></i> Stage Cards
                </button>
                <a href="<?= app_url('/judges/index.php') ?>" class="btn btn-secondary btn-sm" target="_blank" style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #fff; padding: 8px 12px; border-radius: 10px;">
                    <i class="fa-solid fa-gavel mr-1"></i> Judges
                </a>
                <a href="<?= app_url('/live-display/dashboard.php') ?>" class="btn btn-secondary btn-sm" target="_blank" style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #fff; padding: 8px 12px; border-radius: 10px;">
                    <i class="fa-solid fa-tv mr-1"></i> Live TV
                </a>
            </div>
        </div>
    </div>

    <!-- Live Toast Notification -->
    <div id="toastNotification" class="toast-notify">
        <i class="fa-solid fa-circle-check"></i> <span id="toastText">Live Stage Updated!</span>
    </div>

    <!-- HERO MASTER STAGE DECK CONTAINER -->
    <div class="emcee-deck-container">
        
        <!-- PROGRAM HEADER BAR -->
        <div style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                <span id="badgeStatus" class="badge" style="font-size: 11px; padding: 4px 14px; text-transform: uppercase; font-weight: 800;">
                    --
                </span>

                <span id="badgeClassType" class="badge badge-info" style="font-size: 11px; padding: 4px 12px; background: rgba(16,185,129,0.18); color: #34d399; border: 1px solid rgba(16,185,129,0.35); font-weight: 700;">
                    --
                </span>

                <span id="badgeSection" class="badge badge-neutral" style="font-size: 11px; padding: 4px 12px; display: none; background: rgba(0,0,0,0.6); border: 1px solid rgba(255,255,255,0.15); color: #ccc;">
                    <i class="fa-solid fa-clock mr-1"></i> <span id="textSection">--</span>
                </span>
            </div>

            <h1 id="textProgramTitle" style="margin: 0; font-size: 32px; font-weight: 900; color: #fff; line-height: 1.2; letter-spacing: -0.5px;">
                --
            </h1>

            <div id="textParticipantProgress" style="font-size: 13.5px; color: rgba(255,255,255,0.65); margin-top: 6px; font-weight: 500;">
                --
            </div>
            <div style="font-size: 11px; color: rgba(52,211,153,0.7); margin-top: 4px; font-weight: 600;">
                <i class="fa-solid fa-hand-pointer mr-1"></i> Click Card or Broadcast Button to Put Live · Swipe Left/Right
            </div>
        </div>

        <!-- CENTERED ARROW NAVIGATION & CHEST DISPLAY CARD -->
        <div class="stage-deck-row" style="display: flex; align-items: center; justify-content: center; gap: 16px; max-width: 720px; margin: 0 auto 28px auto;">
            
            <!-- PREVIOUS ARROW BUTTON (<-) -->
            <button type="button" id="btnPrev" class="arrow-btn" title="Previous Slide / Program (Swipe Right or Left Arrow)" onclick="navigateStage(-1)">
                <i class="fa-solid fa-chevron-left"></i>
                <span class="arrow-key-hint">LEFT</span>
            </button>

            <!-- CENTER DISPLAY CARD (Clicking also broadcasts live!) -->
            <div id="chestBox" class="chest-display-box" onclick="broadcastLiveStage()" title="Click to Broadcast Live to Stage">
                <div id="textChestHeader" style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: rgba(255,255,255,0.5); letter-spacing: 2px;">
                    CHEST NUMBER
                </div>
                <div id="textChestNumber" class="chest-number-title">
                    --
                </div>
                <div id="textParticipantName" class="participant-name-title">
                    --
                </div>
                <div style="margin-top: 12px;">
                    <span id="pillTeam" class="team-color-pill" style="padding: 5px 16px; border-radius: 999px; font-size: 13px; font-weight: 700;">
                        --
                    </span>
                </div>
            </div>

            <!-- NEXT ARROW BUTTON (->) -->
            <button type="button" id="btnNext" class="arrow-btn" title="Next Slide / Program (Swipe Left or Right Arrow)" onclick="navigateStage(1)">
                <i class="fa-solid fa-chevron-right"></i>
                <span class="arrow-key-hint">RIGHT</span>
            </button>

        </div>

        <!-- SELECT / BROADCAST LIVE BUTTON -->
        <button type="button" id="btnSelect" class="select-btn" onclick="broadcastLiveStage()">
            ⚡ BROADCAST LIVE TO STAGE
        </button>

        <!-- UPCOMING ON STAGE REEL STRIP -->
        <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid rgba(16,185,129,0.18); text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                <div style="font-size: 11.5px; font-weight: 800; text-transform: uppercase; color: #34d399; letter-spacing: 1.5px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-forward-step"></i> Next Up On Stage (Click to Broadcast)
                </div>
                <label style="font-size: 12px; color: rgba(255,255,255,0.5); font-weight: 600;">Direct Stage Jump:</label>
            </div>

            <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
                <div id="upcomingRibbon" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap;">
                </div>
                <select id="selectStageJump" onchange="jumpToQueueIndex(parseInt(this.value, 10))" 
                        class="form-control" 
                        style="width: 100%; max-width: 380px; background: rgba(0,0,0,0.85); border: 1px solid rgba(16,185,129,0.35); color: #fff; padding: 10px 14px; border-radius: 12px; font-size: 13.5px;">
                </select>
            </div>
        </div>

    </div>

    <!-- BOTTOM KEYBOARD COMMAND BAR -->
    <div style="margin-top: 20px; width: 100%; display: flex; justify-content: center; gap: 20px; font-size: 12px; color: rgba(255,255,255,0.5); font-weight: 600; flex-wrap: wrap; text-align: center;">
        <span><kbd style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #34d399; padding: 2px 8px; border-radius: 4px; font-size: 11px;">← / → or SWIPE</kbd> Navigate</span>
        <span><kbd style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #34d399; padding: 2px 8px; border-radius: 4px; font-size: 11px;">SPACE / ENTER</kbd> Broadcast Live</span>
        <span><kbd style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #34d399; padding: 2px 8px; border-radius: 4px; font-size: 11px;">ESC</kbd> Close Modal</span>
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
                <div style="font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px;">Click any card to enter &amp; broadcast live on stage</div>
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

function renderStageDeck(index) {
    if (!stageQueue || stageQueue.length === 0) return;
    
    currentIndex = Math.max(0, Math.min(stageQueue.length - 1, index));
    const item = stageQueue[currentIndex];

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

    if (item.is_intro) {
        document.getElementById('textParticipantProgress').innerHTML = 
            `Program Intro Slide · <strong>${item.total_participants} ${item.is_group ? 'Teams' : 'Participants'} Registered</strong>`;
        textChestHeader.innerText = 'STAGE DISPLAY SLIDE';
        textChestNum.innerText = item.is_group ? '👥 GROUP INTRO' : '📋 PROGRAM INTRO';
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '26px' : '34px';
        textPartName.innerText = item.entry_name;
        
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
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '30px' : '42px';
        textPartName.innerText = 'Team Performance';
        
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
        textChestNum.style.fontSize = window.innerWidth <= 480 ? '36px' : '56px';
        textPartName.innerText = item.entry_name;
        
        pillTeam.innerText = item.team_name;
        pillTeam.style.background = item.team_color + '22';
        pillTeam.style.borderColor = item.team_color + '44';
        pillTeam.style.color = '#fff';
        pillTeam.style.display = 'inline-block';
    } else {
        document.getElementById('textParticipantProgress').innerText = 'No entries registered for this program';
        textChestHeader.innerText = 'CHEST NUMBER';
        textChestNum.innerText = '-';
        textChestNum.style.fontSize = '56px';
        textPartName.innerText = 'No Participants Registered';
        pillTeam.style.display = 'none';
    }

    // Is Live State Comparison
    const isLiveItem = (item.program_id === liveProgramId && item.entry_id === liveEntryId);

    const badgeStatus = document.getElementById('badgeStatus');
    const chestBox = document.getElementById('chestBox');
    const btnSelect = document.getElementById('btnSelect');

    if (isLiveItem) {
        badgeStatus.className = 'badge badge-success';
        badgeStatus.innerText = item.is_intro ? '🔴 PROGRAM INTRO LIVE' : '🔴 ON STAGE LIVE';
        chestBox.classList.add('is-live-box');

        btnSelect.className = 'select-btn select-btn-live';
        btnSelect.innerHTML = item.is_intro 
            ? '<i class="fa-solid fa-circle-check"></i> PROGRAM INTRO LIVE' 
            : '<i class="fa-solid fa-circle-check"></i> CURRENTLY ON STAGE LIVE';
    } else {
        badgeStatus.className = 'badge badge-neutral';
        badgeStatus.innerText = 'PREVIEWING';
        badgeStatus.style.background = 'rgba(0,0,0,0.6)';
        badgeStatus.style.border = '1px solid rgba(255,255,255,0.15)';
        badgeStatus.style.color = '#aaa';
        chestBox.classList.remove('is-live-box');

        btnSelect.className = 'select-btn select-btn-unselected';
        btnSelect.innerHTML = item.is_intro 
            ? '⚡ SELECT &amp; SHOW PROGRAM INTRO' 
            : '⚡ BROADCAST LIVE TO STAGE';
    }

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

function renderUpcomingRibbon() {
    const upcomingContainer = document.getElementById('upcomingRibbon');
    if (!upcomingContainer || !stageQueue) return;

    upcomingContainer.innerHTML = '';
    const nextItems = stageQueue.slice(currentIndex + 1, currentIndex + 4);

    if (nextItems.length === 0) {
        upcomingContainer.innerHTML = '<div style="font-size: 12px; color: rgba(255,255,255,0.4); padding: 8px;">End of Stage Queue</div>';
        return;
    }

    nextItems.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'next-ribbon-card';
        card.onclick = () => selectCardIndex(item.queue_index, true);

        let titleText = item.is_intro ? ('📋 INTRO: ' + item.program_title) : (item.is_group ? item.team_name : ('Chest #' + item.chest_number + ' (' + item.entry_name + ')'));
        
        card.innerHTML = `
            <div style="font-size: 10px; font-weight: 800; color: #34d399; text-transform: uppercase; margin-bottom: 2px;">Slide #${item.queue_index + 1}</div>
            <div style="font-size: 12px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;">${escapeHtml(titleText)}</div>
            <div style="font-size: 9.5px; font-weight: 700; color: rgba(52,211,153,0.8); margin-top: 4px; text-transform: uppercase;">Click to Broadcast</div>
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
                Click to Broadcast
            </div>
        `;

        card.onclick = () => {
            selectCardIndex(item.queue_index, true);
        };

        container.appendChild(card);
    });
}

function selectCardIndex(idx, broadcastNow = true) {
    renderStageDeck(idx);
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
    const targetIdx = currentIndex + delta;
    if (targetIdx >= 0 && targetIdx < stageQueue.length) {
        renderStageDeck(targetIdx);
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

const deckElement = document.querySelector('.emcee-deck-container');

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
    renderStageDeck(currentIndex);
});
</script>

<?php
admin_close_page();
