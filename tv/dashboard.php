<?php
$pageTitle = 'TV Display Control';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];

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
        CONSTRAINT uniq_event_slide UNIQUE (event_id, slide_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Clean up legacy/obsolete slide keys
$pdo->exec("DELETE FROM musabaqa_tv_components WHERE slide_key NOT IN ('intro', 'leaderboard', 'schedule', 'current-program')");

// Populate default global slide components if missing
$existingKeys = $pdo->query("SELECT DISTINCT slide_key FROM musabaqa_tv_components WHERE event_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);

$defaultSlides = [
    'intro' => ['Welcome Intro', 12000, 1],
    'leaderboard' => ['Team Leaderboard', 16000, 2],
    'schedule' => ['Upcoming Programs', 18000, 3],
    'current-program' => ['Main Stage (Now Performing)', 18000, 4]
];

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO musabaqa_tv_components (event_id, slide_key, title, duration, is_enabled, sort_order)
    VALUES (NULL, ?, ?, ?, 1, ?)
");

$updateSortStmt = $pdo->prepare("
    UPDATE musabaqa_tv_components 
    SET sort_order = ? 
    WHERE slide_key = ? AND event_id IS NULL
");

foreach ($defaultSlides as $key => $slide) {
    if (!in_array($key, $existingKeys, true)) {
        $insertStmt->execute([$key, $slide[0], $slide[1], $slide[2]]);
    } else {
        $updateSortStmt->execute([$slide[2], $key]);
    }
}

$activeEventId = (int)($_SESSION['active_event_id'] ?? 0);
$activeEvent = null;

if ($activeEventId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM musabaqa_events WHERE id = ? LIMIT 1');
    $stmt->execute([$activeEventId]);
    $activeEvent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Copy defaults for the active event (only copying missing slide keys)
    $pdo->prepare("
        INSERT INTO musabaqa_tv_components (event_id, slide_key, title, duration, is_enabled, sort_order)
        SELECT ?, slide_key, title, duration, is_enabled, sort_order
        FROM musabaqa_tv_components g
        WHERE g.event_id IS NULL
          AND NOT EXISTS (
              SELECT 1 
              FROM musabaqa_tv_components e 
              WHERE e.event_id = ? 
                AND e.slide_key = g.slide_key
          )
    ")->execute([$activeEventId, $activeEventId]);
}

// POST Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/tv/dashboard.php');
    }

    $slides = $_POST['slides'] ?? [];
    try {
        $pdo->beginTransaction();
        foreach ($slides as $key => $slideData) {
            $title = trim((string)($slideData['title'] ?? ''));
            if ($title === '') {
                $title = ucfirst($key);
            }
            // Convert seconds back to milliseconds
            $duration = max(1, (int)($slideData['duration'] ?? 10)) * 1000;
            $isEnabled = isset($slideData['is_enabled']) ? 1 : 0;
            $sortOrder = (int)($slideData['sort_order'] ?? 0);
            
            $style = trim((string)($slideData['style'] ?? 'classic'));
            if (!in_array($style, ['classic', 'orbit', 'podium', 'staggered', 'style2'], true)) {
                $style = 'classic';
            }

            if ($activeEventId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE musabaqa_tv_components 
                    SET title = ?, duration = ?, is_enabled = ?, sort_order = ?, style = ?
                    WHERE event_id = ? AND slide_key = ?
                ");
                $stmt->execute([$title, $duration, $isEnabled, $sortOrder, $style, $activeEventId, $key]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE musabaqa_tv_components 
                    SET title = ?, duration = ?, is_enabled = ?, sort_order = ?, style = ?
                    WHERE event_id IS NULL AND slide_key = ?
                ");
                $stmt->execute([$title, $duration, $isEnabled, $sortOrder, $style, $key]);
            }
        }
        $pdo->commit();
        admin_flash('success', 'TV slides updated successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash('error', 'Failed to save settings: ' . $e->getMessage());
    }
    admin_redirect('/tv/dashboard.php');
}

// Retrieve Slide Configs
if ($activeEventId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM musabaqa_tv_components WHERE event_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$activeEventId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM musabaqa_tv_components WHERE event_id IS NULL ORDER BY sort_order ASC");
    $stmt->execute();
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$flash = admin_take_flash();

// Load TV functions to fetch live statistics
require_once __DIR__ . '/includes/functions.php';
$stats = tv_stats($activeEventId);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* TV Control Layout */
.tv-control-layout {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 24px;
    align-items: start;
    margin-top: 20px;
}

@media (max-width: 1024px) {
    .tv-control-layout {
        grid-template-columns: 1fr;
    }
}

.sticky-preview {
    position: sticky;
    top: 24px;
}

/* TV Bezel and Frame */
.tv-frame-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    margin: 0 auto;
}

.tv-bezel {
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #1e1e24;
    border: 12px solid #2d2d35;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    position: relative;
    box-sizing: border-box;
}

.tv-screen {
    width: 100%;
    height: 100%;
    background: #000;
    overflow: hidden;
    position: relative;
}

.tv-screen iframe {
    width: 1920px;
    height: 1080px;
    border: none;
    transform-origin: top left;
    position: absolute;
    top: 0;
    left: 0;
}

.tv-stand {
    width: 60px;
    height: 20px;
    background: #23232a;
    border-bottom: 2px solid #1a1a20;
}

.tv-base {
    width: 160px;
    height: 8px;
    background: #2d2d35;
    border-radius: 4px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

/* Live Badge */
.live-badge {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(239, 68, 68, 0.2);
}

.live-dot {
    width: 8px;
    height: 8px;
    background-color: #ef4444;
    border-radius: 50%;
    display: inline-block;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% {
        transform: scale(0.9);
        opacity: 1;
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    }
    70% {
        transform: scale(1);
        opacity: 0.8;
        box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
    }
    100% {
        transform: scale(0.9);
        opacity: 1;
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
    }
}

/* Analytics Stats Grid */
.tv-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

@media (max-width: 480px) {
    .tv-stats-grid {
        grid-template-columns: 1fr;
    }
}

.tv-stat-card {
    background: var(--bg-secondary, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    padding: 20px;
    border-radius: 12px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.admin-layout.dark-mode .tv-stat-card,
body.dark .tv-stat-card {
    background: #1e1e24;
    border-color: #2d2d35;
}

.tv-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.tv-stat-val {
    font-size: 28px;
    font-weight: 800;
    color: var(--text-primary, #0f172a);
    line-height: 1;
    margin-bottom: 4px;
}

.tv-stat-lbl {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    font-weight: 500;
}

.tv-stat-icon {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 32px;
    color: var(--primary-color-light, rgba(20, 184, 166, 0.15));
    opacity: 0.8;
}
</style>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title">TV Broadcast Control</div>
            <div class="page-subtitle">Configure screen slide ordering and durations</div>
        </div>
        <div class="flex gap-3">
            <a href="<?= APP_URL ?>/tv/index.php" target="_blank" class="btn btn-primary btn-md" data-ajax-ignore>
                <i class="fa-solid fa-square-rss"></i> Launch TV Display
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="tv-control-layout">
        <!-- Left Column: Slide Controls & Analytics -->
        <div class="tv-control-left">
            <div class="panel">
                <form method="POST" class="form-grid">
                    <?= admin_csrf_field() ?>
                    
                    <div class="full-width table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 100px;">Sort Order</th>
                                    <th>Slide Component</th>
                                    <th>Broadcast Title</th>
                                    <th style="width: 150px;">Duration (sec)</th>
                                    <th style="width: 140px;">Layout / Style</th>
                                    <th style="width: 120px; text-align: center;">Status</th>
                                    <th style="width: 200px; text-align: center;">Actions</th>
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
                                                   style="width: 80px;"
                                                   required>
                                        </td>
                                        <td>
                                            <strong><?= e(ucfirst($c['slide_key'])) ?></strong>
                                            <span class="block text-muted text-xs"><?= e($c['slide_key']) ?></span>
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
                                                   min="1" 
                                                   class="form-control" 
                                                   style="width: 100px;"
                                                   required>
                                        </td>
                                        <td>
                                            <?php if ($c['slide_key'] === 'leaderboard'): ?>
                                                <select name="slides[<?= e($c['slide_key']) ?>][style]" class="form-control" style="width: 130px;">
                                                    <option value="classic" <?= ($c['style'] ?? 'classic') === 'classic' ? 'selected' : '' ?>>Classic (Bars)</option>
                                                    <option value="orbit" <?= ($c['style'] ?? 'classic') === 'orbit' ? 'selected' : '' ?>>Orbit (Radial)</option>
                                                    <option value="podium" <?= ($c['style'] ?? 'classic') === 'podium' ? 'selected' : '' ?>>Podium (3D)</option>
                                                    <option value="style2" <?= ($c['style'] ?? 'classic') === 'style2' ? 'selected' : '' ?>>Style 2 (Diamond 3D)</option>
                                                    <option value="staggered" <?= ($c['style'] ?? 'classic') === 'staggered' ? 'selected' : '' ?>>Staggered</option>
                                                </select>
                                            <?php else: ?>
                                                <input type="hidden" name="slides[<?= e($c['slide_key']) ?>][style]" value="classic">
                                                <span class="text-muted text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="slide-status-wrap">
                                                <label class="toggle-switch">
                                                    <input type="checkbox"
                                                           class="slide-toggle"
                                                           name="slides[<?= e($c['slide_key']) ?>][is_enabled]"
                                                           value="1"
                                                           data-slide-name="<?= e($c['title']) ?>"
                                                           <?= $c['is_enabled'] ? 'checked' : '' ?>>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                <span class="slide-status-label <?= $c['is_enabled'] ? 'is-on' : '' ?>"><?= $c['is_enabled'] ? 'On Air' : 'Off' ?></span>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; gap: 8px; justify-content: center; align-items: center;">
                                                <button type="button" 
                                                        class="btn btn-secondary btn-sm preview-slide-btn" 
                                                        data-url="<?= APP_URL ?>/tv/<?= e($c['slide_key']) ?>.php"
                                                        style="min-height: 28px; padding: 4px 8px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;"
                                                        title="Preview in Frame">
                                                    <i class="fa-solid fa-eye"></i> Preview
                                                </button>
                                                <a href="<?= APP_URL ?>/tv/<?= e($c['slide_key']) ?>.php" 
                                                   target="_blank" 
                                                   class="btn btn-primary btn-sm" 
                                                   style="min-height: 28px; padding: 4px 8px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;"
                                                   title="Launch Standalone"
                                                   data-ajax-ignore>
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="full-width flex justify-end gap-3 mt-4">
                        <button type="submit" class="btn btn-success btn-md">
                            <i class="fa-solid fa-floppy-disk"></i> Save TV Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Analytics Panel (Placed below the controls as requested) -->
            <div class="panel mt-6">
                <div class="flex justify-between items-center mb-4" style="border-bottom: 1px solid var(--border-color, #e2e8f0); padding-bottom: 12px;">
                    <h3 style="font-size: 16px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-chart-simple text-primary"></i> Broadcast Analytics
                    </h3>
                </div>
                <div class="tv-stats-grid">
                    <div class="tv-stat-card">
                        <div class="tv-stat-val"><?= number_format($stats['teams']) ?></div>
                        <div class="tv-stat-lbl">Competing Teams</div>
                        <i class="fa-solid fa-users tv-stat-icon"></i>
                    </div>
                    <div class="tv-stat-card">
                        <div class="tv-stat-val"><?= number_format($stats['programs']) ?></div>
                        <div class="tv-stat-lbl">Total Programs</div>
                        <i class="fa-solid fa-list-check tv-stat-icon"></i>
                    </div>
                    <div class="tv-stat-card">
                        <div class="tv-stat-val"><?= number_format($stats['completed_programs']) ?></div>
                        <div class="tv-stat-lbl">Completed Programs</div>
                        <i class="fa-solid fa-circle-check tv-stat-icon"></i>
                    </div>
                    <div class="tv-stat-card">
                        <div class="tv-stat-val"><?= number_format($stats['entries']) ?></div>
                        <div class="tv-stat-lbl">Score Entries</div>
                        <i class="fa-solid fa-clipboard-list tv-stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Live TV Display Preview -->
        <div class="tv-control-right">
            <div class="panel sticky-preview">
                <div class="flex justify-between items-center mb-4" style="border-bottom: 1px solid var(--border-color, #e2e8f0); padding-bottom: 12px;">
                    <h3 style="font-size: 16px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-tv text-primary"></i> Live TV Preview
                    </h3>
                    <span class="live-badge"><span class="live-dot"></span> LIVE FEED</span>
                </div>
                
                <!-- Realistically designed TV screen frame -->
                <div class="tv-frame-container">
                    <div class="tv-bezel">
                        <div class="tv-screen">
                            <iframe id="tvPreviewIframe" src="<?= APP_URL ?>/tv/index.php" frameborder="0"></iframe>
                        </div>
                    </div>
                    <div class="tv-stand"></div>
                    <div class="tv-base"></div>
                </div>
                
                <div class="flex justify-center gap-3 mt-4">
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('tvPreviewIframe').src = '<?= APP_URL ?>/tv/index.php';">
                        <i class="fa-solid fa-arrows-spin"></i> Loop All Slides
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('tvPreviewIframe').src = document.getElementById('tvPreviewIframe').src;">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                    <a href="<?= APP_URL ?>/tv/index.php" target="_blank" class="btn btn-primary btn-sm" data-ajax-ignore>
                        <i class="fa-solid fa-expand"></i> Fullscreen Loop
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SLIDE TOGGLE CONFIRMATION MODAL -->
<div class="modal-overlay" id="slideToggleModal">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <div class="modal-title" id="slideToggleTitle">Confirm Toggle</div>
            <button class="modal-close" type="button" data-close="slideToggleModal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="panel mb-6" id="slideToggleMessage">
            Are you sure you want to change the status of this slide?
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-secondary btn-md" id="slideToggleCancelBtn">Cancel</button>
            <button type="button" class="btn btn-success btn-md" id="slideToggleConfirmBtn">
                <i class="fa-solid fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<script>
(() => {
    function openModal(id) { document.getElementById(id)?.classList.add('active'); }
    function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

    let pendingToggle = null;

    const modal = document.getElementById('slideToggleModal');
    const titleEl = document.getElementById('slideToggleTitle');
    const msgEl = document.getElementById('slideToggleMessage');
    const confirmBtn = document.getElementById('slideToggleConfirmBtn');
    const cancelBtn = document.getElementById('slideToggleCancelBtn');

    function updateStatusLabel(checkbox) {
        const wrap = checkbox.closest('.slide-status-wrap');
        if (!wrap) return;
        const label = wrap.querySelector('.slide-status-label');
        if (!label) return;
        if (checkbox.checked) {
            label.textContent = 'On Air';
            label.classList.add('is-on');
        } else {
            label.textContent = 'Off';
            label.classList.remove('is-on');
        }
    }

    document.querySelectorAll('.slide-toggle').forEach(toggle => {
        toggle.addEventListener('change', function (e) {
            e.preventDefault();

            // Revert the visual state immediately — we'll apply it on confirm
            this.checked = !this.checked;

            const slideName = this.dataset.slideName || 'this slide';
            const willEnable = !this.checked; // the intended new state

            pendingToggle = this;

            if (willEnable) {
                titleEl.textContent = 'Enable Slide';
                msgEl.innerHTML = `Enable <strong>${slideName}</strong> on the TV broadcast? It will start appearing in the rotation.`;
                confirmBtn.innerHTML = '<i class="fa-solid fa-eye"></i> Enable';
                confirmBtn.className = 'btn btn-success btn-md';
            } else {
                titleEl.textContent = 'Disable Slide';
                msgEl.innerHTML = `Disable <strong>${slideName}</strong> from the TV broadcast? It will no longer appear in the rotation.`;
                confirmBtn.innerHTML = '<i class="fa-solid fa-eye-slash"></i> Disable';
                confirmBtn.className = 'btn btn-danger btn-md';
            }

            openModal('slideToggleModal');
        });
    });

    confirmBtn?.addEventListener('click', () => {
        if (pendingToggle) {
            pendingToggle.checked = !pendingToggle.checked;
            updateStatusLabel(pendingToggle);
            pendingToggle = null;
        }
        closeModal('slideToggleModal');
    });

    cancelBtn?.addEventListener('click', () => {
        pendingToggle = null;
        closeModal('slideToggleModal');
    });

    // Close modal on overlay click
    modal?.addEventListener('click', e => {
        if (e.target === modal) {
            pendingToggle = null;
            closeModal('slideToggleModal');
        }
    });

    // Close modal on [data-close] buttons
    document.querySelectorAll('[data-close="slideToggleModal"]').forEach(btn => {
        btn.addEventListener('click', () => {
            pendingToggle = null;
            closeModal('slideToggleModal');
        });
    });

    // Handle individual slide preview button click
    document.querySelectorAll('.preview-slide-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = this.dataset.url;
            const iframe = document.getElementById('tvPreviewIframe');
            if (iframe) {
                iframe.src = url;
            }
        });
    });

    // TV Preview scaling
    const tvScreen = document.querySelector('.tv-screen');
    const tvIframe = document.getElementById('tvPreviewIframe');
    if (tvScreen && tvIframe) {
        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const scale = entry.contentRect.width / 1920;
                tvIframe.style.transform = `scale(${scale})`;
            }
        });
        resizeObserver.observe(tvScreen);
    }
})();
</script>

<?php admin_close_page(); ?>

