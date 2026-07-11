<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-helpers.php';

// Secure endpoint: only authenticated admin panel users can access
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$dashboardPdo = $GLOBALS['dashboard_pdo'];
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// Self-healing database table initialization
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musabaqa_chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender (sender_id),
            INDEX idx_receiver (receiver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database initialization failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_messages';

header('Content-Type: application/json; charset=utf-8');

function chat_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function chat_avatar_url(?string $photo, ?string $name): string
{
    if (!empty($photo)) {
        return avatar_url($photo) ?: '';
    }

    return 'https://ui-avatars.com/api/?name=' . urlencode($name ?: 'User') . '&background=0d1420&color=14b8a6&bold=true';
}

try {
    if ($action === 'get_users') {
        // Fetch all other users
        $stmt = $dashboardPdo->prepare("
            SELECT id, username, full_name, profile_photo, status
            FROM users
            WHERE id <> ? AND status = 'active'
            ORDER BY full_name ASC, username ASC
        ");
        $stmt->execute([$currentUserId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map avatar URL
        foreach ($users as &$u) {
            $u['avatar'] = chat_avatar_url($u['profile_photo'] ?? null, $u['full_name'] ?? $u['username'] ?? null);
        }

        chat_json(['success' => true, 'users' => $users]);
    }

    if ($action === 'get_messages') {
        $receiverId = isset($_GET['receiver_id']) && $_GET['receiver_id'] !== '' ? (int)$_GET['receiver_id'] : null;

        // Fetch users mapping to attach names/avatars to messages
        $stmt = $dashboardPdo->query("SELECT id, username, full_name, profile_photo FROM users");
        $allUsersRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $userMap = [];
        foreach ($allUsersRaw as $u) {
            $userMap[(int)$u['id']] = [
                'name' => $u['full_name'] ?? $u['username'],
                'avatar' => chat_avatar_url($u['profile_photo'] ?? null, $u['full_name'] ?? $u['username'] ?? null),
            ];
        }

        if ($receiverId === null) {
            // Global Group Chat Lounge (receiver_id is null)
            $stmt = $pdo->prepare("
                SELECT id, sender_id, receiver_id, message, created_at
                FROM musabaqa_chat_messages
                WHERE receiver_id IS NULL
                ORDER BY created_at ASC, id ASC
                LIMIT 150
            ");
            $stmt->execute();
        } else {
            // Direct 1-to-1 message thread
            $stmt = $pdo->prepare("
                SELECT id, sender_id, receiver_id, message, created_at
                FROM musabaqa_chat_messages
                WHERE (sender_id = ? AND receiver_id = ?)
                   OR (sender_id = ? AND receiver_id = ?)
                ORDER BY created_at ASC, id ASC
                LIMIT 150
            ");
            $stmt->execute([$currentUserId, $receiverId, $receiverId, $currentUserId]);
        }

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($messages as &$msg) {
            $sender = $userMap[(int)$msg['sender_id']] ?? ['name' => 'System', 'avatar' => ''];
            $msg['sender_name'] = $sender['name'];
            $msg['sender_avatar'] = $sender['avatar'];
            $msg['is_me'] = (int)$msg['sender_id'] === $currentUserId;
            // Format time nicely
            $msg['time'] = date('h:i A', strtotime($msg['created_at']));
        }

        chat_json(['success' => true, 'messages' => $messages]);
    }

    if ($action === 'send_message') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Invalid request method.');
        }

        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            chat_json(['success' => false, 'error' => 'Invalid security token. Refresh the page and try again.'], 403);
        }

        $message = trim((string)($_POST['message'] ?? ''));
        $receiverId = isset($_POST['receiver_id']) && $_POST['receiver_id'] !== '' ? (int)$_POST['receiver_id'] : null;

        if ($message === '') {
            throw new RuntimeException('Message cannot be empty.');
        }

        if ($receiverId !== null) {
            if ($receiverId === $currentUserId) {
                throw new RuntimeException('You cannot send a direct message to yourself.');
            }

            $stmt = $dashboardPdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND status = ?');
            $stmt->execute([$receiverId, 'active']);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('Selected chat user is unavailable.');
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO musabaqa_chat_messages (sender_id, receiver_id, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$currentUserId, $receiverId, $message]);

        chat_json(['success' => true, 'message_id' => (int)$pdo->lastInsertId()]);
    }

    throw new RuntimeException('Unknown action request.');

} catch (Throwable $exception) {
    chat_json(['success' => false, 'error' => $exception->getMessage()], 400);
}
