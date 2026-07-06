<?php
$pageTitle = 'Add Members';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$activeTeamId = (int)($_SESSION['active_team_id'] ?? $_GET['team'] ?? 0);

if ($activeTeamId <= 0) {
    admin_flash('error', 'Please select a team first.');
    admin_redirect('/admin/teams.php');
}

$stmt = $pdo->prepare('SELECT * FROM musabaqa_teams WHERE id = ? AND event_id = ? LIMIT 1');
$stmt->execute([$activeTeamId, $activeEventId]);
$activeTeam = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activeTeam) {
    unset($_SESSION['active_team_id']);
    admin_flash('error', 'Selected team was not found in this event.');
    admin_redirect('/admin/teams.php');
}
$_SESSION['active_team_id'] = $activeTeamId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/add-members.php', ['team' => $activeTeamId]);
    }

    $studentIds = array_values(array_unique(array_map('intval', (array)($_POST['student_ids'] ?? []))));
    if (!$studentIds) {
        admin_flash('error', 'Please select at least one student.');
        admin_redirect('/admin/add-members.php', ['team' => $activeTeamId]);
    }

    try {
        $pdo->beginTransaction();

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id
            FROM musabaqa_team_members
            WHERE event_id = ?
              AND student_id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$activeEventId], $studentIds));
        $existing = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'student_id'));
        $newStudentIds = array_values(array_diff($studentIds, $existing));

        if (!$newStudentIds) {
            throw new RuntimeException('All selected students are already assigned in this event.');
        }

        $insert = $pdo->prepare('INSERT INTO musabaqa_team_members (event_id, team_id, student_id, chest_number, status) VALUES (?, ?, ?, ?, "active")');
        foreach ($newStudentIds as $studentId) {
            $insert->execute([$activeEventId, $activeTeamId, $studentId, null]);
        }

        $pdo->commit();
        admin_flash('success', count($newStudentIds) . ' member(s) added successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Unable to add members.');
    }

    admin_redirect('/admin/add-members.php', ['team' => $activeTeamId]);
}

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));

$stmt = $pdo->prepare("
    SELECT tm.student_id, t.team_name, t.team_color
    FROM musabaqa_team_members tm
    JOIN musabaqa_teams t ON t.id = tm.team_id
    WHERE tm.event_id = ?
");
$stmt->execute([$activeEventId]);
$existingMembers = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingMembers[(int)$row['student_id']] = $row;
}

$query = "
    SELECT s.id, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name, c.name AS class_name, ct.name AS class_type
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id
    LEFT JOIN class_types ct ON ct.id = c.class_type_id
    WHERE s.status = 'active'
";
$params = [];
if ($search !== '') {
    $query .= " AND (COALESCE(NULLIF(s.display_name, ''), s.full_name) LIKE ? OR s.admission_no LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
$query .= ' ORDER BY c.name ASC, full_name ASC';
$stmt = $dashboardPdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classes = [];
foreach ($students as $student) {
    $classes[$student['class_name'] ?: 'Unassigned'][] = $student;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div><div class="page-title">Add Members</div><div class="page-subtitle"><span class="team-color-pill" style="background: <?= e($activeTeam['team_color'] ?? '#64748b') ?>22; color: <?= e($activeTeam['team_color'] ? '#111' : '#111') ?>;"><?= e($activeTeam['team_name']) ?></span></div></div>
        <a href="<?= app_url('/admin/members.php') ?>?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-arrow-left"></i> Back to Members</a>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="panel mb-6">
        <form method="GET" class="form-grid">
            <input type="hidden" name="team" value="<?= $activeTeamId ?>">
            <div class="input-group full-width"><label>Search Students</label><input type="text" name="search" value="<?= e($search) ?>" placeholder="Student name or admission number"></div>
            <div class="form-actions full-width"><button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button><?php if ($search !== ''): ?><a href="<?= app_url('/admin/add-members.php') ?>?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md">Clear</a><?php endif; ?></div>
        </form>
    </div>

    <?php if (!$classes): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-user-graduate"></i></div><div class="empty-title">No Students Found</div><div class="empty-subtitle">No active students match your search.</div></div>
    <?php else: ?>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <div class="class-accordion">
                <?php foreach ($classes as $className => $classStudents): ?>
                    <div class="class-block">
                        <button type="button" class="class-header">
                            <div><div class="class-title"><?= e($className) ?></div><div class="class-count"><?= count($classStudents) ?> Students</div></div>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <div class="class-body">
                            <div class="student-grid">
                                <?php foreach ($classStudents as $student): ?>
                                    <?php $assigned = isset($existingMembers[(int)$student['id']]); $teamData = $assigned ? $existingMembers[(int)$student['id']] : null; ?>
                                    <label class="student-card <?= $assigned ? 'assigned' : '' ?>">
                                        <?php if (!$assigned): ?><input type="checkbox" name="student_ids[]" value="<?= (int)$student['id'] ?>"><?php endif; ?>
                                        <div class="student-card-content">
                                            <div class="student-avatar"><?= e(mb_substr((string)$student['full_name'], 0, 1)) ?></div>
                                            <div class="student-info">
                                                <div class="student-name"><?= e($student['full_name']) ?></div>
                                                <div class="student-meta"><?= e($student['class_type'] ?: '-') ?></div>
                                                <?php if ($assigned): ?><div class="assigned-badge" style="background:<?= e($teamData['team_color'] ?: '#64748b') ?>22;color:<?= e($teamData['team_color'] ?: '#cbd5e1') ?>;"><i class="fa-solid fa-users"></i><?= e($teamData['team_name']) ?></div><?php endif; ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-success btn-md"><i class="fa-solid fa-user-plus"></i> Add Selected Members</button></div>
        </form>
    <?php endif; ?>
</div>

<script>
(() => {

document.querySelectorAll('.class-header').forEach(header => {
    header.addEventListener('click', () => {
        header.classList.toggle('active');
        header.nextElementSibling?.classList.toggle('active');
    });
});
document.querySelector('.class-body')?.classList.add('active');

})();
</script>
<?php admin_close_page(); ?>
