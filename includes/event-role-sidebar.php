<?php
$eventRoleUser = current_user() ?? [];
if (!empty($isAjaxRequest)) {
    return;
}
$eventRoleName = $eventRoleUser['full_name'] ?? $eventRoleUser['username'] ?? 'User';
$eventRoleInitial = mb_strtoupper(mb_substr((string)$eventRoleName, 0, 1));

// Fetch user authorities for the small subtitle label
$userAuthorities = [];
if (is_admin()) {
    $userAuthorities[] = 'Super Admin';
} else {
    $stmt = $GLOBALS['dashboard_pdo']->prepare("
        SELECT a.name 
        FROM authorities a
        JOIN user_authorities ua ON ua.authority_id = a.id
        WHERE ua.user_id = ?
        ORDER BY a.name ASC
    ");
    $stmt->execute([$eventRoleUser['id'] ?? 0]);
    $userAuthorities = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$roleLabel = implode(', ', $userAuthorities) ?: 'Event Coordinator';
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
?>
<header class="event-top-nav">
    <div class="event-nav-left">
        <button type="button" class="event-mobile-menu" data-event-menu-toggle aria-controls="eventNavMenu" aria-expanded="false" aria-label="Open navigation menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a class="event-brand" href="<?= app_url('/home.php') ?>" aria-label="Back to home">
            <span class="event-brand-mark"><img src="<?= asset_url('images/thanafus-logo.png') ?>" alt=""></span>
            <span class="event-brand-copy"><strong>Kauzariyya</strong><small>Event Hub</small></span>
        </a>
    </div>

    <nav class="event-nav-menu" id="eventNavMenu" aria-label="Event workspace navigation">
        <?php if (current_user_has_authority('members-info')): ?>
            <a href="<?= app_url('/admin/event/id-cards.php') ?>" class="<?= str_contains($currentPath, 'id-cards.php') ? 'active' : '' ?>"><i class="fa-solid fa-id-card-clip"></i> Print Center</a>
        <?php endif; ?>
        <?php if (current_user_has_authority('assign-entries')): ?>
            <a href="<?= app_url('/admin/event/program-entries.php') ?>" class="<?= str_contains($currentPath, 'program-entries.php') ? 'active' : '' ?>"><i class="fa-solid fa-list-check"></i> Assign Entries</a>
        <?php endif; ?>
        <?php if (current_user_has_authority('upload-scores')): ?>
            <a href="<?= app_url('/admin/event/upload-scores.php') ?>" class="<?= str_contains($currentPath, 'upload-scores.php') ? 'active' : '' ?>"><i class="fa-solid fa-pen-to-square"></i> Upload Scores</a>
        <?php endif; ?>
        <?php if (current_user_has_authority('control-tv')): ?>
            <a href="<?= app_url('/admin/event/control-tv.php') ?>" class="<?= str_contains($currentPath, 'control-tv.php') ? 'active' : '' ?>"><i class="fa-solid fa-tv"></i> TV Control</a>
        <?php endif; ?>
        <a href="<?= app_url('/home.php') ?>"><i class="fa-solid fa-house"></i> Home</a>
        <?php if (is_admin()): ?><a href="<?= app_url('/admin/dashboard.php') ?>"><i class="fa-solid fa-table-columns"></i> Admin</a><?php endif; ?>
    </nav>

    <div class="event-nav-right">
        <div class="event-user-box">
            <span class="event-avatar"><?= e($eventRoleInitial) ?></span>
            <span class="event-user-details"><strong><?= e($eventRoleName) ?></strong><small><?= e($roleLabel) ?></small></span>
        </div>
        <a class="event-nav-icon" href="<?= app_url('/home.php') ?>" aria-label="Back to home" title="Back to home"><i class="fa-solid fa-house"></i></a>
        <a class="event-logout" href="<?= app_url('/auth/logout.php') ?>" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('[data-event-menu-toggle]');
    const menu = document.getElementById('eventNavMenu');
    if (!button || !menu) return;
    button.addEventListener('click', () => {
        const open = menu.classList.toggle('is-open');
        button.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
});
</script>
