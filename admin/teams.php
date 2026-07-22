<?php
$pageTitle = 'Manage Teams';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

function admin_teams_ensure_group_leader_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'musabaqa_teams'
          AND column_name = 'group_leader_student_id'
    ");
    $stmt->execute();

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE musabaqa_teams ADD COLUMN group_leader_student_id INT UNSIGNED DEFAULT NULL AFTER teacher_incharge_id');
    }

    $checked = true;
}

admin_teams_ensure_group_leader_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/teams.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $teamId = (int)($_POST['team_id'] ?? 0);
    $teamName = trim((string)($_POST['team_name'] ?? ''));
    $shortName = trim((string)($_POST['short_name'] ?? ''));
    $teamColor = trim((string)($_POST['team_color'] ?? '#14b8a6'));
    $groupLeaderId = (int)($_POST['group_leader_student_id'] ?? 0);
    $groupLeaderId = $groupLeaderId > 0 ? $groupLeaderId : null;
    $teacherInchargeId = (int)($_POST['teacher_incharge_id'] ?? 0);
    $teacherInchargeId = $teacherInchargeId > 0 ? $teacherInchargeId : null;

    if ($action === 'delete') {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_team_members WHERE team_id = ? AND event_id = ?');
            $stmt->execute([$teamId, $activeEventId]);
            $memberCount = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM musabaqa_program_entries WHERE team_id = ? AND event_id = ?');
            $stmt->execute([$teamId, $activeEventId]);
            $entryCount = (int)$stmt->fetchColumn();
            if ($memberCount > 0 || $entryCount > 0) {
                throw new RuntimeException('Teams with members or entries cannot be deleted.');
            }
            $stmt = $pdo->prepare('DELETE FROM musabaqa_teams WHERE id = ? AND event_id = ?');
            $stmt->execute([$teamId, $activeEventId]);
            if ((int)($_SESSION['active_team_id'] ?? 0) === $teamId) {
                unset($_SESSION['active_team_id']);
            }
            admin_flash('success', 'Team deleted successfully.');
        } catch (Throwable $e) {
            admin_flash('error', $e->getMessage() ?: 'Unable to delete team.');
        }
        admin_redirect('/admin/teams.php');
    }

    if ($teamName === '') {
        admin_flash('error', 'Team name is required.');
        admin_redirect('/admin/teams.php');
    }

    try {
        $dup = $pdo->prepare('SELECT id FROM musabaqa_teams WHERE event_id = ? AND team_name = ? AND id <> ? LIMIT 1');
        $dup->execute([$activeEventId, $teamName, $teamId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Team name already exists.');
        }

        if ($groupLeaderId !== null) {
            if ($action !== 'update' || $teamId <= 0) {
                throw new RuntimeException('Add members to the team before selecting a group leader.');
            }

            $leaderCheck = $pdo->prepare("SELECT student_id FROM musabaqa_team_members WHERE student_id = ? AND team_id = ? AND event_id = ? AND status = 'active' LIMIT 1");
            $leaderCheck->execute([$groupLeaderId, $teamId, $activeEventId]);
            if (!$leaderCheck->fetchColumn()) {
                throw new RuntimeException('The group leader must be an active member of this team.');
            }

            $activeStudentCheck = $dashboardPdo->prepare("SELECT id FROM students WHERE id = ? AND status = 'active' LIMIT 1");
            $activeStudentCheck->execute([$groupLeaderId]);
            if (!$activeStudentCheck->fetchColumn()) {
                throw new RuntimeException('The selected group leader is not an active student.');
            }
        }

        if ($teacherInchargeId !== null) {
            $teacherCheck = $dashboardPdo->prepare('SELECT id FROM teachers WHERE id = ? LIMIT 1');
            $teacherCheck->execute([$teacherInchargeId]);
            if (!$teacherCheck->fetchColumn()) {
                throw new RuntimeException('Selected teacher in charge was not found.');
            }
        }

        if ($action === 'update' && $teamId > 0) {
            $stmt = $pdo->prepare('UPDATE musabaqa_teams SET team_name = ?, short_name = ?, team_color = ?, group_leader_student_id = ?, teacher_incharge_id = ? WHERE id = ? AND event_id = ?');
            $stmt->execute([$teamName, $shortName ?: null, $teamColor, $groupLeaderId, $teacherInchargeId, $teamId, $activeEventId]);
            admin_flash('success', 'Team updated successfully.');
        } else {
            $stmt = $pdo->prepare('SELECT MAX(number_prefix) FROM musabaqa_teams WHERE event_id = ?');
            $stmt->execute([$activeEventId]);
            $maxPrefix = (int)$stmt->fetchColumn();
            $numberPrefix = $maxPrefix > 0 ? $maxPrefix + 100 : 100;
            $stmt = $pdo->prepare('INSERT INTO musabaqa_teams (event_id, team_name, short_name, team_color, number_prefix, group_leader_student_id, teacher_incharge_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$activeEventId, $teamName, $shortName ?: null, $teamColor, $numberPrefix, $groupLeaderId, $teacherInchargeId]);
            admin_flash('success', 'Team created successfully.');
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to save team.');
    }

    admin_redirect('/admin/teams.php');
}

$flash = admin_take_flash();
$search = trim((string)($_GET['search'] ?? ''));
$where = 'WHERE t.event_id = ?';
$params = [$activeEventId];
if ($search !== '') {
    $where .= ' AND (t.team_name LIKE ? OR t.short_name LIKE ? OR CAST(t.number_prefix AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$stmt = $pdo->prepare("
    SELECT t.*, COUNT(DISTINCT tm.id) AS member_count, COUNT(DISTINCT pe.id) AS entry_count
    FROM musabaqa_teams t
    LEFT JOIN musabaqa_team_members tm ON tm.team_id = t.id AND tm.status = 'active'
    LEFT JOIN musabaqa_program_entries pe ON pe.team_id = t.id
    {$where}
    GROUP BY t.id
    ORDER BY t.team_name ASC
");
$stmt->execute($params);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
$leadersById = [];
$studentIds = array_values(array_unique(array_filter(array_map(static fn ($team) => (int)($team['group_leader_student_id'] ?? 0), $teams))));
if ($studentIds) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $leaderStmt = $dashboardPdo->prepare("
        SELECT id, COALESCE(NULLIF(display_name, ''), full_name) AS display_name
        FROM students
        WHERE id IN ({$placeholders})
    ");
    $leaderStmt->execute($studentIds);
    foreach ($leaderStmt->fetchAll(PDO::FETCH_ASSOC) as $leader) {
        $leadersById[(int)$leader['id']] = trim((string)($leader['display_name'] ?? ''));
    }
}
foreach ($teams as &$team) {
    $leaderId = (int)($team['group_leader_student_id'] ?? 0);
    $team['group_leader_name'] = $leaderId > 0 && isset($leadersById[$leaderId]) && $leadersById[$leaderId] !== ''
        ? $leadersById[$leaderId]
        : null;
}
unset($team);

$teachersById = [];
$teacherIds = array_values(array_unique(array_filter(array_map(static fn ($team) => (int)($team['teacher_incharge_id'] ?? 0), $teams))));
if ($teacherIds) {
    $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
    $teacherStmt = $dashboardPdo->prepare("SELECT id, full_name FROM teachers WHERE id IN ({$placeholders})");
    $teacherStmt->execute($teacherIds);
    foreach ($teacherStmt->fetchAll(PDO::FETCH_ASSOC) as $teacher) {
        $teachersById[(int)$teacher['id']] = trim((string)($teacher['full_name'] ?? ''));
    }
}
foreach ($teams as &$team) {
    $teacherId = (int)($team['teacher_incharge_id'] ?? 0);
    $team['teacher_incharge_name'] = $teacherId > 0 && isset($teachersById[$teacherId]) && $teachersById[$teacherId] !== ''
        ? $teachersById[$teacherId]
        : null;
}
unset($team);
$totalTeams = count($teams);

$totalActiveMembers = 0;
$totalProgramEntries = 0;
$topTeam = null;

if ($activeEventId > 0) {
    $mStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_team_members WHERE event_id = ? AND status = 'active'");
    $mStmt->execute([$activeEventId]);
    $totalActiveMembers = (int)$mStmt->fetchColumn();

    $eStmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE event_id = ?");
    $eStmt->execute([$activeEventId]);
    $totalProgramEntries = (int)$eStmt->fetchColumn();

    if (!empty($teams)) {
        $topTeam = $teams[0];
        foreach ($teams as $t) {
            if ((float)($t['total_score'] ?? 0) > (float)($topTeam['total_score'] ?? 0)) {
                $topTeam = $t;
            }
        }
    }
}

$leaderOptionsStmt = $pdo->prepare("
    SELECT tm.team_id, s.id, tm.chest_number,
           COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
           COALESCE(NULLIF(c.name, ''), 'Unassigned') AS class_name,
           c.year AS class_year, c.class_type_id, c.id AS class_id
    FROM musabaqa_team_members tm
    JOIN kauzariyya.students s ON s.id = tm.student_id AND s.status = 'active'
    LEFT JOIN kauzariyya.classes c ON c.id = s.class_id
    WHERE tm.event_id = ? AND tm.status = 'active'
    ORDER BY tm.team_id ASC, (c.year IS NULL) ASC, c.year ASC, c.class_type_id ASC, c.id ASC, display_name ASC, s.id ASC
");
$leaderOptionsStmt->execute([$activeEventId]);
$leaderOptionsByTeam = [];
foreach ($leaderOptionsStmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
    $leaderOptionsByTeam[(int)$student['team_id']][] = [
        'id' => (int)$student['id'],
        'name' => (string)$student['display_name'],
        'class_name' => (string)$student['class_name'],
        'class_year' => $student['class_year'] ?? null,
        'class_type_id' => $student['class_type_id'] ?? null,
        'class_id' => $student['class_id'] ?? null,
        'chest_number' => (string)($student['chest_number'] ?? ''),
    ];
}

$teacherOptions = $dashboardPdo
    ->query("SELECT id, full_name FROM teachers WHERE status = 'active' ORDER BY full_name ASC, id ASC")
    ->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['limit'])) {
    $perPage = max(5, min(5000, (int)$_GET['limit']));
    $_SESSION['teams_limit'] = $perPage;
} else {
    $perPage = isset($_SESSION['teams_limit']) ? $_SESSION['teams_limit'] : 15;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$paginatedTeams = array_slice($teams, $offset, $perPage);

function get_arabic_team_name(string $teamName, ?string $shortName = null): string
{
    $name = mb_strtolower(trim($teamName));
    $short = mb_strtolower(trim((string)$shortName));

    if (str_contains($name, 'akhyar') || str_contains($short, 'akhyar') || str_contains($short, 'akh')) return 'أخيار';
    if (str_contains($name, 'awan') || str_contains($name, "a'wan") || str_contains($short, 'awan')) return 'أعوان';
    if (str_contains($name, 'ansar') || str_contains($short, 'ansar') || str_contains($short, 'ans')) return 'أنصار';
    if (str_contains($name, 'abtal') || str_contains($short, 'abtal') || str_contains($short, 'abt')) return 'أابطال';
    if (str_contains($name, 'fursan') || str_contains($short, 'fur')) return 'فرسان';
    if (str_contains($name, 'shuhada')) return 'شهداء';

    return $teamName;
}

function render_admin_team_card(array $team): string
{
    $teamId = (int)$team['id'];
    $color = !empty($team['team_color']) ? e($team['team_color']) : '#14b8a6';
    $name = e($team['team_name']);
    $shortName = !empty($team['short_name']) ? e($team['short_name']) : '';
    $prefix = e((string)$team['number_prefix']);
    $leaderName = !empty($team['group_leader_name']) ? e($team['group_leader_name']) : null;
    $teacherName = !empty($team['teacher_incharge_name']) ? e($team['teacher_incharge_name']) : null;
    $score = number_format((float)($team['total_score'] ?? 0), 2);
    $memberCount = (int)$team['member_count'];
    $entryCount = (int)$team['entry_count'];
    $jsonTeam = e(json_encode($team, JSON_HEX_APOS | JSON_HEX_QUOT));
    $membersUrl = app_url('/admin/members.php') . '?team=' . $teamId;

    ob_start();
    ?>
    <div class="team-card-v2" style="--team-accent: <?= $color ?>; background: linear-gradient(145deg, <?= $color ?>14 0%, <?= $color ?>05 100%); border-color: <?= $color ?>35; border-top-color: <?= $color ?>;" data-team-id="<?= $teamId ?>">
        <div class="team-card-banner">
            <div class="team-color-swatch-badge">
                <span class="swatch-dot"></span>
                <span class="team-prefix-tag">Prefix <?= $prefix ?>+</span>
            </div>
            <?php if ($shortName): ?>
                <span class="team-code-pill"><?= $shortName ?></span>
            <?php endif; ?>
        </div>

        <div class="team-card-main">
            <h3 class="team-title"><?= $name ?></h3>

            <div class="team-meta-roles">
                <div class="meta-role-item <?= $leaderName ? 'has-leader' : 'no-leader' ?>" style="<?= $leaderName ? "background: {$color}18; color: {$color}; font-weight:700;" : '' ?>">
                    <i class="fa-solid fa-user-graduate"></i>
                    <span><?= $leaderName ? $leaderName : 'No Leader Assigned' ?></span>
                </div>
                <div class="meta-role-item <?= $teacherName ? 'has-teacher' : 'no-teacher' ?>">
                    <i class="fa-solid fa-chalkboard-user"></i>
                    <span><?= $teacherName ? $teacherName : 'No In-Charge Teacher' ?></span>
                </div>
            </div>

            <div class="team-score-showcase" style="background: linear-gradient(135deg, <?= $color ?>20 0%, <?= $color ?>0A 100%); border-color: <?= $color ?>38;">
                <div class="score-icon" style="background: <?= $color ?>;"><i class="fa-solid fa-trophy"></i></div>
                <div class="score-val"><?= $score ?> <span class="score-unit">PTS</span></div>
            </div>

            <div class="team-metrics-row">
                <div class="metric-pill" style="background: <?= $color ?>0E; border-color: <?= $color ?>25;">
                    <i class="fa-solid fa-users" style="color: <?= $color ?>;"></i>
                    <span><strong><?= $memberCount ?></strong> Members</span>
                </div>
                <div class="metric-pill" style="background: <?= $color ?>0E; border-color: <?= $color ?>25;">
                    <i class="fa-solid fa-layer-group" style="color: <?= $color ?>;"></i>
                    <span><strong><?= $entryCount ?></strong> Entries</span>
                </div>
            </div>
        </div>

        <div class="team-card-footer" style="border-top-color: <?= $color ?>25;">
            <a href="<?= $membersUrl ?>" class="btn-team-members" style="background: <?= $color ?>;">
                <i class="fa-solid fa-users"></i> Members
            </a>
            <div class="footer-right-btns">
                <button type="button" class="btn-team-edit" data-edit-team='<?= $jsonTeam ?>' title="Edit Team" style="border-color: <?= $color ?>; color: <?= $color ?>; background: <?= $color ?>18;">
                    <i class="fa-solid fa-pen"></i> Edit
                </button>
                <button type="button" class="btn-team-delete" data-delete-id="<?= $teamId ?>" data-delete-name="<?= $name ?>" title="Delete Team">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    if (!$paginatedTeams) {
        echo '<div class="empty-state" style="grid-column: 1 / -1;"><div class="empty-icon"><i class="fa-solid fa-people-group"></i></div><div class="empty-title">No Teams Found</div><div class="empty-subtitle">No matching teams.</div></div>';
    } else {
        foreach ($paginatedTeams as $team) {
            echo render_admin_team_card($team);
        }
    }
    $tbodyHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'html' => $tbodyHtml,
        'pagination' => admin_render_pagination_html($page, $perPage, $totalTeams)
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.teams-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card-v2 {
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.12) 0%, rgba(20, 184, 166, 0.03) 100%);
    border: 1px solid rgba(20, 184, 166, 0.28);
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 14px rgba(20, 184, 166, 0.08);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.stat-card-v2:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(20, 184, 166, 0.2);
    border-color: rgba(20, 184, 166, 0.5);
}
.stat-card-v2 .stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    background: rgba(20, 184, 166, 0.2);
    color: #0d9488;
    box-shadow: 0 0 12px rgba(20, 184, 166, 0.25);
    flex-shrink: 0;
}

.stat-card-v2.blue {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.12) 0%, rgba(37, 99, 235, 0.03) 100%);
    border-color: rgba(37, 99, 235, 0.28);
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.08);
}
.stat-card-v2.blue:hover {
    box-shadow: 0 8px 22px rgba(37, 99, 235, 0.2);
    border-color: rgba(37, 99, 235, 0.5);
}
.stat-card-v2.blue .stat-icon-wrapper {
    background: rgba(37, 99, 235, 0.2);
    color: #2563eb;
    box-shadow: 0 0 12px rgba(37, 99, 235, 0.25);
}

