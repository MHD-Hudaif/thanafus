<?php
$pageTitle = 'Add Members';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
admin_handle_renewal_resolutions($pdo, $dashboardPdo, $activeEventId);
$requestedTeamId = (int)($_GET['team'] ?? 0);
$activeTeamId = $requestedTeamId > 0 ? $requestedTeamId : (int)($_SESSION['active_team_id'] ?? 0);

if ($activeTeamId <= 0) {
    admin_flash('error', 'Please select a team first.');
    admin_redirect('/admin/event-manager/teams.php');
}

$stmt = $pdo->prepare('SELECT * FROM musabaqa_teams WHERE id = ? AND event_id = ? LIMIT 1');
$stmt->execute([$activeTeamId, $activeEventId]);
$activeTeam = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activeTeam) {
    unset($_SESSION['active_team_id']);
    admin_flash('error', 'Selected team was not found in this event.');
    admin_redirect('/admin/event-manager/teams.php');
}
$_SESSION['active_team_id'] = $activeTeamId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/event-manager/add-members.php', ['team' => $activeTeamId]);
    }

    $studentIds = array_values(array_unique(array_map('intval', (array)($_POST['student_ids'] ?? []))));
    if (!$studentIds) {
        admin_flash('error', 'Please select at least one student.');
        admin_redirect('/admin/event-manager/add-members.php', ['team' => $activeTeamId]);
    }

    try {
        admin_db_transaction($pdo, function ($pdo) use ($studentIds, $activeEventId, $activeTeamId) {
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

            // Check for previous entries in deleted_member_entries
            $stmtDel = $pdo->prepare('SELECT * FROM musabaqa_deleted_member_entries WHERE student_id = ? AND event_id = ?');
            
            // Get student name
            $stmtName = $dashboardPdo->prepare("SELECT COALESCE(NULLIF(display_name, ''), full_name) AS name FROM students WHERE id = ? LIMIT 1");
            
            // Get program details
            $stmtProg = $pdo->prepare("SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1");
            
            // Count current entries for this team
            $stmtCount = $pdo->prepare("
                SELECT pe.id, pe.entry_name, pe.entry_number 
                FROM musabaqa_program_entries pe 
                WHERE pe.program_id = ? AND pe.team_id = ? AND pe.event_id = ?
            ");
            
            // Find the team member ID
            $stmtTm = $pdo->prepare('SELECT id FROM musabaqa_team_members WHERE event_id = ? AND team_id = ? AND student_id = ? LIMIT 1');
            
            $overlaps = [];
            
            foreach ($newStudentIds as $studentId) {
                // Find team member ID
                $stmtTm->execute([$activeEventId, $activeTeamId, $studentId]);
                $teamMemberId = (int)$stmtTm->fetchColumn();
                if (!$teamMemberId) continue;
                
                $stmtName->execute([$studentId]);
                $studentName = (string)$stmtName->fetchColumn();
                
                $stmtDel->execute([$studentId, $activeEventId]);
                $delEntries = $stmtDel->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($delEntries as $delEntry) {
                    $programId = (int)$delEntry['program_id'];
                    $stmtProg->execute([$programId, $activeEventId]);
                    $program = $stmtProg->fetch(PDO::FETCH_ASSOC);
                    if (!$program) continue;
                    
                    $stmtCount->execute([$programId, $activeTeamId, $activeEventId]);
                    $currentEntries = $stmtCount->fetchAll(PDO::FETCH_ASSOC);
                    
                    $perTeamLimit = (int)($program['entries_limit'] ?? 10);
                    
                    if (count($currentEntries) < $perTeamLimit) {
                        // Recreate entry automatically
                        $tMax = $pdo->prepare("SELECT COALESCE(MAX(entry_number), 0) + 1 FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ?");
                        $tMax->execute([$activeEventId, $programId]);
                        $entryNumber = (int)$tMax->fetchColumn();
                        
                        $perfOrder = mt_rand(1, 999999);
                        
                        $insEntry = $pdo->prepare("
                            INSERT INTO musabaqa_program_entries
                                (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'approved')
                        ");
                        $insEntry->execute([$activeEventId, $programId, $activeTeamId, $studentName, $entryNumber, $perfOrder]);
                        $newEntryId = (int)$pdo->lastInsertId();
                        
                        $insMem = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, ?)");
                        $insMem->execute([$newEntryId, $teamMemberId, (string)$delEntry['role_name']]);
                        
                        // Clean up
                        $delSql = $pdo->prepare('DELETE FROM musabaqa_deleted_member_entries WHERE id = ?');
                        $delSql->execute([(int)$delEntry['id']]);
                    } else {
                        // Conflict
                        $overlaps[] = [
                            'deleted_entry_id' => (int)$delEntry['id'],
                            'student_id' => $studentId,
                            'student_name' => $studentName,
                            'team_member_id' => $teamMemberId,
                            'team_id' => $activeTeamId,
                            'team_name' => $activeTeam['team_name'],
                            'program_id' => $programId,
                            'program_title' => $program['title'],
                            'limit' => $perTeamLimit,
                            'existing_entries' => $currentEntries
                        ];
                    }
                }
            }
            
            if (!empty($overlaps)) {
                if (!isset($_SESSION['pending_renewals'])) {
                    $_SESSION['pending_renewals'] = [];
                }
                $_SESSION['pending_renewals'] = array_merge($_SESSION['pending_renewals'], $overlaps);
            }
        });
        admin_flash('success', 'Members added successfully.');
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to add members.');
    }

    admin_redirect('/admin/event-manager/add-members.php', ['team' => $activeTeamId]);
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

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">

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
        <a href="<?= app_url('/admin/event-manager/members.php') ?>?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md"><i class="fa-solid fa-arrow-left"></i> Back to Members</a>
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
                    <?php if ($search !== ''): ?><a href="<?= app_url('/admin/event-manager/add-members.php') ?>?team=<?= $activeTeamId ?>" class="btn btn-secondary btn-md">Clear</a><?php endif; ?>
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
        if (window.showToast) {
            window.showToast('Please select at least one student.', 'error');
        } else {
            alert('Please select at least one student.');
        }
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
<?php admin_render_renewal_modal_html(); ?>
<?php admin_close_page(); ?>
