<?php
declare(strict_types=1);

if (!defined('LIVE_DISPLAY_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $tvBodyClass = trim(($tvBodyClass ?? '') . ' tv-schedule-active');
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'schedule';
    $settings['slides']['schedule']['enabled'] = true;
    $settings['slides']['schedule']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-schedule" data-slide="schedule" style="opacity: 1; visibility: visible; transform: scale(1);">';
}

// Fetch current leader info for dynamic background aura
$leaderboard = tv_leaderboard((int)($event['id'] ?? 0));
$firstTeam = !empty($leaderboard) ? $leaderboard[0] : null;
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#10b981';
$firstTeamName = !empty($firstTeam['team_name']) ? $firstTeam['team_name'] : 'Leader';
?>
<?php if (!defined('LIVE_DISPLAY_STAGE')): ?>
<script>
document.body.classList.add('tv-schedule-active');
document.querySelector('.tv-topbar')?.setAttribute('hidden', '');
</script>
<?php endif; ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800;900&family=Outfit:wght@600;700;800;900&family=Cairo:wght@700;800;900&display=swap');

body.tv-schedule-active .tv-topbar,
body.tv-schedule-active .tv-backdrop,
body:has(#slide-schedule.tv-slide--active) .tv-topbar,
body:has(#slide-schedule.tv-slide--active) .tv-backdrop {
    display: none !important;
}

#slide-schedule {
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

.tv-schedule-stage-root {
    width: 100vw;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.tv-schedule {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.schedule-slide-container {
    --first-team-color: <?= e($firstTeamColor) ?>;
    --current-neon: #10b981;
    --panel-glow: rgba(16, 185, 129, 0.12);
    width: 100%;
    max-width: 1650px;
    height: 100vh;
    padding: 48px 64px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 2;
}

/* Light Backdrop Mesh with Subtle Pulse Tinted in 1st Rank Team's Color */
.ambient-mesh-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background: 
        radial-gradient(circle at 18% 25%, color-mix(in srgb, var(--first-team-color, #10b981) 22%, transparent) 0%, transparent 55%),
        radial-gradient(circle at 82% 75%, color-mix(in srgb, var(--first-team-color, #10b981) 14%, transparent) 0%, transparent 48%);
    animation: ambient-pulse 12s ease-in-out infinite alternate;
}

@keyframes ambient-pulse {
    0% { opacity: 0.8; transform: scale(1); }
    100% { opacity: 1; transform: scale(1.04); }
}

/* Upgraded Page Background & Dynamic 3D Cut Geometric Chevrons */
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

#particles-js, .tv-particles {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 1;
}

@keyframes bg-chevron-pulse-float {
    0% {
        transform: translate3d(0, 0, 0) scale(1);
        opacity: 0.85;
    }
    50% {
        transform: translate3d(0, -6px, 0) scale(1.008);
        opacity: 1;
    }
    100% {
        transform: translate3d(0, 0, 0) scale(1);
        opacity: 0.85;
    }
}

@keyframes line-dash-flow {
    0% {
        stroke-dashoffset: 400;
    }
    100% {
        stroke-dashoffset: -400;
    }
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

/* Title Header style: Pure Minimalist */
.schedule-slide-title {
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
    border-bottom: 2px solid color-mix(in srgb, var(--first-team-color) 30%, transparent);
}

.page-count-badge {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(16px);
    border: 1.5px solid color-mix(in srgb, var(--first-team-color) 35%, white);
    padding: 8px 20px;
    border-radius: 30px;
    letter-spacing: 0.05em;
    box-shadow: 0 8px 20px rgba(0,0,0,0.04);
}

/* Schedule Board Glass container */
.tv-schedule-board {
    background: rgba(255, 255, 255, 0.84);
    backdrop-filter: blur(40px);
    -webkit-backdrop-filter: blur(40px);
    border: 1.5px solid color-mix(in srgb, var(--first-team-color) 25%, rgba(255, 255, 255, 0.95));
    border-radius: 36px;
    overflow: hidden;
    box-shadow: 0 24px 60px -12px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    transition: border-color 1.2s ease;
}

/* Table Header */
.tv-schedule-board-head {
    display: grid;
    grid-template-columns: 80px 160px minmax(0, 1fr) 220px 180px;
    align-items: center;
    height: 64px;
    background: rgba(15, 23, 42, 0.04);
    border-bottom: 1.5px solid rgba(15, 23, 42, 0.08);
    padding: 0 36px;
    box-sizing: border-box;
}

.tv-schedule-board-head span {
    font-size: 14px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: #475569;
}

/* Table Body rows list */
.tv-schedule-page {
    display: flex;
    flex-direction: column;
}

.tv-schedule-row {
    display: grid;
    grid-template-columns: 80px 160px minmax(0, 1fr) 220px 180px;
    align-items: center;
    min-height: 94px;
    padding: 12px 36px;
    box-sizing: border-box;
    border-bottom: 1px solid rgba(15, 23, 42, 0.06);
    background: transparent;
    transition: background 0.3s ease, border-left-color 0.3s ease;
    border-left: 5px solid transparent;
}

.tv-schedule-row:last-child {
    border-bottom: none;
}

.tv-schedule-row.is-first-upcoming {
    background: rgba(16, 185, 129, 0.06) !important;
    border-left: 6px solid #10b981 !important;
}

.tv-schedule-row.is-break {
    background: rgba(245, 158, 11, 0.06) !important;
    border-left: 6px solid #f59e0b !important;
}

.tv-schedule-row-num {
    font-size: 20px;
    font-weight: 900;
    color: #64748b;
    font-family: 'Plus Jakarta Sans', monospace;
}

.tv-schedule-row.is-first-upcoming .tv-schedule-row-num {
    color: #10b981;
}

.tv-schedule-row-time {
    font-size: 26px;
    font-weight: 900;
    color: #0f172a;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-variant-numeric: tabular-nums;
}

.tv-schedule-row-program {
    display: flex;
    align-items: center;
    min-width: 0;
}

.tv-schedule-row-program strong {
    font-size: 32px;
    font-weight: 900;
    color: #0f172a;
    text-transform: uppercase;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: 'Plus Jakarta Sans', 'Cairo', sans-serif;
    letter-spacing: -0.01em;
}

.tv-schedule-row-location {
    font-size: 20px;
    font-weight: 800;
    color: #475569;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tv-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 20px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 900;
    text-transform: uppercase;
    text-align: center;
    letter-spacing: 0.05em;
}

.tv-status.completed {
    background: rgba(148, 163, 184, 0.15);
    border: 1px solid rgba(148, 163, 184, 0.3);
    color: #64748b;
}

.tv-status.inprogress {
    background: rgba(16, 185, 129, 0.15);
    border: 1.5px solid rgba(16, 185, 129, 0.4);
    color: #059669;
}

.tv-status.upcoming {
    background: rgba(59, 130, 246, 0.12);
    border: 1.5px solid rgba(59, 130, 246, 0.3);
    color: #2563eb;
}

.tv-status.break {
    background: rgba(245, 158, 11, 0.12);
    border: 1.5px solid rgba(245, 158, 11, 0.3);
    color: #d97706;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js" defer></script>

<div class="tv-schedule-stage-root" data-schedule-stage-root style="--first-team-color: <?= e($firstTeamColor) ?>; --top-team-color: <?= e($firstTeamColor) ?>;">
    <!-- 3D Relief Geometric Layer Cuts Background -->
    <svg class="bg-3d-cuts-svg" viewBox="0 0 1920 1080" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
        <filter id="cutShadowSchedule" x="-20%" y="-20%" width="140%" height="140%">
            <feDropShadow dx="-8" dy="12" stdDeviation="15" flood-color="rgba(0,0,0,0.06)" />
        </filter>
        <polygon points="-100,1200 650,540 -100,-100" fill="#ffffff" filter="url(#cutShadowSchedule)" />
        <polygon points="-100,1050 480,540 -100,30" fill="rgba(250,250,252,0.92)" filter="url(#cutShadowSchedule)" />
        <polygon points="2020,1200 1270,540 2020,-100" fill="#ffffff" filter="url(#cutShadowSchedule)" />
        <polygon points="2020,1050 1440,540 2020,30" fill="rgba(250,250,252,0.92)" filter="url(#cutShadowSchedule)" />
    </svg>

    <!-- Dynamic 1st Rank Team Geometric Chevron Vectors -->
    <svg class="side-chevrons-svg full-screen" viewBox="0 0 1920 1080" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
        <g class="animated-chevron-group">
            <path class="animated-dash-line" d="M-100 1200 L650 540 L-100 -100" stroke="var(--first-team-color, #10b981)" stroke-width="3" stroke-linecap="round" />
            <path class="animated-dash-line" d="M-150 1050 L480 540 L-150 30" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.8" stroke-linecap="round" />
            <line class="animated-cross-line" x1="200" y1="900" x2="420" y2="680" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.75" />
            <line class="animated-cross-line" x1="320" y1="780" x2="540" y2="560" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.75" />

            <path class="animated-dash-line" d="M2020 1200 L1270 540 L2020 -100" stroke="var(--first-team-color, #10b981)" stroke-width="3" stroke-linecap="round" />
            <path class="animated-dash-line" d="M2070 1050 L1440 540 L2070 30" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.8" stroke-linecap="round" />
            <line class="animated-cross-line" x1="1720" y1="900" x2="1500" y2="680" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.75" />
        </g>
    </svg>

    <div class="ambient-mesh-bg"></div>
    <div id="particles-js" class="tv-particles"></div>

    <div class="tv-schedule" data-schedule></div>
</div>

<script>
    (() => {
        let activeParticleColor = '';

        function initTopTeamParticles(colorHex) {
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
    })();
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
