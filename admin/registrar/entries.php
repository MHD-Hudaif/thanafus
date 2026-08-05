<?php
$pageTitle = 'Entries Manager';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();
$_SESSION['active_workspace'] = 'registrar';

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function entries_redirect(array $query = []): void
{
    admin_redirect('/admin/registrar/entries.php', $query);
}

function entries_next_number(PDO $pdo, int $eventId, int $programId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(entry_number), 0) + 1
        FROM musabaqa_program_entries
        WHERE event_id = ? AND program_id = ?
    ");
    $stmt->execute([$eventId, $programId]);

    return max(1, (int)$stmt->fetchColumn());
}

function entries_next_performance_order(PDO $pdo, int $eventId, int $programId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(performance_order), 0) + 1
        FROM musabaqa_program_entries
        WHERE event_id = ? AND program_id = ?
    ");
    $stmt->execute([$eventId, $programId]);

    return max(1, (int)$stmt->fetchColumn());
}

function entries_status_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-info',
    };
}

function entries_load_program(PDO $pdo, int $eventId, int $programId): ?array
{
    $stmt = $pdo->prepare("
        SELECT mp.*, ct.name AS class_type_name
        FROM musabaqa_programs mp
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
        WHERE mp.id = ? AND mp.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    return $program ?: null;
}

// Fetch all schedule sessions for this event
$stmtSec = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY sort_order ASC, start_time ASC");
$stmtSec->execute([$activeEventId]);
$scheduleSessions = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

// Fetch all programs for header dropdowns & list with schedule session info
$stmt = $pdo->prepare("
    SELECT mp.*, ct.name AS class_type_name,
           mss.id AS schedule_section_id, mss.name AS schedule_section_name,
           mss.start_time AS schedule_section_start, mss.end_time AS schedule_section_end,
           mss.section_date AS schedule_section_date, mss.sort_order AS schedule_section_sort,
           (SELECT COUNT(*) FROM musabaqa_program_entries pe WHERE pe.program_id = mp.id AND pe.event_id = mp.event_id) AS current_entries
    FROM musabaqa_programs mp
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = mp.section_id
    WHERE mp.event_id = ?
    ORDER BY COALESCE(mss.sort_order, 999) ASC, COALESCE(mss.start_time, '23:59:59') ASC, mp.title ASC
");
$stmt->execute([$activeEventId]);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$programs) {
    admin_flash('error', 'Create a program before managing entries.');
    admin_redirect('/admin/event-manager/programs.php');
}

$programMap = [];
foreach ($programs as $prog) {
    $programMap[(int)$prog['id']] = $prog;
}

// Active program determination
$selectedProgramId = (int)($_GET['program_id'] ?? $_GET['program'] ?? $_SESSION['active_program_id'] ?? 0);
$viewMode = trim((string)($_GET['view'] ?? ''));

if ($viewMode === 'programs') {
    $selectedProgramId = 0;
    unset($_SESSION['active_program_id']);
} elseif ($selectedProgramId > 0 && isset($programMap[$selectedProgramId])) {
    $_SESSION['active_program_id'] = $selectedProgramId;
} else {
    $selectedProgramId = 0;
}

// POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        entries_redirect();
    }

    $action = (string)($_POST['action'] ?? '');
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? $selectedProgramId);
    $teamId = (int)($_POST['team_id'] ?? 0);
    $returnQuery = [];
    if ($programId > 0) {
        $returnQuery['program_id'] = $programId;
        if ($viewMode === 'assign') {
            $returnQuery['view'] = 'assign';
        }
    }

    try {
        $pdo->beginTransaction();

        if (in_array($action, ['create_entry', 'update_entry'], true)) {
            $program = entries_load_program($pdo, $activeEventId, $programId);
            if (!$program) {
                throw new RuntimeException('Selected program is invalid.');
            }
            if (in_array((string)$program['approval_status'], ['submitted', 'approved'], true)) {
                throw new RuntimeException('Submitted or approved programs cannot be changed.');
            }

            $perTeamLimit = (int)($program['entries_limit'] ?? 10);

            if ($action === 'create_entry') {
                if ($program['program_type'] === 'individual') {
                    $teamMemberIds = [];
                    if (isset($_POST['team_member_ids']) && is_array($_POST['team_member_ids'])) {
                        $teamMemberIds = array_filter(array_map('intval', $_POST['team_member_ids']));
                    } elseif (!empty($_POST['team_member_id'])) {
                        $teamMemberIds = [(int)$_POST['team_member_id']];
                    }

                    if (empty($teamMemberIds)) {
                        throw new RuntimeException('Please select at least one participant.');
                    }

                    $addedCount = 0;
                    $skippedNames = [];

                    foreach ($teamMemberIds as $teamMemberId) {
                        $stmt = $pdo->prepare("
                            SELECT tm.*, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name
                            FROM musabaqa_team_members tm
                            JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                            WHERE tm.id = ?
                              AND tm.event_id = ?
                              AND tm.status = 'active'
                            LIMIT 1
                        ");
                        $stmt->execute([$teamMemberId, $activeEventId]);
                        $member = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$member) {
                            continue;
                        }

                        $memberTeamId = (int)$member['team_id'];
                        if ($memberTeamId <= 0) {
                            continue;
                        }

                        // Check team limit for this program
                        $tCntStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ? AND event_id = ?");
                        $tCntStmt->execute([$programId, $memberTeamId, $activeEventId]);
                        $teamCurrentEntries = (int)$tCntStmt->fetchColumn();

                        if ($teamCurrentEntries >= $perTeamLimit) {
                            $skippedNames[] = $member['full_name'] . " (Team limit of {$perTeamLimit} reached)";
                            continue;
                        }

                        // Duplicate check
                        $dup = $pdo->prepare("
                            SELECT em.id
                            FROM musabaqa_entry_members em
                            JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
                            WHERE pe.event_id = ?
                              AND pe.program_id = ?
                              AND em.team_member_id = ?
                            LIMIT 1
                        ");
                        $dup->execute([$activeEventId, $programId, $teamMemberId]);
                        if ($dup->fetchColumn()) {
                            if (count($teamMemberIds) === 1) {
                                throw new RuntimeException("Participant {$member['full_name']} is already assigned to this program.");
                            }
                            $skippedNames[] = $member['full_name'] . " (already assigned)";
                            continue;
                        }

                        admin_validate_member_program_limits($pdo, $activeEventId, $programId, $teamMemberId);

                        $entryNumber = entries_next_number($pdo, $activeEventId, $programId);
                        $perfOrder   = entries_next_performance_order($pdo, $activeEventId, $programId);

                        $stmt = $pdo->prepare("
                            INSERT INTO musabaqa_program_entries
                                (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'approved')
                        ");
                        $stmt->execute([$activeEventId, $programId, $memberTeamId, $member['full_name'], $entryNumber, $perfOrder]);
                        $newEntryId = (int)$pdo->lastInsertId();

                        $stmt = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, 'Participant')");
                        $stmt->execute([$newEntryId, $teamMemberId]);

                        $addedCount++;
                    }

                    if ($addedCount === 0) {
                        $reason = !empty($skippedNames) ? implode(', ', $skippedNames) : 'Selected participants could not be added.';
                        throw new RuntimeException("No entries created. Reason: {$reason}");
                    }

                    admin_recalculate_program_status($pdo, $programId);
                    $msg = "{$addedCount} participant(s) registered successfully.";
                    if (!empty($skippedNames)) {
                        $msg .= " (" . count($skippedNames) . " skipped: " . implode(', ', $skippedNames) . ")";
                    }
                    admin_flash('success', $msg);
                } else {
                    if ($teamId <= 0) {
                        throw new RuntimeException('Please select a team.');
                    }

                    $entryName = trim((string)($_POST['entry_name'] ?? ''));
                    if ($entryName === '') {
                        throw new RuntimeException('Entry name is required.');
                    }

                    // Check team limit for group program
                    $tCntStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ? AND event_id = ?");
                    $tCntStmt->execute([$programId, $teamId, $activeEventId]);
                    if ((int)$tCntStmt->fetchColumn() >= $perTeamLimit) {
                        throw new RuntimeException("This team has reached its maximum limit of {$perTeamLimit} entries for this program.");
                    }

                    $dup = $pdo->prepare('SELECT id FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ? AND team_id = ? AND entry_name = ? LIMIT 1');
                    $dup->execute([$activeEventId, $programId, $teamId, $entryName]);
                    if ($dup->fetchColumn()) {
                        throw new RuntimeException('This team already has an entry with that name.');
                    }

                    $teamMemberIds = [];
                    if (isset($_POST['team_member_ids']) && is_array($_POST['team_member_ids'])) {
                        $teamMemberIds = array_filter(array_map('intval', $_POST['team_member_ids']));
                    }

                    $entryNumber = entries_next_number($pdo, $activeEventId, $programId);
                    $perfOrder   = entries_next_performance_order($pdo, $activeEventId, $programId);

                    $stmt = $pdo->prepare("
                        INSERT INTO musabaqa_program_entries
                            (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'approved')
                    ");
                    $stmt->execute([$activeEventId, $programId, $teamId, $entryName, $entryNumber, $perfOrder]);
                    $newEntryId = (int)$pdo->lastInsertId();

                    $addedCount = 0;
                    $skippedNames = [];

                    foreach ($teamMemberIds as $teamMemberId) {
                        $stmt = $pdo->prepare("
                            SELECT tm.*, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name
                            FROM musabaqa_team_members tm
                            JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                            WHERE tm.id = ? AND tm.event_id = ? AND tm.team_id = ? AND tm.status = 'active'
                            LIMIT 1
                        ");
                        $stmt->execute([$teamMemberId, $activeEventId, $teamId]);
                        $member = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$member) {
                            continue;
                        }

                        // Duplicate check in this program
                        $dupMem = $pdo->prepare("
                            SELECT em.id
                            FROM musabaqa_entry_members em
                            JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
                            WHERE pe.event_id = ? AND pe.program_id = ? AND em.team_member_id = ?
                            LIMIT 1
                        ");
                        $dupMem->execute([$activeEventId, $programId, $teamMemberId]);
                        if ($dupMem->fetchColumn()) {
                            $skippedNames[] = $member['full_name'] . " (already assigned)";
                            continue;
                        }

                        admin_validate_member_program_limits($pdo, $activeEventId, $programId, $teamMemberId);

                        $insMem = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, 'Participant')");
                        $insMem->execute([$newEntryId, $teamMemberId]);
                        $addedCount++;
                    }

                    admin_recalculate_program_status($pdo, $programId);
                    $msg = "Group entry '{$entryName}' created successfully with {$addedCount} member(s).";
                    if (!empty($skippedNames)) {
                        $msg .= " (" . count($skippedNames) . " skipped: " . implode(', ', $skippedNames) . ")";
                    }
                    admin_flash('success', $msg);
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT pe.*, old_program.program_type AS old_program_type
                    FROM musabaqa_program_entries pe
                    JOIN musabaqa_programs old_program ON old_program.id = pe.program_id
                    WHERE pe.id = ? AND pe.event_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$entryId, $activeEventId]);
                $entry = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$entry) {
                    throw new RuntimeException('Entry not found.');
                }
                if ($entry['status'] === 'completed') {
                    throw new RuntimeException('Completed entries cannot be reassigned.');
                }

                $entryName = (string)$entry['entry_name'];
                if ($program['program_type'] === 'group') {
                    $entryName = trim((string)($_POST['entry_name'] ?? ''));
                    if ($entryName === '') {
                        throw new RuntimeException('Entry name is required.');
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE musabaqa_program_entries
                    SET entry_name = ?
                    WHERE id = ? AND event_id = ?
                ");
                $stmt->execute([$entryName, $entryId, $activeEventId]);

                if ($program['program_type'] === 'group' && isset($_POST['update_group_members'])) {
                    $teamMemberIds = [];
                    if (isset($_POST['team_member_ids']) && is_array($_POST['team_member_ids'])) {
                        $teamMemberIds = array_filter(array_map('intval', $_POST['team_member_ids']));
                    }

                    // Remove current members for this entry
                    $pdo->prepare("DELETE FROM musabaqa_entry_members WHERE entry_id = ?")->execute([$entryId]);

                    $addedCount = 0;
                    $skippedNames = [];
                    foreach ($teamMemberIds as $teamMemberId) {
                        $stmt = $pdo->prepare("
                            SELECT tm.*, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name
                            FROM musabaqa_team_members tm
                            JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                            WHERE tm.id = ? AND tm.event_id = ? AND tm.team_id = ? AND tm.status = 'active'
                            LIMIT 1
                        ");
                        $stmt->execute([$teamMemberId, $activeEventId, (int)$entry['team_id']]);
                        $member = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$member) {
                            continue;
                        }

                        // Duplicate check excluding current entry
                        $dupMem = $pdo->prepare("
                            SELECT em.id
                            FROM musabaqa_entry_members em
                            JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
                            WHERE pe.event_id = ? AND pe.program_id = ? AND em.team_member_id = ? AND pe.id != ?
                            LIMIT 1
                        ");
                        $dupMem->execute([$activeEventId, $programId, $teamMemberId, $entryId]);
                        if ($dupMem->fetchColumn()) {
                            $skippedNames[] = $member['full_name'] . " (assigned to another entry)";
                            continue;
                        }

                        admin_validate_member_program_limits($pdo, $activeEventId, $programId, $teamMemberId, $entryId);

                        $insMem = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, 'Participant')");
                        $insMem->execute([$entryId, $teamMemberId]);
                        $addedCount++;
                    }
                }

                admin_recalculate_program_status($pdo, $programId);
                admin_flash('success', 'Group entry updated successfully.');
            }
        } elseif ($action === 'delete_entry') {
            $stmt = $pdo->prepare('SELECT program_id, entry_name FROM musabaqa_program_entries WHERE id = ? AND event_id = ? LIMIT 1');
            $stmt->execute([$entryId, $activeEventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $entryProgramId = (int)($row['program_id'] ?? 0);
            $deletedEntryName = (string)($row['entry_name'] ?? '');

            $pdo->prepare('DELETE FROM musabaqa_member_scores WHERE entry_id = ?')->execute([$entryId]);
            $pdo->prepare('DELETE FROM musabaqa_scores WHERE entry_id = ? AND event_id = ?')->execute([$entryId, $activeEventId]);

            $sheetStmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE entry_id = ?');
            $sheetStmt->execute([$entryId]);
            $sheetIds = $sheetStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($sheetIds) {
                $sheetPlaceholders = implode(',', array_fill(0, count($sheetIds), '?'));
                $pdo->prepare("DELETE FROM musabaqa_category_scores WHERE score_sheet_id IN ($sheetPlaceholders)")->execute($sheetIds);
            }
            $pdo->prepare('DELETE FROM musabaqa_score_sheets WHERE entry_id = ?')->execute([$entryId]);

            $pdo->prepare('DELETE FROM musabaqa_entry_members WHERE entry_id = ?')->execute([$entryId]);
            $pdo->prepare('DELETE FROM musabaqa_program_entries WHERE id = ? AND event_id = ?')->execute([$entryId, $activeEventId]);

            if ($entryProgramId > 0) {
                admin_recalculate_participant_totals($pdo, $activeEventId, $entryProgramId);
                admin_recalculate_program_results($pdo, $activeEventId, $entryProgramId);
            }
            admin_recalculate_team_totals($pdo, $activeEventId);

            admin_flash('success', "Entry undone/deleted successfully" . ($deletedEntryName ? " ({$deletedEntryName})" : '') . ".");
        } else {
            throw new RuntimeException('Invalid entry action.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to update entries.');
    }

    entries_redirect($returnQuery);
}

// Fetch teams list
$teamsStmt = $pdo->prepare('SELECT * FROM musabaqa_teams WHERE event_id = ? ORDER BY team_name ASC');
$teamsStmt->execute([$activeEventId]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

$search = trim((string)($_GET['search'] ?? ''));
$teamFilter = (int)($_GET['team'] ?? 0);
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
$classFilter = trim((string)($_GET['class'] ?? 'all'));

$activeProgram = $selectedProgramId > 0 ? ($programMap[$selectedProgramId] ?? null) : null;

// Program-specific Selection & Entry Data
$currentEntries = [];
$assignedMemberMap = [];
$teamsGrouped = [];
$teamAssignedCounts = [];
$perTeamLimit = 10;

if ($activeProgram) {
    $perTeamLimit = (int)($activeProgram['entries_limit'] ?? 10);

    // Fetch existing registered entries for this program
    $stmt = $pdo->prepare("
        SELECT pe.*, t.team_name, t.team_color,
               (SELECT GROUP_CONCAT(tm.id)
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                WHERE em.entry_id = pe.id) AS member_ids_str,
               (SELECT GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ''), s.full_name) SEPARATOR ', ')
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                WHERE em.entry_id = pe.id) AS member_names,
               (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> '') AS chest_numbers,
               (SELECT c.name
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
                WHERE em.entry_id = pe.id LIMIT 1) AS class_name
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.event_id = ? AND pe.program_id = ?
        ORDER BY pe.performance_order ASC, pe.id ASC
    ");
    $stmt->execute([$activeEventId, (int)$activeProgram['id']]);
    $rawEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $currentEntriesMap = [];
    foreach ($rawEntries as $row) {
        $eId = (int)$row['id'];
        $tId = (int)$row['team_id'];
        $teamAssignedCounts[$tId] = ($teamAssignedCounts[$tId] ?? 0) + 1;

        $mIds = array_filter(array_map('intval', explode(',', (string)$row['member_ids_str'])));
        foreach ($mIds as $tmId) {
            $currentEntriesMap[$eId] = [
                'id' => $eId,
                'team_id' => $tId,
                'team_name' => $row['team_name'],
                'team_color' => $row['team_color'],
                'entry_name' => $row['entry_name'],
                'entry_number' => (int)$row['entry_number'],
                'performance_order' => (int)$row['performance_order'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'member_ids' => $mIds,
                'member_names' => $row['member_names'],
                'chest_numbers' => $row['chest_numbers'],
                'class_name' => $row['class_name'],
            ];
            $assignedMemberMap[$tmId] = [
                'id' => $eId,
                'entry_name' => $row['entry_name'],
                'entry_number' => (int)$row['entry_number'],
            ];
        }
    }
    $currentEntries = array_values($currentEntriesMap);

    // Fetch all active team members eligible for this program
    $sql = "
        SELECT tm.id AS team_member_id, tm.team_id, tm.student_id, tm.chest_number,
               t.team_name, t.team_color,
               COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name, s.admission_no,
               c.id AS class_id, c.name AS class_name, c.year AS class_year, ct.name AS class_type
        FROM musabaqa_team_members tm
        JOIN musabaqa_teams t ON t.id = tm.team_id
        JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
        LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
        LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = c.class_type_id
        WHERE tm.event_id = ?
          AND tm.status = 'active'
    ";
    $params = [$activeEventId];

    $allowedSections = [];
    if (!empty($activeProgram['allowed_sections'])) {
        $allowedSections = array_filter(array_map('intval', explode(',', $activeProgram['allowed_sections'])));
    } elseif (!empty($activeProgram['class_type_id'])) {
        $allowedSections = [(int)$activeProgram['class_type_id']];
    }

    if (!empty($allowedSections)) {
        $placeholders = implode(',', array_fill(0, count($allowedSections), '?'));
        $sql .= " AND c.class_type_id IN ($placeholders)";
        foreach ($allowedSections as $secId) {
            $params[] = $secId;
        }
    }
    if ($teamFilter > 0) {
        $sql .= " AND tm.team_id = ?";
        $params[] = $teamFilter;
    }
    if ($search !== '') {
        $sql .= " AND (COALESCE(NULLIF(s.display_name, ''), s.full_name) LIKE ? OR s.admission_no LIKE ? OR tm.chest_number LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $sql .= " ORDER BY t.team_name ASC, (c.year IS NULL) ASC, c.year DESC, full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sectionFilter = trim((string)($_GET['section'] ?? 'all'));
    $groupByMode   = trim((string)($_GET['group_by'] ?? 'team'));

    // Extract all available sections for filtering dropdown
    $availableSections = [];
    foreach ($allMembers as $m) {
        $sec = trim((string)($m['class_type'] ?? ''));
        if ($sec !== '' && !in_array($sec, $availableSections, true)) {
            $availableSections[] = $sec;
        }
    }
    sort($availableSections);

    if ($sectionFilter !== 'all' && $sectionFilter !== '') {
        $allMembers = array_values(array_filter($allMembers, static function($m) use ($sectionFilter) {
            return ($m['class_type'] ?? '') === $sectionFilter;
        }));
    }

    // Group eligible members by TEAM
    foreach ($allMembers as $m) {
        $teamKey = 'team-' . (int)$m['team_id'];
        if (!isset($teamsGrouped[$teamKey])) {
            $teamsGrouped[$teamKey] = [
                'id' => (int)$m['team_id'],
                'name' => $m['team_name'],
                'color' => $m['team_color'] ?: '#6366f1',
                'members' => [],
            ];
        }
        $teamsGrouped[$teamKey]['members'][] = $m;
    }

    // Group eligible members by SECTION
    $sectionsGrouped = [];
    foreach ($allMembers as $m) {
        $secName = $m['class_type'] ?: 'General Section';
        $secKey  = 'sec-' . md5($secName);
        if (!isset($sectionsGrouped[$secKey])) {
            $sectionsGrouped[$secKey] = [
                'name' => $secName,
                'members' => [],
            ];
        }
        $sectionsGrouped[$secKey]['members'][] = $m;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">

    <?php if ($flash = admin_take_flash()): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$activeProgram): ?>
        <!-- ========================================================= -->
        <!-- VIEW 1: ALL PROGRAMS LIST (Grouped by Schedule Session)  -->
        <!-- ========================================================= -->
        <div class="topbar">
            <div>
                <div class="page-title"><i class="fa-solid fa-list-check mr-2" style="color:var(--accent);"></i> Programs & Entries Hub</div>
                <div class="page-subtitle">Manage program entries grouped by Schedule Sessions or Class Divisions</div>
            </div>
            <div class="flex gap-2">
                <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-clock mr-1"></i> Schedule Sessions
                </a>
                <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-gear mr-1"></i> Manage Programs
                </a>
            </div>
        </div>

        <?php
            $sessionIdFilter = (int)($_GET['session_id'] ?? 0);
            $programGroupBy  = trim((string)($_GET['program_group_by'] ?? 'session'));

            // Filter programs
            $filteredPrograms = $programs;
            if ($sessionIdFilter > 0) {
                $filteredPrograms = array_values(array_filter($filteredPrograms, fn($p) => (int)($p['schedule_section_id'] ?? 0) === $sessionIdFilter));
            } elseif ($sessionIdFilter === -1) {
                $filteredPrograms = array_values(array_filter($filteredPrograms, fn($p) => (int)($p['schedule_section_id'] ?? 0) <= 0));
            }

            if ($classFilter !== 'all') {
                $filteredPrograms = array_values(array_filter($filteredPrograms, fn($p) => admin_class_type_tier_from_name($p['class_type_name'] ?? '') === $classFilter));
            }

            if ($typeFilter !== 'all') {
                $filteredPrograms = array_values(array_filter($filteredPrograms, fn($p) => strtolower((string)$p['program_type']) === $typeFilter));
            }

            if ($search !== '') {
                $term = strtolower($search);
                $filteredPrograms = array_values(array_filter($filteredPrograms, fn($p) => str_contains(strtolower($p['title']), $term) || str_contains(strtolower($p['class_type_name'] ?? ''), $term)));
            }

            // Grouping Structure 1: By Schedule Session
            $groupedBySession = [];
            foreach ($scheduleSessions as $sec) {
                $secId = (int)$sec['id'];
                $timeStr = '';
                if (!empty($sec['start_time']) && !empty($sec['end_time'])) {
                    $timeStr = date('h:i A', strtotime($sec['start_time'])) . ' - ' . date('h:i A', strtotime($sec['end_time']));
                }
                $groupedBySession['session_' . $secId] = [
                    'id' => $secId,
                    'name' => $sec['name'],
                    'time_range' => $timeStr,
                    'date' => !empty($sec['section_date']) ? date('M d, Y', strtotime($sec['section_date'])) : '',
                    'programs' => []
                ];
            }
            $groupedBySession['unassigned'] = [
                'id' => 0,
                'name' => 'Unassigned Schedule Sessions',
                'time_range' => '',
                'date' => '',
                'programs' => []
            ];

            foreach ($filteredPrograms as $prog) {
                $secId = (int)($prog['schedule_section_id'] ?? 0);
                $key = ($secId > 0 && isset($groupedBySession['session_' . $secId])) ? 'session_' . $secId : 'unassigned';
                $groupedBySession[$key]['programs'][] = $prog;
            }
        ?>

        <div class="panel mb-6">
            <form method="GET" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items: flex-end;">
                <input type="hidden" name="view" value="programs">
                <input type="hidden" name="program_group_by" value="<?= e($programGroupBy) ?>">

                <div class="input-group">
                    <label>Search Program</label>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program title or section...">
                </div>
                
                <div class="input-group">
                    <label>Schedule Session</label>
                    <select name="session_id" onchange="this.form.submit()">
                        <option value="0">All Schedule Sessions</option>
                        <?php foreach ($scheduleSessions as $sec): ?>
                            <?php
                                $timeStr = '';
                                if (!empty($sec['start_time']) && !empty($sec['end_time'])) {
                                    $timeStr = ' (' . date('h:i A', strtotime($sec['start_time'])) . ')';
                                }
                            ?>
                            <option value="<?= (int)$sec['id'] ?>" <?= $sessionIdFilter === (int)$sec['id'] ? 'selected' : '' ?>>
                                <?= e($sec['name']) ?><?= $timeStr ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="-1" <?= $sessionIdFilter === -1 ? 'selected' : '' ?>>Unassigned Sessions</option>
                    </select>
                </div>

                <div class="input-group">
                    <label>Division / Class</label>
                    <select name="class">
                        <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="input-group">
                    <label>Program Type</label>
                    <select name="type">
                        <option value="all">All Types</option>
                        <option value="individual" <?= $typeFilter === 'individual' ? 'selected' : '' ?>>Individual</option>
                        <option value="group" <?= $typeFilter === 'group' ? 'selected' : '' ?>>Group</option>
                    </select>
                </div>

                <div class="form-actions" style="grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 6px;">
                    <div style="display: flex; align-items: center; background: rgba(255,255,255,0.04); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                        <a href="<?= app_url('/admin/registrar/entries.php?view=programs&program_group_by=session' . ($sessionIdFilter !== 0 ? '&session_id=' . $sessionIdFilter : '') . ($classFilter !== 'all' ? '&class=' . urlencode($classFilter) : '') . ($typeFilter !== 'all' ? '&type=' . urlencode($typeFilter) : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $programGroupBy === 'session' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;">
                            <i class="fa-solid fa-clock mr-1"></i> By Schedule Session
                        </a>
                        <a href="<?= app_url('/admin/registrar/entries.php?view=programs&program_group_by=division' . ($sessionIdFilter !== 0 ? '&session_id=' . $sessionIdFilter : '') . ($classFilter !== 'all' ? '&class=' . urlencode($classFilter) : '') . ($typeFilter !== 'all' ? '&type=' . urlencode($typeFilter) : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $programGroupBy === 'division' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;">
                            <i class="fa-solid fa-layer-group mr-1"></i> By Class Division
                        </a>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                    </div>
                </div>
            </form>
        </div>

        <?php foreach ($groupedBySession as $secKey => $sessionGroup): ?>
            <?php if (empty($sessionGroup['programs'])) continue; ?>
            <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; overflow: hidden; padding: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px);">
                <div style="background: rgba(255,255,255,0.03); padding: 16px 22px; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <h3 style="font-size: 16px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-clock" style="color: #34d399;"></i>
                        <?= e($sessionGroup['name']) ?>
                    </h3>
                    <span style="font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; background: rgba(16,185,129,0.15); color: #34d399;">
                        <?= count($sessionGroup['programs']) ?> Programs
                    </span>
                </div>

                <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0;">
                    <table class="table table-glass">
                        <thead>
                            <tr>
                                <th>Program Title & Time</th>
                                <th>Type</th>
                                <th>Section / Class</th>
                                <th>Limit per Team</th>
                                <th>Total Entries</th>
                                <th style="text-align: right; width: 180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessionGroup['programs'] as $prog): ?>
                                <?php
                                    $limitVal = (int)($prog['entries_limit'] ?? 10);
                                    $currentVal = (int)($prog['current_entries'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color: #fff; font-size: 14px;"><?= e($prog['title']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge <?= $prog['program_type'] === 'individual' ? 'badge-info' : 'badge-neutral' ?>">
                                            <?= e(ucfirst($prog['program_type'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: #a5b4fc; font-size: 13px; font-weight: 600;">
                                            <?= e(admin_class_type_display($prog['class_type_name'] ?? null, (int)($prog['class_type_id'] ?? 0))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: #6366f1; font-size: 13px;"><?= $limitVal ?> Entries / Team</strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-success" style="font-size: 12px; font-weight: 700;">
                                            <?= $currentVal ?> Registered
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$prog['id']) ?>" class="btn btn-primary btn-sm">
                                            <i class="fa-solid fa-list-check mr-1"></i> View Entries (<?= $currentVal ?>)
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

    <?php elseif ($viewMode !== 'assign'): ?>
        <!-- ========================================================= -->
        <!-- VIEW 2: REGISTERED ENTRIES OVERVIEW (Intermediate Page)  -->
        <!-- ========================================================= -->
        <div class="topbar">
            <div>
                <div class="page-title">
                    <i class="fa-solid fa-clipboard-list mr-2" style="color:var(--accent);"></i> <?= e($activeProgram['title']) ?>
                </div>
                <div class="page-subtitle">
                    Class: <strong><?= e(admin_class_type_display($activeProgram['class_type_name'] ?? null, (int)($activeProgram['class_type_id'] ?? 0))) ?></strong> · 
                    Type: <strong><?= e(ucfirst($activeProgram['program_type'])) ?></strong>
                    <?php if (!empty($activeProgram['schedule_section_name'])): ?>
                        · Session: <strong style="color: #34d399;"><i class="fa-solid fa-clock mr-1"></i><?= e($activeProgram['schedule_section_name']) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex gap-2" style="flex-wrap: wrap;">
                <a href="<?= app_url('/admin/registrar/entries.php?view=programs') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-chevron-left mr-1"></i> All Programs
                </a>
                <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id'] . '&view=assign') ?>" class="btn btn-primary btn-md" style="background: #10b981; border-color: #10b981;">
                    <i class="fa-solid fa-user-plus mr-1"></i> Assign / Add Entries
                </a>
            </div>
        </div>

        <!-- Top Stats Row -->
        <div class="member-stats mb-6">
            <div class="member-stat">
                <div class="member-stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div>
                    <strong><?= $perTeamLimit ?></strong>
                    <span>Limit Per Team</span>
                </div>
            </div>
            <div class="member-stat">
                <div class="member-stat-icon" style="background:rgba(16,185,129,0.15); color:#34d399; border-color:rgba(16,185,129,0.25);">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <strong><?= count($currentEntries) ?></strong>
                    <span>Total Entries Registered</span>
                </div>
            </div>
            <div class="member-stat">
                <div class="member-stat-icon" style="background:rgba(99,102,241,0.15); color:#818cf8; border-color:rgba(99,102,241,0.25);">
                    <i class="fa-solid fa-people-group"></i>
                </div>
                <div>
                    <strong><?= count($teamsGrouped) ?></strong>
                    <span>Participating Teams</span>
                </div>
            </div>
        </div>

        <!-- Registered Entries Table with Undo Action -->
        <div class="panel mb-6">
            <div class="flex-between mb-4" style="border-bottom: 1px solid var(--border); padding-bottom: 14px;">
                <h3 style="margin: 0; color: #fff; font-size: 16px; font-weight: 800;">
                    <i class="fa-solid fa-rectangle-list mr-2" style="color: #60a5fa;"></i>
                    Registered Competition Entries (<?= count($currentEntries) ?>)
                </h3>

                <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id'] . '&view=assign') ?>" class="btn btn-primary btn-sm" style="background: #10b981; border-color: #10b981;">
                    <i class="fa-solid fa-plus mr-1"></i> Add / Assign Entries
                </a>
            </div>

            <?php if (empty($currentEntries)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center;">
                    <div class="empty-icon"><i class="fa-solid fa-user-slash" style="font-size: 36px; color: var(--muted);"></i></div>
                    <div class="empty-title" style="font-weight: 800; font-size: 16px; color: #fff; margin-top: 12px;">No Entries Registered Yet</div>
                    <div class="empty-subtitle" style="color: var(--muted); margin-top: 4px;">Click the button below to start assigning participants to this program.</div>
                    <div style="margin-top: 18px;">
                        <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id'] . '&view=assign') ?>" class="btn btn-primary btn-md" style="background: #10b981; border-color: #10b981;">
                            <i class="fa-solid fa-user-plus mr-1"></i> Assign First Entry
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table table-glass">
                        <thead>
                            <tr>
                                <th style="width: 80px; text-align: center;">Perf #</th>
                                <th style="width: 100px;">Chest #</th>
                                <th>Participant / Entry Name</th>
                                <th>Team</th>
                                <th>Members Count</th>
                                <th>Status</th>
                                <th style="text-align: right; width: 160px;">Action / Undo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentEntries as $idx => $entry): ?>
                                <tr>
                                    <td style="text-align: center;"><strong style="color: #60a5fa; font-size: 14px;">#<?= $idx + 1 ?></strong></td>
                                    <td>
                                        <span class="badge badge-neutral" style="font-weight: 800; font-size: 13px; color: #fff;">
                                            #<?= e($entry['chest_numbers'] ?: $entry['entry_number']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: #fff; font-size: 14.5px;"><?= e($entry['entry_name']) ?></strong>
                                        <?php if (!empty($entry['member_names']) && strtolower($activeProgram['program_type']) === 'group'): ?>
                                            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                                <i class="fa-solid fa-users mr-1" style="font-size: 10px;"></i> <?= e($entry['member_names']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: rgba(255,255,255,0.06); color: #fff; border: 1px solid rgba(255,255,255,0.15);">
                                            <span class="team-color-dot" style="background: <?= e($entry['team_color'] ?: '#6366f1') ?>; margin-right: 6px; width: 8px; height: 8px;"></span>
                                            <?= e($entry['team_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral">
                                            <?= count($entry['member_ids']) ?> member<?= count($entry['member_ids']) === 1 ? '' : 's' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= entries_status_badge($entry['status']) ?>">
                                            <?= e(ucfirst($entry['status'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to UNDO and delete this entry? This action cannot be undone.');">
                                            <?= admin_csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_entry">
                                            <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                                            <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-danger" style="background: #ef4444; border-color: #ef4444; color: #fff; font-weight: 700;">
                                                <i class="fa-solid fa-rotate-left mr-1"></i> Undo Entry
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ========================================================= -->
        <!-- VIEW 3: STUDENT REGISTRATION WORKSPACE (Assign Grid)    -->
        <!-- ========================================================= -->
        <div class="topbar">
            <div>
                <div class="page-title">
                    <i class="fa-solid fa-user-plus mr-2" style="color: #10b981;"></i> Assign Participants: <?= e($activeProgram['title']) ?>
                </div>
                <div class="page-subtitle">
                    Class: <strong><?= e(admin_class_type_display($activeProgram['class_type_name'] ?? null, (int)($activeProgram['class_type_id'] ?? 0))) ?></strong> · 
                    Type: <strong><?= e(ucfirst($activeProgram['program_type'])) ?></strong>
                </div>
            </div>
            <div class="flex gap-2" style="flex-wrap: wrap;">
                <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id']) ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-chevron-left mr-1"></i> Registered Entries List (<?= count($currentEntries) ?>)
                </a>
                <a href="<?= app_url('/admin/registrar/entries.php?view=programs') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-list-check mr-1"></i> All Programs
                </a>
            </div>
        </div>

        <!-- Top Stats Row -->
        <div class="member-stats mb-6">
            <div class="member-stat">
                <div class="member-stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div>
                    <strong><?= $perTeamLimit ?></strong>
                    <span>Limit Per Team</span>
                </div>
            </div>
            <div class="member-stat">
                <div class="member-stat-icon" style="background:rgba(16,185,129,0.15); color:#34d399; border-color:rgba(16,185,129,0.25);">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <strong><?= count($currentEntries) ?></strong>
                    <span>Total Entries Registered</span>
                </div>
            </div>
            <div class="member-stat">
                <div class="member-stat-icon" style="background:rgba(99,102,241,0.15); color:#818cf8; border-color:rgba(99,102,241,0.25);">
                    <i class="fa-solid fa-people-group"></i>
                </div>
                <div>
                    <strong><?= count($teamsGrouped) ?></strong>
                    <span>Participating Teams</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar & Search Form -->
        <div class="panel entries-filter-panel mb-6">
            <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">
                <input type="hidden" name="view" value="assign">

                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="font-weight: 600; font-size: 13px; color: var(--muted); margin:0;">Program:</label>
                    <select name="program_id" class="form-input" style="height: 38px; font-size: 13px; max-width: 260px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;" onchange="this.form.submit()">
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?= (int)$prog['id'] ?>" <?= $selectedProgramId === (int)$prog['id'] ? 'selected' : '' ?>>
                                <?= e($prog['title']) ?> (<?= (int)$prog['current_entries'] ?> entries)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="width: 160px;">
                    <select name="team" class="form-input" style="height: 38px; font-size: 13px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;" onchange="this.form.submit()">
                        <option value="0">All Teams</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $teamFilter === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['team_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($availableSections)): ?>
                    <div style="width: 160px;">
                        <select name="section" class="form-input" style="height: 38px; font-size: 13px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;" onchange="this.form.submit()">
                            <option value="all">All Sections</option>
                            <?php foreach ($availableSections as $secName): ?>
                                <option value="<?= e($secName) ?>" <?= $sectionFilter === $secName ? 'selected' : '' ?>><?= e($secName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="flex: 1; min-width: 160px;">
                    <input type="text" name="search" class="form-input" placeholder="Search participant, chest # or admission..." value="<?= e($search) ?>" style="height: 38px; font-size: 13px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
                </div>

                <div style="display: flex; align-items: center; background: rgba(255,255,255,0.04); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                    <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id'] . '&view=assign&group_by=team' . ($sectionFilter !== 'all' ? '&section=' . urlencode($sectionFilter) : '') . ($teamFilter > 0 ? '&team=' . $teamFilter : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $groupByMode === 'team' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;">
                        <i class="fa-solid fa-people-group mr-1"></i> By Team
                    </a>
                    <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id'] . '&view=assign&group_by=section' . ($sectionFilter !== 'all' ? '&section=' . urlencode($sectionFilter) : '') . ($teamFilter > 0 ? '&team=' . $teamFilter : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $groupByMode === 'section' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;">
                        <i class="fa-solid fa-layer-group mr-1"></i> By Section
                    </a>
                </div>

                <button type="submit" class="btn btn-secondary btn-md" style="height: 38px;"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
            </form>
        </div>

        <?php if ($activeProgram['program_type'] === 'individual'): ?>
            <!-- SELECTION FORM FOR INDIVIDUAL PROGRAM -->
            <form method="POST" id="batchEntryForm">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="create_entry">
                <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">

                <!-- Sticky Selection Command Bar -->
                <div class="selection-sticky-bar" id="selectionStickyBar">
                    <div>
                        <span style="font-size:14px; color:var(--muted); font-weight:700;">Selected Participants:</span>
                        <strong id="selectedCountText" style="font-size:20px; color:#fff; margin-left:6px;">0</strong>
                        <span style="font-size:12px; color:var(--muted); margin-left:8px;">(Limit: <?= $perTeamLimit ?> entries per team)</span>
                    </div>
                    <div id="selectionWarningAlert" style="display:none; color:#f87171; font-size:12px; font-weight:700;">Selection limit reached for team!</div>
                    <button type="submit" class="btn btn-glow-success btn-md" id="saveBatchEntriesBtn" disabled>
                        <i class="fa-solid fa-user-plus mr-1"></i> Save Selected Entries
                    </button>
                </div>

                <!-- Grouped Student Cards -->
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <?php if ($groupByMode === 'section'): ?>
                        <?php foreach ($sectionsGrouped as $secKey => $secGroup): ?>
                            <div class="panel" style="padding: 18px 22px; background: rgba(15,23,42,0.55); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; border-left: 4px solid #facc15;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.06);">
                                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                        <i class="fa-solid fa-layer-group" style="color: #facc15;"></i>
                                        <strong style="font-size: 16px; color: #fff;"><?= e($secGroup['name']) ?></strong>
                                        <span style="font-size: 12px; color: var(--muted);">(<?= count($secGroup['members']) ?> Members)</span>
                                    </div>
                                </div>

                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                                    <?php foreach ($secGroup['members'] as $m): ?>
                                        <?php
                                            $mId = (int)$m['team_member_id'];
                                            $tId = (int)$m['team_id'];
                                            $tAssigned = (int)($teamAssignedCounts[$tId] ?? 0);
                                            $isTeamFull = $tAssigned >= $perTeamLimit;
                                            $isAssigned = isset($assignedMemberMap[$mId]);
                                            $entryData = $isAssigned ? $assignedMemberMap[$mId] : null;
                                        ?>
                                        <?php if ($isAssigned): ?>
                                            <div class="student-card-selection assigned">
                                                <span class="student-avatar-badge"><i class="fa-solid fa-check"></i></span>
                                                <div style="flex: 1; min-width: 0;">
                                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; display: block;">
                                                        <?= e($m['full_name']) ?>
                                                    </strong>
                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                        <span class="student-meta-badge" style="background: rgba(255,255,255,0.06); color: #fff;">
                                                            <span class="team-color-dot" style="background: <?= e($m['team_color'] ?: '#6366f1') ?>;"></span> <?= e($m['team_name']) ?>
                                                        </span>
                                                    </div>
                                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                                                        <span style="font-size: 11px; color: #34d399; font-weight: 700;"><i class="fa-solid fa-check mr-1"></i> Assigned</span>
                                                        <button type="submit" form="undoForm_<?= (int)$entryData['id'] ?>" class="btn btn-xs btn-danger" style="font-size: 10.5px; padding: 2px 6px;">
                                                            <i class="fa-solid fa-rotate-left mr-1"></i> Undo
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <label class="student-card-selection" style="<?= $isTeamFull ? 'opacity: 0.45; cursor: not-allowed;' : '' ?>">
                                                <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="member-checkbox" data-team-id="<?= $tId ?>" <?= $isTeamFull ? 'disabled' : '' ?> style="display:none;">
                                                <span class="student-avatar-badge">
                                                    <span class="badge-text-chest">#<?= e($m['chest_number'] ?: mb_substr((string)$m['full_name'], 0, 1)) ?></span>
                                                    <i class="fa-solid fa-check badge-icon-check"></i>
                                                </span>
                                                <div style="flex: 1; min-width: 0;">
                                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; display: block;">
                                                        <?= e($m['full_name']) ?>
                                                    </strong>
                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                        <span class="student-meta-badge" style="background: rgba(255,255,255,0.06); color: #fff;">
                                                            <span class="team-color-dot" style="background: <?= e($m['team_color'] ?: '#6366f1') ?>;"></span> <?= e($m['team_name']) ?>
                                                        </span>
                                                        <?php if (!empty($m['class_name'])): ?>
                                                            <span class="student-meta-badge" style="background: rgba(99, 102, 241, 0.12); color: #a5b4fc;">
                                                                <i class="fa-solid fa-layer-group" style="font-size: 10px;"></i> <?= e($m['class_name']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($isTeamFull): ?>
                                                        <div style="font-size: 11px; margin-top: 4px; color: #f87171; font-weight: 600;">
                                                            <i class="fa-solid fa-lock mr-1"></i> Team Limit Reached
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </label>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Grouped by TEAM -->
                        <?php foreach ($teamsGrouped as $teamKey => $teamGroup): ?>
                            <?php
                                $tAssigned = (int)($teamAssignedCounts[$teamGroup['id']] ?? 0);
                                $isTeamFull = $tAssigned >= $perTeamLimit;
                            ?>
                            <div class="panel" style="padding: 18px 22px; background: rgba(15,23,42,0.55); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; border-left: 4px solid <?= e($teamGroup['color']) ?>;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.06);">
                                    <strong style="font-size: 16px; color: #fff;"><?= e($teamGroup['name']) ?></strong>
                                    <span class="badge" style="background: rgba(255,255,255,0.06); color: #fff;">
                                        <?= $tAssigned ?> / <?= $perTeamLimit ?> Assigned
                                    </span>
                                </div>

                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                                    <?php foreach ($teamGroup['members'] as $m): ?>
                                        <?php
                                            $mId = (int)$m['team_member_id'];
                                            $tId = (int)$m['team_id'];
                                            $isAssigned = isset($assignedMemberMap[$mId]);
                                            $entryData = $isAssigned ? $assignedMemberMap[$mId] : null;
                                        ?>
                                        <?php if ($isAssigned): ?>
                                            <div class="student-card-selection assigned">
                                                <span class="student-avatar-badge"><i class="fa-solid fa-check"></i></span>
                                                <div style="flex: 1; min-width: 0;">
                                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; display: block;">
                                                        <?= e($m['full_name']) ?>
                                                    </strong>
                                                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">
                                                        <?= e($m['class_name'] ?: 'General Class') ?>
                                                    </div>
                                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                                                        <span style="font-size: 11px; color: #34d399; font-weight: 700;"><i class="fa-solid fa-check mr-1"></i> Assigned</span>
                                                        <button type="submit" form="undoForm_<?= (int)$entryData['id'] ?>" class="btn btn-xs btn-danger" style="font-size: 10.5px; padding: 2px 6px;">
                                                            <i class="fa-solid fa-rotate-left mr-1"></i> Undo
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <label class="student-card-selection" style="<?= $isTeamFull ? 'opacity: 0.45; cursor: not-allowed;' : '' ?>">
                                                <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="member-checkbox" data-team-id="<?= $tId ?>" <?= $isTeamFull ? 'disabled' : '' ?> style="display:none;">
                                                <span class="student-avatar-badge">
                                                    <span class="badge-text-chest">#<?= e($m['chest_number'] ?: mb_substr((string)$m['full_name'], 0, 1)) ?></span>
                                                    <i class="fa-solid fa-check badge-icon-check"></i>
                                                </span>
                                                <div style="flex: 1; min-width: 0;">
                                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; display: block;">
                                                        <?= e($m['full_name']) ?>
                                                    </strong>
                                                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">
                                                        <?= e($m['class_name'] ?: 'General Class') ?>
                                                    </div>
                                                    <?php if ($isTeamFull): ?>
                                                        <div style="font-size: 11px; margin-top: 4px; color: #f87171; font-weight: 600;">
                                                            <i class="fa-solid fa-lock mr-1"></i> Team Limit Reached
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </label>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Hidden Undo Forms for Assigned Cards -->
            <?php foreach ($currentEntries as $entry): ?>
                <form method="POST" id="undoForm_<?= (int)$entry['id'] ?>" style="display:none;" onsubmit="return confirm('Undo registration for this participant?');">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="delete_entry">
                    <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                    <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">
                </form>
            <?php endforeach; ?>

        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    const saveBtn = document.getElementById('saveBatchEntriesBtn');
    const countText = document.getElementById('selectedCountText');

    function updateCount() {
        const checked = document.querySelectorAll('.member-checkbox:checked');
        if (countText) countText.textContent = checked.length;
        if (saveBtn) saveBtn.disabled = checked.length === 0;
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            const card = cb.closest('.student-card-selection');
            if (card) {
                if (cb.checked) card.classList.add('selected');
                else card.classList.remove('selected');
            }
            updateCount();
        });
    });
});
</script>

<?php admin_close_page(); ?>
