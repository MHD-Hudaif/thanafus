<?php
$pageTitle = 'Schedule';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// AJAX request handler (for session drag-and-drop)
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Security token expired. Please refresh the page.');
        }
        $action = (string)($_POST['action'] ?? '');
        
        if ($action === 'assign_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            $sectionId = (int)($_POST['section_id'] ?? 0);
            if ($programId > 0 && $sectionId > 0) {
                $stmt = $pdo->prepare("UPDATE musabaqa_programs SET section_id = ? WHERE id = ? AND event_id = ?");
                $stmt->execute([$sectionId, $programId, $activeEventId]);
                
                // Fetch program details for return payload
                $progStmt = $pdo->prepare("
                    SELECT mp.id, mp.title, mp.start_time, mp.end_time, mst.name AS stage_type_name,
                           ct.name AS class_type_name
                    FROM musabaqa_programs mp
                    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
                    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
                    WHERE mp.id = ?
                ");
                $progStmt->execute([$programId]);
                $prog = $progStmt->fetch(PDO::FETCH_ASSOC);
                
                $duration = 0;
                if ($prog && $prog['start_time'] && $prog['end_time']) {
                    $pStart = new DateTime($prog['start_time']);
                    $pEnd = new DateTime($prog['end_time']);
                    $duration = (int)(($pEnd->getTimestamp() - $pStart->getTimestamp()) / 60);
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Program successfully assigned.',
                    'program' => [
                        'id' => (int)$prog['id'],
                        'title' => $prog['title'],
                        'duration' => $duration,
                        'stage' => $prog['stage_type_name'] ?: 'TBD',
                        'time' => $prog['start_time'] ? date('h:i A', strtotime($prog['start_time'])) : null,
                        'start_time' => $prog['start_time'],
                        'class_tier' => admin_class_type_tier_from_name($prog['class_type_name'] ?? '')
                    ]
                ]);
            } else {
                throw new RuntimeException('Invalid program or session.');
            }
        } elseif ($action === 'unassign_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            if ($programId > 0) {
                $progStmt = $pdo->prepare("
                    SELECT mp.id, mp.title, mp.start_time, mp.end_time, mst.name AS stage_type_name,
                           ct.name AS class_type_name
                    FROM musabaqa_programs mp
                    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
                    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
                    WHERE mp.id = ?
                ");
                $progStmt->execute([$programId]);
                $prog = $progStmt->fetch(PDO::FETCH_ASSOC);
                
                $duration = 0;
                if ($prog && $prog['start_time'] && $prog['end_time']) {
                    $pStart = new DateTime($prog['start_time']);
                    $pEnd = new DateTime($prog['end_time']);
                    $duration = (int)(($pEnd->getTimestamp() - $pStart->getTimestamp()) / 60);
                }

                $stmt = $pdo->prepare("UPDATE musabaqa_programs SET section_id = NULL WHERE id = ? AND event_id = ?");
                $stmt->execute([$programId, $activeEventId]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Program successfully unassigned.',
                    'program' => [
                        'id' => (int)$prog['id'],
                        'title' => $prog['title'],
                        'duration' => $duration,
                        'stage' => $prog['stage_type_name'] ?: 'TBD',
                        'time' => $prog['start_time'] ? date('h:i A', strtotime($prog['start_time'])) : null,
                        'start_time' => $prog['start_time'],
                        'class_tier' => admin_class_type_tier_from_name($prog['class_type_name'] ?? '')
                    ]
                ]);
            } else {
                throw new RuntimeException('Invalid program.');
            }
        } else {
            throw new RuntimeException('Invalid AJAX action.');
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function validate_session_no_program_overflow(
    PDO $pdo,
    int $sectionId,       // 0 means 'add' — skip check
    string $sectionDate,
    string $startTime,    // HH:MM or HH:MM:SS
    string $endTime,
    int $eventId
): void {
    if ($sectionId <= 0) {
        return; // New section — no programs assigned yet
    }

    $sesStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sectionDate . ' ' . $startTime)
             ?: DateTimeImmutable::createFromFormat('Y-m-d H:i',   $sectionDate . ' ' . $startTime);
    $sesEnd   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sectionDate . ' ' . $endTime)
             ?: DateTimeImmutable::createFromFormat('Y-m-d H:i',   $sectionDate . ' ' . $endTime);

    if (!$sesStart || !$sesEnd) {
        return;
    }

    if ($sesEnd < $sesStart) {
        $sesEnd = $sesEnd->modify('+1 day');
    }

    // Find all programs currently assigned to this section
    $stmt = $pdo->prepare("
        SELECT id, title, start_time, end_time
        FROM musabaqa_programs
        WHERE section_id = ?
          AND event_id = ?
          AND start_time IS NOT NULL
          AND end_time IS NOT NULL
    ");
    $stmt->execute([$sectionId, $eventId]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $toUnassignIds = [];

    foreach ($programs as $prog) {
        $pStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $prog['start_time'])
               ?: DateTimeImmutable::createFromFormat('Y-m-d H:i',   $prog['start_time']);
        $pEnd   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $prog['end_time'])
               ?: DateTimeImmutable::createFromFormat('Y-m-d H:i',   $prog['end_time']);

        if (!$pStart || !$pEnd) {
            continue;
        }

        // Check if completely outside
        $isCompletelyOutside = ($pEnd <= $sesStart || $pStart >= $sesEnd);
        
        // Check if completely inside
        $isCompletelyInside = ($pStart >= $sesStart && $pEnd <= $sesEnd);

        if ($isCompletelyOutside) {
            $toUnassignIds[] = (int)$prog['id'];
        } elseif (!$isCompletelyInside) {
            $fmtProg = date('h:i A', $pStart->getTimestamp()) . '–' . date('h:i A', $pEnd->getTimestamp());
            $fmtSes  = date('h:i A', $sesStart->getTimestamp()) . '–' . date('h:i A', $sesEnd->getTimestamp());
            throw new RuntimeException(
                "Cannot update session: \"{$prog['title']}\" ({$fmtProg}) would partially overlap the new window ({$fmtSes}). " .
                "Reschedule or unschedule the program first."
            );
        }
    }

    // Auto-unassign completely outside programs so they can be reassigned
    if (!empty($toUnassignIds)) {
        $placeholders = implode(',', array_fill(0, count($toUnassignIds), '?'));
        $unassignStmt = $pdo->prepare("
            UPDATE musabaqa_programs
            SET section_id = NULL
            WHERE id IN ($placeholders)
        ");
        $unassignStmt->execute($toUnassignIds);
    }
}

/**
 * Calculates the total allocated minutes of a session by merging overlapping program intervals.
 */
function calculate_session_allocated_minutes(array $assignedProgs): int
{
    $intervals = [];
    foreach ($assignedProgs as $prog) {
        if (!empty($prog['start_time']) && !empty($prog['end_time'])) {
            $start = strtotime($prog['start_time']);
            $end = strtotime($prog['end_time']);
            if ($start < $end) {
                $intervals[] = ['start' => $start, 'end' => $end];
            }
        }
    }
    
    if (empty($intervals)) {
        return 0;
    }
    
    // Sort intervals by start time
    usort($intervals, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });
    
    $merged = [];
    $current = $intervals[0];
    
    for ($i = 1, $count = count($intervals); $i < $count; $i++) {
        $next = $intervals[$i];
        if ($next['start'] <= $current['end']) {
            $current['end'] = max($current['end'], $next['end']);
        } else {
            $merged[] = $current;
            $current = $next;
        }
    }
    $merged[] = $current;
    
    $totalMinutes = 0;
    foreach ($merged as $interval) {
        $totalMinutes += (int)(($interval['end'] - $interval['start']) / 60);
    }
    
    return $totalMinutes;
}

function schedule_redirect(int $stageTypeId = 0): void
{
    $query = $stageTypeId > 0 ? ['stage_id' => $stageTypeId] : [];
    admin_redirect('/admin/event-manager/schedule', $query);
}

function schedule_program_datetime_columns(PDO $pdo): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM musabaqa_programs")->fetchAll(PDO::FETCH_COLUMN);
        $available = array_map('strtolower', $cols);

        $start = in_array('start_datetime', $available, true) ? 'start_datetime' : 'start_time';
        $end = in_array('end_datetime', $available, true) ? 'end_datetime' : 'end_time';
        return $columns = [$start, $end];
    } catch (Throwable $e) {
        return $columns = ['start_time', 'end_time'];
    }
}

