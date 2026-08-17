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

function admin_csrf_value(): string
{
    return generate_csrf_token();
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

    $lowerName = strtolower($name);

    if (str_contains($name, 'حفظ') || str_contains($lowerName, 'hifz') || str_contains($lowerName, 'subjunior') || str_contains($lowerName, 'sub-junior') || str_contains($lowerName, 'sub junior')) {
        return 'subjunior';
    }

    if (str_contains($name, 'ثانوية') || str_contains($name, 'الثانوية') || str_contains($lowerName, 'sanaviyya') || str_contains($lowerName, 'thanawiya') || str_contains($lowerName, 'junior')) {
        return 'junior';
    }

    if (str_contains($name, 'عالية') || str_contains($name, 'العالية') || str_contains($lowerName, 'aliya') || str_contains($lowerName, 'aliyaa') || str_contains($lowerName, 'senior')) {
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

function admin_class_type_ids_for_tier($dashboardPdo, string $tier): array
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
function admin_program_class_filter_sql($dashboardPdo, string $classFilter, string $programAlias = 'mp'): array
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
    // Auto-migrate stage_type_id to be nullable for unscheduled programs
    static $migrated = false;
    if (!$migrated) {
        try {
            $pdo->exec("ALTER TABLE musabaqa_programs MODIFY stage_type_id INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE musabaqa_programs ADD COLUMN responsible_teacher_id INT UNSIGNED DEFAULT NULL");
            $pdo->exec("ALTER TABLE musabaqa_programs ADD COLUMN responsible_teacher_ids VARCHAR(255) DEFAULT NULL");
            try { $pdo->exec("ALTER TABLE musabaqa_programs DROP FOREIGN KEY fk_program_class_type"); } catch (Throwable $e) {}
            
            // Auto-migrate musabaqa_stage_types to support category column
            try {
                $pdo->exec("ALTER TABLE musabaqa_stage_types ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'on_stage'");
                $pdo->exec("UPDATE musabaqa_stage_types SET category = 'off_stage' WHERE name LIKE '%off%' OR name = 'Off Stage'");
            } catch (Throwable $e) {}

            // Auto-migrate musabaqa_events to support DATETIME for start_date and end_date
            try {
                $pdo->exec("ALTER TABLE musabaqa_events MODIFY start_date DATETIME DEFAULT NULL");
                $pdo->exec("ALTER TABLE musabaqa_events MODIFY end_date DATETIME DEFAULT NULL");
            } catch (Throwable $e) {}
            
            $migrated = true;
        } catch (Throwable $e) {}
    }

    $selectedEventId = (int)($_SESSION['selected_event_id'] ?? $_SESSION['active_event_id'] ?? 0);

    if ($selectedEventId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM musabaqa_events WHERE id = ? LIMIT 1');
        $stmt->execute([$selectedEventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event) {
            $_SESSION['selected_event_id'] = (int)$event['id'];
            return $event;
        }
    }

    if (function_exists('get_active_musabaqa')) {
        $event = get_active_musabaqa();
        if ($event) {
            $_SESSION['selected_event_id'] = (int)$event['id'];
            return $event;
        }
    }

    unset($_SESSION['selected_event_id']);
    admin_redirect('/admin/index.php');
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

/**
 * Executes a callback inside a PDO database transaction with automatic deadlock retries.
 * Handles MySQL SQLSTATE 40001 / Error 1213 (Deadlock found) and Error 1205 (Lock wait timeout).
 *
 * @param PDO $pdo
 * @param callable $callback
 * @param int $maxRetries
 * @return mixed
 */
function admin_db_transaction(PDO $pdo, callable $callback, int $maxRetries = 5)
{
    if ($pdo->inTransaction()) {
        return $callback($pdo);
    }

    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rbEx) {
                    // Ignore rollback exception
                }
            }

            $isDeadlock = false;
            $msg = $e->getMessage();
            if ($e instanceof PDOException) {
                $sqlState = (string)$e->getCode();
                $errCode = $e->errorInfo[1] ?? 0;
                if ($sqlState === '40001' || $errCode == 1213 || $errCode == 1205 || str_contains($msg, '1213') || stripos($msg, 'deadlock') !== false) {
                    $isDeadlock = true;
                }
            } elseif (str_contains($msg, '1213') || stripos($msg, 'deadlock') !== false) {
                $isDeadlock = true;
            }

            if ($isDeadlock && $attempt < $maxRetries) {
                usleep(rand(30000, 120000));
                continue;
            }

            throw $e;
        }
    }
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

function admin_calculate_grade_info(float $finalTotal, int $judgesCount = 2, array $settings = []): array
{
    $judgesCount = max(1, $judgesCount);
    $percentage = round(($finalTotal / ($judgesCount * 100)) * 100, 2);

    if ($percentage >= 85.0) {
        $grade = 'A';
    } elseif ($percentage >= 75.0) {
        $grade = 'B';
    } elseif ($percentage >= 65.0) {
        $grade = 'C';
    } else {
        $grade = 'D';
    }

    return [
        'percentage' => $percentage,
        'grade' => $grade,
        'grade_points' => 0,
    ];
}

