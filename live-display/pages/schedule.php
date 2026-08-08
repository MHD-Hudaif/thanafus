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
body.tv-schedule-active .tv-topbar,
body:has(#slide-schedule.tv-slide--active) .tv-topbar {
    display: none !important;
}

#slide-schedule {
    padding: 0 !important;
    overflow: hidden;
    background: #030712;
    font-family: 'Inter', 'Cairo', system-ui, -apple-system, sans-serif;
    color: #f8fafc;
    width: 100vw;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
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
    max-width: 1600px;
    height: 100vh;
    padding: 50px 60px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 1;
}

/* Reliable Pure CSS Ambient Mesh Background */
.ambient-mesh-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background: 
        radial-gradient(circle at 18% 25%, color-mix(in srgb, var(--first-team-color) 22%, transparent) 0%, transparent 55%),
        radial-gradient(circle at 82% 75%, color-mix(in srgb, var(--first-team-color) 14%, transparent) 0%, transparent 48%),
        radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.05) 0%, transparent 70%),
        linear-gradient(180deg, #030712 0%, #02040a 100%);
    animation: ambient-pulse 12s ease-in-out infinite alternate;
}

@keyframes ambient-pulse {
    0% { opacity: 0.8; transform: scale(1); }
    100% { opacity: 1; transform: scale(1.04); }
}

/* Title Header style: Pure Minimalist */
.schedule-slide-title {
    font-size: 38px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid rgba(16, 185, 129, 0.2);
    padding-bottom: 18px;
    margin-bottom: 20px;
}

.page-count-badge {
    font-size: 20px;
    font-weight: 700;
    color: #10b981;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.25);
    padding: 6px 16px;
    border-radius: 8px;
    letter-spacing: 0.02em;
}

/* Schedule Board Glass container */
.tv-schedule-board {
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(30px);
    -webkit-backdrop-filter: blur(30px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 
        0 25px 50px -12px rgba(0, 0, 0, 0.7),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
    display: flex;
    flex-direction: column;
}

/* Table Header */
.tv-schedule-board-head {
    display: grid;
    grid-template-columns: 90px 180px minmax(0, 1fr) 240px 200px;
    align-items: center;
    height: 70px;
    background: rgba(16, 185, 129, 0.08);
    border-bottom: 2px solid rgba(16, 185, 129, 0.25);
    padding: 0 40px;
    box-sizing: border-box;
}

.tv-schedule-board-head span {
    font-size: 16px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #a7f3d0;
}

/* Table Body rows list */
.tv-schedule-page {
    display: flex;
    flex-direction: column;
}

.tv-schedule-row {
    display: grid;
    grid-template-columns: 90px 180px minmax(0, 1fr) 240px 200px;
    align-items: center;
    min-height: 110px;
    padding: 10px 40px;
    box-sizing: border-box;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    background: transparent;
    transition: background 0.3s ease, border-left-color 0.3s ease;
    border-left: 4px solid transparent;
}

.tv-schedule-row.is-live {
    background: rgba(16, 185, 129, 0.08) !important;
    border-left: 6px solid #10b981 !important;
    box-shadow: inset 0 0 24px rgba(16, 185, 129, 0.08);
}

.tv-schedule-row-num {
    font-size: 24px;
    font-weight: 800;
    color: #94a3b8;
}

.tv-schedule-row.is-live .tv-schedule-row-num {
    color: #10b981;
}

.tv-schedule-row-time {
    font-size: 32px;
    font-weight: 800;
    color: #ffffff;
    font-variant-numeric: tabular-nums;
}

.tv-schedule-row-program {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}

.tv-schedule-row-program strong {
    font-size: 34px;
    font-weight: 900;
    color: #ffffff;
    text-transform: uppercase;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tv-schedule-row-program span {
    font-size: 16px;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    margin-top: 6px;
}

.tv-schedule-row-category {
    font-size: 22px;
    font-weight: 800;
    color: #e2e8f0;
    text-transform: uppercase;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tv-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 18px;
    border-radius: 30px;
    font-size: 16px;
    font-weight: 800;
    text-transform: uppercase;
    text-align: center;
}

.tv-status.completed {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #94a3b8;
}

.tv-status.inprogress {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.4);
    color: #10b981;
}

.tv-status.upcoming {
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.25);
    color: #60a5fa;
}

.aura-watermark {
    position: absolute;
    bottom: 18px;
    right: 60px;
    font-size: 13px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.25);
    text-transform: uppercase;
    letter-spacing: 0.15em;
    pointer-events: none;
    z-index: 10;
}
</style>

<div class="ambient-mesh-bg"></div>

<div class="tv-schedule" data-schedule></div>

<div class="aura-watermark">
    Leading Team: <?= e($firstTeamName) ?>
</div>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
