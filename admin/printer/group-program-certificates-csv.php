<?php
declare(strict_types=1);

$pageTitle = 'Group Program Certificate CSV';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

if (!can_access_category('printer')) {
    http_response_code(403);
    exit('Access Denied: You do not have authority to access this page.');
}

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function group_program_certificate_rows(PDO $pdo, int $eventId, ?int $programId = null, ?int $teamId = null): array
{
    $sql = '
        SELECT
            COALESCE(NULLIF(s.display_name, \'\'), s.full_name) AS name,
            p.title AS program
        FROM musabaqa_programs p
        JOIN musabaqa_program_entries pe
          ON pe.program_id = p.id
         AND pe.event_id = p.event_id
        JOIN musabaqa_entry_members em ON em.entry_id = pe.id
        JOIN musabaqa_team_members tm
          ON tm.id = em.team_member_id
         AND tm.event_id = p.event_id
        JOIN ' . DB_MAIN_NAME . '.students s ON s.id = tm.student_id
        WHERE p.event_id = ?
          AND p.program_type = \'group\'
    ';
    $params = [$eventId];

    if ($programId !== null) {
        $sql .= ' AND p.id = ?';
        $params[] = $programId;
    }

    if ($teamId !== null) {
        $sql .= ' AND pe.team_id = ?';
        $params[] = $teamId;
    }

    $sql .= ' ORDER BY p.title ASC, name ASC, pe.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function group_program_certificate_programs(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('
        SELECT
            p.id,
            p.title,
            COUNT(em.id) AS participant_count
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_program_entries pe
          ON pe.program_id = p.id
         AND pe.event_id = p.event_id
        LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
        WHERE p.event_id = ?
          AND p.program_type = \'group\'
        GROUP BY p.id, p.title
        ORDER BY p.title ASC, p.id ASC
    ');
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function group_program_certificate_teams(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('
        SELECT
            p.id AS program_id,
            pe.team_id,
            t.team_name,
            t.team_color,
            pe.final_rank,
            COUNT(em.id) AS participant_count
        FROM musabaqa_programs p
        JOIN musabaqa_program_entries pe
          ON pe.program_id = p.id
         AND pe.event_id = p.event_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
        WHERE p.event_id = ?
          AND p.program_type = \'group\'
        GROUP BY p.id, pe.id, pe.team_id, t.team_name, t.team_color, pe.final_rank
        ORDER BY p.id ASC,
                 CASE WHEN pe.final_rank IS NULL THEN 1 ELSE 0 END ASC,
                 pe.final_rank ASC,
                 t.team_name ASC
    ');
    $stmt->execute([$eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$downloadProgramId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);
$downloadTeamId = (int)($_GET['team_id'] ?? $_POST['team_id'] ?? 0);

if ((($_GET['download'] ?? '') === 'csv') || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_csv']))) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/printer/group-program-certificates-csv.php');
    }

    if ($downloadProgramId <= 0) {
        http_response_code(400);
        exit('Please select a group program.');
    }

    if ($downloadTeamId <= 0) {
        http_response_code(400);
        exit('Please select a group.');
    }

    $rows = group_program_certificate_rows($pdo, $activeEventId, $downloadProgramId, $downloadTeamId);
    $filename = 'group-program-certificates-program-' . $downloadProgramId . '-team-' . $downloadTeamId . '.csv';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['name', 'program']);

    foreach ($rows as $row) {
        fputcsv($output, [$row['name'] ?? '', $row['program'] ?? '']);
    }

    fclose($output);
    exit;
}

$programs = group_program_certificate_programs($pdo, $activeEventId);
$teamsByProgram = [];
foreach (group_program_certificate_teams($pdo, $activeEventId) as $team) {
    $teamsByProgram[(int)$team['program_id']][] = $team;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1><i class="fa-solid fa-file-csv" style="color:#22c55e;"></i> Group Program Certificate CSV</h1>
            <p>Download certificate recipient data for all group program participants.</p>
        </div>
        <a href="<?= app_url('/admin/printer/index.php') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Print Center
        </a>
    </div>

    <div class="panel" style="max-width: 900px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap;">
            <div>
                <h3 style="margin:0 0 6px;">Certificate export</h3>
                <p style="margin:0; color:var(--muted);">Open a program to choose one of its groups. Each CSV contains only <code>name</code> and <code>program</code>.</p>
            </div>
            <span class="badge badge-neutral"><?= count($programs) ?> group program<?= count($programs) === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <div class="panel" style="max-width: 900px; margin-top:20px;">
        <h3 style="margin-top:0;">Group programs</h3>
        <?php if (empty($programs)): ?>
            <p style="color:var(--muted); margin-bottom:0;">No group programs have been created for this event yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Program</th><th>Participants</th><th style="text-align:right;">Groups</th></tr></thead>
                    <tbody>
                        <?php foreach ($programs as $program): ?>
                            <?php $participantCount = (int)$program['participant_count']; ?>
                            <tr>
                                <td><strong><?= e($program['title']) ?></strong></td>
                                <td><?= $participantCount ?> participant<?= $participantCount === 1 ? '' : 's' ?></td>
                                <td style="text-align:right;">
                                    <?php if (!empty($teamsByProgram[(int)$program['id']])): ?>
                                        <button type="button" class="btn btn-success btn-sm open-program-groups" data-modal-id="program-groups-<?= (int)$program['id'] ?>" style="display:inline-flex !important; visibility:visible !important; opacity:1 !important;">
                                            <i class="fa-solid fa-users"></i> View groups
                                        </button>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">No participants</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($programs as $program): ?>
        <?php $programTeams = $teamsByProgram[(int)$program['id']] ?? []; ?>
        <dialog class="program-groups-modal" id="program-groups-<?= (int)$program['id'] ?>">
            <div class="program-groups-modal-head">
                <div>
                    <span>Certificate groups</span>
                    <h2><?= e($program['title']) ?></h2>
                </div>
                <button type="button" class="program-groups-close" aria-label="Close">&times;</button>
            </div>
            <p class="program-groups-modal-note">Select a group to download its certificate CSV.</p>
            <div class="program-groups-list">
                <?php foreach ($programTeams as $team): ?>
                    <?php
                    $rank = $team['final_rank'] !== null ? (int)$team['final_rank'] : 0;
                    $rankLabel = $rank > 0 ? '#' . $rank : '—';
                    ?>
                    <a class="program-group-download" href="<?= app_url('/admin/printer/group-program-certificates-csv.php') ?>?download=csv&amp;program_id=<?= (int)$program['id'] ?>&amp;team_id=<?= (int)$team['team_id'] ?>" style="--team-color:<?= e($team['team_color'] ?: '#22c55e') ?>;">
                        <span class="program-group-rank"><?= e($rankLabel) ?></span>
                        <span class="program-group-name"><strong><?= e($team['team_name']) ?></strong><small><?= (int)$team['participant_count'] ?> participant<?= (int)$team['participant_count'] === 1 ? '' : 's' ?></small></span>
                        <span class="program-group-download-label"><i class="fa-solid fa-download"></i> CSV</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </dialog>
    <?php endforeach; ?>
</div>

<style>
.program-groups-modal{width:min(620px,calc(100vw - 32px));padding:0;border:1px solid rgba(255,255,255,.18);border-radius:18px;color:#fff;background:#111827;box-shadow:0 28px 75px rgba(0,0,0,.6)}
.program-groups-modal::backdrop{background:rgba(2,6,23,.75);backdrop-filter:blur(5px)}
.program-groups-modal-head{display:flex;justify-content:space-between;gap:20px;align-items:start;padding:24px 26px 18px;border-bottom:1px solid rgba(255,255,255,.1)}
.program-groups-modal-head span{color:#86efac;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.program-groups-modal-head h2{margin:5px 0 0;font-size:24px}.program-groups-close{border:0;background:transparent;color:#cbd5e1;font-size:30px;line-height:1;cursor:pointer}.program-groups-modal-note{margin:18px 26px;color:#94a3b8}.program-groups-list{display:grid;gap:10px;padding:0 26px 26px}
.program-group-download{display:grid;grid-template-columns:48px minmax(0,1fr) auto;align-items:center;gap:13px;padding:12px;border:1px solid rgba(255,255,255,.12);border-left:4px solid var(--team-color);border-radius:12px;color:#fff;text-decoration:none;background:rgba(255,255,255,.04)}.program-group-download:hover{background:rgba(255,255,255,.09);border-color:var(--team-color)}.program-group-rank{display:grid;place-items:center;width:42px;height:42px;border-radius:10px;background:color-mix(in srgb,var(--team-color) 26%,#0f172a);color:#fff;font-weight:900}.program-group-name{display:grid;gap:3px;min-width:0}.program-group-name strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.program-group-name small{color:#94a3b8}.program-group-download-label{color:#86efac;font-size:12px;font-weight:800}
</style>
<script>
document.querySelectorAll('.open-program-groups').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.modalId)?.showModal()));
document.querySelectorAll('.program-groups-close').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
document.querySelectorAll('.program-groups-modal').forEach((modal) => modal.addEventListener('click', (event) => { if (event.target === modal) modal.close(); }));
</script>

<?php admin_close_page(); ?>
