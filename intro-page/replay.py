import json

file_content = ""

try:
    with open(r'C:\Users\hudai\.gemini\antigravity\brain\a46d1441-f6c1-494c-ba5d-c17dade173d1\.system_generated\logs\transcript_full.jsonl', 'r', encoding='utf-8') as f:
        for line in f:
            if 'style.css' not in line:
                continue
            
            data = json.loads(line)
            if 'tool_calls' in data:
                for tc in data['tool_calls']:
                    args = tc.get('args', {})
                    target_file = args.get('TargetFile', '')
                    if 'style.css' in target_file:
                        if tc['name'] == 'write_to_file':
                            if str(args.get('Overwrite', '')).lower() == 'true' or file_content == "":
                                file_content = args.get('CodeContent', '')
                            else:
                                file_content += args.get('CodeContent', '')
                        elif tc['name'] == 'replace_file_content':
                            target = args.get('TargetContent', '')
                            replacement = args.get('ReplacementContent', '')
                            file_content = file_content.replace(target, replacement)
                        elif tc['name'] == 'multi_replace_file_content':
                            chunks = args.get('ReplacementChunks', [])
                            for chunk in chunks:
                                target = chunk.get('TargetContent', '')
                                replacement = chunk.get('ReplacementContent', '')
                                file_content = file_content.replace(target, replacement)
                                
            # Stop if we reach the overhaul step where the size jumps to 20k+
            if len(file_content) > 25000 and len(file_content.split('\n')) == 1304:
                # We reached exactly 1304 lines!
                pass

    with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style_replayed.css', 'w', encoding='utf-8') as out:
        out.write(file_content)
        
    lines = file_content.split('\n')
    print(f'Replayed CSS: {len(lines)} lines, {len(file_content)} bytes.')
    
except Exception as e:
    print('Error:', e)
