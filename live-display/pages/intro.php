<?php
declare(strict_types=1);

if (!defined('LIVE_DISPLAY_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'intro';
    $settings['slides']['intro']['enabled'] = true;
    $settings['slides']['intro']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-intro" data-slide="intro" style="opacity: 1; visibility: visible; transform: scale(1);">';
}
$videoPath = app_path('assets/videos/Intro.mp4');
$version = file_exists($videoPath) ? filemtime($videoPath) : time();
$introVideoUrl = asset_url('videos/Intro.mp4') . '?v=' . $version;
?>
<style>
body:has(#slide-intro.tv-slide--active) .tv-topbar,
body.tv-intro-only .tv-topbar {
    display: none !important;
}

body:has(#slide-intro.tv-slide--active) .tv-backdrop,
body.tv-intro-only .tv-backdrop {
    display: none !important;
}

body:has(#slide-intro.tv-slide--active) #tvViewportScaler,
body.tv-intro-only #tvViewportScaler {
    width: 100vw !important;
    height: 100vh !important;
    transform: none !important;
    position: fixed !important;
    inset: 0 !important;
    max-width: 100vw !important;
    max-height: 100vh !important;
    margin: 0 !important;
}

#slide-intro {
    display: none !important;
    opacity: 0 !important;
    visibility: hidden !important;
    z-index: -1 !important;
    pointer-events: none !important;
}

#slide-intro.tv-slide--active {
    display: flex !important;
    opacity: 1 !important;
    visibility: visible !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden;
    position: fixed !important;
    inset: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: #000000;
    align-items: center;
    justify-content: center;
    z-index: 99999 !important;
    pointer-events: auto !important;
}

#slide-intro.tv-slide--exiting {
    display: flex !important;
    opacity: 0 !important;
    visibility: hidden !important;
    transition: opacity 0.4s ease, visibility 0.4s ease !important;
    z-index: 99999 !important;
}

.tv-intro-video-element {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 108%;
    object-fit: cover;
    z-index: 1;
    transform: translateY(-8%); /* Crops the bottom 8% of the video where the date is shown */
}
</style>

<video class="tv-intro-video-element" id="introVideoPlayer" autoplay loop muted playsinline preload="auto" data-intro-video src="<?= e($introVideoUrl) ?>"></video>

<script>
window.TV_INTRO_VIDEOS = [<?= json_encode($introVideoUrl, JSON_UNESCAPED_SLASHES) ?>];
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
