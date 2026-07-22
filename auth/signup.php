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
        // Standard User Signup
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $full_name === '' || $phone === '' || $password === '') {
            $error = "Please fill in all required fields.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } else {
            try {
                // Check if username already exists
                $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = "Username is already taken.";
                } else {
                    // Check if phone number is already registered in users
                    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
                    $stmt->execute([$phone]);
                    if ($stmt->fetch()) {
                        $error = "This phone number is already registered. Please login.";
                    } elseif (!empty($email)) {
                        // Check if email is already registered in users
                        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $error = "This email address is already registered. Please login.";
                        }
                    }
                }

                if ($error === '') {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, phone, email, password, full_name, email_verified_at, status)
                        VALUES (?, ?, ?, ?, ?, NOW(), 'active')
                    ");
                    $stmt->execute([
                        $username,
                        $phone,
                        $email !== '' ? $email : null,
                        $hashed_password,
                        $full_name
                    ]);

                    $user_id = $pdo->lastInsertId();

                    /* Check and Link Student */
                    $stmt = $pdo->prepare("SELECT * FROM students WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
                    $stmt->execute([$phone]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);

                    /* Check and Link Teacher */
                    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE REPLACE(phone, ' ', '') = ? LIMIT 1");
                    $stmt->execute([$phone]);
                    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($student) {
                        $pdo->prepare("UPDATE students SET user_id = ? WHERE id = ?")->execute([$user_id, $student['id']]);
                        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$user_id]);
                    } elseif ($teacher) {
                        $pdo->prepare("UPDATE teachers SET user_id = ? WHERE id = ?")->execute([$user_id, $teacher['id']]);
                        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 2)")->execute([$user_id]);
                    } else {
                        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$user_id]);
                    }

                    login_user_session((int)$user_id);
                    $_SESSION['just_logged_in'] = true;

                    header('Location: ' . app_url('/'));
                    exit;
                }
            } catch (Throwable $e) {
                error_log("Signup failed: " . $e->getMessage());
                $error = "Registration failed. Please try again.";
            }
        }
    }
}

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
    <title>Create Account | Kauzariyya Digital Musabaqa</title>
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
                <a href="<?= app_url('/auth/login.php') ?>" class="auth-poster-back">
                    <span>Back to login</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div class="auth-poster-content">
                <p class="auth-poster-kicker">Join the Community</p>
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
                <h1>Create account</h1>
                <p>Already have an account? <a href="<?= app_url('/auth/login.php') ?>">Log in</a></p>
            </div>

            <?php if ($displayError): ?>
                <div class="auth-alert auth-alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= e($displayError) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="form-grid" id="signupForm">
                
                <div class="form-grid-2">
                    <div class="form-field">
                        <label>Full Name</label>
                        <div class="input-box">
                            <i class="fa-solid fa-id-card field-icon"></i>
                            <input type="text" name="full_name" placeholder="Full name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-field">
                        <label>Username</label>
                        <div class="input-box">
                            <i class="fa-solid fa-user field-icon"></i>
                            <input type="text" name="username" placeholder="Username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-field">
                        <label>Phone Number</label>
                        <div class="input-box">
                            <i class="fa-solid fa-phone field-icon"></i>
                            <input type="tel" name="phone" placeholder="Phone number" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-field">
                        <label>Email (Optional)</label>
                        <div class="input-box">
                            <i class="fa-solid fa-envelope field-icon"></i>
                            <input type="email" name="email" placeholder="Email address" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-field">
                    <label>Password</label>
                    <div class="input-box">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" name="password" id="passwordInput" placeholder="Min 6 characters" required autocomplete="new-password">
                        <span class="toggle-pass" onclick="togglePassword()">
                            <i class="fa-regular fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                </div>

                <div class="form-options">
                    <label class="form-checkbox">
                        <input type="checkbox" name="terms" checked required>
                        <span>I agree to the Terms &amp; Conditions</span>
                    </label>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Create account</span>
                    <i class="fa-solid fa-user-plus"></i>
                </button>

                <div class="auth-divider">
                    <span>Or register with</span>
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
                             data-context="signup"
                             data-ux_mode="redirect"
                             data-login_uri="<?= app_absolute_url('/auth/google-oauth.php') ?>"
                             data-auto_prompt="false">
                        </div>
                        <div class="g_id_signin"
                             data-type="standard"
                             data-shape="rectangular"
                             data-theme="filled_blue"
                             data-text="signup_with"
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
