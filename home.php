<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/public-data.php';

$page  = 'home';
$title = 'Kauzariyya Musabaqa · Al Jamiathul Kauzariyya';

// ── Data ────────────────────────────────────────────────────────────────────
$event        = tv_active_event();
$eventId      = tv_active_event_id();
$teamsList    = teams();
$leader       = $teamsList[0] ?? null;
$maxScore     = max(1, ...array_column($teamsList ?: [['score' => 1]], 'score'));

$scheduleItems = schedule_items();
$upcomingItems = array_values(array_filter($scheduleItems, fn($i) => $i['status'] !== 'completed'));
$liveProgram   = array_values(array_filter($scheduleItems, fn($i) => $i['status'] === 'live'))[0] ?? null;
$focusProgram  = $liveProgram ?? ($upcomingItems[0] ?? null);

$dateLabel     = $event ? date('d F Y', strtotime($event['start_date'] ?? 'now')) : '12 July 2026';
$teamCount     = count($teamsList);
$programCount  = count($scheduleItems);
$participantCount = 0;
try {
    if ($eventId > 0) {
        $stmt = $GLOBALS['musabaqa_pdo']->prepare(
            "SELECT COUNT(DISTINCT em.team_member_id) FROM musabaqa_entry_members em
             JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
             WHERE pe.event_id = ?"
        );
        $stmt->execute([$eventId]);
        $participantCount = (int)$stmt->fetchColumn();
    }
} catch (Throwable) {}

require __DIR__ . '/includes/public-header.php';
?>

