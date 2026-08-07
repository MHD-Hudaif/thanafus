<?php
declare(strict_types=1);
?>
    </main>

</div>

<script>
window.TV_BOOT = {
    api: {
        bootstrap:     <?= json_encode(app_url('/live-display/api/settings.php'),        JSON_UNESCAPED_SLASHES) ?>,
        leaderboard:   <?= json_encode(app_url('/live-display/api/leaderboard.php'),      JSON_UNESCAPED_SLASHES) ?>,
        current:       <?= json_encode(app_url('/live-display/api/current-program.php'),  JSON_UNESCAPED_SLASHES) ?>,
        schedule:      <?= json_encode(app_url('/live-display/api/schedule.php'),         JSON_UNESCAPED_SLASHES) ?>,
        winners:       <?= json_encode(app_url('/live-display/api/winners.php'),          JSON_UNESCAPED_SLASHES) ?>,
        announcements: <?= json_encode(app_url('/live-display/api/announcements.php'),    JSON_UNESCAPED_SLASHES) ?>
    },
    initial: <?= json_encode($tvBootstrapData ?? tv_bootstrap_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= e(live_display_asset_url('js/live-display.js')) ?>?v=<?= filemtime(app_path('live-display/assets/js/live-display.js')) ?>" defer></script>
</body>
</html>

