<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonBody = !empty($rawInput) ? json_decode($rawInput, true) : [];
$action = $_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? $jsonBody['action'] ?? '';

try {
    require_once __DIR__ . '/includes/public-data.php';

    if ($action === 'get_data') {
        // Retrieve and format teams
        $rawTeams = teams();
        $formattedTeams = [];
        foreach ($rawTeams as $t) {
            $formattedTeams[] = [
                'name' => $t['name'],
                'score' => (int)$t['score'],
                'color' => $t['color']
            ];
        }

        // Retrieve and format schedule
        $rawSchedule = schedule_items();
        $formattedSchedule = [];
        foreach ($rawSchedule as $s) {
            $title = $s['title'];
            $category = $s['category'];
            if (!empty($s['is_stacked']) && !empty($s['stacked_programs'])) {
                $titles = [];
                foreach ($s['stacked_programs'] as $sp) {
                    $titles[] = $sp['title'];
                }
                $title = implode(', ', $titles);
            }
            
            $session = $s['session'];

            $formattedSchedule[] = [
                $session,
                $s['start_time'],
                $title,
                $s['venue'] . ' · ' . $category,
                $s['status'],
                (int)$s['duration_minutes'],
                $s['date']
            ];
        }

        // Retrieve and format participants
        $rawParticipants = participants();
        $formattedParticipants = [];
        foreach ($rawParticipants as $p) {
            $formattedParticipants[] = [
                $p['name'],
                $p['code'],
                $p['program'],
                $p['category'],
                $p['reporting_time'],
                $p['team_name'],
                $p['team_color'] ?? '#4ee883',
                $p['order'] ?? 1,
                $p['id'] ?? 0,
                $p['program_id'] ?? 0
            ];
        }

        // Retrieve and format committee
        $rawCommittee = working_committee();
        $formattedCommittee = [];
        foreach ($rawCommittee as $c) {
            $formattedCommittee[] = [
                'name' => $c['name'],
                'role' => $c['role'],
                'image' => $c['image']
            ];
        }

        // Retrieve active event
        $event = tv_active_event();
        $eventTitle = trim((string)($event['title'] ?? 'Al-Jamiathul Kauzariyya · Arts Festival'));
        $eventTitle = $eventTitle !== '' ? $eventTitle : 'Al-Jamiathul Kauzariyya · Arts Festival';
        $eventDate = !empty($event['start_date']) 
            ? date('d F Y', strtotime((string)$event['start_date'])) 
            : '18 August 2026';
        $eventInfo = [
            'title' => $eventTitle,
            'date' => $eventDate,
            'start_date' => $event['start_date'] ?? '2026-08-18'
        ];

        // Retrieve programs, students, and sections
        $programs = plan_programs();
        $students = all_students();
        $sections = get_schedule_sections();

        echo json_encode([
            'success' => true,
            'event' => $eventInfo,
            'teams' => $formattedTeams,
            'schedule' => $formattedSchedule,
            'participants' => $formattedParticipants,
            'committee' => $formattedCommittee,
            'programs' => $programs,
            'students' => $students,
            'sections' => $sections
        ]);
        exit;

    } elseif ($action === 'submit_review') {
        $rating = max(0, min(5, (int)($_POST['rating'] ?? $jsonBody['rating'] ?? $_GET['rating'] ?? 0)));
        $comment = trim((string)($_POST['comment'] ?? $jsonBody['comment'] ?? $_GET['comment'] ?? ''));
        $name = trim((string)($_POST['name'] ?? $jsonBody['name'] ?? $_GET['name'] ?? ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
        $activeEventId = tv_active_event_id();

        if ($rating < 1 || $rating > 5) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid star rating (1 to 5).']);
            exit;
        }
        if ($comment === '') {
            echo json_encode(['success' => false, 'message' => 'Please write a brief comment describing your experience.']);
            exit;
        }

        $pdo = $GLOBALS['musabaqa_pdo'];
        $stmt = $pdo->prepare("
            INSERT INTO musabaqa_reviews (event_id, rating, comment, name, ip_address, user_agent, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())
        ");
        $stmt->execute([
            $activeEventId > 0 ? $activeEventId : null,
            $rating,
            $comment,
            $name !== '' ? $name : null,
            $ip,
            $userAgent
        ]);

        echo json_encode(['success' => true, 'message' => 'Thank you! Your feedback has been received.']);
        exit;
    } else {
        throw new Exception('Invalid action specified.');
    }

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ]);
    exit;
}
