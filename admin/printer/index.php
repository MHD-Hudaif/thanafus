<?php
$pageTitle = 'Printer Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();
$_SESSION['active_workspace'] = 'printer';

$activeEvent = get_active_musabaqa();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-print" style="color:#3b82f6;"></i> Printer Space</h1>
            <p>Print Team Roster, ID Cards, Chest Numbers, Export CSVs & Print Updates</p>
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
        $dashboardPdo = $GLOBALS['dashboard_pdo'];

        // Stats
        $stmt = $dashboardPdo->query('SELECT COUNT(*) FROM students');
        $studentsCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_teams WHERE event_id = ?');
        $stmt->execute([$activeEventId]);
        $teamsCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ?');
        $stmt->execute([$activeEventId]);
        $programsCount = $stmt->fetchColumn();

        // Fetch recent logs
        $stmt = $pdo->query('SELECT * FROM musabaqa_activity_logs ORDER BY created_at DESC, id DESC LIMIT 4');
        $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <div class="dashboard-layout-grid">
            <div class="dashboard-main-col">
                <!-- Stats Grid -->
                <div class="dashboard-stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-graduation-cap" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$studentsCount ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-people-group" style="color: #fde047;"></i></div>
                        <div class="stat-value"><?= (int)$teamsCount ?></div>
                        <div class="stat-label">Total Teams</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-list-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$programsCount ?></div>
                        <div class="stat-label">Event Programs</div>
                    </div>
                </div>

                <!-- Recent Activity Panel -->
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-clock-rotate-left mr-2" style="color: var(--accent);"></i> Recent Print Activity Logs</h3>
                    <div class="dashboard-list">
                        <?php if (empty($recentLogs)): ?>
                            <div class="empty-state-row" style="text-align: center; padding: 20px; color: var(--muted);">No logs recorded yet.</div>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong style="display: block; font-size: 13.5px;"><?= e($log['action_type']) ?></strong>
                                        <span style="font-size: 11.5px; color: var(--muted);"><?= e($log['description']) ?></span>
                                    </div>
                                    <div style="text-align: right;">
                                        <span class="badge badge-neutral" style="font-size: 10px;"><?= date('h:i A', strtotime($log['created_at'])) ?></span>
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
                        <a href="<?= app_url('/admin/printer/judge-sheet.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                                <i class="fa-solid fa-clipboard-check"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Judge Chest Number Sheet</div>
                                <div class="sidebar-action-subtitle">Dynamic 2-layout judge score sheet</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/score-sheets.php') ?>?print_type=scores" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-file-invoice"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Judges Score Sheets</div>
                                <div class="sidebar-action-subtitle">Batch print sheets for judges</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/mc-sheets.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8;">
                                <i class="fa-solid fa-microphone"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Emcee Stage Sheets</div>
                                <div class="sidebar-action-subtitle">Batch print MC stage sheets</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/id-card-designer.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">ID Card Designer</div>
                                <div class="sidebar-action-subtitle">Upload raw cards & customize positions</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/id-cards-search.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-address-card"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Print ID Cards</div>
                                <div class="sidebar-action-subtitle">Search & print badges</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/chest-numbers.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-id-badge"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Print Chest Numbers</div>
                                <div class="sidebar-action-subtitle">Bulk print chest slips</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/members-export.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-file-csv"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Export Team Roster</div>
                                <div class="sidebar-action-subtitle">Download CSV datasets</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/printer/logs.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">All System Logs</div>
                                <div class="sidebar-action-subtitle">View detailed activity feed</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
