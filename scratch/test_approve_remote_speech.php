<?php
define('ALLOW_ACCESS', true);
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
        PDO::ATTR_TIMEOUT => 10
    ]);
    echo "Connected.\n";

    echo "Attempting to approve program 96...\n";
    admin_approve_program_scores($remotePdo, 8, 96, 1, false);
    echo "Successfully approved program 96!\n";

    // Let's run recalculate results just to be sure
    echo "Recalculating results...\n";
    admin_recalculate_program_results($remotePdo, 8, 96);
    echo "Recalculated.\n";

    // Query entries again to see if they got updated
    $stmt = $remotePdo->prepare("SELECT id, final_score, final_rank, team_score, grade, grade_points, status FROM musabaqa_program_entries WHERE program_id = 96");
    $stmt->execute();
    $entries = $stmt->fetchAll();
    foreach ($entries as $e) {
        echo "Entry ID: {$e['id']} | Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Grade: {$e['grade']} | Grade Pts: {$e['grade_points']} | Status: {$e['status']}\n";
    }

} catch (Exception $e) {
    echo "Error approving/recalculating: " . $e->getMessage() . "\n";
}
