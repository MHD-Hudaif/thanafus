<?php
require_once __DIR__ . '/includes/public-data.php';

$page = 'schedule';
$title = 'Program Schedule · Kauzariyya';

$items = schedule_items();
$sessions = schedule_sections();
$firstKey = !empty($sessions) ? array_key_first($sessions) : '';

require __DIR__ . '/includes/public-header.php';
?>

<section class="schedule-minimal-hero section-wrap">
  <div class="minimal-badge"><i class="fa-solid fa-calendar-days"></i> Program Timeline</div>
  <h1>Event Schedule</h1>
  <p>Track all live and upcoming program sessions in real time.</p>
</section>

<div class="schedule-minimal-nav section-wrap" role="tablist">
  <button type="button" class="minimal-tab active" data-session="all">All Sessions</button>
  <?php foreach ($sessions as $key => $label): ?>
    <button type="button" class="minimal-tab" data-session="<?= e($key) ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
</div>

<section class="schedule-minimal-timeline section-wrap">
  <div class="timeline-list">
    <?php foreach ($items as $index => $item): 
      $isLive = ($item['status'] ?? '') === 'live' || ($item['status'] ?? '') === 'scoring';
      $isDone = ($item['status'] ?? '') === 'completed';
      $statusClass = $isLive ? 'status-live' : ($isDone ? 'status-completed' : 'status-upcoming');
      $statusLabel = $isLive ? 'LIVE NOW' : ($isDone ? 'COMPLETED' : 'UPCOMING');
    ?>
      <div class="timeline-row <?= $statusClass ?>" data-session-row="<?= e($item['session'] ?? 'morning') ?>">
        <div class="timeline-time">
          <span class="time-main"><?= e($item['start_time']) ?></span>
          <?php if (!empty($item['date'])): ?>
            <span class="time-date" style="display:block; font-size: 0.85em; opacity: 0.8; margin-top: 4px; margin-bottom: 4px;"><i class="fa-regular fa-calendar" style="margin-right: 4px;"></i><?= e($item['date']) ?></span>
          <?php endif; ?>
          <span class="time-duration"><?= e($item['duration_minutes']) ?> mins</span>
        </div>

        <div class="timeline-dot"></div>

        <div class="timeline-body">
          <div class="timeline-head">
            <h3 class="timeline-title"><?= e($item['title']) ?></h3>
            <span class="timeline-status <?= $statusClass ?>"><?= $statusLabel ?></span>
          </div>
          <?php if (!empty($item['is_stacked'])): ?>
            <div class="timeline-stacked" style="display:flex; flex-direction:column; gap:8px; margin-top:12px;">
              <?php foreach ($item['stacked_programs'] as $sp): ?>
                <div class="stacked-item" style="background:rgba(0,0,0,0.03); padding:8px 12px; border-radius:8px; border-left:3px solid var(--primary);">
                  <strong style="display:block; font-size:0.95em; margin-bottom:4px;"><?= e($sp['title']) ?></strong>
                  <span style="font-size:0.8em; opacity:0.8; margin-right:8px;"><i class="fa-solid fa-layer-group"></i> <?= e($sp['category']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="timeline-meta">
              <span class="meta-pill meta-category"><i class="fa-solid fa-layer-group"></i> <?= e($item['category'] ?? 'General') ?></span>
              <?php if (!empty($item['venue'])): ?>
                <span class="meta-pill meta-stage"><i class="fa-solid fa-location-dot"></i> <?= e($item['venue']) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const tabs = document.querySelectorAll('.minimal-tab');
  const rows = document.querySelectorAll('[data-session-row]');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      const targetSession = tab.getAttribute('data-session');
      rows.forEach(row => {
        if (targetSession === 'all' || row.getAttribute('data-session-row') === targetSession) {
          row.style.display = 'flex';
        } else {
          row.style.display = 'none';
        }
      });
    });
  });
});
</script>

<?php
require __DIR__ . '/includes/public-footer.php';
?>
