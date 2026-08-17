<?php
$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';
$DB_DASHBOARD = 'ensplpmy_kauzariyya_dashboard';

try {
    $remotePdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_MUSABAQA};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);

    // Get active event
    $event = $remotePdo->query("SELECT id, title FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
    $eventId = (int)$event['id'];
    echo "=== Active Event: {$event['title']} (ID: {$eventId}) ===\n\n";

    // Query teams
    $teamsStmt = $remotePdo->prepare("SELECT id, team_name, total_score FROM musabaqa_teams WHERE event_id = ?");
    $teamsStmt->execute([$eventId]);
    $teams = $teamsStmt->fetchAll();

    // Query raw logs
    $rawLogsStmt = $remotePdo->prepare("
        SELECT 
            pe.id AS entry_id,
            pe.program_id,
            pe.team_id,
            pe.entry_name,
            pe.entry_number,
            pe.final_score,
            pe.final_rank,
            pe.team_score,
            p.title AS program_title,
            p.program_type,
            p.disable_scores,
            COALESCE(p.reviewed_at, p.submitted_at, p.created_at) AS approved_at,
            t.team_name,
            t.team_color
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.event_id = ?
          AND p.approval_status = 'approved'
          AND pe.team_score > 0
        ORDER BY COALESCE(p.reviewed_at, p.submitted_at, p.created_at) ASC, pe.final_rank ASC, pe.id ASC
    ");
    $rawLogsStmt->execute([$eventId]);
    $rawLogs = $rawLogsStmt->fetchAll();

    $teamRunningTotals = [];
    foreach ($teams as $t) {
        $teamRunningTotals[(int)$t['id']] = 0.0;
    }

    echo "=== Processing logs chronologically ===\n";
    foreach ($rawLogs as $log) {
        $tId = (int)$log['team_id'];
        $pts = (float)$log['team_score'];
        $rank = (int)$log['final_rank'];

        $prevTotal = $teamRunningTotals[$tId] ?? 0.0;
        $newTotal = $prevTotal + $pts;
        $teamRunningTotals[$tId] = $newTotal;

        echo "Entry ID: {$log['entry_id']} | Program: {$log['program_title']} (ID: {$log['program_id']}) | Team: {$log['team_name']} | Score Added: +{$pts} | Prev Total: {$prevTotal} | New Total: {$newTotal} | Approved At: {$log['approved_at']}\n";
    }

    echo "\n=== Final Running Totals ===\n";
    foreach ($teams as $t) {
        $tId = (int)$t['id'];
        echo "Team: {$t['team_name']} | Current DB Score: {$t['total_score']} | Calculated Running Total: " . ($teamRunningTotals[$tId] ?? 0) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
