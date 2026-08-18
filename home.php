<?php
declare(strict_types=1);

$teams = [];
$schedule = [];
$participants = [];
$committee = [];
$programs = [];
$students = [];
$sections = [];
$eventInfo = [
    'title' => 'Al-Jamiathul Kauzariyya · Arts Festival',
    'date' => '18 August 2026',
    'start_date' => '2026-08-18'
];

// Establish database context or fall back gracefully
try {
    require_once __DIR__ . '/includes/public-data.php';

    if (isset($_GET['from_app'])) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['from_app'] = true;
        session_write_close();
    }

    // Retrieve and format active event
    $event = tv_active_event();
    $eventTitle = trim((string)($event['title'] ?? 'Al-Jamiathul Kauzariyya · Arts Festival'));
    $eventTitle = $eventTitle !== '' ? $eventTitle : 'Al-Jamiathul Kauzariyya · Arts Festival';
    $eventDate = !empty($event['start_date']) 
        ? date('d F Y', strtotime((string)$event['start_date'])) 
        : '18 August 2026';
    $eventInfo = [
        'title' => $eventTitle,
        'date' => $eventDate,
        'start_date' => $event['start_date'] ?? '2026-08-18'
    ];

    // Retrieve and format teams
    $rawTeams = teams();
    foreach ($rawTeams as $t) {
        $teams[] = [
            'name' => $t['name'],
            'score' => (int)$t['score'],
            'color' => $t['color']
        ];
    }

    // Retrieve and format schedule
    $rawSchedule = schedule_items();
    foreach ($rawSchedule as $s) {
        $title = $s['title'];
        $category = $s['category'];
        if (!empty($s['is_stacked']) && !empty($s['stacked_programs'])) {
            $titles = [];
            foreach ($s['stacked_programs'] as $sp) {
                $titles[] = $sp['title'];
            }
            $title = implode(', ', $titles);
        }
        
        $session = $s['session'];

        $schedule[] = [
            $session,
            $s['start_time'],
            $title,
            $s['venue'] . ' · ' . $category,
            $s['status'],
            (int)$s['duration_minutes'],
            $s['date']
        ];
    }

    // Retrieve and format participants
    $rawParticipants = participants();
    foreach ($rawParticipants as $p) {
        $participants[] = [
            $p['name'],
            $p['code'],
            $p['program'],
            $p['category'],
            $p['reporting_time'],
            $p['team_name'],
            $p['team_color'] ?? '#4ee883',
            $p['order'] ?? 1,
            $p['id'] ?? 0,
            $p['program_id'] ?? 0
        ];
    }

    // Retrieve and format committee
    $rawCommittee = working_committee();
    foreach ($rawCommittee as $c) {
        $committee[] = [
            'name' => $c['name'],
            'role' => $c['role'],
            'image' => $c['image']
        ];
    }

    // Retrieve and format programs
    $programs = plan_programs();

    // Retrieve and format students
    $students = all_students();

    // Retrieve active schedule sections
    $sections = get_schedule_sections();
} catch (\Throwable $e) {
    // Graceful fallback if database configuration is missing
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#121513">
  <meta name="description" content="Kauzariyya Arts Festival static front-end">
  <title>Al-Jamiathul Kauzariyya · Arts Festival</title>
  <link rel="icon" type="image/png" href="assets/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&family=Plus+Jakarta+Sans:wght@700;800;900&family=Outfit:wght@700;800&family=Fredoka:wght@600;700&family=Amiri:wght@400;700&family=Cairo:wght@400;600;700&family=Reem+Kufi:wght@400;500;600;700&family=Tajawal:wght@400;500;700&family=Dosis:wght@300..800&family=Manrope:wght@400..800&family=Noto+Naskh+Arabic:wght@500..700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/modern.css">
  <script>
    window.isLowEndDevice = 
        (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) ||
        (navigator.deviceMemory && navigator.deviceMemory < 4) ||
        /SmartTV|GoogleTV|AppleTV|HbbTV|Tizen|WebOS|Android 9|Android 8|Android 7|Android 6|Android 5/i.test(navigator.userAgent) ||
        window.location.search.includes('perf=low');
    if (window.isLowEndDevice) {
        document.documentElement.classList.add('low-perf-device');
    }
  </script>
  <style>
    /* Low performance device overrides */
    .low-perf-device .home-video-bg,
    .low-perf-device [data-background-video],
    .low-perf-device .orb,
    .low-perf-device .ambient-glow {
        display: none !important;
    }
    .low-perf-device .glass-card,
    .low-perf-device .site-header,
    .low-perf-device .home-card,
    .low-perf-device .mobile-tabbar {
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
        background: #121513 !important;
        box-shadow: none !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    .low-perf-device * {
        text-shadow: none !important;
        box-shadow: none !important;
        transition: none !important;
    }
  </style>
  <?php if (function_exists('render_clarity_script')) render_clarity_script(); ?>
</head>
<body class="page-home">
  <main>
    <div class="home-video-bg" aria-hidden="true">
      <video autoplay muted loop playsinline preload="metadata" data-background-video data-src="assets/intro3.mp4" style="--video-brightness:.25" id="homeBgVideo"></video>
      <script>
        if (window.isLowEndDevice) {
            const v = document.getElementById('homeBgVideo');
            if (v) {
                v.removeAttribute('autoplay');
                try { v.pause(); } catch(_) {}
                v.style.display = 'none';
            }
        }
      </script>
    </div>
    <div id="react-root"></div>
  </main>

  <footer class="site-footer simple-social-footer home-institution-footer">
    <aside class="home-footer-utility home-footer-pulse home-footer-location" aria-label="Find Al Jamiathul Kauzariyya">
      <div class="footer-location-map">
        <iframe title="Interactive Google Map showing Al Jamiathul Kauzariyya in Edathala" src="https://www.google.com/maps?q=10.0730%2C76.3704&amp;z=16&amp;output=embed" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
        <span>
          <svg viewBox="0 0 24 24" fill="none"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" fill="#ea4335"/></svg>
        </span>
      </div>
      <div class="footer-pulse-copy">
        <p class="overline">Our place &middot; our community</p>
        <h2>Rooted in<br>Edathala.</h2>
        <p>A welcoming campus in Aluva where Qur’anic learning, scholarship and character grow together.</p>
        <a class="footer-directions-link" href="https://www.google.com/maps/search/?api=1&amp;query=10.0730%2C76.3704" target="_blank" rel="noopener noreferrer">
          <span class="btn-gmap-badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" fill="currentColor"/></svg>
          </span>
          <span class="btn-gmap-label">Open in Google Maps</span>
          <b class="btn-gmap-arrow" aria-hidden="true">&nearr;</b>
        </a>
      </div>
    </aside>
    <div class="home-footer-identity">
      <h2>AL JAMIATHUL KAUZARIYYA</h2>
      <address>Edathala North P.O., Aluva<br>Ernakulam District, Kerala 683561, India</address>
    </div>
    <nav class="simple-social-icons" aria-label="Kauzariyya social media">
      <a class="facebook" href="https://www.facebook.com/Kauzariyya" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
        <svg viewBox="0 0 32 32" width="28" height="28" fill="none">
          <circle cx="16" cy="16" r="16" fill="#1877F2"/>
          <path d="M21.2 16h-3.4v11.6h-4.8V16h-2.3v-4.1h2.3V9.3c0-2.3 1.4-3.6 3.5-3.6 1 0 2.1.2 2.1.2v2.3h-1.2c-1.1 0-1.5.7-1.5 1.4v2.3h3.8l-.5 4.1z" fill="#FFFFFF"/>
        </svg>
      </a>
      <a class="instagram" href="https://instagram.com/kauzariyya" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
        <svg viewBox="0 0 32 32" width="28" height="28" fill="none">
          <defs>
            <linearGradient id="ig-official-grad" x1="0%" y1="100%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#FFD600"/>
              <stop offset="25%" stop-color="#FF7A00"/>
              <stop offset="50%" stop-color="#FF0069"/>
              <stop offset="75%" stop-color="#D300C5"/>
              <stop offset="100%" stop-color="#7638FA"/>
            </linearGradient>
          </defs>
          <rect x="2.5" y="2.5" width="27" height="27" rx="8.5" fill="none" stroke="url(#ig-official-grad)" stroke-width="3"/>
          <circle cx="16" cy="16" r="6" fill="none" stroke="url(#ig-official-grad)" stroke-width="3"/>
          <circle cx="23" cy="9" r="1.8" fill="url(#ig-official-grad)"/>
        </svg>
      </a>
      <a class="x-social" href="https://x.com/kauzariyya" target="_blank" rel="noopener noreferrer" aria-label="X">
        <svg viewBox="0 0 32 32" width="28" height="28" fill="none">
          <path d="M20.6 7.5h3.2l-7 8 8.2 10.8h-6.4l-5-6.5-5.7 6.5H4.7l7.5-8.6L4.2 7.5h6.6l4.5 6 5.3-6zm-1.1 16.9h1.8L9.9 9.3H8l11.5 15.1z" fill="#FFFFFF"/>
        </svg>
      </a>
      <a class="whatsapp" href="https://whatsapp.com/channel/0029VaZ7xFm4tRrwIYmLGa31" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
        <svg viewBox="0 0 32 32" width="28" height="28" fill="none">
          <path d="M16 2a13.9 13.9 0 0 0-11.8 21.2L2.3 29.7l6.7-1.8A13.9 13.9 0 1 0 16 2zm0 25.4c-2.3 0-4.5-.6-6.4-1.8l-.5-.3-4 1.1 1.1-3.9-.3-.5A11.5 11.5 0 1 1 16 27.4zm6.7-8.6c-.3-.2-2.1-1-2.4-1.1-.3-.1-.6-.2-.8.2s-.9 1.1-1.1 1.3c-.2.2-.4.2-.7.1-2.1-1-3.7-2.6-4.7-4.7-.1-.3 0-.5.1-.7.1-.1.3-.4.5-.6.2-.2.2-.4.3-.6.1-.2 0-.4 0-.5-.1-.1-.8-2-1.1-2.7-.3-.7-.6-.6-.8-.6h-.7c-.2 0-.6.1-.9.4s-1.2 1.2-1.2 2.9c0 1.7 1.2 3.4 1.4 3.6.2.2 2.4 3.7 5.8 5.2.8.3 1.5.6 2 .7.8.3 1.6.2 2.2.1.7-.1 2.1-.9 2.4-1.7.3-.8.3-1.5.2-1.7-.1-.2-.3-.3-.6-.4z" fill="#25D366"/>
        </svg>
      </a>
      <a class="youtube" href="https://youtube.com/@Kauzariyya?sub_confirmation=1" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
        <svg viewBox="0 0 32 32" width="28" height="28" fill="none">
          <rect x="1" y="5" width="30" height="22" rx="6.5" fill="#FF0000"/>
          <path d="M13 11l8 5-8 5V11z" fill="#FFFFFF"/>
        </svg>
      </a>
    </nav>
    <p class="home-footer-copyright">&copy; 2026 Al Jamiathul Kauzariyya &middot; All rights reserved</p>
  </footer>

  <script>
    window.INITIAL_DATA = <?= json_encode([
        'event' => $eventInfo,
        'teams' => $teams,
        'schedule' => $schedule,
        'participants' => $participants,
        'committee' => $committee,
        'programs' => $programs,
        'students' => $students,
        'sections' => $sections
    ]) ?>;
  </script>
  <script src="assets/js/site.js?v=<?= file_exists(__DIR__ . '/assets/js/site.js') ? filemtime(__DIR__ . '/assets/js/site.js') : time() ?>" defer></script>
  <script src="main.js?v=<?= file_exists(__DIR__ . '/main.js') ? filemtime(__DIR__ . '/main.js') : time() ?>"></script>
</body>
</html>
