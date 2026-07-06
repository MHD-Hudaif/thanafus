<?php

require_once __DIR__ . '/../config/auth.php';

/* =====================================================
   AJAX HELPERS
   ===================================================== */

function admin_is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function admin_close_page(): void
{
    if (!admin_is_ajax()) {
        echo '</body></html>';
    }
}

function admin_redirect(string $path, array $query = []): void
{
    $url = app_url($path);
    $query = array_filter(
        $query,
        static fn ($value) => $value !== null && $value !== '' && $value !== 'all'
    );

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    if (admin_is_ajax()) {
        header('Content-Type: application/json');
        echo json_encode(['redirect' => $url]);
        exit;
    }

    header('Location: ' . $url);
    exit;
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_take_flash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : null;
}

function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generate_csrf_token()) . '">';
}

function admin_class_type_tiers(): array
{
    return [
        'all' => 'All',
        'senior' => 'Senior',
        'junior' => 'Junior',
        'subjunior' => 'Sub Junior',
    ];
}

function admin_class_type_tier_from_name(?string $name): ?string
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }

    if (str_contains($name, 'حفظ')) {
        return 'subjunior';
    }

    if (str_contains($name, 'ثانوية') || str_contains($name, 'الثانوية')) {
        return 'junior';
    }

    if (str_contains($name, 'عالية') || str_contains($name, 'العالية')) {
        return 'senior';
    }

    return null;
}

function admin_class_type_tier_label(?string $tier): string
{
    $tiers = admin_class_type_tiers();

    if (!$tier || $tier === 'all') {
        return $tiers['all'];
    }

    return $tiers[$tier] ?? ucfirst($tier);
}

function admin_class_type_display(?string $arabicName, ?int $classTypeId = null): string
{
    if (!$arabicName && ($classTypeId === null || $classTypeId <= 0)) {
        return 'All Classes';
    }

    $tier = admin_class_type_tier_from_name($arabicName);
    $english = $tier ? admin_class_type_tier_label($tier) : '—';
    $arabic = trim((string) $arabicName);

    if ($arabic === '') {
        return $english;
    }

    return $english . ' · ' . $arabic;
}

function admin_class_type_badge_class(?string $tier): string
{
    return match ($tier) {
        'senior' => 'badge-info',
        'junior' => 'badge-warning',
        'subjunior' => 'badge-success',
        default => 'badge-neutral',
    };
}

function admin_class_type_ids_for_tier(PDO $dashboardPdo, string $tier): array
{
    if (!in_array($tier, ['senior', 'junior', 'subjunior'], true)) {
        return [];
    }

    $stmt = $dashboardPdo->query('SELECT id, name FROM class_types ORDER BY id ASC');
    $ids = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (admin_class_type_tier_from_name($row['name'] ?? '') === $tier) {
            $ids[] = (int) $row['id'];
        }
    }

    return $ids;
}

/**
 * @return array{0: string, 1: array<int>}
 */
function admin_program_class_filter_sql(PDO $dashboardPdo, string $classFilter, string $programAlias = 'mp'): array
{
    $classFilter = trim($classFilter);

    if ($classFilter === '' || $classFilter === 'all') {
        return ['', []];
    }

    if (!in_array($classFilter, ['senior', 'junior', 'subjunior'], true)) {
        return ['', []];
    }

    $ids = admin_class_type_ids_for_tier($dashboardPdo, $classFilter);
    if (!$ids) {
        return [' AND 1 = 0', []];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    return [" AND {$programAlias}.class_type_id IN ({$placeholders})", $ids];
}

function admin_require_active_event(PDO $pdo): array
{
    $activeEventId = (int)($_SESSION['active_event_id'] ?? 0);

    if ($activeEventId <= 0) {
        admin_redirect('/admin/events.php');
    }

    $stmt = $pdo->prepare('SELECT * FROM musabaqa_events WHERE id = ? LIMIT 1');
    $stmt->execute([$activeEventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        unset($_SESSION['active_event_id']);
        admin_redirect('/admin/events.php');
    }

    return $event;
}

function admin_normalize_slug(string $value): string
{
    return strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $value), '-'));
}

