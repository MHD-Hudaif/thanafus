<?php
$pageTitle = 'Score Approval';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();
require_roles(['admin', 'score-approver']);

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

function approval_redirect(array $query = []): void
{
    admin_redirect('/admin/score-update/score-approval.php', $query);
}

function approval_badge(?string $status): string
{
    return match ((string)$status) {
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'submitted' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function approval_can_approve(?string $status): bool
{
    return admin_program_approvable($status);
}

/**
 * Fetch schedule sessions with program counts and submission statistics
 */
function admin_get_sessions_approval_stats(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("
        SELECT 
            mss.id AS session_id,
            mss.name AS session_name,
            mss.section_date,
            mss.start_time,
            mss.end_time,
            mss.sort_order,
            COUNT(p.id) AS total_programs,
            SUM(CASE WHEN p.approval_status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN p.approval_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN p.approval_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN p.approval_status IS NULL OR p.approval_status NOT IN ('submitted', 'approved', 'rejected') THEN 1 ELSE 0 END) AS pending_count
        FROM musabaqa_schedule_sections mss
        JOIN musabaqa_programs p ON p.section_id = mss.id AND p.event_id = mss.event_id
        WHERE mss.event_id = ?
        GROUP BY mss.id, mss.name, mss.section_date, mss.start_time, mss.end_time, mss.sort_order
        HAVING COUNT(p.id) > 0
        ORDER BY mss.section_date ASC, mss.start_time ASC, mss.sort_order ASC
    ");
    $stmt->execute([$eventId]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch unscheduled / off-stage programs stats
    $stmtUnscheduled = $pdo->prepare("
        SELECT 
            0 AS session_id,
            'Unscheduled / Off-Stage' AS session_name,
            NULL AS section_date,
            NULL AS start_time,
            NULL AS end_time,
            99999 AS sort_order,
            COUNT(p.id) AS total_programs,
            SUM(CASE WHEN p.approval_status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN p.approval_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN p.approval_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN p.approval_status IS NULL OR p.approval_status NOT IN ('submitted', 'approved', 'rejected') THEN 1 ELSE 0 END) AS pending_count
        FROM musabaqa_programs p
        WHERE p.event_id = ? AND (p.section_id IS NULL OR p.section_id = 0)
    ");
    $stmtUnscheduled->execute([$eventId]);
    $unscheduled = $stmtUnscheduled->fetch(PDO::FETCH_ASSOC);

    if ($unscheduled && (int)$unscheduled['total_programs'] > 0) {
        $sessions[] = $unscheduled;
    }

    return $sessions;
}

// ----------------------------------------------------
// AJAX / POST ENDPOINT HANDLERS
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax']) || isset($_GET['action'])) {
    $action = (string)($_REQUEST['action'] ?? $_POST['approval_action'] ?? '');
    
    // AJAX: Fetch Programs inside a Schedule Session for Modal View
    if ($action === 'fetch_session_programs') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
            
            // Get session info
            $sessionInfo = null;
            if ($sessionId > 0) {
                $sStmt = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE id = ? AND event_id = ? LIMIT 1");
                $sStmt->execute([$sessionId, $activeEventId]);
                $sessionInfo = $sStmt->fetch(PDO::FETCH_ASSOC);
            }

            // Fetch programs for session
            if ($sessionId > 0) {
                $pStmt = $pdo->prepare("
                    SELECT 
                        p.*, 
                        ct.name AS class_type_name, 
                        submitter.full_name AS submitted_name, 
                        submitter.username AS submitted_username, 
                        COUNT(DISTINCT pe.id) AS entry_count
                    FROM musabaqa_programs p
                    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
                    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
                    LEFT JOIN " . DB_MAIN_NAME . ".users submitter ON submitter.id = p.submitted_by
                    WHERE p.event_id = ? AND p.section_id = ?
                    GROUP BY p.id, ct.id, submitter.id
                    ORDER BY p.title ASC
                ");
                $pStmt->execute([$activeEventId, $sessionId]);
            } else {
                $pStmt = $pdo->prepare("
                    SELECT 
                        p.*, 
                        ct.name AS class_type_name, 
                        submitter.full_name AS submitted_name, 
                        submitter.username AS submitted_username, 
                        COUNT(DISTINCT pe.id) AS entry_count
                    FROM musabaqa_programs p
                    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
                    LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
                    LEFT JOIN " . DB_MAIN_NAME . ".users submitter ON submitter.id = p.submitted_by
                    WHERE p.event_id = ? AND (p.section_id IS NULL OR p.section_id = 0)
                    GROUP BY p.id, ct.id, submitter.id
                    ORDER BY p.title ASC
                ");
                $pStmt->execute([$activeEventId]);
            }
            $programs = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute statistics for this session
            $totalPrograms = count($programs);
            $submittedCount = 0;
            $approvedCount = 0;
            $rejectedCount = 0;
            $pendingCount = 0;

            foreach ($programs as $p) {
                $st = (string)($p['approval_status'] ?? '');
                if ($st === 'submitted') $submittedCount++;
                elseif ($st === 'approved') $approvedCount++;
                elseif ($st === 'rejected') $rejectedCount++;
                else $pendingCount++;
            }

            $canBulkApprove = ($totalPrograms > 0 && $pendingCount == 0 && $submittedCount > 0);

            ob_start();
            if (!$programs): ?>
                <div class="empty-state" style="padding: 40px 20px;">
                    <div class="empty-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div class="empty-title">No Programs in Session</div>
                    <div class="empty-subtitle">There are no programs assigned to this schedule session.</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table approval-table">
                        <thead>
                            <tr>
                                <th>Program Title</th>
                                <th>Class</th>
                                <th>Entries</th>
                                <th>Submitted By</th>
                                <th>Submitted Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programs as $p): ?>
                                <tr id="prog-row-<?= (int)$p['id'] ?>">
                                    <td><strong><?= e($p['title']) ?></strong></td>
                                    <td>
                                        <?php $classTier = admin_class_type_tier_from_name($p['class_type_name'] ?? ''); ?>
                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>">
                                            <?= e(admin_class_type_display($p['class_type_name'] ?? null, (int)($p['class_type_id'] ?? 0))) ?>
                                        </span>
                                    </td>
                                    <td><?= (int)$p['entry_count'] ?> Entries</td>
                                    <td><?= e($p['submitted_name'] ?: $p['submitted_username'] ?: '-') ?></td>
                                    <td><?= $p['submitted_at'] ? e(date('d M h:i A', strtotime($p['submitted_at']))) : '-' ?></td>
                                    <td id="prog-status-td-<?= (int)$p['id'] ?>">
                                        <span class="badge <?= approval_badge($p['approval_status']) ?>">
                                            <?= e(ucfirst((string)($p['approval_status'] ?: 'Pending'))) ?>
                                        </span>
                                    </td>
                                    <td id="prog-actions-td-<?= (int)$p['id'] ?>">
                                        <div class="flex gap-2 flex-wrap">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleProgramPreviewInModal(<?= (int)$p['id'] ?>)">
                                                <i class="fa-solid fa-eye"></i> View
                                            </button>
                                            <?php if ($p['approval_status'] === 'submitted' || $p['approval_status'] === 'rejected'): ?>
                                                <button type="button" class="btn btn-success btn-sm" onclick="approveProgramInModal(<?= (int)$p['id'] ?>)">
                                                    <i class="fa-solid fa-check"></i> Approve
                                                </button>
                                                <?php if ($p['approval_status'] === 'submitted'): ?>
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="promptRejectProgram(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['title'])) ?>')">
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                            <?php elseif ($p['approval_status'] === 'approved'): ?>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="promptRevokeProgram(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['title'])) ?>')">
                                                    <i class="fa-solid fa-rotate-left"></i> Undo
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr id="prog-preview-tr-<?= (int)$p['id'] ?>" class="hidden">
                                    <td colspan="7" style="padding: 12px; background: rgba(0, 0, 0, 0.35);">
                                        <div id="prog-preview-content-<?= (int)$p['id'] ?>" class="text-sm text-muted">
                                            Loading ranking preview...
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif;
            $html = ob_get_clean();

            echo json_encode([
                'success' => true,
                'session_id' => $sessionId,
                'session_name' => $sessionInfo ? $sessionInfo['name'] : 'Unscheduled / Off-Stage',
                'session_meta' => $sessionInfo && $sessionInfo['section_date'] ? date('D, d M Y', strtotime($sessionInfo['section_date'])) . ($sessionInfo['start_time'] ? ' • ' . date('h:i A', strtotime($sessionInfo['start_time'])) . ' - ' . date('h:i A', strtotime($sessionInfo['end_time'])) : '') : 'Off-Stage Programs',
                'html' => $html,
                'total_programs' => $totalPrograms,
                'submitted_count' => $submittedCount,
                'approved_count' => $approvedCount,
                'rejected_count' => $rejectedCount,
                'pending_count' => $pendingCount,
                'can_bulk_approve' => $canBulkApprove,
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // AJAX: Fetch Program Ranking Preview
    if ($action === 'fetch_program_preview') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT p.*, ct.name AS class_type_name, submitter.full_name AS submitted_name, submitter.username AS submitted_username
                FROM musabaqa_programs p
                LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
                LEFT JOIN " . DB_MAIN_NAME . ".users submitter ON submitter.id = p.submitted_by
                WHERE p.id = ? AND p.event_id = ?
                LIMIT 1
            ");
            $stmt->execute([$programId, $activeEventId]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$program) {
                throw new RuntimeException('Program not found.');
            }

            $settings = admin_get_settings($pdo);
            $firstPoints = (int)($settings['first_place_points'] ?? 10);
            $secondPoints = (int)($settings['second_place_points'] ?? 7);
            $thirdPoints = (int)($settings['third_place_points'] ?? 5);

            $teamPointsConfig = null;
            if (!empty($program['team_points_config'])) {
                $teamPointsConfig = json_decode($program['team_points_config'], true);
            }
            $pointConfig = [];
            if (is_array($teamPointsConfig)) {
                foreach ($teamPointsConfig as $r => $pts) {
                    $pointConfig[(int)$r] = (int)$pts;
                }
            } else {
                $pointConfig[1] = $firstPoints;
                $pointConfig[2] = $secondPoints;
                $pointConfig[3] = $thirdPoints;
            }

            $isDisableScores = !empty($program['disable_scores']);
            $judgesCount = max(1, (int)($program['judges_count'] ?? 2));
            $isMarkBased = empty($program['only_team_marks']);

            if ($isDisableScores) {
                $stmt = $pdo->prepare("
                    SELECT
                        pe.id, pe.entry_number, pe.entry_name, pe.final_rank, t.team_name, t.team_color
                    FROM musabaqa_program_entries pe
                    JOIN musabaqa_teams t ON t.id = pe.team_id
                    WHERE pe.event_id = ? AND pe.program_id = ?
                    ORDER BY (CASE WHEN pe.final_rank IS NULL OR pe.final_rank = 0 THEN 9999 ELSE pe.final_rank END) ASC, pe.entry_number ASC, pe.id ASC
                ");
                $stmt->execute([$activeEventId, $programId]);
                $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $processedEntries = [];
                $teamTotals = [];
                foreach ($entries as $entry) {
                    $r = (int)($entry['final_rank'] ?? 0);
                    $teamPoints = ($r > 0 && isset($pointConfig[$r])) ? $pointConfig[$r] : 0;
                    $entry['rank'] = $r;
                    $entry['team_points'] = $teamPoints;
                    $processedEntries[] = $entry;
                    $team = (string)$entry['team_name'];
                    $teamTotals[$team] = [
                        'total' => ($teamTotals[$team]['total'] ?? 0) + $teamPoints,
                        'color' => $entry['team_color'] ?: '#64748b',
                    ];
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT
                        pe.id, pe.entry_number, pe.entry_name, t.team_name, t.team_color, ss.judge1_total, ss.judge2_total, ss.final_total
                    FROM musabaqa_program_entries pe
                    JOIN musabaqa_teams t ON t.id = pe.team_id
                    JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
                    WHERE pe.event_id = ? AND pe.program_id = ?
                    ORDER BY ss.final_total DESC, pe.entry_number ASC, pe.id ASC
                ");
                $stmt->execute([$activeEventId, $programId]);
                $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $scoreGroups = [];
                foreach ($entries as $entry) {
                    $scoreKey = (string)(float)$entry['final_total'];
                    $scoreGroups[$scoreKey][] = $entry;
                }
                $groupCounts = array_map('count', array_values($scoreGroups));

                $position = 1;
                $idx = 0;
                $processedEntries = [];
                $teamTotals = [];

                foreach ($scoreGroups as $scoreStr => $groupEntries) {
                    $count = count($groupEntries);
                    $rank = $position;
                    $teamPoints = 0;
                    if ($idx === 0) {
                        $teamPoints = $pointConfig[1] ?? 0;
                    } elseif ($idx === 1) {
                        $c1 = $groupCounts[0] ?? 0;
                        // A shared first consumes both first and second place.
                        // The following score group is rank 3, so it receives
                        // the third-place points rather than second-place points.
                        if ($c1 === 1) {
                            $teamPoints = $pointConfig[2] ?? 0;
                        } elseif ($c1 === 2) {
                            $teamPoints = $pointConfig[3] ?? 0;
                        }
                    } elseif ($idx === 2) {
                        $c1 = $groupCounts[0] ?? 0;
                        $c2 = $groupCounts[1] ?? 0;
                        $teamPoints = ($c1 === 1 && $c2 === 1) ? ($pointConfig[3] ?? 0) : 0;
                    }

                    foreach ($groupEntries as $entry) {
                        $gradeInfo = admin_calculate_grade_info((float)$entry['final_total'], $judgesCount, $settings);
                        $gradeBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                        if ($rank >= 1 && $rank <= 3) {
                            $gradeBonus = 0;
                        }
                        $finalRank = $rank;
                        if (!isset($pointConfig[3]) && $finalRank >= 3) {
                            $finalRank = null;
                        }
                        $entry['rank'] = $finalRank;
                        $entry['grade'] = $gradeInfo['grade'];
                        $entry['grade_bonus'] = $gradeBonus;
                        $entry['team_points'] = $teamPoints + $gradeBonus;
                        $processedEntries[] = $entry;
                        $team = (string)$entry['team_name'];
                        $teamTotals[$team] = [
                            'total' => ($teamTotals[$team]['total'] ?? 0) + $entry['team_points'],
                            'color' => $entry['team_color'] ?: '#64748b',
                        ];
                    }
                    $position += $count;
                    $idx++;
                }
            }

            ob_start(); ?>
            <div class="panel" style="background: rgba(15, 22, 34, 0.95); padding: 16px;">
                <div class="flex-between mb-4">
                    <div>
                        <strong style="font-size: 15px; display: block; color: var(--text);">Score Ranking Breakdown</strong>
                        <span style="font-size: 12px; color: var(--muted);">Program: <?= e($program['title']) ?></span>
                    </div>
                    <span class="badge <?= approval_badge($program['approval_status']) ?>"><?= e(ucfirst((string)$program['approval_status'])) ?></span>
                </div>

                <div class="table-wrapper mb-4">
                    <table class="table" style="font-size: 13px;">
                        <thead>
                            <?php if ($isDisableScores): ?>
                                <tr><th>Entry Name</th><th>Team</th><th style="text-align: center;">Placement Rank</th><th style="text-align: center;">Team Pts</th></tr>
                            <?php else: ?>
                                <tr>
                                    <th>Entry Name</th>
                                    <th>Team</th>
                                    <th><?= $judgesCount > 1 ? 'Judge 1 Total' : 'Judge Total' ?></th>
                                    <?php if ($judgesCount > 1): ?>
                                        <th>Judge 2 Total</th>
                                    <?php endif; ?>
                                    <th>Final Total</th>
                                    <th>Grade</th>
                                    <th>Grade A Bonus</th>
                                    <th>Rank</th>
                                    <th>Team Pts</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($processedEntries as $e): ?>
                                <tr>
                                    <td>#<?= e(str_pad((string)$e['entry_number'], 3, '0', STR_PAD_LEFT)) ?> <?= e($e['entry_name'] ?: 'Unnamed Entry') ?></td>
                                    <td><span class="team-color-pill" style="background: <?= e($e['team_color'] ?? '#64748b') ?>22;"><?= e($e['team_name']) ?></span></td>
                                    <?php if ($isDisableScores): ?>
                                        <td style="text-align: center; font-weight: 700; color: #facc15;">
                                            <?php
                                                $r = (int)($e['rank'] ?? 0);
                                                echo match ($r) {
                                                    1 => '1st Place 🥇',
                                                    2 => '2nd Place 🥈',
                                                    3 => '3rd Place 🥉',
                                                    default => ($r > 0 ? $r . 'th Place' : '—')
                                                };
                                            ?>
                                        </td>
                                        <td style="text-align: center;"><strong><?= (int)$e['team_points'] ?></strong></td>
                                    <?php else: ?>
                                        <td><?= number_format((float)$e['judge1_total'], 2) ?></td>
                                        <?php if ($judgesCount > 1): ?>
                                            <td><?= number_format((float)$e['judge2_total'], 2) ?></td>
                                        <?php endif; ?>
                                        <td><strong><?= number_format((float)$e['final_total'], 2) ?></strong></td>
                                        <td><span class="badge badge-<?= ($e['grade'] ?? '') === 'A' ? 'success' : 'neutral' ?>">Grade <?= e($e['grade'] ?? '—') ?></span></td>
                                        <td style="text-align: center; color: #34d399; font-weight: 800;">+<?= number_format((float)($e['grade_bonus'] ?? 0), 0) ?></td>
                                        <td><strong><?= $e['rank'] ? (int)$e['rank'] : '—' ?></strong></td>
                                        <td><strong><?= (int)$e['team_points'] ?></strong></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <strong style="font-size: 13.5px; display: block; color: var(--muted); margin-bottom: 8px;">Team Points Awarded</strong>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php foreach ($teamTotals as $tName => $tData): ?>
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;">
                            <span style="color: <?= e($tData['color']) ?>;">● <?= e($tName) ?></span>: <?= (int)$tData['total'] ?> Pts
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            $html = ob_get_clean();
            echo json_encode(['success' => true, 'html' => $html]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // AJAX / POST: Approve Program
    if ($action === 'approve_program' || $action === 'approve') {
        $isAjax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
        $programId = (int)($_REQUEST['program_id'] ?? 0);
        try {
            admin_db_transaction($pdo, function ($pdo) use ($programId, $activeEventId, $currentUserId) {
                admin_approve_program_scores($pdo, $activeEventId, $programId, $currentUserId);
            });
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'Program approved.', 'program_id' => $programId, 'status' => 'approved']);
                exit;
            }
            admin_flash('success', 'Program scores approved.');
        } catch (Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            admin_flash('error', $e->getMessage());
        }
        approval_redirect();
    }

    // AJAX / POST: Reject Program
    if ($action === 'reject_program' || $action === 'reject') {
        $isAjax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
        $programId = (int)($_REQUEST['program_id'] ?? 0);
        $notes = trim((string)($_REQUEST['rejection_notes'] ?? ''));
        try {
            admin_db_transaction($pdo, function ($pdo) use ($programId, $activeEventId, $currentUserId, $notes) {
                admin_reject_program_scores($pdo, $activeEventId, $programId, $currentUserId, $notes);
            });
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'Program rejected.', 'program_id' => $programId, 'status' => 'rejected']);
                exit;
            }
            admin_flash('success', 'Program scores rejected.');
        } catch (Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            admin_flash('error', $e->getMessage());
        }
        approval_redirect();
    }

    // AJAX / POST: Revoke Program Approval (Undo)
    if ($action === 'revoke_program' || $action === 'revoke_approved') {
        $isAjax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
        $programId = (int)($_REQUEST['program_id'] ?? 0);
        $notes = trim((string)($_REQUEST['rejection_notes'] ?? ''));
        try {
            admin_db_transaction($pdo, function ($pdo) use ($programId, $activeEventId, $currentUserId, $notes) {
                admin_revoke_program_approval($pdo, $activeEventId, $programId, $currentUserId, $notes);
            });
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'Program approval revoked.', 'program_id' => $programId, 'status' => 'submitted']);
                exit;
            }
            admin_flash('success', 'Approval revoked.');
        } catch (Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            admin_flash('error', $e->getMessage());
        }
        approval_redirect();
    }

    // AJAX / POST: Bulk Approve Session Programs
    if ($action === 'approve_session') {
        $isAjax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
        $sessionId = (int)($_REQUEST['session_id'] ?? 0);
        try {
            admin_db_transaction($pdo, function ($pdo) use ($sessionId, $activeEventId, $currentUserId) {
                if ($sessionId > 0) {
                    $stmt = $pdo->prepare("SELECT id FROM musabaqa_programs WHERE event_id = ? AND section_id = ? AND approval_status IN ('submitted', 'rejected')");
                    $stmt->execute([$activeEventId, $sessionId]);
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM musabaqa_programs WHERE event_id = ? AND (section_id IS NULL OR section_id = 0) AND approval_status IN ('submitted', 'rejected')");
                    $stmt->execute([$activeEventId]);
                }
                $programIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
                if (!$programIds) {
                    throw new RuntimeException('No programs ready for approval in this session.');
                }
                foreach ($programIds as $pid) {
                    admin_approve_program_scores($pdo, $activeEventId, $pid, $currentUserId, true);
                }
                foreach ($programIds as $pid) {
                    admin_recalculate_participant_totals($pdo, $activeEventId, $pid);
                    admin_recalculate_program_results($pdo, $activeEventId, $pid);
                }
                admin_recalculate_team_totals($pdo, $activeEventId);
                admin_trigger_live_score_reveal($pdo, $activeEventId, $programIds);
            });
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'Session programs approved successfully.', 'session_id' => $sessionId]);
                exit;
            }
            admin_flash('success', 'Session programs approved successfully.');
        } catch (Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            admin_flash('error', $e->getMessage());
        }
        approval_redirect();
    }
}

