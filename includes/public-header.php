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
  <meta name="theme-color" content="#07100c">
  <meta name="description" content="The official Kauzariyya Musabaqa companion for live scores, schedules, participants and festival results.">
  <title><?= e($title) ?></title>
  <link rel="icon" type="image/png" sizes="192x192" href="<?= asset_url('favicon.png') ?>?v=20260712-2">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Dosis:wght@300;400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <link rel="stylesheet" href="<?= asset_url('css/site.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/site.css') ?>">
  <?php if ($page === 'home'): ?>
    <link rel="stylesheet" href="<?= asset_url('css/home.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/home.css') ?>">
  <?php endif; ?>
  <style>
  /* Shared responsive navigation behavior. */
  .mobile-only-action {
    display: block !important;
    border-top: 1px solid var(--line);
    margin-top: 4px;
  }
  .header-actions {
    display: none !important;
  }
  
  /* Smooth mobile navigation slide-down transition */
  .site-nav {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    display: grid !important;
    grid-template-columns: 1fr;
    max-height: min(70svh, 560px);
    overflow-y: auto;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-12px);
    transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.3s ease;
    pointer-events: none;
  }
  .site-nav.open {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: auto;
  }

  @media(max-width: 1099px) {
    .site-header {
      grid-template-columns: minmax(0, 1fr) auto !important;
    }
    .menu-toggle {
      display: grid !important;
    }
  }

  @media(min-width: 1100px) {
    .mobile-only-action {
      display: none !important;
    }
    .header-actions {
      display: flex !important;
      gap: 10px;
      align-items: center;
      justify-self: end;
    }
    .site-nav {
      position: static !important;
      display: flex !important;
      opacity: 1 !important;
      visibility: visible !important;
      transform: none !important;
      pointer-events: auto !important;
      transition: none !important;
    }
    .menu-toggle {
      display: none !important;
    }
  }

  /* Smooth fade transition for mobile schedule tab changes */
  @media(max-width: 759px) {
    .schedule-column {
      transition: opacity 0.4s ease, transform 0.4s ease;
    }
    .schedule-column:not(.mobile-active) {
      display: none !important;
    }
    .schedule-column.mobile-active {
      display: block !important;
      animation: tabFadeIn 0.35s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }
  }
  @keyframes tabFadeIn {
    from {
      opacity: 0;
      transform: translateY(12px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  /* Smooth pulse/scale transition on speaker card updates */
  @keyframes cardUpdate {
    0% {
      opacity: 0.6;
      transform: scale(0.97) translateY(2px);
    }
    100% {
      opacity: 1;
      transform: scale(1) translateY(0);
    }
  }
  .speaker-card.updated {
    animation: cardUpdate 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
  }

  /* Smooth participant item hover and click shifts */
  .participant-row {
    transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
  }
  .participant-row:hover {
    background: rgba(255, 255, 255, 0.05);
  }
  .participant-row.active {
    transform: translateX(4px);
  }
  </style>
</head>
<body class="page-<?= e($page) ?>">
<header class="site-header">
  <a class="site-logo" href="index.php" aria-label="Kauzariyya home">
    <img src="<?= asset_url('kauzariyya-brand-icon.png') ?>" alt="Kauzariyya">
    <span><b>Al Jamiathul Kauzariyya</b><small><?= $page === 'home' ? 'Thanafus &middot; Musabaqa 2026' : 'Festival Management Platform' ?></small></span>
  </a>
  
  <nav class="site-nav" aria-label="Main navigation">
    <?php foreach ($nav as $key => $label): ?>
      <?php $href = $key === 'home' ? 'index.php' : $key.'.php'; ?>
      <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    
    <!-- Mobile only links -->
    <a class="mobile-only-action" href="<?= app_url('/tv') ?>"><i class="fa-solid fa-tv"></i> TV Mode</a>
    <?php if ($isLoggedIn): ?>
        <div class="mobile-only-action" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--line);">
            <img src="<?=
                !empty($user['profile_photo'])
                    ? avatar_url($user['profile_photo'])
                    : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username']) . '&background=0d1420&color=14b8a6&bold=true'
            ?>" alt="Profile" style="width: 28px; height: 28px; border-radius: 50%; border: 2px solid #14b8a6; object-fit: cover;">
            <span style="font-size: 14px; font-weight: 600; color: #ffffff;"><?= e($user['username']) ?></span>
        </div>
        <?php if (is_admin()): ?>
            <a class="mobile-only-action" href="<?= app_url('/admin/dashboard') ?>"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <?php else: ?>
            <a class="mobile-only-action" href="<?= app_url('/auth/logout') ?>"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        <?php endif; ?>
    <?php else: ?>
        <a class="mobile-only-action" href="<?= app_url('/auth/login') ?>"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    <?php endif; ?>
  </nav>

  <!-- Mobile Toggle Button -->
  <button class="menu-toggle" type="button" aria-expanded="false" aria-label="Toggle navigation">
    <span></span>
    <span></span>
    <span></span>
  </button>

  <!-- Desktop header actions -->
  <div class="header-actions">
      <a href="<?= app_url('/tv') ?>" class="button button-ghost" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-tv"></i> TV Mode</a>
      <?php if ($isLoggedIn): ?>
          <div class="user-avatar-badge" style="display: flex; align-items: center; gap: 8px; margin-left: 10px;">
              <img src="<?=
                  !empty($user['profile_photo'])
                      ? avatar_url($user['profile_photo'])
                      : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username']) . '&background=0d1420&color=14b8a6&bold=true'
              ?>" alt="Profile" style="width: 32px; height: 32px; border-radius: 50%; border: 2px solid #14b8a6; object-fit: cover;">
              <span style="font-size: 14px; font-weight: 600; color: #ffffff;"><?= e($user['username']) ?></span>
          </div>
          <?php if (is_admin()): ?>
              <a href="<?= app_url('/admin/dashboard') ?>" class="button button-light" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
          <?php else: ?>
              <a href="<?= app_url('/auth/logout') ?>" class="button button-light" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
          <?php endif; ?>
      <?php else: ?>
          <a href="<?= app_url('/auth/login') ?>" class="button button-light" style="padding: 6px 12px; font-size: 14px;"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
      <?php endif; ?>
  </div>
</header>
<main>
