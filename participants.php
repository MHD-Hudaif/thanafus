<?php
require_once __DIR__ . '/includes/public-data.php';

$page = 'participants';
$title = 'Directory of Participants · Kauzariyya';

$people = participants();
$firstPerson = $people[0] ?? null;
$totalPeople = count($people);

require __DIR__ . '/includes/public-header.php';
?>

<section class="participant-shell section-wrap">
  <header class="participant-title reveal">
    <div>
      <p class="overline">Live program</p>
      <h1><?= e($firstPerson ? $firstPerson['program'] : 'Participants') ?></h1>
      <span><?= e($firstPerson ? $firstPerson['category'] : 'Festival') ?> · Main Auditorium</span>
    </div>
    <div class="clock-block">
      <strong data-time><?= date('H:i') ?></strong>
      <span><?= date('d F Y') ?></span>
    </div>
  </header>
  
  <div class="participant-layout">
    <article class="speaker-card reveal">
      <?php if ($firstPerson): ?>
        <span class="live-label"><i></i> Speaking now</span>
        <div>
          <p><?= e($firstPerson['program']) ?> · <?= e($firstPerson['category']) ?></p>
          <h2><?= e($firstPerson['name']) ?></h2>
        </div>
        <footer>
          <strong><?= e($firstPerson['reporting_time']) ?></strong>
          <span><?= e($firstPerson['team_name']) ?> · <?= e($firstPerson['code']) ?></span>
        </footer>
      <?php else: ?>
        <span class="live-label"><i></i> No participants</span>
        <div>
          <p>Arts Festival</p>
          <h2>No entries loaded</h2>
        </div>
        <footer>
          <strong>00:00</strong>
          <span>General info</span>
        </footer>
      <?php endif; ?>
    </article>
    
    <aside class="participant-list reveal">
      <div class="list-head">
        <h2>Participants</h2>
        <span><?= e($totalPeople) ?> speakers</span>
      </div>
      <form class="participant-search" role="search" onsubmit="event.preventDefault();">
        <input type="search" name="q" placeholder="Search name or ID" autocomplete="off" />
        <button type="button" aria-label="Search">⌕</button>
      </form>
      <div data-participant-results>
        <?php foreach ($people as $index => $person): ?>
          <button class="participant-row <?= $index === 0 ? 'active' : '' ?>" type="button" data-name="<?= e(strtolower($person['name'] . ' ' . $person['code'])) ?>">
            <time><?= e($person['reporting_time']) ?></time>
            <span>
              <strong><?= e($person['name']) ?></strong>
              <small><?= e($person['team_name']) ?> · <?= e($person['code']) ?></small>
            </span>
            <b><?= e(str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)) ?></b>
          </button>
        <?php endforeach; ?>
      </div>
      <p class="reporting-note">Reporting now: <strong>All festival participants</strong></p>
    </aside>
  </div>
</section>

<?php
require __DIR__ . '/includes/public-footer.php';
?>
