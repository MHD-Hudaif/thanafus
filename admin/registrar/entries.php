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
        LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
        WHERE mp.id = ? AND mp.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    return $program ?: null;
}

// Fetch all programs for header dropdowns & list
$stmt = $pdo->prepare("
    SELECT mp.*, ct.name AS class_type_name,
           (SELECT COUNT(*) FROM musabaqa_program_entries pe WHERE pe.program_id = mp.id AND pe.event_id = mp.event_id) AS current_entries
    FROM musabaqa_programs mp
    LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
    WHERE mp.event_id = ?
    ORDER BY mp.title ASC
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
                            JOIN kauzariyya.students s ON s.id = tm.student_id
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

                        $stmt = $pdo->prepare("
                            INSERT INTO musabaqa_program_entries
                                (event_id, program_id, team_id, entry_name, entry_number, status)
                            VALUES (?, ?, ?, ?, ?, 'approved')
                        ");
                        $stmt->execute([$activeEventId, $programId, $memberTeamId, $member['full_name'], $entryNumber]);
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

                    $stmt = $pdo->prepare("
                        INSERT INTO musabaqa_program_entries
                            (event_id, program_id, team_id, entry_name, entry_number, status)
                        VALUES (?, ?, ?, ?, ?, 'approved')
                    ");
                    $stmt->execute([$activeEventId, $programId, $teamId, $entryName, $entryNumber]);
                    $newEntryId = (int)$pdo->lastInsertId();

                    $addedCount = 0;
                    $skippedNames = [];

                    foreach ($teamMemberIds as $teamMemberId) {
                        $stmt = $pdo->prepare("
                            SELECT tm.*, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name
                            FROM musabaqa_team_members tm
                            JOIN kauzariyya.students s ON s.id = tm.student_id
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
                            JOIN kauzariyya.students s ON s.id = tm.student_id
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
            $stmt = $pdo->prepare('SELECT program_id FROM musabaqa_program_entries WHERE id = ? AND event_id = ? LIMIT 1');
            $stmt->execute([$entryId, $activeEventId]);
            $entryProgramId = (int)$stmt->fetchColumn();

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

            admin_flash('success', 'Entry deleted successfully.');
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

    // Fetch existing entries in this program
    $stmt = $pdo->prepare("
        SELECT pe.*, t.team_name, t.team_color,
               em.team_member_id, tm.chest_number,
               COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name,
               c.name AS class_name
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
        LEFT JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
        LEFT JOIN kauzariyya.students s ON s.id = tm.student_id
        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
        WHERE pe.event_id = ? AND pe.program_id = ?
        ORDER BY pe.entry_number ASC, pe.id ASC
    ");
    $stmt->execute([$activeEventId, $selectedProgramId]);
    $rawEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $currentEntriesMap = [];
    $assignedMemberMap = [];

    foreach ($rawEntries as $row) {
        $eId = (int)$row['id'];
        if (!isset($currentEntriesMap[$eId])) {
            $tId = (int)$row['team_id'];
            $teamAssignedCounts[$tId] = ($teamAssignedCounts[$tId] ?? 0) + 1;
            $currentEntriesMap[$eId] = [
                'id' => $eId,
                'event_id' => (int)$row['event_id'],
                'program_id' => (int)$row['program_id'],
                'team_id' => $tId,
                'entry_name' => $row['entry_name'],
                'entry_number' => (int)$row['entry_number'],
                'status' => $row['status'],
                'team_name' => $row['team_name'],
                'team_color' => $row['team_color'],
                'members' => [],
            ];
        }

        if (!empty($row['team_member_id'])) {
            $tmId = (int)$row['team_member_id'];
            $currentEntriesMap[$eId]['members'][] = [
                'team_member_id' => $tmId,
                'chest_number' => $row['chest_number'],
                'full_name' => $row['full_name'],
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
        JOIN kauzariyya.students s ON s.id = tm.student_id
        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
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
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>



<div class="main-content">

    <?php if ($flash = admin_take_flash()): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$activeProgram): ?>
        <!-- VIEW 1: PROGRAMS LIST BY DIVISION (List Style like Programs Page!) -->
        <div class="topbar">
            <div>
                <div class="page-title"><i class="fa-solid fa-list-check mr-2" style="color:var(--accent);"></i> Programs & Entries Hub</div>
                <div class="page-subtitle">Select a program from the division lists below to assign team entries</div>
            </div>
            <div class="flex gap-2">
                <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-gear mr-1"></i> Manage Programs
                </a>
            </div>
        </div>

        <div class="panel mb-6">
            <form method="GET" class="form-grid">
                <input type="hidden" name="view" value="programs">
                <div class="input-group">
                    <label>Search Program</label>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program title or section...">
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
                <div class="form-actions full-width">
                    <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                    <?php if ($search !== '' || $typeFilter !== 'all' || $classFilter !== 'all'): ?>
                        <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/registrar/entries.php?view=programs') ?>">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php
        // Filter & Group programs into divisions matching programs.php
        $filteredPrograms = [];
        foreach ($programs as $prog) {
            if ($search !== '' && stripos($prog['title'] . ' ' . ($prog['class_type_name'] ?? ''), $search) === false) {
                continue;
            }
            if ($typeFilter !== 'all' && $prog['program_type'] !== $typeFilter) {
                continue;
            }
            $filteredPrograms[] = $prog;
        }

        $tiers = [
            'senior' => 'Senior',
            'junior' => 'Junior',
            'subjunior' => 'Sub Junior',
            'general' => 'General / Multi-Section'
        ];

        $grouped = [
            'subjunior' => [],
            'junior' => [],
            'senior' => [],
            'general' => []
        ];

        foreach ($filteredPrograms as $prog) {
            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
            $allowedCount = !empty($prog['allowed_sections']) ? count(explode(',', $prog['allowed_sections'])) : 0;
            
            if ($allowedCount > 1 || !$classTier) {
                $grouped['general'][] = $prog;
            } else {
                $grouped[$classTier][] = $prog;
            }
        }
        ?>

        <?php if (empty($filteredPrograms)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-layer-group"></i></div>
                <div class="empty-title">No Programs Found</div>
                <div class="empty-subtitle">Try clearing search filters or create a program.</div>
            </div>
        <?php else: ?>
            <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                <?php 
                    if ($classFilter !== 'all' && $classFilter !== $tierKey) continue;
                    $tierPrograms = $grouped[$tierKey] ?? []; 
                ?>
                <?php if (!$tierPrograms) continue; ?>

                <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; overflow: hidden; padding: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px);">
                    <div style="background: rgba(255,255,255,0.02); padding: 16px 22px; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="font-size: 16px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-layer-group" style="color: #facc15;"></i>
                            <?= e($tierLabel) ?> Division
                        </h3>
                        <span style="font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; background: rgba(99,102,241,0.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.25);">
                            <?= count($tierPrograms) ?> <?= count($tierPrograms) === 1 ? 'Program' : 'Programs' ?>
                        </span>
                    </div>

                    <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0;">
                        <table class="table table-glass">
                            <thead>
                                <tr>
                                    <th>Program Title</th>
                                    <th>Type</th>
                                    <th>Section / Class</th>
                                    <th>Limit per Team</th>
                                    <th>Total Entries</th>
                                    <th style="text-align: right; width: 180px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tierPrograms as $prog): ?>
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
                                                <i class="fa-solid fa-user-plus mr-1"></i> Manage Entries
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- VIEW 2: PROGRAM SELECTION WORKSPACE (Team Grouped Grid with Per-Team Limits!) -->
        <div class="topbar">
            <div>
                <div class="page-title">
                    <i class="fa-solid fa-clipboard-list mr-2" style="color:var(--accent);"></i> <?= e($activeProgram['title']) ?>
                </div>
                <div class="page-subtitle">
                    Class: <strong><?= e(admin_class_type_display($activeProgram['class_type_name'] ?? null, (int)($activeProgram['class_type_id'] ?? 0))) ?></strong> · 
                    Type: <strong><?= e(ucfirst($activeProgram['program_type'])) ?></strong>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="<?= app_url('/admin/registrar/entries.php?view=programs') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-arrow-left mr-1"></i> All Programs
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

                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="font-weight: 600; font-size: 13px; color: var(--muted); margin:0;">Program:</label>
                    <select name="program_id" class="form-input" style="height: 38px; font-size: 13px; max-width: 280px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;" onchange="this.form.submit()">
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?= (int)$prog['id'] ?>" <?= $selectedProgramId === (int)$prog['id'] ? 'selected' : '' ?>>
                                <?= e($prog['title']) ?> (<?= (int)$prog['current_entries'] ?> entries · Limit: <?= (int)($prog['entries_limit'] ?? 10) ?>/team)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="width: 180px;">
                    <select name="team" class="form-input" style="height: 38px; font-size: 13px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;" onchange="this.form.submit()">
                        <option value="0">All Teams</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $teamFilter === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['team_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="flex: 1; min-width: 180px;">
                    <input type="text" name="search" class="form-input" placeholder="Search participant, chest # or admission..." value="<?= e($search) ?>" style="height: 38px; font-size: 13px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
                </div>

                <button type="submit" class="btn btn-secondary btn-md" style="height: 38px;"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                <?php if ($search !== '' || $teamFilter > 0): ?>
                    <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . (int)$activeProgram['id']) ?>" class="btn btn-link btn-sm" style="color: var(--muted);">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($activeProgram['program_type'] === 'individual'): ?>
            <!-- SELECTION FORM (Per-Team Limit Enforcement!) -->
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

                <!-- TEAM Grouped Student Cards -->
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <?php if (empty($teamsGrouped)): ?>
                        <div class="panel text-center" style="padding: 40px; color: var(--muted);">
                            <i class="fa-solid fa-user-slash mb-2" style="font-size: 28px; display: block;"></i>
                            No eligible team members found matching criteria.
                        </div>
                    <?php else: ?>
                        <?php foreach ($teamsGrouped as $teamKey => $teamGroup): ?>
                            <?php
                                $tAssigned = (int)($teamAssignedCounts[$teamGroup['id']] ?? 0);
                                $tRemaining = max(0, $perTeamLimit - $tAssigned);
                                $isTeamFull = $tAssigned >= $perTeamLimit;
                            ?>
                            <div class="panel" style="padding: 18px 22px; background: rgba(15,23,42,0.55); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; border-left: 4px solid <?= e($teamGroup['color']) ?>; <?= $isTeamFull ? 'opacity: 0.8;' : '' ?>">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.06);">
                                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                        <span class="team-color-dot" style="background: <?= e($teamGroup['color']) ?>;"></span>
                                        <strong style="font-size: 16px; color: #fff;"><?= e($teamGroup['name']) ?></strong>
                                        <span style="font-size: 12px; color: var(--muted);">(<?= count($teamGroup['members']) ?> Eligible Member<?= count($teamGroup['members']) === 1 ? '' : 's' ?>)</span>
                                        
                                        <span class="badge" style="font-size: 11px; background: <?= $isTeamFull ? 'rgba(239,68,68,0.2)' : 'rgba(99,102,241,0.2)' ?>; color: <?= $isTeamFull ? '#f87171' : '#a5b4fc' ?>; border: 1px solid <?= $isTeamFull ? 'rgba(239,68,68,0.3)' : 'rgba(99,102,241,0.3)' ?>;">
                                            <?= $tAssigned ?> / <?= $perTeamLimit ?> Assigned
                                        </span>

                                        <?php if ($isTeamFull): ?>
                                            <span class="badge" style="background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.3); font-size: 11px;">
                                                <i class="fa-solid fa-lock mr-1"></i> Team Limit Reached
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size: 11.5px; color: #34d399; font-weight: 700;">
                                                <?= $tRemaining ?> slot(s) left
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!$isTeamFull): ?>
                                        <button type="button" class="btn btn-link btn-xs select-all-class-btn" data-team-id="<?= (int)$teamGroup['id'] ?>" data-class-key="<?= e($teamKey) ?>" data-remaining="<?= $tRemaining ?>" style="color: #a5b4fc; font-size: 12px; text-decoration: none;">
                                            <i class="fa-solid fa-check-double mr-1"></i> Select Up to <?= $tRemaining ?> in <?= e($teamGroup['name']) ?>
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #f87171; font-weight: 600;">
                                            <i class="fa-solid fa-ban mr-1"></i> Disabled (Team Full)
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));" id="<?= e($teamKey) ?>">
                                    <?php foreach ($teamGroup['members'] as $m): ?>
                                        <?php
                                            $mId = (int)$m['team_member_id'];
                                            $isAssigned = isset($assignedMemberMap[$mId]);
                                            $entryData = $isAssigned ? $assignedMemberMap[$mId] : null;
                                            $isDisabled = $isAssigned || $isTeamFull;
                                        ?>
                                        <label class="student-card-selection <?= $isAssigned ? 'assigned' : '' ?>" style="<?= $isDisabled && !$isAssigned ? 'opacity: 0.45; cursor: not-allowed;' : '' ?>">
                                            <?php if (!$isAssigned): ?>
                                                <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="custom-checkbox-input member-checkbox" data-team-id="<?= (int)$teamGroup['id'] ?>" data-class-key="<?= e($teamKey) ?>" <?= $isTeamFull ? 'disabled' : '' ?>>
                                                <span class="student-avatar-badge">#<?= e($m['chest_number'] ?: mb_substr((string)$m['full_name'], 0, 1)) ?></span>
                                            <?php else: ?>
                                                <span class="student-avatar-badge"><i class="fa-solid fa-check"></i></span>
                                            <?php endif; ?>

                                            <div style="flex: 1; min-width: 0;">
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                                        <?= e($m['full_name']) ?>
                                                    </strong>
                                                </div>

                                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                    <span class="student-meta-badge" style="background: rgba(99, 102, 241, 0.12); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.25);">
                                                        <i class="fa-solid fa-layer-group" style="font-size: 10px;"></i> <?= e($m['class_name'] ?: 'Unassigned') ?>
                                                    </span>
                                                    <?php if (!empty($m['admission_no'])): ?>
                                                        <span class="student-meta-badge">Adm: <?= e($m['admission_no']) ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($isAssigned): ?>
                                                    <div style="font-size: 11px; margin-top: 4px; color: #34d399; font-weight: 700;">
                                                        <i class="fa-solid fa-check mr-1"></i> Assigned (#<?= (int)$entryData['entry_number'] ?>)
                                                    </div>
                                                <?php elseif ($isTeamFull): ?>
                                                    <div style="font-size: 11px; margin-top: 4px; color: #f87171; font-weight: 600;">
                                                        <i class="fa-solid fa-lock mr-1"></i> Team Limit Reached
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>
        <?php else: ?>
            <!-- GROUP PROGRAM ENTRY FORM -->
            <div class="panel mb-6" style="padding: 22px; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px;">
                <div style="font-size: 16px; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-users-rectangle" style="color: #6366f1;"></i> Create New Group Entry
                </div>

                <form method="POST" id="createGroupForm">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="create_entry">
                    <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px;">
                        <div class="input-group" style="margin:0;">
                            <label style="font-weight: 700; font-size: 13px;">Team <span class="required">*</span></label>
                            <select name="team_id" id="groupTeamSelect" required class="form-input" style="height: 42px; font-size: 13px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; color: #fff;">
                                <option value="">-- Select Team --</option>
                                <?php foreach ($teams as $t): ?>
                                    <?php
                                        $tId = (int)$t['id'];
                                        $tAssigned = (int)($teamAssignedCounts[$tId] ?? 0);
                                        $isFull = $tAssigned >= $perTeamLimit;
                                    ?>
                                    <option value="<?= $tId ?>" <?= $isFull ? 'disabled' : '' ?>>
                                        <?= e($t['team_name']) ?> (<?= $tAssigned ?> / <?= $perTeamLimit ?> Entries<?= $isFull ? ' - Limit Reached' : '' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="input-group" style="margin:0;">
                            <label style="font-weight: 700; font-size: 13px;">Entry Name <span class="required">*</span></label>
                            <input type="text" name="entry_name" id="groupEntryNameInput" class="form-input" placeholder="e.g. Group A, Duff Team 1" required style="height: 42px; font-size: 13px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; color: #fff;">
                        </div>
                    </div>

                    <!-- Student Member Selection Block -->
                    <div style="margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="color: #fff; font-size: 14px;"><i class="fa-solid fa-user-check mr-1" style="color: #34d399;"></i> Select Group Members</strong>
                                <span style="font-size: 12px; color: var(--muted); display: block; margin-top: 2px;">Pick students from the selected team to assign to this group entry.</span>
                            </div>
                            <div style="font-size: 13px; color: var(--muted);">
                                Selected: <strong id="groupSelectedCountText" style="color: #6366f1; font-size: 16px;">0</strong> members
                            </div>
                        </div>

                        <div id="groupNoTeamNotice" class="panel text-center" style="padding: 30px; color: var(--muted); background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.08); border-radius: 12px;">
                            <i class="fa-solid fa-arrow-up-long mb-2" style="font-size: 24px; color: var(--accent);"></i>
                            <div>Please select a team above to display eligible team members.</div>
                        </div>

                        <?php foreach ($teamsGrouped as $teamKey => $teamGroup): ?>
                            <div class="group-team-cards-container" id="groupTeamCards-<?= (int)$teamGroup['id'] ?>" style="display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding: 10px 14px; background: rgba(255,255,255,0.03); border-radius: 10px;">
                                    <span style="font-size: 13px; font-weight: 700; color: #fff;">
                                        <span class="team-color-dot" style="background: <?= e($teamGroup['color']) ?>; margin-right: 6px;"></span>
                                        <?= e($teamGroup['name']) ?> Members (<?= count($teamGroup['members']) ?> Eligible)
                                    </span>
                                    <button type="button" class="btn btn-link btn-xs group-select-all-btn" data-team-id="<?= (int)$teamGroup['id'] ?>" style="color: #a5b4fc; text-decoration: none; font-size: 12px;">
                                        <i class="fa-solid fa-check-double mr-1"></i> Toggle All Available
                                    </button>
                                </div>

                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                                    <?php foreach ($teamGroup['members'] as $m): ?>
                                        <?php
                                            $mId = (int)$m['team_member_id'];
                                            $isAssigned = isset($assignedMemberMap[$mId]);
                                            $assignedEntry = $isAssigned ? $assignedMemberMap[$mId] : null;
                                        ?>
                                        <label class="student-card-selection <?= $isAssigned ? 'assigned' : '' ?>" style="<?= $isAssigned ? 'opacity: 0.5; cursor: not-allowed;' : '' ?>">
                                            <?php if (!$isAssigned): ?>
                                                <input type="checkbox" name="team_member_ids[]" value="<?= $mId ?>" class="custom-checkbox-input group-member-checkbox" data-team-id="<?= (int)$teamGroup['id'] ?>">
                                                <span class="student-avatar-badge">#<?= e($m['chest_number'] ?: mb_substr((string)$m['full_name'], 0, 1)) ?></span>
                                            <?php else: ?>
                                                <span class="student-avatar-badge"><i class="fa-solid fa-check"></i></span>
                                            <?php endif; ?>

                                            <div style="flex: 1; min-width: 0;">
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                                        <?= e($m['full_name']) ?>
                                                    </strong>
                                                </div>

                                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                    <span class="student-meta-badge" style="background: rgba(99, 102, 241, 0.12); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.25);">
                                                        <i class="fa-solid fa-layer-group" style="font-size: 10px;"></i> <?= e($m['class_name'] ?: 'Unassigned') ?>
                                                    </span>
                                                    <?php if (!empty($m['admission_no'])): ?>
                                                        <span class="student-meta-badge">Adm: <?= e($m['admission_no']) ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($isAssigned): ?>
                                                    <div style="font-size: 11px; margin-top: 4px; color: #f87171; font-weight: 600;">
                                                        <i class="fa-solid fa-ban mr-1"></i> In #<?= (int)$assignedEntry['entry_number'] ?> (<?= e($assignedEntry['entry_name']) ?>)
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions full-width mt-6" style="display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn btn-glow-success btn-md" id="submitCreateGroupBtn">
                            <i class="fa-solid fa-plus mr-1"></i> Create Group Entry
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- CURRENT ENTRIES TABLE -->
        <div class="panel mt-8" style="border: 1px solid rgba(255,255,255,0.04); background: rgba(255,255,255,0.015); border-radius: 12px; padding: 0; overflow: hidden;">
            <div style="padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.06); font-weight: 700; font-size: 14px; color: #fff;">
                <i class="fa-solid fa-list mr-2" style="color:var(--accent);"></i> Existing Entries in <?= e($activeProgram['title']) ?> (<?= count($currentEntries) ?> Total Registered)
            </div>
            <div class="table-wrapper">
                <table class="table table-glass">
                    <thead>
                        <tr>
                            <th style="width: 70px;">#</th>
                            <th>Entry Name / Participant</th>
                            <th>Chest #</th>
                            <th>Team</th>
                            <th>Group Members</th>
                            <th>Status</th>
                            <th style="text-align: right; width: 140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($currentEntries)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 30px; color: var(--muted);">
                                    No entries registered for <?= e($activeProgram['title']) ?> yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($currentEntries as $entry): ?>
                                <tr>
                                    <td><span class="badge badge-neutral">#<?= (int)$entry['entry_number'] ?></span></td>
                                    <td><strong style="color: #fff; font-size: 13.5px;"><?= e($entry['entry_name']) ?></strong></td>
                                    <td>
                                        <?php
                                            $chests = array_filter(array_column($entry['members'], 'chest_number'));
                                        ?>
                                        <span style="color:#6366f1; font-weight:700; font-size: 12.5px;">
                                            <?= $chests ? '#' . implode(', #', array_map('e', $chests)) : '-' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-info" style="border-left: 3px solid <?= e($entry['team_color'] ?: '#14b8a6') ?>;">
                                            <?= e($entry['team_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($entry['members'])): ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px; max-width: 380px;">
                                                <?php foreach ($entry['members'] as $m): ?>
                                                    <span class="badge" style="background: rgba(99,102,241,0.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.25); font-size: 11px;">
                                                        #<?= e($m['chest_number'] ?: '-') ?> <?= e($m['full_name']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="font-size: 11px;">No Members Selected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= entries_status_badge($entry['status']) ?>"><?= e(ucfirst($entry['status'])) ?></span></td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <?php if ($activeProgram['program_type'] === 'group'): ?>
                                                <button type="button" class="btn btn-secondary btn-xs btn-edit-group-entry"
                                                        data-entry-id="<?= (int)$entry['id'] ?>"
                                                        data-entry-name="<?= e($entry['entry_name']) ?>"
                                                        data-team-id="<?= (int)$entry['team_id'] ?>"
                                                        data-team-name="<?= e($entry['team_name']) ?>"
                                                        data-members='<?= json_encode(array_values(array_column($entry['members'], 'team_member_id'))) ?>'
                                                        title="Manage Group Members">
                                                    <i class="fa-solid fa-user-gear"></i>
                                                </button>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                                                <?= admin_csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_entry">
                                                <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-xs" title="Delete Entry">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($activeProgram['program_type'] === 'group'): ?>
            <!-- EDIT GROUP ENTRY MODAL -->
            <div id="editGroupModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.8); z-index:9999; backdrop-filter:blur(8px); align-items:center; justify-content:center;">
                <div style="background:#0f172a; border:1px solid rgba(99,102,241,0.3); border-radius:16px; width:92%; max-width:680px; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.7);">
                    <div style="padding:18px 22px; background:rgba(255,255,255,0.03); border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
                        <h3 style="font-size:16px; font-weight:800; color:#fff; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-user-pen" style="color:#818cf8;"></i> Manage Group Entry & Members
                        </h3>
                        <button type="button" id="closeEditGroupModal" style="background:none; border:none; color:var(--muted); font-size:20px; cursor:pointer;">&times;</button>
                    </div>

                    <form method="POST" id="editGroupForm" style="display:flex; flex-direction:column; flex:1; overflow:hidden; margin:0;">
                        <?= admin_csrf_field() ?>
                        <input type="hidden" name="action" value="update_entry">
                        <input type="hidden" name="update_group_members" value="1">
                        <input type="hidden" name="entry_id" id="modalEditEntryId">
                        <input type="hidden" name="program_id" value="<?= (int)$activeProgram['id'] ?>">

                        <div style="padding:20px; overflow-y:auto; flex:1;">
                            <div class="input-group" style="margin-bottom:18px;">
                                <label style="font-weight:700; font-size:13px;">Entry Name <span class="required">*</span></label>
                                <input type="text" name="entry_name" id="modalEditEntryName" class="form-input" required style="height:40px; font-size:13px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#fff;">
                            </div>

                            <div style="margin-bottom:14px; font-size:13px; color:#a5b4fc; font-weight:700;" id="modalTeamLabel"></div>

                            <div style="font-size:13px; font-weight:700; color:#fff; margin-bottom:10px;">Select Group Members:</div>
                            <div id="modalMembersGrid" class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
                                <!-- Populated dynamically via JS per team -->
                            </div>
                        </div>

                        <div style="padding:16px 22px; background:rgba(255,255,255,0.03); border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:flex-end; gap:10px;">
                            <button type="button" class="btn btn-secondary btn-md" id="cancelEditGroupModal">Cancel</button>
                            <button type="submit" class="btn btn-glow-success btn-md"><i class="fa-solid fa-check mr-1"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
(() => {
    const perTeamLimit = <?= (int)$perTeamLimit ?>;
    const teamAssignedCounts = <?= json_encode($teamAssignedCounts) ?>;

    const checkboxes = document.querySelectorAll('.member-checkbox');
    const selectedCountText = document.getElementById('selectedCountText');
    const saveBtn = document.getElementById('saveBatchEntriesBtn');
    const warningAlert = document.getElementById('selectionWarningAlert');

    function getTeamCheckedCounts() {
        const counts = {};
        document.querySelectorAll('.member-checkbox:checked').forEach(cb => {
            const teamId = cb.dataset.teamId;
            counts[teamId] = (counts[teamId] || 0) + 1;
        });
        return counts;
    }

    function updateSelectionState() {
        const checked = document.querySelectorAll('.member-checkbox:checked');
        const count = checked.length;
        if (selectedCountText) selectedCountText.textContent = count;

        const teamChecked = getTeamCheckedCounts();
        let anyTeamExceeded = false;

        checkboxes.forEach(cb => {
            const card = cb.closest('.student-card-selection');
            if (card) {
                if (cb.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            }
        });

        // Enforce Per-Team Limit live in JS
        const teamGroups = {};
        checkboxes.forEach(cb => {
            const teamId = cb.dataset.teamId;
            if (!teamGroups[teamId]) teamGroups[teamId] = [];
            teamGroups[teamId].push(cb);
        });

        Object.keys(teamGroups).forEach(teamId => {
            const assigned = Number(teamAssignedCounts[teamId] || 0);
            const newlySelected = Number(teamChecked[teamId] || 0);
            const totalForTeam = assigned + newlySelected;

            const teamCbs = teamGroups[teamId];
            if (totalForTeam >= perTeamLimit) {
                if (newlySelected > perTeamLimit - assigned) {
                    anyTeamExceeded = true;
                }
                teamCbs.forEach(cb => {
                    if (!cb.checked) {
                        cb.disabled = true;
                        const card = cb.closest('.student-card-selection');
                        if (card) card.style.opacity = '0.45';
                    }
                });
            } else {
                teamCbs.forEach(cb => {
                    cb.disabled = false;
                    const card = cb.closest('.student-card-selection');
                    if (card) card.style.opacity = '1';
                });
            }
        });

        if (anyTeamExceeded) {
            if (warningAlert) {
                warningAlert.style.display = 'block';
                warningAlert.textContent = `A team selection exceeds the per-team limit of ${perTeamLimit}.`;
            }
            if (saveBtn) saveBtn.disabled = true;
        } else {
            if (warningAlert) warningAlert.style.display = 'none';
            if (saveBtn) saveBtn.disabled = count === 0;
        }
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectionState);
    });

    document.querySelectorAll('.select-all-class-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const classKey = btn.dataset.classKey;
            const teamId = btn.dataset.teamId;
            const classContainer = document.getElementById(classKey);
            if (!classContainer) return;

            const teamCbs = classContainer.querySelectorAll('.member-checkbox');
            const allChecked = Array.from(teamCbs).every(cb => cb.checked);

            const assigned = Number(teamAssignedCounts[teamId] || 0);
            const maxAllowed = Math.max(0, perTeamLimit - assigned);

            let checkedSoFar = 0;
            teamCbs.forEach(cb => {
                if (!allChecked) {
                    if (checkedSoFar < maxAllowed) {
                        cb.checked = true;
                        checkedSoFar++;
                    }
                } else {
                    cb.checked = false;
                }
            });
            updateSelectionState();
        });
    });

    updateSelectionState();

    // Group Program Dynamic Team Selection & Modal Handler
    const groupTeamSelect = document.getElementById('groupTeamSelect');
    const groupNoTeamNotice = document.getElementById('groupNoTeamNotice');
    const groupSelectedCountText = document.getElementById('groupSelectedCountText');

    const allTeamMemberCards = <?= json_encode($teamsGrouped) ?>;
    const assignedMemberMap = <?= json_encode($assignedMemberMap) ?>;

    function updateGroupSelectionState() {
        if (!groupTeamSelect) return;
        const selectedTeamId = groupTeamSelect.value;

        document.querySelectorAll('.group-team-cards-container').forEach(el => {
            el.style.display = 'none';
        });

        if (!selectedTeamId) {
            if (groupNoTeamNotice) groupNoTeamNotice.style.display = 'block';
            if (groupSelectedCountText) groupSelectedCountText.textContent = '0';
            return;
        }

        if (groupNoTeamNotice) groupNoTeamNotice.style.display = 'none';
        const activeContainer = document.getElementById(`groupTeamCards-${selectedTeamId}`);
        if (activeContainer) {
            activeContainer.style.display = 'block';
            const checked = activeContainer.querySelectorAll('.group-member-checkbox:checked');
            if (groupSelectedCountText) groupSelectedCountText.textContent = checked.length;
        }
    }

    if (groupTeamSelect) {
        groupTeamSelect.addEventListener('change', updateGroupSelectionState);
        document.querySelectorAll('.group-member-checkbox').forEach(cb => {
            cb.addEventListener('change', updateGroupSelectionState);
        });
        document.querySelectorAll('.group-select-all-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const teamId = btn.dataset.teamId;
                const container = document.getElementById(`groupTeamCards-${teamId}`);
                if (!container) return;
                const cbs = Array.from(container.querySelectorAll('.group-member-checkbox:not(:disabled)'));
                const allChecked = cbs.every(cb => cb.checked);
                cbs.forEach(cb => cb.checked = !allChecked);
                updateGroupSelectionState();
            });
        });
        updateGroupSelectionState();
    }

    // Modal Edit Handler
    const editModal = document.getElementById('editGroupModal');
    const modalEntryId = document.getElementById('modalEditEntryId');
    const modalEntryName = document.getElementById('modalEditEntryName');
    const modalTeamLabel = document.getElementById('modalTeamLabel');
    const modalMembersGrid = document.getElementById('modalMembersGrid');

    document.querySelectorAll('.btn-edit-group-entry').forEach(btn => {
        btn.addEventListener('click', () => {
            const entryId = btn.dataset.entryId;
            const entryName = btn.dataset.entryName;
            const teamId = Number(btn.dataset.teamId);
            const teamName = btn.dataset.teamName;
            const memberIds = JSON.parse(btn.dataset.members || '[]');

            if (modalEntryId) modalEntryId.value = entryId;
            if (modalEntryName) modalEntryName.value = entryName;
            if (modalTeamLabel) modalTeamLabel.textContent = `Team: ${teamName}`;

            // Render member cards for this team
            const teamKey = `team-${teamId}`;
            const teamData = allTeamMemberCards[teamKey];
            if (modalMembersGrid) {
                modalMembersGrid.innerHTML = '';

                if (teamData && teamData.members) {
                    teamData.members.forEach(m => {
                        const mId = Number(m.team_member_id);
                        const isInThisEntry = memberIds.includes(mId);
                        const isAssignedOther = assignedMemberMap[mId] && Number(assignedMemberMap[mId].id) !== Number(entryId);

                        const label = document.createElement('label');
                        label.className = `student-card-selection ${isInThisEntry ? 'selected' : (isAssignedOther ? 'assigned' : '')}`;
                        if (isAssignedOther) {
                            label.style.opacity = '0.5';
                            label.style.cursor = 'not-allowed';
                        }

                        label.innerHTML = `
                            ${!isAssignedOther ? `<input type="checkbox" name="team_member_ids[]" value="${mId}" class="custom-checkbox-input" ${isInThisEntry ? 'checked' : ''}>` : ''}
                            <span class="student-avatar-badge">${isInThisEntry ? '<i class="fa-solid fa-check"></i>' : (m.chest_number ? '#' + m.chest_number : (m.full_name ? m.full_name.substring(0, 1) : 'S'))}</span>
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                    <strong style="color: #fff; font-size: 13.5px; font-weight: 700; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                        ${m.full_name}
                                    </strong>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                    <span class="student-meta-badge" style="background: rgba(99, 102, 241, 0.12); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.25);">
                                        <i class="fa-solid fa-layer-group" style="font-size: 10px;"></i> ${m.class_name || 'Unassigned'}
                                    </span>
                                    ${m.admission_no ? `<span class="student-meta-badge">Adm: ${m.admission_no}</span>` : ''}
                                </div>
                                ${isAssignedOther ? `<div style="font-size: 11px; margin-top: 4px; color: #f87171; font-weight: 600;"><i class="fa-solid fa-ban mr-1"></i> Assigned to another entry</div>` : (isInThisEntry ? `<div style="font-size: 11px; margin-top: 4px; color: #34d399; font-weight: 700;"><i class="fa-solid fa-check mr-1"></i> Current Member</div>` : '')}
                            </div>
                        `;
                        modalMembersGrid.appendChild(label);
                    });
                }
            }

            if (editModal) editModal.style.display = 'flex';
        });
    });

    const closeModalFunc = () => {
        if (editModal) editModal.style.display = 'none';
    };

    document.getElementById('closeEditGroupModal')?.addEventListener('click', closeModalFunc);
    document.getElementById('cancelEditGroupModal')?.addEventListener('click', closeModalFunc);
})();
</script>

<?php admin_close_page(); ?>
