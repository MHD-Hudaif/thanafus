<?php
declare(strict_types=1);

$pages = tv_page_map();

// --- Standalone page-navigation slideshow (not TV_STAGE combined view) ---
if (!defined('TV_STAGE')):
    // Slide URL map
    $tvSlideUrls = [
        'intro'           => app_url('/tv/intro.php'),
        'leaderboard'     => app_url('/tv/leaderboard.php'),
        'schedule'        => app_url('/tv/schedule.php'),
        'current-program' => app_url('/tv/current-program.php'),
    ];

    // Build ordered list of enabled slides
    $tvSlides = $settings['slides'] ?? [];
    uasort($tvSlides, static fn($a, $b) => ($a['sort_order'] ?? 99) <=> ($b['sort_order'] ?? 99));

    $tvEnabledKeys = [];
    foreach ($tvSlides as $key => $slide) {
        if (!empty($slide['enabled']) && isset($pages[$key], $tvSlideUrls[$key])) {
            $tvEnabledKeys[] = $key;
        }
    }
    if (empty($tvEnabledKeys)) {
        $tvEnabledKeys = array_keys($tvSlideUrls);
    }

    // Current slide key comes from $settings['active_slide'] set by each page
    $tvCurrentKey  = $settings['active_slide'] ?? 'intro';
    $tvCurrentIdx  = array_search($tvCurrentKey, $tvEnabledKeys, true);
    $tvNextKey     = $tvEnabledKeys[($tvCurrentIdx === false ? 0 : ($tvCurrentIdx + 1)) % count($tvEnabledKeys)];
    $tvNextUrl     = $tvSlideUrls[$tvNextKey] ?? $tvSlideUrls['intro'];
    $tvDuration    = (int)($tvSlides[$tvCurrentKey]['duration'] ?? 12000);
    if ($tvDuration < 3000) $tvDuration = 3000;
endif;
?>
    </main>

</div>

<script>
window.TV_BOOT = {
    api: {
        bootstrap: <?= json_encode(app_url('/tv/api/settings.php'), JSON_UNESCAPED_SLASHES) ?>,
        leaderboard: <?= json_encode(app_url('/tv/api/leaderboard.php'), JSON_UNESCAPED_SLASHES) ?>,
        current: <?= json_encode(app_url('/tv/api/current-program.php'), JSON_UNESCAPED_SLASHES) ?>,
        schedule: <?= json_encode(app_url('/tv/api/schedule.php'), JSON_UNESCAPED_SLASHES) ?>,
        winners: <?= json_encode(app_url('/tv/api/winners.php'), JSON_UNESCAPED_SLASHES) ?>,
        announcements: <?= json_encode(app_url('/tv/api/announcements.php'), JSON_UNESCAPED_SLASHES) ?>
    },
    initial: <?= json_encode($tvBootstrapData ?? tv_bootstrap_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= e(tv_asset_url('js/tv.js')) ?>?v=<?= filemtime(app_path('tv/assets/js/tv.js')) ?>" defer></script>

<?php if (!defined('TV_STAGE')): ?>
<script>
(function () {
    'use strict';
    var duration = <?= (int)$tvDuration ?>;
    var nextUrl  = <?= json_encode($tvNextUrl, JSON_UNESCAPED_SLASHES) ?>;

    var app = document.getElementById('tvApp');
    var advanced = false;
    function advance() {
        if (advanced) return;
        advanced = true;
        // Fade out, then navigate
        if (app) {
            app.classList.add('tv-page-out');
            setTimeout(function () { window.location.href = nextUrl; }, 420);
        } else {
            window.location.href = nextUrl;
        }
    }

    // For the intro slide: also advance when the video ends, whichever is sooner
    var video = document.querySelector('[data-intro-video]');
    if (video) {
        video.addEventListener('ended', advance, { once: true });
        video.addEventListener('error', advance,  { once: true });
        video.play().catch(function () { /* autoplay blocked – fallback timer handles it */ });
    }

    setTimeout(advance, duration);
})();
</script>
<?php endif; ?>
</body>
</html>
