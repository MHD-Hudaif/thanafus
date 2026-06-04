<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function ceremony_color(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '#1fe08a';
    }

    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
        return $value;
    }

    if (preg_match('/^rgba?\(\s*[\d.\s,]+\)$/i', $value)) {
        return $value;
    }

    return '#1fe08a';
}

function ceremony_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fetch_ceremony_teams(PDO $pdo): array
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

    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'team_name' => (string) ($row['team_name'] ?? 'Team'),
            'team_color' => ceremony_color($row['team_color'] ?? ''),
            'total_score' => (float) ($row['total_score'] ?? 0),
        ];
    }, $teams);
}

function fetch_ceremony_progress(PDO $pdo): array
{
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_programs,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_programs
        FROM musabaqa_programs
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int) ($row['total_programs'] ?? 0);
    $completed = (int) ($row['completed_programs'] ?? 0);

    return [
        'total' => $total,
        'completed' => $completed,
        'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'ceremony') {
    try {
        ceremony_json([
            'success' => true,
            'teams' => fetch_ceremony_teams($musabaqa_pdo),
            'progress' => fetch_ceremony_progress($musabaqa_pdo),
            'generated_at' => date('c'),
        ]);
    } catch (Throwable $e) {
        ceremony_json([
            'success' => false,
            'message' => 'Unable to load ceremonial leaderboard.',
        ]);
    }
}

$initialTeams = fetch_ceremony_teams($musabaqa_pdo);
$initialPayload = [
    'teams' => $initialTeams,
    'progress' => fetch_ceremony_progress($musabaqa_pdo),
    'generated_at' => date('c'),
];

