<?php
$pageTitle = 'Team Members';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$activeTeamId = (int)($_GET['team'] ?? $_SESSION['active_team_id'] ?? 0);

if ($activeTeamId <= 0) {
    admin_flash('error', 'Please select a team first.');
    admin_redirect('/admin/teams.php');
}

$stmt = $pdo->prepare('SELECT * FROM musabaqa_teams WHERE id = ? AND event_id = ? LIMIT 1');
$stmt->execute([$activeTeamId, $activeEventId]);
$activeTeam = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activeTeam) {
    unset($_SESSION['active_team_id']);
    admin_flash('error', 'Team not found for this event.');
    admin_redirect('/admin/teams.php');
}
$_SESSION['active_team_id'] = $activeTeamId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/members.php', ['team' => $activeTeamId]);
    }

    $action = (string)($_POST['action'] ?? '');
    $memberId = (int)($_POST['member_id'] ?? 0);

    try {
        if ($action === 'update') {
            $chestNumber = trim((string)($_POST['chest_number'] ?? ''));
            $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $chestNumber = $chestNumber === '' ? null : $chestNumber;
            if ($chestNumber !== null) {
                $dup = $pdo->prepare('SELECT id FROM musabaqa_team_members WHERE event_id = ? AND chest_number = ? AND id <> ? LIMIT 1');
                $dup->execute([$activeEventId, $chestNumber, $memberId]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('Chest number is already used in this event.');
                }
            }
            $stmt = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ?, status = ? WHERE id = ? AND team_id = ? AND event_id = ?');
            $stmt->execute([$chestNumber, $status, $memberId, $activeTeamId, $activeEventId]);
            admin_flash('success', 'Member updated successfully.');
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_entry_members WHERE team_member_id = ?');
            $stmt->execute([$memberId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare('UPDATE musabaqa_team_members SET status = "inactive" WHERE id = ? AND team_id = ? AND event_id = ?');
                $stmt->execute([$memberId, $activeTeamId, $activeEventId]);
                admin_flash('success', 'Member has entries, so they were marked inactive.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM musabaqa_team_members WHERE id = ? AND team_id = ? AND event_id = ?');
                $stmt->execute([$memberId, $activeTeamId, $activeEventId]);
                admin_flash('success', 'Member removed successfully.');
            }
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to update member.');
    }

    admin_redirect('/admin/members.php', ['team' => $activeTeamId]);
}

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'active'));

$where = 'WHERE mtm.team_id = ? AND mtm.event_id = ?';
$params = [$activeTeamId, $activeEventId];
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where .= ' AND mtm.status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare("
    SELECT mtm.*, COALESCE(score_data.total_score, 0) AS total_score, COALESCE(entry_data.entry_count, 0) AS entry_count
    FROM musabaqa_team_members mtm
    LEFT JOIN (
        SELECT member_id, SUM(score) AS total_score
        FROM musabaqa_member_scores
        GROUP BY member_id
    ) score_data ON score_data.member_id = mtm.id
    LEFT JOIN (
        SELECT team_member_id, COUNT(*) AS entry_count
        FROM musabaqa_entry_members
        GROUP BY team_member_id
    ) entry_data ON entry_data.team_member_id = mtm.id
    {$where}
    ORDER BY mtm.chest_number IS NULL ASC, CAST(mtm.chest_number AS UNSIGNED) ASC, mtm.id ASC
");
$stmt->execute($params);
$rawMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$students = [];
$studentIds = array_values(array_unique(array_map('intval', array_column($rawMembers, 'student_id'))));
if ($studentIds) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $dashboardPdo->prepare("
        SELECT s.id, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name, c.name AS class_name, ct.name AS class_type
        FROM students s
        LEFT JOIN classes c ON c.id = s.class_id
        LEFT JOIN class_types ct ON ct.id = c.class_type_id
        WHERE s.id IN ({$placeholders})
    ");
    $stmt->execute($studentIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
        $students[(int)$student['id']] = $student;
    }
}

