<?php

require_once __DIR__ . '/admin-helpers.php';

require_login();

$user = current_user();

$pageTitle =
    $pageTitle
    ?? 'Kauzariyya Musabaqa';

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
href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap"
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
CSS
===================================================== -->
<link
    rel="stylesheet"
    href="<?= APP_URL ?>/assets/css/admin.css"
>



<!-- =====================================================
GSAP
===================================================== -->

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>

</head>

<body>

<div class="admin-layout">
