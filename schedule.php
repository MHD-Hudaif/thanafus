<?php
require_once __DIR__ . '/includes/public-data.php';

$page = 'schedule';
$title = 'Program Schedule · Kauzariyya';

$items = schedule_items();
$sessions = [
    'morning' => 'Morning',
    'afternoon' => 'Afternoon',
    'evening' => 'Evening'
];

require __DIR__ . '/includes/public-header.php';
?>

<section class="schedule-head section-wrap reveal">
  <div>
    <p class="overline">One day · One stage</p>
    <h1>Full program<br /><em>schedule.</em></h1>
  </div>
  <p>All program times in one place. Please arrive at the reporting desk at least 15 minutes before your program begins.</p>
</section>

<div class="schedule-tabs" role="tablist">
  <?php foreach ($sessions as $key => $label): ?>
    <button type="button" class="<?= $key === 'morning' ? 'active' : '' ?>" data-session="<?= e($key) ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
</div>

<section class="schedule-grid section-wrap">
  <?php 
  $groupIndex = 0;
  foreach ($sessions as $key => $label): 
    $groupIndex++;
    $sessionItems = array_values(array_filter($items, fn($item) => $item['session'] === $key));
    $timeRange = '';
    if (!empty($sessionItems)) {
        $firstTime = $sessionItems[0]['start_time'];
        $lastTime = $sessionItems[count($sessionItems) - 1]['start_time'];
        $timeRange = $firstTime . ' - ' . $lastTime;
    }
  ?>
    <article class="schedule-column reveal <?= $key === 'morning' ? 'mobile-active' : '' ?>" data-session-column="<?= e($key) ?>">
      <header>
        <span>0<?= e($groupIndex) ?></span>
        <h2><?= e($label) ?></h2>
        <small><?= e($timeRange) ?></small>
      </header>
      
      <?php foreach ($sessionItems as $item): ?>
        <div class="schedule-row <?= $item['status'] === 'live' ? 'live' : '' ?>">
          <time><?= e($item['start_time']) ?></time>
          <div>
            <h3><?= e($item['title']) ?></h3>
            <p><?= e($item['category']) ?></p>
          </div>
          <small><?= e($item['duration_minutes']) ?> min</small>
        </div>
      <?php endforeach; ?>
      
      <?php if ($key === 'evening'): ?>
        <div class="venue-card">
          <strong>Main Auditorium</strong>
          <p>All listed programs take place on Stage One unless announced otherwise.</p>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<?php
require __DIR__ . '/includes/public-footer.php';
?>
