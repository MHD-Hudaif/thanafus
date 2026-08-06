<?php

require_once __DIR__ . '/admin-helpers.php';
$user = $user ?? current_user();

/*
|--------------------------------------------------------------------------
| ACTIVE CONTEXT
|--------------------------------------------------------------------------
*/

$activeEventId = $_SESSION['selected_event_id'] ?? $_SESSION['active_event_id'] ?? null;
if (!$activeEventId && function_exists('get_active_musabaqa')) {
    $ev = get_active_musabaqa();
    if ($ev) $activeEventId = (int)$ev['id'];
}
$activeTeamId  = $_SESSION['active_team_id'] ?? null;

/*
|--------------------------------------------------------------------------
| ACTIVE PAGE DETECTION & WORKSPACE ROUTING
|--------------------------------------------------------------------------
*/

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requestUri  = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('admin_sidebar_is_active')) {
function admin_sidebar_is_active($path) {
    global $currentPath, $requestUri;
    if (str_contains($path, '?') || str_contains($path, '=')) {
        return str_contains($requestUri, $path) ? 'active' : '';
    }
    return str_contains($currentPath, $path) ? 'active' : '';
}
}

// Auto-detect workspace based on path
$activeSpace = $_SESSION['active_workspace'] ?? 'event-manager';

if (str_contains($currentPath, '/admin/printer/') || str_contains($currentPath, '/admin/id-cards-search.php') || str_contains($currentPath, '/admin/logs.php') || str_contains($currentPath, '/admin/event/id-cards')) {
    $activeSpace = 'printer';
} elseif (str_contains($currentPath, '/admin/registrar/') || str_contains($currentPath, '/admin/entries.php') || str_contains($currentPath, '/admin/add-entry.php') || str_contains($currentPath, '/admin/event/program-entries.php')) {
    $activeSpace = 'registrar';
} elseif (str_contains($currentPath, '/admin/live-display/') || str_contains($currentPath, '/admin/event/control-tv.php')) {
    $activeSpace = 'live-display';
} elseif (str_contains($currentPath, '/admin/score-entry/') || str_contains($currentPath, '/admin/score-entry.php') || str_contains($currentPath, '/admin/event/upload-scores.php')) {
    $activeSpace = 'score-entry';
} elseif (str_contains($currentPath, '/admin/score-update/') || str_contains($currentPath, '/admin/score-approval.php') || str_contains($currentPath, '/admin/reviews.php')) {
    $activeSpace = 'score-update';
} elseif (str_contains($currentPath, '/admin/event-manager/') || str_contains($currentPath, '/admin/settings.php') || str_contains($currentPath, '/admin/programs.php') || str_contains($currentPath, '/admin/schedule.php') || str_contains($currentPath, '/admin/teams.php') || str_contains($currentPath, '/admin/members.php') || str_contains($currentPath, '/admin/chest-numbers.php') || str_contains($currentPath, '/admin/analytics.php')) {
    $activeSpace = 'event-manager';
}

$_SESSION['active_workspace'] = $activeSpace;
?>

