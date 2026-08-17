<?php

declare(strict_types=1);

if (!defined('LIVE_DISPLAY_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $tvBodyClass = trim(($tvBodyClass ?? '') . ' tv-current-programs-theme');
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'current-program';
    $settings['slides']['current-program']['enabled'] = true;
    $settings['slides']['current-program']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-current-program" data-slide="current-program" style="opacity: 1; visibility: visible; transform: scale(1);">';
}

// Fetch current leader info for dynamic 1st rank team color transition & aura
$leaderboard = tv_leaderboard((int)($event['id'] ?? 0));
$firstTeam = !empty($leaderboard) ? $leaderboard[0] : null;
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#6400a6';
$firstTeamName = !empty($firstTeam['team_name']) ? $firstTeam['team_name'] : 'Leader';

if (!function_exists('tv_format_section_name')) {
    function tv_format_section_name(?string $category): string {
        if (empty($category) || $category === 'Musabaqa Category' || $category === 'All Classes') {
            return 'General';
        }
        $c = trim($category);
        if (str_contains($c, 'العالية') || strcasecmp($c, 'senior') === 0) {
            return 'Senior';
        }
        if (str_contains($c, 'الثانوية') || strcasecmp($c, 'junior') === 0) {
            return 'Junior';
        }
        if (str_contains($c, 'حفظ') || str_contains($c, 'التحصص') || strcasecmp($c, 'sub') === 0 || strcasecmp($c, 'sub junior') === 0) {
            return 'Sub';
        }
        return $c;
    }
}

// Pre-fetch live stage program & performer data for instant server-side rendering
$cpData = tv_current_program((int)($event['id'] ?? 0));
$initProg = $cpData['program'] ?? [];
$initPerf = $cpData['performer'] ?? [];
$initNext = $cpData['next_performer'] ?? [];
$initNextProg = $cpData['next_program'] ?? [];
$initIsIntro = !empty($cpData['is_intro']) || empty($initPerf['id']);
$isBreak = !empty($cpData['is_break']);

$initTitleRaw = !empty($initProg['title']) ? $initProg['title'] : 'No Active Program';
$initCategory = tv_format_section_name($initProg['category'] ?? null);
$initFullTitle = trim($initTitleRaw . ($initCategory !== '' ? ' ' . $initCategory : ''));

$initChest = !empty($initPerf['chest_number']) ? $initPerf['chest_number'] : (!empty($initPerf['number']) ? $initPerf['number'] : '—');
$initPerfName = !empty($initPerf['name']) ? $initPerf['name'] : 'Awaiting Performer';
$initTeamName = !empty($initPerf['team']) ? $initPerf['team'] : '—';
$initTeamColor = !empty($initPerf['team_color']) ? live_display_color($initPerf['team_color'] ?? null) : $firstTeamColor;

$initNextChest = !empty($initNext['chest_number']) ? $initNext['chest_number'] : (!empty($initNext['number']) ? $initNext['number'] : '—');
$initNextColor = !empty($initNext['team_color']) ? live_display_color($initNext['team_color'] ?? null) : $firstTeamColor;

$nextProgTitle = !empty($initNextProg['title']) ? $initNextProg['title'] : ($isBreak ? 'Upcoming Extra' : 'Next Program');
$nextProgCategory = tv_format_section_name($initNextProg['category'] ?? null);
$nextProgTime = !empty($initNextProg['start_label']) ? $initNextProg['start_label'] : (!empty($initNextProg['time']) ? $initNextProg['time'] : 'Scheduled Soon');
?>
<?php if (!defined('LIVE_DISPLAY_STAGE')): ?>
    <script>
        document.body.classList.add('tv-current-programs-theme');
        document.querySelector('.tv-topbar')?.setAttribute('hidden', '');
    </script>
<?php endif; ?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800;900&family=Outfit:wght@600;700;800;900&family=Cairo:wght@700;800;900&display=swap');

    body.tv-current-programs-theme .tv-topbar,
    body:has(#slide-current-program.tv-slide--active) .tv-topbar {
        display: none !important;
    }

    #slide-current-program {
        padding: 0 !important;
        margin: 0 !important;
        overflow: hidden;
        background: transparent !important;
        font-family: 'Plus Jakarta Sans', 'Outfit', 'Cairo', system-ui, -apple-system, sans-serif;
        color: #0f172a;
        width: 100% !important;
        height: 100% !important;
        display: flex;
        align-items: center;
        justify-content: center;
        position: absolute !important;
        inset: 0 !important;
    }

    .current-programs-stage-root {
        width: 100%;
        height: 100%;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent !important;
    }

    /* Light Backdrop Mesh with Subtle Pulse Tinted in 1st Rank Team's Color */
    .ambient-mesh-bg {
        position: absolute;
        inset: 0;
        pointer-events: none;
        z-index: 0;
        background:
            radial-gradient(circle at 15% 20%, color-mix(in srgb, var(--first-team-color) 25%, transparent) 0%, transparent 65%),
            radial-gradient(circle at 85% 80%, color-mix(in srgb, var(--first-team-color) 20%, transparent) 0%, transparent 60%);
        animation: simple-aura-pulse 7s ease-in-out infinite alternate;
    }

    @keyframes simple-aura-pulse {
        0% {
            opacity: 0.7;
            transform: scale(1);
        }

        100% {
            opacity: 1;
            transform: scale(1.05);
        }
    }

    .programs-wrapper {
        --first-team-color: <?= e($firstTeamColor) ?>;
        --current-neon: var(--first-team-color, #6400a6);
        width: 100%;
        max-width: 1840px;
        height: 100%;
        padding: 36px 48px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        box-sizing: border-box;
        position: relative;
        z-index: 2;
    }

    /* Ultra-Smooth Luxury Entrance Slide-In Keyframe Animations */
    @keyframes slide-in-top-pill {
        0% {
            opacity: 0;
            transform: translateY(-50px) scale(0.92);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    @keyframes slide-in-main-card {
        0% {
            opacity: 0;
            transform: translateX(-90px) translateY(30px) scale(0.95);
        }
        100% {
            opacity: 1;
            transform: translateX(0) translateY(0) scale(1);
        }
    }

    @keyframes slide-in-side-card {
        0% {
            opacity: 0;
            transform: translateX(90px) translateY(30px) scale(0.95);
        }
        100% {
            opacity: 1;
            transform: translateX(0) translateY(0) scale(1);
        }
    }

    @keyframes fade-in-silk-lines {
        0% {
            opacity: 0;
            transform: scale(1.06);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }

    /* Elegant 1st Rank Team Indicator Transition Badge */
    .first-team-rank-pill {
        position: absolute;
        top: 40px;
        right: 70px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.92);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1.5px solid color-mix(in srgb, var(--first-team-color) 40%, white);
        padding: 10px 24px;
        border-radius: 30px;
        font-size: 14px;
        font-weight: 800;
        color: #1e293b;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
        z-index: 10;
        transition: border-color 1.2s ease;
        animation: slide-in-top-pill 1.4s cubic-bezier(0.19, 1, 0.22, 1) both;
    }

    .rank-crown {
        font-size: 16px;
    }

    .rank-label {
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.15em;
        color: #64748b;
        text-transform: uppercase;
    }

    .rank-name {
        font-size: 15px;
        font-weight: 900;
        color: #0f172a;
    }

    .rank-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
    }

    /* Main Grid Workspace: Enlarged Main Card + 2 Right Boxes */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1.65fr 1fr;
        gap: 32px;
        width: 100%;
        align-items: stretch;
        position: relative;
        z-index: 2;
    }

    /* Seamlessly Blended Dark Glass Cards with 1st Team Rank Color Detailing */
    .glass-panel {
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.82) 0%, rgba(9, 15, 30, 0.72) 100%) !important;
        backdrop-filter: blur(30px);
        -webkit-backdrop-filter: blur(30px);
        border: 1.5px solid color-mix(in srgb, var(--first-team-color, #10b981) 22%, rgba(255, 255, 255, 0.08)) !important;
        border-radius: 36px;
        padding: 44px 52px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        box-sizing: border-box;
        box-shadow: 0 25px 65px rgba(0, 0, 0, 0.4), inset 0 0 30px rgba(255, 255, 255, 0.02) !important;
        transition: transform 0.6s ease, border-color 1.2s ease;
    }

    .glass-panel:hover {
        transform: translateY(-4px);
    }

    /* Main Performing Card */
    .now-performing-card {
        min-height: 560px;
        justify-content: space-between;
        animation: slide-in-main-card 1.6s cubic-bezier(0.19, 1, 0.22, 1) 0.15s both;
    }

    /* Header Live Bar inside Main Card */
    .program-stage-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 14px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .stage-live-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(16, 185, 129, 0.2);
        border: 1.5px solid rgba(16, 185, 129, 0.5);
        color: #34d399;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        box-shadow: 0 0 15px rgba(16, 185, 129, 0.25);
    }

    .stage-live-badge .live-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #34d399;
        box-shadow: 0 0 10px #34d399;
        animation: live-pulse-dot 1.4s ease-in-out infinite alternate;
    }

    .program-title-display {
        font-size: 48px;
        font-weight: 900;
        line-height: 1.15;
        margin: 0 0 24px 0;
        color: #ffffff;
        text-shadow: 0 2px 12px rgba(0, 0, 0, 0.5);
        letter-spacing: -0.02em;
        text-transform: uppercase;
        font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
    }

    .program-section-inline {
        font-weight: 800;
        color: #a3e635;
        font-size: 40px;
        margin-left: 10px;
        letter-spacing: 0;
        text-transform: uppercase;
    }

    /* Performer Showcase Box */
    .performer-hero-info {
        position: relative;
        background: rgba(15, 23, 42, 0.7);
        border: 1.5px solid color-mix(in srgb, var(--current-team-color, #10b981) 50%, rgba(255, 255, 255, 0.2));
        padding: 34px 44px;
        border-radius: 28px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), inset 0 0 25px color-mix(in srgb, var(--current-team-color, #10b981) 15%, transparent);
        transition: border-color 1.2s ease;
    }

    /* Traveling Neon Border Light Beam Outline */
    .travel-border-svg {
        position: absolute;
        inset: -2px;
        width: calc(100% + 4px);
        height: calc(100% + 4px);
        pointer-events: none;
        z-index: 5;
        overflow: visible;
    }

    .travel-border-rect {
        fill: none;
        stroke: var(--current-team-color, #10b981);
        stroke-width: 3.5px;
        stroke-dasharray: 24 76;
        animation: neon-travel-dash 3.5s linear infinite;
        filter: drop-shadow(0 0 6px var(--current-team-color, #10b981)) drop-shadow(0 0 16px var(--current-team-color, #10b981));
        transition: stroke 1.2s ease, filter 1.2s ease;
    }

    @keyframes neon-travel-dash {
        0% { stroke-dashoffset: 100; }
        100% { stroke-dashoffset: 0; }
    }

    .performer-details-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .performer-details {
        flex: 1;
        min-width: 0;
    }

    .performer-name {
        display: none !important;
        font-size: 60px;
        font-weight: 900;
        margin: 0;
        color: #ffffff;
        text-shadow: 0 2px 14px rgba(0, 0, 0, 0.6);
        letter-spacing: -0.02em;
        line-height: 1.1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
    }

    .team-pill {
        margin-top: 10px;
        font-size: 24px;
        font-weight: 800;
        color: rgba(255, 255, 255, 0.85);
        display: flex;
        align-items: center;
        gap: 12px;
        font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
    }

    .tv-team-dot {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        display: inline-block;
    }

    /* Active Metadata Wrapper for Timer + Chest badge side-by-side */
    .active-metadata-wrap {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-left: 24px;
    }

    /* Active Stage Timer Badge */
    .active-stage-timer {
        background: rgba(15, 23, 42, 0.95);
        border: 1.5px solid rgba(244, 63, 94, 0.3);
        color: #ffffff;
        padding: 20px 32px;
        border-radius: 22px;
        text-align: center;
        box-shadow: 0 14px 35px rgba(244, 63, 94, 0.08);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 150px;
    }

    .active-stage-timer.is-running {
        animation: timer-pulse-border 2s infinite alternate;
    }

    @keyframes timer-pulse-border {
        0% {
            border-color: rgba(244, 63, 94, 0.3);
            box-shadow: 0 14px 35px rgba(244, 63, 94, 0.08);
        }
        100% {
            border-color: rgba(244, 63, 94, 0.75);
            box-shadow: 0 14px 35px rgba(244, 63, 94, 0.25);
        }
    }

    .active-stage-timer .label {
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.18em;
        color: #f43f5e;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 4px;
    }

    .active-stage-timer .num {
        font-size: 46px;
        font-weight: 900;
        color: #f43f5e;
        text-shadow: 0 0 16px rgba(244, 63, 94, 0.6);
        font-family: 'Plus Jakarta Sans', monospace;
        line-height: 1;
    }

    /* Active Performer Chest Badge */
    .active-chest-hero {
        background: rgba(15, 23, 42, 0.95);
        border: 1.5px solid rgba(255, 255, 255, 0.2);
        color: #ffffff;
        padding: 20px 32px;
        border-radius: 22px;
        text-align: center;
        box-shadow: 0 14px 35px rgba(0, 0, 0, 0.5);
    }

    .active-chest-hero .label {
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.22em;
        color: rgba(255, 255, 255, 0.6);
        text-transform: uppercase;
        display: block;
        margin-bottom: 4px;
    }

    .active-chest-hero .num {
        font-size: 46px;
        font-weight: 900;
        color: var(--current-team-color, #10b981);
        text-shadow: 0 0 16px var(--current-team-color, #10b981);
        font-family: 'Plus Jakarta Sans', monospace;
        line-height: 1;
    }

    /* State toggling displays */
    .performer-hero-info.state-awaiting .performer-details-container {
        display: none !important;
    }
    .performer-hero-info.state-active .stage-awaiting-container {
        display: none !important;
    }

    /* Stage Awaiting / Intermission State Widget */
    .stage-awaiting-container {
        display: flex;
        align-items: center;
        gap: 20px;
        width: 100%;
    }

    .stage-awaiting-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(16, 185, 129, 0.2);
        border: 1.5px solid rgba(16, 185, 129, 0.5);
        color: #34d399;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        animation: mic-pulse 2s ease-in-out infinite alternate;
    }

    @keyframes mic-pulse {
        0% { transform: scale(0.95); opacity: 0.8; }
        100% { transform: scale(1.1); opacity: 1; }
    }

    .stage-awaiting-title {
        font-size: 30px;
        font-weight: 900;
        color: #ffffff;
        letter-spacing: -0.01em;
    }

    .stage-awaiting-sub {
        font-size: 15px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.65);
        margin-top: 4px;
    }

    /* Bottom Program Stats Grid */
    .program-stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid rgba(255, 255, 255, 0.12);
    }

    .stat-widget-box {
        background: rgba(255, 255, 255, 0.02) !important;
        border: 1px solid rgba(255, 255, 255, 0.06) !important;
        padding: 12px 18px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .stat-widget-icon {
        font-size: 18px;
        color: #34d399;
    }

    .stat-widget-label {
        font-size: 10px;
        font-weight: 900;
        color: rgba(255, 255, 255, 0.55);
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .stat-widget-value {
        font-size: 15px;
        font-weight: 900;
        color: #ffffff;
    }

    /* Sidebar Column with 2 Glass Boxes */
    .sidebar-column {
        display: flex;
        flex-direction: column;
        gap: 28px;
        height: 100%;
        justify-content: space-between;
    }

    /* Top Right Box */
    .side-card-top {
        flex: 1;
        min-height: 260px;
        padding: 36px 44px;
        text-align: center;
        justify-content: center;
        align-items: center;
        animation: slide-in-side-card 1.6s cubic-bezier(0.19, 1, 0.22, 1) 0.3s both;
    }

    /* Bottom Right Box */
    .side-card-bottom {
        flex: 1;
        min-height: 260px;
        padding: 36px 44px;
        text-align: center;
        justify-content: center;
        align-items: center;
        animation: slide-in-side-card 1.6s cubic-bezier(0.19, 1, 0.22, 1) 0.45s both;
    }

    .side-box-label {
        font-size: 14px;
        font-weight: 900;
        color: rgba(255, 255, 255, 0.65);
        letter-spacing: 0.2em;
        text-transform: uppercase;
        margin-bottom: 14px;
    }

    .up-next-chest-big {
        font-size: 88px;
        font-weight: 900;
        line-height: 1;
        color: #ffffff;
        font-family: 'Plus Jakarta Sans', monospace;
    }

    .up-next-chest-big span {
        color: var(--first-team-color, #10b981);
        text-shadow: 0 0 30px var(--first-team-color, #10b981);
        transition: color 1.2s ease;
    }

    .next-prog-title {
        font-size: 34px;
        font-weight: 900;
        color: #ffffff;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        line-height: 1.2;
        margin: 0 0 14px 0;
        letter-spacing: -0.01em;
        font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
    }

    .next-prog-cat {
        font-size: 24px;
        font-weight: 800;
        color: #a3e635;
    }

    .next-prog-time-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 8px 20px;
        border-radius: 20px;
        font-size: 15px;
        font-weight: 800;
        color: #ffffff;
    }

    /* Dynamic Chevron Lines Strictly Behind Card Text */
    .card-chevrons-svg {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100% !important;
        height: 100% !important;
        pointer-events: none !important;
        z-index: 0 !important;
        overflow: hidden !important;
    }

    .animated-card-group {
        will-change: transform, opacity;
        animation: bg-chevron-pulse-float 11s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite alternate;
    }
</style>

<div class="current-programs-stage-root" data-current-theme-root style="--first-team-color: <?= e($firstTeamColor) ?>; --top-team-color: <?= e($firstTeamColor) ?>;">
    <div class="programs-wrapper">
        <!-- Main Workspace Grid -->
        <div class="dashboard-grid">
            <!-- Main Panel -->
            <main class="glass-panel now-performing-card">
                <div>
                    <!-- Stage Header Bar -->
                    <div class="program-stage-header-bar">
                        <span class="stage-live-badge">
                            <span class="live-dot"></span> LIVE STAGE • NORMAL STAGE
                        </span>
                        <span style="font-size: 13px; font-weight: 800; color: rgba(255,255,255,0.65); text-transform: uppercase; letter-spacing: 0.12em;">
                            <i class="fa-solid fa-circle-play mr-1" style="color: #34d399;"></i> PERFORMANCE STAGE
                        </span>
                    </div>
                    <h1 class="program-title-display" data-current-title><?= e($initFullTitle) ?></h1>
                </div>

                <!-- Performer Hero Details -->
                <div class="performer-hero-info <?= ($initIsIntro || empty($initPerf['name']) || $initPerfName === 'Awaiting Performer') ? 'state-awaiting' : 'state-active' ?>" data-performer-hero-info>
                    <!-- Stage Ready / Awaiting Performer Display (State Awaiting) -->
                    <div class="stage-awaiting-container">
                        <div class="stage-awaiting-icon">
                            <i class="fa-solid fa-microphone-lines animate-pulse"></i>
                        </div>
                        <div>
                            <div class="stage-awaiting-title">STAGE READY • CONTESTANT ENTRY</div>
                            <div class="stage-awaiting-sub">Judges Ready • Awaiting Contestant Stage Call</div>
                        </div>
                    </div>

                    <!-- Active Performer Showcase (State Active) -->
                    <div class="performer-details-container">
                        <div class="performer-details">
                            <h2 class="performer-name" data-current-performer><?= e($initPerfName) ?></h2>
                            <div class="team-pill" data-current-team style="<?= ($initIsIntro || $initTeamName === '—') ? 'display: none;' : 'display: flex;' ?>">
                                <span class="tv-team-dot" style="background:<?= e($initTeamColor) ?>;"></span> <?= e($initTeamName) ?>
                            </div>
                        </div>
                        <div class="active-metadata-wrap">
                            <div class="active-stage-timer" id="stageTimerBox" style="<?= ($initIsIntro || empty($initPerf['name']) || $initPerfName === 'Awaiting Performer') ? 'display: none;' : 'display: flex;' ?>">
                                <span class="label"><i class="fa-solid fa-stopwatch animate-pulse"></i> LIVE TIMER</span>
                                <span class="num" id="stageTimerDisplay">00:00</span>
                            </div>
                            <div class="active-chest-hero" data-active-chest-box style="<?= $initIsIntro ? 'display: none;' : '' ?>">
                                <span class="label">CHEST NO</span>
                                <span class="num" data-current-chest><?= e($initChest) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Program Stats Widgets Grid -->
                <div class="program-stats-grid">
                    <div class="stat-widget-box">
                        <i class="fa-solid fa-users stat-widget-icon"></i>
                        <div>
                            <div class="stat-widget-label">CONTESTANTS</div>
                            <div class="stat-widget-value" data-current-entry-count-display>12 REGISTERED</div>
                        </div>
                    </div>
                    <div class="stat-widget-box">
                        <i class="fa-solid fa-bolt stat-widget-icon"></i>
                        <div>
                            <div class="stat-widget-label">STAGE STATUS</div>
                            <div class="stat-widget-value" data-current-status-display>ACTIVE SCORING</div>
                        </div>
                    </div>
                    <div class="stat-widget-box">
                        <i class="fa-solid fa-location-dot stat-widget-icon"></i>
                        <div>
                            <div class="stat-widget-label">LOCATION</div>
                            <div class="stat-widget-value" data-current-room-display>NORMAL STAGE</div>
                        </div>
                    </div>
                </div>
            </main>

            <!-- Sidebar Column -->
            <aside class="sidebar-column">
                <!-- Box 1 (Top Right): NEXT PARTICIPANT / CONTESTANT -->
                <div class="glass-panel side-card-top" style="<?= ($settings['show_next_participant'] ?? true) ? '' : 'display: none !important;' ?>">
                    <div class="side-box-label" data-next-label>
                        <?= $initIsIntro ? '1ST CONTESTANT STAGE ENTRY' : 'NEXT CONTESTANT' ?>
                    </div>
                    <div class="up-next-chest-big">
                        <span data-next-chest style="color: <?= e($initNextColor) ?>; text-shadow: 0 0 30px <?= e($initNextColor) ?>;"><?= e($initNextChest) ?></span>
                    </div>
                </div>

                <!-- Box 2 (Bottom Right): NEXT PROGRAM / BREAK -->
                <div class="glass-panel side-card-bottom">
                    <div class="side-box-label" data-next-prog-label>
                        <?= $isBreak ? 'INTERMISSION / EXTRA' : 'NEXT PROGRAM' ?>
                    </div>
                    <h3 class="next-prog-title" data-next-prog-title>
                        <?= e($nextProgTitle) ?><?= $nextProgCategory ? ' <span class="next-prog-cat">' . e($nextProgCategory) . '</span>' : '' ?>
                    </h3>
                    <div class="next-prog-time-badge" data-next-prog-time>
                        <i class="fa-solid fa-clock mr-1"></i> <?= e($nextProgTime) ?>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Hidden elements for tracker compatibility -->
        <div hidden>
            <span data-current-status>LIVE STAGE</span>
            <span data-current-stage>Stage</span>
            <span data-current-category>Category</span>
            <span data-current-room>Venue</span>
            <span data-current-entry-count>0</span>
            <span data-judges>No judges</span>
            <span data-next-program>No program</span>
            <span data-current-progress-label>0 / 0</span>
            <div data-current-progress-fill style="width: 0%;"></div>
        </div>
    </div>
</div>

        <!-- Hidden elements for tracker compatibility -->
        <div hidden>
            <span data-current-status>LIVE STAGE</span>
            <span data-current-stage>Stage</span>
            <span data-current-category>Category</span>
            <span data-current-room>Venue</span>
            <span data-current-entry-count>0</span>
            <span data-judges>No judges</span>
            <span data-next-program>No program</span>
            <span data-current-progress-label>0 / 0</span>
            <div data-current-progress-fill style="width: 0%;"></div>
        </div>
    </div>
</div>

<script>
    (() => {
        const root = document.querySelector('[data-current-theme-root]');

        function parseColor(value) {
            if (!value) return null;
            const hex = String(value).trim().match(/^#?([0-9a-f]{6})$/i);
            if (hex) return `#${hex[1]}`;
            const rgb = String(value).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
            if (rgb) {
                return `rgb(${rgb[1]}, ${rgb[2]}, ${rgb[3]})`;
            }
            return null;
        }

        function teamColor(selector) {
            const dot = document.querySelector(selector)?.querySelector('.tv-team-dot');
            return parseColor(dot?.style.background || dot?.style.backgroundColor);
        }

        function syncTheme() {
            if (!root) return;
            const current = teamColor('[data-current-team]');
            if (current) {
                root.style.setProperty('--current-neon', current);
            } else {
                root.style.setProperty('--current-neon', getComputedStyle(document.documentElement).getPropertyValue('--first-team-color') || '<?= e($firstTeamColor) ?>');
            }
        }

        syncTheme();

        function tvFormatSectionName(category) {
            if (!category || category === 'Musabaqa Category' || category === 'All Classes') {
                return 'General';
            }
            const c = String(category).trim();
            if (c.includes('العالية') || c.toLowerCase() === 'senior') {
                return 'Senior';
            }
            if (c.includes('الثانوية') || c.toLowerCase() === 'junior') {
                return 'Junior';
            }
            if (c.includes('حفظ') || c.includes('التحصص') || c.toLowerCase() === 'sub' || c.toLowerCase() === 'sub junior') {
                return 'Sub';
            }
            return c;
        }

        let activeParticleColor = '';

        function initTopTeamParticles(colorHex) {
            if (!document.getElementById('particles-js')) return;
            if (!colorHex || colorHex === activeParticleColor) return;
            activeParticleColor = colorHex;

            if (typeof particlesJS === 'undefined') {
                setTimeout(() => initTopTeamParticles(colorHex), 150);
                return;
            }

            if (window.pJSDom && window.pJSDom.length > 0) {
                try {
                    window.pJSDom[0].pJS.fn.vendors.destroypJS();
                } catch(e) {}
                window.pJSDom = [];
            }

            particlesJS('particles-js', {
                "particles": {
                    "number": {
                        "value": 50,
                        "density": { "enable": true, "value_area": 900 }
                    },
                    "color": {
                        "value": [colorHex, "#ffffff", colorHex]
                    },
                    "shape": { "type": "circle" },
                    "opacity": {
                        "value": 0.7,
                        "random": true,
                        "anim": { "enable": true, "speed": 1.4, "opacity_min": 0.25, "sync": false }
                    },
                    "size": {
                        "value": 4.5,
                        "random": true,
                        "anim": { "enable": true, "speed": 2.2, "size_min": 1.2, "sync": false }
                    },
                    "line_linked": {
                        "enable": true,
                        "distance": 140,
                        "color": colorHex,
                        "opacity": 0.28,
                        "width": 1.2
                    },
                    "move": {
                        "enable": true,
                        "speed": 1.6,
                        "direction": "none",
                        "random": true,
                        "straight": false,
                        "out_mode": "out",
                        "bounce": false
                    }
                },
                "interactivity": {
                    "detect_on": "canvas",
                    "events": {
                        "onhover": { "enable": true, "mode": "grab" },
                        "onclick": { "enable": true, "mode": "push" },
                        "resize": true
                    },
                    "modes": {
                        "grab": { "distance": 180, "line_linked": { "opacity": 0.45 } },
                        "push": { "particles_nb": 4 }
                    }
                },
                "retina_detect": true
            });
        }

        initTopTeamParticles(<?= json_encode($firstTeamColor) ?>);

        // Real-Time Live API Polling Engine
        let lastStateHash = '';

        function fetchCurrentProgramState() {
            const apiPath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/api/current-program.php';
            fetch(apiPath)
                .then(res => res.json())
                .then(res => {
                    if (!res.success || !res.data || !res.data.current) return;
                    
                    // Toggle Next Participant Box visibility
                    const showNext = res.data.settings ? (res.data.settings.show_next_participant !== false) : true;
                    const sideCardTop = document.querySelector('.side-card-top');
                    if (sideCardTop) {
                        sideCardTop.style.display = showNext ? 'block' : 'none';
                    }
                    
                    // Live Top Rank Team Color Synchronization & Dynamic Particles
                    if (res.data.leaderboard && res.data.leaderboard[0] && res.data.leaderboard[0].team_color) {
                        const topTeamColor = res.data.leaderboard[0].team_color;
                        document.documentElement.style.setProperty('--first-team-color', topTeamColor);
                        const rootEl = document.querySelector('[data-current-theme-root]');
                        if (rootEl) rootEl.style.setProperty('--first-team-color', topTeamColor);
                        initTopTeamParticles(topTeamColor);
                    }

                    const c = res.data.current;
                    const prog = c.program || {};
                    const perf = c.performer || {};
                    const nextPerf = c.next_performer || {};
                    const nextProg = c.next_program || {};
                    const isIntro = c.is_intro || !perf || !perf.id;
                    const isBreak = !!c.is_break;

                    const hash = JSON.stringify({
                        progId: prog.id,
                        perfId: perf.id,
                        nextId: nextPerf.id,
                        nextProgId: nextProg.id,
                        status: c.status,
                        isIntro: isIntro,
                        isBreak: isBreak
                    });
                    if (hash === lastStateHash) return;
                    lastStateHash = hash;

                    // Update Main Title (Program Title + Section)
                    const titleEl = document.querySelector('[data-current-title]');
                    if (titleEl) {
                        const pTitle = prog.title || 'No Active Program';
                        const pSec = tvFormatSectionName(prog.category);
                        titleEl.innerHTML = pTitle + (pSec ? ` <span class="program-section-inline">${pSec}</span>` : '');
                    }

                    // Update Active Chest Box
                    const activeChestBox = document.querySelector('[data-active-chest-box]');
                    if (activeChestBox) {
                        activeChestBox.style.display = isIntro ? 'none' : 'block';
                    }
                    const chestEl = document.querySelector('[data-current-chest]');
                    if (chestEl) chestEl.textContent = perf.chest_number || perf.number || '—';

                    // Update Performer Name
                    const perfEl = document.querySelector('[data-current-performer]');
                    if (perfEl) {
                        perfEl.textContent = isIntro ? 'Ready to begin' : (perf.name || 'Awaiting performer');
                    }

                    // Update Team Pill & Traveling Neon Border Color under performer
                    const teamEl = document.querySelector('[data-current-team]');
                    const heroBoxEl = document.querySelector('.performer-hero-info');
                    if (teamEl) {
                        if (isIntro || !perf.team || perf.team === '—') {
                            teamEl.style.display = 'none';
                            teamEl.innerHTML = '';
                            if (heroBoxEl) heroBoxEl.style.setProperty('--current-team-color', '<?= e($firstTeamColor) ?>');
                        } else {
                            const color = perf.team_color || '<?= e($firstTeamColor) ?>';
                            teamEl.innerHTML = `<span class="tv-team-dot" style="background:${color};"></span>`;
                            teamEl.style.display = 'flex';
                            if (heroBoxEl) heroBoxEl.style.setProperty('--current-team-color', color);
                        }
                    }

                    // Update Up Next Box 1 (Next Contestant)
                    const upNextLabelEl = document.querySelector('[data-next-label]');
                    if (upNextLabelEl) {
                        upNextLabelEl.textContent = isIntro ? '1ST CONTESTANT STAGE ENTRY' : 'NEXT CONTESTANT';
                    }
                    const nextChestEl = document.querySelector('[data-next-chest]');
                    if (nextChestEl) nextChestEl.textContent = nextPerf.chest_number || nextPerf.number || '—';

                    // Update Up Next Box 2 (Next Program / Break)
                    const nextProgLabelEl = document.querySelector('[data-next-prog-label]');
                    if (nextProgLabelEl) {
                        nextProgLabelEl.textContent = isBreak ? 'INTERMISSION / EXTRA' : 'NEXT PROGRAM';
                    }
                    const nextProgTitleEl = document.querySelector('[data-next-prog-title]');
                    if (nextProgTitleEl) {
                        const npTitle = nextProg.title || (isBreak ? 'Upcoming Extra' : 'Next Program');
                        const npSec = tvFormatSectionName(nextProg.category);
                        nextProgTitleEl.innerHTML = npTitle + (npSec ? ` <span class="next-prog-cat">${npSec}</span>` : '');
                    }
                    const nextProgTimeEl = document.querySelector('[data-next-prog-time]');
                    if (nextProgTimeEl) {
                        const npTime = nextProg.start_label || nextProg.time || 'Scheduled Soon';
                        nextProgTimeEl.innerHTML = `<i class="fa-solid fa-clock mr-1"></i> ${npTime}`;
                    }

                    syncTheme();
                    window.triggerCurrentProgramAnimations?.();
                })
                .catch(err => console.error('Fetch error:', err));
        }

        if (!window.IS_SINGLE_PAGE) {
            // Do not run duplicate polling when running inside the main slideshow container,
            // as parent live-display.js already syncs and calls renderCurrent.
            syncTheme();
        } else {
            fetchCurrentProgramState();
            const pollInterval = (document.documentElement.classList.contains('performance-mode') || window.isLowEndDevice) ? 2000 : 500;
            setInterval(fetchCurrentProgramState, pollInterval);
        }

        // Fluid GSAP Entrance Sequence
        window.triggerCurrentProgramAnimations = function() {
            if (typeof gsap === 'undefined') return;
            const mainCard = document.querySelector('.now-performing-card');
            const sideTopCard = document.querySelector('.side-card-top');
            const sideBottomCard = document.querySelector('.side-card-bottom');

            gsap.killTweensOf([mainCard, sideTopCard, sideBottomCard]);

            if (mainCard) {
                gsap.fromTo(mainCard, {
                    opacity: 0.88,
                    scale: 0.98,
                    y: 12
                }, {
                    opacity: 1,
                    scale: 1,
                    y: 0,
                    duration: 0.8,
                    ease: 'power3.out'
                });
            }
            if (sideTopCard) {
                gsap.fromTo(sideTopCard, {
                    opacity: 0.88,
                    scale: 0.98,
                    x: 16
                }, {
                    opacity: 1,
                    scale: 1,
                    x: 0,
                    duration: 0.8,
                    ease: 'power3.out',
                    delay: 0.08
                });
            }
            if (sideBottomCard) {
                gsap.fromTo(sideBottomCard, {
                    opacity: 0.88,
                    scale: 0.98,
                    x: 16
                }, {
                    opacity: 1,
                    scale: 1,
                    x: 0,
                    duration: 0.8,
                    ease: 'power3.out',
                    delay: 0.16
                });
            }
        };

        setTimeout(() => {
            syncTheme();
            window.triggerCurrentProgramAnimations?.();
        }, 150);
    })();
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>