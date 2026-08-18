<?php
header("Location: home.php");
exit;
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/event-guard.php';

$activeEvent = get_active_musabaqa();
$eventTitle = $activeEvent['title'] ?? 'Kauzariyya Arts Festival';
$startDateFormatted = !empty($activeEvent['start_date']) ? date('d F Y', strtotime($activeEvent['start_date'])) : '18 August 2026';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#030a05">
  <meta name="description" content="Mobile-Responsive Live Display for <?= e($eventTitle) ?>. Real-time scores and performance spotlight.">
  <title>Live Display · <?= e($eventTitle) ?></title>
  <link rel="icon" type="image/png" href="<?= asset_url('images/kauzariyya-logo.png') ?>">
  <script src="capacitor.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700&family=Cairo:wght@600;700;800;900&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --green:   #10b981;
      --green-d: #059669;
      --blue:    #3b82f6;
      --gold:    #f59e0b;
      --silver:  #94a3b8;
      --bronze:  #b45309;
      --bg:      #030a05;
      --card:    rgba(10, 22, 15, 0.72);
      --border:  rgba(255,255,255,0.07);
      --text:    #f1f5f9;
      --muted:   rgba(255,255,255,0.38);
    }

    html { scroll-behavior: smooth; }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Plus Jakarta Sans', sans-serif;
      min-height: 100dvh;
      overflow-x: hidden;
    }

    /* ── Ambient background ── */
    .bg-scene {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
    }
    .bg-scene::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(16,185,129,0.13) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 80%, rgba(59,130,246,0.09) 0%, transparent 55%),
        radial-gradient(ellipse 50% 40% at 50% 50%, rgba(245,158,11,0.04) 0%, transparent 60%);
    }
    .bg-scene::after {
      content: '';
      position: absolute;
      inset: 0;
      background: repeating-linear-gradient(
        0deg,
        transparent,
        transparent 3px,
        rgba(255,255,255,0.012) 3px,
        rgba(255,255,255,0.012) 4px
      );
    }

    /* floating orbs */
    .orb {
      position: fixed;
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.35;
      animation: orb-drift 18s ease-in-out infinite alternate;
      pointer-events: none;
    }
    .orb-1 { width: 320px; height: 320px; background: #10b981; top: -80px; left: -60px; animation-duration: 22s; }
    .orb-2 { width: 260px; height: 260px; background: #3b82f6; bottom: 80px; right: -80px; animation-duration: 17s; animation-delay: -5s; }
    .orb-3 { width: 180px; height: 180px; background: #f59e0b; top: 40%; left: 50%; animation-duration: 25s; animation-delay: -10s; opacity: 0.18; }

    @keyframes orb-drift {
      0%   { transform: translate(0,0) scale(1); }
      50%  { transform: translate(40px, 30px) scale(1.1); }
      100% { transform: translate(-20px, 50px) scale(0.95); }
    }

    /* ── Layout ── */
    .page-wrap {
      position: relative;
      z-index: 2;
      max-width: 560px;
      margin: 0 auto;
      padding: 16px 14px 48px;
    }

    /* ── Header ── */
    .site-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      margin-bottom: 20px;
      background: rgba(10,22,15,0.6);
      border: 1px solid var(--border);
      border-radius: 18px;
      backdrop-filter: blur(20px);
    }
    .brand-link {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
    }
    .brand-link img {
      height: 34px;
      width: auto;
      filter: drop-shadow(0 0 8px rgba(16,185,129,0.4));
    }
    .brand-text h1 {
      font-size: 15px;
      font-weight: 800;
      color: #fff;
      line-height: 1.1;
      letter-spacing: -0.02em;
    }
    .brand-text span {
      font-size: 9.5px;
      font-weight: 500;
      color: var(--muted);
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    .btn-back {
      display: flex;
      align-items: center;
      gap: 5px;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.09);
      color: rgba(255,255,255,0.75);
      padding: 7px 13px;
      border-radius: 30px;
      font-size: 11.5px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.22s ease;
      white-space: nowrap;
    }
    .btn-back svg { opacity: 0.7; transition: transform 0.2s; }
    .btn-back:hover {
      background: rgba(16,185,129,0.12);
      border-color: rgba(16,185,129,0.5);
      color: var(--green);
    }
    .btn-back:hover svg { transform: translateX(-2px); opacity: 1; }

    /* ── Live ticker bar ── */
    .live-ticker {
      display: flex;
      align-items: center;
      gap: 10px;
      background: linear-gradient(90deg, rgba(16,185,129,0.08), rgba(16,185,129,0.03));
      border: 1px solid rgba(16,185,129,0.18);
      border-radius: 12px;
      padding: 8px 14px;
      margin-bottom: 18px;
      overflow: hidden;
    }
    .ticker-dot {
      width: 7px;
      height: 7px;
      background: var(--green);
      border-radius: 50%;
      flex-shrink: 0;
      box-shadow: 0 0 0 0 rgba(16,185,129,0.8);
      animation: ticker-pulse 1.4s ease-in-out infinite;
    }
    @keyframes ticker-pulse {
      0%   { box-shadow: 0 0 0 0 rgba(16,185,129,0.8); }
      60%  { box-shadow: 0 0 0 7px rgba(16,185,129,0); }
      100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
    }
    .ticker-label {
      font-size: 10px;
      font-weight: 800;
      color: var(--green);
      text-transform: uppercase;
      letter-spacing: 0.12em;
      flex-shrink: 0;
    }
    .ticker-divider {
      width: 1px;
      height: 12px;
      background: rgba(16,185,129,0.25);
      flex-shrink: 0;
    }
    .ticker-text {
      font-size: 11px;
      font-weight: 600;
      color: rgba(255,255,255,0.55);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* ── Glass card ── */
    .glass-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 22px;
      backdrop-filter: blur(22px);
      -webkit-backdrop-filter: blur(22px);
      box-shadow:
        0 4px 24px rgba(0,0,0,0.4),
        inset 0 1px 0 rgba(255,255,255,0.05);
      margin-bottom: 18px;
      overflow: hidden;
    }
    .card-header-strip {
      padding: 14px 20px 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .card-badge {
      display: flex;
      align-items: center;
      gap: 7px;
    }
    .badge-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      animation: ticker-pulse 1.4s ease-in-out infinite;
    }
    .badge-dot.green { background: var(--green); }
    .badge-dot.blue  { background: var(--blue);  box-shadow: 0 0 0 0 rgba(59,130,246,0.8); }
    @keyframes blue-pulse {
      0%   { box-shadow: 0 0 0 0 rgba(59,130,246,0.8); }
      60%  { box-shadow: 0 0 0 7px rgba(59,130,246,0); }
      100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
    }
    .badge-dot.blue { animation-name: blue-pulse; }
    .badge-text {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.1em;
    }
    .badge-text.green { color: var(--green); }
    .badge-text.blue  { color: var(--blue); }
    .card-last-updated {
      font-size: 9px;
      color: var(--muted);
      font-weight: 500;
    }

    /* ── Spotlight card body ── */
    .spotlight-body { padding: 16px 20px 20px; }

    .program-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--muted);
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.06);
      padding: 4px 10px;
      border-radius: 30px;
      margin-bottom: 12px;
    }
    .program-eyebrow svg { opacity: 0.6; }

    .performer-name-el {
      font-size: 30px;
      font-weight: 900;
      color: #fff;
      line-height: 1.1;
      letter-spacing: -0.03em;
      margin-bottom: 18px;
      background: linear-gradient(135deg, #fff 60%, rgba(16,185,129,0.7));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      text-fill-color: transparent;
    }

    .meta-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 0;
    }
    .meta-pill {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 14px;
      padding: 10px 14px;
      transition: border-color 0.3s;
    }
    .meta-pill-label {
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .meta-pill-value {
      font-size: 16px;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.01em;
    }
    .meta-pill-value.team-val {
      color: var(--team-color, var(--green));
      font-size: 14px;
    }

    /* ── Next Up box ── */
    .next-up-strip {
      margin: 14px 20px 18px;
      background: linear-gradient(90deg, rgba(245,158,11,0.06), rgba(245,158,11,0.02));
      border: 1px dashed rgba(245,158,11,0.25);
      border-radius: 14px;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .next-up-icon {
      font-size: 18px;
      flex-shrink: 0;
    }
    .next-up-meta {}
    .next-up-label {
      font-size: 9px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--gold);
      margin-bottom: 2px;
    }
    .next-up-name-el {
      font-size: 13px;
      font-weight: 700;
      color: rgba(255,255,255,0.85);
    }

    /* ── Break card ── */
    .break-display {
      text-align: center;
      padding: 36px 16px 28px;
    }
    .break-emoji {
      font-size: 44px;
      display: block;
      margin-bottom: 12px;
      animation: float-emoji 3s ease-in-out infinite;
    }
    @keyframes float-emoji {
      0%, 100% { transform: translateY(0); }
      50%       { transform: translateY(-6px); }
    }
    .break-heading {
      font-size: 22px;
      font-weight: 800;
      color: var(--gold);
      margin-bottom: 6px;
      letter-spacing: -0.02em;
    }
    .break-sub {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.5;
    }

    /* ── Leaderboard ── */
    .lb-body { padding: 12px 14px 16px; }

    .leader-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 11px 14px;
      border-radius: 13px;
      margin-bottom: 8px;
      background: rgba(255,255,255,0.025);
      border: 1px solid rgba(255,255,255,0.04);
      border-left: 3px solid var(--row-color, rgba(255,255,255,0.1));
      transition: background 0.25s, transform 0.2s;
      animation: slide-in 0.4s ease both;
    }
    .leader-row:last-child { margin-bottom: 0; }
    .leader-row:hover {
      background: rgba(255,255,255,0.045);
      transform: translateX(3px);
    }
    @keyframes slide-in {
      from { opacity: 0; transform: translateX(-12px); }
      to   { opacity: 1; transform: translateX(0); }
    }
    .leader-left {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }
    .rank-badge {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      font-size: 12px;
      font-weight: 800;
    }
    .rank-badge.gold   { background: rgba(245,158,11,0.15); color: var(--gold);   border: 1.5px solid rgba(245,158,11,0.4); }
    .rank-badge.silver { background: rgba(148,163,184,0.12); color: var(--silver); border: 1.5px solid rgba(148,163,184,0.35); }
    .rank-badge.bronze { background: rgba(180,83,9,0.15);   color: #d97706;       border: 1.5px solid rgba(180,83,9,0.4); }
    .rank-badge.normal { background: rgba(255,255,255,0.04); color: var(--muted);  border: 1.5px solid rgba(255,255,255,0.06); font-size: 10px; }

    .leader-name {
      font-size: 14px;
      font-weight: 700;
      color: #fff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .leader-score-wrap {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
    }
    .leader-score {
      font-size: 15px;
      font-weight: 800;
      color: var(--row-color, var(--green));
      background: rgba(255,255,255,0.05);
      padding: 4px 12px;
      border-radius: 30px;
      letter-spacing: -0.01em;
      border: 1px solid rgba(255,255,255,0.06);
    }

    /* ── Skeleton shimmer ── */
    .skeleton {
      background: linear-gradient(90deg, rgba(255,255,255,0.05) 25%, rgba(255,255,255,0.1) 50%, rgba(255,255,255,0.05) 75%);
      background-size: 200% 100%;
      animation: shimmer 1.6s infinite;
      border-radius: 8px;
    }
    @keyframes shimmer {
      0%   { background-position: -200% 0; }
      100% { background-position:  200% 0; }
    }
    .skel-row { height: 46px; margin-bottom: 8px; border-radius: 13px; }
    .skel-name { height: 38px; width: 65%; margin-bottom: 16px; border-radius: 8px; }
    .skel-eyebrow { height: 22px; width: 140px; border-radius: 30px; margin-bottom: 14px; }

    /* ── Footer ── */
    .page-footer {
      text-align: center;
      padding-top: 8px;
      font-size: 10px;
      color: rgba(255,255,255,0.2);
      font-weight: 500;
      letter-spacing: 0.04em;
    }
    .page-footer span { color: rgba(16,185,129,0.4); }

    /* ── App Download Card ── */
    .download-card {
      background: linear-gradient(135deg, rgba(59,130,246,0.15) 0%, rgba(16,185,129,0.1) 100%);
      border: 1px solid rgba(59,130,246,0.25);
      border-radius: 22px;
      backdrop-filter: blur(22px);
      -webkit-backdrop-filter: blur(22px);
      box-shadow: 0 8px 32px rgba(3, 10, 5, 0.5);
      padding: 20px;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 16px;
      position: relative;
      overflow: hidden;
    }
    .download-card::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle, rgba(59,130,246,0.08) 0%, transparent 60%);
      pointer-events: none;
    }
    .download-icon-wrap {
      width: 52px;
      height: 52px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--blue), var(--green));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      color: #fff;
      flex-shrink: 0;
      box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3);
    }
    .download-info {
      flex-grow: 1;
      min-width: 0;
    }
    .download-info h3 {
      font-size: 16px;
      font-weight: 800;
      color: #fff;
      margin-bottom: 3px;
      letter-spacing: -0.01em;
    }
    .download-info p {
      font-size: 11.5px;
      color: rgba(255,255,255,0.7);
      line-height: 1.4;
    }
    .btn-download-app {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: #fff;
      color: #030a05;
      padding: 10px 18px;
      border-radius: 14px;
      font-size: 13px;
      font-weight: 800;
      text-decoration: none;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 12px rgba(255, 255, 255, 0.15);
      white-space: nowrap;
      cursor: pointer;
    }
    .btn-download-app:hover {
      background: var(--blue);
      color: #fff;
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
      transform: translateY(-1px);
    }
    
    @media (max-width: 480px) {
      .download-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 14px;
      }
      .download-icon-wrap {
        width: 44px;
        height: 44px;
        font-size: 20px;
      }
      .btn-download-app {
        width: 100%;
      }
    }

    /* ── Transition fade on update ── */
    .fade-update {
      animation: fade-update 0.4s ease;
    }
    @keyframes fade-update {
      0%   { opacity: 0.3; transform: translateY(4px); }
      100% { opacity: 1;   transform: translateY(0); }
    }

    /* ── Home Screen Styles ── */
    .home-container {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      min-height: calc(100vh - 100px);
      min-height: calc(100dvh - 100px);
      width: 100%;
      max-width: 400px;
      margin: 0 auto;
      gap: 24px;
    }

    .home-card {
      width: 100%;
      background: var(--card);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 32px 24px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
      display: flex;
      flex-direction: column;
      gap: 24px;
      text-align: center;
    }

    .home-brand .brand-logo {
      width: 60px;
      height: 60px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--green), var(--green-d));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      color: #fff;
      box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
      margin: 0 auto 16px;
    }

    .home-brand h1 {
      font-size: 24px;
      font-weight: 900;
      color: #fff;
      letter-spacing: -0.5px;
    }

    .home-brand p {
      font-size: 14px;
      color: var(--muted);
      font-weight: 500;
      margin-top: 4px;
    }

    .home-menu {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .menu-item-btn {
      display: flex;
      align-items: center;
      gap: 16px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px 20px;
      cursor: pointer;
      text-align: left;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      width: 100%;
      color: inherit;
      font-family: inherit;
    }

    .menu-item-btn:hover {
      transform: translateY(-2px);
      border-color: rgba(16, 185, 129, 0.4);
      background: rgba(16, 185, 129, 0.08);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.15);
    }

    .menu-item-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: #fff;
      flex-shrink: 0;
    }

    .menu-item-content {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .menu-item-title {
      font-size: 16px;
      font-weight: 700;
      color: #fff;
    }

    .menu-item-desc {
      font-size: 11.5px;
      color: var(--muted);
      line-height: 1.3;
    }

    .home-footer-status {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: var(--muted);
      margin-top: 8px;
    }

    /* ── Slideshow Overlay Styles ── */
    .slideshow-view {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      z-index: 99999;
      background: #000;
      overflow: hidden;
    }

    .slideshow-iframe {
      border: none;
      width: 100%;
      height: 100%;
      display: block;
    }

    .slideshow-back-btn {
      position: fixed;
      top: 16px;
      left: 16px;
      z-index: 100000;
      background: rgba(10, 22, 15, 0.7);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(255, 255, 255, 0.15);
      color: #fff;
      padding: 10px 16px;
      border-radius: 99px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
      transition: all 0.2s ease;
      font-family: inherit;
    }

    .slideshow-back-btn:hover {
      background: rgba(244, 63, 94, 0.9);
      border-color: rgba(244, 63, 94, 0.4);
      box-shadow: 0 4px 12px rgba(244, 63, 94, 0.4);
    }
  </style>
</head>
<body>
  <!-- Ambient background -->
  <div class="bg-scene"></div>
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>

  <div class="page-wrap">

    <!-- HOME VIEW -->
    <div id="home-view" class="home-container" style="display: flex;">
      <div class="home-card">
        <div class="home-brand">
          <div class="brand-logo">
            🏆
          </div>
          <h1>Kauzariyya Musabaqa</h1>
          <p>Live Event Hub</p>
        </div>
        
        <div class="home-menu">
          <button onclick="showSlideshow()" class="menu-item-btn">
            <div class="menu-item-icon" style="background: linear-gradient(135deg, var(--blue), var(--green));">
              📺
            </div>
            <div class="menu-item-content">
              <span class="menu-item-title">Launch Slideshow</span>
              <span class="menu-item-desc">Fullscreen presentation view (Auto-Landscape)</span>
            </div>
          </button>
          
          <button onclick="showScoreboard()" class="menu-item-btn">
            <div class="menu-item-icon" style="background: linear-gradient(135deg, var(--gold), var(--bronze));">
              📊
            </div>
            <div class="menu-item-content">
              <span class="menu-item-title">Standings & Feed</span>
              <span class="menu-item-desc">Check overall standings and real-time updates</span>
            </div>
          </button>
        </div>
        
        <div class="home-footer-status">
          <div class="live-ticker" style="margin-bottom: 0; width: 100%; justify-content: center;">
            <span class="ticker-dot"></span>
            <span class="ticker-label" id="home-status-lbl">Connected</span>
          </div>
        </div>
      </div>
    </div>

    <!-- DASHBOARD VIEW -->
    <div id="dashboard-view" style="display: none;">
      <!-- Header -->
      <header class="site-header">
        <button onclick="goHome()" class="btn-back" style="border: none; cursor: pointer; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.09); color: rgba(255,255,255,0.75); padding: 7px 13px; border-radius: 30px; font-size: 11.5px; font-weight: 700; display: flex; align-items: center; gap: 5px;">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M7.5 2L3.5 6L7.5 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Back
        </button>
        <a href="<?= app_url('/') ?>" class="brand-link">
          <img src="<?= asset_url('images/kauzariyya-logo.png') ?>" alt="Kauzariyya Logo">
          <div class="brand-text">
            <h1>Kauzariyya</h1>
            <span>Live Display</span>
          </div>
        </a>
      </header>

      <!-- Live ticker -->
      <div class="live-ticker">
        <span class="ticker-dot"></span>
        <span class="ticker-label">Live</span>
        <div class="ticker-divider"></div>
        <span class="ticker-text" id="ticker-text"><?= e($eventTitle) ?> · Real-time updates every 4s</span>
      </div>

      <!-- Spotlight Card -->
      <div class="glass-card" id="spotlight-card">
        <div class="card-header-strip">
          <div class="card-badge">
            <span class="badge-dot green"></span>
            <span class="badge-text green" id="status-label">Live Spotlight</span>
          </div>
          <span class="card-last-updated" id="last-updated-el">–</span>
        </div>

        <div class="spotlight-body" id="spotlight-content">
          <!-- skeleton state -->
          <div class="skel-eyebrow skeleton"></div>
          <div class="skel-name skeleton"></div>
          <div class="meta-grid">
            <div class="skel-row skeleton"></div>
            <div class="skel-row skeleton"></div>
          </div>
        </div>

        <!-- Next Up -->
        <div class="next-up-strip" id="next-up-element" style="display:none;">
          <span class="next-up-icon">⏭</span>
          <div class="next-up-meta">
            <div class="next-up-label">Coming Up Next</div>
            <div class="next-up-name-el" id="next-up-name">–</div>
          </div>
        </div>
      </div>

      <!-- App Download Card -->
      <div class="download-card">
        <div class="download-icon-wrap">
          📲
        </div>
        <div class="download-info">
          <h3>Get the Mobile App</h3>
          <p>Install the native app for instant updates and presentation features.</p>
        </div>
        <a href="<?= app_url('/uploads/kauzariyya-musabaqa.apk') ?>" class="btn-download-app" download>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          Download APK
        </a>
        <a href="<?= app_url('/uploads/thanafus%20gallery.apk') ?>" class="btn-download-app" style="margin-top: 10px; background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3); color: #60a5fa;" download>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #60a5fa;">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          Download Gallery APK
        </a>
      </div>

      <!-- Leaderboard Card -->
      <div class="glass-card">
        <div class="card-header-strip" style="padding-bottom: 6px;">
          <div class="card-badge">
            <span class="badge-dot blue"></span>
            <span class="badge-text blue">Team Standings</span>
          </div>
        </div>
        <div class="lb-body" id="leaderboard-list">
          <div class="skel-row skeleton"></div>
          <div class="skel-row skeleton"></div>
          <div class="skel-row skeleton"></div>
        </div>
      </div>

      <footer class="page-footer">
        Auto-refreshing every 4 seconds · <span>Kauzariyya <?= date('Y') ?></span>
      </footer>
    </div>

  </div><!-- /page-wrap -->

  <!-- SLIDESHOW VIEW -->
  <div id="slideshow-view" class="slideshow-view" style="display: none;">
    <button onclick="exitSlideshow()" class="slideshow-back-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"></line>
        <polyline points="12 19 5 12 12 5"></polyline>
      </svg>
      <span>Back to Home</span>
    </button>
    <iframe class="slideshow-iframe" src="https://musabaqa.kauzariyya.com/live-display/"></iframe>
  </div>

  <script>
    const apiEndpoint = 'live-display/api/current-program.php';

    // ── Web-to-App Hybrid View Routing & Native Integrations ──
    const isApp = typeof window.Capacitor !== 'undefined';
    let ScreenOrientation = null;
    let AppPlugin = null;

    if (isApp) {
      ScreenOrientation = window.Capacitor.Plugins.ScreenOrientation;
      AppPlugin = window.Capacitor.Plugins.App;
    }

    async function lockLandscape() {
      if (isApp && ScreenOrientation) {
        try {
          await ScreenOrientation.lock({ orientation: 'landscape' });
        } catch (e) {
          console.warn('Native ScreenOrientation lock failed:', e);
        }
      }
    }

    async function unlockOrientation() {
      if (isApp && ScreenOrientation) {
        try {
          await ScreenOrientation.unlock();
        } catch (e) {
          console.warn('Native ScreenOrientation unlock failed:', e);
        }
      }
    }

    function showSlideshow() {
      document.getElementById('home-view').style.display = 'none';
      document.getElementById('dashboard-view').style.display = 'none';
      document.getElementById('slideshow-view').style.display = 'block';
      lockLandscape();
    }

    function exitSlideshow() {
      document.getElementById('slideshow-view').style.display = 'none';
      document.getElementById('home-view').style.display = 'flex';
      unlockOrientation();
    }

    function showScoreboard() {
      document.getElementById('home-view').style.display = 'none';
      document.getElementById('dashboard-view').style.display = 'block';
    }

    function goHome() {
      document.getElementById('dashboard-view').style.display = 'none';
      document.getElementById('home-view').style.display = 'flex';
    }

    // Bind physical back button for Android
    if (isApp && AppPlugin) {
      AppPlugin.addListener('backButton', () => {
        const homeView = document.getElementById('home-view');
        const dashboardView = document.getElementById('dashboard-view');
        const slideshowView = document.getElementById('slideshow-view');

        if (slideshowView && slideshowView.style.display !== 'none') {
          exitSlideshow();
        } else if (dashboardView && dashboardView.style.display !== 'none') {
          goHome();
        } else {
          AppPlugin.exitApp();
        }
      });

      // Update home status label to show app-specific connection
      const homeStatusLbl = document.getElementById('home-status-lbl');
      if (homeStatusLbl) homeStatusLbl.textContent = 'App Core Connected';
    }

    function fmtTime() {
      return new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function setLastUpdated() {
      const el = document.getElementById('last-updated-el');
      if (el) el.textContent = fmtTime();
    }

    async function fetchLiveUpdates() {
      try {
        const response = await fetch(apiEndpoint + '?t=' + Date.now());
        if (!response.ok) return;
        const res = await response.json();
        if (!res.success || !res.data) return;

        const data = res.data;
        updateSpotlight(data.current);
        updateLeaderboard(data.leaderboard);
        setLastUpdated();
      } catch (err) {
        console.error('Error fetching live updates:', err);
      }
    }

    function animateFade(el) {
      el.classList.remove('fade-update');
      void el.offsetWidth; // reflow
      el.classList.add('fade-update');
    }

    function updateSpotlight(curr) {
      const statusLabel   = document.getElementById('status-label');
      const contentEl     = document.getElementById('spotlight-content');
      const nextUpElement = document.getElementById('next-up-element');
      const nextUpName    = document.getElementById('next-up-name');
      const tickerText    = document.getElementById('ticker-text');

      if (!curr || curr.is_break || !curr.performer) {
        // ── Break state ──
        statusLabel.textContent = 'Interval';
        contentEl.innerHTML = `
          <div class="break-display">
            <span class="break-emoji">☕</span>
            <div class="break-heading">Break / Interval</div>
            <p class="break-sub">No active stage performance at this moment.<br>Stay tuned — something amazing is coming!</p>
          </div>`;
        animateFade(contentEl);
        nextUpElement.style.display = 'none';
        if (tickerText) tickerText.textContent = 'On break · Next performance starting soon';
        return;
      }

      // ── Live performer ──
      statusLabel.textContent = 'Live Spotlight';

      const prog = curr.program  || {};
      const perf = curr.performer || {};
      const teamColor = perf.team_color || '#10b981';
      const venue = prog.venue || 'Stage';
      const title = prog.title || 'Program';

      contentEl.innerHTML = `
        <div class="program-eyebrow">
          <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
            <circle cx="5" cy="5" r="4" stroke="currentColor" stroke-width="1.4"/>
            <path d="M5 3V5.5L6.5 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          </svg>
          ${escHtml(venue)} &middot; ${escHtml(title)}
        </div>
        <div class="performer-name-el" id="performer-name">${escHtml(perf.name || 'Anonymous Performer')}</div>
        <div class="meta-grid">
          <div class="meta-pill">
            <div class="meta-pill-label">Chest No.</div>
            <div class="meta-pill-value">${escHtml(String(perf.chest_number || perf.code || '—'))}</div>
          </div>
          <div class="meta-pill">
            <div class="meta-pill-label">Team</div>
            <div class="meta-pill-value team-val" style="--team-color:${escHtml(teamColor)}">${escHtml(perf.team_name || '—')}</div>
          </div>
        </div>`;
      animateFade(contentEl);

      if (tickerText) tickerText.textContent = `Now: ${perf.name || 'Performer'} · ${venue}`;

      // Next performer
      const next = curr.next_performer || {};
      if (next && next.name) {
        nextUpElement.style.display = 'flex';
        nextUpName.textContent = `${next.name}${next.team_name ? '  ·  ' + next.team_name : ''}`;
      } else {
        nextUpElement.style.display = 'none';
      }
    }

    const MEDALS = ['🥇', '🥈', '🥉'];
    const RANK_CLASS = ['gold', 'silver', 'bronze'];

    function updateLeaderboard(leaders) {
      const listEl = document.getElementById('leaderboard-list');
      if (!leaders || !leaders.length) {
        listEl.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;">No team standings yet.</div>`;
        return;
      }

      listEl.innerHTML = leaders.map((t, idx) => {
        const color    = t.team_color || '#10b981';
        const rankCls  = RANK_CLASS[idx] ?? 'normal';
        const rankLabel = idx < 3 ? MEDALS[idx] : String(idx + 1).padStart(2, '0');
        const delay    = idx * 60;
        return `<div class="leader-row" style="--row-color:${escHtml(color)};animation-delay:${delay}ms">
          <div class="leader-left">
            <span class="rank-badge ${rankCls}">${rankLabel}</span>
            <span class="leader-name" lang="ar">${escHtml(t.team_name)}</span>
          </div>
          <div class="leader-score-wrap">
            <span class="leader-score">${parseFloat(t.total_score || 0).toFixed(1)}</span>
          </div>
        </div>`;
      }).join('');
    }

    function escHtml(str) {
      return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
    }

    // Start polling
    fetchLiveUpdates();
    setInterval(fetchLiveUpdates, 4000);
  </script>
</body>
</html>
