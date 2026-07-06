<?php
declare(strict_types=1);

if (!defined('TV_STAGE')) {
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

$introVideo = is_file(__DIR__ . '/../assets/videos/intro.mp4')
    ? tv_asset_url('videos/intro.mp4')
    : tv_asset_url('video/intro.mp4');
?>
<video class="tv-intro-video" autoplay muted playsinline preload="auto" data-intro-video>
    <source src="<?= e($introVideo) ?>" type="video/mp4">
</video>
<div class="tv-intro-overlay">
    <img src="<?= e(tv_asset_url('kauzariyya-logo.png')) ?>" alt="Kauzariyya" class="tv-intro-mark">
    <div class="tv-intro-copy">
        <div class="tv-intro-eyebrow">Welcome to the arena</div>
        <h1 class="tv-intro-title" data-intro-title>Kauzariyya Musabaqa</h1>
        <p class="tv-intro-subtitle" data-intro-subtitle>Live competition broadcast</p>
    </div>
</div>
<?php
if (!defined('TV_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
