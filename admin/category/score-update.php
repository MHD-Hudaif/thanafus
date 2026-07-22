<?php
$pageTitle = 'Score Update Agent Hub';

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
            <h1><i class="fa-solid fa-square-check" style="color:#10b981;"></i> Score Update Agent Space</h1>
            <p>Approve submitted scores and update team total standings</p>
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
            <a href="<?= app_url('/admin/score-approval.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Approve & Publish Scores</div>
                    <div class="role-action-subtitle">Review pending submissions & update totals</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/reviews.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Audit Score Reviews</div>
                    <div class="role-action-subtitle">Inspect score verifications & team points</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
