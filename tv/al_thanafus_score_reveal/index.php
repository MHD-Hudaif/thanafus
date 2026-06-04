<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/reveal.php';

$initialEvent = reveal_best_event();
$initialState = $initialEvent ? reveal_leaderboard_snapshot((int) $initialEvent['id']) : [
    'event' => reveal_event_summary(null),
    'teams' => [],
    'leaderboard' => [],
    'completion' => ['approved_programs' => 0, 'total_programs' => 0, 'percentage' => 0],
    'latest_log_id' => 0,
    'program_count' => 0,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Al-Thanafus | Score Approval Reveal</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.__INITIAL_STATE__ = <?= json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>
<body>
    <div class="app-shell">
        <canvas id="particle-canvas" aria-hidden="true"></canvas>

        <div class="ambient ambient-a"></div>
        <div class="ambient ambient-b"></div>
        <div class="ambient ambient-c"></div>

        <main class="stage">
            <header class="topbar">
                <div class="brand-block">
                    <div class="brand-kicker">Al-Thanafus</div>
                    <h1 id="eventTitle" class="brand-title">Score Approval Reveal</h1>
                    <p id="eventSubtitle" class="brand-subtitle">Ceremonial leaderboard transformation system</p>
                </div>

                <div class="status-group">
                    <div class="status-pill" id="connectionStatus">LIVE</div>
                    <div class="status-pill muted" id="approvalState">Waiting for approved scores</div>
                </div>
            </header>

            <section class="arena" id="arena">
                <div class="orb-stage">
                    <svg class="orb-ring" viewBox="0 0 200 200" aria-hidden="true">
                        <defs>
                            <filter id="orbGlow">
                                <feGaussianBlur stdDeviation="4" result="blur"></feGaussianBlur>
                                <feColorMatrix in="blur" type="matrix"
                                    values="1 0 0 0 0
                                            0 1 0 0 0
                                            0 0 1 0 0
                                            0 0 0 18 -8"></feColorMatrix>
                                <feMerge>
                                    <feMergeNode></feMergeNode>
                                    <feMergeNode in="SourceGraphic"></feMergeNode>
                                </feMerge>
                            </filter>
                        </defs>
                        <circle class="orb-track" cx="100" cy="100" r="76"></circle>
                        <circle class="orb-progress" cx="100" cy="100" r="76" id="orbProgress"></circle>
                    </svg>

                    <div class="orb-core">
                        <div class="orb-kicker">Competition Heartbeat</div>
                        <div class="orb-percent" id="orbPercent">0%</div>
                        <div class="orb-counts">
                            <span id="approvedCount">0</span>
                            <span class="sep">/</span>
                            <span id="totalCount">0</span>
                            <span class="suffix">Programs</span>
                        </div>
                    </div>

                    <div class="orb-bloom orb-bloom-1"></div>
                    <div class="orb-bloom orb-bloom-2"></div>
                </div>

                <div class="team-card" id="teamCardTop" data-slot="top">
                    <div class="diamond"></div>
                    <div class="team-face">
                        <div class="team-meta">
                            <div class="rank-chip" id="rankTop">—</div>
                            <div class="team-name" id="nameTop">—</div>
                            <div class="team-sub" id="subTop">—</div>
                        </div>
                        <div class="team-score-wrap">
                            <div class="team-score" id="scoreTop">0.00</div>
                            <div class="score-delta" id="deltaTop"></div>
                        </div>
                    </div>
                </div>

                <div class="team-card" id="teamCardLeft" data-slot="left">
                    <div class="diamond"></div>
                    <div class="team-face">
                        <div class="team-meta">
                            <div class="rank-chip" id="rankLeft">—</div>
                            <div class="team-name" id="nameLeft">—</div>
                            <div class="team-sub" id="subLeft">—</div>
                        </div>
                        <div class="team-score-wrap">
                            <div class="team-score" id="scoreLeft">0.00</div>
                            <div class="score-delta" id="deltaLeft"></div>
                        </div>
                    </div>
                </div>

                <div class="team-card" id="teamCardRight" data-slot="right">
                    <div class="diamond"></div>
                    <div class="team-face">
                        <div class="team-meta">
                            <div class="rank-chip" id="rankRight">—</div>
                            <div class="team-name" id="nameRight">—</div>
                            <div class="team-sub" id="subRight">—</div>
                        </div>
                        <div class="team-score-wrap">
                            <div class="team-score" id="scoreRight">0.00</div>
                            <div class="score-delta" id="deltaRight"></div>
                        </div>
                    </div>
                </div>

                <div class="team-card" id="teamCardBottom" data-slot="bottom">
                    <div class="diamond"></div>
                    <div class="team-face">
                        <div class="team-meta">
                            <div class="rank-chip" id="rankBottom">—</div>
                            <div class="team-name" id="nameBottom">—</div>
                            <div class="team-sub" id="subBottom">—</div>
                        </div>
                        <div class="team-score-wrap">
                            <div class="team-score" id="scoreBottom">0.00</div>
                            <div class="score-delta" id="deltaBottom"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="banner" id="banner" aria-live="polite" aria-atomic="true"></section>

            <section class="updates-panel hidden" id="updatesPanel" aria-live="polite" aria-atomic="true">
                <div class="panel-shell">
                    <div class="panel-header">
                        <div>
                            <div class="panel-kicker">Approval Batch</div>
                            <h2>UPDATES</h2>
                        </div>
                        <div class="panel-chip" id="batchChip">0 Programs</div>
                    </div>
                    <div class="panel-grid" id="panelGrid"></div>
                </div>
            </section>
        </main>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
