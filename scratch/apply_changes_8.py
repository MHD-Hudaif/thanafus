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

# Path to schedule.php
schedule_path = r"live-display/pages/schedule.php"

# 1. schedule-slide-container width & padding
search_container = """.schedule-slide-container {
    --first-team-color: <?= e($firstTeamColor) ?>;
    --current-neon: var(--first-team-color, #10b981);
    --panel-glow: color-mix(in srgb, var(--first-team-color, #10b981) 12%, transparent);
    width: 100%;
    max-width: 1600px;
    height: 100%;
    padding: 32px 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 2;
}"""

replace_container = """.schedule-slide-container {
    --first-team-color: <?= e($firstTeamColor) ?>;
    --current-neon: var(--first-team-color, #10b981);
    --panel-glow: color-mix(in srgb, var(--first-team-color, #10b981) 12%, transparent);
    width: 100%;
    max-width: 95vw;
    height: 100%;
    padding: 32px 0px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 2;
}"""

replace_in_file(schedule_path, search_container, replace_container)

# 2. schedule-slide-title
search_title = """.schedule-slide-title {
    font-size: 38px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.015em;
    color: #fff !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 18px;
    margin-bottom: 24px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}"""

replace_title = """.schedule-slide-title {
    font-size: 48px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.015em;
    color: #fff !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 24px;
    margin-bottom: 32px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}"""

replace_in_file(schedule_path, search_title, replace_title)

# 3. schedule-card columns & padding
search_card = """.schedule-card,
.schedule-card:nth-child(odd),
.schedule-card:nth-child(even) {
    position: relative;
    display: grid;
    grid-template-columns: 1.2fr 0.8fr 310px;
    align-items: center;
    background: #ffffff !important;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    border-bottom: 1px solid #f1f5f9 !important;
    padding: 24px 38px !important; /* Increased padding to make the table cards bigger */
    box-sizing: border-box;
    overflow: hidden;
    transition: all 0.25s ease;
}"""

replace_card = """.schedule-card,
.schedule-card:nth-child(odd),
.schedule-card:nth-child(even) {
    position: relative;
    display: grid;
    grid-template-columns: 1.2fr 0.8fr 430px;
    align-items: center;
    background: #ffffff !important;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    border-bottom: 1px solid #f1f5f9 !important;
    padding: 32px 54px !important; /* Increased padding to make the table cards bigger */
    box-sizing: border-box;
    overflow: hidden;
    transition: all 0.25s ease;
}"""

replace_in_file(schedule_path, search_card, replace_card)

# 4. schedule-index-inline size
search_index = """.schedule-index-inline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    font-size: 18px;
    font-weight: 900;
    font-family: 'Outfit', sans-serif;
    flex-shrink: 0;
}"""

replace_index = """.schedule-index-inline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    font-size: 24px;
    font-weight: 900;
    font-family: 'Outfit', sans-serif;
    flex-shrink: 0;
}"""

replace_in_file(schedule_path, search_index, replace_index)

# 5. schedule-program-title font-size
search_prog_title = """.schedule-program-title {
    font-size: 26px !important; /* Increased font-size for a bigger table */
    font-weight: 800;
    color: #0f172a !important; /* Dark text for light background */
    margin: 0;
    letter-spacing: -0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}"""

replace_prog_title = """.schedule-program-title {
    font-size: 32px !important; /* Increased font-size for a bigger table */
    font-weight: 800;
    color: #0f172a !important; /* Dark text for light background */
    margin: 0;
    letter-spacing: -0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}"""

replace_in_file(schedule_path, search_prog_title, replace_prog_title)

# 6. program-sec-tag padding & font-size
search_sec_tag = """.program-sec-tag {
    background: #f1f5f9;
    color: #475569;
    border: none;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px !important;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    width: fit-content;
    flex-shrink: 0;
}"""

replace_sec_tag = """.program-sec-tag {
    background: #f1f5f9;
    color: #475569;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 14px !important;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    width: fit-content;
    flex-shrink: 0;
}"""

replace_in_file(schedule_path, search_sec_tag, replace_sec_tag)

# 7. schedule-ranks-row gap
search_ranks_row = """.schedule-ranks-row {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    justify-content: center;
    align-items: center;
}"""

replace_ranks_row = """.schedule-ranks-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    align-items: center;
}"""

replace_in_file(schedule_path, search_ranks_row, replace_ranks_row)

# 8. rank-pill size & padding
search_pill = """.rank-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 11px;
    border-radius: 30px;
    font-size: 12px !important;
    font-weight: 900;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    /* Match the premium, dark-to-team-color treatment of leaderboard cards. */
    background: linear-gradient(135deg, #070c18 0%, color-mix(in srgb, var(--badge-team-color, #71717a) 42%, #03050a) 100%) !important;
    color: #fff !important;
    border: 1.5px solid color-mix(in srgb, var(--badge-team-color, #71717a) 78%, #ffffff) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .14), 0 0 12px color-mix(in srgb, var(--badge-team-color, #71717a) 42%, transparent), 0 4px 10px rgba(0, 0, 0, 0.3) !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
    white-space: nowrap;
}"""

replace_pill = """.rank-pill {
    display: inline-flex;
    align-items: center;
    padding: 8px 20px !important;
    border-radius: 30px;
    font-size: 18px !important;
    font-weight: 900;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    /* Match the premium, dark-to-team-color treatment of leaderboard cards. */
    background: linear-gradient(135deg, #070c18 0%, color-mix(in srgb, var(--badge-team-color, #71717a) 42%, #03050a) 100%) !important;
    color: #fff !important;
    border: 1.5px solid color-mix(in srgb, var(--badge-team-color, #71717a) 78%, #ffffff) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .14), 0 0 16px color-mix(in srgb, var(--badge-team-color, #71717a) 50%, transparent), 0 6px 14px rgba(0, 0, 0, 0.35) !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
    white-space: nowrap;
}"""

replace_in_file(schedule_path, search_pill, replace_pill)

# 9. schedule-time-label
search_time = """.schedule-time-label {
    font-size: 34px !important; /* Increased font-size for a bigger table */
    font-weight: 800;
    color: #0f172a !important; /* Dark text for light background */
    font-family: 'Plus Jakarta Sans', sans-serif;
    letter-spacing: -0.015em;
    white-space: nowrap !important;
}"""

replace_time = """.schedule-time-label {
    font-size: 40px !important; /* Increased font-size for a bigger table */
    font-weight: 800;
    color: #0f172a !important; /* Dark text for light background */
    font-family: 'Plus Jakarta Sans', sans-serif;
    letter-spacing: -0.015em;
    white-space: nowrap !important;
}"""

replace_in_file(schedule_path, search_time, replace_time)

# 10. program-day-tag
search_day = """.program-day-tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 800;
    background: #f1f5f9;
    color: #475569;
    padding: 4px 10px;
    border-radius: 6px;
    letter-spacing: 0.05em;
    width: fit-content;
    text-transform: uppercase;
}"""

replace_day = """.program-day-tag {
    display: inline-block;
    font-size: 14px;
    font-weight: 800;
    background: #f1f5f9;
    color: #475569;
    padding: 6px 12px;
    border-radius: 6px;
    letter-spacing: 0.05em;
    width: fit-content;
    text-transform: uppercase;
}"""

replace_in_file(schedule_path, search_day, replace_day)
