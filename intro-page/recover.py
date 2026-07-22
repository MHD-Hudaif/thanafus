import json, re

try:
    with open(r'C:\Users\hudai\.gemini\antigravity\brain\54b8eea1-6de4-4a5d-a016-68dc73a86aea\.system_generated\logs\transcript_full.jsonl', 'r', encoding='utf-8') as f:
        # Find the view_file tool calls
        view_file_outputs = []
        for line in f:
            if 'Total Lines: 1711' in line:
                view_file_outputs.append(json.loads(line))
        
    css_dict = {}
    for r in view_file_outputs:
        content = r.get('content', '')
        lines = content.split('\n')
        for l in lines:
            m = re.match(r'^(\d+): (.*)', l)
            if m:
                num = int(m.group(1))
                css_dict[num] = m.group(2)
            else:
                m2 = re.match(r'^(\d+):', l)
                if m2:
                    num = int(m2.group(1))
                    css_dict[num] = ''
    
    if css_dict:
        max_line = max(css_dict.keys())
        css_lines = [css_dict.get(i, '') for i in range(1, max_line + 1)]
        
        with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style_overhaul.css', 'w', encoding='utf-8') as out:
            out.write('\n'.join(css_lines))
        print(f'Recovered {len(css_lines)} lines of the overhaul file.')
    else:
        print('Not found in this transcript')
except Exception as e:
    print('Error:', e)
