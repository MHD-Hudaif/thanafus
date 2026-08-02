<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Load settings
$settingsStmt = $musabaqa_pdo->query("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
$settingsJson = $settingsStmt->fetchColumn();
$settings = json_decode($settingsJson, true) ?: [
    'default_entries_limit' => 10,
    'section_limits' => []
];

$eventId = 8;

echo "SIMULATING EVENT ID: $eventId\n";

// Get Teams
$teamsStmt = $musabaqa_pdo->prepare("SELECT id, team_name FROM musabaqa_teams WHERE event_id = ?");
$teamsStmt->execute([$eventId]);
$teams = $teamsStmt->fetchAll();

// Get Programs
$progsStmt = $musabaqa_pdo->prepare("SELECT * FROM musabaqa_programs WHERE event_id = ?");
$progsStmt->execute([$eventId]);
$programs = $progsStmt->fetchAll();

// Fetch all members of this event with their sections
$membersStmt = $musabaqa_pdo->prepare("
    SELECT tm.id, tm.team_id, c.class_type_id, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name
    FROM musabaqa_team_members tm
    JOIN kauzariyya.students s ON s.id = tm.student_id
    LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
    WHERE tm.event_id = ? AND tm.status = 'active'
");
$membersStmt->execute([$eventId]);
$rawMembers = $membersStmt->fetchAll();

// Group members by team
$teamMembers = [];
foreach ($rawMembers as $m) {
    $teamMembers[(int)$m['team_id']][] = [
        'id' => (int)$m['id'],
        'class_type_id' => (int)$m['class_type_id'],
        'full_name' => $m['full_name']
    ];
}

// In memory counts map: member_id => [stage_type_id => count]
$countsMap = [];
foreach ($rawMembers as $m) {
    $countsMap[(int)$m['id']] = [1 => 0, 2 => 0]; // 1 = on-stage, 2 = off-stage
}

// Pre-load current counts
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

$simulatedEntriesCount = 0;
$placeholdersCount = 0;

foreach ($programs as $prog) {
    $progId = (int)$prog['id'];
    $limit = (int)($prog['entries_limit'] ?: ($settings['default_entries_limit'] ?: 10));
    $allowedSections = array_filter(array_map('trim', explode(',', $prog['allowed_sections'] ?? '')));
    $isGroup = ($prog['program_type'] === 'group');
    $stageId = (int)$prog['stage_type_id'];
    $isOnStage = ($stageId === 1);
    
    foreach ($teams as $team) {
        $teamId = (int)$team['id'];
        
        // Count current entries
        $cntStmt = $musabaqa_pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ?");
        $cntStmt->execute([$progId, $teamId]);
        $currentCount = (int)$cntStmt->fetchColumn();
        
        $needed = $limit - $currentCount;
        if ($needed <= 0) {
            continue;
        }
        
        for ($i = 0; $i < $needed; $i++) {
            if ($isGroup) {
                // Group program entries can just be placeholder group entries without members
                $simulatedEntriesCount++;
            } else {
                // Find an eligible member
                $assigned = false;
                $tMembers = $teamMembers[$teamId] ?? [];
                
                foreach ($tMembers as $member) {
                    $mId = $member['id'];
                    $classTypeId = $member['class_type_id'];
                    
                    // Check section
                    if ($allowedSections && !in_array((string)$classTypeId, $allowedSections, true)) {
                        continue;
                    }
                    
                    // Check already in this program
                    if (isset($inProgMap[$mId][$progId])) {
                        continue;
                    }
                    
                    // Check stage type limit
                    $maxLimit = (int)($settings['section_limits'][$classTypeId][$isOnStage ? 'on_stage' : 'off_stage'] ?? ($isOnStage ? 2 : 3));
                    $currentStageCount = $countsMap[$mId][$stageId] ?? 0;
                    
                    if ($currentStageCount < $maxLimit) {
                        // Assign!
                        $countsMap[$mId][$stageId]++;
                        $inProgMap[$mId][$progId] = true;
                        $simulatedEntriesCount++;
                        $assigned = true;
                        break;
                    }
                }
                
                if (!$assigned) {
                    // Create a placeholder candidate entry
                    $simulatedEntriesCount++;
                    $placeholdersCount++;
                }
            }
        }
    }
}

echo "\nSimulation Finished!\n";
echo "Total simulated entries to insert: $simulatedEntriesCount\n";
echo "Placeholder entries: $placeholdersCount\n";
