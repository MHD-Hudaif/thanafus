<?php
declare(strict_types=1);

if (!defined('TV_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'schedule';
    $settings['slides']['schedule']['enabled'] = true;
    $settings['slides']['schedule']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-schedule" data-slide="schedule" style="opacity: 1; visibility: visible; transform: scale(1);">';
}
?>
<div class="tv-slide-head">
    <div>
        <div class="tv-kicker">Timeline</div>
        <h1>Schedule & Results</h1>
    </div>
    <div class="tv-pill"><span>Live</span></div>
</div>
<div class="tv-schedule" data-schedule style="flex: 1; display: flex; flex-direction: column; min-height: 0;"></div>
<?php
if (!defined('TV_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
