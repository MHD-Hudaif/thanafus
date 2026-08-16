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
    $settings['slides']['schedule']['duration'] = 7000;
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
    max-width: 1600px;
    height: 100%;
    padding: 32px 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 2;
}

/* Title Header: Premium Minimalist Broadcast style */
.schedule-slide-title {
    font-size: 38px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.015em;
    color: #fff !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 18px;
    margin-bottom: 24px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    border-bottom: 2px solid rgba(255, 255, 255, 0.12);
}

.page-count-badge {
    font-size: 16px;
    font-weight: 800;
    color: #fff !important;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    padding: 6px 20px;
    border-radius: 30px;
    letter-spacing: 0.05em;
}

/* Timeline table container with curved corners */
.schedule-timeline-container {
    display: flex;
    flex-direction: column;
    gap: 0 !important;
    width: 100%;
    background: linear-gradient(135deg, rgba(10, 15, 30, 0.95) 0%, rgba(5, 8, 15, 0.9) 100%) !important;
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border-radius: 24px !important;
    border: 2px solid rgba(255, 255, 255, 0.1) !important;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7) !important;
    overflow: hidden !important;
    box-sizing: border-box;
}

/* 3-Column Table Row Layout (Program left, Ranks center, Time right) */
.schedule-card {
    position: relative;
    display: grid;
    grid-template-columns: 1.2fr 0.8fr 310px;
    align-items: center;
    background: transparent !important;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    border-bottom: 1.5px solid rgba(255, 255, 255, 0.08) !important;
    padding: 18px 38px;
    box-sizing: border-box;
    overflow: hidden;
    transition: all 0.25s ease;
}

.schedule-card:last-child {
    border-bottom: none !important;
}

.schedule-card-accent {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 6px;
    background: transparent;
    transition: all 0.3s ease;
}

/* Inline index indicator */
.schedule-index-inline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    font-size: 16px;
    font-weight: 900;
    font-family: 'Outfit', sans-serif;
    flex-shrink: 0;
}
.schedule-index-inline.num-blue { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1.5px solid rgba(59, 130, 246, 0.3); }
.schedule-index-inline.num-green { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1.5px solid rgba(16, 185, 129, 0.3); }
.schedule-index-inline.num-gray { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1.5px solid rgba(148, 163, 184, 0.2); }
.schedule-index-inline.num-amber { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1.5px solid rgba(245, 158, 11, 0.3); }