$pageTitle = 'Ceremonial Leaderboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#05070a">
<title><?= e($pageTitle) ?></title>
<style>
    :root {
        --bg: #05070a;
        --bg-2: #091015;
        --panel: rgba(10, 16, 20, 0.78);
        --panel-2: rgba(10, 16, 20, 0.92);
        --line: rgba(255, 255, 255, 0.08);
        --line-2: rgba(125, 255, 196, 0.12);
        --text: #f4f7f6;
        --muted: rgba(244, 247, 246, 0.72);
        --shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
        --radius-xl: 30px;
        --radius-lg: 22px;
        --radius-md: 16px;
        --radius-sm: 12px;
    }

    * { box-sizing: border-box; }

    html, body {
        width: 100%;
        height: 100%;
        margin: 0;
        overflow: hidden;
        background:
            radial-gradient(circle at 50% 20%, rgba(31, 224, 138, 0.10), transparent 24%),
            radial-gradient(circle at 80% 15%, rgba(200, 178, 107, 0.08), transparent 20%),
            linear-gradient(180deg, #091115 0%, #040506 100%);
        color: var(--text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    body {
        -webkit-font-smoothing: antialiased;
        text-rendering: geometricPrecision;
    }

    img { display: block; max-width: 100%; }

    .ceremony-root {
        position: relative;
        width: 100vw;
        height: 100vh;
        overflow: hidden;
        isolation: isolate;
    }

    .bg-grid {
        position: absolute;
        inset: 0;
        pointer-events: none;
        background-image:
            linear-gradient(rgba(255,255,255,.017) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.017) 1px, transparent 1px);
        background-size: 44px 44px;
        opacity: .18;
        mask-image: linear-gradient(180deg, rgba(0,0,0,.9), transparent 110%);
    }

    .bg-glow {
        position: absolute;
        width: 42vw;
        height: 42vw;
        border-radius: 999px;
        filter: blur(90px);
        opacity: .34;
        mix-blend-mode: screen;
        pointer-events: none;
    }

    .bg-glow-a { top: -16vw; left: -8vw; background: rgba(31, 224, 138, .20); }
    .bg-glow-b { bottom: -18vw; right: -8vw; background: rgba(200, 178, 107, .12); }

    .topbar {
        position: absolute;
        inset: 0 0 auto 0;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
        padding: 22px 28px;
        pointer-events: none;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }

    .brand-logo {
        width: 56px;
        height: 56px;
        object-fit: contain;
        filter: drop-shadow(0 8px 18px rgba(0, 0, 0, .35));
        flex: none;
    }

    .brand-copy { min-width: 0; }

    .eyebrow {
        font-size: 11px;
        letter-spacing: .24em;
        text-transform: uppercase;
        color: rgba(244, 247, 246, .58);
        margin-bottom: 6px;
    }

    .title {
        font-size: 18px;
        font-weight: 800;
        letter-spacing: .02em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .clock-pill {
        flex: none;
        padding: 12px 16px;
        border-radius: 999px;
        border: 1px solid var(--line);
        background: rgba(255,255,255,.03);
        box-shadow: var(--shadow);
        letter-spacing: .12em;
        font-weight: 700;
        font-size: 14px;
    }

    .stage {
        position: absolute;
        inset: 0;
        padding-top: 88px;
    }

    .scene {
        position: absolute;
        inset: 0;
        opacity: 0;
        visibility: hidden;
        transform: scale(1.02);
        transition: opacity .55s ease, transform .55s ease, visibility .55s ease;
        padding: 88px 34px 34px;
    }

    .scene.is-active {
        opacity: 1;
        visibility: visible;
        transform: scale(1);
    }

    .ceremony-scene {
        position: relative;
        height: calc(100vh - 122px);
        min-height: 0;
    }

    .progress-orbit {
        position: absolute;
        left: 50%;
        top: 50%;
        width: min(24vw, 260px);
        aspect-ratio: 1;
        transform: translate(-50%, -50%);
        z-index: 2;
        display: grid;
        place-items: center;
    }

    .progress-orbit-core {
        position: absolute;
        inset: 10%;
        border-radius: 999px;
        background: radial-gradient(circle at 50% 45%, rgba(255,255,255,.08), rgba(255,255,255,.02) 70%, rgba(255,255,255,.01));
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: inset 0 0 80px rgba(0,0,0,.28);
    }

    .progress-ring {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        transform: rotate(-90deg);
        overflow: visible;
    }

    .ring-bg {
        fill: none;
        stroke: rgba(255,255,255,.08);
        stroke-width: 12;
    }

    .ring-progress {
        fill: none;
        stroke: #1fe08a;
        stroke-width: 12;
        stroke-linecap: round;
        filter: drop-shadow(0 0 18px rgba(31,224,138,.35));
        stroke-dasharray: 565;
        stroke-dashoffset: 565;
    }

    .progress-center {
        position: relative;
        z-index: 1;
        text-align: center;
        width: 72%;
        display: grid;
        gap: 4px;
        align-content: center;
        justify-items: center;
    }

    .progress-percent {
        font-size: clamp(28px, 4vw, 54px);
        font-weight: 900;
        letter-spacing: -.06em;
        line-height: 1;
    }

    .progress-label {
        font-size: 11px;
        letter-spacing: .22em;
        text-transform: uppercase;
        color: rgba(244, 247, 246, .72);
    }

    .progress-count {
        font-size: 13px;
        color: rgba(244, 247, 246, .56);
        letter-spacing: .1em;
    }

    .diamond-layout {
        position: absolute;
        inset: 0;
        z-index: 4;
        pointer-events: none;
    }

    .team-card {
        position: absolute;
        width: 160px;
        height: 160px;
        border-radius: 26px;
        background: rgba(255,255,255,.03);
        border: 2px solid currentColor;
        box-shadow: 0 20px 55px rgba(0,0,0,.25);
        overflow: hidden;
        transform: translate(-50%, -50%) rotate(45deg);
        transform-origin: center;
        will-change: left, top, width, height, transform, opacity;
    }

    .team-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(255,255,255,.08), transparent 36%);
        opacity: .45;
        pointer-events: none;
    }

    .team-card-inner {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        padding: 16px;
        transform: rotate(-45deg);
        text-align: center;
    }

    .team-card.is-mini {
        border-radius: 18px;
        width: 108px;
        height: 60px;
        background: rgba(255,255,255,.05);
        box-shadow: 0 10px 28px rgba(0,0,0,.22);
    }

    .team-card.is-mini .team-card-inner {
        padding: 8px 10px;
    }

    .team-card.is-mini .card-rank {
        display: none;
    }

    .team-card.is-mini .card-score {
        display: none;
    }

    .team-card.is-mini .card-chip {
        margin-top: 4px;
        padding: 6px 8px;
        font-size: 9px;
        letter-spacing: .08em;
    }

    .team-card.is-mini .card-name {
        font-size: 13px;
        line-height: 1;
        margin-top: 0;
        max-width: 88px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card-rank {
        font-size: 13px;
        letter-spacing: .2em;
        text-transform: uppercase;
        color: rgba(244, 247, 246, .62);
    }

    .card-name {
        margin-top: 10px;
        font-size: 24px;
        font-weight: 900;
        letter-spacing: -.04em;
        line-height: 1;
        max-width: 125px;
        overflow-wrap: break-word;
    }

    .card-score {
        margin-top: 12px;
        font-size: 36px;
        font-weight: 900;
        line-height: 1;
    }

    .card-chip {
        margin-top: 12px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 11px;
        letter-spacing: .16em;
        text-transform: uppercase;
        background: rgba(255,255,255,.04);
        color: rgba(244,247,246,.82);
    }

    .team-dot {
        width: 11px;
        height: 11px;
        border-radius: 999px;
        flex: none;
        box-shadow: 0 0 0 4px rgba(255,255,255,.08);
    }

    .position-1 { left: 50%; top: 20%; }
    .position-2 { left: 22%; top: 50%; }
    .position-3 { left: 78%; top: 50%; }
    .position-4 { left: 50%; top: 80%; }

    .ranking-layout {
        position: absolute;
        inset: 0;
        z-index: 5;
        opacity: 0;
        pointer-events: none;
    }

    .ranking-list {
        position: absolute;
        left: 50%;
        top: 56%;
        width: min(82vw, 1120px);
        transform: translate(-50%, -50%);
        display: grid;
        gap: 16px;
    }

    .ranking-row {
        position: relative;
        display: grid;
        grid-template-columns: 84px 118px minmax(0, 1fr) 120px;
        align-items: center;
        gap: 14px;
        min-height: 78px;
        padding: 14px 18px;
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(14, 22, 27, .94), rgba(6, 10, 12, .94));
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .ranking-place {
        font-size: 13px;
        letter-spacing: .18em;
        text-transform: uppercase;
        color: rgba(244,247,246,.66);
    }

    .rank-slot {
        width: 108px;
        height: 60px;
        border-radius: 18px;
        background: rgba(255,255,255,.03);
        border: 1px solid rgba(255,255,255,.05);
        position: relative;
    }

    .rank-label {
        position: absolute;
        inset: 50% auto auto 50%;
        transform: translate(-50%, -50%);
        color: rgba(244,247,246,.38);
        font-size: 10px;
        letter-spacing: .16em;
        text-transform: uppercase;
        white-space: nowrap;
        pointer-events: none;
    }

    .ranking-team-name {
        font-size: clamp(18px, 2vw, 30px);
        font-weight: 900;
        letter-spacing: -.03em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }

    .ranking-score {
        text-align: right;
        font-size: clamp(24px, 2.5vw, 40px);
        font-weight: 900;
        letter-spacing: -.04em;
    }

    .ranking-bar {
        position: absolute;
        left: 230px;
        right: 138px;
        bottom: 12px;
        height: 6px;
        border-radius: 999px;
        background: rgba(255,255,255,.06);
        overflow: hidden;
    }

    .ranking-bar > span {
        display: block;
        height: 100%;
        width: 0;
        border-radius: inherit;
        background: linear-gradient(90deg, currentColor, transparent);
        box-shadow: 0 0 24px currentColor;
    }

    .empty-state {
        position: absolute;
        inset: 50% auto auto 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        display: none;
    }

    .empty-state h2 {
        font-size: clamp(34px, 4vw, 64px);
        margin: 0;
        letter-spacing: -.05em;
    }

    .empty-state p {
        margin: 14px 0 0;
        color: var(--muted);
        font-size: 18px;
    }

    @media (max-width: 1100px) {
        .scene { padding-inline: 18px; }
        .topbar { padding-inline: 16px; }
        .ranking-list { width: min(90vw, 1120px); }
        .ranking-row { grid-template-columns: 72px 106px minmax(0, 1fr) 92px; }
        .ranking-bar { left: 214px; right: 112px; }
    }

    @media (max-width: 760px) {
        .brand-logo { width: 44px; height: 44px; }
        .title { font-size: 15px; }
        .clock-pill { font-size: 12px; }
        .progress-orbit { width: 220px; }
        .team-card { width: 138px; height: 138px; }
        .team-card.is-mini { width: 96px; height: 54px; }
        .card-name { font-size: 18px; }
        .card-score { font-size: 28px; }
        .ranking-row {
            grid-template-columns: 1fr;
            gap: 8px;
            padding-bottom: 18px;
        }
        .ranking-bar {
            position: relative;
            left: 0;
            right: auto;
            bottom: auto;
            margin-top: 10px;
            width: 100%;
        }
        .ranking-score { text-align: left; }
    }
</style>
</head>
<body>
<div class="ceremony-root" id="ceremonyRoot">
    <div class="bg-glow bg-glow-a"></div>
    <div class="bg-glow bg-glow-b"></div>
    <div class="bg-grid"></div>

    <div class="topbar">
        <div class="brand">
            <img class="brand-logo" src="<?= e(APP_URL) ?>/tv/assets/thanafus-logo.png" alt="Thanafus Logo">
            <div class="brand-copy">
                <div class="eyebrow">Musabaqa Ceremonial Ranking</div>
                <div class="title">Diamond Reveal to Final Ranking</div>
            </div>
        </div>

        <div class="clock-pill" id="refreshStamp">Live • refreshing every 5s</div>

        <div class="brand" style="justify-content:flex-end; text-align:right;">
            <div class="brand-copy">
                <div class="eyebrow">Broadcast Mode</div>
                <div class="title">Ceremony Board</div>
            </div>
            <img class="brand-logo" src="<?= e(APP_URL) ?>/tv/assets/kauzariyya-logo.png" alt="Kauzariyya Logo">
        </div>
    </div>

    <main class="stage">
        <section class="scene is-active" id="sceneCeremony">
            <div class="ceremony-scene">
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

                <div class="diamond-layout" id="diamondLayout" aria-label="Team reveal"></div>

                <div class="ranking-layout" id="rankingLayout" aria-label="Final ranking">
                    <div class="ranking-list" id="rankingList"></div>
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
window.TV_CEREMONY = {
    apiUrl: <?= json_encode('leaderboard_ceremony.php?api=ceremony', JSON_UNESCAPED_SLASHES) ?>,
    refreshMs: 5000,
    holdMs: 18000,
    phases: {
        intro: 2000,
        count: 2600,
        travel: 1650,
        hold: 900,
        returnTravel: 1350
    }
};

window.TV_CEREMONY_INITIAL = <?= json_encode($initialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/MotionPathPlugin.min.js"></script>
<script>
(() => {
    'use strict';

    if (window.gsap && window.MotionPathPlugin) {
        gsap.registerPlugin(MotionPathPlugin);
    }

    const config = window.TV_CEREMONY || {};
    const initial = window.TV_CEREMONY_INITIAL || { teams: [], progress: { total: 0, completed: 0, percentage: 0 } };

    const root = document.getElementById('ceremonyRoot');
    const diamondLayout = document.getElementById('diamondLayout');
    const rankingLayout = document.getElementById('rankingLayout');
    const rankingList = document.getElementById('rankingList');
    const emptyState = document.getElementById('emptyState');
    const refreshStamp = document.getElementById('refreshStamp');
    const ringProgress = document.getElementById('ringProgress');
    const progressPercent = document.getElementById('progressPercent');
    const progressCount = document.getElementById('progressCount');

    const state = {
        teams: normalizeTeams(initial.teams || []),
        progress: initial.progress || { total: 0, completed: 0, percentage: 0 },
        latest: null,
        busy: false,
        cycleToken: 0,
        refreshTimer: null,
        clockTimer: null,
        cards: [],
        diamondCenters: [],
        rankingTargets: [],
        rankingRows: []
    };

    function normalizeTeams(teams) {
        return (teams || [])
            .slice(0, 4)
            .map((team, index) => ({
                id: Number(team.id || index),
                team_name: String(team.team_name || `Team ${index + 1}`),
                team_color: String(team.team_color || '#1fe08a'),
                total_score: Number(team.total_score || 0)
            }))
            .sort((a, b) => b.total_score - a.total_score || a.team_name.localeCompare(b.team_name));
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function formatScore(value) {
        const n = Number(value || 0);
        if (Number.isInteger(n)) return String(n);
        return n.toFixed(2).replace(/\.00$/, '');
    }

    function getOrdinal(n) {
        if (n === 1) return '1st';
        if (n === 2) return '2nd';
        if (n === 3) return '3rd';
        return `${n}th`;
    }

    function setClock() {
        const now = new Date();
        const text = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if (refreshStamp) refreshStamp.textContent = `Live • ${text}`;
    }

    function setProgress(progress) {
        const total = Number(progress?.total || 0);
        const completed = Number(progress?.completed || 0);
        const percent = Number(progress?.percentage || 0);
        const radius = 90;
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (percent / 100) * circumference;

        if (ringProgress) {
            ringProgress.style.strokeDasharray = String(circumference);
            ringProgress.style.strokeDashoffset = String(offset);
        }
        if (progressPercent) progressPercent.textContent = `${Math.max(0, Math.min(100, percent))}%`;
        if (progressCount) progressCount.textContent = `${completed} / ${total}`;
    }

    function cardMarkup(team, index) {
        return `
            <div class="team-card position-${index + 1}" data-index="${index}">
                <div class="team-card-inner">
                    <div class="card-rank">${index + 1}</div>
                    <div class="card-name">${escapeHtml(team.team_name)}</div>
                    <div class="card-score" data-role="cardScore">${formatScore(team.total_score)}</div>
                    <div class="card-chip"><span class="team-dot" style="background:${escapeHtml(team.team_color)}"></span><span>${escapeHtml(team.team_name)}</span></div>
                </div>
            </div>
        `;
    }

    function renderDiamonds(teams) {
        if (!diamondLayout) return;
        diamondLayout.innerHTML = teams.map((team, index) => cardMarkup(team, index)).join('');
        state.cards = Array.from(diamondLayout.querySelectorAll('.team-card'));
        state.cards.forEach((card, index) => {
            const team = teams[index];
            card.style.color = team.team_color;
            card.style.borderColor = team.team_color;
            const dot = card.querySelector('.team-dot');
            if (dot) dot.style.background = team.team_color;
            const nameEl = card.querySelector('.card-name');
            if (nameEl) nameEl.textContent = team.team_name;
            const chipLabel = card.querySelector('.card-chip span:last-child');
            if (chipLabel) chipLabel.textContent = team.team_name;
            const scoreEl = card.querySelector('[data-role="cardScore"]');
            if (scoreEl) scoreEl.textContent = '0';
        });
    }

    function renderRankingRows(teams) {
        if (!rankingList) return;
        const max = Math.max(...teams.map((t) => Number(t.total_score || 0)), 1);
        rankingList.innerHTML = teams.map((team, index) => {
            const width = Math.max(12, Math.round((Number(team.total_score || 0) / max) * 100));
            return `
                <article class="ranking-row" data-index="${index}" style="color:${escapeHtml(team.team_color)};">
                    <div class="ranking-place">${escapeHtml(getOrdinal(index + 1))}</div>
                    <div class="rank-slot"><div class="rank-label">dock</div></div>
                    <div class="ranking-team-name">${escapeHtml(team.team_name)}</div>
                    <div class="ranking-score">${escapeHtml(formatScore(team.total_score))}</div>
                    <div class="ranking-bar"><span data-bar style="width:${width}%"></span></div>
                </article>
            `;
        }).join('');
        state.rankingRows = Array.from(rankingList.querySelectorAll('.ranking-row'));
        state.rankingTargets = Array.from(rankingList.querySelectorAll('.rank-slot'));
        state.rankingRows.forEach((row) => {
            const bar = row.querySelector('[data-bar]');
            if (bar) bar.style.width = '0%';
        });
    }

    function layoutDiamondCenters() {
        if (!state.cards.length) return;
        const rootRect = root.getBoundingClientRect();
        const positions = [
            { x: rootRect.width * 0.50, y: rootRect.height * 0.23 },
            { x: rootRect.width * 0.22, y: rootRect.height * 0.50 },
            { x: rootRect.width * 0.78, y: rootRect.height * 0.50 },
            { x: rootRect.width * 0.50, y: rootRect.height * 0.77 },
        ];

        state.cards.forEach((card, index) => {
            const pos = positions[index];
            card.style.left = `${pos.x}px`;
            card.style.top = `${pos.y}px`;
        });
        state.diamondCenters = positions;
    }

    function cardCenterForSlot(slotEl) {
        const rootRect = root.getBoundingClientRect();
        const slotRect = slotEl.getBoundingClientRect();
        return {
            x: slotRect.left - rootRect.left + slotRect.width / 2,
            y: slotRect.top - rootRect.top + slotRect.height / 2
        };
    }

    function animateDiamondPulse() {
        if (!window.gsap || !state.cards.length) return;
        state.cards.forEach((card, index) => {
            gsap.killTweensOf(card);
            gsap.to(card, {
                y: (index % 2 === 0 ? -10 : -6),
                duration: 1.6 + index * 0.1,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: index * 0.12
            });
        });
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    async function refreshData() {
        try {
            const json = await fetchJson(config.apiUrl);
            if (json?.success) {
                state.latest = {
                    teams: normalizeTeams(json.teams || []),
                    progress: json.progress || { total: 0, completed: 0, percentage: 0 }
                };
                if (!state.busy) {
                    state.teams = state.latest.teams;
                    state.progress = state.latest.progress;
                    rebuildScene();
                }
                setClock();
            }
        } catch (error) {
            console.error('Ceremony refresh failed:', error);
        }
    }

    function rebuildScene() {
        if (!state.teams.length) {
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';
        renderDiamonds(state.teams);
        renderRankingRows(state.teams);
        layoutDiamondCenters();
        setProgress(state.progress);
        animateDiamondPulse();
    }

    function setMode(mode) {
        if (!window.gsap) {
            diamondLayout.style.opacity = mode === 'diamond' ? '1' : '0';
            rankingLayout.style.opacity = mode === 'ranking' ? '1' : '0';
            return Promise.resolve();
        }

        const tl = gsap.timeline();
        tl.to(diamondLayout, { opacity: mode === 'diamond' ? 1 : 0, duration: 0.35, ease: 'power2.out' }, 0)
          .to(rankingLayout, { opacity: mode === 'ranking' ? 1 : 0, duration: 0.35, ease: 'power2.out' }, 0);
        return tl;
    }

    function countScore(node, target, duration = 2.2) {
        return new Promise((resolve) => {
            if (!window.gsap) {
                node.textContent = formatScore(target);
                resolve();
                return;
            }
            const obj = { value: 0 };
            gsap.to(obj, {
                value: target,
                duration,
                ease: 'power3.out',
                onUpdate: () => {
                    node.textContent = formatScore(obj.value);
                },
                onComplete: () => {
                    node.textContent = formatScore(target);
                    resolve();
                }
            });
        });
    }

    function curveTween(card, toX, toY, toW, toH, opts = {}) {
        const start = card.getBoundingClientRect();
        const rootRect = root.getBoundingClientRect();
        const startCenter = {
            x: start.left - rootRect.left + start.width / 2,
            y: start.top - rootRect.top + start.height / 2
        };
        const mid = {
            x: (startCenter.x + toX) / 2 + (opts.curveX || 0),
            y: (startCenter.y + toY) / 2 + (opts.curveY || 0)
        };

        const path = [
            { x: startCenter.x, y: startCenter.y },
            { x: mid.x, y: mid.y },
            { x: toX, y: toY }
        ];

        if (window.MotionPathPlugin) {
            return gsap.to(card, {
                duration: opts.duration || 1.4,
                ease: 'power3.inOut',
                motionPath: { path, autoRotate: false },
                width: toW,
                height: toH,
                rotation: opts.rotation ?? 0,
                borderRadius: opts.radius ?? 18
            });
        }

        return gsap.to(card, {
            duration: opts.duration || 1.4,
            ease: 'power3.inOut',
            left: `${toX}px`,
            top: `${toY}px`,
            width: toW,
            height: toH,
            rotation: opts.rotation ?? 0,
            borderRadius: opts.radius ?? 18
        });
    }

    function miniCardMode(card, team) {
        card.classList.add('is-mini');
        card.style.color = team.team_color;
        card.style.borderColor = team.team_color;
        const nameEl = card.querySelector('.card-name');
        if (nameEl) nameEl.textContent = team.team_name;
        const scoreEl = card.querySelector('[data-role="cardScore"]');
        if (scoreEl) scoreEl.textContent = formatScore(team.total_score);
        const chip = card.querySelector('.card-chip span:last-child');
        if (chip) chip.textContent = team.team_name;
    }

    function bigCardMode(card, team) {
        card.classList.remove('is-mini');
        card.style.color = team.team_color;
        card.style.borderColor = team.team_color;
        const nameEl = card.querySelector('.card-name');
        if (nameEl) nameEl.textContent = team.team_name;
        const scoreEl = card.querySelector('[data-role="cardScore"]');
        if (scoreEl) scoreEl.textContent = '0';
        const chip = card.querySelector('.card-chip span:last-child');
        if (chip) chip.textContent = team.team_name;
    }

    async function runCycle() {
        if (state.busy || !state.teams.length) return;
        state.busy = true;
        const token = ++state.cycleToken;

        const teams = state.teams.slice(0, 4);
        const scoreNodes = state.cards.map((card) => card.querySelector('[data-role="cardScore"]'));

        renderDiamonds(teams);
        renderRankingRows(teams);
        layoutDiamondCenters();
        setProgress(state.progress);

        if (window.gsap) {
            gsap.set(rankingLayout, { opacity: 0, scale: 0.99 });
            gsap.set(diamondLayout, { opacity: 1 });
            gsap.fromTo(state.cards, { opacity: 0, scale: 0.94, rotation: 45 }, { opacity: 1, scale: 1, rotation: 45, duration: 0.65, stagger: 0.08, ease: 'power3.out' });
        }

        await wait(config.phases?.intro || 2000);
        if (token !== state.cycleToken) return;

        await Promise.all(teams.map((team, index) => countScore(scoreNodes[index], team.total_score, 2.2)));
        if (token !== state.cycleToken) return;

        await wait(config.phases?.hold || 900);
        if (token !== state.cycleToken) return;

        if (window.gsap) {
            gsap.set(rankingLayout, { opacity: 1, scale: 1 });
            gsap.set(diamondLayout, { opacity: 1 });
        } else {
            rankingLayout.style.opacity = '1';
        }

        const targetPositions = state.rankingTargets.map((slotEl) => cardCenterForSlot(slotEl));
        const barNodes = state.rankingRows.map((row) => row.querySelector('[data-bar]'));
        const max = Math.max(...teams.map((t) => Number(t.total_score || 0)), 1);

        const travelAnimations = [];
        state.cards.forEach((card, index) => {
            const team = teams[index];
            const target = targetPositions[index];
            const cardWidth = 108;
            const cardHeight = 60;
            miniCardMode(card, team);
            travelAnimations.push(curveTween(card, target.x, target.y, cardWidth, cardHeight, {
                duration: 1.65,
                curveX: index % 2 === 0 ? -90 : 90,
                curveY: index % 2 === 0 ? 40 : -40,
                radius: 18,
                rotation: 0
            }));
        });

        if (window.gsap) {
            const barTweens = barNodes.map((bar, index) => {
                const team = teams[index];
                const width = Math.max(12, Math.round((Number(team.total_score || 0) / max) * 100));
                return gsap.to(bar, {
                    width: `${width}%`,
                    duration: 1.3,
                    ease: 'power3.out',
                    delay: 0.15 + index * 0.08
                });
            });
            travelAnimations.push(...barTweens);
            gsap.to(rankingLayout, { opacity: 1, duration: 0.45, ease: 'power2.out' });
        } else {
            barNodes.forEach((bar, index) => {
                const team = teams[index];
                const width = Math.max(12, Math.round((Number(team.total_score || 0) / max) * 100));
                bar.style.width = `${width}%`;
            });
            rankingLayout.style.opacity = '1';
        }

        await Promise.allSettled(travelAnimations.map((p) => Promise.resolve(p)));
        if (token !== state.cycleToken) return;

        await wait(config.holdMs || 18000);
        if (token !== state.cycleToken) return;

        if (window.gsap) {
            const backTweens = state.cards.map((card, index) => {
                const team = teams[index];
                bigCardMode(card, team);
                const start = state.diamondCenters[index];
                const cardWidth = 160;
                const cardHeight = 160;
                return curveTween(card, start.x, start.y, cardWidth, cardHeight, {
                    duration: 1.25,
                    curveX: index % 2 === 0 ? 60 : -60,
                    curveY: index % 2 === 0 ? -30 : 30,
                    radius: 26,
                    rotation: 45
                });
            });
            gsap.to(rankingLayout, { opacity: 0, duration: 0.45, ease: 'power2.in' });
            await Promise.allSettled(backTweens.map((p) => Promise.resolve(p)));
        } else {
            rankingLayout.style.opacity = '0';
            state.cards.forEach((card, index) => {
                const team = teams[index];
                bigCardMode(card, team);
                const start = state.diamondCenters[index];
                card.style.left = `${start.x}px`;
                card.style.top = `${start.y}px`;
                card.style.width = '160px';
                card.style.height = '160px';
                card.style.transform = 'translate(-50%, -50%) rotate(45deg)';
            });
        }

        state.busy = false;
        await refreshData();
        if (token !== state.cycleToken) return;
        runCycle();
    }

    function wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function onResize() {
        if (!state.cards.length) return;
        layoutDiamondCenters();
    }

    async function boot() {
        rebuildScene();
        setClock();
        await refreshData();
        window.addEventListener('resize', onResize, { passive: true });
        state.clockTimer = setInterval(setClock, 1000);
        state.refreshTimer = setInterval(refreshData, config.refreshMs || 5000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshData();
        });
        window.addEventListener('focus', refreshData);
        runCycle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
</script>
</body>
</html>
