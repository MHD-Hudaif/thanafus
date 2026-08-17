<?php
declare(strict_types=1);

$event = $event ?? tv_active_event();
$settings = $settings ?? tv_get_settings((int)($event['id'] ?? 0));
$eventPayload = tv_event_payload($event);
$assetBase = live_display_asset_url();

$headerLeaderboard = tv_leaderboard((int)($event['id'] ?? 0));
$headerFirstTeam = !empty($headerLeaderboard) ? $headerLeaderboard[0] : null;
$headerFirstColor = !empty($headerFirstTeam['team_color']) ? live_display_color($headerFirstTeam['team_color']) : '#00aaff';

if (!function_exists('tv_get_video_src')) {
    function tv_get_video_src(string $color): string {
        $c = strtolower(trim($color));
        if (str_contains($c, 'blue') || str_contains($c, 'cyan') || in_array($c, ['#00aaff', '#00a8ff', '#0088ff', '#2563eb', '#3b82f6', '#0284c7', '#60a5fa'], true)) {
            return asset_url('videos/bg-blue.mp4');
        }
        if (str_contains($c, 'green') || str_contains($c, 'emerald') || in_array($c, ['#00ff88', '#10b981', '#22c55e', '#059669', '#34d399'], true)) {
            return asset_url('videos/bg-green.mp4');
        }
        if (str_contains($c, 'purple') || str_contains($c, 'violet') || str_contains($c, 'pink') || str_contains($c, 'red') || str_contains($c, 'magenta') || in_array($c, ['#d000ff', '#ff2255', '#f43f5e', '#8b5cf6', '#a855f7', '#6400a6'], true)) {
            return asset_url('videos/bg-purple.mp4');
        }
        return asset_url('videos/bg-yellow.mp4');
    }
}
$initialBgVideoSrc = tv_get_video_src($headerFirstColor);
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
    <script>
        window.isLowEndDevice = 
            (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) ||
            (navigator.deviceMemory && navigator.deviceMemory < 4) ||
            /SmartTV|GoogleTV|AppleTV|HbbTV|Tizen|WebOS|Android 9|Android 8|Android 7|Android 6|Android 5/i.test(navigator.userAgent) ||
            window.location.search.includes('perf=low');
        if (window.isLowEndDevice) {
            document.documentElement.classList.add('low-perf-device');
        }
        <?php if (($settings['performance_mode'] ?? 'quality') === 'performance'): ?>
            document.documentElement.classList.add('performance-mode');
            document.documentElement.classList.add('low-perf-device');
        <?php endif; ?>
    </script>
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
        
        /* Low performance device and Performance Mode overrides */
        .low-perf-device #flowCanvas,
        .low-perf-device #tvBgVideo,
        .low-perf-device .glow-orb,
        .performance-mode #flowCanvas,
        .performance-mode #tvBgVideo,
        .performance-mode .glow-orb,
        .performance-mode .card-chevrons-svg,
        .performance-mode .constellation-lasers,
        .performance-mode .orbital-card::after,
        .performance-mode .orbital-card-dark-wave,
        .performance-mode .constellation-star-svg,
        .performance-mode .stage-backdrop {
            display: none !important;
        }
        .low-perf-device .tv-backdrop,
        .performance-mode .tv-backdrop {
            background: radial-gradient(circle at 50% 50%, #0c1220 0%, #05080f 100%) !important;
        }
        .low-perf-device *,
        .performance-mode * {
            text-shadow: none !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        /* Stop continuous rotations and pulsing keyframe animations */
        .performance-mode .star-rotation-group,
        .performance-mode .constellation-laser-line,
        .performance-mode .travel-border-rect,
        .performance-mode .stage-live-badge .live-dot,
        .performance-mode .live-dot,
        .performance-mode .animated-card-group,
        .performance-mode .live-pulse-dot,
        .performance-mode .animate-pulse,
        .performance-mode .pulse-dot-red {
            animation: none !important;
            animation-play-state: paused !important;
        }

        /* Simpler and faster transitions for low power rendering */
        .performance-mode .orbital-card,
        .performance-mode .active-stage-timer,
        .performance-mode .active-chest-hero,
        .performance-mode .orbital-extra-teams-bar,
        .performance-mode .glass-panel,
        .performance-mode .tv-slide,
        .performance-mode .program-row {
            transition-duration: 0.15s !important;
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
    <div class="tv-backdrop" aria-hidden="true" style="--first-team-color: <?= e($headerFirstColor) ?>;">
        <div class="stage-backdrop">
            <div class="glow-orb glow-orb-1" id="glowOrb1"></div>
            <div class="glow-orb glow-orb-2" id="glowOrb2"></div>
        </div>
        <canvas id="flowCanvas"></canvas>
        <video id="tvBgVideo" autoplay loop muted playsinline src="<?= e($initialBgVideoSrc) ?>" data-current-src="<?= e($initialBgVideoSrc) ?>" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 3; opacity: 0.12; transition: opacity 0.6s ease; mix-blend-mode: overlay; pointer-events: none;"></video>
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

            <!-- Global Live Stage Timer (Top Left) -->
            <div class="global-stage-timer" id="globalStageTimerBox" style="display: none; align-items: center; gap: 8px; background: rgba(239, 68, 68, 0.18); border: 1px solid rgba(239, 68, 68, 0.4); padding: 5px 12px; border-radius: 8px; font-weight: 700; color: #f87171; font-family: 'Space Grotesk', 'Cairo', sans-serif; font-size: 12.5px; margin-right: auto; margin-left: 20px; pointer-events: auto; box-shadow: 0 0 15px rgba(239, 68, 68, 0.15); transition: opacity 0.3s ease;">
                <i class="fa-solid fa-stopwatch animate-pulse" style="color: #ef4444;"></i>
                <span style="font-size: 9.5px; opacity: 0.85; letter-spacing: 0.05em; text-transform: uppercase;">STAGE TIMER</span>
                <span id="globalStageTimerDisplay" style="font-size: 14.5px; font-weight: 800; color: #fff; min-width: 44px; text-align: center;">00:00</span>
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

