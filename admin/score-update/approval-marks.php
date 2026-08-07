<?php
$pageTitle = 'Approval Marks & Rankings';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();
require_roles(['admin', 'score-approver']);

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Query all approved program results and their ranks
$stmt = $pdo->prepare("
    SELECT 
        p.id AS program_id,
        p.title AS program_title,
        p.only_team_marks,
        ct.name AS class_type_name,
        pe.id AS entry_id,
        pe.final_score,
        pe.final_rank,
        pe.team_score,
        t.team_name,
        t.team_color,
        t.number_prefix AS team_prefix,
        GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ''), s.full_name) ORDER BY s.id ASC SEPARATOR ', ') AS participant_names
    FROM musabaqa_programs p
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
    JOIN musabaqa_program_entries pe ON pe.program_id = p.id
    JOIN musabaqa_teams t ON t.id = pe.team_id
    LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
    LEFT JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
    LEFT JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
    WHERE p.event_id = ? AND p.approval_status = 'approved'
    GROUP BY p.id, pe.id, t.id
    ORDER BY p.title ASC, pe.final_rank ASC, pe.final_score DESC, t.team_name ASC
");
$stmt->execute([$activeEventId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group results by program
$programs = [];
foreach ($rows as $row) {
    $pid = (int)$row['program_id'];
    if (!isset($programs[$pid])) {
        $programs[$pid] = [
            'id' => $pid,
            'title' => $row['program_title'],
            'class_type_name' => $row['class_type_name'] ?? 'All Classes',
            'only_team_marks' => (bool)$row['only_team_marks'],
            'entries' => []
        ];
    }
    
    $programs[$pid]['entries'][] = [
        'rank' => $row['final_rank'] !== null ? (int)$row['final_rank'] : null,
        'score' => $row['final_score'] !== null ? (float)$row['final_score'] : 0.00,
        'team_score' => $row['team_score'] !== null ? (float)$row['team_score'] : 0.00,
        'team_name' => $row['team_name'],
        'team_color' => $row['team_color'] ?: '#14b8a6',
        'team_prefix' => $row['team_prefix'],
        'participant_names' => $row['participant_names'] ?: null
    ];
}

$totalApproved = count($programs);
$totalEntries = count($rows);

// Helper to render high-contrast premium rank badges
function render_rank_badge(?int $rank): string {
    if ($rank === 1) {
        return '<span class="badge" style="background: rgba(251, 191, 36, 0.15); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.25); font-weight: 700; padding: 4px 8px;"><i class="fa-solid fa-trophy mr-1"></i> 1st Rank</span>';
    }
    if ($rank === 2) {
        return '<span class="badge" style="background: rgba(156, 163, 175, 0.15); color: #cbd5e1; border: 1px solid rgba(156, 163, 175, 0.25); font-weight: 700; padding: 4px 8px;"><i class="fa-solid fa-trophy mr-1"></i> 2nd Rank</span>';
    }
    if ($rank === 3) {
        return '<span class="badge" style="background: rgba(217, 118, 6, 0.15); color: #f59e0b; border: 1px solid rgba(217, 118, 6, 0.25); font-weight: 700; padding: 4px 8px;"><i class="fa-solid fa-trophy mr-1"></i> 3rd Rank</span>';
    }
    return '<span class="badge badge-neutral" style="padding: 4px 8px;">' . ($rank ? $rank . 'th' : '-') . '</span>';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <!-- Premium Header Area -->
    <div class="topbar">
        <div>
            <div class="page-title">
                <i class="fa-solid fa-award mr-2" style="color:var(--accent);"></i> Approval Marks &amp; Rankings
            </div>
            <div class="page-subtitle">
                Official marks and standings of all finalized programs in <strong><?= e($activeEvent['title']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Quick Stats Cards Row -->
    <div class="stats-grid mb-6">
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                <i class="fa-solid fa-square-check"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= $totalApproved ?></div>
                <div class="stat-label">Approved Programs</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= $totalEntries ?></div>
                <div class="stat-label">Scored Entries</div>
            </div>
        </div>
    </div>

    <!-- Results List -->
    <?php if (empty($programs)): ?>
        <div class="panel text-center" style="padding: 60px 20px;">
            <div class="empty-state" style="min-height: auto;">
                <div class="empty-icon" style="font-size: 56px; margin-bottom: 12px; color: var(--muted-2);">
                    <i class="fa-solid fa-ranking-star"></i>
                </div>
                <div class="empty-title">No Approved Ranks Found</div>
                <div class="empty-subtitle">Programs will appear here as soon as their score sheets are officially approved.</div>
            </div>
        </div>
    <?php else: ?>
        <div class="grid gap-6" style="display: grid; gap: 24px;">
            <?php foreach ($programs as $program): ?>
                <div class="panel" style="padding: 24px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface);">
                    
                    <!-- Program Details Header -->
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 14px;">
                        <div>
                            <h3 style="font-size: 19px; font-weight: 800; color: var(--text); font-family: 'Cairo', sans-serif; display: flex; align-items: center; gap: 8px; margin: 0;">
                                <i class="fa-solid fa-microphone-lines" style="color: var(--primary); font-size: 16px;"></i>
                                <?= e($program['title']) ?>
                            </h3>
                            <div style="font-size: 12.5px; color: var(--muted); margin-top: 4px;">
                                Program ID: <span style="font-family: monospace;"><?= $program['id'] ?></span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span class="badge badge-neutral" style="padding: 5px 10px; font-size: 12px; font-weight: 600;">
                                <i class="fa-solid fa-layer-group mr-1"></i> <?= e($program['class_type_name']) ?>
                            </span>
                            <span class="badge badge-success" style="padding: 5px 10px; font-size: 12px; font-weight: 600;">
                                <i class="fa-solid fa-lock mr-1"></i> Approved &amp; Published
                            </span>
                        </div>
                    </div>

                    <!-- Program Rankings Table -->
                    <div class="table-wrapper" style="margin: 0; border: 1px solid rgba(255,255,255,0.04); border-radius: 8px;">
                        <table class="table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="width: 120px; text-align: left;">Rank</th>
                                    <th style="text-align: left;">Participant(s)</th>
                                    <th style="width: 240px; text-align: left;">Team</th>
                                    <th style="width: 140px; text-align: right;">Marks</th>
                                    <th style="width: 140px; text-align: right; padding-right: 24px;">Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($program['entries'] as $entry): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                                        <td>
                                            <?= render_rank_badge($entry['rank']) ?>
                                        </td>
                                        <td style="font-weight: 500; color: var(--text);">
                                            <?php if ($program['only_team_marks']): ?>
                                                <span style="color: var(--muted); font-size: 13px; font-style: italic;">
                                                    <i class="fa-solid fa-people-group mr-1"></i> Group Team Entry
                                                </span>
                                            <?php else: ?>
                                                <?= e($entry['participant_names']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="width: 10px; height: 10px; border-radius: 50%; display: inline-block; background: <?= e($entry['team_color']) ?>; box-shadow: 0 0 8px <?= e($entry['team_color']) ?>;"></span>
                                                <span class="badge" style="background: rgba(255,255,255,0.03); color: var(--text); border: 1px solid rgba(255,255,255,0.07); font-weight: 600; padding: 4px 10px;">
                                                    <?= e($entry['team_name']) ?> <small style="color: var(--muted); margin-left: 4px;">(<?= $entry['team_prefix'] ?>+)</small>
                                                </span>
                                            </div>
                                        </td>
                                        <td style="text-align: right; font-weight: 700; color: var(--primary); font-size: 15px; font-family: monospace;">
                                            <?= number_format($entry['score'], 2) ?>
                                        </td>
                                        <td style="text-align: right; padding-right: 24px;">
                                            <?php if ($entry['team_score'] > 0): ?>
                                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25); font-weight: 700;">+<?= number_format($entry['team_score'], 1) ?> pts</span>
                                            <?php else: ?>
                                                <span class="badge badge-neutral" style="opacity: 0.7;">0.0 pts</span>
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
