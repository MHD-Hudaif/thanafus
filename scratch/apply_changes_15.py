import os

def replace_in_file(filepath, search_str, replace_str):
    if not os.path.exists(filepath):
        print(f"File not found: {filepath}")
        return False
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    normalized_content = content.replace('\r\n', '\n')
    normalized_search = search_str.replace('\r\n', '\n')
    normalized_replace = replace_str.replace('\r\n', '\n')
    
    if normalized_search in normalized_content:
        new_content = normalized_content.replace(normalized_search, normalized_replace)
        if '\r\n' in content:
            new_content = new_content.replace('\n', '\r\n')
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"Successfully modified: {filepath}")
        return True
    else:
        print(f"Search string not found in: {filepath}")
        return False

ctrl_path = r"admin/live-display/control-live-display.php"

# 1. Replace POST Action handlers at the top
search_top = """// POST Save Slide components
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
}"""

replace_top = """$tvSettings = tv_get_settings($activeEventId);

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'upload_quick_screen') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
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
                $uploadFileDir = app_path('uploads/quick-screens/');
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                $newFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $fileName);
                $dest_path = $uploadFileDir . $newFileName;
                if (!move_uploaded_file($fileTmpPath, $dest_path)) {
                    throw new Exception('Failed to save uploaded image file.');
                }
                admin_flash('success', 'Quick screen image uploaded successfully.');
            } else {
                throw new Exception('No image file selected or upload error.');
            }
        } catch (Throwable $e) {
            admin_flash('error', $e->getMessage());
        }
        admin_redirect('/admin/live-display/control-live-display.php');
    }

    if ($action === 'delete_quick_screen') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            admin_flash('error', 'Invalid security token.');
            admin_redirect('/admin/live-display/control-live-display.php');
        }

        $image = (string)($_POST['image'] ?? '');
        $imagePath = app_path('uploads/quick-screens/' . basename($image));
        if ($image !== '' && file_exists($imagePath)) {
            unlink($imagePath);
            if (($tvSettings['quick_screen_image'] ?? '') === $image) {
                $tvSettings['quick_screen_enabled'] = false;
                $tvSettings['quick_screen_image'] = '';
                tv_save_settings($activeEventId, $tvSettings);
            }
            admin_flash('success', 'Quick screen image deleted.');
        } else {
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
}"""

replace_in_file(ctrl_path, search_top, replace_top)

# 2. Remove redundant tvSettings reload
search_reload = """$tvSettings = tv_get_settings($activeEventId);"""
replace_reload = """$tvSettings = $tvSettings ?? tv_get_settings($activeEventId);"""
replace_in_file(ctrl_path, search_reload, replace_reload)

# 3. Replace Flash Announcement Panel with Quick Screen Panel
search_panel = """            <!-- Flash Announcement & Breaks Panel -->
            <div class="panel">
                <div class="page-subtitle mb-4">Flash Announcement & Break Overlay</div>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div class="input-group" style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 13px; font-weight: 700; color: var(--text-primary);">Announcement Message</label>
                        <textarea id="flashMessage" class="form-control" rows="3" placeholder="Type a message (e.g. 'PRAYER BREAK: Programs will resume at 04:45 PM' or 'LUNCH BREAK')..." style="resize: vertical; width: 100%; box-sizing: border-box;"><?= e($tvSettings['flash_announcement'] ?? '') ?></textarea>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button class="btn <?= !empty($tvSettings['flash_announcement_enabled']) ? 'btn-success' : 'btn-secondary' ?> btn-md" type="button" id="btnActivateFlash">
                            <i class="fa-solid fa-broadcast-tower mr-1"></i> Activate Overlay
                        </button>
                        <button class="btn <?= empty($tvSettings['flash_announcement_enabled']) ? 'btn-danger' : 'btn-secondary' ?> btn-md" type="button" id="btnDeactivateFlash">
                            <i class="fa-solid fa-ban mr-1"></i> Hide Overlay
                        </button>
                    </div>
                    <div style="font-size: 11.5px; color: var(--text-muted); line-height: 1.4; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 10px;">
                        <span style="color: #3b82f6; font-weight: 700;">Tip:</span> Activating this overlay will immediately interrupt the TV screen loop on all displays to show the custom message in a large cinematic screen format (perfect for breaks or emergency alerts).
                    </div>
                </div>
            </div>"""

