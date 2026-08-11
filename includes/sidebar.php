<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-helpers.php';
$user = $user ?? current_user();
$pdo = $GLOBALS['musabaqa_pdo'];

/* Active Context */
$activeEventId = $_SESSION['selected_event_id'] ?? $_SESSION['active_event_id'] ?? null;
if (!$activeEventId && function_exists('get_active_musabaqa')) {
    $ev = get_active_musabaqa();
    if ($ev) $activeEventId = (int)$ev['id'];
}
$activeTeamId  = $_SESSION['active_team_id'] ?? null;

/* Fetch Active Event Details */
$activeEvent = null;
if ($activeEventId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM musabaqa_events WHERE id = ? LIMIT 1');
    $stmt->execute([$activeEventId]);
    $activeEvent = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* Active Page Detection */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requestUri  = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('admin_sidebar_is_active')) {
    function admin_sidebar_is_active(string $path): string {
        global $currentPath, $requestUri;
        
        $norm = function(string $p): string {
            $p = str_replace('\\', '/', $p);
            $p = preg_replace('/\.php$/i', '', $p);
            $p = preg_replace('/\/index$/i', '', $p);
            return rtrim($p, '/');
        };
        
        $cNorm = $norm($currentPath);
        $pNorm = $norm($path);
        
        if (str_contains($path, '?') || str_contains($path, '=')) {
            return str_contains($requestUri, $path) ? 'active' : '';
        }
        
        if ($cNorm === $pNorm || (strlen($pNorm) > 0 && str_ends_with($cNorm, '/' . ltrim($pNorm, '/')))) {
            return 'active';
        }
        
        return '';
    }
}

// Auto-detect workspace based on path
$activeSpace = 'event-manager';
if (str_contains($currentPath, '/admin/printer/')) {
    $activeSpace = 'printer';
} elseif (str_contains($currentPath, '/admin/registrar/')) {
    $activeSpace = 'registrar';
} elseif (str_contains($currentPath, '/admin/live-display/')) {
    $activeSpace = 'live-display';
} elseif (str_contains($currentPath, '/admin/score-entry/')) {
    $activeSpace = 'score-entry';
} elseif (str_contains($currentPath, '/admin/score-update/')) {
    $activeSpace = 'score-update';
}
$_SESSION['active_workspace'] = $activeSpace;

