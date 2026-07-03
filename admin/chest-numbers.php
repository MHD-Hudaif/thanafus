<?php
$pageTitle = 'Chest Numbers';

require_once __DIR__ . '/../includes/id-card-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

$search = trim((string)($_GET['search'] ?? ''));

$stmt = $pdo->prepare("
    SELECT t.*,
           COUNT(mtm.id) AS member_count,
           SUM(CASE WHEN mtm.chest_number IS NULL OR mtm.chest_number = '' THEN 1 ELSE 0 END) AS empty_count,
           SUM(CASE WHEN mtm.chest_number IS NOT NULL AND mtm.chest_number <> '' THEN 1 ELSE 0 END) AS assigned_count
    FROM musabaqa_teams t
    LEFT JOIN musabaqa_team_members mtm
      ON mtm.team_id = t.id
     AND mtm.event_id = t.event_id
     AND mtm.status = 'active'
    WHERE t.event_id = ?
    GROUP BY t.id
    ORDER BY CAST(t.number_prefix AS UNSIGNED) ASC, t.id ASC
");
$stmt->execute([$activeEventId]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/chest-numbers.php');
    }

    try {
        if (!$teams) {
            throw new RuntimeException('Create teams before generating chest numbers.');
        }

        $ranges = [];
        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            $startRaw = trim((string)($_POST['range_start'][$teamId] ?? ''));
            $endRaw = trim((string)($_POST['range_end'][$teamId] ?? ''));

            if ($startRaw === '' || $endRaw === '') {
                throw new RuntimeException('Enter a start and end number for every team.');
            }
            if (!ctype_digit($startRaw) || !ctype_digit($endRaw)) {
                throw new RuntimeException('Chest number ranges must use whole numbers.');
            }

            $start = (int)$startRaw;
            $end = (int)$endRaw;
            $memberCount = (int)$team['member_count'];

            if ($start <= 0 || $end <= 0 || $start > $end) {
                throw new RuntimeException('Check the range for ' . $team['team_name'] . '.');
            }
            if (($end - $start + 1) < $memberCount) {
                throw new RuntimeException($team['team_name'] . ' needs at least ' . $memberCount . ' numbers.');
            }

            foreach ($ranges as $range) {
                if ($start <= $range['end'] && $end >= $range['start']) {
                    throw new RuntimeException($team['team_name'] . ' overlaps with ' . $range['team_name'] . '.');
                }
            }

            $ranges[$teamId] = [
                'start' => $start,
                'end' => $end,
                'team_name' => $team['team_name'],
            ];
        }

        $pdo->beginTransaction();
        $memberStmt = $pdo->prepare("
            SELECT mtm.id
            FROM musabaqa_team_members mtm
            JOIN kauzariyya.students s ON s.id = mtm.student_id
            LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
            WHERE mtm.event_id = ?
              AND mtm.team_id = ?
              AND mtm.status = 'active'
            ORDER BY
              CASE
                WHEN c.class_type_id = 1 THEN 1
                WHEN c.class_type_id = 2 THEN 2
                WHEN c.class_type_id = 3 THEN 3
                ELSE 4
              END ASC,
              mtm.id ASC
        ");
        $updateStmt = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ? WHERE id = ? AND event_id = ? AND team_id = ?');
        $assigned = 0;

        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            $range = $ranges[$teamId];
            $memberStmt->execute([$activeEventId, $teamId]);
            $next = $range['start'];

            foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
                $updateStmt->execute([(string)$next, (int)$member['id'], $activeEventId, $teamId]);
                $next++;
                $assigned++;
            }
        }

        $pdo->commit();
        admin_flash('success', 'Generated chest numbers for ' . $assigned . ' active member(s). Existing active chest numbers were reset.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to generate chest numbers.');
    }

    admin_redirect('/admin/chest-numbers.php');
}

$stmt = $pdo->prepare("
    SELECT
        mtm.id,
        mtm.chest_number,
        t.team_name,
        t.team_color,
        COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
        c.class_type_id,
        c.name AS section,
        ct.name AS class_type_name
    FROM musabaqa_team_members mtm
    JOIN musabaqa_teams t ON t.id = mtm.team_id
    JOIN kauzariyya.students s ON s.id = mtm.student_id
    LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
    LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
    WHERE mtm.event_id = ?
      AND mtm.status = 'active'
    ORDER BY NULLIF(mtm.chest_number, '') IS NULL ASC,
             CAST(mtm.chest_number AS UNSIGNED) ASC,
             CAST(t.number_prefix AS UNSIGNED) ASC,
             t.id ASC,
             display_name ASC
");
$stmt->execute([$activeEventId]);
$members = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
    $member['category'] = id_card_category_label($member['class_type_name'] ?? null, (int)($member['class_type_id'] ?? 0));
    if (
        $search !== ''
        && stripos((string)($member['display_name'] ?? ''), $search) === false
        && stripos((string)($member['chest_number'] ?? ''), $search) === false
        && stripos((string)($member['team_name'] ?? ''), $search) === false
        && stripos((string)($member['section'] ?? ''), $search) === false
        && stripos((string)($member['category'] ?? ''), $search) === false
    ) {
        continue;
    }
    $members[] = $member;
}

