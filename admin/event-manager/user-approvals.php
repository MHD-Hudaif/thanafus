<?php
$pageTitle = 'Pending User Approvals';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['dashboard_pdo'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/event-manager/user-approvals.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);

    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$userId]);
            admin_flash('success', 'User approved successfully.');
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND status = 'pending'");
            $stmt->execute([$userId]);
            admin_flash('success', 'User registration request rejected.');
        }
    } catch (Throwable $e) {
        admin_flash('error', 'Action failed: ' . $e->getMessage());
    }

    admin_redirect('/admin/event-manager/user-approvals.php');
}

$flash = admin_take_flash();

// Fetch pending users
$stmt = $pdo->prepare("SELECT * FROM users WHERE status = 'pending' ORDER BY id DESC");
$stmt->execute();
$pendingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-user-check" style="color:#10b981;"></i> Pending User Approvals</h1>
            <p>Approve or reject pending registration requests for the mobile application</p>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> mb-4">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mr-2"></i>
            <span><?= e($flash['message']) ?></span>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fa-solid fa-users-clock mr-2"></i> Pending Requests (<?= count($pendingUsers) ?>)</h3>
        </div>

        <?php if (empty($pendingUsers)): ?>
            <div class="empty-state-row" style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="fa-solid fa-user-clock fa-3x mb-3" style="color: var(--border-color); display: block; margin: 0 auto 10px;"></i>
                <p style="font-size: 16px; font-weight: 500; margin: 0;">No pending approval requests at the moment.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Date Requested</th>
                            <th style="text-align: right; width: 220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingUsers as $user): ?>
                            <tr>
                                <td>
                                    <strong><?= e($user['full_name'] ?: 'N/A') ?></strong>
                                    <small style="display: block; color: var(--text-muted);">Username: <?= e($user['username']) ?></small>
                                </td>
                                <td><?= e($user['phone']) ?></td>
                                <td><?= e($user['email'] ?: 'N/A') ?></td>
                                <td><?= date('d M Y, h:i A', strtotime($user['created_at'])) ?></td>
                                <td style="text-align: right;">
                                    <form method="POST" style="display: inline-block; margin-right: 5px;">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fa-solid fa-check mr-1"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to reject this request?');">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fa-solid fa-xmark mr-1"></i> Reject
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php admin_close_page(); ?>