function admin_recalculate_program_results(PDO $pdo, int $eventId, int $programId): void
{
    $settings = admin_get_settings($pdo);
    $tiedMode = $settings['tied_rank_mode'] ?? 'shared_full';

    $stmtProg = $pdo->prepare("SELECT judges_count, disable_scores, team_points_config, only_team_marks FROM musabaqa_programs WHERE id = ? LIMIT 1");
    $stmtProg->execute([$programId]);
    $progInfo = $stmtProg->fetch(PDO::FETCH_ASSOC) ?: [];
    $judgesCount = (int)($progInfo['judges_count'] ?? 2);
    $disableScores = !empty($progInfo['disable_scores']);

    $firstPoints = (int)($settings['first_place_points'] ?? 10);
    $secondPoints = (int)($settings['second_place_points'] ?? 7);
    $thirdPoints = (int)($settings['third_place_points'] ?? 5);

    $teamPoints = null;
    $onlyTeamMarks = (int)($progInfo['only_team_marks'] ?? 0);
    if (!empty($progInfo['team_points_config'])) {
        $teamPoints = json_decode($progInfo['team_points_config'], true);
    }

    $pointConfig = [];
    if (is_array($teamPoints)) {
        // If program has a custom point config, ONLY award points to ranks explicitly defined in it
        foreach ($teamPoints as $r => $pts) {
            $pointConfig[(int)$r] = (int)$pts;
        }
    } else {
        // Fallback to event-wide default points
        $pointConfig[1] = $firstPoints;
        $pointConfig[2] = $secondPoints;
        $pointConfig[3] = $thirdPoints;
    }

    if ($disableScores) {
        $stmtEntries = $pdo->prepare("
            SELECT id, final_rank, status
            FROM musabaqa_program_entries
            WHERE event_id = ? AND program_id = ?
        ");
        $stmtEntries->execute([$eventId, $programId]);
        $allEntries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

        $update = $pdo->prepare("
            UPDATE musabaqa_program_entries
            SET final_score = 0.00, team_score = ?, status = 'completed'
            WHERE id = ? AND event_id = ? AND program_id = ?
        ");

        foreach ($allEntries as $e) {
            $r = (int)($e['final_rank'] ?? 0);
            $teamScore = ($r > 0 && isset($pointConfig[$r])) ? $pointConfig[$r] : 0;
            $update->execute([$teamScore, (int)$e['id'], $eventId, $programId]);
        }

        admin_recalculate_program_status($pdo, $programId);
        return;
    }

    $orderClause = "ms.total_mark DESC, mpe.entry_number ASC, mpe.id ASC";
    if ($tiedMode === 'tie_breaker') {
        $orderClause = "ms.total_mark DESC, ms.mark_breakdown DESC, mpe.entry_number ASC, mpe.id ASC";
    }

    $stmt = $pdo->prepare("
        SELECT
            mpe.id,
            p.approval_status,
            p.team_points_config,
            p.only_team_marks,
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
        ORDER BY {$orderClause}
    ");
    $stmt->execute([$eventId, $programId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bonusThreshold = (float)($settings['grade_85_plus_threshold'] ?? 85.0);
    $bonusPoints = (int)($settings['grade_85_plus_bonus_points'] ?? 3);

    $update = $pdo->prepare("
        UPDATE musabaqa_program_entries
        SET final_score = ?, final_rank = ?, team_score = ?, status = ?
        WHERE id = ? AND event_id = ? AND program_id = ?
    ");

    $approvedEntries = [];
    foreach ($entries as $entry) {
        if (($entry['approval_status'] ?? '') !== 'approved' || $entry['total_mark'] === null) {
            $status = empty($entry['total_mark']) ? 'approved' : 'scoring';
            $update->execute([0, null, 0, $status, (int)$entry['id'], $eventId, $programId]);
        } else {
            $approvedEntries[] = $entry;
        }
    }

    if (!$approvedEntries) {
        admin_recalculate_program_status($pdo, $programId);
        return;
    }

    $scoreGroups = [];
    foreach ($approvedEntries as $entry) {
        $scoreKey = (string)(float)$entry['total_mark'];
        $scoreGroups[$scoreKey][] = $entry;
    }

    $groupCounts = [];
    foreach ($scoreGroups as $scoreStr => $groupEntries) {
        $groupCounts[] = count($groupEntries);
    }

    $position = 1;
    $seqRank = 1;
    $idx = 0;
    $isMarkBased = ($onlyTeamMarks === 0);

    foreach ($scoreGroups as $scoreStr => $groupEntries) {
        $count = count($groupEntries);
        $score = (float)$scoreStr;
        $finalScore = $onlyTeamMarks ? 0 : $score;

        if ($tiedMode === 'shared_split') {
            $sumPoints = 0;
            for ($i = 0; $i < $count; $i++) {
                $pos = $position + $i;
                $sumPoints += isset($pointConfig[$pos]) ? $pointConfig[$pos] : 0;
            }
            $teamScore = round($sumPoints / $count, 2);
            $rank = $position;

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $eBonus = ($isMarkBased && $eMark >= $bonusThreshold) ? $bonusPoints : 0;
                $entryTeamScore = ($teamScore > 0) ? $teamScore : $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;
        } elseif ($tiedMode === 'shared_sequential') {
            $rank = $seqRank;
            $teamScore = isset($pointConfig[$rank]) ? $pointConfig[$rank] : 0;

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $eBonus = ($isMarkBased && $eMark >= $bonusThreshold) ? $bonusPoints : 0;
                $entryTeamScore = ($teamScore > 0) ? $teamScore : $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;
            $seqRank++;
        } elseif ($tiedMode === 'tie_breaker') {
            foreach ($groupEntries as $e) {
                $rank = $position;
                $teamScore = isset($pointConfig[$rank]) ? $pointConfig[$rank] : 0;
                $eMark = (float)($e['total_mark'] ?? $score);
                $eBonus = ($isMarkBased && $eMark >= $bonusThreshold) ? $bonusPoints : 0;
                $entryTeamScore = ($teamScore > 0) ? $teamScore : $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, 'completed', (int)$e['id'], $eventId, $programId]);
                $position++;
            }
        } else {
            // shared_full
            $rank = $position;

            $teamScore = 0;
            if ($idx === 0) {
                $teamScore = $pointConfig[1] ?? 0;
            } elseif ($idx === 1) {
                $c1 = $groupCounts[0];
                if ($c1 === 1) {
                    $teamScore = $pointConfig[2] ?? 0;
                } elseif ($c1 === 2) {
                    $teamScore = $pointConfig[3] ?? 0;
                } else {
                    $teamScore = 0;
                }
            } elseif ($idx === 2) {
                $c1 = $groupCounts[0];
                $c2 = $groupCounts[1];
                if ($c1 === 1 && $c2 === 1) {
                    $teamScore = $pointConfig[3] ?? 0;
                } else {
                    $teamScore = 0;
                }
            } else {
                $teamScore = 0;
            }

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $eBonus = ($isMarkBased && $eMark >= $bonusThreshold) ? $bonusPoints : 0;
                $entryTeamScore = ($teamScore > 0) ? $teamScore : $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;
        }
        $idx++;
    }

    admin_recalculate_program_status($pdo, $programId);
}

function admin_recalculate_team_totals(PDO $pdo, int $eventId): void
{
    // Step 1: Query total team scores via read-only SELECT (no locks on teams table)
    $stmt = $pdo->prepare("
        SELECT pe.team_id, COALESCE(SUM(pe.team_score), 0) AS total_score
        FROM musabaqa_program_entries pe
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.event_id = ?
          AND p.approval_status = 'approved'
          AND (p.redirect_to_team IS NULL OR p.redirect_to_team = 1)
        GROUP BY pe.team_id
    ");
    $stmt->execute([$eventId]);
    $teamTotals = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['team_id'] !== null) {
            $teamTotals[(int)$row['team_id']] = (float)$row['total_score'];
        }
    }

    // Step 2: Update teams in deterministic order (ORDER BY id ASC) to eliminate InnoDB deadlock risk
    $teamsStmt = $pdo->prepare("SELECT id FROM musabaqa_teams WHERE event_id = ? ORDER BY id ASC");
    $teamsStmt->execute([$eventId]);
    $teamIds = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($teamIds) {
        $updateStmt = $pdo->prepare("UPDATE musabaqa_teams SET total_score = ? WHERE id = ?");
        foreach ($teamIds as $tId) {
            $score = $teamTotals[(int)$tId] ?? 0.0;
            $updateStmt->execute([$score, (int)$tId]);
        }
    }
}

function admin_recalculate_participant_totals(PDO $pdo, int $eventId, int $programId): void
{
    $pdo->prepare('DELETE FROM musabaqa_member_scores WHERE program_id = ?')->execute([$programId]);

    $stmt = $pdo->prepare("
        INSERT INTO musabaqa_member_scores (member_id, program_id, entry_id, score)
        SELECT em.team_member_id, ms.program_id, ms.entry_id, ms.total_mark
        FROM musabaqa_scores ms
        JOIN musabaqa_entry_members em ON em.entry_id = ms.entry_id
        JOIN musabaqa_programs p ON p.id = ms.program_id
        WHERE ms.event_id = ?
          AND ms.program_id = ?
          AND ms.status = 'approved'
          AND (p.disable_scores IS NULL OR p.disable_scores = 0)
          AND (p.only_team_marks IS NULL OR p.only_team_marks = 0)
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

function admin_approve_program_scores(PDO $pdo, int $eventId, int $programId, int $userId, bool $isBulk = false): void
{
    admin_db_transaction($pdo, function ($pdo) use ($eventId, $programId, $userId, $isBulk) {
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

        $stmtProg = $pdo->prepare("SELECT disable_scores FROM musabaqa_programs WHERE id = ? LIMIT 1");
        $stmtProg->execute([$programId]);
        $isDisableScores = (bool)$stmtProg->fetchColumn();

        foreach ($rows as $row) {
            $entryId = (int)$row['entry_id'];
            $total = $isDisableScores ? 0.00 : (float)$row['final_total'];
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

        if (!$isBulk) {
            admin_recalculate_participant_totals($pdo, $eventId, $programId);
            admin_recalculate_program_results($pdo, $eventId, $programId);
            admin_recalculate_team_totals($pdo, $eventId);
            admin_trigger_live_score_reveal($pdo, $eventId, $programId);
        }

        admin_log_activity($pdo, $userId, $eventId, 'approve_program_scores', 'musabaqa_programs', $programId, 'Program scores approved and finalized.');
        admin_log_activity($pdo, $userId, $eventId, 'leaderboard_update', 'musabaqa_teams', null, 'Leaderboard totals recalculated from approved program scores.');
        
        // Auto-redirect TV slideshow to Leaderboard
        admin_redirect_tv_slide($pdo, $eventId, 'leaderboard');
    });
}

function admin_trigger_live_score_reveal(PDO $pdo, int $eventId, $programIds = []): void
{
    try {
        if (!is_array($programIds)) {
            $programIds = $programIds ? [(int)$programIds] : [];
        }
        $programIds = array_values(array_filter(array_map('intval', $programIds)));

        $whereCond = "p.event_id = ? AND (p.approval_status = 'approved'";
        if (!empty($programIds)) {
            $whereCond .= " OR p.id IN (" . implode(',', $programIds) . ")";
        }
        $whereCond .= ")";

        $stmt = $pdo->prepare("
            SELECT p.id, p.title, p.program_type, ct.name AS class_type_name
            FROM musabaqa_programs p
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
            WHERE {$whereCond}
            ORDER BY p.id ASC
        ");
        $stmt->execute([$eventId]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$programs) return;

        $allProgIds = array_column($programs, 'id');
        $inClauseAll = implode(',', array_fill(0, count($allProgIds), '?'));

        $stmt = $pdo->prepare("
            SELECT 
                pe.id AS entry_id,
                pe.program_id,
                pe.entry_name,
                pe.entry_number,
                pe.final_score,
                pe.final_rank,
                pe.team_score,
                t.id AS team_id,
                t.team_name,
                t.short_name,
                t.team_color
            FROM musabaqa_program_entries pe
            JOIN musabaqa_teams t ON t.id = pe.team_id
            WHERE pe.event_id = ? AND pe.program_id IN ({$inClauseAll}) AND pe.team_score > 0
            ORDER BY pe.program_id ASC, pe.final_rank ASC, pe.id ASC
        ");
        $stmt->execute(array_merge([$eventId], $allProgIds));
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT id, team_name, short_name, team_color, total_score
            FROM musabaqa_teams
            WHERE event_id = ?
            ORDER BY total_score DESC, team_name ASC
        ");
        $stmt->execute([$eventId]);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $teamProgramPoints = [];
        $teamProgramBreakdown = [];
        $programMap = [];
        foreach ($programs as $prog) {
            $programMap[(int)$prog['id']] = $prog['title'];
        }

        foreach ($entries as $e) {
            $tId = (int)$e['team_id'];
            $pId = (int)$e['program_id'];
            $pts = (float)$e['team_score'];
            $teamProgramPoints[$tId] = ($teamProgramPoints[$tId] ?? 0) + $pts;
            $pTitle = $programMap[$pId] ?? 'Program';
            $teamProgramBreakdown[$tId][$pTitle] = ($teamProgramBreakdown[$tId][$pTitle] ?? 0) + $pts;
        }

        $formattedTeams = [];
        foreach ($teams as $idx => $t) {
            $tId = (int)$t['id'];
            $formattedTeams[] = [
                'id' => $tId,
                'team_name' => $t['team_name'],
                'short_name' => $t['short_name'] ?: $t['team_name'],
                'team_color' => $t['team_color'] ?: '#6366f1',
                'total_score' => (float)$t['total_score'],
                'program_points' => (float)($teamProgramPoints[$tId] ?? 0),
                'breakdown' => $teamProgramBreakdown[$tId] ?? [],
                'rank' => $idx + 1
            ];
        }

        $topTeam = $formattedTeams[0] ?? null;

        $formattedPrograms = [];
        foreach ($programs as $prog) {
            $formattedPrograms[] = [
                'id' => (int)$prog['id'],
                'title' => $prog['title'],
                'program_type' => ucfirst($prog['program_type'] ?: 'Individual'),
                'category_name' => $prog['class_type_name'] ?: '',
            ];
        }

        $payload = [
            'event_id' => $eventId,
            'programs' => $formattedPrograms,
            'program_id' => $formattedPrograms[0]['id'] ?? 0,
            'program_title' => count($formattedPrograms) === 1 
                ? $formattedPrograms[0]['title'] 
                : count($formattedPrograms) . ' Updated Programs',
            'category_name' => count($formattedPrograms) === 1 ? ($formattedPrograms[0]['category_name'] ?? '') : '',
            'timestamp' => round(microtime(true) * 1000),
            'top_team' => $topTeam,
            'teams' => $formattedTeams,
            'entries' => $entries
        ];

        $stmt = $pdo->prepare("
            INSERT INTO musabaqa_settings (setting_key, setting_value)
            VALUES ('live_score_reveal_event', ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        error_log('admin_trigger_live_score_reveal error: ' . $e->getMessage());
    }
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
    admin_db_transaction($pdo, function ($pdo) use ($eventId, $programId, $userId, $notes) {
        $stmt = $pdo->prepare('SELECT approval_status FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1');
        $stmt->execute([$programId, $eventId]);
        $approvalStatus = (string) ($stmt->fetchColumn() ?: '');

        if ($approvalStatus !== 'approved') {
            throw new RuntimeException('Only approved programs can be revoked.');
        }

        $pdo->prepare("
            UPDATE musabaqa_score_sheets
            SET status = 'submitted'
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
                team_score = 0,
                status = 'scoring'
            WHERE event_id = ?
              AND program_id = ?
        ")->execute([$eventId, $programId]);

        $pdo->prepare("
            UPDATE musabaqa_programs
            SET status = 'scoring',
                approval_status = 'submitted',
                reviewed_by = NULL,
                reviewed_at = NULL
            WHERE id = ?
              AND event_id = ?
        ")->execute([$programId, $eventId]);

        admin_recalculate_team_totals($pdo, $eventId);

        $description = 'Approved program scores revoked; finalized marks removed.';
        if (trim($notes) !== '') {
            $description .= ' Notes: ' . trim($notes);
        }

        admin_log_activity($pdo, $userId, $eventId, 'revoke_program_approval', 'musabaqa_programs', $programId, $description);
        admin_log_activity($pdo, $userId, $eventId, 'leaderboard_update', 'musabaqa_teams', null, 'Leaderboard totals recalculated after approval revocation.');
    });
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
    
    $html .= '<div class="limit-options-bar" style="display: inline-flex; align-items: center; gap: 4px; margin-left: 4px;">';
    foreach ([10, 15, 30, 5000] as $lOpt) {
        $isSel = ($limit === $lOpt);
        $btnClass = $isSel ? 'btn-primary' : 'btn-secondary';
        $label = $lOpt === 5000 ? 'All' : $lOpt;
        $html .= '<button type="button" class="btn ' . $btnClass . ' btn-xs limit-btn" data-limit="' . $lOpt . '" style="padding: 2px 8px; font-size: 11px; border-radius: 999px;">' . $label . '</button>';
    }
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

    // The global router owns the delegated click listener and always calls the
    // function belonging to the page that is currently displayed.
    window.adminAjaxPaginationFetch = fetchResults;

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

function admin_get_settings(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT setting_value FROM musabaqa_settings WHERE setting_key = 'global_musabaqa_settings' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    
    $defaults = [
        'default_judges_count' => 2,
        'max_judges_count' => 2,
        'default_total_marks' => 100,
        'default_entries_limit' => 10,
        'first_place_points' => 10,
        'second_place_points' => 7,
        'third_place_points' => 5,
        'tied_rank_mode' => 'shared_full',
        'active_sections' => [],
        'section_limits' => [],
        'judge_passkeys' => [
            1 => '1111',
            2 => '2222',
            3 => '3333',
            4 => '4444',
            5 => '5555'
        ]
    ];
    
    if ($row) {
        $data = json_decode($row['setting_value'], true);
        if (is_array($data)) {
            if (isset($data['judge_passkeys']) && is_array($data['judge_passkeys'])) {
                $defaults['judge_passkeys'] = array_replace($defaults['judge_passkeys'], $data['judge_passkeys']);
            }
            return array_merge($defaults, $data);
        }
    }
    
    return $defaults;
}

if (!function_exists('admin_save_settings')) {
    function admin_save_settings(PDO $pdo, array $settings): void
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("
            INSERT INTO musabaqa_settings (setting_key, setting_value)
            VALUES ('global_musabaqa_settings', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$json]);
    }
}

if (!function_exists('save_musabaqa_settings')) {
    function save_musabaqa_settings(PDO $pdo, array $settings): void
    {
        admin_save_settings($pdo, $settings);
    }
}

function admin_get_judge_passkey(PDO $pdo, int $judgeNo): string
{
    $settings = admin_get_settings($pdo);
    $passkeys = $settings['judge_passkeys'] ?? [];
    if (isset($passkeys[$judgeNo]) && trim((string)$passkeys[$judgeNo]) !== '') {
        return trim((string)$passkeys[$judgeNo]);
    }
    return (string)($judgeNo * 1111);
}

function admin_verify_judge_passkey(PDO $pdo, int $judgeNo, string $inputPasskey): bool
{
    $expected = admin_get_judge_passkey($pdo, $judgeNo);
    return trim($inputPasskey) === $expected;
}

function admin_detect_judge_by_passkey(PDO $pdo, string $passkey): ?int
{
    $cleanPasskey = trim($passkey);
    if ($cleanPasskey === '') {
        return null;
    }

    $settings = admin_get_settings($pdo);
    $maxJudges = max(2, min(10, (int)($settings['max_judges_count'] ?? $settings['default_judges_count'] ?? 2)));

    for ($j = 1; $j <= max(10, $maxJudges); $j++) {
        $expected = admin_get_judge_passkey($pdo, $j);
        if ($cleanPasskey === $expected) {
            return $j;
        }
    }

    return null;
}

function admin_set_live_stage_control(PDO $pdo, int $programId, int $entryId = 0): void
{
    $settings = admin_get_settings($pdo);
    $settings['live_program_id'] = $programId;
    $settings['live_entry_id'] = $entryId;
    
    $eventId = (int)($_SESSION['selected_event_id'] ?? $_SESSION['active_event_id'] ?? 0);
    if ($eventId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $eventId = (int)($stmt->fetchColumn() ?: 0);
    }
    
    if ($programId > 0) {
        if ($eventId > 0) {
            $pdo->prepare("UPDATE musabaqa_programs SET status = 'active' WHERE event_id = ? AND status = 'scoring'")->execute([$eventId]);
        }
        $pdo->prepare("UPDATE musabaqa_programs SET status = 'scoring' WHERE id = ?")->execute([$programId]);
    }
    
    save_musabaqa_settings($pdo, $settings);

    // Auto-redirect TV slideshow to Current Program
    if ($programId > 0 && $eventId > 0) {
        admin_redirect_tv_slide($pdo, $eventId, 'current-program');
    }
}

function admin_get_live_stage_control(PDO $pdo): array
{
    $settings = admin_get_settings($pdo);
    $liveProgramId = (int)($settings['live_program_id'] ?? 0);
    $liveEntryId = (int)($settings['live_entry_id'] ?? 0);

    if ($liveProgramId <= 0) {
        $eventId = (int)($_SESSION['selected_event_id'] ?? $_SESSION['active_event_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM musabaqa_programs WHERE event_id = ? AND status = 'scoring' LIMIT 1");
        $stmt->execute([$eventId]);
        $liveProgramId = (int)($stmt->fetchColumn() ?: 0);
    }

    return [
        'program_id' => $liveProgramId,
        'entry_id' => $liveEntryId
    ];
}

function admin_save_recorded_time(PDO $pdo, int $programId, int $entryId, int $durationSeconds, string $formattedTime): void
{
    $settings = admin_get_settings($pdo);
    if (!isset($settings['recorded_times']) || !is_array($settings['recorded_times'])) {
        $settings['recorded_times'] = [];
    }
    $key = "p{$programId}_e{$entryId}";
    $settings['recorded_times'][$key] = [
        'program_id' => $programId,
        'entry_id' => $entryId,
        'duration_seconds' => $durationSeconds,
        'formatted_time' => $formattedTime,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    save_musabaqa_settings($pdo, $settings);
}

function admin_get_recorded_times(PDO $pdo): array
{
    $settings = admin_get_settings($pdo);
    return is_array($settings['recorded_times'] ?? null) ? $settings['recorded_times'] : [];
}


function admin_validate_member_program_limits(PDO $pdo, int $eventId, int $programId, int $teamMemberId, ?int $excludeEntryId = null): void
{
    // 1. Load settings
    $settings = admin_get_settings($pdo);

    // 2. Load program details
    $stmt = $pdo->prepare("SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch();
    if (!$program) {
        throw new RuntimeException('Program not found.');
    }

    // 3. Load member details
    $stmt = $pdo->prepare("
        SELECT tm.id, c.class_type_id, s.display_name, s.full_name
        FROM musabaqa_team_members tm
        JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
        LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
        WHERE tm.id = ? AND tm.event_id = ? AND tm.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$teamMemberId, $eventId]);
    $member = $stmt->fetch();
    if (!$member) {
        throw new RuntimeException('Participant not found.');
    }
    
    $memberName = $member['display_name'] ?: $member['full_name'];
    $classTypeId = (int)$member['class_type_id'];

    // 4. Validate allowed sections
    $allowedSections = array_filter(array_map('trim', explode(',', $program['allowed_sections'] ?? '')));
    if ($allowedSections && !in_array((string)$classTypeId, $allowedSections, true)) {
        throw new RuntimeException("Participant $memberName's section is not allowed in this program.");
    }

    // 5. Validate program entry count limits
    $stageTypeId = (int)$program['stage_type_id'];
    $isOnStage = ($stageTypeId === 1); // 1 = Normal Stage (on-stage), 2 = Off Stage (off-stage)
    $stageKey = $isOnStage ? 'on_stage' : 'off_stage';
    
    $limit = (int)($settings['section_limits'][$classTypeId][$stageKey] ?? ($isOnStage ? 2 : 3));
    
    // Count current active entries for this member in same stage type (excluding current entry if editing)
    $excludeSql = $excludeEntryId ? "AND pe.id != :exclude_id" : "";
    $countQuery = "
        SELECT COUNT(DISTINCT pe.id)
        FROM musabaqa_entry_members em
        JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.event_id = :event_id
          AND em.team_member_id = :member_id
          AND p.stage_type_id = :stage_type_id
          $excludeSql
    ";
    $countParams = [
        'event_id' => $eventId,
        'member_id' => $teamMemberId,
        'stage_type_id' => $stageTypeId
    ];
    if ($excludeEntryId) {
        $countParams['exclude_id'] = $excludeEntryId;
    }
    
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($countParams);
    $currentCount = (int)$stmt->fetchColumn();
    
    if ($currentCount >= $limit) {
        $stageLabel = $isOnStage ? 'on-stage' : 'off-stage';
        throw new RuntimeException("Participant $memberName has reached the maximum limit of $limit $stageLabel program(s) for their section.");
    }
}

function admin_validate_program_entry_limit(PDO $pdo, int $eventId, int $programId, int $teamId, ?int $excludeEntryId = null): void
{
    $stmt = $pdo->prepare("SELECT * FROM musabaqa_programs WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch();
    if (!$program) {
        throw new RuntimeException('Program not found.');
    }

    $excludeSql = $excludeEntryId ? "AND id != ?" : "";
    $params = [$programId, $teamId, $eventId];
    if ($excludeEntryId) {
        $params[] = $excludeEntryId;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM musabaqa_program_entries WHERE program_id = ? AND team_id = ? AND event_id = ? $excludeSql");
    $stmt->execute($params);
    $currentEntriesCount = (int)$stmt->fetchColumn();
    
    $limit = (int)($program['entries_limit'] ?? 10);
    if ($currentEntriesCount >= $limit) {
        throw new RuntimeException("This program has reached its maximum entry limit of $limit per team.");
    }
}

if (!function_exists('can_access_category')) {
    function can_access_category(string $categorySlug): bool {
        if (is_admin()) return true;
        $categoryRoles = [
            'event-manager' => ['event-manager', 'event_manager'],
            'team-manager' => ['team-manager', 'team_manager', 'members-info-manager'],
            'printer' => ['printer', 'members-info-manager'],
            'registrar' => ['registrar', 'entries-assigner'],
            'live-display' => ['live-display-manager', 'live_display', 'tv-controller'],
            'score-entry' => ['score-entry-agent', 'score_entry', 'score-uploader'],
            'score-update' => ['score-update-agent', 'score_update']
        ];
        $allowedRoles = $categoryRoles[$categorySlug] ?? [$categorySlug];
        foreach ($allowedRoles as $r) {
            if (current_user_has_role($r)) return true;
        }
        return false;
    }
}

if (!function_exists('get_user_default_category_url')) {
    function get_user_default_category_url(?array $user = null): string {
        $user ??= current_user();

        if (!$user) {
            return '/auth/login.php';
        }

        $activeSpace = $_SESSION['active_workspace'] ?? '';
        $categories = [
            'event-manager' => '/admin/event-manager/',
            'team-manager'  => '/admin/event-manager/',
            'printer'       => '/admin/printer/',
            'registrar'     => '/admin/registrar/',
            'live-display'  => '/admin/live-display/',
            'score-entry'   => '/admin/score-entry/',
            'score-update'  => '/admin/score-update/',
        ];

        if ($activeSpace !== '' && isset($categories[$activeSpace])) {
            $categorySlug = ($activeSpace === 'team-manager') ? 'event-manager' : $activeSpace;
            if (can_access_category($categorySlug)) {
                return $categories[$activeSpace];
            }
        }

        if (is_admin()) {
            return '/admin/event-manager/';
        }

        foreach ($categories as $categorySlug => $path) {
            if (can_access_category($categorySlug)) {
                return $path;
            }
        }

        if (current_user_has_authority('members-info')) {
            return '/admin/printer/index.php';
        }
        if (current_user_has_authority('assign-entries')) {
            return '/admin/event/program-entries.php';
        }
        if (current_user_has_authority('upload-scores')) {
            return '/admin/score-entry/score-entry.php';
        }
        if (current_user_has_authority('control-live-display') || current_user_has_authority('control-tv')) {
            return '/admin/live-display/control-live-display.php';
        }

        return '/';
    }
}

function admin_auto_assign_programs_to_sections(PDO $pdo, int $eventId): int
{
    // 1. Fetch all schedule sections for the event
    $stmt = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY section_date ASC, start_time ASC, sort_order ASC");
    $stmt->execute([$eventId]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$sections) {
        return 0;
    }
    
    // 2. Fetch all scheduled programs for the event
    $stmt = $pdo->prepare("SELECT id, start_time FROM musabaqa_programs WHERE event_id = ? AND start_time IS NOT NULL");
    $stmt->execute([$eventId]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updateStmt = $pdo->prepare("UPDATE musabaqa_programs SET section_id = ? WHERE id = ?");
    $count = 0;
    
    $tvTimeInRange = static function(string $timeStr, string $start, string $end): bool {
        $time = date('H:i:s', strtotime($timeStr));
        if ($start <= $end) {
            return $time >= $start && $time <= $end;
        } else {
            return $time >= $start || $time <= $end;
        }
    };
    
    foreach ($programs as $prog) {
        $progDateTime = $prog['start_time'];
        $progDate = date('Y-m-d', strtotime($progDateTime));
        $matchedSectionId = null;
        
        foreach ($sections as $sec) {
            // If section has a date, it must match the program's date
            if (!empty($sec['section_date']) && $sec['section_date'] !== $progDate) {
                continue;
            }
            if ($tvTimeInRange($progDateTime, $sec['start_time'], $sec['end_time'])) {
                $matchedSectionId = (int)$sec['id'];
                break; // Stop at first match
            }
        }
        
        if ($matchedSectionId !== null) {
            $updateStmt->execute([$matchedSectionId, $prog['id']]);
            $count++;
        } else {
            // Clear section assignment if it doesn't match any section
            $updateStmt->execute([null, $prog['id']]);
        }
    }
    
    return $count;
}

/**
 * Checks if a target program is locked for scoring because a preceding program in chronological schedule sequence is not yet scored.
 * Returns null if unlocked, or the preceding blocking program array if locked.
 */
function admin_check_program_scoring_locked(PDO $pdo, int $eventId, int $targetProgramId): ?array
{
    if ($targetProgramId <= 0 || $eventId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            p.id, p.title, p.status, p.approval_status, p.start_time,
            COUNT(DISTINCT pe.id) AS entry_count,
            COUNT(DISTINCT CASE WHEN ss.status IN ('completed','submitted','approved','rejected') THEN pe.id END) AS scored_count
        FROM musabaqa_programs p
        LEFT JOIN musabaqa_schedule_sections mss ON mss.id = p.section_id
        LEFT JOIN musabaqa_program_entries pe ON pe.program_id = p.id
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE p.event_id = ?
        GROUP BY p.id, mss.id
        ORDER BY 
            COALESCE(mss.section_date, '9999-12-31') ASC,
            COALESCE(mss.sort_order, 999) ASC,
            COALESCE(mss.start_time, '23:59:59') ASC,
            (p.start_time IS NULL) ASC,
            p.start_time ASC,
            p.id ASC
    ");
    $stmt->execute([$eventId]);
    $allProgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unscoredPrev = null;
    foreach ($allProgs as $prog) {
        $pId = (int)$prog['id'];
        if ($pId === $targetProgramId) {
            return $unscoredPrev;
        }

        $entryCount = (int)$prog['entry_count'];
        $scoredCount = (int)$prog['scored_count'];
        $isCompleted = ($prog['status'] === 'completed') || 
                       in_array($prog['approval_status'], ['submitted', 'approved'], true) ||
                       ($entryCount > 0 && $scoredCount >= $entryCount);

        if ($entryCount > 0 && !$isCompleted) {
            if ($unscoredPrev === null) {
                $unscoredPrev = $prog;
            }
        }
    }

    return null;
}

/**
 * Returns subquery string to select an entry's chest number with multi-tier fallback.
 */
function admin_entry_chest_number_subquery(string $peAlias = 'pe'): string
{
    return "
        COALESCE(
            NULLIF(TRIM((
                SELECT tm.chest_number
                FROM musabaqa_entry_members mem
                JOIN musabaqa_team_members tm ON tm.id = mem.team_member_id
                WHERE mem.entry_id = $peAlias.id
                  AND tm.chest_number IS NOT NULL AND TRIM(tm.chest_number) <> ''
                LIMIT 1
            )), ''),
            NULLIF(TRIM((
                SELECT tm.chest_number
                FROM musabaqa_team_members tm
                JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
                WHERE tm.team_id = $peAlias.team_id
                  AND TRIM(LOWER(s.full_name)) = TRIM(LOWER($peAlias.entry_name))
                  AND tm.chest_number IS NOT NULL AND TRIM(tm.chest_number) <> ''
                LIMIT 1
            )), ''),
            NULLIF(TRIM($peAlias.entry_number), ''),
            '-'
        ) AS chest_number
    ";
}

/**
 * Resolves category label from class type name or class type ID.
 */
if (!function_exists('id_card_category_label')) {
    function id_card_category_label(?string $classTypeName, int $classTypeId = 0): string
    {
        $name = trim((string)$classTypeName);
        if ($name !== '') {
            return $name;
        }

        return match ($classTypeId) {
            1 => 'Bidaya',
            2 => 'Uula',
            3 => 'Thaniya',
            4 => 'Thalisa',
            5 => 'Aliya',
            default => 'General',
        };
    }
}

if (!function_exists('admin_get_default_categories_for_program_title')) {
    function admin_get_default_categories_for_program_title(string $title): array
    {
        $t = strtolower($title);

        if (str_contains($t, 'qira') || str_contains($t, 'quran') || str_contains($t, 'قرآن')) {
            return [
                ['name' => 'التجويد والترتيل', 'max_marks' => 30.00, 'sort_order' => 1],
                ['name' => 'حسن الصوت واللحن', 'max_marks' => 30.00, 'sort_order' => 2],
                ['name' => 'الوقف والابتداء', 'max_marks' => 20.00, 'sort_order' => 3],
                ['name' => 'الالتزام بالوقت', 'max_marks' => 20.00, 'sort_order' => 4]
            ];
        }
        if (str_contains($t, 'song') || str_contains($t, 'nasheed') || str_contains($t, 'نشيد') || str_contains($t, 'غناء')) {
            return [
                ['name' => 'حسن الصوت واللحن', 'max_marks' => 30.00, 'sort_order' => 1],
                ['name' => 'الأداء والإيقاع', 'max_marks' => 30.00, 'sort_order' => 2],
                ['name' => 'مضمون النشيد', 'max_marks' => 20.00, 'sort_order' => 3],
                ['name' => 'الثقة بالنفس', 'max_marks' => 20.00, 'sort_order' => 4]
            ];
        }
        if (str_contains($t, 'speech') || str_contains($t, 'خطاب') || str_contains($t, 'muhadasa') || str_contains($t, 'ebarath') || str_contains($t, 'reading')) {
            return [
                ['name' => 'الموضوع', 'max_marks' => 20.00, 'sort_order' => 1],
                ['name' => 'التقديم', 'max_marks' => 20.00, 'sort_order' => 2],
                ['name' => 'النطق والاداء', 'max_marks' => 20.00, 'sort_order' => 3],
                ['name' => 'الثقة بالنفس', 'max_marks' => 20.00, 'sort_order' => 4],
                ['name' => 'الالتزام بالوقت', 'max_marks' => 20.00, 'sort_order' => 5]
            ];
        }
        if (str_contains($t, 'calligraphy') || str_contains($t, 'خط')) {
            return [
                ['name' => 'دقة الخط والقواعد', 'max_marks' => 30.00, 'sort_order' => 1],
                ['name' => 'الجمال والتناسق', 'max_marks' => 30.00, 'sort_order' => 2],
                ['name' => 'النظافة والإتقان', 'max_marks' => 20.00, 'sort_order' => 3],
                ['name' => 'الإبداع والابتكار', 'max_marks' => 20.00, 'sort_order' => 4]
            ];
        }
        if (str_contains($t, 'essay') || str_contains($t, 'poem') || str_contains($t, 'writing') || str_contains($t, 'news') || str_contains($t, 'كتابة') || str_contains($t, 'شعر')) {
            return [
                ['name' => 'الموضوع والأفكار', 'max_marks' => 30.00, 'sort_order' => 1],
                ['name' => 'الأسلوب واللغة', 'max_marks' => 30.00, 'sort_order' => 2],
                ['name' => 'التنظيم والتسلسل', 'max_marks' => 20.00, 'sort_order' => 3],
                ['name' => 'النحو والإملاء', 'max_marks' => 20.00, 'sort_order' => 4]
            ];
        }
        if (str_contains($t, 'fight') || str_contains($t, 'word') || str_contains($t, 'musajala') || str_contains($t, 'quiz') || str_contains($t, 'مسابقة')) {
            return [
                ['name' => 'الدقة والصحة', 'max_marks' => 40.00, 'sort_order' => 1],
                ['name' => 'سرعة الإجابة', 'max_marks' => 30.00, 'sort_order' => 2],
                ['name' => 'الالتزام بالقواعد', 'max_marks' => 30.00, 'sort_order' => 3]
            ];
        }

        return [
            ['name' => 'الموضوع والأفكار', 'max_marks' => 30.00, 'sort_order' => 1],
            ['name' => 'الأداء والتقديم', 'max_marks' => 30.00, 'sort_order' => 2],
            ['name' => 'الإتقان والمهارة', 'max_marks' => 20.00, 'sort_order' => 3],
            ['name' => 'الالتزام بالوقت', 'max_marks' => 20.00, 'sort_order' => 4]
        ];
    }
}

/**
 * Fetches all active event team members for ID cards CSV export.
 */
if (!function_exists('id_card_members')) {
    function id_card_members(PDO $pdo, int $eventId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                mtm.chest_number,
                COALESCE(NULLIF(s.display_name, ''), s.full_name) AS display_name,
                t.team_name,
                t.team_color,
                ct.name AS class_type_name,
                c.class_type_id
            FROM musabaqa_team_members mtm
            JOIN musabaqa_teams t ON t.id = mtm.team_id
            JOIN " . DB_MAIN_NAME . ".students s ON s.id = mtm.student_id
            LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = c.class_type_id
            WHERE mtm.event_id = ?
              AND mtm.status = 'active'
            ORDER BY mtm.chest_number ASC, mtm.id ASC
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('admin_handle_renewal_resolutions')) {
    function admin_handle_renewal_resolutions(PDO $pdo, PDO $dashboardPdo, int $activeEventId): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve_renewal') {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                admin_flash('error', 'Invalid security token.');
                admin_redirect($_SERVER['REQUEST_URI']);
            }

            $deletedEntryId = (int)$_POST['deleted_entry_id'];
            $resolveAction = (string)$_POST['resolve_action']; // 'replace' or 'discard'
            $existingEntryId = (int)($_POST['existing_entry_id'] ?? 0);
            $studentId = (int)$_POST['student_id'];
            $teamMemberId = (int)$_POST['team_member_id'];
            $programId = (int)$_POST['program_id'];
            $teamId = (int)$_POST['team_id'];

            try {
                admin_db_transaction($pdo, function ($pdo) use ($deletedEntryId, $resolveAction, $existingEntryId, $studentId, $teamMemberId, $programId, $teamId, $activeEventId, $dashboardPdo) {
                    // Verify record in deleted_member_entries
                    $stmt = $pdo->prepare('SELECT * FROM musabaqa_deleted_member_entries WHERE id = ? AND student_id = ? AND program_id = ? AND event_id = ? LIMIT 1');
                    $stmt->execute([$deletedEntryId, $studentId, $programId, $activeEventId]);
                    $delEntry = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$delEntry) {
                        throw new RuntimeException('Renewal request not found.');
                    }

                    if ($resolveAction === 'discard') {
                        // Just delete the saved entry
                        $stmt = $pdo->prepare('DELETE FROM musabaqa_deleted_member_entries WHERE id = ?');
                        $stmt->execute([$deletedEntryId]);
                    } elseif ($resolveAction === 'replace') {
                        if ($existingEntryId <= 0) {
                            throw new RuntimeException('Please select an existing participant to replace.');
                        }
                        
                        // Delete the existing entry membership
                        $stmt = $pdo->prepare('DELETE FROM musabaqa_entry_members WHERE entry_id = ?');
                        $stmt->execute([$existingEntryId]);
                        
                        // Delete the entry itself
                        $stmt = $pdo->prepare('DELETE FROM musabaqa_program_entries WHERE id = ? AND program_id = ? AND team_id = ? AND event_id = ?');
                        $stmt->execute([$existingEntryId, $programId, $teamId, $activeEventId]);
                        
                        // Create the new entry for this student
                        $stmtName = $dashboardPdo->prepare("SELECT COALESCE(NULLIF(display_name, ''), full_name) AS name FROM students WHERE id = ? LIMIT 1");
                        $stmtName->execute([$studentId]);
                        $studentName = (string)$stmtName->fetchColumn();
                        
                        $tMax = $pdo->prepare("SELECT COALESCE(MAX(entry_number), 0) + 1 FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ?");
                        $tMax->execute([$activeEventId, $programId]);
                        $entryNumber = (int)$tMax->fetchColumn();
                        
                        $perfOrder = mt_rand(1, 999999);
                        
                        $insEntry = $pdo->prepare("
                            INSERT INTO musabaqa_program_entries
                                (event_id, program_id, team_id, entry_name, entry_number, performance_order, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'approved')
                        ");
                        $insEntry->execute([$activeEventId, $programId, $teamId, $studentName, $entryNumber, $perfOrder]);
                        $newEntryId = (int)$pdo->lastInsertId();
                        
                        $insMem = $pdo->prepare("INSERT INTO musabaqa_entry_members (entry_id, team_member_id, role_name) VALUES (?, ?, ?)");
                        $insMem->execute([$newEntryId, $teamMemberId, (string)$delEntry['role_name']]);
                        
                        // Delete from deleted_member_entries
                        $stmt = $pdo->prepare('DELETE FROM musabaqa_deleted_member_entries WHERE id = ?');
                        $stmt->execute([$deletedEntryId]);
                    }
                });
                
                // Remove from session pending renewals
                if (isset($_SESSION['pending_renewals'])) {
                    foreach ($_SESSION['pending_renewals'] as $k => $item) {
                        if ((int)$item['deleted_entry_id'] === $deletedEntryId) {
                            unset($_SESSION['pending_renewals'][$k]);
                            break;
                        }
                    }
                    $_SESSION['pending_renewals'] = array_values($_SESSION['pending_renewals']);
                }
                
                admin_flash('success', 'Participant limit conflict resolved successfully.');
            } catch (Throwable $e) {
                admin_flash('error', $e->getMessage() ?: 'Unable to resolve conflict.');
            }
            
            $redirectUrl = $_SERVER['PHP_SELF'];
            if ($_GET) {
                $redirectUrl .= '?' . http_build_query($_GET);
            }
            admin_redirect($redirectUrl);
        }
    }
}

if (!function_exists('admin_render_renewal_modal_html')) {
    function admin_render_renewal_modal_html(): void
    {
        if (empty($_SESSION['pending_renewals'])) {
            return;
        }
        
        $item = $_SESSION['pending_renewals'][0];
        ?>
        <div class="modal-overlay active" id="renewalModal" style="display: flex; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999;">
            <div class="modal-box modal-md" style="background: var(--panel-bg, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: 12px; padding: 24px; width: 500px; max-width: 95%;">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div class="modal-title" style="font-size: 1.25rem; font-weight: 600; color: #fff;">Participant Limit Overlap</div>
                </div>
                <form method="POST">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="resolve_renewal">
                    <input type="hidden" name="deleted_entry_id" value="<?= (int)$item['deleted_entry_id'] ?>">
                    <input type="hidden" name="student_id" value="<?= (int)$item['student_id'] ?>">
                    <input type="hidden" name="team_member_id" value="<?= (int)$item['team_member_id'] ?>">
                    <input type="hidden" name="program_id" value="<?= (int)$item['program_id'] ?>">
                    <input type="hidden" name="team_id" value="<?= (int)$item['team_id'] ?>">
                    
                    <div class="panel" style="margin-bottom: 20px; color: #cbd5e1; font-size: 0.95rem; line-height: 1.5; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 6px; border: 1px solid #334155;">
                        <strong style="color: #38bdf8;"><?= e($item['student_name']) ?></strong> has a previous registration in 
                        <strong style="color: #fff;"><?= e($item['program_title']) ?></strong>.
                        <br><br>
                        However, team <strong style="color: #38bdf8;"><?= e($item['team_name']) ?></strong> has already reached the limit of 
                        <strong><?= (int)$item['limit'] ?></strong> entry/entries for this program.
                    </div>
                    
                    <div class="input-group" style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #94a3b8;">Choose Resolution Option:</label>
                        
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php foreach ($item['existing_entries'] as $idx => $exist): ?>
                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; color: #fff; background: rgba(255,255,255,0.05); padding: 10px; border-radius: 6px; border: 1px solid #334155;">
                                    <input type="radio" name="resolve_action" value="replace" <?= $idx === 0 ? 'checked' : '' ?> style="margin-top: 4px;" onclick="document.getElementById('existing_entry_id').value = '<?= (int)$exist['id'] ?>'">
                                    <span>Replace <strong><?= e($exist['entry_name']) ?></strong> (Entry #<?= (int)$exist['entry_number'] ?>) in this program</span>
                                </label>
                            <?php endforeach; ?>
                            
                            <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; color: #cbd5e1; background: rgba(255,255,255,0.05); padding: 10px; border-radius: 6px; border: 1px solid #334155;">
                                    <input type="radio" name="resolve_action" value="discard" style="margin-top: 4px;" onclick="document.getElementById('existing_entry_id').value = '0'">
                                    <span>Discard previous entry (Do not register <?= e($item['student_name']) ?> for this program)</span>
                            </label>
                        </div>
                        
                        <input type="hidden" name="existing_entry_id" id="existing_entry_id" value="<?= (int)($item['existing_entries'][0]['id'] ?? 0) ?>">
                    </div>
                    
                    <div class="form-actions" style="display: flex; justify-content: flex-end; gap: 12px;">
                        <button class="btn btn-success btn-md" type="submit">Resolve</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}

/**
 * Reshuffle (randomize) the performance order of all program participants in an event.
 */
if (!function_exists('admin_reshuffle_all_event_program_entries')) {
    function admin_reshuffle_all_event_program_entries(PDO $pdo, int $eventId): void
    {
        $stmt = $pdo->prepare("SELECT id FROM musabaqa_programs WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $programIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($programIds as $programId) {
            $eStmt = $pdo->prepare("SELECT id FROM musabaqa_program_entries WHERE event_id = ? AND program_id = ?");
            $eStmt->execute([$eventId, $programId]);
            $entryIds = $eStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if ($entryIds) {
                shuffle($entryIds);
                $updateStmt = $pdo->prepare("UPDATE musabaqa_program_entries SET performance_order = ? WHERE id = ?");
                foreach ($entryIds as $index => $id) {
                    $updateStmt->execute([$index + 1, $id]);
                }
            }
        }
    }
}

/**
 * Update the TV display setting to manually activate a specific slide.
 */
function admin_redirect_tv_slide(PDO $pdo, int $eventId, string $slideKey): void
{
    if ($eventId <= 0) return;
    if ($slideKey === 'schedule') return; // Do not redirect to schedule in any action
    
    $settKey = 'live_display.event.' . $eventId . '.settings';
    
    // Read current settings
    $stmt = $pdo->prepare('SELECT setting_value FROM musabaqa_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$settKey]);
    $existingJson = (string)$stmt->fetchColumn();
    $settings = json_decode($existingJson, true) ?: [];
    
    // Keep the existing slideshow mode, do not override it to manual!
    $settings['active_slide'] = $slideKey;
    // Milliseconds ensure two quick actions are still distinct to every TV.
    $settings['last_updated'] = (int) round(microtime(true) * 1000);
    $settings['updated_at'] = date(DATE_ATOM);
    
    $saveStmt = $pdo->prepare('
        INSERT INTO musabaqa_settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE 
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ');
    $saveStmt->execute([$settKey, json_encode($settings)]);
}




