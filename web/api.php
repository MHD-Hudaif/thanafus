<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';

try {
    require_once __DIR__ . '/../includes/public-data.php';

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
            if (str_starts_with($session, 'section_')) {
                // map specific sections or use time of day
                $hour = (int)date('H', strtotime($s['start_time']));
                if ($hour < 9) {
                    $session = 'subahi';
                } elseif ($hour >= 9 && $hour < 12) {
                    $session = 'morning';
                } elseif ($hour >= 12 && $hour < 16) {
                    $session = 'afternoon';
                } elseif ($hour >= 16 && $hour < 20) {
                    $session = 'evening';
                } else {
                    $session = 'night';
                }
            }

            $formattedSchedule[] = [
                $session,
                $s['start_time'],
                $title,
                $s['venue'] . ' · ' . $category,
                $s['status'],
                (int)$s['duration_minutes']
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
                $p['team_name']
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

        echo json_encode([
            'success' => true,
            'teams' => $formattedTeams,
            'schedule' => $formattedSchedule,
            'participants' => $formattedParticipants,
            'committee' => $formattedCommittee
        ]);
        exit;

    } elseif ($action === 'submit_review') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('Invalid request method');
        }
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $comment = trim((string)($_POST['comment'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
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
        throw new Exception('Invalid action');
    }

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
