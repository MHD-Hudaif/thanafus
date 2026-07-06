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
            
            if ($activeEventId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE musabaqa_tv_components 
                    SET title = ?, duration = ?, is_enabled = ?, sort_order = ?
                    WHERE event_id = ? AND slide_key = ?
                ");
                $stmt->execute([$title, $duration, $isEnabled, $sortOrder, $activeEventId, $key]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE musabaqa_tv_components 
                    SET title = ?, duration = ?, is_enabled = ?, sort_order = ?
                    WHERE event_id IS NULL AND slide_key = ?
                ");
                $stmt->execute([$title, $duration, $isEnabled, $sortOrder, $key]);
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

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

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
                            <th style="width: 120px; text-align: center;">Status</th>
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
})();
</script>

<?php admin_close_page(); ?>

