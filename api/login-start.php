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
require_once __DIR__ . '/../includes/mail-helpers.php';

function mask_email(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = str_repeat('*', $len);
    } else {
        $maskedName = substr($name, 0, 2) . str_repeat('*', $len - 2);
    }
    return $maskedName . '@' . $domain;
}

try {
    // Read JSON payload or fallback to POST
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $rawPhone = trim((string)($input['phone'] ?? ''));
    $phone = preg_replace('/\D/', '', $rawPhone);

    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required.']);
        exit;
    }

    $pdo = $GLOBALS['dashboard_pdo'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'new_user', 'message' => 'Phone number is not registered. Please request approval.']);
        exit;
    }

    $status = $user['status'] ?? 'pending';

    if ($status === 'pending') {
        echo json_encode(['status' => 'pending', 'message' => 'Your account is pending admin approval. Please contact the administrator.']);
        exit;
    }

    if ($status !== 'active') {
        echo json_encode(['status' => 'error', 'message' => 'Your account is ' . $status . '. Please contact the administrator.']);
        exit;
    }

    $email = trim($user['email'] ?? '');
    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'No registered email address found for this account. Please contact admin.']);
        exit;
    }

    // Generate 6-digit OTP
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes expiry

    // Save to Database
    $upd = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
    $upd->execute([$otp, $expiresAt, $user['id']]);

    // Send Mail
    $subject = "Your OTP for Kauzariyya Musabaqa";
    $emailBody = "
        <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff;\">
            <h2 style=\"color: #6366f1; text-align: center; margin-bottom: 20px;\">Kauzariyya Musabaqa Login Verification</h2>
            <p>Assalamu Alaikum <strong>" . htmlspecialchars($user['full_name'] ?? 'User') . "</strong>,</p>
            <p>Your one-time verification code (OTP) to log in to the Musabaqa mobile application is:</p>
            <div style=\"text-align: center; margin: 30px 0;\">
                <span style=\"font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #4f46e5; background: #e0e7ff; padding: 10px 24px; border-radius: 8px; display: inline-block;\">" . $otp . "</span>
            </div>
            <p style=\"color: #64748b; font-size: 14px;\">This code is valid for 10 minutes and should not be shared with anyone.</p>
            <hr style=\"border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;\" />
            <p style=\"font-size: 12px; color: #94a3b8; text-align: center;\">Al Jamiathul Kauzariyya &middot; Thanafus 2026</p>
        </div>
    ";

    send_smtp_email($email, $subject, $emailBody);

    echo json_encode([
        'status' => 'otp_sent',
        'message' => 'OTP has been sent to your registered email address.',
        'email_masked' => mask_email($email)
    ]);

} catch (Throwable $e) {
    error_log("login-start API failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
}
