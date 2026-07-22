<?php
$pageTitle = 'Printer Hub';

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
            <h1><i class="fa-solid fa-print" style="color:#3b82f6;"></i> Printer Space</h1>
            <p>Print Team Roster, ID Cards, Chest Numbers, Export CSVs & Print Updates</p>
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
            <a href="<?= app_url('/admin/id-cards-search.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                    <i class="fa-solid fa-address-card"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Print ID Cards</div>
                    <div class="role-action-subtitle">Search & print official ID badges</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/chest-numbers.php') ?>?mode=print" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(168, 85, 247, 0.2); color: #c084fc;">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Print Chest Numbers</div>
                    <div class="role-action-subtitle">Print chest numbers for participants</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/members.php') ?>?mode=export" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                    <i class="fa-solid fa-file-csv"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Export Team Roster CSV</div>
                    <div class="role-action-subtitle">Download CSV datasets for teams</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/program-scores.php') ?>?mode=print" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                    <i class="fa-solid fa-file-pdf"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Print Score Sheets</div>
                    <div class="role-action-subtitle">Print program results & scorecards</div>
                </div>
            </a>

            <a href="<?= app_url('/admin/logs.php') ?>" class="role-action-btn">
                <div class="role-action-icon" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div class="role-action-info">
                    <div class="role-action-title">Future Updates Queue</div>
                    <div class="role-action-subtitle">Print activity logs & update history</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
