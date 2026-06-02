<?php
$pageTitle = 'Manage Teams';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/teams.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $teamId = (int)($_POST['team_id'] ?? 0);
    $teamName = trim((string)($_POST['team_name'] ?? ''));
    $shortName = trim((string)($_POST['short_name'] ?? ''));
    $teamColor = trim((string)($_POST['team_color'] ?? '#14b8a6'));
    $numberPrefix = (int)($_POST['number_prefix'] ?? 0);

    if ($action === 'delete') {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_team_members WHERE team_id = ? AND event_id = ?');
            $stmt->execute([$teamId, $activeEventId]);
            $memberCount = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_program_entries WHERE team_id = ? AND event_id = ?');
            $stmt->execute([$teamId, $activeEventId]);
            $entryCount = (int)$stmt->fetchColumn();
            if ($memberCount > 0 || $entryCount > 0) {
                throw new RuntimeException('Teams with members or entries cannot be deleted.');
            }
            $stmt = $pdo->prepare('DELETE FROM musabaqa_teams WHERE id = ? AND event_id = ?');
            $stmt->execute([$teamId, $activeEventId]);
            if ((int)($_SESSION['active_team_id'] ?? 0) === $teamId) {
                unset($_SESSION['active_team_id']);
            }
            admin_flash('success', 'Team deleted successfully.');
        } catch (Throwable $e) {
            admin_flash('error', $e->getMessage() ?: 'Unable to delete team.');
        }
        admin_redirect('/admin/teams.php');
    }

    if ($teamName === '' || $numberPrefix <= 0) {
        admin_flash('error', 'Team name and chest number prefix are required.');
        admin_redirect('/admin/teams.php');
    }

    try {
        $dup = $pdo->prepare('SELECT id FROM musabaqa_teams WHERE event_id = ? AND number_prefix = ? AND id <> ? LIMIT 1');
        $dup->execute([$activeEventId, $numberPrefix, $teamId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Chest number prefix is already used.');
        }

        $dup = $pdo->prepare('SELECT id FROM musabaqa_teams WHERE event_id = ? AND team_name = ? AND id <> ? LIMIT 1');
        $dup->execute([$activeEventId, $teamName, $teamId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Team name already exists.');
        }

        if ($action === 'update' && $teamId > 0) {
            $stmt = $pdo->prepare('UPDATE musabaqa_teams SET team_name = ?, short_name = ?, team_color = ?, number_prefix = ? WHERE id = ? AND event_id = ?');
            $stmt->execute([$teamName, $shortName ?: null, $teamColor, $numberPrefix, $teamId, $activeEventId]);
            admin_flash('success', 'Team updated successfully.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO musabaqa_teams (event_id, team_name, short_name, team_color, number_prefix) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$activeEventId, $teamName, $shortName ?: null, $teamColor, $numberPrefix]);
            admin_flash('success', 'Team created successfully.');
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to save team.');
    }

    admin_redirect('/admin/teams.php');
}

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$where = 'WHERE t.event_id = ?';
$params = [$activeEventId];
if ($search !== '') {
    $where .= ' AND (t.team_name LIKE ? OR t.short_name LIKE ? OR CAST(t.number_prefix AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$stmt = $pdo->prepare("
    SELECT t.*, COUNT(DISTINCT tm.id) AS member_count, COUNT(DISTINCT pe.id) AS entry_count
    FROM musabaqa_teams t
    LEFT JOIN musabaqa_team_members tm ON tm.team_id = t.id AND tm.status = 'active'
    LEFT JOIN musabaqa_program_entries pe ON pe.team_id = t.id
    {$where}
    GROUP BY t.id
    ORDER BY t.team_name ASC
");
$stmt->execute($params);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Teams</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <button class="btn btn-success btn-md" data-open-team><i class="fa-solid fa-plus"></i> Create Team</button>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Team name, short name or prefix">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($search !== ''): ?><a class="btn btn-secondary btn-md" href="<?= APP_URL ?>/admin/teams.php">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$teams): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-people-group"></i></div><div class="empty-title">No Teams Found</div><div class="empty-subtitle">Create teams for this event.</div></div>
    <?php else: ?>
        <div class="teams-grid">
            <?php foreach ($teams as $team): ?>
                <div class="team-card" style="border-top:4px solid <?= e($team['team_color'] ?: '#14b8a6') ?>;">
                    <div class="team-top"><div class="team-color-dot" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>;"></div><div class="team-prefix"><?= e((string)$team['number_prefix']) ?>+</div></div>
                    <div class="team-name"><?= e($team['team_name']) ?></div>
                    <div class="team-short"><?= e($team['short_name'] ?: 'No short name') ?></div>
                    <div class="team-score"><?= number_format((float)($team['total_score'] ?? 0), 2) ?> <span>points</span></div>
                    <div class="event-meta">
                        <div class="event-meta-item"><span>Members</span><strong><?= (int)$team['member_count'] ?></strong></div>
                        <div class="event-meta-item"><span>Entries</span><strong><?= (int)$team['entry_count'] ?></strong></div>
                    </div>
                    <div class="team-actions">
                        <a href="<?= APP_URL ?>/admin/members.php?team=<?= (int)$team['id'] ?>" class="btn btn-success btn-sm">Members</a>
                        <button class="btn btn-secondary btn-sm" data-edit-team='<?= e(json_encode($team, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i> Edit</button>
                        <button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$team['id'] ?>" data-delete-name="<?= e($team['team_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="teamModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="teamModalTitle">Create Team</div>
            <button class="modal-close" type="button" data-close="teamModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="teamForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="teamAction" value="create">
            <input type="hidden" name="team_id" id="teamId">
            <div class="form-grid">
                <div class="input-group full-width"><label>Team Name <span class="required">*</span></label><input type="text" name="team_name" id="teamName" required></div>
                <div class="input-group"><label>Short Name</label><input type="text" name="short_name" id="teamShort"></div>
                <div class="input-group"><label>Team Color</label><input type="color" name="team_color" id="teamColor" value="#14b8a6"></div>
                <div class="input-group full-width"><label>Chest Number Prefix <span class="required">*</span></label><input type="number" name="number_prefix" id="teamPrefix" min="1" required></div>
            </div>
            <div class="form-actions"><button type="button" class="btn btn-secondary btn-md" data-close="teamModal">Cancel</button><button class="btn btn-success btn-md" type="submit">Save Team</button></div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-md">
        <div class="modal-header"><div class="modal-title">Delete Team</div><button class="modal-close" type="button" data-close="deleteModal"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="panel">Delete <strong id="deleteName"></strong>? Teams with members or entries are protected.</div>
        <form method="POST"><?= admin_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="team_id" id="deleteId"><div class="form-actions"><button type="button" class="btn btn-secondary btn-md" data-close="deleteModal">Cancel</button><button class="btn btn-danger btn-md" type="submit">Delete</button></div></form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelector('[data-open-team]')?.addEventListener('click', () => {
    document.getElementById('teamForm').reset();
    document.getElementById('teamModalTitle').textContent = 'Create Team';
    document.getElementById('teamAction').value = 'create';
    document.getElementById('teamId').value = '';
    openModal('teamModal');
});
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));
document.querySelectorAll('[data-edit-team]').forEach(btn => btn.addEventListener('click', () => {
    const team = JSON.parse(btn.dataset.editTeam);
    document.getElementById('teamModalTitle').textContent = 'Edit Team';
    document.getElementById('teamAction').value = 'update';
    document.getElementById('teamId').value = team.id || '';
    document.getElementById('teamName').value = team.team_name || '';
    document.getElementById('teamShort').value = team.short_name || '';
    document.getElementById('teamColor').value = team.team_color || '#14b8a6';
    document.getElementById('teamPrefix').value = team.number_prefix || '';
    openModal('teamModal');
}));
document.querySelectorAll('[data-delete-id]').forEach(btn => btn.addEventListener('click', () => {
    document.getElementById('deleteId').value = btn.dataset.deleteId;
    document.getElementById('deleteName').textContent = btn.dataset.deleteName || 'this team';
    openModal('deleteModal');
}));
</script>
</body>
</html>
