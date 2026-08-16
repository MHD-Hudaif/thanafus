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
      <div class="footer-location-map"><iframe title="Interactive Google Map showing Al Jamiathul Kauzariyya in Edathala" data-map-src="https://www.google.com/maps?q=10.0730%2C76.3704&amp;z=16&amp;output=embed" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe></div>
      <div class="footer-pulse-copy"><p class="overline">Our place · our community</p><h2>Rooted in<br>Edathala.</h2><p>A welcoming campus in Aluva where Qur’anic learning, scholarship and character grow together.</p><a class="footer-directions-link" href="https://www.google.com/maps/search/?api=1&amp;query=10.0730%2C76.3704" target="_blank" rel="noopener noreferrer"><span class="btn-gmap-badge" aria-hidden="true">●</span><span class="btn-gmap-label">Open in Google Maps</span><b class="btn-gmap-arrow" aria-hidden="true">↗</b></a></div>
    </aside>
    <div class="home-footer-identity"><h2>AL JAMIATHUL KAUZARIYYA</h2><address>Edathala North P.O., Aluva<br>Ernakulam District, Kerala 683561, India</address></div>
    <nav class="simple-social-icons" aria-label="Kauzariyya social media">
      <a class="facebook" href="https://www.facebook.com/Kauzariyya" target="_blank" rel="noopener noreferrer" aria-label="Facebook">Facebook</a>
      <a class="instagram" href="https://instagram.com/kauzariyya" target="_blank" rel="noopener noreferrer" aria-label="Instagram">Instagram</a>
      <a class="youtube" href="https://youtube.com/@Kauzariyya?sub_confirmation=1" target="_blank" rel="noopener noreferrer" aria-label="YouTube">YouTube</a>
    </nav>
    <p class="home-footer-copyright">© 2026 Al Jamiathul Kauzariyya · All rights reserved</p>
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
