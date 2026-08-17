<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../live-display/includes/functions.php';

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

function schedule_sections(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [
            'morning' => 'Morning',
            'afternoon' => 'Afternoon',
            'evening' => 'Evening'
        ];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT id, name
            FROM musabaqa_schedule_sections
            WHERE event_id = ?
            ORDER BY sort_order ASC, section_date ASC, start_time ASC, id ASC
        ");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $sections = [];
            foreach ($rows as $r) {
                $sections['section_' . $r['id']] = $r['name'];
            }
            return $sections;
        }
    } catch (Throwable $e) {
        error_log('schedule_sections query failed: ' . $e->getMessage());
    }

    return [
        'morning' => 'Morning',
        'afternoon' => 'Afternoon',
        'evening' => 'Evening'
    ];
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
                p.section_id,
                st.name AS stage_name,
                ct.name AS class_name
            FROM musabaqa_programs p
            LEFT JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
            WHERE p.event_id = ? AND p.start_time IS NOT NULL AND p.stage_type_id IS NOT NULL
            ORDER BY p.start_time ASC, p.id ASC
        ");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $items = [];
        $offstageGroups = [];
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
            if ($row['section_id']) {
                $session = 'section_' . $row['section_id'];
            } elseif ($row['start_time']) {
                $hour = (int)date('H', strtotime($row['start_time']));
                if ($hour >= 12 && $hour < 16) {
                    $session = 'afternoon';
                } elseif ($hour >= 16) {
                    $session = 'evening';
                }
            }

            $isOffstage = (stripos($row['stage_name'] ?? '', 'off') !== false || stripos($row['location'] ?? '', 'off') !== false);
            $rawDate = $row['start_time'] ? date('Y-m-d', strtotime($row['start_time'])) : '1970-01-01';
            $dateFormatted = $row['start_time'] ? date('M d, Y', strtotime($row['start_time'])) : '';
            $venue = $row['location'] ?: ($row['stage_name'] ?: 'Main Venue');

            // Fetch winners & A grades for completed programs
            $results = [];
            $aGradeCount = 0;
            if ($status === 'completed' || $row['status'] === 'completed') {
                $stmtRes = $pdo->prepare("
                    SELECT pe.final_rank, pe.grade, pe.entry_name, t.team_name, t.team_color, pe.final_score
                    FROM musabaqa_program_entries pe
                    JOIN musabaqa_teams t ON t.id = pe.team_id
                    WHERE pe.program_id = ?
                      AND (pe.final_rank IS NOT NULL OR pe.grade = 'A')
                    ORDER BY pe.final_rank ASC, pe.id ASC
                ");
                $stmtRes->execute([(int)$row['id']]);
                foreach ($stmtRes->fetchAll(PDO::FETCH_ASSOC) as $resRow) {
                    if ($resRow['grade'] === 'A') {
                        $aGradeCount++;
                    }
                    if ($resRow['final_rank'] !== null && (int)$resRow['final_rank'] >= 1 && (int)$resRow['final_rank'] <= 3) {
                        $results[] = [
                            'rank' => (int)$resRow['final_rank'],
                            'entry_name' => $resRow['entry_name'],
                            'team_name' => $resRow['team_name'],
                            'team_color' => $resRow['team_color'] ?: '#64748b',
                            'grade' => $resRow['grade'],
                            'final_score' => $resRow['final_score'] !== null ? (float)$resRow['final_score'] : null
                        ];
                    }
                }
            }

            if ($isOffstage) {
                $groupKey = $startTimeStr . '_' . $dateFormatted . '_' . $session;
                if (!isset($offstageGroups[$groupKey])) {
                    $offstageGroups[$groupKey] = [
                        'id' => (int)$row['id'],
                        'start_time' => $startTimeStr,
                        'title' => 'Off-Stage Programs',
                        'category' => 'Multiple Categories',
                        'session' => $session,
                        'duration_minutes' => $duration,
                        'status' => $status,
                        'venue' => 'Various Off-Stage Venues',
                        'raw_date' => $rawDate,
                        'date' => $dateFormatted,
                        'results' => $results,
                        'a_grade_count' => $aGradeCount,
                        'is_stacked' => true,
                        'stacked_programs' => []
                    ];
                }
                $offstageGroups[$groupKey]['stacked_programs'][] = [
                    'title' => $row['title'],
                    'category' => $row['class_name'] ?: 'Open Category',
                    'venue' => $venue
                ];
                if ($duration > $offstageGroups[$groupKey]['duration_minutes']) {
                    $offstageGroups[$groupKey]['duration_minutes'] = $duration;
                }
                if ($status === 'live') {
                    $offstageGroups[$groupKey]['status'] = 'live';
                }
            } else {
                $items[] = [
                    'id' => (int)$row['id'],
                    'start_time' => $startTimeStr,
                    'title' => $row['title'],
                    'category' => $row['class_name'] ?: 'Open Category',
                    'session' => $session,
                    'duration_minutes' => $duration,
                    'status' => $status,
                    'venue' => $venue,
                    'raw_date' => $rawDate,
                    'date' => $dateFormatted,
                    'results' => $results,
                    'a_grade_count' => $aGradeCount,
                ];
            }
        }
        
        $items = array_merge($items, array_values($offstageGroups));
        
        usort($items, function($a, $b) {
            $timeA = strtotime($a['raw_date'] . ' ' . $a['start_time']);
            $timeB = strtotime($b['raw_date'] . ' ' . $b['start_time']);
            if ($timeA == $timeB) {
                return $a['id'] <=> $b['id'];
            }
            return $timeA <=> $timeB;
        });

        // Group by day and assign daily_program_no starting from 1 for each day
        $currentDayDate = null;
        $dailyCount = 0;
        foreach ($items as &$item) {
            $itemDayDate = $item['raw_date'];
            if ($itemDayDate !== $currentDayDate) {
                $currentDayDate = $itemDayDate;
                $dailyCount = 1;
            } else {
                $dailyCount++;
            }
            $item['daily_program_no'] = $dailyCount;
        }
        unset($item);

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
                t.team_color,
                p.id AS program_id,
                p.start_time AS program_start_time,
                p.title AS program_title,
                ct.name AS category_name,
                COALESCE(pe.final_score, 0) AS final_score,
                COALESCE(pe.performance_order, 1) AS performance_order
            FROM musabaqa_entry_members em
            JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
            JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
            JOIN musabaqa_teams t ON t.id = tm.team_id
            JOIN musabaqa_program_entries pe ON pe.id = em.entry_id
            JOIN musabaqa_programs p ON p.id = pe.program_id
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
            WHERE tm.event_id = :event_id";
            
    $params = ['event_id' => $eventId];
    
    if ($query !== '') {
        $sql .= " AND (s.full_name LIKE :query OR tm.chest_number LIKE :query OR p.title LIKE :query OR t.team_name LIKE :query)";
        $params['query'] = '%' . $query . '%';
    }

    $sql .= " UNION ALL

            SELECT 
                pe.id AS participant_id,
                COALESCE(NULLIF(pe.entry_name, ''), t.team_name) AS participant_name,
                '' AS participant_code,
                t.team_name,
                t.short_name AS team_slug,
                t.team_color,
                p.id AS program_id,
                p.start_time AS program_start_time,
                p.title AS program_title,
                ct.name AS category_name,
                COALESCE(pe.final_score, 0) AS final_score,
                COALESCE(pe.performance_order, 1) AS performance_order
            FROM musabaqa_program_entries pe
            JOIN musabaqa_programs p ON p.id = pe.program_id
            JOIN musabaqa_teams t ON t.id = pe.team_id
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
            LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
            WHERE pe.event_id = :event_id_2 AND em.id IS NULL";

    $params['event_id_2'] = $eventId;
    
    if ($query !== '') {
        $sql .= " AND (pe.entry_name LIKE :query_2 OR p.title LIKE :query_2 OR t.team_name LIKE :query_2)";
        $params['query_2'] = '%' . $query . '%';
    }
    
    $sql .= " ORDER BY program_start_time ASC, performance_order ASC, participant_name ASC";
    
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
                'team_color' => $row['team_color'] ?: '#4ee883',
                'reporting_time' => $row['program_start_time'] ? date('H:i', strtotime($row['program_start_time'])) : '09:00',
                'program' => $row['program_title'],
                'program_id' => (int)($row['program_id'] ?? 0),
                'category' => $row['category_name'] ?: 'Open Category',
                'order' => (int)($row['performance_order'] ?? 1),
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
                COALESCE(
                    NULLIF(GROUP_CONCAT(tm.chest_number ORDER BY tm.chest_number ASC SEPARATOR ', '), ''),
                    (SELECT tm2.chest_number 
                     FROM musabaqa_team_members tm2 
                     JOIN " . DB_MAIN_NAME . ".students s2 ON s2.id = tm2.student_id 
                     WHERE tm2.team_id = pe.team_id AND (s2.full_name = pe.entry_name OR s2.display_name = pe.entry_name) AND tm2.chest_number IS NOT NULL AND tm2.chest_number <> '' LIMIT 1)
                ) AS participant_code,
                p.title AS program_title,
                ct.name AS category_name,
                t.team_name,
                t.short_name AS team_slug,
                COALESCE(pe.final_score, 0) AS final_score,
                COALESCE(pe.team_score, 0) AS team_score,
                pe.final_rank
            FROM musabaqa_program_entries pe
            JOIN musabaqa_programs p ON p.id = pe.program_id
            JOIN musabaqa_teams t ON t.id = pe.team_id
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = p.class_type_id
            LEFT JOIN musabaqa_entry_members em ON em.entry_id = pe.id
            LEFT JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
            LEFT JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
            WHERE pe.event_id = :event_id
              AND (p.status = 'completed' OR p.approval_status = 'approved')
              AND (pe.final_rank IS NOT NULL OR pe.final_score > 0)
            GROUP BY pe.id, p.title, ct.name, t.team_name, t.short_name, pe.final_score, pe.team_score, pe.final_rank, p.reviewed_at, p.end_time, p.created_at
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
                'team_score' => (int)$row['team_score'],
                'position' => (int)($row['final_rank'] ?: 1),
            ];
        }
        return $items;
    } catch (Throwable $e) {
        error_log('result_items query failed: ' . $e->getMessage());
        return [];
    }
}

