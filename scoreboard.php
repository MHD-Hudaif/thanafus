<?php
require_once __DIR__ . '/includes/public-data.php';

$page = 'scoreboard';
$title = 'Live Scoreboard · Kauzariyya';

$ordered = teams();
$leader = $ordered[0] ?? null;
$maxScore = 1;
if (!empty($ordered)) {
    $maxScore = max(array_column($ordered, 'score') ?: [1]);
    if ($maxScore <= 0) {
        $maxScore = 1;
    }
}

require __DIR__ . '/includes/public-header.php';
?>

<style>
.standing-row {
  background: linear-gradient(135deg, rgba(255,255,255,0.04) 0%, rgba(255,255,255,0.01) 60%, color-mix(in srgb, var(--team) 15%, transparent) 100%) !important;
  border-left: 4px solid var(--team) !important;
  transition: all 0.3s ease;
}

.standing-row span i {
  background: linear-gradient(135deg, var(--team) 0%, color-mix(in srgb, var(--team) 75%, white) 50%, var(--team) 100%) !important;
  box-shadow: 0 0 12px color-mix(in srgb, var(--team) 45%, transparent) !important;
}

.score-leader {
  background: linear-gradient(135deg, color-mix(in srgb, var(--team) 28%, #0d1520) 0%, #0d1520 60%, color-mix(in srgb, var(--team) 15%, #0d1520) 100%) !important;
  border: 1px solid color-mix(in srgb, var(--team) 40%, transparent) !important;
  box-shadow: 0 15px 40px -10px color-mix(in srgb, var(--team) 30%, transparent) !important;
}
</style>

<section class="scoreboard-dashboard scoreboard-modern section-wrap" data-refresh="scoreboard">
  <div class="scoreboard-watermark" aria-hidden="true">
    <img src="<?= asset_url('thanafus-logo.png') ?>" alt="" />
  </div>
  <div class="scoreboard-heading reveal">
    <div>
      <p class="overline">Live · Verified results</p>
      <h1>Team<br /><em>standings.</em></h1>
    </div>
    <p>Follow every team as verified marks arrive from the judging panel.</p>
  </div>
  <div class="scoreboard-layout">
    <?php if ($leader): ?>
      <article class="score-leader reveal" style="--team: <?= e($leader['color']) ?>;">
        <span>Current leader</span>
        <b>01</b>
        <h2><?= e($leader['name']) ?></h2>
        <strong><?= e(round($leader['score'])) ?></strong>
        <small>verified marks</small>
      </article>
    <?php endif; ?>
    
    <div class="standing-list" aria-label="Live team standings">
      <?php foreach ($ordered as $index => $team): ?>
        <?php $pct = ($team['score'] / $maxScore) * 100; ?>
        <article class="standing-row reveal" style="--team: <?= e($team['color']) ?>;">
          <b><?= e(str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)) ?></b>
          <div>
            <h2><?= e($team['name']) ?></h2>
            <span><i style="width: <?= e($pct) ?>%"></i></span>
          </div>
          <strong><?= e(round($team['score'])) ?></strong>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<div class="score-note section-wrap">
  <span>Scores verified by the judging panel</span>
  <span>Last updated <time data-clock><?= date('H:i:s') ?></time></span>
</div>

<?php
require __DIR__ . '/includes/public-footer.php';
?>
