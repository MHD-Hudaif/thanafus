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
    background: #f8fafc url('<?= asset_url('images/white-background.png') ?>') center center / cover no-repeat;
    font-family: 'Plus Jakarta Sans', 'Outfit', 'Cairo', system-ui, -apple-system, sans-serif;
    color: #0f172a;
    width: 100vw;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.tv-leaderboard-stage-root {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.ambient-mesh-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background:
        radial-gradient(circle at 15% 20%, color-mix(in srgb, var(--first-team-color, #10b981) 25%, transparent) 0%, transparent 65%),
        radial-gradient(circle at 85% 80%, color-mix(in srgb, var(--first-team-color, #10b981) 20%, transparent) 0%, transparent 60%);
    animation: simple-aura-pulse 7s ease-in-out infinite alternate;
}

@keyframes simple-aura-pulse {
    0% { opacity: 0.7; transform: scale(1); }
    100% { opacity: 1; transform: scale(1.05); }
}

.bg-3d-cuts-svg {
    position: absolute;
    inset: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    z-index: 0;
}

.side-chevrons-svg.full-screen {
    position: absolute;
    inset: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    z-index: 1;
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

.leaderboard-slide-container {
    --first-team-color: <?= e($firstTeamColor) ?>;
    --current-neon: #10b981;
    width: 100%;
    max-width: 1720px;
    height: 100vh;
    padding: 50px 70px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 2;
}

.leaderboard-slide-title {
    font-size: 38px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.01em;
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 16px;
    margin-bottom: 24px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    border-bottom: 2px solid color-mix(in srgb, var(--first-team-color, #10b981) 30%, transparent);
}

.first-team-rank-pill {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1.5px solid color-mix(in srgb, var(--first-team-color, #10b981) 40%, white);
    padding: 10px 24px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 800;
    color: #1e293b;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 1.65fr 1fr;
    gap: 32px;
    width: 100%;
    align-items: stretch;
    position: relative;
    z-index: 2;
}

.glass-panel {
    background: rgba(255, 255, 255, 0.82);
    backdrop-filter: blur(40px);
    -webkit-backdrop-filter: blur(40px);
    border: 1.5px solid color-mix(in srgb, var(--first-team-color, #10b981) 25%, rgba(255, 255, 255, 0.95));
    border-radius: 36px;
    padding: 48px 56px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    overflow: hidden;
    box-sizing: border-box;
    box-shadow: 0 24px 60px -12px rgba(0, 0, 0, 0.05);
    transition: transform 0.6s ease, border-color 1.2s ease;
}

.glass-panel:hover {
    transform: translateY(-4px);
}

.leaderboard-hero-card {
    min-height: 540px;
    justify-content: space-between;
}

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

.hero-leader-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: color-mix(in srgb, var(--first-team-color, #10b981) 12%, rgba(255, 255, 255, 0.9));
    border: 1.5px solid color-mix(in srgb, var(--first-team-color, #10b981) 35%, white);
    padding: 8px 20px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #0f172a;
    width: fit-content;
}

.hero-team-name {
    font-size: 64px;
    font-weight: 900;
    margin: 20px 0 10px 0;
    color: #0f172a;
    letter-spacing: -0.02em;
    line-height: 1.1;
    font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.hero-score-box {
    background: rgba(255, 255, 255, 0.88);
    border: 1px solid rgba(255, 255, 255, 0.95);
    padding: 32px 48px;
    border-radius: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 14px 35px rgba(0, 0, 0, 0.03);
    margin-top: 24px;
}

.hero-score-label {
    font-size: 14px;
    font-weight: 900;
    letter-spacing: 0.2em;
    color: #64748b;
    text-transform: uppercase;
}

.hero-score-num {
    font-size: 88px;
    font-weight: 900;
    color: var(--first-team-color, #10b981);
    font-family: 'Plus Jakarta Sans', monospace;
    line-height: 1;
}

.sidebar-column {
    display: flex;
    flex-direction: column;
    gap: 28px;
    height: 100%;
    justify-content: space-between;
}

.side-card-top {
    flex: 1;
    min-height: 250px;
    padding: 32px 40px;
    text-align: center;
    justify-content: center;
    align-items: center;
}

.side-card-bottom {
    flex: 1;
    min-height: 250px;
    padding: 32px 40px;
    justify-content: center;
}

.side-box-label {
    font-size: 14px;
    font-weight: 900;
    color: #64748b;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    margin-bottom: 12px;
}

.runner-team-name {
    font-size: 40px;
    font-weight: 900;
    color: #0f172a;
    margin: 0 0 10px 0;
    font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
}

.runner-score-big {
    font-size: 72px;
    font-weight: 900;
    line-height: 1;
    font-family: 'Plus Jakarta Sans', monospace;
}

.standings-rows-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
}

.standing-row-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 18px;
    font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
}

.st-rank-badge {
    font-size: 14px;
    font-weight: 900;
    color: #475569;
    width: 48px;
}

.st-team-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
}

.tv-team-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    display: inline-block;
}

.st-pts-num {
    font-size: 24px;
    font-weight: 900;
    color: #0f172a;
    font-family: 'Plus Jakarta Sans', monospace;
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
