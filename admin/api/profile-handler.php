<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$dashPdo = $GLOBALS['dashboard_pdo'];
$myId    = (int)($_SESSION['user_id'] ?? 0);
$back    = $_POST['_back'] ?? app_url('/admin/event-manager/accounts.php');

// Sanitise redirect target — only allow relative paths on same host
if (!str_starts_with($back, '/') && !str_starts_with($back, app_url('/'))) {
    $back = app_url('/admin/event-manager/accounts.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    admin_flash('error', 'Invalid security token.');
    header('Location: ' . $back);
    exit;
}

$action = (string)($_POST['action'] ?? '');

/* ── Update own profile ── */
if ($action === 'update_profile') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email    = trim((string)($_POST['email']     ?? ''));
    $phone    = trim((string)($_POST['phone']     ?? ''));

    if ($fullName === '') {
        admin_flash('error', 'Full name is required.');
    } else {
        try {
            $dashPdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                    ->execute([$fullName, $email, $phone, $myId]);
            // Refresh session user data
            $_SESSION['user'] = load_user($myId);
            admin_flash('success', 'Profile updated successfully.');
        } catch (Throwable $e) {
            admin_flash('error', 'Could not update profile: ' . $e->getMessage());
        }
    }
    header('Location: ' . $back);
    exit;
}

/* ── Change own password ── */
if ($action === 'change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password']     ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    try {
        $stmt = $dashPdo->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$myId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            admin_flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            admin_flash('error', 'New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            admin_flash('error', 'Passwords do not match.');
        } else {
            $dashPdo->prepare("UPDATE users SET password=? WHERE id=?")
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $myId]);
            admin_flash('success', 'Password changed successfully.');
        }
    } catch (Throwable $e) {
        admin_flash('error', 'Could not change password: ' . $e->getMessage());
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
