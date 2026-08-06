<?php
$pageTitle = 'Registrar Hub';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();
$_SESSION['active_workspace'] = 'registrar';

$activeEvent = admin_require_active_event($GLOBALS['musabaqa_pdo']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-clipboard-user" style="color:#f59e0b;"></i> Registrar Space</h1>
            <p>Manage Program Entries and Student Registrations per Event Program</p>
        </div>
        <?php if (is_admin()): ?>
        <div>
            <a href="<?= app_url('/admin/index.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-calendar-days mr-1"></i> All Events
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: 
        $activeEventId = (int)$activeEvent['id'];
        $pdo = $GLOBALS['musabaqa_pdo'];

        // Stats
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM musabaqa_team_members WHERE event_id = ?');
        $stmt->execute([$activeEventId]);
        $enrolledStudentsCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id IN (SELECT id FROM musabaqa_programs WHERE event_id = ?)');
        $stmt->execute([$activeEventId]);
        $programEntriesCount = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ? AND start_time IS NOT NULL');
        $stmt->execute([$activeEventId]);
        $scheduledCount = $stmt->fetchColumn();

        // Fetch recent entries
        $stmt = $pdo->prepare('
            SELECT e.*,
                   p.title AS program_name,
                   t.team_name,
                   CASE
                       WHEN NULLIF(e.entry_name, "") IS NOT NULL THEN
                           CONCAT(
                               e.entry_name,
                               COALESCE((
                                   SELECT CONCAT(" (", GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ""), s.full_name) SEPARATOR ", "), ")")
                                   FROM musabaqa_entry_members em
                                   JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                                   JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                                   WHERE em.entry_id = e.id
                               ), "")
                           )
                       ELSE
                           COALESCE(
                               (
                                   SELECT GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ""), s.full_name) SEPARATOR ", ")
                                   FROM musabaqa_entry_members em
                                   JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                                   JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                                   WHERE em.entry_id = e.id
                               ),
                               t.team_name
                           )
                   END AS student_name,
                   (
                       SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ", ")
                       FROM musabaqa_entry_members em
                       JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                       WHERE em.entry_id = e.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> ""
                   ) AS chest_number
            FROM musabaqa_program_entries e
            JOIN musabaqa_programs p ON e.program_id = p.id
            JOIN musabaqa_teams t ON e.team_id = t.id
            WHERE p.event_id = ?
            ORDER BY e.id DESC
            LIMIT 4
        ');
        $stmt->execute([$activeEventId]);
        $recentEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <div class="dashboard-layout-grid">
            <div class="dashboard-main-col">
                <!-- Stats Grid -->
                <div class="dashboard-stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-graduation-cap" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$enrolledStudentsCount ?></div>
                        <div class="stat-label">Enrolled Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-rectangle-list" style="color: #fde047;"></i></div>
                        <div class="stat-value"><?= (int)$programEntriesCount ?></div>
                        <div class="stat-label">Program Entries</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-calendar-check" style="color: var(--accent);"></i></div>
                        <div class="stat-value"><?= (int)$scheduledCount ?></div>
                        <div class="stat-label">Scheduled Programs</div>
                    </div>
                </div>

                <!-- Recent Registrations Panel -->
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-user-plus mr-2" style="color: var(--accent);"></i> Recent Program Registrations</h3>
                    <div class="dashboard-list">
                        <?php if (empty($recentEntries)): ?>
                            <div class="empty-state-row" style="text-align: center; padding: 20px; color: var(--muted);">No program entries registered yet.</div>
                        <?php else: ?>
                            <?php foreach ($recentEntries as $entry): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong style="display: block; font-size: 14px;"><?= e($entry['student_name']) ?></strong>
                                        <span style="font-size: 11.5px; color: var(--muted);"><i class="fa-solid fa-hashtag mr-1"></i> <?= e($entry['chest_number'] ?: 'No Chest #') ?></span>
                                    </div>
                                    <div style="text-align: right;">
                                        <span class="badge badge-info"><?= e($entry['program_name']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dashboard-sidebar-col">
                <div class="panel">
                    <h3 class="mb-4"><i class="fa-solid fa-compass mr-2" style="color: var(--accent);"></i> Quick Navigation</h3>
                    <div class="dashboard-list">
                        <a href="<?= app_url('/admin/registrar/entries.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-rectangle-list"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Program Entries Overview</div>
                                <div class="sidebar-action-subtitle">Filter & search entries</div>
                            </div>
                        </a>

                        <a href="<?= app_url('/admin/registrar/add-entry.php') ?>" class="sidebar-action-btn">
                            <div class="sidebar-action-icon" style="background: rgba(139, 92, 246, 0.15); color: var(--accent);">
                                <i class="fa-solid fa-user-plus"></i>
                            </div>
                            <div class="sidebar-action-info">
                                <div class="sidebar-action-title">Register Program Entry</div>
                                <div class="sidebar-action-subtitle">Assign students to events</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
