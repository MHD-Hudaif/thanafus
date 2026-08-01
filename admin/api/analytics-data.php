<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/event-guard.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();

    $pdo = $GLOBALS['musabaqa_pdo'];
    $activeEvent = get_active_musabaqa();

    if (!$activeEvent) {
        echo json_encode([
            'success' => false,
            'message' => 'No active event detected.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $eventId = (int)$activeEvent['id'];

    // 1. Team Standings with Scores & Colors
    $teamStmt = $pdo->prepare("
        SELECT 
            t.id AS team_id,
            t.team_name,
            t.team_color,
            COALESCE(SUM(ss.final_total), 0) AS total_score,
            COUNT(DISTINCT pe.id) AS total_entries,
            COUNT(DISTINCT CASE WHEN ss.status = 'completed' THEN pe.id END) AS completed_entries
        FROM musabaqa_teams t
        LEFT JOIN musabaqa_program_entries pe ON pe.team_id = t.id AND pe.event_id = ?
        LEFT JOIN musabaqa_score_sheets ss ON ss.entry_id = pe.id
        WHERE t.event_id = ?
        GROUP BY t.id, t.team_name, t.team_color
        ORDER BY total_score DESC, t.team_name ASC
    ");
    $teamStmt->execute([$eventId, $eventId]);
    $teamStandings = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Program Completion Breakdown
    $progStmt = $pdo->prepare("
        SELECT 
            COALESCE(p.approval_status, 'scheduled') AS status,
            COUNT(*) AS total_count
        FROM musabaqa_programs p
        WHERE p.event_id = ?
        GROUP BY COALESCE(p.approval_status, 'scheduled')
    ");
    $progStmt->execute([$eventId]);
    $progStatusRows = $progStmt->fetchAll(PDO::FETCH_ASSOC);

    $progStatusMap = [
        'scheduled' => 0,
        'scoring' => 0,
        'submitted' => 0,
        'approved' => 0
    ];
    $totalPrograms = 0;
    foreach ($progStatusRows as $row) {
        $statusKey = (string)$row['status'];
        $count = (int)$row['total_count'];
        $progStatusMap[$statusKey] = ($progStatusMap[$statusKey] ?? 0) + $count;
        $totalPrograms += $count;
    }

    // 3. Top High Scoring Entries
    $topEntriesStmt = $pdo->prepare("
        SELECT 
            pe.id AS entry_id,
            pe.entry_number,
            pe.entry_name,
            p.title AS program_title,
            t.team_name,
            t.team_color,
            ss.final_total
        FROM musabaqa_score_sheets ss
        JOIN musabaqa_program_entries pe ON pe.id = ss.entry_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.event_id = ? AND ss.final_total > 0
        ORDER BY ss.final_total DESC
        LIMIT 6
    ");
    $topEntriesStmt->execute([$eventId]);
    $topEntries = $topEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Category Score Aggregation
    $catStmt = $pdo->prepare("
        SELECT 
            pc.name AS category_name,
            COUNT(cs.id) AS total_evaluations,
            COALESCE(AVG(cs.score), 0) AS avg_score,
            COALESCE(MAX(cs.score), 0) AS max_score
        FROM musabaqa_program_categories pc
        JOIN musabaqa_category_scores cs ON cs.category_id = pc.id
        JOIN musabaqa_programs p ON p.id = pc.program_id
        WHERE p.event_id = ?
        GROUP BY pc.id, pc.name
        ORDER BY avg_score DESC
        LIMIT 8
    ");
    $catStmt->execute([$eventId]);
    $categoryStats = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Total Metrics
    $totalPoints = array_sum(array_column($teamStandings, 'total_score'));
    $topTeam = $teamStandings[0]['team_name'] ?? 'N/A';
    $topTeamColor = $teamStandings[0]['team_color'] ?? '#10b981';
    
    $completedCount = ($progStatusMap['approved'] ?? 0) + ($progStatusMap['submitted'] ?? 0);
    $completionRate = $totalPrograms > 0 ? round(($completedCount / $totalPrograms) * 100, 1) : 0;

    echo json_encode([
        'success' => true,
        'timestamp' => date('h:i:s A'),
        'metrics' => [
            'total_points' => number_format((float)$totalPoints, 1),
            'total_programs' => $totalPrograms,
            'completion_rate' => $completionRate,
            'top_team' => $topTeam,
            'top_team_color' => $topTeamColor
        ],
        'team_standings' => array_map(function($team) {
            return [
                'team_id' => (int)$team['team_id'],
                'team_name' => (string)$team['team_name'],
                'team_color' => (string)($team['team_color'] ?: '#6366f1'),
                'total_score' => round((float)$team['total_score'], 1),
                'total_entries' => (int)$team['total_entries'],
                'completed_entries' => (int)$team['completed_entries']
            ];
        }, $teamStandings),
        'program_status' => $progStatusMap,
        'top_entries' => array_map(function($item) {
            return [
                'entry_number' => str_pad((string)$item['entry_number'], 3, '0', STR_PAD_LEFT),
                'entry_name' => (string)($item['entry_name'] ?: 'Participant'),
                'program_title' => (string)$item['program_title'],
                'team_name' => (string)$item['team_name'],
                'team_color' => (string)($item['team_color'] ?: '#34d399'),
                'final_total' => round((float)$item['final_total'], 1)
            ];
        }, $topEntries),
        'category_stats' => array_map(function($cat) {
            return [
                'category_name' => (string)$cat['category_name'],
                'avg_score' => round((float)$cat['avg_score'], 1),
                'max_score' => round((float)$cat['max_score'], 1),
                'evaluations' => (int)$cat['total_evaluations']
            ];
        }, $categoryStats)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch analytics data: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
