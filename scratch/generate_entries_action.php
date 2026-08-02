<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/admin-helpers.php';

// Function to find next entry number
function get_next_entry_number(PDO $pdo, int $eventId, int $programId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(entry_number), 0) + 1
        FROM musabaqa_program_entries
        WHERE event_id = ? AND program_id = ?
    ");
    $stmt->execute([$eventId, $programId]);
    return max(1, (int)$stmt->fetchColumn());
}

// Load settings
$settingsStmt = $musabaqa_pdo->query("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
$settingsJson = $settingsStmt->fetchColumn();
$settings = json_decode($settingsJson, true) ?: [
    'default_entries_limit' => 10,
    'section_limits' => []
];

$eventIds = [13, 8];

foreach ($eventIds as $eventId) {
    echo "\n========================================\n";
    echo "PROCESSING EVENT ID: $eventId\n";
    
    // Get Event Title
    $eventStmt = $musabaqa_pdo->prepare("SELECT title FROM musabaqa_events WHERE id = ?");
    $eventStmt->execute([$eventId]);
    $eventTitle = $eventStmt->fetchColumn();
    if (!$eventTitle) {
        echo "Event ID $eventId not found. Skipping.\n";
        continue;
    }
    echo "Event Title: $eventTitle\n";
    
    // Start Transaction
    $musabaqa_pdo->beginTransaction();
    
    try {
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
        
        // Pre-load current counts from DB
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
        
        $insertedCount = 0;
        $placeholderCount = 0;
        
        foreach ($programs as $prog) {
            $progId = (int)$prog['id'];
            $limit = (int)($prog['entries_limit'] ?: ($settings['default_entries_limit'] ?: 10));
            $allowedSections = array_filter(array_map('trim', explode(',', $prog['allowed_sections'] ?? '')));
            $isGroup = ($prog['program_type'] === 'group');
            $stageId = (int)$prog['stage_type_id'];
            $isOnStage = ($stageId === 1);
            
            foreach ($teams as $team) {
                $teamId = (int)$team['id'];
                
                // Count current entries in DB for this team/program
                $cntStmt = $musabaqa_pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ?");
                $cntStmt->execute([$progId, $teamId]);
                $currentCount = (int)$cntStmt->fetchColumn();
                
                $needed = $limit - $currentCount;
                if ($needed <= 0) {
                    continue;
                }
                
                for ($i = 0; $i < $needed; $i++) {
                    $entryNumber = get_next_entry_number($musabaqa_pdo, $eventId, $progId);
                    
                    if ($isGroup) {
                        // Create Group Entry
                        $entryName = ($limit === 1) ? $team['team_name'] : "{$team['team_name']} Group " . ($currentCount + $i + 1);
                        
                        $ins = $musabaqa_pdo->prepare("
                            INSERT INTO musabaqa_program_entries (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'approved')
                        ");
                        $ins->execute([$eventId, $progId, $teamId, $entryName, $entryNumber, rand(1, 1000000)]);
                        $insertedCount++;
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
                                // Assign this member!
                                $ins = $musabaqa_pdo->prepare("
                                    INSERT INTO musabaqa_program_entries (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                                    VALUES (?, ?, ?, ?, ?, ?, 'approved')
                                ");
                                $ins->execute([$eventId, $progId, $teamId, $member['full_name'], $entryNumber, rand(1, 1000000)]);
                                $newEntryId = (int)$musabaqa_pdo->lastInsertId();
                                
                                $insMem = $musabaqa_pdo->prepare("
                                    INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name)
                                    VALUES (?, ?, 'Participant')
                                ");
                                $insMem->execute([$newEntryId, $mId]);
                                
                                $countsMap[$mId][$stageId]++;
                                $inProgMap[$mId][$progId] = true;
                                $insertedCount++;
                                $assigned = true;
                                break;
                            }
                        }
                        
                        if (!$assigned) {
                            // Create placeholder candidate entry
                            $entryName = "{$team['team_name']} Candidate";
                            $ins = $musabaqa_pdo->prepare("
                                INSERT INTO musabaqa_program_entries (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'approved')
                            ");
                            $ins->execute([$eventId, $progId, $teamId, $entryName, $entryNumber, rand(1, 1000000)]);
                            $insertedCount++;
                            $placeholderCount++;
                        }
                    }
                }
            }
            
            // Recalculate status for the program
            admin_recalculate_program_status($musabaqa_pdo, $progId);
        }
        
        $musabaqa_pdo->commit();
        echo "SUCCESS: Transaction committed for Event $eventId.\n";
        echo "Total program entries inserted: $insertedCount (Placeholder candidates: $placeholderCount)\n";
        
    } catch (Throwable $e) {
        $musabaqa_pdo->rollBack();
        echo "ERROR: Transaction rolled back for Event $eventId.\n";
        echo "Message: " . $e->getMessage() . "\n";
    }
}
