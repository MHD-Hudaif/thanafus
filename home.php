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
    ['icon' => 'fa-book-quran', 'title' => "Qur'an Competitions", 'text' => 'Hifz, Tajweed, Qiraat and recitation programs.'],
    ['icon' => 'fa-microphone-lines', 'title' => 'Speech and Debate', 'text' => 'Stage events that build confidence and clarity.'],
    ['icon' => 'fa-book-open-reader', 'title' => 'Islamic Quiz', 'text' => 'Knowledge rounds across Islamic studies and history.'],
    ['icon' => 'fa-mosque', 'title' => 'Adhan Competition', 'text' => 'A platform for discipline, voice and devotion.'],
    ['icon' => 'fa-pen-nib', 'title' => 'Literary Events', 'text' => 'Arabic, Malayalam and creative writing contests.'],
    ['icon' => 'fa-trophy', 'title' => 'Team Championship', 'text' => 'A complete team-based competition journey.'],
];

$liveFeatures = [
    'Live Leaderboard',
    'Current Performance',
    'Upcoming Schedule',
    'Program Results',
    'Team Rankings',
    'Medal Table',
    'Digital ID Cards',
    'Real-time Score Updates',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kauzariyya Musabaqa 2026</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>
:root {
    --bg: #07100d;
    --surface: #0e1915;
    --surface-2: #14241d;
    --text: #f8fafc;
    --muted: #b6c4ba;
    --muted-2: #7f9187;
    --green: #16a34a;
    --emerald: #10b981;
    --gold: #d8a827;
    --gold-2: #facc15;
    --border: rgba(255,255,255,.12);
    --shadow: 0 22px 60px rgba(0,0,0,.34);
    --radius: 8px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    min-height: 100vh;
    font-family: Cairo, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
}
a { color: inherit; text-decoration: none; }
img { max-width: 100%; display: block; }

.site-header {
    position: fixed;
    top: 18px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 40;
    width: min(1180px, calc(100% - 28px));
    min-height: 74px;
    padding: 10px 14px 10px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: rgba(7, 16, 13, .78);
    backdrop-filter: blur(18px);
    box-shadow: 0 16px 42px rgba(0,0,0,.22);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.brand img { width: 58px; height: auto; }
.brand-title { font-weight: 900; line-height: 1.05; white-space: nowrap; }
.brand-subtitle { color: var(--muted); font-size: 12px; font-weight: 700; }

.nav {
    display: flex;
    align-items: center;
    gap: 24px;
    color: rgba(255,255,255,.78);
    font-size: 14px;
    font-weight: 700;
}
.nav a:hover { color: var(--gold-2); }

.header-actions { display: flex; gap: 10px; align-items: center; }
.btn {
    min-height: 42px;
    padding: 9px 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: var(--radius);
    border: 1px solid transparent;
    font-weight: 900;
    line-height: 1.2;
    transition: transform .2s ease, filter .2s ease, border-color .2s ease;
}
.btn:hover { transform: translateY(-2px); filter: brightness(1.06); }
.btn-primary { background: linear-gradient(135deg, var(--emerald), var(--gold-2)); color: #062117; }
.btn-secondary { background: rgba(255,255,255,.08); border-color: var(--border); color: var(--text); }

.hero {
    position: relative;
    min-height: 92vh;
    display: grid;
    align-items: end;
    overflow: hidden;
    padding: 140px 24px 72px;
}
.hero-bg, .hero-bg::after, .bg-image {
    position: absolute;
    inset: 0;
}
.hero-bg { z-index: 0; background: #08110e; }
.hero-bg::after {
    content: "";
    background:
        linear-gradient(90deg, rgba(7,16,13,.92), rgba(7,16,13,.68), rgba(7,16,13,.36)),
        linear-gradient(0deg, rgba(7,16,13,1), rgba(7,16,13,.22) 42%, rgba(7,16,13,.72));
}
.bg-image {
    background-size: cover;
    background-position: center;
    opacity: 0;
    transform: scale(1.04);
    transition: opacity 2.8s ease;
}
.bg-image.active { opacity: 1; }

.hero-inner {
    position: relative;
    z-index: 2;
    width: min(1180px, 100%);
    margin: 0 auto;
    display: grid;
    grid-template-columns: minmax(0, 760px);
    gap: 30px;
}
.eyebrow {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: 8px;
    min-height: 30px;
    padding: 4px 10px;
    border-radius: var(--radius);
    border: 1px solid rgba(250,204,21,.3);
    color: #fef3c7;
    background: rgba(250,204,21,.08);
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.hero h1 {
    max-width: 760px;
    font-size: clamp(44px, 7vw, 88px);
    line-height: .98;
    font-weight: 900;
    letter-spacing: 0;
}
.hero h1 span { color: var(--gold-2); }
.hero-copy {
    max-width: 690px;
    color: rgba(255,255,255,.82);
    font-size: clamp(16px, 2vw, 20px);
}
.motto {
    color: var(--gold-2);
    font-weight: 900;
}
.hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }

.section {
    padding: 74px 24px;
    background: var(--bg);
}
.section.alt { background: #0a1511; }
.section-inner {
    width: min(1180px, 100%);
    margin: 0 auto;
}
.section-heading {
    display: grid;
    gap: 10px;
    margin-bottom: 28px;
}
.section-kicker {
    color: var(--gold-2);
    font-size: 13px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.section h2 {
    max-width: 780px;
    font-size: clamp(30px, 4vw, 50px);
    line-height: 1.08;
    font-weight: 900;
    letter-spacing: 0;
}
.section-lead {
    max-width: 850px;
    color: var(--muted);
    font-size: 17px;
}

.about-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
    gap: 24px;
    align-items: stretch;
}
.story-panel, .quote-panel {
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    box-shadow: var(--shadow);
}
.story-panel p + p { margin-top: 14px; }
.quote-panel {
    display: grid;
    align-content: center;
    gap: 18px;
    border-top: 4px solid var(--gold);
}
.arabic {
    font-size: 30px;
    font-weight: 900;
    line-height: 1.6;
    color: #fef3c7;
    direction: rtl;
}
.quote-text {
    color: var(--muted);
    font-size: 17px;
}

.mission-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}
.mission-block {
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
}
.mission-block h3 {
    margin-bottom: 12px;
    font-size: 24px;
    font-weight: 900;
}
.mission-list {
    display: grid;
    gap: 10px;
    color: var(--muted);
    list-style: none;
}
.mission-list li {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.mission-list i { margin-top: 6px; color: var(--emerald); }

.highlight-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.highlight-card {
    min-height: 190px;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
}
.highlight-card i {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    margin-bottom: 18px;
    border-radius: var(--radius);
    background: rgba(16,185,129,.13);
    color: var(--gold-2);
    font-size: 20px;
}
.highlight-card h3 {
    margin-bottom: 8px;
    font-size: 20px;
    font-weight: 900;
}
.highlight-card p { color: var(--muted); }

.feature-strip {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.feature-pill {
    min-height: 62px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-2);
    color: rgba(255,255,255,.88);
    font-weight: 800;
}
.feature-pill i { color: var(--emerald); }

.welcome {
    position: relative;
    overflow: hidden;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    background:
        linear-gradient(90deg, rgba(7,16,13,.96), rgba(7,16,13,.72)),
        url('<?= asset_url('images/kauzariyya-logo.png') ?>') center right 8% / min(420px, 65vw) no-repeat,
        #07100d;
}
.welcome .section-inner {
    display: grid;
    gap: 20px;
}
.welcome-message {
    max-width: 820px;
    color: rgba(255,255,255,.84);
    font-size: 18px;
}

.footer {
    padding: 44px 24px;
    background: #050d0a;
    border-top: 1px solid var(--border);
}
.footer-inner {
    width: min(1180px, 100%);
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    gap: 24px;
    align-items: flex-start;
    color: var(--muted);
}
.footer strong { display: block; color: var(--text); font-size: 18px; }
.footer em { color: #fef3c7; font-style: normal; font-weight: 800; }

@media (max-width: 960px) {
    .site-header { align-items: flex-start; }
    .nav { display: none; }
    .about-grid, .mission-grid { grid-template-columns: 1fr; }
    .highlight-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .feature-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
    .site-header {
        position: absolute;
        top: 10px;
        min-height: auto;
        align-items: center;
    }
    .brand img { width: 48px; }
    .brand-subtitle { display: none; }
    .header-actions .btn-secondary { display: none; }
    .hero { min-height: 96vh; padding: 118px 16px 52px; }
    .hero-actions .btn { width: 100%; }
    .section { padding: 56px 16px; }
    .highlight-grid, .feature-strip { grid-template-columns: 1fr; }
    .footer-inner { display: grid; }
}
</style>
</head>

<body>
<header class="site-header">
    <a href="<?= app_url('/home') ?>" class="brand">
        <img src="<?= asset_url('images/thanafus-logo.png') ?>" alt="Kauzariyya Musabaqa">
        <div>
            <div class="brand-title">Kauzariyya Musabaqa</div>
            <div class="brand-subtitle">Digital Competition Platform</div>
        </div>
    </a>

    <nav class="nav">
        <a href="#about">About</a>
        <a href="#vision">Vision</a>
        <a href="#events">Events</a>
        <a href="#live">Live</a>
        <a href="#welcome">Welcome</a>
    </nav>

    <div class="header-actions">
        <a href="<?= app_url('/tv') ?>" class="btn btn-secondary"><i class="fa-solid fa-tv"></i> TV Mode</a>
        <?php if ($isLoggedIn): ?>
            <a href="<?= app_url('/admin/dashboard') ?>" class="btn btn-primary"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <?php else: ?>
            <a href="<?= app_url('/auth/login') ?>" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
        <?php endif; ?>
    </div>
</header>

<main>
    <section class="hero">
        <div class="hero-bg" aria-hidden="true">
            <?php foreach ($backgroundImages as $index => $imagePath): ?>
                <div class="bg-image<?= $index === 0 ? ' active' : '' ?>" style="background-image:url('<?= e(asset_url('images/' . basename($imagePath))) ?>')"></div>
            <?php endforeach; ?>
        </div>

        <div class="hero-inner">
            <div class="eyebrow"><i class="fa-solid fa-star-and-crescent"></i> Al Jamiathul Kauzariyya Arabic College</div>
            <h1>Kauzariyya <span>Musabaqa 2026</span></h1>
            <p class="hero-copy">Competing in excellence, growing in knowledge, and standing together in faith. The official digital platform for Kauzariyya's student competitions, live scores, schedules, teams and results.</p>
            <p class="motto">Excellence Through Knowledge • Unity Through Faith • Success Through Sincerity</p>
            <div class="hero-actions">
                <a href="<?= app_url('/tv') ?>" class="btn btn-primary"><i class="fa-solid fa-tower-broadcast"></i> Watch Live Display</a>
                <a href="#events" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Explore Events</a>
            </div>
        </div>
    </section>

    <section class="section" id="about">
        <div class="section-inner about-grid">
            <div class="story-panel">
                <div class="section-heading">
                    <div class="section-kicker">About the Musabaqa</div>
                    <h2>A stage for knowledge, discipline and sincere competition.</h2>
                </div>
                <p>The Kauzariyya Musabaqa is an annual academic and Islamic competition organized by Al Jamiathul Kauzariyya Arabic College. It gives students a meaningful platform to demonstrate excellence in Qur'an, Hadith, Arabic, Islamic studies, speeches, recitation, literature and co-curricular programs.</p>
                <p>More than a competition, it is a gathering that strengthens confidence, brotherhood, discipline and good character while celebrating the talents Allah has placed in every student.</p>
            </div>

            <div class="quote-panel">
                <div class="arabic">بسم الله الرحمن الرحيم</div>
                <p class="quote-text">Inspired with the aroma of sterling Islam, Kauzariyya continues to guide students toward beneficial knowledge, service and sincerity.</p>
            </div>
        </div>
    </section>

    <section class="section alt" id="vision">
        <div class="section-inner">
            <div class="section-heading">
                <div class="section-kicker">Vision and Mission</div>
                <h2>Healthy competition shaped by the Qur'an and Sunnah.</h2>
                <p class="section-lead">The Musabaqa exists to inspire students to pursue excellence while preserving humility, respect and unity.</p>
            </div>

            <div class="mission-grid">
                <div class="mission-block">
                    <h3>Vision</h3>
                    <p class="section-lead">To inspire students to pursue excellence through healthy competition while upholding the teachings of the Qur'an and Sunnah.</p>
                </div>

                <div class="mission-block">
                    <h3>Mission</h3>
                    <ul class="mission-list">
                        <li><i class="fa-solid fa-check"></i><span>Encourage academic and Islamic excellence.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Develop leadership, confidence and discipline.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Discover and nurture student talents.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Strengthen unity, brotherhood and good character.</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="events">
        <div class="section-inner">
            <div class="section-heading">
                <div class="section-kicker">Event Highlights</div>
                <h2>Programs that bring every talent into the light.</h2>
                <p class="section-lead">From recitation and speeches to writing, quiz and team championship moments, every event is built to reward preparation, sincerity and presence.</p>
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
        </div>
    </section>

    <section class="section alt" id="live">
        <div class="section-inner">
            <div class="section-heading">
                <div class="section-kicker">Live Digital Platform</div>
                <h2>Real-time tools for stage, judges, teams and audience.</h2>
                <p class="section-lead">The Musabaqa system connects scoring, schedules, team standings, ID cards and TV displays in one smooth competition workflow.</p>
            </div>

            <div class="feature-strip">
                <?php foreach ($liveFeatures as $feature): ?>
                    <div class="feature-pill"><i class="fa-solid fa-circle-dot"></i><?= e($feature) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section welcome" id="welcome">
        <div class="section-inner">
            <div class="section-heading">
                <div class="section-kicker">Welcome Message</div>
                <h2>May this gathering become a means of beneficial knowledge.</h2>
            </div>
            <p class="welcome-message">We warmly welcome all participants, judges, teachers, parents and guests to the Kauzariyya Musabaqa. May Allah accept our efforts, strengthen Islamic values through this gathering, and grant every participant success with sincerity. Ameen.</p>
            <div class="hero-actions">
                <a href="<?= app_url('/tv') ?>" class="btn btn-primary"><i class="fa-solid fa-tv"></i> Open TV Mode</a>
                <?php if ($isLoggedIn): ?>
                    <a href="<?= app_url('/admin/dashboard') ?>" class="btn btn-secondary"><i class="fa-solid fa-shield"></i> Admin Panel</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="footer-inner">
        <div>
            <strong>Al Jamiathul Kauzariyya Arabic College</strong>
            <span>Edathala, Aluva, Kerala</span>
        </div>
        <div><em>Inspired with the Aroma of Sterling Islam</em></div>
    </div>
</footer>

<script>
(function () {
    const backgrounds = document.querySelectorAll('.bg-image');
    if (backgrounds.length < 2) return;

    let current = 0;
    setInterval(function () {
        backgrounds[current].classList.remove('active');
        current = (current + 1) % backgrounds.length;
        backgrounds[current].classList.add('active');
    }, 9000);
})();
</script>
</body>
</html>
