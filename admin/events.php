<?php
$pageTitle = 'Manage Events';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/events.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $eventId = (int)($_POST['event_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $themeColors = trim((string)($_POST['theme_colors'] ?? '#14b8a6,#22c55e'));
    $scoreboardMode = in_array($_POST['scoreboard_mode'] ?? 'system', ['system', 'manual'], true) ? $_POST['scoreboard_mode'] : 'system';
    $status = in_array($_POST['status'] ?? 'draft', ['draft', 'active', 'completed'], true) ? $_POST['status'] : 'draft';
    $startDate = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $endDate = trim((string)($_POST['end_date'] ?? '')) ?: null;
    $introEnabled = trim((string)($_POST['intro_enabled'] ?? '1')) === '1' ? 1 : 0;
    $scoreboardEnabled = trim((string)($_POST['scoreboard_enabled'] ?? '1')) === '1' ? 1 : 0;

    if ($action === 'delete') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_program_entries WHERE event_id = ?');
            $stmt->execute([$eventId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException('This event has entries and cannot be deleted.');
            }

            foreach (['musabaqa_team_members', 'musabaqa_programs', 'musabaqa_teams'] as $table) {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE event_id = ?");
                $stmt->execute([$eventId]);
            }

            $stmt = $pdo->prepare('DELETE FROM musabaqa_events WHERE id = ?');
            $stmt->execute([$eventId]);
            if ((int)($_SESSION['active_event_id'] ?? 0) === $eventId) {
                unset($_SESSION['active_event_id'], $_SESSION['active_team_id']);
            }
            $pdo->commit();
            admin_flash('success', 'Event deleted successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            admin_flash('error', $e->getMessage() ?: 'Unable to delete event.');
        }
        admin_redirect('/admin/events.php');
    }

    if ($title === '') {
        admin_flash('error', 'Event title is required.');
        admin_redirect('/admin/events.php');
    }

    $slug = $slug !== '' ? admin_normalize_slug($slug) : admin_normalize_slug($title);

    if ($startDate && $endDate && $endDate < $startDate) {
        admin_flash('error', 'End date cannot be before start date.');
        admin_redirect('/admin/events.php');
    }

    try {
        $dup = $pdo->prepare('SELECT id FROM musabaqa_events WHERE slug = ? AND id <> ? LIMIT 1');
        $dup->execute([$slug, $eventId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Another event already uses that slug.');
        }

        if ($action === 'update' && $eventId > 0) {
            $stmt = $pdo->prepare("
                UPDATE musabaqa_events
                SET title = ?, slug = ?, description = ?, theme_colors = ?, scoreboard_mode = ?,
                    intro_enabled = ?, scoreboard_enabled = ?, status = ?, start_date = ?, end_date = ?
                WHERE id = ?
            ");
            $stmt->execute([$title, $slug, $description, $themeColors, $scoreboardMode, $introEnabled, $scoreboardEnabled, $status, $startDate, $endDate, $eventId]);
            admin_flash('success', 'Event updated successfully.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_events (
                    title, slug, description, theme_colors, scoreboard_mode,
                    intro_enabled, scoreboard_enabled, status, start_date, end_date, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $slug, $description, $themeColors, $scoreboardMode, $introEnabled, $scoreboardEnabled, $status, $startDate, $endDate, (int)($_SESSION['user_id'] ?? 0)]);
            admin_flash('success', 'Event created successfully.');
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to save event.');
    }

    admin_redirect('/admin/events.php');
}

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));

$where = 'WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (title LIKE ? OR slug LIKE ? OR description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== 'all' && in_array($statusFilter, ['draft', 'active', 'completed'], true)) {
    $where .= ' AND status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare("SELECT * FROM musabaqa_events {$where} ORDER BY id DESC");
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalEvents = count($events);

if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['events_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['events_limit']) ? $_SESSION['events_limit'] : 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedEvents = array_slice($events, $offset, $perPage);

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedEvents) {
        echo '<div class="empty-state" style="grid-column: 1 / -1;"><div class="empty-icon"><i class="fa-solid fa-calendar-days"></i></div><div class="empty-title">No Events Found</div><div class="empty-subtitle">No matching events.</div></div>';
    } else {
        foreach ($paginatedEvents as $event) {
            $colors = array_map('trim', explode(',', $event['theme_colors'] ?: '#14b8a6,#22c55e'));
            $color1 = $colors[0] ?? '#14b8a6';
            $isActive = (int)($_SESSION['active_event_id'] ?? 0) === (int)$event['id'];
            ?>
            <div class="event-card" style="border-top: 4px solid <?= e($color1) ?>;">
                <div class="event-top">
                    <span class="badge badge-neutral"><?= e(strtoupper((string)$event['scoreboard_mode'])) ?></span>
                    <span class="badge <?= $isActive ? 'badge-success' : 'badge-neutral' ?>"><?= $isActive ? 'ACTIVE CONTEXT' : e(strtoupper((string)$event['status'])) ?></span>
                </div>
                <div class="event-title"><?= e($event['title']) ?></div>
                <div class="event-description"><?= e($event['description'] ?: 'No description') ?></div>
                <div class="event-meta">
                    <div class="event-meta-item"><span>Start</span><strong><?= e($event['start_date'] ?: '-') ?></strong></div>
                    <div class="event-meta-item"><span>End</span><strong><?= e($event['end_date'] ?: '-') ?></strong></div>
                </div>
                <div class="event-actions">
                    <a class="btn btn-success btn-sm" href="<?= app_url('/admin/utilities/set-active-event.php') ?>?id=<?= (int)$event['id'] ?>">
                        <i class="fa-solid fa-door-open"></i> Open
                    </a>
                    <button class="btn btn-secondary btn-sm" data-edit-event='<?= e(json_encode($event, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                        <i class="fa-solid fa-pen"></i> Edit
                    </button>
                    <button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$event['id'] ?>" data-delete-name="<?= e($event['title']) ?>">
                        <i class="fa-solid fa-trash"></i>
                    </button>
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
        'pagination' => admin_render_pagination_html($page, $perPage, $totalEvents)
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Events</div>
            <div class="page-subtitle">Manage musabaqa events, themes and live systems</div>
        </div>
        <button class="btn btn-success btn-md" data-open-modal="eventModal">
            <i class="fa-solid fa-plus"></i> Create Event
        </button>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" id="search-form">
            <div class="input-group">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Title, slug or description">
            </div>
            <div class="input-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                    <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/events.php') ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$events): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-calendar-days"></i></div>
            <div class="empty-title">No Events Found</div>
            <div class="empty-subtitle">Create an event to begin the workflow.</div>
        </div>
    <?php else: ?>
        <div class="events-grid" id="table-body">
            <?php foreach ($paginatedEvents as $event): ?>
                <?php
                    $colors = array_map('trim', explode(',', $event['theme_colors'] ?: '#14b8a6,#22c55e'));
                    $color1 = $colors[0] ?? '#14b8a6';
                    $color2 = $colors[1] ?? '#22c55e';
                    $isActive = (int)($_SESSION['active_event_id'] ?? 0) === (int)$event['id'];
                ?>
                <div class="event-card" style="border-top: 4px solid <?= e($color1) ?>;">
                    <div class="event-top">
                        <span class="badge badge-neutral"><?= e(strtoupper((string)$event['scoreboard_mode'])) ?></span>
                        <span class="badge <?= $isActive ? 'badge-success' : 'badge-neutral' ?>"><?= $isActive ? 'ACTIVE CONTEXT' : e(strtoupper((string)$event['status'])) ?></span>
                    </div>
                    <div class="event-title"><?= e($event['title']) ?></div>
                    <div class="event-description"><?= e($event['description'] ?: 'No description') ?></div>
                    <div class="event-meta">
                        <div class="event-meta-item"><span>Start</span><strong><?= e($event['start_date'] ?: '-') ?></strong></div>
                        <div class="event-meta-item"><span>End</span><strong><?= e($event['end_date'] ?: '-') ?></strong></div>
                    </div>
                    <div class="event-actions">
                        <a class="btn btn-success btn-sm" href="<?= app_url('/admin/utilities/set-active-event.php') ?>?id=<?= (int)$event['id'] ?>">
                            <i class="fa-solid fa-door-open"></i> Open
                        </a>
                        <button class="btn btn-secondary btn-sm" data-edit-event='<?= e(json_encode($event, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                            <i class="fa-solid fa-pen"></i> Edit
                        </button>
                        <button class="btn btn-danger btn-sm" data-delete-id="<?= (int)$event['id'] ?>" data-delete-name="<?= e($event['title']) ?>">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="pagination-container">
            <?= admin_render_pagination_html($page, $perPage, $totalEvents) ?>
    <?php endif; ?>


<div class="modal-overlay" id="eventModal" aria-hidden="true">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="eventModalTitle">Create Event</div>
            <button class="modal-close" type="button" data-modal-close><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="eventForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="eventAction" value="create">
            <input type="hidden" name="event_id" id="eventId" value="">
            <input type="hidden" name="intro_enabled" id="eventIntro" value="1">
            <input type="hidden" name="scoreboard_enabled" id="eventScoreboard" value="1">
            <div class="input-group full-width hidden" id="eventStatusRow">
                <label>Status</label>
                <select name="status" id="eventStatusSelect">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="form-grid">
                <div class="input-group">
                    <label>Title <span class="required">*</span></label>
                    <input type="text" name="title" id="eventTitle" required>
                </div>
                <div class="input-group">
                    <label>Slug</label>
                    <input type="text" name="slug" id="eventSlug">
                </div>
                <div class="input-group">
                    <label>Theme Colors</label>
                    <div class="color-input-container">
                        <div class="color-chip-list" id="eventColorChips" aria-live="polite"></div>
                        <input type="text" id="eventColorEntry" autocomplete="off" placeholder="Type a color name or hex code">
                    </div>
                    <input type="hidden" name="theme_colors" id="eventColors" value="">
                    <div class="color-suggestions" id="eventColorSuggestions" role="listbox" aria-label="Color suggestions"></div>
                </div>
                <div class="input-group">
                    <label>Scoreboard Mode</label>
                    <select name="scoreboard_mode" id="eventMode">
                        <option value="system">System</option>
                        <option value="manual">Manual</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="eventStart">
                </div>
                <div class="input-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" id="eventEnd">
                </div>
                <div class="input-group full-width">
                    <label>Description</label>
                    <textarea name="description" id="eventDescription" rows="4"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success btn-md">Save Event</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteModal" aria-hidden="true">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div class="modal-title">Delete Event</div>
            <button class="modal-close" type="button" data-modal-close><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="panel">Delete <strong id="deleteName"></strong>? Events with entries are protected from deletion.</div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="event_id" id="deleteId">
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger btn-md">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {

const COLOR_SUGGESTIONS = [
    { label: 'Teal', value: '#14b8a6' },
    { label: 'Green', value: '#22c55e' },
    { label: 'Blue', value: '#2563eb' },
    { label: 'Orange', value: '#f97316' },
    { label: 'Pink', value: '#e11d48' },
    { label: 'Purple', value: '#8b5cf6' },
    { label: 'Yellow', value: '#facc15' },
    { label: 'Sky', value: '#0ea5e9' }
];

const colorState = { chips: [] };
const eventForm = document.getElementById('eventForm');
const colorChipsEl = document.getElementById('eventColorChips');
const colorInput = document.getElementById('eventColorEntry');
const colorHidden = document.getElementById('eventColors');
const colorSuggestionsEl = document.getElementById('eventColorSuggestions');

function normalizeColor(value) {
    return String(value || '').trim();
}

function parseColorString(value) {
    return String(value || '')
        .split(',')
        .map(normalizeColor)
        .filter(Boolean);
}

function updateHiddenColors() {
    colorHidden.value = colorState.chips.join(',');
}

function renderColorChips() {
    colorChipsEl.innerHTML = '';

    colorState.chips.forEach((color, index) => {
        const chip = document.createElement('span');
        chip.className = 'color-chip';
        chip.innerHTML = `
            <span class="color-chip-swatch" style="background:${color};"></span>
            <span class="color-chip-label">${color}</span>
            <button type="button" class="color-chip-remove" aria-label="Remove ${color}">&times;</button>
        `;
        chip.querySelector('.color-chip-remove')?.addEventListener('click', () => {
            colorState.chips.splice(index, 1);
            renderColorChips();
            updateHiddenColors();
            updateSuggestions(colorInput.value.trim());
        });
        colorChipsEl.appendChild(chip);
    });
}

function isValidColor(value) {
    if (!value) return false;
    const normalized = normalizeColor(value).replace(/,$/, '');
    return CSS.supports('color', normalized);
}

function addColorChip(value) {
    const color = normalizeColor(value).replace(/,$/, '');
    if (!color || colorState.chips.includes(color)) return;
    if (!isValidColor(color)) return;
    colorState.chips.push(color);
    renderColorChips();
    updateHiddenColors();
}

function clearColorEntry() {
    colorInput.value = '';
}

function setColorChips(values) {
    colorState.chips = Array.from(new Set(parseColorString(values))).filter(Boolean);
    renderColorChips();
    updateHiddenColors();
}

function filterSuggestions(query) {
    const normalized = String(query || '').toLowerCase().trim();
    if (!normalized) {
        return COLOR_SUGGESTIONS;
    }
    return COLOR_SUGGESTIONS.filter(item =>
        item.label.toLowerCase().includes(normalized) || item.value.toLowerCase().includes(normalized)
    );
}

function renderSuggestions(query) {
    const suggestions = filterSuggestions(query);
    colorSuggestionsEl.innerHTML = '';

    if (!suggestions.length) {
        colorSuggestionsEl.classList.remove('active');
        return;
    }

    suggestions.forEach(item => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'color-suggestion';
        option.innerHTML = `
            <span class="color-swatch" style="background:${item.value};"></span>
            <span>${item.label}</span>
            <small>${item.value}</small>
        `;
        option.addEventListener('click', () => {
            addColorChip(item.value);
            clearColorEntry();
            renderSuggestions('');
            colorInput.focus();
        });
        colorSuggestionsEl.appendChild(option);
    });
    colorSuggestionsEl.classList.add('active');
}

function commitColorEntry() {
    const value = normalizeColor(colorInput.value);
    if (!value) return;
    addColorChip(value);
    clearColorEntry();
    renderSuggestions('');
}

colorInput?.addEventListener('input', (event) => {
    renderSuggestions(event.target.value);
});

colorInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        commitColorEntry();
    }
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('.color-input-container') && !event.target.closest('.color-suggestion')) {
        colorSuggestionsEl.classList.remove('active');
    }
});

