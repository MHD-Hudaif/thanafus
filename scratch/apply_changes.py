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
        # We perform the replace on normalized and put CRLF back if they were present
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
search_1 = """    $stmt = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.short_name,
            t.team_color,
            COALESCE(
                (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                 FROM musabaqa_entry_members em
                 JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                 WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> ''),
                pe.entry_number
            ) AS chest_number
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        WHERE pe.program_id = ?"""

replace_1 = """    $stmt = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.short_name,
            t.team_color,
            p.program_type,
            p.only_team_marks,
            CASE 
                WHEN p.program_type = 'group' OR p.only_team_marks = 1 THEN pe.entry_name
                ELSE COALESCE(
                    (SELECT GROUP_CONCAT(tm.chest_number SEPARATOR ', ')
                     FROM musabaqa_entry_members em
                     JOIN musabaqa_team_members tm ON tm.id = em.team_member_id
                     WHERE em.entry_id = pe.id AND tm.chest_number IS NOT NULL AND tm.chest_number <> ''),
                    pe.entry_number
                )
            END AS chest_number
        FROM musabaqa_program_entries pe
        JOIN musabaqa_teams t ON t.id = pe.team_id
        JOIN musabaqa_programs p ON p.id = pe.program_id
        WHERE pe.program_id = ?"""

replace_in_file(functions_path, search_1, replace_1)

# 2. Update emcee/index.php
emcee_path = r"emcee/index.php"
replace_in_file(emcee_path, 
    "        $chestDisplay = $isGroupProg ? ($eRow['team_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['chest_number'] ?: '-');",
    "        $chestDisplay = $isGroupProg ? ($eRow['entry_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['chest_number'] ?: '-');"
)
replace_in_file(emcee_path,
    """        textChestHeader.innerText = 'STAGE GROUP TEAM';
        textChestNum.innerText = item.team_name;""",
    """        textChestHeader.innerText = 'GROUP NAME';
        textChestNum.innerText = item.chest_number;"""
)
replace_in_file(emcee_path,
    "        let subDisplay = item.is_group ? 'Team Performance' : escapeHtml(item.entry_name);",
    "        let subDisplay = item.is_group ? escapeHtml(item.team_name) : escapeHtml(item.entry_name);"
)

# 3. Update mobile-app/emcee/index.php
mobile_emcee_path = r"mobile-app/emcee/index.php"
replace_in_file(mobile_emcee_path, 
    "        $chestDisplay = $isGroupProg ? ($eRow['team_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['chest_number'] ?: '-');",
    "        $chestDisplay = $isGroupProg ? ($eRow['entry_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['chest_number'] ?: '-');"
)
replace_in_file(mobile_emcee_path,
    """        textChestHeader.innerText = 'STAGE GROUP TEAM';
        textChestNum.innerText = item.team_name;""",
    """        textChestHeader.innerText = 'GROUP NAME';
        textChestNum.innerText = item.chest_number;"""
)
replace_in_file(mobile_emcee_path,
    "        let subDisplay = item.is_group ? 'Team Performance' : escapeHtml(item.entry_name);",
    "        let subDisplay = item.is_group ? escapeHtml(item.team_name) : escapeHtml(item.entry_name);"
)

# 4. Update live-display/pages/current-programs.php
current_prog_path = r"live-display/pages/current-programs.php"
replace_in_file(current_prog_path,
    """$initChest = !empty($initPerf['chest_number']) ? $initPerf['chest_number'] : (!empty($initPerf['number']) ? $initPerf['number'] : '—');
$initHasChest = $initChest !== '—';""",
    """$isGroupProg = (($initProg['program_type'] ?? '') === 'group' || !empty($initProg['only_team_marks']));
if ($isGroupProg) {
    $initChest = !empty($initPerf['entry_name']) ? $initPerf['entry_name'] : '—';
} else {
    $initChest = !empty($initPerf['chest_number']) ? $initPerf['chest_number'] : (!empty($initPerf['number']) ? $initPerf['number'] : '—');
}
$initHasChest = $initChest !== '—';"""
)
replace_in_file(current_prog_path,
    '                                <span class="label">CHEST NUMBER</span>',
    '                                <span class="label" data-chest-label><?= $isGroupProg ? \'GROUP NAME\' : \'CHEST NUMBER\' ?></span>'
)
replace_in_file(current_prog_path,
    "                    if (chestEl && hasChest) chestEl.textContent = chestValue;",
    """                    if (chestEl && hasChest) chestEl.textContent = chestValue;
                    const labelEl = document.querySelector('[data-chest-label]');
                    if (labelEl) {
                        const isGroup = (prog.program_type === 'group' || !!prog.only_team_marks);
                        labelEl.textContent = isGroup ? 'GROUP NAME' : 'CHEST NUMBER';
                    }"""
)
