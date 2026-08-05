<?php
$pageTitle = 'Score Entry';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function score_entry_status_badge(?string $status): string
{
    return match ((string)$status) {
        'completed' => 'badge-success',
        'scoring' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function score_entry_approval_badge(?string $status): string
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

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$approvalFilter = trim((string)($_GET['approval'] ?? 'all'));
$classFilter = trim((string)($_GET['class'] ?? 'all'));
$sessionIdFilter = (int)($_GET['session_id'] ?? 0);
$programGroupBy = trim((string)($_GET['program_group_by'] ?? 'session'));

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

[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'p');
$where .= $classSql;
array_push($params, ...$classParams);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        ct.name AS class_type_name,
        mss.id AS schedule_section_id, mss.name AS schedule_section_name,
        mss.start_time AS schedule_section_start, mss.end_time AS schedule_section_end,
        mss.section_date AS schedule_section_date, mss.sort_order AS schedule_section_sort,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count,
        COALESCE(category_data.category_count, 0) AS category_count,
        COALESCE(category_data.category_total, 0) AS category_total
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
    LEFT JOIN (
        SELECT program_id, COUNT(*) AS category_count, SUM(max_marks) AS category_total
        FROM musabaqa_program_categories
        GROUP BY program_id
    ) category_data ON category_data.program_id = p.id
    {$where}
    GROUP BY p.id, ct.id, mss.id, category_data.category_count, category_data.category_total
    ORDER BY 
        COALESCE(mss.section_date, '9999-12-31') ASC,
        COALESCE(mss.sort_order, 999) ASC,
        COALESCE(mss.start_time, '23:59:59') ASC,
        (p.start_time IS NULL) ASC,
        p.start_time ASC,
        p.title ASC
");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-calculator mr-2" style="color:var(--accent);"></i> Score Entry Workspace</div>
            <div class="page-subtitle">Select a program to record participant judge scores, grouped by Schedule Sessions or Class Divisions</div>
        </div>
        <div class="flex gap-2">
            <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-clock mr-1"></i> Sessions
            </a>
            <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-microphone-lines mr-1"></i> Programs
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); align-items: flex-end;">
            <input type="hidden" name="program_group_by" value="<?= e($programGroupBy) ?>">

            <div class="input-group">
                <label>Schedule Session</label>
                <select name="session_id" onchange="this.form.submit()">
                    <option value="0">All Sessions</option>
                    <?php foreach ($scheduleSessions as $sec): ?>
                        <?php
                            $timeStr = '';
                            if (!empty($sec['start_time']) && !empty($sec['end_time'])) {
                                $timeStr = ' (' . date('h:i A', strtotime($sec['start_time'])) . ')';
                            }
                        ?>
                        <option value="<?= (int)$sec['id'] ?>" <?= $sessionIdFilter === (int)$sec['id'] ? 'selected' : '' ?>>
                            <?= e($sec['name']) ?><?= $timeStr ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="-1" <?= $sessionIdFilter === -1 ? 'selected' : '' ?>>Unassigned Sessions</option>
                </select>
            </div>

            <div class="input-group">
                <label>Status</label>
                <select name="status">
                    <option value="all">All Status</option>
                    <?php foreach (['active', 'scoring', 'completed'] as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Approval</label>
                <select name="approval">
                    <option value="all">All Approval</option>
                    <?php foreach (['none', 'submitted', 'rejected', 'approved'] as $status): ?>
                        <option value="<?= $status ?>" <?= $approvalFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Class Division</label>
                <select name="class">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program title or location...">
            </div>

            <div class="form-actions" style="grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 6px;">
                <!-- Group By Mode Toggle Pills -->
                <div style="display: flex; align-items: center; background: rgba(255,255,255,0.04); padding: 3px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                    <a href="<?= app_url('/admin/score-entry/score-entry.php?program_group_by=session' . ($sessionIdFilter !== 0 ? '&session_id=' . $sessionIdFilter : '') . ($statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '') . ($approvalFilter !== 'all' ? '&approval=' . urlencode($approvalFilter) : '') . ($classFilter !== 'all' ? '&class=' . urlencode($classFilter) : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $programGroupBy === 'session' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;" title="Group by Schedule Session">
                        <i class="fa-solid fa-clock mr-1"></i> By Schedule Session
                    </a>
                    <a href="<?= app_url('/admin/score-entry/score-entry.php?program_group_by=division' . ($sessionIdFilter !== 0 ? '&session_id=' . $sessionIdFilter : '') . ($statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '') . ($approvalFilter !== 'all' ? '&approval=' . urlencode($approvalFilter) : '') . ($classFilter !== 'all' ? '&class=' . urlencode($classFilter) : '') . ($search !== '' ? '&search=' . urlencode($search) : '')) ?>" class="btn btn-xs <?= $programGroupBy === 'division' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11.5px; padding: 5px 12px; border-radius: 6px;" title="Group by Class Division">
                        <i class="fa-solid fa-layer-group mr-1"></i> By Class Division
                    </a>
                </div>

                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                    <?php if ($search !== '' || $statusFilter !== 'all' || $approvalFilter !== 'all' || $classFilter !== 'all' || $sessionIdFilter !== 0): ?>
                        <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="btn btn-secondary btn-md">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php
    // Compute sequential scoring lock status (prevent future programs from being scored until preceding programs are scored)
    $firstUnscoredProg = null;
    foreach ($programs as &$prog) {
        $entryCount  = (int)$prog['entry_count'];
        $scoredCount = (int)$prog['scored_count'];
        $isCompleted = ($prog['status'] === 'completed') ||
                       in_array($prog['approval_status'], ['submitted', 'approved'], true) ||
                       ($entryCount > 0 && $scoredCount >= $entryCount);

        if ($firstUnscoredProg !== null) {
            $prog['scoring_locked'] = true;
            $prog['blocking_program_title'] = $firstUnscoredProg['title'];
        } else {
            $prog['scoring_locked'] = false;
            $prog['blocking_program_title'] = null;

            if ($entryCount > 0 && !$isCompleted) {
                $firstUnscoredProg = $prog;
            }
        }
    }
    unset($prog);

    // Group Structure 1: By Schedule Session
    $groupedBySession = [];
    foreach ($scheduleSessions as $sec) {
        $secId = (int)$sec['id'];
        $timeRange = '';
        if (!empty($sec['start_time']) && !empty($sec['end_time'])) {
            $timeRange = date('h:i A', strtotime($sec['start_time'])) . ' - ' . date('h:i A', strtotime($sec['end_time']));
        }
        $groupedBySession['session_' . $secId] = [
            'id' => $secId,
            'name' => $sec['name'],
            'time_range' => $timeRange,
            'date' => !empty($sec['section_date']) ? date('M j, Y', strtotime($sec['section_date'])) : '',
            'programs' => []
        ];
    }
    $groupedBySession['unassigned'] = [
        'id' => 0,
        'name' => 'Unassigned Schedule Session',
        'time_range' => '',
        'date' => '',
        'programs' => []
    ];

    foreach ($programs as $prog) {
        $secId = (int)($prog['schedule_section_id'] ?? 0);
        $key = ($secId > 0 && isset($groupedBySession['session_' . $secId])) ? 'session_' . $secId : 'unassigned';
        $groupedBySession[$key]['programs'][] = $prog;
    }

    // Group Structure 2: By Class Division
    $tiers = [
        'senior' => 'Senior',
        'junior' => 'Junior',
        'subjunior' => 'Sub Junior',
        'general' => 'General / Multi-Section'
    ];

    $groupedByDivision = [
        'subjunior' => [],
        'junior' => [],
        'senior' => [],
        'general' => []
    ];

    foreach ($programs as $prog) {
        $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
        $allowedCount = !empty($prog['allowed_sections']) ? count(explode(',', $prog['allowed_sections'])) : 0;
        
        if ($allowedCount > 1 || !$classTier) {
            $groupedByDivision['general'][] = $prog;
        } else {
            $groupedByDivision[$classTier][] = $prog;
        }
    }
    ?>

    <?php if (!$programs): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="empty-title">No Programs Found</div>
            <div class="empty-subtitle">Create programs and entries before scoring.</div>
        </div>
    <?php elseif ($programGroupBy === 'session'): ?>
        <!-- GROUPED BY SCHEDULE SESSION -->
        <?php foreach ($groupedBySession as $secKey => $sessionGroup): ?>
            <?php if (empty($sessionGroup['programs'])) continue; ?>
            <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; overflow: hidden; padding: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px);">
                <div style="background: rgba(255,255,255,0.03); padding: 16px 22px; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <h3 style="font-size: 16px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-clock" style="color: #34d399;"></i>
                        <?= e($sessionGroup['name']) ?>
                        <?php if (!empty($sessionGroup['time_range'])): ?>
                            <span style="font-size: 13px; font-weight: 600; color: var(--muted); margin-left: 4px;">
                                (<?= e($sessionGroup['time_range']) ?>)
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($sessionGroup['date'])): ?>
                            <span class="badge badge-neutral" style="font-size: 11px;">
                                <?= e($sessionGroup['date']) ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <span style="font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.25);">
                        <?= count($sessionGroup['programs']) ?> <?= count($sessionGroup['programs']) === 1 ? 'Program' : 'Programs' ?>
                    </span>
                </div>

                <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0;">
                    <table class="table table-glass">
                        <thead>
                            <tr>
                                <th>Program Title & Time</th>
                                <th>Class</th>
                                <th>Entries</th>
                                <th>Scored</th>
                                <th>Categories</th>
                                <th>Status</th>
                                <th>Approval</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessionGroup['programs'] as $program): ?>
                                <?php
                                $entryCount    = (int)$program['entry_count'];
                                $scoredCount   = (int)$program['scored_count'];
                                $categoryTotal = (float)$program['category_total'];
                                $categoryValid = (int)$program['category_count'] > 0 && abs($categoryTotal - 100.0) <= 0.01;
                                $isLocked      = !empty($program['scoring_locked']);
                                $blockingTitle = $program['blocking_program_title'] ?? '';
                                ?>
                                <tr style="<?= $isLocked ? 'opacity: 0.68;' : '' ?>">
                                    <td>
                                        <strong style="color: #fff; font-size: 14px;"><?= e($program['title']) ?></strong>
                                        <?php if (!empty($program['start_time'])): ?>
                                            <div style="font-size: 11.5px; color: #34d399; font-weight: 700; margin-top: 2px;">
                                                <i class="fa-solid fa-clock mr-1"></i><?= date('h:i A', strtotime($program['start_time'])) ?>
                                                <?php if (!empty($program['end_time'])): ?>
                                                    - <?= date('h:i A', strtotime($program['end_time'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="muted"><?= e($program['location'] ?: '-') ?></div>
                                        <?php endif; ?>

                                        <?php if ($isLocked): ?>
                                            <div style="font-size: 11px; color: #f87171; font-weight: 600; margin-top: 3px;">
                                                <i class="fa-solid fa-lock mr-1"></i> Complete "<?= e($blockingTitle) ?>" first
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? ''); ?>
                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                            <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                                        </span>
                                    </td>
                                    <td><?= $entryCount ?></td>
                                    <td>
                                        <span class="badge <?= $scoredCount === $entryCount && $entryCount > 0 ? 'badge-success' : 'badge-neutral' ?>">
                                            <?= $scoredCount ?> / <?= $entryCount ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $categoryValid ? 'badge-success' : 'badge-danger' ?>">
                                            <?= (int)$program['category_count'] ?> · <?= number_format($categoryTotal, 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isLocked): ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.25);">
                                                <i class="fa-solid fa-lock mr-1"></i> Locked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge <?= score_entry_status_badge($program['status']) ?>"><?= e(ucfirst((string)$program['status'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= score_entry_approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span></td>
                                    <td style="text-align: right;">
                                        <div class="flex gap-2 flex-wrap" style="justify-content: flex-end;">
                                            <?php if ($isLocked): ?>
                                                <button class="btn btn-secondary btn-sm" disabled style="opacity: 0.45; cursor: not-allowed;" title="Complete scoring for &quot;<?= e($blockingTitle) ?>&quot; first">
                                                    <i class="fa-solid fa-lock mr-1"></i> Locked
                                                </button>
                                            <?php else: ?>
                                                <a class="btn btn-success btn-sm" href="<?= app_url('/admin/score-entry/program-scores.php') ?>?program_id=<?= (int)$program['id'] ?>">
                                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Score Entry
                                                </a>
                                            <?php endif; ?>
                                            <a class="btn btn-secondary btn-sm" href="<?= app_url('/admin/registrar/entries.php') ?>?program_id=<?= (int)$program['id'] ?>">
                                                <i class="fa-solid fa-list-check mr-1"></i> Entries
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- GROUPED BY CLASS DIVISION -->
        <?php foreach ($tiers as $tierKey => $tierLabel): ?>
            <?php 
                if ($classFilter !== 'all' && $classFilter !== $tierKey) continue;
                $tierPrograms = $groupedByDivision[$tierKey] ?? []; 
            ?>
            <?php if (!$tierPrograms) continue; ?>

            <div class="panel mb-6" style="border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; overflow: hidden; padding: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px);">
                <div style="background: rgba(255,255,255,0.02); padding: 16px 22px; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 16px; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-layer-group" style="color: #facc15;"></i>
                        <?= e($tierLabel) ?> Division
                    </h3>
                    <span style="font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; background: rgba(99,102,241,0.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.25);">
                        <?= count($tierPrograms) ?> <?= count($tierPrograms) === 1 ? 'Program' : 'Programs' ?>
                    </span>
                </div>

                <div class="table-wrapper" style="margin: 0; border: none; border-radius: 0;">
                    <table class="table table-glass">
                        <thead>
                            <tr>
                                <th>Program Title & Time</th>
                                <th>Schedule Session</th>
                                <th>Class</th>
                                <th>Entries</th>
                                <th>Scored</th>
                                <th>Categories</th>
                                <th>Status</th>
                                <th>Approval</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tierPrograms as $program): ?>
                                <?php
                                $entryCount    = (int)$program['entry_count'];
                                $scoredCount   = (int)$program['scored_count'];
                                $categoryTotal = (float)$program['category_total'];
                                $categoryValid = (int)$program['category_count'] > 0 && abs($categoryTotal - 100.0) <= 0.01;
                                $isLocked      = !empty($program['scoring_locked']);
                                $blockingTitle = $program['blocking_program_title'] ?? '';
                                ?>
                                <tr style="<?= $isLocked ? 'opacity: 0.68;' : '' ?>">
                                    <td>
                                        <strong style="color: #fff; font-size: 14px;"><?= e($program['title']) ?></strong>
                                        <?php if (!empty($program['start_time'])): ?>
                                            <div style="font-size: 11.5px; color: #34d399; font-weight: 700; margin-top: 2px;">
                                                <i class="fa-solid fa-clock mr-1"></i><?= date('h:i A', strtotime($program['start_time'])) ?>
                                                <?php if (!empty($program['end_time'])): ?>
                                                    - <?= date('h:i A', strtotime($program['end_time'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="muted"><?= e($program['location'] ?: '-') ?></div>
                                        <?php endif; ?>

                                        <?php if ($isLocked): ?>
                                            <div style="font-size: 11px; color: #f87171; font-weight: 600; margin-top: 3px;">
                                                <i class="fa-solid fa-lock mr-1"></i> Complete "<?= e($blockingTitle) ?>" first
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($program['schedule_section_name'])): ?>
                                            <span class="badge badge-info" style="font-size: 11px;">
                                                <i class="fa-solid fa-clock mr-1"></i> <?= e($program['schedule_section_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--muted); font-size: 12px;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? ''); ?>
                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                            <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                                        </span>
                                    </td>
                                    <td><?= $entryCount ?></td>
                                    <td>
                                        <span class="badge <?= $scoredCount === $entryCount && $entryCount > 0 ? 'badge-success' : 'badge-neutral' ?>">
                                            <?= $scoredCount ?> / <?= $entryCount ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $categoryValid ? 'badge-success' : 'badge-danger' ?>">
                                            <?= (int)$program['category_count'] ?> · <?= number_format($categoryTotal, 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isLocked): ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.25);">
                                                <i class="fa-solid fa-lock mr-1"></i> Locked
                                            </span>
                                        <?php else: ?>
                                            <span class="badge <?= score_entry_status_badge($program['status']) ?>"><?= e(ucfirst((string)$program['status'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= score_entry_approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span></td>
                                    <td style="text-align: right;">
                                        <div class="flex gap-2 flex-wrap" style="justify-content: flex-end;">
                                            <?php if ($isLocked): ?>
                                                <button class="btn btn-secondary btn-sm" disabled style="opacity: 0.45; cursor: not-allowed;" title="Complete scoring for &quot;<?= e($blockingTitle) ?>&quot; first">
                                                    <i class="fa-solid fa-lock mr-1"></i> Locked
                                                </button>
                                            <?php else: ?>
                                                <a class="btn btn-success btn-sm" href="<?= app_url('/admin/score-entry/program-scores.php') ?>?program_id=<?= (int)$program['id'] ?>">
                                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Score Entry
                                                </a>
                                            <?php endif; ?>
                                            <a class="btn btn-secondary btn-sm" href="<?= app_url('/admin/registrar/entries.php') ?>?program_id=<?= (int)$program['id'] ?>">
                                                <i class="fa-solid fa-list-check mr-1"></i> Entries
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php admin_close_page(); ?>
