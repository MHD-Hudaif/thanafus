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

$tvSettings = $tvSettings ?? tv_get_settings($activeEventId);

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
              || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

    if ($action === 'upload_quick_screen') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
                exit;
            }
            admin_flash('error', 'Invalid security token.');
            admin_redirect('/admin/live-display/control-live-display.php');
        }

        try {
            if (isset($_FILES['quick_screen']) && $_FILES['quick_screen']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['quick_screen']['tmp_name'];
                $fileName = $_FILES['quick_screen']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExtension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    throw new Exception('Only image files (JPG, PNG, WEBP, GIF) are allowed.');
                }
                $uploadFileDir = app_path('uploads/quick-screens') . DIRECTORY_SEPARATOR;
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                $newFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $fileName);
                $dest_path = $uploadFileDir . $newFileName;
                if (!move_uploaded_file($fileTmpPath, $dest_path)) {
                    throw new Exception('Failed to save uploaded image file.');
                }
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Quick screen image uploaded successfully.',
                        'image' => $newFileName,
                        'url' => app_url('/uploads/quick-screens/' . $newFileName),
                        'display_name' => preg_replace('/^\d+_/', '', $newFileName)
                    ]);
                    exit;
                }
                admin_flash('success', 'Quick screen image uploaded successfully.');
            } else {
                throw new Exception('No image file selected or upload error.');
            }
        } catch (Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            admin_flash('error', $e->getMessage());
        }
        admin_redirect('/admin/live-display/control-live-display.php');
    }

    if ($action === 'delete_quick_screen') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
                exit;
            }
            admin_flash('error', 'Invalid security token.');
            admin_redirect('/admin/live-display/control-live-display.php');
        }

        $image = (string)($_POST['image'] ?? '');
        $imagePath = app_path('uploads/quick-screens') . DIRECTORY_SEPARATOR . basename($image);
        if ($image !== '' && file_exists($imagePath)) {
            unlink($imagePath);
            if (($tvSettings['quick_screen_image'] ?? '') === $image) {
                $tvSettings['quick_screen_enabled'] = false;
                $tvSettings['quick_screen_image'] = '';
                tv_save_settings($activeEventId, $tvSettings);
            }
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Quick screen image deleted.']);
                exit;
            }
            admin_flash('success', 'Quick screen image deleted.');
        } else {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Image not found.']);
                exit;
            }
            admin_flash('error', 'Image not found.');
        }
        admin_redirect('/admin/live-display/control-live-display.php');
    }

    if ($action === 'save_slides') {
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
}

// Load configurations
$stmt = $pdo->prepare("SELECT * FROM musabaqa_live_display_components WHERE event_id = ? ORDER BY sort_order ASC");
$stmt->execute([$activeEventId]);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tvSettings = $tvSettings ?? tv_get_settings($activeEventId);
$stats = tv_stats($activeEventId);
$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">
<style>
/* Custom TV Control Dashboard Improvements */
.panel {
    background: rgba(30, 41, 59, 0.45) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2) !important;
    border-radius: 16px !important;
    padding: 24px !important;
}

.page-subtitle {
    font-size: 15px !important;
    font-weight: 800 !important;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #e2e8f0 !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    padding-bottom: 10px;
}

.btn-md {
    padding: 10px 18px !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
    border-radius: 10px !important;
}

