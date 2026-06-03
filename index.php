<?php

session_start();

/*
|--------------------------------------------------------------------------
| APP URL
|--------------------------------------------------------------------------
*/

define(
    'APP_URL',
    '/kauzariyya-musabaqa'
);

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "kauzariyya"
);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/*
|--------------------------------------------------------------------------
| GET LOGGED IN USER
|--------------------------------------------------------------------------
*/

$user_id = $_SESSION['user_id'] ?? 1;

$user = null;

$stmt = $conn->prepare("
    SELECT username, profile_photo
    FROM users
    WHERE id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
}

$username = $user['username'] ?? 'Guest';

$profile_photo = !empty($user['profile_photo'])
    ? $user['profile_photo']
    : APP_URL . '/assets/images/default-user.png';

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Kauzariyya Musabaqa</title>

<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/home.css">

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

</head>

<body>

<canvas id="bgCanvas"></canvas>

<section class="intro" id="intro">

<img
src="<?= APP_URL ?>/assets/images/kauzariyya-logo.png"
id="kauzariyyaLogo"
class="logo"
>

<div class="thanafus-wrapper" id="thanafusWrapper">

<div class="logo-glow" id="logoGlow"></div>

<img
src="<?= APP_URL ?>/assets/images/thanafus-logo.png"
id="thanafusLogo"
>

<div class="logo-light" id="logoLight"></div>

</div>

</section>

<button class="skip-btn" id="skipBtn">
Skip Intro
</button>

<main class="home" id="home">

<div class="bg-slideshow">

<div
class="bg-image active"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya1.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya2.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya3.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya4.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya5.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya6.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya7.png')">
</div>

<div
class="bg-image"
style="background-image:url('<?= APP_URL ?>/assets/images/kauzariyya8.png')">
</div>

</div>

<div class="gradient-overlay"></div>

<header class="header" id="header">

<div class="header-left">

<img
src="<?= APP_URL ?>/assets/images/thanafus-logo.png"
id="headerLogo"
>

</div>

<nav class="nav">

<a href="<?= APP_URL ?>/home.php">الرئيسية</a>
<a href="<?= APP_URL ?>/teams.php">الفرق</a>
<a href="<?= APP_URL ?>/results.php">النتائج</a>
<a href="<?= APP_URL ?>/programs.php">البرامج</a>

</nav>

<div class="profile">

<div class="profile-name">
<?= htmlspecialchars($username) ?>
</div>

<div class="profile-pic">

<img
src="<?= htmlspecialchars($profile_photo) ?>"
alt="Profile Picture"
>

</div>

</div>

</header>

<section class="hero">

<a
href="<?= APP_URL ?>/home"
class="enter-btn"
id="enterBtn"
>
Enter The Arena
</a>

<div class="enter-sub" id="enterSub">
KAUZARIYYA DIGITAL MUSABAQA SYSTEM
</div>

</section>

</main>

<script src="<?= APP_URL ?>/assets/js/home.js"></script>

</body>
</html>