<div class="festival-home">

  <!-- ═══════════════════════════════════════════════════════════ HERO ═══ -->
  <section class="festival-hero">

    <div class="hero-media">
      <img src="<?= asset_url('images/kauzariyya8.png') ?>"
           alt="Kauzariyya festival" loading="eager" fetchpriority="high">
    </div>

    <div class="hero-glow hero-glow-one" aria-hidden="true"></div>
    <div class="hero-glow hero-glow-two" aria-hidden="true"></div>

    <div class="section-shell">
      <div class="hero-layout">

        <!-- Left: main copy -->
        <div class="hero-main">
          <div class="event-chip">
            <span class="event-chip-dot"></span>
            <span>Live Event</span>
            <span class="event-chip-divider"></span>
            <span><?= e($dateLabel) ?></span>
          </div>

          <img class="hero-wordmark"
               src="<?= asset_url('kauzariyya-logo.png') ?>"
               alt="Kauzariyya">

          <p class="hero-kicker">Al Jamiathul Kauzariyya · Annual Musabaqa</p>

          <h1>The <em>Grand</em><br>Festival.</h1>

          <p class="hero-lead">
            Follow live scores, the full program schedule, and team standings
            as the event unfolds — all in one place.
          </p>

          <div class="hero-actions">
            <a href="<?= url('scoreboard') ?>" class="home-button home-button-primary">
              <i class="fa-solid fa-chart-bar"></i> Live Scoreboard
            </a>
            <a href="<?= url('schedule') ?>" class="home-button home-button-secondary">
              <i class="fa-regular fa-calendar"></i> Full Schedule
            </a>
          </div>

          <p class="hero-note">
            <i></i> Results verified by judges
            <i></i> Updated in real time
          </p>
        </div>

        <!-- Right: live desk -->
        <aside class="live-desk">
          <div class="live-desk-head">
            <div>
              <span class="live-indicator">
                <i></i> Live
              </span>
              <strong>Event Desk</strong>
            </div>
            <time class="live-time" data-clock><?= date('H:i') ?></time>
          </div>

          <!-- Focus program -->
          <div class="focus-program <?= $focusProgram ? '' : 'focus-program-empty' ?>">
            <?php if ($focusProgram): ?>
              <div class="focus-program-top">
                <span><?= $liveProgram ? 'Now Live' : 'Up Next' ?></span>
                <span><?= e($focusProgram['venue']) ?></span>
              </div>
              <h2><?= e($focusProgram['title']) ?></h2>
              <p>
                <i class="fa-regular fa-clock"></i>
                <?= e($focusProgram['start_time']) ?> · <?= e($focusProgram['duration_minutes']) ?> min
                · <?= e($focusProgram['category']) ?>
              </p>
            <?php else: ?>
              <div class="focus-program-top"><span>Programs</span></div>
              <h2>Schedule to be announced</h2>
              <p><i class="fa-regular fa-calendar"></i> Check back soon</p>
            <?php endif; ?>
          </div>

          <!-- Leading team -->
          <?php if ($leader): ?>
          <div class="desk-leader">
            <span>Current Leader</span>
            <div class="desk-leader-row" style="--team-color: <?= e($leader['color']) ?>">
              <i></i>
              <strong><?= e($leader['name']) ?></strong>
              <b><?= e(round($leader['score'])) ?></b>
              <small>pts</small>
            </div>
          </div>
          <?php endif; ?>

          <a href="<?= url('scoreboard') ?>" class="desk-link">
            <span>Full standings</span>
            <i class="fa-solid fa-arrow-right"></i>
          </a>
        </aside>

      </div><!-- /.hero-layout -->
    </div><!-- /.section-shell -->

    <!-- Stats bar -->
    <div class="hero-stats">
      <div class="stat-item">
        <strong><?= $teamCount ?: '—' ?></strong>
        <span>Teams</span>
      </div>
      <div class="stat-item">
        <strong><?= $programCount ?: '—' ?></strong>
        <span>Programs</span>
      </div>
      <div class="stat-item">
        <strong><?= $participantCount ?: '—' ?></strong>
        <span>Participants</span>
      </div>
      <div class="stat-item">
        <strong><?= $event ? '1' : '—' ?></strong>
        <span>Active Event</span>
      </div>
      <a href="<?= url('participants') ?>" class="stat-link">
        View all participants <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

  </section><!-- /.festival-hero -->

  <!-- ══════════════════════════════════════════════════════ GATEWAY ═══ -->
  <section class="home-section gateway-section">
    <div class="section-shell">

      <div class="home-section-heading">
        <div>
          <p class="home-overline">Explore</p>
          <h2>Everything<br><em>in one place.</em></h2>
        </div>
        <p>Access live scores, program timings, participant lists, and results — all updated as the event progresses.</p>
      </div>

      <div class="gateway-grid">

        <a href="<?= url('scoreboard') ?>" class="gateway-card gateway-card-featured">
          <div class="gateway-icon"><i class="fa-solid fa-ranking-star"></i></div>
          <div>
            <small>Live · Updated</small>
            <h3>Team<br>Scoreboard</h3>
            <p>Follow every team's verified marks as results arrive from the judging panel in real time.</p>
          </div>
          <div class="gateway-arrow"><i class="fa-solid fa-arrow-up-right"></i></div>
        </a>

        <a href="<?= url('schedule') ?>" class="gateway-card">
          <div class="gateway-icon"><i class="fa-regular fa-calendar-days"></i></div>
          <div>
            <small>Full day</small>
            <h3>Program<br>Schedule</h3>
            <p>All programs with start times, categories, and stages — organised by session.</p>
          </div>
          <div class="gateway-arrow"><i class="fa-solid fa-arrow-up-right"></i></div>
        </a>

        <a href="<?= url('participants') ?>" class="gateway-card gateway-card-photo">
          <div class="gateway-photo">
            <img src="<?= asset_url('images/kauzariyya4.png') ?>" alt="">
          </div>
          <div class="gateway-icon"><i class="fa-solid fa-users"></i></div>
          <div>
            <small>All entries</small>
            <h3>Participants<br>List</h3>
            <p>Search by name or chest number and find reporting times for every contestant.</p>
          </div>
          <div class="gateway-arrow"><i class="fa-solid fa-arrow-up-right"></i></div>
        </a>

        <a href="<?= url('review') ?>" class="gateway-card">
          <div class="gateway-icon"><i class="fa-regular fa-star"></i></div>
          <div>
            <small>Share your thoughts</small>
            <h3>Leave a<br>Review</h3>
            <p>Rate the event and share feedback. Your response helps us improve future editions.</p>
          </div>
          <div class="gateway-arrow"><i class="fa-solid fa-arrow-up-right"></i></div>
        </a>

      </div>
    </div>
  </section>

  <!-- ═══════════════════════════════════════════════════════ PULSE ═══ -->
  <section class="home-section pulse-section">
    <div class="section-shell">
      <div class="pulse-layout">

        <!-- Leaderboard panel -->
        <div class="leaderboard-panel">
          <div class="panel-heading">
            <div>
              <p class="home-overline">Standings</p>
              <h2>Team<br>scores.</h2>
            </div>
            <a href="<?= url('scoreboard') ?>">
              Full board <i class="fa-solid fa-arrow-right"></i>
            </a>
          </div>

          <div class="mini-leaderboard">
            <?php if (empty($teamsList)): ?>
              <p class="panel-empty">No scores recorded yet. Check back once the event begins.</p>
            <?php else: ?>
              <?php foreach (array_slice($teamsList, 0, 5) as $index => $team):
                $pct = $maxScore > 0 ? round(($team['score'] / $maxScore) * 100) : 0;
              ?>
              <div class="mini-team" style="--team-color: <?= e($team['color']) ?>">
                <span class="mini-rank"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <div class="mini-team-main">
                  <div>
                    <i></i>
                    <strong><?= e($team['name']) ?></strong>
                  </div>
                  <span class="team-track">
                    <i data-progress="<?= e($pct) ?>"></i>
                  </span>
                </div>
                <b><?= e(round($team['score'])) ?><small> pts</small></b>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Program panel -->
        <div class="program-panel">
          <div class="panel-heading">
            <div>
              <p class="home-overline">Schedule</p>
              <h2>Today's<br>programs.</h2>
            </div>
            <a href="<?= url('schedule') ?>"><i class="fa-solid fa-arrow-right"></i></a>
          </div>

          <div class="program-list">
            <?php
            $displayItems = array_slice(
                array_filter($scheduleItems, fn($i) => $i['status'] !== 'completed'),
                0, 5
            );
            if (empty($displayItems)):
            ?>
              <p class="panel-empty">No upcoming programs at this time.</p>
            <?php else: ?>
              <?php foreach ($displayItems as $item): ?>
              <div class="program-item">
                <time><?= e($item['start_time']) ?></time>
                <span>
                  <strong><?= e($item['title']) ?></strong>
                  <small><?= e($item['category']) ?> · <?= e($item['venue']) ?></small>
                </span>
                <?php if ($item['status'] === 'live'): ?>
                  <em class="program-live">Live</em>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="program-note">
            <i class="fa-solid fa-circle-info"></i>
            <p>
              Please arrive at the reporting desk at least 15 minutes before your program.
              <span>Venue: Main Auditorium unless announced otherwise.</span>
            </p>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════ STORY ═══ -->
  <section class="home-section story-section">
    <div class="section-shell">
      <div class="story-layout">

        <figure class="story-photo">
          <img src="<?= asset_url('images/kauzariyya3.png') ?>" alt="Kauzariyya">
          <figcaption>Al Jamiathul Kauzariyya</figcaption>
        </figure>

        <div class="story-copy">
          <p class="home-overline">About the event</p>
          <h2>A celebration of<br><em>knowledge &amp; faith.</em></h2>
          <p>
            The Kauzariyya Musabaqa is an annual inter-class festival hosted by
            Al Jamiathul Kauzariyya, bringing together students across all years
            to compete in religious, literary, and academic programs.
          </p>
          <p>
            From recitation and Arabic composition to general knowledge and
            debate, every program is a testament to the dedication of our
            students and teachers.
          </p>
          <a href="<?= url('schedule') ?>" class="home-button home-button-primary" style="margin-top: 28px; text-decoration: none;">
            <i class="fa-regular fa-calendar-days"></i> View Full Schedule
          </a>
        </div>

      </div>
    </div>
  </section>

</div><!-- /.festival-home -->

<?php
require __DIR__ . '/includes/public-footer.php';
?>
