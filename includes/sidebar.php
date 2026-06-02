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
?>

<div class="sidebar">

    <!-- =====================================================
    TOP
    ====================================================== -->

    <div class="sidebar-top">

        <!-- LOGO -->
        <div class="sidebar-logo-wrap">
            <img src="<?= APP_URL ?>/assets/images/thanafus-logo.png" 
                 class="sidebar-logo" alt="Thanafus Logo">
        </div>

        <!-- SUBTITLE -->
        <div class="sidebar-subtitle">
            DIGITAL MUSABAQA SYSTEM
        </div>

        <!-- MENU -->
        <div class="sidebar-menu">

            <!-- DASHBOARD -->
            <a href="<?= APP_URL ?>/admin/dashboard" 
               class="sidebar-link <?= admin_sidebar_is_active('/admin/dashboard') ?>">
                <i class="fa-solid fa-table-columns"></i>
                <span>Dashboard</span>
            </a>

            <!-- EVENTS -->
            <a href="<?= APP_URL ?>/admin/events" 
               class="sidebar-link <?= admin_sidebar_is_active('/admin/events') ?>">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Events</span>
            </a>

            <!-- =====================================================
            EVENT ACTIVE → SHOW FULL MENU
            ====================================================== -->

            <?php if ($activeEventId): ?>

                <!-- TEAMS HUB -->
                <a href="<?= APP_URL ?>/admin/teams" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/teams') ?>">
                    <i class="fa-solid fa-people-group"></i>
                    <span>Teams</span>
                </a>

                <!-- PROGRAMS -->
                <a href="<?= APP_URL ?>/admin/programs" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/programs') ?>">
                    <i class="fa-solid fa-microphone-lines"></i>
                    <span>Programs</span>
                </a>

                <!-- SCHEDULE -->
                <a href="<?= APP_URL ?>/admin/schedule" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/schedule') ?>">
                    <i class="fa-solid fa-clock"></i>
                    <span>Schedule</span>
                </a>

                <!-- ENTRIES -->
                <a href="<?= APP_URL ?>/admin/entries" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/entries') ?>">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Entries</span>
                </a>

                <!-- SCORES -->
                <a href="<?= APP_URL ?>/admin/score-entry" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/score-entry') ?>">
                    <i class="fa-solid fa-pen"></i>
                    <span>Scores</span>
                </a>

                <!-- SCORE APPROVAL -->
                <a href="<?= APP_URL ?>/admin/score-approval" 
                   class="sidebar-link <?= admin_sidebar_is_active('/admin/score-approval') ?>">
                    <i class="fa-solid fa-check"></i>
                    <span>Approval</span>
                </a>

                <!-- TV MODE -->
                <a href="<?= APP_URL ?>/tv/index.php" 
                   class="sidebar-link <?= admin_sidebar_is_active('/tv') ?>">
                    <i class="fa-solid fa-tv"></i>
                    <span>TV Mode</span>
                </a>
                <a href="<?= APP_URL ?>/admin/logs.php"
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
                        ? APP_URL . '/uploads/profile/' . rawurlencode((string)$user['profile_photo'])
                        : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username'])
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

        <!-- LOGOUT -->
        <a href="<?= APP_URL ?>/auth/logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>

    </div>

</div>
