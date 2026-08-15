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
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800;900&family=Outfit:wght@600;700;800;900&family=Cairo:wght@700;800;900&display=swap');

body.tv-leaderboard-only .tv-topbar,
body:has(#slide-leaderboard.tv-slide--active) .tv-topbar {
    display: none !important;
}

#slide-leaderboard {
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

.tv-leaderboard-stage-root {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

/* Background Effects (Pure Video Background) */
.ambient-mesh-bg,
.bg-3d-cuts-svg,
.side-chevrons-svg,
.orbital-aura-bg {
    display: none !important;
}

@keyframes bg-chevron-pulse-float {
    0% { transform: translate3d(0, 0, 0) scale(1); opacity: 0.85; }
    50% { transform: translate3d(0, -6px, 0) scale(1.008); opacity: 1; }
    100% { transform: translate3d(0, 0, 0) scale(1); opacity: 0.85; }
}

@keyframes line-dash-flow {
    0% { stroke-dashoffset: 400; }
    100% { stroke-dashoffset: -400; }
}

.animated-chevron-group {
    will-change: transform, opacity;
    animation: bg-chevron-pulse-float 10s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite alternate;
}

.animated-dash-line {
    stroke-dasharray: 250 120;
    will-change: stroke-dashoffset;
    animation: line-dash-flow 24s linear infinite;
    transition: stroke 1.4s cubic-bezier(0.19, 1, 0.22, 1), opacity 1.4s cubic-bezier(0.19, 1, 0.22, 1);
}

.animated-cross-line {
    transition: stroke 1.4s cubic-bezier(0.19, 1, 0.22, 1), opacity 1.4s cubic-bezier(0.19, 1, 0.22, 1);
}

/* Orbital Stage Container */
.orbital-stage-container {
    position: relative;
    width: 1050px;
    height: 820px;
    max-width: 96%;
    max-height: 94%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
}

/* Background Glowing Mesh Auras - Disabled to remove color spread */
.orbital-aura-bg {
    display: none !important;
}

@keyframes aura-float {
    0% { transform: scale(0.92) translate(0, 0); opacity: 0.3; }
    100% { transform: scale(1.08) translate(0, 0); opacity: 0.45; }
}

/* SVG Vector Canvas */
.orbital-vector-canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 1;
}

