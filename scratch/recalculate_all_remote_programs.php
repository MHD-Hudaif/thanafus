<?php
define('ALLOW_ACCESS', true);

// Set DB names for remote before requiring database or helpers
define('DB_MAIN_NAME', 'ensplpmy_kauzariyya_dashboard');
define('DB_MUSABAQA_NAME', 'ensplpmy_kauzariyya_musabaqa');

require_once __DIR__ . '/../includes/admin-helpers.php';

$DB_HOST = '162.214.80.164';
$DB_USER = 'ensplpmy_hudaif';
$DB_PASS = 'abd527-157';
$DB_MUSABAQA = 'ensplpmy_kauzariyya_musabaqa';

try {
    echo "Connecting to remote Bluehost DB...\n";
    $remotePdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_MUSABAQA};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 15
    ]);
    echo "Connected.\n";

    $GLOBALS['musabaqa_pdo'] = $remotePdo;

    // 1. Fetch active event
    $stmtEvent = $remotePdo->query("
        SELECT id, title FROM musabaqa_events
        WHERE status = 'active'
        ORDER BY id DESC LIMIT 1
    ");
    $event = $stmtEvent->fetch();
    if (!$event) {
        throw new Exception("No active event found.");
    }
    $eventId = (int)$event['id'];
    echo "Active Event: {$event['title']} (ID: {$eventId})\n";

    // 2. Fetch all programs for this event
    $stmtProgs = $remotePdo->prepare("
        SELECT id, title, approval_status, status
        FROM musabaqa_programs
        WHERE event_id = ?
    ");
    $stmtProgs->execute([$eventId]);
    $programs = $stmtProgs->fetchAll();
    echo "Found " . count($programs) . " programs. Starting recalculation...\n";

    $recalculatedCount = 0;
    foreach ($programs as $p) {
        $pid = (int)$p['id'];
        // We only need to recalculate results for programs that are approved/completed
        // or have submitted score sheets, but running on all is safe.
        // Let's print progress for programs that are approved.
        if ($p['approval_status'] === 'approved' || $p['status'] === 'completed') {
            echo "Recalculating approved program: {$p['title']} (ID: {$pid})...\n";
            admin_recalculate_program_results($remotePdo, $eventId, $pid);
            $recalculatedCount++;
        }
    }

    echo "Recalculated {$recalculatedCount} approved programs.\n";

    // 3. Recalculate team totals
    echo "Recalculating team totals for the event...\n";
    admin_recalculate_team_totals($remotePdo, $eventId);
    echo "Team totals recalculated successfully.\n";

    // 4. Verify Malayalam Speech (ID 96) again to make sure
    $stmtVerify = $remotePdo->prepare("
        SELECT pe.id, pe.final_rank, pe.team_score, pe.grade, pe.grade_points
        FROM musabaqa_program_entries pe
        WHERE pe.program_id = 96 ORDER BY pe.final_rank ASC
    ");
    $stmtVerify->execute();
    $entries = $stmtVerify->fetchAll();
    echo "\n=== Verification of Malayalam Speech (ID 96) ===\n";
    foreach ($entries as $e) {
        if ($e['final_rank'] !== null) {
            echo "Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Grade: {$e['grade']} | Grade Pts: {$e['grade_points']}\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
