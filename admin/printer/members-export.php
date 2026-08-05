<?php
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_once __DIR__ . '/../../includes/id-card-helpers.php';
require_login();

if (!can_access_category('printer')) {
    http_response_code(403);
    exit('Access Denied: You do not have authority to access this page.');
}

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

$export = $_GET['export'] ?? '';

if ($export !== '' && $activeEvent) {
    $activeEventId = (int)$activeEvent['id'];
    $filename = 'team-roster';
    $data = [];

    if ($export === 'all') {
        $data = id_card_members($pdo, $activeEventId);
        $filename = 'full-roster-event-' . $activeEventId . '.csv';
    } elseif ($export === 'team') {
        $teamId = (int)($_GET['team_id'] ?? 0);
        if ($teamId > 0) {
            $tStmt = $pdo->prepare("SELECT team_name FROM musabaqa_teams WHERE id = ? AND event_id = ? LIMIT 1");
            $tStmt->execute([$teamId, $activeEventId]);
            $teamName = $tStmt->fetchColumn();

            if ($teamName) {
                $filename = admin_normalize_slug($teamName) . '-roster-event-' . $activeEventId . '.csv';
                
                $stmt = $pdo->prepare("
                    SELECT
                        mtm.id AS member_id,
                        mtm.student_id,
                        mtm.chest_number,
                        mtm.status,
                        t.team_name,
                        t.team_color,
                        ev.title AS event_title,
                        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
                        s.full_name,
                        s.place,
                        s.admission_no,
                        c.class_type_id,
                        c.name AS section,
                        ct.name AS class_type_name
                    FROM musabaqa_team_members mtm
                    JOIN musabaqa_teams t ON t.id = mtm.team_id
                    JOIN musabaqa_events ev ON ev.id = mtm.event_id
                    JOIN " . DB_MAIN_NAME . ".students s ON s.id = mtm.student_id
                    LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
                    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = c.class_type_id
                    WHERE mtm.event_id = ?
                      AND mtm.team_id = ?
                      AND mtm.status = 'active'
                    ORDER BY NULLIF(mtm.chest_number, '') IS NULL ASC,
                             CAST(mtm.chest_number AS UNSIGNED) ASC, display_name ASC
                ");
                $stmt->execute([$activeEventId, $teamId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $m['category'] = id_card_category_label($m['class_type_name'] ?? null, (int)($m['class_type_id'] ?? 0));
                    $data[] = $m;
                }
            }
        }
    }

    if (!empty($data)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        $f = fopen('php://output', 'w');
        
        // UTF-8 BOM
        fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($f, ['ID', 'Chest Number', 'Student Name', 'Team Name', 'Class/Tier', 'Section']);

        foreach ($data as $m) {
            fputcsv($f, [
                $m['member_id'],
                $m['chest_number'] ?? '',
                $m['display_name'],
                $m['team_name'],
                $m['category'],
                $m['section']
            ]);
        }
        fclose($f);
        exit;
    } else {
        exit('No roster data found for export.');
    }
}

// Renders the selection panel
$pageTitle = 'CSV Export';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

$teams = [];
if ($activeEvent) {
    $activeEventId = (int)$activeEvent['id'];
    $stmt = $pdo->prepare("
        SELECT t.*,
               (SELECT COUNT(*) FROM musabaqa_team_members WHERE team_id = t.id AND status = 'active') as member_count
        FROM musabaqa_teams t
        WHERE t.event_id = ?
        ORDER BY CAST(t.number_prefix AS UNSIGNED) ASC, t.id ASC
    ");
    $stmt->execute([$activeEventId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="main-content">
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-file-csv" style="color:#10b981;"></i> Team Roster CSV Export</h1>
            <p>Download structured CSV spreadsheets of all students or select specific teams for ID card and badge printing</p>
        </div>
        <div>
            <a href="<?= app_url('/admin/printer/index.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-chevron-left mr-1"></i> Printer Space
            </a>
        </div>
    </div>

    <?php if (!$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: ?>
        <div class="stats-grid mb-6">
            <div class="stat-card" style="border-top: 5px solid var(--accent);">
                <div class="stat-icon"><i class="fa-solid fa-file-csv"></i></div>
                <div class="stat-value">CSV</div>
                <div class="stat-label">Roster Formats</div>
            </div>
            <div class="stat-card" style="border-top: 5px solid #10b981;">
                <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                <div class="stat-value"><?= count($teams) ?></div>
                <div class="stat-label">Registered Teams</div>
            </div>
            <div class="stat-card" style="border-top: 5px solid #3b82f6;">
                <div class="stat-icon"><i class="fa-solid fa-download"></i></div>
                <div class="stat-value"><a href="<?= app_url('/admin/printer/members-export.php') ?>?export=all" class="btn btn-success btn-xs"><i class="fa-solid fa-download mr-1"></i> Export All</a></div>
                <div class="stat-label">Download Full Event Roster</div>
            </div>
        </div>

        <div class="panel">
            <div class="page-subtitle mb-4">Export Roster by Team</div>
            
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Team ID</th>
                            <th>Team Name</th>
                            <th>Team Color</th>
                            <th>Prefix</th>
                            <th>Members Count</th>
                            <th style="width: 150px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teams)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 35px; color: var(--muted);">No teams registered for this event.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teams as $team): ?>
                                <tr>
                                    <td><strong>#<?= (int)$team['id'] ?></strong></td>
                                    <td><strong><?= e($team['team_name']) ?></strong></td>
                                    <td>
                                        <span class="team-color-pill" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>22; color:#fff;">
                                            <span class="team-color-dot" style="width:12px;height:12px;background:<?= e($team['team_color'] ?: '#14b8a6') ?>;"></span>
                                            <?= e($team['team_color'] ?: '#14b8a6') ?>
                                        </span>
                                    </td>
                                    <td><span class="badge badge-neutral"><?= e($team['number_prefix'] ?: '-') ?></span></td>
                                    <td><span class="badge badge-neutral"><?= (int)$team['member_count'] ?> active members</span></td>
                                    <td style="text-align: center;">
                                        <a href="<?= app_url('/admin/printer/members-export.php') ?>?export=team&team_id=<?= (int)$team['id'] ?>" class="btn btn-secondary btn-sm">
                                            <i class="fa-solid fa-download mr-1"></i> Export CSV
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
