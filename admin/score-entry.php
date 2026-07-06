<?php
$pageTitle = 'Score Entry';

require_once __DIR__ . '/../includes/admin-helpers.php';
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

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$approvalFilter = trim((string)($_GET['approval'] ?? 'all'));
$classFilter = trim((string)($_GET['class'] ?? 'all'));

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
[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'p');
$where .= $classSql;
array_push($params, ...$classParams);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        ct.name AS class_type_name,
        COUNT(DISTINCT pe.id) AS entry_count,
        COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count,
        COALESCE(category_data.category_count, 0) AS category_count,
        COALESCE(category_data.category_total, 0) AS category_total
    FROM musabaqa_programs p
    LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
    LEFT JOIN (
        SELECT program_id, COUNT(*) AS category_count, SUM(max_marks) AS category_total
        FROM musabaqa_program_categories
        GROUP BY program_id
    ) category_data ON category_data.program_id = p.id
    {$where}
    GROUP BY p.id
    ORDER BY COALESCE(p.start_time, '9999-12-31') ASC, p.title ASC
");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Score Entry</div>
            <div class="page-subtitle">Select a program, then score all entries together</div>
        </div>
        <a href="<?= app_url('/admin/programs.php') ?>" class="btn btn-secondary btn-md">
            <i class="fa-solid fa-microphone-lines"></i> Programs
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
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
                <label>Class</label>
                <select name="class">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Program title or location">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($search !== '' || $statusFilter !== 'all' || $approvalFilter !== 'all' || $classFilter !== 'all'): ?>
                    <a href="<?= app_url('/admin/score-entry.php') ?>" class="btn btn-secondary btn-md">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$programs): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-clipboard-list"></i></div><div class="empty-title">No Programs Found</div><div class="empty-subtitle">Create programs and entries before scoring.</div></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Class</th>
                        <th>Entries</th>
                        <th>Scored</th>
                        <th>Categories</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                        <?php
                        $entryCount = (int)$program['entry_count'];
                        $scoredCount = (int)$program['scored_count'];
                        $categoryTotal = (float)$program['category_total'];
                        $categoryValid = (int)$program['category_count'] > 0 && abs($categoryTotal - 100.0) <= 0.01;
                        ?>
                        <tr>
                            <td><strong><?= e($program['title']) ?></strong><div class="muted"><?= e($program['location'] ?: '-') ?></div></td>
                            <td>
                                <?php $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? ''); ?>
                                <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                    <?= e(admin_class_type_display($program['class_type_name'] ?? null, (int)($program['class_type_id'] ?? 0))) ?>
                                </span>
                            </td>
                            <td><?= $entryCount ?></td>
                            <td><?= $scoredCount ?> / <?= $entryCount ?></td>
                            <td>
                                <span class="badge <?= $categoryValid ? 'badge-success' : 'badge-danger' ?>">
                                    <?= (int)$program['category_count'] ?> · <?= number_format($categoryTotal, 2) ?>
                                </span>
                            </td>
                            <td><span class="badge <?= score_entry_status_badge($program['status']) ?>"><?= e(ucfirst((string)$program['status'])) ?></span></td>
                            <td><span class="badge <?= score_entry_approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span></td>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <a class="btn btn-success btn-sm" href="<?= app_url('/admin/program-scores.php') ?>?program_id=<?= (int)$program['id'] ?>">
                                        <i class="fa-solid fa-pen-to-square"></i> Score Entry
                                    </a>
                                    <a class="btn btn-secondary btn-sm" href="<?= app_url('/admin/entries.php') ?>?program=<?= (int)$program['id'] ?>">
                                        <i class="fa-solid fa-list-check"></i> Entries
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_close_page(); ?>
