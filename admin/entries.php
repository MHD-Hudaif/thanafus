<?php
$pageTitle = 'Entries';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function entries_redirect(array $query = []): void
{
    admin_redirect('/admin/entries.php', $query);
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

$classFilter = trim((string)($_GET['class'] ?? 'all'));

$programWhere = 'WHERE mp.event_id = ?';
$programParams = [$activeEventId];
[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'mp');
$programWhere .= $classSql;
array_push($programParams, ...$classParams);

$stmt = $pdo->prepare("
    SELECT mp.*, ct.name AS class_type_name
    FROM musabaqa_programs mp
    LEFT JOIN kauzariyya.class_types ct ON ct.id = mp.class_type_id
    {$programWhere}
    ORDER BY mp.title ASC
");
$stmt->execute($programParams);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$programs) {
    admin_flash('error', 'Create a program before managing entries.');
    admin_redirect('/admin/programs.php');
}

$programMap = [];
foreach ($programs as $program) {
    $programMap[(int)$program['id']] = $program;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        entries_redirect();
    }

    $action = (string)($_POST['action'] ?? '');
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);
    $teamId = (int)($_POST['team_id'] ?? 0);
    $return = [
        'program' => (int)($_POST['return_program'] ?? 0) ?: null,
        'search' => trim((string)($_POST['return_search'] ?? '')) ?: null,
        'status' => trim((string)($_POST['return_status'] ?? 'all')),
        'team' => (int)($_POST['return_team'] ?? 0) ?: null,
    ];

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
                    if (!empty($program['class_type_id']) && (int)$member['class_type_id'] !== (int)$program['class_type_id']) {
                        throw new RuntimeException('Participant does not match this program class type.');
                    }

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
                if ($entry['status'] === 'completed') {
                    throw new RuntimeException('Completed entries cannot be reassigned.');
                }
                if ($entry['old_program_type'] !== $program['program_type']) {
                    throw new RuntimeException('Program reassignment must stay within the same program type.');
                }

                $stmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
                $stmt->execute([$entryId]);
                $hasScoreSheet = (bool)$stmt->fetchColumn();

                $oldProgramId = (int)$entry['program_id'];
                if ($hasScoreSheet && $oldProgramId !== $programId) {
                    throw new RuntimeException('Scored entries cannot be moved to another program.');
                }

                $newEntryNumber = $oldProgramId === $programId
                    ? (int)$entry['entry_number']
                    : entries_next_number($pdo, $activeEventId, $programId);

                $entryName = (string)$entry['entry_name'];
                if ($program['program_type'] === 'group') {
                    $entryName = trim((string)($_POST['entry_name'] ?? ''));
                    if ($entryName === '') {
                        throw new RuntimeException('Entry name is required.');
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE musabaqa_program_entries
                    SET program_id = ?, entry_name = ?, entry_number = ?
                    WHERE id = ? AND event_id = ?
                ");
                $stmt->execute([$programId, $entryName, $newEntryNumber, $entryId, $activeEventId]);

                admin_recalculate_entry_status($pdo, $entryId);
                admin_recalculate_program_status($pdo, $oldProgramId);
                admin_recalculate_program_status($pdo, $programId);
                admin_flash('success', 'Entry updated successfully.');
            }
        } elseif ($action === 'add_member') {
            $teamMemberId = (int)($_POST['team_member_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT pe.*, mp.program_type, mp.class_type_id
                FROM musabaqa_program_entries pe
                JOIN musabaqa_programs mp ON mp.id = pe.program_id
                WHERE pe.id = ? AND pe.event_id = ?
                LIMIT 1
            ");
            $stmt->execute([$entryId, $activeEventId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry || $entry['program_type'] !== 'group') {
                throw new RuntimeException('Only group entries can manage multiple members.');
            }

            $stmt = $pdo->prepare("
                SELECT tm.id, c.class_type_id
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
            if (!empty($entry['class_type_id']) && (int)$member['class_type_id'] !== (int)$entry['class_type_id']) {
                throw new RuntimeException('Member does not match this program class type.');
            }

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
            $stmt = $pdo->prepare('SELECT id FROM musabaqa_score_sheets WHERE entry_id = ? LIMIT 1');
            $stmt->execute([$entryId]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Scored entries cannot be deleted.');
            }

            $pdo->prepare('DELETE FROM musabaqa_entry_members WHERE entry_id = ?')->execute([$entryId]);
            $pdo->prepare('DELETE FROM musabaqa_program_entries WHERE id = ? AND event_id = ?')->execute([$entryId, $activeEventId]);
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

    entries_redirect($return);
}

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

            $sql .= ' ORDER BY tm.chest_number IS NULL ASC, CAST(tm.chest_number AS UNSIGNED), full_name ASC';
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
                ORDER BY tm.chest_number IS NULL ASC, CAST(tm.chest_number AS UNSIGNED), full_name ASC
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
$selectedProgramId = (int)($_GET['program'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$teamFilter = (int)($_GET['team'] ?? 0);

$stmt = $pdo->prepare('SELECT id, team_name, short_name, team_color FROM musabaqa_teams WHERE event_id = ? ORDER BY team_name ASC');
$stmt->execute([$activeEventId]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$where = 'WHERE pe.event_id = ?';
$params = [$activeEventId];
if ($selectedProgramId > 0) {
    $where .= ' AND pe.program_id = ?';
    $params[] = $selectedProgramId;
}
if ($search !== '') {
    $where .= ' AND (pe.entry_name LIKE ? OR CAST(pe.entry_number AS CHAR) LIKE ? OR t.team_name LIKE ? OR mp.title LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($statusFilter !== 'all' && in_array($statusFilter, ['approved', 'scoring', 'completed'], true)) {
    $where .= ' AND pe.status = ?';
    $params[] = $statusFilter;
}
if ($teamFilter > 0) {
    $where .= ' AND pe.team_id = ?';
    $params[] = $teamFilter;
}
[$entryClassSql, $entryClassParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'mp');
$where .= $entryClassSql;
array_push($params, ...$entryClassParams);

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
    {$where}
    ORDER BY mp.title ASC, pe.entry_number ASC, pe.id ASC
");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalEntries = count($entries);

if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['entries_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['entries_limit']) ? $_SESSION['entries_limit'] : 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedEntries = array_slice($entries, $offset, $perPage);

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedEntries) {
        echo '<tr><td colspan="9" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Entries Found</div></td></tr>';
    } else {
        foreach ($paginatedEntries as $entry) {
            $classTier = admin_class_type_tier_from_name($entry['class_type_name'] ?? '');
            ?>
            <tr>
                <td><strong>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                <td><?= e($entry['program_title']) ?></td>
                <td>
                    <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                        <?= e(admin_class_type_display($entry['class_type_name'] ?? null, (int)($entry['class_type_id'] ?? 0))) ?>
                    </span>
                </td>
                <td><span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;"><?= e($entry['team_name']) ?></span></td>
                <td><?= (int)$entry['member_count'] ?></td>
                <td><?= $entry['final_total'] !== null ? e(number_format((float)$entry['final_total'], 2)) : '<span class="badge badge-neutral">Not scored</span>' ?></td>
                <td><span class="badge <?= entries_status_badge($entry['status']) ?>"><?= e(ucfirst((string)$entry['status'])) ?></span></td>
                <td>
                    <div class="flex gap-2 flex-wrap">
                        <button class="btn btn-secondary btn-sm" type="button" data-view-id="<?= (int)$entry['id'] ?>"><i class="fa-solid fa-eye"></i></button>
                        <button class="btn btn-secondary btn-sm" type="button" data-edit='<?= e(json_encode($entry, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i></button>
                        <?php if ($entry['program_type'] === 'group'): ?>
                            <button class="btn btn-info btn-sm" type="button" data-manage-id="<?= (int)$entry['id'] ?>"><i class="fa-solid fa-users"></i></button>
                        <?php endif; ?>
                        <button class="btn btn-danger btn-sm" type="button" data-delete-id="<?= (int)$entry['id'] ?>" data-delete-name="<?= e($entry['entry_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php
        }
    }
    $tbodyHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'html' => $tbodyHtml,
        'pagination' => admin_render_pagination_html($page, $perPage, $totalEntries)
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Entries</div>
            <div class="page-subtitle">Manage approved entries and program assignments</div>
        </div>
        <button type="button" class="btn btn-success btn-md" data-create-entry><i class="fa-solid fa-plus"></i> Create Entry</button>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" id="search-form">
            <div class="input-group">
                <label>Class</label>
                <select name="class">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Program</label>
                <select name="program">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= (int)$program['id'] ?>" <?= $selectedProgramId === (int)$program['id'] ? 'selected' : '' ?>>
                            <?= e($program['title']) ?> · <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Team</label>
                <select name="team">
                    <option value="0">All Teams</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int)$team['id'] ?>" <?= $teamFilter === (int)$team['id'] ? 'selected' : '' ?>><?= e($team['team_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Status</label>
                <select name="status">
                    <option value="all">All Status</option>
                    <?php foreach (['approved', 'scoring', 'completed'] as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Entry, number, team or program">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($search !== '' || $selectedProgramId || $teamFilter || $statusFilter !== 'all' || $classFilter !== 'all'): ?>
                    <a href="<?= app_url('/admin/entries.php') ?>" class="btn btn-secondary btn-md">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$entries): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-list-check"></i></div><div class="empty-title">No Entries Found</div><div class="empty-subtitle">Create entries for a program to begin scoring.</div></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead><tr><th>No.</th><th>Entry</th><th>Program</th><th>Class</th><th>Team</th><th>Members</th><th>Score</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="table-body">
                    <?php foreach ($paginatedEntries as $entry): ?>
                        <tr>
                            <td><strong>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                            <td><?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                            <td><?= e($entry['program_title']) ?></td>
                            <td>
                                <?php $classTier = admin_class_type_tier_from_name($entry['class_type_name'] ?? ''); ?>
                                <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                    <?= e(admin_class_type_display($entry['class_type_name'] ?? null, (int)($entry['class_type_id'] ?? 0))) ?>
                                </span>
                            </td>
                            <td><span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;"><?= e($entry['team_name']) ?></span></td>
                            <td><?= (int)$entry['member_count'] ?></td>
                            <td><?= $entry['final_total'] !== null ? e(number_format((float)$entry['final_total'], 2)) : '<span class="badge badge-neutral">Not scored</span>' ?></td>
                            <td><span class="badge <?= entries_status_badge($entry['status']) ?>"><?= e(ucfirst((string)$entry['status'])) ?></span></td>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <button class="btn btn-secondary btn-sm" type="button" data-view-id="<?= (int)$entry['id'] ?>"><i class="fa-solid fa-eye"></i></button>
                                    <button class="btn btn-secondary btn-sm" type="button" data-edit='<?= e(json_encode($entry, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i></button>
                                    <?php if ($entry['program_type'] === 'group'): ?>
                                        <button class="btn btn-info btn-sm" type="button" data-manage-id="<?= (int)$entry['id'] ?>"><i class="fa-solid fa-users"></i></button>
                                    <?php endif; ?>
                                    <button class="btn btn-danger btn-sm" type="button" data-delete-id="<?= (int)$entry['id'] ?>" data-delete-name="<?= e($entry['entry_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="pagination-container">
            <?= admin_render_pagination_html($page, $perPage, $totalEntries) ?>
        </div>
    <?php endif; ?>


<div class="modal-overlay" id="entryModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="entryModalTitle">Create Entry</div>
            <button type="button" class="modal-close" onclick="closeModal('entryModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="entryForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="entryAction" value="create_entry">
            <input type="hidden" name="entry_id" id="entryId">
            <input type="hidden" name="return_program" value="<?= (int)$selectedProgramId ?>">
            <input type="hidden" name="return_search" value="<?= e($search) ?>">
            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
            <input type="hidden" name="return_team" value="<?= (int)$teamFilter ?>">

            <div class="form-grid">
                <div class="input-group">
                    <label>Program <span class="required">*</span></label>
                    <select name="program_id" id="entryProgramId" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= (int)$program['id'] ?>">
                                <?= e($program['title']) ?> · <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                    <select name="team_member_id" id="teamMemberId"><option value="">Select Program and Team First</option></select>
                </div>
                <div class="input-group full-width" id="entryNameWrap">
                    <label>Entry Name <span class="required">*</span></label>
                    <input type="text" name="entry_name" id="entryName" placeholder="Group name">
                </div>
            </div>
            <div class="field-help mt-4">Entry number and status are generated by the system.</div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" onclick="closeModal('entryModal')">Cancel</button>
                <button type="submit" class="btn btn-success btn-md">Save Entry</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="membersModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div><div class="modal-title" id="membersTitle">Entry Members</div><div class="page-subtitle" id="membersSubtitle"></div></div>
            <button type="button" class="modal-close" onclick="closeModal('membersModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="membersBody" class="grid gap-4"></div>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-md">
        <div class="modal-header"><div class="modal-title">Delete Entry</div><button type="button" class="modal-close" onclick="closeModal('deleteModal')"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="panel">Delete <strong id="deleteName"></strong>? Scored entries are protected.</div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" id="deleteId">
            <div class="form-actions"><button type="button" class="btn btn-secondary btn-md" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger btn-md">Delete</button></div>
        </form>
    </div>
</div>

<script>
(() => {

const ADMIN_ENTRIES_URL = <?= json_encode(app_url('/admin/entries.php'), JSON_UNESCAPED_SLASHES) ?>;
const PROGRAMS = <?= json_encode(array_values($programs), JSON_UNESCAPED_UNICODE) ?>;
const TEAMS = <?= json_encode(array_values($teams), JSON_UNESCAPED_UNICODE) ?>;
const CSRF = <?= json_encode(generate_csrf_token()) ?>;
const SELECTED_PROGRAM_ID = <?= (int)$selectedProgramId ?>;
const RETURN_FIELDS = `
    <input type="hidden" name="return_program" value="<?= (int)$selectedProgramId ?>">
    <input type="hidden" name="return_search" value="<?= e($search) ?>">
    <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
    <input type="hidden" name="return_team" value="<?= (int)$teamFilter ?>">
`;

function escapeHtml(value){return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
function selectedProgram(){return PROGRAMS.find(program => Number(program.id) === Number(document.getElementById('entryProgramId').value));}
function selectedTeam(){return TEAMS.find(team => Number(team.id) === Number(document.getElementById('entryTeamId').value));}

function updateGroupEntryName(isEdit = false) {
    if (isEdit) {
        return;
    }

    const program = selectedProgram();
    const team = selectedTeam();
    const entryName = document.getElementById('entryName');

    if (!program || program.program_type !== 'group') {
        entryName.value = '';
        return;
    }

    if (!team) {
        entryName.value = '';
        return;
    }

    entryName.value = `${program.title} - ${team.team_name}`;
}

function syncEntryFields(isEdit = false, entryType = null) {
    const program = selectedProgram();
    const type = entryType || (program ? program.program_type : '');
    const isIndividual = type === 'individual';
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
    document.getElementById('teamMemberId').innerHTML = '<option value="">Select Program and Team First</option>';
    if (SELECTED_PROGRAM_ID > 0) {
        document.getElementById('entryProgramId').value = SELECTED_PROGRAM_ID;
        syncEntryFields(false);
        loadMembers();
    } else {
        syncEntryFields(false);
    }
   window.openModal('entryModal');
}

window.openCreateEntryModal = openCreateModal;

function openEditModal(entry) {
    document.getElementById('entryForm').reset();
    document.getElementById('entryModalTitle').textContent = 'Edit Entry';
    document.getElementById('entryAction').value = 'update_entry';
    document.getElementById('entryId').value = entry.id || '';
    document.getElementById('entryProgramId').value = entry.program_id || '';
    document.getElementById('entryTeamId').value = entry.team_id || '';
    document.getElementById('entryTeamId').disabled = true;
    document.getElementById('entryName').value = entry.entry_name || '';
    syncEntryFields(true, entry.program_type || '');
   window.openModal('entryModal');
}

async function loadMembers() {
    const programId = document.getElementById('entryProgramId').value;
    const teamId = document.getElementById('entryTeamId').value;
    const select = document.getElementById('teamMemberId');
    if (!programId || !teamId) {
        select.innerHTML = '<option value="">Select Program and Team First</option>';
        return;
    }
    select.innerHTML = '<option value="">Loading...</option>';
    const response = await fetch(`${ADMIN_ENTRIES_URL}?action=team_members&program_id=${encodeURIComponent(programId)}&team_id=${encodeURIComponent(teamId)}`);
    const data = await response.json();
    select.innerHTML = '<option value="">Select Participant</option>';
    if (!data.success || !Array.isArray(data.members) || data.members.length === 0) {
        select.innerHTML = '<option value="">No available members</option>';
        return;
    }
    data.members.forEach(member => {
        const option = document.createElement('option');
        option.value = member.id;
        option.textContent = `${member.full_name} (#${member.chest_number || '-'})`;
        select.appendChild(option);
    });
}

document.getElementById('entryProgramId').addEventListener('change', () => { syncEntryFields(false); loadMembers(); });
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
                current += `<td><form method="POST"><input type="hidden" name="csrf_token" value="${CSRF}"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="entry_member_id" value="${escapeHtml(member.entry_member_id)}">${RETURN_FIELDS}<button class="btn btn-danger btn-sm" type="submit">Remove</button></form></td>`;
            }
            current += '</tr>';
        });
        current += '</tbody></table></div>';
    } else {
        current += '<div class="empty-subtitle mt-4">No members attached.</div>';
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
    let add = `<div class="panel"><div class="page-subtitle">Add Member</div><form method="POST" class="form-grid mt-4"><input type="hidden" name="csrf_token" value="${CSRF}"><input type="hidden" name="action" value="add_member"><input type="hidden" name="entry_id" value="${entryId}">${RETURN_FIELDS}<div class="input-group"><label>Member</label><select name="team_member_id" required>`;
    add += members.length ? '<option value="">Select Member</option>' : '<option value="">No available members</option>';
    members.forEach(member => add += `<option value="${escapeHtml(member.id)}">${escapeHtml(member.full_name)} (#${escapeHtml(member.chest_number || '-')})</option>`);
    add += '</select></div><div class="input-group"><label>Role</label><input name="role_name" value="Member"></div><div class="form-actions full-width"><button class="btn btn-success btn-md" type="submit">Add Member</button></div></form></div>';
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
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal)window.closeModal(modal.id); }));
if (new URLSearchParams(window.location.search).get('create') === '1') openCreateModal();

})();
</script>
</div>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
