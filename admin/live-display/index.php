<?php
$pageTitle = 'Live Display Manager Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();
$_SESSION['active_workspace'] = 'live-display';

$activeEvent = admin_require_active_event($GLOBALS['musabaqa_pdo']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-tv" style="color:#ec4899;"></i> Live Display Space</h1>
            <p>Control TV Scoreboards, Live Presentation Feeds, Rankings & Announcements</p>
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
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_teams WHERE event_id = ?');
        $stmt->execute([$activeEventId]);
        $teamsCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ?');
        $stmt->execute([$activeEventId]);
        $programsCount = $stmt->fetchColumn();
    ?>
        <div class="dashboard-layout-grid">
            <div class="dashboard-main-col">
                <!-- Stats Grid -->
                <div class="dashboard-stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-people-group" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$teamsCount ?></div>
                        <div class="stat-label">Total Teams</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-list-check" style="color: #fde047;"></i></div>
                        <div class="stat-value"><?= (int)$programsCount ?></div>
                        <div class="stat-label">Event Programs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-circle-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value">Active</div>
                        <div class="stat-label">Event Broadcast</div>
                    </div>
                </div>

                <!-- Display Info Panel -->
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-tower-broadcast mr-2" style="color: var(--accent);"></i> Live Broadcasting Channels</h3>
                    <div class="dashboard-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong style="display: block; font-size: 14px;">Main Stage TV Scoreboard</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Real-time leaderboards, results ticker, and rankings display</span>
                            </div>
                            <div>
                                <span class="badge badge-success">Live Ready</span>
                            </div>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong style="display: block; font-size: 14px;">Announcements & Rankings Feed</strong>
                                <span style="font-size: 11.5px; color: var(--muted);">Full standings screen shown on projectors and mobile web views</span>
                            </div>
                            <div>
                                <span class="badge badge-success">Live Ready</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-sidebar-col">
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-compass mr-2" style="color: var(--accent);"></i> Quick Navigation</h3>
                    <div class="dashboard-list">
                        <a href="<?= app_url('/tv/dashboard.php') ?>" class="sidebar-action-btn" target="_blank">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-tower-broadcast"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">TV Control Console</div>
                                <div class="sidebar-action-subtitle">Control screen overlay feeds</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/scoreboard.php') ?>" class="sidebar-action-btn" target="_blank">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-display"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Scoreboard Preview</div>
                                <div class="sidebar-action-subtitle">View public standings feed</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
