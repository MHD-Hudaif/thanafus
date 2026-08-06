<?php
$pageTitle = 'Event & Team Manager Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();
$_SESSION['active_workspace'] = 'event-manager';

$activeEvent = admin_require_active_event($GLOBALS['musabaqa_pdo']);
$pdo = $GLOBALS['musabaqa_pdo'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-calendar-gear" style="color:#6366f1;"></i> Event & Team Manager</h1>
            <p>Access Event Settings, Programs, Schedule, Teams, Members, and Chest Numbers</p>
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
        // Fetch stats
        $activeEventId = (int)$activeEvent['id'];
        $totalPrograms = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ?');
        $totalPrograms->execute([$activeEventId]);
        $totalProgramsCount = (int)$totalPrograms->fetchColumn();

        $scheduled = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ? AND start_time IS NOT NULL');
        $scheduled->execute([$activeEventId]);
        $scheduledCount = $scheduled->fetchColumn();

        $stages = $pdo->prepare('SELECT COUNT(DISTINCT stage_type_id) FROM musabaqa_programs WHERE event_id = ? AND stage_type_id IS NOT NULL');
        $stages->execute([$activeEventId]);
        $stagesCount = $stages->fetchColumn();

        // Fetch upcoming programs
        $upcoming = $pdo->prepare('SELECT mp.*, mst.name AS stage_name FROM musabaqa_programs mp LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id WHERE mp.event_id = ? ORDER BY mp.start_time IS NULL ASC, mp.start_time ASC, mp.id ASC LIMIT 4');
        $upcoming->execute([$activeEventId]);
        $upcomingPrograms = $upcoming->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <div class="dashboard-layout-grid">
            <div class="dashboard-main-col">
                <!-- Stats Row -->
                <div class="dashboard-stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-list-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$totalProgramsCount ?></div>
                        <div class="stat-label">Total Programs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-calendar-days" style="color: #fde047;"></i></div>
                        <div class="stat-value"><?= (int)$scheduledCount ?></div>
                        <div class="stat-label">Scheduled Programs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-location-dot" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$stagesCount ?></div>
                        <div class="stat-label">Total Venues / Stages</div>
                    </div>
                </div>

                <!-- Upcoming Programs Panel -->
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-clock-rotate-left mr-2" style="color: var(--accent);"></i> Upcoming Programs</h3>
                    <div class="dashboard-list">
                        <?php if (empty($upcomingPrograms)): ?>
                            <div class="empty-state-row" style="text-align: center; padding: 20px; color: var(--muted);">No programs configured yet.</div>
                        <?php else: ?>
                            <?php foreach ($upcomingPrograms as $prog): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong style="display: block; font-size: 14px;"><?= e($prog['title']) ?></strong>
                                        <span style="font-size: 12px; color: var(--muted);"><i class="fa-solid fa-location-dot mr-1"></i> Stage: <?= e($prog['stage_name'] ?: ($prog['location'] ?: 'TBD')) ?></span>
                                    </div>
                                    <div style="text-align: right;">
                                        <span class="badge badge-info"><?= $prog['start_time'] ? date('h:i A', strtotime($prog['start_time'])) : 'TBD' ?></span>
                                        <span style="display: block; font-size: 11px; color: var(--muted); margin-top: 4px;"><?= e(ucfirst($prog['type'] ?? 'single')) ?></span>
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
                        <a href="<?= app_url('/admin/event-manager/progress.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(52, 211, 153, 0.15); color: #34d399;">
                                <i class="fa-solid fa-square-poll-vertical"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Marks & Progress</div>
                                <div class="sidebar-action-subtitle">Individual student judge marks</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/analytics.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Real-Time Analytics</div>
                                <div class="sidebar-action-subtitle">Standings & insights</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/settings.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-sliders"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Event Settings</div>
                                <div class="sidebar-action-subtitle">Configure theme & params</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-list-check"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Manage Programs</div>
                                <div class="sidebar-action-subtitle">Organize event categories</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Schedule Sessions</div>
                                <div class="sidebar-action-subtitle">Morning, Evening & Night sessions</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/schedule.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-calendar-days"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Schedule Management</div>
                                <div class="sidebar-action-subtitle">Timings & stages</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/teams.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(52, 211, 153, 0.15); color: #34d399;">
                                <i class="fa-solid fa-people-group"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Teams Management</div>
                                <div class="sidebar-action-subtitle">Manage teams & colors</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/members.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(96, 165, 250, 0.15); color: #60a5fa;">
                                <i class="fa-solid fa-user-plus"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Team Members</div>
                                <div class="sidebar-action-subtitle">Roster & chest assignment</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/event-manager/chest-numbers.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(251, 191, 36, 0.15); color: #fbbf24;">
                                <i class="fa-solid fa-id-badge"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Chest Numbers</div>
                                <div class="sidebar-action-subtitle">Badges & numbering</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
