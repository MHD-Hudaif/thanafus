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

api_path = r"live-display/api/settings.php"
search_api = """        } elseif ($action === 'flash_announcement') {
            $settings['flash_announcement'] = trim((string)($_POST['message'] ?? ''));
            $settings['flash_announcement_enabled'] = (bool)(int)($_POST['enabled'] ?? 0);
        } else {"""

replace_api = """        } elseif ($action === 'quick_screen') {
            $settings['quick_screen_image'] = trim((string)($_POST['image'] ?? ''));
            $settings['quick_screen_enabled'] = (bool)(int)($_POST['enabled'] ?? 0);
        } else {"""

replace_in_file(api_path, search_api, replace_api)
