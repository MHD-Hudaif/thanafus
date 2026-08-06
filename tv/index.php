<?php
declare(strict_types=1);

require_once __DIR__ . '/router.php';

$event    = tv_active_event();
$settings = tv_get_settings((int)($event['id'] ?? 0));

// Find the first enabled slide in sort order
$slides = $settings['slides'] ?? [];
uasort($slides, static fn($a, $b) => ($a['sort_order'] ?? 99) <=> ($b['sort_order'] ?? 99));

$pageMap = tv_page_map();
$firstKey = null;
foreach ($slides as $key => $slide) {
    if (!empty($slide['enabled']) && isset($pageMap[$key])) {
        $firstKey = $key;
        break;
    }
}

// Default to intro if nothing is enabled
$firstKey = $firstKey ?? 'intro';

// Map slide key to its standalone URL
$urlMap = [
    'intro'           => app_url('/tv/intro.php'),
    'leaderboard'     => app_url('/tv/leaderboard.php'),
    'schedule'        => app_url('/tv/schedule.php'),
    'current-program' => app_url('/tv/current-program.php'),
];

$target = $urlMap[$firstKey] ?? app_url('/tv/intro.php');

header('Location: ' . $target);
exit;