<!-- DESKTOP VERTICAL SIDEBAR LAYOUT -->
<aside class="sidebar-vertical">
    <!-- Brand: Toggles Workspace Header Navigation -->
    <button type="button" class="sidebar-vertical-brand workspace-toggle-btn" id="sidebarWorkspaceToggleBtn" aria-expanded="false" title="Click to open Workspace Navigation header" style="background:none; border:none; padding:0; cursor:pointer; text-align:left; font-family:inherit; width:100%;">
        <div class="sidebar-logo-icon">
            <img src="<?= asset_url('images/green-v-logo.svg') ?>" alt="Logo" class="sidebar-logo-img">
        </div>
        <div class="sidebar-brand-info">
            <span class="sidebar-brand-name">Kauzariyya</span>
            <span class="sidebar-brand-tag"><?= ucwords(str_replace('-', ' ', $activeSpace)) ?></span>
        </div>
    </button>
    
    <!-- Workspace Menu Links -->
    <nav class="sidebar-vertical-menu">
        <?php if ($activeSpace === 'event-manager' || $activeSpace === 'team-manager'): ?>
            <?php
            $hasActiveTeam = (int)($_GET['team'] ?? $_SESSION['active_team_id'] ?? 0) > 0;
            ?>
            <a href="<?= app_url('/admin/event-manager/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/event-manager/index.php') ?>">
                <i class="fa-solid fa-circle-info"></i> <span>Overview</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/teams.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('teams') ?>">
                <i class="fa-solid fa-people-group"></i> <span>Teams</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/members.php?team=' . (int)($_GET['team'] ?? $_SESSION['active_team_id'] ?? 0)) ?>" class="sidebar-vertical-link members-link <?= admin_sidebar_is_active('members') ?> <?= !$hasActiveTeam ? 'hidden-link' : 'slide-in-link' ?>" id="sidebarMembersLink">
                <i class="fa-solid fa-user-plus"></i> <span>Members</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/chest-numbers.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('chest-numbers') ?>">
                <i class="fa-solid fa-id-badge"></i> <span>Chest Numbers</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('programs') ?>">
                <i class="fa-solid fa-list-check"></i> <span>Programs</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('sections') ?>">
                <i class="fa-solid fa-layer-group"></i> <span>Schedule Sessions</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/schedule.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('schedule') ?>">
                <i class="fa-solid fa-calendar-days"></i> <span>Schedule</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/analytics.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('analytics') ?>">
                <i class="fa-solid fa-chart-line"></i> <span>Analytics</span>
            </a>
            <a href="<?= app_url('/admin/event-manager/settings.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('settings') ?>">
                <i class="fa-solid fa-sliders"></i> <span>Settings</span>
            </a>

        <?php elseif ($activeSpace === 'printer'): ?>
            <a href="<?= app_url('/admin/printer/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/printer/index.php') ?>">
                <i class="fa-solid fa-circle-info"></i> <span>Overview</span>
            </a>
            <a href="<?= app_url('/admin/printer/id-cards-search.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('id-cards-search') ?>">
                <i class="fa-solid fa-address-card"></i> <span>ID Cards</span>
            </a>
            <a href="<?= app_url('/admin/printer/chest-numbers.php') ?>" class="sidebar-vertical-link <?= str_contains($currentPath, 'chest-numbers') ? 'active' : '' ?>">
                <i class="fa-solid fa-id-badge"></i> <span>Chest Numbers</span>
            </a>
            <a href="<?= app_url('/admin/printer/members-export.php') ?>" class="sidebar-vertical-link <?= str_contains($currentPath, 'members-export') ? 'active' : '' ?>">
                <i class="fa-solid fa-file-csv"></i> <span>CSV Export</span>
            </a>
            <a href="<?= app_url('/admin/printer/score-sheets.php') ?>" class="sidebar-vertical-link <?= str_contains($currentPath, 'score-sheets') ? 'active' : '' ?>">
                <i class="fa-solid fa-file-pdf"></i> <span>Score Sheets</span>
            </a>
            <a href="<?= app_url('/admin/printer/mc-sheets.php') ?>" class="sidebar-vertical-link <?= str_contains($currentPath, 'mc-sheets') ? 'active' : '' ?>">
                <i class="fa-solid fa-microphone"></i> <span>MC Sheets</span>
            </a>

        <?php elseif ($activeSpace === 'registrar'): ?>
            <?php
            $activeProgramId = (int)($_GET['program_id'] ?? $_SESSION['active_program_id'] ?? 0);
            $hasActiveProgram = $activeProgramId > 0;
            ?>
            <a href="<?= app_url('/admin/registrar/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/registrar/index.php') ?>">
                <i class="fa-solid fa-circle-info"></i> <span>Overview</span>
            </a>
            <a href="<?= app_url('/admin/registrar/entries.php?view=programs') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('view=programs') ?>">
                <i class="fa-solid fa-list-check"></i> <span>All Programs</span>
            </a>
            <a href="<?= app_url('/admin/registrar/entries.php?program_id=' . $activeProgramId) ?>" class="sidebar-vertical-link program-entries-link <?= str_contains($currentPath, 'entries.php') && $hasActiveProgram ? 'active' : '' ?> <?= !$hasActiveProgram ? 'hidden-link' : 'slide-in-link' ?>" id="sidebarProgramEntriesLink">
                <i class="fa-solid fa-rectangle-list"></i> <span>Program Entries</span>
            </a>
            <a href="<?= app_url('/admin/registrar/add-entry.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('add-entry.php') ?>">
                <i class="fa-solid fa-user-plus"></i> <span>Register</span>
            </a>

        <?php elseif ($activeSpace === 'live-display'): ?>
            <a href="<?= app_url('/admin/live-display/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/live-display/index.php') ?>">
                <i class="fa-solid fa-circle-info"></i> <span>Overview</span>
            </a>
            <a href="<?= app_url('/admin/live-display/control-tv.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('control-tv.php') ?>">
                <i class="fa-solid fa-gears"></i> <span>TV Control</span>
            </a>
            <a href="<?= app_url('/tv/dashboard.php') ?>" class="sidebar-vertical-link" target="_blank">
                <i class="fa-solid fa-tower-broadcast"></i> <span>TV Feed</span>
            </a>
            <a href="<?= app_url('/scoreboard.php') ?>" class="sidebar-vertical-link" target="_blank">
                <i class="fa-solid fa-display"></i> <span>Scoreboard</span>
            </a>

        <?php elseif ($activeSpace === 'score-entry'): ?>
            <a href="<?= app_url('/admin/score-entry/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/score-entry/index.php') ?>">
                <i class="fa-solid fa-circle-info"></i> <span>Overview</span>
            </a>
            <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('score-entry.php') ?>">
                <i class="fa-solid fa-calculator"></i> <span>Score Entry</span>
            </a>
            <a href="<?= app_url('/admin/score-entry/upload-scores.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('upload-scores.php') ?>">
                <i class="fa-solid fa-file-arrow-up"></i> <span>Upload Scores</span>
            </a>
            <a href="<?= app_url('/admin/score-entry/program-scores.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('program-scores.php') ?>">
                <i class="fa-solid fa-file-lines"></i> <span>Score Sheets</span>
            </a>

        <?php elseif ($activeSpace === 'score-update'): ?>
            <a href="<?= app_url('/admin/score-update/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/score-update/index.php') ?>">
                <i class="fa-solid fa-circle-info"></i> <span>Overview</span>
            </a>
            <a href="<?= app_url('/admin/score-update/score-approval.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('score-approval.php') ?>">
                <i class="fa-solid fa-circle-check"></i> <span>Approve Scores</span>
            </a>
            <a href="<?= app_url('/admin/score-update/reviews.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('reviews.php') ?>">
                <i class="fa-solid fa-magnifying-glass-chart"></i> <span>Audit Reviews</span>
            </a>
        <?php endif; ?>
    </nav>
    
    <!-- Footer -->
    <div class="sidebar-vertical-footer">
        <div class="sidebar-user-avatar">
            <img src="<?=
                !empty($user['profile_photo'])
                    ? avatar_url($user['profile_photo'])
                    : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username']) . '&background=0d1420&color=8b5cf6&bold=true'
            ?>" alt="Profile">
        </div>
        <div class="sidebar-user-details">
            <span class="sidebar-user-name"><?= e($user['full_name'] ?? $user['username']) ?></span>
            <span class="sidebar-user-role"><?= is_admin() ? 'Super Admin' : 'Staff' ?></span>
        </div>
        <a href="<?= app_url('/auth/logout') ?>" class="sidebar-logout-btn" title="Logout" data-ajax-ignore>
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>
</aside>



