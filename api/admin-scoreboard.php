<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/admin-helpers.php';
require_once __DIR__ . '/../live-display/includes/functions.php';

try {
    $pdo = $GLOBALS['musabaqa_pdo'];
    $activeEvent = tv_active_event();
    
    if (!$activeEvent) {
        echo json_encode(['ok' => false, 'error' => 'No active event found.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $eventId = (int)$activeEvent['id'];
    
    // Fetch overall team leaderboard using tv_leaderboard
    $leaderboard = tv_leaderboard($eventId);
    
    // Fetch detailed program score breakdown per team
    $stmt = $pdo->prepare("
        SELECT 
            t.id AS team_id,
            t.team_name,
            t.team_color AS color_code,
            COALESCE(ct.name, 'General') AS division_name,
            SUM(pe.team_score) AS division_score,
            COUNT(DISTINCT pe.program_id) AS programs_completed
        FROM musabaqa_teams t
        CROSS JOIN " . DB_MAIN_NAME . ".class_types ct
        LEFT JOIN musabaqa_program_entries pe ON pe.team_id = t.id AND pe.event_id = ?
        LEFT JOIN musabaqa_programs p ON p.id = pe.program_id AND p.event_id = ?
        LEFT JOIN musabaqa_scores s ON s.entry_id = pe.id AND s.status = 'approved'
        WHERE t.event_id = ?
          AND (p.class_type_id = ct.id OR p.class_type_id IS NULL)
          AND (p.disable_scores IS NULL OR p.disable_scores = 0)
        GROUP BY t.id, t.team_name, t.team_color, ct.id, ct.name
        ORDER BY t.id ASC
    ");
    $stmt->execute([$eventId, $eventId, $eventId]);
    $divisionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $divisionBreakdown = [];
    foreach ($divisionRows as $dRow) {
        $tId = (int)$dRow['team_id'];
        if (!isset($divisionBreakdown[$tId])) {
            $divisionBreakdown[$tId] = [];
        }
        $divisionBreakdown[$tId][$dRow['division_name']] = [
            'score' => round((float)$dRow['division_score'], 2),
            'programs' => (int)$dRow['programs_completed']
        ];
    }

    // Attach division breakdown to leaderboard teams
    foreach ($leaderboard as &$teamData) {
        $tId = (int)$teamData['id'];
        $teamData['divisions'] = $divisionBreakdown[$tId] ?? [];
    }
    unset($teamData);

    // Fetch latest live updates
    $latestUpdate = tv_latest_score_update($eventId);

    // Fetch recent 10 approved score activity logs
    $stmt = $pdo->prepare("
        SELECT 
            ms.id AS score_id,
            ms.updated_at,
            ms.total_mark,
            ms.status,
            p.id AS program_id,
            p.title AS program_title,
            t.team_name,
            t.team_color AS color_code
        FROM musabaqa_scores ms
        JOIN musabaqa_programs p ON p.id = ms.program_id
        JOIN musabaqa_program_entries pe ON pe.id = ms.entry_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE p.event_id = ?
        ORDER BY ms.updated_at DESC, ms.id DESC
        LIMIT 10
    ");
    $stmt->execute([$eventId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch overall event metrics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_programs,
            SUM(CASE WHEN start_time IS NOT NULL AND end_time IS NOT NULL THEN 1 ELSE 0 END) AS scheduled_programs,
            SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) AS completed_programs
        FROM musabaqa_programs
        WHERE event_id = ?
    ");
    $stmt->execute([$eventId]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_programs' => 0,
        'scheduled_programs' => 0,
        'completed_programs' => 0
    ];

    echo json_encode([
        'ok' => true,
        'event' => [
            'id' => $eventId,
            'title' => $activeEvent['title'],
            'status' => $activeEvent['status'],
            'scoreboard_mode' => $activeEvent['scoreboard_mode'] ?? 'system'
        ],
        'metrics' => [
            'total_programs' => (int)$metrics['total_programs'],
            'scheduled_programs' => (int)$metrics['scheduled_programs'],
            'completed_programs' => (int)$metrics['completed_programs']
        ],
        'leaderboard' => $leaderboard,
        'latest_update' => $latestUpdate,
        'recent_activity' => array_map(function($act) {
            return [
                'id' => (int)$act['score_id'],
                'time' => $act['updated_at'],
                'time_formatted' => date('h:i A', strtotime($act['updated_at'])),
                'score' => round((float)$act['total_mark'], 2),
                'status' => $act['status'],
                'program_id' => (int)$act['program_id'],
                'program_title' => $act['program_title'],
                'team_name' => $act['team_name'],
                'color_code' => $act['color_code']
            ];
        }, $recentActivity),
        'timestamp' => time()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

