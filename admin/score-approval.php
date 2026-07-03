<?php
$pageTitle = 'Score Approval';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();
require_roles(['admin', 'score-approver']);

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

function approval_redirect(array $query = []): void
{
    admin_redirect('/admin/score-approval.php', $query);
}

function approval_badge(?string $status): string
{
    return match ((string)$status) {
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'submitted' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function approval_can_approve(?string $status): bool
{
    return admin_program_approvable($status);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        approval_redirect();
    }

    $programId = (int)($_POST['program_id'] ?? 0);
    $action = (string)($_POST['approval_action'] ?? '');
    $notes = trim((string)($_POST['rejection_notes'] ?? ''));
    $selectedProgramIds = array_values(array_unique(array_map('intval', (array)($_POST['program_ids'] ?? []))));
    $returnFilters = [
        'status' => trim((string)($_POST['return_status'] ?? 'submitted')),
        'search' => trim((string)($_POST['return_search'] ?? '')),
        'class' => trim((string)($_POST['return_class'] ?? 'all')),
    ];

    try {
        $pdo->beginTransaction();

        if ($action === 'approve') {
            $stmt = $pdo->prepare('SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
            $stmt->execute([$programId, $activeEventId]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$program) {
                throw new RuntimeException('Program not found.');
            }
            if (!approval_can_approve($program['approval_status'] ?? null)) {
                throw new RuntimeException('Only submitted or rejected programs can be approved.');
            }
            admin_approve_program_scores($pdo, $activeEventId, $programId, $currentUserId);
            admin_flash('success', 'Program scores approved.');
        } elseif ($action === 'approve_selected') {
            if (!$selectedProgramIds) {
                throw new RuntimeException('Please select at least one program to approve.');
            }
            foreach ($selectedProgramIds as $selectedProgramId) {
                admin_approve_program_scores($pdo, $activeEventId, $selectedProgramId, $currentUserId);
            }
            admin_flash('success', count($selectedProgramIds) . ' program(s) approved.');
        } elseif ($action === 'approve_all') {
            $query = "
                SELECT p.id
                FROM musabaqa_programs p
                LEFT JOIN kauzariyya.users submitter ON submitter.id = p.submitted_by
                WHERE p.event_id = ?
                  AND p.approval_status IN ('submitted', 'rejected')
            ";
            $queryParams = [$activeEventId];

            if ($returnFilters['status'] === 'submitted' || $returnFilters['status'] === 'rejected') {
                $query .= ' AND p.approval_status = ?';
                $queryParams[] = $returnFilters['status'];
            }

            if ($returnFilters['search'] !== '') {
                $query .= ' AND (p.title LIKE ? OR submitter.full_name LIKE ? OR submitter.username LIKE ?)';
                $like = '%' . $returnFilters['search'] . '%';
                array_push($queryParams, $like, $like, $like);
            }

            [$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $returnFilters['class'] ?? 'all', 'p');
            $query .= $classSql;
            array_push($queryParams, ...$classParams);

            $stmt = $pdo->prepare($query);
            $stmt->execute($queryParams);
            $selectedProgramIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
            if (!$selectedProgramIds) {
                throw new RuntimeException('No submitted or rejected programs match the current filter to approve.');
            }
            foreach ($selectedProgramIds as $selectedProgramId) {
                admin_approve_program_scores($pdo, $activeEventId, $selectedProgramId, $currentUserId);
            }
            admin_flash('success', count($selectedProgramIds) . ' program(s) approved.');
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare('SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
            $stmt->execute([$programId, $activeEventId]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$program) {
                throw new RuntimeException('Program not found.');
            }
            if (($program['approval_status'] ?? '') !== 'submitted') {
                throw new RuntimeException('Only submitted programs can be rejected.');
            }
            admin_reject_program_scores($pdo, $activeEventId, $programId, $currentUserId, $notes);
            admin_flash('success', 'Program scores rejected.');
        } elseif ($action === 'revoke_approved') {
            $stmt = $pdo->prepare('SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
            $stmt->execute([$programId, $activeEventId]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$program) {
                throw new RuntimeException('Program not found.');
            }
            if (($program['approval_status'] ?? '') !== 'approved') {
                throw new RuntimeException('Only approved programs can be revoked.');
            }
            admin_revoke_program_approval($pdo, $activeEventId, $programId, $currentUserId, $notes);
            admin_flash('success', 'Approval revoked. Finalized marks were removed and score sheets can be corrected.');
        } else {
            throw new RuntimeException('Invalid approval action.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to process program scores.');
    }

    approval_redirect($returnFilters);
}

$flash = admin_take_flash();
$statusFilter = trim((string)($_GET['status'] ?? 'submitted'));
$search = trim((string)($_GET['search'] ?? ''));
$classFilter = trim((string)($_GET['class'] ?? 'all'));
$viewProgramId = (int)($_GET['view'] ?? 0);

$where = 'WHERE p.event_id = ?';
$params = [$activeEventId];
if ($statusFilter !== 'all' && in_array($statusFilter, ['none', 'submitted', 'rejected', 'approved'], true)) {
    $where .= ' AND p.approval_status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= ' AND (p.title LIKE ? OR submitter.full_name LIKE ? OR submitter.username LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'p');
$where .= $classSql;
array_push($params, ...$classParams);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        ct.name AS class_type_name,
        COUNT(DISTINCT pe.id) AS entry_count,
        submitter.full_name AS submitted_name,
        submitter.username AS submitted_username
    FROM musabaqa_programs p
    LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
    LEFT JOIN kauzariyya.users submitter ON submitter.id = p.submitted_by
    {$where}
    GROUP BY p.id
    ORDER BY p.submitted_at DESC, p.id DESC
");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$review = null;
if ($viewProgramId > 0) {
        $stmt = $pdo->prepare("
        SELECT p.*, ct.name AS class_type_name, submitter.full_name AS submitted_name, submitter.username AS submitted_username
        FROM musabaqa_programs p
        LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
        LEFT JOIN kauzariyya.users submitter ON submitter.id = p.submitted_by
        WHERE p.id = ? AND p.event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$viewProgramId, $activeEventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($program) {
        $stmt = $pdo->prepare("
            SELECT
                pe.id,
                pe.entry_number,
                pe.entry_name,
                t.team_name,
                t.team_color,
                ss.judge1_total,
                ss.judge2_total,
                ss.final_total
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
            WHERE pe.event_id = ?
              AND pe.program_id = ?
            ORDER BY ss.final_total DESC, pe.entry_number ASC, pe.id ASC
        ");
        $stmt->execute([$activeEventId, $viewProgramId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rank = 0;
        $position = 0;
        $previous = null;
        $teamTotals = [];
        foreach ($entries as &$entry) {
            $position++;
            $total = (float)$entry['final_total'];
            if ($previous === null || $total < $previous) {
                $rank = $position;
            }
            $previous = $total;
            $entry['rank'] = $rank;
            $team = (string)$entry['team_name'];
            $teamTotals[$team] = [
                'total' => ($teamTotals[$team]['total'] ?? 0) + $total,
                'color' => $entry['team_color'] ?: '#64748b',
            ];
        }
        unset($entry);
        uasort($teamTotals, static function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $review = [
            'program' => $program,
            'entries' => $entries,
            'team_totals' => $teamTotals,
        ];
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Score Approval</div>
            <div class="page-subtitle">Review and approve complete programs</div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group">
                <label>Approval Status</label>
                <select name="status">
                    <option value="all">All Status</option>
                    <?php foreach (['submitted', 'approved', 'rejected', 'none'] as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program or submitter">
            </div>
            <div class="input-group">
                <label>Class</label>
                <select name="class">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions full-width">
                <button type="submit" class="btn btn-secondary btn-md"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($statusFilter !== 'submitted' || $search !== '' || $classFilter !== 'all'): ?>
                    <a class="btn btn-secondary btn-md" href="<?= APP_URL ?>/admin/score-approval.php">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$programs): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-clipboard-check"></i></div><div class="empty-title">No Programs Found</div><div class="empty-subtitle">No program submissions match the current filter.</div></div>
    <?php else: ?>
        <form method="POST" id="batchApprovalForm" class="mb-6">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="approval_action" id="batchApprovalAction" value="">
            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
            <input type="hidden" name="return_search" value="<?= e($search) ?>">
            <input type="hidden" name="return_class" value="<?= e($classFilter) ?>">
            <div class="flex gap-2 mb-4">
                <button type="button" class="btn btn-success btn-md" id="approveSelectedBtn"><i class="fa-solid fa-check"></i> Approve Selected</button>
                <?php if (in_array($statusFilter, ['submitted', 'rejected', 'all'], true)): ?>
                    <button type="button" class="btn btn-success btn-md" id="approveAllBtn"><i class="fa-solid fa-list-check"></i> Approve All</button>
                <?php endif; ?>
            </div>
        </form>
        <div class="table-wrapper mb-6 approval-table-wrap">
            <table class="table approval-table">
                <thead>
                    <tr>
                        <th class="checkbox-cell"><input type="checkbox" class="approval-checkbox" id="selectAllPrograms" aria-label="Select all approvable programs"></th>
                        <th>Program Name</th>
                        <th>Class</th>
                        <th>Entry Count</th>
                        <th>Submitted By</th>
                        <th>Submitted Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                        <?php
                        $isExpanded = $review && (int)$review['program']['id'] === (int)$program['id'];
                        $baseQuery = ['status' => $statusFilter, 'search' => $search, 'class' => $classFilter];
                        $viewQuery = array_merge($baseQuery, ['view' => (int)$program['id']]);
                        $toggleUrl = APP_URL . '/admin/score-approval.php?' . http_build_query($isExpanded ? $baseQuery : $viewQuery);
                        ?>
                        <tr>
                            <td class="checkbox-cell">
                                <?php if (approval_can_approve($program['approval_status'] ?? null)): ?>
                                    <input type="checkbox" class="approval-checkbox program-checkbox" data-program-id="<?= (int)$program['id'] ?>" aria-label="Select program <?= e($program['title']) ?>">
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($program['title']) ?></strong></td>
                            <td>
                                <?php $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? ''); ?>
                                <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                    <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                                </span>
                            </td>
                            <td><?= (int)$program['entry_count'] ?></td>
                            <td><?= e($program['submitted_name'] ?: $program['submitted_username'] ?: '-') ?></td>
                            <td><?= $program['submitted_at'] ? e(date('d M Y h:i A', strtotime($program['submitted_at']))) : '-' ?></td>
                            <td><span class="badge <?= approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span></td>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <a class="btn btn-secondary btn-sm" href="<?= e($toggleUrl) ?>"><i class="fa-solid fa-eye"></i> <?= $isExpanded ? 'Close' : 'View' ?></a>
                                    <?php if ($program['approval_status'] === 'submitted'): ?>
                                        <form method="POST">
                                            <?= admin_csrf_field() ?>
                                            <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                            <input type="hidden" name="approval_action" value="approve">
                                            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                            <input type="hidden" name="return_search" value="<?= e($search) ?>">
                                            <input type="hidden" name="return_class" value="<?= e($classFilter) ?>">
                                            <button class="btn btn-success btn-sm" type="submit">Approve</button>
                                        </form>
                                        <button class="btn btn-danger btn-sm" type="button" data-reject-id="<?= (int)$program['id'] ?>" data-reject-name="<?= e($program['title']) ?>" data-reject-action="reject">Reject</button>
                                    <?php elseif ($program['approval_status'] === 'rejected'): ?>
                                        <form method="POST">
                                            <?= admin_csrf_field() ?>
                                            <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                            <input type="hidden" name="approval_action" value="approve">
                                            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                            <input type="hidden" name="return_search" value="<?= e($search) ?>">
                                            <input type="hidden" name="return_class" value="<?= e($classFilter) ?>">
                                            <button class="btn btn-success btn-sm" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                                        </form>
                                    <?php elseif ($program['approval_status'] === 'approved'): ?>
                                        <button class="btn btn-danger btn-sm" type="button" data-reject-id="<?= (int)$program['id'] ?>" data-reject-name="<?= e($program['title']) ?>" data-reject-action="revoke_approved">Reject Approval</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if ($isExpanded): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="panel">
                                        <div class="flex-between mb-6">
                                            <div>
                                                <div class="dashboard-heading">Program Ranking Preview</div>
                                                <div class="page-subtitle">Final Score is Judge 1 Total + Judge 2 Total.</div>
                                            </div>
                                            <span class="badge <?= approval_badge($review['program']['approval_status']) ?>"><?= e(ucfirst((string)$review['program']['approval_status'])) ?></span>
                                        </div>

                                        <div class="table-wrapper mb-6">
                                            <table class="table">
                                                <thead><tr><th>Entry Name</th><th>Team</th><th>Judge 1 Total</th><th>Judge 2 Total</th><th>Final Score</th><th>Rank</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($review['entries'] as $entry): ?>
                                                        <tr>
                                                            <td>#<?= e(str_pad((string)$entry['entry_number'], 3, '0', STR_PAD_LEFT)) ?> <?= e($entry['entry_name'] ?: 'Unnamed Entry') ?></td>
                                                            <td><span class="team-color-pill" style="background: <?= e($entry['team_color'] ?? '#64748b') ?>22;"><?= e($entry['team_name']) ?></span></td>
                                                            <td><?= number_format((float)$entry['judge1_total'], 2) ?></td>
                                                            <td><?= number_format((float)$entry['judge2_total'], 2) ?></td>
                                                            <td><strong><?= number_format((float)$entry['final_total'], 2) ?></strong></td>
                                                            <td><strong><?= (int)$entry['rank'] ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="dashboard-heading mb-6">Team Totals Preview</div>
                                        <div class="table-wrapper">
                                            <table class="table">
                                                <thead><tr><th>Team</th><th>Program Total</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($review['team_totals'] as $teamName => $teamData): ?>
                                                        <tr>
                                                            <td><span class="team-color-pill" style="background: <?= e($teamData['color'] ?? '#64748b') ?>22;"><?= e($teamName) ?></span></td>
                                                            <td><strong><?= number_format((float)$teamData['total'], 2) ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="rejectModal">
    <div class="modal-box modal-md">
        <div class="modal-header"><div class="modal-title" id="rejectModalTitle">Reject Program</div><button class="modal-close" type="button" data-close="rejectModal"><i class="fa-solid fa-xmark"></i></button></div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="program_id" id="rejectProgramId">
            <input type="hidden" name="approval_action" id="rejectApprovalAction" value="reject">
            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
            <input type="hidden" name="return_search" value="<?= e($search) ?>">
            <input type="hidden" name="return_class" value="<?= e($classFilter) ?>">
            <div class="panel mb-6" id="rejectModalMessage">Reject <strong id="rejectProgramName"></strong>? Score sheets will become editable again.</div>
            <div class="input-group full-width"><label>Notes</label><textarea name="rejection_notes" rows="4" placeholder="Optional notes for the scoring team"></textarea></div>
            <div class="form-actions"><button type="button" class="btn btn-secondary btn-md" data-close="rejectModal">Cancel</button><button class="btn btn-danger btn-md" type="submit" id="rejectModalSubmit">Reject</button></div>
        </form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
function collectSelectedProgramIds(){
    return Array.from(document.querySelectorAll('.program-checkbox:checked')).map(input => Number(input.dataset.programId)).filter(id => id > 0);
}
function clearBatchProgramInputs(form){
    Array.from(form.querySelectorAll('input[name="program_ids[]"]')).forEach(input => input.remove());
}
function addBatchProgramInputs(form, ids){
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'program_ids[]';
        input.value = String(id);
        form.appendChild(input);
    });
}

const batchForm = document.getElementById('batchApprovalForm');
const selectAllCheckbox = document.getElementById('selectAllPrograms');
const approveSelectedBtn = document.getElementById('approveSelectedBtn');
const approveAllBtn = document.getElementById('approveAllBtn');

function syncSelectAllCheckbox() {
    if (!selectAllCheckbox) return;
    const boxes = document.querySelectorAll('.program-checkbox');
    const checked = document.querySelectorAll('.program-checkbox:checked');
    selectAllCheckbox.checked = boxes.length > 0 && boxes.length === checked.length;
    selectAllCheckbox.indeterminate = checked.length > 0 && checked.length < boxes.length;
}

if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', () => {
        document.querySelectorAll('.program-checkbox').forEach(cb => { cb.checked = selectAllCheckbox.checked; });
        selectAllCheckbox.indeterminate = false;
    });
    document.querySelectorAll('.program-checkbox').forEach(cb => {
        cb.addEventListener('change', syncSelectAllCheckbox);
    });
}

if (approveSelectedBtn && batchForm) {
    approveSelectedBtn.addEventListener('click', () => {
        const ids = collectSelectedProgramIds();
        if (!ids.length) {
            alert('Select at least one submitted or rejected program to approve.');
            return;
        }
        clearBatchProgramInputs(batchForm);
        addBatchProgramInputs(batchForm, ids);
        document.getElementById('batchApprovalAction').value = 'approve_selected';
        batchForm.submit();
    });
}

if (approveAllBtn && batchForm) {
    approveAllBtn.addEventListener('click', () => {
        if (!confirm('Approve all submitted or rejected programs that match the current filter?')) {
            return;
        }
        clearBatchProgramInputs(batchForm);
        document.getElementById('batchApprovalAction').value = 'approve_all';
        batchForm.submit();
    });
}

document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));
document.querySelectorAll('[data-reject-id]').forEach(btn => btn.addEventListener('click', () => {
    const isRevoke = btn.dataset.rejectAction === 'revoke_approved';
    const programName = btn.dataset.rejectName || 'this program';

    document.getElementById('rejectProgramId').value = btn.dataset.rejectId;
    document.getElementById('rejectApprovalAction').value = isRevoke ? 'revoke_approved' : 'reject';
    document.getElementById('rejectModalTitle').textContent = isRevoke ? 'Reject Approved Scores' : 'Reject Program';
    const msgEl = document.getElementById('rejectModalMessage');
    const nameEl = document.getElementById('rejectProgramName');
    nameEl.textContent = programName;
    msgEl.replaceChildren(
        document.createTextNode(isRevoke ? 'Reject approval for ' : 'Reject '),
        nameEl,
        document.createTextNode(
            isRevoke
                ? '? This removes finalized marks from score sheets, system scores, member scores, entry ranks, and team totals. Score sheets will become editable again.'
                : '? Score sheets will become editable again.'
        )
    );
    document.getElementById('rejectModalSubmit').textContent = isRevoke ? 'Reject Approval' : 'Reject';

    openModal('rejectModal');
}));
</script>
<?php admin_close_page(); ?>
