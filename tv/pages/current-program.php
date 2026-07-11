<?php
declare(strict_types=1);

if (!defined('TV_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'current-program';
    $settings['slides']['current-program']['enabled'] = true;
    $settings['slides']['current-program']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-current-program" data-slide="current-program" style="opacity: 1; visibility: visible; transform: scale(1);">';
}
?>
<div class="tv-now">
    <div class="tv-now-main">
        <div class="tv-now-brow">
            <div class="tv-kicker" data-current-stage>Main Stage</div>
            <span class="tv-stage-chip" data-current-status>Break</span>
        </div>
        <h1 data-current-title>Break Time</h1>
        <div class="tv-now-performer" data-current-performer>No active performer</div>
        <div class="tv-team-badge" data-current-team>Awaiting next program</div>

        <div class="tv-now-progress">
            <div class="tv-now-progress-head">
                <span>Entry Progress</span>
                <strong data-current-progress-label>Waiting for entries</strong>
            </div>
            <div class="tv-now-progress-track"><span data-current-progress-fill></span></div>
        </div>

        <div class="tv-now-meta-grid">
            <div>
                <span>Category</span>
                <strong data-current-category>All Classes</strong>
            </div>
            <div>
                <span>Entries</span>
                <strong data-current-entry-count>0</strong>
            </div>
            <div>
                <span>Room</span>
                <strong data-current-room>Main Hall</strong>
            </div>
        </div>
    </div>
    <div class="tv-now-side">
        <div class="tv-card">
            <div class="tv-card-label">Next Performer</div>
            <div class="tv-card-value" data-next-performer style="margin-bottom: 8px;">Queued automatically</div>
            <div class="tv-card-sub" data-next-team>Team details pending</div>
        </div>
        <div class="tv-card">
            <div class="tv-card-label">Judges</div>
            <div class="tv-card-value" data-judges style="font-size: 20px; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">Panel pending</div>
        </div>
        <div class="tv-card">
            <div class="tv-card-label">Next Program</div>
            <div class="tv-card-value" data-next-program style="font-size: 22px;">Schedule pending</div>
        </div>
    </div>
</div>
<?php
if (!defined('TV_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
