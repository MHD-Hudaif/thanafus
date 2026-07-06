<?php
$pageTitle = 'Schedule Sections';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/sections.php');
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Section name is required.');
            }
            if ($startTime === '' || $endTime === '') {
                throw new RuntimeException('Start time and end time are required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_schedule_sections (event_id, name, start_time, end_time, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$activeEventId, $name, $startTime, $endTime, $sortOrder]);
            admin_flash('success', 'Section added successfully.');
        } elseif ($action === 'update') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Section name is required.');
            }
            if ($startTime === '' || $endTime === '') {
                throw new RuntimeException('Start time and end time are required.');
            }

            $stmt = $pdo->prepare("
                UPDATE musabaqa_schedule_sections
                SET name = ?, start_time = ?, end_time = ?, sort_order = ?
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([$name, $startTime, $endTime, $sortOrder, $sectionId, $activeEventId]);
            admin_flash('success', 'Section updated successfully.');
        } elseif ($action === 'delete') {
            $sectionId = (int)($_POST['section_id'] ?? 0);

            $pdo->beginTransaction();
            // Disassociate any programs from this section
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET section_id = NULL
                WHERE section_id = ? AND event_id = ?
            ");
            $stmt->execute([$sectionId, $activeEventId]);

            // Delete the section
            $stmt = $pdo->prepare("
                DELETE FROM musabaqa_schedule_sections
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([$sectionId, $activeEventId]);
            $pdo->commit();

            admin_flash('success', 'Section removed.');
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Operation failed.');
    }

    admin_redirect('/admin/sections.php');
}

$flash = admin_take_flash();

// Load all sections
$stmt = $pdo->prepare("
    SELECT *
    FROM musabaqa_schedule_sections
    WHERE event_id = ?
    ORDER BY sort_order ASC, start_time ASC, id ASC
");
$stmt->execute([$activeEventId]);
$sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Schedule Sections</div>
            <div class="page-subtitle">Group programs into morning, evening, or night sections with custom timings</div>
        </div>
        <div class="flex gap-2">
            <a href="<?= app_url('/admin/schedule.php') ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-clock"></i> View Schedule</a>
            <button class="btn btn-success btn-md" type="button" data-open-add><i class="fa-solid fa-plus"></i> Add Section</button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$sections): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-folder-tree"></i></div>
            <div class="empty-title">No Schedule Sections</div>
            <div class="empty-subtitle">Create sections to group your programs (e.g. Morning programs 8:00 to 12:30).</div>
            <button class="btn btn-success btn-md mt-4" type="button" data-open-add><i class="fa-solid fa-plus"></i> Add First Section</button>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Section Name</th>
                            <th>Timing</th>
                            <th>Sort Order</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $section): ?>
                            <tr>
                                <td>
                                    <strong><?= e($section['name']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?= e(date('h:i A', strtotime($section['start_time']))) ?> - <?= e(date('h:i A', strtotime($section['end_time']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= (int)$section['sort_order'] ?>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <button 
                                            class="btn btn-secondary btn-sm" 
                                            data-edit-section='<?= e(json_encode($section, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                        >
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button 
                                            class="btn btn-danger btn-sm" 
                                            data-delete-id="<?= (int)$section['id'] ?>" 
                                            data-delete-name="<?= e($section['name']) ?>"
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Section Modal -->
<div class="modal-overlay" id="sectionModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="modalTitle">Add Section</div>
            </div>
            <button class="modal-close" type="button" data-close="sectionModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="sectionForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="section_id" id="sectionId">
            <div class="form-grid">
                <div class="input-group full-width">
                    <label>Section Name <span class="required">*</span></label>
                    <input type="text" name="name" id="sectionName" placeholder="e.g. Morning Programs" required>
                </div>
                <div class="input-group">
                    <label>Start Time <span class="required">*</span></label>
                    <input type="time" name="start_time" id="sectionStartTime" required>
                </div>
                <div class="input-group">
                    <label>End Time <span class="required">*</span></label>
                    <input type="time" name="end_time" id="sectionEndTime" required>
                </div>
                <div class="input-group full-width">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" id="sectionSortOrder" value="0" min="0" step="1">
                </div>
            </div>
            <div class="form-actions mt-6">
                <button class="btn btn-secondary btn-md" type="button" data-close="sectionModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit">Save Section</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <div class="modal-title">Delete Section</div>
            <button class="modal-close" type="button" data-close="deleteModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-6">
            <p>Are you sure you want to delete <strong id="deleteName">this section</strong>?</p>
            <p class="muted mt-2 text-sm">Programs assigned to this section will be marked as unassigned. This action cannot be undone.</p>
        </div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="section_id" id="deleteId">
            <div class="form-actions">
                <button class="btn btn-secondary btn-md" type="button" data-close="deleteModal">Cancel</button>
                <button class="btn btn-danger btn-md" type="submit">Delete Section</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => window.closeModal(btn.dataset.close)));
    document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) window.closeModal(modal.id); }));

    document.querySelectorAll('[data-open-add]').forEach(btn => btn.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent = 'Add Section';
        document.getElementById('formAction').value = 'add';
        document.getElementById('sectionId').value = '';
        document.getElementById('sectionName').value = '';
        document.getElementById('sectionStartTime').value = '08:00';
        document.getElementById('sectionEndTime').value = '12:30';
        document.getElementById('sectionSortOrder').value = '0';
        window.openModal('sectionModal');
    }));

    document.addEventListener('click', (e) => {
        const editBtn = e.target.closest('[data-edit-section]');
        if (editBtn) {
            const s = JSON.parse(editBtn.dataset.editSection);

            document.getElementById('modalTitle').textContent = 'Edit Section';
            document.getElementById('formAction').value = 'update';
            document.getElementById('sectionId').value = s.id || '';
            document.getElementById('sectionName').value = s.name || '';
            
            // Extract HH:MM from TIME format (which might be HH:MM:SS)
            const formatTime = (timeStr) => {
                if (!timeStr) return '';
                const parts = timeStr.split(':');
                return parts.slice(0, 2).join(':');
            };
            
            document.getElementById('sectionStartTime').value = formatTime(s.start_time);
            document.getElementById('sectionEndTime').value = formatTime(s.end_time);
            document.getElementById('sectionSortOrder').value = s.sort_order || '0';

            window.openModal('sectionModal');
            return;
        }

        const deleteBtn = e.target.closest('[data-delete-id]');
        if (deleteBtn) {
            document.getElementById('deleteId').value = deleteBtn.dataset.deleteId;
            document.getElementById('deleteName').textContent = deleteBtn.dataset.deleteName || 'this section';
            window.openModal('deleteModal');
            return;
        }
    });
})();
</script>

<?php admin_close_page(); ?>
