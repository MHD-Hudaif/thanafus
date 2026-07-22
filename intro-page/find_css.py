import json, re

try:
    with open(r'C:\Users\hudai\.gemini\antigravity\brain\a46d1441-f6c1-494c-ba5d-c17dade173d1\.system_generated\logs\transcript_full.jsonl', 'r', encoding='utf-8') as f:
        res = [json.loads(line) for line in f if 'style.css' in line]
    
    for idx, r in enumerate(res):
        if 'tool_calls' in r:
            for tc in r['tool_calls']:
                args = tc.get('args', {})
                if 'CodeContent' in args:
                    content = args['CodeContent']
                    print(f"Write at idx {idx}: len {len(content)}, lines {len(content.split(chr(10)))}")
                elif 'ReplacementChunks' in args:
                    print(f"Multi-replace at idx {idx}")
                elif 'ReplacementContent' in args:
                    print(f"Replace at idx {idx}")
except Exception as e:
    print('Error:', e)
