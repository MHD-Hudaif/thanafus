<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| SMALL HELPERS
|--------------------------------------------------------------------------
*/

function tv_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tv_pdo(): PDO
{
    return $GLOBALS['musabaqa_pdo'];
}

function tv_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$table]);

    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function tv_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!tv_table_exists($pdo, $table)) {
        return $cache[$table] = [];
    }

    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
        ORDER BY ordinal_position
    ");
    $stmt->execute([$table]);

    return $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function tv_pick(array $haystack, array $needles, ?string $fallback = null): ?string
{
    foreach ($needles as $needle) {
        if (in_array($needle, $haystack, true)) {
            return $needle;
        }
    }

    return $fallback;
}

function tv_ident(?string $name): ?string
{
    if ($name === null || $name === '') {
        return null;
    }

    return '`' . str_replace('`', '``', $name) . '`';
}

function tv_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tv_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tv_fetch_value(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function tv_score_status_sql(array $scoreCols): array
{
    $statusCol = tv_pick($scoreCols, ['status', 'score_status', 'approval_status']);
    $approvedCol = tv_pick($scoreCols, ['approved', 'is_approved', 'approval', 'approved_flag']);

    if ($statusCol !== null) {
        return [
            'sql' => 'LOWER(COALESCE(s.' . tv_ident($statusCol) . ', \'\')) = \'approved\'',
            'kind' => 'status'
        ];
    }

    if ($approvedCol !== null) {
        return [
            'sql' => 'COALESCE(s.' . tv_ident($approvedCol) . ', 0) IN (1, "1", TRUE)',
            'kind' => 'flag'
        ];
    }

    return [
        'sql' => '1=1',
        'kind' => 'none'
    ];
}

function tv_score_value_expr(array $scoreCols): string
{
    $candidates = ['total_mark', 'total_marks', 'score', 'marks', 'final_score', 'point', 'points'];
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $scoreCols, true)) {
            return 'COALESCE(s.' . tv_ident($candidate) . ', 0)';
        }
    }

    return '0';
}

function tv_program_title_expr(array $programCols): string
{
    $col = tv_pick($programCols, ['program_name', 'title', 'name', 'program_title']);
    return $col ? 'p.' . tv_ident($col) : "CONCAT('Program #', p.id)";
}

function tv_team_name_expr(array $teamCols): string
{
    $col = tv_pick($teamCols, ['team_name', 'name', 'title']);
    return $col ? 't.' . tv_ident($col) : "CONCAT('Team #', t.id)";
}

function tv_team_color_expr(array $teamCols): string
{
    $col = tv_pick($teamCols, ['team_color', 'color', 'colour', 'badge_color']);
    return $col ? 't.' . tv_ident($col) : "NULL";
}

function tv_entry_name_expr(array $entryCols): string
{
    $col = tv_pick($entryCols, ['entry_name', 'participant_name', 'name', 'title', 'member_name']);
    return $col ? 'pe.' . tv_ident($col) : "CONCAT('Entry #', pe.id)";
}

function tv_entry_number_expr(array $entryCols): string
{
    $col = tv_pick($entryCols, ['entry_number', 'sort_order', 'order_no', 'position', 'sequence', 'entry_order']);
    return $col ? 'pe.' . tv_ident($col) : 'pe.id';
}

function tv_program_order_expr(array $programCols): string
{
    $col = tv_pick($programCols, ['sort_order', 'display_order', 'program_order', 'order_no', 'position']);
    return $col ? 'p.' . tv_ident($col) : 'p.id';
}

function tv_entry_program_fk(array $entryCols): ?string
{
    return tv_pick($entryCols, ['program_id', 'musabaqa_program_id', 'program', 'event_program_id']);
}

function tv_entry_team_fk(array $entryCols): ?string
{
    return tv_pick($entryCols, ['team_id', 'musabaqa_team_id', 'team', 'group_id']);
}

