<?php
$pageTitle = 'Event Manager Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-calendar-gear" style="color:#6366f1;"></i> Event Manager Space</h1>
            <p>Access Event Settings, Manage Programs, Program Settings, and Event Schedule</p>
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
            <a href="<?= app_url('/admin/settings.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                    <i class="fa-solid fa-sliders"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Event Settings</div>
                    <div class="role-action-subtitle">Configure theme, mode & parameters</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/programs.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Manage Programs</div>
                    <div class="role-action-subtitle">Add, edit & organize programs</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/sections.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Program Settings</div>
                    <div class="role-action-subtitle">Categories, criteria & rules</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/schedule.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Schedule Management</div>
                    <div class="role-action-subtitle">Timings, stages & sequence</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
