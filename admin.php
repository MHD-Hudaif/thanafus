<?php
require_once __DIR__ . '/config/auth.php';
if (!empty($_SESSION['user'])) {
    if (is_admin()) {
        header('Location: ' . app_url('/admin/dashboard'));
    } else {
        header('Location: ' . app_url('/'));
    }
} else {
    header('Location: ' . app_url('/auth/login'));
}
exit;
