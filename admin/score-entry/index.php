<?php
$pageTitle = 'Score Entry Agent Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();
$_SESSION['active_workspace'] = 'score-entry';

$activeEvent = admin_require_active_event($GLOBALS['musabaqa_pdo']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-pen-to-square" style="color:#8b5cf6;"></i> Score Entry Agent Space</h1>
            <p>Enter judge scores per participant and submit score approval requests to Score Updater</p>
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

        // Fetch recent scores
        $stmt = $pdo->prepare('SELECT ms.*, p.title AS program_name FROM musabaqa_scores ms JOIN musabaqa_programs p ON ms.program_id = p.id WHERE ms.event_id = ? ORDER BY ms.id DESC LIMIT 4');
        $stmt->execute([$activeEventId]);
        $recentScores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <div class="dashboard-layout-grid">
            <div class="dashboard-main-col">
                <!-- Stats Grid -->
                <div class="dashboard-stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-calculator" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$totalScoresCount ?></div>
                        <div class="stat-label">Entered Scores</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-circle-pause" style="color: #fde047;"></i></div>
                        <div class="stat-value"><?= (int)$pendingCount ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-circle-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$approvedCount ?></div>
                        <div class="stat-label">Approved Scores</div>
                    </div>
                </div>

                <!-- Recent Scores Panel -->
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-star-half-stroke mr-2" style="color: var(--accent);"></i> Recent Score Submissions</h3>
                    <div class="dashboard-list">
                        <?php if (empty($recentScores)): ?>
                            <div class="empty-state-row" style="text-align: center; padding: 20px; color: var(--muted);">No scores entered yet.</div>
                        <?php else: ?>
                            <?php foreach ($recentScores as $score): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong style="display: block; font-size: 14px;"><?= e($score['program_name']) ?></strong>
                                        <span style="font-size: 11.5px; color: var(--muted);"><i class="fa-solid fa-user-tie mr-1"></i> Judge: <?= e($score['judge_name']) ?></span>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong style="font-size: 16px; color: var(--accent);"><?= e($score['total_mark']) ?> pts</strong>
                                        <span style="display: block; font-size: 10px; margin-top: 4px;" class="badge <?= $score['status'] === 'approved' ? 'badge-success' : 'badge-warning' ?>">
                                            <?= e(ucfirst($score['status'])) ?>
                                        </span>
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
                        <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-calculator"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Judge Score Entry Workspace</div>
                                <div class="sidebar-action-subtitle">Enter judge marks by session</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/score-entry/program-scores.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-file-lines"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Program Score Sheets</div>
                                <div class="sidebar-action-subtitle">Review & print score sheets</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(52, 211, 153, 0.15); color: #34d399;">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Schedule Sessions</div>
                                <div class="sidebar-action-subtitle">Manage session timetables</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
