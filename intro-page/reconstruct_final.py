import re

with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style.css', 'r', encoding='utf-8') as f:
    css = f.read()

# Strip out dark mode completely
css = re.sub(r'\[data-theme=[\'"]dark[\'"]\][^{]*\{[^}]*\}', '', css)
css = re.sub(r'@media\s*\(prefers-color-scheme:\s*dark\)[^{]*\{([^}]*\{[^}]*\})*[^}]*\}', '', css)

# Strip out specific features
css = re.sub(r'::-webkit-scrollbar[^{]*\{[^}]*\}', '', css)
css = re.sub(r'\.cursor-(dot|ring)[^{]*\{[^}]*\}', '', css)
css = re.sub(r'body:hover \.cursor-ring[^{]*\{[^}]*\}', '', css)
css = re.sub(r'\.scroll-progress[^{]*\{[^}]*\}', '', css)
css = re.sub(r'\.theme-toggle[^{]*\{[^}]*\}', '', css)
css = re.sub(r'header\.header-scrolled[^{]*\{[^}]*\}', '', css)
css = re.sub(r'\.hamburger-btn[^{]*\{[^}]*\}', '', css)
css = re.sub(r'\.typewriter-cursor[^{]*\{[^}]*\}', '', css)

# Remove any cursor property that sets 'none' for custom cursor
css = re.sub(r'cursor:\s*none;\s*/\* For custom cursor \*/', '', css)

# Replace fonts in import
css = css.replace('family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&family=Amiri:wght@400;700&display=swap', 'family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap')

# Variables to simplify
css = re.sub(r'\s*--shadow-(sm|md|lg|xl)[^;]+;\n?', '', css)
css = re.sub(r'\s*--font-(sans|heading|arabic)[^;]+;\n?', '', css)
css = re.sub(r'\s*--radius-(sm|md|lg|xl|full)[^;]+;\n?', '', css)

# Replace usages
css = css.replace('var(--font-sans)', "'Inter', sans-serif")
css = css.replace('var(--font-heading)', "'Outfit', sans-serif")
css = css.replace('var(--font-arabic)', "'Amiri', serif")
css = css.replace('var(--radius-sm)', '8px')
css = css.replace('var(--radius-md)', '12px')
css = css.replace('var(--radius-lg)', '16px')
css = css.replace('var(--radius-xl)', '24px')
css = css.replace('var(--radius-full)', '9999px')
css = css.replace('var(--shadow-sm)', '0 1px 2px 0 rgba(0, 0, 0, 0.05)')
css = css.replace('var(--shadow-md)', '0 4px 6px -1px rgba(0, 0, 0, 0.1)')
css = css.replace('var(--shadow-lg)', '0 10px 15px -3px rgba(0, 0, 0, 0.1)')
css = css.replace('var(--shadow-xl)', '0 20px 25px -5px rgba(0, 0, 0, 0.1)')

# Remove media queries
new_css = []
in_media_to_remove = False
brace_level = 0
for line in css.split('\n'):
    if in_media_to_remove:
        brace_level += line.count('{') - line.count('}')
        if brace_level <= 0:
            in_media_to_remove = False
            brace_level = 0
        continue
    
    if '@media' in line and ('1200px' in line or '768px' in line or '480px' in line):
        in_media_to_remove = True
        brace_level = line.count('{') - line.count('}')
        continue
        
    new_css.append(line)

css = '\n'.join(new_css)
css = re.sub(r'\n{3,}', '\n\n', css)

with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style.css', 'w', encoding='utf-8') as f:
    f.write(css)
    
print(f"Reconstructed with {len(css.split(chr(10)))} lines")
