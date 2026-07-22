<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public-data.php';

$page = 'home';
$title = 'Thanafus 2026 | Kauzariyya Musabaqa';

$event = tv_active_event();
$eventStats = tv_stats();
$teams = teams();
$schedule = schedule_items();

$teamsCount = (int)($eventStats['teams'] ?? count($teams));
$programsCount = (int)($eventStats['programs'] ?? count($schedule));
$entriesCount = (int)($eventStats['entries'] ?? 0);
$completedCount = (int)($eventStats['completed_programs'] ?? 0);

$eventTitle = trim((string)($event['title'] ?? 'Kauzariyya Musabaqa 2026'));
$eventTitle = $eventTitle !== '' ? $eventTitle : 'Kauzariyya Musabaqa 2026';
$eventStart = !empty($event['start_date']) ? strtotime((string)$event['start_date']) : false;
$eventDate = $eventStart ? date('d F Y', $eventStart) : '2026 Season';

$focusProgram = null;
foreach ($schedule as $program) {
    if (($program['status'] ?? '') === 'live') {
        $focusProgram = $program;
        break;
    }
}
if ($focusProgram === null) {
    foreach ($schedule as $program) {
        if (($program['status'] ?? '') === 'upcoming') {
            $focusProgram = $program;
            break;
        }
    }
}
$focusProgram ??= $schedule[0] ?? null;

$programPreview = array_values(array_filter(
    $schedule,
    static fn(array $program): bool => ($program['status'] ?? '') !== 'completed'
));
if ($programPreview === []) {
    $programPreview = $schedule;
}
$programPreview = array_slice($programPreview, 0, 3);

$leaderboard = array_slice($teams, 0, 4);
$maxScore = max(array_map(
    static fn(array $team): float => (float)($team['score'] ?? 0),
    $leaderboard ?: [['score' => 0]]
));
$maxScore = max($maxScore, 1);

$safeTeamColor = static function (mixed $color): string {
    $color = trim((string)$color);
    return preg_match('/^#[0-9a-f]{3,8}$/i', $color) ? $color : '#7ee787';
};

require __DIR__ . '/includes/public-header.php';
?>

