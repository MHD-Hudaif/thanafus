<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function tv_color(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '#11b07d';
    }

    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
        return $value;
    }

    if (preg_match('/^rgba?\(\s*[\d.\s,]+\)$/i', $value)) {
        return $value;
    }

    return '#11b07d';
}

function tv_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fetch_leaderboard(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            t.id,
            t.team_name,
            t.team_color,
            COALESCE(score_totals.total_score, 0) AS total_score
        FROM musabaqa_teams t
        LEFT JOIN (
            SELECT pe.team_id, SUM(s.total_mark) AS total_score
            FROM musabaqa_scores s
            JOIN musabaqa_program_entries pe ON pe.id = s.entry_id
            WHERE s.status = 'approved'
            GROUP BY pe.team_id
        ) score_totals ON score_totals.team_id = t.id
        ORDER BY COALESCE(score_totals.total_score, 0) DESC, t.team_name ASC
        LIMIT 4
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_progress(PDO $pdo): array
{
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_programs,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_programs
        FROM musabaqa_programs
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($row['total_programs'] ?? 0);
    $completed = (int)($row['completed_programs'] ?? 0);

    return [
        'total' => $total,
        'completed' => $completed,
        'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'leaderboard') {
    try {
        tv_json([
            'success' => true,
            'teams' => fetch_leaderboard($musabaqa_pdo),
            'progress' => fetch_progress($musabaqa_pdo),
            'generated_at' => date('c'),
        ]);
    } catch (Throwable $e) {
        tv_json([
            'success' => false,
            'message' => 'Unable to load leaderboard.',
        ]);
    }
}

$teams = fetch_leaderboard($musabaqa_pdo);
$teams = array_pad($teams, 4, null);

$initialPayload = [
    'teams' => array_values(array_filter($teams)),
    'progress' => fetch_progress($musabaqa_pdo),
    'generated_at' => date('c'),
];

$pageTitle = 'Ceremonial Leaderboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#06120e">
<title><?= e($pageTitle) ?></title>

<link rel="stylesheet" href="<?= e(tv_asset_url('css/leaderboard.css')) ?>">
</head>
<body>
<div class="tv-shell">
    <header class="tv-topbar">
        <div class="brand">
            <div class="brand-mark">
                <img src="<?= e(tv_asset_url('thanafus-logo.png')) ?>" alt="Thanafus Logo">
            </div>
            <div class="brand-copy">
                <div class="eyebrow">Musabaqa Ceremonial Ranking</div>
                <h1 class="title">Premium Podium Leaderboard</h1>
            </div>
        </div>

        <div class="score-pill" id="refreshStamp">Live leaderboard • auto refresh every 5s</div>

        <div class="brand brand-right">
            <div class="brand-copy text-right">
                <div class="eyebrow">Broadcast Display</div>
                <h1 class="title">Team Ceremony Arena</h1>
            </div>
            <div class="brand-mark">
                <img src="<?= e(tv_asset_url('kauzariyya-logo.png')) ?>" alt="Kauzariyya Logo">
            </div>
        </div>
    </header>

    <main class="tv-stage">
        <section class="progress-orbit" aria-label="Program completion progress">
            <div class="progress-orbit-core"></div>

            <svg class="progress-ring" viewBox="0 0 220 220" aria-hidden="true">
                <circle class="ring-bg" cx="110" cy="110" r="90"></circle>
                <circle class="ring-progress" id="ringProgress" cx="110" cy="110" r="90"></circle>
            </svg>

            <div class="progress-center">
                <div class="progress-percent" id="progressPercent">0%</div>
                <div class="progress-label">PROGRAMS COMPLETED</div>
                <div class="progress-count" id="progressCount">0 / 0</div>
            </div>
        </section>

        <section class="podium-scene" id="podiumScene">
            <div class="stage-spotlight" aria-hidden="true"></div>

            <div class="podium-grid">
                <div class="podium-card second" data-rank="2">
                    <div class="card-topline"></div>
                    <div class="card-badge">2nd</div>
                    <div class="card-medal">🥈</div>
                    <div class="team-name"></div>
                    <div class="score-wrap">
                        <div class="score-label">Total Score</div>
                        <div class="score-value" data-score="0">0</div>
                        <div class="score-unit">points</div>
                    </div>
                </div>

                <div class="podium-card first" data-rank="1">
                    <div class="card-topline"></div>
                    <div class="card-badge">1st</div>
                    <div class="card-medal">👑</div>
                    <div class="team-name"></div>
                    <div class="score-wrap">
                        <div class="score-label">Total Score</div>
                        <div class="score-value" data-score="0">0</div>
                        <div class="score-unit">points</div>
                    </div>
                </div>

                <div class="podium-card third" data-rank="3">
                    <div class="card-topline"></div>
                    <div class="card-badge">3rd</div>
                    <div class="card-medal">🥉</div>
                    <div class="team-name"></div>
                    <div class="score-wrap">
                        <div class="score-label">Total Score</div>
                        <div class="score-value" data-score="0">0</div>
                        <div class="score-unit">points</div>
                    </div>
                </div>
            </div>

            <div class="podium-fourth-row">
                <div class="podium-card fourth" data-rank="4">
                    <div class="card-topline"></div>
                    <div class="card-badge">4th</div>
                    <div class="card-medal">4</div>
                    <div class="team-name"></div>
                    <div class="score-wrap">
                        <div class="score-label">Total Score</div>
                        <div class="score-value" data-score="0">0</div>
                        <div class="score-unit">points</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="empty-state" id="emptyState">
            <h2>Leaderboard Unavailable</h2>
            <p>No team records were returned from the current event dataset.</p>
        </div>
    </main>
</div>

<script>
window.TV_LEADERBOARD_CONFIG = {
    apiUrl: <?= json_encode(app_url('/tv/leaderboard.php?api=leaderboard'), JSON_UNESCAPED_SLASHES) ?>,
    refreshMs: 5000
};

window.TV_LEADERBOARD_INITIAL = <?= json_encode($initialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="<?= e(tv_asset_url('js/leaderboard.js')) ?>"></script>
</body>
</html>
