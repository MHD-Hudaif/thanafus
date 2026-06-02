<?php
$pageTitle = 'Score Approval';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();
require_roles(['admin', 'score-approver']);

$pdo = $GLOBALS['musabaqa_pdo'];
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        approval_redirect();
    }

    $programId = (int)($_POST['program_id'] ?? 0);
    $action = (string)($_POST['approval_action'] ?? '');
    $notes = trim((string)($_POST['rejection_notes'] ?? ''));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
        $stmt->execute([$programId, $activeEventId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$program) {
            throw new RuntimeException('Program not found.');
        }
        if (($program['approval_status'] ?? '') !== 'submitted') {
            throw new RuntimeException('Only submitted programs can be reviewed.');
        }

        if ($action === 'approve') {
            admin_approve_program_scores($pdo, $activeEventId, $programId, $currentUserId);
            admin_flash('success', 'Program scores approved.');
        } elseif ($action === 'reject') {
            admin_reject_program_scores($pdo, $activeEventId, $programId, $currentUserId, $notes);
            admin_flash('success', 'Program scores rejected.');
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

    approval_redirect();
}

$flash = admin_take_flash();
$statusFilter = trim((string)($_GET['status'] ?? 'submitted'));
$search = trim((string)($_GET['search'] ?? ''));
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

$stmt = $pdo->prepare("
    SELECT
        p.*,
        COUNT(DISTINCT pe.id) AS entry_count,
        submitter.full_name AS submitted_name,
        submitter.username AS submitted_username
    FROM musabaqa_programs p
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
        SELECT p.*, submitter.full_name AS submitted_name, submitter.username AS submitted_username
        FROM musabaqa_programs p
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
            $teamTotals[$team] = ($teamTotals[$team] ?? 0) + $total;
        }
        unset($entry);
        arsort($teamTotals);

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
            <div class="form-actions full-width">
                <button type="submit" class="btn btn-secondary btn-md"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($statusFilter !== 'submitted' || $search !== ''): ?>
                    <a class="btn btn-secondary btn-md" href="<?= APP_URL ?>/admin/score-approval.php">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$programs): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-clipboard-check"></i></div><div class="empty-title">No Programs Found</div><div class="empty-subtitle">No program submissions match the current filter.</div></div>
    <?php else: ?>
        <div class="table-wrapper mb-6">
            <table class="table">
                <thead>
                    <tr>
                        <th>Program Name</th>
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
                        $baseQuery = ['status' => $statusFilter, 'search' => $search];
                        $viewQuery = array_merge($baseQuery, ['view' => (int)$program['id']]);
                        $toggleUrl = APP_URL . '/admin/score-approval.php?' . http_build_query($isExpanded ? $baseQuery : $viewQuery);
                        ?>
                        <tr>
                            <td><strong><?= e($program['title']) ?></strong></td>
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
                                            <button class="btn btn-success btn-sm" type="submit">Approve</button>
                                        </form>
                                        <button class="btn btn-danger btn-sm" type="button" data-reject-id="<?= (int)$program['id'] ?>" data-reject-name="<?= e($program['title']) ?>">Reject</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if ($isExpanded): ?>
                            <tr>
                                <td colspan="6">
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
                                                            <td><?= e($entry['team_name']) ?></td>
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
                                                    <?php foreach ($review['team_totals'] as $teamName => $total): ?>
                                                        <tr><td><?= e($teamName) ?></td><td><strong><?= number_format((float)$total, 2) ?></strong></td></tr>
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
        <div class="modal-header"><div class="modal-title">Reject Program</div><button class="modal-close" type="button" data-close="rejectModal"><i class="fa-solid fa-xmark"></i></button></div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="program_id" id="rejectProgramId">
            <input type="hidden" name="approval_action" value="reject">
            <div class="panel mb-6">Reject <strong id="rejectProgramName"></strong>? Score sheets will become editable again.</div>
            <div class="input-group full-width"><label>Rejection Notes</label><textarea name="rejection_notes" rows="4" placeholder="Optional notes for the scoring team"></textarea></div>
            <div class="form-actions"><button type="button" class="btn btn-secondary btn-md" data-close="rejectModal">Cancel</button><button class="btn btn-danger btn-md" type="submit">Reject</button></div>
        </form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));
document.querySelectorAll('[data-reject-id]').forEach(btn => btn.addEventListener('click', () => {
    document.getElementById('rejectProgramId').value = btn.dataset.rejectId;
    document.getElementById('rejectProgramName').textContent = btn.dataset.rejectName || 'this program';
    openModal('rejectModal');
}));
</script>
</body>
</html>
