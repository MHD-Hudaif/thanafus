<?php
declare(strict_types=1);

$pages = tv_page_map();
$enabledSlides = array_values(array_filter(
    (array)$settings['slides'],
    static fn (array $slide): bool => !empty($slide['enabled']) && isset($pages[$slide['key']])
));
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
</body>
</html>
