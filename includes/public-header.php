<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/public-data.php';

$user = $_SESSION['user'] ?? null;
$isLoggedIn = !empty($user);

$page = $page ?? 'home';
$title = $title ?? 'Al Jamiathul Kauzariyya | Musabaqa Platform';
$nav = [
    'home' => 'Home',
    'scoreboard' => 'Scores',
    'schedule' => 'Schedule',
    'participants' => 'Participants',
    'review' => 'Review'
];

$event = tv_active_event();
$dateLabel = $event ? date('d F Y', strtotime($event['start_date'] ?? '2026-07-12')) : '12 July 2026';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    window.APP_BASE_URL = <?= json_encode(app_url('/')) ?>;
  </script>
  <meta name="theme-color" content="#07100c">
  <meta name="description" content="The official Kauzariyya Musabaqa companion for live scores, schedules, participants and festival results.">
  <title><?= e($title) ?></title>
  <link rel="icon" type="image/png" sizes="192x192" href="<?= asset_url('favicon.png') ?>?v=20260712-2">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@600;700;800&family=Inter:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@500;600;700&family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <link rel="stylesheet" href="<?= asset_url('css/site.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/site.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/modern.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/modern.css') ?>">
  <?php if ($page === 'home'): ?>
    <link rel="stylesheet" href="<?= asset_url('css/home.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/home.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/home-responsive.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/home-responsive.css') ?>">
  <?php endif; ?>
  <script src="<?= asset_url('js/scroll.js') ?>"></script>
  <?php if (function_exists('render_clarity_script')) render_clarity_script(); ?>
</head>
<body class="page-<?= e($page) ?> <?= $page === 'home' ? 'page-home' : '' ?>">
<header class="site-header">
  <a class="site-logo" href="<?= app_url('/') ?>" aria-label="Kauzariyya home">
    <img src="<?= asset_url('kauzariyya-brand-icon.png') ?>" alt="Kauzariyya">
    <span><b>Al Jamiathul Kauzariyya</b><small><?= $page === 'home' ? 'Thanafus &middot; Musabaqa 2026' : 'Festival Management Platform' ?></small></span>
  </a>
  
  <nav class="site-nav" aria-label="Main navigation">
    <?php foreach ($nav as $key => $label): ?>
      <?php $href = app_url('/' . ($key === 'home' ? '' : $key)); ?>
      <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <div class="home-mobile-actions">
      <a href="<?= app_url('/tv') ?>"><i class="fa-solid fa-display"></i> Live Display</a>
      <?php if ($isLoggedIn): ?>
        <a href="<?= is_admin() ? app_url('/admin/index.php') : app_url('/auth/logout') ?>"><i class="fa-solid <?= is_admin() ? 'fa-table-columns' : 'fa-right-from-bracket' ?>"></i> <?= is_admin() ? 'Dashboard' : 'Logout' ?></a>
      <?php else: ?>
        <a href="<?= app_url('/auth/login') ?>"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
      <?php endif; ?>
    </div>
  </nav>

  <!-- Desktop header actions -->
  <div class="header-actions">
      <a href="<?= app_url('/tv') ?>" class="button button-ghost" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-display"></i> Live Display</a>
      <?php if ($isLoggedIn): ?>
          <div class="user-avatar-badge" style="display: flex; align-items: center; gap: 8px;">
              <img src="<?=
                  !empty($user['profile_photo'])
                      ? avatar_url($user['profile_photo'])
                      : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username']) . '&background=0d1420&color=14b8a6&bold=true'
              ?>" alt="Profile" style="width: 32px; height: 32px; border-radius: 50%; border: 2px solid #14b8a6; object-fit: cover;">
              <span style="font-size: 14px; font-weight: 600; color: #ffffff;"><?= e($user['username']) ?></span>
          </div>
          <?php if (is_admin()): ?>
              <a href="<?= app_url('/admin/index.php') ?>" class="button button-light" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
          <?php else: ?>
              <a href="<?= app_url('/auth/logout') ?>" class="button button-light" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
          <?php endif; ?>
      <?php else: ?>
          <a href="<?= app_url('/auth/login') ?>" class="button button-light" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
      <?php endif; ?>
  </div>

  <!-- Mobile Toggle Button -->
  <button class="menu-toggle" type="button" aria-expanded="false" aria-label="Toggle navigation">
    <span></span>
    <span></span>
    <span></span>
  </button>
</header>
<main>
<?php if ($page === 'home'): ?>
<div class="home-video-bg" aria-hidden="true">
  <video autoplay muted loop playsinline preload="metadata" data-background-video data-src="<?= asset_url('video.mp4') ?>" style="--video-brightness:0.35;"></video>
</div>
<?php endif; ?>
