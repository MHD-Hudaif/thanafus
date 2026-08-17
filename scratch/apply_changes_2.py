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

# 1. Update live-display/includes/functions.php
functions_path = r"live-display/includes/functions.php"
search_1 = """    $stmt = $pdo->prepare("
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
        WHERE pe.program_id = ?
        ORDER BY
            COALESCE(pe.performance_order, pe.entry_number, pe.id) ASC,
            pe.id ASC
    ");
    $stmt->execute([$programId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];"""

replace_1 = """    $stmt = $pdo->prepare("
        SELECT
            pe.*,
            t.team_name,
            t.short_name,
            t.team_color,
            p.program_type,
            p.only_team_marks,
            p.title AS program_title,
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
        WHERE pe.program_id = ?
        ORDER BY
            COALESCE(pe.performance_order, pe.entry_number, pe.id) ASC,
            pe.id ASC
    ");
    $stmt->execute([$programId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        if (($row['program_type'] ?? '') === 'group' || !empty($row['only_team_marks'])) {
            $title = $row['program_title'] ?? '';
            $entryName = $row['entry_name'] ?? '';
            $cleanName = $entryName;
            if ($title !== '') {
                $cleanName = trim(str_ireplace($title . ' -', '', $cleanName));
                $cleanName = trim(str_ireplace($title . ' - ', '', $cleanName));
                $cleanName = trim(str_ireplace($title . '-', '', $cleanName));
                $cleanName = trim(str_ireplace($title, '', $cleanName));
                $cleanName = ltrim($cleanName, "- \\t\\n\\r\\0\\x0B");
            }
            $row['chest_number'] = $cleanName ?: $row['entry_name'];
        }
    }
    return $rows;"""

replace_in_file(functions_path, search_1, replace_1)

# 2. Update emcee/index.php
emcee_path = r"emcee/index.php"
search_2 = """        $chestDisplay = $isGroupProg ? ($eRow['entry_name'] ?: 'Team ' . ($partIdx + 1)) : ($eRow['chest_number'] ?: '-');"""
replace_2 = """        if ($isGroupProg) {
            $title = $prog['title'] ?? '';
            $chestDisplay = $eRow['entry_name'] ?? '';
            if ($title !== '') {
                $chestDisplay = trim(str_ireplace($title . ' -', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . ' - ', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title . '-', '', $chestDisplay));
                $chestDisplay = trim(str_ireplace($title, '', $chestDisplay));
                $chestDisplay = ltrim($chestDisplay, "- \\t\\n\\r\\0\\x0B");
            }
            if (empty($chestDisplay)) {
                $chestDisplay = 'Team ' . ($partIdx + 1);
            }
        } else {
            $chestDisplay = $eRow['chest_number'] ?: '-';
        }"""

replace_in_file(emcee_path, search_2, replace_2)

# 3. Update mobile-app/emcee/index.php
mobile_emcee_path = r"mobile-app/emcee/index.php"
replace_in_file(mobile_emcee_path, search_2, replace_2)