function schedule_load_program(PDO $pdo, int $eventId, int $programId): ?array
{
    [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
    $stmt = $pdo->prepare("
        SELECT id, title, stage_type_id, {$startExpr} AS start_at, {$endExpr} AS end_at
        FROM musabaqa_programs
        WHERE id = ?
          AND event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$programId, $eventId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    return $program ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        schedule_redirect((int)($_POST['stage_type_id'] ?? 0));
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'schedule_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            $stageTypeId = (int)($_POST['stage_type_id'] ?? 0);
            $location = trim((string)($_POST['location'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $durationMinutes = (int)($_POST['duration_minutes'] ?? 0);

            if ($programId <= 0 || $stageTypeId <= 0 || $startTime === '') {
                throw new RuntimeException('Program, Stage, and Start Time are required.');
            }

            $startDt = new DateTime($startTime);
            if ($durationMinutes > 0) {
                $endDt = clone $startDt;
                $endDt->modify("+{$durationMinutes} minutes");
                $endTime = $endDt->format('Y-m-d H:i:s');
            } elseif ($endTime !== '') {
                $endDt = new DateTime($endTime);
            } else {
                throw new RuntimeException('Either duration or end time must be specified.');
            }

            if ($endDt <= $startDt) {
                throw new RuntimeException('End time must be after start time.');
            }

            $startSql = $startDt->format('Y-m-d H:i:s');
            $endSql = $endDt->format('Y-m-d H:i:s');

            // Always resolve column names (needed for UPDATE regardless of stage type)
            [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);

            // Overlap check (Bypassed for Off Stage so programs can run concurrently)
            $stageStmt = $pdo->prepare("SELECT category FROM musabaqa_stage_types WHERE id = ?");
            $stageStmt->execute([$stageTypeId]);
            $stageCategory = (string)$stageStmt->fetchColumn();
            $isOffStage = ($stageCategory === 'off_stage');

            if (!$isOffStage) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM musabaqa_programs
                    WHERE event_id = ?
                      AND id <> ?
                      AND stage_type_id = ?
                      AND {$startExpr} IS NOT NULL
                      AND {$endExpr} IS NOT NULL
                      AND {$startExpr} < ?
                      AND {$endExpr} > ?
                    LIMIT 1
                ");
                $stmt->execute([
                    $activeEventId,
                    $programId,
                    $stageTypeId,
                    $endSql,
                    $startSql
                ]);

                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('Another program already exists during this time on the same stage.');
                }
            }

            // Auto-detect section for this program
            $matchedSectionId = null;
            $progDate = date('Y-m-d', strtotime($startSql));
            
            $secStmt = $pdo->prepare("SELECT * FROM musabaqa_schedule_sections WHERE event_id = ? ORDER BY section_date ASC, start_time ASC, sort_order ASC");
            $secStmt->execute([$activeEventId]);
            $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $tvTimeInRange = static function(string $timeStr, string $start, string $end): bool {
                $time = date('H:i:s', strtotime($timeStr));
                if ($start <= $end) {
                    return $time >= $start && $time <= $end;
                } else {
                    return $time >= $start || $time <= $end;
                }
            };
            
            foreach ($sections as $sec) {
                if (!empty($sec['section_date']) && $sec['section_date'] !== $progDate) {
                    continue;
                }
                if ($tvTimeInRange($startSql, $sec['start_time'], $sec['end_time'])) {
                    $matchedSectionId = (int)$sec['id'];
                    break;
                }
            }

            // ------------------------------------------------------------------
            // Session-window enforcement:
            // If sessions exist for the program's date, the program's full time
            // window must fit entirely within a matched session.  Programs are
            // NOT allowed to span session boundaries.
            // ------------------------------------------------------------------
            $sessionsOnDate = array_filter($sections, fn($s) => ($s['section_date'] ?? '') === $progDate);

            if (!$isOffStage && !empty($sessionsOnDate)) {
                if ($matchedSectionId === null) {
                    // No session covers the start time — find closest for a helpful message
                    $sessionNames = array_map(fn($s) => '"' . $s['name'] . '" (' .
                        date('h:i A', strtotime($s['start_time'])) . '–' . date('h:i A', strtotime($s['end_time'])) . ')',
                        array_values($sessionsOnDate)
                    );
                    throw new RuntimeException(
                        'No session covers ' . date('h:i A', strtotime($startSql)) . ' on ' . date('D, d M Y', strtotime($progDate)) . '. ' .
                        'Available sessions: ' . implode(', ', $sessionNames) . '. ' .
                        'Create a session that includes this time, or adjust the program time.'
                    );
                }

                // Also verify the END time fits within the matched session
                $matchedSec = null;
                foreach ($sections as $sec) {
                    if ((int)$sec['id'] === $matchedSectionId) {
                        $matchedSec = $sec;
                        break;
                    }
                }
                if ($matchedSec) {
                    $sesStartDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $matchedSec['section_date'] . ' ' . $matchedSec['start_time'])
                               ?: DateTimeImmutable::createFromFormat('Y-m-d H:i',   $matchedSec['section_date'] . ' ' . $matchedSec['start_time']);
                    $sesEndDt   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $matchedSec['section_date'] . ' ' . $matchedSec['end_time'])
                               ?: DateTimeImmutable::createFromFormat('Y-m-d H:i',   $matchedSec['section_date'] . ' ' . $matchedSec['end_time']);
                    
                    if ($sesStartDt && $sesEndDt) {
                        if ($sesEndDt < $sesStartDt) {
                            $sesEndDt = $sesEndDt->modify('+1 day');
                        }
                        $sesEndFull = $sesEndDt->format('Y-m-d H:i:s');
                        if ($endSql > $sesEndFull) {
                            throw new RuntimeException(
                                'Program end time ' . date('h:i A', strtotime($endSql)) . ' exceeds session "' . $matchedSec['name'] . '" end (' .
                                date('h:i A', $sesEndDt->getTimestamp()) . '). ' .
                                'Shorten the program or extend the session.'
                            );
                        }
                    }
                }
            }

            // Save schedule
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET stage_type_id = ?, location = ?, {$startExpr} = ?, {$endExpr} = ?, section_id = ?
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([
                $stageTypeId,
                $location ?: null,
                $startSql,
                $endSql,
                $matchedSectionId,
                $programId,
                $activeEventId
            ]);

            admin_log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), $activeEventId, 'schedule_program', 'musabaqa_programs', $programId, 'Scheduled program.');
            admin_flash('success', 'Program scheduled successfully.');


        } elseif ($action === 'unschedule_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            if ($programId <= 0) {
                throw new RuntimeException('Invalid program ID.');
            }

            [$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);
            $stmt = $pdo->prepare("
                UPDATE musabaqa_programs
                SET stage_type_id = NULL, location = NULL, {$startExpr} = NULL, {$endExpr} = NULL
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([$programId, $activeEventId]);

            admin_log_activity($pdo, (int)($_SESSION['user_id'] ?? 0), $activeEventId, 'unschedule_program', 'musabaqa_programs', $programId, 'Unscheduled program.');
            admin_flash('success', 'Program unscheduled.');
        } elseif ($action === 'add_session') {
            $name = trim((string)($_POST['name'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $sectionDate = trim((string)($_POST['section_date'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Session name is required.');
            }
            if ($startTime === '' || $endTime === '') {
                throw new RuntimeException('Start time and end time are required.');
            }
            if ($sectionDate === '') {
                throw new RuntimeException('Session date is required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_schedule_sections (event_id, name, start_time, end_time, section_date, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $activeEventId,
                $name,
                $startTime,
                $endTime,
                $sectionDate,
                $sortOrder
            ]);
            admin_auto_assign_programs_to_sections($pdo, $activeEventId);
            admin_flash('success', 'Session added successfully.');
        } elseif ($action === 'update_session') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $sectionDate = trim((string)($_POST['section_date'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Session name is required.');
            }
            if ($startTime === '' || $endTime === '') {
                throw new RuntimeException('Start time and end time are required.');
            }
            if ($sectionDate === '') {
                throw new RuntimeException('Session date is required.');
            }

            // Prevent narrowing the window when scheduled programs would overflow
            validate_session_no_program_overflow($pdo, $sectionId, $sectionDate, $startTime, $endTime, $activeEventId);

            $stmt = $pdo->prepare("
                UPDATE musabaqa_schedule_sections
                SET name = ?, start_time = ?, end_time = ?, section_date = ?, sort_order = ?
                WHERE id = ? AND event_id = ?
            ");
            $stmt->execute([
                $name,
                $startTime,
                $endTime,
                $sectionDate,
                $sortOrder,
                $sectionId,
                $activeEventId
            ]);
            admin_auto_assign_programs_to_sections($pdo, $activeEventId);
            admin_flash('success', 'Session updated successfully.');
        } elseif ($action === 'delete_session') {
            $sectionId = (int)($_POST['section_id'] ?? 0);

            if ($sectionId > 0) {
                // Check for programs with explicit schedule times — those must be rescheduled first
                $scheduledStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM musabaqa_programs
                    WHERE section_id = ? AND event_id = ? AND start_time IS NOT NULL AND end_time IS NOT NULL
                ");
                $scheduledStmt->execute([$sectionId, $activeEventId]);
                $scheduledCount = (int)$scheduledStmt->fetchColumn();

                if ($scheduledCount > 0) {
                    throw new RuntimeException(
                        "Cannot delete session: {$scheduledCount} program(s) have times scheduled within it. " .
                        "Unschedule those programs first (in the Schedule page), then delete the session."
                    );
                }

                admin_db_transaction($pdo, function ($pdo) use ($sectionId, $activeEventId) {
                    $stmt = $pdo->prepare("UPDATE musabaqa_programs SET section_id = NULL WHERE section_id = ? AND event_id = ?");
                    $stmt->execute([$sectionId, $activeEventId]);

                    $stmt = $pdo->prepare("DELETE FROM musabaqa_schedule_sections WHERE id = ? AND event_id = ?");
                    $stmt->execute([$sectionId, $activeEventId]);
                });

                admin_flash('success', 'Session removed.');
            } else {
                throw new RuntimeException('Invalid session ID for deletion.');
            }
        } elseif ($action === 'generate_defaults_sessions') {
            $startDateStr = $activeEvent['start_date'] ?? null;
            $endDateStr = $activeEvent['end_date'] ?? null;

            if (!$startDateStr || !$endDateStr) {
                throw new RuntimeException('Event start date and end date must be set before generating default sessions.');
            }

            $defaults = [
                ['Morning', '08:00:00', '13:00:00', 1],
                ['Evening', '14:00:00', '18:00:00', 2],
                ['Night', '19:30:00', '23:30:00', 3]
            ];

            admin_db_transaction($pdo, function ($pdo) use ($activeEventId, $startDateStr, $endDateStr, $defaults) {
                $pdo->prepare("DELETE FROM musabaqa_schedule_sections WHERE event_id = ?")->execute([$activeEventId]);

                $ins = $pdo->prepare("
                    INSERT INTO musabaqa_schedule_sections (event_id, name, start_time, end_time, section_date, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $start = new DateTime($startDateStr);
                $end = new DateTime($endDateStr);
                $end->modify('+1 day');
                $interval = new DateInterval('P1D');
                $period = new DatePeriod($start, $interval, $end);

                $dayNum = 1;
                foreach ($period as $dt) {
                    $dateSql = $dt->format('Y-m-d');
                    $dayLabel = "Day " . $dayNum;
                    foreach ($defaults as $def) {
                        $name = $dayLabel . " - " . $def[0];
                        $ins->execute([$activeEventId, $name, $def[1], $def[2], $dateSql, ($dayNum - 1) * 10 + $def[3]]);
                    }
                    $dayNum++;
                }

                admin_auto_assign_programs_to_sections($pdo, $activeEventId);
            });

            admin_flash('success', 'Default sessions (Morning, Evening, Night) generated and programs auto-assigned.');
        } elseif ($action === 'auto_assign_sessions') {
            $count = admin_auto_assign_programs_to_sections($pdo, $activeEventId);
            admin_flash('success', "Auto-assignment completed. {$count} program(s) matched.");
        } else {
            throw new RuntimeException('Invalid schedule action.');
        }
    } catch (Throwable $e) {
        admin_flash('error', $e->getMessage() ?: 'Unable to update schedule.');
    }

    schedule_redirect((int)($_POST['stage_type_id'] ?? 0));
}

$flash = admin_take_flash();
[$startExpr, $endExpr] = schedule_program_datetime_columns($pdo);

$stageTypes = $pdo->query('SELECT id, name, category FROM musabaqa_stage_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$activeStageId = (int)($_GET['stage_id'] ?? ($stageTypes[0]['id'] ?? 0));
$classFilter = trim((string)($_GET['class'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));

$programWhere = "
    WHERE mp.event_id = ?
      AND mp.stage_type_id IS NOT NULL
      AND mp.{$startExpr} IS NOT NULL
      AND mp.{$endExpr} IS NOT NULL
";
$programParams = [$activeEventId];
if ($search !== '') {
    $programWhere .= ' AND (mp.title LIKE ? OR mp.location LIKE ?)';
    $like = '%' . $search . '%';
    $programParams[] = $like;
    $programParams[] = $like;
}
[$classSql, $classParams] = admin_program_class_filter_sql($dashboardPdo, $classFilter, 'mp');
$classTypes = $dashboardPdo->query('SELECT id, name FROM class_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$classTypesMap = [];
foreach ($classTypes as $type) {
    $classTypesMap[(int)$type['id']] = $type['name'];
}

$programWhere .= $classSql;
array_push($programParams, ...$classParams);

$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.location, mp.class_type_id, ct.name AS class_type_name,
           t.full_name AS responsible_teacher_name, mp.allowed_sections, mp.stage_type_id,
           mst.category AS stage_category, mp.section_id,
           mp.{$startExpr} AS start_at, mp.{$endExpr} AS end_at
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = mp.responsible_teacher_id
    {$programWhere}
    ORDER BY mp.{$startExpr} ASC, mp.{$endExpr} ASC, mp.id ASC
");
$stmt->execute($programParams);
$allScheduledPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group programs by stage
$programsByStage = [];
foreach ($stageTypes as $st) {
    $programsByStage[(int)$st['id']] = [];
}
foreach ($allScheduledPrograms as $p) {
    $programsByStage[(int)$p['stage_type_id']][] = $p;
}



$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.program_type, mp.class_type_id, ct.name AS class_type_name,
           t.full_name AS responsible_teacher_name, mp.allowed_sections, mp.location,
           COALESCE(mp.stage_type_id, 1) AS stage_type_id,
           mst.category AS stage_category
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".teachers t ON t.id = mp.responsible_teacher_id
    WHERE mp.event_id = ?
      AND (mp.{$startExpr} IS NULL OR mp.{$endExpr} IS NULL)
    ORDER BY mp.title ASC, mp.id DESC
");
$stmt->execute([$activeEventId]);
$unscheduledPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tiers = [
    'senior' => 'Senior',
    'junior' => 'Junior',
    'subjunior' => 'Sub Junior',
    'general' => 'General / Other'
];

$unscheduledGrouped = [
    'subjunior' => [],
    'junior' => [],
    'senior' => [],
    'general' => []
];

foreach ($unscheduledPrograms as $prog) {
    $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
    
    // If the program allows multiple sections, it's considered General/Multi-Section
    $allowedCount = !empty($prog['allowed_sections']) ? count(explode(',', $prog['allowed_sections'])) : 0;
    
    if ($allowedCount > 1 || !$classTier) {
        $unscheduledGrouped['general'][] = $prog;
    } else {
        $unscheduledGrouped[$classTier][] = $prog;
    }
}

// --- SESSION MANAGEMENT DATA LOADER ---
// Automatically auto-assign scheduled programs (main stage & offstage) to matching sections
admin_auto_assign_programs_to_sections($pdo, $activeEventId);

// Load all sessions
$stmt = $pdo->prepare("
    SELECT *
    FROM musabaqa_schedule_sections
    WHERE event_id = ?
    ORDER BY section_date ASC, start_time ASC, sort_order ASC, id ASC
");
$stmt->execute([$activeEventId]);
$sessionList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all programs for assignment view
$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.section_id, mp.start_time, mp.end_time, mst.name AS stage_type_name,
           ct.name AS class_type_name
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    LEFT JOIN " . DB_MAIN_NAME . ".class_types ct ON ct.id = mp.class_type_id
    WHERE mp.event_id = ?
    ORDER BY (mp.start_time IS NULL) ASC, mp.start_time ASC, mp.title ASC
");
$stmt->execute([$activeEventId]);
$allSessionPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group programs by section
$programsBySection = [];
$sessionUnassignedPrograms = [];

foreach ($allSessionPrograms as $prog) {
    if ($prog['section_id']) {
        $programsBySection[(int)$prog['section_id']][] = $prog;
    } else {
        $sessionUnassignedPrograms[] = $prog;
    }
}

// Map dates to Days and compute counts
$eventStartStr = $activeEvent['start_date'] ?? null;
$eventStart = $eventStartStr ? new DateTime($eventStartStr) : null;

$sessionUniqueDays = [];
$sessionDayCounts = ['all' => count($sessionList), 'undated' => 0];

foreach ($sessionList as $sec) {
    if ($sec['section_date']) {
        $dateVal = $sec['section_date'];
        $sessionDayCounts[$dateVal] = ($sessionDayCounts[$dateVal] ?? 0) + 1;
        if ($eventStart) {
            $secDate = new DateTime($dateVal);
            $diff = $secDate->diff($eventStart)->days + 1;
            $sessionUniqueDays[$dateVal] = "Day " . $diff;
        } else {
            $sessionUniqueDays[$dateVal] = date('M d, Y', strtotime($dateVal));
        }
    } else {
        $sessionDayCounts['undated']++;
    }
}

$allSessionProgramsPayload = array_map(function($p) {
    return [
        'id' => (int)$p['id'],
        'title' => $p['title'],
        'section_id' => (int)($p['section_id'] ?? 0)
    ];
}, $allSessionPrograms);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
.modal-box select,
.modal-box option,
.modal-box optgroup {
    background-color: #0f172a !important;
    color: #ffffff !important;
}
.modal-box optgroup {
    color: #38bdf8 !important;
    font-weight: 700;
}
/* Force modals to always be viewport-fixed regardless of parent stacking context */
.modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 100000 !important;
    overflow-y: auto !important;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 30px 16px;
}
.modal-overlay.active {
    display: flex !important;
}

/* Premium UI Styles for Sessions Dashboard */
.session-card {
    background: rgba(15, 23, 42, 0.45) !important;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
    border-radius: 20px !important;
    box-shadow: 0 12px 40px -12px rgba(0, 0, 0, 0.6) !important;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.3s ease, box-shadow 0.3s ease !important;
}
.session-card:hover {
    transform: translateY(-5px);
    border-color: rgba(99, 102, 241, 0.3) !important;
    box-shadow: 0 24px 50px -15px rgba(99, 102, 241, 0.2) !important;
}

.day-tab-btn {
    background: rgba(255, 255, 255, 0.02) !important;
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
    color: rgba(255, 255, 255, 0.6) !important;
    padding: 6px 14px !important;
    border-radius: 99px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
    cursor: pointer;
}
.day-tab-btn:hover {
    background: rgba(255, 255, 255, 0.07) !important;
    color: #fff !important;
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.1) !important;
}
.day-tab-btn.active {
    background: linear-gradient(135deg, #6366f1, #4f46e5) !important;
    border-color: rgba(99, 102, 241, 0.5) !important;
    color: #fff !important;
    box-shadow: 0 4px 16px -4px rgba(99, 102, 241, 0.5) !important;
}

.program-drag-card {
    background: rgba(255, 255, 255, 0.02) !important;
    border: 1px solid rgba(255, 255, 255, 0.04) !important;
    border-left: 3px solid rgba(99, 102, 241, 0.6) !important;
    border-radius: 10px !important;
    padding: 11px 14px !important;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;
    cursor: grab;
}
.program-drag-card:hover {
    transform: translateY(-2px) translateX(2px);
    background: rgba(255, 255, 255, 0.04) !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
    border-left-color: #6366f1 !important;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4) !important;
}
.program-drag-card.dragging {
    opacity: 0.3 !important;
    transform: scale(0.96) rotate(-1.5deg) !important;
    border: 1px dashed rgba(99, 102, 241, 0.6) !important;
    border-left: 1px dashed rgba(99, 102, 241, 0.6) !important;
    box-shadow: 0 20px 30px rgba(0, 0, 0, 0.5) !important;
}

.session-drop-zone {
    border: 2px dashed rgba(255, 255, 255, 0.03) !important;
    border-radius: 14px !important;
    background: rgba(0, 0, 0, 0.08) !important;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
    min-height: 140px;
}
.session-drop-zone.drag-over {
    background: rgba(99, 102, 241, 0.07) !important;
    border-color: rgba(99, 102, 241, 0.5) !important;
    box-shadow: inset 0 0 24px rgba(99, 102, 241, 0.15) !important;
}

#sessionsSearch {
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
#sessionsSearch:focus {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25) !important;
    width: 280px !important;
}

.progress-bar-container {
    background: rgba(255, 255, 255, 0.05) !important;
    border-radius: 99px !important;
    overflow: hidden !important;
    height: 6px !important;
}
.progress-bar-fill {
    border-radius: 99px !important;
}

#unassignedList {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.08) transparent;
}
#unassignedList::-webkit-scrollbar {
    width: 5px;
}
#unassignedList::-webkit-scrollbar-track {
    background: transparent;
}
#unassignedList::-webkit-scrollbar-thumb {
    background-color: rgba(255, 255, 255, 0.08);
    border-radius: 99px;
}

.toast {
    background: rgba(15, 23, 42, 0.9) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    border-radius: 14px !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.4) !important;
    padding: 14px 22px !important;
    color: #fff !important;
    font-weight: 600 !important;
}

/* Division-specific card indicators */
.program-drag-card[data-tier="senior"] { border-left-color: #a78bfa !important; }
.program-drag-card[data-tier="junior"] { border-left-color: #38bdf8 !important; }
.program-drag-card[data-tier="subjunior"] { border-left-color: #34d399 !important; }
.program-drag-card[data-tier="general"] { border-left-color: #facc15 !important; }

/* Custom design polish for drag elements */
.unassign-btn {
    border-radius: 6px !important;
    width: 22px !important;
    height: 22px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: rgba(239, 68, 68, 0.08) !important;
    border: 1px solid rgba(239, 68, 68, 0.15) !important;
    transition: all 0.2s ease !important;
    color: #f87171 !important;
}
.unassign-btn:hover {
    background: #ef4444 !important;
    color: #fff !important;
    border-color: #ef4444 !important;
    transform: scale(1.1);
}
.unassign-btn i {
    font-size: 11px !important;
}

/* ===== Premium Modal Redesign ===== */
#sectionModal .modal-box {
    background: rgba(13, 17, 28, 0.95) !important;
    backdrop-filter: blur(32px) !important;
    -webkit-backdrop-filter: blur(32px) !important;
    border: 1px solid rgba(255, 255, 255, 0.07) !important;
    border-radius: 20px !important;
    box-shadow: 0 30px 70px -20px rgba(0,0,0,0.7), 0 0 0 1px rgba(99,102,241,0.08) !important;
    overflow: hidden !important;
    max-width: 540px !important;
    width: 100% !important;
    padding: 0 !important;
}

.sm-modal-header {
    background: linear-gradient(135deg, rgba(99,102,241,0.15) 0%, rgba(79,70,229,0.08) 100%);
    border-bottom: 1px solid rgba(255,255,255,0.05);
    padding: 24px 28px 20px;
    position: relative;
}
.sm-modal-header::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6, #06b6d4);
    border-radius: 20px 20px 0 0;
}
.sm-modal-icon {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #fff;
    box-shadow: 0 8px 20px -6px rgba(99,102,241,0.6);
    flex-shrink: 0;
}
.sm-modal-title-wrap { display: flex; align-items: center; gap: 14px; }
.sm-modal-title { font-size: 19px; font-weight: 800; color: #fff; line-height: 1.2; }
.sm-modal-subtitle { font-size: 12.5px; color: rgba(255,255,255,0.45); margin-top: 3px; }
.sm-modal-close {
    position: absolute; top: 18px; right: 18px;
    width: 32px; height: 32px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    color: rgba(255,255,255,0.5);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all 0.2s ease;
}
.sm-modal-close:hover {
    background: rgba(239,68,68,0.15);
    border-color: rgba(239,68,68,0.3);
    color: #ef4444;
}

.sm-modal-body {
    padding: 24px 28px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.sm-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.sm-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.sm-label {
    font-size: 12px;
    font-weight: 700;
    color: rgba(255,255,255,0.55);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    display: flex;
    align-items: center;
    gap: 6px;
}
.sm-label i {
    font-size: 11px;
    color: rgba(99,102,241,0.8);
}
.sm-label .sm-req {
    color: #f87171;
    margin-left: 1px;
}
.sm-input-wrap {
    position: relative;
}
.sm-input {
    width: 100%;
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.07) !important;
    border-radius: 10px !important;
    color: #fff !important;
    font-size: 14px !important;
    padding: 11px 14px !important;
    transition: all 0.25s ease !important;
    outline: none !important;
    box-sizing: border-box;
    -webkit-appearance: none;
    color-scheme: dark;
}
.sm-input::placeholder { color: rgba(255,255,255,0.22) !important; }
.sm-input:focus {
    background: rgba(99,102,241,0.07) !important;
    border-color: rgba(99,102,241,0.5) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important;
}
.sm-input:hover:not(:focus) {
    border-color: rgba(255,255,255,0.14) !important;
    background: rgba(255,255,255,0.055) !important;
}
.sm-field-hint {
    font-size: 11.5px;
    color: rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    gap: 5px;
}
.sm-field-hint i { font-size: 10px; }

.sm-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.05);
    margin: 0;
}

.sm-modal-footer {
    padding: 18px 28px;
    border-top: 1px solid rgba(255,255,255,0.05);
    background: rgba(0,0,0,0.15);
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
}
.sm-btn-cancel {
    background: rgba(255,255,255,0.05) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    color: rgba(255,255,255,0.65) !important;
    border-radius: 10px !important;
    padding: 10px 20px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer;
    transition: all 0.2s ease !important;
}
.sm-btn-cancel:hover {
    background: rgba(255,255,255,0.09) !important;
    color: #fff !important;
}
.sm-btn-save {
    background: linear-gradient(135deg, #6366f1, #4f46e5) !important;
    border: none !important;
    color: #fff !important;
    border-radius: 10px !important;
    padding: 10px 22px !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    display: flex; align-items: center; gap: 7px;
    box-shadow: 0 8px 20px -6px rgba(99,102,241,0.5) !important;
}
.sm-btn-save:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 12px 28px -6px rgba(99,102,241,0.65) !important;
    background: linear-gradient(135deg, #818cf8, #6366f1) !important;
}
.sm-btn-save:active { transform: translateY(0) !important; }

/* Dashboard layout grid for sessions */
.dashboard-layout-grid {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}
@media (max-width: 991px) {
    .dashboard-layout-grid {
        flex-direction: column;
    }
    .dashboard-sidebar-col {
        width: 100% !important;
    }
}
</style>

<div class="main-content">
    <div class="topbar" style="background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 12px 20px; border-radius: 12px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(168, 85, 247, 0.2)); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; color: #a78bfa; font-size: 18px;">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div>
                <div class="page-title" style="font-size: 18px; font-weight: 800; color: #fff; margin-bottom: 1px;">Program Schedule</div>
                <div class="page-subtitle" style="font-size: 12.5px; color: var(--muted);">Organize stage timelines, resolve gaps, and schedule competition programs</div>
            </div>
        </div>
        <div class="flex gap-2" style="flex-wrap: wrap;">
            <button class="btn btn-secondary btn-sm" type="button" id="openUnscheduledModalBtn" style="border-radius: 8px; font-weight: 700; background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1); height: 36px; padding: 0 14px;"><i class="fa-solid fa-clock mr-1" style="color: var(--warning);"></i> Unscheduled (<span id="topbarUnscheduledBadge"><?= count($unscheduledPrograms) ?></span>)</button>
            <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="btn btn-secondary btn-sm" style="border-radius: 8px; font-weight: 700; background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1); height: 36px; padding: 0 14px; display: inline-flex; align-items: center;"><i class="fa-solid fa-microphone-lines mr-1" style="color: #38bdf8;"></i> All Programs</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="border-radius: 8px; font-weight: 600; margin-bottom: 14px; padding: 10px 16px;"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- UNIFIED PAGE TABS -->
    <div class="tabs-container" style="display: flex; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 16px; padding-bottom: 2px;">
        <button class="tab-trigger active" data-target="timelineTab" style="background: none; border: none; padding: 10px 18px; color: #fff; font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px; cursor: pointer; border-bottom: 3px solid #6366f1; transition: all 0.2s;">
            <i class="fa-solid fa-timeline" style="color: #6366f1;"></i> Timeline Schedule
        </button>
        <button class="tab-trigger" data-target="sessionsTab" style="background: none; border: none; padding: 10px 18px; color: var(--muted); font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s;">
            <i class="fa-solid fa-clock" style="color: #a78bfa;"></i> Manage Sessions
        </button>
    </div>

    <div id="timelineTabContent" class="tab-content-panel active">

    <!-- FILTER BAR -->
    <div class="panel mb-4" style="padding: 12px 18px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; margin-bottom: 16px;">
        <form method="GET" class="form-grid" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="stage_id" value="<?= $activeStageId ?>">
            <div class="input-group" style="flex: 1; min-width: 180px;">
                <label style="font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: block;">Class Section Filter</label>
                <select name="class" onchange="this.form.submit()" class="form-input" style="height: 38px; font-size: 13px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                    <?php foreach (admin_class_type_tiers() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $classFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group" style="flex: 2; min-width: 240px;">
                <label style="font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: block;">Search Title or Location</label>
                <div style="position: relative; display: flex; align-items: center;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Filter by program name or room..." class="form-input" style="padding-left: 36px; height: 38px; font-size: 13px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff; width: 100%;">
                </div>
            </div>
            <div style="display: flex; gap: 8px; height: 38px;">
                <button class="btn btn-secondary btn-md" type="submit" style="border-radius: 8px; font-weight: 700; height: 38px; padding: 0 16px;"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
                <?php if ($search !== '' || $classFilter !== 'all'): ?>
                    <a href="<?= app_url('/admin/event-manager/schedule.php?stage_id=' . $activeStageId) ?>" class="btn btn-secondary btn-md" style="border-radius: 8px; font-weight: 700; height: 38px; padding: 0 12px; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-solid fa-xmark mr-1"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- MAIN TWO-COLUMN LAYOUT: LEFT STAGE TABS/TIMELINE + RIGHT UNSCHEDULED SIDEBAR -->
    <div class="schedule-main-container" style="display: flex; gap: 24px; width: 100%; align-items: flex-start;">
        
        <!-- LEFT COLUMN: STAGE TABS & TIMELINE -->
        <div class="schedule-left-column" style="flex: 1; min-width: 0;">
            
            <!-- STAGE TABS BAR -->
            <div class="stage-tabs-bar" style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 14px; flex-wrap: wrap;">
                <?php foreach ($stageTypes as $idx => $stage): ?>
                    <?php 
                    $stId = (int)$stage['id'];
                    $stCount = count($programsByStage[$stId] ?? []);
                    $isTabActive = ($stId === $activeStageId) || ($activeStageId <= 0 && $idx === 0);
                    ?>
                    <button type="button" class="stage-tab-btn <?= $isTabActive ? 'active' : '' ?>" data-stage-tab="<?= $stId ?>" style="padding: 11px 20px; border-radius: 12px; font-weight: 700; font-size: 14px; border: 1px solid <?= $isTabActive ? 'rgba(99,102,241,0.5)' : 'rgba(255,255,255,0.08)' ?>; background: <?= $isTabActive ? 'linear-gradient(135deg, rgba(99,102,241,0.25), rgba(168,85,247,0.25))' : 'rgba(255,255,255,0.03)' ?>; color: <?= $isTabActive ? '#fff' : 'var(--muted)' ?>; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 10px; box-shadow: <?= $isTabActive ? '0 4px 16px rgba(99,102,241,0.2)' : 'none' ?>;">
                        <i class="fa-solid fa-layer-group" style="color: <?= $isTabActive ? '#a78bfa' : 'var(--muted)' ?>;"></i>
                        <span><?= e($stage['name']) ?></span>
                        <span class="badge" style="font-size: 11px; padding: 2px 8px; border-radius: 99px; background: <?= $isTabActive ? 'rgba(255,255,255,0.2)' : 'rgba(255,255,255,0.08)' ?>; color: #fff; font-weight: 800;"><?= $stCount ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- LIVE TIMELINE FILTER BAR -->
            <div class="panel mb-6" style="padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: rgba(15,23,42,0.4); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; margin-bottom: 20px;">
                <div style="position: relative; display: flex; align-items: center; flex: 1; min-width: 200px;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                    <input type="text" id="timelineSearch" placeholder="Search scheduled timeline..." class="form-input" style="padding-left: 36px; height: 38px; font-size: 13px; width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
                </div>
                <select id="timelineSectionFilter" class="form-input" style="height: 38px; font-size: 13px; width: 170px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff; padding: 0 12px;">
                    <option value="all">All Divisions</option>
                    <option value="senior">Senior</option>
                    <option value="junior">Junior</option>
                    <option value="subjunior">Sub Junior</option>
                    <option value="general">General / Open</option>
                </select>
            </div>

            <!-- STAGE PANELS -->
            <div class="stage-panels-container" style="display: flex; flex-direction: column; gap: 24px;">
                <?php foreach ($stageTypes as $idx => $stage): ?>
                    <?php 
                    $stageId = (int)$stage['id'];
                    $stageProgs = $programsByStage[$stageId] ?? [];
                    $isOffStage = (($stage['category'] ?? 'on_stage') === 'off_stage');
                    $lastProg = !empty($stageProgs) ? end($stageProgs) : null;
                    $lastEndAt = $lastProg ? $lastProg['end_at'] : '';
                    $isPanelActive = ($stageId === $activeStageId) || ($activeStageId <= 0 && $idx === 0);
                    ?>
                    <div class="stage-panel-item panel" data-stage-id="<?= $stageId ?>" data-last-end-at="<?= e($lastEndAt) ?>" style="padding: 24px; background: rgba(15,23,42,0.4); border: 2px dashed rgba(255,255,255,0.08); border-radius: 16px; transition: all 0.2s ease; <?= !$isPanelActive ? 'display: none;' : '' ?>">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 14px;">
                            <div class="dashboard-heading" style="margin: 0; font-size: 16px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-layer-group" style="color: #a78bfa;"></i>
                                <?= e($stage['name']) ?>
                                <span style="font-size: 12px; color: var(--muted); font-weight: 600;">(<?= count($stageProgs) ?> Scheduled Programs)</span>
                            </div>
                            <div style="font-size: 12px; color: var(--muted); font-weight: 600; display: flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.04); padding: 4px 10px; border-radius: 99px; border: 1px solid rgba(255,255,255,0.06);">
                                <i class="fa-solid fa-hand-pointer" style="color: #38bdf8;"></i> Drag & drop unscheduled programs to schedule
                            </div>
                        </div>

                        <?php if (empty($stageProgs)): ?>
                            <div class="empty-state stage-drop-zone" style="padding: 48px 24px; border: 2px dashed rgba(255,255,255,0.1); border-radius: 14px; text-align: center; background: rgba(255,255,255,0.01); transition: all 0.2s ease;">
                                <div class="empty-icon" style="font-size: 36px; color: var(--muted); margin-bottom: 10px;"><i class="fa-solid fa-calendar-xmark"></i></div>
                                <div class="empty-title" style="font-size: 15px; font-weight: 700; color: #fff; margin-top: 8px;">No Scheduled Programs for <?= e($stage['name']) ?></div>
                                <div class="empty-subtitle" style="font-size: 12.5px; color: var(--muted); margin-top: 4px;">Drag an unscheduled program card from the sidebar or click "Schedule Program" above.</div>
                            </div>
                        <?php else: ?>
                            <div class="grid gap-4 stage-drop-zone" style="position: relative;">
                                <?php
                                // Group programs for this stage by section_id
                                $stageProgsBySection = [];
                                $stageProgsUnassigned = [];
                                foreach ($stageProgs as $program) {
                                    $secId = $program['section_id'] ? (int)$program['section_id'] : 0;
                                    if ($secId > 0) {
                                        $stageProgsBySection[$secId][] = $program;
                                    } else {
                                        $stageProgsUnassigned[] = $program;
                                    }
                                }

                                // We will loop through the sessionList and render each session that has programs
                                foreach ($sessionList as $session):
                                    $secId = (int)$session['id'];
                                    $progs = $stageProgsBySection[$secId] ?? [];
                                    if (empty($progs)) {
                                        continue;
                                    }
                                    
                                    // Parse times for the session header display
                                    $sStart = date('h:i A', strtotime($session['start_time']));
                                    $sEnd = date('h:i A', strtotime($session['end_time']));
                                    $sDate = $session['section_date'] ? date('M d, Y', strtotime($session['section_date'])) : '';
                                    ?>
                                    
                                    <!-- SESSION SECTION CONTAINER -->
                                    <div class="timeline-session-section" style="margin-top: 15px; margin-bottom: 25px; width: 100%;">
                                        <!-- Header displaying the session info like "Day 1 - Morning (8:30 AM - 12:00 PM)" -->
                                        <div class="timeline-session-header" style="margin-bottom: 14px; padding: 12px 18px; background: rgba(99, 102, 241, 0.08); border: 1px solid rgba(99, 102, 241, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                            <div style="font-weight: 800; color: #a78bfa; font-size: 14.5px; display: flex; align-items: center; gap: 10px;">
                                                <i class="fa-solid fa-clock" style="color: #818cf8;"></i>
                                                <?php
                                                $dayLabel = ($session['section_date'] && isset($sessionUniqueDays[$session['section_date']]))
                                                    ? $sessionUniqueDays[$session['section_date']] . ' - '
                                                    : '';
                                                ?>
                                                <span><?= e($dayLabel . $session['name']) ?> (<?= $sStart ?> - <?= $sEnd ?>)</span>
                                            </div>
                                            <?php if ($sDate): ?>
                                                <span style="font-size: 11.5px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;"><i class="fa-regular fa-calendar mr-1"></i> <?= e($sDate) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Programs inside this session -->
                                        <div class="timeline-session-programs-list" style="display: flex; flex-direction: column; gap: 12px;">
                                            <?php if ($isOffStage): ?>
                                                <?php
                                                $groupedProgs = [];
                                                foreach ($progs as $program) {
                                                    $groupedProgs[$program['start_at']][] = $program;
                                                }
                                                ?>
                                                <?php foreach ($groupedProgs as $timeKey => $progsAtTime): ?>
                                                    <?php if (count($progsAtTime) > 1): ?>
                                                        <!-- Stacked / Grouped parallel cards -->
                                                        <div class="parallel-programs-group" style="background: rgba(30, 41, 59, 0.2); border: 1px dashed rgba(255,255,255,0.08); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 14px; width: 100%;">
                                                            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 10px; margin-bottom: 2px;">
                                                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                                    <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                        <i class="fa-regular fa-calendar-days"></i>
                                                                        <?= e(date('M d, Y', strtotime($timeKey))) ?>
                                                                    </span>
                                                                    <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                        <i class="fa-regular fa-clock"></i>
                                                                        <?= e(date('h:i A', strtotime($timeKey))) ?>
                                                                    </span>
                                                                    <span style="font-size: 11.5px; color: var(--muted); font-weight: 700; background: rgba(245,158,11,0.1); color: #facc15; padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(245,158,11,0.2);">
                                                                        <i class="fa-solid fa-layer-group mr-1"></i> Parallel Session (<?= count($progsAtTime) ?> Programs)
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                                                                <?php foreach ($progsAtTime as $program): ?>
                                                                    <?php
                                                                    $secNames = [];
                                                                    if (!empty($program['allowed_sections'])) {
                                                                        $secIds = array_filter(array_map('intval', explode(',', $program['allowed_sections'])));
                                                                        foreach ($secIds as $sid) {
                                                                            if (isset($classTypesMap[$sid])) {
                                                                                $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                                                $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                                                if ($label && !in_array($label, $secNames, true)) {
                                                                                    $secNames[] = $label;
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                    $sectionDisplay = implode(' & ', $secNames);
                                                                    if ($sectionDisplay === '') {
                                                                        $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                                        $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($program['class_type_name'] ?? '—');
                                                                    }

                                                                    $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                                    $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;
                                                                    $itemSection = ($allowedCount > 1 || !$classTier) ? 'general' : $classTier;

                                                                    $startDt = new DateTime((string)$program['start_at']);
                                                                    $endDt = new DateTime((string)$program['end_at']);
                                                                    $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                                                    $borderAccent = match($classTier) {
                                                                        'senior' => '#a78bfa',
                                                                        'junior' => '#38bdf8',
                                                                        'subjunior' => '#34d399',
                                                                        default => '#f43f5e'
                                                                    };
                                                                    ?>
                                                                    <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>" style="margin-bottom: 0;">
                                                                        <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.5); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease; height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                                                                            <div style="display: flex; flex-direction: column; gap: 10px; height: 100%; justify-content: space-between;">
                                                                                <div style="min-width: 0;">
                                                                                    <div class="dashboard-heading" style="font-size: 14.5px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                                    <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                                            <?= e($sectionDisplay) ?>
                                                                                        </span>
                                                                                        <?php if (!empty($program['location'])): ?>
                                                                                            <span style="color: #38bdf8; font-size: 11px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                                        <?php endif; ?>
                                                                                        <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                                            <span style="color: var(--muted); font-size: 11px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                </div>
                                                                                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                                                                                    <div style="display: flex; align-items: center; gap: 8px;">
                                                                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                            <i class="fa-regular fa-calendar-days"></i>
                                                                                            <?= e(date('M d, Y', strtotime($program['start_at']))) ?>
                                                                                        </span>
                                                                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                            <i class="fa-regular fa-clock"></i>
                                                                                            <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                                        </span>
                                                                                        <span style="font-size: 11px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;">
                                                                                            <?= $durMins ?> mins
                                                                                        </span>
                                                                                    </div>
                                                                                    <div class="flex gap-2">
                                                                                        <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen mr-1"></i> Edit</button>
                                                                                        
                                                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                                            <?= admin_csrf_field() ?>
                                                                                            <input type="hidden" name="action" value="unschedule_program">
                                                                                            <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                                            <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                                            <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus mr-1"></i> Unschedule</button>
                                                                                        </form>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php 
                                                        $program = $progsAtTime[0];
                                                        $secNames = [];
                                                        if (!empty($program['allowed_sections'])) {
                                                            $secIds = array_filter(array_map('intval', explode(',', $program['allowed_sections'])));
                                                            foreach ($secIds as $sid) {
                                                                if (isset($classTypesMap[$sid])) {
                                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                                    if ($label && !in_array($label, $secNames, true)) {
                                                                        $secNames[] = $label;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        $sectionDisplay = implode(' & ', $secNames);
                                                        if ($sectionDisplay === '') {
                                                            $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($program['class_type_name'] ?? '—');
                                                        }

                                                        $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                        $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;
                                                        $itemSection = ($allowedCount > 1 || !$classTier) ? 'general' : $classTier;

                                                        $startDt = new DateTime((string)$program['start_at']);
                                                        $endDt = new DateTime((string)$program['end_at']);
                                                        $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                                        $borderAccent = match($classTier) {
                                                            'senior' => '#a78bfa',
                                                            'junior' => '#38bdf8',
                                                            'subjunior' => '#34d399',
                                                            default => '#f43f5e'
                                                        };
                                                        ?>
                                                        <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>">
                                                            <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.4); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                                                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                                                        <div style="min-width: 0; flex: 1;">
                                                                            <div class="dashboard-heading" style="font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                            <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                                                <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                                    <?= e($sectionDisplay) ?>
                                                                                </span>
                                                                                <span class="badge" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700; background: rgba(245, 158, 11, 0.15); color: #facc15; border: 1px solid rgba(245, 158, 11, 0.3);">
                                                                                    <i class="fa-solid fa-layer-group mr-1"></i> Off-Stage Parallel
                                                                                </span>
                                                                                <?php if (!empty($program['location'])): ?>
                                                                                    <span style="color: #38bdf8; font-size: 11.5px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                                    <span style="color: var(--muted); font-size: 11.5px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                                            <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                <i class="fa-regular fa-calendar-days"></i>
                                                                                <?= e(date('M d, Y', strtotime($program['start_at']))) ?>
                                                                            </span>
                                                                            <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                <i class="fa-regular fa-clock"></i>
                                                                                <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                            </span>
                                                                            <span style="font-size: 11px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;">
                                                                                <?= $durMins ?> mins
                                                                            </span>
                                                                        </div>
                                                                        <div class="flex gap-2">
                                                                            <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen mr-1"></i> Edit</button>
                                                                            
                                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                                <?= admin_csrf_field() ?>
                                                                                <input type="hidden" name="action" value="unschedule_program">
                                                                                <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                                <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                                <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus mr-1"></i> Unschedule</button>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- FALLBACK: UNASSIGNED/GENERAL SESSION SECTION -->
                                <?php if (!empty($stageProgsUnassigned)): ?>
                                    <div class="timeline-session-section" style="margin-top: 15px; margin-bottom: 25px; width: 100%;">
                                        <div class="timeline-session-header" style="margin-bottom: 14px; padding: 12px 18px; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.15); border-radius: 12px; display: flex; align-items: center; gap: 10px; color: #ef4444; font-weight: 800; font-size: 14.5px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            <span>General / Unassigned Session</span>
                                        </div>
                                        <div class="timeline-session-programs-list" style="display: flex; flex-direction: column; gap: 12px;">
                                            <?php if ($isOffStage): ?>
                                                <?php
                                                $groupedProgs = [];
                                                foreach ($stageProgsUnassigned as $program) {
                                                    $groupedProgs[$program['start_at']][] = $program;
                                                }
                                                ?>
                                                <?php foreach ($groupedProgs as $timeKey => $progsAtTime): ?>
                                                    <?php if (count($progsAtTime) > 1): ?>
                                                        <!-- Stacked / Grouped parallel cards -->
                                                        <div class="parallel-programs-group" style="background: rgba(30, 41, 59, 0.2); border: 1px dashed rgba(255,255,255,0.08); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 14px; width: 100%;">
                                                            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 10px; margin-bottom: 2px;">
                                                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                                    <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                        <i class="fa-regular fa-calendar-days"></i>
                                                                        <?= e(date('M d, Y', strtotime($timeKey))) ?>
                                                                    </span>
                                                                    <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                        <i class="fa-regular fa-clock"></i>
                                                                        <?= e(date('h:i A', strtotime($timeKey))) ?>
                                                                    </span>
                                                                    <span style="font-size: 11.5px; color: var(--muted); font-weight: 700; background: rgba(245,158,11,0.1); color: #facc15; padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(245,158,11,0.2);">
                                                                        <i class="fa-solid fa-layer-group mr-1"></i> Parallel Session (<?= count($progsAtTime) ?> Programs)
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                                                                <?php foreach ($progsAtTime as $program): ?>
                                                                    <?php
                                                                    $secNames = [];
                                                                    if (!empty($program['allowed_sections'])) {
                                                                        $secIds = array_filter(array_map('intval', explode(',', $program['allowed_sections'])));
                                                                        foreach ($secIds as $sid) {
                                                                            if (isset($classTypesMap[$sid])) {
                                                                                $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                                                $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                                                if ($label && !in_array($label, $secNames, true)) {
                                                                                    $secNames[] = $label;
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                    $sectionDisplay = implode(' & ', $secNames);
                                                                    if ($sectionDisplay === '') {
                                                                        $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                                        $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($program['class_type_name'] ?? '—');
                                                                    }

                                                                    $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                                    $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;
                                                                    $itemSection = ($allowedCount > 1 || !$classTier) ? 'general' : $classTier;

                                                                    $startDt = new DateTime((string)$program['start_at']);
                                                                    $endDt = new DateTime((string)$program['end_at']);
                                                                    $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                                                    $borderAccent = match($classTier) {
                                                                        'senior' => '#a78bfa',
                                                                        'junior' => '#38bdf8',
                                                                        'subjunior' => '#34d399',
                                                                        default => '#f43f5e'
                                                                    };
                                                                    ?>
                                                                    <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>" style="margin-bottom: 0;">
                                                                        <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.5); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease; height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                                                                            <div style="display: flex; flex-direction: column; gap: 10px; height: 100%; justify-content: space-between;">
                                                                                <div style="min-width: 0;">
                                                                                    <div class="dashboard-heading" style="font-size: 14.5px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                                    <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                                            <?= e($sectionDisplay) ?>
                                                                                        </span>
                                                                                        <?php if (!empty($program['location'])): ?>
                                                                                            <span style="color: #38bdf8; font-size: 11px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                                        <?php endif; ?>
                                                                                        <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                                            <span style="color: var(--muted); font-size: 11px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                </div>
                                                                                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                                                                                    <div style="display: flex; align-items: center; gap: 8px;">
                                                                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                            <i class="fa-regular fa-calendar-days"></i>
                                                                                            <?= e(date('M d, Y', strtotime($program['start_at']))) ?>
                                                                                        </span>
                                                                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                            <i class="fa-regular fa-clock"></i>
                                                                                            <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                                        </span>
                                                                                        <span style="font-size: 11px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;">
                                                                                            <?= $durMins ?> mins
                                                                                        </span>
                                                                                    </div>
                                                                                    <div class="flex gap-2">
                                                                                        <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen mr-1"></i> Edit</button>
                                                                                        
                                                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                                            <?= admin_csrf_field() ?>
                                                                                            <input type="hidden" name="action" value="unschedule_program">
                                                                                            <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                                            <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                                            <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus mr-1"></i> Unschedule</button>
                                                                                        </form>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php 
                                                        $program = $progsAtTime[0];
                                                        $secNames = [];
                                                        if (!empty($program['allowed_sections'])) {
                                                            $secIds = array_filter(array_map('intval', explode(',', $program['allowed_sections'])));
                                                            foreach ($secIds as $sid) {
                                                                if (isset($classTypesMap[$sid])) {
                                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                                    if ($label && !in_array($label, $secNames, true)) {
                                                                        $secNames[] = $label;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        $sectionDisplay = implode(' & ', $secNames);
                                                        if ($sectionDisplay === '') {
                                                            $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($program['class_type_name'] ?? '—');
                                                        }

                                                        $classTier = admin_class_type_tier_from_name($program['class_type_name'] ?? '');
                                                        $allowedCount = !empty($program['allowed_sections']) ? count(explode(',', $program['allowed_sections'])) : 0;
                                                        $itemSection = ($allowedCount > 1 || !$classTier) ? 'general' : $classTier;

                                                        $startDt = new DateTime((string)$program['start_at']);
                                                        $endDt = new DateTime((string)$program['end_at']);
                                                        $durMins = max(1, (int)round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60));

                                                        $borderAccent = match($classTier) {
                                                            'senior' => '#a78bfa',
                                                            'junior' => '#38bdf8',
                                                            'subjunior' => '#34d399',
                                                            default => '#f43f5e'
                                                        };
                                                        ?>
                                                        <div class="timeline-item-container timeline-row" data-title="<?= e($program['title']) ?>" data-location="<?= e($program['location'] ?? '') ?>" data-section="<?= e($itemSection) ?>">
                                                            <div class="panel" style="padding: 16px 18px; border-left: 5px solid <?= $borderAccent ?>; background: rgba(30, 41, 59, 0.4); border-color: rgba(255,255,255,0.06); border-radius: 12px; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                                                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                                                        <div style="min-width: 0; flex: 1;">
                                                                            <div class="dashboard-heading" style="font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3;"><?= e($program['title']) ?></div>
                                                                            <div class="page-subtitle" style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                                                <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700;">
                                                                                    <?= e($sectionDisplay) ?>
                                                                                </span>
                                                                                <span class="badge" style="font-size: 10px; padding: 2px 7px; border-radius: 6px; font-weight: 700; background: rgba(245, 158, 11, 0.15); color: #facc15; border: 1px solid rgba(245, 158, 11, 0.3);">
                                                                                    <i class="fa-solid fa-layer-group mr-1"></i> Off-Stage Parallel
                                                                                </span>
                                                                                <?php if (!empty($program['location'])): ?>
                                                                                    <span style="color: #38bdf8; font-size: 11.5px; font-weight: 600;"><i class="fa-solid fa-location-dot mr-1"></i> <?= e($program['location']) ?></span>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($program['responsible_teacher_name'])): ?>
                                                                                    <span style="color: var(--muted); font-size: 11.5px; font-weight: 500;"><i class="fa-solid fa-chalkboard-user mr-1"></i> <?= e($program['responsible_teacher_name']) ?></span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px; margin-top: 4px; flex-wrap: wrap; gap: 8px;">
                                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                                            <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                <i class="fa-regular fa-calendar-days"></i>
                                                                                <?= e(date('M d, Y', strtotime($program['start_at']))) ?>
                                                                            </span>
                                                                            <span class="badge badge-info" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 8px; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.25); display: inline-flex; align-items: center; gap: 4px;">
                                                                                <i class="fa-regular fa-clock"></i>
                                                                                <?= e(date('h:i A', strtotime($program['start_at']))) ?> - <?= e(date('h:i A', strtotime($program['end_at']))) ?>
                                                                            </span>
                                                                            <span style="font-size: 11px; color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.05); padding: 3px 8px; border-radius: 6px;">
                                                                                <?= $durMins ?> mins
                                                                            </span>
                                                                        </div>
                                                                        <div class="flex gap-2">
                                                                            <button class="btn btn-secondary btn-sm" type="button" data-edit-schedule-btn='<?= e(json_encode($program, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' title="Edit Schedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-pen mr-1"></i> Edit</button>
                                                                            
                                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to unschedule <?= e(addslashes($program['title'])) ?>?');">
                                                                                <?= admin_csrf_field() ?>
                                                                                <input type="hidden" name="action" value="unschedule_program">
                                                                                <input type="hidden" name="stage_type_id" value="<?= $stageId ?>">
                                                                                <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                                                                <button class="btn btn-danger btn-sm" type="submit" title="Unschedule" style="padding: 5px 10px; font-size: 11px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-calendar-minus mr-1"></i> Unschedule</button>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: DRAGGABLE UNSCHEDULED PROGRAMS PANEL -->
        <aside class="unscheduled-sidebar-panel panel" style="width: 340px; flex: 0 0 340px; position: sticky; top: 20px; max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); display: flex; flex-direction: column; padding: 20px; border-color: rgba(255,255,255,0.08); background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px); border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.4);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px;">
                <div style="font-size: 15px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clock" style="color: var(--warning);"></i>
                    <span>Unscheduled Programs</span>
                </div>
                <span class="badge" id="sidebarUnscheduledCount" style="font-size: 11px; font-weight: 800; border-radius: 99px; background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); padding: 3px 8px;"><?= count($unscheduledPrograms) ?></span>
            </div>

            <div style="position: relative; display: flex; align-items: center; margin-bottom: 14px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 12px;"></i>
                <input type="text" id="sidebarUnscheduledSearch" placeholder="Search unscheduled..." class="form-input" style="padding-left: 34px; height: 38px; font-size: 12.5px; width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
            </div>

            <div style="font-size: 11.5px; color: var(--muted); margin-bottom: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-grip-vertical" style="color: #38bdf8;"></i> Drag program card to active stage timeline
            </div>

            <div class="unscheduled-sidebar-content" style="display: flex; flex-direction: column; gap: 10px; overflow-y: auto; flex: 1; padding-right: 4px;">
                <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                    <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                    <div class="accordion-item sidebar-accordion-item" data-tier="<?= $tierKey ?>">
                        <button class="accordion-header" type="button" style="width: 100%; text-align: left; padding: 11px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; color: #fff; font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: all 0.2s;">
                            <span><?= e($tierLabel) ?> <span class="tier-count" style="font-size: 11px; color: var(--muted); margin-left: 6px; font-weight: 600;">(<?= count($tierProgs) ?>)</span></span>
                            <i class="fa-solid fa-chevron-down accordion-icon" style="font-size: 11px; transition: transform 0.2s;"></i>
                        </button>
                        <div class="accordion-content" style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;">
                            <div style="padding: 10px 2px 0 2px; display: flex; flex-direction: column; gap: 10px;">
                                <?php if (empty($tierProgs)): ?>
                                    <div style="font-size: 12px; color: var(--muted); text-align: center; padding: 12px; background: rgba(0,0,0,0.1); border-radius: 8px;">No programs in this tier</div>
                                <?php else: ?>
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <?php
                                        $secNames = [];
                                        if (!empty($prog['allowed_sections'])) {
                                            $secIds = array_filter(array_map('intval', explode(',', $prog['allowed_sections'])));
                                            foreach ($secIds as $sid) {
                                                if (isset($classTypesMap[$sid])) {
                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                    if ($label && !in_array($label, $secNames, true)) {
                                                        $secNames[] = $label;
                                                    }
                                                }
                                            }
                                        }
                                        $sectionDisplay = implode(' & ', $secNames);
                                        if ($sectionDisplay === '') {
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($prog['class_type_name'] ?? '—');
                                        }
                                        $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                        ?>
                                        <div class="unscheduled-card panel draggable-program-card" draggable="true" data-title="<?= e($prog['title']) ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" data-program-json='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="padding: 12px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; cursor: grab; display: flex; flex-direction: column; gap: 10px; transition: all 0.2s ease;">
                                            <div style="display: flex; align-items: flex-start; gap: 10px;">
                                                <i class="fa-solid fa-grip-vertical" style="color: var(--muted); font-size: 14px; margin-top: 2px;"></i>
                                                <div style="min-width: 0; flex: 1;">
                                                    <strong style="display: block; font-size: 13.5px; line-height: 1.3; color: #fff;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                                    <span class="page-subtitle" style="font-size: 11px; margin-top: 4px; display: inline-block;">
                                                        <span class="badge <?= admin_class_type_badge_class($classTier) ?>" style="font-size: 9.5px; padding: 1px 6px;">
                                                            <?= e($sectionDisplay) ?>
                                                        </span>
                                                        <?php if (!empty($prog['responsible_teacher_name'])): ?>
                                                            · <span style="color: var(--muted);"><i class="fa-solid fa-chalkboard-user"></i> <?= e($prog['responsible_teacher_name']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <button class="btn btn-success btn-sm" type="button" data-schedule-btn='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="width: 100%; justify-content: center; font-size: 11.5px; padding: 6px 10px; border-radius: 8px; font-weight: 700;"><i class="fa-solid fa-calendar-plus mr-1"></i> Schedule Now</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</div>
<!-- timelineTabContent -->

<div id="sessionsTabContent" class="tab-content-panel" style="display: none;">
    <!-- Real-time Filter & Search & Actions Panel -->
    <div class="panel" style="padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: rgba(255,255,255,0.015); border-color: rgba(255,255,255,0.03); border-radius: 12px; margin-bottom: 16px;">
        <div style="display: flex; gap: 6px; flex-wrap: wrap;" id="dayFilterTabs">
            <button class="day-tab-btn active" data-day="all">
                All Days <span class="badge badge-neutral ml-1" style="font-size: 10px; opacity: 0.8;"><?= $sessionDayCounts['all'] ?></span>
            </button>
            <?php 
            $sortedDates = array_keys($sessionUniqueDays);
            sort($sortedDates);
            foreach ($sortedDates as $date): 
            ?>
                <button class="day-tab-btn" data-day="<?= e($date) ?>">
                    <?= e($sessionUniqueDays[$date]) ?> 
                    <span class="badge badge-neutral ml-1" style="font-size: 10px; opacity: 0.8;"><?= $sessionDayCounts[$date] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
            <?php if (($sessionDayCounts['undated'] ?? 0) > 0): ?>
                <button class="day-tab-btn" data-day="undated">
                    General / Undated 
                    <span class="badge badge-neutral ml-1" style="font-size: 10px; opacity: 0.8;"><?= $sessionDayCounts['undated'] ?></span>
                </button>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div style="position: relative; display: flex; align-items: center;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                <input type="text" id="sessionsSearch" placeholder="Search programs..." class="form-input" style="padding-left: 34px; height: 38px; font-size: 13px; width: 220px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
            </div>
            <button class="btn btn-success btn-md" type="button" data-open-add style="border-radius: 8px; font-weight: 700; height: 38px; padding: 0 16px; box-shadow: 0 4px 14px rgba(16,185,129,0.2); display: flex; align-items: center; gap: 6px;"><i class="fa-solid fa-plus"></i> Add Session</button>
        </div>
    </div>

    <!-- Dashboard layout grid -->
    <div class="dashboard-layout-grid">
        <!-- Main: Sessions Cards Grid -->
        <div class="dashboard-main-col" style="flex: 1; min-width: 0;">
            <?php if (empty($sessionList)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center; background: rgba(255,255,255,0.01); border: 1.5px dashed rgba(255,255,255,0.05); border-radius: 16px;">
                    <div class="empty-icon" style="font-size: 40px; color: rgba(255,255,255,0.1); margin-bottom: 12px;"><i class="fa-solid fa-clock"></i></div>
                    <div class="empty-title" style="font-size: 16px; font-weight: 700; color: #fff;">No Schedule Sessions</div>
                    <div class="empty-subtitle" style="font-size: 13px; color: var(--muted); margin-bottom: 16px;">Create sessions (Morning, Evening, Night) to group programs.</div>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;" id="sessionsContainer">
                    <?php 
                    foreach ($sessionList as $section): 
                        $sectionId = (int)$section['id'];
                        $assignedProgs = $programsBySection[$sectionId] ?? [];
                        
                        // Calculate allocated duration vs total duration of session
                        $secStart = new DateTime($section['start_time']);
                        $secEnd = new DateTime($section['end_time']);
                        if ($secEnd < $secStart) {
                            $secEnd->modify('+1 day');
                        }
                        $sessionTotalMins = (int)(($secEnd->getTimestamp() - $secStart->getTimestamp()) / 60);
                        
                        $allocatedMins = calculate_session_allocated_minutes($assignedProgs);
                        
                        $isOverallocated = $allocatedMins > $sessionTotalMins;
                        $percentage = $sessionTotalMins > 0 ? min(100, (int)(($allocatedMins / $sessionTotalMins) * 100)) : 0;
                        
                        // Find Day Accent class
                        $accentClass = 'card-day-accent-gen';
                        $dateVal = $section['section_date'] ?: 'undated';
                        if ($section['section_date'] && $eventStart) {
                            $secDate = new DateTime($section['section_date']);
                            $diff = $secDate->diff($eventStart)->days + 1;
                            $accentClass = 'card-day-accent-' . (($diff % 3) ?: 3);
                        }
                        
                        // Define inline style colors for accentClass fallback
                        $accentColor = match($accentClass) {
                            'card-day-accent-1' => '#6366f1',
                            'card-day-accent-2' => '#a78bfa',
                            'card-day-accent-3' => '#34d399',
                            default => '#94a3b8'
                        };
                        ?>
                        <div class="panel session-card" 
                             data-day="<?= e($dateVal) ?>"
                             data-section-id="<?= $sectionId ?>"
                             style="display: flex; flex-direction: column; height: 100%; border: 1px solid rgba(255,255,255,0.04); background: rgba(255,255,255,0.015); border-radius: 12px; padding: 0; overflow: hidden; position: relative;">
                            
                            <!-- Top color strip -->
                            <div style="height: 4px; width: 100%; background: <?= $accentColor ?>;"></div>
                            
                            <!-- Card Header -->
                            <div style="padding: 16px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div style="flex: 1; min-width: 0;">
                                        <h4 style="font-size: 15px; font-weight: 800; color: #fff; margin: 0; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-regular fa-clock" style="color: <?= $accentColor ?>; font-size:14px;"></i>
                                            <?= e($section['name']) ?>
                                        </h4>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                            <span class="badge badge-info" style="font-size: 11px; padding: 2px 6px;">
                                                <?= e(date('h:i A', strtotime($section['start_time']))) ?> - <?= e(date('h:i A', strtotime($section['end_time']))) ?>
                                            </span>
                                            <?php if ($section['section_date']): ?>
                                                <span class="badge badge-neutral" style="font-size: 11px; padding: 2px 6px;">
                                                    <i class="fa-regular fa-calendar-days mr-1"></i> <?= e(date('M d, Y', strtotime($section['section_date']))) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex gap-1" style="margin-left: 10px;">
                                        <button 
                                            type="button"
                                            class="btn btn-secondary btn-xs" 
                                            data-edit-section='<?= e(json_encode($section, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                            style="height:26px; width:26px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:11px;"
                                            title="Edit Session"
                                        >
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button 
                                            type="button"
                                            class="btn btn-danger btn-xs" 
                                            data-delete-id="<?= $sectionId ?>" 
                                            data-delete-name="<?= e($section['name']) ?>"
                                            style="height:26px; width:26px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:11px;"
                                            title="Delete Session"
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Session Allocation Metrics -->
                                <div style="margin-top: 12px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; font-weight: 500;">
                                        <span class="allocated-text" style="color: <?= $isOverallocated ? 'var(--danger, #ef4444)' : 'var(--muted)' ?>;">
                                            Allocated: <span class="alloc-mins"><?= $allocatedMins ?></span>m / <?= $sessionTotalMins ?>m (<?= $percentage ?>%)
                                        </span>
                                        <?php if ($isOverallocated): ?>
                                            <span class="warning-badge" style="color: var(--danger, #ef4444); font-weight: 700; font-size: 10px; text-transform: uppercase;">
                                                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Overallocated
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="progress-bar-container" style="background: rgba(255,255,255,0.05); height: 5px; border-radius: 99px; overflow: hidden; margin-top: 6px;">
                                        <div class="progress-bar-fill" 
                                             data-total="<?= $sessionTotalMins ?>"
                                             style="background: <?= $isOverallocated ? 'var(--danger, #ef4444)' : '#6366f1' ?>; width: <?= $percentage ?>%; height: 100%; transition: width 0.3s ease, background-color 0.3s ease;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Droppable list wrapper -->
                            <div class="session-drop-zone" 
                                 data-drop-section-id="<?= $sectionId ?>"
                                 style="padding: 16px 20px; flex: 1; display: flex; flex-direction: column; min-height: 120px;">
                                
                                <div class="assigned-list" style="flex: 1; display:flex; flex-direction:column; gap:8px;">
                                    <?php if (empty($assignedProgs)): ?>
                                        <div class="empty-sec-placeholder" style="text-align: center; color: var(--muted); font-size: 12.5px; padding: 30px 0; border: 1.5px dashed rgba(255,255,255,0.03); border-radius: 8px; margin: auto 0; display:flex; flex-direction:column; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrow-pointer-drag" style="font-size: 18px; color:rgba(255,255,255,0.2);"></i>
                                            <span>Drag programs here</span>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($assignedProgs as $prog): ?>
                                            <?php 
                                            $progDuration = 0;
                                            if ($prog['start_time'] && $prog['end_time']) {
                                                $pStart = new DateTime($prog['start_time']);
                                                $pEnd = new DateTime($prog['end_time']);
                                                $progDuration = (int)(($pEnd->getTimestamp() - $pStart->getTimestamp()) / 60);
                                            }
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                            $tierClass = admin_class_type_badge_class($classTier);
                                            $tierName = $classTier ? admin_class_type_tier_label($classTier) : 'General';
                                            ?>
                                            <div class="program-drag-card" 
                                                 draggable="true"
                                                 data-program-id="<?= (int)$prog['id'] ?>"
                                                 data-duration="<?= $progDuration ?>"
                                                 data-tier="<?= $classTier ?: 'general' ?>"
                                                 data-time="<?= $prog['start_time'] ? e($prog['start_time']) : '' ?>"
                                                 style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); border-radius: 8px; padding: 8px 12px; gap: 8px;">
                                                <div style="min-width: 0;">
                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                        <strong class="prog-title" style="font-size: 13.0px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;"><?= e($prog['title']) ?></strong>
                                                        <span class="badge badge-neutral <?= $tierClass ?>" style="font-size: 9.0px; padding: 1px 5px; border-radius: 4px; font-weight: 800; transform: translateY(-0.5px);"><?= e($tierName) ?></span>
                                                    </div>
                                                    <span style="font-size: 11px; color: var(--muted); display: block; margin-top: 4px;">
                                                        <i class="fa-solid fa-location-dot mr-1"></i> <?= e($prog['stage_type_name'] ?: 'TBD') ?>
                                                        <?php if ($prog['start_time']): ?>
                                                             • <?= e(date('h:i A', strtotime($prog['start_time']))) ?> (<?= $progDuration ?>m)
                                                         <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <i class="fa-solid fa-grip-vertical mr-1" style="color:rgba(255,255,255,0.2); font-size:12px; cursor:grab;"></i>
                                                    <button type="button" class="btn btn-link btn-sm unassign-btn" data-unassign-id="<?= (int)$prog['id'] ?>" style="color: var(--danger, #ef4444); padding:4px;" title="Unassign">
                                                        <i class="fa-solid fa-xmark" style="font-size: 14px;"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Area: Unassigned Programs -->
        <div class="dashboard-sidebar-col" style="width: 340px; flex: 0 0 340px;">
            <div class="panel" style="border: 1px solid rgba(255,255,255,0.04); background: rgba(255,255,255,0.015); border-radius: 12px; position: sticky; top: 20px;">
                <h3 class="mb-4" style="font-size: 15px; font-weight: 800; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <span><i class="fa-solid fa-list-ul mr-2" style="color:var(--warning);"></i> Unassigned Programs</span>
                    <span class="badge badge-warning" id="unassignedCount" style="font-size: 11px; padding: 2px 8px;"><?= count($sessionUnassignedPrograms) ?></span>
                </h3>

                <div id="unassignedList" 
                     class="session-drop-zone" 
                     data-drop-section-id="0"
                     style="display: flex; flex-direction: column; gap: 10px; max-height: 550px; overflow-y: auto; padding: 4px; min-height: 150px; border-radius: 8px;">
                    
                    <?php if (empty($sessionUnassignedPrograms)): ?>
                        <div class="all-assigned-msg" style="text-align: center; color: var(--success); padding: 40px 0; font-size: 13px;">
                           <i class="fa-solid fa-circle-check" style="font-size:24px; display:block; margin-bottom:10px; color:var(--success);"></i>
                           All programs assigned!
                        </div>
                    <?php else: ?>
                        <?php foreach ($sessionUnassignedPrograms as $uProg): ?>
                            <?php 
                            $progDuration = 0;
                            if ($uProg['start_time'] && $uProg['end_time']) {
                                $pStart = new DateTime($uProg['start_time']);
                                $pEnd = new DateTime($uProg['end_time']);
                                $progDuration = (int)(($pEnd->getTimestamp() - $pStart->getTimestamp()) / 60);
                            }
                            $classTier = admin_class_type_tier_from_name($uProg['class_type_name'] ?? '');
                            $tierClass = admin_class_type_badge_class($classTier);
                            $tierName = $classTier ? admin_class_type_tier_label($classTier) : 'General';
                            ?>
                            <div class="program-drag-card" 
                                 draggable="true"
                                 data-program-id="<?= (int)$uProg['id'] ?>"
                                 data-duration="<?= $progDuration ?>"
                                 data-tier="<?= $classTier ?: 'general' ?>"
                                 data-time="<?= $uProg['start_time'] ? e($uProg['start_time']) : '' ?>"
                                 style="background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.04); border-radius: 8px; padding: 10px 12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                <div style="min-width: 0;">
                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                        <strong class="prog-title" style="font-size: 13.0px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;"><?= e($uProg['title']) ?></strong>
                                        <span class="badge badge-neutral <?= $tierClass ?>" style="font-size: 9.0px; padding: 1px 5px; border-radius: 4px; font-weight: 800; transform: translateY(-0.5px);"><?= e($tierName) ?></span>
                                    </div>
                                    <span style="font-size: 11px; color: var(--muted); display: block; margin-top: 4px;">
                                        <i class="fa-solid fa-location-dot mr-1"></i> <?= e($uProg['stage_type_name'] ?: 'TBD') ?>
                                        <?php if ($uProg['start_time']): ?>
                                             • <?= e(date('h:i A', strtotime($uProg['start_time']))) ?> (<?= $progDuration ?>m)
                                         <?php endif; ?>
                                    </span>
                                </div>
                                <div style="display:flex; align-items:center;">
                                    <i class="fa-solid fa-grip-vertical" style="color:rgba(255,255,255,0.2); font-size:12px; cursor:grab;"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Section Modal -->
    <div class="modal-overlay" id="sectionModal">
        <div class="modal-box" style="padding:0;max-width:540px;width:100%;">
            <form method="POST" id="sectionForm">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" id="formAction" value="add_session">
                <input type="hidden" name="section_id" id="sectionId">

                <!-- Header -->
                <div class="sm-modal-header">
                    <button class="sm-modal-close" type="button" data-close="sectionModal" title="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <div class="sm-modal-title-wrap">
                        <div class="sm-modal-icon" id="modalIconEl">
                            <i class="fa-solid fa-calendar-plus"></i>
                        </div>
                        <div>
                            <div class="sm-modal-title" id="modalTitle">Add Session</div>
                            <div class="sm-modal-subtitle" id="modalSubtitle">Fill in the details to create a new session block</div>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="sm-modal-body">

                    <!-- Session Name -->
                    <div class="sm-field">
                        <label class="sm-label">
                            <i class="fa-solid fa-pen-nib"></i> Session Name <span class="sm-req">*</span>
                        </label>
                        <input type="text" class="sm-input" name="name" id="sectionName"
                               placeholder="e.g. Day 1 – Morning" required autocomplete="off">
                    </div>

                    <!-- Date + Sort Row -->
                    <div class="sm-field-row">
                        <div class="sm-field">
                            <label class="sm-label">
                                <i class="fa-solid fa-calendar-day"></i> Session Date <span class="sm-req">*</span>
                            </label>
                            <input type="date" class="sm-input" name="section_date" id="sectionDate"
                                   min="<?= e($activeEvent['start_date'] ?: '') ?>"
                                   max="<?= e($activeEvent['end_date'] ?: '') ?>" required>
                        </div>
                        <div class="sm-field">
                            <label class="sm-label">
                                <i class="fa-solid fa-arrow-up-9-1"></i> Sort Order
                            </label>
                            <input type="number" class="sm-input" name="sort_order" id="sectionSortOrder"
                                   value="0" min="0" step="1" placeholder="0">
                        </div>
                    </div>

                    <!-- Time Row -->
                    <div class="sm-field-row">
                        <div class="sm-field">
                            <label class="sm-label">
                                <i class="fa-solid fa-clock"></i> Start Time <span class="sm-req">*</span>
                            </label>
                            <input type="time" class="sm-input" name="start_time" id="sectionStartTime" required>
                        </div>
                        <div class="sm-field">
                            <label class="sm-label">
                                <i class="fa-solid fa-clock"></i> End Time <span class="sm-req">*</span>
                            </label>
                            <input type="time" class="sm-input" name="end_time" id="sectionEndTime" required>
                        </div>
                    </div>

                    <div class="sm-field-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        Sessions are sorted automatically by date &amp; start time.
                    </div>

                </div>

                <!-- Footer -->
                <div class="sm-modal-footer">
                    <button class="sm-btn-cancel" type="button" data-close="sectionModal">Cancel</button>
                    <button class="sm-btn-save" type="submit">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span id="saveSessionBtnText">Save Session</span>
                    </button>
                </div>

            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteSessionModal">
        <div class="modal-box modal-sm" style="border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a;">
            <div class="modal-header">
                <div class="modal-title">Delete Session</div>
                <button class="modal-close" type="button" data-close="deleteSessionModal"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="delete_session">
                <input type="hidden" name="section_id" id="deleteSessionId">
                <div style="padding: 20px;">
                    <p>Are you sure you want to delete <strong id="deleteSessionName">this session</strong>?</p>
                    <p class="muted mt-2 text-sm">Programs assigned to this session will be marked as unassigned. This action cannot be undone.</p>
                </div>
                <div class="form-actions" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end; gap: 10px;">
                    <button class="btn btn-secondary btn-md" type="button" data-close="deleteSessionModal">Cancel</button>
                    <button class="btn btn-danger btn-md" type="submit">Delete Session</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CSRF Token helper for JS -->
<div id="csrfTokenStorage" data-csrf="<?= e(generate_csrf_token()) ?>" style="display:none;"></div>



<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box modal-md" style="border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a;">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="scheduleModalTitle" style="font-size: 18px; font-weight: 800; color: #fff;">Schedule Program</div>
            </div>
            <button class="modal-close" type="button" data-close="scheduleModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="scheduleForm">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="schedule_program">
            
            <div class="form-grid" style="padding: 20px; gap: 16px;">
                <!-- PROGRAM SELECT DROP-DOWN (Shown when creating/scheduling) -->
                <div class="input-group full-width" id="modalProgramSelectGroup">
                    <label style="font-size: 12.5px; font-weight: 700;">Select Program <span class="required">*</span></label>
                    <select name="program_id" id="scheduleProgramSelect" class="form-input" required style="height: 42px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                        <option value="">-- Choose an Unscheduled Program --</option>
                        <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                            <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                            <?php if (!empty($tierProgs)): ?>
                                <optgroup label="<?= e($tierLabel) ?> Division">
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <?php
                                        $secNames = [];
                                        if (!empty($prog['allowed_sections'])) {
                                            $secIds = array_filter(array_map('intval', explode(',', $prog['allowed_sections'])));
                                            foreach ($secIds as $sid) {
                                                if (isset($classTypesMap[$sid])) {
                                                    $classTier = admin_class_type_tier_from_name($classTypesMap[$sid]);
                                                    $label = $classTier ? admin_class_type_tier_label($classTier) : $classTypesMap[$sid];
                                                    if ($label && !in_array($label, $secNames, true)) {
                                                        $secNames[] = $label;
                                                    }
                                                }
                                            }
                                        }
                                        $sectionDisplay = implode(' & ', $secNames);
                                        if ($sectionDisplay === '') {
                                            $classTier = admin_class_type_tier_from_name($prog['class_type_name'] ?? '');
                                            $sectionDisplay = $classTier ? admin_class_type_tier_label($classTier) : ($prog['class_type_name'] ?? '—');
                                        }
                                        ?>
                                        <option value="<?= (int)$prog['id'] ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" data-location="<?= e($prog['location'] ?? '') ?>"><?= e($prog['title']) ?> (<?= e($sectionDisplay) ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- STATIC DISPLAY (Shown when editing) -->
                <div class="input-group full-width" id="modalProgramStaticGroup" style="display: none;">
                    <label style="font-size: 12.5px; font-weight: 700;">Program</label>
                    <div id="scheduleProgramTitle" style="font-weight: 800; color: #38bdf8; font-size: 15px; padding: 12px 16px; background: rgba(56,189,248,0.08); border: 1px solid rgba(56,189,248,0.2); border-radius: 8px;"></div>
                    <input type="hidden" name="program_id" id="scheduleProgramId">
                </div>

                <div class="input-group full-width" id="modalStageGroup">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Stage Type <span class="required">*</span></label>
                            <select id="scheduleStageTypeFilter" class="form-input" required style="height: 42px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                                <option value="on_stage">On Stage (Normal Stage)</option>
                                <option value="off_stage">Off Stage</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700;">Specific Venue / Stage <span class="required">*</span></label>
                            <select name="stage_type_id" id="scheduleStageTypeId" class="form-input" required style="height: 42px; border-radius: 8px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #fff;">
                                <?php foreach ($stageTypes as $stage): ?>
                                    <option value="<?= (int)$stage['id'] ?>" data-category="<?= e($stage['category'] ?? 'on_stage') ?>" data-name="<?= e($stage['name']) ?>"><?= e($stage['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="input-group full-width">
                    <label style="font-size: 12.5px; font-weight: 700;">Location / Room</label>
                    <input type="text" name="location" id="scheduleLocation" placeholder="e.g. Main Auditorium, Stage 1" class="form-input" style="height: 40px; border-radius: 8px;">
                </div>
                <div class="input-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 12.5px; font-weight: 700; margin: 0;">Start Date & Time <span class="required">*</span></label>
                        <button type="button" id="scheduleUseNextSlotBtn" class="btn btn-secondary btn-xs" style="font-size: 10.5px; padding: 2px 8px; border-radius: 6px; font-weight: 700;"><i class="fa-solid fa-bolt" style="color: #facc15;"></i> Next Slot</button>
                    </div>
                    <input type="datetime-local" name="start_time" id="scheduleStartTime" class="form-input" required style="height: 40px; border-radius: 8px;">
                </div>
                <div class="input-group">
                    <label style="font-size: 12.5px; font-weight: 700;">Duration (Minutes)</label>
                    <input type="number" name="duration_minutes" id="scheduleDurationMinutes" min="1" placeholder="e.g. 30" class="form-input" value="30" style="height: 40px; border-radius: 8px;">
                    <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="15" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">15m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="30" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">30m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="45" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">45m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="60" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">60m</button>
                        <button type="button" class="btn btn-secondary btn-xs duration-preset-btn" data-mins="90" style="border-radius: 6px; font-weight: 700; padding: 4px 10px;">90m</button>
                    </div>
                </div>
                <div class="input-group full-width">
                    <label style="font-size: 12.5px; font-weight: 700;">End Date & Time</label>
                    <input type="datetime-local" name="end_time" id="scheduleEndTime" class="form-input" style="height: 40px; border-radius: 8px;">
                </div>
            </div>
            <div class="form-actions" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary btn-md" type="button" data-close="scheduleModal" style="border-radius: 8px;">Cancel</button>
                <button class="btn btn-success btn-md" type="submit" style="border-radius: 8px; font-weight: 700;">Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="unscheduledProgramsModal">
    <div class="modal-box modal-md" style="max-height: 85vh; display: flex; flex-direction: column; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a;">
        <div class="modal-header">
            <div>
                <div class="modal-title" style="display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 800; color: #fff;">
                    <i class="fa-solid fa-clock" style="color: var(--warning);"></i> Unscheduled Programs
                </div>
            </div>
            <button class="modal-close" type="button" data-close="unscheduledProgramsModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1;">
            <div style="position: relative; display: flex; align-items: center; margin-bottom: 16px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
                <input type="text" id="unscheduledSearchInput" placeholder="Search unscheduled programs..." class="form-input" style="padding-left: 36px; height: 40px; font-size: 13px; width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
            </div>

            <div class="unscheduled-accordion-container" style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($tiers as $tierKey => $tierLabel): ?>
                    <?php $tierProgs = $unscheduledGrouped[$tierKey] ?? []; ?>
                    <div class="accordion-item modal-accordion-item" data-tier="<?= $tierKey ?>">
                        <button class="accordion-header" type="button" style="width: 100%; text-align: left; padding: 12px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; color: #fff; font-weight: 700; font-size: 13.5px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: all 0.2s;">
                            <span><?= e($tierLabel) ?> <span class="tier-count" style="font-size: 11px; color: var(--muted); margin-left: 6px; font-weight: 600;">(<?= count($tierProgs) ?>)</span></span>
                            <i class="fa-solid fa-chevron-down accordion-icon" style="font-size: 11px; transition: transform 0.2s;"></i>
                        </button>
                        <div class="accordion-content" style="max-height: 0; overflow: hidden; transition: max-height 0.25s ease-out;">
                            <div style="padding: 10px 2px 0 2px; display: flex; flex-direction: column; gap: 10px;">
                                <?php if (empty($tierProgs)): ?>
                                    <div style="font-size: 12px; color: var(--muted); text-align: center; padding: 12px; background: rgba(0,0,0,0.1); border-radius: 8px;">No programs</div>
                                <?php else: ?>
                                    <?php foreach ($tierProgs as $prog): ?>
                                        <div class="unscheduled-card panel" data-title="<?= e($prog['title']) ?>" data-stage-type-id="<?= (int)($prog['stage_type_id'] ?? 0) ?>" style="padding: 12px 14px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; display: flex; flex-direction: column; gap: 10px;">
                                            <div style="min-width: 0; flex: 1;">
                                                <strong style="display: block; font-size: 14px; line-height: 1.3; color: #fff;" title="<?= e($prog['title']) ?>"><?= e($prog['title']) ?></strong>
                                            </div>
                                            <button class="btn btn-success btn-sm" type="button" data-schedule-btn='<?= e(json_encode($prog, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' style="width: 100%; justify-content: center; font-size: 12px; padding: 7px 12px; border-radius: 8px; font-weight: 700;"><i class="fa-solid fa-calendar-plus mr-1"></i> Schedule Program</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: flex-end;">
            <button class="btn btn-secondary btn-md" type="button" data-close="unscheduledProgramsModal" style="border-radius: 8px;">Close</button>
        </div>
    </div>
</div>
</div><!-- .main-content -->


<script>
const STAGE_TYPES = <?= json_encode($stageTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.ALL_PROGRAMS = <?= json_encode($allSessionProgramsPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const offStageMap = {
    <?php foreach ($stageTypes as $st): ?>
        '<?= (int)$st['id'] ?>': <?= (($st['category'] ?? '') === 'off_stage') ? 'true' : 'false' ?>,
    <?php endforeach; ?>
};
let currentActiveStageId = '<?= (int)($activeStageId ?: ($stageTypes[0]['id'] ?? 0)) ?>';

// Sync Specific Venue dropdown for Schedule Modal based on Category
function syncScheduleVenues(category, selectedVal = '') {
    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (!stageSelect) return;
    
    const targetVal = selectedVal || stageSelect.value;
    stageSelect.innerHTML = '<option value="">-- Select Venue --</option>';
    
    const filtered = STAGE_TYPES.filter(opt => !category || (opt.category || 'on_stage') === category);
    filtered.forEach(opt => {
        const o = document.createElement('option');
        o.value = opt.id;
        o.textContent = opt.name;
        o.setAttribute('data-category', opt.category || 'on_stage');
        o.setAttribute('data-name', opt.name);
        if (String(opt.id) === String(targetVal)) {
            o.selected = true;
        }
        stageSelect.appendChild(o);
    });
}


// Set stage helper for Schedule Modal (updates Stage Type and syncs venue)
function setScheduleStage(stageId) {
    const filterSelect = document.getElementById('scheduleStageTypeFilter');
    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (!stageSelect) return;

    const found = STAGE_TYPES.find(opt => String(opt.id) === String(stageId));
    const category = found ? (found.category || 'on_stage') : 'on_stage';

    if (filterSelect) {
        filterSelect.value = category;
    }
    syncScheduleVenues(category, stageId);
}

function updateActiveStage(stageId) {
    currentActiveStageId = String(stageId);

    // 1. Update Stage Tab buttons active state
    document.querySelectorAll('.stage-tab-btn').forEach(btn => {
        const isTarget = btn.dataset.stageTab === currentActiveStageId;
        btn.classList.toggle('active', isTarget);
        btn.style.background = isTarget ? 'linear-gradient(135deg, rgba(99,102,241,0.25), rgba(168,85,247,0.25))' : 'rgba(255,255,255,0.03)';
        btn.style.color = isTarget ? '#fff' : 'var(--muted)';
        btn.style.borderColor = isTarget ? 'rgba(99,102,241,0.5)' : 'rgba(255,255,255,0.08)';
    });

    // 2. Toggle Stage Panels
    document.querySelectorAll('.stage-panel-item').forEach(panel => {
        panel.style.display = (panel.dataset.stageId === currentActiveStageId) ? '' : 'none';
    });

    // 3. Filter Sidebar Unscheduled Cards
    filterSidebarUnscheduled();

    // 4. Filter Modal Program Select Dropdown Options
    filterModalProgramOptions();
}

function filterSidebarUnscheduled() {
    const query = sidebarUnscheduledSearch ? sidebarUnscheduledSearch.value.toLowerCase().trim() : '';
    let totalVisible = 0;

    document.querySelectorAll('.sidebar-accordion-item').forEach(item => {
        let matchCount = 0;
        const cards = item.querySelectorAll('.draggable-program-card');
        const content = item.querySelector('.accordion-content');
        const icon = item.querySelector('.accordion-icon');
        const countSpan = item.querySelector('.tier-count');

        cards.forEach(card => {
            const title = card.dataset.title.toLowerCase();
            const cardStage = card.dataset.stageTypeId || '1';

            const matchesStage = (String(cardStage) === String(currentActiveStageId));
            const matchesQuery = (query === '') || title.includes(query);

            if (matchesStage && matchesQuery) {
                card.style.display = '';
                matchCount++;
                totalVisible++;
            } else {
                card.style.display = 'none';
            }
        });

        if (countSpan) {
            countSpan.textContent = '(' + matchCount + ')';
        }

        if (query !== '' && matchCount > 0) {
            item.classList.add('is-open');
            if (content) content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
            item.style.display = '';
        } else if (query !== '' && matchCount === 0) {
            item.style.display = 'none';
        } else {
            item.style.display = '';
            if (!item.classList.contains('is-open')) {
                if (content) content.style.maxHeight = '0';
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        }
    });

    const headerCount = document.getElementById('sidebarUnscheduledCount');
    if (headerCount) {
        headerCount.textContent = totalVisible;
    }
    const topbarBadge = document.getElementById('topbarUnscheduledBadge');
    if (topbarBadge) {
        topbarBadge.textContent = totalVisible;
    }
}

function filterModalProgramOptions() {
    const selectEl = document.getElementById('scheduleProgramSelect');
    const stageSelect = document.getElementById('scheduleStageTypeId');
    const selectedStage = stageSelect ? stageSelect.value : currentActiveStageId;

    if (selectEl) {
        Array.from(selectEl.options).forEach(opt => {
            if (!opt.value) return;
            const optStage = opt.dataset.stageTypeId || '1';
            const matchesStage = (String(optStage) === String(selectedStage));
            opt.disabled = !matchesStage;
            opt.style.display = matchesStage ? '' : 'none';
        });
    }
}

// Register event listeners on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {

    // Stage Type change listener in scheduleModal
    document.getElementById('scheduleStageTypeFilter')?.addEventListener('change', (e) => {
        syncScheduleVenues(e.target.value);
        const stageSelect = document.getElementById('scheduleStageTypeId');
        if (stageSelect) {
            const locName = stageSelect.options[stageSelect.selectedIndex]?.getAttribute('data-name') || '';
            document.getElementById('scheduleLocation').value = locName;
        }
        filterModalProgramOptions();
        applyNextAvailableSlotForStage();
    });

    // Stage Select dropdown change listener in scheduleModal
    document.getElementById('scheduleStageTypeId')?.addEventListener('change', (e) => {
        const locName = e.target.options[e.target.selectedIndex]?.getAttribute('data-name') || '';
        document.getElementById('scheduleLocation').value = locName;
        filterModalProgramOptions();
        applyNextAvailableSlotForStage();
    });
});


function applyNextAvailableSlotForStage() {
    const stageId = document.getElementById('scheduleStageTypeId').value;
    const targetPanel = document.querySelector(`.stage-panel-item[data-stage-id="${stageId}"]`);
    if (targetPanel && targetPanel.dataset.lastEndAt) {
        document.getElementById('scheduleStartTime').value = toLocalDatetime(targetPanel.dataset.lastEndAt);
    } else {
        document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
    }
    updateScheduleEndTime();
}

document.getElementById('scheduleUseNextSlotBtn')?.addEventListener('click', () => {
    applyNextAvailableSlotForStage();
});

// Stage Tabs Switching
document.querySelectorAll('.stage-tab-btn').forEach(tabBtn => {
    tabBtn.addEventListener('click', () => {
        updateActiveStage(tabBtn.dataset.stageTab);
    });
});

// Accordion Toggles
document.querySelectorAll('.accordion-header').forEach(header => {
    header.addEventListener('click', () => {
        const item = header.parentElement;
        const content = item.querySelector('.accordion-content');
        const icon = header.querySelector('.accordion-icon');
        const isOpen = item.classList.toggle('is-open');
        
        if (isOpen) {
            content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            content.style.maxHeight = '0';
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    });
});

// Sidebar Unscheduled Search
const sidebarUnscheduledSearch = document.getElementById('sidebarUnscheduledSearch');
sidebarUnscheduledSearch?.addEventListener('input', () => {
    filterSidebarUnscheduled();
});

// Modal Unscheduled Search Fix
const unscheduledSearchInput = document.getElementById('unscheduledSearchInput');
unscheduledSearchInput?.addEventListener('input', () => {
    const query = unscheduledSearchInput.value.toLowerCase().trim();
    document.querySelectorAll('.modal-accordion-item').forEach(item => {
        let matchCount = 0;
        const cards = item.querySelectorAll('.unscheduled-card');
        const content = item.querySelector('.accordion-content');
        const icon = item.querySelector('.accordion-icon');

        cards.forEach(card => {
            const title = card.dataset.title.toLowerCase();
            if (query === '' || title.includes(query)) {
                card.style.display = '';
                matchCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (query !== '' && matchCount > 0) {
            item.classList.add('is-open');
            if (content) content.style.maxHeight = content.scrollHeight + 'px';
            if (icon) icon.style.transform = 'rotate(180deg)';
            item.style.display = '';
        } else if (query !== '' && matchCount === 0) {
            item.style.display = 'none';
        } else {
            item.style.display = '';
        }
    });
});

// Run stage filter on initial load
document.addEventListener('DOMContentLoaded', () => {
    updateActiveStage(currentActiveStageId);
});

// HTML5 Drag and Drop for Unscheduled Programs
let currentDraggedProgramData = null;

document.querySelectorAll('.draggable-program-card[draggable="true"]').forEach(card => {
    card.addEventListener('dragstart', (e) => {
        try {
            currentDraggedProgramData = JSON.parse(card.dataset.programJson);
        } catch(err) {
            currentDraggedProgramData = null;
        }
        e.dataTransfer.setData('text/plain', card.dataset.programJson || '');
        card.style.opacity = '0.5';
    });
    card.addEventListener('dragend', () => {
        card.style.opacity = '1';
    });
});

document.querySelectorAll('.stage-panel-item').forEach(stagePanel => {
    stagePanel.addEventListener('dragover', (e) => {
        e.preventDefault();
        stagePanel.style.borderColor = '#38bdf8';
        stagePanel.style.background = 'rgba(56,189,248,0.06)';
    });
    stagePanel.addEventListener('dragleave', () => {
        stagePanel.style.borderColor = 'rgba(255,255,255,0.08)';
        stagePanel.style.background = 'rgba(15,23,42,0.4)';
    });
    stagePanel.addEventListener('drop', (e) => {
        e.preventDefault();
        stagePanel.style.borderColor = 'rgba(255,255,255,0.08)';
        stagePanel.style.background = 'rgba(15,23,42,0.4)';
        
        let p = currentDraggedProgramData;
        if (!p) {
            try {
                const raw = e.dataTransfer.getData('text/plain');
                if (raw) p = JSON.parse(raw);
            } catch(err) {}
        }
        
        if (!p || !p.id) {
            return;
        }

        // Reset form cleanly
        document.getElementById('scheduleForm').reset();
        document.getElementById('scheduleModalTitle').textContent = 'Schedule Program';

        const submitBtn = document.querySelector('#scheduleForm button[type="submit"]');
        if (submitBtn) {
            submitBtn.style.pointerEvents = '';
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Save Schedule';
        }

        // Show static program display (no dropdown needed — user already picked via drag)
        document.getElementById('modalProgramSelectGroup').style.display = 'none';
        const selectEl = document.getElementById('scheduleProgramSelect');
        selectEl.disabled = true;
        selectEl.required = false;
        selectEl.name = 'program_id_select_unused';

        document.getElementById('modalProgramStaticGroup').style.display = '';
        document.getElementById('scheduleProgramTitle').textContent = p.title || ('Program #' + p.id);
        const hiddenEl = document.getElementById('scheduleProgramId');
        hiddenEl.disabled = false;
        hiddenEl.name = 'program_id';
        hiddenEl.value = p.id;

        // Always show Stage/Venue field
        const stageGroup = document.getElementById('modalStageGroup');
        if (stageGroup) stageGroup.style.display = '';
        const stageSelectEl = document.getElementById('scheduleStageTypeId');
        stageSelectEl.disabled = false;
        stageSelectEl.required = true;

        // Set the stage from the drop target panel (stageId == stage_type_id)
        const stageId = stagePanel.dataset.stageId;
        if (stageId) {
            setScheduleStage(stageId);
        } else if (p.stage_type_id) {
            setScheduleStage(p.stage_type_id);
        }

        // Set location from program data
        if (p.location) {
            document.getElementById('scheduleLocation').value = p.location;
        }

        // Set start time to the next available slot for this stage
        const lastEndAt = stagePanel.dataset.lastEndAt;
        if (lastEndAt) {
            document.getElementById('scheduleStartTime').value = toLocalDatetime(lastEndAt);
        } else {
            document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
        }

        document.getElementById('scheduleDurationMinutes').value = '30';
        updateScheduleEndTime();

        openModal('scheduleModal');
    });
});

// Duration Presets & End Time Calculation
document.querySelectorAll('.duration-preset-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('scheduleDurationMinutes').value = btn.dataset.mins;
        updateScheduleEndTime();
    });
});

function formatLocalDatetime(date) {
    const tzoffset = date.getTimezoneOffset() * 60000;
    const localISOTime = (new Date(date.getTime() - tzoffset)).toISOString().slice(0, 16);
    return localISOTime;
}

function toLocalDatetime(dbDate) {
    if (!dbDate) return '';
    const date = new Date(dbDate.replace(' ', 'T'));
    if (isNaN(date.getTime())) return '';
    return formatLocalDatetime(date);
}

function updateScheduleEndTime() {
    const startVal = document.getElementById('scheduleStartTime').value;
    const duration = Number(document.getElementById('scheduleDurationMinutes').value);
    if (!startVal || !duration || duration <= 0) return;
    const startDate = new Date(startVal);
    if (isNaN(startDate.getTime())) return;
    startDate.setMinutes(startDate.getMinutes() + duration);
    document.getElementById('scheduleEndTime').value = formatLocalDatetime(startDate);
}

function updateScheduleDurationFromEnd() {
    const startVal = document.getElementById('scheduleStartTime').value;
    const endVal = document.getElementById('scheduleEndTime').value;
    if (!startVal || !endVal) return;
    const startDate = new Date(startVal);
    const endDate = new Date(endVal);
    if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) return;
    const diffMins = Math.round((endDate - startDate) / 60000);
    if (diffMins > 0) {
        document.getElementById('scheduleDurationMinutes').value = diffMins;
    }
}

document.getElementById('scheduleStartTime')?.addEventListener('change', updateScheduleEndTime);
document.getElementById('scheduleDurationMinutes')?.addEventListener('input', updateScheduleEndTime);
document.getElementById('scheduleEndTime')?.addEventListener('change', updateScheduleDurationFromEnd);

// Client-side Timeline search and filter
const timelineSearch = document.getElementById('timelineSearch');
const timelineSectionFilter = document.getElementById('timelineSectionFilter');
const timelineItems = document.querySelectorAll('.timeline-item-container');
const timelineGaps = document.querySelectorAll('.timeline-gap-container');

function filterTimeline() {
    const searchVal = timelineSearch.value.toLowerCase().trim();
    const sectionVal = timelineSectionFilter.value;
    const isFiltering = searchVal !== '' || sectionVal !== 'all';
    
    timelineItems.forEach(item => {
        const title = item.dataset.title.toLowerCase();
        const location = item.dataset.location.toLowerCase();
        const section = item.dataset.section;
        
        const matchesSearch = title.includes(searchVal) || location.includes(searchVal);
        const matchesSection = (sectionVal === 'all') || (section === sectionVal);
        
        if (matchesSearch && matchesSection) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
    
    // Hide/show parallel group container if all items inside are hidden
    document.querySelectorAll('.parallel-programs-group').forEach(group => {
        const hasVisible = Array.from(group.querySelectorAll('.timeline-item-container')).some(item => item.style.display !== 'none');
        group.style.display = hasVisible ? '' : 'none';
    });
    
    timelineGaps.forEach(gap => {
        gap.style.display = isFiltering ? 'none' : '';
    });
}

timelineSearch?.addEventListener('input', filterTimeline);
timelineSectionFilter?.addEventListener('change', filterTimeline);

// Modal helpers
// Move all modal overlays to <body> to escape any CSS stacking context trap
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal-overlay').forEach(el => {
        if (el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    });
});

function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active');
    // Reset scroll so the modal box is always visible at the top
    el.scrollTop = 0;
}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));

// Automatically close modal when submitting any form inside modal
document.querySelectorAll('.modal-overlay form').forEach(form => {
    form.addEventListener('submit', () => {
        const modalOverlay = form.closest('.modal-overlay');
        if (modalOverlay && modalOverlay.id) {
            closeModal(modalOverlay.id);
        }
    });
});



// Auto-fill location and venue stage when selecting a program in dropdown
document.getElementById('scheduleProgramSelect')?.addEventListener('change', (e) => {
    const opt = e.target.options[e.target.selectedIndex];
    if (opt && opt.dataset) {
        if (opt.dataset.location !== undefined && opt.dataset.location !== '') {
            document.getElementById('scheduleLocation').value = opt.dataset.location;
        }
        if (opt.dataset.stageTypeId && Number(opt.dataset.stageTypeId) > 0) {
            setScheduleStage(opt.dataset.stageTypeId);
            applyNextAvailableSlotForStage();
        }
    }
});

function applyNextAvailableSlotForStage() {
    const stageSelect = document.getElementById('scheduleStageTypeId');
    const stageId = stageSelect ? stageSelect.value : currentActiveStageId;
    const stagePanel = document.querySelector(`.stage-panel-item[data-stage-id="${stageId}"]`);
    
    if (stagePanel && stagePanel.dataset.lastEndAt) {
        document.getElementById('scheduleStartTime').value = toLocalDatetime(stagePanel.dataset.lastEndAt);
    } else {
        document.getElementById('scheduleStartTime').value = formatLocalDatetime(new Date());
    }
    document.getElementById('scheduleDurationMinutes').value = '30';
    updateScheduleEndTime();
}

document.getElementById('scheduleUseNextSlotBtn')?.addEventListener('click', applyNextAvailableSlotForStage);


function filterModalProgramOptions() {
    const selectEl = document.getElementById('scheduleProgramSelect');
    const stageSelect = document.getElementById('scheduleStageTypeId');
    if (!selectEl || !stageSelect) return;
    
    const selectedStage = stageSelect.value;
    const options = Array.from(selectEl.options);
    
    options.forEach(opt => {
        if (!opt.value) return;
        opt.disabled = false;
        opt.style.display = '';
    });
}

// Modal Form configurations (Toggles select dropdown vs static display)
function setModalCreateMode() {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Schedule Program';
    
    const submitBtn = document.querySelector('#scheduleForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save Schedule';
    }
    
    document.getElementById('modalProgramSelectGroup').style.display = '';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = false;
    selectEl.required = true;
    selectEl.name = 'program_id';
    
    document.getElementById('modalProgramStaticGroup').style.display = 'none';
    const hiddenEl = document.getElementById('scheduleProgramId');
    hiddenEl.disabled = true;
    hiddenEl.name = 'program_id_hidden';
    
    setScheduleStage(currentActiveStageId || '<?= $stageTypes ? (int)$stageTypes[0]['id'] : '' ?>');
    document.getElementById('scheduleLocation').value = '';
    filterModalProgramOptions();
}

function setModalEditMode(p) {
    document.getElementById('scheduleForm').reset();
    document.getElementById('scheduleModalTitle').textContent = 'Edit Program Schedule';
    
    const submitBtn = document.querySelector('#scheduleForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Save Schedule';
    }
    
    document.getElementById('modalProgramSelectGroup').style.display = 'none';
    const selectEl = document.getElementById('scheduleProgramSelect');
    selectEl.disabled = true;
    selectEl.required = false;
    selectEl.name = 'program_id_disabled';
    
    document.getElementById('modalProgramStaticGroup').style.display = '';
    document.getElementById('scheduleProgramTitle').textContent = p.title;
    const hiddenEl = document.getElementById('scheduleProgramId');
    hiddenEl.disabled = false;
    hiddenEl.value = p.id;
    hiddenEl.name = 'program_id';
    
    const stageGroupEl = document.getElementById('modalStageGroup');
    if (stageGroupEl) stageGroupEl.style.display = '';
    document.getElementById('scheduleStageTypeId').disabled = false;
    document.getElementById('scheduleStageTypeId').required = true;
    setScheduleStage(p.stage_type_id || '');
    document.getElementById('scheduleLocation').value = p.location || '';
    document.getElementById('scheduleStartTime').value = toLocalDatetime(p.start_at);
    document.getElementById('scheduleEndTime').value = toLocalDatetime(p.end_at);
    
    if (p.start_at && p.end_at) {
        const start = new Date(toLocalDatetime(p.start_at));
        const end = new Date(toLocalDatetime(p.end_at));
        const diff = Math.round((end - start) / 60000);
        document.getElementById('scheduleDurationMinutes').value = diff > 0 ? diff : '';
    }
}

// 1. Click main button
document.getElementById('scheduleNewProgramBtn')?.addEventListener('click', () => {
    setModalCreateMode();
    const selectEl = document.getElementById('scheduleProgramSelect');
    if (selectEl) {
        selectEl.dispatchEvent(new Event('change'));
    }
    applyNextAvailableSlotForStage();
    openModal('scheduleModal');
});

// 1.5. Click Unscheduled programs modal button
document.getElementById('openUnscheduledModalBtn')?.addEventListener('click', () => {
    openModal('unscheduledProgramsModal');
});

// 2. Click Schedule card action button
document.querySelectorAll('[data-schedule-btn]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.scheduleBtn);
    closeModal('unscheduledProgramsModal');
    setModalCreateMode();
    
    if (p.stage_type_id) {
        setScheduleStage(p.stage_type_id);
    }
    filterModalProgramOptions();
    
    const selectEl = document.getElementById('scheduleProgramSelect');
    if (selectEl && p && p.id) {
        const targetOpt = selectEl.querySelector(`option[value="${p.id}"]`);
        if (targetOpt) {
            targetOpt.disabled = false;
            targetOpt.style.display = '';
        }
        selectEl.value = String(p.id);
    }

    if (p.location) {
        document.getElementById('scheduleLocation').value = p.location;
    }
    
    applyNextAvailableSlotForStage();
    openModal('scheduleModal');
}));

// 3. Click Edit program schedule button
document.querySelectorAll('[data-edit-schedule-btn]').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.editScheduleBtn);
    setModalEditMode(p);
    openModal('scheduleModal');
}));

// Submit handlers to close modals immediately and show saving progress
document.getElementById('scheduleForm')?.addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.style.pointerEvents = 'none';
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
    }
    setTimeout(() => closeModal('scheduleModal'), 50);
});

// ==========================================
// UNIFIED TABS SWITCHING & PERSISTENCE
// ==========================================
document.querySelectorAll('.tab-trigger').forEach(trigger => {
    trigger.addEventListener('click', () => {
        const target = trigger.dataset.target;
        
        // Update trigger styles
        document.querySelectorAll('.tab-trigger').forEach(btn => {
            btn.classList.remove('active');
            btn.style.color = 'var(--muted)';
            btn.style.borderBottomColor = 'transparent';
        });
        trigger.classList.add('active');
        trigger.style.color = '#fff';
        if (target === 'timelineTab') {
            trigger.style.borderBottomColor = '#6366f1';
        } else {
            trigger.style.borderBottomColor = '#a78bfa';
        }
        
        // Show/hide content panels
        document.getElementById('timelineTabContent').style.display = (target === 'timelineTab') ? '' : 'none';
        document.getElementById('sessionsTabContent').style.display = (target === 'sessionsTab') ? '' : 'none';
        
        // Persist tab
        localStorage.setItem('activeScheduleTab', target);
    });
});

// Restore persisted tab on page load
document.addEventListener('DOMContentLoaded', () => {
    const activeTab = localStorage.getItem('activeScheduleTab') || 'timelineTab';
    const targetTrigger = document.querySelector(`.tab-trigger[data-target="${activeTab}"]`);
    if (targetTrigger) {
        targetTrigger.click();
    }
});

// ==========================================
// SESSIONS DRAG-AND-DROP & ACTION HANDLERS
// ==========================================
document.addEventListener('click', (e) => {
    const closeBtn = e.target.closest('[data-close]');
    if (closeBtn) {
        closeModal(closeBtn.dataset.close);
        return;
    }

    const addBtn = e.target.closest('[data-open-add]');
    if (addBtn) {
        document.getElementById('modalTitle').textContent = 'Add Session';
        document.getElementById('modalSubtitle').textContent = 'Fill in the details to create a new session block';
        document.getElementById('modalIconEl').innerHTML = '<i class="fa-solid fa-calendar-plus"></i>';
        document.getElementById('saveSessionBtnText').textContent = 'Save Session';
        document.getElementById('formAction').value = 'add_session';
        document.getElementById('sectionId').value = '';
        document.getElementById('sectionName').value = '';
        document.getElementById('sectionStartTime').value = '08:00';
        document.getElementById('sectionEndTime').value = '13:00';
        document.getElementById('sectionDate').value = '';
        document.getElementById('sectionSortOrder').value = '0';
        openModal('sectionModal');
        return;
    }

    const editBtn = e.target.closest('[data-edit-section]');
    if (editBtn) {
        try {
            const s = JSON.parse(editBtn.dataset.editSection);
            document.getElementById('modalTitle').textContent = 'Edit Session';
            document.getElementById('modalSubtitle').textContent = 'Update the details for this session block';
            document.getElementById('modalIconEl').innerHTML = '<i class="fa-solid fa-pen-to-square"></i>';
            document.getElementById('saveSessionBtnText').textContent = 'Update Session';
            document.getElementById('formAction').value = 'update_session';
            document.getElementById('sectionId').value = s.id || '';
            document.getElementById('sectionName').value = s.name || '';
            
            const formatTime = (timeStr) => {
                if (!timeStr) return '';
                const parts = timeStr.split(':');
                return parts.slice(0, 2).join(':');
            };
            
            document.getElementById('sectionStartTime').value = formatTime(s.start_time);
            document.getElementById('sectionEndTime').value = formatTime(s.end_time);
            document.getElementById('sectionDate').value = s.section_date || '';
            document.getElementById('sectionSortOrder').value = s.sort_order || '0';

            openModal('sectionModal');
        } catch (err) {
            console.error('Error parsing session metadata:', err);
        }
        return;
    }

    const deleteBtn = e.target.closest('[data-delete-id]');
    if (deleteBtn) {
        document.getElementById('deleteSessionId').value = deleteBtn.dataset.deleteId;
        document.getElementById('deleteSessionName').textContent = deleteBtn.dataset.deleteName || 'this session';
        openModal('deleteSessionModal');
        return;
    }

    const unassignBtn = e.target.closest('.unassign-btn');
    if (unassignBtn) {
        const card = unassignBtn.closest('.program-drag-card');
        const programId = unassignBtn.dataset.unassignId || (card ? card.dataset.programId : null);
        if (programId) {
            const sourceZone = card ? card.closest('.session-drop-zone') : null;
            const unassignedZone = document.getElementById('unassignedList');
            ajaxUnassignProgram(programId, unassignedZone, sourceZone);
        }
        return;
    }
});

// Toast Notification System
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) {
        // Create if not exists
        const c = document.createElement('div');
        c.id = 'toast-container';
        c.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 999999; display: flex; flex-direction: column; gap: 10px;';
        document.body.appendChild(c);
    }
    const targetContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${escapeHtml(message)}</span>`;
    
    targetContainer.appendChild(toast);
    
    // Force reflow
    toast.offsetHeight;
    toast.classList.add('active');
    
    setTimeout(() => {
        toast.classList.remove('active');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function escapeHtml(value) {
    return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}

// Day Tab Filters & Live Search
const dayButtons = document.querySelectorAll('.day-tab-btn');
const searchInput = document.getElementById('sessionsSearch');
const sessionCards = document.querySelectorAll('.session-card');

let currentDayFilter = 'all';
let currentSearchQuery = '';

function filterSessions() {
    sessionCards.forEach(card => {
        const cardDay = card.dataset.day;
        const matchesDay = (currentDayFilter === 'all') || 
                           (currentDayFilter === 'undated' && cardDay === 'undated') || 
                           (cardDay === currentDayFilter);

        let matchesSearch = true;
        if (currentSearchQuery !== '') {
            let programFound = false;
            card.querySelectorAll('.prog-title').forEach(title => {
                if (title.textContent.toLowerCase().includes(currentSearchQuery)) {
                    programFound = true;
                    title.style.background = 'rgba(250, 204, 21, 0.2)';
                } else {
                    title.style.background = 'none';
                }
            });
            const secName = card.querySelector('h4').textContent.toLowerCase();
            if (secName.includes(currentSearchQuery)) {
                programFound = true;
            }
            matchesSearch = programFound;
        } else {
            card.querySelectorAll('.prog-title').forEach(title => title.style.background = 'none');
        }

        if (matchesDay && matchesSearch) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

dayButtons.forEach(btn => {
    btn.addEventListener('click', () => {
        dayButtons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentDayFilter = btn.dataset.day;
        filterSessions();
    });
});

searchInput?.addEventListener('input', () => {
    currentSearchQuery = searchInput.value.trim().toLowerCase();
    
    // Filter unassigned sidebar
    const unassignedCards = document.querySelectorAll('#unassignedList .program-drag-card');
    unassignedCards.forEach(card => {
        const title = card.querySelector('.prog-title').textContent.toLowerCase();
        if (currentSearchQuery === '' || title.includes(currentSearchQuery)) {
            card.style.display = 'flex';
            if (currentSearchQuery !== '') {
                card.querySelector('.prog-title').style.background = 'rgba(250, 204, 21, 0.2)';
            } else {
                card.querySelector('.prog-title').style.background = 'none';
            }
        } else {
            card.style.display = 'none';
        }
    });

    filterSessions();
});

// HTML5 Drag & Drop Delegation & AJAX Handlers
const csrfTokenStorage = document.getElementById('csrfTokenStorage');
const csrfToken = csrfTokenStorage ? csrfTokenStorage.dataset.csrf : '';
let draggedElement = null;

document.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.program-drag-card');
    if (card) {
        draggedElement = card;
        card.classList.add('dragging');
        e.dataTransfer.setData('text/plain', card.dataset.programId);
        e.dataTransfer.effectAllowed = 'move';
    }
});

document.addEventListener('dragend', (e) => {
    const card = e.target.closest('.program-drag-card');
    if (card) {
        card.classList.remove('dragging');
        draggedElement = null;
    }
});

const dropZones = document.querySelectorAll('.session-drop-zone');
dropZones.forEach(zone => {
    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });

    zone.addEventListener('dragenter', () => {
        zone.classList.add('drag-over');
    });

    zone.addEventListener('dragleave', () => {
        zone.classList.remove('drag-over');
    });

    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        
        const programId = e.dataTransfer.getData('text/plain');
        const targetSectionId = zone.dataset.dropSectionId;

        if (!draggedElement || draggedElement.dataset.programId !== programId) return;

        const sourceZone = draggedElement.closest('.session-drop-zone');
        const sourceSectionId = sourceZone ? sourceZone.dataset.dropSectionId : null;

        if (sourceSectionId === targetSectionId) return;

        if (targetSectionId === '0') {
            ajaxUnassignProgram(programId, zone, sourceZone);
        } else {
            ajaxAssignProgram(programId, targetSectionId, zone, sourceZone);
        }
    });
});

function ajaxAssignProgram(programId, sectionId, targetZone, sourceZone) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'assign_program');
    formData.append('program_id', programId);
    formData.append('section_id', sectionId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            moveProgramDOM(programId, targetZone, sourceZone, data.program);
            showToast(data.message, 'success');
        } else {
            showToast(data.error || 'Assignment failed.', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Connection error. Could not assign program.', 'error');
    });
}

function ajaxUnassignProgram(programId, targetZone, sourceZone) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'unassign_program');
    formData.append('program_id', programId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            moveProgramDOM(programId, targetZone, sourceZone, data.program, true);
            showToast(data.message, 'success');
        } else {
            showToast(data.error || 'Unassignment failed.', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Connection error. Could not unassign program.', 'error');
    });
}

function sortProgramList(container) {
    const cards = Array.from(container.querySelectorAll('.program-drag-card'));
    if (cards.length <= 1) return;

    cards.sort((a, b) => {
        const timeA = a.getAttribute('data-time') || '';
        const timeB = b.getAttribute('data-time') || '';
        
        if (timeA && timeB) {
            return timeA.localeCompare(timeB);
        }
        if (timeA) return -1;
        if (timeB) return 1;
        
        const titleA = a.querySelector('.prog-title').textContent.trim().toLowerCase();
        const titleB = b.querySelector('.prog-title').textContent.trim().toLowerCase();
        return titleA.localeCompare(titleB);
    });

    cards.forEach(card => container.appendChild(card));
}

function moveProgramDOM(programId, targetZone, sourceZone, programData, toUnassigned = false) {
    let card = document.querySelector(`.program-drag-card[data-program-id="${programId}"]`);
    
    const targetEmptyPlaceholder = targetZone.querySelector('.empty-sec-placeholder');
    if (targetEmptyPlaceholder) targetEmptyPlaceholder.remove();
    const targetAllAssignedMsg = targetZone.querySelector('.all-assigned-msg');
    if (targetAllAssignedMsg) targetAllAssignedMsg.remove();

    const targetList = targetZone.querySelector('.assigned-list') || targetZone;
    const tier = programData.class_tier || 'general';
    
    let badgeClass = 'badge-neutral';
    let tierLabel = 'General';
    if (tier === 'senior') { badgeClass = 'badge-primary'; tierLabel = 'Senior'; }
    else if (tier === 'junior') { badgeClass = 'badge-info'; tierLabel = 'Junior'; }
    else if (tier === 'subjunior') { badgeClass = 'badge-success'; tierLabel = 'Sub Junior'; }

    if (toUnassigned) {
        const newCard = document.createElement('div');
        newCard.className = 'program-drag-card';
        newCard.setAttribute('draggable', 'true');
        newCard.setAttribute('data-program-id', programId);
        newCard.setAttribute('data-duration', programData.duration);
        newCard.setAttribute('data-tier', tier);
        newCard.setAttribute('data-time', programData.start_time || '');
        newCard.style.padding = '10px 12px';
        newCard.style.display = 'flex';
        newCard.style.justifyContent = 'space-between';
        newCard.style.alignItems = 'center';
        newCard.style.gap = '8px';

        newCard.innerHTML = `
            <div style="min-width: 0;">
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <strong class="prog-title" style="font-size: 13.0px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;">${escapeHtml(programData.title)}</strong>
                    <span class="badge badge-neutral ${badgeClass}" style="font-size: 9.0px; padding: 1px 5px; border-radius: 4px; font-weight: 800; transform: translateY(-0.5px);">${escapeHtml(tierLabel)}</span>
                </div>
                <span style="font-size: 11px; color: var(--muted); display: block; margin-top: 4px;">
                    <i class="fa-solid fa-location-dot mr-1"></i> ${escapeHtml(programData.stage)}
                    ${programData.time ? `• ${programData.time} (${programData.duration}m)` : ''}
                </span>
            </div>
            <div style="display:flex; align-items:center;">
                <i class="fa-solid fa-grip-vertical" style="color:rgba(255,255,255,0.2); font-size:12px; cursor:grab;"></i>
            </div>
        `;
        targetList.appendChild(newCard);
        if (card) card.remove();
    } else {
        const newCard = document.createElement('div');
        newCard.className = 'program-drag-card';
        newCard.setAttribute('draggable', 'true');
        newCard.setAttribute('data-program-id', programId);
        newCard.setAttribute('data-duration', programData.duration);
        newCard.setAttribute('data-tier', tier);
        newCard.setAttribute('data-time', programData.start_time || '');
        newCard.style.display = 'flex';
        newCard.style.justifyContent = 'space-between';
        newCard.style.alignItems = 'center';
        newCard.style.padding = '8px 12px';
        newCard.style.gap = '8px';

        newCard.innerHTML = `
            <div style="min-width: 0;">
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <strong class="prog-title" style="font-size: 13.0px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;">${escapeHtml(programData.title)}</strong>
                    <span class="badge badge-neutral ${badgeClass}" style="font-size: 9.0px; padding: 1px 5px; border-radius: 4px; font-weight: 800; transform: translateY(-0.5px);">${escapeHtml(tierLabel)}</span>
                </div>
                <span style="font-size: 11px; color: var(--muted); display: block; margin-top: 4px;">
                    <i class="fa-solid fa-location-dot mr-1"></i> ${escapeHtml(programData.stage)}
                    ${programData.time ? `• ${programData.time} (${programData.duration}m)` : ''}
                </span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-grip-vertical mr-1" style="color:rgba(255,255,255,0.2); font-size:12px; cursor:grab;"></i>
                <button type="button" class="btn btn-link btn-sm unassign-btn" data-unassign-id="${programId}" style="color: var(--danger, #ef4444); padding:4px;" title="Unassign">
                    <i class="fa-solid fa-xmark" style="font-size: 14px;"></i>
                </button>
            </div>
        `;
        targetList.appendChild(newCard);
        if (card) card.remove();
    }

    // Auto-sort list chronologically
    sortProgramList(targetList);

    if (sourceZone) {
        const sourceList = sourceZone.querySelector('.assigned-list') || sourceZone;
        if (sourceList.children.length === 0) {
            if (sourceZone.id === 'unassignedList') {
                sourceZone.innerHTML = `
                    <div class="all-assigned-msg" style="text-align: center; color: var(--success); padding: 40px 0; font-size: 13px;">
                        <i class="fa-solid fa-circle-check" style="font-size:24px; display:block; margin-bottom:10px; color:var(--success);"></i>
                        All programs assigned!
                    </div>
                `;
            } else {
                sourceList.innerHTML = `
                    <div class="empty-sec-placeholder" style="text-align: center; color: var(--muted); font-size: 12.5px; padding: 30px 0; border: 1.5px dashed rgba(255,255,255,0.03); border-radius: 8px; margin: auto 0; display:flex; flex-direction:column; align-items:center; gap:8px;">
                        <i class="fa-solid fa-arrow-pointer-drag" style="font-size: 18px; color:rgba(255,255,255,0.2);"></i>
                        <span>Drag programs here</span>
                    </div>
                `;
            }
        }
    }

    // Sync in-memory state
    if (window.ALL_PROGRAMS) {
        const pIdNum = parseInt(programId, 10);
        const targetSecIdNum = toUnassigned ? 0 : parseInt(targetZone.dataset.dropSectionId || '0', 10);
        const pObj = window.ALL_PROGRAMS.find(p => p.id === pIdNum);
        if (pObj) {
            pObj.section_id = targetSecIdNum;
        }
    }

    recalculateSessionCapacity(targetZone);
    recalculateSessionCapacity(sourceZone);

    const unassignedList = document.getElementById('unassignedList');
    const count = unassignedList.querySelectorAll('.program-drag-card').length;
    const unassignedCountEl = document.getElementById('unassignedCount');
    if (unassignedCountEl) unassignedCountEl.textContent = count;
}

function recalculateSessionCapacity(zone) {
    if (!zone || zone.id === 'unassignedList') return;

    const cardContainer = zone.closest('.session-card');
    if (!cardContainer) return;

    const durationElements = zone.querySelectorAll('.program-drag-card');
    const intervals = [];
    durationElements.forEach(item => {
        const startTimeStr = item.getAttribute('data-time') || '';
        const duration = parseInt(item.getAttribute('data-duration') || '0', 10);
        
        if (startTimeStr && duration > 0) {
            const startMs = Date.parse(startTimeStr);
            if (!isNaN(startMs)) {
                const endMs = startMs + (duration * 60 * 1000);
                intervals.push({ start: startMs, end: endMs });
            }
        }
    });

    let totalAllocated = 0;
    if (intervals.length > 0) {
        intervals.sort((a, b) => a.start - b.start);
        
        const merged = [];
        let current = intervals[0];
        
        for (let i = 1; i < intervals.length; i++) {
            let next = intervals[i];
            if (next.start <= current.end) {
                current.end = Math.max(current.end, next.end);
            } else {
                merged.push(current);
                current = next;
            }
        }
        merged.push(current);
        
        let totalMs = 0;
        merged.forEach(interval => {
            totalMs += (interval.end - interval.start);
        });
        totalAllocated = Math.round(totalMs / (60 * 1000));
    }

    const fillBar = cardContainer.querySelector('.progress-bar-fill');
    if (!fillBar) return;
    const totalCapacity = parseInt(fillBar.dataset.total || '0', 10);

    const percentage = totalCapacity > 0 ? Math.min(100, Math.round((totalAllocated / totalCapacity) * 100)) : 0;

    fillBar.style.width = `${percentage}%`;
    
    const allocMinsSpan = cardContainer.querySelector('.alloc-mins');
    if (allocMinsSpan) allocMinsSpan.textContent = totalAllocated;

    const isOverallocated = totalAllocated > totalCapacity;
    const allocatedText = cardContainer.querySelector('.allocated-text');
    
    let warningBadge = cardContainer.querySelector('.warning-badge');

    if (isOverallocated) {
        fillBar.style.backgroundColor = 'var(--danger, #ef4444)';
        if (allocatedText) allocatedText.style.color = 'var(--danger, #ef4444)';
        
        if (!warningBadge && allocatedText && allocatedText.parentNode) {
            warningBadge = document.createElement('span');
            warningBadge.className = 'warning-badge';
            warningBadge.style.cssText = 'color: var(--danger, #ef4444); font-weight: 700; font-size: 10px; text-transform: uppercase;';
            warningBadge.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i> Overallocated';
            allocatedText.parentNode.appendChild(warningBadge);
        }
    } else {
        fillBar.style.backgroundColor = 'var(--accent, #6366f1)';
        if (allocatedText) allocatedText.style.color = 'var(--muted)';
        if (warningBadge) warningBadge.remove();
    }
}
</script>
<?php admin_close_page(); ?>