.stat-card-v2.purple {
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.12) 0%, rgba(139, 92, 246, 0.03) 100%);
    border-color: rgba(139, 92, 246, 0.28);
    box-shadow: 0 4px 14px rgba(139, 92, 246, 0.08);
}
.stat-card-v2.purple:hover {
    box-shadow: 0 8px 22px rgba(139, 92, 246, 0.2);
    border-color: rgba(139, 92, 246, 0.5);
}
.stat-card-v2.purple .stat-icon-wrapper {
    background: rgba(139, 92, 246, 0.2);
    color: #8b5cf6;
    box-shadow: 0 0 12px rgba(139, 92, 246, 0.25);
}

.stat-card-v2.gold {
    background: linear-gradient(135deg, rgba(234, 179, 8, 0.15) 0%, rgba(234, 179, 8, 0.04) 100%);
    border-color: rgba(234, 179, 8, 0.32);
    box-shadow: 0 4px 14px rgba(234, 179, 8, 0.1);
}
.stat-card-v2.gold:hover {
    box-shadow: 0 8px 22px rgba(234, 179, 8, 0.25);
    border-color: rgba(234, 179, 8, 0.55);
}
.stat-card-v2.gold .stat-icon-wrapper {
    background: rgba(234, 179, 8, 0.25);
    color: #d97706;
    box-shadow: 0 0 12px rgba(234, 179, 8, 0.3);
}

