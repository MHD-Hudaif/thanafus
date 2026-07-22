<?php
declare(strict_types=1);

$event = $event ?? tv_active_event();
$settings = $settings ?? tv_get_settings((int)($event['id'] ?? 0));
$eventPayload = tv_event_payload($event);
$assetBase = tv_asset_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($eventPayload['title']) ?> | TV Broadcast</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400;1,700&family=Cairo:wght@500;600;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(tv_asset_url('css/tv.css')) ?>?v=<?= filemtime(app_path('tv/assets/css/tv.css')) ?>">
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/tsparticles@3.5.0/tsparticles.bundle.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.159.0/build/three.min.js" defer></script>
    <script src="<?= e(tv_asset_url('js/leaderboard-3d.js')) ?>?v=<?= filemtime(app_path('tv/assets/js/leaderboard-3d.js')) ?>" defer></script>
</head>
<body class="tv-body theme-<?= e($settings['theme']) ?> <?= e($tvBodyClass ?? '') ?>">
<div class="tv-app" id="tvApp">
    <div class="tv-backdrop" aria-hidden="true">
        <div id="particles-js" class="tv-particles"></div>
        <div class="tv-geometric"></div>
        <div class="tv-spotlight tv-spotlight-a"></div>
        <div class="tv-spotlight tv-spotlight-b"></div>
        <div class="tv-sweep"></div>
    </div>
    <header class="tv-topbar">
        <div class="tv-brand">
            <img src="<?= e(asset_url('images/thanafus-logo.png')) ?>" alt="Thanafus">
            <div class="tv-brand-copy">
                <div class="tv-brand-kicker">Kauzariyya Digital Musabaqa</div>
                <div class="tv-brand-title" data-event-title><?= e($eventPayload['title']) ?></div>
            </div>
        </div>
        <div class="tv-topbar-right">
            <div class="tv-live-chip"><span></span> Live</div>
            <div class="tv-clock" id="tvClock">--:--</div>
        </div>
    </header>

    <main class="tv-stage" id="tvStage">
