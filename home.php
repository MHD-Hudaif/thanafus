<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/app.php';

$user =
    $_SESSION['user']
    ?? null;

$isLoggedIn =
    !empty($user);

$backgroundImages = array_values(array_filter(
    glob(__DIR__ . '/assets/images/kauzariyya*.png') ?: [],
    static fn(string $path): bool => !str_contains(basename($path), 'logo')
));
natsort($backgroundImages);
$backgroundImages = array_values($backgroundImages);

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Kauzariyya Musabaqa</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap"
rel="stylesheet"
>

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
text-decoration:none;
}

:root{
--green:#10b981;
--yellow:#facc15;
}

html,
body{
width:100%;
height:100%;
font-family:'Cairo',sans-serif;
background:#020617;
color:white;
overflow:hidden;
}

body{
position:relative;
}

/* =========================================================
BACKGROUND
========================================================= */

.bg{
position:fixed;
inset:0;
overflow:hidden;
z-index:1;
}

.bg::before{
content:"";

position:absolute;
inset:0;

background:
linear-gradient(
to bottom,
rgba(0,0,0,0.3),
rgba(0,0,0,0.45)
);

z-index:2;
}

.bg-slideshow{
position:absolute;
inset:0;
overflow:hidden;
}

.bg-image{
position:absolute;
inset:-5%;

background-size:cover;
background-position:center;

opacity:0;
transform:scale(1);

transition:opacity 4s cubic-bezier(0.4,0,0.2,1);

filter:brightness(0.92) contrast(1.02) saturate(1.08);

animation:
bgZoom 30s linear infinite alternate;

will-change:transform,opacity;
}

.bg-image.active{
opacity:1;
}

@keyframes bgZoom{

0%{
transform:
scale(1)
translate3d(0,0,0);
}

100%{
transform:
scale(1.12)
translate3d(20px,10px,0);
}

}

/* =========================================================
HEADER
========================================================= */

.header{
position:fixed;

top:20px;
left:50%;

transform:translateX(-50%);

width:calc(100% - 60px);
max-width:1550px;

height:95px;

padding:0 30px;

border-radius:38px;

background:
linear-gradient(
135deg,
rgba(255,255,255,0.08),
rgba(255,255,255,0.03)
);

backdrop-filter:blur(20px);

border:1px solid rgba(255,255,255,0.08);

display:flex;
align-items:center;
justify-content:space-between;

z-index:50;
}

.header-logo{
width:150px;
}

.nav{
display:flex;
align-items:center;
gap:35px;
}

.nav a{
color:rgba(255,255,255,0.75);
font-size:15px;
transition:0.3s ease;
}

.nav a:hover{
color:var(--yellow);
}

/* =========================================================
HEADER ACTIONS
========================================================= */

.header-actions{
display:flex;
align-items:center;
gap:15px;
}

.login-btn{
height:54px;
padding:0 24px;

border-radius:18px;

background:
linear-gradient(
135deg,
rgba(16,185,129,0.22),
rgba(250,204,21,0.1)
);

border:1px solid rgba(255,255,255,0.08);

display:flex;
align-items:center;
justify-content:center;
gap:10px;

font-weight:700;

color:white;

transition:0.3s ease;
}

.login-btn:hover{
transform:
translateY(-2px);

box-shadow:
0 0 30px rgba(16,185,129,0.15);
}

.profile-btn{
display:flex;
align-items:center;
gap:12px;
}

.profile-btn img{
width:54px;
height:54px;

border-radius:50%;
object-fit:cover;
border:2px solid rgba(255,255,255,0.12);
}

/* =========================================================
HERO
========================================================= */

.hero{
position:relative;
z-index:10;

width:100%;
height:100vh;

display:flex;
justify-content:center;
align-items:center;
flex-direction:column;

text-align:center;

padding:20px;
}

.hero-title{
font-size:
clamp(60px,9vw,160px);

font-weight:900;

line-height:1;

background:
linear-gradient(
to right,
#10b981,
#facc15
);

-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
}

.hero-subtitle{
margin-top:25px;

font-size:18px;

letter-spacing:4px;

color:rgba(255,255,255,0.7);
}

.hero-buttons{
display:flex;
align-items:center;
gap:20px;

margin-top:40px;
}

.hero-btn{
height:62px;

padding:0 36px;

border-radius:22px;

background:
linear-gradient(
135deg,
rgba(16,185,129,0.2),
rgba(250,204,21,0.08)
);

border:1px solid rgba(255,255,255,0.08);

display:flex;
align-items:center;
justify-content:center;
gap:12px;

font-size:16px;
font-weight:700;

color:white;

transition:0.35s ease;
}

