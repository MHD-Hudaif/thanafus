<?php
require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

if (is_admin()) {
    admin_redirect('/admin/index.php');
} else {
    admin_redirect(get_user_default_category_url());
}
