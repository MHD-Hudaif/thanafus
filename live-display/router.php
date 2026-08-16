<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

function tv_page_map(): array
{
    return [
        'intro' => 'intro.php',
        'leaderboard' => 'leaderboard.php',
        'schedule' => 'schedule.php',
        'current-program' => 'current-programs.php',
        'score-reveal' => 'score-reveal.php',
    ];
}

function tv_render_slide(string $key, array $settings): void
{
    $pages = tv_page_map();
    if (!isset($pages[$key])) {
        return;
    }

    $slide = $settings['slides'][$key] ?? ['title' => ucfirst($key)];
    
    // Do not render disabled slides in HTML
    if (isset($slide['enabled']) && !$slide['enabled']) {
        return;
    }

    $isIntro = $key === 'intro';
    $classes = 'tv-slide' . ($isIntro ? ' tv-slide--active' : '');
    ?>
    <section
        class="<?= e($classes) ?>"
        id="slide-<?= e($key) ?>"
        data-slide="<?= e($key) ?>"
        data-duration="<?= (int)($slide['duration'] ?? ($key === 'intro' ? 10000 : 5000)) ?>"
        aria-label="<?= e($slide['title'] ?? ucfirst($key)) ?>"
    >
        <?php require __DIR__ . '/pages/' . $pages[$key]; ?>
    </section>
    <?php
}