.hero-btn:hover{
transform:
translateY(-4px)
scale(1.02);

box-shadow:
0 0 40px rgba(16,185,129,0.16);
}

.outline-btn{
background:
rgba(255,255,255,0.04);
}

/* =========================================================
FLOATING GLOW
========================================================= */

.glow{
position:absolute;

width:500px;
height:500px;

border-radius:50%;

background:
radial-gradient(
circle,
rgba(16,185,129,0.15),
transparent 70%
);

filter:blur(50px);

z-index:1;
}

.glow-1{
top:-100px;
left:-100px;
}

.glow-2{
bottom:-100px;
right:-100px;
}

/* =========================================================
MOBILE
========================================================= */

@media(max-width:900px){

.header{
width:calc(100% - 20px);

height:82px;

padding:0 18px;

border-radius:28px;
}

.nav{
display:none;
}

.hero-title{
font-size:64px;
}

.hero-buttons{
flex-direction:column;
width:100%;
}

.hero-btn{
width:100%;
}

}

</style>

</head>
<body>

<!-- =====================================================
BACKGROUND
===================================================== -->

<div class="bg">

    <div class="bg-slideshow">

        <?php foreach ($backgroundImages as $index => $imagePath): ?>

            <div
                class="bg-image<?= $index === 0 ? ' active' : '' ?>"
                style="background-image:url('<?= APP_URL ?>/assets/images/<?= e(basename($imagePath)) ?>')"
            ></div>

        <?php endforeach; ?>

    </div>

</div>

<div class="glow glow-1"></div>
<div class="glow glow-2"></div>

<!-- =====================================================
HEADER
===================================================== -->

<header class="header">

    <img
        src="<?= APP_URL ?>/assets/images/thanafus-logo.png"
        class="header-logo"
    >

    <nav class="nav">

        <a href="<?= APP_URL ?>/home">
            Home
        </a>

        <a href="<?= APP_URL ?>/events">
            Events
        </a>

        <a href="<?= APP_URL ?>/teams">
            Teams
        </a>

        <a href="<?= APP_URL ?>/tv/scoreboard">
            TV Mode
        </a>

    </nav>

    <div class="header-actions">

        <?php if($isLoggedIn): ?>

            <a
                href="<?= APP_URL ?>/admin/dashboard"
                class="login-btn"
            >

                <i class="fa-solid fa-grid-2"></i>

                Dashboard

            </a>

            <a
                href="<?= APP_URL ?>/auth/logout"
                class="profile-btn"
            >

                <img

                    src="<?=
                        !empty($user['profile_photo'])

                        ? APP_URL . '/uploads/profile/'
                            . htmlspecialchars($user['profile_photo'])

                        : 'https://ui-avatars.com/api/?name='
                            . urlencode(
                                $user['full_name']
                                ?? $user['username']
                            )
                    ?>"

                    alt="Profile"

                >

            </a>

        <?php else: ?>

            <a
                href="<?= APP_URL ?>/auth/login"
                class="login-btn"
            >

                <i class="fa-solid fa-right-to-bracket"></i>

                Login

            </a>

        <?php endif; ?>

    </div>

</header>

<!-- =====================================================
HERO
===================================================== -->

<section class="hero">

    <div class="hero-title">
        التنافس
    </div>

    <div class="hero-subtitle">
        KAUZARIYYA DIGITAL MUSABAQA PLATFORM
    </div>

    <div class="hero-buttons">

        <a
            href="<?= APP_URL ?>/tv/scoreboard"
            class="hero-btn"
        >

            <i class="fa-solid fa-tv"></i>

            Live Scoreboard

        </a>

        <?php if($isLoggedIn): ?>

            <a
                href="<?= APP_URL ?>/admin/dashboard"
                class="hero-btn outline-btn"
            >

                <i class="fa-solid fa-shield"></i>

                Admin Panel

            </a>

        <?php endif; ?>

    </div>

</section>

<script>
(function () {
    const backgrounds = document.querySelectorAll('.bg-image');
    if (backgrounds.length < 2) return;

    let currentBg = 0;

    setInterval(function () {
        backgrounds[currentBg].classList.remove('active');
        currentBg = (currentBg + 1) % backgrounds.length;
        backgrounds[currentBg].classList.add('active');
    }, 15000);
})();
</script>

</body>
</html>