.teams-search-panel {
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.06) 0%, rgba(20, 184, 166, 0.02) 100%);
    border: 1px solid rgba(20, 184, 166, 0.2);
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
}

.stat-card-v2 .stat-info {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.stat-card-v2 .stat-val {
    font-size: 22px;
    font-weight: 800;
    line-height: 1.2;
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
}
.stat-card-v2 .stat-label {
    font-size: 13px;
    color: var(--muted, #64748b);
    font-weight: 500;
    margin-top: 2px;
}

.teams-grid-v2 {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}
.team-card-v2 {
    position: relative;
    background: linear-gradient(145deg, color-mix(in srgb, var(--team-accent, #14b8a6) 12%, var(--panel-bg, #ffffff)) 0%, color-mix(in srgb, var(--team-accent, #14b8a6) 4%, var(--panel-bg, #ffffff)) 100%);
    border: 1px solid color-mix(in srgb, var(--team-accent, #14b8a6) 35%, var(--border-color, #e2e8f0));
    border-top: 5px solid var(--team-accent, #14b8a6);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.team-card-v2:hover {
    transform: translateY(-4px);
    background: linear-gradient(145deg, color-mix(in srgb, var(--team-accent, #14b8a6) 18%, var(--panel-bg, #ffffff)) 0%, color-mix(in srgb, var(--team-accent, #14b8a6) 7%, var(--panel-bg, #ffffff)) 100%);
    border-color: var(--team-accent, #14b8a6);
    box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.08), 0 0 22px -2px var(--team-accent, rgba(20, 184, 166, 0.35));
}
.team-card-banner {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.team-color-swatch-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--muted, #64748b);
}
.swatch-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--team-accent, #14b8a6);
    box-shadow: 0 0 0 2px rgba(255,255,255,0.8), 0 0 6px var(--team-accent, #14b8a6);
    display: inline-block;
}
.team-code-pill {
    background: var(--team-accent, #14b8a6);
    color: #ffffff;
    font-size: 11px;
    font-weight: 800;
    padding: 3px 10px;
    border-radius: 999px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.team-title {
    font-size: 20px;
    font-weight: 800;
    margin: 8px 0 12px 0;
    color: var(--text-color, #0f172a);
}
.team-meta-roles {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 14px;
}
.meta-role-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    padding: 6px 10px;
    border-radius: 8px;
    background: var(--bg-body, #f8fafc);
}
.meta-role-item i {
    font-size: 14px;
}
.meta-role-item.has-leader {
    color: #0d9488;
    background: rgba(20, 184, 166, 0.08);
    font-weight: 600;
}
.meta-role-item.no-leader {
    color: #94a3b8;
}
.meta-role-item.has-teacher {
    color: #2563eb;
    background: rgba(37, 99, 235, 0.08);
    font-weight: 600;
}
.meta-role-item.no-teacher {
    color: #94a3b8;
}
.team-score-showcase {
    display: flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(135deg, rgba(20, 184, 166, 0.05) 0%, rgba(20, 184, 166, 0.12) 100%);
    border: 1px solid rgba(20, 184, 166, 0.15);
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 12px;
}
.team-score-showcase .score-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: var(--team-accent, #14b8a6);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.team-score-showcase .score-val {
    font-size: 20px;
    font-weight: 900;
    color: var(--text-color, #0f172a);
}
.team-score-showcase .score-unit {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted, #64748b);
    margin-left: 2px;
}
.team-metrics-row {
    display: flex;
    gap: 10px;
}
.metric-pill {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 8px;
    background: var(--bg-body, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    font-size: 12px;
    color: var(--muted, #64748b);
}
.metric-pill strong {
    color: var(--text-color, #0f172a);
}
.team-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 14px;
    border-top: 1px dashed var(--border-color, #e2e8f0);
}
.footer-right-btns {
    display: flex;
    align-items: center;
    gap: 8px;
}
.btn-team-members {
    background: var(--team-accent, #14b8a6);
    color: #ffffff !important;
    border: none;
    padding: 8px 14px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    cursor: pointer;
}
.btn-team-members:hover {
    transform: translateY(-2px) scale(1.04);
    box-shadow: 0 6px 16px -2px var(--team-accent, #14b8a6);
    color: #ffffff !important;
    filter: brightness(1.1);
}
.btn-team-members i {
    transition: transform 0.25s ease;
}
.btn-team-members:hover i {
    transform: translateX(3px);
}

.btn-team-edit {
    padding: 8px 14px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}
.btn-team-edit:hover {
    background: var(--team-accent, #14b8a6) !important;
    color: #ffffff !important;
    border-color: var(--team-accent, #14b8a6) !important;
    transform: translateY(-2px) scale(1.04);
    box-shadow: 0 6px 16px -2px var(--team-accent, #14b8a6);
}
.btn-team-edit i {
    transition: transform 0.25s ease;
}
.btn-team-edit:hover i {
    transform: rotate(20deg) scale(1.15);
}

.btn-team-delete {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.25);
    padding: 8px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
}
.btn-team-delete:hover {
    background: #ef4444;
    color: #ffffff !important;
    border-color: #ef4444;
    transform: translateY(-2px) scale(1.04);
    box-shadow: 0 6px 16px -2px rgba(239, 68, 68, 0.45);
}
.btn-team-delete i {
    transition: transform 0.25s ease;
}
.btn-team-delete:hover i {
    transform: scale(1.2) rotate(-10deg);
}

.color-presets-palette {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}
.preset-color-btn {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform 0.15s ease, border-color 0.15s ease;
}
.preset-color-btn:hover, .preset-color-btn.active {
    transform: scale(1.2);
    border-color: var(--text-color, #0f172a);
}

.team-modal-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 24px;
}
@media (max-width: 840px) {
    .team-modal-grid {
        grid-template-columns: 1fr;
    }
}
.team-preview-box {
    background: var(--bg-body, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
}
.team-preview-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--muted, #64748b);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.poster-preview-wrapper {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.poster-nav-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.12));
}
.poster-tab-btn {
    padding: 8px 16px;
    border-radius: 999px;
    border: 1px solid var(--border-color, rgba(255,255,255,0.15));
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-color, #f8fafc);
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
}
.poster-tab-btn:hover, .poster-tab-btn.active {
    background: var(--poster-theme, #14b8a6);
    color: #ffffff;
    border-color: var(--poster-theme, #14b8a6);
    box-shadow: 0 4px 12px var(--poster-theme-glow, rgba(20, 184, 166, 0.35));
}

.posters-grid-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(440px, 1fr));
    gap: 28px;
    justify-items: center;
}
@media (max-width: 540px) {
    .posters-grid-container {
        grid-template-columns: 1fr;
    }
}

/* Individual Poster Sheet matching exact A4 format */
.team-poster-sheet {
    position: relative;
    width: 100%;
    max-width: 595px;
    aspect-ratio: 1 / 1.4142;
    background: #ffffff !important;
    color: #0f172a !important;
    border: 18px solid var(--poster-color, #8b5cf6);
    border-radius: 4px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.35);
    padding: 28px 24px 24px 24px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-sizing: border-box;
    font-family: 'Inter', system-ui, sans-serif;
    user-select: text;
}

/* Watermark in background */
.poster-watermark {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 1;
    opacity: 0.07;
    color: #000000;
    font-family: 'Cairo', 'Amiri', serif;
    font-size: 76px;
    font-weight: 900;
    line-height: 1.1;
    white-space: nowrap;
    overflow: hidden;
}

/* Corner Geometric Patterns */
.poster-corner-tl {
    position: absolute;
    top: 0;
    left: 0;
    width: 110px;
    height: 110px;
    background: linear-gradient(135deg, var(--poster-color, #8b5cf6) 50%, transparent 50%);
    z-index: 2;
    pointer-events: none;
}
.poster-corner-tr-stripes {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 80px;
    height: 80px;
    background: repeating-linear-gradient(45deg, var(--poster-color, #8b5cf6), var(--poster-color, #8b5cf6) 6px, transparent 6px, transparent 14px);
    z-index: 2;
    pointer-events: none;
}
.poster-corner-bl-stripes {
    position: absolute;
    bottom: 8px;
    left: 8px;
    width: 80px;
    height: 80px;
    background: repeating-linear-gradient(45deg, var(--poster-color, #8b5cf6), var(--poster-color, #8b5cf6) 6px, transparent 6px, transparent 14px);
    z-index: 2;
    pointer-events: none;
}
.poster-corner-br {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 130px;
    height: 130px;
    background: linear-gradient(315deg, var(--poster-color, #8b5cf6) 50%, transparent 50%);
    z-index: 2;
    pointer-events: none;
}

/* Wooden Sign Header */
.poster-header-sign-wrap {
    position: relative;
    z-index: 5;
    display: flex;
    justify-content: center;
    margin-bottom: 14px;
}
.poster-wooden-sign {
    position: relative;
    width: 78%;
    background: linear-gradient(180deg, #d97706 0%, #b45309 55%, #92400e 100%);
    border: 3px solid #78350f;
    border-radius: 8px;
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.35), inset 0 2px 4px rgba(255, 255, 255, 0.35);
    padding: 10px 16px;
    text-align: center;
}
.poster-wooden-sign::before, .poster-wooden-sign::after {
    content: "";
    position: absolute;
    top: -16px;
    width: 12px;
    height: 18px;
    background: #d97706;
    border: 2px solid #78350f;
    border-radius: 4px;
}
.poster-wooden-sign::before { left: 22%; }
.poster-wooden-sign::after { right: 22%; }

.poster-arabic-title {
    font-family: 'Cairo', 'Amiri', 'Traditional Arabic', serif;
    font-size: 44px;
    font-weight: 900;
    color: #2e1003;
    line-height: 1.1;
    text-shadow: 0 1px 2px rgba(255, 255, 255, 0.3);
    margin: 0;
}

/* Usthad / Teachers Center List */
.poster-usthad-section {
    position: relative;
    z-index: 5;
    text-align: center;
    margin-bottom: 16px;
    padding: 0 10px;
}
.poster-usthad-list {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    font-size: 20px;
    font-weight: 900;
    color: #0f172a;
}
.poster-usthad-item {
    line-height: 1.35;
}

/* Two Columns Members Body */
.poster-members-body {
    position: relative;
    z-index: 5;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    flex: 1;
    align-items: start;
    font-size: 13.5px;
    font-weight: 800;
    color: #0f172a;
}
.poster-members-col {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.poster-member-item {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.35;
}
.poster-member-item.is-leader {
    font-weight: 900;
    color: #881337;
}
.poster-leader-tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 900;
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    padding: 1px 6px;
    border-radius: 4px;
    margin-left: 4px;
    vertical-align: baseline;
    letter-spacing: 0.2px;
}

/* Print Styles (Strict A4 Print Layout) */
@media print {
    @page {
        size: A4 portrait;
        margin: 0;
    }
    
    /* Completely hide layout grids, sidebars, headers, other modals, and page alerts */
    .sidebar,
    .mobile-admin-bar,
    .sidebar-overlay,
    .topbar,
    .teams-stats-grid,
    .teams-search-panel,
    .teams-grid-v2,
    #pagination-container,
    #teamModal,
    #deleteModal,
    .alert {
        display: none !important;
    }

    /* Reset global layout containers to simple block flow starting at 0px top */
    .admin-layout,
    .main-content {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        height: auto !important;
        background: transparent !important;
        border: none !important;
    }

    html, body {
        background: #ffffff !important;
        color: #000000 !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        height: auto !important;
    }

    #postersModal {
        display: block !important;
        position: static !important;
        width: 100% !important;
        height: auto !important;
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
        overflow: visible !important;
        z-index: auto !important;
        box-shadow: none !important;
    }

    #postersModal .modal-box {
        max-width: 100% !important;
        max-height: none !important;
        height: auto !important;
        overflow: visible !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        display: block !important;
    }

    #postersModal .poster-preview-wrapper {
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
        padding: 0 !important;
        display: block !important;
    }

    .modal-header,
    .poster-nav-tabs,
    .modal-close,
    .btn {
        display: none !important;
    }

    .posters-grid-container {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .team-poster-sheet {
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        page-break-after: always !important;
        break-after: page !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        box-shadow: none !important;
        margin: 0 auto !important;
        width: 210mm !important;
        height: 290mm !important;
        max-width: 210mm !important;
        max-height: 290mm !important;
        box-sizing: border-box !important;
        border-width: 14px !important;
        padding: 20px 18px 16px 18px !important;
        overflow: hidden !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .team-poster-sheet .poster-arabic-title {
        font-size: 38px !important;
    }

    .team-poster-sheet .poster-wooden-sign {
        padding: 8px 14px !important;
        margin-bottom: 10px !important;
    }

    .team-poster-sheet .poster-usthad-list {
        font-size: 18px !important;
        gap: 2px !important;
    }

    .team-poster-sheet .poster-usthad-section {
        margin-bottom: 12px !important;
    }

    .team-poster-sheet .poster-members-body {
        font-size: 12.5px !important;
        gap: 12px !important;
    }

    .team-poster-sheet .poster-member-item {
        line-height: 1.25 !important;
    }

    .team-poster-sheet:first-child,
    .team-poster-sheet:first-of-type {
        page-break-before: avoid !important;
        break-before: avoid !important;
    }
}
</style>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Teams Management</div>
            <div class="page-subtitle"><?= e($activeEvent['title']) ?></div>
        </div>
        <div class="topbar-actions" style="display:flex; gap:10px;">
            <button type="button" class="btn btn-secondary btn-md" data-open-posters>
                <i class="fa-solid fa-file-image"></i> Posters Preview
            </button>
            <a href="<?= app_url('/admin/members.php') ?>" class="btn btn-secondary btn-md">
                <i class="fa-solid fa-users"></i> All Members
            </a>
            <button class="btn btn-success btn-md" data-open-team>
                <i class="fa-solid fa-plus"></i> Create Team
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- Overview Stats Bar -->
    <div class="teams-stats-grid">
        <div class="stat-card-v2">
            <div class="stat-icon-wrapper"><i class="fa-solid fa-people-group"></i></div>
            <div class="stat-info">
                <div class="stat-val"><?= $totalTeams ?></div>
                <div class="stat-label">Total Teams</div>
            </div>
        </div>
        <div class="stat-card-v2 blue">
            <div class="stat-icon-wrapper"><i class="fa-solid fa-users"></i></div>
            <div class="stat-info">
                <div class="stat-val"><?= $totalActiveMembers ?></div>
                <div class="stat-label">Active Members</div>
            </div>
        </div>
        <div class="stat-card-v2 purple">
            <div class="stat-icon-wrapper"><i class="fa-solid fa-layer-group"></i></div>
            <div class="stat-info">
                <div class="stat-val"><?= $totalProgramEntries ?></div>
                <div class="stat-label">Program Entries</div>
            </div>
        </div>
        <div class="stat-card-v2 gold">
            <div class="stat-icon-wrapper"><i class="fa-solid fa-trophy"></i></div>
            <div class="stat-info">
                <div class="stat-val"><?= $topTeam ? e($topTeam['team_name']) : 'N/A' ?></div>
                <div class="stat-label">Leaderboard Leader (<?= $topTeam ? number_format((float)($topTeam['total_score'] ?? 0), 2) : 0 ?> pts)</div>
            </div>
        </div>
    </div>

    <div class="panel teams-search-panel mb-6">
        <form method="GET" class="form-grid" id="search-form">
            <div class="input-group full-width">
                <label>Search Teams</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by team name, short code, or prefix number...">
            </div>
            <div class="form-actions full-width">
                <button class="btn btn-secondary btn-md" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-secondary btn-md" href="<?= app_url('/admin/teams.php') ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$teams): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-people-group"></i></div>
            <div class="empty-title">No Teams Found</div>
            <div class="empty-subtitle">Get started by creating teams for this event.</div>
        </div>
    <?php else: ?>
        <div class="teams-grid-v2" id="table-body">
            <?php foreach ($paginatedTeams as $team): ?>
                <?= render_admin_team_card($team) ?>
            <?php endforeach; ?>
        </div>
        <div id="pagination-container" class="mt-4">
            <?= admin_render_pagination_html($page, $perPage, $totalTeams) ?>
        </div>
    <?php endif; ?>

<div class="modal-overlay" id="teamModal">
    <div class="modal-box modal-lg" style="max-width: 820px;">
        <div class="modal-header">
            <div class="modal-title" id="teamModalTitle">Create Team</div>
            <button class="modal-close" type="button" data-close="teamModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="teamForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" id="teamAction" value="create">
            <input type="hidden" name="team_id" id="teamId">

            <div class="team-modal-grid">
                <div class="modal-form-col">
                    <div class="form-grid">
                        <div class="input-group full-width">
                            <label>Team Name <span class="required">*</span></label>
                            <input type="text" name="team_name" id="teamName" required placeholder="e.g. Al-Fursan">
                        </div>
                        <div class="input-group full-width">
                            <label>Short Name / Code</label>
                            <input type="text" name="short_name" id="teamShort" placeholder="e.g. ALF">
                        </div>
                        <div class="input-group full-width">
                            <label>Group Leader</label>
                            <select name="group_leader_student_id" id="teamGroupLeader">
                                <option value="">Add members before selecting a leader</option>
                            </select>
                        </div>
                        <div class="input-group full-width">
                            <label>Teacher in Charge</label>
                            <select name="teacher_incharge_id" id="teamTeacherIncharge">
                                <option value="">No teacher in charge</option>
                                <?php foreach ($teacherOptions as $teacher): ?>
                                    <option value="<?= (int)$teacher['id'] ?>"><?= e($teacher['full_name'] ?: ('Teacher #' . (int)$teacher['id'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group full-width">
                            <label>Team Theme Color</label>
                            <div class="color-input-container">
                                <div class="color-chip-list" id="teamColorChips" aria-live="polite"></div>
                                <input type="text" id="teamColorEntry" autocomplete="off" placeholder="Pick a preset or type hex code (e.g. #14b8a6)">
                            </div>
                            <input type="hidden" name="team_color" id="teamColor" value="#14b8a6">
                            <div class="color-suggestions" id="teamColorSuggestions" role="listbox" aria-label="Team color suggestions"></div>

                            <div class="color-presets-palette mt-2">
                                <button type="button" class="preset-color-btn" style="background:#14b8a6;" data-color="#14b8a6" title="Teal"></button>
                                <button type="button" class="preset-color-btn" style="background:#10b981;" data-color="#10b981" title="Emerald"></button>
                                <button type="button" class="preset-color-btn" style="background:#3b82f6;" data-color="#3b82f6" title="Blue"></button>
                                <button type="button" class="preset-color-btn" style="background:#6366f1;" data-color="#6366f1" title="Indigo"></button>
                                <button type="button" class="preset-color-btn" style="background:#8b5cf6;" data-color="#8b5cf6" title="Purple"></button>
                                <button type="button" class="preset-color-btn" style="background:#f43f5e;" data-color="#f43f5e" title="Rose"></button>
                                <button type="button" class="preset-color-btn" style="background:#ef4444;" data-color="#ef4444" title="Red"></button>
                                <button type="button" class="preset-color-btn" style="background:#f97316;" data-color="#f97316" title="Orange"></button>
                                <button type="button" class="preset-color-btn" style="background:#f59e0b;" data-color="#f59e0b" title="Amber"></button>
                                <button type="button" class="preset-color-btn" style="background:#eab308;" data-color="#eab308" title="Gold"></button>
                                <button type="button" class="preset-color-btn" style="background:#06b6d4;" data-color="#06b6d4" title="Cyan"></button>
                                <button type="button" class="preset-color-btn" style="background:#64748b;" data-color="#64748b" title="Slate"></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="team-preview-box">
                    <div class="team-preview-label"><i class="fa-solid fa-eye"></i> Live Card Preview</div>
                    <div class="team-card-v2" id="modalCardPreview" style="--team-accent: #14b8a6; width: 100%;">
                        <div class="team-card-banner">
                            <div class="team-color-swatch-badge">
                                <span class="swatch-dot" id="previewSwatchDot"></span>
                                <span class="team-prefix-tag" id="previewPrefix">Prefix 100+</span>
                            </div>
                            <span class="team-code-pill" id="previewCodePill">TMP</span>
                        </div>
                        <div class="team-card-main">
                            <h3 class="team-title" id="previewTitle">New Team</h3>
                            <div class="team-meta-roles">
                                <div class="meta-role-item no-leader" id="previewLeader"><i class="fa-solid fa-user-graduate"></i> <span>No Leader Assigned</span></div>
                                <div class="meta-role-item no-teacher" id="previewTeacher"><i class="fa-solid fa-chalkboard-user"></i> <span>No In-Charge Teacher</span></div>
                            </div>
                            <div class="team-score-showcase">
                                <div class="score-icon"><i class="fa-solid fa-trophy"></i></div>
                                <div class="score-val">0.00 <span class="score-unit">PTS</span></div>
                            </div>
                        </div>
                        <div class="team-card-footer">
                            <span class="btn-team-members" id="previewBtnMembers" style="background: #14b8a6;">
                                <i class="fa-solid fa-users"></i> Members
                            </span>
                            <div class="footer-right-btns">
                                <span class="btn-team-edit" id="previewBtnEdit" style="border-color: #14b8a6; color: #14b8a6; background: #14b8a618;">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions mt-4">
                <button type="button" class="btn btn-secondary btn-md" data-close="teamModal">Cancel</button>
                <button class="btn btn-success btn-md" type="submit"><i class="fa-solid fa-check"></i> Save Team</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div class="modal-title">Delete Team</div>
            <button class="modal-close" type="button" data-close="deleteModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="panel mb-4">Delete <strong id="deleteName"></strong>? Teams with active members or program entries are protected from deletion.</div>
        <form method="POST">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="team_id" id="deleteId">
            <div class="form-actions">
                <button type="button" class="btn btn-secondary btn-md" data-close="deleteModal">Cancel</button>
                <button class="btn btn-danger btn-md" type="submit"><i class="fa-solid fa-trash"></i> Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="postersModal">
    <div class="modal-box modal-xl" style="max-width: 1140px; max-height: 92vh; display:flex; flex-direction:column; padding: 24px;">
        <div class="modal-header" style="margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <div class="modal-title"><i class="fa-solid fa-file-image"></i> Team Posters Preview</div>
            <div style="display:flex; gap:10px; align-items:center;">
                <button type="button" class="btn btn-secondary btn-md" onclick="window.print();">
                    <i class="fa-solid fa-print"></i> Print Posters
                </button>
                <button class="modal-close" type="button" data-close="postersModal"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>

        <div class="poster-preview-wrapper" style="flex:1; overflow-y:auto; padding-right:4px;">
            <div class="poster-nav-tabs" id="posterTabs">
                <button type="button" class="poster-tab-btn active" data-poster-target="all">
                    <i class="fa-solid fa-layer-group" style="margin-right: 6px;"></i> All Teams Grid
                </button>
                <?php foreach ($teams as $team): ?>
                    <?php $color = !empty($team['team_color']) ? e($team['team_color']) : '#14b8a6'; ?>
                    <button type="button" class="poster-tab-btn" data-poster-target="team-<?= (int)$team['id'] ?>" style="--poster-theme: <?= $color ?>;">
                        <span style="background: <?= $color ?>; display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px;"></span>
                        <?= e($team['team_name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="posters-grid-container" id="postersGrid" style="margin-top: 20px;">
                <?php foreach ($teams as $team): ?>
                    <?php
                        $teamId = (int)$team['id'];
                        $color = !empty($team['team_color']) ? e($team['team_color']) : '#14b8a6';
                        $arabicTitle = get_arabic_team_name($team['team_name'], $team['short_name']);
                        $members = $leaderOptionsByTeam[$teamId] ?? [];
                        $leaderId = (int)($team['group_leader_student_id'] ?? 0);
                        $leaderName = trim((string)($team['group_leader_name'] ?? ''));

                        // Sort student members: Group Leader #1, followed by Class Order
                        usort($members, function($a, $b) use ($leaderId, $leaderName) {
                            $aIsLeader = ($leaderId > 0 && $leaderId === (int)$a['id']) || ($leaderName !== '' && trim($a['name']) === $leaderName);
                            $bIsLeader = ($leaderId > 0 && $leaderId === (int)$b['id']) || ($leaderName !== '' && trim($b['name']) === $leaderName);
                            
                            if ($aIsLeader && !$bIsLeader) return -1;
                            if (!$aIsLeader && $bIsLeader) return 1;

                            $yearA = (int)($a['class_year'] ?? 0);
                            $yearB = (int)($b['class_year'] ?? 0);
                            if ($yearA !== $yearB) {
                                return $yearA <=> $yearB;
                            }

                            $typeA = (int)($a['class_type_id'] ?? 0);
                            $typeB = (int)($b['class_type_id'] ?? 0);
                            if ($typeA !== $typeB) {
                                return $typeA <=> $typeB;
                            }

                            $classA = (int)($a['class_id'] ?? 0);
                            $classB = (int)($b['class_id'] ?? 0);
                            if ($classA !== $classB) {
                                return $classA <=> $classB;
                            }

                            return strcasecmp($a['name'], $b['name']);
                        });

                        $totalMembers = count($members);
                        $half = (int)ceil($totalMembers / 2);
                        if ($half < 1) $half = 1;
                        $leftCol = array_slice($members, 0, $half);
                        $rightCol = array_slice($members, $half);

                        // Usthad list (Teachers on top)
                        $usthads = [];
                        if (!empty($team['teacher_incharge_name'])) {
                            $usthads[] = $team['teacher_incharge_name'];
                        }
                        $defaultUsthads = ['Jamaludheen Usthad', 'Mahmood Usthad', 'Salman Usthad', 'Fazil Usthad', 'Salih Usthad'];
                        foreach ($defaultUsthads as $defU) {
                            if (count($usthads) >= 5) break;
                            if (!in_array($defU, $usthads, true)) {
                                $usthads[] = $defU;
                            }
                        }

                        $leaderId = (int)($team['group_leader_student_id'] ?? 0);
                        $leaderName = trim((string)($team['group_leader_name'] ?? ''));
                    ?>
                    <div class="team-poster-sheet" id="poster-team-<?= $teamId ?>" style="--poster-color: <?= $color ?>;">
                        <!-- Watermark -->
                        <div class="poster-watermark">
                            <span>التنافس</span>
                            <span>التنافس</span>
                            <span>التنافس</span>
                        </div>

                        <!-- Corners -->
                        <div class="poster-corner-tl"></div>
                        <div class="poster-corner-tr-stripes"></div>
                        <div class="poster-corner-bl-stripes"></div>
                        <div class="poster-corner-br"></div>

                        <!-- Header Wooden Sign -->
                        <div class="poster-header-sign-wrap">
                            <div class="poster-wooden-sign">
                                <h1 class="poster-arabic-title"><?= e($arabicTitle) ?></h1>
                            </div>
                        </div>

                        <!-- Usthad List (Teachers on top) -->
                        <div class="poster-usthad-section">
                            <div class="poster-usthad-list">
                                <?php foreach ($usthads as $idx => $usthad): ?>
                                    <div class="poster-usthad-item"><?= ($idx + 1) ?>) <?= e($usthad) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Members 2 Columns (Leader tagged inside list) -->
                        <div class="poster-members-body">
                            <div class="poster-members-col">
                                <?php if ($leftCol): ?>
                                    <?php foreach ($leftCol as $idx => $mem): ?>
                                        <?php $isLeader = ($leaderId > 0 && $leaderId === (int)$mem['id']) || ($leaderName !== '' && trim($mem['name']) === $leaderName); ?>
                                        <div class="poster-member-item <?= $isLeader ? 'is-leader' : '' ?>">
                                            <?= ($idx + 1) ?>.<?= e($mem['name']) ?>
                                            <?php if ($isLeader): ?>
                                                <span class="poster-leader-tag">(Leader)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="poster-member-item" style="opacity: 0.5;">No members assigned</div>
                                <?php endif; ?>
                            </div>

                            <div class="poster-members-col">
                                <?php foreach ($rightCol as $idx => $mem): ?>
                                    <?php $isLeader = ($leaderId > 0 && $leaderId === (int)$mem['id']) || ($leaderName !== '' && trim($mem['name']) === $leaderName); ?>
                                    <div class="poster-member-item <?= $isLeader ? 'is-leader' : '' ?>">
                                        <?= ($half + $idx + 1) ?>.<?= e($mem['name']) ?>
                                        <?php if ($isLeader): ?>
                                            <span class="poster-leader-tag">(Leader)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const LEADER_OPTIONS_BY_TEAM = <?= json_encode($leaderOptionsByTeam, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function populateLeaderOptions(teamId, selectedStudentId = '') {
        const select = document.getElementById('teamGroupLeader');
        if (!select) return;

        select.innerHTML = '';
        const students = LEADER_OPTIONS_BY_TEAM[String(teamId)] || [];
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = students.length ? 'No group leader' : 'No active members in this team';
        select.appendChild(emptyOption);

        students.forEach(student => {
            const option = document.createElement('option');
            const chestNumber = student.chest_number ? `#${student.chest_number} · ` : '';
            option.value = student.id;
            option.textContent = `${chestNumber}${student.name || `Student #${student.id}`} · ${student.class_name || 'Unassigned'}`;
            option.selected = String(student.id) === String(selectedStudentId);
            select.appendChild(option);
        });
    }

    function updateLiveModalPreview() {
        const name = document.getElementById('teamName')?.value.trim() || 'New Team';
        const short = document.getElementById('teamShort')?.value.trim() || '';
        const leaderSelect = document.getElementById('teamGroupLeader');
        const teacherSelect = document.getElementById('teamTeacherIncharge');
        const color = document.getElementById('teamColor')?.value || '#14b8a6';

        let leaderText = null;
        if (leaderSelect && leaderSelect.selectedIndex > 0 && leaderSelect.value !== '') {
            leaderText = leaderSelect.options[leaderSelect.selectedIndex].text.split('·')[0].trim();
        }

        let teacherText = null;
        if (teacherSelect && teacherSelect.selectedIndex > 0 && teacherSelect.value !== '') {
            teacherText = teacherSelect.options[teacherSelect.selectedIndex].text.trim();
        }

        const previewCard = document.getElementById('modalCardPreview');
        if (previewCard) {
            previewCard.style.setProperty('--team-accent', color);
            previewCard.style.background = `linear-gradient(145deg, ${color}14 0%, ${color}05 100%)`;
            previewCard.style.borderColor = `${color}35`;
            previewCard.style.borderTopColor = color;
        }

        const btnMembers = document.getElementById('previewBtnMembers');
        if (btnMembers) {
            btnMembers.style.background = color;
        }
        const btnEdit = document.getElementById('previewBtnEdit');
        if (btnEdit) {
            btnEdit.style.borderColor = color;
            btnEdit.style.color = color;
            btnEdit.style.background = color + '18';
        }

        const titleEl = document.getElementById('previewTitle');
        if (titleEl) titleEl.textContent = name;

        const codePill = document.getElementById('previewCodePill');
        if (codePill) {
            codePill.textContent = short || 'TMP';
            codePill.style.display = short ? 'inline-block' : 'none';
        }

        const leaderEl = document.getElementById('previewLeader');
        if (leaderEl) {
            if (leaderText) {
                leaderEl.className = 'meta-role-item has-leader';
                leaderEl.innerHTML = `<i class="fa-solid fa-user-graduate"></i> <span>${leaderText}</span>`;
            } else {
                leaderEl.className = 'meta-role-item no-leader';
                leaderEl.innerHTML = `<i class="fa-solid fa-user-graduate"></i> <span>No Leader Assigned</span>`;
            }
        }

        const teacherEl = document.getElementById('previewTeacher');
        if (teacherEl) {
            if (teacherText) {
                teacherEl.className = 'meta-role-item has-teacher';
                teacherEl.innerHTML = `<i class="fa-solid fa-chalkboard-user"></i> <span>${teacherText}</span>`;
            } else {
                teacherEl.className = 'meta-role-item no-teacher';
                teacherEl.innerHTML = `<i class="fa-solid fa-chalkboard-user"></i> <span>No In-Charge Teacher</span>`;
            }
        }
    }

    // Modal triggers & actions
    document.querySelector('[data-open-team]')?.addEventListener('click', () => {
        document.getElementById('teamForm').reset();
        document.getElementById('teamModalTitle').textContent = 'Create Team';
        document.getElementById('teamAction').value = 'create';
        document.getElementById('teamId').value = '';
        populateLeaderOptions(0);
        document.getElementById('teamTeacherIncharge').value = '';
        teamColorState.color = '';
        teamColorHidden.value = '#14b8a6';
        renderTeamColorChip();
        teamColorSuggestionsEl?.classList.remove('active');
        updateLiveModalPreview();
        window.openModal('teamModal');
    });

    document.querySelector('[data-open-posters]')?.addEventListener('click', () => {
        window.openModal('postersModal');
    });

    document.querySelectorAll('[data-poster-target]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.posterTarget;
            document.querySelectorAll('[data-poster-target]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const posterSheets = document.querySelectorAll('.team-poster-sheet');
            if (target === 'all') {
                posterSheets.forEach(sheet => sheet.style.display = 'flex');
            } else {
                posterSheets.forEach(sheet => {
                    sheet.style.display = (sheet.id === 'poster-' + target) ? 'flex' : 'none';
                });
            }
        });
    });

    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => window.closeModal(btn.dataset.close)));
    document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) window.closeModal(modal.id); }));

    const TEAM_COLOR_SUGGESTIONS = [
        { label: 'Teal', value: '#14b8a6' },
        { label: 'Emerald', value: '#10b981' },
        { label: 'Green', value: '#22c55e' },
        { label: 'Blue', value: '#2563eb' },
        { label: 'Indigo', value: '#6366f1' },
        { label: 'Orange', value: '#f97316' },
        { label: 'Rose', value: '#f43f5e' },
        { label: 'Red', value: '#ef4444' },
        { label: 'Purple', value: '#8b5cf6' },
        { label: 'Amber', value: '#f59e0b' },
        { label: 'Gold', value: '#eab308' },
        { label: 'Sky', value: '#0ea5e9' },
        { label: 'Cyan', value: '#06b6d4' },
        { label: 'Slate', value: '#64748b' }
    ];

    const teamColorState = { color: '' };
    const teamColorChipsEl = document.getElementById('teamColorChips');
    const teamColorInput = document.getElementById('teamColorEntry');
    const teamColorHidden = document.getElementById('teamColor');
    const teamColorSuggestionsEl = document.getElementById('teamColorSuggestions');

    function normalizeColorValue(value) {
        return String(value || '').trim();
    }

    function isValidColorValue(value) {
        if (!value) return false;
        return CSS.supports('color', normalizeColorValue(value));
    }

    function renderTeamColorChip() {
        if (!teamColorChipsEl) return;
        teamColorChipsEl.innerHTML = '';
        const color = normalizeColorValue(teamColorState.color);
        if (!color) return;
        const chip = document.createElement('span');
        chip.className = 'color-chip';
        chip.innerHTML = `
            <span class="color-chip-swatch" style="background:${color};"></span>
            <span class="color-chip-label">${color}</span>
            <button type="button" class="color-chip-remove" aria-label="Remove ${color}">&times;</button>
        `;
        chip.querySelector('.color-chip-remove')?.addEventListener('click', () => {
            teamColorState.color = '';
            if (teamColorHidden) teamColorHidden.value = '#14b8a6';
            renderTeamColorChip();
            if (teamColorInput) renderTeamColorSuggestions(teamColorInput.value.trim());
            updateLiveModalPreview();
        });
        teamColorChipsEl.appendChild(chip);
    }

    function setTeamColor(value) {
        const color = normalizeColorValue(value);
        if (!color || !isValidColorValue(color)) return;
        teamColorState.color = color;
        if (teamColorHidden) teamColorHidden.value = color;
        renderTeamColorChip();
        updateLiveModalPreview();
    }

    function filterTeamColorSuggestions(query) {
        const normalized = String(query || '').toLowerCase().trim();
        if (!normalized) return TEAM_COLOR_SUGGESTIONS;
        return TEAM_COLOR_SUGGESTIONS.filter(item => item.label.toLowerCase().includes(normalized) || item.value.toLowerCase().includes(normalized));
    }

    function renderTeamColorSuggestions(query) {
        if (!teamColorSuggestionsEl) return;
        const suggestions = filterTeamColorSuggestions(query);
        teamColorSuggestionsEl.innerHTML = '';
        if (!suggestions.length) {
            teamColorSuggestionsEl.classList.remove('active');
            return;
        }
        suggestions.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'color-suggestion';
            button.innerHTML = `
                <span class="color-swatch" style="background:${item.value};"></span>
                <span>${item.label}</span>
                <small>${item.value}</small>
            `;
            button.addEventListener('click', () => {
                setTeamColor(item.value);
                if (teamColorInput) teamColorInput.value = '';
                teamColorSuggestionsEl.classList.remove('active');
                if (teamColorInput) teamColorInput.focus();
            });
            teamColorSuggestionsEl.appendChild(button);
        });
        teamColorSuggestionsEl.classList.add('active');
    }

    teamColorInput?.addEventListener('input', event => renderTeamColorSuggestions(event.target.value));
    teamColorInput?.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            setTeamColor(teamColorInput.value);
            teamColorInput.value = '';
            if (teamColorSuggestionsEl) teamColorSuggestionsEl.classList.remove('active');
        }
    });

    // Preset color buttons click handler
    document.querySelectorAll('.preset-color-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const color = btn.dataset.color;
            if (color) {
                setTeamColor(color);
                document.querySelectorAll('.preset-color-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            }
        });
    });

    // Inputs change listeners for real-time live preview
    ['teamName', 'teamShort', 'teamGroupLeader', 'teamTeacherIncharge'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateLiveModalPreview);
            el.addEventListener('change', updateLiveModalPreview);
        }
    });

    // Only attach global document listeners ONCE per session
    if (!window._teamsPageInit) {
        window._teamsPageInit = true;
        
        document.addEventListener('click', event => {
            if (!event.target.closest('.color-input-container') && !event.target.closest('.color-suggestion')) {
                const sugg = document.getElementById('teamColorSuggestions');
                if (sugg) sugg.classList.remove('active');
            }
        });

        document.addEventListener('click', (e) => {
            const editBtn = e.target.closest('[data-edit-team]');
            if (editBtn) {
                const team = JSON.parse(editBtn.dataset.editTeam);
                document.getElementById('teamModalTitle').textContent = 'Edit Team';
                document.getElementById('teamAction').value = 'update';
                document.getElementById('teamId').value = team.id || '';
                document.getElementById('teamName').value = team.team_name || '';
                document.getElementById('teamShort').value = team.short_name || '';
                populateLeaderOptions(team.id, team.group_leader_student_id || '');
                document.getElementById('teamTeacherIncharge').value = team.teacher_incharge_id || '';
                
                const color = team.team_color || '#14b8a6';
                const hiddenInput = document.getElementById('teamColor');
                if (hiddenInput) hiddenInput.value = color;
                teamColorState.color = color;
                renderTeamColorChip();
                
                const suggEl = document.getElementById('teamColorSuggestions');
                if (suggEl) suggEl.classList.remove('active');
                
                updateLiveModalPreview();
                window.openModal('teamModal');
                return;
            }

            const deleteBtn = e.target.closest('[data-delete-id]');
            if (deleteBtn) {
                document.getElementById('deleteId').value = deleteBtn.dataset.deleteId;
                document.getElementById('deleteName').textContent = deleteBtn.dataset.deleteName || 'this team';
                window.openModal('deleteModal');
                return;
            }
        });
    }
})();
</script>
</div>
<?= admin_ajax_pagination_script() ?>
<?php admin_close_page(); ?>