$flash = admin_take_flash();
$totalMembers = count($members);
$assignedCount = 0;
$missingCount = 0;
foreach ($members as $member) {
    if (trim((string)($member['chest_number'] ?? '')) === '') {
        $missingCount++;
    } else {
        $assignedCount++;
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Chest Numbers</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <div class="flex gap-2 flex-wrap">
            <button class="btn btn-success btn-md" type="button" data-open-generate><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
            <a href="<?= app_url('/admin/teams.php') ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-people-group"></i> Teams</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="stats-grid mb-6">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div class="stat-value"><?= $totalMembers ?></div><div class="stat-label">Active Members</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-hashtag"></i></div><div class="stat-value"><?= $assignedCount ?></div><div class="stat-label">With Chest #</div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div><div class="stat-value"><?= $missingCount ?></div><div class="stat-label">Missing Chest #</div></div>
    </div>

    <?php if ($assignedCount > 0): ?>
        <div class="alert alert-warning">Generating again will reset <?= $assignedCount ?> existing active chest number(s).</div>
    <?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <div class="input-group full-width">
                <label>Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Chest number, name, team, section or category">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($search !== ''): ?><a href="<?= app_url('/admin/chest-numbers.php') ?>" class="btn btn-secondary btn-md">Clear</a><?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$members): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-hashtag"></i></div><div class="empty-title">No Members Found</div><div class="empty-subtitle"><?= $search !== '' ? 'No members match your search.' : 'Add team members before generating chest numbers.' ?></div></div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr><th>Chest #</th><th>Display Name</th><th>Team</th><th>Section</th><th>Category</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td><strong><?= trim((string)($member['chest_number'] ?? '')) !== '' ? '#' . e((string)$member['chest_number']) : '-' ?></strong></td>
                            <td><?= e($member['display_name'] ?? '') ?></td>
                            <td><span class="team-color-pill" style="background: <?= e($member['team_color'] ?: '#14b8a6') ?>22; color:#fff;"><span class="team-color-dot" style="width:12px;height:12px;background:<?= e($member['team_color'] ?: '#14b8a6') ?>;"></span><?= e($member['team_name'] ?? '') ?></span></td>
                            <td><?= e($member['section'] ?: '-') ?></td>
                            <td><span class="badge badge-info"><?= e($member['category'] ?: '-') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="generateModal">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <div>
                <div class="modal-title">Generate Chest Numbers</div>
                <div class="page-subtitle">Order: each team, Senior, Junior, Sub Junior, then the next team.</div>
            </div>
            <button class="modal-close" type="button" data-close="generateModal"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <?php if ($assignedCount > 0): ?>
            <div class="alert alert-warning">This will reset <?= $assignedCount ?> existing active chest number(s).</div>
        <?php endif; ?>

        <?php if (!$teams): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-people-group"></i></div><div class="empty-title">No Teams Found</div><div class="empty-subtitle">Create teams before generating chest numbers.</div></div>
        <?php else: ?>
            <form method="POST">
                <?= admin_csrf_field() ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr><th>Team</th><th>Members</th><th>Empty</th><th>Start</th><th>End</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $team): ?>
                                <?php
                                    $prefix = (int)($team['number_prefix'] ?? 0);
                                    $count = (int)$team['member_count'];
                                    $suggestedStart = $prefix > 0 ? $prefix + 1 : '';
                                    $suggestedEnd = $prefix > 0 ? $prefix + 100 : '';
                                ?>
                                <tr>
                                    <td>
                                        <span class="team-color-pill" style="background: <?= e($team['team_color'] ?: '#14b8a6') ?>22; color: #fff;">
                                            <span class="team-color-dot" style="width:12px;height:12px;background:<?= e($team['team_color'] ?: '#14b8a6') ?>;"></span>
                                            <?= e($team['team_name']) ?>
                                        </span>
                                    </td>
                                    <td><?= $count ?></td>
                                    <td><?= (int)($team['empty_count'] ?? 0) ?></td>
                                    <td><input type="number" name="range_start[<?= (int)$team['id'] ?>]" min="1" step="1" value="<?= e((string)$suggestedStart) ?>" required></td>
                                    <td><input type="number" name="range_end[<?= (int)$team['id'] ?>]" min="1" step="1" value="<?= e((string)$suggestedEnd) ?>" required></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary btn-md" data-close="generateModal">Cancel</button>
                    <button class="btn btn-success btn-md" type="submit"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Chest Numbers</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelector('[data-open-generate]')?.addEventListener('click', () => openModal('generateModal'));
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', event => {
    if (event.target === modal) closeModal(modal.id);
}));
</script>
<?php admin_close_page(); ?>
