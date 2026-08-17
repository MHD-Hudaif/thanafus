<?php
require_once __DIR__ . '/../config/database.php';

$musabaqaPdo = $GLOBALS['musabaqa_pdo'];

echo "=== SEARCHING FOR MALAYALAM SPEECH IN PROGRAMS ===\n";
try {
    $stmt = $musabaqaPdo->query("SELECT id, title, event_id, status, approval_status, judges_count FROM musabaqa_programs WHERE title LIKE '%malayalam%'");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($programs)) {
        echo "No programs found with 'malayalam' in the title.\n";
    } else {
        foreach ($programs as $p) {
            echo "ID: {$p['id']} | Title: {$p['title']} | Event ID: {$p['event_id']} | Status: {$p['status']} | Approval: {$p['approval_status']} | Judges: {$p['judges_count']}\n";
            
            // Check entries for this program
            $stmtEntries = $musabaqaPdo->prepare("
                SELECT pe.*
                FROM musabaqa_program_entries pe
                WHERE pe.program_id = ?
            ");
            $stmtEntries->execute([$p['id']]);
            $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
            echo "  Entries (Total " . count($entries) . "):\n";
            foreach ($entries as $e) {
                echo "    Entry ID: {$e['id']} | Team ID: {$e['team_id']} | Final Score: {$e['final_score']} | Rank: {$e['final_rank']} | Team Score: {$e['team_score']} | Grade: {$e['grade']} | Grade Pts: {$e['grade_points']} | Status: {$e['status']}\n";
            }
            
            // Check score sheets
            $stmtSheets = $musabaqaPdo->prepare("
                SELECT ss.*
                FROM musabaqa_score_sheets ss
                WHERE ss.program_id = ?
            ");
            $stmtSheets->execute([$p['id']]);
            $sheets = $stmtSheets->fetchAll(PDO::FETCH_ASSOC);
            echo "  Score Sheets:\n";
            foreach ($sheets as $s) {
                echo "    ID: {$s['id']} | Entry ID: {$s['entry_id']} | Final Total: {$s['final_total']} | Status: {$s['status']} | Created By: " . ($s['created_by_judge_id'] ?? $s['judge_id'] ?? $s['judge_no'] ?? 'N/A') . "\n";
                // Let's print all keys in $s
                echo "      Keys: " . implode(', ', array_keys($s)) . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
