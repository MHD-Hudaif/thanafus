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

css_path = r"live-display/assets/css/live-display.css"

search_css = """/* ==========================================================================
   CINEMATIC FLASH ANNOUNCEMENT OVERLAY
   ========================================================================== */
.flash-announcement-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: rgba(3, 8, 6, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.5s;
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    padding: 48px;
    box-sizing: border-box;
}

/* Light Theme version */
body.theme-light .flash-announcement-overlay {
    background: rgba(250, 246, 235, 0.96);
}

.flash-announcement-overlay.is-active {
    opacity: 1;
    visibility: visible;
}

.flash-announcement-box {
    width: 100%;
    max-width: 1200px;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
    border: 2px solid rgba(255, 255, 255, 0.08);
    border-radius: 40px;
    padding: 64px 80px;
    box-sizing: border-box;
    box-shadow: 0 40px 100px rgba(0, 0, 0, 0.7);
    text-align: center;
    transform: scale(0.9) translateY(30px);
    transition: transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    overflow: hidden;
}

body.theme-light .flash-announcement-box {
    background: rgba(255, 255, 255, 0.88);
    border: 2px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 40px 90px rgba(15, 23, 42, 0.06);
}

.flash-announcement-overlay.is-active .flash-announcement-box {
    transform: scale(1) translateY(0);
}

.flash-announcement-header {
    margin-bottom: 40px;
    display: flex;
    justify-content: center;
}

.flash-live-badge {
    background: rgba(245, 158, 11, 0.15);
    border: 2.5px solid rgba(245, 158, 11, 0.55);
    color: #f59e0b;
    padding: 10px 24px;
    border-radius: 30px;
    font-size: 16px;
    font-weight: 900;
    letter-spacing: 0.15em;
    box-shadow: 0 0 30px rgba(245, 158, 11, 0.25);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
}

body.theme-light .flash-live-badge {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.4);
    box-shadow: 0 0 20px rgba(245, 158, 11, 0.1);
    text-shadow: none;
}

.flash-announcement-content {
    font-size: 64px;
    line-height: 1.25;
    font-weight: 850;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: #fff;
    letter-spacing: -0.02em;
    margin-bottom: 48px;
    word-wrap: break-word;
}

body.theme-light .flash-announcement-content {
    color: #0f172a;
}

.flash-announcement-footer {
    display: flex;
    justify-content: center;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    padding-top: 32px;
}

body.theme-light .flash-announcement-footer {
    border-top-color: rgba(15, 23, 42, 0.08);
}

.tv-clock-inline {
    font-size: 28px;
    font-family: 'Outfit', 'Plus Jakarta Sans', monospace;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.65);
    letter-spacing: 0.05em;
}

body.theme-light .tv-clock-inline {
    color: #475569;
}"""

replace_css = """/* ==========================================================================
   CINEMATIC QUICK SCREEN IMAGE OVERLAY
   ========================================================================== */
.quick-screen-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: #000;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.5s ease, visibility 0.5s;
    overflow: hidden;
}

.quick-screen-overlay.is-active {
    opacity: 1;
    visibility: visible;
}

/* Background blurred cover image */
.quick-screen-blur-bg {
    position: absolute;
    inset: -20px;
    background-size: cover;
    background-position: center;
    filter: blur(40px) brightness(0.35);
    transform: scale(1.1);
}

/* Foreground Contain image */
.quick-screen-contain-img {
    position: absolute;
    inset: 0;
    background-size: contain;
    background-position: center;
    background-repeat: no-repeat;
    z-index: 2;
}"""

replace_in_file(css_path, search_css, replace_css)
