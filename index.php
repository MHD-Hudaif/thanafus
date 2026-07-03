<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/app.php';

$user = $_SESSION['user'] ?? null;
$isLoggedIn = !empty($user);

$backgroundImages = array_values(array_filter(
    glob(__DIR__ . '/assets/images/kauzariyya*.png') ?: [],
    static fn(string $path): bool => !str_contains(basename($path), 'logo')
));
natsort($backgroundImages);
$backgroundImages = array_values($backgroundImages);

$highlights = [
    ['icon' => 'fa-book-quran', 'title' => "Qur'an Competitions", 'text' => 'Hifz, Tajweed, Qiraat and disciplined recitation programs.'],
    ['icon' => 'fa-microphone-lines', 'title' => 'Speech and Debate', 'text' => 'Stage events that build confidence, clarity and courage.'],
    ['icon' => 'fa-book-open-reader', 'title' => 'Islamic Quiz', 'text' => 'Knowledge rounds across Islamic studies, history and culture.'],
    ['icon' => 'fa-pen-nib', 'title' => 'Literary Events', 'text' => 'Arabic, Malayalam and creative writing competitions.'],
];

$liveFeatures = [
    'Live Leaderboard',
    'Current Performance',
    'Upcoming Schedule',
    'Team Rankings',
    'Program Results',
    'Digital ID Cards',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kauzariyya Musabaqa 2026</title>

<link rel="stylesheet" href="<?= asset_url('css/home.css') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
</head>

<body>
<canvas id="bgCanvas" aria-hidden="true"></canvas>

<section class="intro" id="intro" aria-label="Kauzariyya Musabaqa intro">
    <div class="intro-stage">
        <div class="intro-ring intro-ring-one"></div>
        <div class="intro-ring intro-ring-two"></div>

        <img src="<?= asset_url('images/kauzariyya-logo.png') ?>" id="kauzariyyaLogo" class="intro-logo kauzariyya-logo" alt="Kauzariyya">

        <div class="thanafus-wrapper" id="thanafusWrapper">
            <div class="logo-glow" id="logoGlow"></div>
            <img src="<?= asset_url('images/thanafus-logo.png') ?>" id="thanafusLogo" alt="Thanafus">
            <div class="logo-light" id="logoLight"></div>
        </div>

        <div class="intro-copy" id="introCopy">
            <div class="intro-kicker">Al Jamiathul Kauzariyya Arabic College</div>
            <div class="intro-title">Kauzariyya Musabaqa 2026</div>
            <div class="intro-subtitle">Excellence Through Knowledge • Unity Through Faith</div>
        </div>
    </div>
</section>

<button class="skip-btn" id="skipBtn" type="button">Skip Intro</button>

<main class="home" id="home">
    <div class="bg-slideshow" aria-hidden="true">
        <?php foreach ($backgroundImages as $index => $imagePath): ?>
            <div class="bg-image<?= $index === 0 ? ' active' : '' ?>" style="background-image:url('<?= e(asset_url('images/' . basename($imagePath))) ?>')"></div>
        <?php endforeach; ?>
    </div>
    <div class="gradient-overlay" aria-hidden="true"></div>

    <header class="header" id="header">
        <a href="<?= app_url('/index') ?>" class="header-left">
            <img src="<?= asset_url('images/thanafus-logo.png') ?>" id="headerLogo" alt="Kauzariyya Musabaqa">
            <div class="header-brand-text">
                <strong>Kauzariyya Musabaqa</strong>
                <span>Digital Competition Platform</span>
            </div>
        </a>

        <nav class="nav">
            <a href="#about">About</a>
            <a href="#vision">Vision</a>
            <a href="#events">Events</a>
            <a href="#live">Live</a>
        </nav>

        <div class="header-actions">
            <a href="<?= app_url('/tv') ?>" class="header-btn secondary"><i class="fa-solid fa-tv"></i> TV Mode</a>
            <?php if ($isLoggedIn): ?>
                <a href="<?= app_url('/admin/dashboard') ?>" class="header-btn"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
            <?php else: ?>
                <a href="<?= app_url('/auth/login') ?>" class="header-btn"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="hero">
        <div class="hero-content">
            <div class="hero-eyebrow">Inspired with the Aroma of Sterling Islam</div>
            <h1>Kauzariyya <span>Musabaqa 2026</span></h1>
            <p>Competing in excellence, growing in knowledge, and standing together in faith. The official digital platform for Kauzariyya's student competitions, live scores, schedules, teams and results.</p>
            <div class="hero-motto">Excellence Through Knowledge • Unity Through Faith • Success Through Sincerity</div>
            <div class="hero-actions">
                <a href="<?= app_url('/tv') ?>" class="action-btn primary"><i class="fa-solid fa-tower-broadcast"></i> Watch Live Display</a>
                <a href="#about" class="action-btn"><i class="fa-solid fa-arrow-down"></i> Enter The Arena</a>
            </div>
        </div>
    </section>

    <section class="content-section" id="about">
        <div class="section-grid">
            <div class="text-panel">
                <div class="section-kicker">About the Musabaqa</div>
                <h2>A stage for knowledge, discipline and sincere competition.</h2>
                <p>The Kauzariyya Musabaqa is an annual academic and Islamic competition organized by Al Jamiathul Kauzariyya Arabic College. It provides students an opportunity to demonstrate excellence in Qur'an, Hadith, Arabic, Islamic studies, speeches, recitation, literature and co-curricular activities.</p>
                <p>More than a competition, it strengthens brotherhood, confidence, discipline and character while celebrating the talents Allah has placed in every student.</p>
            </div>
            <div class="welcome-panel">
                <div class="arabic">بسم الله الرحمن الرحيم</div>
                <p>May this gathering become a means of seeking beneficial knowledge, strengthening Islamic values and encouraging every participant to strive for excellence with sincerity.</p>
            </div>
        </div>
    </section>

    <section class="content-section alt" id="vision">
        <div class="section-heading">
            <div class="section-kicker">Vision and Mission</div>
            <h2>Healthy competition shaped by the Qur'an and Sunnah.</h2>
        </div>
        <div class="mission-grid">
            <article>
                <h3>Vision</h3>
                <p>To inspire students to pursue excellence through healthy competition while upholding the teachings of the Qur'an and Sunnah.</p>
            </article>
            <article>
                <h3>Mission</h3>
                <ul>
                    <li>Encourage academic and Islamic excellence.</li>
                    <li>Develop leadership, confidence and discipline.</li>
                    <li>Discover and nurture student talents.</li>
                    <li>Strengthen unity, brotherhood and good character.</li>
                </ul>
            </article>
        </div>
    </section>

    <section class="content-section" id="events">
        <div class="section-heading">
            <div class="section-kicker">Event Highlights</div>
            <h2>Programs that bring every talent into the light.</h2>
        </div>
        <div class="highlight-grid">
            <?php foreach ($highlights as $highlight): ?>
                <article class="highlight-card">
                    <i class="fa-solid <?= e($highlight['icon']) ?>"></i>
                    <h3><?= e($highlight['title']) ?></h3>
                    <p><?= e($highlight['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="content-section alt" id="live">
        <div class="section-heading">
            <div class="section-kicker">Live Digital Platform</div>
            <h2>Real-time tools for stage, judges, teams and audience.</h2>
        </div>
        <div class="feature-strip">
            <?php foreach ($liveFeatures as $feature): ?>
                <div class="feature-pill"><i class="fa-solid fa-circle-dot"></i><?= e($feature) ?></div>
            <?php endforeach; ?>
        </div>
    </section>

    <footer class="home-footer">
        <strong>Al Jamiathul Kauzariyya Arabic College</strong>
        <span>Edathala, Aluva, Kerala</span>
    </footer>
</main>

<script src="<?= asset_url('js/home.js') ?>"></script>
</body>
</html>
