/* global gsap, particlesJS, TV_BOOT */
(() => {
    'use strict';

    const state = {
        activeSlide: 'intro',
        is_playing: true,
        mode: 'auto',
        theme: 'emerald',
        slides: {},
        lastCelebrationId: null,
        isCelebrating: false,
        leaderboardData: null,
        slideOrder: ['intro', 'leaderboard', 'schedule', 'current-program'],
        timers: {
            slide: null,
            clock: null,
            refresh: null
        }
    };

    const els = {
        body: document.body,
        clock: document.getElementById('tvClock'),
        tvApp: document.getElementById('tvApp'),
        tvStage: document.getElementById('tvStage'),
        emergency: document.querySelector('[data-emergency]'),
        emergencyMsg: document.querySelector('[data-emergency-message]'),
        celebration: document.querySelector('[data-celebration]'),
        celebrationTitle: document.querySelector('[data-celebration-title]'),
        celebrationTeam: document.querySelector('[data-celebration-team]'),
        celebrationScore: document.querySelector('[data-celebration-score]'),

        // Intro Slide Elements
        introVideo: document.querySelector('[data-intro-video]'),
        introTitle: document.querySelector('[data-intro-title]'),
        introSubtitle: document.querySelector('[data-intro-subtitle]'),

        // Leaderboard Slide Elements
        leaderboard: document.querySelector('[data-leaderboard]'),
        leaderboardUpdated: document.querySelector('[data-leaderboard-updated]'),

        // Schedule Slide Elements
        schedule: document.querySelector('[data-schedule]'),

        // Current Program Slide Elements
        currentStage: document.querySelector('[data-current-stage]'),
        currentTitle: document.querySelector('[data-current-title]'),
        currentPerformer: document.querySelector('[data-current-performer]'),
        currentTeam: document.querySelector('[data-current-team]'),
        currentCategory: document.querySelector('[data-current-category]'),
        currentStatus: document.querySelector('[data-current-status]'),
        currentRoom: document.querySelector('[data-current-room]'),
        nextPerformer: document.querySelector('[data-next-performer]'),
        nextTeam: document.querySelector('[data-next-team]'),
        judges: document.querySelector('[data-judges]'),
        nextProgram: document.querySelector('[data-next-program]')
    };

    const slideEls = Array.from(document.querySelectorAll('.tv-slide'));

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function colorDot(color) {
        if (!color) return '<span class="tv-team-dot"></span>';
        return `<span class="tv-team-dot" style="background:${escapeHtml(color)}; box-shadow:0 0 0 4px color-mix(in srgb, ${escapeHtml(color)} 18%, transparent);"></span>`;
    }

    function formatScore(score) {
        return String(Math.round(Number(score || 0)));
    }

    function startClock() {
        const update = () => {
            const now = new Date();
            const time = now.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
            if (els.clock) els.clock.textContent = time;
        };

        update();
        state.timers.clock = setInterval(update, 1000);
    }

    function initParticles() {
        if (typeof particlesJS !== 'undefined' && document.getElementById('particles-js')) {
            try {
                particlesJS('particles-js', {
                    "particles": {
                        "number": {
                            "value": 45,
                            "density": {
                                "enable": true,
                                "value_area": 800
                            }
                        },
                        "color": {
                            "value": "#ffffff"
                        },
                        "shape": {
                            "type": ["edge", "triangle"]
                        },
                        "opacity": {
                            "value": 0.12,
                            "random": true,
                            "anim": {
                                "enable": true,
                                "speed": 0.8,
                                "opacity_min": 0.04,
                                "sync": false
                            }
                        },
                        "size": {
                            "value": 3.5,
                            "random": true,
                            "anim": {
                                "enable": true,
                                "speed": 1.5,
                                "size_min": 0.1,
                                "sync": false
                            }
                        },
                        "line_linked": {
                            "enable": true,
                            "distance": 140,
                            "color": "#ffffff",
                            "opacity": 0.05,
                            "width": 1
                        },
                        "move": {
                            "enable": true,
                            "speed": 1.0,
                            "direction": "none",
                            "random": true,
                            "straight": false,
                            "out_mode": "out",
                            "bounce": false,
                            "attract": {
                                "enable": false,
                                "rotateX": 600,
                                "rotateY": 1200
                            }
                        }
                    },
                    "interactivity": {
                        "detect_on": "canvas",
                        "events": {
                            "onhover": { "enable": false },
                            "onclick": { "enable": false },
                            "resize": true
                        }
                    },
                    "retina_detect": true
                });
            } catch (err) {
                console.error("particlesJS error:", err);
            }
        }
    }

    function setActiveSlide(name) {
        if (state.activeSlide === name && slideEls.some(s => s.classList.contains('tv-slide--active') && s.dataset.slide === name)) {
            return;
        }

        state.activeSlide = name;
        slideEls.forEach((slide) => {
            const isActive = slide.dataset.slide === name;
            slide.classList.toggle('tv-slide--active', isActive);
        });

        if (name === 'intro') {
            if (els.introVideo) {
                try {
                    els.introVideo.currentTime = 0;
                    els.introVideo.play().catch(() => {});
                } catch (_) {}
            }
            return;
        }

        animateEntrance(`#slide-${name}`);
    }

    function animateEntrance(scope) {
        const root = document.querySelector(scope);
        if (!root || typeof gsap === 'undefined') return;

        gsap.killTweensOf(root.querySelectorAll('*'));

        // Custom sliding animations for the Leaderboard slide
        if (scope === '#slide-leaderboard') {
            // Whole leaderboard slide entrance
            gsap.fromTo(root, {
                opacity: 0,
                scale: 0.95,
                filter: 'blur(16px)',
                y: 60
            }, {
                opacity: 1,
                scale: 1,
                filter: 'blur(0px)',
                y: 0,
                duration: 0.9,
                ease: 'power2.out',
                clearProps: 'transform, opacity, filter',
                onComplete: () => {
                    triggerLeaderboardAnimations();
                }
            });

            // Card elements slide left-to-right on entrance
            const cards = root.querySelectorAll('.tv-chart-row');
            if (cards.length > 0) {
                gsap.fromTo(cards, {
                    opacity: 0,
                    scale: 0.82,
                    x: -160,
                    filter: 'blur(20px)'
                }, {
                    opacity: 1,
                    scale: 1,
                    x: 0,
                    filter: 'blur(0px)',
                    duration: 1.1,
                    stagger: 0.12,
                    ease: 'power4.out',
                    clearProps: 'transform, opacity, filter'
                });
            }
            return;
        }

        // Default layout slide transitions
        const items = root.querySelectorAll('.tv-card-rank, .tv-item, .tv-panel, .tv-now-main, .tv-now-side > *');
        gsap.fromTo(items, {
            opacity: 0,
            y: 20
        }, {
            opacity: 1,
            y: 0,
            duration: 0.6,
            stagger: 0.04,
            ease: 'power2.out'
        });
    }

    function hexToRgb(hex) {
        if (!hex) return { r: 255, g: 255, b: 255 };
        const h = hex.replace(/^#/, '');
        let r = 0, g = 0, b = 0;
        if (h.length === 3) {
            r = parseInt(h.charAt(0) + h.charAt(0), 16);
            g = parseInt(h.charAt(1) + h.charAt(1), 16);
            b = parseInt(h.charAt(2) + h.charAt(2), 16);
        } else if (h.length === 6) {
            r = parseInt(h.slice(0, 2), 16);
            g = parseInt(h.slice(2, 4), 16);
            b = parseInt(h.slice(4, 6), 16);
        }
        return { r, g, b };
    }

    function rgbaColor(hex, alpha) {
        const rgb = hexToRgb(hex);
        return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
    }

    function getTeamTheme(colorHex) {
        const rgb = hexToRgb(colorHex || '#ffffff');
        const r = rgb.r / 255;
        const g = rgb.g / 255;
        const b = rgb.b / 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        let h = 0, s = 0, l = (max + min) / 2;

        if (max !== min) {
            const d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                case b: h = (r - g) / d + 4; break;
            }
            h /= 6;
        }
        
        const hueDegrees = h * 360;
        
        if (s < 0.15 || l > 0.85) {
            return { key: 'frost', icon: '❄️', name: 'Frost' };
        }
        
        if (hueDegrees >= 45 && hueDegrees < 85) {
            return { key: 'lightning', icon: '⚡', name: 'Lightning' };
        } else if (hueDegrees >= 165 && hueDegrees < 280) {
            return { key: 'water', icon: '🌊', name: 'Water' };
        } else if (hueDegrees >= 85 && hueDegrees < 165) {
            return { key: 'frost', icon: '❄️', name: 'Frost' };
        } else {
            return { key: 'fire', icon: '🔥', name: 'Fire' };
        }
    }

    function triggerGoldenConfetti(container) {
        const canvas = container.querySelector('.confetti-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        canvas.width = container.clientWidth;
        canvas.height = container.clientHeight;
        
        const colors = ['#ffd700', '#f1c40f', '#e67e22', '#ffffff'];
        const particles = [];
        for (let i = 0; i < 200; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: Math.random() * -canvas.height,
                r: Math.random() * 6 + 4,
                d: Math.random() * canvas.height,
                color: colors[Math.floor(Math.random() * colors.length)],
                tilt: Math.random() * 10 - 5,
                tiltAngleIncremental: Math.random() * 0.07 + 0.02,
                tiltAngle: 0
            });
        }
        
        let active = true;
        
        function draw() {
            if (!active) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            particles.forEach((p, idx) => {
                p.tiltAngle += p.tiltAngleIncremental;
                p.y += (Math.cos(p.d) + 3 + p.r / 2) / 2;
                p.x += Math.sin(p.tiltAngle);
                p.tilt = Math.sin(p.tiltAngle - (idx / 3)) * 15;
                
                ctx.beginPath();
                ctx.lineWidth = p.r;
                ctx.strokeStyle = p.color;
                ctx.moveTo(p.x + p.tilt + p.r / 2, p.y);
                ctx.lineTo(p.x + p.tilt, p.y + p.tilt + p.r / 2);
                ctx.stroke();
            });
            
            update();
            requestAnimationFrame(draw);
        }
        
        function update() {
            let remaining = 0;
            particles.forEach(p => {
                if (p.y < canvas.height) {
                    remaining++;
                }
            });
            if (remaining === 0) {
                active = false;
            }
        }
        
        draw();
    }

    function renderLeaderboard(rows, completionPercent = 0) {
        state.leaderboardData = rows;
        
        // 1. Dynamic background based on 1st place team color
        if (rows.length > 0 && typeof gsap !== 'undefined') {
            const firstColor = rows[0].team_color || '#00ff88';
            const rgb = hexToRgb(firstColor);
            const rootStyle = getComputedStyle(document.documentElement);
            const currentR = parseInt(rootStyle.getPropertyValue('--dynamic-r')) || 0;
            const currentG = parseInt(rootStyle.getPropertyValue('--dynamic-g')) || 255;
            const currentB = parseInt(rootStyle.getPropertyValue('--dynamic-b')) || 136;
            
            const colorObj = { r: currentR, g: currentG, b: currentB };
            gsap.to(colorObj, {
                r: rgb.r,
                g: rgb.g,
                b: rgb.b,
                duration: 1.8,
                ease: 'power3.inOut',
                onUpdate: () => {
                    document.documentElement.style.setProperty('--dynamic-r', Math.round(colorObj.r));
                    document.documentElement.style.setProperty('--dynamic-g', Math.round(colorObj.g));
                    document.documentElement.style.setProperty('--dynamic-b', Math.round(colorObj.b));
                }
            });
        }

        const container = document.querySelector('[data-leaderboard]');
        if (!container) return;

        const slots = Array.from({ length: 4 }, (_, index) => rows[index] || null);
        const maxScore = Math.max(...slots.map(t => t ? Number(t.total_score || 0) : 0), 10);

        container.className = 'tv-floating-leaderboard';
        container.innerHTML = `
            <canvas class="confetti-canvas"></canvas>
            <div class="tv-floating-card-grid">
                ${slots.map((team, index) => {
                    const color = team?.team_color || ['#38bdf8', '#f7c948', '#34d399', '#fb7185'][index];
                    const rgb = hexToRgb(color);
                    const rgbStr = `${rgb.r}, ${rgb.g}, ${rgb.b}`;
                    const theme = getTeamTheme(color);
                    
                    const scoreVal = team ? Number(team.total_score || 0) : 0;
                    const targetHeight = team ? Math.max(60, Math.round((scoreVal / maxScore) * 440)) : 60;
                    
                    const rank = team ? `#${team.rank}` : `#${index + 1}`;
                    const name = team ? (team.team_name || team.short_name || 'Team') : 'Awaiting Team';

                    return `
                        <div class="team-slot-3d" style="--team-color: ${color}; --team-color-rgb: ${rgbStr};">
                            <!-- Circular Neon Base Ring -->
                            <div class="base-ring-3d">
                                <div class="base-icon-3d">${theme.icon}</div>
                            </div>
                            
                            <!-- Spotlight Flare -->
                            <div class="pillar-spotlight"></div>
                            
                            <!-- 3D Column (Pillar) -->
                            <div class="pillar-3d" style="--pillar-height: ${targetHeight}px;">
                                <div class="face front">
                                    <div class="pillar-fx ${theme.key}"></div>
                                    <div class="pillar-score-text" data-score="${scoreVal}">0</div>
                                </div>
                                <div class="face back"><div class="pillar-fx ${theme.key}"></div></div>
                                <div class="face left"><div class="pillar-fx ${theme.key}"></div></div>
                                <div class="face right"><div class="pillar-fx ${theme.key}"></div></div>
                                <div class="face top"></div>
                            </div>
                            
                            <!-- Team Name Tag -->
                            <div class="pillar-name-tag">
                                <span>${escapeHtml(name)}</span>
                                <small>${escapeHtml(rank)}</small>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;

        // Animate the height of the pillars using GSAP (sequenced as in the video)
        setTimeout(() => {
            const slots3d = container.querySelectorAll('.team-slot-3d');
            const pillars = container.querySelectorAll('.pillar-3d');
            const scores = container.querySelectorAll('.pillar-score-text');
            
            if (typeof gsap !== 'undefined') {
                // Initialize scale to 0
                gsap.set(pillars, { scaleY: 0 });
                
                // Animate score text counters
                scores.forEach(scoreEl => {
                    const target = Number(scoreEl.dataset.score);
                    const scoreObj = { value: 0 };
                    gsap.to(scoreObj, {
                        value: target,
                        duration: 3,
                        ease: 'power2.out',
                        delay: 0.5,
                        onUpdate: () => {
                            scoreEl.textContent = formatScore(scoreObj.value);
                        }
                    });
                });

                // Pillar 1 (Left / Water) rises first (after 0.5s delay)
                if (pillars[0]) {
                    gsap.to(pillars[0], {
                        scaleY: 1,
                        duration: 2.8,
                        ease: 'power2.out',
                        delay: 0.5
                    });
                }
                
                // Pillars 2 & 3 (Lightning & Frost) rise together next
                if (pillars[1] || pillars[2]) {
                    gsap.to([pillars[1], pillars[2]].filter(Boolean), {
                        scaleY: 1,
                        duration: 2.4,
                        ease: 'power2.out',
                        stagger: 0.15,
                        delay: 1.4
                    });
                }
                
                // Pillar 4 (Right / Fire) rises fastest last
                if (pillars[3]) {
                    gsap.to(pillars[3], {
                        scaleY: 1,
                        duration: 2.0,
                        ease: 'power3.out',
                        delay: 2.4,
                        onComplete: () => {
                            // Find the team with the highest score
                            let winnerIdx = 0;
                            let maxS = -1;
                            slots.forEach((t, i) => {
                                if (t && Number(t.total_score || 0) > maxS) {
                                    maxS = Number(t.total_score || 0);
                                    winnerIdx = i;
                                }
                            });
                            
                            // Turn on the spotlight flare on the winner
                            if (slots3d[winnerIdx]) {
                                const spotlight = slots3d[winnerIdx].querySelector('.pillar-spotlight');
                                if (spotlight) {
                                    spotlight.classList.add('winner-spotlight');
                                }
                            }
                            
                            // Trigger the golden confetti rain
                            if (maxS > 0) {
                                triggerGoldenConfetti(container);
                            }
                        }
                    });
                }
            } else {
                // Fallback if GSAP is missing
                pillars.forEach(p => p.style.transform = 'scaleY(1)');
                scores.forEach(s => s.textContent = s.dataset.score);
            }
        }, 100);
    }

    function triggerLeaderboardAnimations() {
        // Handled dynamically by renderLeaderboard
    }

    function renderSchedule(scheduleData) {
        if (!els.schedule) return;

        const sections = scheduleData.sections || [];
        if (sections.length === 0) {
            els.schedule.innerHTML = `
                <div class="tv-schedule-empty" style="text-align: center; padding: 60px; color: var(--muted); font-size: 20px;">
                    No schedule items or sections available
                </div>
            `;
            return;
        }

        let html = '<div class="tv-schedule-grid">';
        sections.forEach((sec) => {
            html += `
                <div class="tv-panel">
                    <div class="tv-panel-head">
                        <h2>${escapeHtml(sec.name)}</h2>
                        ${sec.time_label ? `<span>${escapeHtml(sec.time_label)}</span>` : ''}
                    </div>
                    <div class="tv-list">
            `;

            (sec.items || []).forEach((item) => {
                const isBreak = item.type === 'break';
                const isCompleted = item.status === 'completed' || item.approval_status === 'approved';
                const isLive = item.status === 'scoring' || item.status === 'active-stage';
                
                let statusClass = 'upcoming';
                let statusLabel = 'Pending';
                if (isBreak) {
                    statusClass = 'upcoming';
                    statusLabel = 'Break';
                } else if (isCompleted) {
                    statusClass = 'completed';
                    statusLabel = 'Completed';
                } else if (isLive) {
                    statusClass = 'inprogress';
                    statusLabel = 'Live';
                }

                const timeLabel = item.start_label || item.start_time || '';

                html += `
                    <article class="tv-item">
                        <div class="tv-item-head">
                            <span style="font-size: 13px; color: var(--muted); font-weight: 600;">${escapeHtml(timeLabel)}</span>
                            <span class="tv-status ${statusClass}">${escapeHtml(statusLabel)}</span>
                        </div>
                        <div class="tv-item-title" style="margin-top: 8px;">${escapeHtml(item.title)}</div>
                    </article>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        });
        html += '</div>';

        els.schedule.innerHTML = html;
    }

    function renderCurrent(currentData) {
        if (!els.currentTitle) return;

        const isBreak = !currentData || currentData.is_break;
        if (isBreak) {
            els.currentStage.textContent = 'Main Stage';
            els.currentTitle.textContent = 'BREAK TIME';
            els.currentPerformer.textContent = 'Stand by for the next act';
            els.currentTeam.textContent = 'Awaiting next program';
            els.currentCategory.textContent = 'All Classes';
            els.currentStatus.textContent = 'Break';
            els.currentRoom.textContent = 'Main Hall';

            if (els.nextPerformer) els.nextPerformer.textContent = '—';
            if (els.nextTeam) els.nextTeam.textContent = '—';
            if (els.judges) els.judges.textContent = '—';
            if (els.nextProgram) els.nextProgram.textContent = '—';
            return;
        }

        const program = currentData.program || {};
        const performer = currentData.performer || {};
        const next = currentData.next_performer || {};
        const nextProg = currentData.next_program || {};
        const judges = currentData.judges || [];

        els.currentStage.textContent = program.stage || 'Main Stage';
        els.currentTitle.textContent = program.title || 'Current Act';
        
        if (performer.name) {
            els.currentPerformer.textContent = performer.name;
            els.currentTeam.innerHTML = `${performer.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(performer.team_color)}"></span>` : ''}${escapeHtml(performer.team || '—')}`;
        } else {
            els.currentPerformer.textContent = 'No active performer';
            els.currentTeam.textContent = '—';
        }

        els.currentCategory.textContent = program.category || 'All Classes';
        els.currentStatus.textContent = currentData.status || 'Active';
        els.currentRoom.textContent = program.location || 'Stage Room';

        if (els.nextPerformer) {
            els.nextPerformer.textContent = next.name ? `${next.name} (${next.number || performer.number + 1})` : '—';
        }
        if (els.nextTeam) {
            els.nextTeam.innerHTML = next.team ? `${next.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(next.team_color)}"></span>` : ''}${escapeHtml(next.team)}` : '—';
        }

        if (els.judges) {
            els.judges.innerHTML = judges.length 
                ? judges.map(j => `<span class="tv-judge-badge">${escapeHtml(j)}</span>`).join('') 
                : '<span class="text-muted">No panel assigned</span>';
        }

        if (els.nextProgram) {
            els.nextProgram.textContent = nextProg.title ? `${nextProg.title} (${nextProg.start_label || ''})` : '—';
        }
    }

    function triggerCelebration(celebration) {
        if (!els.celebration || !celebration || !celebration.id) return;
        
        state.isCelebrating = true;
        stopSlideTimer();

        if (els.celebrationTitle) els.celebrationTitle.textContent = celebration.title || 'Winner!';
        if (els.celebrationTeam) {
            const color = celebration.team_color || '#d6b25e';
            els.celebrationTeam.innerHTML = `<span class="tv-team-dot" style="background:${color}"></span>${escapeHtml(celebration.winner || 'Champion')} - ${escapeHtml(celebration.team || 'Winning Team')}`;
        }
        if (els.celebrationScore) {
            els.celebrationScore.textContent = formatScore(celebration.score);
        }

        els.celebration.removeAttribute('hidden');
        els.celebration.style.display = 'flex';

        if (typeof gsap !== 'undefined') {
            gsap.fromTo(els.celebration.querySelector('.tv-celebration-copy'), {
                scale: 0.5,
                opacity: 0,
                y: 100
            }, {
                scale: 1,
                opacity: 1,
                y: 0,
                duration: 0.8,
                ease: 'back.out(1.7)'
            });
        }

        // Auto dismiss after 15 seconds
        setTimeout(() => {
            dismissCelebration();
        }, 15000);
    }

    function dismissCelebration() {
        if (!els.celebration || !state.isCelebrating) return;

        if (typeof gsap !== 'undefined') {
            gsap.to(els.celebration.querySelector('.tv-celebration-copy'), {
                scale: 0.7,
                opacity: 0,
                y: -50,
                duration: 0.5,
                ease: 'power2.in',
                onComplete: () => {
                    els.celebration.setAttribute('hidden', '');
                    els.celebration.style.display = 'none';
                    state.isCelebrating = false;
                    scheduleNextSlide(1000);
                }
            });
        } else {
            els.celebration.setAttribute('hidden', '');
            els.celebration.style.display = 'none';
            state.isCelebrating = false;
            scheduleNextSlide(1000);
        }
    }

    function applyBootstrap(data) {
        if (!data) return;

        // Apply theme color schema class on body
        const theme = data.settings?.theme || 'emerald';
        if (state.theme !== theme) {
            els.body.classList.remove(`theme-${state.theme}`);
            els.body.classList.add(`theme-${theme}`);
            state.theme = theme;
        }

        state.is_playing = data.settings?.is_playing ?? true;
        state.mode = data.settings?.mode ?? 'auto';
        state.slides = data.settings?.slides || {};

        // Compute leaderboard program completion percent first
        const stats = data.stats || {};
        const teamsCount = stats.teams || 4;
        const totalProgs = stats.programs || 10;
        const compProgs = stats.completed_programs || 0;
        const completionPercent = totalProgs > 0 ? Math.round((compProgs / totalProgs) * 100) : 0;

        renderLeaderboard(data.leaderboard || [], completionPercent);
        renderSchedule(data.schedule || { sections: [] });
        renderCurrent(data.current || {});

        const countEl = document.querySelector('[data-leaderboard-count]');
        if (countEl) countEl.textContent = teamsCount;

        // Handle Emergency broadcast updates
        const emergency = data.settings?.emergency || {};
        if (els.emergency && els.emergencyMsg) {
            if (emergency.enabled && emergency.message) {
                els.emergencyMsg.textContent = emergency.message;
                els.emergency.removeAttribute('hidden');
                els.emergency.style.display = 'flex';
            } else {
                els.emergency.setAttribute('hidden', '');
                els.emergency.style.display = 'none';
            }
        }

        // Handle Celebration modal overlay triggers
        const celebration = data.settings?.celebration || {};
        if (celebration.id && celebration.id !== state.lastCelebrationId) {
            state.lastCelebrationId = celebration.id;
            triggerCelebration(celebration);
        }

        // Sync local slide map configs if in manual mode
        if (state.mode === 'manual' && !state.isCelebrating) {
            const manualSlide = data.settings?.active_slide || 'intro';
            setActiveSlide(manualSlide);
        }
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    async function syncSettings() {
        try {
            const res = await fetchJson(TV_BOOT.api.bootstrap);
            if (res?.success && res.data) {
                applyBootstrap(res.data);
            }
        } catch (err) {
            console.error('Settings sync failed:', err);
        }
    }

    function startRefreshLoop() {
        // Disabled automatic settings/data polling refresh loop
    }

    function stopSlideTimer() {
        if (state.timers.slide) {
            clearTimeout(state.timers.slide);
            state.timers.slide = null;
        }
    }

    function getNextEnabledSlide() {
        const enabledKeys = state.slideOrder.filter(key => {
            const slideConf = state.slides[key];
            return slideConf && slideConf.enabled !== false;
        });

        if (enabledKeys.length === 0) return 'intro';

        const currentIdx = enabledKeys.indexOf(state.activeSlide);
        if (currentIdx === -1) return enabledKeys[0];

        return enabledKeys[(currentIdx + 1) % enabledKeys.length];
    }

    function scheduleNextSlide(delay) {
        stopSlideTimer();
        if (!state.is_playing || state.mode === 'manual' || state.isCelebrating) return;

        state.timers.slide = setTimeout(() => {
            if (state.activeSlide === 'intro' && els.introVideo && !els.introVideo.ended) {
                scheduleNextSlide(1000);
                return;
            }

            const next = getNextEnabledSlide();
            setActiveSlide(next);

            const duration = state.slides[next]?.duration || 12000;
            scheduleNextSlide(duration);
        }, delay);
    }

    function startRotation() {
        const first = state.slides[state.activeSlide]?.enabled ? state.activeSlide : getNextEnabledSlide();
        setActiveSlide(first);

        if (first === 'intro' && els.introVideo) {
            const introDone = () => {
                if (state.mode === 'auto' && !state.isCelebrating) {
                    const next = getNextEnabledSlide();
                    setActiveSlide(next);
                    scheduleNextSlide(state.slides[next]?.duration || 12000);
                }
            };

            els.introVideo.addEventListener('ended', introDone, { once: true });
            els.introVideo.addEventListener('error', introDone, { once: true });

            els.introVideo.play().catch(() => {
                introDone();
            });
        } else {
            scheduleNextSlide(state.slides[first]?.duration || 12000);
        }
    }

    function boot() {
        startClock();
        initParticles();

        if (TV_BOOT.initial) {
            applyBootstrap(TV_BOOT.initial);
        }

        startRotation();
        startRefreshLoop();

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopSlideTimer();
            } else {
                syncSettings().then(() => {
                    if (state.mode === 'auto' && !state.isCelebrating) {
                        scheduleNextSlide(1000);
                    }
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
