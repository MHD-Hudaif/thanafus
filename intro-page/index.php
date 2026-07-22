<?php
declare(strict_types=1);

if (!function_exists('term')) {
    function term(string $eng, string $ara): string {
        return '<span class="term-toggle"><span class="eng">' . htmlspecialchars($eng) . '</span><span class="ara">' . htmlspecialchars($ara) . '</span></span>';
    }
}

// Establish database context or fall back gracefully
try {
    require_once __DIR__ . '/../includes/public-data.php';
    $event = tv_active_event();
    $eventStats = tv_stats();
    $teams = teams();
    $schedule = schedule_items();
    
    $eventTitle = trim((string)($event['title'] ?? 'Kauzariyya Musabaqa 2026-27'));
    $eventTitle = $eventTitle !== '' ? $eventTitle : 'Kauzariyya Musabaqa 2026-27';
    
    $eventStart = !empty($event['start_date']) ? (string)$event['start_date'] : '2027-05-04T09:00:00';
    $eventDateFormatted = !empty($event['start_date']) 
        ? date('d F Y', strtotime((string)$event['start_date'])) 
        : '4 - 5 May 2027';
        
    $candidatesCount = '800+';
    $teamsCount = '4';
    $programsCount = (int)($eventStats['programs'] ?? count($schedule));
    $programsText = $programsCount > 0 ? (string)$programsCount : '45';
    $divisionsCount = '3';
} catch (\Throwable $e) {
    $eventTitle = 'Kauzariyya Musabaqa 2026-27';
    $eventStart = '2027-05-04T09:00:00';
    $eventDateFormatted = '4 - 5 May 2027';
    $candidatesCount = '800+';
    $teamsCount = '4';
    $programsText = '45';
    $divisionsCount = '3';
}

// Scholars data from Darul Ifta Kauzariyya
$scholars = [
    [
        'name' => 'Mufti Rajeeb Al Qasimi',
        'role' => 'Head of Department - Hanafi',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/82.jpg'
    ],
    [
        'name' => 'Al Usthad Ilyas Al Kauzari',
        'role' => 'Sheikhul Hadhees',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/86.jpg'
    ],
    [
        'name' => 'Al Usthad Abdul Samad Al Kauzari',
        'role' => 'Head of Department - Shafi',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/87.jpg'
    ],
    [
        'name' => 'Mufti Abid Ibrahim Al Kauzari',
        'role' => 'Admin of Darul Ifta',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/89.jpg'
    ],
    [
        'name' => 'Mufti Sirajudheen Al Kauzari',
        'role' => 'Senior Scholar Panel',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/88.jpg'
    ],
    [
        'name' => 'Mufti Mohammed Adil Al Kauzari',
        'role' => 'Jurisprudence Panel',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/79.jpg'
    ],
    [
        'name' => 'Mufti Muhammed Ansaf Al Kauzari',
        'role' => 'Research Scholar',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/80.jpg'
    ],
    [
        'name' => 'Mufti Muhammed Salih Al Kauzari',
        'role' => 'Jurisprudence Panel',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/77.jpg'
    ],
    [
        'name' => 'Mufti Muhammed Aslam Al Kauzari',
        'role' => 'Jurisprudence Panel',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/78.jpg'
    ],
    [
        'name' => 'Mufti Muhammed Shabab Al Kauzari',
        'role' => 'Scholar Panel',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/83.jpg'
    ],
    [
        'name' => 'Mufti Ahamed Hasan Al Kauzari',
        'role' => 'Scholar Panel',
        'image' => 'https://ui-avatars.com/api/?name=Mufti+Ahamed+Hasan+Al+Kauzari&background=1a7a4a&color=fff&size=512'
    ],
    [
        'name' => 'Mufti Aasif Swalih Al Kauzari',
        'role' => 'Scholar Panel',
        'image' => 'https://daruliftakauzariyya.com/storage/avatars/85.jpg'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Kauzariyya Musabaqa - Islamic Arts and Literature Fest. Plan, compete, celebrate.">
    <title>Thanafus 2026-27 | Kauzariyya Musabaqa Landing</title>
    
    <!-- CSS Dependencies -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Tailwind CSS Play CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandBlue: '#1b3a4b',
                        brandGreen: '#1b4332',
                        brandGold: '#ca8a04',
                        brandCream: '#fcfaf6',
                        cardBorder: 'rgba(0, 0, 0, 0.05)',
                    }
                }
            }
        }
    </script>

    <!-- Smooth Scrolling Lenis CDN -->
    <script src="https://unpkg.com/@studio-freight/lenis@1.0.34/dist/lenis.min.js"></script>
    <!-- JS Script -->
    <script src="script.js" defer></script>