replace_panel = """            <!-- Quick Screen Image Manager Panel -->
            <div class="panel">
                <div class="page-subtitle mb-4">Quick Screen Image Board</div>
                
                <!-- Upload Form -->
                <form method="POST" enctype="multipart/form-data" class="mb-4" style="border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 18px;">
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
                $quickScreensDir = app_path('uploads/quick-screens/');
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
                
                if (empty($qsImages)): ?>
                    <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; border: 1.5px dashed rgba(255,255,255,0.1); border-radius: 12px;">
                        No quick screen images uploaded yet.
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px;">
                        <?php foreach ($qsImages as $img): 
                            $isActive = (($tvSettings['quick_screen_image'] ?? '') === $img && !empty($tvSettings['quick_screen_enabled']));
                        ?>
                            <div class="quick-screen-card <?= $isActive ? 'is-active' : '' ?>" style="background: rgba(15, 23, 42, 0.4); border: 1.5px solid <?= $isActive ? '#10b981' : 'rgba(255,255,255,0.08)' ?>; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; position: relative;">
                                <div style="aspect-ratio: 16/9; background-image: url('<?= app_url('/uploads/quick-screens/' . e($img)) ?>'); background-size: cover; background-position: center; position: relative; border-bottom: 1px solid rgba(255,255,255,0.08);">
                                    <?php if ($isActive): ?>
                                        <span class="badge badge-success" style="position: absolute; top: 8px; left: 8px; font-size: 9px; padding: 2px 6px; border-radius: 6px;">ON AIR</span>
                                    <?php endif; ?>
                                </div>
                                <div style="padding: 10px; display: flex; flex-direction: column; gap: 8px; flex: 1; justify-content: space-between;">
                                    <span style="font-size: 11px; font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; color: var(--text-muted); max-width: 100%;" title="<?= e($img) ?>">
                                        <?= e(preg_replace('/^\\d+_/', '', $img)) ?>
                                    </span>
                                    <div style="display: flex; gap: 4px;">
                                        <?php if ($isActive): ?>
                                            <button class="btn btn-danger btn-xs btn-qs-deactivate" data-image="<?= e($img) ?>" style="flex: 1; padding: 4px;" title="Deactivate Quick Screen">Hide</button>
                                        <?php else: ?>
                                            <button class="btn btn-success btn-xs btn-qs-activate" data-image="<?= e($img) ?>" style="flex: 1; padding: 4px;" title="Activate Quick Screen">Show</button>
                                        <?php endif; ?>
                                        <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Are you sure you want to delete this image?');">
                                            <?= admin_csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_quick_screen">
                                            <input type="hidden" name="image" value="<?= e($img) ?>">
                                            <button type="submit" class="btn btn-secondary btn-xs" style="padding: 4px; color: #ff6f6f;" title="Delete image">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div style="font-size: 11.5px; color: var(--text-muted); line-height: 1.4; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 12px; margin-top: 16px;">
                    <span style="color: #3b82f6; font-weight: 700;">Tip:</span> Activating any image from this library will immediately broadcast it in fullscreen on all connected stage screens (super useful for custom break banners or announcements).
                </div>
            </div>"""

replace_in_file(ctrl_path, search_panel, replace_panel)

# 4. Replace JS event handlers
search_js = """    // Flash Announcement Alert trigger actions
    document.getElementById('btnActivateFlash')?.addEventListener('click', async function() {
        const msg = document.getElementById('flashMessage')?.value || '';
        if (msg.trim() === '') {
            if (window.showToast) window.showToast('Please type an announcement message first.', 'error');
            else alert('Please type an announcement message first.');
            return;
        }
        const res = await postSettings('flash_announcement', { message: msg, enabled: 1 });
        if (res && res.success) {
            this.className = 'btn btn-success btn-md';
            const deactivateBtn = document.getElementById('btnDeactivateFlash');
            if (deactivateBtn) deactivateBtn.className = 'btn btn-secondary btn-md';
            // Reload preview iframe
            if (iframe) iframe.src = iframe.src;
        }
    });

    document.getElementById('btnDeactivateFlash')?.addEventListener('click', async function() {
        const msg = document.getElementById('flashMessage')?.value || '';
        const res = await postSettings('flash_announcement', { message: msg, enabled: 0 });
        if (res && res.success) {
            this.className = 'btn btn-danger btn-md';
            const activateBtn = document.getElementById('btnActivateFlash');
            if (activateBtn) activateBtn.className = 'btn btn-secondary btn-md';
            // Reload preview iframe
            if (iframe) iframe.src = iframe.src;
        }
    });"""

replace_js = """    // Quick Screen Image Actions
    document.querySelectorAll('.btn-qs-activate').forEach(btn => {
        btn.addEventListener('click', async function() {
            const img = this.dataset.image;
            const res = await postSettings('quick_screen', { image: img, enabled: 1 });
            if (res && res.success) {
                window.location.reload();
            }
        });
    });

    document.querySelectorAll('.btn-qs-deactivate').forEach(btn => {
        btn.addEventListener('click', async function() {
            const img = this.dataset.image;
            const res = await postSettings('quick_screen', { image: img, enabled: 0 });
            if (res && res.success) {
                window.location.reload();
            }
        });
    });"""

replace_in_file(ctrl_path, search_js, replace_js)
