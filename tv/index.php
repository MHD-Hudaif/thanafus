<?php
declare(strict_types=1);

define('TV_STAGE', true);

require_once __DIR__ . '/router.php';

$event = tv_active_event();
$settings = tv_get_settings((int)($event['id'] ?? 0));

require __DIR__ . '/includes/header.php';

foreach (array_keys(tv_page_map()) as $slideKey) {
    tv_render_slide($slideKey, $settings);
}

require __DIR__ . '/includes/footer.php';
