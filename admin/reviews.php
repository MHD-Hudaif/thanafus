<?php
$pageTitle = 'Visitor Feedback & Reviews';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEventId = (int)($_SESSION['active_event_id'] ?? 0);

// POST Actions (Delete, Archive, Approve)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/reviews.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $reviewId = (int)($_POST['review_id'] ?? 0);

    try {
        if ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM musabaqa_reviews WHERE id = ?');
            $stmt->execute([$reviewId]);
            admin_flash('success', 'Feedback entry deleted successfully.');
        } elseif ($action === 'status') {
            $status = (string)($_POST['status'] ?? 'approved');
            if (in_array($status, ['approved', 'archived', 'pending'], true)) {
                $stmt = $pdo->prepare('UPDATE musabaqa_reviews SET status = ? WHERE id = ?');
                $stmt->execute([$status, $reviewId]);
                admin_flash('success', 'Feedback status updated to ' . ucfirst($status) . '.');
            }
        }
    } catch (Throwable $e) {
        admin_flash('error', 'Action failed: ' . $e->getMessage());
    }

    admin_redirect('/admin/reviews.php');
}

$ratingFilter = (int)($_GET['rating'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

$where = [];
$params = [];

if ($ratingFilter >= 1 && $ratingFilter <= 5) {
    $where[] = 'rating = ?';
    $params[] = $ratingFilter;
}

if ($search !== '') {
    $where[] = '(comment LIKE ? OR name LIKE ? OR ip_address LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Stats
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_count,
        COALESCE(AVG(rating), 0) AS avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS one_star
    FROM musabaqa_reviews
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Pagination
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_reviews {$whereSql}");
$countStmt->execute($params);
$totalReviews = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT *
    FROM musabaqa_reviews
    {$whereSql}
    ORDER BY created_at DESC, id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = admin_take_flash();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Visitor Feedback & Reviews</div>
            <div class="page-subtitle">Review feedback submitted by festival visitors</div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="panel">
            <div class="page-subtitle">Total Feedback</div>
            <div class="dashboard-heading mt-2"><?= number_format((int)($stats['total_count'] ?? 0)) ?></div>
        </div>
        <div class="panel">
            <div class="page-subtitle">Average Rating</div>
            <div class="dashboard-heading mt-2 text-warning"><?= number_format((float)($stats['avg_rating'] ?? 0), 1) ?> ★</div>
        </div>
        <div class="panel">
            <div class="page-subtitle">5-Star Ratings</div>
            <div class="dashboard-heading mt-2 text-success"><?= number_format((int)($stats['five_star'] ?? 0)) ?></div>
        </div>
        <div class="panel">
            <div class="page-subtitle">1-Star Ratings</div>
            <div class="dashboard-heading mt-2 text-danger"><?= number_format((int)($stats['one_star'] ?? 0)) ?></div>
        </div>
    </div>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group">
                <label>Filter Rating</label>
                <select name="rating" onchange="this.form.submit()">
                    <option value="0">All Ratings</option>
                    <?php for ($r = 5; $r >= 1; $r--): ?>
                        <option value="<?= $r ?>" <?= $ratingFilter === $r ? 'selected' : '' ?>><?= $r ?> Stars</option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Search Feedback</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search comment, name or IP">
            </div>
            <div class="form-actions full-width flex justify-start gap-3">
                <button type="submit" class="btn btn-secondary btn-md"><i class="fa-solid fa-filter"></i> Filter</button>
                <?php if ($ratingFilter > 0 || $search !== ''): ?>
                    <a href="<?= app_url('/admin/reviews.php') ?>" class="btn btn-secondary btn-md">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 130px;">Date & Time</th>
                        <th style="width: 110px;">Rating</th>
                        <th>Comment</th>
                        <th style="width: 140px;">Name</th>
                        <th style="width: 130px;">IP Address</th>
                        <th style="width: 140px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$reviews): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--muted-2);">
                                No visitor reviews match the current criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $rev): ?>
                            <tr>
                                <td>
                                    <span class="block text-sm font-semibold"><?= date('d M Y', strtotime($rev['created_at'])) ?></span>
                                    <span class="block text-xs text-muted"><?= date('h:i A', strtotime($rev['created_at'])) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-warning" style="font-weight: 700;">
                                        <?= (int)$rev['rating'] ?> ★
                                    </span>
                                </td>
                                <td>
                                    <p style="margin: 0; white-space: pre-wrap; line-height: 1.4; color: var(--text-primary);"><?= e($rev['comment']) ?></p>
                                </td>
                                <td>
                                    <strong><?= e($rev['name'] ?: 'Anonymous') ?></strong>
                                </td>
                                <td>
                                    <code class="text-xs"><?= e($rev['ip_address'] ?: '-') ?></code>
                                </td>
                                <td style="text-align: center;">
                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Delete this feedback entry?');">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="review_id" value="<?= (int)$rev['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalReviews > $perPage): ?>
            <div class="mt-4">
                <?= admin_render_pagination_html($page, $perPage, $totalReviews) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php admin_close_page(); ?>
