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
  <meta name="theme-color" content="#062117">
  <meta name="description" content="Mobile-Responsive Live Display for <?= e($eventTitle) ?>. Real-time scores and performance spotlight.">
  <title>Live Display (Mobile) · <?= e($eventTitle) ?></title>
  <link rel="icon" type="image/png" href="<?= asset_url('images/kauzariyya-logo.png') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&family=Cairo:wght@600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('css/site.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/modern.css') ?>">
  <style>
    body {
      background: #050b07 !important;
      color: #f8fafc !important;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      padding-bottom: 40px;
    }
    body::before {
      content: "" !important;
      position: fixed !important;
      inset: 0 !important;
      z-index: 1 !important;
      background: linear-gradient(135deg, rgba(6, 33, 23, 0.95), rgba(5, 11, 7, 0.98)) !important;
      pointer-events: none !important;
      display: block !important;
    }
    .mobile-live-container {
      position: relative;
      z-index: 2;
      max-width: 600px;
      margin: 0 auto;
      padding: 16px;
    }
    /* Header styling */
    .live-mobile-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .live-mobile-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
    }
    .live-mobile-brand img {
      height: 36px;
      width: auto;
      object-fit: contain;
    }
    .live-mobile-brand h1 {
      font-size: 16px;
      font-weight: 800;
      color: #fff;
      line-height: 1.1;
    }
    .live-mobile-brand span {
      display: block;
      font-size: 10px;
      color: rgba(255, 255, 255, 0.5);
      font-weight: 500;
    }
    .btn-back-home {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #fff;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-back-home:hover {
      background: rgba(16, 185, 129, 0.15);
      border-color: #10b981;
    }
    /* Section style */
    .live-section {
      background: rgba(14, 25, 21, 0.65);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
      backdrop-filter: blur(10px);
    }
    /* Live Pulse Badge */
    .live-badge-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
    }
    .live-pulse-dot {
      width: 8px;
      height: 8px;
      background: #10b981;
      border-radius: 50%;
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
      animation: pulse-dot 1.6s infinite;
    }
    @keyframes pulse-dot {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .live-badge-label {
      font-size: 11px;
      font-weight: 800;
      color: #10b981;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    /* Spotlight / Presenter Info */
    .spotlight-title {
      font-size: 13px;
      color: rgba(255, 255, 255, 0.5);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 6px;
    }
    .spotlight-performer {
      font-size: 26px;
      font-weight: 800;
      color: #fff;
      margin-bottom: 12px;
      line-height: 1.2;
    }
    .spotlight-meta-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      border-top: 1px solid rgba(255, 255, 255, 0.06);
      padding-top: 12px;
    }
    .meta-box-label {
      font-size: 10px;
      color: rgba(255, 255, 255, 0.4);
      text-transform: uppercase;
      margin-bottom: 2px;
    }
    .meta-box-value {
      font-size: 14px;
      font-weight: 700;
      color: #fff;
    }
    .meta-box-value.team {
      color: var(--team-color, #10b981);
    }
    /* Break state style */
    .break-card {
      text-align: center;
      padding: 30px 10px;
    }
    .break-icon {
      font-size: 32px;
      margin-bottom: 10px;
    }
    .break-title {
      font-size: 18px;
      font-weight: 700;
      color: #facc15;
    }
    /* Leaderboard list */
    .leader-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .leader-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.04);
      padding: 10px 14px;
      border-radius: 10px;
    }
    .leader-row-name-group {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .leader-row-rank {
      font-size: 11px;
      font-weight: 800;
      color: rgba(255, 255, 255, 0.3);
      background: rgba(255, 255, 255, 0.05);
      width: 22px;
      height: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
    }
    .leader-row-name {
      font-size: 14px;
      font-weight: 700;
      color: #fff;
    }
    .leader-row-score {
      font-size: 14px;
      font-weight: 800;
      color: var(--team-color, #10b981);
      background: rgba(255, 255, 255, 0.04);
      padding: 4px 10px;
      border-radius: 12px;
    }
    /* Next Up Info */
    .next-up-box {
      background: rgba(255, 255, 255, 0.02);
      border: 1px dashed rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      padding: 12px 16px;
      margin-top: 16px;
    }
    .next-up-header {
      font-size: 10px;
      color: rgba(255, 255, 255, 0.4);
      text-transform: uppercase;
      font-weight: 700;
      margin-bottom: 4px;
    }
    .next-up-value {
      font-size: 13px;
      font-weight: 600;
      color: rgba(255, 255, 255, 0.85);
    }
  </style>
</head>
<body>
  <div class="mobile-live-container">
    <header class="live-mobile-header">
      <a href="index.php" class="live-mobile-brand">
        <img src="<?= asset_url('images/kauzariyya-logo.png') ?>" alt="Kauzariyya Logo">
        <div>
          <h1>Kauzariyya</h1>
          <span>Live Display (Mobile)</span>
        </div>
      </a>
      <a href="index.php" class="btn-back-home">Back to Home</a>
    </header>

    <!-- Slide Spotlight Container -->
    <section class="live-section" id="spotlight-card">
      <div class="live-badge-wrap">
        <span class="live-pulse-dot"></span>
        <span class="live-badge-label" id="status-label">Live Spotlight</span>
      </div>
      
      <!-- Dynamic Performance Info -->
      <div id="spotlight-content">
        <div class="spotlight-title" id="stage-program-label">Loading Program...</div>
        <div class="spotlight-performer" id="performer-name">Please wait...</div>
        <div class="spotlight-meta-grid">
          <div>
            <div class="meta-box-label">Chest Number</div>
            <div class="meta-box-value" id="chest-no">-</div>
          </div>
          <div>
            <div class="meta-box-label">Team</div>
            <div class="meta-box-value team" id="team-name">-</div>
          </div>
        </div>
        
        <!-- Next performer -->
        <div class="next-up-box" id="next-up-element" style="display:none;">
          <div class="next-up-header">Coming Up Next</div>
          <div class="next-up-value" id="next-up-name">-</div>
        </div>
      </div>
    </section>

    <!-- Leaderboard Container -->
    <section class="live-section">
      <div class="live-badge-wrap">
        <span class="live-pulse-dot" style="background:#3b82f6;"></span>
        <span class="live-badge-label" style="color:#3b82f6;">Live Team Standings</span>
      </div>
      <div class="leader-list" id="leaderboard-list">
        <!-- Rendered dynamically -->
        <div style="text-align:center; padding:20px; color:rgba(255,255,255,0.4);">Loading leaderboard standings...</div>
      </div>
    </section>
  </div>

  <!-- Polling update script -->
  <script>
    const apiEndpoint = 'live-display/api/current-program.php';

    async function fetchLiveUpdates() {
      try {
        const response = await fetch(apiEndpoint + '?t=' + Date.now());
        if (!response.ok) return;
        const res = await response.json();
        if (!res.success || !res.data) return;

        const data = res.data;
        updateSpotlight(data.current);
        updateLeaderboard(data.leaderboard);
      } catch (err) {
        console.error('Error fetching live updates:', err);
      }
    }

    function updateSpotlight(curr) {
      const statusLabel = document.getElementById('status-label');
      const stageLabel = document.getElementById('stage-program-label');
      const performerName = document.getElementById('performer-name');
      const chestNo = document.getElementById('chest-no');
      const teamName = document.getElementById('team-name');
      const nextUpElement = document.getElementById('next-up-element');
      const nextUpName = document.getElementById('next-up-name');

      if (!curr || curr.is_break || !curr.performer) {
        // Break/Interval layout
        statusLabel.textContent = 'Interval';
        stageLabel.textContent = 'Arts Festival';
        performerName.innerHTML = `<div class="break-card">
          <div class="break-icon">☕</div>
          <div class="break-title">Break / Interval</div>
          <p style="font-size:12px; color:rgba(255,255,255,0.4); margin-top:4px;">No active stage performances at this moment.</p>
        </div>`;
        chestNo.textContent = '-';
        teamName.textContent = '-';
        teamName.style.setProperty('--team-color', '#fff');
        nextUpElement.style.display = 'none';
        return;
      }

      // Live performer details
      statusLabel.textContent = 'Live Spotlight';
      const prog = curr.program || {};
      stageLabel.textContent = `${prog.venue || 'Stage'} · ${prog.title || 'Program'}`;
      
      const perf = curr.performer || {};
      performerName.textContent = perf.name || 'Anonymous Performer';
      chestNo.textContent = perf.chest_number || perf.code || '-';
      
      teamName.textContent = perf.team_name || '-';
      teamName.style.setProperty('--team-color', perf.team_color || '#10b981');

      // Next performer
      const next = curr.next_performer || {};
      if (next && next.name) {
        nextUpElement.style.display = 'block';
        nextUpName.textContent = `${next.name} (${next.team_name || 'Team'})`;
      } else {
        nextUpElement.style.display = 'none';
      }
    }

    function updateLeaderboard(leaders) {
      const listEl = document.getElementById('leaderboard-list');
      if (!leaders || !leaders.length) {
        listEl.innerHTML = `<div style="text-align:center; padding:20px; color:rgba(255,255,255,0.4);">No team standings available.</div>`;
        return;
      }

      listEl.innerHTML = leaders.map((t, idx) => {
        const teamColor = t.team_color || '#10b981';
        return `<div class="leader-row" style="border-left: 3px solid ${teamColor};">
          <div class="leader-row-name-group">
            <span class="leader-row-rank">${String(idx + 1).padStart(2, '0')}</span>
            <span class="leader-row-name" lang="ar">${t.team_name}</span>
          </div>
          <span class="leader-row-score" style="--team-color: ${teamColor};">${parseFloat(t.total_score || 0)}</span>
        </div>`;
      }).join('');
    }

    // Start Polling every 4 seconds
    fetchLiveUpdates();
    setInterval(fetchLiveUpdates, 4000);
  </script>
</body>
</html>