<!-- TOP BAR: CORNERED HEADER NAV -->
<header class="event-top-nav" id="eventTopNav">
    <?php if (!admin_is_ajax()): ?>
    <div class="event-nav-left mobile-only-flex">
        <!-- Mobile Menu Toggle -->
        <button type="button" class="event-mobile-menu" id="eventMobileMenuBtn" aria-label="Open navigation menu">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Active Workspace Header Navigation (Page Links vs. Workspace Navigation Links) -->
    <nav class="event-nav-menu" id="eventNavMenu" aria-label="Event workspace navigation">
        <!-- Workspace Navigation Links (Toggled by Sidebar Top Logo) -->
        <div class="event-nav-group workspace-nav-group" id="headerWorkspaceNavGroup">
            <a href="<?= app_url('/admin/event-manager/index.php') ?>" class="nav-item-link <?= $activeSpace === 'event-manager' ? 'active' : '' ?>">
                <i class="fa-solid fa-square-poll-vertical" style="color:#818cf8;"></i> <span>Event Manager</span>
            </a>
            <a href="<?= app_url('/admin/registrar/index.php') ?>" class="nav-item-link <?= $activeSpace === 'registrar' ? 'active' : '' ?>">
                <i class="fa-solid fa-id-card" style="color:#f59e0b;"></i> <span>Registrar</span>
            </a>
            <a href="<?= app_url('/admin/printer/index.php') ?>" class="nav-item-link <?= $activeSpace === 'printer' ? 'active' : '' ?>">
                <i class="fa-solid fa-print" style="color:#3b82f6;"></i> <span>Printer</span>
            </a>
            <a href="<?= app_url('/admin/live-display/index.php') ?>" class="nav-item-link <?= $activeSpace === 'live-display' ? 'active' : '' ?>">
                <i class="fa-solid fa-display" style="color:#ec4899;"></i> <span>Live Display</span>
            </a>
            <a href="<?= app_url('/admin/score-entry/index.php') ?>" class="nav-item-link <?= $activeSpace === 'score-entry' ? 'active' : '' ?>">
                <i class="fa-solid fa-pen-to-square" style="color:#a855f7;"></i> <span>Score Entry</span>
            </a>
            <a href="<?= app_url('/admin/score-update/index.php') ?>" class="nav-item-link <?= $activeSpace === 'score-update' ? 'active' : '' ?>">
                <i class="fa-solid fa-circle-check" style="color:#10b981;"></i> <span>Score Update</span>
            </a>
        </div>
    </nav>

    <!-- User Info & Logout -->
    <div class="event-nav-right">
        <div class="event-user-box">
            <span class="event-avatar"><?= mb_strtoupper(mb_substr((string)($user['full_name'] ?? $user['username'] ?? 'U'), 0, 1)) ?></span>
            <span class="event-user-details">
                <strong><?= e($user['full_name'] ?? $user['username'] ?? 'User') ?></strong>
                <small><?= is_admin() ? 'Super Admin' : 'Staff' ?></small>
            </span>
        </div>
        <a class="event-nav-icon" href="<?= app_url('/index.php') ?>" aria-label="Back to home" title="Back to home">
            <i class="fa-solid fa-house"></i>
        </a>
        <?php if (is_admin()): ?>
            <a class="event-nav-icon" href="<?= app_url('/admin/index.php') ?>" aria-label="Event Selection" title="Musabaqa Event Selection">
                <i class="fa-solid fa-calendar-days" style="color:#34d399;"></i>
            </a>
        <?php endif; ?>
        <a class="event-logout" href="<?= app_url('/auth/logout') ?>" aria-label="Logout" title="Logout" data-ajax-ignore>
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>
</header>