/* Badges Calculations */
$pendingApprovalsCount = 0;
if (is_admin()) {
    try {
        $pendingApprovalsCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {}
}

$isTvLive = false;
$activeEventTitle = 'Kauzariyya Musabaqa';
if ($activeEventId) {
    try {
        $activeEventTitle = $activeEvent['title'] ?? 'No active event';
        $settKey = 'live_display.event.' . $activeEventId . '.settings';
        $settValueStmt = $pdo->prepare('SELECT setting_value FROM musabaqa_settings WHERE setting_key = ? LIMIT 1');
        $settValueStmt->execute([$settKey]);
        $settJson = $settValueStmt->fetchColumn();
        if ($settJson) {
            $settDecoded = json_decode($settJson, true);
            $isTvLive = (($settDecoded['mode'] ?? 'auto') === 'manual');
        }
    } catch (Throwable $e) {}
}
?>

<!-- Load Lucide SVG Icons library -->
<script src="https://unpkg.com/lucide@latest"></script>

<!-- Load Sidebar CSS & JS dynamically -->
<link rel="stylesheet" href="<?= asset_url('css/sidebar.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/sidebar.css') ?>">
<script src="<?= asset_url('js/sidebar.js') ?>?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>" defer></script>

<!-- DESKTOP FLOATING VERTICAL SIDEBAR LAYOUT -->
<aside class="sidebar-vertical" id="adminVerticalSidebar">
    
    <!-- Brand Info -->
    <div class="sidebar-vertical-brand">
        <div class="sidebar-logo-icon">
            <img src="<?= asset_url('images/green-v-logo.svg') ?>" alt="Logo" class="sidebar-logo-img">
        </div>
        <div class="sidebar-brand-info">
            <span class="sidebar-brand-name">Kauzariyya</span>
            <span class="sidebar-brand-tag">Event Hub</span>
        </div>
    </div>

    <!-- Collapse Toggle Trigger Button -->
    <button class="sidebar-collapse-trigger" aria-label="Collapse Navigation Menu" title="Toggle Sidebar Expand/Collapse">
        <i data-lucide="chevron-left"></i>
    </button>

    <!-- Search Launcher Command Launcher -->
    <div class="sidebar-search-launcher-container">
        <button class="sidebar-search-launcher" id="sidebarSearchLauncher" title="Search pages... (Ctrl+K)">
            <i data-lucide="search"></i>
            <span>Search...</span>
            <span class="sidebar-search-shortcut">⌘K</span>
        </button>
    </div>
    
    <!-- Collapsible Workspaces Menu -->
    <nav class="sidebar-vertical-menu">

        <!-- WORKSPACE 1: COMPETITION -->
        <div class="sidebar-group" id="group_competition">
            <div class="sidebar-group-header">
                <span>🏆 Competition</span>
                <i data-lucide="chevron-down" class="chevron-icon"></i>
            </div>
            <div class="sidebar-group-content">
                <a href="<?= app_url('/admin/event-manager/index.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('/admin/event-manager/index.php') ?>">
                    <i data-lucide="layout-dashboard" class="sidebar-icon"></i>
                    <span class="sidebar-label">Overview</span>
                </a>
                <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('programs.php') ?>">
                    <i data-lucide="list-todo" class="sidebar-icon"></i>
                    <span class="sidebar-label">Programs</span>
                </a>
                <a href="<?= app_url('/admin/event-manager/schedule.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('schedule.php') ?>">
                    <i data-lucide="calendar" class="sidebar-icon"></i>
                    <span class="sidebar-label">Schedule Grid</span>
                </a>
                <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('sections.php') ?>">
                    <i data-lucide="layers" class="sidebar-icon"></i>
                    <span class="sidebar-label">Schedule Sessions</span>
                </a>
                <a href="<?= app_url('/admin/event-manager/teams.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('teams.php') ?>">
                    <i data-lucide="users" class="sidebar-icon"></i>
                    <span class="sidebar-label">Teams Directory</span>
                </a>
                <a href="<?= app_url('/admin/event-manager/chest-numbers.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('chest-numbers.php') ?>">
                    <i data-lucide="credit-card" class="sidebar-icon"></i>
                    <span class="sidebar-label">Chest Numbers</span>
                </a>
                <a href="<?= app_url('/admin/registrar/entries.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('entries.php') ?>">
                    <i data-lucide="user-check" class="sidebar-icon"></i>
                    <span class="sidebar-label">Register Participant</span>
                </a>
            </div>
        </div>

        <!-- WORKSPACE 2: SCORING -->
        <div class="sidebar-group" id="group_scoring">
            <div class="sidebar-group-header">
                <span>🎯 Scoring</span>
                <i data-lucide="chevron-down" class="chevron-icon"></i>
            </div>
            <div class="sidebar-group-content">
                <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('score-entry.php') ?>">
                    <i data-lucide="calculator" class="sidebar-icon"></i>
                    <span class="sidebar-label">Scoring</span>
                </a>
                <a href="<?= app_url('/admin/score-entry/program-scores.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('program-scores.php') ?>">
                    <i data-lucide="file-text" class="sidebar-icon"></i>
                    <span class="sidebar-label">Scores - all the scores</span>
                </a>
                <a href="<?= app_url('/admin/score-entry/score-history.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('score-history.php') ?>">
                    <i data-lucide="history" class="sidebar-icon"></i>
                    <span class="sidebar-label">Score History</span>
                </a>
                <?php if (is_admin()): ?>
                <a href="<?= app_url('/admin/score-update/score-approval.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('score-approval.php') ?>">
                    <i data-lucide="check-circle-2" class="sidebar-icon"></i>
                    <span class="sidebar-label">Score Approval</span>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- WORKSPACE 3: LIVE BROADCAST -->
        <div class="sidebar-group" id="group_broadcast">
            <div class="sidebar-group-header">
                <span>📺 Broadcast</span>
                <i data-lucide="chevron-down" class="chevron-icon"></i>
            </div>
            <div class="sidebar-group-content">
                <a href="<?= app_url('/admin/live-display/emcee-deck.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('emcee-deck.php') ?>">
                    <i data-lucide="sparkles" class="sidebar-icon"></i>
                    <span class="sidebar-label">Emcee Stage Deck</span>
                </a>
                <a href="<?= app_url('/admin/live-display/remote.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('remote.php') ?>">
                    <i data-lucide="toggle-left" class="sidebar-icon"></i>
                    <span class="sidebar-label">Live Switcher (Remote)</span>
                    <?php if ($isTvLive): ?>
                        <span class="sidebar-badge sidebar-badge-live">LIVE</span>
                    <?php endif; ?>
                </a>
                <a href="<?= app_url('/admin/live-display/control-live-display.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('control-live-display.php') ?>">
                    <i data-lucide="settings-2" class="sidebar-icon"></i>
                    <span class="sidebar-label">Loop Settings</span>
                </a>
                <a href="<?= app_url('/emcee/index.php') ?>" class="sidebar-vertical-link" target="_blank" data-ajax-ignore>
                    <i data-lucide="mic" class="sidebar-icon"></i>
                    <span class="sidebar-label">Emcee Stage Portal</span>
                </a>
                <a href="<?= app_url('/live-display/dashboard.php') ?>" class="sidebar-vertical-link" target="_blank" data-ajax-ignore>
                    <i data-lucide="tv" class="sidebar-icon"></i>
                    <span class="sidebar-label">Live Feed / TV</span>
                </a>
                <a href="<?= app_url('/scoreboard.php') ?>" class="sidebar-vertical-link" target="_blank" data-ajax-ignore>
                    <i data-lucide="monitor" class="sidebar-icon"></i>
                    <span class="sidebar-label">Public Scoreboard</span>
                </a>
            </div>
        </div>

        <!-- WORKSPACE 4: PRINT & EXPORTS -->
        <div class="sidebar-group" id="group_printer">
            <div class="sidebar-group-header">
                <span>🖨️ Print &amp; Export</span>
                <i data-lucide="chevron-down" class="chevron-icon"></i>
            </div>
            <div class="sidebar-group-content">
                <a href="<?= app_url('/admin/printer/score-sheets.php') ?>" class="sidebar-vertical-link <?= str_contains($currentPath, '/printer/score-sheets.php') ? 'active' : '' ?>">
                    <i data-lucide="printer" class="sidebar-icon"></i>
                    <span class="sidebar-label">Print Score Sheets</span>
                </a>
                <a href="<?= app_url('/admin/printer/mc-sheets.php') ?>" class="sidebar-vertical-link <?= str_contains($currentPath, '/printer/mc-sheets.php') ? 'active' : '' ?>">
                    <i data-lucide="printer" class="sidebar-icon"></i>
                    <span class="sidebar-label">Print MC Sheets</span>
                </a>
                <a href="<?= app_url('/admin/score-update/approval-marks.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('approval-marks.php') ?>">
                    <i data-lucide="trophy" class="sidebar-icon"></i>
                    <span class="sidebar-label">Final Rankings</span>
                </a>
                <a href="<?= app_url('/admin/score-update/reviews.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('reviews.php') ?>">
                    <i data-lucide="clipboard-check" class="sidebar-icon"></i>
                    <span class="sidebar-label">Audit Reviews</span>
                </a>
                <a href="<?= app_url('/admin/event-manager/progress.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('progress.php') ?>">
                    <i data-lucide="bar-chart-3" class="sidebar-icon"></i>
                    <span class="sidebar-label">Marks &amp; Progress</span>
                </a>
            </div>
        </div>

        <!-- WORKSPACE 5: SYSTEM -->
        <div class="sidebar-group" id="group_system">
            <div class="sidebar-group-header">
                <span>⚙️ System</span>
                <i data-lucide="chevron-down" class="chevron-icon"></i>
            </div>
            <div class="sidebar-group-content">
                <?php if (is_admin()): ?>
                <a href="<?= app_url('/admin/event-manager/user-approvals.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('user-approvals.php') ?>">
                    <i data-lucide="shield-alert" class="sidebar-icon"></i>
                    <span class="sidebar-label">User Approvals</span>
                    <?php if ($pendingApprovalsCount > 0): ?>
                        <span class="sidebar-badge sidebar-badge-count"><?= $pendingApprovalsCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= app_url('/admin/event-manager/settings.php') ?>" class="sidebar-vertical-link <?= admin_sidebar_is_active('settings.php') ?>">
                    <i data-lucide="sliders" class="sidebar-icon"></i>
                    <span class="sidebar-label">System Settings</span>
                </a>
                <?php endif; ?>
            </div>
        </div>

    </nav>
    
    <!-- Fixed Footer Panel -->
    <div class="sidebar-footer">
        
        <!-- Active Event details -->
        <div class="sidebar-event-panel">
            <div class="sidebar-event-icon">
                <i data-lucide="trophy"></i>
            </div>
            <div class="sidebar-event-info">
                <span class="sidebar-event-title"><?= e($activeEventTitle) ?></span>
                <span class="sidebar-event-status">Active Broadcast</span>
            </div>
        </div>

        <!-- Authenticated User profile -->
        <div class="sidebar-user-panel">
            <div class="sidebar-user-avatar">
                <img src="<?=
                    !empty($user['profile_photo'])
                        ? avatar_url($user['profile_photo'])
                        : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? $user['username']) . '&background=0b0f19&color=10b981&bold=true'
                ?>" alt="Profile">
            </div>
            <div class="sidebar-user-info">
                <span class="sidebar-user-name"><?= e($user['full_name'] ?? $user['username']) ?></span>
                <span class="sidebar-user-role"><?= is_admin() ? 'Super Admin' : 'Staff' ?></span>
            </div>
            <a href="<?= app_url('/auth/logout') ?>" class="sidebar-logout-btn" title="Logout" data-ajax-ignore>
                <i data-lucide="log-out" style="width: 15px; height: 15px;"></i>
            </a>
        </div>

    </div>
</aside>

<!-- COMMAND PALETTE SEARCH MODAL OVERLAY -->
<div class="sidebar-command-overlay" id="sidebarCommandPalette">
    <div class="sidebar-command-box">
        <!-- Search Input Row -->
        <div class="sidebar-command-search-row">
            <i data-lucide="search"></i>
            <input type="text" class="sidebar-command-input" id="sidebarCommandInput" placeholder="Search pages, menus, settings..." autocomplete="off">
            <button class="sidebar-command-close" id="sidebarCommandClose">ESC</button>
        </div>
        <!-- Results List -->
        <div class="sidebar-command-results" id="sidebarCommandResults">
            <!-- Populated dynamically via JS -->
        </div>
    </div>
</div>
