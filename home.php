<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/app.php';

// Establish public database data context gracefully
try {
    require_once __DIR__ . '/includes/public-data.php';
    $event = tv_active_event();
    $teams = teams();
    $schedule = schedule_items();
    $scheduleSections = schedule_sections();
    $results = result_items();
    $workingCommittee = working_committee();
    $venues = venues_data();
    
    $eventTitle = trim((string)($event['title'] ?? 'Kauzariyya Musabaqa 2026'));
    $eventTitle = $eventTitle !== '' ? $eventTitle : 'Kauzariyya Musabaqa 2026';
    
    $eventStart = !empty($event['start_date']) ? (string)$event['start_date'] : null;
    $eventDateFormatted = !empty($eventStart) 
        ? date('d F Y', strtotime($eventStart)) 
        : '05 JULY 2026';

    $candidatesCount = '800+';
    $eventId = tv_active_event_id();
    if ($eventId > 0 && isset($GLOBALS['musabaqa_pdo'])) {
        try {
            $stmtCount = $GLOBALS['musabaqa_pdo']->prepare("SELECT COUNT(DISTINCT student_id) FROM musabaqa_team_members WHERE event_id = ?");
            $stmtCount->execute([$eventId]);
            $cCount = (int)$stmtCount->fetchColumn();
            if ($cCount > 0) {
                $candidatesCount = (string)$cCount;
            }
        } catch (\Throwable $e) {}
    }
} catch (\Throwable $e) {
    $eventTitle = 'Kauzariyya Musabaqa 2026';
    $eventDateFormatted = '05 JULY 2026';
    $teams = [];
    $schedule = [];
    $scheduleSections = [];
    $results = [];
    $workingCommittee = [];
    $venues = [];
    $candidatesCount = '800+';
}

$user = $_SESSION['user'] ?? null;
$isLoggedIn = !empty($user);

// Fallbacks for preview/demo mode if database is unpopulated
if (empty($teams)) {
    $teams = [
        ['id' => 1, 'name' => 'Team Al-Fath', 'score' => 245, 'color' => '#10b981'],
        ['id' => 2, 'name' => 'Team Al-Noor', 'score' => 210, 'color' => '#f59e0b'],
        ['id' => 3, 'name' => 'Team Al-Hikmah', 'score' => 195, 'color' => '#3b82f6'],
        ['id' => 4, 'name' => 'Team Al-Badr', 'score' => 180, 'color' => '#8b5cf6'],
    ];
}

if (empty($venues)) {
    $venues = [
        [
            'name' => 'Stage 1 · Main Auditorium',
            'count' => 18,
            'next_program' => 'Qur’an Recitation - Senior Final',
            'next_program_status' => 'scoring',
            'last_program' => 'Hadith Memorization'
        ],
        [
            'name' => 'Stage 2 · Seminar Hall',
            'count' => 14,
            'next_program' => 'Arabic Speech - Junior Round',
            'next_program_status' => 'upcoming',
            'last_program' => 'Islamic Quiz Prelims'
        ]
    ];
}

if (empty($results)) {
    $results = [
        [
            'id' => 1,
            'participant' => 'Muhammad Rashid',
            'code' => 'K-102',
            'program' => 'Qur’an Recitation (Tajweed)',
            'category' => 'Ayaat Senior',
            'team_name' => 'Team Al-Fath',
            'score' => 98.5,
            'position' => 1
        ],
        [
            'id' => 2,
            'participant' => 'Abdullah Nizar',
            'code' => 'K-208',
            'program' => 'Arabic Oratory & Speech',
            'category' => 'Bidayah Junior',
            'team_name' => 'Team Al-Noor',
            'score' => 94.0,
            'position' => 2
        ],
        [
            'id' => 3,
            'participant' => 'Bilal Hassan',
            'code' => 'K-315',
            'program' => 'Islamic Quiz Championship',
            'category' => 'General Open',
            'team_name' => 'Team Al-Hikmah',
            'score' => 91.5,
            'position' => 3
        ]
    ];
}

