import json, re

try:
    with open(r'C:\Users\hudai\.gemini\antigravity\brain\a46d1441-f6c1-494c-ba5d-c17dade173d1\.system_generated\logs\transcript_full.jsonl', 'r', encoding='utf-8') as f:
        res = [json.loads(line) for line in f if 'Total Lines: 1304' in line]
    
    css_dict = {}
    for r in res:
        content = r.get('content', '')
        lines = content.split('\n')
        for l in lines:
            # Match "<line_number>: <content>" or "<line_number>:"
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
        
        with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style.css', 'w', encoding='utf-8') as out:
            out.write('\n'.join(css_lines))
        print(f'Wrote {len(css_lines)} lines, missing: {sum(1 for i in range(1, max_line+1) if i not in css_dict)}')
    else:
        print('Not found in transcript')
except Exception as e:
    print('Error:', e)
