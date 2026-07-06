<?php
declare(strict_types=1);

if (!defined('TV_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'leaderboard';
    $settings['slides']['leaderboard']['enabled'] = true;
    $settings['slides']['leaderboard']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-leaderboard" data-slide="leaderboard" style="opacity: 1; visibility: visible; transform: scale(1);">';
}
?>
<!-- Ambient Background Effects -->
<div class="tv-dynamic-bg" id="tvDynamicBg"></div>
<div class="tv-light-beams">
    <div class="tv-light-beam tv-light-beam-1"></div>
    <div class="tv-light-beam tv-light-beam-2"></div>
</div>
<div class="tv-islamic-pattern">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="0.8">
        <circle cx="100" cy="100" r="95" stroke-dasharray="1 3"/>
        <circle cx="100" cy="100" r="88" stroke-dasharray="3 5"/>
        <rect x="35" y="35" width="130" height="130" rx="3"/>
        <rect x="35" y="35" width="130" height="130" rx="3" transform="rotate(45 100 100)"/>
        <rect x="35" y="35" width="130" height="130" rx="3" transform="rotate(22.5 100 100)"/>
        <rect x="35" y="35" width="130" height="130" rx="3" transform="rotate(67.5 100 100)"/>
        <circle cx="100" cy="100" r="45" stroke-dasharray="2 2"/>
        <polygon points="100,5 125,55 195,100 125,145 100,195 75,145 5,100 75,55" />
    </svg>
</div>

<div class="tv-slide-head" style="text-align: center; margin-bottom: 24px; display: block; position: relative; z-index: 2;">
    <div class="tv-kicker" style="font-size: 14px; letter-spacing: 0.25em;">LIVE SCORES - <span data-leaderboard-count>4</span> TEAMS COMPETING</div>
    <h1 style="font-size: 38px; font-weight: 800; letter-spacing: -0.02em; margin: 4px 0 0;">ONGOING PROGRAM LEADERBOARD</h1>
</div>

<!-- Team Legend (Horizontal dot legend) -->
<div class="tv-chart-legend" id="chart-legend"></div>

<div class="tv-chart-container" data-leaderboard>
    <!-- Background gridlines behind bars -->
    <div class="tv-chart-gridlines" id="chart-gridlines"></div>
    
    <!-- Bars Stack -->
    <div class="tv-chart-bars" id="chart-bars-list"></div>
    
    <!-- Bottom Scale Axis -->
    <div class="tv-chart-axis" id="chart-axis"></div>
</div>
<?php
if (!defined('TV_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
