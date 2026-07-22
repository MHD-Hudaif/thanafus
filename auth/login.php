<?php

require_once __DIR__ . '/../config/auth.php';

/*
|--------------------------------------------------------------------------
| IF ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['user_id'])) {
    if (is_admin()) {
        header('Location: ' . app_url('/admin/dashboard'));
    } else {
        header('Location: ' . app_url('/'));
    }
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
    $pdo = $GLOBALS['dashboard_pdo'];

    if (isset($_POST['guest_login']) && $_POST['guest_login'] === '1') {
        // Find or create guest user in main database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = 'guest' LIMIT 1");
        $stmt->execute();
        $guest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$guest) {
            $stmt = $pdo->prepare("
                INSERT INTO users (username, phone, email, password, full_name, status)
                VALUES ('guest', '', 'guest@kauzariyya.com', ?, 'Guest User', 'active')
            ");
            $stmt->execute([password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $guest_id = $pdo->lastInsertId();
            
            // Set student role (3)
            $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$guest_id]);
        } else {
            $guest_id = $guest['id'];
        }
        
        login_user_session((int)$guest_id);
        $_SESSION['just_logged_in'] = true;
        
        header('Location: ' . app_url('/'));
        exit;
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Check rate limit before processing
        $rateLimit = rate_limit_check("login:$clientIp", $rateLimitMax, $rateLimitWindow);

        if (!$rateLimit['allowed']) {
            $error = "Too many login attempts. Please try again in {$rateLimit['retry_after']} seconds.";
        } elseif (empty($username) || empty($password)) {
            $error = 'Please fill all required fields';
            rate_limit_hit("login:$clientIp", $rateLimitMax, $rateLimitWindow);
        } else {
            $phone = preg_replace('/\D/', '', $username);

            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE username = ? OR email = ? OR REPLACE(phone,' ','') = ?
                LIMIT 1
            ");

            $stmt->execute([$username, $username, $phone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user
                && password_verify($password, $user['password'])
            ) {
                if (($user['status'] ?? '') !== 'active') {
                    $error = 'Your account is ' . ($user['status'] ?? 'inactive') . '. Please contact administrator.';
                    rate_limit_hit("login:$clientIp", $rateLimitMax, $rateLimitWindow);
                } else {
                    login_user_session((int)$user['id']);
                    if (is_admin()) {
                        header('Location: ' . app_url('/admin/dashboard'));
                    } else {
                        header('Location: ' . app_url('/'));
                    }
                    exit;
                }
            } else {
                $error = 'Invalid username, phone, or password';
                rate_limit_hit("login:$clientIp", $rateLimitMax, $rateLimitWindow);
            }
        }
    }
}

$rateLimitStatus = rate_limit_check("login:$clientIp", $rateLimitMax, $rateLimitWindow);
$showRateLimitWarning = $rateLimitStatus['remaining'] <= 2 && $rateLimitStatus['remaining'] > 0;

$displayError = $error;
if (!empty($_SESSION['oauth_error'])) {
    $displayError = $_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in | Kauzariyya Digital Musabaqa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('css/auth.css') ?>?v=20260722v1">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>

<div class="auth-page">
    <div class="auth-card">
        
        <!-- LEFT POSTER PANEL (DESKTOP / TABLET ONLY) -->
        <div class="auth-poster">
            <div class="auth-poster-bg" style="background-image: url('<?= asset_url('images/kauzariyya8.png') ?>');"></div>
            <div class="auth-poster-overlay"></div>
            
            <div class="auth-poster-header">
                <img src="<?= asset_url('images/thanafus-logo.png') ?>" alt="Thanafus Logo" class="auth-poster-logo">
                <a href="<?= app_url('/home') ?>" class="auth-poster-back">
                    <span>Back to website</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div class="auth-poster-content">
                <p class="auth-poster-kicker">Al Jamiathul Kauzariyya</p>
                <h2>Where talent<br>finds its stage.</h2>
                <p>Thanafus 2026 &middot; Digital Musabaqa Portal</p>
                
                <div class="auth-poster-dots">
                    <span class="dot active"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
            </div>
        </div>

        <!-- RIGHT FORM PANEL -->
        <div class="auth-form-wrap">
            <div class="auth-form-header">
                <img src="<?= asset_url('images/thanafus-logo.png') ?>" alt="Thanafus" class="mobile-brand-logo">
                <h1>Log in</h1>
                <p>Don't have an account? <a href="<?= app_url('/auth/signup.php') ?>">Create account</a></p>
            </div>

            <?php if ($displayError): ?>
                <div class="auth-alert auth-alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= e($displayError) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($showRateLimitWarning): ?>
                <div class="auth-alert auth-alert-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?= $rateLimitStatus['remaining'] ?> attempts remaining before lockout</span>
                </div>
            <?php endif; ?>

            <form method="POST" class="form-grid" id="loginForm">
                
                <div class="form-field">
                    <label>Username, Phone or Email</label>
                    <div class="input-box">
                        <i class="fa-solid fa-user field-icon"></i>
                        <input type="text" name="username" id="usernameInput" placeholder="Enter username, phone, or email" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username">
                    </div>
                </div>

                <div class="form-field">
                    <label>Password</label>
                    <div class="input-box">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" name="password" id="passwordInput" placeholder="Enter your password" required autocomplete="current-password">
                        <span class="toggle-pass" onclick="togglePassword()">
                            <i class="fa-regular fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                </div>

                <div class="form-options">
                    <label class="form-checkbox">
                        <input type="checkbox" name="remember" checked>
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Log in</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>

                <div class="auth-divider">
                    <span>Or authenticate with</span>
                </div>

                <div class="social-buttons" style="flex-direction: column; gap: 10px;">
                    <!-- Google Custom Login Wrapper -->
                    <div class="gsi-overlay-wrap">
                        <button type="button" class="btn-google-custom" style="width: 100%;">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
                                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
                            </svg>
                            <span>Google</span>
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
                             data-text="signin_with"
                             data-size="large"
                             data-logo_alignment="left"
                             data-width="400">
                        </div>
                    </div>

                    <!-- Guest Login Button -->
                    <button type="submit" name="guest_login" value="1" class="btn-guest" formnovalidate>
                        <i class="fa-solid fa-user-secret"></i>
                        <span>Continue as Guest</span>
                    </button>
                </div>

            </form>
        </div>

    </div>
</div>

<script>
function togglePassword(){
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('toggleIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}
</script>

</body>
</html>