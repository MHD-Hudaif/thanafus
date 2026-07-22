import json, re

try:
    with open(r'C:\Users\hudai\.gemini\antigravity\brain\a46d1441-f6c1-494c-ba5d-c17dade173d1\.system_generated\logs\transcript_full.jsonl', 'r', encoding='utf-8') as f:
        res = [json.loads(line) for line in f if 'Total Lines: 1304' in line]
    
    if res:
        content = res[0]['content']
        lines = content.split('\n')
        css_lines = []
        for l in lines:
            m = re.match(r'^\d+: (.*)', l)
            if m:
                css_lines.append(m.group(1))
            elif re.match(r'^\d+:', l):
                css_lines.append('')
        
        with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style.css', 'w', encoding='utf-8') as out:
            out.write('\n'.join(css_lines))
        print(f'Wrote {len(css_lines)} lines')
    else:
        print('Not found in transcript')
except Exception as e:
    print('Error:', e)
