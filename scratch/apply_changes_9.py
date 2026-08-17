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

css_path = r"live-display/assets/css/live-display.css"

search_light = """/* ==========================================================================
   LIGHT THEME OVERRIDES FOR WHITE BACKDROP & SLOW WIND FLOW
   ========================================================================== */
html, body {
  background: #faf6eb !important; /* Premium soft cream background */
}

.tv-backdrop {
  background: radial-gradient(circle at center, #ffffff 0%, #fdfcf7 60%, #faf6eb 100%) !important; /* White-to-cream gradient */
}

.tv-backdrop .stage-backdrop .glow-orb {
  display: none !important;
}

#tvBgVideo {
  display: none !important;
}

.tv-noise {
  background-image: 
      linear-gradient(rgba(15, 23, 42, 0.012) 1px, transparent 1px),
      linear-gradient(90deg, rgba(15, 23, 42, 0.012) 1px, transparent 1px) !important;
  opacity: 0.8 !important;
}

/* Page Header Titles legibility overrides */
.leaderboard-title,
.schedule-slide-title {
  color: #936a0d !important; /* Rich golden text */
}

.page-count-badge {
  color: #0f172a !important;
  background: rgba(15, 23, 42, 0.05) !important;
  border-color: rgba(15, 23, 42, 0.08) !important;
}

.tv-brand-title {
  color: #0f172a !important;
}

.tv-brand-kicker {
  color: #64748b !important;
}

.tv-clock {
  color: #0f172a !important;
  border-color: rgba(15, 23, 42, 0.08) !important;
  background: rgba(255, 255, 255, 0.7) !important;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04) !important;
}"""

replace_light = """/* ==========================================================================
   LIGHT THEME OVERRIDES FOR WHITE BACKDROP & SLOW WIND FLOW
   ========================================================================== */
body.theme-light,
body.theme-light.tv-current-programs-theme {
  background: #faf6eb !important; /* Premium soft cream background */
}

body.theme-light .tv-backdrop {
  background: radial-gradient(circle at center, #ffffff 0%, #fdfcf7 60%, #faf6eb 100%) !important; /* White-to-cream gradient */
}

body.theme-light .stage-backdrop {
  display: none !important;
}

body.theme-light .tv-backdrop .stage-backdrop .glow-orb {
  display: none !important;
}

body.theme-light #tvBgVideo {
  display: none !important;
}

body.theme-light .tv-noise {
  background-image: 
      linear-gradient(rgba(15, 23, 42, 0.012) 1px, transparent 1px),
      linear-gradient(90deg, rgba(15, 23, 42, 0.012) 1px, transparent 1px) !important;
  opacity: 0.8 !important;
}

/* Page Header Titles legibility overrides */
body.theme-light .leaderboard-title,
body.theme-light .schedule-slide-title {
  color: #936a0d !important; /* Rich golden text */
}

body.theme-light .page-count-badge {
  color: #0f172a !important;
  background: rgba(15, 23, 42, 0.05) !important;
  border-color: rgba(15, 23, 42, 0.08) !important;
}

body.theme-light .tv-brand-title {
  color: #0f172a !important;
}

body.theme-light .tv-brand-kicker {
  color: #64748b !important;
}

body.theme-light .tv-clock {
  color: #0f172a !important;
  border-color: rgba(15, 23, 42, 0.08) !important;
  background: rgba(255, 255, 255, 0.7) !important;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04) !important;
}

/* Current Program Slide & General Glass Panel overrides */
body.theme-light .glass-panel,
body.theme-light .now-performing-card,
body.theme-light .side-card-top,
body.theme-light .side-card-bottom {
  background: rgba(255, 255, 255, 0.82) !important;
  backdrop-filter: blur(30px) !important;
  -webkit-backdrop-filter: blur(30px) !important;
  border: 1.5px solid rgba(15, 23, 42, 0.08) !important;
  box-shadow: 0 25px 60px rgba(15, 23, 42, 0.04), inset 0 1px 0 rgba(255, 255, 255, 0.6) !important;
  color: #0f172a !important;
}

body.theme-light .program-title-display {
  color: #0f172a !important;
  text-shadow: none !important;
}

body.theme-light .stage-awaiting-title {
  color: #0f172a !important;
}

body.theme-light .stage-awaiting-sub {
  color: #475569 !important;
}

body.theme-light .stage-awaiting-icon {
  background: rgba(15, 23, 42, 0.04) !important;
  color: #0f172a !important;
  border: 1px solid rgba(15, 23, 42, 0.08) !important;
}

body.theme-light .side-box-label {
  color: #64748b !important;
}

body.theme-light .next-prog-title {
  color: #0f172a !important;
}

body.theme-light .next-prog-time-badge {
  background: rgba(15, 23, 42, 0.04) !important;
  color: #475569 !important;
  border: 1px solid rgba(15, 23, 42, 0.08) !important;
}

body.theme-light .stat-widget-box {
  background: rgba(15, 23, 42, 0.03) !important;
  border: 1px solid rgba(15, 23, 42, 0.05) !important;
}

body.theme-light .stat-widget-icon {
  color: #64748b !important;
}

body.theme-light .stat-widget-label {
  color: #64748b !important;
}

body.theme-light .stat-widget-value {
  color: #0f172a !important;
}

body.theme-light .program-stage-header-bar {
  border-bottom: 1px solid rgba(15, 23, 42, 0.08) !important;
}

body.theme-light .program-stage-header-bar span[style*="rgba(255,255,255,0.65)"] {
  color: #64748b !important;
}"""

replace_in_file(css_path, search_light, replace_light)
