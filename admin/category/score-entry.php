<?php
$pageTitle = 'Score Entry Agent Hub';

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
            <h1><i class="fa-solid fa-pen-to-square" style="color:#8b5cf6;"></i> Score Entry Agent Space</h1>
            <p>Enter judge scores per participant and submit score approval requests to Score Updater</p>
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
            <a href="<?= app_url('/admin/score-entry.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(139, 92, 246, 0.2); color: #a78bfa;">
                    <i class="fa-solid fa-calculator"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Judge Score Entry Form</div>
                    <div class="role-action-subtitle">Enter judge marks & send score for approval</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/program-scores.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Program Score Sheets</div>
                    <div class="role-action-subtitle">Review entered score draft sheets</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
