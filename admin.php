<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/admin-helpers.php';

if (!empty($_SESSION['user'])) {
    if (is_admin()) {
        header('Location: ' . app_url('/admin/dashboard.php'));
    } else {
        $targetUrl = get_user_default_category_url();
        header('Location: ' . app_url($targetUrl));
    }
} else {
    header('Location: ' . app_url('/auth/login.php'));
}
exit;

