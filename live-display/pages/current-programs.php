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
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#10b981';
$firstTeamName = !empty($firstTeam['team_name']) ? $firstTeam['team_name'] : 'Leader';

// Pre-fetch live stage program & performer data for instant server-side rendering
$cpData = tv_current_program((int)($event['id'] ?? 0));
$initProg = $cpData['program'] ?? [];
$initPerf = $cpData['performer'] ?? [];
$initNext = $cpData['next_performer'] ?? [];
$initIsIntro = !empty($cpData['is_intro']) || empty($initPerf['id']);

$initTitle = !empty($initProg['title']) ? $initProg['title'] : 'No Active Program';
$initChest = !empty($initPerf['chest_number']) ? $initPerf['chest_number'] : (!empty($initPerf['number']) ? $initPerf['number'] : '—');
$initPerfName = !empty($initPerf['name']) ? $initPerf['name'] : 'Awaiting Performer';
$initTeamName = !empty($initPerf['team']) ? $initPerf['team'] : '—';
$initTeamColor = !empty($initPerf['team_color']) ? live_display_color($initPerf['team_color'] ?? null) : '#10b981';

$initNextChest = !empty($initNext['chest_number']) ? $initNext['chest_number'] : (!empty($initNext['number']) ? $initNext['number'] : '—');
?>
<?php if (!defined('LIVE_DISPLAY_STAGE')): ?>
    <script>
        document.body.classList.add('tv-current-programs-theme');
        document.querySelector('.tv-topbar')?.setAttribute('hidden', '');
    </script>
