<?php
$pageTitle = 'Live Display Control';

define('EVENT_AUTHORITY_SCOPE', 'control-live-display');
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Load TV functions to fetch live statistics, settings, and winners
require_once __DIR__ . '/../../live-display/includes/functions.php';

// Copy default slides if missing for active event
try {
    $existingKeys = $pdo->prepare("SELECT DISTINCT slide_key FROM musabaqa_live_display_components WHERE event_id = ?");
    $existingKeys->execute([$activeEventId]);
    $activeKeys = $existingKeys->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musabaqa_live_display_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NULL,
            slide_key VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            duration INT NOT NULL DEFAULT 5000,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            style VARCHAR(50) NOT NULL DEFAULT 'classic',
            CONSTRAINT uniq_event_slide UNIQUE (event_id, slide_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $activeKeys = [];
}


$defaultSlides = [
    'intro'           => ['Welcome Intro',              10000, 1],
    'leaderboard'     => ['Team Leaderboard',             5000, 2],
    'schedule'        => ['Upcoming Programs',            5000, 3],
    'current-program' => ['Main Stage (Now Performing)', 5000, 4]
];

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO musabaqa_live_display_components (event_id, slide_key, title, duration, is_enabled, sort_order)
    VALUES (?, ?, ?, ?, 1, ?)
");
foreach ($defaultSlides as $key => $slide) {
    if (!in_array($key, $activeKeys, true)) {
        $insertStmt->execute([$activeEventId, $key, $slide[0], $slide[1], $slide[2]]);
    }
}

// Normalize existing rows: if a non-intro slide still has an old high default (>= 16000ms),
// reset it to the new default of 5000ms so the UI shows sensible values on first load.
try {
    $pdo->prepare("
        UPDATE musabaqa_live_display_components
        SET duration = 5000
        WHERE event_id = ?
          AND slide_key IN ('leaderboard', 'schedule', 'current-program')
          AND duration >= 16000
    ")->execute([$activeEventId]);
} catch (Throwable $e) { /* non-fatal */ }

// POST Save Slide components
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_slides') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/live-display/control-live-display.php');
    }

    $slides = $_POST['slides'] ?? [];
    try {
        // Handle Video Upload
        if (isset($_FILES['intro_video']) && $_FILES['intro_video']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['intro_video']['tmp_name'];
            $fileName = $_FILES['intro_video']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($fileExtension !== 'mp4') {
                throw new Exception('Only MP4 video files (.mp4) are allowed.');
            }
            $uploadFileDir = app_path('assets/videos/');
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            $dest_path = $uploadFileDir . 'Intro.mp4';
            if (!move_uploaded_file($fileTmpPath, $dest_path)) {
                throw new Exception('Failed to save uploaded video file.');
            }
        }

        admin_db_transaction($pdo, function ($pdo) use ($slides, $activeEventId) {
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
                    UPDATE musabaqa_live_display_components 
                    SET title = ?, duration = ?, is_enabled = ?, sort_order = ?, style = ?
                    WHERE event_id = ? AND slide_key = ?
                ");
                $stmt->execute([$title, $duration, $isEnabled, $sortOrder, $style, $activeEventId, $key]);
            }
        });
        admin_flash('success', 'TV slides configuration saved.');
    } catch (Throwable $e) {
        admin_flash('error', 'Failed to save settings: ' . $e->getMessage());
    }
    admin_redirect('/admin/live-display/control-live-display.php');
}

// Load configurations
$stmt = $pdo->prepare("SELECT * FROM musabaqa_live_display_components WHERE event_id = ? ORDER BY sort_order ASC");
$stmt->execute([$activeEventId]);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tvSettings = tv_get_settings($activeEventId);
$stats = tv_stats($activeEventId);
$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">


