<?php
declare(strict_types=1);

$event = $event ?? tv_active_event();
$settings = $settings ?? tv_get_settings((int)($event['id'] ?? 0));
$eventPayload = tv_event_payload($event);
$assetBase = live_display_asset_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($eventPayload['title']) ?> | Live Display</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400;1,700&family=Cairo:wght@600;700;800;900&family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800;900&family=Space+Grotesk:wght@600;700&family=Noto+Naskh+Arabic:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(live_display_asset_url('css/live-display.css')) ?>?v=<?= filemtime(app_path('live-display/assets/css/live-display.css')) ?>">
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
    <?php if (!defined('LIVE_DISPLAY_STAGE')): ?>
        <script>window.IS_SINGLE_PAGE = true;</script>
    <?php endif; ?>
    <style>
        .tv-app { animation: tv-page-in .5s ease both; }
        @keyframes tv-page-in {
            from { opacity: 0; transform: scale(1.015); }
            to   { opacity: 1; transform: scale(1); }
        }
        .tv-page-out {
            animation: tv-page-out .4s ease forwards !important;
        }
        @keyframes tv-page-out {
            to { opacity: 0; transform: scale(0.985); }
        }
    </style>
</head>
    <script>
        window.TV_VIDEO_ASSETS = {
            yellow: "<?= e(asset_url('videos/bg-yellow.mp4')) ?>",
            blue: "<?= e(asset_url('videos/bg-blue.mp4')) ?>",
            green: "<?= e(asset_url('videos/bg-green.mp4')) ?>",
            purple: "<?= e(asset_url('videos/bg-purple.mp4')) ?>"
        };
    </script>
<body class="tv-body theme-<?= e($settings['theme']) ?> <?= e($tvBodyClass ?? '') ?>">
<div class="tv-app" id="tvApp">
    <div class="tv-backdrop" aria-hidden="true">
        <video id="tvBgVideo" autoplay loop muted playsinline src="<?= e(asset_url('videos/bg-yellow.mp4')) ?>" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1; transition: opacity 0.6s ease;"></video>
    </div>

    <div class="tv-orientation-hint" id="tvOrientationHint">
        <i class="fa-solid fa-mobile-screen-button"></i>
        <span>Landscape 16:9 Stage</span>
        <button type="button" class="tv-hint-fs-btn" id="tvHintFsBtn" onclick="toggleTvFullscreen()" aria-label="Full Screen">
            <i class="fa-solid fa-expand"></i> Full Screen
        </button>
    </div>

    <div class="tv-viewport-scaler" id="tvViewportScaler">
        <header class="tv-topbar">
            <div class="tv-brand">
                <img src="<?= e(asset_url('images/thanafus-logo.png')) ?>" alt="Thanafus">
                <div class="tv-brand-copy">
                    <div class="tv-brand-kicker">Kauzariyya Digital Musabaqa</div>
                    <div class="tv-brand-title" data-event-title><?= e($eventPayload['title']) ?></div>
                </div>
            </div>
            <div class="tv-topbar-right">
                <button type="button" class="tv-fullscreen-btn" id="tvFullscreenBtn" onclick="toggleTvFullscreen()" aria-label="Toggle Full Screen" title="Toggle Full Screen">
                    <i class="fa-solid fa-expand" id="tvFsIcon"></i>
                </button>
                <div class="tv-live-chip"><span></span> Live</div>
                <div class="tv-clock" id="tvClock">--:--</div>
            </div>
        </header>

        <main class="tv-stage" id="tvStage">

