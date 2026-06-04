<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function reveal_event_summary(?array $event): array
{
    if (!$event) {
        return [
            'id' => null,
            'title' => 'Al-Thanafus',
            'slug' => null,
            'scoreboard_mode' => 'system',
            'theme_colors' => null,
            'status' => 'draft',
        ];
    }

    return [
        'id' => (int) $event['id'],
        'title' => (string) $event['title'],
        'slug' => $event['slug'],
        'description' => $event['description'],
        'theme_colors' => $event['theme_colors'],
        'scoreboard_mode' => $event['scoreboard_mode'],
        'intro_enabled' => (bool) $event['intro_enabled'],
        'scoreboard_enabled' => (bool) $event['scoreboard_enabled'],
        'scoreboard_locked' => (bool) $event['scoreboard_locked'],
        'status' => $event['status'],
        'start_date' => $event['start_date'],
        'end_date' => $event['end_date'],
    ];
}

function reveal_stage_label(?string $stageName, ?int $classTypeId, array $categories): string
{
    $parts = [];
    $stageName = trim((string) $stageName);
    if ($stageName !== '') {
        $parts[] = $stageName;
    }
    if ($classTypeId !== null) {
        $parts[] = 'Class ' . $classTypeId;
    }
    if ($categories) {
        $categoryNames = array_map(static fn ($row) => $row['name'], $categories);
        $parts[] = implode(' · ', array_filter($categoryNames));
    }
    return trim(implode(' — ', array_filter($parts)));
}

function reveal_resolve_entry_score(array $entry): float
{
    if (isset($entry['final_score']) && $entry['final_score'] !== null && (float) $entry['final_score'] > 0) {
        return (float) $entry['final_score'];
    }

    $entryId = (int) $entry['id'];
    $programId = (int) $entry['program_id'];

    $memberScore = (float) reveal_fetch_value(
        "SELECT COALESCE(SUM(score),0) FROM musabaqa_member_scores WHERE entry_id = ? AND program_id = ?",
        [$entryId, $programId],
        0
    );
    if ($memberScore > 0) {
        return $memberScore;
    }

    $judgeScore = (float) reveal_fetch_value(
        "SELECT COALESCE(SUM(total_mark),0) FROM musabaqa_scores WHERE entry_id = ? AND program_id = ? AND status = 'approved'",
        [$entryId, $programId],
        0
    );
    if ($judgeScore > 0) {
        return $judgeScore;
    }

    $sheetScore = (float) reveal_fetch_value(
        "SELECT COALESCE(final_total,0) FROM musabaqa_score_sheets WHERE entry_id = ? AND program_id = ? AND status IN ('approved','completed') ORDER BY id DESC LIMIT 1",
        [$entryId, $programId],
        0
    );

    return $sheetScore;
}

