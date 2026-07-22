<?php
require_once __DIR__ . '/../includes/admin-helpers.php';

session_start();
login_user_session(9);

header('Location: ' . app_url('/admin/database.php'));
exit;

