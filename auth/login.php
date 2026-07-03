<?php

require_once __DIR__ . '/../config/auth.php';

/*
|--------------------------------------------------------------------------
| IF ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['user_id'])) {

    header(
        'Location: '
        . app_url('/admin/dashboard')
    );

    exit;
}

$error = '';

/*
|--------------------------------------------------------------------------
| LOGIN SUBMIT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username =
        trim($_POST['username'] ?? '');

    $password =
        $_POST['password'] ?? '';

    if (
        empty($username)
        || empty($password)
    ) {

        $error =
            'Please fill all fields';

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
            && password_verify(
                $password,
                $user['password']
            )
        ) {

            login_user_session(
                (int)$user['id']
            );

            header(
                'Location: '
                . app_url('/admin/dashboard')
            );

            exit;

        } else {

            $error =
                'Invalid username or password';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Musabaqa Login
</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap"
rel="stylesheet"
>

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>

<link
    rel="stylesheet"
    href="<?= asset_url('css/auth.css') ?>"
>

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>

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

    <form
        method="POST"
        class="auth-card"
    >

        <img
            src="<?= asset_url('images/thanafus-logo.png') ?>"
            class="auth-logo"
            alt="Thanafus"
        >

        <div class="auth-title">
            Welcome Back
        </div>

        <div class="auth-subtitle">
            Kauzariyya Digital Musabaqa System
        </div>

        <?php if($error): ?>

            <div class="auth-error">

                <i class="fa-solid fa-circle-exclamation"></i>

                <?= e($error) ?>

            </div>

        <?php endif; ?>

        <!-- USERNAME -->

        <div class="input-group">

            <label>
                Username
            </label>

            <div class="input-wrap">

                <i class="fa-solid fa-user"></i>

                <input
                    type="text"
                    name="username"
                    placeholder="Enter username"
                    required
                >

            </div>

        </div>

        <!-- PASSWORD -->

        <div class="input-group">

            <label>
                Password
            </label>

            <div class="input-wrap">

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    name="password"
                    placeholder="Enter password"
                    required
                >

            </div>

        </div>

        <!-- SUBMIT -->

        <button
            type="submit"
            class="auth-btn"
        >

            <i class="fa-solid fa-right-to-bracket"></i>

            Login

        </button>

        <a
            href="<?= app_url('/home') ?>"
            class="back-home"
        >

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
        '.input-group',
        '.auth-btn',
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
            // Remove transform from auth-card to prevent rendering artifacts/issues
            gsap.set('.auth-card', { clearProps: 'transform,scale' });
        }
    });

    // Stagger elements in
    gsap.to([
        '.auth-logo',
        '.auth-title',
        '.auth-subtitle',
        '.auth-error',
        '.input-group',
        '.auth-btn',
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