// ----------------------------------------------------
// MAIN PAGE VIEW DATA FETCHING
// ----------------------------------------------------
$flash = admin_take_flash();
$sessions = admin_get_sessions_approval_stats($pdo, $activeEventId);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
@keyframes pulse-submitted {
    0% {
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6);
        transform: scale(1);
    }
    70% {
        box-shadow: 0 0 0 8px rgba(245, 158, 11, 0);
        transform: scale(1.04);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0);
        transform: scale(1);
    }
}
.btn-submitted-pulse {
    background: #f59e0b !important;
    color: #0f172a !important;
    border: 1px solid #d97706 !important;
    font-weight: 700 !important;
    animation: pulse-submitted 2s infinite ease-in-out;
}
.btn-submitted-pulse:hover {
    background: #fbbf24 !important;
    transform: scale(1.05);
}
</style>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">Score Approval</div>
            <div class="page-subtitle">Select a schedule session to review and approve program scores</div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- SCHEDULE SESSIONS TABLE LIST -->
    <?php if (!$sessions): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-clipboard-check"></i></div><div class="empty-title">No Active Sessions Found</div><div class="empty-subtitle">There are no schedule sessions with active program entries.</div></div>
    <?php else: ?>
        <div class="table-wrapper mb-6 approval-table-wrap">
            <table class="table approval-table">
                <thead>
                    <tr>
                        <th>Schedule Session</th>
                        <th>Date & Timing</th>
                        <th>Programs Breakdown</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php 
                        $tot = (int)$session['total_programs'];
                        $app = (int)$session['approved_count'];
                        $sub = (int)$session['submitted_count'];
                        $rej = (int)$session['rejected_count'];
                        $pen = (int)$session['pending_count'];
                        
                        // STRICT BULK APPROVAL RULE:
                        // "the button is disabled until all the programs are send for approval"
                        $canBulkApprove = ($tot > 0 && $pen === 0 && $sub > 0);
                        ?>
                        <tr id="session-row-<?= (int)$session['session_id'] ?>">
                            <td>
                                <strong style="font-size: 15px; color: #fff; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-clock-rotate-left" style="color: #38bdf8; font-size: 14px;"></i>
                                    <?= e($session['session_name']) ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($session['section_date']): ?>
                                    <span class="text-sm text-muted">
                                        <i class="fa-regular fa-calendar mr-1"></i> <?= date('D, d M Y', strtotime($session['section_date'])) ?>
                                        <?php if ($session['start_time']): ?>
                                            • <?= date('h:i A', strtotime($session['start_time'])) ?> - <?= date('h:i A', strtotime($session['end_time'])) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-sm text-muted">Off-Stage & Unscheduled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= $tot ?> Total Programs</strong>
                                <div style="font-size: 12px; margin-top: 3px;">
                                    <span style="color: #34d399; font-weight: 700;"><?= $app ?> Approved</span> • 
                                    <span style="color: #fbbf24; font-weight: 700;"><?= $sub ?> Submitted</span> • 
                                    <span style="color: #94a3b8; font-weight: 700;"><?= $pen ?> Pending</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($tot === 0): ?>
                                    <span class="badge badge-neutral">No Programs</span>
                                <?php elseif ($app === $tot): ?>
                                    <span class="badge badge-success" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                                        <i class="fa-solid fa-circle-check mr-1"></i> All Approved
                                    </span>
                                <?php elseif ($canBulkApprove): ?>
                                    <span class="badge badge-success" style="box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);">
                                        <i class="fa-solid fa-list-check mr-1"></i> Ready for Bulk Approval
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning">
                                        <i class="fa-solid fa-hourglass-half mr-1"></i> <?= $pen ?> Pending Submission
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <button type="button" class="btn <?= $sub > 0 ? 'btn-submitted-pulse' : 'btn-secondary' ?> btn-sm" onclick="openSessionModal(<?= (int)$session['session_id'] ?>, '<?= e(addslashes($session['session_name'])) ?>')">
                                        <i class="fa-solid fa-folder-open mr-1"></i> Review Programs
                                    </button>
                                    
                                    <button type="button" class="btn btn-success btn-sm session-card-bulk-btn" 
                                            data-session-card-id="<?= (int)$session['session_id'] ?>"
                                            <?= !$canBulkApprove ? 'disabled' : '' ?>
                                            title="<?= !$canBulkApprove ? ($pen > 0 ? 'Disabled: All programs in this session must be sent for approval first.' : ($tot == 0 ? 'No programs in session' : 'All submitted programs already approved.')) : 'Bulk approve all submitted programs in this session' ?>"
                                            onclick="bulkApproveSession(<?= (int)$session['session_id'] ?>)">
                                        <i class="fa-solid fa-circle-check"></i> Bulk Approve
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