<!-- GLOBAL CHAT MODAL OVERLAY -->
<div class="chat-modal-overlay" id="globalChatModal" aria-hidden="true">
    <div class="chat-modal-container">
        <!-- Chat Header -->
        <div class="chat-header-bar">
            <div class="chat-header-info">
                <i class="fa-solid fa-comments text-primary"></i>
                <h3 id="chatActiveRoomName">Global Lounge</h3>
            </div>
            <button type="button" class="chat-close-btn" id="closeChatModalBtn">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Chat Main Area -->
        <div class="chat-main-body">
            <!-- Left User Sidebar -->
            <div class="chat-user-sidebar">
                <div class="chat-user-item active" id="chatRoomGlobal" data-room-type="global">
                    <div class="chat-item-avatar global-avatar">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="chat-item-details">
                        <span class="chat-item-name">Global Lounge</span>
                        <span class="chat-item-status">Public Room</span>
                    </div>
                </div>
                <div class="chat-user-divider">Direct Messages</div>
                <div class="chat-users-list" id="chatUsersListContainer">
                    <!-- Loaded dynamically via JS -->
                </div>
            </div>

            <!-- Right Message Feed -->
            <div class="chat-feed-pane">
                <div class="chat-messages-container" id="chatMessagesFeed">
                    <!-- Messages loaded dynamically -->
                </div>
                <form id="chatMessageForm" class="chat-input-area" autocomplete="off" data-ajax-ignore>
                    <?= admin_csrf_field() ?>
                    <input type="hidden" id="chatActiveReceiverId" name="receiver_id" value="">
                    <input type="text" id="chatInputMessage" name="message" placeholder="Type a message..." required>
                    <button type="submit" class="chat-send-btn">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* CSS transition for Members link to slide in */
.sidebar-vertical-link.members-link, .nav-item-link.members-link {
    transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.4s ease, width 0.4s cubic-bezier(0.4, 0, 0.2, 1), margin 0.4s ease, padding 0.4s ease !important;
    overflow: hidden;
}
.sidebar-vertical-link.members-link.hidden-link, .nav-item-link.members-link.hidden-link {
    max-height: 0 !important;
    width: 0 !important;
    opacity: 0 !important;
    pointer-events: none !important;
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    border: none !important;
}
.sidebar-vertical-link.members-link.slide-in-link, .nav-item-link.members-link.slide-in-link {
    max-height: 0;
    width: 0;
    opacity: 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('sidebarWorkspaceToggleBtn');
    const topNav = document.getElementById('eventTopNav');
    if (toggleBtn && topNav) {
        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = topNav.classList.toggle('is-workspace-mode');
            toggleBtn.classList.toggle('open', isOpen);
            toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', (e) => {
            if (!topNav.contains(e.target) && !toggleBtn.contains(e.target)) {
                topNav.classList.remove('is-workspace-mode');
                toggleBtn.classList.remove('open');
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }
});
</script>
