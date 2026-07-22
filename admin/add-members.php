<?php
$pageTitle = 'Add Members';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$requestedTeamId = (int)($_GET['team'] ?? 0);
$activeTeamId = $requestedTeamId > 0 ? $requestedTeamId : (int)($_SESSION['active_team_id'] ?? 0);

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
    SELECT s.id, s.admission_no, COALESCE(NULLIF(s.display_name, ''), s.full_name) AS full_name,
           c.id AS class_id, c.name AS class_name, c.year AS class_year, ct.name AS class_type
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
$query .= ' ORDER BY (c.year IS NULL) ASC, c.year DESC, c.class_type_id ASC, c.id ASC, c.name ASC, full_name ASC';
$stmt = $dashboardPdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classes = [];
foreach ($students as $student) {
    $classKey = $student['class_id'] ? 'class-' . (int)$student['class_id'] : 'unassigned';
    if (!isset($classes[$classKey])) {
        $classes[$classKey] = [
            'name' => $student['class_name'] ?: 'Unassigned',
            'year' => $student['class_year'] ?? null,
            'type' => $student['class_type'] ?: 'No class type',
            'students' => [],
        ];
    }
    $classes[$classKey]['students'][] = $student;
}

$availableCount = 0;
$assignedCount = 0;
foreach ($students as $student) {
    if (isset($existingMembers[(int)$student['id']])) {
        $assignedCount++;
    } else {
        $availableCount++;
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <style>
        .add-members-shell { display: grid; gap: 18px; }
        .add-members-topbar { align-items: center; margin-bottom: 22px; }
        .team-marker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-2);
            color: var(--text);
            font-size: 13px;
            font-weight: 900;
        }
        .team-marker-dot {
            width: 11px;
            height: 11px;
            border-radius: 999px;
            box-shadow: 0 0 0 3px rgba(255,255,255,.08);
        }
        .member-command {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, .55fr);
            gap: 16px;
            align-items: stretch;
        }
        .search-panel { display: grid; gap: 14px; }
        .search-panel-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .panel-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .panel-kicker i { color: var(--primary-2); }
        .search-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: end;
        }
        .member-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .member-stat {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface-2);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .member-stat-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 11px;
            background: rgba(76,175,80,.12);
            color: var(--primary-2);
            border: 1px solid rgba(76,175,80,.18);
        }
        .member-stat strong { display: block; font-size: 26px; line-height: 1; }
        .member-stat span { display: block; margin-top: 6px; color: var(--muted); font-size: 12px; font-weight: 800; }
        .selection-bar {
            position: sticky;
            top: 14px;
            z-index: 20;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border: 1px solid rgba(76,175,80,.26);
            border-radius: var(--radius);
            background: rgba(0,0,0,.82);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: var(--shadow-sm);
        }
        .selection-count { font-size: 14px; color: var(--muted); font-weight: 800; }
        .selection-count strong { color: var(--text); font-size: 20px; }
        .class-stack { display: grid; gap: 16px; }
        .class-block.add-member-class {
            overflow: hidden;
            border-color: rgba(255,255,255,.1);
            background: rgba(0,0,0,.42);
        }
        .class-header.add-member-class-header {
            padding: 18px;
            border-bottom: 1px solid transparent;
            gap: 16px;
            cursor: default;
        }
        .class-header.add-member-class-header.active { border-bottom-color: var(--border); }
        .add-member-class-header.active i { transform: none; }
        .class-head-main {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            text-align: left;
            flex: 1 1 auto;
        }
        .class-year {
            min-width: 68px;
            padding: 9px 10px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(76,175,80,.24), rgba(46,125,50,.18));
            border: 1px solid rgba(76,175,80,.26);
            color: #d9f99d;
            text-align: center;
            font-size: 15px;
            font-weight: 900;
        }
        .class-summary { min-width: 0; }
        .class-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .class-title { font-size: 18px; line-height: 1.35; }
        .class-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 0 0 auto;
        }
        .class-toggle {
            width: 38px;
            height: 38px;
            min-height: 38px;
            padding: 0;
            flex: 0 0 auto;
            border-radius: 12px;
        }
        .add-member-class-header .class-toggle-icon { transition: transform .2s ease; }
        .add-member-class-header .class-toggle[aria-expanded="true"] .class-toggle-icon { transform: rotate(180deg); }
        .class-body.add-member-class-body { padding: 16px 18px 18px; }
        .student-grid.add-member-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .student-card-content.add-member-card {
            min-height: 82px;
            align-items: flex-start;
            border-color: rgba(255,255,255,.09);
            transition: border-color .16s ease, background .16s ease, transform .16s ease;
        }
        .student-card:not(.assigned):hover .add-member-card {
            border-color: rgba(76,175,80,.34);
            background: rgba(76,175,80,.08);
            transform: translateY(-1px);
        }
        .student-card input:checked + .add-member-card {
            border-color: rgba(76,175,80,.78);
            background: rgba(76,175,80,.16);
        }
        .student-check {
            width: 22px;
            height: 22px;
            flex: 0 0 auto;
            display: grid;
            place-items: center;
            border: 1px solid var(--border-strong);
            border-radius: 7px;
            color: transparent;
            background: rgba(255,255,255,.04);
            margin-top: 10px;
        }
        .student-card input:checked + .add-member-card .student-check {
            color: #042f2e;
            background: var(--primary-2);
            border-color: var(--primary-2);
        }
        .student-check.is-assigned {
            color: #cbd5e1;
            background: rgba(148,163,184,.12);
            border-color: rgba(148,163,184,.2);
        }
        .student-info { min-width: 0; }
        .student-name { overflow-wrap: anywhere; }
        .student-meta-line {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }
        .student-meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 22px;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(255,255,255,.05);
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }
        .student-card.assigned .add-member-card {
            background: rgba(148,163,184,.08);
            border-style: dashed;
        }
        .student-card.assigned .student-avatar {
            background: rgba(148,163,184,.18);
            color: #e2e8f0;
        }
        .empty-class-note {
            color: var(--muted);
            padding: 14px;
            border: 1px dashed var(--border-strong);
            border-radius: var(--radius);
        }
        .assigned-toggle-container {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-soft);
            border-radius: 999px;
            cursor: pointer;
            user-select: none;
            transition: all 0.2s ease;
        }
        .assigned-toggle-container:hover {
            border-color: rgba(20, 184, 166, 0.45);
            background: rgba(20, 184, 166, 0.1);
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 22px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(148, 163, 184, 0.3);
            transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: #ffffff;
            transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        .toggle-switch input:checked + .toggle-slider {
            background-color: #14b8a6;
            border-color: #0d9488;
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(16px);
        }
        .toggle-label {
            font-size: 12px;
            font-weight: 800;
            color: var(--text);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .hide-assigned-members .student-card.assigned {
            display: none !important;
        }
        @media (max-width: 980px) {
            .member-command { grid-template-columns: 1fr; }
            .search-row { grid-template-columns: 1fr; }
            .selection-bar { position: static; flex-direction: column; align-items: stretch; }
            .selection-bar .form-actions { justify-content: stretch; margin-top: 0; }
            .selection-bar .btn { width: 100%; }
        }
        @media (max-width: 640px) {
            .member-stats { grid-template-columns: 1fr; }
            .class-head-main { align-items: flex-start; }
            .class-actions { width: 100%; justify-content: space-between; }
            .class-header.add-member-class-header { align-items: flex-start; flex-direction: column; }
            .class-title { font-size: 16px; }
        }
    </style>

    <div class="topbar add-members-topbar">
        <div>
            <div class="page-title">Add Members</div>
            <div class="page-subtitle">
                <span class="team-marker">
                    <span class="team-marker-dot" style="background: <?= e($activeTeam['team_color'] ?: '#4caf50') ?>;"></span>
                    <?= e($activeTeam['team_name']) ?>
                </span>
            </div>
        </div>
        <a href="<?= app_url('/admin/members.php') ?>?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-arrow-left"></i> Back to Members</a>
    </div>

    <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="add-members-shell">
        <div class="member-command">
            <div class="panel search-panel">
                <div class="search-panel-heading">
                    <div class="panel-kicker"><i class="fa-solid fa-user-graduate"></i> Student pool</div>
                    <label class="assigned-toggle-container">
                        <span class="toggle-switch">
                            <input type="checkbox" id="toggleAssignedMembers" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <span class="toggle-label"><i class="fa-solid fa-user-check"></i> Show assigned members</span>
                    </label>
                </div>
                <form method="GET" class="search-row">
                    <input type="hidden" name="team" value="<?= $activeTeamId ?>">
                    <div class="input-group">
                        <label>Search students</label>
                        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Name or admission number">
                    </div>
                    <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                    <?php if ($search !== ''): ?><a href="<?= app_url('/admin/add-members.php') ?>?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md">Clear</a><?php endif; ?>
                </form>
            </div>

            <div class="member-stats">
                <div class="member-stat"><div class="member-stat-icon"><i class="fa-solid fa-users"></i></div><div><strong id="statStudentsShown"><?= count($students) ?></strong><span>Students shown</span></div></div>
                <div class="member-stat"><div class="member-stat-icon"><i class="fa-solid fa-user-plus"></i></div><div><strong><?= $availableCount ?></strong><span>Available</span></div></div>
                <div class="member-stat"><div class="member-stat-icon"><i class="fa-solid fa-user-check"></i></div><div><strong><?= $assignedCount ?></strong><span>Already assigned</span></div></div>
            </div>
        </div>

        <?php if (!$classes): ?>
            <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-user-graduate"></i></div><div class="empty-title">No Students Found</div><div class="empty-subtitle">No active students match your search.</div></div>
        <?php else: ?>
            <form method="POST" id="addMembersForm">
                <?= admin_csrf_field() ?>
                <div class="selection-bar">
                    <div>
                        <div class="selection-count"><strong id="selectedCount">0</strong> selected for <?= e($activeTeam['team_name']) ?></div>
                        <div class="page-subtitle">Classes are sorted by latest year first.</div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary btn-md" id="clearSelection"><i class="fa-solid fa-xmark"></i> Clear</button>
                        <button type="submit" class="btn btn-success btn-md"><i class="fa-solid fa-user-plus"></i> Add Selected Members</button>
                    </div>
                </div>

                <div class="class-stack">
                    <?php foreach ($classes as $classKey => $classData): ?>
                        <?php
                            $classStudents = $classData['students'];
                            $availableInClass = 0;
                            foreach ($classStudents as $student) {
                                if (!isset($existingMembers[(int)$student['id']])) {
                                    $availableInClass++;
                                }
                            }
                            $classBodyId = 'class-body-' . $classKey;
                        ?>
                        <div class="class-block add-member-class">
                            <div class="class-header add-member-class-header">
                                <div class="class-head-main">
                                    <div class="class-year"><?= $classData['year'] ? e($classData['year']) : '-' ?></div>
                                    <div class="class-summary">
                                        <div class="class-title-row">
                                            <div class="class-title" dir="auto"><?= e($classData['name']) ?></div>
                                            <span class="badge badge-neutral"><?= e($classData['type']) ?></span>
                                        </div>
                                        <div class="class-count"><?= count($classStudents) ?> students &middot; <?= $availableInClass ?> available</div>
                                    </div>
                                </div>
                                <div class="class-actions">
                                    <?php if ($availableInClass > 0): ?>
                                        <button type="button" class="btn btn-secondary btn-sm" data-select-class="<?= e($classKey) ?>"><i class="fa-solid fa-check-double"></i> Select available</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary btn-sm class-toggle" data-toggle-class aria-controls="<?= e($classBodyId) ?>" aria-expanded="false" aria-label="Toggle <?= e($classData['name']) ?>">
                                        <i class="fa-solid fa-chevron-down class-toggle-icon"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="class-body add-member-class-body" id="<?= e($classBodyId) ?>">
                                <?php if ($availableInClass <= 0): ?>
                                    <div class="empty-class-note">All students in this class are already assigned to teams for this event.</div>
                                <?php else: ?>
                                    <div class="student-grid add-member-grid">
                                        <?php foreach ($classStudents as $student): ?>
                                            <?php $assigned = isset($existingMembers[(int)$student['id']]); $teamData = $assigned ? $existingMembers[(int)$student['id']] : null; ?>
                                            <label class="student-card <?= $assigned ? 'assigned' : '' ?>"<?= $assigned ? ' aria-disabled="true"' : '' ?>>
                                                <?php if (!$assigned): ?><input type="checkbox" name="student_ids[]" value="<?= (int)$student['id'] ?>" data-class-key="<?= e($classKey) ?>"><?php endif; ?>
                                                <div class="student-card-content add-member-card">
                                                    <span class="student-check <?= $assigned ? 'is-assigned' : '' ?>"><i class="fa-solid <?= $assigned ? 'fa-user-check' : 'fa-check' ?>"></i></span>
                                                    <div class="student-avatar"><?= e(mb_substr((string)$student['full_name'], 0, 1)) ?></div>
                                                    <div class="student-info">
                                                        <div class="student-name" dir="auto"><?= e($student['full_name']) ?></div>
                                                        <div class="student-meta-line">
                                                            <span class="student-meta-pill"><i class="fa-solid fa-layer-group"></i><?= e($student['class_type'] ?: '-') ?></span>
                                                            <?php if (!empty($student['admission_no'])): ?><span class="student-meta-pill"><i class="fa-solid fa-id-badge"></i><?= e($student['admission_no']) ?></span><?php endif; ?>
                                                        </div>
                                                        <?php if ($assigned): ?><div class="assigned-badge" style="background:<?= e($teamData['team_color'] ?: '#64748b') ?>22;color:<?= e($teamData['team_color'] ?: '#cbd5e1') ?>;"><i class="fa-solid fa-users"></i><?= e($teamData['team_name']) ?></div><?php endif; ?>
                                                    </div>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {

const toggleClassBlock = (button, force) => {
    const bodyId = button.getAttribute('aria-controls');
    const body = bodyId ? document.getElementById(bodyId) : button.closest('.class-block')?.querySelector('.class-body');
    const header = button.closest('.class-header');
    if (!body || !header) return;
    const shouldOpen = typeof force === 'boolean' ? force : !body.classList.contains('active');
    header.classList.toggle('active', shouldOpen);
    body.classList.toggle('active', shouldOpen);
    button.setAttribute('aria-expanded', String(shouldOpen));
};

document.querySelectorAll('[data-toggle-class]').forEach(button => {
    button.addEventListener('click', () => toggleClassBlock(button));
});
const firstToggle = document.querySelector('[data-toggle-class]');
if (firstToggle) {
    toggleClassBlock(firstToggle, true);
}

const selectedCount = document.getElementById('selectedCount');
const addMembersForm = document.getElementById('addMembersForm');
const checkboxes = () => Array.from(document.querySelectorAll('input[name="student_ids[]"]'));
const updateSelectionCount = () => {
    if (!selectedCount) return;
    selectedCount.textContent = checkboxes().filter(input => input.checked).length;
};

addMembersForm?.addEventListener('change', event => {
    if (event.target.matches('input[name="student_ids[]"]')) {
        updateSelectionCount();
    }
});

document.querySelectorAll('[data-select-class]').forEach(button => {
    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const classKey = button.dataset.selectClass;
        const classBoxes = checkboxes().filter(input => input.dataset.classKey === classKey);
        const shouldCheck = classBoxes.some(input => !input.checked);
        classBoxes.forEach(input => {
            input.checked = shouldCheck;
        });
        const toggleButton = button.closest('.class-block')?.querySelector('[data-toggle-class]');
        if (toggleButton) {
            toggleClassBlock(toggleButton, true);
        }
        updateSelectionCount();
    });
});

document.getElementById('clearSelection')?.addEventListener('click', () => {
    checkboxes().forEach(input => {
        input.checked = false;
    });
    updateSelectionCount();
});

addMembersForm?.addEventListener('submit', event => {
    if (!checkboxes().some(input => input.checked)) {
        event.preventDefault();
        alert('Please select at least one student.');
    }
});

const toggleAssignedInput = document.getElementById('toggleAssignedMembers');
const addMembersShell = document.querySelector('.add-members-shell');

function applyAssignedVisibility() {
    const showAssigned = toggleAssignedInput ? toggleAssignedInput.checked : true;
    localStorage.setItem('musabaqa_show_assigned_members', showAssigned ? '1' : '0');
    
    if (addMembersShell) {
        addMembersShell.classList.toggle('hide-assigned-members', !showAssigned);
    }
    
    document.querySelectorAll('.class-block').forEach(block => {
        const assignedCards = block.querySelectorAll('.student-card.assigned');
        const availableCards = block.querySelectorAll('.student-card:not(.assigned)');
        const emptyNote = block.querySelector('.empty-class-note');
        const countEl = block.querySelector('.class-count');
        
        const visibleCards = showAssigned ? (assignedCards.length + availableCards.length) : availableCards.length;
        
        if (countEl) {
            countEl.textContent = `${visibleCards} student(s) shown · ${availableCards.length} available`;
        }
        
        if (emptyNote) {
            emptyNote.style.display = (!showAssigned && availableCards.length === 0) ? 'block' : (assignedCards.length > 0 && availableCards.length === 0 ? 'block' : 'none');
        }
    });

    const shownStatEl = document.getElementById('statStudentsShown');
    if (shownStatEl) {
        const totalCards = document.querySelectorAll('.student-card');
        const assignedCards = document.querySelectorAll('.student-card.assigned');
        const count = showAssigned ? totalCards.length : (totalCards.length - assignedCards.length);
        shownStatEl.textContent = count;
    }
}

if (toggleAssignedInput) {
    const savedPref = localStorage.getItem('musabaqa_show_assigned_members');
    if (savedPref !== null) {
        toggleAssignedInput.checked = savedPref === '1';
    }
    
    toggleAssignedInput.addEventListener('change', applyAssignedVisibility);
    applyAssignedVisibility();
}

updateSelectionCount();

})();
</script>
<?php admin_close_page(); ?>