<?php endif; ?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Cairo:wght@600;800;900&display=swap');

    body.tv-current-programs-theme .tv-topbar,
    body:has(#slide-current-program.tv-slide--active) .tv-topbar {
        display: none !important;
    }

    #slide-current-program {
        padding: 0 !important;
        overflow: hidden;
        background: #f8fafc url('<?= asset_url('images/white-background.png') ?>') center center / cover no-repeat;
        font-family: 'Outfit', 'Cairo', system-ui, -apple-system, sans-serif;
        color: #0f172a;
        width: 100vw;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
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
        --current-neon: #10b981;
        width: 100%;
        max-width: 1680px;
        height: 100vh;
        padding: 60px 80px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        box-sizing: border-box;
        position: relative;
        z-index: 1;
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
        top: 50px;
        right: 80px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.9);
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

    /* Main Grid Workspace: Balanced 2 Columns */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1.25fr 0.75fr;
        gap: 48px;
        width: 100%;
        align-items: center;
        position: relative;
        z-index: 2;
    }

    /* Seamlessly Blended Glass Cards with 1st Team Rank Color Detailing */
    .glass-panel {
        background: rgba(255, 255, 255, 0.78);
        backdrop-filter: blur(40px);
        -webkit-backdrop-filter: blur(40px);
        border: 1.5px solid color-mix(in srgb, var(--first-team-color) 25%, rgba(255, 255, 255, 0.95));
        border-radius: 40px;
        padding: 56px 60px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden;
        box-sizing: border-box;
        box-shadow: 0 20px 50px -10px rgba(0, 0, 0, 0.05);
        transition: transform 0.6s ease, border-color 1.2s ease;
    }

    .glass-panel:hover {
        transform: translateY(-4px);
    }

    /* Main Performing Card with Slower Ultra-Smooth Slide-In */
    .now-performing-card {
        min-height: 480px;
        justify-content: space-between;
        animation: slide-in-main-card 1.6s cubic-bezier(0.19, 1, 0.22, 1) 0.15s both;
    }

    .program-title-display {
        font-size: 68px;
        font-weight: 900;
        line-height: 1.1;
        margin: 0 0 24px 0;
        color: #0f172a;
        letter-spacing: -0.02em;
        text-transform: uppercase;
    }

    .program-category-sub {
        font-size: 22px;
        font-weight: 800;
        color: #64748b;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .program-category-sub::before {
        content: '';
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--first-team-color);
        transition: background 0.8s ease;
    }

    /* Performer Showcase Box */
    .performer-hero-info {
        background: rgba(255, 255, 255, 0.82);
        border: 1px solid rgba(255, 255, 255, 0.95);
        padding: 36px 44px;
        border-radius: 26px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.02);
    }

    .performer-details {
        flex: 1;
        min-width: 0;
    }

    .performer-name {
        font-size: 60px;
        font-weight: 900;
        margin: 0;
        color: #0f172a;
        letter-spacing: -0.02em;
        line-height: 1.1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .team-pill {
        margin-top: 12px;
        font-size: 24px;
        font-weight: 800;
        color: #475569;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .tv-team-dot {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: inline-block;
    }

    /* Active Performer Chest Badge */
    .active-chest-hero {
        background: #0f172a;
        color: #ffffff;
        padding: 20px 32px;
        border-radius: 24px;
        text-align: center;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.15);
    }

    .active-chest-hero .label {
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.2em;
        color: #94a3b8;
        text-transform: uppercase;
        display: block;
        margin-bottom: 4px;
    }

    .active-chest-hero .num {
        font-size: 42px;
        font-weight: 900;
        color: #ffffff;
        font-family: monospace;
        line-height: 1;
    }

    /* Sidebar Panel: Coming Up Next with Slower Ultra-Smooth Slide-In */
    .side-panel {
        min-height: 480px;
        text-align: center;
        justify-content: center;
        align-items: center;
        animation: slide-in-side-card 1.6s cubic-bezier(0.19, 1, 0.22, 1) 0.3s both;
    }

    .up-next-chest-only-box {
        width: 100%;
        padding: 60px 40px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    .up-next-chest-label {
        font-size: 15px;
        font-weight: 900;
        color: #64748b;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        margin-bottom: 24px;
    }

    .up-next-chest-big {
        font-size: 96px;
        font-weight: 900;
        line-height: 1;
        font-family: monospace;
        margin-bottom: 20px;
    }

    .up-next-chest-big span {
        color: var(--first-team-color);
        transition: color 1.2s ease;
    }

    .up-next-subtext {
        font-size: 13px;
        font-weight: 800;
        color: #94a3b8;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .up-next-subtext::before,
    .up-next-subtext::after {
        content: '';
        width: 14px;
        height: 1px;
        background: #cbd5e1;
    }

    /* Dynamic Animated Geometric Chevron Vector Lines (Background & Cards) */
    @keyframes bg-chevron-pulse-float {
        0% {
            transform: translateY(0) scale(1);
            opacity: 0.8;
        }
        50% {
            transform: translateY(-6px) scale(1.01);
            opacity: 1;
        }
        100% {
            transform: translateY(0) scale(1);
            opacity: 0.8;
        }
    }

    @keyframes line-dash-flow {
        0% {
            stroke-dashoffset: 300;
        }
        100% {
            stroke-dashoffset: -300;
        }
    }

    .side-chevrons-svg {
        position: absolute;
        top: 0;
        bottom: 0;
        height: 100vh;
        width: 500px;
        pointer-events: none;
        z-index: 1;
    }

    .side-chevrons-svg.left-side {
        left: 0;
    }

    .side-chevrons-svg.right-side {
        right: 0;
    }

    .animated-chevron-group {
        animation: bg-chevron-pulse-float 6s ease-in-out infinite alternate;
    }

    .animated-dash-line {
        stroke-dasharray: 200 100;
        animation: line-dash-flow 12s linear infinite;
        transition: stroke 1.2s ease, opacity 1.2s ease;
    }

    .animated-cross-line {
        transition: stroke 1.2s ease, opacity 1.2s ease;
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
        animation: fade-in-silk-lines 2s cubic-bezier(0.19, 1, 0.22, 1) 0.5s both;
    }

    .animated-card-group {
        animation: bg-chevron-pulse-float 7s ease-in-out infinite alternate;
    }

    /* Elevate all card text & child elements above SVG chevron lines */
    .glass-panel > *:not(.card-chevrons-svg) {
        position: relative !important;
        z-index: 2 !important;
    }
</style>

<!-- Dynamic 1st Rank Team Geometric Chevron Vectors (Background Left & Right) -->
<svg class="side-chevrons-svg left-side" viewBox="0 0 500 1080" fill="none" xmlns="http://www.w3.org/2000/svg">
    <g class="animated-chevron-group">
        <!-- Soft 3D Cut Drop Shadows -->
        <path d="M-100 1180 L450 630 L-100 80" stroke="rgba(0,0,0,0.06)" stroke-width="14" stroke-linecap="round"/>
        <path d="M-150 1000 L350 500 L-150 -50" stroke="rgba(0,0,0,0.04)" stroke-width="10" stroke-linecap="round"/>

        <!-- Primary Team Colored Diagonal Chevron Lines -->
        <path class="animated-dash-line" d="M-100 1180 L450 630 L-100 80" stroke="var(--first-team-color)" stroke-width="3" stroke-linecap="round"/>
        <path class="animated-dash-line" d="M-150 1000 L350 500 L-150 -50" stroke="var(--first-team-color)" stroke-width="2" opacity="0.8" stroke-linecap="round"/>
        <path class="animated-dash-line" d="M-50 1280 L520 710 L-50 200" stroke="var(--first-team-color)" stroke-width="1.5" opacity="0.6" stroke-linecap="round"/>

        <!-- Perpendicular Cross-Hatch Accent Slashes -->
        <line class="animated-cross-line" x1="120" y1="960" x2="320" y2="760" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
        <line class="animated-cross-line" x1="220" y1="860" x2="420" y2="660" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
        <line class="animated-cross-line" x1="50" y1="430" x2="250" y2="230" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
        <line class="animated-cross-line" x1="150" y1="330" x2="350" y2="130" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
    </g>
</svg>

<svg class="side-chevrons-svg right-side" viewBox="0 0 500 1080" fill="none" xmlns="http://www.w3.org/2000/svg">
    <g class="animated-chevron-group">
        <!-- Soft 3D Cut Drop Shadows -->
        <path d="M600 1180 L50 630 L600 80" stroke="rgba(0,0,0,0.06)" stroke-width="14" stroke-linecap="round"/>
        <path d="M650 1000 L150 500 L650 -50" stroke="rgba(0,0,0,0.04)" stroke-width="10" stroke-linecap="round"/>

        <!-- Primary Team Colored Diagonal Chevron Lines -->
        <path class="animated-dash-line" d="M600 1180 L50 630 L600 80" stroke="var(--first-team-color)" stroke-width="3" stroke-linecap="round"/>
        <path class="animated-dash-line" d="M650 1000 L150 500 L650 -50" stroke="var(--first-team-color)" stroke-width="2" opacity="0.8" stroke-linecap="round"/>
        <path class="animated-dash-line" d="M550 1280 L-20 710 L550 200" stroke="var(--first-team-color)" stroke-width="1.5" opacity="0.6" stroke-linecap="round"/>

        <!-- Perpendicular Cross-Hatch Accent Slashes -->
        <line class="animated-cross-line" x1="380" y1="960" x2="180" y2="760" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
        <line class="animated-cross-line" x1="280" y1="860" x2="80" y2="660" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
        <line class="animated-cross-line" x1="450" y1="430" x2="250" y2="230" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
        <line class="animated-cross-line" x1="350" y1="330" x2="150" y2="130" stroke="var(--first-team-color)" stroke-width="2" opacity="0.75" />
    </g>
</svg>

<!-- Light Backdrop Mesh with Subtle Pulse Tinted in 1st Rank Team's Color -->
<div class="ambient-mesh-bg"></div>

<div class="programs-wrapper" data-current-theme-root style="--top-team-color: <?= e($firstTeamColor) ?>;">
    <!-- Minimalist 1st Rank Team Transition Indicator -->
    <div class="first-team-rank-pill">
        <span class="rank-crown">🏆</span>
        <span class="rank-label">LEADING TEAM:</span>
        <strong class="rank-name"><?= e($firstTeamName) ?></strong>
        <span class="rank-dot" style="background: <?= e($firstTeamColor) ?>;"></span>
    </div>

    <!-- Main Workspace Grid -->
    <div class="dashboard-grid">
        <!-- Main Panel (Program Title & Active Performer) -->
        <main class="glass-panel now-performing-card">
            <!-- Dynamic Geometric Chevron Lines Accents Inside Main Card -->
            <svg class="card-chevrons-svg" viewBox="0 0 800 500" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g class="animated-card-group">
                    <path class="animated-dash-line" d="M-50 480 L350 80 L-50 -320" stroke="var(--first-team-color)" stroke-width="2" opacity="0.5" stroke-linecap="round"/>
                    <path class="animated-dash-line" d="M-20 510 L380 110 L-20 -290" stroke="var(--first-team-color)" stroke-width="1.2" opacity="0.35" stroke-linecap="round"/>
                    <line class="animated-cross-line" x1="120" y1="410" x2="240" y2="290" stroke="var(--first-team-color)" stroke-width="1.6" opacity="0.45" />
                    <line class="animated-cross-line" x1="180" y1="350" x2="300" y2="230" stroke="var(--first-team-color)" stroke-width="1.6" opacity="0.45" />

                    <!-- Right edge mirrored chevrons -->
                    <path class="animated-dash-line" d="M850 480 L450 80 L850 -320" stroke="var(--first-team-color)" stroke-width="2" opacity="0.5" stroke-linecap="round"/>
                    <line class="animated-cross-line" x1="680" y1="410" x2="560" y2="290" stroke="var(--first-team-color)" stroke-width="1.6" opacity="0.45" />
                </g>
            </svg>

            <div>
                <h1 class="program-title-display" data-current-title><?= e($initTitle) ?></h1>
                <div class="program-category-sub" data-current-category-sub>
                    <?= e($initProg['category'] ?? 'Musabaqa Category') ?>
                </div>
            </div>

            <!-- Performer Hero Details -->
            <div class="performer-hero-info">
                <div class="performer-details">
                    <h2 class="performer-name" data-current-performer><?= e($initPerfName) ?></h2>
                    <div class="team-pill" data-current-team>
                        <?php if ($initTeamName !== '—'): ?>
                            <span class="tv-team-dot" style="background:<?= e($initTeamColor) ?>; color:<?= e($initTeamColor) ?>;"></span> <?= e($initTeamName) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </div>
                </div>
                <div class="active-chest-hero" data-active-chest-box style="<?= $initIsIntro ? 'display: none;' : '' ?>">
                    <span class="label">CHEST NO</span>
                    <span class="num" data-current-chest><?= e($initChest) ?></span>
                </div>
            </div>
        </main>

        <!-- Sidebar Panel: Coming Up Next (Chest Number ONLY) -->
        <aside class="glass-panel side-panel">
            <!-- Dynamic Geometric Chevron Lines Accents Inside Sidebar Card -->
            <svg class="card-chevrons-svg" viewBox="0 0 500 500" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g class="animated-card-group">
                    <path class="animated-dash-line" d="M-30 480 L280 170 L-30 -140" stroke="var(--first-team-color)" stroke-width="2" opacity="0.55" stroke-linecap="round"/>
                    <path class="animated-dash-line" d="M530 480 L220 170 L530 -140" stroke="var(--first-team-color)" stroke-width="2" opacity="0.55" stroke-linecap="round"/>
                    <line class="animated-cross-line" x1="100" y1="400" x2="200" y2="300" stroke="var(--first-team-color)" stroke-width="1.6" opacity="0.45" />
                    <line class="animated-cross-line" x1="400" y1="400" x2="300" y2="300" stroke="var(--first-team-color)" stroke-width="1.6" opacity="0.45" />
                </g>
            </svg>

            <div class="up-next-chest-only-box">
                <div class="up-next-chest-label" data-next-label>
                    <?= $initIsIntro ? '1ST CONTESTANT STAGE ENTRY' : 'NEXT CONTESTANT' ?>
                </div>
                <div class="up-next-chest-big">
                    <span data-next-chest><?= e($initNextChest) ?></span>
                </div>
                <div class="up-next-subtext">GET READY FOR STAGE</div>
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
                root.style.setProperty('--current-neon', '#10b981');
            }
        }

        syncTheme();

        const watched = [
            '[data-current-team]',
            '[data-current-performer]'
        ].map((selector) => document.querySelector(selector)).filter(Boolean);
        const observer = new MutationObserver(syncTheme);
        watched.forEach((node) => observer.observe(node, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true
        }));

        // Real-Time Live API Polling Engine
        let lastStateHash = '';

        function fetchCurrentProgramState() {
            const apiPath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/api/current-program.php';
            fetch(apiPath)
                .then(res => res.json())
                .then(res => {
                    if (!res.success || !res.data || !res.data.current) return;
                    const c = res.data.current;
                    const prog = c.program || {};
                    const perf = c.performer || {};
                    const nextPerf = c.next_performer || {};
                    const isIntro = c.is_intro || !perf || !perf.id;

                    const hash = JSON.stringify({
                        progId: prog.id,
                        perfId: perf.id,
                        nextId: nextPerf.id,
                        status: c.status,
                        isIntro: isIntro
                    });
                    if (hash === lastStateHash) return;
                    lastStateHash = hash;

                    // Update Title & Category Subtitle
                    const titleEl = document.querySelector('[data-current-title]');
                    if (titleEl && prog.title) titleEl.textContent = prog.title;

                    const catSubEl = document.querySelector('[data-current-category-sub]');
                    if (catSubEl) catSubEl.textContent = prog.category || 'Musabaqa Category';

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

                    // Update Team
                    const teamEl = document.querySelector('[data-current-team]');
                    if (teamEl) {
                        if (isIntro) {
                            teamEl.innerHTML = `Category: ${prog.category || 'All Classes'}`;
                        } else {
                            const color = perf.team_color || '#10b981';
                            teamEl.innerHTML = perf.team ? `<span class="tv-team-dot" style="background:${color}; color:${color};"></span> ${perf.team}` : '—';
                        }
                    }

                    // Update Up Next Box Label & Chest Number
                    const upNextLabelEl = document.querySelector('[data-next-label]');
                    if (upNextLabelEl) {
                        upNextLabelEl.textContent = isIntro ? '1ST CONTESTANT STAGE ENTRY' : 'NEXT CONTESTANT';
                    }

                    const nextChestEl = document.querySelector('[data-next-chest]');
                    if (nextChestEl) nextChestEl.textContent = nextPerf.chest_number || nextPerf.number || '—';

                    syncTheme();
                    window.triggerCurrentProgramAnimations?.();
                })
                .catch(err => console.error('Fetch error:', err));
        }

        fetchCurrentProgramState();
        setInterval(fetchCurrentProgramState, 2000);

        // Fluid GSAP Entrance Sequence
        window.triggerCurrentProgramAnimations = function() {
            if (typeof gsap === 'undefined') return;
            const mainCard = document.querySelector('.now-performing-card');
            const sideCard = document.querySelector('.side-panel');

            gsap.killTweensOf([mainCard, sideCard]);

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
            if (sideCard) {
                gsap.fromTo(sideCard, {
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