<?php
$pageTitle = 'Chest Numbers';

require_once __DIR__ . '/../includes/id-card-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

if (isset($_GET['ajax_lookup']) && $_GET['ajax_lookup'] === '1') {
    $lookupNumber = trim((string)($_GET['chest_number'] ?? ''));
    $memberId = (int)($_GET['member_id'] ?? 0);

    header('Content-Type: application/json; charset=utf-8');

    if ($lookupNumber === '') {
        echo json_encode(['exists' => false]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            mtm.id,
            mtm.chest_number,
            COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
            t.team_name,
            t.team_color,
            c.name AS section,
            ct.name AS class_type_name,
            c.class_type_id
        FROM musabaqa_team_members mtm
        JOIN musabaqa_teams t ON t.id = mtm.team_id
        JOIN kauzariyya.students s ON s.id = mtm.student_id
        LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
        LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
        WHERE mtm.event_id = ?
          AND mtm.chest_number = ?
          AND mtm.id != ?
          AND mtm.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$activeEventId, $lookupNumber, $memberId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($match) {
        $match['category'] = id_card_category_label($match['class_type_name'] ?? null, (int)($match['class_type_id'] ?? 0));
        echo json_encode([
            'exists' => true,
            'member' => [
                'id' => (int)$match['id'],
                'display_name' => $match['display_name'],
                'chest_number' => $match['chest_number'],
                'team_name' => $match['team_name'],
                'team_color' => $match['team_color'] ?: '#14b8a6',
                'section' => $match['section'] ?: '-',
                'category' => $match['category'] ?: '-'
            ]
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit;
}

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

    if (isset($_POST['download_csv'])) {
        try {
            $idMembers = id_card_members($pdo, $activeEventId);
            $filename = 'id-cards-event-' . $activeEventId . '.csv';

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['chest_number', 'display_name', 'team_name', 'team_color', 'category']);

            foreach ($idMembers as $m) {
                $mCategory = id_card_category_label($m['class_type_name'] ?? null, (int)($m['class_type_id'] ?? 0));

                fputcsv($output, [
                    $m['chest_number'] ?? '',
                    $m['display_name'] ?? '',
                    $m['team_name'] ?? '',
                    $m['team_color'] ?? '',
                    $mCategory,
                ]);
            }

            fclose($output);
            exit;
        } catch (Throwable $e) {
            admin_flash('error', $e->getMessage() ?: 'Unable to generate CSV.');
            admin_redirect('/admin/chest-numbers.php');
        }
    }

    if (($_POST['action'] ?? '') === 'manual_update') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $newChestNumber = trim((string)($_POST['chest_number'] ?? ''));

        if ($memberId <= 0) {
            admin_flash('error', 'Select a valid participant.');
            admin_redirect('/admin/chest-numbers.php');
        }

        try {
            $stmt = $pdo->prepare("
                SELECT mtm.id, mtm.chest_number, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name
                FROM musabaqa_team_members mtm
                JOIN kauzariyya.students s ON s.id = mtm.student_id
                WHERE mtm.id = ? AND mtm.event_id = ?
                LIMIT 1
            ");
            $stmt->execute([$memberId, $activeEventId]);
            $currentMember = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentMember) {
                throw new RuntimeException('Participant not found.');
            }

            $oldChestNumber = trim((string)($currentMember['chest_number'] ?? ''));

            if ($newChestNumber === '') {
                $updateStmt = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = NULL WHERE id = ? AND event_id = ?');
                $updateStmt->execute([$memberId, $activeEventId]);
                admin_flash('success', 'Cleared chest number for ' . $currentMember['display_name'] . '.');
            } else {
                $findConflict = $pdo->prepare("
                    SELECT mtm.id, mtm.chest_number, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name
                    FROM musabaqa_team_members mtm
                    JOIN kauzariyya.students s ON s.id = mtm.student_id
                    WHERE mtm.event_id = ? AND mtm.chest_number = ? AND mtm.id != ?
                    LIMIT 1
                ");
                $findConflict->execute([$activeEventId, $newChestNumber, $memberId]);
                $conflictingMember = $findConflict->fetch(PDO::FETCH_ASSOC);

                $pdo->beginTransaction();

                if ($conflictingMember) {
                    $otherMemberId = (int)$conflictingMember['id'];
                    $updateOther = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ? WHERE id = ? AND event_id = ?');
                    $updateOther->execute([$oldChestNumber !== '' ? $oldChestNumber : NULL, $otherMemberId, $activeEventId]);

                    $updateCurrent = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ? WHERE id = ? AND event_id = ?');
                    $updateCurrent->execute([$newChestNumber, $memberId, $activeEventId]);

                    $pdo->commit();

                    $msg = 'Assigned chest number #' . $newChestNumber . ' to ' . $currentMember['display_name'] . '.';
                    if ($oldChestNumber !== '') {
                        $msg .= ' Swapped chest number #' . $oldChestNumber . ' with ' . $conflictingMember['display_name'] . '.';
                    } else {
                        $msg .= ' Removed chest number #' . $newChestNumber . ' from ' . $conflictingMember['display_name'] . '.';
                    }
                    admin_flash('success', $msg);
                } else {
                    $updateCurrent = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ? WHERE id = ? AND event_id = ?');
                    $updateCurrent->execute([$newChestNumber, $memberId, $activeEventId]);

                    $pdo->commit();
                    admin_flash('success', 'Assigned chest number #' . $newChestNumber . ' to ' . $currentMember['display_name'] . '.');
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            admin_flash('error', $e->getMessage() ?: 'Unable to update chest number.');
        }

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

        // Reset all existing chest numbers for this event first to avoid duplicates or orphans
        $clearStmt = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = NULL WHERE event_id = ?');
        $clearStmt->execute([$activeEventId]);

        $stmtSenior = $pdo->prepare("
            SELECT mtm.id
            FROM musabaqa_team_members mtm
            JOIN kauzariyya.students s ON s.id = mtm.student_id
            LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
            LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
            WHERE mtm.event_id = ? AND mtm.team_id = ? AND mtm.status = 'active'
              AND (ct.name LIKE '%عالية%' OR ct.name LIKE '%العالية%' OR c.class_type_id = 1)
            ORDER BY COALESCE(NULLIF(c.priority, 0), 999999) ASC, RAND()
        ");

        $stmtJunior = $pdo->prepare("
            SELECT mtm.id
            FROM musabaqa_team_members mtm
            JOIN kauzariyya.students s ON s.id = mtm.student_id
            LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
            LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
            WHERE mtm.event_id = ? AND mtm.team_id = ? AND mtm.status = 'active'
              AND (ct.name LIKE '%ثانوية%' OR ct.name LIKE '%الثانوية%' OR c.class_type_id = 2)
            ORDER BY COALESCE(NULLIF(c.priority, 0), 999999) ASC, RAND()
        ");

        $stmtSubJunior = $pdo->prepare("
            SELECT mtm.id
            FROM musabaqa_team_members mtm
            JOIN kauzariyya.students s ON s.id = mtm.student_id
            LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
            LEFT JOIN kauzariyya.class_types ct ON ct.id = c.class_type_id
            WHERE mtm.event_id = ? AND mtm.team_id = ? AND mtm.status = 'active'
              AND (
                ct.name LIKE '%حفظ%' OR ct.name LIKE '%تحصص%' OR c.class_type_id = 3
                OR (
                    NOT (ct.name LIKE '%عالية%' OR ct.name LIKE '%العالية%' OR c.class_type_id = 1)
                    AND NOT (ct.name LIKE '%ثانوية%' OR ct.name LIKE '%الثانوية%' OR c.class_type_id = 2)
                )
              )
            ORDER BY COALESCE(NULLIF(c.priority, 0), 999999) ASC, RAND()
        ");

        $updateStmt = $pdo->prepare('UPDATE musabaqa_team_members SET chest_number = ? WHERE id = ? AND event_id = ? AND team_id = ?');
        $assigned = 0;

        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            $range = $ranges[$teamId];
            $teamStart = (int)$range['start'];

            $seniorStart = $teamStart;          // e.g. 101 (01 to 25)
            $juniorStart = $teamStart + 25;     // e.g. 126 (26 to 50)
            $subJuniorStart = $teamStart + 50;  // e.g. 151 (51 onwards)

            // 1. Assign Senior members (Fixed Block: prefix+01 to prefix+25)
            $stmtSenior->execute([$activeEventId, $teamId]);
            $seniorMembers = $stmtSenior->fetchAll(PDO::FETCH_COLUMN);
            $currSenior = $seniorStart;
            foreach ($seniorMembers as $memberId) {
                $updateStmt->execute([(string)$currSenior, (int)$memberId, $activeEventId, $teamId]);
                $currSenior++;
                $assigned++;
            }

            // 2. Assign Junior members (Fixed Block: prefix+26 to prefix+50)
            $stmtJunior->execute([$activeEventId, $teamId]);
            $juniorMembers = $stmtJunior->fetchAll(PDO::FETCH_COLUMN);
            $currJunior = $juniorStart;
            foreach ($juniorMembers as $memberId) {
                $updateStmt->execute([(string)$currJunior, (int)$memberId, $activeEventId, $teamId]);
                $currJunior++;
                $assigned++;
            }

            // 3. Assign Sub Junior members (Fixed Block: prefix+51 to end of range)
            $stmtSubJunior->execute([$activeEventId, $teamId]);
            $subJuniorMembers = $stmtSubJunior->fetchAll(PDO::FETCH_COLUMN);
            $currSubJunior = $subJuniorStart;
            foreach ($subJuniorMembers as $memberId) {
                $updateStmt->execute([(string)$currSubJunior, (int)$memberId, $activeEventId, $teamId]);
                $currSubJunior++;
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

if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['chest_numbers_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['chest_numbers_limit']) ? $_SESSION['chest_numbers_limit'] : 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedMembers = array_slice($members, $offset, $perPage);

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedMembers) {
        echo '<tr><td colspan="6" class="empty-state-row" style="text-align: center; padding: 30px; color: var(--muted);"><div class="empty-title">No Members Found</div></td></tr>';
    } else {
        foreach ($paginatedMembers as $member) {
            ?>
            <tr>
                <td><strong><?= trim((string)($member['chest_number'] ?? '')) !== '' ? '#' . e((string)$member['chest_number']) : '-' ?></strong></td>
                <td><?= e($member['display_name'] ?? '') ?></td>
                <td><span class="team-color-pill" style="background: <?= e($member['team_color'] ?: '#14b8a6') ?>22; color:#fff;"><span class="team-color-dot" style="width:12px;height:12px;background:<?= e($member['team_color'] ?: '#14b8a6') ?>;"></span><?= e($member['team_name'] ?? '') ?></span></td>
                <td><?= e($member['section'] ?: '-') ?></td>
                <td><span class="badge badge-info"><?= e($member['category'] ?: '-') ?></span></td>
                <td style="text-align: right;">
                    <button class="btn btn-secondary btn-sm" type="button" data-edit-member='<?= json_encode($member, JSON_HEX_APOS | JSON_HEX_QUOT) ?>' title="Edit Chest Number">
                        <i class="fa-solid fa-pen"></i> Edit
                    </button>
                </td>
            </tr>
            <?php
        }
    }
    $tbodyHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'html' => $tbodyHtml,
        'pagination' => admin_render_pagination_html($page, $perPage, $totalMembers)
    ]);
    exit;
}

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
            <div class="page-title">Chest Numbers &amp; ID Cards</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <div class="flex gap-2 flex-wrap">
            <button class="btn btn-secondary btn-md" type="button" data-open-manual><i class="fa-solid fa-pen-to-square"></i> Assign / Edit Chest #</button>
            <button class="btn btn-success btn-md" type="button" data-open-generate><i class="fa-solid fa-wand-magic-sparkles"></i> Auto Generate</button>
            
            <form method="POST" action="" style="display:inline-flex; gap:8px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <button class="btn btn-info btn-md" type="submit" name="download_csv" value="1"><i class="fa-solid fa-file-csv"></i> Download CSV</button>
            </form>
            
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
        <form method="GET" class="form-grid" id="search-form">
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
                    <tr><th>Chest #</th><th>Display Name</th><th>Team</th><th>Section</th><th>Category</th><th style="width: 90px; text-align: right;">Action</th></tr>
                </thead>
                <tbody id="table-body">
                    <?php foreach ($paginatedMembers as $member): ?>
                        <tr>
                            <td><strong><?= trim((string)($member['chest_number'] ?? '')) !== '' ? '#' . e((string)$member['chest_number']) : '-' ?></strong></td>
                            <td><?= e($member['display_name'] ?? '') ?></td>
                            <td><span class="team-color-pill" style="background: <?= e($member['team_color'] ?: '#14b8a6') ?>22; color:#fff;"><span class="team-color-dot" style="width:12px;height:12px;background:<?= e($member['team_color'] ?: '#14b8a6') ?>;"></span><?= e($member['team_name'] ?? '') ?></span></td>
                            <td><?= e($member['section'] ?: '-') ?></td>
                            <td><span class="badge badge-info"><?= e($member['category'] ?: '-') ?></span></td>
                            <td style="text-align: right;">
                                <button class="btn btn-secondary btn-sm" type="button" data-edit-member='<?= json_encode($member, JSON_HEX_APOS | JSON_HEX_QUOT) ?>' title="Edit Chest Number">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="pagination-container">
            <?= admin_render_pagination_html($page, $perPage, $totalMembers) ?>
        </div>
    <?php endif; ?>
</div> <!-- Closes main-content -->

<style>
body.modal-open {
    overflow: hidden !important;
}
.modal-overlay {
    z-index: 10000 !important;
    align-items: flex-start !important;
    overflow-y: auto !important;
    padding-top: 5vh !important;
    padding-bottom: 5vh !important;
}
.modal-box {
    margin: 0 auto !important;
}
</style>

<div class="modal-overlay" id="manualEditModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="manualModalTitle">Assign / Edit Chest Number</div>
                <div class="page-subtitle">Assign or swap chest number for a participant</div>
            </div>
            <button class="modal-close" type="button" data-close="manualEditModal"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" id="manualEditForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="manual_update">
            
            <div class="input-group full-width mb-4">
                <label>Participant</label>
                <select name="member_id" id="manualMemberSelect" class="form-select" required style="width: 100%; padding: 10px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--surface-2); color: var(--text);">
                    <option value="">-- Select Participant --</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" data-chest="<?= e($m['chest_number'] ?? '') ?>">
                            <?= e($m['display_name']) ?> (<?= e($m['team_name']) ?>) <?= trim((string)($m['chest_number'] ?? '')) !== '' ? '[#' . e((string)$m['chest_number']) . ']' : '[No Chest #]' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group full-width mb-4">
                <label>Chest Number</label>
                <input type="text" name="chest_number" id="manualChestNumber" placeholder="e.g. 105" autocomplete="off" style="width: 100%; padding: 10px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--surface-2); color: var(--text);">
            </div>

            <!-- Small Conflict / Info Box -->
            <div id="chestConflictBox" class="mb-4" style="display: none;"></div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-close="manualEditModal">Cancel</button>
                <button type="submit" class="btn btn-success btn-md" id="manualSubmitBtn"><i class="fa-solid fa-check"></i> Save & Assign</button>
            </div>
        </form>
    </div>
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
(() => {

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    let lookupDebounceTimer = null;

    function checkChestConflict() {
        const memberId = document.getElementById('manualMemberSelect')?.value || 0;
        const chestNumber = document.getElementById('manualChestNumber')?.value.trim() || '';
        const conflictBox = document.getElementById('chestConflictBox');

        if (!conflictBox) return;

        if (!chestNumber) {
            conflictBox.style.display = 'none';
            conflictBox.innerHTML = '';
            return;
        }

        clearTimeout(lookupDebounceTimer);
        lookupDebounceTimer = setTimeout(() => {
            fetch(`chest-numbers.php?ajax_lookup=1&chest_number=${encodeURIComponent(chestNumber)}&member_id=${encodeURIComponent(memberId)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.exists && data.member) {
                        const m = data.member;
                        conflictBox.style.display = 'block';
                        conflictBox.innerHTML = `
                            <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 12px; display: flex; gap: 12px; align-items: center;">
                                <div style="font-size: 24px; color: #ef4444;"><i class="fa-solid fa-arrows-rotate"></i></div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 700; font-size: 13px; color: #ef4444; margin-bottom: 2px;">
                                        Chest #${escapeHtml(chestNumber)} is assigned to another participant
                                    </div>
                                    <div style="font-size: 13px; color: var(--text);">
                                        <strong>${escapeHtml(m.display_name)}</strong>
                                        <span class="team-color-pill" style="background: ${escapeHtml(m.team_color)}22; color:#fff; font-size:11px; padding:2px 8px; border-radius:12px; margin-left:6px;">
                                            <span class="team-color-dot" style="width:8px;height:8px;background:${escapeHtml(m.team_color)};display:inline-block;border-radius:50%;margin-right:4px;"></span>
                                            ${escapeHtml(m.team_name)}
                                        </span>
                                        <span class="badge badge-info" style="font-size:10px; margin-left:4px;">${escapeHtml(m.category)}</span>
                                    </div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                                        Saving will <strong>swap</strong> chest numbers between these two participants.
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        conflictBox.style.display = 'block';
                        conflictBox.innerHTML = `
                            <div style="background: rgba(34, 197, 94, 0.08); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; padding: 8px 12px; display: flex; gap: 8px; align-items: center;">
                                <div style="color: #22c55e;"><i class="fa-solid fa-circle-check"></i></div>
                                <div style="font-size: 13px; color: #22c55e; font-weight: 600;">
                                    Chest #${escapeHtml(chestNumber)} is available.
                                </div>
                            </div>
                        `;
                    }
                })
                .catch(() => {
                    conflictBox.style.display = 'none';
                });
        }, 200);
    }

    function bindManualEditButtons() {
        document.querySelectorAll('[data-edit-member]').forEach(btn => {
            btn.onclick = () => {
                try {
                    const member = JSON.parse(btn.dataset.editMember);
                    const select = document.getElementById('manualMemberSelect');
                    if (select) {
                        select.value = member.id;
                    }
                    const input = document.getElementById('manualChestNumber');
                    if (input) {
                        input.value = member.chest_number || '';
                    }
                    checkChestConflict();
                    window.openModal('manualEditModal');
                    document.body.classList.add('modal-open');
                } catch (e) {
                    console.error(e);
                }
            };
        });
    }

    document.querySelector('[data-open-manual]')?.addEventListener('click', () => {
        document.getElementById('manualMemberSelect').value = '';
        document.getElementById('manualChestNumber').value = '';
        document.getElementById('chestConflictBox').style.display = 'none';
        window.openModal('manualEditModal');
        document.body.classList.add('modal-open');
    });

    document.querySelector('[data-open-generate]')?.addEventListener('click', () => {
        window.openModal('generateModal');
        document.body.classList.add('modal-open');
    });

    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => {
        window.closeModal(btn.dataset.close);
        document.body.classList.remove('modal-open');
    }));

    document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', event => {
        if (event.target === modal) {
            window.closeModal(modal.id);
            document.body.classList.remove('modal-open');
        }
    }));

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            window.closeModal('generateModal');
            window.closeModal('manualEditModal');
            document.body.classList.remove('modal-open');
        }
    });

    document.getElementById('manualChestNumber')?.addEventListener('input', checkChestConflict);
    document.getElementById('manualMemberSelect')?.addEventListener('change', () => {
        const select = document.getElementById('manualMemberSelect');
        const selectedOpt = select.options[select.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.chest !== undefined) {
            document.getElementById('manualChestNumber').value = selectedOpt.dataset.chest;
        }
        checkChestConflict();
    });

    bindManualEditButtons();

    // Hook into AJAX pagination re-rendering if active
    const tableBody = document.getElementById('table-body');
    if (tableBody) {
        const observer = new MutationObserver(() => {
            bindManualEditButtons();
        });
        observer.observe(tableBody, { childList: true });
    }

})();
</script>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
