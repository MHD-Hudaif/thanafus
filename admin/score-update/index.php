<?php
$pageTitle = 'Score Update Agent Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();
$_SESSION['active_workspace'] = 'score-update';

$activeEvent = admin_require_active_event($GLOBALS['musabaqa_pdo']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-square-check" style="color:#10b981;"></i> Score Update Agent Space</h1>
            <p>Approve submitted scores and update team total standings</p>
        </div>
        <?php if (is_admin()): ?>
        <div>
            <a href="<?= app_url('/admin/index.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-calendar-days mr-1"></i> All Events
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: 
        $activeEventId = (int)$activeEvent['id'];
        $pdo = $GLOBALS['musabaqa_pdo'];

        // Stats
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_scores WHERE event_id = ?');
        $stmt->execute([$activeEventId]);
        $totalScoresCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_scores WHERE event_id = ? AND status = "pending"');
        $stmt->execute([$activeEventId]);
        $pendingCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_scores WHERE event_id = ? AND status = "approved"');
        $stmt->execute([$activeEventId]);
        $approvedCount = $stmt->fetchColumn();

        // Fetch pending scores
        $stmt = $pdo->prepare('SELECT ms.*, p.title AS program_name FROM musabaqa_scores ms JOIN musabaqa_programs p ON ms.program_id = p.id WHERE ms.event_id = ? AND ms.status = "pending" ORDER BY ms.id DESC LIMIT 4');
        $stmt->execute([$activeEventId]);
        $pendingScores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <div class="dashboard-layout-grid">
            <div class="dashboard-main-col">
                <!-- Stats Grid -->
                <div class="dashboard-stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-list-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$totalScoresCount ?></div>
                        <div class="stat-label">Entered Scores</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-circle-exclamation" style="color: #fde047;"></i></div>
                        <div class="stat-value"><?= (int)$pendingCount ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-circle-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$approvedCount ?></div>
                        <div class="stat-label">Approved Scores</div>
                    </div>
                </div>

                <!-- Pending Approvals Panel -->
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-clock-rotate-left mr-2" style="color: var(--accent);"></i> Pending Score Approvals</h3>
                    <div class="dashboard-list">
                        <?php if (empty($pendingScores)): ?>
                            <div class="empty-state-row" style="text-align: center; padding: 20px; color: var(--muted);">No scores pending approval right now. All caught up!</div>
                        <?php else: ?>
                            <?php foreach ($pendingScores as $score): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong style="display: block; font-size: 14px;"><?= e($score['program_name']) ?></strong>
                                        <span style="font-size: 11.5px; color: var(--muted);"><i class="fa-solid fa-user-tie mr-1"></i> Judge: <?= e($score['judge_name']) ?></span>
                                    </div>
                                    <div style="text-align: right; display: flex; align-items: center; gap: 12px;">
                                        <strong style="font-size: 16px; color: var(--accent);"><?= e($score['total_mark']) ?> pts</strong>
                                        <a href="<?= app_url('/admin/score-update/score-approval.php') ?>" class="btn btn-secondary btn-sm">Review</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dashboard-sidebar-col">
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-compass mr-2" style="color: var(--accent);"></i> Quick Navigation</h3>
                    <div class="dashboard-list">
                        <a href="<?= app_url('/admin/score-update/score-approval.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Approve & Publish</div>
                                <div class="sidebar-action-subtitle">Review score submissions</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/score-update/reviews.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-magnifying-glass-chart"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Audit Score Reviews</div>
                                <div class="sidebar-action-subtitle">Inspect score verifications</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
