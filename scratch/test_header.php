<?php
$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';

try {
    $remotePdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_MUSABAQA};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);

    // Simulating tv_active_event
    $stmt = $remotePdo->query("SELECT * FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $event = $stmt->fetch() ?: null;
    $eventId = (int)($event['id'] ?? 0);

    // Simulating tv_leaderboard
    $manualFirst = ($event['scoreboard_mode'] ?? 'system') === 'manual';
    $scoreExpr = $manualFirst
        ? 'COALESCE(manual_scores.score, t.total_score, approved_scores.total_score, 0)'
        : 'COALESCE(approved_scores.total_score, t.total_score, manual_scores.score, 0)';

    $stmt = $remotePdo->prepare("
        SELECT
            t.id,
            t.team_name,
            t.short_name,
            t.team_color,
            {$scoreExpr} AS total_score
        FROM musabaqa_teams t
        LEFT JOIN (
            SELECT pe.team_id, SUM(pe.team_score) AS total_score
            FROM musabaqa_program_entries pe
            JOIN musabaqa_programs p ON p.id = pe.program_id
            WHERE pe.event_id = ?
              AND p.approval_status = 'approved'
              AND (p.redirect_to_team IS NULL OR p.redirect_to_team = 1)
            GROUP BY pe.team_id
        ) approved_scores ON approved_scores.team_id = t.id
        LEFT JOIN musabaqa_manual_scoreboard manual_scores
               ON manual_scores.team_id = t.id
              AND manual_scores.event_id = ?
        WHERE t.event_id = ?
        ORDER BY total_score DESC, t.team_name ASC, t.id ASC
    ");
    $stmt->execute([$eventId, $eventId, $eventId]);
    $headerLeaderboard = $stmt->fetchAll();

    $headerFirstTeam = !empty($headerLeaderboard) ? $headerLeaderboard[0] : null;
    
    // Simulating live_display_color
    function test_live_display_color(?string $value, string $fallback = '#00ff88'): string
    {
        $value = trim((string)$value);
        if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $value)) {
            return $value;
        }
        return $fallback;
    }

    $headerFirstColor = !empty($headerFirstTeam['team_color']) ? test_live_display_color($headerFirstTeam['team_color']) : '#00aaff';

    echo "Event ID: $eventId\n";
    echo "First Team Name: " . ($headerFirstTeam['team_name'] ?? 'none') . "\n";
    echo "First Team Color in DB: " . ($headerFirstTeam['team_color'] ?? 'none') . "\n";
    echo "headerFirstColor computed: $headerFirstColor\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
