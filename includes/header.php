<?php

require_once __DIR__ . '/admin-helpers.php';

require_login();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$user = current_user();

$isAjaxRequest = admin_is_ajax();

$pageTitle =
    $pageTitle
    ?? 'Kauzariyya Musabaqa';

if (!$isAjaxRequest):
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

    <?= e($pageTitle) ?>

</title>

<!-- =====================================================
FONTS
===================================================== -->

<link rel="preconnect" href="https://fonts.googleapis.com">

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800;900&family=Cinzel:wght@400;700&family=Playfair+Display:wght@400;700&display=swap"
rel="stylesheet"
>

<!-- =====================================================
ICONS
===================================================== -->

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>

<!-- =====================================================
CSS Component Architecture
===================================================== -->
<link
    rel="stylesheet"
    href="<?= asset_url('css/main.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/main.css') ?>"
>
<link
    rel="stylesheet"
    href="<?= asset_url('css/cards.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/cards.css') ?>"
>
<link
    rel="stylesheet"
    href="<?= asset_url('css/tables.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/tables.css') ?>"
>
<link
    rel="stylesheet"
    href="<?= asset_url('css/admin.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>"
>
<link
    rel="stylesheet"
    href="<?= asset_url('css/includes.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/includes.css') ?>"
>
<link
    rel="stylesheet"
    href="<?= asset_url('css/musabaqa-categories.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/musabaqa-categories.css') ?>"
>



<!-- =====================================================
GSAP
===================================================== -->

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script>
window.APP_CONFIG = {
    baseUrl: <?= json_encode(APP_BASE_URL, JSON_UNESCAPED_SLASHES) ?>,
    assetUrl: <?= json_encode(asset_url(), JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= asset_url('js/admin.js') ?>?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>" defer></script>

<script src="https://unpkg.com/htmx.org@1.9.12"></script>
<script src="https://unpkg.com/htmx.org/dist/ext/sse.js"></script>
</head>

<body class="layout-sidebar-enabled" hx-boost="true" hx-target=".main-content">

<div class="admin-layout<?= !empty($useTopNavigation) ? ' admin-layout-topnav' : '' ?>">
<?php endif; ?>