if (empty($workingCommittee)) {
    $workingCommittee = [
        [
            'id' => 1,
            'name' => 'Usthad Ilyas Kauzari',
            'role' => 'General Convener',
            'place' => 'Aluva',
            'image' => 'https://daruliftakauzariyya.com/team-photos/Usthad-Ilyas.png'
        ],
        [
            'id' => 2,
            'name' => 'Usthad Abid Kauzari',
            'role' => 'Program Controller',
            'place' => 'Edathala',
            'image' => 'https://daruliftakauzariyya.com/team-photos/Abid.png'
        ],
        [
            'id' => 3,
            'name' => 'Usthad Abdul Basith',
            'role' => 'Chief Inspector',
            'place' => 'Ernakulam',
            'image' => 'https://ui-avatars.com/api/?name=Abdul+Basith&background=1b4332&color=fff&size=512'
        ],
        [
            'id' => 4,
            'name' => 'Usthad Faisal Farooqi',
            'role' => 'Stage & Venue Manager',
            'place' => 'Kochi',
            'image' => 'https://ui-avatars.com/api/?name=Faisal+Farooqi&background=1b4332&color=fff&size=512'
        ]
    ];
}

// Calculate highest score for leaderboard percentage bars
$maxScore = 1;
foreach ($teams as $t) {
    if ((float)$t['score'] > $maxScore) {
        $maxScore = (float)$t['score'];
    }
}

// Dynamic 3D schedule deck construction
$scheduleDeck = [];
if (!empty($schedule)) {
    $grouped = [];
    foreach ($schedule as $item) {
        $sec = $item['session'] ?? 'general';
        $grouped[$sec][] = $item;
    }

    $dayIndex = 1;
    foreach ($grouped as $secKey => $items) {
        $sectionName = $scheduleSections[$secKey] ?? ('Stage Session ' . $dayIndex);
        $dayNum = sprintf('%02d', $dayIndex);
        $progCount = count($items);
        
        $venuesList = array_unique(array_filter(array_column($items, 'venue')));
        $venueStr = !empty($venuesList) ? implode(' & ', array_slice($venuesList, 0, 2)) : 'STAGE 1 & 2';

        $catsList = array_unique(array_filter(array_column($items, 'category')));
        $catStr = !empty($catsList) ? implode(', ', array_slice($catsList, 0, 3)) : 'Qur\'an, Oratory & Arts';

        $scheduleDeck[] = [
            'badge' => 'DAY ' . $dayNum,
            'pill' => $progCount . ' PROGRAMMES',
            'number' => date('d', !empty($eventStart) ? strtotime($eventStart . " +".($dayIndex-1)." days") : time()),
            'month_year' => strtoupper(date('F Y', !empty($eventStart) ? strtotime($eventStart) : time())),
            'subtitle' => strtoupper($sectionName) . ' · ' . strtoupper($venueStr),
            'footer' => $catStr
        ];
        $dayIndex++;
        if ($dayIndex > 4) break;
    }
}

