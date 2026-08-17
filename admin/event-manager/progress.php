<?php
$pageTitle = 'Program Progress & Student Individual Marks';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';
require_login();

$activeEvent = get_active_musabaqa();
$pdo = $GLOBALS['musabaqa_pdo'];

if (!$activeEvent) {
    require_once __DIR__ . '/../../includes/header.php';
    require_once __DIR__ . '/../../includes/sidebar.php';
    render_no_active_event_guard();
    admin_close_page();
    exit;
}

$eventId = (int)$activeEvent['id'];

// Filter Inputs
$sessionFilter = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int)$_GET['session_id'] : null;
$teamFilter = isset($_GET['team_id']) && $_GET['team_id'] !== '' ? (int)$_GET['team_id'] : null;
$programFilter = isset($_GET['program_id']) && $_GET['program_id'] !== '' ? (int)$_GET['program_id'] : null;
$search = trim((string)($_GET['search'] ?? ''));
$currentTab = trim((string)($_GET['tab'] ?? 'program'));

// Load Schedule Sessions
$sessionsStmt = $pdo->prepare("SELECT id, name FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY section_date ASC, start_time ASC, sort_order ASC, name ASC");
$sessionsStmt->execute([$eventId]);
$scheduleSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Load Teams
$teamsStmt = $pdo->prepare("SELECT id, team_name, team_color FROM musabaqa_teams WHERE event_id = ? ORDER BY team_name ASC");
$teamsStmt->execute([$eventId]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Load Programs
$programsStmt = $pdo->prepare("SELECT id, title, program_type, section_id FROM musabaqa_programs WHERE event_id = ? ORDER BY id ASC, title ASC");
$programsStmt->execute([$eventId]);
$allPrograms = $programsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Program Entries with Scores and Member Details
$sql = "
    SELECT 
        pe.id AS entry_id,
        pe.program_id,
        pe.team_id,
        pe.entry_name,
        pe.entry_number,
        pe.performance_order,
        pe.status AS entry_status,
        p.title AS program_title,
        p.program_type,
        p.judges_count,
        p.stage_type_id,
        p.section_id,
        sec.name AS session_name,
        t.team_name,
        t.team_color,
        ss.id AS score_sheet_id,
        ss.judge1_total,
        ss.judge2_total,
        ss.final_total,
        ss.status AS sheet_status,
        (
            SELECT GROUP_CONCAT(
                CONCAT_WS(':', tm.id, tm.chest_number, COALESCE(NULLIF(s.display_name, ''), s.full_name))
                SEPARATOR '||'
            )
            FROM musabaqa_entry_members em
            JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
            JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
            WHERE em.entry_id = pe.id
        ) AS members_raw
    FROM musabaqa_program_entries pe
    JOIN musabaqa_programs p ON p.id = pe.program_id
    LEFT JOIN musabaqa_schedule_sections sec ON sec.id = p.section_id
    JOIN musabaqa_teams t ON t.id = pe.team_id
    LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
    WHERE pe.event_id = ?
";

$params = [$eventId];

if ($sessionFilter !== null) {
    $sql .= " AND p.section_id = ?";
    $params[] = $sessionFilter;
}

if ($teamFilter !== null) {
    $sql .= " AND pe.team_id = ?";
    $params[] = $teamFilter;
}

if ($programFilter !== null) {
    $sql .= " AND pe.program_id = ?";
    $params[] = $programFilter;
}

if ($search !== '') {
    $sql .= " AND (pe.entry_name LIKE ? OR p.title LIKE ? OR t.team_name LIKE ? OR pe.entry_number LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY p.id ASC, p.title ASC, pe.performance_order ASC, pe.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entriesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process Entries & Grouping
$programsData = [];
$studentMarksList = [];
$teamGroupedData = [];

foreach ($entriesRaw as $r) {
    $pId = (int)$r['program_id'];
    $tId = (int)$r['team_id'];
    $jCount = (int)($r['judges_count'] ?? 2);

    // Format members
    $membersList = [];
    if (!empty($r['members_raw'])) {
        $mParts = explode('||', $r['members_raw']);
        foreach ($mParts as $mp) {
            $mFields = explode(':', $mp, 3);
            if (count($mFields) === 3) {
                $membersList[] = [
                    'team_member_id' => (int)$mFields[0],
                    'chest_number' => $mFields[1],
                    'full_name' => $mFields[2]
                ];
            }
        }
    }

    $entryItem = [
        'entry_id' => (int)$r['entry_id'],
        'program_id' => $pId,
        'team_id' => $tId,
        'entry_name' => $r['entry_name'],
        'entry_number' => $r['entry_number'],
        'program_title' => $r['program_title'],
        'program_type' => $r['program_type'],
        'judges_count' => $jCount,
        'session_name' => $r['session_name'] ?: 'General',
        'team_name' => $r['team_name'],
        'team_color' => $r['team_color'],
        'has_score' => !empty($r['score_sheet_id']),
        'judge1_total' => $r['judge1_total'] !== null ? (float)$r['judge1_total'] : null,
        'judge2_total' => $r['judge2_total'] !== null ? (float)$r['judge2_total'] : null,
        'final_total' => $r['final_total'] !== null ? (float)$r['final_total'] : null,
        'sheet_status' => $r['sheet_status'] ?: 'pending',
        'members' => $membersList
    ];

    if (!isset($programsData[$pId])) {
        $programsData[$pId] = [
            'id' => $pId,
            'title' => $r['program_title'],
            'type' => $r['program_type'],
            'judges_count' => $jCount,
            'session_name' => $r['session_name'] ?: 'General',
            'entries' => [],
            'scored_count' => 0,
            'total_count' => 0
        ];
    }

    $programsData[$pId]['entries'][] = $entryItem;
    $programsData[$pId]['total_count']++;
    if ($entryItem['has_score']) {
        $programsData[$pId]['scored_count']++;
    }

    if (!isset($teamGroupedData[$tId])) {
        $teamGroupedData[$tId] = [
            'team_name' => $r['team_name'],
            'team_color' => $r['team_color'],
            'entries' => [],
            'scored_count' => 0,
            'total_count' => 0
        ];
    }
    $teamGroupedData[$tId]['entries'][] = $entryItem;
    $teamGroupedData[$tId]['total_count']++;
    if ($entryItem['has_score']) {
        $teamGroupedData[$tId]['scored_count']++;
    }

    // Populate student individual list
    if (!empty($membersList)) {
        foreach ($membersList as $m) {
            $studentMarksList[] = [
                'student_name' => $m['full_name'],
                'chest_number' => $m['chest_number'] ?: $r['entry_number'],
                'team_name' => $r['team_name'],
                'team_color' => $r['team_color'],
                'program_title' => $r['program_title'],
                'program_type' => $r['program_type'],
                'entry_name' => $r['entry_name'],
                'judges_count' => $jCount,
                'judge1_total' => $entryItem['judge1_total'],
                'judge2_total' => $entryItem['judge2_total'],
                'final_total' => $entryItem['final_total'],
                'has_score' => $entryItem['has_score']
            ];
        }
    } else {
        $studentMarksList[] = [
            'student_name' => $r['entry_name'],
            'chest_number' => $r['entry_number'],
            'team_name' => $r['team_name'],
            'team_color' => $r['team_color'],
            'program_title' => $r['program_title'],
            'program_type' => $r['program_type'],
            'entry_name' => $r['entry_name'],
            'judges_count' => $jCount,
            'judge1_total' => $entryItem['judge1_total'],
            'judge2_total' => $entryItem['judge2_total'],
            'final_total' => $entryItem['final_total'],
            'has_score' => $entryItem['has_score']
        ];
    }
}

// Stats
$totalProgramsCount = count($allPrograms);
$totalEntriesCount = count($entriesRaw);
$scoredEntriesCount = count(array_filter($entriesRaw, fn($e) => !empty($e['score_sheet_id'])));
$progressPercent = $totalEntriesCount > 0 ? round(($scoredEntriesCount / $totalEntriesCount) * 100, 1) : 0;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="musabaqa-hub-header mb-6">
        <div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                <span class="analytics-header-icon" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.25); width: 42px; height: 42px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fa-solid fa-square-poll-vertical"></i>
                </span>
                <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #fff;">Program Progress & Individual Marks</h1>
                <span class="badge badge-success" style="font-weight: 700; font-size: 12px;">
                    <i class="fa-solid fa-chart-line mr-1"></i> Live Tracking
                </span>
            </div>
            <p class="muted" style="margin: 0; font-size: 13.5px;">View event scoring progress, individual student judge marks, and team performance breakdown</p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="btn btn-primary btn-sm" style="background: #10b981; border-color: #10b981;">
                <i class="fa-solid fa-pen-to-square mr-1"></i> Score Entry
            </a>
            <a href="<?= app_url('/admin/event-manager/index.php') ?>" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left mr-1"></i> Admin Hub
            </a>
        </div>
    </div>

    <!-- OVERALL EVENT PROGRESS & STATS -->
    <div class="panel mb-6" style="padding: 22px; background: rgba(15,23,42,0.65); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px;">
        <div class="grid grid-4 gap-4 mb-4">
            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 12px;">
                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Overall Scoring Progress</div>
                <div style="font-size: 26px; font-weight: 800; color: #34d399; margin-top: 4px;"><?= $progressPercent ?>%</div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;"><?= $scoredEntriesCount ?> / <?= $totalEntriesCount ?> Entries Scored</div>
            </div>

            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 12px;">
                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Total Programs</div>
                <div style="font-size: 26px; font-weight: 800; color: #60a5fa; margin-top: 4px;"><?= $totalProgramsCount ?></div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;"><?= count($programsData) ?> Active in View</div>
            </div>

            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 12px;">
                <div style="font-size: 12.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Registered Entries</div>
                <div style="font-size: 26px; font-weight: 800; color: #a78bfa; margin-top: 4px;"><?= $totalEntriesCount ?></div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">Across <?= count($teams) ?> Teams</div>
            </div>

            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 12px;">
                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Students Tracked</div>
                <div style="font-size: 26px; font-weight: 800; color: #f43f5e; margin-top: 4px;"><?= count($studentMarksList) ?></div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">Individual Marks Recorded</div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div style="background: rgba(255,255,255,0.05); border-radius: 9999px; height: 10px; overflow: hidden; width: 100%;">
            <div style="background: linear-gradient(90deg, #10b981, #34d399); height: 100%; width: <?= $progressPercent ?>%; transition: width 0.5s ease;"></div>
        </div>
    </div>

    <!-- FILTER CONTROL BAR -->
    <div class="panel mb-6" style="padding: 18px; background: rgba(15,23,42,0.7); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px;">
        <form method="GET" class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); align-items: flex-end;">
            <input type="hidden" name="tab" value="<?= e($currentTab) ?>">

            <div class="input-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 12.5px; color: #cbd5e1; margin-bottom: 4px;">Schedule Session</label>
                <select name="session_id" class="form-select" onchange="this.form.submit()" style="height: 38px; font-size: 13px;">
                    <option value="">-- All Schedule Sessions --</option>
                    <?php foreach ($scheduleSessions as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $sessionFilter === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 12.5px; color: #cbd5e1; margin-bottom: 4px;">Team</label>
                <select name="team_id" class="form-select" onchange="this.form.submit()" style="height: 38px; font-size: 13px;">
                    <option value="">-- All Teams --</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $teamFilter === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= e($t['team_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 12.5px; color: #cbd5e1; margin-bottom: 4px;">Program</label>
                <select name="program_id" class="form-select" onchange="this.form.submit()" style="height: 38px; font-size: 13px;">
                    <option value="">-- All Programs --</option>
                    <?php foreach ($allPrograms as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $programFilter === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 12.5px; color: #cbd5e1; margin-bottom: 4px;">Search Student / Chest #</label>
                <input type="text" name="search" class="form-input" value="<?= e($search) ?>" placeholder="Student, Chest #, Entry..." style="height: 38px; font-size: 13px;">
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px; font-weight: 700;">
                    <i class="fa-solid fa-magnifying-glass mr-1"></i> Filter
                </button>
                <?php if ($sessionFilter !== null || $teamFilter !== null || $programFilter !== null || $search !== ''): ?>
                    <a href="<?= app_url('/admin/event-manager/progress.php?tab=' . e($currentTab)) ?>" class="btn btn-secondary btn-sm" style="height: 38px;">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- WORKSPACE TABS -->
    <div style="display: flex; gap: 10px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 24px; padding-bottom: 12px; flex-wrap: wrap;">
        <a href="<?= app_url('/admin/event-manager/progress.php?tab=program&' . http_build_query(array_diff_key($_GET, ['tab' => 1]))) ?>" class="btn <?= $currentTab === 'program' ? 'btn-primary' : 'btn-secondary' ?> btn-md" style="font-weight: 700;">
            <i class="fa-solid fa-list-check mr-1"></i> By Program Marks
        </a>
        <a href="<?= app_url('/admin/event-manager/progress.php?tab=students&' . http_build_query(array_diff_key($_GET, ['tab' => 1]))) ?>" class="btn <?= $currentTab === 'students' ? 'btn-primary' : 'btn-secondary' ?> btn-md" style="font-weight: 700;">
            <i class="fa-solid fa-user-graduate mr-1"></i> Individual Student Marks List (<?= count($studentMarksList) ?>)
        </a>
        <a href="<?= app_url('/admin/event-manager/progress.php?tab=teams&' . http_build_query(array_diff_key($_GET, ['tab' => 1]))) ?>" class="btn <?= $currentTab === 'teams' ? 'btn-primary' : 'btn-secondary' ?> btn-md" style="font-weight: 700;">
            <i class="fa-solid fa-people-group mr-1"></i> By Team Breakdown
        </a>
    </div>

    <!-- TAB 1: BY PROGRAM MARKS & STATUS -->
    <?php if ($currentTab === 'program'): ?>
        <?php if (empty($programsData)): ?>
            <div class="empty-state" style="padding: 40px; text-align: center;">
                <i class="fa-solid fa-filter-circle-xmark" style="font-size: 36px; color: var(--muted);"></i>
                <div style="font-weight: 700; color: #fff; margin-top: 12px;">No Programs Match Selected Filters</div>
                <div style="font-size: 13px; color: var(--muted);">Try resetting your session or team filters above.</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 24px;">
                <?php foreach ($programsData as $pId => $pData): ?>
                    <?php
                        $pScored = $pData['scored_count'];
                        $pTotal = $pData['total_count'];
                        $pPct = $pTotal > 0 ? round(($pScored / $pTotal) * 100) : 0;
                    ?>
                    <div class="panel" style="padding: 22px; background: rgba(15,23,42,0.65); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px;">
                        <div class="flex-between mb-4" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3 style="margin: 0; color: #fff; font-size: 17px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-trophy" style="color: #34d399;"></i>
                                    <?= e($pData['title']) ?>
                                    <span class="badge badge-neutral" style="font-size: 11px; text-transform: uppercase;"><?= e($pData['type']) ?></span>
                                </h3>
                                <div class="muted" style="font-size: 12.5px; margin-top: 3px;">
                                    Session: <strong style="color: #60a5fa;"><?= e($pData['session_name']) ?></strong> · <?= $pScored ?> of <?= $pTotal ?> Entries Scored (<?= $pPct ?>%)
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <a href="<?= app_url('/admin/score-entry/program-scores.php?program_id=' . $pId) ?>" class="btn btn-secondary btn-sm" style="font-weight: 700;">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Open Score Sheet
                                </a>
                            </div>
                        </div>

                        <!-- Program Progress Bar -->
                        <div class="mb-4" style="background: rgba(255,255,255,0.05); border-radius: 9999px; height: 6px; overflow: hidden;">
                            <div style="background: <?= $pPct === 100 ? '#10b981' : '#60a5fa' ?>; height: 100%; width: <?= $pPct ?>%;"></div>
                        </div>

                        <!-- Participants Marks Table -->
                        <div class="table-wrapper">
                            <table class="table table-glass">
                                <thead>
                                    <tr>
                                        <th style="width: 70px; text-align: center;">Perf #</th>
                                        <th style="width: 90px;">Chest #</th>
                                        <th>Participant / Entry Name</th>
                                        <th>Team</th>
                                        <th>Judge 1</th>
                                        <th>Judge 2</th>
                                        <th>Combined Final Score</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pData['entries'] as $idx => $entry): ?>
                                        <tr>
                                            <td style="text-align: center;"><strong style="color: #60a5fa;">#<?= $idx + 1 ?></strong></td>
                                            <td><span class="badge badge-neutral">#<?= e($entry['entry_number']) ?></span></td>
                                            <td>
                                                <strong style="color: #fff; font-size: 14px;"><?= e($entry['entry_name']) ?></strong>
                                                <?php if (!empty($entry['members'])): ?>
                                                    <div style="font-size: 11.5px; color: #94a3b8; margin-top: 2px;">
                                                        <i class="fa-solid fa-users mr-1"></i>
                                                        <?= e(implode(', ', array_column($entry['members'], 'full_name'))) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge" style="background: rgba(255,255,255,0.06); color: #fff; border: 1px solid rgba(255,255,255,0.15);">
                                                    <span class="team-color-dot" style="background: <?= e($entry['team_color'] ?: '#6366f1') ?>; margin-right: 6px; width: 8px; height: 8px;"></span>
                                                    <?= e($entry['team_name']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= $entry['has_score'] ? number_format((float)$entry['judge1_total'], 2) : '<span class="muted">-</span>' ?>
                                            </td>
                                            <td>
                                                <?= $entry['has_score'] ? number_format((float)$entry['judge2_total'], 2) : '<span class="muted">-</span>' ?>
                                            </td>
                                            <td>
                                                <?php if ($entry['has_score']): ?>
                                                    <strong style="color: #34d399; font-size: 15px;"><?= number_format((float)$entry['final_total'], 2) ?></strong>
                                                <?php else: ?>
                                                    <span class="badge badge-neutral">Not Scored</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $entry['has_score'] ? 'badge-success' : 'badge-warning' ?>">
                                                    <?= $entry['has_score'] ? 'Scored' : 'Pending' ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <!-- TAB 2: INDIVIDUAL STUDENT MARKS LIST -->
    <?php elseif ($currentTab === 'students'): ?>
        <div class="panel" style="padding: 22px; background: rgba(15,23,42,0.65); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px;">
            <div class="flex-between mb-4" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px;">
                <h3 style="margin: 0; color: #fff; font-size: 17px; font-weight: 800;">
                    <i class="fa-solid fa-user-graduate mr-2" style="color: #34d399;"></i>
                    Individual Student Marks Master List (<?= count($studentMarksList) ?>)
                </h3>
            </div>

            <?php if (empty($studentMarksList)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center; color: var(--muted);">
                    No student marks found for selected filters.
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table table-glass">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Chest #</th>
                                <th>Student Name</th>
                                <th>Team</th>
                                <th>Program Title</th>
                                <th>Type</th>
                                <th>Judge 1 Total</th>
                                <th>Judge 2 Total</th>
                                <th style="text-align: right;">Final Total Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentMarksList as $sm): ?>
                                <tr>
                                    <td><strong style="color: #fff;">#<?= e($sm['chest_number']) ?></strong></td>
                                    <td><strong style="color: #60a5fa; font-size: 14px;"><?= e($sm['student_name']) ?></strong></td>
                                    <td>
                                        <span class="badge" style="background: rgba(255,255,255,0.06); color: #fff; border: 1px solid rgba(255,255,255,0.15);">
                                            <span class="team-color-dot" style="background: <?= e($sm['team_color'] ?: '#6366f1') ?>; margin-right: 6px; width: 8px; height: 8px;"></span>
                                            <?= e($sm['team_name']) ?>
                                        </span>
                                    </td>
                                    <td><?= e($sm['program_title']) ?></td>
                                    <td><span class="badge badge-neutral" style="font-size: 11px; text-transform: uppercase;"><?= e($sm['program_type']) ?></span></td>
                                    <td><?= $sm['has_score'] ? number_format((float)$sm['judge1_total'], 2) : '<span class="muted">-</span>' ?></td>
                                    <td><?= $sm['has_score'] ? number_format((float)$sm['judge2_total'], 2) : '<span class="muted">-</span>' ?></td>
                                    <td style="text-align: right;">
                                        <?php if ($sm['has_score']): ?>
                                            <strong style="color: #34d399; font-size: 15px;"><?= number_format((float)$sm['final_total'], 2) ?></strong>
                                        <?php else: ?>
                                            <span class="badge badge-neutral">Not Scored</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <!-- TAB 3: BY TEAM BREAKDOWN -->
    <?php elseif ($currentTab === 'teams'): ?>
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <?php foreach ($teamGroupedData as $tId => $tData): ?>
                <div class="panel" style="padding: 22px; background: rgba(15,23,42,0.65); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; border-left: 5px solid <?= e($tData['team_color']) ?>;">
                    <div class="flex-between mb-4" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 style="margin: 0; color: #fff; font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                                <span class="team-color-dot" style="background: <?= e($tData['team_color']) ?>; width: 14px; height: 14px; border-radius: 50%;"></span>
                                <?= e($tData['team_name']) ?>
                            </h3>
                            <div class="muted" style="font-size: 12.5px; margin-top: 3px;">
                                <?= $tData['scored_count'] ?> of <?= $tData['total_count'] ?> Entries Scored
                            </div>
                        </div>
                        <span class="badge badge-neutral" style="font-weight: 700; font-size: 12px;">
                            <?= count($tData['entries']) ?> Program Entries
                        </span>
                    </div>

                    <div class="table-wrapper">
                        <table class="table table-glass">
                            <thead>
                                <tr>
                                    <th>Chest #</th>
                                    <th>Participant / Entry Name</th>
                                    <th>Program Title</th>
                                    <th>Type</th>
                                    <th>Judge 1 Total</th>
                                    <th>Judge 2 Total</th>
                                    <th style="text-align: right;">Final Total Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tData['entries'] as $entry): ?>
                                    <tr>
                                        <td><span class="badge badge-neutral">#<?= e($entry['entry_number']) ?></span></td>
                                        <td>
                                            <strong style="color: #fff; font-size: 14px;"><?= e($entry['entry_name']) ?></strong>
                                            <?php if (!empty($entry['members'])): ?>
                                                <div style="font-size: 11.5px; color: #94a3b8; margin-top: 2px;">
                                                    <i class="fa-solid fa-users mr-1"></i>
                                                    <?= e(implode(', ', array_column($entry['members'], 'full_name'))) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($entry['program_title']) ?></td>
                                        <td><span class="badge badge-neutral" style="font-size: 11px; text-transform: uppercase;"><?= e($entry['program_type']) ?></span></td>
                                        <td><?= $entry['has_score'] ? number_format((float)$entry['judge1_total'], 2) : '<span class="muted">-</span>' ?></td>
                                        <td><?= $entry['has_score'] ? number_format((float)$entry['judge2_total'], 2) : '<span class="muted">-</span>' ?></td>
                                        <td style="text-align: right;">
                                            <?php if ($entry['has_score']): ?>
                                                <strong style="color: #34d399; font-size: 15px;"><?= number_format((float)$entry['final_total'], 2) ?></strong>
                                            <?php else: ?>
                                                <span class="badge badge-neutral">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
