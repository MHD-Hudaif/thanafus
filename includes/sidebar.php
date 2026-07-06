<?php

/*
|--------------------------------------------------------------------------
| ACTIVE CONTEXT
|--------------------------------------------------------------------------
*/

$activeEventId = $_SESSION['active_event_id'] ?? null;
$activeTeamId  = $_SESSION['active_team_id'] ?? null;

/*
|--------------------------------------------------------------------------
| ACTIVE PAGE DETECTION
|--------------------------------------------------------------------------
*/

$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!function_exists('admin_sidebar_is_active')) {
function admin_sidebar_is_active($path) {
    global $currentPath;
    return str_contains($currentPath, $path) ? 'active' : '';
}
}

/* Skip sidebar rendering for AJAX requests */
if (!empty($isAjaxRequest)) return;
?>

<div class="sidebar">

    <!-- =====================================================
    TOP
    ====================================================== -->

    <div class="sidebar-top">

        <!-- LOGO -->
        <div class="sidebar-logo-wrap">
            <img src="<?= asset_url('images/thanafus-logo.png') ?>" 
                 class="sidebar-logo" alt="Thanafus Logo">
        </div>

        <!-- SUBTITLE -->
        <div class="sidebar-subtitle">
            DIGITAL MUSABAQA SYSTEM
        </div>

        <!-- MENU -->
        <div class="sidebar-menu">

            <!-- DASHBOARD -->
            <a href="<?= app_url('/admin/dashboard') ?>" 
               class="sidebar-link <?= admin_sidebar_is_active('/admin/dashboard') ?>">
                <i class="fa-solid fa-table-columns"></i>
                <span>Dashboard</span>
            </a>

            <!-- EVENTS -->
            <a href="<?= app_url('/admin/events') ?>" 
               class="sidebar-link <?= admin_sidebar_is_active('/admin/events') ?>">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Events</span>
            </a>

            <!-- =====================================================
            EVENT ACTIVE → SHOW FULL MENU
            ====================================================== -->

            <?php if ($activeEventId): ?>

                <!-- TEAMS HUB -->
                <a href="<?= app_url('/admin/teams') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/teams') ?>">
                    <i class="fa-solid fa-people-group"></i>
                    <span>Teams</span>
                </a>

                <a href="<?= app_url('/admin/chest-numbers') ?>"
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/chest-numbers') ?>">
                    <i class="fa-solid fa-hashtag"></i>
                    <span>Chest Numbers</span>
                </a>

                <a href="<?= app_url('/admin/id-cards') ?>"
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/id-cards') ?>">
                    <i class="fa-solid fa-id-card"></i>
                    <span>ID Cards</span>
                </a>

                <!-- PROGRAMS -->
                <a href="<?= app_url('/admin/programs') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/programs') ?>">
                    <i class="fa-solid fa-microphone-lines"></i>
                    <span>Programs</span>
                </a>

                <!-- SCHEDULE -->
                <a href="<?= app_url('/admin/schedule') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/schedule') ?>">
                    <i class="fa-solid fa-clock"></i>
                    <span>Schedule</span>
                </a>

                <!-- ENTRIES -->
                <a href="<?= app_url('/admin/entries') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/entries') ?>">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Entries</span>
                </a>

                <!-- SCORES -->
                <a href="<?= app_url('/admin/score-entry') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/score-entry') ?>">
                    <i class="fa-solid fa-pen"></i>
                    <span>Scores</span>
                </a>

                <!-- SCORE APPROVAL -->
                <a href="<?= app_url('/admin/score-approval') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/score-approval') ?>">
                    <i class="fa-solid fa-check"></i>
                    <span>Approval</span>
                </a>

                <!-- TV CONTROL -->
                <a href="<?= app_url('/tv/dashboard') ?>" 
                   class="sidebar-link <?= admin_sidebar_is_active('/tv') ?>">
                    <i class="fa-solid fa-tv"></i>
                    <span>TV Control</span>
                </a>
                <a href="<?= app_url('/admin/logs') ?>"
   class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'logs.php' ? 'active' : '' ?>">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <span>Activity Logs</span>
</a>

            <?php endif; ?>

        </div>

    </div>

    <!-- =====================================================
    BOTTOM
    ====================================================== -->

    <div class="sidebar-bottom">

        <!-- USER -->
        <div class="sidebar-user">
            <div class="sidebar-user-image">
                <img src="<?=
                    !empty($user['profile_photo'])
                        ? avatar_url($user['profile_photo'])
                        : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username']) . '&background=0d1420&color=14b8a6&bold=true'
                ?>" alt="Profile">
            </div>

            <div class="sidebar-user-info">
                <div class="sidebar-user-name">
                    <?= e($user['full_name'] ?? $user['username']) ?>
                </div>
                <div class="sidebar-user-role">
                    <?= e(implode(', ', $user['role_names'] ?? [])) ?>
                </div>
            </div>
        </div>

        <!-- BACK TO HOME -->
        <a href="<?= app_url('/home') ?>" class="home-btn" data-ajax-ignore>
            <i class="fa-solid fa-arrow-left"></i>
            Back to Home
        </a>

        <!-- LOGOUT -->
        <a href="<?= app_url('/auth/logout') ?>" class="logout-btn" data-ajax-ignore>
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>

    </div>

</div>