/* Left Column: Program details */
.schedule-card-program-col {
    display: flex;
    align-items: center;
    gap: 24px;
    overflow: hidden;
}
.schedule-program-details-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    overflow: hidden;
}
.schedule-program-title {
    font-size: 24px !important;
    font-weight: 900;
    color: #fff;
    margin: 0;
    letter-spacing: -0.012em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.program-sec-tag {
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    padding: 4px 14px;
    font-size: 12px !important;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    width: fit-content;
    flex-shrink: 0;
}

/* Center Column: Ranks / Results */
.schedule-card-ranks-col {
    display: flex;
    align-items: center;
    justify-content: center;
}
.schedule-ranks-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    align-items: center;
}
.rank-pill {
    display: inline-flex;
    align-items: center;
    padding: 7px 18px;
    border-radius: 30px;
    font-size: 16px !important;
    font-weight: 900;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    background: color-mix(in srgb, var(--badge-team-color, #71717a) 15%, #0f172a);
    color: #fff;
    border: 1.5px solid var(--badge-team-color, #71717a);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
    white-space: nowrap;
}
.rank-pill.rank-1 {
    border-color: #fbbf24;
    background: rgba(251, 191, 36, 0.18);
    box-shadow: 0 0 15px rgba(251, 191, 36, 0.3);
    color: #fef08a;
}
.rank-pill.rank-2 {
    border-color: #cbd5e1;
    background: rgba(203, 213, 225, 0.15);
    box-shadow: 0 0 12px rgba(203, 213, 225, 0.2);
    color: #f1f5f9;
}
.rank-pill.rank-3 {
    border-color: #b45309;
    background: rgba(180, 83, 9, 0.15);
    box-shadow: 0 0 12px rgba(180, 83, 9, 0.2);
    color: #ffedd5;
}
.rank-pill.grade-a {
    border-color: #34d399;
    background: rgba(52, 211, 153, 0.15);
    box-shadow: 0 0 12px rgba(52, 211, 153, 0.2);
    color: #d1fae5;
}
.no-results-placeholder {
    color: rgba(255, 255, 255, 0.15);
    font-size: 24px;
    font-weight: 700;
    letter-spacing: 0.05em;
}

/* Right Column: Time slot */
.schedule-card-time-col {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    gap: 6px;
}
.schedule-time-label {
    font-size: 32px !important;
    font-weight: 900;
    color: #ffffff;
    font-family: 'Plus Jakarta Sans', monospace;
    letter-spacing: -0.02em;
    text-shadow: 0 0 15px rgba(255, 255, 255, 0.15);
    white-space: nowrap !important;
}
.program-day-tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 800;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.7);
    padding: 3px 10px;
    border-radius: 5px;
    letter-spacing: 0.05em;
    width: fit-content;
}

/* Highlighting spotlight features */
.schedule-card.is-running-program {
    background: rgba(16, 185, 129, 0.15) !important;
    box-shadow: inset 0 0 25px rgba(16, 185, 129, 0.12) !important;
}
.schedule-card.is-running-program .schedule-card-accent {
    background: var(--first-team-color, #10b981) !important;
}

.schedule-card.is-break {
    background: rgba(245, 158, 11, 0.1) !important;
}
.schedule-card.is-break .schedule-card-accent {
    background: #f59e0b !important;
}

/* ──────────────── Responsive Overhaul ──────────────── */
@media (max-width: 1024px) {
    .schedule-slide-container {
        padding: 24px 16px !important;
        justify-content: flex-start !important;
        overflow-y: auto !important;
    }
    
    #slide-schedule {
        overflow-y: auto !important;
    }
    
    .schedule-slide-title {
        font-size: 28px !important;
        margin-bottom: 18px !important;
        padding-bottom: 12px !important;
    }

    .schedule-timeline-container {
        gap: 14px !important;
    }

    .schedule-card {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
        padding: 18px 24px !important;
        border-radius: 16px !important;
    }

    .schedule-card-accent {
        width: 100% !important;
        height: 4px !important;
        bottom: auto !important;
    }

    .schedule-card-program-col {
        width: 100% !important;
        gap: 12px !important;
    }

    .schedule-program-title {
        font-size: 20px !important;
        white-space: normal !important;
        text-align: left !important;
    }

    .schedule-card-ranks-col {
        width: 100% !important;
        justify-content: flex-start !important;
        margin-top: 2px !important;
    }

    .schedule-ranks-row {
        justify-content: flex-start !important;
    }

    .rank-pill {
        font-size: 13px !important;
        padding: 4px 12px !important;
    }

    .schedule-card-time-col {
        width: 100% !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: space-between !important;
        border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
        padding-top: 10px !important;
        margin-top: 4px !important;
    }

    .schedule-time-label {
        font-size: 18px !important;
    }
}

@media (max-height: 750px) {
    .schedule-slide-container {
        padding: 16px 32px !important;
    }
    .schedule-slide-title {
        font-size: 28px !important;
        margin-bottom: 12px !important;
        padding-bottom: 10px !important;
    }
    .schedule-timeline-container {
        gap: 8px !important;
    }
    .schedule-card {
        padding: 8px 20px !important;
        border-radius: 12px !important;
    }
    .schedule-program-title {
        font-size: 17px !important;
    }
    .schedule-time-label {
        font-size: 17px !important;
    }
    .rank-pill {
        font-size: 12px !important;
        padding: 4px 12px !important;
    }
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
