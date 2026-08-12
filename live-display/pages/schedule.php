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
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#6400a6';
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
body:has(#slide-schedule.tv-slide--active) .tv-topbar {
    display: none !important;
}

#slide-schedule {
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden;
    background: transparent !important;
    font-family: 'Plus Jakarta Sans', 'Outfit', 'Cairo', system-ui, -apple-system, sans-serif;
    color: #fff;
    width: 100% !important;
    height: 100% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute !important;
    inset: 0 !important;
}

.tv-schedule-stage-root {
    width: 100%;
    height: 100%;
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
    --current-neon: var(--first-team-color, #6400a6);
    --panel-glow: color-mix(in srgb, var(--first-team-color, #6400a6) 12%, transparent);
    width: 100%;
    max-width: 1650px;
    height: 100%;
    padding: 48px 64px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 2;
}

/* Title Header style: Pure Minimalist */
.schedule-slide-title {
    font-size: 38px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.01em;
    color: #fff !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 16px;
    margin-bottom: 24px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    border-bottom: 2px solid rgba(255, 255, 255, 0.15);
}

.page-count-badge {
    font-size: 16px;
    font-weight: 800;
    color: #fff !important;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(16px);
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    padding: 8px 20px;
    border-radius: 30px;
    letter-spacing: 0.05em;
}

.schedule-table-card {
    width: 100%;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border-radius: 24px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    z-index: 1;
}

.schedule-table {
    width: 100%;
    border-collapse: collapse;
    background: transparent;
}

.schedule-table th {
    background-color: rgba(248, 250, 252, 0.95);
    font-size: 12px;
    color: #64748b;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 18px 24px;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
    text-align: left;
}

.schedule-table td {
    padding: 16px 24px;
    text-align: left;
    border-bottom: 1px solid rgba(241, 245, 249, 0.9);
    vertical-align: middle;
}

.date-header-row {
    background-color: rgba(241, 245, 249, 0.9);
    font-weight: bold;
    font-size: 14px;
}

.date-header {
    color: #0f172a;
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    padding: 12px 24px !important;
}

.program-row {
    background: #ffffff;
    transition: background 0.2s ease;
    border-left: 5px solid transparent;
}

.program-row.is-running-program {
    background: rgba(236, 253, 245, 0.85) !important;
    border-left: 5px solid #10b981 !important;
}

.program-row.is-break {
    background: rgba(254, 243, 199, 0.6) !important;
    border-left: 5px solid #f59e0b !important;
}

.section-badge {
    display: inline-block;
    background-color: #ffd700;
    color: #000;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 900;
    margin-left: 16px;
}

.marks {
    font-size: 22px;
    font-weight: bold;
}

.rank-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 18px;
    font-weight: 900;
    margin-right: 12px;
    text-align: center;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
}

.rank-badge.rank-1 {
    background-color: #22c55e !important;
    color: #fff !important;
}

.rank-badge.rank-2 {
    background-color: #eab308 !important;
    color: #000 !important;
}

.rank-badge.rank-3 {
    background-color: #3b82f6 !important;
    color: #fff !important;
}

.tv-schedule-row-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: color-mix(in srgb, var(--first-team-color, #6400a6) 18%, transparent);
    border: 1px solid color-mix(in srgb, var(--first-team-color, #6400a6) 45%, transparent);
    color: var(--first-team-color, #6400a6);
    font-size: 11px;
    font-weight: 900;
    padding: 3px 10px;
    border-radius: 20px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    flex-shrink: 0;
    margin-left: 12px;
}

.tv-schedule-row-live-badge .live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--first-team-color, #6400a6);
    box-shadow: 0 0 8px var(--first-team-color, #6400a6);
    animation: live-pulse-dot 1.4s ease-in-out infinite alternate;
}

@keyframes live-pulse-dot {
    0% { opacity: 0.4; transform: scale(0.9); }
    100% { opacity: 1; transform: scale(1.3); }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js" defer></script>

<div class="tv-schedule-stage-root" data-schedule-stage-root style="--first-team-color: <?= e($firstTeamColor) ?>; --top-team-color: <?= e($firstTeamColor) ?>; width: 100%; height: 100%;">
    <div class="tv-schedule" data-schedule style="width: 100%; height: 100%;"></div>
</div>

<script>
    // Replaced canvas teardown particlesJS with hardware-accelerated CSS ambient particles
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
