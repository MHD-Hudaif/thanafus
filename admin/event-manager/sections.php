<?php
$pageTitle = 'Schedule Sessions';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();
$_SESSION['active_workspace'] = 'event-manager';

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// AJAX request handler
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
                    SELECT mp.id, mp.title, mp.start_time, mp.end_time, mst.name AS stage_type_name
                    FROM musabaqa_programs mp
                    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
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
                        'time' => $prog['start_time'] ? date('h:i A', strtotime($prog['start_time'])) : null
                    ]
                ]);
            } else {
                throw new RuntimeException('Invalid program or session.');
            }
        } elseif ($action === 'unassign_program') {
            $programId = (int)($_POST['program_id'] ?? 0);
            if ($programId > 0) {
                // Fetch program details for return payload
                $progStmt = $pdo->prepare("
                    SELECT mp.id, mp.title, mp.start_time, mp.end_time, mst.name AS stage_type_name
                    FROM musabaqa_programs mp
                    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
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
                        'time' => $prog['start_time'] ? date('h:i A', strtotime($prog['start_time'])) : null
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

// ---------------------------------------------------------------------------
// Validation: ensure no scheduled program overflows the session window
// ---------------------------------------------------------------------------

/**
 * Throw if any program assigned to $sectionId has a datetime that falls
 * outside [$sectionDate $startTime, $sectionDate $endTime].
 *
 * Pass $excludeSectionId = 0 for 'add' (no existing section yet).
 *
 * @throws RuntimeException
 */
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

    $startSql = $sesStart->format('Y-m-d H:i:s');
    $endSql   = $sesEnd->format('Y-m-d H:i:s');

    // Find any program in this section whose time falls outside the new window
    $stmt = $pdo->prepare("
        SELECT title, start_time, end_time
        FROM musabaqa_programs
        WHERE section_id = ?
          AND event_id = ?
          AND start_time IS NOT NULL
          AND end_time IS NOT NULL
          AND (start_time < ? OR end_time > ?)
        LIMIT 1
    ");
    $stmt->execute([$sectionId, $eventId, $startSql, $endSql]);
    $offender = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($offender) {
        $fmtProg = date('h:i A', strtotime($offender['start_time'])) . '–' . date('h:i A', strtotime($offender['end_time']));
        $fmtSes  = date('h:i A', strtotime($startTime)) . '–' . date('h:i A', strtotime($endTime));
        throw new RuntimeException(
            "Cannot update session: \"{$offender['title']}\" ({$fmtProg}) would fall outside the new window ({$fmtSes}). " .
            "Reschedule or unschedule the program first."
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/event-manager/sections');
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
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
        } elseif ($action === 'update') {
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
        } elseif ($action === 'delete') {
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
        } elseif ($action === 'generate_defaults') {
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
        } elseif ($action === 'auto_assign') {
            $count = admin_auto_assign_programs_to_sections($pdo, $activeEventId);
            admin_flash('success', "Auto-assignment completed. {$count} program(s) matched.");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', $e->getMessage() ?: 'Operation failed.');
    }

    admin_redirect('/admin/event-manager/sections');
}

$flash = admin_take_flash();

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
$sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all programs for assignment view
$stmt = $pdo->prepare("
    SELECT mp.id, mp.title, mp.section_id, mp.start_time, mp.end_time, mst.name AS stage_type_name
    FROM musabaqa_programs mp
    LEFT JOIN musabaqa_stage_types mst ON mst.id = mp.stage_type_id
    WHERE mp.event_id = ?
    ORDER BY mp.title ASC
");
$stmt->execute([$activeEventId]);
$allPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group programs by section
$programsBySection = [];
$unassignedPrograms = [];

foreach ($allPrograms as $prog) {
    if ($prog['section_id']) {
        $programsBySection[(int)$prog['section_id']][] = $prog;
    } else {
        $unassignedPrograms[] = $prog;
    }
}

// Map dates to Days and compute counts
$eventStartStr = $activeEvent['start_date'] ?? null;
$eventStart = $eventStartStr ? new DateTime($eventStartStr) : null;

$uniqueDays = [];
$dayCounts = ['all' => count($sections), 'undated' => 0];

foreach ($sections as $sec) {
    if ($sec['section_date']) {
        $dateVal = $sec['section_date'];
        $dayCounts[$dateVal] = ($dayCounts[$dateVal] ?? 0) + 1;
        if ($eventStart) {
            $secDate = new DateTime($dateVal);
            $diff = $secDate->diff($eventStart)->days + 1;
            $uniqueDays[$dateVal] = "Day " . $diff;
        } else {
            $uniqueDays[$dateVal] = date('M d, Y', strtotime($dateVal));
        }
    } else {
        $dayCounts['undated']++;
    }
}

$allProgramsPayload = array_map(function($p) {
    return [
        'id' => (int)$p['id'],
        'title' => $p['title'],
        'section_id' => (int)($p['section_id'] ?? 0)
    ];
}, $allPrograms);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<script>
window.ALL_PROGRAMS = <?= json_encode($allProgramsPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<style>
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
    background: rgba(30, 41, 59, 0.4) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
    border-radius: 16px !important;
    box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5) !important;
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), border-color 0.3s ease, box-shadow 0.3s ease !important;
}
.session-card:hover {
    transform: translateY(-4px);
    border-color: rgba(99, 102, 241, 0.25) !important;
    box-shadow: 0 20px 40px -15px rgba(99, 102, 241, 0.15) !important;
}

.day-tab-btn {
    background: rgba(255, 255, 255, 0.02) !important;
    border: 1px solid rgba(255, 255, 255, 0.06) !important;
    color: rgba(255, 255, 255, 0.6) !important;
    padding: 8px 16px !important;
    border-radius: 20px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: none !important;
}
.day-tab-btn:hover {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #fff !important;
    transform: translateY(-1px);
}
.day-tab-btn.active {
    background: linear-gradient(135deg, #6366f1, #4f46e5) !important;
    border-color: #6366f1 !important;
    color: #fff !important;
    box-shadow: 0 8px 20px -6px rgba(99, 102, 241, 0.6) !important;
}

.program-drag-card {
    background: rgba(255, 255, 255, 0.02) !important;
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
    border-radius: 12px !important;
    padding: 10px 14px !important;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
}
.program-drag-card:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.04) !important;
    border-color: rgba(255, 255, 255, 0.12) !important;
    box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.3) !important;
}
.program-drag-card.dragging {
    opacity: 0.35 !important;
    transform: scale(0.94) rotate(-1deg) !important;
    border: 1px dashed #6366f1 !important;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5) !important;
}

.session-drop-zone {
    border: 2px dashed rgba(255, 255, 255, 0.04) !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}
.session-drop-zone.drag-over {
    background: rgba(99, 102, 241, 0.08) !important;
    border-color: #6366f1 !important;
    box-shadow: inset 0 0 20px rgba(99, 102, 241, 0.15) !important;
}

#sessionsSearch {
    transition: all 0.3s ease !important;
}
#sessionsSearch:focus {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2) !important;
    width: 280px !important;
}

