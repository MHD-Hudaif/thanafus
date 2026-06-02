<?php

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$programId = (int)($_GET['program'] ?? 0);

if ($programId <= 0) {
    admin_flash('error', 'Please select a program before creating an entry.');
    admin_redirect('/admin/programs.php');
}

admin_redirect('/admin/entries.php', [
    'program' => $programId,
    'create' => 1,
]);