function resetEventModal() {
    document.getElementById('eventForm').reset();
    setColorChips('');
    colorSuggestionsEl.classList.remove('active');
    document.getElementById('eventStatusSelect').value = 'draft';
    document.getElementById('eventIntro').value = '1';
    document.getElementById('eventScoreboard').value = '1';
}

document.querySelectorAll('[data-open-modal]').forEach(btn => btn.addEventListener('click', () => {
    resetEventModal();
    document.getElementById('eventModalTitle').textContent = 'Create Event';
    document.getElementById('eventAction').value = 'create';
    document.getElementById('eventId').value = '';
    document.getElementById('eventStatusRow').classList.add('hidden');
    document.getElementById('eventStatusSelect').value = 'draft';
   window.openModal(btn.dataset.openModal);
}));
document.addEventListener('click', (e) => {
    const editBtn = e.target.closest('[data-edit-event]');
    if (editBtn) {
        const event = JSON.parse(editBtn.dataset.editEvent);
        document.getElementById('eventModalTitle').textContent = 'Edit Event';
        document.getElementById('eventAction').value = 'update';
        document.getElementById('eventId').value = event.id || '';
        document.getElementById('eventTitle').value = event.title || '';
        document.getElementById('eventSlug').value = event.slug || '';
        setColorChips(event.theme_colors || '');
        document.getElementById('eventMode').value = event.scoreboard_mode || 'system';
        document.getElementById('eventStatusSelect').value = event.status || 'draft';
        document.getElementById('eventStatusRow').classList.remove('hidden');
        document.getElementById('eventIntro').value = String(event.intro_enabled) === '1' ? '1' : '0';
        document.getElementById('eventScoreboard').value = String(event.scoreboard_enabled) === '1' ? '1' : '0';
        document.getElementById('eventStart').value = event.start_date || '';
        document.getElementById('eventEnd').value = event.end_date || '';
        document.getElementById('eventDescription').value = event.description || '';
        colorSuggestionsEl.classList.remove('active');
       window.openModal('eventModal');
        return;
    }

    const deleteBtn = e.target.closest('[data-delete-id]');
    if (deleteBtn) {
        document.getElementById('deleteId').value = deleteBtn.dataset.deleteId;
        document.getElementById('deleteName').textContent = deleteBtn.dataset.deleteName || 'this event';
       window.openModal('deleteModal');
        return;
    }
});

eventForm?.addEventListener('submit', () => {
    updateHiddenColors();
});

})();
</script>
</div>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
