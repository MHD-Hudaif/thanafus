import os

def replace_in_file(filepath, search_str, replace_str):
    if not os.path.exists(filepath):
        print(f"File not found: {filepath}")
        return False
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Normalize line endings for replacement search
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

# 1. Update live-display/assets/css/live-display.css
css_path = r"live-display/assets/css/live-display.css"
search_css = "    background: radial-gradient(circle at 20% 30%, color-mix(in srgb, var(--first-team-color, #10b981) 35%, #022c22) 0%, color-mix(in srgb, var(--first-team-color, #10b981) 18%, #022c22) 45%, #021a14 80%, #010d0a 100%);"
replace_css = "    background: radial-gradient(circle at 20% 30%, color-mix(in srgb, var(--first-team-color, #10b981) 35%, #0c1220) 0%, color-mix(in srgb, var(--first-team-color, #10b981) 18%, #0c1220) 45%, #05080f 80%, #020306 100%);"
replace_in_file(css_path, search_css, replace_css)

# 2. Update live-display/assets/js/live-display.js
js_path = r"live-display/assets/js/live-display.js"
search_js = """    function initFlowCanvas() {
        const canvas = document.getElementById('flowCanvas');
        if (!canvas) return;
        if (isLowEndDevice || state.performance_mode === 'performance') {"""

replace_js = """    function initFlowCanvas() {
        const canvas = document.getElementById('flowCanvas');
        if (!canvas) return;

        // Initialize windColor with first team color from CSS variables if available
        const computedStyle = getComputedStyle(document.documentElement);
        const cssFirstColor = computedStyle.getPropertyValue('--first-team-color') || computedStyle.getPropertyValue('--top-team-color');
        if (cssFirstColor && cssFirstColor.trim()) {
            const rgb = hexToRgb(cssFirstColor.trim());
            if (rgb) {
                windColor = { r: rgb.r, g: rgb.g, b: rgb.b, targetR: rgb.r, targetG: rgb.g, targetB: rgb.b };
            }
        }

        if (isLowEndDevice || state.performance_mode === 'performance') {"""

replace_in_file(js_path, search_js, replace_js)
