<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../tv/includes/functions.php';

function teams(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $leaderboard = tv_leaderboard($eventId);
    $items = [];
    foreach ($leaderboard as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'slug' => $row['short_name'] ?: strtolower(str_replace(' ', '-', $row['team_name'])),
            'name' => $row['team_name'],
            'score' => (float)$row['total_score'],
            'color' => $row['team_color'] ?: '#00ff88',
        ];
    }
    return $items;
}

function schedule_items(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.title,
                p.start_time,
                p.end_time,
                p.status,
                p.location,
                st.name AS stage_name,
                ct.name AS class_name
            FROM musabaqa_programs p
            LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
            LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
            WHERE p.event_id = ?
            ORDER BY p.start_time ASC, p.id ASC
        ");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $items = [];
        foreach ($rows as $row) {
            $startTimeStr = $row['start_time'] ? date('H:i', strtotime($row['start_time'])) : '09:00';
            $duration = ($row['start_time'] && $row['end_time']) 
                ? (int)round((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60) 
                : 30;
            
            $status = 'upcoming';
            if ($row['status'] === 'scoring') {
                $status = 'live';
            } elseif ($row['status'] === 'completed') {
                $status = 'completed';
            }
            
            $session = 'morning';
            if ($row['start_time']) {
                $hour = (int)date('H', strtotime($row['start_time']));
                if ($hour >= 12 && $hour < 16) {
                    $session = 'afternoon';
                } elseif ($hour >= 16) {
                    $session = 'evening';
                }
            }

            $items[] = [
                'id' => (int)$row['id'],
                'start_time' => $startTimeStr,
                'title' => $row['title'],
                'category' => $row['class_name'] ?: 'Open Category',
                'session' => $session,
                'duration_minutes' => $duration,
                'status' => $status,
                'venue' => $row['stage_name'] ?: ($row['location'] ?: 'Main Venue'),
            ];
        }
        return $items;
    } catch (Throwable $e) {
        error_log('schedule_items query failed: ' . $e->getMessage());
        return [];
    }
}

function participants(string $query = ''): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    $sql = "SELECT 
                em.id AS participant_id,
                COALESCE(NULLIF(s.display_name, ''), s.full_name) AS participant_name,
                tm.chest_number AS participant_code,
                t.team_name,
                t.short_name AS team_slug,
                p.start_time AS program_start_time,
                p.title AS program_title,
                ct.name AS category_name,
                COALESCE(pe.final_score, 0) AS final_score
            FROM musabaqa_entry_members em
            JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
            JOIN kauzariyya.students s ON s.id = tm.student_id
            JOIN musabaqa_teams t ON t.id = tm.team_id
            JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
            JOIN musabaqa_programs p ON p.id = pe.program_id
            LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
            WHERE tm.event_id = :event_id";
            
    $params = ['event_id' => $eventId];
    
    if ($query !== '') {
        $sql .= " AND (s.full_name LIKE :query OR tm.chest_number LIKE :query)";
        $params['query'] = '%' . $query . '%';
    }
    
    $sql .= " ORDER BY p.start_time ASC, s.full_name ASC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)$row['participant_id'],
                'name' => $row['participant_name'],
                'code' => $row['participant_code'] ?: '',
                'team_name' => $row['team_name'],
                'team_slug' => $row['team_slug'] ?: 'default',
                'reporting_time' => $row['program_start_time'] ? date('H:i', strtotime($row['program_start_time'])) : '09:00',
                'program' => $row['program_title'],
                'category' => $row['category_name'] ?: 'Open Category',
                'score' => (float)$row['final_score'],
            ];
        }
        return $items;
    } catch (Throwable $e) {
        error_log('participants query failed: ' . $e->getMessage());
        return [];
    }
}

function result_items(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    $sql = "SELECT 
                pe.id AS result_id,
                COALESCE(NULLIF(pe.entry_name, ''), GROUP_CONCAT(COALESCE(NULLIF(s.display_name, ''), s.full_name) ORDER BY s.full_name ASC SEPARATOR ', ')) AS participant_name,
                GROUP_CONCAT(tm.chest_number ORDER BY tm.chest_number ASC SEPARATOR ', ') AS participant_code,
                p.title AS program_title,
                ct.name AS category_name,
                t.team_name,
                t.short_name AS team_slug,
                COALESCE(pe.final_score, 0) AS final_score,
                pe.final_rank
            FROM musabaqa_program_entries pe
            JOIN musabaqa_programs p ON p.id = pe.program_id
            JOIN musabaqa_teams t ON t.id = pe.team_id
            LEFT JOIN kauzariyya.class_types ct ON ct.id = p.class_type_id
            LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
            LEFT JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
            LEFT JOIN kauzariyya.students s ON s.id = tm.student_id
            WHERE pe.event_id = :event_id
              AND (p.status = 'completed' OR p.approval_status = 'approved')
              AND (p.disable_scores IS NULL OR p.disable_scores = 0)
              AND (pe.final_rank IS NOT NULL OR pe.final_score > 0)
            GROUP BY pe.id, p.title, ct.name, t.team_name, t.short_name, pe.final_score, pe.final_rank, p.reviewed_at, p.end_time, p.created_at
            ORDER BY COALESCE(p.reviewed_at, p.end_time, p.created_at) DESC, pe.final_rank ASC, pe.final_score DESC";
            
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['event_id' => $eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)$row['result_id'],
                'participant' => $row['participant_name'] ?: 'Team Event',
                'code' => $row['participant_code'] ?: '',
                'program' => $row['program_title'],
                'category' => $row['category_name'] ?: 'Open Category',
                'team_name' => $row['team_name'],
                'team_slug' => $row['team_slug'] ?: 'default',
                'score' => (float)$row['final_score'],
                'position' => (int)($row['final_rank'] ?: 1),
            ];
        }
        return $items;
    } catch (Throwable $e) {
        error_log('result_items query failed: ' . $e->getMessage());
        return [];
    }
}
