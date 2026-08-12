<?php
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

    /* ── Transition fade on update ── */
    .fade-update {
      animation: fade-update 0.4s ease;
    }
    @keyframes fade-update {
      0%   { opacity: 0.3; transform: translateY(4px); }
      100% { opacity: 1;   transform: translateY(0); }
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

    <!-- Header -->
    <header class="site-header">
      <a href="<?= app_url('/') ?>" class="brand-link">
        <img src="<?= asset_url('images/kauzariyya-logo.png') ?>" alt="Kauzariyya Logo">
        <div class="brand-text">
          <h1>Kauzariyya</h1>
          <span>Live Display</span>
        </div>
      </a>
      <a href="<?= app_url('/') ?>" class="btn-back">
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
          <path d="M7.5 2L3.5 6L7.5 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Home
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

  </div><!-- /page-wrap -->

  <script>
    const apiEndpoint = 'live-display/api/current-program.php';

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
