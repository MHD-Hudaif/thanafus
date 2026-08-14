<?php
declare(strict_types=1);

$teams = [];
$schedule = [];
$participants = [];
$committee = [];

// Establish database context or fall back gracefully
try {
    require_once __DIR__ . '/../includes/public-data.php';

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
        if (str_starts_with($session, 'section_')) {
            $hour = (int)date('H', strtotime($s['start_time']));
            if ($hour < 9) {
                $session = 'subahi';
            } elseif ($hour >= 9 && $hour < 12) {
                $session = 'morning';
            } elseif ($hour >= 12 && $hour < 16) {
                $session = 'afternoon';
            } elseif ($hour >= 16 && $hour < 20) {
                $session = 'evening';
            } else {
                $session = 'night';
            }
        }

        $schedule[] = [
            $session,
            $s['start_time'],
            $title,
            $s['venue'] . ' · ' . $category,
            $s['status'],
            (int)$s['duration_minutes']
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
            $p['team_name']
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
</head>
<body class="page-home">
  <main>
    <div class="home-video-bg" aria-hidden="true">
      <video autoplay muted loop playsinline preload="metadata" data-background-video data-src="assets/intro3.mp4" style="--video-brightness:.25"></video>
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
        'teams' => $teams,
        'schedule' => $schedule,
        'participants' => $participants,
        'committee' => $committee
    ]) ?>;
  </script>
  <script src="assets/js/site.js" defer></script>
  <script src="main.js"></script>
</body>
</html>