</head>
<body>
    <!-- Glowing background warmth -->
    <div class="glow-blob blob-1"></div>
    <div class="glow-blob blob-2"></div>
    <div class="glow-blob blob-3"></div>

    <!-- Header Navigation -->
    <header>
        <div class="nav-container">
            <a href="#" class="logo-link" style="display: flex; align-items: center; gap: 10px;">
                <img src="<?= asset_url('kauzariyya-brand-icon.png') ?>" alt="Kauzariyya" style="height: 38px;">
            </a>
            
            <ul class="nav-menu">
                <li><a href="#about" class="nav-link">Articles</a></li>
                <li><a href="#categories" class="nav-link">Categories</a></li>
                <li><a href="#scoring" class="nav-link">Scoring</a></li>
                <li><a href="#scholars" class="nav-link">Scholars</a></li>
                <li><a href="#stages" class="nav-link">Stages</a></li>
                <li><a href="#location" class="nav-link">Location</a></li>
            </ul>
            
            <a href="../admin.php" class="btn-login">Portal Login</a>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="hero-content">
            <div class="hero-subtitle">Annual Arts Fest</div>
            <div class="hero-logo-wrap reveal-3d" style="max-width: 320px; width: 100%; margin: 0.5rem 0 1.5rem; display: flex; justify-content: center;">
                <img src="<?= asset_url('thanafus-logo.png') ?>" alt="Thanafus Logo" style="width: 100%; height: auto;">
            </div>
            <h1 class="hero-title" style="font-size: clamp(2rem, 5vw, 3.6rem); margin-top: 0.25rem;"><?= htmlspecialchars($eventTitle) ?></h1>
            <p class="hero-description" style="min-height: 80px;">
                Celebrate creativity, culture, and scholarship. Witness the grand stage<br>
                <span id="typewriter-text" style="color: var(--brand-blue); font-weight: 700; display: inline-block;"></span>
            </p>
            
            <!-- Countdown module (Glassmorphic) -->
            <div class="countdown-container glass-timer rounded-3xl p-6 shadow-2xl border border-white/40 flex justify-center gap-4 max-w-lg mx-auto mb-8" id="countdown" data-target-date="<?= htmlspecialchars($eventStart) ?>">
                <div class="countdown-box flex flex-col items-center">
                    <div class="countdown-value text-3xl md:text-4xl font-extrabold text-brandGreen" id="days-val">00</div>
                    <div class="countdown-label text-[10px] uppercase tracking-widest text-brandGreen/60 mt-1 font-bold">Days</div>
                </div>
                <div class="h-8 w-px bg-brandGreen/20 self-center"></div>
                <div class="countdown-box flex flex-col items-center">
                    <div class="countdown-value text-3xl md:text-4xl font-extrabold text-brandGreen" id="hours-val">00</div>
                    <div class="countdown-label text-[10px] uppercase tracking-widest text-brandGreen/60 mt-1 font-bold">Hours</div>
                </div>
                <div class="h-8 w-px bg-brandGreen/20 self-center"></div>
                <div class="countdown-box flex flex-col items-center">
                    <div class="countdown-value text-3xl md:text-4xl font-extrabold text-brandGreen" id="minutes-val">00</div>
                    <div class="countdown-label text-[10px] uppercase tracking-widest text-brandGreen/60 mt-1 font-bold">Mins</div>
                </div>
                <div class="h-8 w-px bg-brandGreen/20 self-center"></div>
                <div class="countdown-box flex flex-col items-center">
                    <div class="countdown-value text-3xl md:text-4xl font-extrabold text-brandGreen" id="seconds-val">00</div>
                    <div class="countdown-label text-[10px] uppercase tracking-widest text-brandGreen/60 mt-1 font-bold">Secs</div>
                </div>
            </div>

            <!-- CTA Actions -->
            <div class="hero-actions">
                <a href="../scoreboard.php" class="btn-primary">View Scoreboard</a>
                <button id="celebrate-btn" class="btn-secondary">Celebrate 🎉</button>
            </div>
            
            <div style="margin-top: 2rem; font-size: 0.9rem; color: var(--brand-gold); font-weight: 700; letter-spacing: 2px;">
                <i class="fa-solid fa-location-dot" style="margin-right: 6px;"></i> Edathala, Aluva, Kerala | <?= htmlspecialchars($eventDateFormatted) ?>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats" id="stats">
        <div class="section-header reveal-3d">
            <span class="section-tag">Key Metrics</span>
            <h2 class="section-title">Festival in Numbers</h2>
            <p class="section-desc">An annual internal championship of intelligence, creative writings, and artistic expressions hosted by <?= term('Al Jamiathul Kauzariyya', 'الجامعة الكوثرية') ?>.</p>
        </div>
        
        <div class="stats-grid">
            <!-- Candidates -->
            <div class="stat-card yellow reveal-3d">
                <div class="stat-number"><?= htmlspecialchars($candidatesCount) ?></div>
                <div class="stat-info">
                    <div class="stat-name">Students</div>
                    <div class="stat-desc">Talented scholars participating</div>
                </div>
                <i class="fa-solid fa-graduation-cap stat-icon"></i>
            </div>
            
            <!-- Teams -->
            <div class="stat-card green reveal-3d">
                <div class="stat-number"><?= htmlspecialchars($teamsCount) ?></div>
                <div class="stat-info">
                    <div class="stat-name">Teams</div>
                    <div class="stat-desc">Draft-filled campus squads</div>
                </div>
                <i class="fa-solid fa-people-group stat-icon"></i>
            </div>
            
            <!-- Programs -->
            <div class="stat-card blue reveal-3d">
                <div class="stat-number"><?= htmlspecialchars($programsText) ?></div>
                <div class="stat-info">
                    <div class="stat-name">Programs</div>
                    <div class="stat-desc">Stage & off-stage events</div>
                </div>
                <i class="fa-solid fa-microphone stat-icon"></i>
            </div>
            
            <!-- Divisions -->
            <div class="stat-card red reveal-3d">
                <div class="stat-number"><?= htmlspecialchars($divisionsCount) ?></div>
                <div class="stat-info">
                    <div class="stat-name">Divisions</div>
                    <div class="stat-desc">Subjunior, Junior & Senior</div>
                </div>
                <i class="fa-solid fa-layer-group stat-icon"></i>
            </div>
        </div>
    </section>

    <!-- Articles Section -->
    <section class="articles-section" id="about">
        <div class="section-header reveal-3d">
            <span class="section-tag">Charter & Regulations</span>
            <h2 class="section-title">Championship Articles</h2>
            <p class="section-desc">Explore the formal rules, division mappings, and draft selection processes of the Thanafus Musabaqa.</p>
        </div>

        <div class="articles-grid">
            <div class="article-card reveal-3d">
                <span class="article-num">Article I</span>
                <h3 class="article-title">Origin & Scope</h3>
                <p class="article-body">The festival is officially designated as the <?= term('Thanafus', 'التنافس') ?> Annual Arts Festival, hosted exclusively by <?= term('Al Jamiathul Kauzariyya', 'الجامعة الكوثرية') ?> as an internal league for all active students.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article II</span>
                <h3 class="article-title">Annual Frequency</h3>
                <p class="article-body">The <?= term('Musabaqa', 'المسابقة') ?> is conducted strictly every single academic year. It serves as the primary co-curricular cornerstone for evaluating student talent and spiritual leadership.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article III</span>
                <h3 class="article-title">Subjunior Division</h3>
                <p class="article-body">The Hifz section of the campus is mapped as the <?= term('Subjunior', 'تحت الناشئين') ?> division. Competition categories for this division emphasize Qur'an memorization (<?= term('Hifz', 'الحفظ') ?>) and basic recitation skills.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article IV</span>
                <h3 class="article-title">Junior Division</h3>
                <p class="article-body">The Sanaviyya subsection of the Alumni department is mapped as the <?= term('Junior', 'الناشئون') ?> division, testing candidates on intermediate multi-language public speaking, essay writing, and Islamic quizzes.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article V</span>
                <h3 class="article-title">Senior Division</h3>
                <p class="article-body">The Aliya subsection of the Alumni department is mapped as the <?= term('Senior', 'الكبار') ?> division, focusing on advanced jurisprudence (<?= term('Fiqh', 'الفقه') ?>) debates, Tafseer research, and complex literature.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article VI</span>
                <h3 class="article-title">The Four Teams</h3>
                <p class="article-body">The festival is contested between exactly four campus squads. The representative team names, banners, slogans, and official team colors are decided entirely by each respective team leader.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article VII</span>
                <h3 class="article-title">The Draft Selection</h3>
                <p class="article-body">First, four leaders are selected from the last year class. They then take turns selecting team members one by one (draft-style) until no student is left, ensuring equal distribution of talent.</p>
            </div>

            <div class="article-card reveal-3d">
                <span class="article-num">Article VIII</span>
                <h3 class="article-title">Code of Conduct</h3>
                <p class="article-body">Any student showing plagiarism in literary items or using internet devices during closed-room contests will face immediate disqualification from all event rosters.</p>
            </div>
        </div>
    </section>

    <!-- Category Explorer Section -->
    <section class="categories-section" id="categories">
        <div class="section-header reveal-3d">
            <span class="section-tag">Contests</span>
            <h2 class="section-title">Competition Categories</h2>
            <p class="section-desc">Scholars across Subjunior, Junior, and Senior divisions compete in five core subject fields.</p>
        </div>

        <div class="categories-grid">
            <div class="category-card reveal-3d">
                <div class="category-icon-wrapper"><i class="fa-solid fa-book-quran"></i></div>
                <h3 class="category-card-title">Qur'anic Corner</h3>
                <p class="category-card-desc">Evaluations in Memorization (<?= term('Hifz', 'الحفظ') ?> accuracy), Qira'at (vocal maqamat, <?= term('Tajweed', 'التجويد') ?> guidelines), and Tafseer (structural analysis of selected verses).</p>
            </div>

            <div class="category-card reveal-3d">
                <div class="category-icon-wrapper"><i class="fa-solid fa-comments"></i></div>
                <h3 class="category-card-title">Islamic Oratory</h3>
                <p class="category-card-desc">Linguistic presentations testing vocabulary, speed, and posture. Speeches are conducted in Arabic (<?= term('Fus\'ha', 'الفصحى') ?>), English, Urdu, and Malayalam divisions.</p>
            </div>

            <div class="category-card reveal-3d">
                <div class="category-icon-wrapper"><i class="fa-solid fa-pen-nib"></i></div>
                <h3 class="category-card-title">Literary & Writing</h3>
                <p class="category-card-desc">Quiet writing assessments focusing on original poetry compositions, detailed jurisprudence (<?= term('Fiqh', 'الفقه') ?>) research essays, and narrative moral storytelling.</p>
            </div>

            <div class="category-card reveal-3d">
                <div class="category-icon-wrapper"><i class="fa-solid fa-brain"></i></div>
                <h3 class="category-card-title">Knowledge & Intellect</h3>
                <p class="category-card-desc">Intellectual face-offs comprising the Grand Islamic Quiz, legal debates covering classical Hanafi/Shafi fiqh, and Hadith chain checks.</p>
            </div>

            <div class="category-card reveal-3d">
                <div class="category-icon-wrapper"><i class="fa-solid fa-palette"></i></div>
                <h3 class="category-card-title">Design & Media</h3>
                <p class="category-card-desc">Visual communication tasks, including calligraphy scripts (<?= term('Thuluth', 'الثلث') ?>, <?= term('Naskh', 'النسخ') ?>), geometric vector illustration, and team wall magazine edits.</p>
            </div>
        </div>
    </section>

    <!-- Scoring Matrix Section -->
    <section class="scoring-section" id="scoring">
        <div class="section-header reveal-3d">
            <span class="section-tag">Regulations</span>
            <h2 class="section-title">Points Matrix & Scoring</h2>
            <p class="section-desc">Points allocation determines individual achievements and the final team standings. Scoring is split based on individual or group participation.</p>
        </div>

        <div class="scoring-table-container reveal-3d">
            <table class="scoring-table">
                <thead>
                    <tr>
                        <th>Competition Level</th>
                        <th>1st Place</th>
                        <th>2nd Place</th>
                        <th>3rd Place</th>
                        <th>A Grade (Non-Placing)</th>
                        <th>B Grade</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Individual Contests</strong></td>
                        <td><span class="badge yellow">10 Pts</span></td>
                        <td><span class="badge green">7 Pts</span></td>
                        <td><span class="badge blue">5 Pts</span></td>
                        <td><span class="badge grey">3 Pts</span></td>
                        <td><span class="badge dim">1 Pt</span></td>
                    </tr>
                    <tr>
                        <td><strong>Group Contests</strong></td>
                        <td><span class="badge yellow">15 Pts</span></td>
                        <td><span class="badge green">10 Pts</span></td>
                        <td><span class="badge blue">7 Pts</span></td>
                        <td><span class="badge grey">—</span></td>
                        <td><span class="badge dim">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="scoring-rules reveal-3d">
            <div class="rule-box">
                <h4><i class="fa-solid fa-award"></i> Leaderboard Standings</h4>
                <p>Team scores are automatically aggregated based on grade submissions. In case of a tie for the championship, the team with the higher number of First Place finishes is crowned the winner.</p>
            </div>
            <div class="rule-box">
                <h4><i class="fa-solid fa-crown"></i> Kalathilakam Trophy</h4>
                <p>The prestigious individual champion trophy is awarded to the student who secures the highest overall score in individual events, encouraging dedicated preparation and excellence.</p>
            </div>
        </div>
    </section>

    <!-- Scholars Section -->
    <section class="scholars" id="scholars">
        <div class="section-header reveal-3d">
            <span class="section-tag">Faculty Panel</span>
            <h2 class="section-title">Our Scholar Team</h2>
            <p class="section-desc">Meet the eminent Muftis and teachers of Darul Ifta Kauzariyya who guide the academic and spiritual growth of our students.</p>
        </div>
        
        <div class="scholars-grid">
            <?php foreach ($scholars as $scholar): ?>
                <div class="scholar-card reveal-3d">
                    <div class="scholar-image-container">
                        <img src="<?= htmlspecialchars($scholar['image']) ?>" alt="<?= htmlspecialchars($scholar['name']) ?>" class="scholar-img" loading="lazy">
                    </div>
                    
                    <div class="scholar-verified">
                        <i class="bi bi-patch-check-fill text-brandGreen"></i>
                    </div>
                    
                    <div class="scholar-info">
                        <h4 class="scholar-name"><?= htmlspecialchars($scholar['name']) ?></h4>
                        <div class="scholar-role"><?= htmlspecialchars($scholar['role']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Stages Section -->
    <section class="stages-section" id="stages">
        <div class="section-header reveal-3d">
            <span class="section-tag">Venues</span>
            <h2 class="section-title">Stages & Infrastructure</h2>
            <p class="section-desc">Thanafus operates concurrently across four active stages designed to support different event categories with appropriate acoustics and equipment.</p>
        </div>

        <div class="stages-grid">
            <div class="stage-card reveal-3d">
                <div class="stage-tag-badge">Stage A</div>
                <h3 class="stage-title"><?= term('Baghdad', 'بغداد') ?></h3>
                <div class="stage-subtitle">Central Courtyard Stage</div>
                <div class="stage-divider-line"></div>
                <p class="stage-text">The flagship outdoor venue handling large-scale public speaking, inaugurals, group recitals, and closing assemblies. Equipped with surround arrays.</p>
            </div>

            <div class="stage-card reveal-3d">
                <div class="stage-tag-badge">Stage B</div>
                <h3 class="stage-title"><?= term('Cordoba', 'قرطبة') ?></h3>
                <div class="stage-subtitle">Main Seminar Hall</div>
                <div class="stage-divider-line"></div>
                <p class="stage-text">The intellectual core venue accommodating translation contests, quiz championships, Fiqh debates, and digital presentations. Fully air-conditioned.</p>
            </div>

            <div class="stage-card reveal-3d">
                <div class="stage-tag-badge">Stage C</div>
                <h3 class="stage-title"><?= term('Medina', 'المدينة') ?></h3>
                <div class="stage-subtitle">Mosque Lobby Hall</div>
                <div class="stage-divider-line"></div>
                <p class="stage-text">Structured specifically to provide deep acoustic resonance for Qur'an recitations, Hifzh evaluations, Azan contests, and spiritual lectures.</p>
            </div>

            <div class="stage-card reveal-3d">
                <div class="stage-tag-badge">Stage D</div>
                <h3 class="stage-title"><?= term('Damascus', 'دمشق') ?></h3>
                <div class="stage-subtitle">Library Annex Rooms</div>
                <div class="stage-divider-line"></div>
                <p class="stage-text">A quiet, focused environment reserved for calligraphy assessments, original poetry compositions, long-form essays, and wall magazine layouts.</p>
            </div>
        </div>
    </section>

    <!-- Guidelines & Disciplinary Code Section -->
    <section class="guidelines-section">
        <div class="section-header reveal-3d">
            <span class="section-tag">Policies</span>
            <h2 class="section-title">Codes & Guidelines</h2>
            <p class="section-desc">Contestants must adhere to the high moral and academic standards of Al Jamiathul Kauzariyya throughout the festival.</p>
        </div>

        <div class="guidelines-grid">
            <div class="guideline-card reveal-3d">
                <h3><i class="fa-solid fa-circle-exclamation" style="color:var(--color-red); margin-right:8px;"></i> Code of Conduct</h3>
                <p>Contestants must showcase exemplary character. Direct disqualification is enforced for plagiarized writings, electronic device usage during closed-book contests, or showing disrespect towards judges and stage controllers. Late arrival past a 10-minute window will result in forfeiting the slot.</p>
            </div>
            <div class="guideline-card reveal-3d">
                <h3><i class="fa-solid fa-gavel" style="color:var(--brand-gold); margin-right:8px;"></i> <?= term('Appeals Committee', 'لجنة الاستئناف') ?></h3>
                <p>Any objection or appeal regarding evaluation and grading must be submitted in writing to the Appeals Committee within 1 hour of the results announcement. The objection must outline clear rationale. The Appeals Committee's decision is final and binding on all four teams.</p>
            </div>
        </div>
    </section>

    <!-- Map & Location Section -->
    <section class="map-section" id="location">
        <div class="section-header reveal-3d">
            <span class="section-tag">Venue Map</span>
            <h2 class="section-title">Find Us Here</h2>
            <p class="section-desc">Join us live at the campus. Get directions, route details, and coordinates for the event.</p>
        </div>

        <div class="map-card reveal-3d">
            <div class="map-iframe-container">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3928.322964953935!2d76.36854127599026!3d10.07222387178385!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b080c102df0d9f9%3A0xe4a19280cdb7c7b8!2sAl%20Jamiathul%20Kauzariyya!5e0!3m2!1sen!2sin!4v1721626000000!5m2!1sen!2sin" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>

            <div class="map-info">
                <h3 class="map-info-title">Al Jamiathul Kauzariyya</h3>
                <p class="map-info-desc">Located in the scenic landscapes of Edathala, Aluva, Kerala, the institution serves as the focal point of the annual internal Musabaqa. Easy access via roads and public transit.</p>
                
                <ul class="map-details-list">
                    <li class="map-details-item">
                        <div class="map-details-icon map-visual-pin"><i class="fa-solid fa-map-pin"></i></div>
                        <span>Edathala North, Aluva, Ernakulam, Kerala 683564</span>
                    </li>
                    <li class="map-details-item">
                        <div class="map-details-icon"><i class="fa-solid fa-phone"></i></div>
                        <span>+91 9495 666 777</span>
                    </li>
                    <li class="map-details-item">
                        <div class="map-details-icon"><i class="fa-solid fa-envelope"></i></div>
                        <span>kauzariyya@gmail.com</span>
                    </li>
                </ul>

                <a href="https://www.google.com/maps/search/?api=1&query=Al+Jamiathul+Kauzariyya+Edathala+Aluva" target="_blank" class="btn-directions">
                    Get Directions <i class="fa-solid fa-diamond-turn-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- Footer Section -->
    <footer>
        <div class="footer-container">
            <div class="footer-top">
                <div class="footer-brand">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem;">
                        <img src="<?= asset_url('kauzariyya-brand-icon.png') ?>" alt="Kauzariyya" style="height: 38px;">
                        <h3 style="font-family:'Outfit', sans-serif; font-weight:900; font-size:1.6rem; letter-spacing:-1px; color:var(--brand-blue); margin: 0;">
                            THANAFUS<span style="color:var(--brand-gold);">.</span>
                        </h3>
                    </div>
                    <p class="footer-text">
                        Thanafus is the signature Annual Arts Festival of Al Jamiathul Kauzariyya, blending traditional values with modern expressions of literary and stage skills.
                    </p>
                    <div class="footer-socials">
                        <a href="https://www.youtube.com/@Kauzariyya" class="social-btn" target="_blank"><i class="fa-brands fa-youtube"></i></a>
                        <a href="https://www.instagram.com/kauzariyya" class="social-btn" target="_blank"><i class="fa-brands fa-instagram"></i></a>
                        <a href="https://x.com/kauzariyya" class="social-btn" target="_blank"><i class="fa-brands fa-twitter"></i></a>
                        <a href="https://www.facebook.com/" class="social-btn" target="_blank"><i class="fa-brands fa-facebook-f"></i></a>
                    </div>
                </div>
                
                <div class="footer-links-group">
                    <div class="footer-links-col">
                        <h6>Quick Links</h6>
                        <ul class="footer-links">
                            <li><a href="../scoreboard.php">Live Scoreboard</a></li>
                            <li><a href="../schedule.php">Program Schedule</a></li>
                            <li><a href="../admin.php">Management Portal</a></li>
                        </ul>
                    </div>
                    <div class="footer-links-col">
                        <h6>Contact Info</h6>
                        <ul class="footer-links" style="pointer-events: none;">
                            <li style="color:var(--text-secondary);"><i class="fa-solid fa-location-dot" style="margin-right:8px; color:var(--brand-blue);"></i> Edathala, Aluva, Kerala</li>
                            <li style="color:var(--text-secondary);"><i class="fa-solid fa-envelope" style="margin-right:8px; color:var(--brand-blue);"></i> kauzariyya@gmail.com</li>
                            <li style="color:var(--text-secondary);"><i class="fa-solid fa-phone" style="margin-right:8px; color:var(--brand-blue);"></i> +91 9495 666 777</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2026 Kauzariyya Musabaqa. All rights reserved.</p>
                <div class="footer-developer">
                    App created & developed by <span>Haris I M</span>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