/* Constellation Hub Center 8-Point Star Medallion Node */
.orbital-center-node.constellation-star-node {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 160px;
    height: 160px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(30px);
    -webkit-backdrop-filter: blur(30px);
    border: 2.5px solid color-mix(in srgb, var(--first-team-color, #10b981) 45%, white);
    box-shadow: 
        0 15px 45px rgba(0, 0, 0, 0.08),
        0 0 30px color-mix(in srgb, var(--first-team-color, #10b981) 28%, transparent);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

.constellation-star-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.star-rotation-group {
    transform-origin: 80px 80px;
    animation: slow-star-spin 30s linear infinite;
}

@keyframes slow-star-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.constellation-center-content {
    position: relative;
    z-index: 5;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.constellation-kicker {
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 0.18em;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.constellation-lead-num {
    font-size: 32px;
    font-weight: 900;
    color: var(--first-team-color, #10b981);
    font-family: 'Plus Jakarta Sans', monospace;
    line-height: 1;
}

.constellation-unit {
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 0.12em;
    color: #475569;
}

/* Pulsing Radial Laser Connector Beams */
.constellation-laser-line {
    animation: laser-flow 1.8s linear infinite;
    filter: drop-shadow(0 0 4px currentColor);
}

@keyframes laser-flow {
    0% { stroke-dashoffset: 56; }
    100% { stroke-dashoffset: 0; }
}

/* Card Point Gap Pills */
.orbital-gap-pill {
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.08em;
    padding: 3px 12px;
    border-radius: 16px;
    font-family: 'Plus Jakarta Sans', monospace;
}

.orbital-gap-pill.leader-gap {
    background: color-mix(in srgb, var(--accent-color, #10b981) 16%, rgba(255,255,255,0.9));
    border: 1px solid color-mix(in srgb, var(--accent-color, #10b981) 40%, white);
    color: var(--accent-color, #10b981);
}

.orbital-gap-pill.chaser-gap {
    background: rgba(15, 23, 42, 0.06);
    border: 1px solid rgba(15, 23, 42, 0.12);
    color: #64748b;
}

/* 4 Quadrant Cards */
.orbital-card {
    position: absolute;
    width: 320px;
    height: 255px;
    border-radius: 30px;
    padding: 24px 28px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(40px);
    -webkit-backdrop-filter: blur(40px);
    border: 2px solid color-mix(in srgb, var(--accent-color, #10b981) 40%, rgba(255, 255, 255, 0.95));
    box-shadow: 
        0 20px 50px -10px rgba(0, 0, 0, 0.05),
        0 0 25px color-mix(in srgb, var(--accent-color, #10b981) 25%, transparent);
    overflow: hidden;
    z-index: 5;
    transition: transform 0.4s ease, box-shadow 0.4s ease;
}

/* Card hover animation disabled */
.orbital-card:hover {
    transform: none !important;
}

/* 3D stage perspective container support */
.orbital-stage-container {
    perspective: 1200px !important;
    transform-style: preserve-3d !important;
}

/* Slide Entrance & Exiting Transitions */
#slide-leaderboard {
    transition: opacity 0.45s ease, transform 0.45s ease, visibility 0.45s ease !important;
}

#slide-leaderboard.tv-slide--exiting {
    opacity: 0 !important;
    transform: scale(0.96) rotateX(12deg) !important;
}

#slide-leaderboard.tv-slide--exiting .orbital-card[data-pos="1"] {
    transform: translateY(-150px) rotateX(-30deg) scale(0.75) !important;
    opacity: 0 !important;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

#slide-leaderboard.tv-slide--exiting .orbital-card[data-pos="2"] {
    transform: translateX(150px) rotateY(30deg) scale(0.75) !important;
    opacity: 0 !important;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

#slide-leaderboard.tv-slide--exiting .orbital-card[data-pos="3"] {
    transform: translateX(-150px) rotateY(-30deg) scale(0.75) !important;
    opacity: 0 !important;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

#slide-leaderboard.tv-slide--exiting .orbital-card[data-pos="4"] {
    transform: translateY(150px) rotateX(30deg) scale(0.75) !important;
    opacity: 0 !important;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

#slide-leaderboard.tv-slide--exiting .orbital-center-node {
    transform: scale(0.4) rotateZ(90deg) !important;
    opacity: 0 !important;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

/* Embedded Card Chevron Animations (Stroked in Team Color) */
.card-chevrons-svg {
    position: absolute !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
    pointer-events: none !important;
    z-index: 0 !important;
    overflow: hidden !important;
    border-radius: inherit;
}

.animated-card-group {
    will-change: transform, opacity;
    animation: bg-chevron-pulse-float 9s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite alternate;
}

.animated-dash-line {
    stroke-dasharray: 160 80;
    will-change: stroke-dashoffset;
    animation: line-dash-flow 18s linear infinite;
    filter: drop-shadow(0 0 6px var(--accent-color, #10b981));
    transition: stroke 1s ease;
}

.animated-cross-line {
    filter: drop-shadow(0 0 4px var(--accent-color, #10b981));
    transition: stroke 1s ease;
}

/* Dark subtle glass curve overlay at bottom of card */
.orbital-card-dark-wave {
    position: absolute;
    bottom: -40px;
    left: -20px;
    right: -20px;
    height: 120px;
    background: radial-gradient(ellipse at center bottom, color-mix(in srgb, var(--accent-color, #10b981) 12%, rgba(255,255,255,0.9)) 0%, transparent 80%);
    border-top-left-radius: 50%;
    border-top-right-radius: 50%;
    pointer-events: none;
    z-index: 1;
}

/* Positional Alignments */
.orbital-card[data-pos="1"] {
    top: 0;
    left: 50%;
    transform: translateX(-50%);
}

.orbital-card[data-pos="2"] {
    top: 50%;
    right: 0;
    transform: translateY(-50%);
}

.orbital-card[data-pos="3"] {
    top: 50%;
    left: 0;
    transform: translateY(-50%);
}

.orbital-card[data-pos="4"] {
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
}

/* Card Header */
.orbital-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    z-index: 2;
}

.orbital-badge-leading {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: color-mix(in srgb, var(--accent-color, #10b981) 15%, rgba(255,255,255,0.9));
    border: 1.5px solid color-mix(in srgb, var(--accent-color, #10b981) 45%, white);
    color: #0f172a;
    padding: 5px 16px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.orbital-rank-index {
    font-size: 20px;
    font-weight: 900;
    color: #475569;
    letter-spacing: 0.05em;
    margin-left: auto;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* Card Main Title */
.orbital-team-title {
    font-size: 34px;
    font-weight: 900;
    color: #0f172a;
    margin: 10px 0 4px 0;
    line-height: 1.15;
    font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
    position: relative;
    z-index: 2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Card Score Row */
.orbital-score-wrapper {
    display: flex;
    align-items: baseline;
    gap: 12px;
    position: relative;
    z-index: 2;
}

.orbital-score-digit {
    font-size: 76px;
    font-weight: 900;
    color: var(--accent-color, #10b981);
    font-family: 'Plus Jakarta Sans', monospace;
    line-height: 1;
}

.orbital-score-label {
    font-size: 14px;
    font-weight: 900;
    color: #64748b;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

/* Extra Teams Row (Rank 5+) */
.orbital-extra-teams-bar {
    position: absolute;
    bottom: 15px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 16px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(15, 23, 42, 0.12);
    padding: 8px 24px;
    border-radius: 30px;
    z-index: 20;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
}

.orbital-extra-team-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
}
</style>

<div class="tv-leaderboard-stage-root" data-leaderboard data-leaderboard-stage>
    <!-- Dynamic Leaderboard Stage rendered by live-display.js -->
</div>

<script>
(function initLeaderboardStage() {
    function tryMount() {
        if (!window.TV_BOOTSTRAP_DATA && window.TV_BOOT?.initial) {
            window.TV_BOOTSTRAP_DATA = window.TV_BOOT.initial;
        }
        const container = document.querySelector('[data-leaderboard], [data-leaderboard-stage]');
        const teams = window.TV_BOOTSTRAP_DATA?.leaderboard || window.TV_BOOT?.initial?.leaderboard || [];
        if (container && typeof renderLeaderboard === 'function' && teams.length > 0) {
            renderLeaderboard(teams);
        } else if (!container || !teams.length) {
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