.progress-bar-container {
    background: rgba(255, 255, 255, 0.06) !important;
    border-radius: 8px !important;
    overflow: hidden !important;
    height: 6px !important;
}
.progress-bar-fill {
    border-radius: 8px !important;
}

#unassignedList {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.1) transparent;
}
#unassignedList::-webkit-scrollbar {
    width: 6px;
}
#unassignedList::-webkit-scrollbar-track {
    background: transparent;
}
#unassignedList::-webkit-scrollbar-thumb {
    background-color: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
}

.toast {
    background: rgba(30, 41, 59, 0.85) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    border-radius: 12px !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3) !important;
    padding: 12px 20px !important;
    color: #fff !important;
    font-weight: 600 !important;
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
</style>


<!-- Toast notification container -->
<div id="toast-container"></div>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-clock mr-2" style="color:var(--accent);"></i> Schedule Sessions</div>
            <div class="page-subtitle">Map programs into Morning, Evening, and Night blocks for each day. Drag & drop to assign.</div>
        </div>
        <div class="flex gap-2" style="flex-wrap: wrap;">
            <button class="btn btn-success btn-md" type="button" data-open-add><i class="fa-solid fa-plus mr-1"></i> Add Session</button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- Real-time Filter & Search Panel -->
    <div class="panel mb-6" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: rgba(255,255,255,0.015); border-color: rgba(255,255,255,0.03);">
        <div style="display: flex; gap: 8px; flex-wrap: wrap;" id="dayFilterTabs">
            <button class="day-tab-btn active" data-day="all">
                All Days <span class="badge badge-neutral ml-1" style="font-size: 10px; opacity: 0.8;"><?= $dayCounts['all'] ?></span>
            </button>
            <?php 
            $sortedDates = array_keys($uniqueDays);
            sort($sortedDates);
            foreach ($sortedDates as $date): 
            ?>
                <button class="day-tab-btn" data-day="<?= e($date) ?>">
                    <?= e($uniqueDays[$date]) ?> 
                    <span class="badge badge-neutral ml-1" style="font-size: 10px; opacity: 0.8;"><?= $dayCounts[$date] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
            <?php if (($dayCounts['undated'] ?? 0) > 0): ?>
                <button class="day-tab-btn" data-day="undated">
                    General / Undated 
                    <span class="badge badge-neutral ml-1" style="font-size: 10px; opacity: 0.8;"><?= $dayCounts['undated'] ?></span>
                </button>
            <?php endif; ?>
        </div>
        
        <div style="position: relative; display: flex; align-items: center;">
            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; color: var(--muted); font-size: 13px;"></i>
            <input type="text" id="sessionsSearch" placeholder="Search programs or sessions..." class="form-input" style="padding-left: 34px; height: 38px; font-size: 13px; width: 240px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff;">
        </div>
    </div>

    <div class="dashboard-layout-grid">
        <!-- Main: Sessions Cards Grid -->
        <div class="dashboard-main-col" style="flex: 2;">
            <?php if (!$sections): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fa-solid fa-clock"></i></div>
                    <div class="empty-title">No Schedule Sessions</div>
                    <div class="empty-subtitle">Create sessions (Morning, Evening, Night) to group programs, or click "Generate Defaults" to build them automatically.</div>
                    <button class="btn btn-success btn-md mt-4" type="button" data-open-add><i class="fa-solid fa-plus mr-1"></i> Add First Session</button>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;" id="sessionsContainer">
                    <?php 
                    foreach ($sections as $section): 
                        $sectionId = (int)$section['id'];
                        $assignedProgs = $programsBySection[$sectionId] ?? [];
                        
                        // Calculate allocated duration vs total duration of session
                        $secStart = new DateTime($section['start_time']);
                        $secEnd = new DateTime($section['end_time']);
                        if ($secEnd < $secStart) {
                            $secEnd->modify('+1 day');
                        }
                        $sessionTotalMins = (int)(($secEnd->getTimestamp() - $secStart->getTimestamp()) / 60);
                        
                        $allocatedMins = 0;
                        foreach ($assignedProgs as $prog) {
                            if ($prog['start_time'] && $prog['end_time']) {
                                $pStart = new DateTime($prog['start_time']);
                                $pEnd = new DateTime($prog['end_time']);
                                $allocatedMins += (int)(($pEnd->getTimestamp() - $pStart->getTimestamp()) / 60);
                            }
                        }
                        
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
                        ?>
                        <div class="panel session-card" 
                             data-day="<?= e($dateVal) ?>"
                             data-section-id="<?= $sectionId ?>"
                             style="display: flex; flex-direction: column; height: 100%; border: 1px solid rgba(255,255,255,0.04); background: rgba(255,255,255,0.015); border-radius: 12px; padding: 0; overflow: hidden; position: relative;">
                            
                            <!-- Top color strip -->
                            <div class="<?= $accentClass ?>" style="height: 4px; width: 100%;"></div>
                            
                            <!-- Card Header -->
                            <div style="padding: 16px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div style="flex: 1; min-width: 0;">
                                        <h4 style="font-size: 15px; font-weight: 800; color: #fff; margin: 0; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-regular fa-clock" style="color:var(--accent); font-size:14px;"></i>
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
                                             style="background: <?= $isOverallocated ? 'var(--danger, #ef4444)' : 'var(--accent, #6366f1)' ?>; width: <?= $percentage ?>%; height: 100%; transition: width 0.3s ease, background-color 0.3s ease;"></div>
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
                                            ?>
                                            <div class="program-drag-card" 
                                                 draggable="true"
                                                 data-program-id="<?= (int)$prog['id'] ?>"
                                                 data-duration="<?= $progDuration ?>"
                                                 style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); border-radius: 8px; padding: 8px 12px; gap: 8px;">
                                                <div style="min-width: 0;">
                                                    <strong class="prog-title" style="display: block; font-size: 13px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= e($prog['title']) ?></strong>
                                                    <span style="font-size: 11px; color: var(--muted);">
                                                        <i class="fa-solid fa-location-dot mr-1"></i> <?= e($prog['stage_type_name'] ?: 'TBD') ?>
                                                        <?php if ($prog['start_time']): ?>
                                                             • <?= e(date('h:i A', strtotime($prog['start_time']))) ?> (<?= $progDuration ?>m)
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div style="display:flex; align-items:center; gap:4px;">
                                                    <i class="fa-solid fa-grip-vertical mr-1" style="color:rgba(255,255,255,0.2); font-size:12px; cursor:grab;"></i>
                                                    <button type="button" class="btn btn-link btn-sm unassign-btn" data-unassign-id="<?= (int)$prog['id'] ?>" style="color: var(--danger, #ef4444); padding:4px;" title="Unassign">
                                                        <i class="fa-solid fa-xmark" style="font-size: 14px;"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Assign Dropdown Selector -->
                                <div style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.03); padding-top: 12px; display: flex; gap: 8px; align-items: center;">
                                    <select class="form-input dropdown-quick-assign" style="height: 32px; font-size: 12px; padding: 0 8px; flex: 1;">
                                        <option value="">-- Quick Assign Program --</option>
                                        <?php foreach ($allPrograms as $pOption): ?>
                                            <?php if ((int)$pOption['section_id'] !== $sectionId): ?>
                                                <option value="<?= (int)$pOption['id'] ?>"><?= e($pOption['title']) ?><?= $pOption['section_id'] ? ' (Reassign)' : '' ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Area: Unassigned Programs -->
        <div class="dashboard-sidebar-col" style="flex: 1; min-width: 280px;">
            <div class="panel" style="border: 1px solid rgba(255,255,255,0.04); background: rgba(255,255,255,0.015); border-radius: 12px; position: sticky; top: 20px;">
                <h3 class="mb-4" style="font-size: 15px; font-weight: 800; display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fa-solid fa-list-ul mr-2" style="color:var(--warning);"></i> Unassigned Programs</span>
                    <span class="badge badge-warning" id="unassignedCount" style="font-size: 11px; padding: 2px 8px;"><?= count($unassignedPrograms) ?></span>
                </h3>

                <div id="unassignedList" 
                     class="session-drop-zone" 
                     data-drop-section-id="0"
                     style="display: flex; flex-direction: column; gap: 10px; max-height: 550px; overflow-y: auto; padding: 4px; min-height: 150px; border-radius: 8px;">
                    
                    <?php if (empty($unassignedPrograms)): ?>
                        <div class="all-assigned-msg" style="text-align: center; color: var(--success); padding: 40px 0; font-size: 13px;">
                            <i class="fa-solid fa-circle-check" style="font-size:24px; display:block; margin-bottom:10px; color:var(--success);"></i>
                            All programs assigned!
                        </div>
                    <?php else: ?>
                        <?php foreach ($unassignedPrograms as $uProg): ?>
                            <?php 
                            $progDuration = 0;
                            if ($uProg['start_time'] && $uProg['end_time']) {
                                $pStart = new DateTime($uProg['start_time']);
                                $pEnd = new DateTime($uProg['end_time']);
                                $progDuration = (int)(($pEnd->getTimestamp() - $pStart->getTimestamp()) / 60);
                            }
                            ?>
                            <div class="program-drag-card" 
                                 draggable="true"
                                 data-program-id="<?= (int)$uProg['id'] ?>"
                                 data-duration="<?= $progDuration ?>"
                                 style="background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.04); border-radius: 8px; padding: 10px 12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                <div style="min-width: 0;">
                                    <strong class="prog-title" style="display: block; font-size: 13px; color: #fff; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= e($uProg['title']) ?></strong>
                                    <span style="font-size: 11px; color: var(--muted); display: block;">
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
                <input type="hidden" name="action" id="formAction" value="add">
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
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <div class="modal-title">Delete Session</div>
                <button class="modal-close" type="button" data-close="deleteModal"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="section_id" id="deleteId">
                <div class="p-6">
                    <p>Are you sure you want to delete <strong id="deleteName">this session</strong>?</p>
                    <p class="muted mt-2 text-sm">Programs assigned to this session will be marked as unassigned. This action cannot be undone.</p>
                </div>
                <div class="form-actions">
                    <button class="btn btn-secondary btn-md" type="button" data-close="deleteModal">Cancel</button>
                    <button class="btn btn-danger btn-md" type="submit">Delete Session</button>
                </div>
            </form>
        </div>
    </div>

