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

search_js = """            const imageName = settings.quick_screen_image;
            const base = (window.APP_CONFIG?.baseUrl || '').replace(/\/$/, '');
            const imageUrl = `${base}/uploads/quick-screens/${imageName}`;"""

replace_js = """            const imageName = settings.quick_screen_image;
            const bootstrapUrl = window.TV_BOOT?.api?.bootstrap || '';
            const imageUrl = bootstrapUrl.replace('/live-display/api/settings.php', `/uploads/quick-screens/${imageName}`);"""

replace_in_file(js_path, search_js, replace_js)
