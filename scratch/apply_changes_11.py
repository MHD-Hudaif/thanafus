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

# 1. CSS Overrides link replacement
search_css = """<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">"""

replace_css = """<link rel="stylesheet" href="<?= asset_url('css/event-workspace.css') ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/event-workspace.css') ?>">
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
</style>"""

replace_in_file(ctrl_path, search_css, replace_css)

# 2. Panel insertion
search_panel = """            </div>



            <!-- Statistics Grid -->"""

replace_panel = """            </div>

            <!-- Flash Announcement & Breaks Panel -->
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
            </div>

            <!-- Statistics Grid -->"""

replace_in_file(ctrl_path, search_panel, replace_panel)

# 3. JavaScript Click handlers
search_js = """    });

    // Loop Display mode auto/manual switching"""

replace_js = """    });

    // Flash Announcement Alert trigger actions
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
    });

    // Loop Display mode auto/manual switching"""

replace_in_file(ctrl_path, search_js, replace_js)
