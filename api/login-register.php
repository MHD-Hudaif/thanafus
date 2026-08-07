<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/admin-helpers.php';

try {
    // Read JSON payload or fallback to POST
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $rawPhone = trim((string)($input['phone'] ?? ''));
    $phone = preg_replace('/\D/', '', $rawPhone);
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));

    if (empty($phone) || empty($name) || empty($email)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'All fields (Name, Email, Phone) are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
        exit;
    }

    $pdo = $GLOBALS['dashboard_pdo'];

    // Check if phone number is already registered in users
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'This phone number is already registered.']);
        exit;
    }

    // Check if email address is already registered in users
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'This email address is already registered.']);
        exit;
    }

    // Generate a long random password since login is OTP-based
    $randomPassword = bin2hex(random_bytes(16));
    $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);

    // Insert user with pending status
    $stmt = $pdo->prepare("
        INSERT INTO users (username, phone, email, password, full_name, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $phone, // username = phone
        $phone,
        $email,
        $hashedPassword,
        $name
    ]);

    $user_id = $pdo->lastInsertId();

    // Check and link student or teacher records if they exist in dashboard DB
    $stmt = $pdo->prepare("SELECT * FROM students WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
    $stmt->execute([$phone]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
    $stmt->execute([$phone]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $pdo->prepare("UPDATE students SET user_id = ? WHERE id = ?")->execute([$user_id, $student['id']]);
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$user_id]); // student role
    } elseif ($teacher) {
        $pdo->prepare("UPDATE teachers SET user_id = ? WHERE id = ?")->execute([$user_id, $teacher['id']]);
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 2)")->execute([$user_id]); // staff/teacher role
    } else {
        // default role = student/viewer
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$user_id]);
    }

    echo json_encode([
        'status' => 'queued',
        'message' => 'Your registration request has been submitted successfully and is queued for admin approval.'
    ]);

} catch (Throwable $e) {
    error_log("login-register API failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
}
