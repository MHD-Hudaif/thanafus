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

$introVideoUrl = asset_url('videos/all clips-21.mp4');
?>
<style>
body:has(#slide-intro.tv-slide--active) .tv-topbar,
body.tv-intro-only .tv-topbar {
    display: none !important;
}

#slide-intro {
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden;
    position: absolute !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: #000000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.tv-intro-video-element {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    z-index: 1;
}

/* Subtle Vignette Mask for Contrast */
.tv-intro-vignette {
    position: absolute;
    inset: 0;
    z-index: 2;
    pointer-events: none;
    background: radial-gradient(circle at center, transparent 35%, rgba(0, 0, 0, 0.72) 100%);
}

.tv-intro-logo-stage {
    position: relative;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    pointer-events: none;
}

/* Vector Emblem Frame */
.tv-emblem-frame {
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 440px;
    height: 300px;
    max-width: 42vw;
    max-height: 40vh;
    opacity: 0;
    transform: scale(0.9);
    filter: drop-shadow(0 25px 60px rgba(0, 0, 0, 0.85));
    will-change: opacity, transform;
}

.tv-emblem-svg-ring {
    position: absolute;
    inset: -20px;
    width: calc(100% + 40px);
    height: calc(100% + 40px);
    pointer-events: none;
}

.tv-emblem-img {
    width: 78%;
    height: 78%;
    object-fit: contain;
    position: relative;
    z-index: 2;
    filter: drop-shadow(0 12px 28px rgba(0, 0, 0, 0.65));
}

/* Metallic Light Sheen Overlay */
.tv-emblem-sheen {
    position: absolute;
    inset: 0;
    border-radius: 28px;
    background: linear-gradient(115deg, transparent 20%, rgba(255, 215, 0, 0.35) 48%, rgba(255, 255, 255, 0.65) 50%, rgba(255, 215, 0, 0.35) 52%, transparent 80%);
    mix-blend-mode: overlay;
    pointer-events: none;
    z-index: 3;
    transform: translateX(-130%) skewX(-20deg);
}

/* Keyframe Animations */
@keyframes emblem-thanafus-sequence {
    0% { opacity: 0; transform: scale(0.88); }
    15%, 42% { opacity: 1; transform: scale(1); }
    56%, 100% { opacity: 0; transform: scale(1.06); }
}

@keyframes emblem-kauzariyya-sequence {
    0%, 52% { opacity: 0; transform: scale(0.88); }
    66%, 94% { opacity: 1; transform: scale(1); }
    100% { opacity: 1; transform: scale(1); }
}

@keyframes sheen-sweep {
    0%, 20% { transform: translateX(-130%) skewX(-20deg); }
    50%, 100% { transform: translateX(130%) skewX(-20deg); }
}

@keyframes ring-pulse {
    0%, 100% { transform: scale(1); opacity: 0.75; }
    50% { transform: scale(1.025); opacity: 1; }
}

#slide-intro.tv-slide--active #emblemThanafus,
.tv-intro-only #emblemThanafus {
    animation: emblem-thanafus-sequence 7.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

#slide-intro.tv-slide--active #emblemKauzariyya,
.tv-intro-only #emblemKauzariyya {
    animation: emblem-kauzariyya-sequence 7.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

#slide-intro.tv-slide--active .tv-emblem-sheen {
    animation: sheen-sweep 3.5s cubic-bezier(0.16, 1, 0.3, 1) infinite;
}

#slide-intro.tv-slide--active .tv-emblem-svg-ring {
    animation: ring-pulse 4s ease-in-out infinite alternate;
}
</style>

<video class="tv-intro-video-element" id="introVideoPlayer" autoplay loop muted playsinline preload="auto" data-intro-video src="<?= e($introVideoUrl) ?>"></video>

<div class="tv-intro-vignette"></div>

<div class="tv-intro-logo-stage">
    <!-- Thanafus Vector Emblem Frame -->
    <div class="tv-emblem-frame" id="emblemThanafus">
        <svg class="tv-emblem-svg-ring" viewBox="0 0 480 340" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="15" y="15" width="450" height="310" rx="28" fill="rgba(15, 23, 42, 0.45)" stroke="url(#goldGrad1)" stroke-width="2.5" />
            <rect x="25" y="25" width="430" height="290" rx="20" fill="none" stroke="rgba(255, 215, 0, 0.25)" stroke-width="1.2" stroke-dasharray="8 6" />
            <circle cx="25" cy="25" r="5" fill="#ffd700" />
            <circle cx="455" cy="25" r="5" fill="#ffd700" />
            <circle cx="25" cy="315" r="5" fill="#ffd700" />
            <circle cx="455" cy="315" r="5" fill="#ffd700" />
            <defs>
                <linearGradient id="goldGrad1" x1="0" y1="0" x2="480" y2="340" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="#ffd700" />
                    <stop offset="50%" stop-color="#c8b26b" />
                    <stop offset="100%" stop-color="#ffd700" />
                </linearGradient>
            </defs>
        </svg>
        <div class="tv-emblem-sheen"></div>
        <img src="<?= e(live_display_asset_url('thanafus-logo.png')) ?>" alt="Thanafus Logo" class="tv-emblem-img">
    </div>

    <!-- Kauzariyya Vector Emblem Frame -->
    <div class="tv-emblem-frame" id="emblemKauzariyya">
        <svg class="tv-emblem-svg-ring" viewBox="0 0 480 340" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="15" y="15" width="450" height="310" rx="28" fill="rgba(15, 23, 42, 0.45)" stroke="url(#goldGrad2)" stroke-width="2.5" />
            <rect x="25" y="25" width="430" height="290" rx="20" fill="none" stroke="rgba(255, 215, 0, 0.25)" stroke-width="1.2" stroke-dasharray="8 6" />
            <circle cx="25" cy="25" r="5" fill="#ffd700" />
            <circle cx="455" cy="25" r="5" fill="#ffd700" />
            <circle cx="25" cy="315" r="5" fill="#ffd700" />
            <circle cx="455" cy="315" r="5" fill="#ffd700" />
            <defs>
                <linearGradient id="goldGrad2" x1="0" y1="0" x2="480" y2="340" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="#10b981" />
                    <stop offset="50%" stop-color="#ffd700" />
                    <stop offset="100%" stop-color="#10b981" />
                </linearGradient>
            </defs>
        </svg>
        <div class="tv-emblem-sheen"></div>
        <img src="<?= e(live_display_asset_url('kauzariyya-logo.png')) ?>" alt="Kauzariyya Logo" class="tv-emblem-img">
    </div>
</div>

<script>
window.TV_INTRO_VIDEOS = [<?= json_encode($introVideoUrl, JSON_UNESCAPED_SLASHES) ?>];
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
