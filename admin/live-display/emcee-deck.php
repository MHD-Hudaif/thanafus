<?php
declare(strict_types=1);

$pageTitle = 'Emcee Stage Control Deck';
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$_SESSION['active_workspace'] = 'live-display';

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)($activeEvent['id'] ?? 0);

// Load TV functions if available
if (file_exists(__DIR__ . '/../../live-display/includes/functions.php')) {
    require_once __DIR__ . '/../../live-display/includes/functions.php';
}

// ---------------------------------------------------------
// AJAX / Real-Time API Handler
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'broadcast_stage') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $entryId = (int)($_POST['entry_id'] ?? 0);
        if ($programId > 0) {
            admin_set_live_stage_control($pdo, $programId, $entryId);
            
            // Save stage start timestamp in settings for live timer
            $settings = admin_get_settings($pdo);
            $settings['live_stage_start_time'] = time();
            save_musabaqa_settings($pdo, $settings);
        }
        
        if (admin_is_ajax() || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'live_program_id' => $programId,
                'live_entry_id' => $entryId,
                'live_stage_start_time' => time(),
                'message' => $entryId > 0 ? 'Contestant set live on stage!' : 'Program Intro broadcast live!'
            ]);
            exit;
        }
        admin_flash('success', "Live Stage updated successfully!");
        admin_redirect('/admin/live-display/emcee-deck.php');
    }

    if ($action === 'trigger_mode') {
        $mode = trim((string)($_POST['mode'] ?? 'auto'));
        $slideKey = trim((string)($_POST['slide_key'] ?? ''));
        
        $settKey = 'live_display.event.' . $activeEventId . '.settings';
        $existingJson = '';
        try {
            $stmt = $pdo->prepare('SELECT setting_value FROM musabaqa_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$settKey]);
            $existingJson = (string)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        $settDecoded = json_decode($existingJson, true) ?: [];
        $settDecoded['mode'] = $mode;
        if ($slideKey !== '') {
            $settDecoded['active_slide'] = $slideKey;
        }
        $settDecoded['last_updated'] = time();

        $saveStmt = $pdo->prepare('INSERT INTO musabaqa_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $saveStmt->execute([$settKey, json_encode($settDecoded)]);

        if (admin_is_ajax() || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'mode' => $mode, 'slide_key' => $slideKey]);
            exit;
        }
        admin_redirect('/admin/live-display/emcee-deck.php');
    }
}

// ---------------------------------------------------------
// Live State Polling Endpoint
// ---------------------------------------------------------
if (isset($_GET['poll_deck_state'])) {
    header('Content-Type: application/json');
    $liveControl = admin_get_live_stage_control($pdo);
    $settings = admin_get_settings($pdo);
    $startTime = (int)($settings['live_stage_start_time'] ?? time());
    
    echo json_encode([
        'success' => true,
        'live_control' => $liveControl,
        'start_time' => $startTime,
        'elapsed_seconds' => max(0, time() - $startTime)
    ]);
    exit;
}

// ---------------------------------------------------------
// Load Active Event Data & Programs Queue
// ---------------------------------------------------------
$liveControl = admin_get_live_stage_control($pdo);
$liveProgramId = (int)$liveControl['program_id'];
$liveEntryId = (int)$liveControl['entry_id'];

$settings = admin_get_settings($pdo);
$stageStartTime = (int)($settings['live_stage_start_time'] ?? time());
$stageElapsed = max(0, time() - $stageStartTime);

// 1. Fetch All Programs for Event
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

// If no live program selected, auto-default to first active or first program
if ($liveProgramId <= 0 && !empty($programs)) {
    $liveProgramId = (int)$programs[0]['id'];
}

// 2. Build Stage Queue Cards across programs
$stageQueue = [];
$currentLiveIndex = -1;
$activeProgram = null;
$activeProgramEntries = [];
$completedProgramsCount = 0;
$totalEntriesCount = 0;

foreach ($programs as $pIdx => $prog) {
    if ($prog['status'] === 'completed') {
        $completedProgramsCount++;
    }

    $pId = (int)$prog['id'];
    $isGroup = ($prog['program_type'] === 'group' || !empty($prog['only_team_marks']));

    // Fetch entries
    $stmtEntries = $pdo->prepare("
        SELECT pe.*, t.team_name, t.team_color,
               " . admin_entry_chest_number_subquery() . ",
               (SELECT GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ''), s.full_name) SEPARATOR ', ')
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                WHERE em.entry_id = pe.id) AS member_names
        FROM musabaqa_program_entries pe
        LEFT JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.program_id = ? AND pe.event_id = ?
        ORDER BY pe.performance_order ASC, pe.id ASC
    ");
    $stmtEntries->execute([$pId, $activeEventId]);
    $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
    $totalEntriesCount += count($entries);

    if ($pId === $liveProgramId) {
        $activeProgram = $prog;
        $activeProgramEntries = $entries;
    }

    // Intro Slide
    $isLiveIntro = ($liveProgramId === $pId && $liveEntryId === 0);
    $introItem = [
        'type' => 'intro',
        'program_id' => $pId,
        'entry_id' => 0,
        'title' => 'PROGRAM INTRO: ' . ($prog['title'] ?? $prog['name'] ?? ''),
        'subtitle' => ($prog['class_type_name'] ?? 'General') . ' (' . ($isGroup ? 'Group' : 'Individual') . ')',
        'chest_number' => 'INTRO',
        'performer_name' => ($prog['title'] ?? $prog['name'] ?? 'Program Intro'),
        'team_name' => $prog['schedule_section_name'] ?: 'Main Stage',
        'team_color' => '#10b981',
        'order' => 0,
        'is_live' => $isLiveIntro
    ];
    $stageQueue[] = $introItem;
    if ($isLiveIntro) {
        $currentLiveIndex = count($stageQueue) - 1;
    }

    // Participant Entries
    foreach ($entries as $eIdx => $ent) {
        $eId = (int)$ent['id'];
        $chest = $ent['chest_number'] ?: ('#' . sprintf('%02d', $ent['performance_order']));
        $pName = $isGroup ? ($ent['team_name'] . ' Group') : ($ent['member_names'] ?: ($ent['code_name'] ?: 'Participant ' . $chest));
        $tName = $ent['team_name'] ?: 'Independent';
        $isLiveEnt = ($liveProgramId === $pId && $liveEntryId === $eId);

        $entItem = [
            'type' => 'entry',
            'program_id' => $pId,
            'entry_id' => $eId,
            'title' => ($prog['title'] ?? $prog['name'] ?? ''),
            'subtitle' => $tName,
            'chest_number' => $chest,
            'performer_name' => $pName,
            'team_name' => $tName,
            'team_color' => $ent['team_color'] ?: '#22c55e',
            'order' => (int)$ent['performance_order'],
            'is_live' => $isLiveEnt
        ];
        $stageQueue[] = $entItem;
        if ($isLiveEnt) {
            $currentLiveIndex = count($stageQueue) - 1;
        }
    }
}

