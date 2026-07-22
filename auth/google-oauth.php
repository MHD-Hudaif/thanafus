<?php
// auth/google-oauth.php
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$credential = $_POST['credential'] ?? '';

if (empty($credential)) {
    http_response_code(400);
    exit('Missing Google Credential');
}

// Verify Google Token via Google's tokeninfo API
$url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

$options = [
    'http' => [
        'header' => "User-Agent: PHP\r\n",
        'method' => 'GET',
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    $_SESSION['oauth_error'] = 'Failed to connect to Google verification service. Please check your internet connection.';
    header("Location: " . app_url('/auth/login'));
    exit;
}

$payload = json_decode($response, true);

if (empty($payload) || isset($payload['error'])) {
    http_response_code(400);
    exit('Invalid ID token: ' . ($payload['error_description'] ?? 'unknown error'));
}

// Validate issuer and audience
$issurers = ['accounts.google.com', 'https://accounts.google.com'];
if (!in_array($payload['iss'] ?? '', $issurers, true)) {
    http_response_code(400);
    exit('Invalid token issuer');
}

if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    http_response_code(400);
    exit('Audience mismatch');
}

$google_id = $payload['sub'];
$email = $payload['email'] ?? '';
$full_name = $payload['name'] ?? '';
$picture = $payload['picture'] ?? null;

$pdo = $GLOBALS['dashboard_pdo'];

// 1. Search for user by google_id
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? LIMIT 1");
$stmt->execute([$google_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if ($user['status'] !== 'active') {
        $_SESSION['oauth_error'] = "Your account is " . $user['status'];
        header("Location: " . app_url('/auth/login'));
        exit;
    }
    
    // Update profile photo if empty
    if (empty($user['profile_photo']) && $picture) {
        $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$picture, $user['id']]);
    }
    
    login_user_session($user['id']);
    
    if (is_admin()) {
        header("Location: " . app_url('/admin/dashboard'));
    } else {
        header("Location: " . app_url('/'));
    }
    exit;
}

// 2. If google_id not found, search by email
if ($email !== '') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Email matches an existing user. Link the google_id.
        $email_verified_at_clause = empty($user['email_verified_at']) ? ", email_verified_at = NOW()" : "";
        $photo_clause = empty($user['profile_photo']) && $picture ? ", profile_photo = ?" : "";
        
        $sql = "UPDATE users SET google_id = ? {$email_verified_at_clause} {$photo_clause} WHERE id = ?";
        $params = [$google_id];
        if (empty($user['profile_photo']) && $picture) {
            $params[] = $picture;
        }
        $params[] = $user['id'];
        
        $pdo->prepare($sql)->execute($params);
        
        login_user_session($user['id']);
        
        if (is_admin()) {
            header("Location: " . app_url('/admin/dashboard'));
        } else {
            header("Location: " . app_url('/'));
        }
        exit;
    }
}

// 3. User does not exist at all. Redirect to Login page with error.
$_SESSION['oauth_error'] = "No account found matching this Google email. Please register on the dashboard first.";
header("Location: " . app_url('/auth/login'));
exit;