function tv_score_entry_fk(array $scoreCols): ?string
{
    return tv_pick($scoreCols, ['entry_id', 'program_entry_id', 'musabaqa_program_entry_id', 'program_entry', 'program_entries_id']);
}

function tv_score_program_fk(array $scoreCols): ?string
{
    return tv_pick($scoreCols, ['program_id', 'musabaqa_program_id']);
}

function tv_group_entries_by_program(array $entries, string $programKey, string $entryKey = 'id'): array
{
    $grouped = [];
    foreach ($entries as $entry) {
        $pid = $entry[$programKey] ?? null;
        if ($pid === null) {
            continue;
        }
        if (!isset($grouped[$pid])) {
            $grouped[$pid] = [];
        }
        $grouped[$pid][] = $entry;
    }
    return $grouped;
}

function tv_build_data(): array
{
    $pdo = tv_pdo();

    $teamsCols = tv_columns($pdo, 'musabaqa_teams');
    $programCols = tv_columns($pdo, 'musabaqa_programs');
    $entryCols = tv_columns($pdo, 'musabaqa_program_entries');
    $scoreCols = tv_columns($pdo, 'musabaqa_scores');

    $teamNameExpr = tv_team_name_expr($teamsCols);
    $teamColorExpr = tv_team_color_expr($teamsCols);

    $programTitleExpr = tv_program_title_expr($programCols);
    $programOrderExpr = tv_program_order_expr($programCols);

    $entryNameExpr = tv_entry_name_expr($entryCols);
    $entryNumberExpr = tv_entry_number_expr($entryCols);

    $entryProgramFk = tv_entry_program_fk($entryCols);
    $entryTeamFk = tv_entry_team_fk($entryCols);

    $scoreEntryFk = tv_score_entry_fk($scoreCols);
    $scoreProgramFk = tv_score_program_fk($scoreCols);
    $scoreValueExpr = tv_score_value_expr($scoreCols);
    $approvedSql = tv_score_status_sql($scoreCols)['sql'];

    $entryProgramJoin = $entryProgramFk ? 'pe.' . tv_ident($entryProgramFk) . ' = p.id' : '1=0';
    $entryTeamJoin = $entryTeamFk ? 't.id = pe.' . tv_ident($entryTeamFk) : '1=0';
    $scoreEntryJoin = $scoreEntryFk ? 's.' . tv_ident($scoreEntryFk) : 'NULL';
    $scoreProgramJoin = $scoreProgramFk ? 's.' . tv_ident($scoreProgramFk) : 'NULL';

    /*
    |--------------------------------------------------------------------------
    | LEADERBOARD
    |--------------------------------------------------------------------------
    */

    $leaderboard = [];
    if (tv_table_exists($pdo, 'musabaqa_teams')) {
        $hasApprovedScoreTotals = !empty($scoreEntryFk)
            && !empty($entryTeamFk)
            && tv_table_exists($pdo, 'musabaqa_scores')
            && tv_table_exists($pdo, 'musabaqa_program_entries');

        $scoreTotalsJoin = $hasApprovedScoreTotals
            ? "
                LEFT JOIN (
                    SELECT
                        pe." . tv_ident($entryTeamFk) . " AS team_id,
                        SUM({$scoreValueExpr}) AS total_score
                    FROM musabaqa_scores s
                    JOIN musabaqa_program_entries pe ON pe.id = {$scoreEntryJoin}
                    WHERE {$approvedSql}
                    GROUP BY pe." . tv_ident($entryTeamFk) . "
                ) approved_scores ON approved_scores.team_id = t.id
            "
            : "";
        $leaderboardTotalExpr = $hasApprovedScoreTotals ? 'COALESCE(approved_scores.total_score, 0)' : '0';

        $leaderboardSql = "
            SELECT
                t.id,
                {$teamNameExpr} AS team_name,
                {$teamColorExpr} AS team_color,
                {$leaderboardTotalExpr} AS total_score
            FROM musabaqa_teams t
            {$scoreTotalsJoin}
            ORDER BY total_score DESC, team_name ASC, t.id ASC
        ";
        $leaderboard = tv_fetch_all($pdo, $leaderboardSql);
    }

    /*
    |--------------------------------------------------------------------------
    | SCORES (APPROVED)
    |--------------------------------------------------------------------------
    */

    $approvedByEntry = [];
    $entryScores = [];
    $approvedByProgram = [];

    if (tv_table_exists($pdo, 'musabaqa_scores') && !empty($scoreEntryFk)) {
        $approvedScoreSql = "
            SELECT
                s.*,
                {$scoreValueExpr} AS score_value
            FROM musabaqa_scores s
            WHERE {$approvedSql}
        ";
        $rows = tv_fetch_all($pdo, $approvedScoreSql);

        foreach ($rows as $row) {
            $entryId = $row[$scoreEntryFk] ?? null;
            if ($entryId === null) {
                continue;
            }

            $entryId = (string)$entryId;
            $approvedByEntry[$entryId] = true;

            if (!isset($entryScores[$entryId])) {
                $entryScores[$entryId] = 0.0;
            }
            $entryScores[$entryId] += (float)($row['score_value'] ?? 0);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PROGRAMS + ENTRIES
    |--------------------------------------------------------------------------
    */

    $programs = [];
    $programGroups = [];
    $entriesByProgram = [];

    if (tv_table_exists($pdo, 'musabaqa_programs')) {
        $programs = tv_fetch_all($pdo, "
            SELECT
                p.*,
                {$programTitleExpr} AS program_title
            FROM musabaqa_programs p
            ORDER BY {$programOrderExpr} ASC, p.id ASC
        ");
    }

    if (tv_table_exists($pdo, 'musabaqa_program_entries') && !empty($entryProgramFk)) {
        $entriesByProgram = [];
        $entrySql = "
            SELECT
                pe.*,
                {$entryNameExpr} AS entry_display_name,
                {$entryNumberExpr} AS entry_display_number
            FROM musabaqa_program_entries pe
            ORDER BY " . (in_array('program_id', $entryCols, true) ? 'pe.`program_id`' : 'pe.id') . " ASC,
                     {$entryNumberExpr} ASC,
                     pe.id ASC
        ";
        $entries = tv_fetch_all($pdo, $entrySql);
        foreach ($entries as $entry) {
            $pid = $entry[$entryProgramFk] ?? null;
            if ($pid === null) {
                continue;
            }
            $entriesByProgram[(string)$pid][] = $entry;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CLASSIFY PROGRAMS
    |--------------------------------------------------------------------------
    */

    $upcoming = [];
    $inProgress = [];
    $completed = [];
    $incompletePrograms = [];

    foreach ($programs as $program) {
        $pid = (string)($program['id'] ?? '');
        $programEntries = $entriesByProgram[$pid] ?? [];

        if (empty($programEntries)) {
            $upcoming[] = [
                'id' => $program['id'],
                'title' => $program['program_title'] ?? ('Program #' . $program['id']),
                'status' => 'Upcoming'
            ];
            continue;
        }

        $approvedCount = 0;
        $entryTotals = [];

        foreach ($programEntries as $entry) {
            $eid = (string)($entry['id'] ?? '');
            $hasApproved = !empty($approvedByEntry[$eid]);
            if ($hasApproved) {
                $approvedCount++;
            }
            $entryTotals[] = [
                'entry' => $entry,
                'approved' => $hasApproved,
                'score' => (float)($entryScores[$eid] ?? 0),
            ];
        }

        $totalEntries = count($programEntries);

        if ($approvedCount === 0) {
            $upcoming[] = [
                'id' => $program['id'],
                'title' => $program['program_title'] ?? ('Program #' . $program['id']),
                'status' => 'Upcoming'
            ];
            $incompletePrograms[] = [
                'program' => $program,
                'entries' => $programEntries,
                'entryTotals' => $entryTotals
            ];
            continue;
        }

        if ($approvedCount < $totalEntries) {
            $inProgress[] = [
                'id' => $program['id'],
                'title' => $program['program_title'] ?? ('Program #' . $program['id']),
                'status' => 'In Progress'
            ];
            $incompletePrograms[] = [
                'program' => $program,
                'entries' => $programEntries,
                'entryTotals' => $entryTotals
            ];
            continue;
        }

        usort($entryTotals, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                $aOrder = (int)($a['entry']['entry_display_number'] ?? $a['entry']['id'] ?? 0);
                $bOrder = (int)($b['entry']['entry_display_number'] ?? $b['entry']['id'] ?? 0);
                return $aOrder <=> $bOrder;
            }
            return $b['score'] <=> $a['score'];
        });

        $winners = [];
        foreach (array_slice($entryTotals, 0, 3) as $idx => $row) {
            $entry = $row['entry'];
            $teamName = null;
            $teamColor = null;

            if (!empty($entry['team_id']) && tv_table_exists($pdo, 'musabaqa_teams')) {
                $team = tv_fetch_one($pdo, "
                    SELECT
                        t.*,
                        {$teamNameExpr} AS team_name,
                        {$teamColorExpr} AS team_color
                    FROM musabaqa_teams t
                    WHERE t.id = ?
                    LIMIT 1
                ", [$entry['team_id']]);

                if ($team) {
                    $teamName = $team['team_name'] ?? null;
                    $teamColor = $team['team_color'] ?? null;
                }
            }

            $winners[] = [
                'place' => $idx + 1,
                'team_name' => $teamName ?: ('Team #' . ($entry['team_id'] ?? $entry['id'])),
                'team_color' => $teamColor
            ];
        }

        $completed[] = [
            'id' => $program['id'],
            'title' => $program['program_title'] ?? ('Program #' . $program['id']),
            'results' => $winners
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | NOW PERFORMING
    |--------------------------------------------------------------------------
    */

    $now = [
        'break' => true,
        'program' => null,
        'current' => null,
        'next' => null,
        'nextProgram' => null,
    ];

    if (!empty($incompletePrograms)) {
        $firstIncomplete = $incompletePrograms[0];
        $program = $firstIncomplete['program'];
        $entries = $firstIncomplete['entries'];

        $currentEntry = null;
        $nextEntry = null;

        foreach ($entries as $entry) {
            $eid = (string)($entry['id'] ?? '');
            if (empty($approvedByEntry[$eid])) {
                $currentEntry = $entry;
                break;
            }
        }

        if ($currentEntry !== null) {
            $foundCurrent = false;
            foreach ($entries as $entry) {
                $eid = (string)($entry['id'] ?? '');
                if (empty($approvedByEntry[$eid])) {
                    if (!$foundCurrent) {
                        $foundCurrent = true;
                        continue;
                    }
                    $nextEntry = $entry;
                    break;
                }
            }

            $currentTeam = null;
            $nextTeam = null;

            if (!empty($currentEntry['team_id']) && tv_table_exists($pdo, 'musabaqa_teams')) {
                $currentTeam = tv_fetch_one($pdo, "
                    SELECT
                        t.*,
                        {$teamNameExpr} AS team_name,
                        {$teamColorExpr} AS team_color
                    FROM musabaqa_teams t
                    WHERE t.id = ?
                    LIMIT 1
                ", [$currentEntry['team_id']]);
            }

            if ($nextEntry && !empty($nextEntry['team_id']) && tv_table_exists($pdo, 'musabaqa_teams')) {
                $nextTeam = tv_fetch_one($pdo, "
                    SELECT
                        t.*,
                        {$teamNameExpr} AS team_name,
                        {$teamColorExpr} AS team_color
                    FROM musabaqa_teams t
                    WHERE t.id = ?
                    LIMIT 1
                ", [$nextEntry['team_id']]);
            }

            $nextProgramName = null;
            foreach ($incompletePrograms as $index => $block) {
                if ((string)$block['program']['id'] === (string)$program['id']) {
                    if (isset($incompletePrograms[$index + 1])) {
                        $nextProgramName = $incompletePrograms[$index + 1]['program']['program_title'] ?? ('Program #' . $incompletePrograms[$index + 1]['program']['id']);
                    }
                    break;
                }
            }

            $now = [
                'break' => false,
                'program' => [
                    'id' => $program['id'],
                    'title' => $program['program_title'] ?? ('Program #' . $program['id']),
                ],
                'current' => [
                    'name' => $currentEntry['entry_display_name'] ?? ($currentEntry['entry_name'] ?? ('Entry #' . $currentEntry['id'])),
                    'number' => $currentEntry['entry_display_number'] ?? $currentEntry['id'],
                    'team_name' => $currentTeam['team_name'] ?? (empty($currentEntry['team_id']) ? '—' : ('Team #' . $currentEntry['team_id'])),
                    'team_color' => $currentTeam['team_color'] ?? null,
                ],
                'next' => $nextEntry ? [
                    'name' => $nextEntry['entry_display_name'] ?? ($nextEntry['entry_name'] ?? ('Entry #' . $nextEntry['id'])),
                    'number' => $nextEntry['entry_display_number'] ?? $nextEntry['id'],
                    'team_name' => $nextTeam['team_name'] ?? (empty($nextEntry['team_id']) ? '—' : ('Team #' . $nextEntry['team_id'])),
                    'team_color' => $nextTeam['team_color'] ?? null,
                ] : null,
                'nextProgram' => $nextProgramName,
            ];
        }
    }

    return [
        'leaderboard' => $leaderboard,
        'upcoming' => $upcoming,
        'inProgress' => $inProgress,
        'completed' => $completed,
        'now' => $now
    ];
}

function tv_render_response(array $data, string $mode = 'auto'): void
{
    tv_json([
        'success' => true,
        'mode' => $mode,
        'data' => $data,
        'timestamp' => time()
    ]);
}

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

if (isset($_GET['api'])) {
    try {
        $data = tv_build_data();

        switch ((string)$_GET['api']) {
            case 'leaderboard':
                tv_render_response([
                    'leaderboard' => $data['leaderboard']
                ]);
                break;

            case 'schedule':
                tv_render_response([
                    'upcoming' => $data['upcoming'],
                    'inProgress' => $data['inProgress'],
                    'completed' => $data['completed']
                ]);
                break;

            case 'current':
                tv_render_response([
                    'now' => $data['now']
                ]);
                break;

            case 'bootstrap':
                tv_render_response($data);
                break;

            default:
                tv_json(['success' => false, 'message' => 'Unknown API endpoint'], 404);
        }
    } catch (Throwable $e) {
        tv_json([
            'success' => false,
            'message' => 'TV API error',
            'error' => $e->getMessage()
        ], 500);
    }
}

$assetBase = tv_asset_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TV Mode</title>
    <link rel="stylesheet" href="<?= e($assetBase) ?>/css/tv.css">
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
</head>
<body>
    <div id="tv-root" class="tv-root">
        <div class="tv-bg">
            <div class="tv-bg-glow tv-bg-glow-a"></div>
            <div class="tv-bg-glow tv-bg-glow-b"></div>
            <div class="tv-noise"></div>
        </div>

        <header class="tv-topbar">
            <div class="tv-brand">
                <img src="<?= e(asset_url('images/thanafus-logo.png')) ?>" alt="Thanafus">
                <div class="tv-brand-copy">
                    <div class="tv-brand-kicker">Kauzariyya Digital Musabaqa</div>
                    <div class="tv-brand-title">Broadcast Display</div>
                </div>
            </div>
            <div class="tv-clock" id="tvClock">--:--</div>
        </header>

        <main class="tv-stage">
            <section class="tv-slide tv-slide--active" data-slide="intro" id="slide-intro">
                <video id="introVideo" class="tv-intro-video" autoplay muted playsinline preload="auto">
                    <source src="<?= e($assetBase) ?>/videos/intro.mp4" type="video/mp4">
                </video>
                <div class="tv-intro-overlay">
                    <img src="<?= e(asset_url('images/kauzariyya-logo.png')) ?>" alt="Kauzariyya" class="tv-intro-mark">
                    <div class="tv-intro-copy">
                        <div class="tv-intro-eyebrow">Welcome to the arena</div>
                        <div class="tv-intro-title">Kauzariyya Musabaqa</div>
                        <div class="tv-intro-subtitle">Live competition broadcast display</div>
                    </div>
                </div>
            </section>

            <section class="tv-slide" data-slide="leaderboard" id="slide-leaderboard">
                <div class="tv-slide-head">
                    <div>
                        <div class="tv-kicker">Live Ranking</div>
                        <h1>Team Leaderboard</h1>
                    </div>
                    <div class="tv-pill">Auto refresh: 5s</div>
                </div>
                <div id="leaderboardContainer" class="tv-leaderboard"></div>
            </section>

            <section class="tv-slide" data-slide="schedule" id="slide-schedule">
                <div class="tv-slide-head">
                    <div>
                        <div class="tv-kicker">Competition Flow</div>
                        <h1>Schedule & Results</h1>
                    </div>
                    <div class="tv-pill">Auto refresh: 5s</div>
                </div>

                <div class="tv-schedule-grid">
                    <div class="tv-panel">
                        <div class="tv-panel-head">
                            <h2>Upcoming Programs</h2>
                            <span>Upcoming / In Progress</span>
                        </div>
                        <div id="upcomingPrograms" class="tv-list"></div>
                        <div id="inProgressPrograms" class="tv-list tv-list--compact"></div>
                    </div>

                    <div class="tv-panel">
                        <div class="tv-panel-head">
                            <h2>Completed Programs</h2>
                            <span>Placements only</span>
                        </div>
                        <div id="completedPrograms" class="tv-list"></div>
                    </div>
                </div>
            </section>

            <section class="tv-slide" data-slide="current" id="slide-current">
                <div class="tv-now">
                    <div class="tv-now-main">
                        <div class="tv-kicker">Main Stage</div>
                        <h1 id="currentProgramTitle">BREAK TIME</h1>
                        <div id="currentPerformerName" class="tv-now-performer">No active performer</div>
                        <div id="currentTeamBadge" class="tv-team-badge">—</div>
                    </div>

                    <div class="tv-now-side">
                        <div class="tv-card">
                            <div class="tv-card-label">NEXT PERFORMER</div>
                            <div id="nextPerformerName" class="tv-card-value">—</div>
                            <div id="nextPerformerTeam" class="tv-card-sub">—</div>
                        </div>

                        <div class="tv-card">
                            <div class="tv-card-label">NEXT PROGRAM</div>
                            <div id="nextProgramName" class="tv-card-value">—</div>
                            <div class="tv-card-sub">Queued automatically</div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        window.TV_APP = {
            api: {
                bootstrap: '<?= e(app_url('/tv/index.php?api=bootstrap')) ?>',
                leaderboard: '<?= e(app_url('/tv/index.php?api=leaderboard')) ?>',
                schedule: '<?= e(app_url('/tv/index.php?api=schedule')) ?>',
                current: '<?= e(app_url('/tv/index.php?api=current')) ?>'
            },
            slideDurations: {
                leaderboard: 15000,
                schedule: 20000,
                current: 20000
            }
        };
    </script>
    <script src="<?= e($assetBase) ?>/js/tv.js" defer></script>
</body>
</html>
