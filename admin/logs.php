<?php
$pageTitle = 'Activity Logs';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];

$activeEventId = (int)($_SESSION['active_event_id'] ?? 0);

$where = [];
$params = [];

if ($activeEventId > 0) {
    $where[] = 'event_id = ?';
    $params[] = $activeEventId;
}

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $where[] = '(
        action_type LIKE ?
        OR target_table LIKE ?
        OR description LIKE ?
    )';

    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$actionFilter = trim($_GET['action'] ?? '');

if ($actionFilter !== '') {
    $where[] = 'action_type = ?';
    $params[] = $actionFilter;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM musabaqa_activity_logs
    {$whereSql}
");
$countStmt->execute($params);

$totalLogs = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT *
    FROM musabaqa_activity_logs
    {$whereSql}
    ORDER BY created_at DESC
    LIMIT {$perPage}
    OFFSET {$offset}
");

$stmt->execute($params);

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$todayLogs = (int)$pdo
    ->query("
        SELECT COUNT(*)
        FROM musabaqa_activity_logs
        WHERE DATE(created_at)=CURDATE()
    ")
    ->fetchColumn();

$scoreLogs = (int)$pdo
    ->query("
        SELECT COUNT(*)
        FROM musabaqa_activity_logs
        WHERE action_type='score_creation'
    ")
    ->fetchColumn();

$approvalLogs = (int)$pdo
    ->query("
        SELECT COUNT(*)
        FROM musabaqa_activity_logs
        WHERE action_type='approve_program_scores'
    ")
    ->fetchColumn();

function badgeClass(string $action): string
{
    return match ($action) {

        'score_creation' =>
            'badge-success',

        'submit_for_approval' =>
            'badge-warning',

        'approve_program_scores' =>
            'badge-info',

        'leaderboard_update' =>
            'badge-neutral',

        default =>
            'badge-neutral'
    };
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">

```
<div class="topbar">
    <div>
        <div class="page-title">Activity Logs</div>
        <div class="page-subtitle">
            Audit trail of all musabaqa actions
        </div>
    </div>
</div>

<div class="stats-grid mb-6">

    <div class="stat-card">
        <div class="stat-value">
            <?= number_format($totalLogs) ?>
        </div>
        <div class="stat-label">
            Total Logs
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-value">
            <?= number_format($todayLogs) ?>
        </div>
        <div class="stat-label">
            Today
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-value">
            <?= number_format($scoreLogs) ?>
        </div>
        <div class="stat-label">
            Score Actions
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-value">
            <?= number_format($approvalLogs) ?>
        </div>
        <div class="stat-label">
            Approvals
        </div>
    </div>

</div>

<div class="panel mb-6">

    <form method="get" class="form-grid">

        <div class="field">
            <label class="field-label">
                Search Logs
            </label>

            <input
                type="text"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Search description, table or action..."
            >
        </div>

        <div class="field">
            <label class="field-label">
                Action Type
            </label>

            <select name="action">

                <option value="">
                    All Actions
                </option>

                <option value="score_creation">
                    Score Creation
                </option>

                <option value="submit_for_approval">
                    Submit For Approval
                </option>

                <option value="approve_program_scores">
                    Approve Program Scores
                </option>

                <option value="leaderboard_update">
                    Leaderboard Update
                </option>

            </select>

        </div>

        <div class="form-actions">
            <button class="btn btn-success">
                <i class="fa-solid fa-search"></i>
                Search
            </button>
        </div>

    </form>

</div>

<div class="panel">

    <div class="dashboard-heading mb-6">
        Activity Timeline
    </div>

    <?php if (!$logs): ?>

        <div class="empty-state">

            <div class="empty-icon">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>

            <div class="empty-title">
                No Logs Found
            </div>

            <div class="empty-subtitle">
                There are no matching activity records.
            </div>

        </div>

    <?php else: ?>

        <div class="table-wrapper">

            <table>

                <thead>
                <tr>
                    <th>ID</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>Table</th>
                    <th>Target</th>
                    <th>User</th>
                    <th>Date</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($logs as $log): ?>

                    <tr>

                        <td>
                            #<?= (int)$log['id'] ?>
                        </td>

                        <td>
                            <span class="badge <?= badgeClass($log['action_type']) ?>">
                                <?= e($log['action_type']) ?>
                            </span>
                        </td>

                        <td>
                            <?= e($log['description']) ?>
                        </td>

                        <td>
                            <?= e($log['target_table']) ?>
                        </td>

                        <td>
                            <?= e((string)$log['target_id']) ?>
                        </td>

                        <td>
                            #<?= (int)$log['user_id'] ?>
                        </td>

                        <td>
                            <?= date(
                                'd M Y h:i A',
                                strtotime($log['created_at'])
                            ) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php
        $pages = max(
            1,
            ceil($totalLogs / $perPage)
        );
        ?>

        <?php if ($pages > 1): ?>

            <div class="form-actions mt-5">

                <?php for ($i = 1; $i <= $pages; $i++): ?>

                    <a
                        href="?page=<?= $i ?>"
                        class="btn <?= $i === $page ? 'btn-success' : 'btn-secondary' ?>"
                    >
                        <?= $i ?>
                    </a>

                <?php endfor; ?>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>


</div>

<?php admin_close_page(); ?>

