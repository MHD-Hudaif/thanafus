<?php
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$programId = (int)($_GET['program_id'] ?? $_GET['program'] ?? 0);
$query = $_GET;
if ($programId > 0) {
    $query['program_id'] = $programId;
}

admin_redirect('/admin/registrar/entries.php', $query);
