<?php
$pageTitle = 'TV Display Control';

define('EVENT_AUTHORITY_SCOPE', 'control-tv');
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Load TV functions to fetch live statistics, settings, and winners
require_once __DIR__ . '/../../tv/includes/functions.php';

// Initialize DB schema if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS musabaqa_tv_components (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NULL,
        slide_key VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        duration INT NOT NULL DEFAULT 15000,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        style VARCHAR(50) NOT NULL DEFAULT 'classic',
        CONSTRAINT uniq_event_slide UNIQUE (event_id, slide_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Copy default slides if missing for active event
$existingKeys = $pdo->prepare("SELECT DISTINCT slide_key FROM musabaqa_tv_components WHERE event_id = ?");
$existingKeys->execute([$activeEventId]);
$activeKeys = $existingKeys->fetchAll(PDO::FETCH_COLUMN);

$defaultSlides = [
    'intro' => ['Welcome Intro', 12000, 1],
    'leaderboard' => ['Team Leaderboard', 16000, 2],
    'schedule' => ['Upcoming Programs', 18000, 3],
    'current-program' => ['Main Stage (Now Performing)', 18000, 4]
];

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO musabaqa_tv_components (event_id, slide_key, title, duration, is_enabled, sort_order)
    VALUES (?, ?, ?, ?, 1, ?)
");
foreach ($defaultSlides as $key => $slide) {
    if (!in_array($key, $activeKeys, true)) {
        $insertStmt->execute([$activeEventId, $key, $slide[0], $slide[1], $slide[2]]);
    }
}

// POST Save Slide components
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_slides') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/event/control-tv.php');
    }

    $slides = $_POST['slides'] ?? [];
    try {
        $pdo->beginTransaction();
        foreach ($slides as $key => $slideData) {
            $title = trim((string)($slideData['title'] ?? ''));
            if ($title === '') {
                $title = ucfirst($key);
            }
            $duration = max(1, (int)($slideData['duration'] ?? 10)) * 1000;
            $isEnabled = isset($slideData['is_enabled']) ? 1 : 0;
            $sortOrder = (int)($slideData['sort_order'] ?? 0);
            
            $style = trim((string)($slideData['style'] ?? 'classic'));
            if (!in_array($style, ['classic', 'orbit', 'podium', 'staggered', 'style2'], true)) {
                $style = 'classic';
            }

            $stmt = $pdo->prepare("
                UPDATE musabaqa_tv_components 
                SET title = ?, duration = ?, is_enabled = ?, sort_order = ?, style = ?
                WHERE event_id = ? AND slide_key = ?
            ");
            $stmt->execute([$title, $duration, $isEnabled, $sortOrder, $style, $activeEventId, $key]);
        }
        $pdo->commit();
        admin_flash('success', 'TV slides configuration saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', 'Failed to save settings: ' . $e->getMessage());
    }
    admin_redirect('/admin/event/control-tv.php');
}

// Load configurations
$stmt = $pdo->prepare("SELECT * FROM musabaqa_tv_components WHERE event_id = ? ORDER BY sort_order ASC");
$stmt->execute([$activeEventId]);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tvSettings = tv_get_settings($activeEventId);
$stats = tv_stats($activeEventId);
$winners = tv_dashboard_winner_options($activeEventId);
$flash = admin_take_flash();

$useTopNavigation = true;
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/event-role-sidebar.php';
?>
<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">