<div class="festival-home">
  <section class="festival-hero" id="top" aria-labelledby="hero-title">
    <div class="hero-media" aria-hidden="true">
      <img src="<?= asset_url('kauzariyya8.png') ?>" alt="" fetchpriority="high">
    </div>
    <div class="hero-glow hero-glow-one" aria-hidden="true"></div>
    <div class="hero-glow hero-glow-two" aria-hidden="true"></div>

    <div class="hero-layout section-shell">
      <div class="hero-main reveal">
        <div class="event-chip">
          <span class="event-chip-dot"></span>
          <span><?= e($eventDate) ?></span>
          <span class="event-chip-divider"></span>
          <span>Edathala, Kerala</span>
        </div>

        <img class="hero-wordmark" src="<?= asset_url('thanafus-logo.png') ?>" alt="Thanafus">
        <p class="hero-kicker">Al Jamiathul Kauzariyya presents</p>
        <h1 id="hero-title">Where talent<br><em>finds its stage.</em></h1>
        <p class="hero-lead">Follow every performance, point and proud moment of <?= e($eventTitle) ?> from one live festival companion.</p>

        <div class="hero-actions">
          <a class="home-button home-button-primary magnetic" href="scoreboard.php">
            <span>View live scores</span>
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
          </a>
          <a class="home-button home-button-secondary" href="schedule.php">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            <span>Explore schedule</span>
          </a>
        </div>

        <p class="hero-note"><span>Qur&rsquo;an</span><i></i><span>Oratory</span><i></i><span>Literature</span><i></i><span>Knowledge</span></p>
      </div>

      <aside class="live-desk reveal" aria-label="Live festival snapshot">
        <header class="live-desk-head">
          <div>
            <span class="live-indicator"><i></i> Live desk</span>
            <strong>Festival pulse</strong>
          </div>
          <span class="live-time" data-time>--:--</span>
        </header>

        <?php if ($focusProgram): ?>
          <div class="focus-program">
            <div class="focus-program-top">
              <span><?= ($focusProgram['status'] ?? '') === 'live' ? 'On stage now' : 'Coming up' ?></span>
              <time><?= e($focusProgram['start_time'] ?? '') ?></time>
            </div>
            <h2><?= e($focusProgram['title'] ?? 'Festival program') ?></h2>
            <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i><?= e($focusProgram['venue'] ?? 'Main Venue') ?></p>
          </div>
        <?php else: ?>
          <div class="focus-program focus-program-empty">
            <span>Festival central</span>
            <h2>The next program will appear here.</h2>
            <p>Schedules update as the event moves.</p>
          </div>
        <?php endif; ?>

        <div class="desk-leader">
          <span>Current leader</span>
          <?php if (!empty($leaderboard[0])): ?>
            <?php $leader = $leaderboard[0]; ?>
            <div class="desk-leader-row">
              <i style="--team-color:<?= e($safeTeamColor($leader['color'] ?? '')) ?>"></i>
              <strong><?= e($leader['name'] ?? 'Leading team') ?></strong>
              <b data-score="<?= (int)round((float)($leader['score'] ?? 0)) ?>">0</b>
              <small>pts</small>
            </div>
          <?php else: ?>
            <div class="desk-leader-row"><strong>Scores open soon</strong></div>
          <?php endif; ?>
        </div>

        <a class="desk-link" href="scoreboard.php">
          <span>Open full leaderboard</span>
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
      </aside>
    </div>

    <div class="hero-stats section-shell reveal" aria-label="Festival statistics">
      <div class="stat-item"><strong><?= e($teamsCount) ?></strong><span>Teams</span></div>
      <div class="stat-item"><strong><?= e($programsCount) ?></strong><span>Programs</span></div>
      <div class="stat-item"><strong><?= e($entriesCount) ?></strong><span>Entries</span></div>
      <div class="stat-item"><strong><?= e($completedCount) ?></strong><span>Results declared</span></div>
      <a class="stat-link" href="participants.php"><span>Find a participant</span><i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </section>

  <section class="home-section gateway-section" aria-labelledby="gateway-title">
    <div class="section-shell">
      <div class="home-section-heading reveal">
        <div>
          <p class="home-overline">Everything in one place</p>
          <h2 id="gateway-title">The festival,<br>at your fingertips.</h2>
        </div>
        <p>From the first call to stage to the final team ranking, stay close to every part of Thanafus.</p>
      </div>

      <div class="gateway-grid">
        <a class="gateway-card gateway-card-featured reveal" href="scoreboard.php">
          <span class="gateway-icon"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i></span>
          <div>
            <small>Live</small>
            <h3>Scoreboard</h3>
            <p>Team standings and point totals as results are approved.</p>
          </div>
          <span class="gateway-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
        </a>

        <a class="gateway-card reveal" href="schedule.php">
          <span class="gateway-icon"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
          <div>
            <small>Plan</small>
            <h3>Program schedule</h3>
            <p>Times, sessions and venues for every competition.</p>
          </div>
          <span class="gateway-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
        </a>

        <a class="gateway-card reveal" href="participants.php">
          <span class="gateway-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
          <div>
            <small>Discover</small>
            <h3>Participants</h3>
            <p>Find reporting times, programs and team details quickly.</p>
          </div>
          <span class="gateway-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
        </a>

        <a class="gateway-card gateway-card-photo reveal" href="<?= app_url('/tv') ?>">
          <span class="gateway-photo" aria-hidden="true"><img src="<?= asset_url('kauzariyya4.png') ?>" alt="" loading="lazy"></span>
          <span class="gateway-icon"><i class="fa-solid fa-tv" aria-hidden="true"></i></span>
          <div>
            <small>Broadcast</small>
            <h3>TV mode</h3>
            <p>Open the full-screen live festival display.</p>
          </div>
          <span class="gateway-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
        </a>
      </div>
    </div>
  </section>

  <section class="home-section pulse-section" aria-labelledby="pulse-title">
    <div class="section-shell pulse-layout">
      <div class="leaderboard-panel reveal">
        <div class="panel-heading">
          <div>
            <p class="home-overline">Team championship</p>
            <h2 id="pulse-title">The race is on.</h2>
          </div>
          <a href="scoreboard.php">All scores <i class="fa-solid fa-arrow-right"></i></a>
        </div>

        <div class="mini-leaderboard">
          <?php if ($leaderboard): ?>
            <?php foreach ($leaderboard as $index => $team): ?>
              <?php
                $score = (float)($team['score'] ?? 0);
                $progress = max(4, min(100, ($score / $maxScore) * 100));
              ?>
              <article class="mini-team">
                <span class="mini-rank"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <div class="mini-team-main">
                  <div><i style="--team-color:<?= e($safeTeamColor($team['color'] ?? '')) ?>"></i><strong><?= e($team['name'] ?? 'Team') ?></strong></div>
                  <span class="team-track"><i data-progress="<?= e(number_format($progress, 2, '.', '')) ?>" style="--team-color:<?= e($safeTeamColor($team['color'] ?? '')) ?>"></i></span>
                </div>
                <b><?= e(number_format($score, 0)) ?><small> pts</small></b>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="panel-empty">Team standings will appear as soon as scoring begins.</div>
          <?php endif; ?>
        </div>
      </div>

      <aside class="program-panel reveal" aria-labelledby="program-panel-title">
        <div class="panel-heading">
          <div>
            <p class="home-overline">Up next</p>
            <h2 id="program-panel-title">On the program.</h2>
          </div>
          <a href="schedule.php" aria-label="View the complete schedule"><i class="fa-solid fa-arrow-right"></i></a>
        </div>

        <div class="program-list">
          <?php if ($programPreview): ?>
            <?php foreach ($programPreview as $program): ?>
              <a class="program-item" href="schedule.php">
                <time><?= e($program['start_time'] ?? '--:--') ?></time>
                <span>
                  <strong><?= e($program['title'] ?? 'Festival program') ?></strong>
                  <small><?= e($program['venue'] ?? 'Main Venue') ?> &middot; <?= e($program['category'] ?? 'Open Category') ?></small>
                </span>
                <?php if (($program['status'] ?? '') === 'live'): ?><i class="program-live">Live</i><?php endif; ?>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="panel-empty">The next programs will be announced here.</div>
          <?php endif; ?>
        </div>

        <div class="program-note">
          <i class="fa-regular fa-clock" aria-hidden="true"></i>
          <p><strong>Stay on time.</strong><span>Check reporting details before your event.</span></p>
        </div>
      </aside>
    </div>
  </section>

  <section class="home-section story-section" aria-labelledby="story-title">
    <div class="section-shell story-layout">
      <figure class="story-photo reveal">
        <img src="<?= asset_url('kauzariyya8.png') ?>" alt="Kauzariyya campus illuminated for the evening" loading="lazy">
        <figcaption>Al Jamiathul Kauzariyya &middot; Edathala</figcaption>
      </figure>

      <div class="story-copy reveal">
        <p class="home-overline">More than a competition</p>
        <h2 id="story-title">Knowledge in action.<br>Character in every moment.</h2>
        <p>Thanafus brings recitation, scholarship, language, creativity and teamwork onto one stage&mdash;giving every student a chance to prepare deeply, perform bravely and grow together.</p>
        <blockquote>
          <p lang="ar" dir="rtl">وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ</p>
          <span>&ldquo;For this, let the competitors compete.&rdquo;</span>
          <cite>Qur&rsquo;an 83:26</cite>
        </blockquote>
      </div>
    </div>
  </section>

  <section class="home-cta">
    <div class="section-shell home-cta-inner reveal">
      <div>
        <p class="home-overline">Be part of the moment</p>
        <h2>Ready when the stage is.</h2>
        <p>Open the live display, follow the standings and celebrate every achievement.</p>
      </div>
      <div class="home-cta-actions">
        <a class="home-button home-button-primary" href="<?= app_url('/tv') ?>"><i class="fa-solid fa-play"></i><span>Launch TV mode</span></a>
        <a class="home-button home-button-secondary" href="review.php"><span>Share feedback</span><i class="fa-solid fa-arrow-right"></i></a>
      </div>
    </div>
  </section>
</div>

<script src="<?= asset_url('js/home.js') ?>?v=20260714" defer></script>

<?php require __DIR__ . '/includes/public-footer.php'; ?>