// Default current live item if index not set
if ($currentLiveIndex < 0 && !empty($stageQueue)) {
    $currentLiveIndex = 0;
    $stageQueue[0]['is_live'] = true;
}

$liveItem = $stageQueue[$currentLiveIndex] ?? ($stageQueue[0] ?? [
    'chest_number' => '08',
    'performer_name' => 'Muhammed Shamil',
    'team_name' => 'Darul Huda Islamic Center',
    'title' => 'QURAN RECITATION',
    'subtitle' => 'Juniors (Boys)',
    'order' => 8,
    'program_id' => 0,
    'entry_id' => 0
]);

// Queue Neighbors
$nextItem  = $stageQueue[$currentLiveIndex + 1] ?? null;
$thenItem  = $stageQueue[$currentLiveIndex + 2] ?? null;
$afterItem = $stageQueue[$currentLiveIndex + 3] ?? null;

// Program stats for current active program
$currProgTotalEntries = count($activeProgramEntries);
$currProgCompleted = 0;
$currProgCurrentOrder = 1;
if ($liveEntryId > 0 && !empty($activeProgramEntries)) {
    foreach ($activeProgramEntries as $idx => $e) {
        if ((int)$e['id'] === $liveEntryId) {
            $currProgCurrentOrder = (int)$e['performance_order'];
            $currProgCompleted = max(0, $currProgCurrentOrder - 1);
            break;
        }
    }
}
$currProgRemaining = max(0, $currProgTotalEntries - $currProgCompleted);
$currProgPercent = $currProgTotalEntries > 0 ? round(($currProgCompleted / $currProgTotalEntries) * 100) : 0;

// Date formatting
$eventStartDate = !empty($activeEvent['start_date']) ? date('d M Y', strtotime($activeEvent['start_date'])) : date('d M Y');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- Emcee Stage Control Deck Styles -->
<style>
:root {
    --deck-bg: #060c09;
    --deck-panel-bg: rgba(11, 20, 16, 0.85);
    --deck-card-bg: rgba(16, 30, 24, 0.7);
    --deck-border: rgba(16, 185, 129, 0.22);
    --deck-border-glow: rgba(16, 185, 129, 0.45);
    --deck-accent-green: #10b981;
    --deck-neon-green: #00ff87;
    --deck-text-bright: #ffffff;
    --deck-text-muted: #9ca3af;
    --deck-gold: #fbbf24;
}

body {
    background-color: var(--deck-bg) !important;
}

.main-content {
    background: radial-gradient(circle at 50% 0%, #0d261b 0%, #060c09 70%) !important;
    min-height: 100vh;
    padding: 20px 24px 32px 24px !important;
    margin-left: calc(var(--sidebar-width-expanded, 280px) + (var(--sidebar-margin, 16px) * 2)) !important;
    transition: margin-left 0.22s ease !important;
    color: #e5e7eb;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

body.layout-sidebar-enabled.sidebar-collapsed .main-content {
    margin-left: calc(var(--sidebar-width-collapsed, 80px) + (var(--sidebar-margin, 16px) * 2)) !important;
}

/* Master Top Header Bar */
.deck-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(12, 24, 19, 0.9);
    border: 1px solid var(--deck-border);
    backdrop-filter: blur(12px);
    border-radius: 16px;
    padding: 12px 24px;
    margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
}

.deck-brand-area {
    display: flex;
    align-items: center;
    gap: 14px;
}

.deck-brand-logo {
    width: 42px;
    height: 42px;
    object-fit: contain;
    filter: drop-shadow(0 0 8px rgba(16, 185, 129, 0.6));
}

.deck-brand-title {
    font-size: 14px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: #f3f4f6;
    text-transform: uppercase;
}

.deck-brand-sub {
    font-size: 11px;
    color: var(--deck-accent-green);
    font-weight: 600;
    letter-spacing: 0.05em;
}

.deck-center-heading {
    text-align: center;
}

.deck-title-main {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 900;
    letter-spacing: 0.1em;
    color: #ffffff;
    text-transform: uppercase;
}

.deck-title-main i {
    color: var(--deck-neon-green);
    filter: drop-shadow(0 0 10px var(--deck-neon-green));
}

.deck-title-sub {
    font-size: 12px;
    color: #a7f3d0;
    font-weight: 500;
}

.deck-top-widgets {
    display: flex;
    align-items: center;
    gap: 16px;
}

.deck-pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.3);
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    color: #34d399;
}

.pulse-dot-green {
    width: 8px;
    height: 8px;
    background-color: var(--deck-neon-green);
    border-radius: 50%;
    box-shadow: 0 0 10px var(--deck-neon-green);
    animation: pulseGlow 1.8s infinite ease-in-out;
}

@keyframes pulseGlow {
    0%, 100% { transform: scale(0.9); opacity: 0.5; }
    50% { transform: scale(1.2); opacity: 1; }
}

.deck-clock-box {
    background: rgba(0, 0, 0, 0.6);
    border: 1px solid var(--deck-border);
    padding: 6px 16px;
    border-radius: 12px;
    font-family: monospace, monospace;
    font-size: 16px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.08em;
    box-shadow: inset 0 0 12px rgba(16, 185, 129, 0.15);
}

.deck-status-online {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(6, 78, 59, 0.5);
    border: 1px solid rgba(52, 211, 153, 0.4);
    padding: 6px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    color: #a7f3d0;
}

/* Master Layout 3-Column Grid */
.deck-main-grid {
    display: grid;
    grid-template-columns: 280px 1fr 300px;
    gap: 20px;
    align-items: start;
}

