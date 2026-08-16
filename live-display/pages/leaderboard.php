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

/* Background Effects (Hiding old SVGs to keep display clean and modern) */
.ambient-mesh-bg,
.bg-3d-cuts-svg,
.side-chevrons-svg,
.orbital-vector-canvas {
    display: none !important;
}

/* 3D stage perspective container support */
.orbital-stage-container {
    position: relative;
    display: flex !important;
    flex-direction: row !important;
    align-items: flex-end !important;
    justify-content: center !important;
    gap: 24px !important;
    width: 100% !important;
    height: 100% !important;
    max-width: 1450px !important;
    max-height: 96% !important;
    padding: 40px 20px 80px 20px !important;
    margin: 0 auto !important;
    perspective: 1200px !important;
    transform-style: preserve-3d !important;
    box-sizing: border-box;
}

/* Orbital Stage Medallion Node (Centered at Top) */
.orbital-center-node.constellation-star-node {
    position: absolute !important;
    top: 24px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: 140px !important;
    height: 140px !important;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(30px);
    -webkit-backdrop-filter: blur(30px);
    border: 2.5px solid color-mix(in srgb, var(--first-team-color, #10b981) 45%, white);
    box-shadow: 
        0 15px 45px rgba(0, 0, 0, 0.12),
        0 0 40px color-mix(in srgb, var(--first-team-color, #10b981) 35%, transparent);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 10;
    margin: 0 !important;
}

.constellation-star-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.star-rotation-group {
    transform-origin: 70px 70px;
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
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 0.18em;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.constellation-lead-num {
    font-size: 28px;
    font-weight: 900;
    color: var(--first-team-color, #10b981);
    font-family: 'Plus Jakarta Sans', monospace;
    line-height: 1;
}

.constellation-unit {
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 0.12em;
    color: #475569;
}

/* Card Point Gap Pills */
.orbital-gap-pill {
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.06em;
    padding: 4px 12px;
    border-radius: 16px;
    font-family: 'Plus Jakarta Sans', monospace;
}

.orbital-gap-pill.leader-gap {
    background: linear-gradient(135deg, color-mix(in srgb, var(--accent-color, #10b981) 22%, rgba(255,255,255,0.95)) 0%, color-mix(in srgb, var(--accent-color, #10b981) 10%, rgba(255,255,255,0.9)) 100%);
    border: 1px solid color-mix(in srgb, var(--accent-color, #10b981) 45%, white);
    color: var(--accent-color, #10b981);
}

.orbital-gap-pill.chaser-gap {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.07) 0%, rgba(15, 23, 42, 0.02) 100%);
    border: 1px solid rgba(15, 23, 42, 0.12);
    color: rgba(255, 255, 255, 0.7);
}

/* 4 Quadrant Cards - Responsive Flex/Podium overhaul */
.orbital-card {
    position: relative !important;
    top: auto !important;
    bottom: auto !important;
    left: auto !important;
    right: auto !important;
    transform: none !important;
    width: 290px !important;
    height: 350px !important;
    border-radius: 24px;
    padding: 28px 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background: 
        linear-gradient(
            135deg, 
            rgba(15, 23, 42, 0.92) 0%, 
            rgba(15, 23, 42, 0.82) 55%, 
            color-mix(in srgb, var(--accent-color, #10b981) 18%, rgba(15, 23, 42, 0.9)) 100%
        );
    backdrop-filter: blur(30px);
    -webkit-backdrop-filter: blur(30px);
    border: 2px solid color-mix(in srgb, var(--accent-color, #10b981) 45%, rgba(255, 255, 255, 0.15));
    box-shadow: 
        0 20px 50px -10px rgba(0, 0, 0, 0.55),
        inset 0 0 20px rgba(255,255,255,0.03);
    overflow: hidden;
    z-index: 5;
    box-sizing: border-box;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Top Accent Glow Bar */
.orbital-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 5px;
    background: linear-gradient(
        90deg, 
        transparent 0%,
        var(--accent-color, #10b981) 50%, 
        transparent 100%
    );
    box-shadow: 0 0 14px var(--accent-color, #10b981);
}

/* Slide Entrance & Exiting Transitions */
#slide-leaderboard {
    transition: opacity 0.45s ease, transform 0.45s ease, visibility 0.45s ease !important;
}

#slide-leaderboard.tv-slide--exiting {
    opacity: 0 !important;
    transform: scale(0.98) !important;
    transition: opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1), transform 0.35s cubic-bezier(0.16, 1, 0.3, 1) !important;
}

/* Dark glass curve overlay at bottom of card */
.orbital-card-dark-wave {
    position: absolute;
    bottom: -40px;
    left: -20px;
    right: -20px;
    height: 120px;
    background: radial-gradient(ellipse at center bottom, color-mix(in srgb, var(--accent-color, #10b981) 15%, transparent) 0%, transparent 80%);
    border-top-left-radius: 50%;
    border-top-right-radius: 50%;
    pointer-events: none;
    z-index: 1;
}

/* Podium Heights & Orders (Silver, Gold, Bronze, Runner-up) */
.orbital-card[data-pos="1"] {
    order: 2 !important;
    height: 390px !important;
    border: 2.5px solid #ffd700 !important;
    box-shadow: 
        0 25px 60px rgba(0, 0, 0, 0.6),
        0 0 40px color-mix(in srgb, var(--accent-color, #10b981) 40%, transparent) !important;
}

.orbital-card[data-pos="2"] {
    order: 1 !important;
    height: 350px !important;
}

.orbital-card[data-pos="3"] {
    order: 3 !important;
    height: 330px !important;
}

.orbital-card[data-pos="4"] {
    order: 4 !important;
    height: 300px !important;
}

/* Card Header */
.orbital-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    z-index: 2;
    margin-bottom: 8px;
}

.orbital-rank-index {
    font-size: 24px;
    font-weight: 900;
    color: rgba(255, 255, 255, 0.85);
    letter-spacing: -0.02em;
    margin-left: auto;
    font-family: 'Outfit', sans-serif;
    display: flex;
    align-items: center;
}

/* Card Main Title */
.orbital-team-title {
    font-size: 32px;
    font-weight: 900;
    color: #ffffff;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.6);
    margin: 12px 0 4px 0;
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
    gap: 10px;
    position: relative;
    z-index: 2;
}

.orbital-score-digit {
    font-size: 68px;
    font-weight: 900;
    color: var(--accent-color, #10b981);
    text-shadow: 0 0 25px color-mix(in srgb, var(--accent-color, #10b981) 40%, transparent);
    font-family: 'Plus Jakarta Sans', monospace;
    line-height: 1;
}

.orbital-score-label {
    font-size: 13px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.55);
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

/* Extra Teams Row (Rank 5+) */
.orbital-extra-teams-bar {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 18px;
    background: rgba(15, 23, 42, 0.9);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1.5px solid rgba(255, 255, 255, 0.12);
    padding: 10px 28px;
    border-radius: 30px;
    z-index: 20;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6);
}

.orbital-extra-team-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 800;
    color: #ffffff;
}

.orbital-extra-team-item span.tv-team-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

/* ──────────────── Responsive Overhaul ──────────────── */
@media (max-width: 1024px) {
    .orbital-stage-container {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        justify-content: flex-start !important;
        gap: 16px !important;
        width: 100% !important;
        height: auto !important;
        max-width: 620px !important;
        max-height: none !important;
        padding: 110px 16px 120px 16px !important;
        overflow-y: auto !important;
        perspective: none !important;
        transform-style: flat !important;
    }

    #slide-leaderboard {
        overflow-y: auto !important;
    }

    .orbital-center-node.constellation-star-node {
        position: absolute !important;
        top: 16px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        width: 84px !important;
        height: 84px !important;
        border-width: 1.5px !important;
        z-index: 100 !important;
    }

    .constellation-star-svg {
        display: none !important; /* Hide spinner ring on mobile for simplicity */
    }

    .constellation-kicker {
        font-size: 7px !important;
        letter-spacing: 0.1em !important;
    }

    .constellation-lead-num {
        font-size: 20px !important;
    }

    .constellation-unit {
        font-size: 7px !important;
    }

    .orbital-card {
        position: relative !important;
        inset: auto !important;
        width: 100% !important;
        height: auto !important;
        min-height: 86px !important;
        padding: 14px 20px !important;
        border-radius: 16px !important;
        transform: none !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: space-between !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4) !important;
    }

    .orbital-card[data-pos="1"] {
        order: 1 !important;
        height: auto !important;
        border: 2px solid #ffd700 !important;
        box-shadow: 0 8px 24px rgba(255, 215, 0, 0.15), 0 8px 24px rgba(0,0,0,0.4) !important;
    }
    .orbital-card[data-pos="2"] { order: 2 !important; height: auto !important; }
    .orbital-card[data-pos="3"] { order: 3 !important; height: auto !important; }
    .orbital-card[data-pos="4"] { order: 4 !important; height: auto !important; }

    .orbital-card-dark-wave {
        display: none !important;
    }

    .orbital-card-header {
        display: flex !important;
        flex-direction: row-reverse !important;
        align-items: center !important;
        gap: 12px !important;
        margin-bottom: 0 !important;
        width: auto !important;
    }

    .orbital-rank-index {
        font-size: 20px !important;
        margin-left: 0 !important;
    }

    .orbital-team-title {
        font-size: 20px !important;
        margin: 0 0 0 12px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        flex-grow: 1 !important;
        text-align: left !important;
    }

    .orbital-score-wrapper {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-end !important;
        gap: 0 !important;
        margin-left: 12px !important;
    }

    .orbital-score-digit {
        font-size: 32px !important;
    }

    .orbital-score-label {
        font-size: 8px !important;
        letter-spacing: 0.05em !important;
    }

    .orbital-extra-teams-bar {
        position: relative !important;
        bottom: auto !important;
        left: auto !important;
        transform: none !important;
        display: flex !important;
        flex-wrap: wrap !important;
        justify-content: center !important;
        width: 100% !important;
        margin-top: 20px !important;
        padding: 12px 16px !important;
        border-radius: 16px !important;
        box-shadow: none !important;
        background: rgba(15, 23, 42, 0.7) !important;
        border: 1px solid rgba(255, 255, 255, 0.08) !important;
        order: 5 !important;
        gap: 12px 16px !important;
    }

    .orbital-extra-team-item {
        font-size: 12px !important;
    }
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