<!-- SESSION PROGRAMS MODAL -->
<div class="modal-overlay" id="sessionModal">
    <div class="modal-box modal-lg" style="max-width: 980px; width: 95%; max-height: calc(100vh - 40px); display: flex; flex-direction: column; overflow: hidden; padding: 20px;">
        <div class="modal-header" style="flex-shrink: 0; margin-bottom: 14px;">
            <div>
                <div class="modal-title" id="sessionModalTitle">Session Programs</div>
                <div class="text-xs text-muted" id="sessionModalMeta">Loading session info...</div>
            </div>
            <button class="modal-close" type="button" data-close="sessionModal"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="flex-between mb-4 p-3" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; flex-shrink: 0; flex-wrap: wrap; gap: 10px;">
            <div id="sessionModalStats" style="font-size: 13px; font-weight: 700; color: var(--muted);"></div>
            <button type="button" class="btn btn-success btn-md" id="sessionModalBulkBtn" disabled onclick="bulkApproveFromModal()">
                <i class="fa-solid fa-circle-check"></i> Approve Entire Session
            </button>
        </div>

        <div style="flex: 1; overflow-y: auto; padding-right: 6px;" id="sessionModalBody">
            <div class="empty-state" style="padding: 40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 28px;"></i><div class="empty-title mt-2">Loading programs...</div></div>
        </div>

        <div class="form-actions" style="flex-shrink: 0; padding-top: 14px; margin-top: 12px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <span class="text-xs text-muted"><i class="fa-solid fa-info-circle mr-1"></i> Actions inside this window execute instantly and will not close the window.</span>
            <button type="button" class="btn btn-secondary btn-md" data-close="sessionModal">Close</button>
        </div>
    </div>
