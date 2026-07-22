<?php
$pageTitle = 'Team Manager Hub';

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
            <h1><i class="fa-solid fa-users-gear" style="color:#10b981;"></i> Team Manager Space</h1>
            <p>Access Teams, Team Members, and Chest Numbers Assignment</p>
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
            <a href="<?= app_url('/admin/teams.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                    <i class="fa-solid fa-people-group"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Manage Teams</div>
                    <div class="role-action-subtitle">View, create & manage event teams</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/members.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Team Members</div>
                    <div class="role-action-subtitle">Roster, assignments & info</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/chest-numbers.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(168, 85, 247, 0.2); color: #c084fc;">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Chest Numbers</div>
                    <div class="role-action-subtitle">Assign & verify chest numbers</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
