import os

filepath = r"admin/score-update/score-approval.php"
if os.path.exists(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # We replace the text directly ignoring spaces before and after
    search_str = "<td><strong><?= (int)$e['rank'] ?></strong></td>"
    replace_str = "<td><strong><?= $e['rank'] ? (int)$e['rank'] : '—' ?></strong></td>"
    
    if search_str in content:
        content = content.replace(search_str, replace_str)
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print("Successfully patched all occurrences!")
    else:
        print("Search string not found in raw content.")
else:
    print("File not found.")
