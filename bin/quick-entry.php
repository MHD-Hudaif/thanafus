<?php

$pageTitle = 'Program Entries';

require_once __DIR__ . '/../config/auth.php';

require_login();

$musabaqa_pdo  = $GLOBALS['musabaqa_pdo'];
$dashboard_pdo = $GLOBALS['dashboard_pdo'];

$activeEventId = (int)($_SESSION['active_event_id'] ?? 0);

if (!$activeEventId) {
    header('Location: ' . APP_URL . '/admin/dashboard.php');
    exit;
}

$programId = (int)($_GET['program'] ?? $_POST['program_id'] ?? 0);

if (!$programId) {
    header('Location: ' . APP_URL . '/admin/programs.php');
    exit;
}

function entries_next_number(PDO $pdo, int $programId, int $teamId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(entry_number), 0) + 1
        FROM musabaqa_program_entries
        WHERE program_id = ?
          AND team_id = ?
    ");

    $stmt->execute([$programId, $teamId]);

    return (int)$stmt->fetchColumn();
}

function entries_entry_number_from_chest(PDO $pdo, int $programId, int $teamId, ?string $chestNumber): int
{
    $digits = preg_replace('/\D+/', '', (string)$chestNumber);

    if ($digits !== '') {
        return (int)$digits;
    }

    return entries_next_number($pdo, $programId, $teamId);
}

/*
|--------------------------------------------------------------------------
| LOAD PROGRAM
|--------------------------------------------------------------------------
*/

$stmt = $musabaqa_pdo->prepare("
    SELECT
        mp.*,
        mst.name AS stage_type_name,
        ct.name AS class_type_name
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst
        ON mst.id = mp.stage_type_id
    LEFT JOIN kauzariyya.class_types ct
        ON ct.id = mp.class_type_id
    WHERE mp.id = ?
    LIMIT 1
");
$stmt->execute([$programId]);

$program = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$program
    || (int)$program['event_id'] !== $activeEventId
) {
    header('Location: ' . APP_URL . '/admin/programs.php');
    exit;
}

$programType = $program['program_type'] ?? '';

