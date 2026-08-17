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

# 1. Update live-display/includes/functions.php
functions_path = r"live-display/includes/functions.php"
search_1 = """            if ($title !== '') {
                $cleanName = trim(str_ireplace($title . ' -', '', $cleanName));
                $cleanName = trim(str_ireplace($title . ' - ', '', $cleanName));
                $cleanName = trim(str_ireplace($title . '-', '', $cleanName));
                $cleanName = trim(str_ireplace($title, '', $cleanName));
                $cleanName = ltrim($cleanName, "- \\t\\n\\r\\0\\x0B");
            }
            $row['chest_number'] = $cleanName ?: $row['entry_name'];"""

replace_1 = """            if ($title !== '') {
                $cleanName = trim(str_ireplace($title . ' -', '', $cleanName));
                $cleanName = trim(str_ireplace($title . ' - ', '', $cleanName));
                $cleanName = trim(str_ireplace($title . '-', '', $cleanName));
                $cleanName = trim(str_ireplace($title, '', $cleanName));
                $cleanName = ltrim($cleanName, "- \\t\\n\\r\\0\\x0B");
            }
            $cleanName = preg_replace('/\\s*\\(\\d+\\)\\s*$/u', '', $cleanName);
            $row['chest_number'] = $cleanName ?: $row['entry_name'];"""

replace_in_file(functions_path, search_1, replace_1)

# 2. Update emcee/index.php
emcee_path = r"emcee/index.php"
search_2 = """            if ($title !== '') {
                $chestDisplay = trim(str_ireplace($title . ' -', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . ' - ', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . '-', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title, '', $chestDisplay));
                $chestDisplay = ltrim($chestDisplay, "- \\t\\n\\r\\0\\x0B");
            }
            if (empty($chestDisplay)) {
                $chestDisplay = 'Team ' . ($partIdx + 1);
            }"""

replace_2 = """            if ($title !== '') {
                $chestDisplay = trim(str_ireplace($title . ' -', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . ' - ', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . '-', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title, '', $chestDisplay));
                $chestDisplay = ltrim($chestDisplay, "- \\t\\n\\r\\0\\x0B");
            }
            $chestDisplay = preg_replace('/\\s*\\(\\d+\\)\\s*$/u', '', $chestDisplay);
            if (empty($chestDisplay)) {
                $chestDisplay = 'Team ' . ($partIdx + 1);
            }"""

replace_in_file(emcee_path, search_2, replace_2)

# 3. Update mobile-app/emcee/index.php
mobile_emcee_path = r"mobile-app/emcee/index.php"
replace_in_file(mobile_emcee_path, search_2, replace_2)