if (empty($scheduleDeck)) {
    $scheduleDeck = [
        [
            'badge' => 'DAY 01',
            'pill' => '12 PROGRAMMES',
            'number' => '18',
            'month_year' => 'AUGUST 2026',
            'subtitle' => 'TUESDAY · STAGE 1 & 2',
            'footer' => 'Qur\'an Recitation, Oratory & Literary Arts'
        ],
        [
            'badge' => 'DAY 02',
            'pill' => '14 PROGRAMMES',
            'number' => '19',
            'month_year' => 'AUGUST 2026',
            'subtitle' => 'WEDNESDAY · STAGE 1 & 2',
            'footer' => 'Grand Finale, Cultural Arts & Awards'
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#061009">
  <meta name="description" content="The official Kauzariyya Musabaqa companion for live scores, schedules, participants and festival results.">
  <title><?= e($eventTitle) ?> · Al Jamiathul Kauzariyya</title>
  
  <link rel="icon" type="image/png" href="<?= asset_url('kauzariyya-brand-icon.png') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <link rel="stylesheet" href="<?= asset_url('css/musabaqa-landing.css') ?>">
  <script src="<?= asset_url('js/musabaqa-landing.js') ?>" defer></script>
</head>
<body>

  <!-- ════════════════════════════════════════ BACKGROUND VIDEO ═══ -->
  <div class="home-video-bg">
    <video id="bgVideo" autoplay muted loop playsinline fetchpriority="high">
      <source src="<?= asset_url('video.mp4') ?>" type="video/mp4">
      <source src="<?= asset_url('intro.mp4') ?>" type="video/mp4">
    </video>
  </div>
  <div class="home-video-overlay" aria-hidden="true"></div>

  <!-- ════════════════════════════════════════ SITE HEADER ═══ -->
  <header class="site-header">
    <a href="<?= app_url('/home') ?>" class="site-logo">
      <img src="<?= asset_url('kauzariyya-brand-icon.png') ?>" alt="Kauzariyya Logo">
      <span>
        <b>Al-Jamiathul Kauzariyya</b>
        <small>Management Platform</small>
      </span>
    </a>

    <nav class="site-nav">
      <a href="#hero" class="active">Home</a>
      <a href="#leaderboard">Leaderboard</a>
      <a href="#stages">Stages</a>
      <a href="#results">Results</a>
      <a href="#committee">Committee</a>
      <a href="<?= app_url('/schedule') ?>">Schedule</a>
      <a href="<?= app_url('/participants') ?>">Participants</a>
      <a href="#about">About</a>
    </nav>

    <div class="header-account-actions">
      <div class="festival-date">
        <span>FESTIVAL DATE</span>
        <b><?= e(strtoupper($eventDateFormatted)) ?></b>
      </div>
      
      <a href="<?= app_url('/tv/index.php') ?>" class="header-action-btn btn-secondary" title="Open TV Live Display">
        <i class="fa-solid fa-tv"></i> TV Mode
      </a>

      <?php if ($isLoggedIn): ?>
        <a href="<?= app_url('/admin/dashboard') ?>" class="header-action-btn btn-primary">
          <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
      <?php else: ?>
        <a href="<?= app_url('/auth/login') ?>" class="header-action-btn btn-primary">
          <i class="fa-solid fa-right-to-bracket"></i> Login
        </a>
      <?php endif; ?>
    </div>
  </header>

  <!-- ════════════════════════════════════════ MAIN CONTENT ═══ -->
  <main id="hero">

    <!-- HERO SECTION -->
    <section class="home-redesign-hero">
      <div class="home-redesign-copy">
        <span class="home-hero-eyebrow">FAITH · KNOWLEDGE · CREATIVITY</span>
        <h1><?= e(explode(' ', $eventTitle)[0] ?? 'Kauzariyya') ?> <em><?= e(implode(' ', array_slice(explode(' ', $eventTitle), 1)) ?: 'Arts Festival') ?>.</em></h1>
        <p class="home-hero-intro">
          A celebration where students discover their voice, share their talent and grow through meaningful competition.
        </p>
      </div>
    </section>

    <!-- PLATFORM STATEMENT -->
    <section class="home-platform-statement section-wrap">
      <p>
        Competing in excellence, growing in knowledge, and standing together in faith. The official digital platform for Kauzariyya’s student competitions, live scores, schedules, teams and results.
      </p>
      <strong>
        EXCELLENCE THROUGH KNOWLEDGE <i></i> UNITY THROUGH FAITH <i></i> SUCCESS THROUGH SINCERITY
      </strong>
    </section>

    <!-- 1. LIVE LEADERBOARD / TEAM STANDINGS -->
    <section class="home-access section-wrap" id="leaderboard">
      <header>
        <div>
          <span class="overline">LIVE CHAMPIONSHIP</span>
          <h2>Team Leaderboard</h2>
        </div>
        <span>Real-time standings and total points accumulated across all competition categories.</span>
      </header>

      <div class="home-leaderboard-grid">
        <?php foreach ($teams as $rankIdx => $t): 
            $rank = $rankIdx + 1;
            $score = (float)($t['score'] ?? 0);
            $pct = min(100, max(8, round(($score / $maxScore) * 100)));
            $rankClass = $rank <= 3 ? 'rank-' . $rank : '';
            $medalIcon = $rank === 1 ? '🥇 1st Place' : ($rank === 2 ? '🥈 2nd Place' : ($rank === 3 ? '🥉 3rd Place' : '#' . $rank . ' Rank'));
            $color = $t['color'] ?? '#59df7b';
        ?>
          <article class="leaderboard-card">
            <div>
              <span class="leaderboard-rank-tag <?= $rankClass ?>"><?= $medalIcon ?></span>
              <div class="leaderboard-score-val"><?= (int)$score ?> <small>PTS</small></div>
              <div class="leaderboard-team-name"><?= e($t['name']) ?></div>
            </div>
            <div class="leaderboard-progress-bg">
              <div class="leaderboard-progress-bar" style="width: <?= $pct ?>%; background-color: <?= e($color) ?>;"></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 2. LIVE STAGES & VENUES -->
    <section class="home-schedule-3d-deck section-wrap" id="stages">
      <header class="schedule-3d-head">
        <div>
          <span class="overline">VENUES &amp; STAGES</span>
          <h2>Live Competition Stages</h2>
        </div>
        <a href="<?= app_url('/schedule') ?>" class="schedule-3d-action-btn">
          Full Stage Schedule <i class="fa-solid fa-arrow-right"></i>
        </a>
      </header>

      <div class="home-venues-grid">
        <?php foreach ($venues as $v): 
            $status = $v['next_program_status'] ?? 'upcoming';
            $statusLabel = $status === 'scoring' ? '● LIVE STAGE' : ($status === 'completed' ? 'FINISHED' : 'UPCOMING');
            $statusClass = $status === 'scoring' ? 'live' : ($status === 'completed' ? 'completed' : 'upcoming');
        ?>
          <article class="venue-card">
            <div>
              <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
              <div class="venue-title"><?= e($v['name']) ?></div>
              <p class="venue-meta">
                <?php if (!empty($v['next_program'])): ?>
                  <strong>Current/Next:</strong> <?= e($v['next_program']) ?>
                <?php else: ?>
                  All stage programs completed for this session.
                <?php endif; ?>
              </p>
            </div>
            <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--text-muted); font-size: 11px;">
              <i class="fa-solid fa-layer-group" style="color: var(--accent);"></i> <?= (int)($v['count'] ?? 0) ?> Total Programs Assigned
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 3. RECENT WINNERS & RESULTS -->
    <section class="home-access section-wrap" id="results">
      <header>
        <div>
          <span class="overline">FESTIVAL HIGHLIGHTS</span>
          <h2>Recent Winners &amp; Results</h2>
        </div>
        <a href="<?= app_url('/review') ?>" class="feature-card-link" style="font-size: 13px;">
          View All Results <i class="fa-solid fa-arrow-right"></i>
        </a>
      </header>

      <div class="home-results-grid">
        <?php foreach (array_slice($results, 0, 6) as $res): 
            $pos = (int)($res['position'] ?? 1);
            $posClass = $pos <= 3 ? 'pos-' . $pos : 'pos-3';
            $medalSymbol = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : '#' . $pos));
        ?>
          <article class="result-card">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between;">
                <div class="result-medal <?= $posClass ?>"><?= $medalSymbol ?></div>
                <?php if (!empty($res['code'])): ?>
                  <span style="font-size: 10px; color: var(--text-muted); font-weight: 800; background: rgba(255,255,255,0.06); padding: 3px 8px; border-radius: 6px;">CHEST: <?= e($res['code']) ?></span>
                <?php endif; ?>
              </div>
              <div class="result-program-title"><?= e($res['program']) ?></div>
              <div class="result-winner-name"><?= e($res['participant']) ?></div>
            </div>
            <div style="margin-top: 12px;">
              <span class="result-team-tag"><i class="fa-solid fa-flag" style="margin-right: 4px; color: var(--accent);"></i> <?= e($res['team_name']) ?></span>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 4. WORKING COMMITTEE -->
    <section class="home-event-highlights section-wrap" id="committee">
      <header>
        <span class="overline">LEADERSHIP &amp; ORGANIZERS</span>
        <h2>Working Committee</h2>
      </header>

      <div class="home-committee-grid">
        <?php foreach ($workingCommittee as $member): ?>
          <article class="committee-card">
            <img src="<?= e($member['image']) ?>" alt="<?= e($member['name']) ?>" class="committee-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($member['name']) ?>&background=1b4332&color=fff&size=512'">
            <div class="committee-name"><?= e($member['name']) ?></div>
            <div class="committee-role"><?= e($member['role']) ?></div>
            <?php if (!empty($member['place'])): ?>
              <div class="committee-place"><i class="fa-solid fa-location-dot" style="margin-right: 3px;"></i> <?= e($member['place']) ?></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ABOUT MUSABAQA -->
    <section class="home-musabaqa-about section-wrap" id="about">
      <article class="home-about-copy">
        <span class="overline">ABOUT THE MUSABAQA</span>
        <h2>A stage for knowledge, discipline and sincere competition.</h2>
        <p>
          The Kauzariyya Musabaqa is an annual inter-class festival hosted by Al Jamiathul Kauzariyya, bringing together students across all years to compete in religious, literary, and academic programs.
        </p>
        <p>
          From recitation and Arabic composition to general knowledge and debate, every program is a testament to the dedication of our students and teachers.
        </p>
      </article>

      <article class="home-musabaqa-prayer">
        <div class="bismillah-mark">بِسْمِ اللهِ الرَّحْمٰنِ الرَّحِيْمِ</div>
        <p>
          May this gathering ignite beneficial knowledge, deep brotherhood, and lifelong sincerity in the hearts of all participants.
        </p>
      </article>
    </section>

    <!-- CAMPUS STORY & QURANIC VERSE -->
    <section class="home-story section-wrap">
      <figure class="home-story-visual">
        <img src="<?= asset_url('kauzariyya3.png') ?>" alt="Al Jamiathul Kauzariyya Campus at Twilight">
        <figcaption>Al Jamiathul Kauzariyya · Edathala</figcaption>
      </figure>

      <article class="home-story-content">
        <span class="home-story-kicker">MORE THAN A COMPETITION</span>
        <h2>Knowledge in action.<br>Character in every moment.</h2>
        <p class="home-story-copy">
          Designed to encourage healthy rivalry while building humility, confidence and public speaking skills across generations of students.
        </p>

        <blockquote class="home-story-verse">
          <div class="home-story-verse-head">
            <b>Qur'anic Inspiration</b>
            <span>Surah Al-Mutaffifin</span>
          </div>
          <p lang="ar">وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ</p>
          <footer>
            <span>"And for this let those specify who would strive to compete."</span>
            <cite>Surah 83 · Verse 26</cite>
          </footer>
        </blockquote>
      </article>
    </section>

    <!-- FESTIVAL ACCESS CARDS -->
    <section class="home-access section-wrap" id="access">
      <header>
        <div>
          <span class="overline">FESTIVAL ACCESS</span>
          <h2>Explore the platform</h2>
        </div>
        <span>Live updates, student directory, schedule blocks and community feedback.</span>
      </header>

      <div class="home-access-grid">
        <article class="feature-card">
          <div>
            <div class="feature-card-icon">
              <i class="fa-solid fa-trophy"></i>
            </div>
            <h3>Scoreboard</h3>
            <p>
              <?php if (!empty($teams)): ?>
                Leader: <strong><?= e($teams[0]['name']) ?></strong> (<?= (int)$teams[0]['score'] ?> pts) across <?= count($teams) ?> teams.
              <?php else: ?>
                Live team rankings, total points accumulated, and instant competition standings.
              <?php endif; ?>
            </p>
          </div>
          <a href="<?= app_url('/scoreboard') ?>" class="feature-card-link">View Scores <i class="fa-solid fa-arrow-right"></i></a>
        </article>

        <article class="feature-card">
          <div>
            <div class="feature-card-icon">
              <i class="fa-solid fa-calendar-days"></i>
            </div>
            <h3>Schedule</h3>
            <p>
              <?php if (!empty($schedule)): ?>
                <?= count($schedule) ?> programs scheduled. Real-time timeline of ongoing &amp; upcoming performances.
              <?php else: ?>
                Real-time timeline of ongoing and upcoming stage performances and events.
              <?php endif; ?>
            </p>
          </div>
          <a href="<?= app_url('/schedule') ?>" class="feature-card-link">View Timetable <i class="fa-solid fa-arrow-right"></i></a>
        </article>

        <article class="feature-card">
          <div>
            <div class="feature-card-icon">
              <i class="fa-solid fa-users"></i>
            </div>
            <h3>Participants</h3>
            <p>
              Complete directory of <?= e($candidatesCount) ?> registered competitors, chest numbers and categories.
            </p>
          </div>
          <a href="<?= app_url('/participants') ?>" class="feature-card-link">Explore Roster <i class="fa-solid fa-arrow-right"></i></a>
        </article>

        <article class="feature-card">
          <div>
            <div class="feature-card-icon">
              <i class="fa-solid fa-star"></i>
            </div>
            <h3>Reviews &amp; Results</h3>
            <p>
              <?php if (!empty($results)): ?>
                Latest: <strong><?= e($results[0]['program']) ?></strong> — <?= e($results[0]['participant']) ?> (<?= e($results[0]['team_name']) ?>)
              <?php else: ?>
                Feedback, visitor impressions, and official judge highlights from the festival.
              <?php endif; ?>
            </p>
          </div>
          <a href="<?= app_url('/review') ?>" class="feature-card-link">Read Feedback <i class="fa-solid fa-arrow-right"></i></a>
        </article>
      </div>
    </section>

    <!-- 3D SCHEDULE DECK -->
    <section class="home-schedule-3d-deck section-wrap" id="schedule">
      <header class="schedule-3d-head">
        <div>
          <span class="overline">SCHEDULE DETAILS</span>
          <h2>Information of Event Schedules</h2>
        </div>
        <a href="<?= app_url('/schedule') ?>" class="schedule-3d-action-btn">
          Explore Full Schedule <i class="fa-solid fa-arrow-right"></i>
        </a>
      </header>

      <div class="schedule-3d-grid">
        <?php foreach ($scheduleDeck as $deckCard): ?>
          <a href="<?= app_url('/schedule') ?>" class="schedule-3d-card">
            <header class="card-3d-head">
              <span class="card-3d-badge"><?= e($deckCard['badge']) ?></span>
              <span class="card-3d-pill"><?= e($deckCard['pill']) ?></span>
            </header>
            <div class="card-3d-body">
              <span class="card-3d-number"><?= e($deckCard['number']) ?></span>
              <div class="card-3d-date-meta">
                <strong><?= e($deckCard['month_year']) ?></strong>
                <small><?= e($deckCard['subtitle']) ?></small>
              </div>
            </div>
            <footer class="card-3d-foot">
              <span><?= e($deckCard['footer']) ?></span>
              <i class="fa-solid fa-arrow-right"></i>
            </footer>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- EVENT HIGHLIGHTS -->
    <section class="home-event-highlights section-wrap" id="highlights">
      <header>
        <span class="overline">EVENT HIGHLIGHTS</span>
        <h2>Where faith, knowledge and creativity take the stage.</h2>
      </header>

      <div>
        <article>
          <div class="highlight-card-head">
            <i class="fa-solid fa-book-quran"></i>
          </div>
          <h3>Qur’an &amp; Recitation</h3>
          <p>Celebrating memorisation, precise Tajweed and the beauty of Qur’anic recitation.</p>
        </article>

        <article>
          <div class="highlight-card-head">
            <i class="fa-solid fa-microphone-lines"></i>
          </div>
          <h3>Oratory &amp; Expression</h3>
          <p>Inspiring confident voices through thoughtful speeches, debates and presentations.</p>
        </article>

        <article>
          <div class="highlight-card-head">
            <i class="fa-solid fa-kaaba"></i>
          </div>
          <h3>Islamic Knowledge</h3>
          <p>Exploring the Qur’an, Seerah and Islamic heritage through engaging challenges.</p>
        </article>

        <article>
          <div class="highlight-card-head">
            <i class="fa-solid fa-feather-pointed"></i>
          </div>
          <h3>Language &amp; Literature</h3>
          <p>Showcasing imagination through Arabic, Malayalam and creative writing.</p>
        </article>
      </div>
    </section>

    <!-- LOCATION & COMMUNITY FOOTER -->
    <footer class="site-footer">
      <section class="home-footer-location">
        <div class="footer-location-map">
          <iframe
            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3928.2718105021235!2d76.37042571479483!3d10.073042992801452!2m3!1f0!0f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b0809b4d8ec11ab%3A0x6b4fb6c178f5ff60!2sAl%20Jamiathul%20Kauzariyya!5e0!3m2!1sen!2sin!4v1680000000000!5m2!1sen!2sin"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            title="Campus Location Map">
          </iframe>
        </div>

        <div class="footer-pulse-copy">
          <span class="overline">OUR PLACE · OUR COMMUNITY</span>
          <h2>Rooted in Edathala.</h2>
          <p>
            A welcoming campus in Aluva, fostering sacred knowledge, community unity, and lifelong guidance.
          </p>
          <a href="https://maps.google.com/?q=Al+Jamiathul+Kauzariyya+Edathala" target="_blank" rel="noopener" class="footer-directions-link">
            Open in Google Maps <i class="fa-solid fa-arrow-up-right-from-square"></i>
          </a>
        </div>
      </section>

      <div class="home-footer-identity">
        <h2>AL JAMIATHUL KAUZARIYYA</h2>
        <address>
          Edathala North P.O., Aluva, Ernakulam District, Kerala 683561, India
        </address>
      </div>

      <div class="simple-social-icons">
        <a href="https://instagram.com/kauzariyya" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
        <a href="https://youtube.com/@Kauzariyya" target="_blank" rel="noopener" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
        <a href="https://wa.me/" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
        <a href="https://facebook.com/Kauzariyya" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
      </div>

      <div class="home-footer-copyright">
        &copy; 2026 Al Jamiathul Kauzariyya · All rights reserved
      </div>
    </footer>

  </main>

</body>
</html>