</div>

<!-- CSRF Token helper for JS -->
<div id="csrfTokenStorage" data-csrf="<?= e(generate_csrf_token()) ?>" style="display:none;"></div>

<script>
(() => {
    // Move all modal overlays to <body> to escape any CSS stacking context trap
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.modal-overlay').forEach(el => {
            if (el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
        });
    });

    // ----------------------------------------------------
    // Modal Helpers
    // ----------------------------------------------------
    function openModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            modal.scrollTop = 0;
        }
        if (typeof window.openModal === 'function' && window.openModal !== openModal) {
            try {
                window.openModal(id);
            } catch (err) {
                console.error(err);
            }
        }
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('active');
        }
        if (typeof window.closeModal === 'function' && window.closeModal !== closeModal) {
            try {
                window.closeModal(id);
            } catch (err) {
                console.error(err);
            }
        }
    }

    document.querySelectorAll('.modal-overlay').forEach(modal => modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); }));

    // Automatically close modal when submitting form inside modal (Save / Delete actions)
    document.querySelectorAll('.modal-overlay form').forEach(form => {
        form.addEventListener('submit', () => {
            const modalOverlay = form.closest('.modal-overlay');
            if (modalOverlay && modalOverlay.id) {
                closeModal(modalOverlay.id);
            }
        });
    });

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
            document.getElementById('formAction').value = 'add';
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
                document.getElementById('formAction').value = 'update';
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
                console.error('Error parsing section metadata:', err);
            }
            return;
        }

        const deleteBtn = e.target.closest('[data-delete-id]');
        if (deleteBtn) {
            document.getElementById('deleteId').value = deleteBtn.dataset.deleteId;
            document.getElementById('deleteName').textContent = deleteBtn.dataset.deleteName || 'this session';
            openModal('deleteModal');
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

    // ----------------------------------------------------
    // Toast Notification System
    // ----------------------------------------------------
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
        toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${escapeHtml(message)}</span>`;
        
        container.appendChild(toast);
        
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

    // ----------------------------------------------------
    // Day Tab Filters & Live Search
    // ----------------------------------------------------
    const dayButtons = document.querySelectorAll('.day-tab-btn');
    const searchInput = document.getElementById('sessionsSearch');
    const cards = document.querySelectorAll('.session-card');

    let currentDayFilter = 'all';
    let currentSearchQuery = '';

    function filterSessions() {
        cards.forEach(card => {
            const cardDay = card.dataset.day; // e.g. "2026-07-30" or "undated"
            const matchesDay = (currentDayFilter === 'all') || 
                               (currentDayFilter === 'undated' && cardDay === 'undated') || 
                               (cardDay === currentDayFilter);

            let matchesSearch = true;
            if (currentSearchQuery !== '') {
                // Check if any program inside the card contains the query
                let programFound = false;
                card.querySelectorAll('.prog-title').forEach(title => {
                    if (title.textContent.toLowerCase().includes(currentSearchQuery)) {
                        programFound = true;
                        title.style.background = 'rgba(250, 204, 21, 0.2)'; // highlight match
                    } else {
                        title.style.background = 'none';
                    }
                });
                // Check section name as well
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
        
        // Also filter unassigned sidebar
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

    // ----------------------------------------------------
    // HTML5 Drag & Drop Delegation & AJAX Handlers
    // ----------------------------------------------------
    const csrfToken = document.getElementById('csrfTokenStorage').dataset.csrf;
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
            const targetSectionId = zone.dataset.dropSectionId; // "0" for unassigned

            if (!draggedElement || draggedElement.dataset.programId !== programId) return;

            const sourceZone = draggedElement.closest('.session-drop-zone');
            const sourceSectionId = sourceZone ? sourceZone.dataset.dropSectionId : null;

            if (sourceSectionId === targetSectionId) return; // Dropped on same list

            // Perform assignment via AJAX
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

    function moveProgramDOM(programId, targetZone, sourceZone, programData, toUnassigned = false) {
        let card = document.querySelector(`.program-drag-card[data-program-id="${programId}"]`);
        
        const targetEmptyPlaceholder = targetZone.querySelector('.empty-sec-placeholder');
        if (targetEmptyPlaceholder) targetEmptyPlaceholder.remove();
        const targetAllAssignedMsg = targetZone.querySelector('.all-assigned-msg');
        if (targetAllAssignedMsg) targetAllAssignedMsg.remove();

        const targetList = targetZone.querySelector('.assigned-list') || targetZone;

        if (toUnassigned) {
            const newCard = document.createElement('div');
            newCard.className = 'program-drag-card';
            newCard.setAttribute('draggable', 'true');
            newCard.setAttribute('data-program-id', programId);
            newCard.setAttribute('data-duration', programData.duration);
            newCard.style.background = 'rgba(255,255,255,0.025)';
            newCard.style.border = '1px solid rgba(255,255,255,0.04)';
            newCard.style.borderRadius = '8px';
            newCard.style.padding = '10px 12px';
            newCard.style.display = 'flex';
            newCard.style.justifyContent = 'space-between';
            newCard.style.alignItems = 'center';
            newCard.style.gap = '8px';

            newCard.innerHTML = `
                <div style="min-width: 0;">
                    <strong class="prog-title" style="display: block; font-size: 13px; color: #fff; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(programData.title)}</strong>
                    <span style="font-size: 11px; color: var(--muted); display: block;">
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
            newCard.style.display = 'flex';
            newCard.style.justifyContent = 'space-between';
            newCard.style.alignItems = 'center';
            newCard.style.background = 'rgba(255,255,255,0.02)';
            newCard.style.border = '1px solid rgba(255,255,255,0.04)';
            newCard.style.borderRadius = '8px';
            newCard.style.padding = '8px 12px';
            newCard.style.gap = '8px';

            newCard.innerHTML = `
                <div style="min-width: 0;">
                    <strong class="prog-title" style="display: block; font-size: 13px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(programData.title)}</strong>
                    <span style="font-size: 11px; color: var(--muted);">
                        <i class="fa-solid fa-location-dot mr-1"></i> ${escapeHtml(programData.stage)}
                        ${programData.time ? `• ${programData.time} (${programData.duration}m)` : ''}
                    </span>
                </div>
                <div style="display:flex; align-items:center; gap:4px;">
                    <i class="fa-solid fa-grip-vertical mr-1" style="color:rgba(255,255,255,0.2); font-size:12px; cursor:grab;"></i>
                    <button type="button" class="btn btn-link btn-sm unassign-btn" data-unassign-id="${programId}" style="color: var(--danger, #ef4444); padding:4px;" title="Unassign">
                        <i class="fa-solid fa-xmark" style="font-size: 14px;"></i>
                    </button>
                </div>
            `;
            targetList.appendChild(newCard);
            if (card) card.remove();
        }

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

        updateQuickAssignDropdowns();
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
        let totalAllocated = 0;
        durationElements.forEach(item => {
            totalAllocated += parseInt(item.dataset.duration || '0', 10);
        });

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

    function updateQuickAssignDropdowns() {
        const assignedIds = Array.from(document.querySelectorAll('.session-card .program-drag-card'))
                                 .map(el => String(el.dataset.programId));

        const allPrograms = window.ALL_PROGRAMS || [];

        document.querySelectorAll('.dropdown-quick-assign').forEach(dropdown => {
            const card = dropdown.closest('.session-card');
            if (!card) return;
            const sectionId = card.dataset.sectionId;

            dropdown.innerHTML = '<option value="">-- Quick Assign Program --</option>';
            allPrograms.forEach(p => {
                const isAssignedToThis = card.querySelector(`.program-drag-card[data-program-id="${p.id}"]`) !== null;
                if (!isAssignedToThis) {
                    const isReassign = assignedIds.includes(String(p.id));
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.title + (isReassign ? ' (Reassign)' : '');
                    dropdown.appendChild(opt);
                }
            });
        });
    }

    // Handle Dropdown Quick Assign selection
    document.addEventListener('change', (e) => {
        const dropdown = e.target.closest('.dropdown-quick-assign');
        if (dropdown && dropdown.value !== '') {
            const programId = dropdown.value;
            const card = dropdown.closest('.session-card');
            const targetSectionId = card.dataset.sectionId;
            const targetZone = card.querySelector('.session-drop-zone');
            
            const sourceCard = document.querySelector(`.program-drag-card[data-program-id="${programId}"]`);
            const sourceZone = sourceCard ? sourceCard.closest('.session-drop-zone') : null;

            ajaxAssignProgram(programId, targetSectionId, targetZone, sourceZone);
            dropdown.value = '';
        }
    });

    updateQuickAssignDropdowns();

})();
</script>

<?php admin_close_page(); ?>
