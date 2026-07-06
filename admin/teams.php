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

    if ($teamName === '') {
        admin_flash('error', 'Team name is required.');
        admin_redirect('/admin/teams.php');
    }

    try {
        $dup = $pdo->prepare('SELECT id FROM musabaqa_teams WHERE event_id = ? AND team_name = ? AND id <> ? LIMIT 1');
        $dup->execute([$activeEventId, $teamName, $teamId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Team name already exists.');
        }

        if ($action === 'update' && $teamId > 0) {
            $stmt = $pdo->prepare('UPDATE musabaqa_teams SET team_name = ?, short_name = ?, team_color = ? WHERE id = ? AND event_id = ?');
            $stmt->execute([$teamName, $shortName ?: null, $teamColor, $teamId, $activeEventId]);
            admin_flash('success', 'Team updated successfully.');
        } else {
            $stmt = $pdo->prepare('SELECT MAX(number_prefix) FROM musabaqa_teams WHERE event_id = ?');
            $stmt->execute([$activeEventId]);
            $maxPrefix = (int)$stmt->fetchColumn();
            $numberPrefix = $maxPrefix > 0 ? $maxPrefix + 100 : 100;
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
$totalTeams = count($teams);

if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['teams_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['teams_limit']) ? $_SESSION['teams_limit'] : 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedTeams = array_slice($teams, $offset, $perPage);

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedTeams) {
        echo '<div class="empty-state" style="grid-column: 1 / -1;"><div class="empty-icon"><i class="fa-solid fa-people-group"></i></div><div class="empty-title">No Teams Found</div><div class="empty-subtitle">No matching teams.</div></div>';
    } else {
        foreach ($paginatedTeams as $team) {
            ?>
            <div class="team-card" style="border-top:4px solid <?= e($team['team_color'] ?: '#14b8a6') ?>;">
                <div class="team-top"><div class="team-color-dot" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>;"></div><div class="team-prefix"><?= e((string)$team['number_prefix']) ?>+</div></div>
                <div class="team-name team-color-pill" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>22; color: <?= e($team['team_color'] ? '#111' : '#111') ?>;"><?= e($team['team_name']) ?></div>
                <div class="team-short"><?= e($team['short_name'] ?: 'No short name') ?></div>
                <div class="team-score"><?= number_format((float)($team['total_score'] ?? 0), 2) ?> <span>points</span></div>
                <div class="event-meta">
                    <div class="event-meta-item"><span>Members</span><strong><?= (int)$team['member_count'] ?></strong></div>
                    <div class="event-meta-item"><span>Entries</span><strong><?= (int)$team['entry_count'] ?></strong></div>
                </div>
                <div class="team-actions">
                    <a href="<?= app_url('/admin/members.php') ?>?team=<?= (int)$team['id'] ?>" class="btn btn-success btn-sm">Members</a>
                    <button class="btn btn-secondary btn-sm" data-edit-team='<?= e(json_encode($team, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i> Edit</button>
                    <button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$team['id'] ?>" data-delete-name="<?= e($team['team_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
            <?php
        }
    }
    $tbodyHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'html' => $tbodyHtml,
        'pagination' => admin_render_pagination_html($page, $perPage, $totalTeams)
    ]);
    exit;
}

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
        <form method="GET" class="form-grid" id="search-form">
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Team name, short name or prefix">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($search !== ''): ?><a class="btn btn-secondary btn-md" href="<?= app_url('/admin/teams.php') ?>">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$teams): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-people-group"></i></div><div class="empty-title">No Teams Found</div><div class="empty-subtitle">Create teams for this event.</div></div>
    <?php else: ?>
        <div class="teams-grid" id="table-body">
            <?php foreach ($paginatedTeams as $team): ?>
                <div class="team-card" style="border-top:4px solid <?= e($team['team_color'] ?: '#14b8a6') ?>;">
                    <div class="team-top"><div class="team-color-dot" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>;"></div><div class="team-prefix"><?= e((string)$team['number_prefix']) ?>+</div></div>
                    <div class="team-name team-color-pill" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>22; color: <?= e($team['team_color'] ? '#111' : '#111') ?>;"><?= e($team['team_name']) ?></div>
                    <div class="team-short"><?= e($team['short_name'] ?: 'No short name') ?></div>
                    <div class="team-score"><?= number_format((float)($team['total_score'] ?? 0), 2) ?> <span>points</span></div>
                    <div class="event-meta">
                        <div class="event-meta-item"><span>Members</span><strong><?= (int)$team['member_count'] ?></strong></div>
                        <div class="event-meta-item"><span>Entries</span><strong><?= (int)$team['entry_count'] ?></strong></div>
                    </div>
                    <div class="team-actions">
                        <a href="<?= app_url('/admin/members.php') ?>?team=<?= (int)$team['id'] ?>" class="btn btn-success btn-sm">Members</a>
                        <button class="btn btn-secondary btn-sm" data-edit-team='<?= e(json_encode($team, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><i class="fa-solid fa-pen"></i> Edit</button>
                        <button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$team['id'] ?>" data-delete-name="<?= e($team['team_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="pagination-container">
            <?= admin_render_pagination_html($page, $perPage, $totalTeams) ?>
    <?php endif; ?>


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
                <div class="input-group">
                    <label>Team Color</label>
                    <div class="color-input-container">
                        <div class="color-chip-list" id="teamColorChips" aria-live="polite"></div>
                        <input type="text" id="teamColorEntry" autocomplete="off" placeholder="Type a color name or hex code">
                    </div>
                    <input type="hidden" name="team_color" id="teamColor" value="#14b8a6">
                    <div class="color-suggestions" id="teamColorSuggestions" role="listbox" aria-label="Team color suggestions"></div>
                </div>
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
(() => {
    // Re-bind modal triggers for the new DOM elements injected via AJAX
    document.querySelector('[data-open-team]')?.addEventListener('click', () => {
        document.getElementById('teamForm').reset();
        document.getElementById('teamModalTitle').textContent = 'Create Team';
        document.getElementById('teamAction').value = 'create';
        document.getElementById('teamId').value = '';
        teamColorState.color = '';
        teamColorHidden.value = '#14b8a6';
        renderTeamColorChip();
        teamColorSuggestionsEl.classList.remove('active');
        window.openModal('teamModal');
    });

    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => window.closeModal(btn.dataset.close)));
    document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) window.closeModal(modal.id); }));

    const TEAM_COLOR_SUGGESTIONS = [
        { label: 'Teal', value: '#14b8a6' },
        { label: 'Green', value: '#22c55e' },
        { label: 'Blue', value: '#2563eb' },
        { label: 'Orange', value: '#f97316' },
        { label: 'Pink', value: '#e11d48' },
        { label: 'Purple', value: '#8b5cf6' },
        { label: 'Yellow', value: '#facc15' },
        { label: 'Sky', value: '#0ea5e9' }
    ];

    const teamColorState = { color: '' };
    const teamColorChipsEl = document.getElementById('teamColorChips');
    const teamColorInput = document.getElementById('teamColorEntry');
    const teamColorHidden = document.getElementById('teamColor');
    const teamColorSuggestionsEl = document.getElementById('teamColorSuggestions');

    function normalizeColorValue(value) {
        return String(value || '').trim();
    }

    function isValidColorValue(value) {
        if (!value) return false;
        return CSS.supports('color', normalizeColorValue(value));
    }

    function renderTeamColorChip() {
        if (!teamColorChipsEl) return;
        teamColorChipsEl.innerHTML = '';
        const color = normalizeColorValue(teamColorState.color);
        if (!color) return;
        const chip = document.createElement('span');
        chip.className = 'color-chip';
        chip.innerHTML = `
            <span class="color-chip-swatch" style="background:${color};"></span>
            <span class="color-chip-label">${color}</span>
            <button type="button" class="color-chip-remove" aria-label="Remove ${color}">&times;</button>
        `;
        chip.querySelector('.color-chip-remove')?.addEventListener('click', () => {
            teamColorState.color = '';
            if (teamColorHidden) teamColorHidden.value = '#14b8a6';
            renderTeamColorChip();
            if (teamColorInput) renderTeamColorSuggestions(teamColorInput.value.trim());
        });
        teamColorChipsEl.appendChild(chip);
    }

    function setTeamColor(value) {
        const color = normalizeColorValue(value);
        if (!color || !isValidColorValue(color)) return;
        teamColorState.color = color;
        if (teamColorHidden) teamColorHidden.value = color;
        renderTeamColorChip();
    }

    function filterTeamColorSuggestions(query) {
        const normalized = String(query || '').toLowerCase().trim();
        if (!normalized) return TEAM_COLOR_SUGGESTIONS;
        return TEAM_COLOR_SUGGESTIONS.filter(item => item.label.toLowerCase().includes(normalized) || item.value.toLowerCase().includes(normalized));
    }

    function renderTeamColorSuggestions(query) {
        if (!teamColorSuggestionsEl) return;
        const suggestions = filterTeamColorSuggestions(query);
        teamColorSuggestionsEl.innerHTML = '';
        if (!suggestions.length) {
            teamColorSuggestionsEl.classList.remove('active');
            return;
        }
        suggestions.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'color-suggestion';
            button.innerHTML = `
                <span class="color-swatch" style="background:${item.value};"></span>
                <span>${item.label}</span>
                <small>${item.value}</small>
            `;
            button.addEventListener('click', () => {
                setTeamColor(item.value);
                if (teamColorInput) teamColorInput.value = '';
                teamColorSuggestionsEl.classList.remove('active');
                if (teamColorInput) teamColorInput.focus();
            });
            teamColorSuggestionsEl.appendChild(button);
        });
        teamColorSuggestionsEl.classList.add('active');
    }

    teamColorInput?.addEventListener('input', event => renderTeamColorSuggestions(event.target.value));
    teamColorInput?.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            setTeamColor(teamColorInput.value);
            teamColorInput.value = '';
            if (teamColorSuggestionsEl) teamColorSuggestionsEl.classList.remove('active');
        }
    });

    // Only attach global document listeners ONCE per session to prevent duplication
    if (!window._teamsPageInit) {
        window._teamsPageInit = true;
        
        document.addEventListener('click', event => {
            if (!event.target.closest('.color-input-container') && !event.target.closest('.color-suggestion')) {
                const sugg = document.getElementById('teamColorSuggestions');
                if (sugg) sugg.classList.remove('active');
            }
        });

        document.addEventListener('click', (e) => {
            const editBtn = e.target.closest('[data-edit-team]');
            if (editBtn) {
                const team = JSON.parse(editBtn.dataset.editTeam);
                document.getElementById('teamModalTitle').textContent = 'Edit Team';
                document.getElementById('teamAction').value = 'update';
                document.getElementById('teamId').value = team.id || '';
                document.getElementById('teamName').value = team.team_name || '';
                document.getElementById('teamShort').value = team.short_name || '';
                
                // We must re-query the inputs because the DOM might have been swapped via AJAX
                const hiddenInput = document.getElementById('teamColor');
                if (hiddenInput) hiddenInput.value = team.team_color || '#14b8a6';
                
                // Hack to render chip using latest DOM elements
                const chipsEl = document.getElementById('teamColorChips');
                if (chipsEl) {
                    chipsEl.innerHTML = '';
                    const color = team.team_color || '#14b8a6';
                    const chip = document.createElement('span');
                    chip.className = 'color-chip';
                    chip.innerHTML = `
                        <span class="color-chip-swatch" style="background:${color};"></span>
                        <span class="color-chip-label">${color}</span>
                        <button type="button" class="color-chip-remove" aria-label="Remove ${color}">&times;</button>
                    `;
                    chip.querySelector('.color-chip-remove')?.addEventListener('click', () => {
                        if (hiddenInput) hiddenInput.value = '#14b8a6';
                        chipsEl.innerHTML = '';
                    });
                    chipsEl.appendChild(chip);
                }

                const suggEl = document.getElementById('teamColorSuggestions');
                if (suggEl) suggEl.classList.remove('active');
                
                window.openModal('teamModal');
                return;
            }

            const deleteBtn = e.target.closest('[data-delete-id]');
            if (deleteBtn) {
                document.getElementById('deleteId').value = deleteBtn.dataset.deleteId;
                document.getElementById('deleteName').textContent = deleteBtn.dataset.deleteName || 'this team';
                window.openModal('deleteModal');
                return;
            }
        });
    }
})();
</script>
</div>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