.form-control {
    background: rgba(15, 23, 42, 0.4) !important;
    border: 1.5px solid rgba(255, 255, 255, 0.1) !important;
    color: #f8fafc !important;
    border-radius: 8px !important;
    padding: 8px 12px !important;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important;
}
</style>


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

            <!-- Performance Profile Panel -->
            <div class="panel">
                <div class="page-subtitle mb-4">Performance Profile</div>
                <div class="quick-control-card" style="display: flex; flex-direction: column; gap: 14px; align-items: stretch;">
                    <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                        <span class="text-sm">TV Display Mode:</span>
                        <div style="display: flex; gap: 4px;">
                            <button class="btn <?= ($tvSettings['performance_mode'] ?? 'quality') === 'quality' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" id="btnPerfQuality" type="button">Quality Mode (60 FPS)</button>
                            <button class="btn <?= ($tvSettings['performance_mode'] ?? 'quality') === 'performance' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" id="btnPerfPerformance" type="button">Performance Mode (30 FPS)</button>
                        </div>
                    </div>
                    <div class="performance-checklist" style="border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 12px; margin-top: 4px; font-size: 11.5px; color: var(--text-muted);">
                        <div style="font-weight: 700; margin-bottom: 8px; color: var(--text-primary);">Performance Mode Features:</div>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px 12px; line-height: 1.4;">
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Reduced animations</div>
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Disable particles</div>
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Disable backdrop blur</div>
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Disable heavy shadows</div>
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Disable continuous rotation</div>
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Reduce refresh frequency</div>
                            <div><i class="fa-solid fa-square-check text-success" style="color: #10b981;"></i> Use simpler transitions</div>
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

            <!-- Quick Screen Image Manager Panel -->
            <div class="panel">
                <div class="page-subtitle mb-4">Quick Screen Image Board</div>
                
                <!-- Upload Form -->
                <form id="qsUploadForm" method="POST" enctype="multipart/form-data" class="mb-4" style="border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 18px;">
                    <?= admin_csrf_field() ?>
                    <input type="hidden" name="action" value="upload_quick_screen">
                    <label style="font-size: 13px; font-weight: 700; color: var(--text-primary); display: block; margin-bottom: 8px;">Upload New Quick Screen Image</label>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="file" name="quick_screen" accept="image/*" class="form-control" style="flex: 1; min-width: 200px;" required>
                        <button type="submit" class="btn btn-primary btn-md">
                            <i class="fa-solid fa-cloud-arrow-up mr-1"></i> Upload
                        </button>
                    </div>
                </form>

                <!-- Gallery -->
                <label style="font-size: 13px; font-weight: 700; color: var(--text-primary); display: block; margin-bottom: 12px;">Quick Screen Library</label>
                <?php
                $quickScreensDir = app_path('uploads/quick-screens') . DIRECTORY_SEPARATOR;
                $qsImages = [];
                if (is_dir($quickScreensDir)) {
                    $files = glob($quickScreensDir . '*.*');
                    if ($files) {
                        usort($files, function($a, $b) {
                            return filemtime($b) <=> filemtime($a);
                        });
                        foreach ($files as $file) {
                            $qsImages[] = basename($file);
                        }
                    }
                }
                ?>
                
                <div id="qsEmptyState" style="display: <?= empty($qsImages) ? 'block' : 'none' ?>; text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; border: 1.5px dashed rgba(255,255,255,0.1); border-radius: 12px; margin-bottom: 16px;">
                    No quick screen images uploaded yet.
                </div>
                
                <div id="qsGrid" style="display: <?= empty($qsImages) ? 'grid' : 'none' ?>; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px;">
                    <?php foreach ($qsImages as $img): 
                        $isActive = (($tvSettings['quick_screen_image'] ?? '') === $img && !empty($tvSettings['quick_screen_enabled']));
                    ?>
                        <div class="quick-screen-card <?= $isActive ? 'is-active' : '' ?>" data-image-card="<?= e($img) ?>" style="background: rgba(15, 23, 42, 0.4); border: 1.5px solid <?= $isActive ? '#10b981' : 'rgba(255,255,255,0.08)' ?>; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; position: relative;">
                            <div class="img-preview-box" style="aspect-ratio: 16/9; background-image: url('<?= app_url('/uploads/quick-screens/' . e($img)) ?>'); background-size: cover; background-position: center; position: relative; border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <span class="badge badge-success on-air-badge" style="position: absolute; top: 8px; left: 8px; font-size: 9px; padding: 2px 6px; border-radius: 6px; display: <?= $isActive ? 'inline-block' : 'none' ?>;">ON AIR</span>
                            </div>
                            <div style="padding: 10px; display: flex; flex-direction: column; gap: 8px; flex: 1; justify-content: space-between;">
                                <span style="font-size: 11px; font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; color: var(--text-muted); max-width: 100%;" title="<?= e($img) ?>">
                                    <?= e(preg_replace('/^\d+_/', '', $img)) ?>
                                </span>
                                <div style="display: flex; gap: 4px;">
                                    <?php if ($isActive): ?>
                                        <button class="btn btn-danger btn-xs btn-qs-deactivate" data-image="<?= e($img) ?>" style="flex: 1; padding: 4px;" title="Deactivate Quick Screen">Hide</button>
                                    <?php else: ?>
                                        <button class="btn btn-success btn-xs btn-qs-activate" data-image="<?= e($img) ?>" style="flex: 1; padding: 4px;" title="Activate Quick Screen">Show</button>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary btn-xs btn-qs-delete" data-image="<?= e($img) ?>" style="padding: 4px; color: #ff6f6f;" title="Delete image">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="font-size: 11.5px; color: var(--text-muted); line-height: 1.4; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 12px; margin-top: 16px;">
                    <span style="color: #3b82f6; font-weight: 700;">Tip:</span> Activating any image from this library will immediately broadcast it in fullscreen on all connected stage screens (super useful for custom break banners or announcements).
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

    // Helper to dynamically update the active quick screen UI card states
    function updateActiveQuickScreen(activeImg) {
        document.querySelectorAll('.quick-screen-card').forEach(card => {
            const imgName = card.dataset.imageCard;
            const badge = card.querySelector('.on-air-badge');
            const actionBtn = card.querySelector('.btn-qs-activate, .btn-qs-deactivate');
            
            if (imgName === activeImg) {
                card.classList.add('is-active');
                card.style.borderColor = '#10b981';
                if (badge) badge.style.display = 'inline-block';
                if (actionBtn) {
                    actionBtn.className = 'btn btn-danger btn-xs btn-qs-deactivate';
                    actionBtn.textContent = 'Hide';
                    actionBtn.title = 'Deactivate Quick Screen';
                }
            } else {
                card.classList.remove('is-active');
                card.style.borderColor = 'rgba(255,255,255,0.08)';
                if (badge) badge.style.display = 'none';
                if (actionBtn) {
                    actionBtn.className = 'btn btn-success btn-xs btn-qs-activate';
                    actionBtn.textContent = 'Show';
                    actionBtn.title = 'Activate Quick Screen';
                }
            }
        });
        
        // Reload preview iframe if visible to reflect display change immediately
        if (iframe) iframe.src = iframe.src;
    }

    // Quick Screen Image Actions Event Delegation (Show / Hide / Delete)
    document.addEventListener('click', async function(e) {
        const actBtn = e.target.closest('.btn-qs-activate');
        const deactBtn = e.target.closest('.btn-qs-deactivate');
        const deleteBtn = e.target.closest('.btn-qs-delete');
        
        if (actBtn) {
            e.preventDefault();
            const img = actBtn.dataset.image;
            const res = await postSettings('quick_screen', { image: img, enabled: 1 });
            if (res && res.success) {
                updateActiveQuickScreen(img);
                if (window.showToast) window.showToast('Quick screen activated.', 'success');
            }
        }
        
        if (deactBtn) {
            e.preventDefault();
            const img = deactBtn.dataset.image;
            const res = await postSettings('quick_screen', { image: img, enabled: 0 });
            if (res && res.success) {
                updateActiveQuickScreen(null);
                if (window.showToast) window.showToast('Quick screen deactivated.', 'info');
            }
        }

        if (deleteBtn) {
            e.preventDefault();
            const img = deleteBtn.dataset.image;
            if (!confirm('Are you sure you want to delete this image?')) return;
            
            const formData = new FormData();
            formData.append('csrf_token', CSRF);
            formData.append('action', 'delete_quick_screen');
            formData.append('image', img);
            formData.append('ajax', '1');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                if (res && res.success) {
                    if (window.showToast) window.showToast('Image deleted successfully.', 'success');
                    // Remove card from DOM
                    const card = document.querySelector(`[data-image-card="${img}"]`);
                    if (card) card.remove();
                    
                    // Toggle empty state if grid is empty
                    const grid = document.getElementById('qsGrid');
                    const emptyState = document.getElementById('qsEmptyState');
                    if (grid && grid.querySelectorAll('.quick-screen-card').length === 0) {
                        grid.style.display = 'none';
                        emptyState.style.display = 'block';
                    }
                } else {
                    if (window.showToast) window.showToast(res.message || 'Failed to delete image.', 'error');
                }
            } catch (err) {
                console.error(err);
                if (window.showToast) window.showToast('Network error occurred.', 'error');
            }
        }
    });

    // Quick Screen Image Async Upload Handler
    document.getElementById('qsUploadForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalBtnHTML = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Uploading...';
        
        const formData = new FormData(this);
        formData.append('ajax', '1');
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const res = await response.json();
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHTML;
            
            if (res && res.success) {
                if (window.showToast) window.showToast(res.message || 'Uploaded successfully.', 'success');
                // Reset file field
                this.reset();
                
                // Add the new card to the grid
                const grid = document.getElementById('qsGrid');
                const emptyState = document.getElementById('qsEmptyState');
                
                if (grid && emptyState) {
                    const cardHTML = `
                        <div class="quick-screen-card" data-image-card="${res.image}" style="background: rgba(15, 23, 42, 0.4); border: 1.5px solid rgba(255,255,255,0.08); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; position: relative;">
                            <div class="img-preview-box" style="aspect-ratio: 16/9; background-image: url('${res.url}'); background-size: cover; background-position: center; position: relative; border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <span class="badge badge-success on-air-badge" style="position: absolute; top: 8px; left: 8px; font-size: 9px; padding: 2px 6px; border-radius: 6px; display: none;">ON AIR</span>
                            </div>
                            <div style="padding: 10px; display: flex; flex-direction: column; gap: 8px; flex: 1; justify-content: space-between;">
                                <span style="font-size: 11px; font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; color: var(--text-muted); max-width: 100%;" title="${res.image}">
                                    ${res.display_name}
                                </span>
                                <div style="display: flex; gap: 4px;">
                                    <button class="btn btn-success btn-xs btn-qs-activate" data-image="${res.image}" style="flex: 1; padding: 4px;" title="Activate Quick Screen">Show</button>
                                    <button class="btn btn-secondary btn-xs btn-qs-delete" data-image="${res.image}" style="padding: 4px; color: #ff6f6f;" title="Delete image">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    // Insert at the beginning of the grid
                    grid.insertAdjacentHTML('afterbegin', cardHTML);
                    
                    // Show grid and hide empty state
                    grid.style.display = 'grid';
                    emptyState.style.display = 'none';
                }
            } else {
                if (window.showToast) window.showToast(res.message || 'Upload failed.', 'error');
            }
        } catch (err) {
            console.error(err);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHTML;
            if (window.showToast) window.showToast('Network error during upload.', 'error');
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

    // Performance Mode switching
    document.getElementById('btnPerfQuality')?.addEventListener('click', async function() {
        const res = await postSettings('performance_mode', { performance_mode: 'quality' });
        if (res && res.success) {
            this.className = 'btn btn-primary btn-sm';
            const perfBtn = document.getElementById('btnPerfPerformance');
            if (perfBtn) perfBtn.className = 'btn btn-secondary btn-sm';
            // Reload preview iframe
            if (iframe) iframe.src = iframe.src;
        }
    });
    document.getElementById('btnPerfPerformance')?.addEventListener('click', async function() {
        const res = await postSettings('performance_mode', { performance_mode: 'performance' });
        if (res && res.success) {
            this.className = 'btn btn-primary btn-sm';
            const qualBtn = document.getElementById('btnPerfQuality');
            if (qualBtn) qualBtn.className = 'btn btn-secondary btn-sm';
            // Reload preview iframe
            if (iframe) iframe.src = iframe.src;
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