<main class="main-content event-workspace-content">
    <section class="workspace-hero">
        <div>
            <span class="eyebrow"><i class="fa-solid fa-display"></i> Scoreboard Controller</span>
            <h1>Live Display Control</h1>
            <p>Orchestrate slides, ticker announcements, alert overlays, and trophy reveals for the big screen.</p>
        </div>
        <div class="hero-actions">
            <a href="<?= app_url('/live-display/index.php') ?>" target="_blank" class="btn btn-primary btn-md" data-ajax-ignore>
                <i class="fa-solid fa-square-rss"></i> Open Live Display
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
                <form method="POST" enctype="multipart/form-data">
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
                                            <?php if ($c['slide_key'] === 'intro'): ?>
                                                <div style="margin-top: 8px; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 6px;">
                                                    <label style="font-size: 11px; font-weight: 700; color: #10b981; display: block; margin-bottom: 3px;">
                                                        <i class="fa-solid fa-cloud-arrow-up"></i> Upload Intro Video (.mp4)
                                                    </label>
                                                    <input type="file" 
                                                           name="intro_video" 
                                                           id="intro-video-upload" 
                                                           accept="video/mp4" 
                                                           style="font-size: 11px; width: 100%; max-width: 200px;">
                                                    <div style="font-size: 9px; color: var(--text-muted); margin-top: 2px;">
                                                        Auto-detects and locks duration on selection.
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($c['slide_key'] === 'intro'): ?>
                                                <input type="number" 
                                                        id="intro-duration-input"
                                                        name="slides[<?= e($c['slide_key']) ?>][duration]" 
                                                        value="<?= (int)($c['duration'] / 1000) ?>" 
                                                        min="1" 
                                                        max="300"
                                                        class="form-control" 
                                                        style="width: 70px; padding: 4px; background: rgba(255,255,255,0.05); color: #888;"
                                                        title="Intro duration is locked to the video file length. Select a new video to update."
                                                        readonly
                                                        required>
                                            <?php else: ?>
                                                <input type="number" 
                                                        name="slides[<?= e($c['slide_key']) ?>][duration]" 
                                                        value="<?= (int)($c['duration'] / 1000) ?>" 
                                                        min="1" 
                                                        max="300"
                                                        class="form-control" 
                                                        style="width: 70px; padding: 4px;"
                                                        title="Duration in seconds. Min: 1s."
                                                        required>
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
                                            <input type="hidden" name="slides[<?= e($c['slide_key']) ?>][style]" value="<?= e($c['style'] ?? 'classic') ?>">
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; gap: 4px; justify-content: center;">
                                                <button type="button" 
                                                        class="btn btn-secondary btn-xs btn-preview-slide" 
                                                        data-url="<?= app_url('/live-display/') ?><?= e($c['slide_key']) ?>.php"
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
                        <i class="fa-solid fa-display text-primary"></i> Screen Loop Preview
                    </h3>
                    <div style="display: inline-flex; align-items: center; gap: 8px;">
                        <span class="badge badge-success" style="font-size: 11px; padding: 2px 8px; border-radius: 8px; display: flex; align-items: center; gap: 4px;">
                            <i class="fa-solid fa-circle" style="font-size: 7px; color: #fff; animation: pulse 1.5s infinite;"></i> LIVE
                        </span>
                    </div>
                </div>

                <div class="tv-bezel-outer">
                    <div class="tv-bezel-screen">
                        <iframe id="tvFrame" src="<?= app_url('/live-display/index.php') ?>" frameborder="0"></iframe>
                    </div>
                </div>
                <div class="tv-base-stand"></div>
                <div class="tv-base-plate"></div>

                <div class="flex justify-center gap-2 mt-4" style="display: flex; justify-content: center; gap: 8px;">
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('tvFrame').src = '<?= app_url('/live-display/index.php') ?>';">
                        <i class="fa-solid fa-arrows-spin"></i> Reset Loop
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('tvFrame').src = document.getElementById('tvFrame').src;">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                </div>



            </div>
        </div>
    </div>
</main>

<script>
(() => {
    const API_URL = <?= json_encode(app_url('/live-display/api/settings.php'), JSON_UNESCAPED_SLASHES) ?>;
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
                if (window.showToast) window.showToast(res.message || 'Action failed.', 'error');
                else alert(res.message || 'Action failed.');
            }
            return res;
        } catch (e) {
            console.error(e);
            if (window.showToast) window.showToast('A network error occurred.', 'error');
            else alert('A network error occurred.');
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
                    const base = (window.APP_CONFIG?.baseUrl || '').replace(/\/$/, '');
                    if (iframe) iframe.src = `${base}/live-display/${key}.php`;
            }
        });
    });



    // Video Duration Detection on selection
    const introVideoUpload = document.getElementById('intro-video-upload');
    const introDurationInput = document.getElementById('intro-duration-input');
    
    if (introVideoUpload && introDurationInput) {
        introVideoUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.src = URL.createObjectURL(file);
            
            video.onloadedmetadata = function() {
                URL.revokeObjectURL(video.src);
                const duration = Math.round(video.duration);
                if (duration > 0) {
                    introDurationInput.value = duration;
                    if (window.showToast) {
                        window.showToast('Detected video duration: ' + duration + 's. Locked duration updated.', 'info');
                    }
                }
            };
        });
    }

})();
</script>

<?php admin_close_page(); ?>