@media (max-width: 1280px) {
    .deck-main-grid {
        grid-template-columns: 260px 1fr;
    }
    .deck-right-col {
        grid-column: span 2;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
}

@media (max-width: 900px) {
    .deck-main-grid {
        grid-template-columns: 1fr;
    }
    .deck-right-col {
        grid-column: span 1;
        grid-template-columns: 1fr;
    }
}

/* Glassmorphism Panel Container */
.deck-panel {
    background: var(--deck-panel-bg);
    border: 1px solid var(--deck-border);
    border-radius: 18px;
    padding: 20px;
    backdrop-filter: blur(16px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.deck-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: #6ee7b7;
    text-transform: uppercase;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.deck-panel-header i {
    margin-right: 6px;
}

/* Overview & Info Data Rows */
.deck-meta-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.deck-meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.deck-meta-label {
    font-size: 11px;
    color: var(--deck-text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.deck-meta-val {
    font-size: 14px;
    font-weight: 700;
    color: #ffffff;
}

.deck-progress-wrapper {
    margin-top: 14px;
}

.deck-progress-bar-bg {
    width: 100%;
    height: 8px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 999px;
    overflow: hidden;
    margin-top: 8px;
}

.deck-progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #00ff87);
    border-radius: 999px;
    box-shadow: 0 0 12px rgba(0, 255, 135, 0.8);
    transition: width 0.4s ease;
}

/* Event Health Items */
.deck-health-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(16, 30, 24, 0.5);
    border-radius: 10px;
    margin-bottom: 8px;
    border: 1px solid rgba(255, 255, 255, 0.04);
}

.deck-health-name {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: #d1d5db;
}

.deck-health-status {
    font-size: 11.5px;
    font-weight: 700;
    color: #34d399;
}

.deck-health-status.is-off {
    color: #ef4444;
}

.deck-announcer-toggle {
    margin-top: 16px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid var(--deck-border);
    padding: 10px 14px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all 0.2s ease;
}

.deck-announcer-toggle:hover {
    background: rgba(16, 185, 129, 0.2);
    border-color: var(--deck-accent-green);
}

/* Center Stage Viewport Box */
.deck-stage-hero {
    background: radial-gradient(circle at 50% 30%, #064e3b 0%, #031c15 65%, #02120d 100%);
    border: 2px solid var(--deck-border-glow);
    border-radius: 24px;
    padding: 32px 24px;
    position: relative;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.7), inset 0 0 60px rgba(16, 185, 129, 0.15);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    min-height: 380px;
    justify-content: space-between;
    overflow: hidden;
}

.deck-stage-hero-top {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.badge-live-stage {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(220, 38, 38, 0.9);
    color: #ffffff;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.08em;
    padding: 6px 14px;
    border-radius: 999px;
    box-shadow: 0 0 16px rgba(239, 68, 68, 0.6);
    text-transform: uppercase;
}

.badge-broadcasting {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(6, 78, 59, 0.9);
    border: 1px solid var(--deck-neon-green);
    color: var(--deck-neon-green);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.08em;
    padding: 6px 14px;
    border-radius: 999px;
    box-shadow: 0 0 16px rgba(0, 255, 135, 0.3);
    text-transform: uppercase;
}

/* Islamic Illuminated Stage Graphic Backdrop */
.deck-stage-visual-container {
    margin: 20px 0;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    max-width: 520px;
}

.deck-islamic-arch-svg {
    position: absolute;
    top: -30px;
    width: 380px;
    height: 300px;
    pointer-events: none;
    opacity: 0.45;
    filter: drop-shadow(0 0 25px rgba(16, 185, 129, 0.8));
}

.deck-chest-badge {
    background: rgba(0, 0, 0, 0.7);
    border: 1px solid var(--deck-border-glow);
    padding: 6px 20px;
    border-radius: 12px;
    margin-bottom: 12px;
    backdrop-filter: blur(8px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
    z-index: 2;
}

.deck-chest-label {
    font-size: 10px;
    font-weight: 800;
    color: var(--deck-text-muted);
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.deck-chest-num {
    font-size: 32px;
    font-weight: 900;
    color: #ffffff;
    line-height: 1;
    font-family: monospace, monospace;
}

.deck-performer-name {
    font-size: 34px;
    font-weight: 900;
    color: #ffffff;
    letter-spacing: -0.01em;
    text-shadow: 0 4px 20px rgba(0, 0, 0, 0.8), 0 0 20px rgba(16, 185, 129, 0.3);
    z-index: 2;
    margin-bottom: 6px;
}

.deck-performer-team {
    font-size: 16px;
    font-weight: 700;
    color: var(--deck-neon-green);
    letter-spacing: 0.02em;
    z-index: 2;
    margin-bottom: 16px;
    text-shadow: 0 0 12px rgba(0, 255, 135, 0.4);
}

.deck-onstage-pill {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    font-size: 12px;
    font-weight: 800;
    padding: 6px 20px;
    border-radius: 999px;
    box-shadow: 0 0 20px rgba(16, 185, 129, 0.6);
    letter-spacing: 0.08em;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.deck-stage-timer-box {
    align-self: flex-end;
    background: rgba(0, 0, 0, 0.65);
    border: 1px solid var(--deck-border);
    padding: 6px 14px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--deck-text-muted);
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
}

.deck-timer-val {
    font-size: 18px;
    font-weight: 900;
    color: #ffffff;
    font-family: monospace, monospace;
}

/* Timeline Horizontal Nodes */
.deck-timeline-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    padding: 16px 8px;
    overflow-x: auto;
    gap: 12px;
}

.deck-timeline-line {
    position: absolute;
    top: 50%;
    left: 20px;
    right: 20px;
    height: 3px;
    background: rgba(255, 255, 255, 0.1);
    transform: translateY(-50%);
    z-index: 1;
}

.deck-timeline-node {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    cursor: pointer;
}

.node-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #0d1b14;
    border: 2px solid rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    color: #9ca3af;
    transition: all 0.2s ease;
}

.deck-timeline-node.completed .node-circle {
    background: #065f46;
    border-color: var(--deck-accent-green);
    color: #ffffff;
}

.deck-timeline-node.active .node-circle {
    background: var(--deck-accent-green);
    border-color: var(--deck-neon-green);
    color: #000000;
    box-shadow: 0 0 16px var(--deck-neon-green);
    transform: scale(1.15);
}

.node-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--deck-text-muted);
}

.deck-timeline-node.active .node-label {
    color: var(--deck-neon-green);
}

/* Broadcast Switcher Grid */
.deck-switcher-grid {
    display: grid;
    grid-template-columns: 1.8fr repeat(4, 1fr);
    gap: 12px;
    align-items: stretch;
}

@media (max-width: 1100px) {
    .deck-switcher-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .deck-btn-golive {
        grid-column: span 3 !important;
        grid-row: span 1 !important;
        min-height: 70px !important;
    }
}

.deck-btn-golive {
    grid-column: span 1;
    grid-row: span 2;
    height: 100%;
    min-height: 110px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: 2px solid var(--deck-neon-green);
    border-radius: 16px;
    padding: 16px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.25s ease;
    box-shadow: 0 0 25px rgba(16, 185, 129, 0.4);
}

.deck-btn-golive:hover {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 0 35px rgba(0, 255, 135, 0.6);
}

.deck-btn-action {
    background: rgba(16, 30, 24, 0.7);
    border: 1px solid var(--deck-border);
    border-radius: 14px;
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #e5e7eb;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
}

.deck-btn-action i {
    font-size: 18px;
    color: var(--deck-accent-green);
}

.deck-btn-action:hover {
    background: rgba(16, 185, 129, 0.15);
    border-color: var(--deck-accent-green);
    transform: translateY(-2px);
}

/* Stage Queue Cards Right Column */
.deck-queue-card {
    background: rgba(16, 30, 24, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s ease;
}

.deck-queue-card.is-live {
    background: rgba(16, 185, 129, 0.15);
    border-color: var(--deck-accent-green);
    box-shadow: 0 0 16px rgba(16, 185, 129, 0.2);
}

.deck-queue-chest {
    font-size: 14px;
    font-weight: 900;
    color: var(--deck-accent-green);
    margin-right: 10px;
    font-family: monospace, monospace;
}

.deck-queue-info {
    flex: 1;
}

.deck-queue-name {
    font-size: 13px;
    font-weight: 800;
    color: #ffffff;
}

.deck-queue-sub {
    font-size: 11px;
    color: var(--deck-text-muted);
}

/* Bottom Slide Strip Carousel */
.deck-slide-strip-container {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 10px;
}

.deck-strip-scroll {
    display: flex;
    align-items: center;
    gap: 12px;
    overflow-x: auto;
    scroll-behavior: smooth;
    padding: 8px 4px;
    flex: 1;
}

.deck-strip-card {
    min-width: 140px;
    height: 90px;
    background: rgba(16, 30, 24, 0.7);
    border: 1px solid var(--deck-border);
    border-radius: 14px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    user-select: none;
}

.deck-strip-card:hover {
    border-color: var(--deck-accent-green);
    transform: translateY(-2px);
}

.deck-strip-card.active {
    border-color: var(--deck-neon-green);
    background: rgba(16, 185, 129, 0.2);
    box-shadow: 0 0 18px rgba(0, 255, 135, 0.3);
}

.deck-strip-arrow {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(16, 30, 24, 0.8);
    border: 1px solid var(--deck-border);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.deck-strip-arrow:hover {
    background: var(--deck-accent-green);
    color: #000000;
}

/* Bottom Controls Bar */
.deck-bottom-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(11, 20, 16, 0.9);
    border: 1px solid var(--deck-border);
    border-radius: 16px;
    padding: 12px 20px;
    margin-top: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.deck-emergency-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn-emergency {
    background: rgba(185, 28, 28, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.4);
    color: #fca5a5;
    font-size: 11px;
    font-weight: 800;
    padding: 6px 12px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    text-transform: uppercase;
}

.btn-emergency:hover {
    background: #dc2626;
    color: #ffffff;
    box-shadow: 0 0 12px rgba(239, 68, 68, 0.6);
}

.deck-kbd-hint {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 11px;
    color: #ffffff;
}

/* Modal Styling */
.deck-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.deck-modal-content {
    background: var(--deck-panel-bg);
    border: 1px solid var(--deck-border-glow);
    border-radius: 20px;
    width: 90%;
    max-width: 700px;
    max-height: 80vh;
    overflow-y: auto;
    padding: 24px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8);
}
</style>

<div class="main-content">
<!-- Top Master Deck Navigation Header Bar -->
<div class="deck-top-bar">
    <div class="deck-brand-area">
        <img src="<?= asset_url('images/green-v-logo.svg') ?>" alt="Kauzariyya Logo" class="deck-brand-logo">
        <div>
            <div class="deck-brand-title">KAUZARIYYA MUSABAQA</div>
            <div class="deck-brand-sub"><?= e($activeEvent['title'] ?? '2026-27') ?></div>
        </div>
    </div>

    <div class="deck-center-heading">
        <div class="deck-title-main">
            <i data-lucide="tv"></i> STAGE LIVE MONITOR
        </div>
        <div class="deck-title-sub">Real-Time Live Stage View</div>
    </div>

    <div class="deck-top-widgets">
        <div class="deck-pill-badge">
            <span class="pulse-dot-green"></span>
            LIVE MONITORING • <?= e($eventStartDate) ?>
        </div>
        <div class="deck-clock-box" id="deckLiveClock">
            --:--:-- AM
        </div>
        <div class="deck-status-online">
            <i data-lucide="wifi"></i> Auto Sync Active
        </div>
    </div>
</div>

<!-- Master 3-Column Deck Layout -->
<div class="deck-main-grid">
    
    <!-- LEFT COLUMN: Event Overview & Program Progress -->
    <div class="deck-left-col">
        <!-- Event Overview Panel -->
        <div class="deck-panel">
            <div class="deck-panel-header">
                <span><i data-lucide="layers"></i> Event Overview</span>
            </div>
            <div class="deck-meta-list">
                <div class="deck-meta-item">
                    <span class="deck-meta-label">Event</span>
                    <span class="deck-meta-val"><?= e($activeEvent['title'] ?? 'Kauzariyya Musabaqa') ?></span>
                </div>
                <div class="deck-meta-item">
                    <span class="deck-meta-label">Venue</span>
                    <span class="deck-meta-val"><?= e($activeEvent['location'] ?? 'Main Auditorium') ?></span>
                </div>
                <div class="deck-meta-item">
                    <span class="deck-meta-label">Division</span>
                    <span class="deck-meta-val"><?= e($activeProgram['class_type_name'] ?? 'Juniors (Boys)') ?></span>
                </div>
                <div class="deck-meta-item">
                    <span class="deck-meta-label">Programs Today</span>
                    <span class="deck-meta-val"><?= count($programs) ?> Programs</span>
                </div>
                <div class="deck-meta-item">
                    <span class="deck-meta-label">Total Entries</span>
                    <span class="deck-meta-val"><?= $totalEntriesCount ?></span>
                </div>
            </div>
        </div>

        <!-- Program Info Panel -->
        <div class="deck-panel">
            <div class="deck-panel-header">
                <span><i data-lucide="book-open"></i> Program Info</span>
            </div>
            <div class="deck-meta-list">
                <div class="deck-meta-item">
                    <span class="deck-meta-label">Current Program</span>
                    <span class="deck-meta-val" id="deckCurrentProgTitle" style="color: var(--deck-neon-green); font-size: 15px;">
                        <?= e($activeProgram['title'] ?? $activeProgram['name'] ?? 'QURAN RECITATION') ?>
                    </span>
                    <span id="deckCurrentProgType" style="font-size: 11px; color: var(--deck-text-muted);">
                        <?= ($activeProgram['program_type'] ?? '') === 'group' ? 'Group Performance' : 'Individual Performance' ?>
                    </span>
                </div>
                <div class="deck-meta-item" style="margin-top: 6px;">
                    <span class="deck-meta-label">Entry No.</span>
                    <span class="deck-meta-val" id="deckCurrentEntryOrder" style="font-size: 18px; font-family: monospace;">
                        <?= sprintf('%02d', $currProgCurrentOrder) ?> / <?= sprintf('%02d', $currProgTotalEntries) ?>
                    </span>
                </div>
            </div>
            <div class="deck-progress-wrapper">
                <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--deck-text-muted); font-weight: 700;">
                    <span id="deckProgTotalText">Total: <?= $currProgTotalEntries ?></span>
                    <span id="deckProgCompText">Completed: <?= $currProgCompleted ?></span>
                    <span id="deckProgRemText">Remaining: <?= $currProgRemaining ?></span>
                </div>
                <div class="deck-progress-bar-bg">
                    <div class="deck-progress-bar-fill" id="deckProgressBarFill" style="width: <?= $currProgPercent ?>%;"></div>
                </div>
                <div id="deckProgPercentText" style="text-align: right; font-size: 10px; color: var(--deck-accent-green); font-weight: 800; margin-top: 4px;">
                    <?= $currProgPercent ?>%
                </div>
            </div>
        </div>
    </div>

    <!-- CENTER COLUMN: Stage Visual Viewport -->
    <div class="deck-center-col">
        <!-- Master Stage Viewport Card -->
        <div class="deck-stage-hero">
            <div class="deck-stage-hero-top">
                <div class="badge-live-stage">
                    <span class="pulse-dot-green" style="background:#ffffff;"></span> LIVE ON STAGE
                </div>
                <div class="badge-broadcasting">
                    <i data-lucide="radio" style="width:12px;"></i> BROADCASTING
                </div>
            </div>

            <div class="deck-stage-visual-container">
                <!-- Islamic Architectural Arch Vector Backdrop -->
                <svg class="deck-islamic-arch-svg" viewBox="0 0 400 300" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M200 10 C 130 60, 50 120, 50 290 L 350 290 C 350 120, 270 60, 200 10 Z" stroke="#10b981" stroke-width="3" fill="none" opacity="0.6"/>
                    <path d="M200 30 C 145 75, 75 130, 75 290 L 325 290 C 325 130, 255 75, 200 30 Z" stroke="#fbbf24" stroke-width="1.5" fill="none" opacity="0.4"/>
                    <ellipse cx="200" cy="270" rx="140" ry="20" fill="url(#stageGlow)" opacity="0.8"/>
                    <defs>
                        <radialGradient id="stageGlow" cx="50%" cy="50%" r="50%">
                            <stop offset="0%" stop-color="#00ff87" stop-opacity="0.6"/>
                            <stop offset="100%" stop-color="#10b981" stop-opacity="0"/>
                        </radialGradient>
                    </defs>
                </svg>

                <div class="deck-chest-badge">
                    <div class="deck-chest-label">CHEST NO.</div>
                    <div class="deck-chest-num" id="liveChestDisplay"><?= e($liveItem['chest_number']) ?></div>
                </div>

                <div class="deck-performer-name" id="liveNameDisplay"><?= e($liveItem['performer_name']) ?></div>
                <div class="deck-performer-team" id="liveTeamDisplay"><?= e($liveItem['team_name']) ?></div>

                <div class="deck-onstage-pill">
                    <i data-lucide="mic"></i> ON STAGE
                </div>
            </div>

            <div class="deck-stage-timer-box">
                <i data-lucide="clock"></i> TIME ON STAGE:
                <span class="deck-timer-val" id="stageTimerDisplay">00:00</span>
            </div>
        </div>

        <!-- Program Timeline (Horizontal Nodes) -->
        <div class="deck-panel" style="margin-top: 20px;">
            <div class="deck-panel-header">
                <span><i data-lucide="git-commit"></i> Program Timeline</span>
                <span style="font-size: 10px; color: var(--deck-text-muted);">Real-Time Progress</span>
            </div>
            <div class="deck-timeline-bar" id="timelineBar">
                <div class="deck-timeline-line"></div>
                <?php 
                $maxNodes = min(12, count($activeProgramEntries));
                for ($n = 1; $n <= max(12, $maxNodes); $n++): 
                    $isComp = ($n < $currProgCurrentOrder);
                    $isAct = ($n === $currProgCurrentOrder);
                    $cls = $isAct ? 'active' : ($isComp ? 'completed' : '');
                ?>
                    <div class="deck-timeline-node <?= $cls ?>" onclick="jumpToEntryNode(<?= $n ?>)">
                        <div class="node-circle">
                            <?= $isComp ? '<i data-lucide="check" style="width:14px;"></i>' : sprintf('%02d', $n) ?>
                        </div>
                        <div class="node-label"><?= $isAct ? 'LIVE NOW' : ($isComp ? 'DONE' : '') ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Stage Queue & System Connections -->
    <div class="deck-right-col">
        <!-- Stage Queue Panel -->
        <div class="deck-panel" id="stageQueuePanel">
            <div class="deck-panel-header">
                <span><i data-lucide="list-ordered"></i> Stage Queue</span>
            </div>
            <div id="stageQueuePanelContent">
                <!-- LIVE NOW -->
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-neon-green); margin-bottom: 6px; letter-spacing: 0.08em;">
                    • LIVE NOW
                </div>
                <div class="deck-queue-card is-live">
                    <div class="deck-queue-chest"><?= e($liveItem['chest_number']) ?></div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name"><?= e($liveItem['performer_name']) ?></div>
                        <div class="deck-queue-sub"><?= e($liveItem['team_name']) ?></div>
                    </div>
                    <i data-lucide="mic" style="color: var(--deck-neon-green); width:16px;"></i>
                </div>

                <!-- NEXT -->
                <?php if ($nextItem): ?>
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-text-muted); margin: 10px 0 6px 0; letter-spacing: 0.08em;">
                    • NEXT
                </div>
                <div class="deck-queue-card" onclick="broadcastSpecific(<?= (int)$nextItem['program_id'] ?>, <?= (int)$nextItem['entry_id'] ?>)" style="cursor:pointer;">
                    <div class="deck-queue-chest"><?= e($nextItem['chest_number']) ?></div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name"><?= e($nextItem['performer_name']) ?></div>
                        <div class="deck-queue-sub"><?= e($nextItem['team_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- THEN -->
                <?php if ($thenItem): ?>
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-text-muted); margin: 10px 0 6px 0; letter-spacing: 0.08em;">
                    • THEN
                </div>
                <div class="deck-queue-card" onclick="broadcastSpecific(<?= (int)$thenItem['program_id'] ?>, <?= (int)$thenItem['entry_id'] ?>)" style="cursor:pointer;">
                    <div class="deck-queue-chest"><?= e($thenItem['chest_number']) ?></div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name"><?= e($thenItem['performer_name']) ?></div>
                        <div class="deck-queue-sub"><?= e($thenItem['team_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- AFTER -->
                <?php if ($afterItem): ?>
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-text-muted); margin: 10px 0 6px 0; letter-spacing: 0.08em;">
                    • AFTER
                </div>
                <div class="deck-queue-card" onclick="broadcastSpecific(<?= (int)$afterItem['program_id'] ?>, <?= (int)$afterItem['entry_id'] ?>)" style="cursor:pointer;">
                    <div class="deck-queue-chest"><?= e($afterItem['chest_number']) ?></div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name"><?= e($afterItem['performer_name']) ?></div>
                        <div class="deck-queue-sub"><?= e($afterItem['team_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <button class="btn btn-secondary btn-sm w-100 mt-3" style="background: rgba(16,30,24,0.8); border: 1px solid var(--deck-border); color:#ffffff; font-weight:700;" onclick="openFullQueueModal()">
                    <i data-lucide="list"></i> View Full Queue
                </button>
            </div>
        </div>
    </div>
</div>

<!-- BOTTOM SLIDE STRIP CAROUSEL -->
<div class="deck-panel" style="margin-top: 20px;">
    <div class="deck-panel-header">
        <span><i data-lucide="film"></i> Stage Cards / Slide Strip</span>
    </div>
    <div class="deck-slide-strip-container">
        <button class="deck-strip-arrow" onclick="scrollStrip(-200)"><i data-lucide="chevron-left"></i></button>
        
        <div class="deck-strip-scroll" id="slideStripScroll">
            <?php foreach ($stageQueue as $qIdx => $sq): ?>
                <div class="deck-strip-card <?= $sq['is_live'] ? 'active' : '' ?>">
                    <?php if ($sq['is_live']): ?>
                        <span style="position: absolute; top:4px; right:4px; background:#ef4444; color:#fff; font-size:9px; font-weight:900; padding:2px 6px; border-radius:4px;">LIVE</span>
                    <?php endif; ?>
                    <i data-lucide="<?= $sq['type'] === 'intro' ? 'file-text' : 'user' ?>" style="color: var(--deck-accent-green); width:18px; margin-bottom:4px;"></i>
                    <div style="font-size:11px; font-weight:900; color:#fff; font-family:monospace;"><?= e($sq['chest_number']) ?></div>
                    <div style="font-size:10px; color:var(--deck-text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px;">
                        <?= e($sq['performer_name']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button class="deck-strip-arrow" onclick="scrollStrip(200)"><i data-lucide="chevron-right"></i></button>
    </div>
</div>

<!-- BOTTOM FOOTER BAR: LIVE SYNC & STATUS -->
<div class="deck-bottom-bar">
    <div style="display: flex; align-items: center; gap: 16px; font-size: 11.5px; font-weight: 700; color: var(--deck-text-muted);">
        <span><i data-lucide="tv"></i> STAGE LIVE MONITORING</span>
        <span style="color: var(--deck-accent-green);"><i data-lucide="refresh-cw" style="width:12px;"></i> Auto-Sync Active</span>
    </div>

    <div style="font-size: 11.5px; font-weight: 700; color: var(--deck-text-muted);">
        <span>Displaying Live Contestant & Stage Progress</span>
    </div>
</div>

<!-- FULL QUEUE MODAL -->
<div class="deck-modal" id="fullQueueModal">
    <div class="deck-modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="margin:0; font-size:18px; font-weight:900; color:#fff;"><i data-lucide="list"></i> Full Stage Queue</h3>
            <button class="btn btn-sm btn-secondary" onclick="closeFullQueueModal()">Close</button>
        </div>
        <div class="deck-meta-list">
            <?php foreach ($stageQueue as $idx => $item): ?>
                <div class="deck-queue-card <?= $item['is_live'] ? 'is-live' : '' ?>" style="margin-bottom:8px;">
                    <div class="deck-queue-chest"><?= e($item['chest_number']) ?></div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name"><?= e($item['performer_name']) ?></div>
                        <div class="deck-queue-sub"><?= e($item['title']) ?> (<?= e($item['team_name']) ?>)</div>
                    </div>
                    <span class="badge <?= $item['is_live'] ? 'badge-success' : 'badge-neutral' ?>" style="font-size: 11px; padding: 4px 10px;">
                        <?= $item['is_live'] ? 'ON STAGE' : 'QUEUED' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Master Deck Zero-Refresh Real-Time Engine -->
<script>
let currentLiveIndex = <?= (int)$currentLiveIndex ?>;
const stageQueueData = <?= json_encode($stageQueue, JSON_UNESCAPED_UNICODE) ?>;
let stageElapsedSeconds = <?= (int)$stageElapsed ?>;
let stageTimerInterval = null;
let voiceAnnouncerEnabled = false;

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Sleek Floating Toast Notification
function showToast(msg, type = 'success') {
    let container = document.getElementById('deckToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'deckToastContainer';
        container.style.cssText = 'position:fixed; bottom:24px; right:24px; z-index:99999; display:flex; flex-direction:column; gap:10px; pointer-events:none;';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    const isError = (type === 'error');
    toast.style.cssText = `
        background: ${isError ? 'rgba(220, 38, 38, 0.95)' : 'rgba(16, 185, 129, 0.95)'};
        color: #ffffff;
        padding: 12px 20px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 800;
        letter-spacing: 0.03em;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(10px);
        transform: translateY(20px);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        pointer-events: auto;
    `;
    toast.textContent = msg;
    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
    });

    setTimeout(() => {
        toast.style.transform = 'translateY(-10px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 2800);
}

// Real-Time Digital Clock Handler
function updateLiveClock() {
    const now = new Date();
    const clockEl = document.getElementById('deckLiveClock');
    if (clockEl) {
        clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    }
}
setInterval(updateLiveClock, 1000);
updateLiveClock();

// Stage Timer Counter
function startStageTimer() {
    if (stageTimerInterval) clearInterval(stageTimerInterval);
    stageTimerInterval = setInterval(() => {
        stageElapsedSeconds++;
        const mins = String(Math.floor(stageElapsedSeconds / 60)).padStart(2, '0');
        const secs = String(stageElapsedSeconds % 60).padStart(2, '0');
        const timerEl = document.getElementById('stageTimerDisplay');
        if (timerEl) {
            timerEl.textContent = `${mins}:${secs}`;
        }
    }, 1000);
}
startStageTimer();

// Zero-Refresh Dynamic Deck UI Updater
function updateDeckUI(pId, eId) {
    const newIdx = stageQueueData.findIndex(item => item.program_id == pId && item.entry_id == eId);
    if (newIdx < 0) return;

    currentLiveIndex = newIdx;
    const liveItem = stageQueueData[newIdx];
    lastPolledProgId = pId;
    lastPolledEntryId = eId;

    // 1. Center Hero Box Text
    const chestDisplay = document.getElementById('liveChestDisplay');
    const nameDisplay = document.getElementById('liveNameDisplay');
    const teamDisplay = document.getElementById('liveTeamDisplay');
    if (chestDisplay) chestDisplay.textContent = liveItem.chest_number;
    if (nameDisplay) nameDisplay.textContent = liveItem.performer_name;
    if (teamDisplay) teamDisplay.textContent = liveItem.team_name;

    // 2. Stage Timer Reset
    stageElapsedSeconds = 0;
    const timerEl = document.getElementById('stageTimerDisplay');
    if (timerEl) timerEl.textContent = '00:00';

    // 3. Stage Queue Side Panel (LIVE NOW, NEXT, THEN, AFTER)
    const nextItem = stageQueueData[newIdx + 1] || null;
    const thenItem = stageQueueData[newIdx + 2] || null;
    const afterItem = stageQueueData[newIdx + 3] || null;

    const queueContent = document.getElementById('stageQueuePanelContent');
    if (queueContent) {
        let html = `
            <div style="font-size: 10px; font-weight: 800; color: var(--deck-neon-green); margin-bottom: 6px; letter-spacing: 0.08em;">
                • LIVE NOW
            </div>
            <div class="deck-queue-card is-live">
                <div class="deck-queue-chest">${escapeHtml(liveItem.chest_number)}</div>
                <div class="deck-queue-info">
                    <div class="deck-queue-name">${escapeHtml(liveItem.performer_name)}</div>
                    <div class="deck-queue-sub">${escapeHtml(liveItem.team_name)}</div>
                </div>
                <i data-lucide="mic" style="color: var(--deck-neon-green); width:16px;"></i>
            </div>
        `;

        if (nextItem) {
            html += `
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-text-muted); margin: 10px 0 6px 0; letter-spacing: 0.08em;">
                    • NEXT
                </div>
                <div class="deck-queue-card" onclick="broadcastSpecific(${nextItem.program_id}, ${nextItem.entry_id})" style="cursor:pointer;">
                    <div class="deck-queue-chest">${escapeHtml(nextItem.chest_number)}</div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name">${escapeHtml(nextItem.performer_name)}</div>
                        <div class="deck-queue-sub">${escapeHtml(nextItem.team_name)}</div>
                    </div>
                </div>
            `;
        }

        if (thenItem) {
            html += `
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-text-muted); margin: 10px 0 6px 0; letter-spacing: 0.08em;">
                    • THEN
                </div>
                <div class="deck-queue-card" onclick="broadcastSpecific(${thenItem.program_id}, ${thenItem.entry_id})" style="cursor:pointer;">
                    <div class="deck-queue-chest">${escapeHtml(thenItem.chest_number)}</div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name">${escapeHtml(thenItem.performer_name)}</div>
                        <div class="deck-queue-sub">${escapeHtml(thenItem.team_name)}</div>
                    </div>
                </div>
            `;
        }

        if (afterItem) {
            html += `
                <div style="font-size: 10px; font-weight: 800; color: var(--deck-text-muted); margin: 10px 0 6px 0; letter-spacing: 0.08em;">
                    • AFTER
                </div>
                <div class="deck-queue-card" onclick="broadcastSpecific(${afterItem.program_id}, ${afterItem.entry_id})" style="cursor:pointer;">
                    <div class="deck-queue-chest">${escapeHtml(afterItem.chest_number)}</div>
                    <div class="deck-queue-info">
                        <div class="deck-queue-name">${escapeHtml(afterItem.performer_name)}</div>
                        <div class="deck-queue-sub">${escapeHtml(afterItem.team_name)}</div>
                    </div>
                </div>
            `;
        }

        html += `
            <button class="btn btn-secondary btn-sm w-100 mt-3" style="background: rgba(16,30,24,0.8); border: 1px solid var(--deck-border); color:#ffffff; font-weight:700;" onclick="openFullQueueModal()">
                <i data-lucide="list"></i> View Full Queue
            </button>
        `;

        queueContent.innerHTML = html;
    }

    // 4. Slide Strip Cards Highlights
    const cards = document.querySelectorAll('.deck-strip-card');
    cards.forEach((card, idx) => {
        const isCur = (idx === newIdx);
        card.classList.toggle('active', isCur);
        let liveBadge = card.querySelector('.live-card-badge');
        if (isCur) {
            if (!liveBadge) {
                const b = document.createElement('span');
                b.className = 'live-card-badge';
                b.style.cssText = 'position: absolute; top:4px; right:4px; background:#ef4444; color:#fff; font-size:9px; font-weight:900; padding:2px 6px; border-radius:4px;';
                b.textContent = 'LIVE';
                card.appendChild(b);
            }
        } else if (liveBadge) {
            liveBadge.remove();
        }
    });

    // 5. Timeline Nodes Highlights
    const order = liveItem.order || 0;
    const nodes = document.querySelectorAll('.deck-timeline-node');
    nodes.forEach((node, idx) => {
        const n = idx + 1;
        node.classList.remove('active', 'completed');
        const circle = node.querySelector('.node-circle');
        const label = node.querySelector('.node-label');

        if (n === order) {
            node.classList.add('active');
            if (circle) circle.innerHTML = String(n).padStart(2, '0');
            if (label) label.textContent = 'LIVE NOW';
        } else if (n < order) {
            node.classList.add('completed');
            if (circle) circle.innerHTML = '<i data-lucide="check" style="width:14px;"></i>';
            if (label) label.textContent = 'DONE';
        } else {
            if (circle) circle.innerHTML = String(n).padStart(2, '0');
            if (label) label.textContent = '';
        }
    });

    if (window.lucide) lucide.createIcons();

    // 6. Voice Announcer Trigger
    if (voiceAnnouncerEnabled) {
        announceCurrentStage();
    }
}

// Zero-Refresh AJAX Broadcast Trigger
function broadcastSpecific(programId, entryId) {
    const formData = new FormData();
    formData.append('action', 'broadcast_stage');
    formData.append('program_id', programId);
    formData.append('entry_id', entryId);

    fetch(window.location.href + (window.location.search ? '&' : '?') + 'ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateDeckUI(programId, entryId);
            showToast(data.message || 'Contestant broadcast live on stage!');
        }
    })
    .catch(err => showToast('Broadcast failed', 'error'));
}

function triggerNextContestant() {
    if (currentLiveIndex < stageQueueData.length - 1) {
        const nextItem = stageQueueData[currentLiveIndex + 1];
        broadcastSpecific(nextItem.program_id, nextItem.entry_id);
    }
}

function triggerPreviousContestant() {
    if (currentLiveIndex > 0) {
        const prevItem = stageQueueData[currentLiveIndex - 1];
        broadcastSpecific(prevItem.program_id, prevItem.entry_id);
    }
}

function triggerMode(mode, slideKey) {
    const formData = new FormData();
    formData.append('action', 'trigger_mode');
    formData.append('mode', mode);
    formData.append('slide_key', slideKey);

    fetch(window.location.href + (window.location.search ? '&' : '?') + 'ajax=1', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(`Stage Mode Switched: ${mode.toUpperCase()}`);
        }
    })
    .catch(err => showToast('Failed to switch stage mode', 'error'));
}

function resetLiveStage() {
    if (stageQueueData.length > 0) {
        broadcastSpecific(stageQueueData[0].program_id, stageQueueData[0].entry_id);
    }
}

function jumpToEntryNode(nodeIndex) {
    const item = stageQueueData.find(q => q.order === nodeIndex);
    if (item) {
        broadcastSpecific(item.program_id, item.entry_id);
    }
}

// Speech Synthesis Voice Announcer
function announceCurrentStage() {
    if ('speechSynthesis' in window) {
        const item = stageQueueData[currentLiveIndex];
        if (!item) return;
        const text = `Next contestant on stage, chest number ${item.chest_number}, ${item.performer_name}, representing ${item.team_name}.`;
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 0.95;
        window.speechSynthesis.speak(utterance);
    }
}

// Toggle Voice Announcer Button
document.getElementById('btnToggleVoice')?.addEventListener('click', () => {
    voiceAnnouncerEnabled = !voiceAnnouncerEnabled;
    const btn = document.getElementById('btnToggleVoice');
    if (btn) {
        if (voiceAnnouncerEnabled) {
            btn.style.background = 'rgba(16, 185, 129, 0.4)';
            btn.style.borderColor = '#00ff87';
            showToast('Voice Announcer Enabled');
            announceCurrentStage();
        } else {
            btn.style.background = 'rgba(16, 185, 129, 0.1)';
            btn.style.borderColor = 'rgba(16, 185, 129, 0.22)';
            showToast('Voice Announcer Disabled');
        }
    }
});

// GO LIVE Primary Button Trigger
document.getElementById('btnGoLive')?.addEventListener('click', () => {
    if (stageQueueData.length > 0) {
        const item = stageQueueData[currentLiveIndex];
        broadcastSpecific(item.program_id, item.entry_id);
    }
});

// Auto-Sync Live State Polling Engine (every 2.5s) without full reloads
let lastPolledProgId = <?= (int)$liveProgramId ?>;
let lastPolledEntryId = <?= (int)$liveEntryId ?>;

function pollDeckState() {
    fetch(window.location.href + (window.location.search ? '&' : '?') + 'poll_deck_state=1')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.live_control) {
            const pId = parseInt(data.live_control.program_id || 0, 10);
            const eId = parseInt(data.live_control.entry_id || 0, 10);
            if (pId !== lastPolledProgId || eId !== lastPolledEntryId) {
                updateDeckUI(pId, eId);
            } else if (typeof data.elapsed_seconds !== 'undefined') {
                stageElapsedSeconds = parseInt(data.elapsed_seconds, 10);
            }
        }
    })
    .catch(err => console.warn('Poll error:', err));
}
setInterval(pollDeckState, 2500);

// Carousel Scroll
function scrollStrip(amount) {
    document.getElementById('slideStripScroll')?.scrollBy({ left: amount, behavior: 'smooth' });
}

// Modal Handlers
function openFullQueueModal() {
    document.getElementById('fullQueueModal').style.display = 'flex';
}
function closeFullQueueModal() {
    document.getElementById('fullQueueModal').style.display = 'none';
}

// Keyboard Shortcuts Listener
document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

    if (e.key === 'ArrowRight') {
        e.preventDefault();
        triggerNextContestant();
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        triggerPreviousContestant();
    } else if (e.code === 'Space') {
        e.preventDefault();
        document.getElementById('btnToggleVoice')?.click();
    } else if (e.key === 'b' || e.key === 'B') {
        e.preventDefault();
        triggerMode('black', 'black');
    } else if (e.key === 'h' || e.key === 'H') {
        e.preventDefault();
        triggerMode('hold', 'hold');
    }
});
</script>

</div>
<?php require_once __DIR__ . '/../../includes/public-footer.php'; ?>