function reveal_program_details(int $programId): ?array
{
    $program = reveal_fetch_one(
        "SELECT p.*, st.name AS stage_name
         FROM musabaqa_programs p
         LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
         WHERE p.id = ?",
        [$programId]
    );

    if (!$program) {
        return null;
    }

    $categories = reveal_fetch_all(
        "SELECT * FROM musabaqa_program_categories WHERE program_id = ? ORDER BY sort_order ASC, id ASC",
        [$programId]
    );

    $entries = reveal_fetch_all(
        "SELECT pe.*, t.team_name, t.short_name, t.team_color
         FROM musabaqa_program_entries pe
         LEFT JOIN musabaqa_teams t ON t.id = pe.team_id
         WHERE pe.program_id = ?
         ORDER BY
            CASE WHEN pe.final_rank IS NULL THEN 9999 ELSE pe.final_rank END ASC,
            pe.final_score DESC,
            pe.id ASC",
        [$programId]
    );

    $resolvedEntries = [];
    foreach ($entries as $entry) {
        $score = reveal_resolve_entry_score($entry);
        $resolvedEntries[] = [
            'id' => (int) $entry['id'],
            'entry_name' => $entry['entry_name'],
            'entry_number' => $entry['entry_number'] !== null ? (int) $entry['entry_number'] : null,
            'team_id' => (int) $entry['team_id'],
            'team_name' => $entry['team_name'] ?? ('Team #' . $entry['team_id']),
            'short_name' => $entry['short_name'] ?? null,
            'team_color' => reveal_safe_hex($entry['team_color'] ?? null),
            'status' => $entry['status'],
            'final_score' => round($score, 2),
            'final_rank' => $entry['final_rank'] !== null ? (int) $entry['final_rank'] : null,
        ];
    }

    $placements = array_slice($resolvedEntries, 0, 3);

    return [
        'id' => (int) $program['id'],
        'title' => $program['title'],
        'program_type' => $program['program_type'],
        'class_type_id' => $program['class_type_id'] !== null ? (int) $program['class_type_id'] : null,
        'stage_type_id' => (int) $program['stage_type_id'],
        'stage_name' => $program['stage_name'] ?? null,
        'location' => $program['location'],
        'status' => $program['status'],
        'approval_status' => $program['approval_status'],
        'submitted_at' => $program['submitted_at'],
        'reviewed_at' => $program['reviewed_at'],
        'categories' => array_map(static function ($row) {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'max_marks' => (float) $row['max_marks'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }, $categories),
        'entries' => $resolvedEntries,
        'placements' => $placements,
        'stage_label' => reveal_stage_label($program['stage_name'] ?? null, $program['class_type_id'] !== null ? (int) $program['class_type_id'] : null, $categories),
    ];
}

function reveal_current_event_id(): ?int
{
    $event = reveal_best_event();
    return $event ? (int) $event['id'] : null;
}

function reveal_completion_summary(int $eventId): array
{
    $total = (int) reveal_fetch_value(
        "SELECT COUNT(*) FROM musabaqa_programs WHERE event_id = ?",
        [$eventId],
        0
    );

    $approved = (int) reveal_fetch_value(
        "SELECT COUNT(DISTINCT id) FROM musabaqa_programs WHERE event_id = ? AND approval_status = 'approved'",
        [$eventId],
        0
    );

    $percent = $total > 0 ? round(($approved / $total) * 100, 2) : 0.0;

    return [
        'approved_programs' => $approved,
        'total_programs' => $total,
        'percentage' => $percent,
    ];
}

function reveal_team_rows(int $eventId, string $mode = 'system'): array
{
    $teams = reveal_fetch_all(
        "SELECT * FROM musabaqa_teams WHERE event_id = ? ORDER BY id ASC",
        [$eventId]
    );

    $scoresByTeam = [];

    if ($mode === 'manual') {
        $rows = reveal_fetch_all(
            "SELECT team_id, COALESCE(score,0) AS score FROM musabaqa_manual_scoreboard WHERE event_id = ?",
            [$eventId]
        );
        foreach ($rows as $row) {
            $scoresByTeam[(int) $row['team_id']] = (float) $row['score'];
        }
    } else {
        $entries = reveal_fetch_all(
            "SELECT pe.*, p.approval_status
             FROM musabaqa_program_entries pe
             INNER JOIN musabaqa_programs p ON p.id = pe.program_id
             WHERE p.event_id = ? AND p.approval_status = 'approved'",
            [$eventId]
        );

        foreach ($entries as $entry) {
            $teamId = (int) $entry['team_id'];
            $scoresByTeam[$teamId] = ($scoresByTeam[$teamId] ?? 0.0) + reveal_resolve_entry_score($entry);
        }

        if (!$entries) {
            $fallback = reveal_fetch_all(
                "SELECT id AS team_id, COALESCE(total_score,0) AS score FROM musabaqa_teams WHERE event_id = ?",
                [$eventId]
            );
            foreach ($fallback as $row) {
                $scoresByTeam[(int) $row['team_id']] = (float) $row['score'];
            }
        }
    }

    $result = [];
    foreach ($teams as $team) {
        $teamId = (int) $team['id'];
        $score = $scoresByTeam[$teamId] ?? (float) $team['total_score'];
        $result[] = [
            'id' => $teamId,
            'event_id' => (int) $team['event_id'],
            'team_name' => $team['team_name'],
            'short_name' => $team['short_name'],
            'team_color' => reveal_safe_hex($team['team_color'] ?? null),
            'number_prefix' => $team['number_prefix'] !== null ? (int) $team['number_prefix'] : null,
            'total_score' => round($score, 2),
            'teacher_incharge_id' => $team['teacher_incharge_id'] !== null ? (int) $team['teacher_incharge_id'] : null,
        ];
    }

    usort($result, static function (array $a, array $b): int {
        if ($a['total_score'] === $b['total_score']) {
            return strcmp($a['team_name'], $b['team_name']);
        }
        return $a['total_score'] < $b['total_score'] ? 1 : -1;
    });

    foreach ($result as $index => &$team) {
        $team['rank'] = $index + 1;
    }

    return $result;
}

function reveal_leaderboard_snapshot(int $eventId): array
{
    $event = reveal_fetch_one("SELECT * FROM musabaqa_events WHERE id = ?", [$eventId]);
    if (!$event) {
        return [
            'event' => reveal_event_summary(null),
            'teams' => [],
            'leaderboard' => [],
            'completion' => ['approved_programs' => 0, 'total_programs' => 0, 'percentage' => 0],
            'latest_log_id' => 0,
            'program_count' => 0,
        ];
    }

    $completion = reveal_completion_summary($eventId);
    $teams = reveal_team_rows($eventId, (string) $event['scoreboard_mode']);
    $latestLogId = (int) reveal_fetch_value(
        "SELECT COALESCE(MAX(id), 0) FROM musabaqa_activity_logs WHERE event_id = ? AND action_type = 'approve_program_scores'",
        [$eventId],
        0
    );

    return [
        'event' => reveal_event_summary($event),
        'teams' => $teams,
        'leaderboard' => $teams,
        'completion' => $completion,
        'latest_log_id' => $latestLogId,
        'program_count' => $completion['total_programs'],
    ];
}

function reveal_program_batch_payload(int $eventId, array $logs): array
{
    $programIds = [];
    foreach ($logs as $log) {
        if (!empty($log['target_id'])) {
            $programIds[] = (int) $log['target_id'];
        }
    }

    $programIds = array_values(array_unique($programIds));
    $programs = [];
    foreach ($programIds as $programId) {
        $details = reveal_program_details($programId);
        if ($details) {
            $programs[] = $details;
        }
    }

    $teamDeltas = [];
    foreach ($programs as $program) {
        foreach ($program['entries'] as $entry) {
            $teamId = (int) $entry['team_id'];
            if (!isset($teamDeltas[$teamId])) {
                $teamDeltas[$teamId] = 0.0;
            }
            $teamDeltas[$teamId] += (float) $entry['final_score'];
        }
    }

    $teamDeltasOut = [];
    foreach ($teamDeltas as $teamId => $delta) {
        $teamDeltasOut[] = [
            'team_id' => $teamId,
            'delta' => round($delta, 2),
        ];
    }

    return [
        'event_id' => $eventId,
        'batch_id' => max(array_map(static fn ($log) => (int) $log['id'], $logs)),
        'log_ids' => array_map(static fn ($log) => (int) $log['id'], $logs),
        'source_logs' => array_map(static function ($log) {
            return [
                'id' => (int) $log['id'],
                'action_type' => $log['action_type'],
                'target_table' => $log['target_table'],
                'target_id' => $log['target_id'] !== null ? (int) $log['target_id'] : null,
                'description' => $log['description'],
                'created_at' => $log['created_at'],
            ];
        }, $logs),
        'programs' => $programs,
        'team_deltas' => $teamDeltasOut,
        'program_ids' => $programIds,
    ];
}

function reveal_approval_batches_since(int $eventId, int $afterLogId, int $gapSeconds = 5): array
{
    $logs = reveal_fetch_all(
        "SELECT * FROM musabaqa_activity_logs
         WHERE event_id = ?
           AND action_type = 'approve_program_scores'
           AND id > ?
         ORDER BY id ASC",
        [$eventId, $afterLogId]
    );

    if (!$logs) {
        return [];
    }

    $batches = [];
    $current = [
        'logs' => [],
        'last_ts' => null,
    ];

    foreach ($logs as $log) {
        $ts = strtotime((string) $log['created_at']);
        $shouldSplit = false;

        if ($current['logs']) {
            $prevTs = (int) $current['last_ts'];
            if (($ts - $prevTs) > $gapSeconds) {
                $shouldSplit = true;
            }
        }

        if ($shouldSplit) {
            $batches[] = reveal_program_batch_payload($eventId, $current['logs']);
            $current = [
                'logs' => [],
                'last_ts' => null,
            ];
        }

        $current['logs'][] = $log;
        $current['last_ts'] = $ts;
    }

    if ($current['logs']) {
        $batches[] = reveal_program_batch_payload($eventId, $current['logs']);
    }

    return $batches;
}

function reveal_current_state_for_event(int $eventId, int $afterLogId = 0): array
{
    $snapshot = reveal_leaderboard_snapshot($eventId);
    $batches = $afterLogId > 0 ? reveal_approval_batches_since($eventId, $afterLogId) : [];

    return [
        'snapshot' => $snapshot,
        'batches' => $batches,
    ];
}