<style>
/* TV Control Double Column Layout */
.tv-control-workspace {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 1024px) {
    .tv-control-workspace {
        grid-template-columns: 1fr;
    }
}
.sticky-preview-bar {
    position: sticky;
    top: 96px;
}
.tv-bezel-outer {
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #1e1e24;
    border: 10px solid #2d2d35;
    border-radius: 12px;
    box-shadow: 0 16px 36px rgba(0,0,0,0.45);
    position: relative;
    box-sizing: border-box;
    overflow: hidden;
}
.tv-bezel-screen {
    width: 100%;
    height: 100%;
    background: #000;
    overflow: hidden;
    position: relative;
}
.tv-bezel-screen iframe {
    width: 1920px;
    height: 1080px;
    border: none;
    transform-origin: top left;
    position: absolute;
    top: 0;
    left: 0;
}
.tv-base-stand {
    width: 50px;
    height: 15px;
    background: #23232a;
    margin: 0 auto;
}
.tv-base-plate {
    width: 130px;
    height: 6px;
    background: #2d2d35;
    border-radius: 3px;
    margin: 0 auto;
    box-shadow: 0 4px 6px rgba(0,0,0,0.2);
}
.quick-control-card {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}
.slide-status-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
}
.slide-status-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted-2);
    text-transform: uppercase;
}
.slide-status-label.is-on {
    color: #2dd4bf;
}
.workspace-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-top: 16px;
}
@media (max-width: 480px) {
    .workspace-stats-grid {
        grid-template-columns: 1fr;
    }
}
.ws-stat-card {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
    padding: 16px;
    border-radius: 14px;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.ws-stat-val {
    font-size: 24px;
    font-weight: 900;
    color: #fff;
    line-height: 1;
    margin-bottom: 4px;
}
.ws-stat-lbl {
    font-size: 12px;
    color: var(--muted-2);
    font-weight: 600;
}
.ws-stat-icon {
    position: absolute;
    right: 16px;
    bottom: 16px;
    font-size: 24px;
    color: rgba(45, 212, 191, 0.15);
}
</style>

<main class="main-content event-workspace-content">
    <section class="workspace-hero">
        <div>
            <span class="eyebrow"><i class="fa-solid fa-tv"></i> Scoreboard Controller</span>
            <h1>TV Display Control</h1>
            <p>Orchestrate slides, ticker announcements, alert overlays, and trophy reveals for the big screen.</p>
        </div>
        <div class="hero-actions">
            <a href="<?= app_url('/tv/index.php') ?>" target="_blank" class="btn btn-primary btn-md" data-ajax-ignore>
                <i class="fa-solid fa-square-rss"></i> Open TV Screen
            </a>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="tv-control-workspace">
        <!-- LEFT COLUMN: SETTINGS & CONTROLS -->
        <div class="workspace-control-left" style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Live Controller state -->
            <div class="panel">
                <div class="page-subtitle mb-4">Playback Controller</div>
                <div class="quick-control-card">
                    <div style="display: flex; gap: 8px;">
                        <button class="btn <?= !empty($tvSettings['is_playing']) ? 'btn-success' : 'btn-secondary' ?> btn-md" type="button" id="btnPlay">
                            <i class="fa-solid fa-play"></i> Play Loop
                        </button>
                        <button class="btn <?= empty($tvSettings['is_playing']) ? 'btn-danger' : 'btn-secondary' ?> btn-md" type="button" id="btnPause">
                            <i class="fa-solid fa-pause"></i> Pause Loop
                        </button>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-left: auto;">
                        <span class="text-sm">Loop Mode:</span>
                        <div style="display: flex; gap: 4px;">
                            <button class="btn <?= ($tvSettings['mode'] ?? 'auto') === 'auto' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" id="btnModeAuto">Auto</button>
                            <button class="btn <?= ($tvSettings['mode'] ?? 'auto') === 'manual' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" id="btnModeManual">Manual</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slides Configuration Form -->
            <div class="panel">
                <div class="page-subtitle mb-4">Slide rotation sequence</div>
                <form method="POST">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="save_slides">

                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 80px;">Sort</th>
                                    <th>Slide Component</th>
                                    <th>Title</th>
                                    <th style="width: 100px;">Duration (sec)</th>
                                    <th style="width: 120px;">Layout Style</th>
                                    <th style="text-align: center; width: 100px;">On Air</th>
                                    <th style="text-align: center; width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($components as $c): ?>
                                    <tr>
                                        <td>
                                            <input type="number" 
                                                   name="slides[<?= e($c['slide_key']) ?>][sort_order]" 
                                                   value="<?= (int)$c['sort_order'] ?>" 
                                                   class="form-control" 
                                                   style="width: 60px; padding: 4px;"
                                                   required>
                                        </td>
                                        <td>
                                            <strong><?= e(ucfirst($c['slide_key'])) ?></strong>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="slides[<?= e($c['slide_key']) ?>][title]" 
                                                   value="<?= e($c['title']) ?>" 
                                                   class="form-control" 
                                                   required>
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="slides[<?= e($c['slide_key']) ?>][duration]" 
                                                   value="<?= (int)($c['duration'] / 1000) ?>" 
                                                   min="3" 
                                                   class="form-control" 
                                                   style="width: 70px; padding: 4px;"
                                                   required>
                                        </td>
                                        <td>
                                            <?php if ($c['slide_key'] === 'leaderboard'): ?>
                                                <select name="slides[<?= e($c['slide_key']) ?>][style]" class="form-control" style="padding: 4px; font-size: 13px;">
                                                    <option value="classic" <?= ($c['style'] ?? 'classic') === 'classic' ? 'selected' : '' ?>>Classic Bars</option>
                                                    <option value="orbit" <?= ($c['style'] ?? 'classic') === 'orbit' ? 'selected' : '' ?>>Radial Orbit</option>
                                                    <option value="podium" <?= ($c['style'] ?? 'classic') === 'podium' ? 'selected' : '' ?>>3D Podium</option>
                                                    <option value="style2" <?= ($c['style'] ?? 'classic') === 'style2' ? 'selected' : '' ?>>Diamond 3D</option>
                                                    <option value="staggered" <?= ($c['style'] ?? 'classic') === 'staggered' ? 'selected' : '' ?>>Staggered</option>
                                                </select>
                                            <?php else: ?>
                                                <input type="hidden" name="slides[<?= e($c['slide_key']) ?>][style]" value="classic">
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="slide-status-wrap">
                                                <label class="toggle-switch">
                                                    <input type="checkbox"
                                                           class="slide-toggle-checkbox"
                                                           name="slides[<?= e($c['slide_key']) ?>][is_enabled]"
                                                           value="1"
                                                           data-slide-name="<?= e($c['title']) ?>"
                                                           <?= $c['is_enabled'] ? 'checked' : '' ?>>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                <span class="slide-status-label <?= $c['is_enabled'] ? 'is-on' : '' ?>">
                                                    <?= $c['is_enabled'] ? 'On' : 'Off' ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; gap: 4px; justify-content: center;">
                                                <button type="button" 
                                                        class="btn btn-secondary btn-xs btn-preview-slide" 
                                                        data-url="<?= app_url('/tv/') ?><?= e($c['slide_key']) ?>.php"
                                                        title="Show in frame preview">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-primary btn-xs btn-manual-trigger"
                                                        data-key="<?= e($c['slide_key']) ?>"
                                                        title="Force display this slide immediately">
                                                    <i class="fa-solid fa-bullseye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end mt-4">
                        <button type="submit" class="btn btn-success btn-md">
                            <i class="fa-solid fa-floppy-disk"></i> Save rotation
                        </button>
                    </div>
                </form>
            </div>

            <!-- Emergency alert panels -->
            <div class="panel">
                <div class="page-subtitle mb-4">Emergency Alert Ticker</div>
                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Alert Message</label>
                        <input type="text" class="form-control" id="emergencyMessage" value="<?= e($tvSettings['emergency']['message'] ?? '') ?>" placeholder="Type high priority emergency notification message...">
                    </div>
                    <div class="form-actions full-width" style="display: flex; gap: 8px;">
                        <button class="btn btn-success btn-md" id="btnPublishEmergency">
                            <i class="fa-solid fa-circle-exclamation"></i> Publish Alert
                        </button>
                        <button class="btn btn-danger btn-md" id="btnClearEmergency">
                            <i class="fa-solid fa-trash"></i> Clear Alert
                        </button>
                        <div style="display: inline-flex; align-items: center; gap: 8px; margin-left: auto;">
                            <span class="text-sm">Alert Active:</span>
                            <span class="badge <?= !empty($tvSettings['emergency']['enabled']) ? 'badge-danger' : 'badge-neutral' ?>" id="emergencyBadge">
                                <?= !empty($tvSettings['emergency']['enabled']) ? 'ON AIR' : 'OFF' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Announcement ticker -->
            <div class="panel">
                <div class="page-subtitle mb-4">Announcement Ticker</div>
                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Announcement Text</label>
                        <input type="text" class="form-control" id="announcementMessage" value="<?= e($tvSettings['announcement']['message'] ?? '') ?>" placeholder="Type scrollable bottom announcement ticker text...">
                    </div>
                    <div class="form-actions full-width" style="display: flex; gap: 8px; align-items: center;">
                        <button class="btn btn-success btn-md" id="btnPublishAnnouncement">
                            <i class="fa-solid fa-bullhorn"></i> Save Ticker
                        </button>
                        <label class="toggle-switch" style="margin-left: 12px;">
                            <input type="checkbox" id="announcementToggle" <?= !empty($tvSettings['announcement']['enabled']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="text-sm">Ticker Enabled</span>
                    </div>
                </div>
            </div>

            <!-- Winner celebration revealer -->
            <div class="panel">
                <div class="page-subtitle mb-4">Winner Celebration Revealer</div>
                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Winner Program Candidate</label>
                        <select id="celebrationWinnerSelect">
                            <option value="">-- Choose Completed Program Winner --</option>
                            <?php foreach ($winners as $opt): ?>
                                <option value="<?= (int)$opt['program_id'] ?>" 
                                        data-title="<?= e($opt['title']) ?>"
                                        data-winner="<?= e($opt['winner']) ?>"
                                        data-team="<?= e($opt['team']) ?>"
                                        data-color="<?= e($opt['team_color']) ?>"
                                        data-score="<?= (float)$opt['score'] ?>">
                                    <?= e($opt['label']) ?> (<?= (float)$opt['score'] ?> pts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; width: 100%;" class="full-width mt-4">
                        <div class="input-group">
                            <label>Program Title</label>
                            <input type="text" class="form-control" id="celebTitle" placeholder="Custom program title">
                        </div>
                        <div class="input-group">
                            <label>Winner Name</label>
                            <input type="text" class="form-control" id="celebWinnerName" placeholder="Participant full name">
                        </div>
                        <div class="input-group">
                            <label>Team Name</label>
                            <input type="text" class="form-control" id="celebTeamName" placeholder="Winner team name">
                        </div>
                        <div class="input-group">
                            <label>Team Color</label>
                            <input type="color" class="form-control" style="height: 38px; padding: 2px;" id="celebTeamColor" value="#d6b25e">
                        </div>
                        <div class="input-group full-width" style="grid-column: span 2;">
                            <label>Total Score (pts)</label>
                            <input type="number" class="form-control" id="celebScore" step="0.01" placeholder="Marks obtained">
                        </div>
                    </div>

                    <div class="form-actions full-width mt-4">
                        <button class="btn btn-success btn-md" id="btnTriggerCelebration" style="background: linear-gradient(135deg, #d97706, #f59e0b); border: none; color: #fff;">
                            <i class="fa-solid fa-trophy"></i> Launch Winner Reveal!
                        </button>
                    </div>
                </div>
            </div>

            <!-- Statistics Grid -->
            <div class="panel">
                <div class="page-subtitle">Event Statistics</div>
                <div class="workspace-stats-grid">
                    <div class="ws-stat-card">
                        <div class="ws-stat-val"><?= number_format($stats['teams']) ?></div>
                        <div class="ws-stat-lbl">Competing Teams</div>
                        <i class="fa-solid fa-users ws-stat-icon"></i>
                    </div>
                    <div class="ws-stat-card">
                        <div class="ws-stat-val"><?= number_format($stats['programs']) ?></div>
                        <div class="ws-stat-lbl">Total Programs</div>
                        <i class="fa-solid fa-list ws-stat-icon"></i>
                    </div>
                    <div class="ws-stat-card">
                        <div class="ws-stat-val"><?= number_format($stats['completed_programs']) ?></div>
                        <div class="ws-stat-lbl">Completed Programs</div>
                        <i class="fa-solid fa-circle-check ws-stat-icon"></i>
                    </div>
                    <div class="ws-stat-card">
                        <div class="ws-stat-val"><?= number_format($stats['entries']) ?></div>
                        <div class="ws-stat-lbl">Assigned Entries</div>
                        <i class="fa-solid fa-clipboard-list ws-stat-icon"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN: PREVIEW SCREEN -->
        <div class="workspace-control-right sticky-preview-bar">
            <div class="panel">
                <div class="flex justify-between items-center mb-4" style="border-bottom: 1px solid var(--border); padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 16px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-tv text-primary"></i> Screen Loop Preview
                    </h3>
                    <div style="display: inline-flex; align-items: center; gap: 8px;">
                        <span class="badge badge-success" style="font-size: 11px; padding: 2px 8px; border-radius: 8px; display: flex; align-items: center; gap: 4px;">
                            <i class="fa-solid fa-circle" style="font-size: 7px; color: #fff; animation: pulse 1.5s infinite;"></i> LIVE
                        </span>
                    </div>
                </div>

                <div class="tv-bezel-outer">
                    <div class="tv-bezel-screen">
                        <iframe id="tvFrame" src="<?= app_url('/tv/index.php') ?>" frameborder="0"></iframe>
                    </div>
                </div>
                <div class="tv-base-stand"></div>
                <div class="tv-base-plate"></div>

                <div class="flex justify-center gap-2 mt-4" style="display: flex; justify-content: center; gap: 8px;">
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('tvFrame').src = '<?= app_url('/tv/index.php') ?>';">
                        <i class="fa-solid fa-arrows-spin"></i> Reset Loop
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('tvFrame').src = document.getElementById('tvFrame').src;">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                </div>

                <!-- Theme Selection Card -->
                <div style="border-top: 1px solid var(--border); margin-top: 20px; padding-top: 15px;">
                    <div class="input-group">
                        <label>TV Theme Color Scheme</label>
                        <select id="tvThemeSelect">
                            <option value="emerald" <?= ($tvSettings['theme'] ?? 'emerald') === 'emerald' ? 'selected' : '' ?>>Emerald Theme (Green)</option>
                            <option value="royal" <?= ($tvSettings['theme'] ?? 'emerald') === 'royal' ? 'selected' : '' ?>>Royal Theme (Blue)</option>
                            <option value="midnight" <?= ($tvSettings['theme'] ?? 'emerald') === 'midnight' ? 'selected' : '' ?>>Midnight Theme (Dark Slate)</option>
                        </select>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<script>
(() => {
    const API_URL = <?= json_encode(app_url('/tv/api/settings.php'), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF = <?= json_encode(generate_csrf_token()) ?>;

    // Helper function to update status label toggles
    document.querySelectorAll('.slide-toggle-checkbox').forEach(chk => {
        chk.addEventListener('change', function() {
            const label = this.closest('.slide-status-wrap')?.querySelector('.slide-status-label');
            if (label) {
                label.textContent = this.checked ? 'On' : 'Off';
                label.classList.toggle('is-on', this.checked);
            }
        });
    });

    // Helper function to scale iframe preview dynamically
    const screen = document.querySelector('.tv-bezel-screen');
    const iframe = document.getElementById('tvFrame');
    if (screen && iframe) {
        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const scale = entry.contentRect.width / 1920;
                iframe.style.transform = `scale(${scale})`;
            }
        });
        resizeObserver.observe(screen);
    }

    // Individual preview slide button click handler
    document.querySelectorAll('.btn-preview-slide').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = this.dataset.url;
            if (iframe) {
                iframe.src = url;
            }
        });
    });

    // AJAX Settings Poster Helper
    async function postSettings(action, data = {}) {
        const formData = new FormData();
        formData.append('csrf_token', CSRF);
        formData.append('action', action);
        for (const [key, val] of Object.entries(data)) {
            formData.append(key, val);
        }
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const res = await response.json();
            if (!res.success) {
                alert(res.message || 'Action failed.');
            }
            return res;
        } catch (e) {
            console.error(e);
            alert('A network error occurred.');
            return null;
        }
    }

    // Play/Pause Action handlers
    document.getElementById('btnPlay')?.addEventListener('click', async function() {
        const res = await postSettings('play');
        if (res && res.success) {
            this.className = 'btn btn-success btn-md';
            const pause = document.getElementById('btnPause');
            if (pause) pause.className = 'btn btn-secondary btn-md';
        }
    });
    document.getElementById('btnPause')?.addEventListener('click', async function() {
        const res = await postSettings('pause');
        if (res && res.success) {
            this.className = 'btn btn-danger btn-md';
            const play = document.getElementById('btnPlay');
            if (play) play.className = 'btn btn-secondary btn-md';
        }
    });

    // Loop Display mode auto/manual switching
    document.getElementById('btnModeAuto')?.addEventListener('click', async function() {
        const res = await postSettings('mode', { mode: 'auto' });
        if (res && res.success) {
            this.className = 'btn btn-primary btn-sm';
            const manual = document.getElementById('btnModeManual');
            if (manual) manual.className = 'btn btn-secondary btn-sm';
        }
    });
    document.getElementById('btnModeManual')?.addEventListener('click', async function() {
        const res = await postSettings('mode', { mode: 'manual' });
        if (res && res.success) {
            this.className = 'btn btn-primary btn-sm';
            const auto = document.getElementById('btnModeAuto');
            if (auto) auto.className = 'btn btn-secondary btn-sm';
        }
    });

    // Manual slide triggering
    document.querySelectorAll('.btn-manual-trigger').forEach(btn => {
        btn.addEventListener('click', async function() {
            const key = this.dataset.key;
            const res = await postSettings('slide', { slide: key });
            if (res && res.success) {
                // Change loop buttons visual mode state
                const manual = document.getElementById('btnModeManual');
                if (manual) manual.className = 'btn btn-primary btn-sm';
                const auto = document.getElementById('btnModeAuto');
                if (auto) auto.className = 'btn btn-secondary btn-sm';
                // Trigger preview iframe immediately
                if (iframe) iframe.src = `${window.APP_CONFIG.baseUrl}/tv/${key}.php`;
            }
        });
    });

    // Theme selector
    document.getElementById('tvThemeSelect')?.addEventListener('change', async function() {
        await postSettings('theme', { theme: this.value });
    });

    // Emergency Alerts controls
    document.getElementById('btnPublishEmergency')?.addEventListener('click', async () => {
        const msg = document.getElementById('emergencyMessage').value.trim();
        if (msg === '') {
            alert('Please type an emergency notification message.');
            return;
        }
        const res = await postSettings('emergency', { enabled: '1', message: msg });
        if (res && res.success) {
            const badge = document.getElementById('emergencyBadge');
            if (badge) {
                badge.className = 'badge badge-danger';
                badge.textContent = 'ON AIR';
            }
        }
    });
    document.getElementById('btnClearEmergency')?.addEventListener('click', async () => {
        const res = await postSettings('clear_emergency');
        if (res && res.success) {
            document.getElementById('emergencyMessage').value = '';
            const badge = document.getElementById('emergencyBadge');
            if (badge) {
                badge.className = 'badge badge-neutral';
                badge.textContent = 'OFF';
            }
        }
    });

    // Announcements ticker controls
    document.getElementById('btnPublishAnnouncement')?.addEventListener('click', async () => {
        const msg = document.getElementById('announcementMessage').value.trim();
        const chk = document.getElementById('announcementToggle').checked ? '1' : '0';
        const data = { message: msg };
        if (chk === '1') {
            data.enabled = '1';
        }
        await postSettings('announcement', data);
    });
    document.getElementById('announcementToggle')?.addEventListener('change', async function() {
        const msg = document.getElementById('announcementMessage').value.trim();
        const data = { message: msg };
        if (this.checked) {
            data.enabled = '1';
        }
        await postSettings('announcement', data);
    });

    // Winner celebration autofills & triggers
    document.getElementById('celebrationWinnerSelect')?.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt.value) {
            return;
        }
        document.getElementById('celebTitle').value = opt.dataset.title || '';
        document.getElementById('celebWinnerName').value = opt.dataset.winner || '';
        document.getElementById('celebTeamName').value = opt.dataset.team || '';
        document.getElementById('celebTeamColor').value = opt.dataset.color || '#d6b25e';
        document.getElementById('celebScore').value = opt.dataset.score || '';
    });

    document.getElementById('btnTriggerCelebration')?.addEventListener('click', async () => {
        const title = document.getElementById('celebTitle').value.trim();
        const winner = document.getElementById('celebWinnerName').value.trim();
        const team = document.getElementById('celebTeamName').value.trim();
        const teamColor = document.getElementById('celebTeamColor').value.trim();
        const score = document.getElementById('celebScore').value.trim();
        const select = document.getElementById('celebrationWinnerSelect');
        const programId = select.value || '';

        if (winner === '' || team === '') {
            alert('Winner Name and Team Name are required.');
            return;
        }

        const data = {
            program_id: programId,
            title: title,
            winner: winner,
            team: team,
            team_color: teamColor,
            score: score
        };

        const res = await postSettings('celebration', data);
        if (res && res.success) {
            alert('Celebration triggered on TV screen.');
        }
    });

})();
</script>

<?php admin_close_page(); ?>
