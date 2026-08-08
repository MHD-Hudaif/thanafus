<?php
declare(strict_types=1);

if (!defined('LIVE_DISPLAY_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'leaderboard';
    $settings['slides']['leaderboard']['enabled'] = true;
    $settings['slides']['leaderboard']['duration'] = 999999;
    $tvBodyClass = 'tv-leaderboard-only';
    $tvBootstrapData = tv_bootstrap_data();
    $tvBootstrapData['settings']['mode'] = 'manual';
    $tvBootstrapData['settings']['active_slide'] = 'leaderboard';
    $tvBootstrapData['settings']['slides']['leaderboard']['enabled'] = true;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-leaderboard" data-slide="leaderboard" style="opacity: 1; visibility: visible; transform: scale(1);">';
    echo '<script>window.TV_FORCE_LEADERBOARD_ONLY = true; document.body.classList.add(\'tv-leaderboard-only\');</script>';
}

// Fetch current leader info for dynamic background aura
$leaderboard = tv_leaderboard((int)($event['id'] ?? 0));
$firstTeam = !empty($leaderboard) ? $leaderboard[0] : null;
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#10b981';
$firstTeamName = !empty($firstTeam['team_name']) ? $firstTeam['team_name'] : 'Leader';
?>
<?php if (!defined('LIVE_DISPLAY_STAGE')): ?>
<script>
document.body.classList.add('tv-leaderboard-only');
document.querySelector('.tv-topbar')?.setAttribute('hidden', '');
</script>
<?php endif; ?>

<style>
body.tv-leaderboard-only .tv-topbar,
body:has(#slide-leaderboard.tv-slide--active) .tv-topbar {
    display: none !important;
}

#slide-leaderboard {
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden;
    background: #02040a;
    font-family: 'Inter', 'Cairo', system-ui, -apple-system, sans-serif;
    color: #f8fafc;
    width: 100vw;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.tv-leaderboard-stage-root {
    width: 100vw;
    height: 100vh;
    position: absolute;
    inset: 0;
    overflow: hidden;
    background: #02040a;
}

/* 3D WebGL Canvas Backdrop - 100% Fullscreen Broadcast Engine */
.tv-3d-canvas {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    z-index: 1 !important;
    pointer-events: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Screen-Space Projected 3D Labels */
.team-labels {
    position: absolute;
    inset: 0;
    z-index: 5;
    pointer-events: none;
    overflow: hidden;
}

.team-label {
    position: absolute;
    min-width: 180px;
    text-align: center;
    text-shadow: 0 2px 14px #000, 0 0 28px #000;
    transform: translate(-50%, -50%);
    will-change: transform, opacity;
    transition: opacity 0.35s ease;
    pointer-events: none;
}

.team-label__rank {
    display: block;
    margin-bottom: 4px;
    color: var(--team-color);
    font-size: 14px;
    font-weight: 900;
    letter-spacing: 0.22em;
    text-transform: uppercase;
}

.team-label__name {
    display: block;
    font-family: 'Inter', 'Cairo', system-ui, sans-serif;
    font-size: 26px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #ffffff;
}

.team-label__score {
    display: block;
    margin-top: 4px;
    color: #fef08a;
    font-size: 32px;
    font-variant-numeric: tabular-nums;
    font-weight: 900;
    letter-spacing: 0.04em;
}

.team-label.is-leading .team-label__name {
    color: #fef08a;
    text-shadow: 0 0 28px #f59e0b;
}

/* Minimal Live Overlay Indicator */
.tv-broadcast-overlay {
    position: absolute;
    top: 35px;
    right: 50px;
    z-index: 10;
    pointer-events: none;
}

.live-chip-3d {
    font-size: 16px;
    font-weight: 900;
    color: #ef4444;
    background: rgba(239, 68, 68, 0.12);
    border: 1.5px solid rgba(239, 68, 68, 0.35);
    padding: 8px 20px;
    border-radius: 30px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.2);
}

.live-chip-3d span {
    width: 10px;
    height: 10px;
    background: #ef4444;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 10px #ef4444;
    animation: live-pulse-3d 1.6s infinite ease-in-out;
}

@keyframes live-pulse-3d {
    0% { transform: scale(0.9); opacity: 0.6; }
    50% { transform: scale(1.3); opacity: 1; box-shadow: 0 0 16px #ef4444; }
    100% { transform: scale(0.9); opacity: 0.6; }
}

/* Watermark indicator showing leading team */
.aura-watermark {
    position: absolute;
    bottom: 25px;
    right: 50px;
    font-size: 14px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.25);
    text-transform: uppercase;
    letter-spacing: 0.18em;
    pointer-events: none;
    z-index: 10;
}
</style>

<div class="tv-leaderboard-stage-root" data-leaderboard data-leaderboard-stage>
    <!-- Fullscreen 3D WebGL Canvas -->
    <canvas id="tvLeaderboardCanvas" class="tv-3d-canvas"></canvas>

    <!-- Screen-Space 3D Projected Floating Labels Container -->
    <div id="team-labels" class="team-labels"></div>

    <!-- Minimal Broadcast Live Chip Overlay -->
    <div class="tv-broadcast-overlay">
        <div class="live-chip-3d">
            <span></span> Live Standings
        </div>
    </div>

    <!-- Dynamic Leader Watermark -->
    <div class="aura-watermark">
        Champion Aura: <?= e($firstTeamName) ?>
    </div>
</div>

<script>
(function init3DStage() {
    function tryMount() {
        if (!window.TV_BOOTSTRAP_DATA && window.TV_BOOT?.initial) {
            window.TV_BOOTSTRAP_DATA = window.TV_BOOT.initial;
        }
        const canvas = document.querySelector('#tvLeaderboardCanvas');
        const teams = window.TV_BOOTSTRAP_DATA?.leaderboard || window.TV_BOOT?.initial?.leaderboard || [];
        if (canvas && window.TVLeaderboard3D) {
            window.TVLeaderboard3D.mount(canvas, teams);
        } else {
            setTimeout(tryMount, 50);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryMount);
    } else {
        tryMount();
    }
})();
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