function working_committee(): array
{
    $eventId = tv_active_event_id();
    $pdo = $GLOBALS['musabaqa_pdo'];
    $dashboardPdo = $GLOBALS['dashboard_pdo'];
    
    try {
        if ($eventId > 0) {
            $stmt = $pdo->prepare("
                SELECT tt.id, tt.role, t.id as teacher_id, t.full_name, t.place, t.specialisation, u.profile_photo
                FROM musabaqa_team_teachers tt
                JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = tt.teacher_id
                LEFT JOIN " . DB_MAIN_NAME . ".users u ON u.id = t.user_id
                WHERE tt.event_id = ? AND tt.team_id = 0 AND t.status = 'active'
                ORDER BY tt.id ASC
            ");
            $stmt->execute([$eventId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            
            if (!empty($rows)) {
                $items = [];
                foreach ($rows as $r) {
                    $roleName = ucwords(str_replace('_', ' ', $r['role'] ?: ($r['specialisation'] ?: 'Working Committee')));
                    
                    // Map official photos from daruliftakauzariyya.com
                    $image = '';
                    if (!empty($r['profile_photo'])) {
                        $image = $r['profile_photo'];
                    } elseif (stripos($r['full_name'], 'Ilyas') !== false) {
                        $image = 'https://daruliftakauzariyya.com/team-photos/Usthad-Ilyas.png';
                    } elseif (stripos($r['full_name'], 'Abid') !== false) {
                        $image = 'https://daruliftakauzariyya.com/team-photos/Abid.png';
                    } elseif (stripos($r['full_name'], 'Answaf') !== false) {
                        $image = asset_url('images/committee/ansaf.png');
                    } elseif (stripos($r['full_name'], 'Adhil') !== false) {
                        $image = asset_url('images/committee/adil.png');
                    } else {
                        $cleanName = trim(preg_replace('/^(Usthad|Mufti|Al|Moulana)\s+/i', '', $r['full_name']));
                        $image = 'https://ui-avatars.com/api/?name=' . urlencode($cleanName) . '&background=073a69&color=c9a84c&size=512&font-size=0.42&bold=true';
                    }

                    $items[] = [
                        'id' => (int)$r['id'],
                        'name' => $r['full_name'],
                        'role' => $roleName,
                        'place' => $r['place'] ?: '',
                        'image' => $image,
                    ];
                }
                return $items;
            }
        }

        // Fallback query to active teachers from teachers table if no team_teachers assigned
        $stmt2 = $dashboardPdo->prepare("
            SELECT t.id, t.full_name, t.place, t.specialisation, u.profile_photo
            FROM teachers t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.status = 'active' AND (t.specialisation IS NOT NULL OR t.full_name IS NOT NULL)
            ORDER BY t.id ASC
        ");
        $stmt2->execute();
        $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $items = [];
        foreach ($rows2 as $r) {
            $items[] = [
                'id' => (int)$r['id'],
                'name' => $r['full_name'],
                'role' => $r['specialisation'] ?: 'Working Committee Member',
                'place' => $r['place'] ?: '',
                'image' => !empty($r['profile_photo']) ? $r['profile_photo'] : 'https://ui-avatars.com/api/?name=' . urlencode($r['full_name']) . '&background=1b4332&color=fff&size=512',
            ];
        }
        return $items;
    } catch (Throwable $e) {
        error_log('working_committee query failed: ' . $e->getMessage());
        return [];
    }
}

function venues_data(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT location 
            FROM musabaqa_programs 
            WHERE event_id = ? AND location IS NOT NULL AND location != ''
            ORDER BY location ASC
        ");
        $stmt->execute([$eventId]);
        $locations = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $venues = [];
        foreach ($locations as $loc) {
            // Count total programs
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) 
                FROM musabaqa_programs 
                WHERE event_id = ? AND location = ?
            ");
            $stmtCount->execute([$eventId, $loc]);
            $count = (int)$stmtCount->fetchColumn();

            // Find current/next active program
            $stmtNext = $pdo->prepare("
                SELECT title, status, start_time 
                FROM musabaqa_programs 
                WHERE event_id = ? AND location = ? AND status != 'completed'
                ORDER BY start_time ASC, id ASC
                LIMIT 1
            ");
            $stmtNext->execute([$eventId, $loc]);
            $nextProgram = $stmtNext->fetch(PDO::FETCH_ASSOC);

            // Fetch last completed program
            $stmtLast = $pdo->prepare("
                SELECT title 
                FROM musabaqa_programs 
                WHERE event_id = ? AND location = ? AND status = 'completed'
                ORDER BY end_time DESC, id DESC
                LIMIT 1
            ");
            $stmtLast->execute([$eventId, $loc]);
            $lastProgram = $stmtLast->fetchColumn();

            $venues[] = [
                'name' => $loc,
                'count' => $count,
                'next_program' => $nextProgram ? $nextProgram['title'] : null,
                'next_program_status' => $nextProgram ? $nextProgram['status'] : null,
                'last_program' => $lastProgram ?: null
            ];
        }
        return $venues;
    } catch (Throwable $e) {
        error_log('venues_data query failed: ' . $e->getMessage());
        return [];
    }
}

function plan_programs(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT p.title, st.category
            FROM musabaqa_programs p
            JOIN musabaqa_stage_types st ON st.id = p.stage_type_id
            WHERE p.event_id = ?
            ORDER BY st.category DESC, p.id ASC
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('plan_programs query failed: ' . $e->getMessage());
        return [];
    }
}

function all_students(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(NULLIF(s.display_name, ''), s.full_name) AS name,
                tm.chest_number AS code,
                t.team_name,
                c.name AS class_name,
                ct.name AS category_name
            FROM musabaqa_team_members tm
            JOIN musabaqa_teams t ON t.id = tm.team_id
            JOIN " . DB_MAIN_NAME . ".students s ON s.id = tm.student_id
            LEFT JOIN " . DB_MAIN_NAME . ".classes c ON c.id = s.class_id
            LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = c.class_type_id
            WHERE tm.event_id = ?
            ORDER BY s.full_name ASC
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('all_students query failed: ' . $e->getMessage());
        return [];
    }
}

function get_schedule_sections(): array
{
    $eventId = tv_active_event_id();
    if ($eventId <= 0) {
        return [];
    }

    $pdo = $GLOBALS['musabaqa_pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, start_time, end_time
            FROM musabaqa_schedule_sections
            WHERE event_id = ?
            ORDER BY sort_order ASC, section_date ASC, start_time ASC, id ASC
        ");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $sections = [];
        foreach ($rows as $row) {
            $startTime = $row['start_time'] ? date('g:i A', strtotime($row['start_time'])) : '';
            $endTime = $row['end_time'] ? date('g:i A', strtotime($row['end_time'])) : '';
            $timeRange = '';
            if ($startTime && $endTime) {
                $timeRange = $startTime . ' - ' . $endTime;
            } elseif ($startTime) {
                $timeRange = $startTime;
            }
            
            $sections[] = [
                'id' => 'section_' . $row['id'],
                'name' => $row['name'],
                'time' => $timeRange
            ];
        }
        return $sections;
    } catch (Throwable $e) {
        error_log('get_schedule_sections failed: ' . $e->getMessage());
        return [];
    }
}


