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

<style>
.schedule-day-group-title {
  background: rgba(15, 23, 42, 0.05);
  border-left: 4px solid var(--primary, #6366f1);
  padding: 12px 20px;
  margin: 32px 0 16px 0;
  border-radius: 8px;
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--text-heading, #0f172a);
  display: flex;
  align-items: center;
  gap: 10px;
}

.timeline-row-3col {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  background: #ffffff;
  border: 1px solid rgba(226, 232, 240, 0.8);
  border-radius: 12px;
  padding: 16px 20px;
  margin-bottom: 12px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
  transition: all 0.2s ease;
}

.timeline-row-3col:hover {
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
  border-color: rgba(99, 102, 241, 0.3);
}

.timeline-col-left {
  display: flex;
  align-items: center;
  gap: 16px;
  flex: 1 1 35%;
  min-width: 0;
}

.daily-prog-num {
  background: #0f172a;
  color: #38bdf8;
  font-weight: 900;
  font-size: 1.1rem;
  padding: 6px 14px;
  border-radius: 8px;
  flex-shrink: 0;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.1);
}

.prog-info-wrap {
  min-width: 0;
}

.prog-info-title {
  font-size: 1.05rem;
  font-weight: 800;
  margin: 0 0 4px 0;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.prog-info-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 0.8rem;
  color: #64748b;
}

.timeline-col-middle {
  flex: 1 1 40%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-align: center;
}

.ranks-badge-list {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: center;
}

.schedule-rank-tag {
  font-size: 0.78rem;
  font-weight: 800;
  padding: 3px 10px;
  border-radius: 20px;
  color: #ffffff;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.schedule-rank-tag-1 { background: #10b981; }
.schedule-rank-tag-2 { background: #f59e0b; color: #000; }
.schedule-rank-tag-3 { background: #3b82f6; }

.grade-a-pill {
  background: rgba(245, 158, 11, 0.12);
  color: #d97706;
  border: 1px solid rgba(245, 158, 11, 0.3);
  font-size: 0.78rem;
  font-weight: 800;
  padding: 3px 10px;
  border-radius: 20px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.timeline-col-right {
  flex: 0 0 auto;
  text-align: right;
  margin-left: auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.time-big {
  font-size: 1.1rem;
  font-weight: 800;
  color: #0f172a;
}
</style>

<section class="schedule-minimal-timeline section-wrap">
  <div class="timeline-list">
    <?php 
    $lastDayDate = null;
    $dayIndex = 0;
    foreach ($items as $index => $item): 
      $isLive = ($item['status'] ?? '') === 'live' || ($item['status'] ?? '') === 'scoring';
      $isDone = ($item['status'] ?? '') === 'completed';
      $statusClass = $isLive ? 'status-live' : ($isDone ? 'status-completed' : 'status-upcoming');
      $statusLabel = $isLive ? 'LIVE NOW' : ($isDone ? 'COMPLETED' : 'UPCOMING');

      $currentDayDate = $item['date'] ?? '';
      if ($currentDayDate !== $lastDayDate):
        $lastDayDate = $currentDayDate;
        $dayIndex++;
    ?>
        <div class="schedule-day-group-title">
          <i class="fa-solid fa-calendar-day" style="color: var(--primary, #6366f1);"></i>
          Day <?= $dayIndex ?> — <?= e($currentDayDate ?: 'Event Schedule') ?>
        </div>
      <?php endif; ?>

      <div class="timeline-row-3col <?= $statusClass ?>" data-session-row="<?= e($item['session'] ?? 'morning') ?>">
        <!-- LEFT COLUMN: Program #, Title, Meta -->
        <div class="timeline-col-left">
          <div class="daily-prog-num" title="Program #<?= (int)($item['daily_program_no'] ?? 1) ?> of Day <?= $dayIndex ?>">
            #<?= (int)($item['daily_program_no'] ?? 1) ?>
          </div>
          <div class="prog-info-wrap">
            <h3 class="prog-info-title"><?= e($item['title']) ?></h3>
            <div class="prog-info-meta">
              <span class="meta-pill"><i class="fa-solid fa-layer-group"></i> <?= e($item['category'] ?? 'General') ?></span>
              <?php if (!empty($item['venue'])): ?>
                <span class="meta-pill"><i class="fa-solid fa-location-dot"></i> <?= e($item['venue']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- MIDDLE COLUMN: 1st, 2nd, 3rd Scores -->
        <div class="timeline-col-middle">
          <?php if (!empty($item['results'])): ?>
            <div class="ranks-badge-list">
              <?php foreach ($item['results'] as $res): ?>
                <?php
                $rankVal = (int)($res['rank'] ?? 0);
                $ord = match($rankVal) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
                ?>
                <span class="schedule-rank-tag schedule-rank-tag-<?= $rankVal ?>" style="background: <?= e($res['team_color'] ?? '#3b82f6') ?>;">
                  <strong><?= $rankVal ?><?= $ord ?>:</strong> <?= $res['final_score'] !== null ? e(round($res['final_score'])) : '—' ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (empty($item['results'])): ?>
            <span style="font-size: 0.8em; color: #94a3b8;">—</span>
          <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: Time & Status -->
        <div class="timeline-col-right">
          <div class="time-big"><i class="fa-regular fa-clock mr-1" style="font-size: 0.85em; opacity: 0.7;"></i><?= e($item['start_time']) ?></div>
          <div style="font-size: 0.8em; opacity: 0.8; margin-top: 2px;">
            <span class="timeline-status <?= $statusClass ?>"><?= $statusLabel ?></span>
            <span style="margin-left: 6px;"><?= e($item['duration_minutes']) ?>m</span>
          </div>
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
