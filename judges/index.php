<?php
declare(strict_types=1);

$pageTitle = 'Judges Marking Portal';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_once __DIR__ . '/../includes/event-guard.php';
require_login();

$_SESSION['active_workspace'] = 'judges';

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)($activeEvent['id'] ?? 0);

function judges_status_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-info',
    };
}

function judges_approval_badge(?string $status): string
{
    return match ((string)$status) {
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'submitted' => 'badge-warning',
        default => 'badge-neutral',
    };
}

$stmtSec = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY sort_order ASC, start_time ASC");
$stmtSec->execute([$activeEventId]);
$scheduleSessions = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$approvalFilter = trim((string)($_GET['approval'] ?? 'all'));
$sessionIdFilter = (int)($_GET['session_id'] ?? 0);

$where = 'WHERE p.event_id = ?';
$params = [$activeEventId];

if ($search !== '') {
    $where .= ' AND (p.title LIKE ? OR p.location LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'scoring', 'completed'], true)) {
    $where .= ' AND p.status = ?';
    $params[] = $statusFilter;
}
if ($approvalFilter !== 'all' && in_array($approvalFilter, ['none', 'submitted', 'rejected', 'approved'], true)) {
    $where .= ' AND p.approval_status = ?';
    $params[] = $approvalFilter;
}
if ($sessionIdFilter > 0) {
    $where .= ' AND p.section_id = ?';
    $params[] = $sessionIdFilter;
} elseif ($sessionIdFilter === -1) {
    $where .= ' AND p.section_id IS NULL';
}

$stmt = $pdo->prepare("
    SELECT
        p.*,
        ct.name AS class_type_name,
        mst.name AS stage_type_name,
        mss.id AS schedule_section_id, mss.name AS schedule_section_name,
        mss.start_time AS schedule_section_start, mss.end_time AS schedule_section_end,
        mss.section_date AS schedule_section_date, mss.sort_order AS schedule_section_sort,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_stage_types mst ON mst.id = p.stage_type_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id AND pe.event_id = p.event_id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id AND ss.program_id = p.id
    {$where}
    GROUP BY p.id
    ORDER BY 
        (p.status = 'scoring') DESC,
        COALESCE(mss.section_date, '9999-12-31') ASC,
        COALESCE(mss.sort_order, 999) ASC,
        COALESCE(mss.start_time, '23:59:59') ASC,
        (p.start_time IS NULL) ASC,
        p.start_time ASC,
        p.title ASC
");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-gavel mr-2" style="color:var(--accent);"></i> Judges Marking Portal</div>
            <div class="page-subtitle">Select a contest program to enter judge criteria marks, rank placements, and submit score sheets</div>
        </div>
        <div>
            <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-eye mr-1"></i> Admin Audit View
            </a>
        </div>
    </div>

    <!-- Filters & Search -->
    <div class="panel mb-4">
        <form method="GET" action="" class="grid grid-4 gap-3 align-end">
            <div>
                <label class="form-label">Search Program</label>
                <input type="text" name="search" class="form-control" placeholder="Title or location..." value="<?= e($search) ?>">
            </div>
            <div>
                <label class="form-label">Schedule Session</label>
                <select name="session_id" class="form-control">
                    <option value="0">All Sessions</option>
                    <option value="-1" <?= $sessionIdFilter === -1 ? 'selected' : '' ?>>Unassigned Session</option>
                    <?php foreach ($scheduleSessions as $sec): ?>
                        <option value="<?= (int)$sec['id'] ?>" <?= $sessionIdFilter === (int)$sec['id'] ? 'selected' : '' ?>>
                            <?= e($sec['name']) ?> (<?= date('h:i A', strtotime($sec['start_time'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Status Filter</label>
                <select name="status" class="form-control">
                    <option value="all">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active / Upcoming</option>
                    <option value="scoring" <?= $statusFilter === 'scoring' ? 'selected' : '' ?>>Scoring In Progress</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary w-full">
                    <i class="fa-solid fa-filter mr-1"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Program Cards / Table -->
    <div class="panel">
        <h3 class="mb-4"><i class="fa-solid fa-clipboard-list mr-2" style="color: var(--accent);"></i> Contest Programs for Marking</h3>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Program & Schedule</th>
                        <th>Division / Class</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th style="text-align: right;">Judges Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($programs)): ?>
                        <tr>
                            <td colspan="6" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);">No matching programs found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($programs as $prog): 
                            $progId = (int)$prog['id'];
                            $totalEntries = (int)$prog['entry_count'];
                            $scoredEntries = (int)$prog['scored_count'];
                            $isComplete = $totalEntries > 0 && $scoredEntries >= $totalEntries;
                        ?>
                            <tr style="<?= $prog['status'] === 'scoring' ? 'background: rgba(16, 185, 129, 0.05);' : '' ?>">
                                <td>
                                    <strong style="color: #fff; font-size: 14.5px;"><?= e($prog['title']) ?></strong>
                                    <?php if (!empty($prog['schedule_section_name'])): ?>
                                        <div style="font-size: 11.5px; color: #34d399; margin-top: 2px;">
                                            <i class="fa-solid fa-clock mr-1"></i><?= e($prog['schedule_section_name']) ?>
                                            <?php if (!empty($prog['start_time'])): ?>
                                                (<?= date('h:i A', strtotime($prog['start_time'])) ?>)
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?= e(admin_class_type_display($prog['class_type_name'] ?? null, (int)($prog['class_type_id'] ?? 0))) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 12px; font-weight: 600; color: #fff;">
                                        <?= $scoredEntries ?> / <?= $totalEntries ?> Scored
                                    </div>
                                    <div style="background: rgba(255,255,255,0.1); height: 5px; border-radius: 999px; overflow: hidden; margin-top: 4px; width: 110px;">
                                        <div style="background: var(--accent); height: 100%; width: <?= $totalEntries > 0 ? round(($scoredEntries / $totalEntries) * 100) : 0 ?>%;"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= judges_status_badge($prog['status']) ?>">
                                        <?= e(ucfirst($prog['status'] ?: 'Active')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= judges_approval_badge($prog['approval_status']) ?>">
                                        <?= e(ucfirst($prog['approval_status'] ?: 'None')) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="flex gap-2" style="justify-content: flex-end;">
                                        <a href="<?= app_url('/judges/program-scores.php?program_id=' . $progId . '&mode=print') ?>" class="btn btn-secondary btn-sm" target="_blank" title="Print Blank Score Sheet">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                        <a href="<?= app_url('/judges/program-scores.php?program_id=' . $progId) ?>" class="btn btn-primary btn-sm">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Enter Marks
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
admin_close_page();
?>
