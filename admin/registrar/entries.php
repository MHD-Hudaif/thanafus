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

// Fetch all class types for map
$classTypes = $dashboardPdo->query("SELECT id, name FROM class_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all schedule sessions for this event
$stmtSec = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY sort_order ASC, start_time ASC");
$stmtSec->execute([$activeEventId]);
$scheduleSessions = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

// Fetch all programs for header dropdowns & list with schedule session info & stage type info
$stmt = $pdo->prepare("
    SELECT mp.*, ct.name AS class_type_name,
           mst.name AS stage_type_name,
           mss.id AS schedule_section_id, mss.name AS schedule_section_name,
           mss.start_time AS schedule_section_start, mss.end_time AS schedule_section_end,
           mss.section_date AS schedule_section_date, mss.sort_order AS schedule_section_sort,
           (SELECT COUNT(*) FROM musabaqa_program_entries pe WHERE pe.program_id = mp.id AND pe.event_id = mp.event_id) AS current_entries
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
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
        admin_db_transaction($pdo, function ($pdo) use ($action, $activeEventId, $programId, $entryId, $teamId, $viewMode) {
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

                            // Check team limit for this program
                            $tCntStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ? AND event_id = ?");
                            $tCntStmt->execute([$programId, $memberTeamId, $activeEventId]);
                            if ((int)$tCntStmt->fetchColumn() >= $perTeamLimit) {
                                $skippedNames[] = $member['full_name'] . " (team limit reached)";
                                continue;
                            }

                            // Duplicate check in this program
                            $dup = $pdo->prepare("
                                SELECT pe.id
                                FROM musabaqa_program_entries pe
                                JOIN musabaqa_entry_members em ON em.entry_id = pe.id
                                WHERE pe.event_id = ? AND pe.program_id = ? AND em.team_member_id = ?
                                LIMIT 1
                            ");
                            $dup->execute([$activeEventId, $programId, $teamMemberId]);
                            if ($dup->fetchColumn()) {
                                $skippedNames[] = $member['full_name'] . " (already assigned)";
                                continue;
                            }

                            admin_validate_member_program_limits($pdo, $activeEventId, $programId, $teamMemberId);

                            $entryName = (string)$member['full_name'];
                            $entryNumber = entries_next_number($pdo, $activeEventId, $programId);
                            $perfOrder   = entries_next_performance_order($pdo, $activeEventId, $programId);

                            $stmt = $pdo->prepare("
                                INSERT INTO musabaqa_program_entries
                                    (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'approved')
                            ");
                            $stmt->execute([$activeEventId, $programId, $memberTeamId, $entryName, $entryNumber, $perfOrder]);
                            $newEntryId = (int)$pdo->lastInsertId();

                            $insMem = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, 'Participant')");
                            $insMem->execute([$newEntryId, $teamMemberId]);
                            $addedCount++;
                        }

                        if ($addedCount === 0) {
                            $err = "No participants could be registered.";
                            if (!empty($skippedNames)) {
                                $err .= " (" . implode(', ', $skippedNames) . ")";
                            }
                            throw new RuntimeException($err);
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

                        // Load team name
                        $stmtTeam = $pdo->prepare("SELECT team_name FROM musabaqa_teams WHERE id = ? LIMIT 1");
                        $stmtTeam->execute([$teamId]);
                        $teamRow = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                        $teamName = $teamRow ? $teamRow['team_name'] : 'Team';

                        $entryName = trim((string)($_POST['entry_name'] ?? ''));
                        if ($entryName === '') {
                            $entryName = $program['title'] . ' - ' . $teamName;
                        }

                        // Check team limit for group program
                        $tCntStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ? AND event_id = ?");
                        $tCntStmt->execute([$programId, $teamId, $activeEventId]);
                        $currentTeamEntries = (int)$tCntStmt->fetchColumn();
                        if ($currentTeamEntries >= $perTeamLimit) {
                            throw new RuntimeException("This team has reached its maximum limit of {$perTeamLimit} entries for this program.");
                        }

                        $dup = $pdo->prepare('SELECT id FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ? AND team_id = ? AND entry_name = ? LIMIT 1');
                        $dup->execute([$activeEventId, $programId, $teamId, $entryName]);
                        if ($dup->fetchColumn()) {
                            $altName = $entryName . ' (' . ($currentTeamEntries + 1) . ')';
                            $dupAlt = $pdo->prepare('SELECT id FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ? AND team_id = ? AND entry_name = ? LIMIT 1');
                            $dupAlt->execute([$activeEventId, $programId, $teamId, $altName]);
                            if (!$dupAlt->fetchColumn()) {
                                $entryName = $altName;
                            } else {
                                throw new RuntimeException('This team already has an entry named "' . $entryName . '".');
                            }
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
        });
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to process entry operation.');
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

            $classTypesMap = [];
            foreach ($classTypes as $type) {
                $classTypesMap[(int)$type['id']] = $type['name'];
            }

            $tierNames = [
                'subjunior' => 'Sub Junior Division',
                'junior'    => 'Junior Division',
                'senior'    => 'Senior Division',
                'general'   => 'General / Open Division'
            ];

            // Grouping Structure 1: By Class Division (Matches Programs Page Structure)
            $groupedByDivision = [];
            $groupedByDivision['group'] = [
                'title' => 'Group Programs',
                'icon'  => 'fa-people-group',
                'color' => '#818cf8',
                'programs' => []
            ];
            $groupedByDivision['offstage'] = [
                'title' => 'Off-Stage Programs',
                'icon'  => 'fa-pen-ruler',
                'color' => '#f59e0b',
                'programs' => []
            ];
            foreach ($tierNames as $tierKey => $tierLabel) {
                $groupedByDivision['normal_' . $tierKey] = [
                    'title' => "{$tierLabel} (Normal Stage)",
                    'icon'  => 'fa-layer-group',
                    'color' => '#34d399',
                    'programs' => []
                ];
            }

            foreach ($filteredPrograms as $program) {
                $isGroup = strtolower((string)$program['program_type']) === 'group';
                $isOffStage = str_contains(strtolower((string)($program['stage_type_name'] ?? '')), 'off');

                if ($isGroup) {
                    $groupedByDivision['group']['programs'][] = $program;
                    continue;
                }

                if ($isOffStage) {
                    $groupedByDivision['offstage']['programs'][] = $program;
                    continue;
                }

                $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;

                if ($allowedCount > 1 || !$classTier) {
                    $tierKey = 'general';
                } else {
                    $tierKey = $classTier;
                }

                $groupedByDivision['normal_' . $tierKey]['programs'][] = $program;
            }

            // Grouping Structure 2: By Schedule Session
            $groupedBySession = [];
            foreach ($scheduleSessions as $sec) {
                $secId = (int)$sec['id'];
                $timeStr = '';
                if (!empty($sec['start_time']) && !empty($sec['end_time'])) {
                    $timeStr = date('h:i A', strtotime($sec['start_time'])) . ' - ' . date('h:i A', strtotime($sec['end_time']));
                }
                $groupedBySession['session_' . $secId] = [
                    'id' => $secId,
                    'title' => $sec['name'],
                    'icon' => 'fa-clock',
                    'color' => '#34d399',
                    'time_range' => $timeStr,
                    'date' => !empty($sec['section_date']) ? date('M d, Y', strtotime($sec['section_date'])) : '',
                    'programs' => []
                ];
            }
            $groupedBySession['unassigned'] = [
                'id' => 0,
                'title' => 'Unassigned Schedule Sessions',
                'icon' => 'fa-clock',
                'color' => '#94a3b8',
                'time_range' => '',
                'date' => '',
                'programs' => []
            ];

            foreach ($filteredPrograms as $prog) {
                $secId = (int)($prog['schedule_section_id'] ?? 0);
                $key = ($secId > 0 && isset($groupedBySession['session_' . $secId])) ? 'session_' . $secId : 'unassigned';
                $groupedBySession[$key]['programs'][] = $prog;
            }

            $activePanels = ($programGroupBy === 'session') ? $groupedBySession : $groupedByDivision;
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
                        <a href="<?= app_url('/admin/registrar/entries.php?view=programs&program_group_by=division' . ($sessionIdFilter !== 0 ? '&session_id=' . $sessionIdFilter : '') . ($classFilter !== 'all' ? '&class=' . urlencode($classFilter) : '') . ($typeFilter !== 'all' ? '&type=' . urlencode($typeFilter) : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $programGroupBy !== 'session' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;">
                            <i class="fa-solid fa-layer-group mr-1"></i> By Class Division
                        </a>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                        <?php if ($search !== '' || $sessionIdFilter !== 0 || $classFilter !== 'all' || $typeFilter !== 'all'): ?>
                            <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/registrar/entries.php?view=programs&program_group_by=' . urlencode($programGroupBy)) ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <?php
        $hasAnyPrograms = false;
        foreach ($activePanels as $p) {
            if (!empty($p['programs'])) {
                $hasAnyPrograms = true;
                break;
            }
        }
        ?>

        <?php if (!$hasAnyPrograms): ?>
            <div class="empty-state" style="padding: 40px; text-align: center;">
                <div class="empty-icon"><i class="fa-solid fa-folder-open" style="font-size: 36px; color: var(--muted);"></i></div>
                <div class="empty-title">No Programs Found</div>
                <div class="empty-subtitle">No programs match the selected filter criteria.</div>
            </div>
        <?php else: ?>
            <?php foreach ($activePanels as $panelKey => $panel): ?>
                <?php $tierPrograms = $panel['programs']; ?>
                <?php if (!$tierPrograms) continue; ?>

                <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.04); border-radius: 12px; overflow: hidden; padding: 0;">
                    <div style="background: rgba(255,255,255,0.015); padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,0.04); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <h3 style="font-size: 15px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid <?= $panel['icon'] ?>" style="color: <?= $panel['color'] ?>;"></i>
                            <?= e($panel['title']) ?>
                            <?php if (!empty($panel['time_range'])): ?>
                                <span style="font-size: 12px; color: var(--muted); font-weight: 500; margin-left: 6px;">(<?= e($panel['time_range']) ?>)</span>
                            <?php endif; ?>
                        </h3>
                        <span style="font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 99px; background: rgba(255,255,255,0.04); color: var(--muted); border: 1px solid rgba(255,255,255,0.02);">
                            <?= count($tierPrograms) ?> <?= count($tierPrograms) === 1 ? 'Program' : 'Programs' ?>
                        </span>
                    </div>

                    <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Program</th>
                                    <th>Type & Stage</th>
                                    <th>Schedule Session</th>
                                    <th>Class Division</th>
                                    <th>Registered / Limit</th>
                                    <th style="text-align: right; width: 220px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tierPrograms as $prog): ?>
                                    <?php
                                        $limitVal = (int)($prog['entries_limit'] ?? 10);
                                        $currentVal = (int)($prog['current_entries'] ?? 0);

                                        $secNames = [];
                                        if (!empty($prog['allowed_sections'])) {
                                            $secIds = array_filter(array_map('intval', explode(',', $prog['allowed_sections'])));
                                            foreach ($secIds as $sid) {
                                                if (isset($classTypesMap[$sid])) {
                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                    if ($label && !in_array($label, $secNames, true)) {
                                                        $secNames[] = $label;
                                                    }
                                                }
                                            }
                                        }
                                        $sectionDisplay = implode(' & ', $secNames);
                                        if ($sectionDisplay === '') {
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($prog['class_type_name'] ?? '—');
                                        } else {
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #fff; font-size: 14px;"><?= e($prog['title']) ?></strong>
                                            <div class="muted" style="font-size: 12px; font-weight: 500; margin-top: 2px;"><?= e($prog['location'] ?: '-') ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?= strtolower((string)$prog['program_type']) === 'individual' ? 'badge-info' : 'badge-neutral' ?>">
                                                <?= e(ucfirst($prog['program_type'])) ?>
                                            </span>
                                            <div class="muted" style="font-size: 11.5px; margin-top: 3px;">
                                                <?= e($prog['stage_type_name'] ?: '-') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($prog['schedule_section_name'])): ?>
                                                <span class="badge badge-success" style="font-weight: 700; background: rgba(52, 211, 153, 0.12); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.25);">
                                                    <i class="fa-solid fa-clock mr-1"></i> <?= e($prog['schedule_section_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="muted" style="font-size: 12px; font-weight: 500;">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                                <?= e($sectionDisplay) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-success" style="font-size: 12px; font-weight: 700;">
                                                <?= $currentVal ?> Registered
                                            </span>
                                            <div class="muted" style="font-size: 11.5px; margin-top: 2px;">
                                                <?= $limitVal ?> Limit / Team
                                            </div>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="flex gap-2 justify-end">
                                                <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$prog['id']) ?>" class="btn btn-primary btn-sm">
                                                    <i class="fa-solid fa-list-check mr-1"></i> View Entries (<?= $currentVal ?>)
                                                </a>
                                                <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$prog['id'] . '&view=assign') ?>" class="btn btn-success btn-sm">
                                                    <i class="fa-solid fa-user-plus mr-1"></i> Assign
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

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
                                <?= e($prog['title']) ?><?= !empty($prog['schedule_section_name']) ? ' [' . e($prog['schedule_section_name']) . ']' : '' ?> (<?= (int)$prog['current_entries'] ?> entries)
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
                                                <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="member-checkbox" data-team-id="<?= $tId ?>" <?= $isTeamFull ? 'disabled data-server-disabled="true"' : '' ?> style="display:none;">
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
                                        <span class="team-assigned-count" data-team-id="<?= (int)$teamGroup['id'] ?>"><?= $tAssigned ?></span> / <?= $perTeamLimit ?> Assigned
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
                                                <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="member-checkbox" data-team-id="<?= $tId ?>" <?= $isTeamFull ? 'disabled data-server-disabled="true"' : '' ?> style="display:none;">
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

        <?php else: ?>
            <!-- GROUP PROGRAM ENTRY CREATION & ASSIGNMENT WORKSPACE -->
            <div style="display: flex; flex-direction: column; gap: 24px;">
                <?php foreach ($teamsGrouped as $teamKey => $teamGroup): ?>
                    <?php
                        $teamId = (int)$teamGroup['id'];
                        $tAssigned = (int)($teamAssignedCounts[$teamId] ?? 0);
                        $isTeamFull = $tAssigned >= $perTeamLimit;
                        $defaultGroupEntryName = $activeProgram['title'] . ' - ' . $teamGroup['name'];
                        if ($tAssigned > 0) {
                            $defaultGroupEntryName .= ' (' . ($tAssigned + 1) . ')';
                        }

                        // Existing entries for this team
                        $teamEntries = array_values(array_filter($currentEntries, static fn($e) => (int)$e['team_id'] === $teamId));
                    ?>

                    <div class="panel" style="padding: 22px; background: rgba(15,23,42,0.65); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; border-left: 5px solid <?= e($teamGroup['color']) ?>;">
                        <div class="flex-between mb-4" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3 style="margin: 0; color: #fff; font-size: 17px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                                    <span class="team-color-dot" style="background: <?= e($teamGroup['color']) ?>; width: 12px; height: 12px; border-radius: 50%;"></span>
                                    <?= e($teamGroup['name']) ?>
                                </h3>
                                <div class="muted" style="font-size: 12.5px; margin-top: 3px;">
                                    <?= count($teamGroup['members']) ?> Eligible Members · Auto Name: <strong style="color: #60a5fa;"><?= e($defaultGroupEntryName) ?></strong>
                                </div>
                            </div>
                            <div>
                                <span class="badge <?= $isTeamFull ? 'badge-warning' : 'badge-success' ?>" style="font-weight: 700; font-size: 12px; padding: 6px 12px;">
                                    <?= $tAssigned ?> / <?= $perTeamLimit ?> Entries Registered
                                </span>
                            </div>
                        </div>

                        <!-- Registered Group Entries for this Team -->
                        <?php if (!empty($teamEntries)): ?>
                            <div class="mb-4" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 14px;">
                                <div style="font-size: 12.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                                    <i class="fa-solid fa-list-check mr-1" style="color: #34d399;"></i> Registered Entries for <?= e($teamGroup['name']) ?>:
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <?php foreach ($teamEntries as $tEntry): ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px 14px; flex-wrap: wrap; gap: 8px;">
                                            <div>
                                                <strong style="color: #fff; font-size: 14px;"><?= e($tEntry['entry_name']) ?></strong>
                                                <?php if (!empty($tEntry['member_names'])): ?>
                                                    <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                                        <i class="fa-solid fa-users mr-1"></i> <?= e($tEntry['member_names']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex gap-2 align-center">
                                                <span class="badge badge-neutral"><?= count($tEntry['member_ids']) ?> member(s)</span>
                                                <button type="submit" form="undoForm_<?= (int)$tEntry['id'] ?>" class="btn btn-xs btn-danger" style="font-weight: 700;">
                                                    <i class="fa-solid fa-rotate-left mr-1"></i> Undo Entry
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Create Group Entry Form -->
                        <?php if (!$isTeamFull): ?>
                            <form method="POST">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="create_entry">
                                <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">
                                <input type="hidden" name="team_id" value="<?= $teamId ?>">

                                <div class="grid gap-4 mb-4" style="grid-template-columns: minmax(260px, 1fr) 2fr; align-items: flex-start;">
                                    <div>
                                        <label style="font-weight: 700; font-size: 13px; color: #fff; display: block; margin-bottom: 6px;">
                                            Entry Name <span style="color: #60a5fa; font-weight: 500;">(Program - Team)</span>
                                        </label>
                                        <input type="text" name="entry_name" class="form-input" value="<?= e($defaultGroupEntryName) ?>" placeholder="<?= e($defaultGroupEntryName) ?>" required style="height: 42px; font-weight: 600; background: rgba(15,23,42,0.8); border: 1px solid rgba(99,102,241,0.3); border-radius: 8px; color: #fff; width: 100%;">
                                        <div class="muted" style="font-size: 11.5px; margin-top: 4px;">
                                            Automatically formatted as <strong>Program Name - Team Name</strong>
                                        </div>
                                    </div>

                                    <div>
                                        <label style="font-weight: 700; font-size: 13px; color: #fff; display: block; margin-bottom: 6px;">
                                            Select Participants for this Group Entry
                                        </label>
                                        <div class="grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); max-height: 260px; overflow-y: auto; padding-right: 4px;">
                                            <?php foreach ($teamGroup['members'] as $m): ?>
                                                <?php
                                                    $mId = (int)$m['team_member_id'];
                                                    $isAssigned = isset($assignedMemberMap[$mId]);
                                                ?>
                                                <?php if ($isAssigned): ?>
                                                    <div style="padding: 8px 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; opacity: 0.6; display: flex; align-items: center; justify-content: space-between;">
                                                        <span style="font-size: 12.5px; color: #cbd5e1; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                            <?= e($m['full_name']) ?>
                                                        </span>
                                                        <span style="font-size: 10.5px; color: #34d399; font-weight: 700;"><i class="fa-solid fa-check"></i> Assigned</span>
                                                    </div>
                                                <?php else: ?>
                                                    <label class="student-card-selection" style="padding: 8px 12px; margin: 0; cursor: pointer;">
                                                        <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="member-checkbox" style="display:none;">
                                                        <span class="student-avatar-badge" style="width: 28px; height: 28px; font-size: 11px;">
                                                            <span class="badge-text-chest">#<?= e($m['chest_number'] ?: mb_substr((string)$m['full_name'], 0, 1)) ?></span>
                                                            <i class="fa-solid fa-check badge-icon-check" style="font-size: 10px;"></i>
                                                        </span>
                                                        <div style="flex: 1; min-width: 0;">
                                                            <strong style="color: #fff; font-size: 12.5px; font-weight: 600; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                                <?= e($m['full_name']) ?>
                                                            </strong>
                                                            <div style="font-size: 10.5px; color: var(--muted);">
                                                                <?= e($m['class_name'] ?: 'General') ?>
                                                            </div>
                                                        </div>
                                                    </label>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 12px;">
                                    <button type="submit" class="btn btn-glow-success btn-md">
                                        <i class="fa-solid fa-plus mr-1"></i> Create Group Entry for <?= e($teamGroup['name']) ?>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div style="font-size: 13px; color: #f87171; font-weight: 600; padding: 10px 14px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2);">
                                <i class="fa-solid fa-lock mr-1"></i> Maximum entry limit (<?= $perTeamLimit ?>) reached for <?= e($teamGroup['name']) ?>.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Hidden Undo Forms for Group Entries -->
            <?php foreach ($currentEntries as $entry): ?>
                <form method="POST" id="undoForm_<?= (int)$entry['id'] ?>" style="display:none;" onsubmit="return confirm('Undo registration for this group entry?');">
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
window.TEAM_LIMIT_CONFIG = {
    perTeamLimit: <?= (int)$perTeamLimit ?>,
    teamAssignedCounts: <?= json_encode(array_map('intval', $teamAssignedCounts ?? [])) ?>
};

document.addEventListener('DOMContentLoaded', () => {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    const saveBtn = document.getElementById('saveBatchEntriesBtn');
    const countText = document.getElementById('selectedCountText');

    const config = window.TEAM_LIMIT_CONFIG || { perTeamLimit: 10, teamAssignedCounts: {} };
    const perTeamLimit = config.perTeamLimit;
    const assignedCounts = config.teamAssignedCounts || {};

    // Mark initial server-disabled checkboxes so JS never re-enables them
    checkboxes.forEach(cb => {
        if (cb.disabled) {
            cb.dataset.serverDisabled = 'true';
        }
    });

    function updateTeamSelectionLimits() {
        // Count checked boxes per team
        const checkedByTeam = {};
        const allTeamIds = new Set();

        checkboxes.forEach(cb => {
            const teamId = cb.getAttribute('data-team-id');
            if (teamId) {
                allTeamIds.add(teamId);
                if (cb.checked) {
                    checkedByTeam[teamId] = (checkedByTeam[teamId] || 0) + 1;
                }
            }
        });

        // Enforce limit per team
        allTeamIds.forEach(teamId => {
            const alreadySaved = parseInt(assignedCounts[teamId] || 0, 10);
            const selectedNow = checkedByTeam[teamId] || 0;
            const availableSlots = Math.max(0, perTeamLimit - alreadySaved);
            const teamLimitReached = selectedNow >= availableSlots;

            // Update team header badge if present
            const badgeCountEl = document.querySelector(`.team-assigned-count[data-team-id="${teamId}"]`);
            if (badgeCountEl) {
                badgeCountEl.textContent = (alreadySaved + selectedNow);
            }

            const teamCbs = document.querySelectorAll(`.member-checkbox[data-team-id="${teamId}"]`);
            teamCbs.forEach(cb => {
                if (cb.dataset.serverDisabled === 'true') {
                    return;
                }

                const card = cb.closest('.student-card-selection');
                if (!cb.checked) {
                    if (teamLimitReached) {
                        cb.disabled = true;
                        if (card) {
                            card.style.opacity = '0.45';
                            card.style.cursor = 'not-allowed';
                        }
                    } else {
                        cb.disabled = false;
                        if (card) {
                            card.style.opacity = '1';
                            card.style.cursor = 'pointer';
                        }
                    }
                }
            });
        });
    }

    function updateCount() {
        const checked = document.querySelectorAll('.member-checkbox:checked');
        if (countText) countText.textContent = checked.length;
        if (saveBtn) saveBtn.disabled = checked.length === 0;

        updateTeamSelectionLimits();
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

    // Run initial state update
    updateCount();
});
</script>

<?php admin_close_page(); ?>
