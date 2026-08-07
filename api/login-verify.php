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
    $otp = trim((string)($input['otp'] ?? ''));

    if (empty($phone) || empty($otp)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Phone and OTP are required.']);
        exit;
    }

    $pdo = $GLOBALS['dashboard_pdo'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }

    if (($user['status'] ?? '') !== 'active') {
        echo json_encode(['status' => 'error', 'message' => 'Your account is not active. Please contact administrator.']);
        exit;
    }

    $savedOtp = $user['otp_code'] ?? null;
    $expiresAt = $user['otp_expires_at'] ?? null;

    if ($savedOtp === null || $savedOtp !== $otp || $expiresAt === null || strtotime($expiresAt) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP.']);
        exit;
    }

    // Clear OTP
    $upd = $pdo->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
    $upd->execute([$user['id']]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'phone' => $user['phone'],
            'email' => $user['email'],
            'full_name' => $user['full_name']
        ]
    ]);

} catch (Throwable $e) {
    error_log("login-verify API failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
}
