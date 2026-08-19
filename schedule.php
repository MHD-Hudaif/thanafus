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

.schedule-phase {
  margin-top: 36px;
}

.schedule-phase:first-child {
  margin-top: 0;
}

.schedule-phase-heading {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0 0 16px;
}

.schedule-phase-heading i {
  width: 38px;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: rgba(16, 185, 129, 0.12);
  color: #059669;
}

.schedule-phase-upcoming .schedule-phase-heading i {
  background: rgba(99, 102, 241, 0.12);
  color: var(--primary, #6366f1);
}

.schedule-phase-heading h2 {
  margin: 0;
  color: var(--text-heading, #0f172a);
  font-size: 1.2rem;
  font-weight: 800;
}

.schedule-phase-heading p {
  margin: 2px 0 0;
  color: #64748b;
  font-size: 0.84rem;
}

.schedule-phase-empty {
  color: #64748b;
  background: rgba(248, 250, 252, 0.8);
  border: 1px dashed rgba(148, 163, 184, 0.55);
  border-radius: 12px;
  padding: 18px 20px;
  font-size: 0.9rem;
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

.result-name {
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 680px) {
  .timeline-row-3col {
    gap: 12px;
    padding: 14px;
  }

  .timeline-col-left {
    flex-basis: auto;
  }

  .timeline-col-middle {
    align-items: flex-end;
    flex: 0 1 auto;
  }

  .daily-prog-num,
  .prog-info-meta {
    display: none;
  }

  .schedule-rank-tag {
    font-size: 0.72rem;
  }
}
</style>

<section class="schedule-minimal-timeline section-wrap">
  <?php
  $completedItems = array_values(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'completed'));
  $upcomingItems = array_values(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') !== 'completed'));
  ?>

  <section class="schedule-phase schedule-phase-completed" data-schedule-phase="completed">
    <header class="schedule-phase-heading">
      <i class="fa-solid fa-trophy"></i>
      <div><h2>Phase 1 — Completed Programs</h2><p>Final ranks and results</p></div>
    </header>
    <div class="timeline-list">
      <?php if (empty($completedItems)): ?>
        <div class="schedule-phase-empty">Completed program results will appear here.</div>
      <?php endif; ?>
      <?php foreach ($completedItems as $item): ?>
        <div class="timeline-row-3col status-completed" data-session-row="<?= e($item['session'] ?? 'morning') ?>">
          <div class="timeline-col-left">
            <div class="prog-info-wrap"><h3 class="prog-info-title"><?= e($item['title']) ?></h3></div>
          </div>
          <div class="timeline-col-middle">
            <?php $validResults = array_values(array_filter($item['results'] ?? [], static fn(array $result): bool => $result['final_score'] !== null && $result['final_score'] !== '')); ?>
            <?php if (!empty($validResults)): ?>
              <div class="ranks-badge-list">
                <?php foreach ($validResults as $res): ?>
                  <?php $rankVal = (int)($res['rank'] ?? 0); $ord = match($rankVal) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' }; ?>
                  <span class="schedule-rank-tag schedule-rank-tag-<?= $rankVal ?>" style="background: <?= e($res['team_color'] ?? '#3b82f6') ?>;">
                    <strong><?= $rankVal ?><?= $ord ?></strong><span class="result-name"><?= e($res['entry_name'] ?: $res['team_name']) ?></span><span><?= e(round((float)$res['final_score'])) ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span style="font-size: 0.8em; color: #94a3b8;">Results pending</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="schedule-phase schedule-phase-upcoming" data-schedule-phase="upcoming">
    <header class="schedule-phase-heading">
      <i class="fa-solid fa-calendar-clock"></i>
      <div><h2>Phase 2 — Upcoming Programs</h2><p>Scheduled program times</p></div>
    </header>
    <div class="timeline-list">
      <?php if (empty($upcomingItems)): ?>
        <div class="schedule-phase-empty">No upcoming programs are scheduled.</div>
      <?php endif; ?>
      <?php foreach ($upcomingItems as $item): ?>
        <div class="timeline-row-3col status-upcoming" data-session-row="<?= e($item['session'] ?? 'morning') ?>">
          <div class="timeline-col-left"><div class="prog-info-wrap"><h3 class="prog-info-title"><?= e($item['title']) ?></h3></div></div>
          <div class="timeline-col-right"><div class="time-big"><i class="fa-regular fa-clock mr-1" style="font-size: 0.85em; opacity: 0.7;"></i><?= e($item['start_time']) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const tabs = document.querySelectorAll('.minimal-tab');
  const rows = document.querySelectorAll('[data-session-row]');
  const phases = document.querySelectorAll('[data-schedule-phase]');

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

      phases.forEach(phase => {
        const visibleRows = Array.from(phase.querySelectorAll('[data-session-row]'))
          .some(row => row.style.display !== 'none');
        phase.style.display = visibleRows || !phase.querySelector('[data-session-row]') ? '' : 'none';
      });
    });
  });
});
</script>

<?php
require __DIR__ . '/includes/public-footer.php';
?>