if (!in_array($programType, ['individual', 'group'], true)) {
    header('Location: ' . APP_URL . '/admin/programs.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX: GET TEAM MEMBERS FOR CREATE MODAL
|--------------------------------------------------------------------------
*/

if (isset($_GET['action']) && $_GET['action'] === 'get_team_members') {

    $teamId = (int)($_GET['team_id'] ?? 0);
    $members = [];

    if ($teamId > 0) {

        $sql = "
            SELECT
                mtm.id AS team_member_id,
                mtm.chest_number,
                s.full_name,
                s.id AS student_id
            FROM musabaqa_team_members mtm
            INNER JOIN kauzariyya.students s
                ON s.id = mtm.student_id
            LEFT JOIN kauzariyya.classes c
                ON c.id = s.class_id
            WHERE mtm.team_id = ?
              AND mtm.event_id = ?
              AND mtm.status = 'active'
        ";

        $params = [$teamId, $activeEventId];

        if (!empty($program['class_type_id'])) {
            $sql .= "
                AND c.class_type_id = ?
            ";
            $params[] = (int)$program['class_type_id'];
        }

        $sql .= "
              AND NOT EXISTS (
                    SELECT 1
                    FROM musabaqa_entry_members em
                    INNER JOIN musabaqa_program_entries mpe
                        ON mpe.id = em.entry_id
                    WHERE mpe.program_id = ?
                      AND em.team_member_id = mtm.id
              )
            ORDER BY
                CAST(NULLIF(mtm.chest_number, '') AS UNSIGNED) ASC,
                mtm.chest_number ASC,
                s.full_name ASC
        ";

        $params[] = $programId;

        $stmtMembers = $musabaqa_pdo->prepare($sql);
        $stmtMembers->execute($params);
        $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($members);
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX: MANAGE MEMBERS
|--------------------------------------------------------------------------
*/

if (isset($_GET['ajax']) && $_GET['ajax'] === 'manage_members' && isset($_GET['entry_id'])) {

    $entryId = (int)($_GET['entry_id'] ?? 0);

    $payload = [
        'entry_id' => $entryId,
        'current_members' => [],
        'available_members' => [],
    ];

    if ($entryId > 0) {

        $stmtEntry = $musabaqa_pdo->prepare("
            SELECT id, team_id, entry_name
            FROM musabaqa_program_entries
            WHERE id = ?
              AND program_id = ?
            LIMIT 1
        ");
        $stmtEntry->execute([$entryId, $programId]);
        $entryRow = $stmtEntry->fetch(PDO::FETCH_ASSOC);

        if ($entryRow) {
            $teamId = (int)$entryRow['team_id'];

            $stmtCurrent = $musabaqa_pdo->prepare("
                SELECT
                    em.id AS entry_member_id,
                    tm.id AS team_member_id,
                    tm.chest_number,
                    s.full_name,
                    c.name AS class_name
                FROM musabaqa_entry_members em
                JOIN musabaqa_team_members tm
                    ON tm.id = em.team_member_id
                JOIN kauzariyya.students s
                    ON s.id = tm.student_id
                LEFT JOIN kauzariyya.classes c
                    ON c.id = s.class_id
                WHERE em.entry_id = ?
                ORDER BY s.full_name ASC
            ");
            $stmtCurrent->execute([$entryId]);
            $payload['current_members'] = $stmtCurrent->fetchAll(PDO::FETCH_ASSOC);

            $availSql = "
                SELECT
                    tm.id AS team_member_id,
                    tm.chest_number,
                    s.full_name,
                    c.name AS class_name
                FROM musabaqa_team_members tm
                JOIN kauzariyya.students s
                    ON s.id = tm.student_id
                LEFT JOIN kauzariyya.classes c
                    ON c.id = s.class_id
                WHERE tm.team_id = ?
                  AND tm.event_id = ?
                  AND tm.status = 'active'
            ";

            $availParams = [$teamId, $activeEventId];

            if (!empty($program['class_type_id'])) {
                $availSql .= "
                    AND c.class_type_id = ?
                ";
                $availParams[] = (int)$program['class_type_id'];
            }

            $availSql .= "
                  AND NOT EXISTS (
                        SELECT 1
                        FROM musabaqa_entry_members em
                        WHERE em.entry_id = ?
                          AND em.team_member_id = tm.id
                  )
                ORDER BY
                    CAST(NULLIF(tm.chest_number, '') AS UNSIGNED) ASC,
                    tm.chest_number ASC,
                    s.full_name ASC
            ";

            $availParams[] = $entryId;

            $stmtAvail = $musabaqa_pdo->prepare($availSql);
            $stmtAvail->execute($availParams);
            $payload['available_members'] = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD TEAMS
|--------------------------------------------------------------------------
*/

$stmtTeams = $musabaqa_pdo->prepare("
    SELECT
        id,
        team_name,
        short_name,
        team_color,
        number_prefix,
        total_score
    FROM musabaqa_teams
    WHERE event_id = ?
    ORDER BY team_name ASC
");
$stmtTeams->execute([$activeEventId]);

$teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);

$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

$csrfToken = generate_csrf_token();

$selectedTeamId = (int)($_GET['team_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token.';
        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    $postedProgramId = (int)($_POST['program_id'] ?? 0);
    $postedTeamId    = (int)($_POST['team_id'] ?? 0);
    $action          = (string)($_POST['action'] ?? '');

    if ($postedProgramId !== $programId) {
        $_SESSION['error'] = 'Program mismatch.';
        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    $stmtTeam = $musabaqa_pdo->prepare("
        SELECT
            id,
            team_name,
            team_color,
            number_prefix
        FROM musabaqa_teams
        WHERE id = ?
          AND event_id = ?
        LIMIT 1
    ");
    $stmtTeam->execute([
        $postedTeamId,
        $activeEventId
    ]);

    $selectedTeam = $stmtTeam->fetch(PDO::FETCH_ASSOC);

    if (!$selectedTeam) {
        $_SESSION['error'] = 'Selected team not found.';
        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE ENTRY
    |--------------------------------------------------------------------------
    */

    if ($action === 'create_entry') {

        if ($programType === 'individual') {

            $teamMemberId = (int)($_POST['team_member_id'] ?? 0);

            if (!$teamMemberId) {
                $_SESSION['error'] = 'Please select a participant.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }

            $stmtMember = $musabaqa_pdo->prepare("
                SELECT
                    mtm.id AS team_member_id,
                    mtm.team_id,
                    mtm.student_id,
                    mtm.chest_number,
                    mtm.status,

                    s.full_name,

                    c.id AS class_id,
                    c.name AS class_name,
                    c.class_type_id,

                    ct.name AS class_type_name
                FROM musabaqa_team_members mtm
                INNER JOIN kauzariyya.students s
                    ON s.id = mtm.student_id
                LEFT JOIN kauzariyya.classes c
                    ON c.id = s.class_id
                LEFT JOIN kauzariyya.class_types ct
                    ON ct.id = c.class_type_id
                WHERE mtm.id = ?
                  AND mtm.team_id = ?
                  AND mtm.event_id = ?
                  AND mtm.status = 'active'
                LIMIT 1
            ");
            $stmtMember->execute([
                $teamMemberId,
                $postedTeamId,
                $activeEventId
            ]);

            $member = $stmtMember->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                $_SESSION['error'] = 'Participant not found in that team.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }

            if (
                !empty($program['class_type_id'])
                && (int)($member['class_type_id'] ?? 0) !== (int)$program['class_type_id']
            ) {
                $_SESSION['error'] = 'Participant does not match this program class type.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }

            $stmtDup = $musabaqa_pdo->prepare("
                SELECT em.id
                FROM musabaqa_entry_members em
                INNER JOIN musabaqa_program_entries mpe
                    ON mpe.id = em.entry_id
                WHERE mpe.program_id = ?
                  AND em.team_member_id = ?
                LIMIT 1
            ");
            $stmtDup->execute([
                $programId,
                $teamMemberId
            ]);

            if ($stmtDup->fetch()) {
                $_SESSION['error'] = 'Participant already assigned to this program.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }

            try {

                $musabaqa_pdo->beginTransaction();

                $entryNumber = entries_entry_number_from_chest(
                    $musabaqa_pdo,
                    $programId,
                    $postedTeamId,
                    $member['chest_number'] ?? null
                );

                $stmtEntry = $musabaqa_pdo->prepare("
                    INSERT INTO musabaqa_program_entries
                    (
                        event_id,
                        program_id,
                        team_id,
                        entry_name,
                        entry_number,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending'
                    )
                ");
                $stmtEntry->execute([
                    $activeEventId,
                    $programId,
                    $postedTeamId,
                    $member['full_name'],
                    $entryNumber
                ]);

                $entryId = (int)$musabaqa_pdo->lastInsertId();

                $stmtEntryMember = $musabaqa_pdo->prepare("
                    INSERT INTO musabaqa_entry_members
                    (
                        entry_id,
                        team_member_id
                    )
                    VALUES
                    (
                        ?,
                        ?
                    )
                ");
                $stmtEntryMember->execute([
                    $entryId,
                    $teamMemberId
                ]);

                $musabaqa_pdo->commit();

                $_SESSION['success'] = 'Participant assigned successfully.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;

            } catch (Throwable $e) {

                if ($musabaqa_pdo->inTransaction()) {
                    $musabaqa_pdo->rollBack();
                }

                $_SESSION['error'] = 'Database error while assigning participant.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }
        }

        if ($programType === 'group') {

            $entryName = trim($_POST['entry_name'] ?? '');

            if ($entryName === '') {
                $_SESSION['error'] = 'Entry name is required.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }

            $stmtDup = $musabaqa_pdo->prepare("
                SELECT id
                FROM musabaqa_program_entries
                WHERE program_id = ?
                  AND team_id = ?
                  AND entry_name = ?
                LIMIT 1
            ");
            $stmtDup->execute([
                $programId,
                $postedTeamId,
                $entryName
            ]);

            if ($stmtDup->fetch()) {
                $_SESSION['error'] = 'An entry with that name already exists for this team.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }

            try {

                $musabaqa_pdo->beginTransaction();

                $entryNumber = entries_next_number($musabaqa_pdo, $programId, $postedTeamId);

                $stmtEntry = $musabaqa_pdo->prepare("
                    INSERT INTO musabaqa_program_entries
                    (
                        event_id,
                        program_id,
                        team_id,
                        entry_name,
                        entry_number,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending'
                    )
                ");
                $stmtEntry->execute([
                    $activeEventId,
                    $programId,
                    $postedTeamId,
                    $entryName,
                    $entryNumber
                ]);

                $musabaqa_pdo->commit();

                $_SESSION['success'] = 'Group entry created successfully.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;

            } catch (Throwable $e) {

                if ($musabaqa_pdo->inTransaction()) {
                    $musabaqa_pdo->rollBack();
                }

                $_SESSION['error'] = 'Database error while creating entry.';
                header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId . '&team_id=' . $postedTeamId);
                exit;
            }
        }

        $_SESSION['error'] = 'Invalid action.';
        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | ADD MEMBER TO GROUP ENTRY
    |--------------------------------------------------------------------------
    */

    if ($action === 'add_member') {

        $entryId      = (int)($_POST['entry_id'] ?? 0);
        $teamMemberId = (int)($_POST['team_member_id'] ?? 0);

        if (!$entryId || !$teamMemberId) {
            $_SESSION['error'] = 'Invalid entry or member.';
            header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
            exit;
        }

        $stmtChk = $musabaqa_pdo->prepare("
            SELECT id, team_id
            FROM musabaqa_program_entries
            WHERE id = ?
              AND program_id = ?
            LIMIT 1
        ");
        $stmtChk->execute([$entryId, $programId]);
        $entryRow = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if (!$entryRow) {
            $_SESSION['error'] = 'Entry not found.';
            header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
            exit;
        }

        $stmtDupMem = $musabaqa_pdo->prepare("
            SELECT id
            FROM musabaqa_entry_members
            WHERE entry_id = ?
              AND team_member_id = ?
            LIMIT 1
        ");
        $stmtDupMem->execute([$entryId, $teamMemberId]);

        if ($stmtDupMem->fetch()) {
            $_SESSION['error'] = 'This member is already added to the entry.';
            header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
            exit;
        }

        try {
            $stmtAddMem = $musabaqa_pdo->prepare("
                INSERT INTO musabaqa_entry_members
                (
                    entry_id,
                    team_member_id
                )
                VALUES
                (
                    ?,
                    ?
                )
            ");
            $stmtAddMem->execute([$entryId, $teamMemberId]);

            $_SESSION['success'] = 'Member added successfully.';
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add member.';
        }

        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | REMOVE MEMBER FROM ENTRY
    |--------------------------------------------------------------------------
    */

    if ($action === 'remove_member') {

        $entryMemberId = (int)($_POST['entry_member_id'] ?? 0);

        if ($entryMemberId) {
            $musabaqa_pdo->prepare("
                DELETE FROM musabaqa_entry_members
                WHERE id = ?
            ")->execute([$entryMemberId]);

            $_SESSION['success'] = 'Member removed.';
        } else {
            $_SESSION['error'] = 'Invalid member.';
        }

        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE ENTRY
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_entry') {

        $entryId = (int)($_POST['entry_id'] ?? 0);

        if ($entryId) {
            try {
                $musabaqa_pdo->beginTransaction();

                $musabaqa_pdo->prepare("
                    DELETE FROM musabaqa_entry_members
                    WHERE entry_id = ?
                ")->execute([$entryId]);

                $musabaqa_pdo->prepare("
                    DELETE FROM musabaqa_program_entries
                    WHERE id = ?
                      AND program_id = ?
                ")->execute([$entryId, $programId]);

                $musabaqa_pdo->commit();

                $_SESSION['success'] = 'Entry deleted.';
            } catch (Throwable $e) {
                if ($musabaqa_pdo->inTransaction()) {
                    $musabaqa_pdo->rollBack();
                }
                $_SESSION['error'] = 'Failed to delete entry.';
            }
        }

        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT ENTRY STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_status') {

        $entryId   = (int)($_POST['entry_id'] ?? 0);
        $newStatus = (string)($_POST['new_status'] ?? '');
        $allowed   = ['pending', 'approved', 'performed', 'completed'];

        if ($entryId && in_array($newStatus, $allowed, true)) {
            $musabaqa_pdo->prepare("
                UPDATE musabaqa_program_entries
                SET status = ?
                WHERE id = ?
                  AND program_id = ?
            ")->execute([$newStatus, $entryId, $programId]);

            $_SESSION['success'] = 'Entry status updated.';
        } else {
            $_SESSION['error'] = 'Invalid status update.';
        }

        header('Location: ' . APP_URL . '/admin/entries.php?program=' . $programId);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

/*
|--------------------------------------------------------------------------
| LOAD ENTRIES
|--------------------------------------------------------------------------
*/

$query = "
    SELECT
        mpe.*,
        mt.team_name,
        mt.team_color,
        (
            SELECT COUNT(*)
            FROM musabaqa_entry_members em
            WHERE em.entry_id = mpe.id
        ) AS member_count
    FROM musabaqa_program_entries mpe
    LEFT JOIN musabaqa_teams mt
        ON mt.id = mpe.team_id
    WHERE mpe.program_id = ?
";

$params = [$programId];

if ($search !== '') {
    $query .= "
        AND (
            mpe.entry_name LIKE ?
            OR mt.team_name LIKE ?
            OR CAST(mpe.entry_number AS CHAR) LIKE ?
        )
    ";
    $wildcard = '%' . $search . '%';
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}

$query .= "
    ORDER BY
        mpe.entry_number ASC,
        mpe.id DESC
";

$stmtEntries = $musabaqa_pdo->prepare($query);
$stmtEntries->execute($params);

$entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$stmtStats = $musabaqa_pdo->prepare("
    SELECT
        COUNT(*) AS total_entries,
        SUM(CASE WHEN status = 'approved'  THEN 1 ELSE 0 END) AS approved_entries,
        SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending_entries,
        COUNT(DISTINCT team_id) AS teams_participating
    FROM musabaqa_program_entries
    WHERE program_id = ?
");
$stmtStats->execute([$programId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

$stmtParticipants = $musabaqa_pdo->prepare("
    SELECT COUNT(*) AS total
    FROM musabaqa_entry_members em
    JOIN musabaqa_program_entries mpe
        ON mpe.id = em.entry_id
    WHERE mpe.program_id = ?
");
$stmtParticipants->execute([$programId]);
$totalParticipants = (int)$stmtParticipants->fetchColumn();

/*
|--------------------------------------------------------------------------
| VIEW
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$statusMap = [
    'pending'   => ['label' => 'Pending',   'class' => 'badge-warning'],
    'approved'  => ['label' => 'Approved',  'class' => 'badge-success'],
    'performed' => ['label' => 'Performed', 'class' => 'badge-info'],
    'completed' => ['label' => 'Completed', 'class' => 'badge-neutral'],
];

?>

<div class="main-content">

    <div class="topbar">
        <div>
            <div class="page-title">
                Entries
            </div>
            <div class="page-subtitle">
                <?= e($program['title']) ?> · <?= e(ucfirst($programType)) ?> · <?= e($program['class_type_name'] ?: 'All Class Types') ?>
            </div>
        </div>

        <button class="btn btn-success btn-md" id="openCreateModal">
            <i class="fa-solid fa-plus"></i>
            Create Entry
        </button>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid mb-20">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-list-check"></i></div>
            <div class="stat-value"><?= number_format((int)($stats['total_entries'] ?? 0)) ?></div>
            <div class="stat-label">Total Entries</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-value"><?= number_format($totalParticipants) ?></div>
            <div class="stat-label">Total Participants</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-people-group"></i></div>
            <div class="stat-value"><?= number_format((int)($stats['teams_participating'] ?? 0)) ?></div>
            <div class="stat-label">Teams Participating</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-value"><?= number_format((int)($stats['approved_entries'] ?? 0)) ?></div>
            <div class="stat-label">Approved Entries</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-value"><?= number_format((int)($stats['pending_entries'] ?? 0)) ?></div>
            <div class="stat-label">Pending Entries</div>
        </div>
    </div>

    <div class="panel mb-20">
        <form method="GET" action="<?= APP_URL ?>/admin/entries.php">
            <input type="hidden" name="program" value="<?= (int)$programId ?>">

            <div class="form-grid">
                <div class="input-group">
                    <label>Search</label>
                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search by entry name, team, or number..."
                    >
                </div>

                <div class="input-group" style="display:flex; align-items:end;">
                    <button type="submit" class="btn btn-secondary btn-md w-full">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        Search
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if (empty($entries)): ?>

        <div class="empty-state">
            <div class="empty-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
            <div class="empty-title">
                <?= $search !== '' ? 'No Entries Found' : 'No Entries Created' ?>
            </div>
            <div class="empty-subtitle">
                <?= $search !== '' ? 'Try another search term.' : 'Create the first entry for this program.' ?>
            </div>
        </div>

    <?php else: ?>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Entry Name</th>
                        <th>Team</th>
                        <th>Members</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Rank</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $statusInfo = $statusMap[$entry['status'] ?? 'pending'] ?? [
                            'label' => ucfirst((string)($entry['status'] ?? 'pending')),
                            'class' => 'badge-neutral'
                        ];
                        $entryNumber = (string)($entry['entry_number'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <?= $entryNumber !== '' ? e(str_pad($entryNumber, 3, '0', STR_PAD_LEFT)) : '-' ?>
                            </td>
                            <td>
                                <?= e($entry['entry_name'] ?? '-') ?>
                            </td>
                            <td>
                                <?php if (!empty($entry['team_name'])): ?>
                                    <span class="badge badge-neutral">
                                        <?= e($entry['team_name']) ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= number_format((int)($entry['member_count'] ?? 0)) ?>
                            </td>
                            <td>
                                <span class="badge <?= e($statusInfo['class']) ?>">
                                    <?= e($statusInfo['label']) ?>
                                </span>
                            </td>
                            <td>
                                <?= isset($entry['final_score']) && $entry['final_score'] !== null ? e((string)$entry['final_score']) : '-' ?>
                            </td>
                            <td>
                                <?= isset($entry['final_rank']) && $entry['final_rank'] !== null ? e((string)$entry['final_rank']) : '-' ?>
                            </td>
                            <td>
                                <div class="flex gap-10 flex-wrap">
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm btn-view-members"
                                        data-entry-id="<?= (int)$entry['id'] ?>"
                                        data-entry-name="<?= e($entry['entry_name'] ?? 'Entry') ?>"
                                        data-mode="view"
                                    >
                                        <i class="fa-solid fa-eye"></i>
                                        View
                                    </button>

                                    <?php if ($programType === 'group'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-secondary btn-sm btn-view-members"
                                            data-entry-id="<?= (int)$entry['id'] ?>"
                                            data-entry-name="<?= e($entry['entry_name'] ?? 'Entry') ?>"
                                            data-mode="manage"
                                        >
                                            <i class="fa-solid fa-user-pen"></i>
                                            Manage
                                        </button>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        class="btn btn-info btn-sm btn-edit-status"
                                        data-entry-id="<?= (int)$entry['id'] ?>"
                                        data-current-status="<?= e($entry['status'] ?? 'pending') ?>"
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                        Status
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm btn-delete-entry"
                                        data-entry-id="<?= (int)$entry['id'] ?>"
                                        data-entry-name="<?= e($entry['entry_name'] ?? 'Entry') ?>"
                                    >
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</div>

<!-- CREATE ENTRY MODAL -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">
                Create <?= $programType === 'individual' ? 'Individual' : 'Group' ?> Entry
            </div>
            <button class="modal-close btn-close-create" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="<?= APP_URL ?>/admin/entries.php?program=<?= (int)$programId ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
            <input type="hidden" name="action" value="create_entry">

            <div class="form-grid">

                <div class="input-group full-width">
                    <label>Team</label>
                    <select name="team_id" id="createTeamSelect" required>
                        <option value="">Select Team</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>">
                                <?= e($team['team_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($programType === 'individual'): ?>
                    <div class="input-group full-width">
                        <label>Student</label>
                        <select
                            name="team_member_id"
                            id="createMemberSelect"
                            required
                            disabled
                        >
                            <option value="">Select Team First</option>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="input-group full-width">
                        <label>Entry Name</label>
                        <input
                            type="text"
                            name="entry_name"
                            placeholder="e.g. Al Qain Nasheed Team"
                            required
                        >
                    </div>
                <?php endif; ?>

            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md btn-close-create">
                    Cancel
                </button>
                <button type="submit" class="btn btn-success btn-md">
                    <i class="fa-solid fa-plus"></i>
                    Create Entry
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MEMBERS MODAL -->
<div class="modal-overlay" id="membersModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="membersModalTitle">Entry Members</div>
            <button class="modal-close btn-close-members" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div id="membersModalBody" style="padding: 10px 0 0;"></div>
    </div>
</div>

<!-- STATUS MODAL -->
<div class="modal-overlay" id="statusModal">
    <div class="modal-box" style="max-width: 560px;">
        <div class="modal-header">
            <div class="modal-title">Update Entry Status</div>
            <button class="modal-close btn-close-status" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="<?= APP_URL ?>/admin/entries.php?program=<?= (int)$programId ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="entry_id" id="statusEntryId" value="">

            <div class="input-group">
                <label>Status</label>
                <select name="new_status" id="statusSelect">
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="performed">Performed</option>
                    <option value="completed">Completed</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md btn-close-status">
                    Cancel
                </button>
                <button type="submit" class="btn btn-success btn-md">
                    Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width: 560px;">
        <div class="modal-header">
            <div class="modal-title">Delete Entry</div>
            <button class="modal-close btn-close-delete" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="panel" style="margin: 0 0 18px;">
            Are you sure you want to delete
            <strong id="deleteEntryName"></strong>?
        </div>

        <form method="POST" action="<?= APP_URL ?>/admin/entries.php?program=<?= (int)$programId ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" id="deleteEntryId" value="">

            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md btn-close-delete">
                    Cancel
                </button>
                <button type="submit" class="btn btn-danger btn-md">
                    <i class="fa-solid fa-trash"></i>
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const APP_URL   = <?= json_encode(APP_URL) ?>;
const PROGRAM_ID = <?= (int)$programId ?>;
const PROGRAM_TYPE = <?= json_encode($programType) ?>;
const CSRF = <?= json_encode($csrfToken) ?>;

function openModal(id) {
    document.getElementById(id)?.classList.add('active');
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('active');
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.classList.remove('active');
        }
    });
});

/* CREATE MODAL */
document.getElementById('openCreateModal')?.addEventListener('click', () => openModal('createModal'));
document.querySelectorAll('.btn-close-create').forEach(btn => {
    btn.addEventListener('click', () => closeModal('createModal'));
});

if (PROGRAM_TYPE === 'individual') {
    const teamSelect = document.getElementById('createTeamSelect');
    const memberSelect = document.getElementById('createMemberSelect');

    teamSelect?.addEventListener('change', async () => {
        const teamId = teamSelect.value;

        memberSelect.disabled = true;
        memberSelect.innerHTML = '<option value="">Loading...</option>';

        if (!teamId) {
            memberSelect.innerHTML = '<option value="">Select Team First</option>';
            return;
        }

        const url = `${APP_URL}/admin/entries.php?program=${PROGRAM_ID}&action=get_team_members&team_id=${encodeURIComponent(teamId)}`;

        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();

            console.log(data);

            memberSelect.innerHTML = '';

            if (!Array.isArray(data) || data.length === 0) {
                memberSelect.innerHTML = '<option value="">No members available</option>';
                memberSelect.disabled = false;
                return;
            }

            memberSelect.innerHTML = '<option value="">Select Student</option>';

            data.forEach(member => {
                const opt = document.createElement('option');
                opt.value = member.team_member_id;
                opt.textContent = member.full_name + (member.chest_number ? ` (#${member.chest_number})` : '');
                memberSelect.appendChild(opt);
            });

            memberSelect.disabled = false;
        } catch (err) {
            console.error(err);
            memberSelect.innerHTML = '<option value="">Error loading members</option>';
            memberSelect.disabled = false;
        }
    });
}

/* MEMBERS MODAL */
document.querySelectorAll('.btn-view-members').forEach(btn => {
    btn.addEventListener('click', async () => {
        const entryId = btn.dataset.entryId;
        const entryName = btn.dataset.entryName;
        const mode = btn.dataset.mode || 'view';

        document.getElementById('membersModalTitle').textContent = entryName + (mode === 'manage' ? ' · Manage Members' : ' · Members');
        document.getElementById('membersModalBody').innerHTML = '<div class="panel">Loading...</div>';
        openModal('membersModal');

        try {
            const res = await fetch(`${APP_URL}/admin/entries.php?program=${PROGRAM_ID}&ajax=manage_members&entry_id=${encodeURIComponent(entryId)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            const currentMembers = Array.isArray(data.current_members) ? data.current_members : [];
            const availableMembers = Array.isArray(data.available_members) ? data.available_members : [];

            if (mode === 'view') {
                if (currentMembers.length === 0) {
                    document.getElementById('membersModalBody').innerHTML = `
                        <div class="empty-state" style="height:auto; padding: 24px 0;">
                            <div class="empty-icon"><i class="fa-solid fa-user-group"></i></div>
                            <div class="empty-title">No Members</div>
                            <div class="empty-subtitle">This entry does not have any members yet.</div>
                        </div>
                    `;
                    return;
                }

                let html = '<div class="table-wrapper"><table><thead><tr><th>Name</th><th>Chest #</th><th>Class</th></tr></thead><tbody>';
                currentMembers.forEach(member => {
                    html += `
                        <tr>
                            <td>${escapeHtml(member.full_name || '')}</td>
                            <td>${escapeHtml(member.chest_number || '-')}</td>
                            <td>${escapeHtml(member.class_name || '-')}</td>
                        </tr>
                    `;
                });
                html += '</tbody></table></div>';

                document.getElementById('membersModalBody').innerHTML = html;
                return;
            }

            let html = '<div class="form-grid">';

            html += `
                <div class="panel">
                    <div class="page-subtitle" style="margin-top:0; margin-bottom:12px;">Current Members</div>
            `;

            if (currentMembers.length === 0) {
                html += '<div class="empty-subtitle">No members yet.</div>';
            } else {
                html += '<div class="table-wrapper"><table><thead><tr><th>Name</th><th>Chest #</th><th></th></tr></thead><tbody>';
                currentMembers.forEach(member => {
                    html += `
                        <tr>
                            <td>${escapeHtml(member.full_name || '')}</td>
                            <td>${escapeHtml(member.chest_number || '-')}</td>
                            <td>
                                <form method="POST" action="${APP_URL}/admin/entries.php?program=${PROGRAM_ID}" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="${CSRF}">
                                    <input type="hidden" name="program_id" value="${PROGRAM_ID}">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="entry_member_id" value="${escapeHtml(member.entry_member_id || '')}">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    `;
                });
                html += '</tbody></table></div>';
            }

            html += '</div>';

            html += `
                <div class="panel">
                    <div class="page-subtitle" style="margin-top:0; margin-bottom:12px;">Available Members</div>
            `;

            if (availableMembers.length === 0) {
                html += '<div class="empty-subtitle">All team members are already added.</div>';
            } else {
                html += '<div class="table-wrapper"><table><thead><tr><th>Name</th><th>Chest #</th><th></th></tr></thead><tbody>';
                availableMembers.forEach(member => {
                    html += `
                        <tr>
                            <td>${escapeHtml(member.full_name || '')}</td>
                            <td>${escapeHtml(member.chest_number || '-')}</td>
                            <td>
                                <form method="POST" action="${APP_URL}/admin/entries.php?program=${PROGRAM_ID}" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="${CSRF}">
                                    <input type="hidden" name="program_id" value="${PROGRAM_ID}">
                                    <input type="hidden" name="action" value="add_member">
                                    <input type="hidden" name="entry_id" value="${escapeHtml(entryId)}">
                                    <input type="hidden" name="team_member_id" value="${escapeHtml(member.team_member_id || '')}">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        Add
                                    </button>
                                </form>
                            </td>
                        </tr>
                    `;
                });
                html += '</tbody></table></div>';
            }

            html += '</div>';

            html += '</div>';

            document.getElementById('membersModalBody').innerHTML = html;
        } catch (err) {
            console.error(err);
            document.getElementById('membersModalBody').innerHTML = '<div class="alert alert-error">Failed to load members.</div>';
        }
    });
});

document.querySelectorAll('.btn-close-members').forEach(btn => {
    btn.addEventListener('click', () => closeModal('membersModal'));
});

/* STATUS MODAL */
document.querySelectorAll('.btn-edit-status').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('statusEntryId').value = btn.dataset.entryId || '';
        document.getElementById('statusSelect').value = btn.dataset.currentStatus || 'pending';
        openModal('statusModal');
    });
});

document.querySelectorAll('.btn-close-status').forEach(btn => {
    btn.addEventListener('click', () => closeModal('statusModal'));
});

/* DELETE MODAL */
document.querySelectorAll('.btn-delete-entry').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('deleteEntryId').value = btn.dataset.entryId || '';
        document.getElementById('deleteEntryName').textContent = btn.dataset.entryName || 'this entry';
        openModal('deleteModal');
    });
});

document.querySelectorAll('.btn-close-delete').forEach(btn => {
    btn.addEventListener('click', () => closeModal('deleteModal'));
});

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

</body>
</html>