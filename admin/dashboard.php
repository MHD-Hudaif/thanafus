<?php
$pageTitle = 'Dashboard';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$flash = admin_take_flash();
$activeEventId = (int)($_SESSION['active_event_id'] ?? 0);
$activeEvent = null;

if ($activeEventId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM musabaqa_events WHERE id = ? LIMIT 1');
    $stmt->execute([$activeEventId]);
    $activeEvent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeEvent) {
        unset($_SESSION['active_event_id'], $_SESSION['active_team_id']);
        admin_redirect('/admin/dashboard.php');
    }
}

$totalTeams = $totalMembers = $totalPrograms = $totalEntries = 0;
if ($activeEvent) {
    $queries = [
        'teams' => 'SELECT COUNT(*) FROM musabaqa_teams WHERE event_id = ?',
        'members' => 'SELECT COUNT(*) FROM musabaqa_team_members WHERE event_id = ? AND status = "active"',
        'programs' => 'SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ?',
        'entries' => 'SELECT COUNT(*) FROM musabaqa_program_entries WHERE event_id = ?',
    ];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$activeEventId]);
        ${'total' . ucfirst($key)} = (int)$stmt->fetchColumn();
    }
}

$events = [];
if (!$activeEvent) {
    $events = $pdo->query('SELECT * FROM musabaqa_events ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Musabaqa Control Center</div>
            <div class="page-subtitle">Event overview and management</div>
        </div>
        <a class="btn btn-success btn-md" href="<?= APP_URL ?>/admin/events.php">
            <i class="fa-solid fa-calendar-plus"></i> Manage Events
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$activeEvent): ?>
        <div class="dashboard-step-header">
            <div>
                <div class="dashboard-step">STEP 1</div>
                <div class="dashboard-heading">Select Event</div>
            </div>
        </div>

        <?php if (!$events): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="empty-title">No Events Created</div>
                <div class="empty-subtitle">Create an event from the Events page to begin.</div>
            </div>
        <?php else: ?>
            <div class="dashboard-grid">
                <?php foreach ($events as $event): ?>
                    <?php $color = trim(explode(',', $event['theme_colors'] ?: '#14b8a6')[0]); ?>
                    <a href="<?= APP_URL ?>/admin/utilities/set-active-event.php?id=<?= (int)$event['id'] ?>" class="dashboard-card" style="border-top:4px solid <?= e($color) ?>;">
                        <div class="dashboard-card-title"><?= e($event['title']) ?></div>
                        <div class="dashboard-card-description"><?= e($event['description'] ?: 'No description') ?></div>
                        <div class="mt-4"><span class="badge badge-neutral"><?= e(strtoupper((string)$event['status'])) ?></span></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="dashboard-step-header">
            <div>
                <div class="active-event-badge">ACTIVE EVENT</div>
                <div class="dashboard-heading"><?= e($activeEvent['title']) ?></div>
            </div>
            <div class="flex gap-3 flex-wrap">
                <a href="<?= APP_URL ?>/admin/utilities/clear-event.php" class="btn btn-secondary btn-md">
                    <i class="fa-solid fa-rotate-left"></i> Change Event
                </a>
                <a href="<?= APP_URL ?>/admin/teams.php" class="btn btn-success btn-md">
                    <i class="fa-solid fa-users"></i> Manage Teams
                </a>
            </div>
        </div>

        <div class="stats-grid mb-6">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-people-group"></i></div><div class="stat-value"><?= number_format($totalTeams) ?></div><div class="stat-label">Teams</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-user"></i></div><div class="stat-value"><?= number_format($totalMembers) ?></div><div class="stat-label">Members</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-list-check"></i></div><div class="stat-value"><?= number_format($totalPrograms) ?></div><div class="stat-label">Programs</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-pen-to-square"></i></div><div class="stat-value"><?= number_format($totalEntries) ?></div><div class="stat-label">Entries</div></div>
        </div>

        <div class="panel">
            <div class="dashboard-heading">Quick Actions</div>
            <div class="quick-actions">
                <a href="<?= APP_URL ?>/admin/teams.php" class="quick-action-btn">Manage Teams</a>
                <a href="<?= APP_URL ?>/admin/programs.php" class="quick-action-btn">Programs</a>
                <a href="<?= APP_URL ?>/admin/schedule.php" class="quick-action-btn">Schedule</a>
                <a href="<?= APP_URL ?>/admin/score-entry.php" class="quick-action-btn">Program Scores</a>
                <a href="<?= APP_URL ?>/admin/score-approval.php" class="quick-action-btn">Program Approval</a>
                <a href="<?= APP_URL ?>/tv/index.php" class="quick-action-btn">TV Mode</a>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
