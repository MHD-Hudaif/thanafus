<?php
$pageTitle = 'Live Display Manager Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$activeEvent = get_active_musabaqa();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-tv" style="color:#ec4899;"></i> Live Display Manager Space</h1>
            <p>Control TV Scoreboards, Live Presentation Feeds, Rankings & Announcements</p>
        </div>
        <div>
            <a href="<?= app_url('/admin/dashboard.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-arrow-left"></i> Back to Hub
            </a>
        </div>
    </div>

    <?php if (!$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: ?>
        <div class="role-buttons-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
            <a href="<?= app_url('/tv/dashboard.php') ?>" class="role-action-btn" target="_blank">
                <div class="role-action-icon" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                    <i class="fa-solid fa-tower-broadcast"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">TV Control Dashboard</div>
                    <div class="role-action-subtitle">Control screen overlays, modes & live feeds</div>
                </div>
            </a>

            <a href="<?= app_url('/scoreboard.php') ?>" class="role-action-btn" target="_blank">
                <div class="role-action-icon" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                    <i class="fa-solid fa-display"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Public Scoreboard Preview</div>
                    <div class="role-action-subtitle">Preview the live public standings feed</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