$members = [];
$totalScore = 0.0;
foreach ($rawMembers as $member) {
    $student = $students[(int)$member['student_id']] ?? null;
    $fullName = $student['full_name'] ?? 'Unknown Student';
    if ($search !== '' && stripos($fullName, $search) === false && stripos((string)$member['chest_number'], $search) === false) {
        continue;
    }
    $member['full_name'] = $fullName;
    $member['class_name'] = $student['class_name'] ?? '-';
    $member['class_type'] = $student['class_type'] ?? '-';
    $member['total_score'] = (float)($member['total_score'] ?? 0);
    $totalScore += $member['total_score'];
    $members[] = $member;
}
$totalMembers = count($members);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div><div class="page-title">Members</div><div class="page-subtitle"><span class="team-color-pill" style="background: <?= e($activeTeam['team_color'] ?? '#64748b') ?>22; color: <?= e($activeTeam['team_color'] ? '#111' : '#111') ?>;"><?= e($activeTeam['team_name']) ?></span></div></div>
        <a href="<?= APP_URL ?>/admin/add-members.php?team=<?= $activeTeamId ?>" class="btn btn-success btn-md"><i class="fa-solid fa-plus"></i> Add Members</a>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <input type="hidden" name="team" value="<?= $activeTeamId ?>">
            <div class="input-group"><label>Search</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Name or chest number"></div>
            <div class="input-group"><label>Status</label><select name="status"><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option></select></div>
            <div class="form-actions full-width"><button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button><?php if ($search !== '' || $statusFilter !== 'active'): ?><a href="<?= APP_URL ?>/admin/members.php?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md">Clear</a><?php endif; ?></div>
        </form>
    </div>

    <div class="stats-grid mb-6">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div class="stat-value"><?= $totalMembers ?></div><div class="stat-label">Members</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-trophy"></i></div><div class="stat-value"><?= number_format($totalScore, 2) ?></div><div class="stat-label">Approved Score</div></div>
    </div>

    <?php if (!$members): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-users"></i></div><div class="empty-title">No Members Found</div><div class="empty-subtitle"><?= $search ? 'No results match your search.' : 'This team has no members yet.' ?></div></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead><tr><th>Chest #</th><th>Name</th><th>Class</th><th>Type</th><th>Score</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td><strong><?= $member['chest_number'] !== null && $member['chest_number'] !== '' ? '#' . e(str_pad((string)$member['chest_number'], 3, '0', STR_PAD_LEFT)) : '-' ?></strong></td>
                            <td><?= e($member['full_name']) ?></td>
                            <td><?= e($member['class_name']) ?></td>
                            <td><?= e($member['class_type']) ?></td>
                            <td><?= number_format((float)$member['total_score'], 2) ?></td>
                            <td><span class="badge <?= $member['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= e(ucfirst($member['status'])) ?></span></td>
                            <td><div class="flex gap-2 flex-wrap"><button class="btn btn-secondary btn-sm" data-edit-member='<?= e(json_encode($member, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i> Edit</button><button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$member['id'] ?>" data-delete-name="<?= e($member['full_name']) ?>"><i class="fa-solid fa-trash"></i></button></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="memberModal">
    <div class="modal-box modal-md">
        <div class="modal-header"><div class="modal-title">Edit Member</div><button class="modal-close" type="button" data-close="memberModal"><i class="fa-solid fa-xmark"></i></button></div>
        <form method="POST"><?= admin_csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="member_id" id="memberId"><div class="form-grid"><div class="input-group"><label>Chest Number</label><input type="text" name="chest_number" id="memberChest" placeholder="Leave empty"></div><div class="input-group"><label>Status</label><select name="status" id="memberStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div><div class="form-actions"><button type="button" class="btn btn-secondary btn-md" data-close="memberModal">Cancel</button><button class="btn btn-success btn-md" type="submit">Save</button></div></form>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-md">
        <div class="modal-header"><div class="modal-title">Remove Member</div><button class="modal-close" type="button" data-close="deleteModal"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="panel">Remove <strong id="deleteName"></strong>? If this member has entries, they will be marked inactive.</div>
        <form method="POST"><?= admin_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="member_id" id="deleteId"><div class="form-actions"><button type="button" class="btn btn-secondary btn-md" data-close="deleteModal">Cancel</button><button class="btn btn-danger btn-md" type="submit">Remove</button></div></form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));
document.querySelectorAll('[data-edit-member]').forEach(btn => btn.addEventListener('click', () => {
    const member = JSON.parse(btn.dataset.editMember);
    document.getElementById('memberId').value = member.id || '';
    document.getElementById('memberChest').value = member.chest_number || '';
    document.getElementById('memberStatus').value = member.status || 'active';
    openModal('memberModal');
}));
document.querySelectorAll('[data-delete-id]').forEach(btn => btn.addEventListener('click', () => {
    document.getElementById('deleteId').value = btn.dataset.deleteId;
    document.getElementById('deleteName').textContent = btn.dataset.deleteName || 'this member';
    openModal('deleteModal');
}));
</script>
</body>
</html>
