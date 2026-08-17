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

js_path = r"live-display/assets/js/live-display.js"

search_js_def = """    function syncFlashAnnouncement(settings) {
        let overlay = document.getElementById('tvFlashAnnouncementOverlay');
        const isEnabled = settings.flash_announcement_enabled && settings.flash_announcement;

        if (isEnabled) {
            const message = settings.flash_announcement;
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'tvFlashAnnouncementOverlay';
                overlay.className = 'flash-announcement-overlay';
                document.body.appendChild(overlay);
            }
            
            // Only update innerHTML if message changed to prevent flashing or re-triggering animations
            if (overlay.dataset.message !== message) {
                overlay.dataset.message = message;
                overlay.innerHTML = `
                    <div class="flash-announcement-box">
                        <div class="flash-announcement-header">
                            <span class="flash-live-badge animate-pulse">
                                <i class="fa-solid fa-broadcast-tower mr-1"></i> LIVE ANNOUNCEMENT
                            </span>
                        </div>
                        <div class="flash-announcement-content">
                            ${escapeHtml(message)}
                        </div>
                        <div class="flash-announcement-footer">
                            <div class="tv-clock-inline" data-flash-clock>00:00:00</div>
                        </div>
                    </div>
                `;
                // Start internal clock for the overlay
                if (overlay.dataset.timerId) {
                    clearInterval(Number(overlay.dataset.timerId));
                }
                const clockEl = overlay.querySelector('[data-flash-clock]');
                if (clockEl) {
                    const updateClock = () => {
                        const now = new Date();
                        clockEl.textContent = now.toLocaleTimeString([], { hour12: false });
                    };
                    updateClock();
                    overlay.dataset.timerId = setInterval(updateClock, 1000);
                }
            }
            
            // Trigger animation in
            setTimeout(() => {
                overlay.classList.add('is-active');
            }, 10);
        } else {
            if (overlay) {
                overlay.classList.remove('is-active');
                // Clean up after exit transition
                if (overlay.dataset.timerId) {
                    clearInterval(Number(overlay.dataset.timerId));
                    overlay.dataset.timerId = '';
                }
                setTimeout(() => {
                    if (!overlay.classList.contains('is-active')) {
                        overlay.remove();
                    }
                }, 600); // match transition duration
            }
        }
    }"""

replace_js_def = """    function syncQuickScreen(settings) {
        let overlay = document.getElementById('tvQuickScreenOverlay');
        const isEnabled = settings.quick_screen_enabled && settings.quick_screen_image;

        if (isEnabled) {
            const imageName = settings.quick_screen_image;
            const base = (window.APP_CONFIG?.baseUrl || '').replace(/\/$/, '');
            const imageUrl = `${base}/uploads/quick-screens/${imageName}`;

            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'tvQuickScreenOverlay';
                overlay.className = 'quick-screen-overlay';
                document.body.appendChild(overlay);
            }

            if (overlay.dataset.image !== imageName) {
                overlay.dataset.image = imageName;
                overlay.innerHTML = `
                    <div class="quick-screen-blur-bg" style="background-image: url('${imageUrl}');"></div>
                    <div class="quick-screen-contain-img" style="background-image: url('${imageUrl}');"></div>
                `;
            }

            setTimeout(() => {
                overlay.classList.add('is-active');
            }, 10);
        } else {
            if (overlay) {
                overlay.classList.remove('is-active');
                setTimeout(() => {
                    if (!overlay.classList.contains('is-active')) {
                        overlay.remove();
                    }
                }, 500);
            }
        }
    }"""

replace_in_file(js_path, search_js_def, replace_js_def)

search_js_call = """        // Sync flash announcement overlay
        syncFlashAnnouncement(settings);"""

replace_js_call = """        // Sync quick screen image overlay
        syncQuickScreen(settings);"""

replace_in_file(js_path, search_js_call, replace_js_call)
