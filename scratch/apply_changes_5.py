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

js_path = r"live-display/assets/js/live-display.js"

search_js = """    function syncBackdropVideo(teamColor) {
        if (teamColor) {
            document.documentElement.style.setProperty('--first-team-color', teamColor);
            const backdrop = document.querySelector('.tv-backdrop');
            if (backdrop) backdrop.style.setProperty('--first-team-color', teamColor);

            const rgb = hexToRgb(teamColor);
            windColor.targetR = rgb.r;
            windColor.targetG = rgb.g;
            windColor.targetB = rgb.b;
            updateParticleTargets(rgb);
        }
        const bgVideo = document.getElementById('tvBgVideo');
        if (!bgVideo) return;
        if (isLowEndDevice || true) {
            bgVideo.style.display = 'none';
            try { bgVideo.pause(); } catch (_) {}
            return;
        }
    }"""

replace_js = """    function syncBackdropVideo(teamColor) {
        if (teamColor) {
            document.documentElement.style.setProperty('--first-team-color', teamColor);
            const backdrop = document.querySelector('.tv-backdrop');
            if (backdrop) backdrop.style.setProperty('--first-team-color', teamColor);

            const rgb = hexToRgb(teamColor);
            windColor.targetR = rgb.r;
            windColor.targetG = rgb.g;
            windColor.targetB = rgb.b;
            updateParticleTargets(rgb);
        }
        const bgVideo = document.getElementById('tvBgVideo');
        if (!bgVideo) return;

        if (isLowEndDevice || state.performance_mode === 'performance') {
            bgVideo.style.display = 'none';
            try { bgVideo.pause(); } catch (_) {}
            return;
        }

        // Dynamically switch the background video source if it changed
        if (teamColor) {
            const videoSrc = getTeamVideoSrc(teamColor);
            const currentSrc = bgVideo.getAttribute('data-current-src') || '';
            if (currentSrc !== videoSrc) {
                bgVideo.setAttribute('data-current-src', videoSrc);
                bgVideo.src = videoSrc;
                try {
                    bgVideo.load();
                    if (state.activeSlide !== 'intro' && !window.isIntroSlideActive) {
                        bgVideo.play().catch(() => {});
                    }
                } catch (_) {}
            }
        }
    }"""

replace_in_file(js_path, search_js, replace_js)
