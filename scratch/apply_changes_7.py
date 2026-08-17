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

# Update admin/score-update/score-approval.php
approval_path = r"admin/score-update/score-approval.php"
search_1 = """            $teamPointsConfig = [];
            if (!empty($program['team_points_config'])) {
                $teamPointsConfig = json_decode($program['team_points_config'], true) ?: [];
            }
            $pointConfig = [];
            $pointConfig[1] = isset($teamPointsConfig[1]) ? (int)$teamPointsConfig[1] : $firstPoints;
            $pointConfig[2] = isset($teamPointsConfig[2]) ? (int)$teamPointsConfig[2] : $secondPoints;
            $pointConfig[3] = isset($teamPointsConfig[3]) ? (int)$teamPointsConfig[3] : $thirdPoints;
            foreach ($teamPointsConfig as $r => $pts) {
                $pointConfig[(int)$r] = (int)$pts;
            }"""

replace_1 = """            $teamPointsConfig = null;
            if (!empty($program['team_points_config'])) {
                $teamPointsConfig = json_decode($program['team_points_config'], true);
            }
            $pointConfig = [];
            if (is_array($teamPointsConfig)) {
                foreach ($teamPointsConfig as $r => $pts) {
                    $pointConfig[(int)$r] = (int)$pts;
                }
            } else {
                $pointConfig[1] = $firstPoints;
                $pointConfig[2] = $secondPoints;
                $pointConfig[3] = $thirdPoints;
            }"""

replace_in_file(approval_path, search_1, replace_1)