</div>

<!-- REJECTION NOTES MODAL -->
<div class="modal-overlay" id="rejectPromptModal" style="z-index: 100010;">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div class="modal-title">Reject Program Scores</div>
            <button class="modal-close" type="button" data-close="rejectPromptModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="panel mb-4">
            Reject <strong id="rejectPromptProgName">this program</strong>? Score sheets will become editable again.
        </div>
        <div class="input-group full-width mb-4">
            <label>Rejection Notes (Optional)</label>
            <textarea id="rejectPromptNotes" rows="3" placeholder="Provide reason for rejection..."></textarea>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-secondary btn-md" data-close="rejectPromptModal">Cancel</button>
            <button type="button" class="btn btn-danger btn-md" id="rejectPromptConfirmBtn">Reject Program</button>
        </div>
    </div>
</div>

<script>
(() => {
    let currentSessionId = null;
    let pendingRejectProgramId = null;

    // Open Session Modal and load programs via AJAX
    window.openSessionModal = function(sessionId, sessionName) {
        currentSessionId = sessionId;
        document.getElementById('sessionModalTitle').textContent = sessionName || 'Session Programs';
        document.getElementById('sessionModalMeta').textContent = 'Loading programs...';
        document.getElementById('sessionModalStats').textContent = '';
        document.getElementById('sessionModalBody').innerHTML = '<div class="empty-state" style="padding: 40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 28px;"></i><div class="empty-title mt-2">Loading programs...</div></div>';
        
        const bulkBtn = document.getElementById('sessionModalBulkBtn');
        bulkBtn.disabled = true;
        bulkBtn.title = 'Checking session status...';

        window.openModal('sessionModal');
        loadSessionPrograms(sessionId);
    };

    // Load Session Programs JSON/HTML
    function loadSessionPrograms(sessionId) {
        const fetchUrl = `${window.location.pathname}?action=fetch_session_programs&session_id=${sessionId}&ajax=1`;
        fetch(fetchUrl)
            .then(res => {
                if (!res.ok) throw new Error(`HTTP error ${res.status}`);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    document.getElementById('sessionModalBody').innerHTML = `<div class="alert alert-error">${data.message || 'Error loading programs'}</div>`;
                    return;
                }

                document.getElementById('sessionModalTitle').textContent = data.session_name;
                document.getElementById('sessionModalMeta').textContent = data.session_meta;
                document.getElementById('sessionModalBody').innerHTML = data.html;

                document.getElementById('sessionModalStats').innerHTML = `
                    <span style="color: #fff;">${data.total_programs} Total Programs</span>: 
                    <span style="color: #34d399;">${data.approved_count} Approved</span>, 
                    <span style="color: #fbbf24;">${data.submitted_count} Submitted</span>, 
                    <span style="color: #94a3b8;">${data.pending_count} Pending</span>
                `;

                const bulkBtn = document.getElementById('sessionModalBulkBtn');
                if (data.can_bulk_approve) {
                    bulkBtn.disabled = false;
                    bulkBtn.title = 'Approve all submitted programs in this session';
                } else {
                    bulkBtn.disabled = true;
                    bulkBtn.title = data.pending_count > 0 
                        ? 'Disabled: All programs in this session must be sent for approval first.' 
                        : 'No submitted programs to approve.';
                }
            })
            .catch(err => {
                document.getElementById('sessionModalBody').innerHTML = `<div class="alert alert-error">Error loading session programs: ${err.message || 'Network error'}</div>`;
            });
    }

    // Toggle Program Ranking Preview inside Modal
    window.toggleProgramPreviewInModal = function(programId) {
        const previewTr = document.getElementById(`prog-preview-tr-${programId}`);
        const previewContent = document.getElementById(`prog-preview-content-${programId}`);
        if (!previewTr) return;

        if (!previewTr.classList.contains('hidden')) {
            previewTr.classList.add('hidden');
            return;
        }

        previewTr.classList.remove('hidden');
        if (previewContent.getAttribute('data-loaded') === 'true') return;

        previewContent.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading breakdown...';
        const fetchUrl = `${window.location.pathname}?action=fetch_program_preview&program_id=${programId}&ajax=1`;
        fetch(fetchUrl)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    previewContent.innerHTML = data.html;
                    previewContent.setAttribute('data-loaded', 'true');
                } else {
                    previewContent.innerHTML = `<div class="text-danger">${data.message || 'Unable to load preview.'}</div>`;
                }
            })
            .catch(() => {
                previewContent.innerHTML = `<div class="text-danger">Network error loading breakdown.</div>`;
            });
    };

    // Approve Program via AJAX inside Modal (MODAL DOES NOT CLOSE)
    window.approveProgramInModal = function(programId) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const fetchUrl = `${window.location.pathname}?action=approve_program&program_id=${programId}&ajax=1`;
        fetch(fetchUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `csrf_token=${encodeURIComponent(csrf)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Reload session programs list inside modal (Modal remains open!)
                if (currentSessionId !== null) {
                    loadSessionPrograms(currentSessionId);
                }
            } else {
                alert(data.message || 'Error approving program.');
            }
        });
    };

    // Prompt Reject Program
    window.promptRejectProgram = function(programId, progName) {
        pendingRejectProgramId = programId;
        document.getElementById('rejectPromptProgName').textContent = progName;
        document.getElementById('rejectPromptNotes').value = '';
        window.openModal('rejectPromptModal');
    };

    const confirmRejectBtn = document.getElementById('rejectPromptConfirmBtn');
    if (confirmRejectBtn) {
        confirmRejectBtn.addEventListener('click', () => {
            if (!pendingRejectProgramId) return;
            const notes = document.getElementById('rejectPromptNotes').value;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const fetchUrl = `${window.location.pathname}?action=reject_program&program_id=${pendingRejectProgramId}&rejection_notes=${encodeURIComponent(notes)}&ajax=1`;

            fetch(fetchUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `csrf_token=${encodeURIComponent(csrf)}`
            })
            .then(res => res.json())
            .then(data => {
                window.closeModal('rejectPromptModal');
                if (data.success) {
                    if (currentSessionId !== null) {
                        loadSessionPrograms(currentSessionId);
                    }
                } else {
                    alert(data.message || 'Error rejecting program.');
                }
            });
        });
    }

    // Revoke Program Approval via AJAX inside Modal (MODAL DOES NOT CLOSE)
    window.promptRevokeProgram = function(programId, progName) {
        if (!confirm(`Undo approval for ${progName}? The program will return to submitted state.`)) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const fetchUrl = `${window.location.pathname}?action=revoke_program&program_id=${programId}&ajax=1`;

        fetch(fetchUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `csrf_token=${encodeURIComponent(csrf)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (currentSessionId !== null) {
                    loadSessionPrograms(currentSessionId);
                }
            } else {
                alert(data.message || 'Error revoking approval.');
            }
        });
    };

    // Bulk Approve Session from Main Page or Modal
    window.bulkApproveSession = function(sessionId) {
        if (!confirm('Approve all submitted programs in this schedule session?')) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const fetchUrl = `${window.location.pathname}?action=approve_session&session_id=${sessionId}&ajax=1`;

        fetch(fetchUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `csrf_token=${encodeURIComponent(csrf)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Error approving session.');
            }
        });
    };

    window.bulkApproveFromModal = function() {
        if (currentSessionId !== null) {
            window.bulkApproveSession(currentSessionId);
        }
    };

    // Standard Modal Close Handlers
    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => window.closeModal(btn.dataset.close)));
    document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) window.closeModal(modal.id); }));

})();
</script>

<?php admin_close_page(); ?>
