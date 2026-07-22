import re

with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style.css', 'r', encoding='utf-8') as f:
    css = f.read()

# Remove Dark Mode rules
css = re.sub(r'\[data-theme=\'dark\'\]\s*\{[^}]*\}', '', css)
css = re.sub(r'\[data-theme=\'dark\'\][^{]*\{[^}]*\}', '', css)

# Remove Custom Scrollbar
css = re.sub(r'::-webkit-scrollbar[^{]*\{[^}]*\}', '', css)

# Remove Custom Cursor
css = re.sub(r'\.cursor-(dot|ring)[^{]*\{[^}]*\}', '', css)
css = re.sub(r'body:hover \.cursor-ring[^{]*\{[^}]*\}', '', css)

# Remove Scroll Progress
css = re.sub(r'\.scroll-progress[^{]*\{[^}]*\}', '', css)

# Remove Theme Toggle
css = re.sub(r'\.theme-toggle[^{]*\{[^}]*\}', '', css)

# Remove .header-scrolled styles
css = re.sub(r'header\.header-scrolled[^{]*\{[^}]*\}', '', css)

# Remove custom cursor from body
css = re.sub(r'cursor:\s*none;\s*/\* For custom cursor \*/', '', css)

# Simplify Google Fonts
css = css.replace('family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&family=Amiri:wght@400;700&display=swap', 'family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap')

# Remove complex CSS variables
css = re.sub(r'\s*--shadow-(sm|md|lg|xl)[^;]+;\n?', '', css)
css = re.sub(r'\s*--font-(sans|heading|arabic)[^;]+;\n?', '', css)
css = re.sub(r'\s*--radius-(sm|md|lg|xl|full)[^;]+;\n?', '', css)

# Replace variable usages with literals
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

# Remove multiple newlines
css = re.sub(r'\n{3,}', '\n\n', css)

# Count lines
lines = css.split('\n')
print(f"Reconstructed lines: {len(lines)}")

with open('c:/laragon/www/kauzariyya-musabaqa/intro-page/style.css', 'w', encoding='utf-8') as f:
    f.write(css)