function admin_log_activity(
    PDO $pdo,
    ?int $userId,
    ?int $eventId,
    string $actionType,
    string $targetTable,
    ?int $targetId,
    string $description
): void {
    $stmt = $pdo->prepare("
        INSERT INTO musabaqa_activity_logs
            (user_id, event_id, action_type, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $eventId, $actionType, $targetTable, $targetId, $description]);
}

function admin_recalculate_entry_status(PDO $pdo, int $entryId): void
{
    $stmt = $pdo->prepare("
        SELECT
            pe.program_id,
            p.approval_status,
            ss.id AS score_sheet_id
        FROM musabaqa_program_entries pe
        JOIN musabaqa_programs p ON p.id = pe.program_id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE pe.id = ?
        LIMIT 1
    ");
    $stmt->execute([$entryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $entryStatus = 'approved';
    if (($row['approval_status'] ?? '') === 'approved') {
        $entryStatus = 'completed';
    } elseif (!empty($row['score_sheet_id'])) {
        $entryStatus = 'scoring';
    }

    $stmt = $pdo->prepare('UPDATE musabaqa_program_entries SET status = ? WHERE id = ?');
    $stmt->execute([$entryStatus, $entryId]);
}

function admin_recalculate_program_status(PDO $pdo, int $programId): void
{
    $stmt = $pdo->prepare("
        SELECT
            p.approval_status,
            COUNT(pe.id) AS entry_count,
            COUNT(ss.id) AS sheet_count
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$programId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $entryCount = (int)($row['entry_count'] ?? 0);
    $sheetCount = (int)($row['sheet_count'] ?? 0);

    $status = 'active';
    if (($row['approval_status'] ?? '') === 'approved') {
        $status = 'completed';
    } elseif ($sheetCount > 0) {
        $status = 'scoring';
    }

    $stmt = $pdo->prepare('UPDATE musabaqa_programs SET status = ? WHERE id = ?');
    $stmt->execute([$status, $programId]);
}

function admin_recalculate_program_results(PDO $pdo, int $eventId, int $programId): void
{
    $stmt = $pdo->prepare("
        SELECT
            mpe.id,
            p.approval_status,
            ms.total_mark
        FROM musabaqa_program_entries mpe
        JOIN musabaqa_programs p ON p.id = mpe.program_id
        LEFT JOIN musabaqa_scores ms
            ON ms.entry_id = mpe.id
           AND ms.program_id = mpe.program_id
           AND ms.event_id = mpe.event_id
           AND ms.status = 'approved'
        WHERE mpe.event_id = ?
          AND mpe.program_id = ?
        ORDER BY ms.total_mark DESC, mpe.entry_number ASC, mpe.id ASC
    ");
    $stmt->execute([$eventId, $programId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rank = 0;
    $previousScore = null;
    $position = 0;

    $update = $pdo->prepare("
        UPDATE musabaqa_program_entries
        SET final_score = ?, final_rank = ?, status = ?
        WHERE id = ? AND event_id = ? AND program_id = ?
    ");

    foreach ($entries as $entry) {
        if (($entry['approval_status'] ?? '') !== 'approved' || $entry['total_mark'] === null) {
            $status = empty($entry['total_mark']) ? 'approved' : 'scoring';
            $update->execute([0, null, $status, (int)$entry['id'], $eventId, $programId]);
            continue;
        }

        $position++;
        $score = (float)$entry['total_mark'];
        if ($previousScore === null || $score < $previousScore) {
            $rank = $position;
        }
        $previousScore = $score;

        $update->execute([$score, $rank, 'completed', (int)$entry['id'], $eventId, $programId]);
    }

    admin_recalculate_program_status($pdo, $programId);
}

function admin_recalculate_team_totals(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare("
        UPDATE musabaqa_teams t
        LEFT JOIN (
            SELECT pe.team_id, COALESCE(SUM(ms.total_mark), 0) AS total_score
            FROM musabaqa_scores ms
            JOIN musabaqa_program_entries pe ON pe.id = ms.entry_id
            WHERE ms.event_id = ?
              AND ms.status = 'approved'
            GROUP BY pe.team_id
        ) totals ON totals.team_id = t.id
        SET t.total_score = COALESCE(totals.total_score, 0)
        WHERE t.event_id = ?
    ");
    $stmt->execute([$eventId, $eventId]);
}

function admin_recalculate_participant_totals(PDO $pdo, int $eventId, int $programId): void
{
    $pdo->prepare('DELETE FROM musabaqa_member_scores WHERE program_id = ?')->execute([$programId]);

    $stmt = $pdo->prepare("
        INSERT INTO musabaqa_member_scores (member_id, program_id, entry_id, score)
        SELECT em.team_member_id, ms.program_id, ms.entry_id, ms.total_mark
        FROM musabaqa_scores ms
        JOIN musabaqa_entry_members em ON em.entry_id = ms.entry_id
        WHERE ms.event_id = ?
          AND ms.program_id = ?
          AND ms.status = 'approved'
    ");
    $stmt->execute([$eventId, $programId]);
}

function admin_program_ready_for_approval(PDO $pdo, int $programId): bool
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(pe.id) AS entry_count,
            SUM(CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN 1 ELSE 0 END) AS completed_count
        FROM musabaqa_program_entries pe
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE pe.program_id = ?
    ");
    $stmt->execute([$programId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $entryCount = (int)($row['entry_count'] ?? 0);
    $completedCount = (int)($row['completed_count'] ?? 0);

    return $entryCount > 0 && $completedCount >= $entryCount;
}

function admin_submit_program_for_approval(PDO $pdo, int $eventId, int $programId, int $userId): void
{
    if (!admin_program_ready_for_approval($pdo, $programId)) {
        throw new RuntimeException('Every entry must have a completed score sheet before approval.');
    }

    $stmt = $pdo->prepare("
        UPDATE musabaqa_score_sheets
        SET status = 'submitted'
        WHERE program_id = ?
          AND status IN ('completed','rejected')
    ");
    $stmt->execute([$programId]);

    $stmt = $pdo->prepare("
        UPDATE musabaqa_programs
        SET status = 'scoring',
            approval_status = 'submitted',
            submitted_by = ?,
            submitted_at = NOW(),
            reviewed_by = NULL,
            reviewed_at = NULL
        WHERE id = ? AND event_id = ?
    ");
    $stmt->execute([$userId, $programId, $eventId]);

    admin_log_activity($pdo, $userId, $eventId, 'submit_for_approval', 'musabaqa_programs', $programId, 'Program scores submitted for approval.');
}

function admin_program_approvable(?string $approvalStatus): bool
{
    return in_array((string) $approvalStatus, ['submitted', 'rejected'], true);
}

function admin_approve_program_scores(PDO $pdo, int $eventId, int $programId, int $userId): void
{
    $stmt = $pdo->prepare('SELECT approval_status FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
    $stmt->execute([$programId, $eventId]);
    $approvalStatus = (string) ($stmt->fetchColumn() ?: '');

    if (!admin_program_approvable($approvalStatus)) {
        throw new RuntimeException('Only submitted or rejected programs can be approved.');
    }

    if ($approvalStatus === 'rejected') {
        if (!admin_program_ready_for_approval($pdo, $programId)) {
            throw new RuntimeException('Every entry must have a score sheet before approval.');
        }

        $pdo->prepare("
            UPDATE musabaqa_score_sheets
            SET status = 'submitted'
            WHERE program_id = ?
              AND status IN ('rejected', 'completed')
        ")->execute([$programId]);

        $pdo->prepare("
            UPDATE musabaqa_programs
            SET approval_status = 'submitted',
                status = 'scoring'
            WHERE id = ?
              AND event_id = ?
        ")->execute([$programId, $eventId]);
    }

    $stmt = $pdo->prepare("
        SELECT pe.id AS entry_id, pe.team_id, ss.final_total
        FROM musabaqa_program_entries pe
        JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE pe.event_id = ?
          AND pe.program_id = ?
          AND ss.status IN ('submitted','approved')
        ORDER BY ss.final_total DESC, pe.entry_number ASC, pe.id ASC
    ");
    $stmt->execute([$eventId, $programId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        throw new RuntimeException('No submitted score sheets found for this program.');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM musabaqa_program_entries
        WHERE event_id = ? AND program_id = ?
    ");
    $stmt->execute([$eventId, $programId]);
    if (count($rows) < (int)$stmt->fetchColumn()) {
        throw new RuntimeException('All entries must be submitted before approval.');
    }

    $pdo->prepare("UPDATE musabaqa_score_sheets SET status = 'approved' WHERE program_id = ?")
        ->execute([$programId]);

    $findScore = $pdo->prepare("
        SELECT id
        FROM musabaqa_scores
        WHERE event_id = ?
          AND program_id = ?
          AND entry_id = ?
          AND judge_name = 'System Final'
        LIMIT 1
    ");
    $updateScore = $pdo->prepare("
        UPDATE musabaqa_scores
        SET total_mark = ?,
            remarks = 'Approved from two-judge score sheet',
            status = 'approved',
            entered_by = ?,
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $insertScore = $pdo->prepare("
        INSERT INTO musabaqa_scores
            (event_id, program_id, entry_id, judge_name, total_mark, remarks, status, entered_by, approved_by, approved_at)
        VALUES (?, ?, ?, 'System Final', ?, 'Approved from two-judge score sheet', 'approved', ?, ?, NOW())
    ");

    foreach ($rows as $row) {
        $entryId = (int)$row['entry_id'];
        $total = (float)$row['final_total'];
        $findScore->execute([$eventId, $programId, $entryId]);
        $scoreId = (int)$findScore->fetchColumn();

        if ($scoreId > 0) {
            $updateScore->execute([$total, $userId, $userId, $scoreId]);
        } else {
            $insertScore->execute([$eventId, $programId, $entryId, $total, $userId, $userId]);
        }
    }

    $stmt = $pdo->prepare("
        UPDATE musabaqa_programs
        SET status = 'completed',
            approval_status = 'approved',
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ? AND event_id = ?
    ");
    $stmt->execute([$userId, $programId, $eventId]);

    admin_recalculate_participant_totals($pdo, $eventId, $programId);
    admin_recalculate_program_results($pdo, $eventId, $programId);
    admin_recalculate_team_totals($pdo, $eventId);

    admin_log_activity($pdo, $userId, $eventId, 'approve_program_scores', 'musabaqa_programs', $programId, 'Program scores approved and finalized.');
    admin_log_activity($pdo, $userId, $eventId, 'leaderboard_update', 'musabaqa_teams', null, 'Leaderboard totals recalculated from approved program scores.');
}

function admin_reject_program_scores(PDO $pdo, int $eventId, int $programId, int $userId, string $notes = ''): void
{
    $pdo->prepare("
        UPDATE musabaqa_score_sheets
        SET status = 'rejected'
        WHERE program_id = ? AND status = 'submitted'
    ")->execute([$programId]);

    $stmt = $pdo->prepare("
        UPDATE musabaqa_programs
        SET status = 'scoring',
            approval_status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ? AND event_id = ?
    ");
    $stmt->execute([$userId, $programId, $eventId]);

    $description = 'Program score submission rejected.';
    if (trim($notes) !== '') {
        $description .= ' Notes: ' . trim($notes);
    }

    admin_log_activity($pdo, $userId, $eventId, 'reject_program_scores', 'musabaqa_programs', $programId, $description);
}

function admin_revoke_program_approval(PDO $pdo, int $eventId, int $programId, int $userId, string $notes = ''): void
{
    $stmt = $pdo->prepare('SELECT approval_status FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
    $stmt->execute([$programId, $eventId]);
    $approvalStatus = (string) ($stmt->fetchColumn() ?: '');

    if ($approvalStatus !== 'approved') {
        throw new RuntimeException('Only approved programs can be revoked.');
    }

    $pdo->prepare("
        UPDATE musabaqa_score_sheets
        SET status = 'rejected'
        WHERE program_id = ?
          AND status = 'approved'
    ")->execute([$programId]);

    $pdo->prepare("
        DELETE FROM musabaqa_scores
        WHERE event_id = ?
          AND program_id = ?
          AND judge_name = 'System Final'
    ")->execute([$eventId, $programId]);

    $pdo->prepare('DELETE FROM musabaqa_member_scores WHERE program_id = ?')->execute([$programId]);

    $pdo->prepare("
        UPDATE musabaqa_program_entries
        SET final_score = 0,
            final_rank = NULL,
            status = 'approved'
        WHERE event_id = ?
          AND program_id = ?
    ")->execute([$eventId, $programId]);

    $pdo->prepare("
        UPDATE musabaqa_programs
        SET status = 'scoring',
            approval_status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ?
          AND event_id = ?
    ")->execute([$userId, $programId, $eventId]);

    admin_recalculate_team_totals($pdo, $eventId);

    $description = 'Approved program scores revoked; finalized marks removed.';
    if (trim($notes) !== '') {
        $description .= ' Notes: ' . trim($notes);
    }

    admin_log_activity($pdo, $userId, $eventId, 'revoke_program_approval', 'musabaqa_programs', $programId, $description);
    admin_log_activity($pdo, $userId, $eventId, 'leaderboard_update', 'musabaqa_teams', null, 'Leaderboard totals recalculated after approval revocation.');
}

function admin_render_pagination_html(int $page, int $limit, int $totalItems): string
{
    if ($totalItems <= 0) {
        return '';
    }

    $totalPages = max(1, (int)ceil($totalItems / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    if ($page < 1) {
        $page = 1;
    }
    $offset = ($page - 1) * $limit;
    $showingStart = $offset + 1;
    $showingEnd = min($offset + $limit, $totalItems);

    $html = '<div class="flex-between pagination-bar mt-4" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 10px;">';
    
    // Left side: Showing entries text with inline limit trigger
    $html .= '<div class="text-muted text-sm" style="display: flex; align-items: center; gap: 8px;">';
    $html .= 'Showing ' . $showingStart . ' to ' . $showingEnd . ' of ' . $totalItems . ' entries';
    $html .= '<span style="margin-left: 8px; color: var(--muted); font-size: 13px;">Limit:</span>';
    
    $html .= '<div class="limit-popover-container" style="position: relative; display: inline-block;">';
    $html .= '<button type="button" class="btn btn-secondary btn-sm active-limit-trigger" style="padding: 2px 6px; font-size: 12px; height: 24px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.05); color: #fff; min-width: 28px;">';
    $html .= '<span>' . ($limit === 5000 ? 'All' : $limit) . '</span>';
    $html .= '</button>';
    $html .= '<div class="limit-options-popover">';
    foreach ([10, 15, 30, 5000] as $lOpt) {
        $btnClass = $limit === $lOpt ? 'btn-primary' : 'btn-secondary';
        $label = $lOpt === 5000 ? 'All' : $lOpt;
        $html .= '<button type="button" class="btn ' . $btnClass . ' btn-xs limit-btn" data-limit="' . $lOpt . '" style="padding: 2px 6px; font-size: 11px;">' . $label . '</button>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>'; // End left side
    
    $html .= '<div class="flex gap-2" style="display: flex; align-items: center; gap: 12px;">';
    $html .= '<div class="flex gap-1" style="display: flex; gap: 4px;">';
    
    if ($page > 1) {
        $html .= '<button type="button" data-page="' . ($page - 1) . '" class="btn btn-secondary btn-sm ajax-page-btn" style="padding: 4px 8px;"><i class="fa-solid fa-angle-left"></i> Previous</button>';
    }
    
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    for ($i = $startPage; $i <= $endPage; $i++) {
        $btnClass = $i === $page ? 'btn-primary' : 'btn-secondary';
        $html .= '<button type="button" data-page="' . $i . '" class="btn ' . $btnClass . ' btn-sm ajax-page-btn" style="padding: 4px 8px;">' . $i . '</button>';
    }
    
    if ($page < $totalPages) {
        $html .= '<button type="button" data-page="' . ($page + 1) . '" class="btn btn-secondary btn-sm ajax-page-btn" style="padding: 4px 8px;">Next <i class="fa-solid fa-angle-right"></i></button>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function admin_ajax_pagination_script(): string
{
    return <<<'HTML'
<style>
.limit-options-popover {
    display: flex;
    align-items: center;
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%) scaleX(0);
    transform-origin: left center;
    margin-left: 8px;
    background: #1e293b;
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 6px;
    padding: 4px;
    gap: 4px;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    opacity: 0;
    pointer-events: none;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.15s linear;
}
.limit-options-popover.active {
    transform: translateY(-50%) scaleX(1);
    opacity: 1;
    pointer-events: auto;
}
</style>
<script>
(() => {
    const tableBody = document.getElementById('table-body');
    const paginationContainer = document.getElementById('pagination-container');
    const searchForm = document.getElementById('search-form');
    
    if (!tableBody || !paginationContainer) return;

    let currentPage = new URLSearchParams(window.location.search).get('page') || 1;
    let currentLimit = new URLSearchParams(window.location.search).get('limit') || '';
    
    function fetchResults(page, limit) {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', '1');
        if (page) url.searchParams.set('page', page);
        if (limit) url.searchParams.set('limit', limit);
        
        // Include form filters if searchForm exists
        if (searchForm) {
            const formData = new FormData(searchForm);
            for (const [key, value] of formData.entries()) {
                url.searchParams.set(key, value);
            }
        }
        
        tableBody.style.opacity = '0.5';
        
        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    tableBody.innerHTML = data.html;
                    paginationContainer.innerHTML = data.pagination;
                    
                    // Update URL without reloading
                    const newUrl = new URL(window.location.href);
                    if (page) newUrl.searchParams.set('page', page);
                    if (limit) newUrl.searchParams.set('limit', limit);
                    
                    // Also carry over search form params
                    if (searchForm) {
                        const formData = new FormData(searchForm);
                        for (const [key, value] of formData.entries()) {
                            if (value) {
                                newUrl.searchParams.set(key, value);
                            } else {
                                newUrl.searchParams.delete(key);
                            }
                        }
                    }
                    
                    window.history.pushState({}, '', newUrl);
                }
            })
            .catch(err => console.error(err))
            .finally(() => {
                tableBody.style.opacity = '1';
            });
    }

    // Handle pagination clicks and limits
    if (!window._paginationInit) {
        window._paginationInit = true;
        document.addEventListener('click', (e) => {
            // Page buttons
            const pageBtn = e.target.closest('.ajax-page-btn');
            if (pageBtn) {
                e.preventDefault();
                currentPage = pageBtn.dataset.page;
                fetchResults(currentPage, currentLimit);
                return;
            }
            
            // Limit buttons
            const limitBtn = e.target.closest('.limit-btn');
            if (limitBtn) {
                e.preventDefault();
                currentLimit = limitBtn.dataset.limit;
                currentPage = 1; // reset to page 1
                fetchResults(currentPage, currentLimit);
                return;
            }
            
            // Toggle popover
            const trigger = e.target.closest('.active-limit-trigger');
            if (trigger) {
                const popover = trigger.nextElementSibling;
                if (popover && popover.classList.contains('limit-options-popover')) {
                    popover.classList.toggle('active');
                }
                return;
            } else {
                // Close popover if clicked outside
                document.querySelectorAll('.limit-options-popover.active').forEach(p => {
                    if (!e.target.closest('.limit-popover-container')) {
                        p.classList.remove('active');
                    }
                });
            }
        });
    }

    // Handle Search Form Submit (this re-binds per page load since the form element is new)
    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            currentPage = 1;
            fetchResults(currentPage, currentLimit);
        });
        
        // Debounce search input
        const searchInputs = searchForm.querySelectorAll('input[type="text"], input[type="search"]');
        let timeout = null;
        searchInputs.forEach(input => {
            input.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    currentPage = 1;
                    fetchResults(currentPage, currentLimit);
                }, 400);
            });
        });

        // Auto-fetch on select change
        const selectInputs = searchForm.querySelectorAll('select');
        selectInputs.forEach(select => {
            select.addEventListener('change', () => {
                currentPage = 1;
                fetchResults(currentPage, currentLimit);
            });
        });
    }
})();
</script>
HTML;
}
