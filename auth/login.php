<?php

require_once __DIR__ . '/../config/auth.php';

/*
|--------------------------------------------------------------------------
| IF ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('/admin/dashboard'));
    exit;
}

$error = '';

// Rate limit config
$rateLimitMax = env('RATE_LIMIT_LOGIN_MAX', 5);
$rateLimitWindow = env('RATE_LIMIT_LOGIN_WINDOW', 300);
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

/*
|--------------------------------------------------------------------------
| LOGIN SUBMIT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Check rate limit before processing
    $rateLimit = rate_limit_check("login:$clientIp", $rateLimitMax, $rateLimitWindow);

    if (!$rateLimit['allowed']) {
        $error = "Too many login attempts. Please try again in {$rateLimit['retry_after']} seconds.";
    } elseif (empty($username) || empty($password)) {
        $error = 'Please fill all fields';
        // Count as attempt
        rate_limit_hit("login:$clientIp", $rateLimitMax, $rateLimitWindow);
    } else {
        $pdo = $GLOBALS['dashboard_pdo'];

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $user
            && password_verify($password, $user['password'])
        ) {
            // Check user status
            if (($user['status'] ?? '') !== 'active') {
                $error = 'Your account is ' . ($user['status'] ?? 'inactive') . '. Please contact administrator.';
                rate_limit_hit("login:$clientIp", $rateLimitMax, $rateLimitWindow);
            } else {
                login_user_session((int)$user['id']);
                header('Location: ' . app_url('/admin/dashboard'));
                exit;
            }
        } else {
            $error = 'Invalid username or password';
            rate_limit_hit("login:$clientIp", $rateLimitMax, $rateLimitWindow);
        }
    }
}

/*
|--------------------------------------------------------------------------
| RATE LIMIT INFO FOR TEMPLATE
|--------------------------------------------------------------------------
*/
$rateLimitStatus = rate_limit_check("login:$clientIp", $rateLimitMax, $rateLimitWindow);
$showRateLimitWarning = $rateLimitStatus['remaining'] <= 2 && $rateLimitStatus['remaining'] > 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Musabaqa Login</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<link rel="stylesheet" href="<?= asset_url('css/auth.css') ?>">

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>

</head>

<body>

<!-- =====================================================
BACKGROUND
===================================================== -->

<div class="auth-background">

    <div class="auth-image"></div>

    <div class="auth-overlay"></div>

</div>

<!-- =====================================================
LOGIN CARD
===================================================== -->

<div class="auth-container">

    <form method="POST" class="auth-card">

        <img src="<?= asset_url('images/thanafus-logo.png') ?>" class="auth-logo" alt="Thanafus">

        <div class="auth-title">Welcome Back</div>

        <div class="auth-subtitle">Kauzariyya Digital Musabaqa System</div>

        <?php
        $displayError = $error;
        if (!empty($_SESSION['oauth_error'])) {
            $displayError = $_SESSION['oauth_error'];
            unset($_SESSION['oauth_error']);
        }
        if ($displayError): ?>
            <div class="auth-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= e($displayError) ?>
            </div>
        <?php endif; ?>

        <?php if ($showRateLimitWarning): ?>
            <div class="auth-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= $rateLimitStatus['remaining'] ?> login attempts remaining before temporary lockout</span>
            </div>
        <?php endif; ?>

        <!-- USERNAME -->
        <div class="input-group">
            <label>Username</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="username" placeholder="Enter username" required autocomplete="username">
            </div>
        </div>

        <!-- PASSWORD -->
        <div class="input-group">
            <label>Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" placeholder="Enter password" required autocomplete="current-password">
            </div>
        </div>

        <!-- SUBMIT -->
        <button type="submit" class="auth-btn">
            <i class="fa-solid fa-right-to-bracket"></i>
            Login
        </button>

        <!-- GOOGLE SIGN IN -->
        <div class="divider"><span>or</span></div>
        <div class="google-btn-wrapper">
            <button type="button" class="google-custom-btn">
                <svg class="google-icon-svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
                </svg>
                <span>Continue with Google</span>
            </button>
            <div id="g_id_onload"
                 data-client_id="<?= GOOGLE_CLIENT_ID ?>"
                 data-context="signin"
                 data-ux_mode="redirect"
                 data-login_uri="<?= app_absolute_url('/auth/google-oauth.php') ?>"
                 data-auto_prompt="false">
            </div>
            <div class="g_id_signin"
                 data-type="standard"
                 data-shape="rectangular"
                 data-theme="filled_blue"
                 data-text="continue_with"
                 data-size="large"
                 data-logo_alignment="left"
                 data-width="370">
            </div>
        </div>

        <a href="<?= app_url('/home') ?>" class="back-home">
            <i class="fa-solid fa-arrow-left"></i>
            Back To Home
        </a>

    </form>

</div>

<script>
window.addEventListener('DOMContentLoaded', () => {
    if (!window.gsap) return;

    // Set initial states
    gsap.set('.auth-card', { opacity: 0, y: 30, scale: 0.95 });
    gsap.set([
        '.auth-logo',
        '.auth-title',
        '.auth-subtitle',
        '.auth-error',
        '.auth-warning',
        '.input-group',
        '.auth-btn',
        '.divider',
        '.google-btn-wrapper',
        '.back-home'
    ], { opacity: 0, y: 15 });

    // Animate Card
    gsap.to('.auth-card', {
        opacity: 1,
        y: 0,
        scale: 1,
        duration: 0.85,
        ease: 'power4.out',
        onComplete: () => {
            gsap.set('.auth-card', { clearProps: 'transform,scale' });
        }
    });

    // Stagger elements in
    gsap.to([
        '.auth-logo',
        '.auth-title',
        '.auth-subtitle',
        '.auth-error',
        '.auth-warning',
        '.input-group',
        '.auth-btn',
        '.divider',
        '.google-btn-wrapper',
        '.back-home'
    ], {
        opacity: 1,
        y: 0,
        duration: 0.6,
        stagger: 0.08,
        delay: 0.15,
        ease: 'power3.out'
    });
});
</script>

</body>

</html>