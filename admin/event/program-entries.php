<?php
$pageTitle = 'Assign Entries';

define('EVENT_AUTHORITY_SCOPE', 'assign-entries');
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function entries_redirect(array $query = []): void
{
    admin_redirect('/admin/event/program-entries.php', $query);
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

// POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        entries_redirect();
    }

    $action = (string)($_POST['action'] ?? '');
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);
    $teamId = (int)($_POST['team_id'] ?? 0);

    $redirectQuery = [];
    if ($programId > 0) {
        $redirectQuery['program_id'] = $programId;
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

            if ($action === 'create_entry') {
                if ($teamId <= 0) {
                    throw new RuntimeException('Please select a team.');
                }

                $stmt = $pdo->prepare('SELECT id FROM musabaqa_teams WHERE id = ? AND event_id = ? LIMIT 1');
                $stmt->execute([$teamId, $activeEventId]);
                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('Selected team is invalid.');
                }

                $entryNumber = entries_next_number($pdo, $activeEventId, $programId);

                if ($program['program_type'] === 'individual') {
                    $teamMemberId = (int)($_POST['team_member_id'] ?? 0);
                    if ($teamMemberId <= 0) {
                        throw new RuntimeException('Please select a participant.');
                    }

                    $stmt = $pdo->prepare("
                        SELECT tm.*, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name, c.class_type_id
                        FROM musabaqa_team_members tm
                        JOIN kauzariyya.students s ON s.id = tm.student_id
                        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
                        WHERE tm.id = ?
                          AND tm.team_id = ?
                          AND tm.event_id = ?
                          AND tm.status = 'active'
                        LIMIT 1
                    ");
                    $stmt->execute([$teamMemberId, $teamId, $activeEventId]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$member) {
                        throw new RuntimeException('Participant not found in selected team.');
                    }
                    
                    admin_validate_member_program_limits($pdo, $activeEventId, $programId, $teamMemberId);
                    admin_validate_program_entry_limit($pdo, $activeEventId, $programId);

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
                        throw new RuntimeException('Participant is already assigned to this program.');
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO musabaqa_program_entries
                            (event_id, program_id, team_id, entry_name, entry_number, status)
                        VALUES (?, ?, ?, ?, ?, 'approved')
                    ");
                    $stmt->execute([$activeEventId, $programId, $teamId, $member['full_name'], $entryNumber]);
                    $entryId = (int)$pdo->lastInsertId();

                    $stmt = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, 'Participant')");
                    $stmt->execute([$entryId, $teamMemberId]);
                } else {
                    $entryName = trim((string)($_POST['entry_name'] ?? ''));
                    if ($entryName === '') {
                        throw new RuntimeException('Entry name is required.');
                    }

                    $dup = $pdo->prepare('SELECT id FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ? AND team_id = ? AND entry_name = ? LIMIT 1');
                    $dup->execute([$activeEventId, $programId, $teamId, $entryName]);
                    if ($dup->fetchColumn()) {
                        throw new RuntimeException('This team already has an entry with that name.');
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO musabaqa_program_entries
                            (event_id, program_id, team_id, entry_name, entry_number, status)
                        VALUES (?, ?, ?, ?, ?, 'approved')
                    ");
                    $stmt->execute([$activeEventId, $programId, $teamId, $entryName, $entryNumber]);
                }

                admin_recalculate_program_status($pdo, $programId);
                admin_flash('success', 'Entry created successfully.');
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

                if ($entry['old_program_type'] === 'individual') {
                    throw new RuntimeException('Individual entries cannot be renamed.');
                }

                $entryName = trim((string)($_POST['entry_name'] ?? ''));
                if ($entryName === '') {
                    throw new RuntimeException('Entry name is required.');
                }

                $dup = $pdo->prepare('SELECT id FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ? AND team_id = ? AND entry_name = ? AND id <> ? LIMIT 1');
                $dup->execute([$activeEventId, $programId, (int)$entry['team_id'], $entryName, $entryId]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('Another entry already has this name.');
                }

                $stmt = $pdo->prepare('UPDATE musabaqa_program_entries SET entry_name = ? WHERE id = ? AND event_id = ?');
                $stmt->execute([$entryName, $entryId, $activeEventId]);
                admin_flash('success', 'Entry updated successfully.');
            }
        } elseif ($action === 'add_member') {
            $stmt = $pdo->prepare("
                SELECT pe.*, p.program_type, p.class_type_id, p.approval_status
                FROM musabaqa_program_entries pe
                JOIN musabaqa_programs p ON p.id = pe.program_id
                WHERE pe.id = ? AND pe.event_id = ?
                LIMIT 1
            ");
            $stmt->execute([$entryId, $activeEventId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$entry) {
                throw new RuntimeException('Entry not found.');
            }
            if ($entry['program_type'] !== 'group') {
                throw new RuntimeException('Cannot add members to individual entries.');
            }
            if (in_array((string)$entry['approval_status'], ['submitted', 'approved'], true)) {
                throw new RuntimeException('Scores are submitted/approved; entry is locked.');
            }

            $teamMemberId = (int)($_POST['team_member_id'] ?? 0);
            if ($teamMemberId <= 0) {
                throw new RuntimeException('Select a member to add.');
            }

            $stmt = $pdo->prepare("
                SELECT tm.*, c.class_type_id
                FROM musabaqa_team_members tm
                JOIN kauzariyya.students s ON s.id = tm.student_id
                LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
                WHERE tm.id = ? AND tm.team_id = ? AND tm.event_id = ? AND tm.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$teamMemberId, (int)$entry['team_id'], $activeEventId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                throw new RuntimeException('Selected member is invalid for this team.');
            }
            
            admin_validate_member_program_limits($pdo, $activeEventId, (int)$entry['program_id'], $teamMemberId);

            $dup = $pdo->prepare('SELECT id FROM musabaqa_entry_members WHERE entry_id = ? AND team_member_id = ? LIMIT 1');
            $dup->execute([$entryId, $teamMemberId]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Member is already attached to this entry.');
            }

            $stmt = $pdo->prepare('INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, ?)');
            $stmt->execute([$entryId, $teamMemberId, trim((string)($_POST['role_name'] ?? 'Member')) ?: 'Member']);
            admin_flash('success', 'Member added to entry.');
        } elseif ($action === 'remove_member') {
            $entryMemberId = (int)($_POST['entry_member_id'] ?? 0);
            $stmt = $pdo->prepare("
                DELETE em
                FROM musabaqa_entry_members em
                JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
                WHERE em.id = ? AND pe.event_id = ?
            ");
            $stmt->execute([$entryMemberId, $activeEventId]);
            admin_flash('success', 'Member removed from entry.');
        } elseif ($action === 'delete_entry') {
            // Get program_id for this entry
            $stmt = $pdo->prepare('SELECT program_id FROM musabaqa_program_entries WHERE id = ? AND event_id = ? LIMIT 1');
            $stmt->execute([$entryId, $activeEventId]);
            $entryProgramId = (int)$stmt->fetchColumn();

            // Delete member scores & scores
            $pdo->prepare('DELETE FROM musabaqa_member_scores WHERE entry_id = ?')->execute([$entryId]);
            $pdo->prepare('DELETE FROM musabaqa_scores WHERE entry_id = ? AND event_id = ?')->execute([$entryId, $activeEventId]);

            // Delete category scores & score sheets
            $sheetStmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE entry_id = ?');
            $sheetStmt->execute([$entryId]);
            $sheetIds = $sheetStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($sheetIds) {
                $sheetPlaceholders = implode(',', array_fill(0, count($sheetIds), '?'));
                $pdo->prepare("DELETE FROM musabaqa_category_scores WHERE score_sheet_id IN ($sheetPlaceholders)")->execute($sheetIds);
            }
            $pdo->prepare('DELETE FROM musabaqa_score_sheets WHERE entry_id = ?')->execute([$entryId]);

            // Delete entry members & program entry
            $pdo->prepare('DELETE FROM musabaqa_entry_members WHERE entry_id = ?')->execute([$entryId]);
            $pdo->prepare('DELETE FROM musabaqa_program_entries WHERE id = ? AND event_id = ?')->execute([$entryId, $activeEventId]);

            // Recalculate participant, program, and team totals to undo any marks
            if ($entryProgramId > 0) {
                admin_recalculate_participant_totals($pdo, $activeEventId, $entryProgramId);
                admin_recalculate_program_results($pdo, $activeEventId, $entryProgramId);
            }
            admin_recalculate_team_totals($pdo, $activeEventId);

            admin_flash('success', 'Entry deleted successfully. Any associated scores and team marks have been undone.');
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

    entries_redirect($redirectQuery);
}

// AJAX Handling
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)$_GET['action'];

    try {
        if ($action === 'team_members') {
            $programId = (int)($_GET['program_id'] ?? 0);
            $teamId = (int)($_GET['team_id'] ?? 0);
            $entryId = (int)($_GET['entry_id'] ?? 0);
            $program = entries_load_program($pdo, $activeEventId, $programId);

            if (!$program || $teamId <= 0) {
                echo json_encode(['success' => false, 'members' => []], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sql = "
                SELECT tm.id, tm.chest_number, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name, c.name AS class_name, ct.name AS class_type
                FROM musabaqa_team_members tm
                JOIN kauzariyya.students s ON s.id = tm.student_id
                LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
                LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
                WHERE tm.event_id = ?
                  AND tm.team_id = ?
                  AND tm.status = 'active'
            ";
            $params = [$activeEventId, $teamId];

            if (!empty($program['class_type_id'])) {
                $sql .= ' AND c.class_type_id = ?';
                $params[] = (int)$program['class_type_id'];
            }

            if ($program['program_type'] === 'individual') {
                $sql .= "
                    AND NOT EXISTS (
                        SELECT 1
                        FROM musabaqa_entry_members em
                        JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
                        WHERE pe.event_id = ?
                          AND pe.program_id = ?
                          AND em.team_member_id = tm.id
                    )
                ";
                $params[] = $activeEventId;
                $params[] = $programId;
            } elseif ($entryId > 0) {
                $sql .= "
                    AND NOT EXISTS (
                        SELECT 1
                        FROM musabaqa_entry_members em
                        WHERE em.entry_id = ? AND em.team_member_id = tm.id
                    )
                ";
                $params[] = $entryId;
            }

            $sql .= " ORDER BY NULLIF(tm.chest_number, '') IS NULL ASC, CAST(tm.chest_number AS UNSIGNED) ASC, full_name ASC, tm.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'entry') {
            $entryId = (int)($_GET['entry_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT pe.*, mp.title AS program_title, mp.program_type, t.team_name
                FROM musabaqa_program_entries pe
                JOIN musabaqa_programs mp ON mp.id = pe.program_id
                JOIN musabaqa_teams t ON t.id = pe.team_id
                WHERE pe.id = ? AND pe.event_id = ?
                LIMIT 1
            ");
            $stmt->execute([$entryId, $activeEventId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$entry) {
                echo json_encode(['success' => false], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT em.id AS entry_member_id, tm.id AS team_member_id, tm.chest_number, em.role_name,
                       COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name, c.name AS class_name
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                JOIN kauzariyya.students s ON s.id = tm.student_id
                LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
                WHERE em.entry_id = ?
                ORDER BY NULLIF(tm.chest_number, '') IS NULL ASC, CAST(tm.chest_number AS UNSIGNED) ASC, full_name ASC, tm.id ASC
            ");
            $stmt->execute([$entryId]);

            echo json_encode(['success' => true, 'entry' => $entry, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load data.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$flash = admin_take_flash();
$selectedProgramId = (int)($_GET['program_id'] ?? 0);
$classFilter = trim((string)($_GET['class'] ?? 'all'));

// Load all teams in event
$stmt = $pdo->prepare('SELECT id, team_name, short_name, team_color FROM musabaqa_teams WHERE event_id = ? ORDER BY team_name ASC');
$stmt->execute([$activeEventId]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle specific program workspace
$program = null;
$entries = [];
$programs = [];

if ($selectedProgramId > 0) {
    $program = entries_load_program($pdo, $activeEventId, $selectedProgramId);
}

if ($program) {
    // Load entries specifically for this program
    $stmt = $pdo->prepare("
        SELECT pe.*, mp.title AS program_title, mp.program_type, mp.class_type_id,
               ct.name AS class_type_name, t.team_name, t.team_color,
               COALESCE(member_counts.member_count, 0) AS member_count,
               ss.final_total, ss.status AS score_sheet_status
        FROM musabaqa_program_entries pe
        JOIN musabaqa_programs mp ON mp.id = pe.program_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN (
            SELECT entry_id, COUNT(*) AS member_count
            FROM musabaqa_entry_members
            GROUP BY entry_id
        ) member_counts ON member_counts.entry_id = pe.id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE pe.event_id = ? AND pe.program_id = ?
        ORDER BY pe.entry_number ASC, pe.id ASC
    ");
    $stmt->execute([$activeEventId, $selectedProgramId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // List programs and count their entries
    $programWhere = 'WHERE mp.event_id = ?';
    $programParams = [$activeEventId];
    [$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'mp');
    $programWhere .= $classSql;
    array_push($programParams, ...$classParams);

    $stmt = $pdo->prepare("
        SELECT mp.*, ct.name AS class_type_name,
               (SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = mp.id) AS entry_count
        FROM musabaqa_programs mp
        LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
        {$programWhere}
        ORDER BY mp.title ASC
    ");
    $stmt->execute($programParams);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$useTopNavigation = true;
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/event-role-sidebar.php';
?>
<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">

<main class="main-content event-workspace-content">
    <?php if ($program): ?>
        <!-- PROGRAM ENTRY LIST HUB -->
        <section class="workspace-hero">
            <div>
                <span class="eyebrow"><i class="fa-solid fa-list-check"></i> Program Assignment</span>
                <h1>Entries for <?= e($program['title']) ?></h1>
                <p>Manage participants, teams, and member roles for this program.</p>
            </div>
            <div class="hero-actions">
                <a href="<?= app_url('/admin/event/program-entries.php') ?>" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-arrow-left"></i> Back to Programs
                </a>
                <?php if (!in_array((string)$program['approval_status'], ['submitted', 'approved'], true)): ?>
                    <button class="btn btn-success btn-md" data-create-entry>
                        <i class="fa-solid fa-plus"></i> Create Entry
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$entries): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-list-check"></i></div>
                <div class="empty-title">No Entries Yet</div>
                <div class="empty-subtitle">Create entries to assign participants to this program.</div>
            </div>
        <?php else: ?>
            <div class="panel">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Entry Name</th>
                                <th>Team</th>
                                <th>Type</th>
                                <th>Members</th>
                                <th>Score</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td><strong>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                                    <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                                    <td>
                                        <span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;">
                                            <?= e($entry['team_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral">
                                            <?= e(ucfirst($entry['program_type'])) ?>
                                        </span>
                                    </td>
                                    <td><?= (int)$entry['member_count'] ?></td>
                                    <td><?= $entry['final_total'] !== null ? e(number_format((float)$entry['final_total'], 2)) : '<span class="badge badge-neutral">Not scored</span>' ?></td>
                                    <td><span class="badge <?= entries_status_badge($entry['status']) ?>"><?= e(ucfirst((string)$entry['status'])) ?></span></td>
                                    <td>
                                        <div class="flex gap-2">
                                            <button class="btn btn-secondary btn-sm" type="button" data-view-id="<?= (int)$entry['id'] ?>" title="View Members">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <?php if (!in_array((string)$program['approval_status'], ['submitted', 'approved'], true)): ?>
                                                <?php if ($entry['program_type'] === 'group'): ?>
                                                    <button class="btn btn-secondary btn-sm" type="button" data-edit='<?= e(json_encode($entry, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Rename Group">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </button>
                                                    <button class="btn btn-info btn-sm" type="button" data-manage-id="<?= (int)$entry['id'] ?>" title="Manage Members">
                                                        <i class="fa-solid fa-users"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-danger btn-sm" type="button" data-delete-id="<?= (int)$entry['id'] ?>" data-delete-name="<?= e($entry['entry_name']) ?>" title="Delete Entry">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ALL PROGRAMS SELECTOR VIEW -->
        <section class="workspace-hero">
            <div>
                <span class="eyebrow"><i class="fa-solid fa-list-check"></i> Assign Entries</span>
                <h1>Program Entries Hub</h1>
                <p>Select a program to manage, create, and configure event participants.</p>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="panel">
            <form method="GET" class="form-grid mb-6">
                <div class="input-group">
                    <label>Filter by Class Type</label>
                    <select name="class" onchange="this.form.submit()">
                        <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Program Title</th>
                            <th>Class Type</th>
                            <th>Program Type</th>
                            <th>Status</th>
                            <th>Entries</th>
                            <th style="width: 150px; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$programs): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: var(--muted-2);">
                                    No programs found for the active event.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($programs as $prog): ?>
                                <?php $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? ''); ?>
                                <tr>
                                    <td><strong><?= e($prog['title']) ?></strong></td>
                                    <td>
                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                            <?= e(admin_class_type_display($prog['class_type_name'] ?? null, (int)($prog['class_type_id'] ?? 0))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral"><?= e(ucfirst($prog['program_type'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $prog['status'] === 'completed' ? 'badge-success' : ($prog['status'] === 'scoring' ? 'badge-warning' : 'badge-info') ?>">
                                            <?= e(ucfirst($prog['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= (int)$prog['entry_count'] ?></strong> entries
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="?program_id=<?= (int)$prog['id'] ?>" class="btn btn-primary btn-sm">
                                            <i class="fa-solid fa-list-check"></i> Manage Entries
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- MODALS -->
<?php if ($program): ?>
<div class="modal-overlay" id="entryModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="entryModalTitle">Create Entry</div>
            <button type="button" class="modal-close" onclick="window.closeModal('entryModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="entryForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="entryAction" value="create_entry">
            <input type="hidden" name="entry_id" id="entryId">
            <input type="hidden" name="program_id" id="entryProgramId" value="<?= (int)$program['id'] ?>">

            <div class="form-grid">
                <div class="input-group">
                    <label>Program</label>
                    <input type="text" class="form-control" value="<?= e($program['title']) ?> (<?= e(ucfirst($program['program_type'])) ?>)" disabled>
                </div>
                <div class="input-group" id="teamWrap">
                    <label>Team <span class="required">*</span></label>
                    <select name="team_id" id="entryTeamId">
                        <option value="">Select Team</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>"><?= e($team['team_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group full-width" id="participantWrap">
                    <label>Participant <span class="required">*</span></label>
                    <select name="team_member_id" id="teamMemberId"><option value="">Select Team First</option></select>
                </div>
                <div class="input-group full-width" id="entryNameWrap">
                    <label>Entry Name <span class="required">*</span></label>
                    <input type="text" name="entry_name" class="form-control" id="entryName" placeholder="Group name">
                </div>
            </div>
            <div class="field-help mt-4">Entry credentials will automatically sync with database logs.</div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" onclick="window.closeModal('entryModal')">Cancel</button>
                <button type="submit" class="btn btn-success btn-md">Save Entry</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="membersModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="membersTitle">Entry Members</div>
                <div class="page-subtitle" id="membersSubtitle"></div>
            </div>
            <button type="button" class="modal-close" onclick="window.closeModal('membersModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="membersBody" style="display: flex; flex-direction: column; gap: 16px;"></div>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div class="modal-title">Delete Entry</div>
            <button type="button" class="modal-close" onclick="window.closeModal('deleteModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="panel">Delete <strong id="deleteName"></strong>? This entry is non-reversible.</div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" id="deleteId">
            <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" onclick="window.closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger btn-md">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const PROGRAM = <?= json_encode($program) ?>;
    const TEAMS = <?= json_encode($teams) ?>;
    const CSRF = <?= json_encode(generate_csrf_token()) ?>;
    const ADMIN_ENTRIES_URL = <?= json_encode(app_url('/admin/event/program-entries.php'), JSON_UNESCAPED_SLASHES) ?>;

    window.openModal = function(id) { document.getElementById(id)?.classList.add('active'); };
    window.closeModal = function(id) { document.getElementById(id)?.classList.remove('active'); };

    function escapeHtml(value){return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
    
    function groupedMemberOptionsHtml(members, placeholder) {
        if (!members.length) return '<option value="">No available members</option>';
        let html = `<option value="">${escapeHtml(placeholder)}</option>`;
        members.forEach(member => {
            html += `<option value="${escapeHtml(member.id)}">#${escapeHtml(member.chest_number || '-')} · ${escapeHtml(member.full_name)} · ${escapeHtml(member.class_name || 'Unassigned')}</option>`;
        });
        return html;
    }

    function selectedTeam(){return TEAMS.find(team => Number(team.id) === Number(document.getElementById('entryTeamId').value));}

    function updateGroupEntryName(isEdit = false) {
        if (isEdit || PROGRAM.program_type !== 'group') return;
        const team = selectedTeam();
        const entryName = document.getElementById('entryName');
        if (!team) {
            entryName.value = '';
            return;
        }
        entryName.value = `${PROGRAM.title} - ${team.team_name}`;
    }

    function syncEntryFields(isEdit = false) {
        const isIndividual = PROGRAM.program_type === 'individual';
        document.getElementById('participantWrap').style.display = isIndividual && !isEdit ? '' : 'none';
        document.getElementById('entryNameWrap').style.display = isIndividual ? 'none' : '';
        updateGroupEntryName(isEdit);
    }

    function openCreateModal() {
        document.getElementById('entryForm').reset();
        document.getElementById('entryModalTitle').textContent = 'Create Entry';
        document.getElementById('entryAction').value = 'create_entry';
        document.getElementById('entryId').value = '';
        document.getElementById('entryTeamId').disabled = false;
        document.getElementById('teamMemberId').innerHTML = '<option value="">Select Team First</option>';
        syncEntryFields(false);
        window.openModal('entryModal');
    }

    function openEditModal(entry) {
        document.getElementById('entryForm').reset();
        document.getElementById('entryModalTitle').textContent = 'Rename Group Entry';
        document.getElementById('entryAction').value = 'update_entry';
        document.getElementById('entryId').value = entry.id || '';
        document.getElementById('entryTeamId').value = entry.team_id || '';
        document.getElementById('entryTeamId').disabled = true;
        document.getElementById('entryName').value = entry.entry_name || '';
        syncEntryFields(true);
        window.openModal('entryModal');
    }

    async function loadMembers() {
        const teamId = document.getElementById('entryTeamId').value;
        const select = document.getElementById('teamMemberId');
        if (!teamId) {
            select.innerHTML = '<option value="">Select Team First</option>';
            return;
        }
        select.innerHTML = '<option value="">Loading...</option>';
        const response = await fetch(`${ADMIN_ENTRIES_URL}?action=team_members&program_id=${PROGRAM.id}&team_id=${encodeURIComponent(teamId)}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.members) || data.members.length === 0) {
            select.innerHTML = '<option value="">No available members matching program criteria</option>';
            return;
        }
        select.innerHTML = groupedMemberOptionsHtml(data.members, 'Select Participant');
    }

    document.getElementById('entryTeamId').addEventListener('change', () => {
        updateGroupEntryName(false);
        loadMembers();
    });

    async function openMembers(entryId, manage = false) {
        const response = await fetch(`${ADMIN_ENTRIES_URL}?action=entry&entry_id=${entryId}`);
        const data = await response.json();
        if (!data.success) return;

        document.getElementById('membersTitle').textContent = data.entry.entry_name || 'Entry Members';
        document.getElementById('membersSubtitle').textContent = `${data.entry.program_title} - ${data.entry.team_name}`;

        let current = '<div class="panel"><div class="page-subtitle">Current Members</div>';
        if (data.members.length) {
            current += '<div class="table-wrapper mt-4"><table class="table"><tbody>';
            data.members.forEach(member => {
                current += `<tr><td>${escapeHtml(member.full_name)}</td><td>#${escapeHtml(member.chest_number || '-')}</td><td>${escapeHtml(member.class_name || '-')}</td><td>${escapeHtml(member.role_name || 'Member')}</td>`;
                if (manage) {
                    current += `<td><form method="POST"><input type="hidden" name="csrf_token" value="${CSRF}"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="program_id" value="${PROGRAM.id}"><input type="hidden" name="entry_member_id" value="${escapeHtml(member.entry_member_id)}"><button class="btn btn-danger btn-sm" type="submit">Remove</button></form></td>`;
                }
                current += '</tr>';
            });
            current += '</tbody></table></div>';
        } else {
            current += '<div class="empty-subtitle mt-4" style="color: var(--muted); margin-top: 10px;">No members assigned.</div>';
        }
        current += '</div>';

        if (!manage) {
            document.getElementById('membersBody').innerHTML = current;
            window.openModal('membersModal');
            return;
        }

        const availableResponse = await fetch(`${ADMIN_ENTRIES_URL}?action=team_members&program_id=${data.entry.program_id}&team_id=${data.entry.team_id}&entry_id=${entryId}`);
        const available = await availableResponse.json();
        const members = available.success ? available.members : [];
        
        let add = `<div class="panel"><div class="page-subtitle">Add Member</div><form method="POST" class="form-grid mt-4"><input type="hidden" name="csrf_token" value="${CSRF}"><input type="hidden" name="action" value="add_member"><input type="hidden" name="program_id" value="${PROGRAM.id}"><input type="hidden" name="entry_id" value="${entryId}"><div class="input-group"><label>Member</label><select name="team_member_id" required>`;
        add += groupedMemberOptionsHtml(members, 'Select Member');
        add += '</select></div><div class="input-group"><label>Role</label><input class="form-control" name="role_name" value="Member"></div><div class="form-actions full-width"><button class="btn btn-success btn-md" type="submit">Add Member</button></div></form></div>';
        
        document.getElementById('membersBody').innerHTML = current + add;
        window.openModal('membersModal');
    }

    document.addEventListener('click', (e) => {
        const createBtn = e.target.closest('[data-create-entry]');
        if (createBtn) {
            openCreateModal();
            return;
        }

        const editBtn = e.target.closest('[data-edit]');
        if (editBtn) {
            openEditModal(JSON.parse(editBtn.dataset.edit));
            return;
        }

        const viewBtn = e.target.closest('[data-view-id]');
        if (viewBtn) {
            openMembers(viewBtn.dataset.viewId, false);
            return;
        }

        const manageBtn = e.target.closest('[data-manage-id]');
        if (manageBtn) {
            openMembers(manageBtn.dataset.manageId, true);
            return;
        }

        const deleteBtn = e.target.closest('[data-delete-id]');
        if (deleteBtn) {
            document.getElementById('deleteId').value = deleteBtn.dataset.deleteId;
            document.getElementById('deleteName').textContent = deleteBtn.dataset.deleteName || 'this entry';
            window.openModal('deleteModal');
            return;
        }
    });

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', event => {
            if (event.target === modal) window.closeModal(modal.id);
        });
    });
})();
</script>
<?php endif; ?>

<?php admin_close_page(); ?>
