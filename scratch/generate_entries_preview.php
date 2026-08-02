<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Load settings
$settingsStmt = $musabaqa_pdo->query("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
$settingsJson = $settingsStmt->fetchColumn();
$settings = json_decode($settingsJson, true) ?: [
    'default_entries_limit' => 10,
    'section_limits' => []
];

$eventIds = [8, 13];

foreach ($eventIds as $eventId) {
    echo "\n========================================\n";
    echo "PREVIEWING EVENT ID: $eventId\n";
    
    // Get Event Title
    $eventStmt = $musabaqa_pdo->prepare("SELECT title FROM musabaqa_events WHERE id = ?");
    $eventStmt->execute([$eventId]);
    $eventTitle = $eventStmt->fetchColumn();
    echo "Event Title: $eventTitle\n";
    
    // Get Teams
    $teamsStmt = $musabaqa_pdo->prepare("SELECT id, team_name FROM musabaqa_teams WHERE event_id = ?");
    $teamsStmt->execute([$eventId]);
    $teams = $teamsStmt->fetchAll();
    echo "Teams Count: " . count($teams) . "\n";
    
    // Get Programs
    $progsStmt = $musabaqa_pdo->prepare("SELECT * FROM musabaqa_programs WHERE event_id = ?");
    $progsStmt->execute([$eventId]);
    $programs = $progsStmt->fetchAll();
    echo "Programs Count: " . count($programs) . "\n";
    
    // Fetch all members of this event with their sections and current counts
    $membersStmt = $musabaqa_pdo->prepare("
        SELECT tm.id, tm.team_id, c.class_type_id, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name
        FROM musabaqa_team_members tm
        JOIN kauzariyya.students s ON s.id = tm.student_id
        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
        WHERE tm.event_id = ? AND tm.status = 'active'
    ");
    $membersStmt->execute([$eventId]);
    $members = $membersStmt->fetchAll();
    echo "Total Active Team Members: " . count($members) . "\n";
    
    // Pre-load current participant entry counts per stage type
    // We will build a helper map in memory: member_id => [stage_type_id => count]
    $countsMap = [];
    foreach ($members as $m) {
        $countsMap[$m['id']] = [1 => 0, 2 => 0]; // 1 = on-stage, 2 = off-stage
    }
    
    $existingEntriesStmt = $musabaqa_pdo->prepare("
        SELECT em.team_member_id, p.stage_type_id, COUNT(DISTINCT pe.id) as cnt
        FROM musabaqa_entry_members em
        JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.event_id = ?
        GROUP BY em.team_member_id, p.stage_type_id
    ");
    $existingEntriesStmt->execute([$eventId]);
    while ($row = $existingEntriesStmt->fetch()) {
        $tmId = (int)$row['team_member_id'];
        $stageId = (int)$row['stage_type_id'];
        if (isset($countsMap[$tmId])) {
            $countsMap[$tmId][$stageId] = (int)$row['cnt'];
        }
    }
    
    // Pre-load programs each member is already in
    $inProgMap = []; // member_id => [program_id => true]
    $existingProgsStmt = $musabaqa_pdo->prepare("
        SELECT em.team_member_id, pe.program_id
        FROM musabaqa_entry_members em
        JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
        WHERE pe.event_id = ?
    ");
    $existingProgsStmt->execute([$eventId]);
    while ($row = $existingProgsStmt->fetch()) {
        $tmId = (int)$row['team_member_id'];
        $pId = (int)$row['program_id'];
        $inProgMap[$tmId][$pId] = true;
    }
    
    // Check what entries are needed
    $totalNeededEntries = 0;
    $individualNeeded = 0;
    $groupNeeded = 0;
    
    foreach ($programs as $prog) {
        $progId = (int)$prog['id'];
        $limit = (int)($prog['entries_limit'] ?: ($settings['default_entries_limit'] ?: 10));
        $allowedSections = array_filter(array_map('trim', explode(',', $prog['allowed_sections'] ?? '')));
        $isGroup = ($prog['program_type'] === 'group');
        $stageId = (int)$prog['stage_type_id'];
        $isOnStage = ($stageId === 1);
        
        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            
            // Count current entries for this team and program
            $cntStmt = $musabaqa_pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ?");
            $cntStmt->execute([$progId, $teamId]);
            $currentCount = (int)$cntStmt->fetchColumn();
            
            $needed = $limit - $currentCount;
            if ($needed > 0) {
                $totalNeededEntries += $needed;
                if ($isGroup) {
                    $groupNeeded += $needed;
                } else {
                    $individualNeeded += $needed;
                }
            }
        }
    }
    
    echo "Total entries needed to reach limits: $totalNeededEntries (Individual: $individualNeeded, Group: $groupNeeded)\n";
}
