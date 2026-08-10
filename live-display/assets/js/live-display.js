/* global gsap, particlesJS, TV_BOOT */
(() => {
    'use strict';

    const state = {
        activeSlide: 'intro',
        is_playing: true,
        mode: 'auto',
        theme: 'emerald',
        slides: {},
        leaderboardData: null,
        leaderboardSignature: '',
        slideOrder: ['intro', 'leaderboard', 'schedule', 'current-program'],
        schedule: {
            data: null,
            pages: [],
            currentPage: 0,
            timer: null,
            playing: false
        },
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

    function initParticles(teamColor = '#10b981') {
        const pContainer = document.getElementById('particles-js');
        if (!pContainer) return;

        if (typeof particlesJS !== 'undefined') {
            try {
                const particleColors = [teamColor, '#38bdf8', '#fbbf24', '#ffffff'];
                particlesJS('particles-js', {
                    "particles": {
                        "number": {
                            "value": 55,
                            "density": {
                                "enable": true,
                                "value_area": 900
                            }
                        },
                        "color": {
                            "value": particleColors
                        },
                        "shape": {
                            "type": ["circle", "triangle"],
                            "stroke": {
                                "width": 0,
                                "color": "#000000"
                            }
                        },
                        "opacity": {
                            "value": 0.45,
                            "random": true,
                            "anim": {
                                "enable": true,
                                "speed": 1.2,
                                "opacity_min": 0.15,
                                "sync": false
                            }
                        },
                        "size": {
                            "value": 4.5,
                            "random": true,
                            "anim": {
                                "enable": true,
                                "speed": 2,
                                "size_min": 1,
                                "sync": false
                            }
                        },
                        "line_linked": {
                            "enable": true,
                            "distance": 140,
                            "color": teamColor,
                            "opacity": 0.18,
                            "width": 1.2
                        },
                        "move": {
                            "enable": true,
                            "speed": 1.4,
                            "direction": "top-right",
                            "random": true,
                            "straight": false,
                            "out_mode": "out",
                            "bounce": false
                        }
                    },
                    "interactivity": {
                        "detect_on": "canvas",
                        "events": {
                            "onhover": { "enable": true, "mode": "grab" },
                            "onclick": { "enable": true, "mode": "push" },
                            "resize": true
                        },
                        "modes": {
                            "grab": { "distance": 160, "line_linked": { "opacity": 0.35 } },
                            "push": { "particles_nb": 3 }
                        }
                    },
                    "retina_detect": true
                });
            } catch (err) {
                console.error("particlesJS error:", err);
            }
        } else {
            setTimeout(() => initParticles(teamColor), 100);
        }
    }

    function getSlideElements() {
        return Array.from(document.querySelectorAll('.tv-slide'));
    }

    function setActiveSlide(name) {
        if (!name) return;
        const normalizedName = String(name).replace('_', '-');
        const slides = getSlideElements();

        els.body.classList.toggle('tv-schedule-active', normalizedName === 'schedule');

        state.activeSlide = normalizedName;
        slides.forEach((slide) => {
            const isActive = slide.dataset.slide === normalizedName;
            slide.classList.toggle('tv-slide--active', isActive);
        });

        if (normalizedName !== 'schedule') {
            stopScheduleTimer();
        }

        if (normalizedName === 'intro') {
            const video = document.querySelector('[data-intro-video]');
            if (video) {
                try {
                    video.currentTime = 0;
                    video.play().catch(() => {});
                } catch (_) {}
            }
        }

        animateEntrance(`#slide-${normalizedName}`);

        if (normalizedName === 'schedule') {
            startSchedulePlayback();
        }
    }

    function animateEntrance(scope) {
        const root = document.querySelector(scope);
        if (!root || typeof gsap === 'undefined') return;

        gsap.killTweensOf(root.querySelectorAll('*'));

        if (scope === '#slide-current-program') {
            if (typeof window.triggerCurrentProgramAnimations === 'function') {
                window.triggerCurrentProgramAnimations();
            }
            return;
        }

        if (scope === '#slide-leaderboard') {
            triggerLeaderboardAnimations(root);
            return;
        }

        const items = root.querySelectorAll('.tv-card-rank, .tv-item, .tv-panel, .glass-panel');
        if (items.length > 0) {
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
    }

    function hexToRgb(hex) {
        if (!hex) return { r: 255, g: 255, b: 255 };
        
        const colorNames = {
            'green': { r: 0, g: 255, b: 136 },
            'red': { r: 255, g: 34, b: 85 },
            'blue': { r: 0, g: 170, b: 255 },
            'yellow': { r: 255, g: 238, b: 0 },
            'white': { r: 224, g: 247, b: 255 },
            'purple': { r: 208, g: 0, b: 255 },
            'orange': { r: 255, g: 136, b: 0 },
            'pink': { r: 255, g: 0, b: 187 },
            'black': { r: 24, g: 24, b: 30 }
        };

        const name = String(hex).toLowerCase().trim();
        if (colorNames[name]) {
            return colorNames[name];
        }

        const h = String(hex).replace(/^#/, '');
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
        
        if (isNaN(r) || isNaN(g) || isNaN(b)) {
            return { r: 0, g: 255, b: 136 }; 
        }
        
        return { r, g, b };
    }

    function rgbaColor(hex, alpha) {
        const rgb = hexToRgb(hex);
        return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
    }

    function getTeamMascot(name) {
        const lower = String(name).toLowerCase();
        if (lower.includes('3') || lower.includes('anas')) {
            return {
                name: 'Lion',
                subName: 'THE LION KINGS',
                svg: `<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M50 10 L85 25 L80 65 L50 90 L20 65 L15 25 Z" /><path d="M38 45 L45 48 L42 52 Z" /><path d="M62 45 L55 48 L58 52 Z" /><path d="M48 55 L52 55 L50 62 Z" /><path d="M50 62 L45 68 L50 72 L55 68 Z" /><path d="M50 10 L50 25" /><path d="M35 20 L45 28" /><path d="M65 20 L55 28" /></svg>`
            };
        } else if (lower.includes('1') || lower.includes('noufal')) {
            return {
                name: 'Falcon',
                subName: 'THE PHOENIX',
                svg: `<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M50 10 L85 25 L80 65 L50 90 L20 65 L15 25 Z" /><path d="M25 35 L40 45 L50 35 L60 45 L75 35" /><path d="M22 45 L40 52 L50 45 L60 52 L78 45" /><path d="M50 45 L46 55 L50 65 L54 55 Z" /><circle cx="44" cy="48" r="1.5" fill="currentColor" /><circle cx="56" cy="48" r="1.5" fill="currentColor" /><path d="M42 68 L50 82 L58 68 Z" /></svg>`
            };
        } else if (lower.includes('2') || lower.includes('imran')) {
            return {
                name: 'Wolf',
                subName: 'THE WOLVES',
                svg: `<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M50 10 L85 25 L80 65 L50 90 L20 65 L15 25 Z" /><path d="M30 35 L38 22 L44 32" /><path d="M70 35 L62 22 L56 32" /><path d="M30 48 L42 48 L50 68 L58 48 L70 48" /><path d="M36 42 L44 45" /><path d="M64 42 L56 45" /><circle cx="50" cy="72" r="3" fill="currentColor" /></svg>`
            };
        } else {
            return {
                name: 'Dragon',
                subName: 'THE DRAGONS',
                svg: `<svg viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M50 10 L85 25 L80 65 L50 90 L20 65 L15 25 Z" /><path d="M35 30 L22 18 L32 32" /><path d="M65 30 L78 18 L68 32" /><path d="M40 45 L50 35 L60 45 L55 68 L45 68 Z" /><path d="M38 52 L26 62 L42 58" /><path d="M62 52 L74 62 L58 58" /><path d="M42 42 L46 44" /><path d="M58 42 L54 44" /></svg>`
            };
        }
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
        
        // Pure neon FX — no icons
        if (s < 0.15 || l > 0.85) {
            return { key: 'neon', name: 'Neon' };
        }
        if (hueDegrees >= 45 && hueDegrees < 85) {
            return { key: 'neon', name: 'Neon' };
        } else if (hueDegrees >= 165 && hueDegrees < 280) {
            return { key: 'neon', name: 'Neon' };
        } else if (hueDegrees >= 85 && hueDegrees < 165) {
            return { key: 'neon', name: 'Neon' };
        } else {
            return { key: 'neon', name: 'Neon' };
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
        const teams = Array.isArray(rows) ? rows : [];

        const container = document.querySelector('[data-leaderboard], [data-leaderboard-stage]');
        if (!container) return;

        const signature = JSON.stringify((teams || []).slice(0, 8).map((team) => [
            team?.id, team?.rank, team?.team_name, team?.total_score, team?.team_color
        ]));

        if (state.leaderboardSignature === signature && container.querySelector('.dashboard-grid')) {
            return;
        }
        state.leaderboardSignature = signature;

        const firstTeam = teams[0] || {};
        const firstTeamColor = firstTeam.team_color || '#10b981';
        const firstTeamName = firstTeam.team_name || firstTeam.short_name || 'Leader';
        const firstTeamScore = Math.round(Number(firstTeam.total_score || 0));

        const secondTeam = teams[1] || {};
        const secondTeamColor = secondTeam.team_color || '#3b82f6';
        const secondTeamName = secondTeam.team_name || secondTeam.short_name || '—';
        const secondTeamScore = Math.round(Number(secondTeam.total_score || 0));

        // 1. Dynamic background based on 1st place team color
        if (teams.length > 0 && typeof gsap !== 'undefined') {
            const rgb = hexToRgb(firstTeamColor);
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
        document.documentElement.style.setProperty('--first-team-color', firstTeamColor);

        // Other teams (3rd, 4th, etc.)
        const otherTeams = teams.slice(2);
        const otherRowsHtml = otherTeams.length ? otherTeams.map((team) => {
            const color = team.team_color || '#64748b';
            const rank = Number(team.rank || 3);
            const name = team.team_name || team.short_name || 'Team';
            const score = Math.round(Number(team.total_score || 0));
            const medal = rank === 3 ? '🥉 ' : '';

            return `
                <div class="standing-row-item">
                    <span class="st-rank-badge">${medal}${rank}th</span>
                    <span class="st-team-info">
                        <span class="tv-team-dot" style="background:${escapeHtml(color)};"></span>
                        <span>${escapeHtml(name)}</span>
                    </span>
                    <span class="st-pts-num">${score} <span style="font-size: 13px; font-weight: 700; color: #64748b;">PTS</span></span>
                </div>
            `;
        }).join('') : `<div style="color: #64748b; font-size: 16px; text-align: center;">No additional teams</div>`;

        container.className = 'tv-leaderboard-stage-root';
        container.innerHTML = `
            <!-- 3D Relief Geometric Layer Cuts Background -->
            <svg class="bg-3d-cuts-svg" viewBox="0 0 1920 1080" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                <filter id="cutShadow" x="-20%" y="-20%" width="140%" height="140%">
                    <feDropShadow dx="-8" dy="12" stdDeviation="15" flood-color="rgba(0,0,0,0.06)" />
                </filter>
                <polygon points="-100,1200 650,540 -100,-100" fill="#ffffff" filter="url(#cutShadow)" />
                <polygon points="-100,1050 480,540 -100,30" fill="rgba(250,250,252,0.92)" filter="url(#cutShadow)" />
                <polygon points="2020,1200 1270,540 2020,-100" fill="#ffffff" filter="url(#cutShadow)" />
                <polygon points="2020,1050 1440,540 2020,30" fill="rgba(250,250,252,0.92)" filter="url(#cutShadow)" />
            </svg>

            <!-- Dynamic 1st Rank Team Geometric Chevron Vectors -->
            <svg class="side-chevrons-svg full-screen" viewBox="0 0 1920 1080" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g class="animated-chevron-group">
                    <path class="animated-dash-line" d="M-100 1200 L650 540 L-100 -100" stroke="var(--first-team-color, #10b981)" stroke-width="3" stroke-linecap="round" />
                    <path class="animated-dash-line" d="M-150 1050 L480 540 L-150 30" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.8" stroke-linecap="round" />
                    <line class="animated-cross-line" x1="200" y1="900" x2="420" y2="680" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.75" />
                    <line class="animated-cross-line" x1="320" y1="780" x2="540" y2="560" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.75" />

                    <path class="animated-dash-line" d="M2020 1200 L1270 540 L2020 -100" stroke="var(--first-team-color, #10b981)" stroke-width="3" stroke-linecap="round" />
                    <path class="animated-dash-line" d="M2070 1050 L1440 540 L2070 30" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.8" stroke-linecap="round" />
                    <line class="animated-cross-line" x1="1720" y1="900" x2="1500" y2="680" stroke="var(--first-team-color, #10b981)" stroke-width="2" opacity="0.75" />
                </g>
            </svg>

            <div class="ambient-mesh-bg"></div>

            <div class="leaderboard-slide-container" style="--first-team-color: ${escapeHtml(firstTeamColor)};">
                <div class="leaderboard-slide-title">
                    <span>Team Standings & Points</span>
                    <span class="first-team-rank-pill">
                        <span class="rank-crown">👑</span>
                        <span class="rank-label">CHAMPION LEADER:</span>
                        <span class="rank-name">${escapeHtml(firstTeamName)}</span>
                    </span>
                </div>

                <div class="dashboard-grid">
                    <!-- Main Card (1st Place Hero) -->
                    <main class="glass-panel leaderboard-hero-card">
                        <svg class="card-chevrons-svg" viewBox="0 0 800 500" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <g class="animated-card-group">
                                <path class="animated-dash-line" d="M-50 480 L350 80 L-50 -320" stroke="var(--first-team-color, #10b981)" stroke-width="2.5" opacity="0.6" stroke-linecap="round"/>
                                <path class="animated-dash-line" d="M850 480 L450 80 L850 -320" stroke="var(--first-team-color, #10b981)" stroke-width="2.5" opacity="0.6" stroke-linecap="round"/>
                            </g>
                        </svg>

                        <div>
                            <div class="hero-leader-badge">
                                <span>👑</span> 1st Place Leader
                            </div>
                            <h1 class="hero-team-name">${escapeHtml(firstTeamName)}</h1>
                            <div class="st-team-info">
                                <span class="tv-team-dot" style="background:${escapeHtml(firstTeamColor)};"></span>
                                <span style="font-size: 16px; color: #475569; font-weight: 700;">Leading Musabaqa Standings</span>
                            </div>
                        </div>

                        <div class="hero-score-box">
                            <span class="hero-score-label">TOTAL OVERALL SCORE</span>
                            <span class="hero-score-num">${firstTeamScore}</span>
                        </div>
                    </main>

                    <!-- Sidebar Column: 2nd Place & Other Teams -->
                    <aside class="sidebar-column">
                        <!-- Top Card: 2nd Place Runner Up -->
                        <div class="glass-panel side-card-top runner-up-card">
                            <svg class="card-chevrons-svg" viewBox="0 0 500 260" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <g class="animated-card-group">
                                    <path class="animated-dash-line" d="M-30 260 L280 80 L-30 -100" stroke="${escapeHtml(secondTeamColor)}" stroke-width="2" opacity="0.55" stroke-linecap="round"/>
                                </g>
                            </svg>

                            <div class="side-box-label">
                                🥈 2nd Place Runner-Up
                            </div>
                            <h2 class="runner-team-name">${escapeHtml(secondTeamName)}</h2>
                            <div class="runner-score-big">
                                <span style="color:${escapeHtml(secondTeamColor)}">${secondTeamScore}</span> <span style="font-size: 20px; color: #64748b; font-weight: 700;">PTS</span>
                            </div>
                        </div>

                        <!-- Bottom Card: Remaining Standings -->
                        <div class="glass-panel side-card-bottom standings-list-card">
                            <div class="side-box-label" style="text-align: left; margin-bottom: 14px;">
                                Other Team Standings
                            </div>
                            <div class="standings-rows-list">
                                ${otherRowsHtml}
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        `;

        triggerLeaderboardAnimations(container);
    }

    function triggerLeaderboardAnimations(customContainer) {
        const root = customContainer || document.querySelector('[data-leaderboard], [data-leaderboard-stage]');
        if (!root || typeof gsap === 'undefined') return;

        const heroCard = root.querySelector('.leaderboard-hero-card');
        const sideTopCard = root.querySelector('.runner-up-card');
        const sideBottomCard = root.querySelector('.standings-list-card');

        if (heroCard) {
            gsap.fromTo(heroCard, {
                opacity: 0.85,
                scale: 0.98,
                x: -30
            }, {
                opacity: 1,
                scale: 1,
                x: 0,
                duration: 0.8,
                ease: 'power3.out'
            });
        }

        if (sideTopCard) {
            gsap.fromTo(sideTopCard, {
                opacity: 0.85,
                scale: 0.98,
                x: 30
            }, {
                opacity: 1,
                scale: 1,
                x: 0,
                duration: 0.8,
                ease: 'power3.out',
                delay: 0.08
            });
        }

        if (sideBottomCard) {
            gsap.fromTo(sideBottomCard, {
                opacity: 0.85,
                scale: 0.98,
                x: 30
            }, {
                opacity: 1,
                scale: 1,
                x: 0,
                duration: 0.8,
                ease: 'power3.out',
                delay: 0.16
            });
        }
    }

    function scheduleRowsPerPage() {
        const height = window.innerHeight || 1080;
        return height < 800 ? 7 : 8;
    }

    function getScheduleStatus(item) {
        const isBreak = item.type === 'break';
        const isCompleted = item.status === 'completed' || item.approval_status === 'approved';
        const isLive = item.status === 'scoring' || item.status === 'active-stage';

        if (isBreak) return { className: 'break', label: 'Break' };
        if (isCompleted) return { className: 'completed', label: 'Completed' };
        if (isLive) return { className: 'inprogress', label: 'In Progress' };

        return { className: 'upcoming', label: 'Upcoming' };
    }

    function flattenScheduleItems(scheduleData) {
        const sections = Array.isArray(scheduleData?.sections) ? scheduleData.sections : [];
        const rows = [];

        sections.forEach((section) => {
            (Array.isArray(section.items) ? section.items : []).forEach((item) => {
                rows.push({
                    ...item,
                    section_name: section.name || 'Schedule',
                    section_time_label: section.time_label || ''
                });
            });
        });

        if (!rows.length && Array.isArray(scheduleData?.timeline)) {
            scheduleData.timeline.forEach((item) => {
                rows.push({
                    ...item,
                    section_name: 'Full Schedule',
                    section_time_label: ''
                });
            });
        }

        return rows;
    }

    function buildSchedulePages(scheduleData) {
        const rows = flattenScheduleItems(scheduleData);
        state.schedule.allRows = rows;
        const pageSize = scheduleRowsPerPage();
        const pages = [];

        for (let i = 0; i < rows.length; i += pageSize) {
            pages.push(rows.slice(i, i + pageSize));
        }

        return pages;
    }

    function scheduleSummary(scheduleData) {
        const rows = flattenScheduleItems(scheduleData);
        const completed = rows.filter((item) => {
            return item.type !== 'break' && (item.status === 'completed' || item.approval_status === 'approved');
        }).length;
        const live = rows.filter((item) => item.status === 'scoring' || item.status === 'active-stage').length;
        const breaks = rows.filter((item) => item.type === 'break').length;

        return {
            total: rows.length,
            completed,
            live,
            breaks
        };
    }

    function scheduleTeamPalette() {
        const fallback = ['#ffe34a', '#ff42f5', '#25ff8a', '#2ee8ff'];
        const teams = Array.isArray(state.leaderboardData) ? state.leaderboardData : [];
        const colors = teams
            .map((team) => String(team?.team_color || '').trim())
            .filter((color) => /^#[0-9a-f]{6}$/i.test(color));

        return colors.length ? colors : fallback;
    }

    function renderScheduleFrame(scheduleData) {
        const pages = state.schedule.pages || [];
        const totalPages = Math.max(1, pages.length);
        const curPage = state.schedule.currentPage || 0;

        els.schedule.innerHTML = `
            <div class="schedule-slide-container">
                <div class="schedule-slide-title">
                    <span>Program Schedule</span>
                    <span class="page-count-badge" data-schedule-page-badge>Page ${curPage + 1} / ${totalPages}</span>
                </div>
                <div class="tv-schedule-board">
                    <svg class="card-chevrons-svg" viewBox="0 0 800 500" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <g class="animated-card-group">
                            <path class="animated-dash-line" d="M-50 480 L350 80 L-50 -320" stroke="var(--first-team-color, #10b981)" stroke-width="2.5" opacity="0.4" stroke-linecap="round"/>
                            <path class="animated-dash-line" d="M850 480 L450 80 L850 -320" stroke="var(--first-team-color, #10b981)" stroke-width="2.5" opacity="0.4" stroke-linecap="round"/>
                        </g>
                    </svg>
                    <div class="tv-schedule-board-head" style="position: relative; z-index: 2;">
                        <span>#</span>
                        <span>Time</span>
                        <span>Program</span>
                        <span>Stage / Venue</span>
                        <span style="text-align: center;">Status</span>
                    </div>
                    <div class="tv-schedule-page-wrapper" style="overflow: hidden; position: relative; z-index: 2;">
                        <div class="tv-schedule-page" data-schedule-page></div>
                    </div>
                </div>
            </div>
        `;
    }

    function updateScheduleClock() {
        const clock = els.schedule?.querySelector('[data-schedule-clock]');
        if (!clock) return;
        const now = new Date();
        clock.textContent = now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
    }

    function tvFormatScheduleSectionName(category) {
        if (!category || category === 'All' || category === 'Full Schedule' || category === 'Musabaqa Category' || category === 'All Classes') {
            return 'General';
        }
        const c = String(category).trim();
        if (c.includes('العالية') || c.toLowerCase() === 'senior') {
            return 'Senior';
        }
        if (c.includes('الثانوية') || c.toLowerCase() === 'junior') {
            return 'Junior';
        }
        if (c.includes('حفظ') || c.includes('التحصص') || c.toLowerCase() === 'sub' || c.toLowerCase() === 'sub junior') {
            return 'Sub';
        }
        return c;
    }

    function renderSchedulePage(index, animateOut = false) {
        const pageEl = els.schedule?.querySelector('[data-schedule-page]');
        if (!pageEl) return;

        const pages = state.schedule.pages || [];
        const page = pages[index] || [];
        const totalPages = Math.max(1, pages.length);

        const badgeEl = els.schedule?.querySelector('[data-schedule-page-badge]');
        if (badgeEl) {
            badgeEl.textContent = `Page ${index + 1} / ${totalPages}`;
        }

        const currentRows = Array.from(pageEl.querySelectorAll('.tv-schedule-row'));

        const updateAndAnimateIn = () => {
            if (!page.length) {
                pageEl.innerHTML = `
                    <div class="tv-schedule-empty" style="padding: 48px; text-align: center;">
                        <strong style="font-size: 24px; display: block; margin-bottom: 8px;">No programs scheduled</strong>
                        <span style="color: #64748b;">Stand by for upcoming competition events.</span>
                    </div>
                `;
                return;
            }

            const allRows = state.schedule.allRows || flattenScheduleItems(state.schedule.data);
            const firstUpcomingIndex = allRows.findIndex(item => {
                const s = getScheduleStatus(item);
                return s.className === 'upcoming' || s.className === 'inprogress';
            });

            pageEl.innerHTML = page.map((item, rowIndex) => {
                const status = getScheduleStatus(item);
                const time = item.start_label || item.start_time || '--';
                const rawCategory = item.category || item.class_type_name || item.class_name || item.section_name || '';
                const title = item.title || item.name || 'Program';
                const secName = tvFormatScheduleSectionName(rawCategory);

                let programLabel = title;
                if (secName && secName.toLowerCase() !== title.toLowerCase()) {
                    programLabel = `${title} ${secName}`;
                }

                const globalIndex = (index * scheduleRowsPerPage()) + rowIndex;
                const rowNumber = String(globalIndex + 1).padStart(2, '0');
                const isFirstUpcoming = (globalIndex === firstUpcomingIndex && status.className !== 'break');
                const isBreak = (status.className === 'break');
                const isInProgress = (status.className === 'inprogress');

                let rowClasses = ['tv-schedule-row'];
                let rowAccent = '#3b82f6';

                if (isInProgress || isFirstUpcoming) {
                    rowClasses.push('is-first-upcoming');
                    rowAccent = '#10b981';
                } else if (isBreak) {
                    rowClasses.push('is-break');
                    rowAccent = '#f59e0b';
                } else if (status.className === 'completed') {
                    rowAccent = '#94a3b8';
                }

                const stageName = item.stage || item.location || item.stage_name || item.stage_type_name || item.section_time_label || 'Main Stage';

                return `
                    <article class="${rowClasses.join(' ')}" style="--row-neon:${escapeHtml(rowAccent)}">
                        <div class="tv-schedule-row-num">${rowNumber}</div>
                        <div class="tv-schedule-row-time">${escapeHtml(time)}</div>
                        <div class="tv-schedule-row-program">
                            <strong>${escapeHtml(programLabel)}</strong>
                        </div>
                        <div class="tv-schedule-row-location">${escapeHtml(stageName)}</div>
                        <div class="tv-schedule-row-status">
                            <span class="tv-status ${status.className}">${escapeHtml(status.label)}</span>
                        </div>
                    </article>
                `;
            }).join('');

            if (typeof gsap !== 'undefined') {
                const rows = pageEl.querySelectorAll('.tv-schedule-row');
                gsap.fromTo(rows, {
                    opacity: 0,
                    x: 60,
                    scale: 0.98
                }, {
                    opacity: 1,
                    x: 0,
                    scale: 1,
                    duration: 0.65,
                    stagger: 0.05,
                    ease: 'power3.out'
                });
            }
        };

        if (animateOut && typeof gsap !== 'undefined' && currentRows.length > 0) {
            gsap.to(currentRows, {
                opacity: 0,
                x: -60,
                duration: 0.35,
                stagger: 0.03,
                ease: 'power2.in',
                onComplete: updateAndAnimateIn
            });
        } else {
            updateAndAnimateIn();
        }
    }

    function renderSchedule(scheduleData) {
        if (!els.schedule) return;

        const signature = JSON.stringify(scheduleData || {});
        if (state.schedule.signature === signature && state.schedule.playing) {
            return;
        }

        stopScheduleTimer();
        state.schedule.signature = signature;
        state.schedule.data = scheduleData || { sections: [] };
        state.schedule.pages = buildSchedulePages(state.schedule.data);
        state.schedule.currentPage = 0;
        renderScheduleFrame(state.schedule.data);
        updateScheduleClock();
        renderSchedulePage(0, false);

        if (state.activeSlide === 'schedule') {
            startSchedulePlayback();
        }
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
        const currentChest = document.querySelector('[data-current-chest]');
        const nextChest = document.querySelector('[data-next-chest]');

        els.currentStage.textContent = program.stage || 'Main Stage';
        els.currentTitle.textContent = program.title || 'Current Act';
        
        if (performer.name) {
            els.currentPerformer.textContent = performer.name;
            if (currentChest) currentChest.textContent = performer.number || '—';
            els.currentTeam.innerHTML = `${performer.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(performer.team_color)}"></span>` : ''}${escapeHtml(performer.team || '—')}`;
        } else {
            els.currentPerformer.textContent = 'No active performer';
            if (currentChest) currentChest.textContent = '—';
            els.currentTeam.textContent = '—';
        }

        els.currentCategory.textContent = program.category || 'All Classes';
        els.currentStatus.textContent = currentData.status || 'Active';
        els.currentRoom.textContent = program.location || 'Stage Room';

        if (els.nextPerformer) {
            els.nextPerformer.textContent = next.name || '—';
        }
        if (els.nextTeam) {
            els.nextTeam.innerHTML = next.team ? `${next.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(next.team_color)}"></span>` : ''}${escapeHtml(next.team)}` : '—';
        }

        if (nextChest) {
            nextChest.textContent = next.number || '—';
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

    function applyBootstrap(data) {
        if (!data) return;

        const settings = data.settings || {};
        const wasAuto = state.mode === 'auto';
        const wasPlaying = state.is_playing;

        // Sync playback state
        if (typeof settings.is_playing === 'boolean') state.is_playing = settings.is_playing;
        if (settings.mode) state.mode = settings.mode;
        if (settings.refresh_interval) state.refresh_interval = settings.refresh_interval;

        // Sync theme
        if (settings.theme) {
            state.theme = settings.theme;
            Array.from(document.body.classList).forEach(cls => {
                if (cls.startsWith('theme-')) {
                    document.body.classList.remove(cls);
                }
            });
            document.body.classList.add(`theme-${settings.theme}`);
        }

        // Rebuild slides map and ordered rotation from settings
        const slidesMap = settings.slides || {};
        state.slides = {};

        const slideArray = Object.values(slidesMap);
        slideArray.sort((a, b) => (a.sort_order ?? 99) - (b.sort_order ?? 99));

        state.slideOrder = slideArray.map(s => s.key || s.slide_key).filter(Boolean);
        slideArray.forEach(slide => {
            const key = slide.key || slide.slide_key;
            if (key) {
                state.slides[key] = {
                    key,
                    title: slide.title || key,
                    duration: slide.duration || 12000,
                    enabled: slide.enabled !== false,
                    sort_order: slide.sort_order ?? 99,
                    style: slide.style || 'classic'
                };
            }
        });

        // Fallback: ensure all known slide keys exist so the rotation never stalls
        ['intro', 'leaderboard', 'schedule', 'current-program'].forEach(key => {
            if (!state.slides[key]) {
                state.slides[key] = { key, title: key, duration: 12000, enabled: true, sort_order: 99, style: 'classic' };
            }
            if (!state.slideOrder.includes(key)) {
                state.slideOrder.push(key);
            }
        });

        // Populate slides with live data
        if (data.leaderboard) renderLeaderboard(data.leaderboard);
        if (data.current)     renderCurrent(data.current);
        if (data.schedule)    renderSchedule(data.schedule);

        // Control slideshow flow based on mode settings
        if (state.mode === 'manual') {
            stopSlideTimer();
            if (settings.active_slide && state.activeSlide !== settings.active_slide) {
                setActiveSlide(settings.active_slide);
            }
        } else if (state.mode === 'auto') {
            if (!wasAuto || (state.is_playing && !wasPlaying)) {
                startRotation();
            }
        }
    }

    function startRefreshLoop() {
        if (state.timers.refresh) {
            clearTimeout(state.timers.refresh);
            state.timers.refresh = null;
        }

        const interval = state.refresh_interval || (TV_BOOT.initial?.settings?.refresh_interval) || 5000;

        state.timers.refresh = setTimeout(async () => {
            await syncSettings();
            startRefreshLoop();
        }, interval);
    }

    function stopSlideTimer() {
        if (state.timers.slide) {
            clearTimeout(state.timers.slide);
            state.timers.slide = null;
        }
    }

    function stopScheduleTimer() {
        if (state.schedule.timer) {
            clearTimeout(state.schedule.timer);
            state.schedule.timer = null;
        }
        state.schedule.playing = false;
    }

    function advanceFromSchedule() {
        stopScheduleTimer();
        if (!state.is_playing || state.mode === 'manual' || state.isCelebrating || state.activeSlide !== 'schedule') {
            return;
        }

        const next = getNextEnabledSlide();
        setActiveSlide(next);

        if (next !== 'schedule') {
            scheduleNextSlide(state.slides[next]?.duration || 12000);
        }
    }

    function startSchedulePlayback() {
        stopSlideTimer();
        stopScheduleTimer();

        if (!els.schedule || !state.is_playing || state.isCelebrating) {
            return;
        }

        if (!state.schedule.data) {
            state.schedule.data = { sections: [] };
        }
        state.schedule.pages = buildSchedulePages(state.schedule.data);

        state.schedule.playing = true;
        state.schedule.currentPage = 0;
        updateScheduleClock();

        const totalPages = Math.max(1, state.schedule.pages.length);
        const configuredDuration = state.slides.schedule?.duration || 18000;
        const pageDuration = Math.max(3000, configuredDuration);

        renderSchedulePage(0, false);

        const tick = () => {
            updateScheduleClock();

            if (state.activeSlide !== 'schedule' || state.isCelebrating) {
                stopScheduleTimer();
                return;
            }

            if (state.schedule.currentPage >= totalPages - 1) {
                if (state.mode === 'manual') {
                    state.schedule.currentPage = 0;
                    renderSchedulePage(0, true);
                    state.schedule.timer = setTimeout(tick, pageDuration);
                    return;
                }

                advanceFromSchedule();
                return;
            }

            state.schedule.currentPage += 1;
            renderSchedulePage(state.schedule.currentPage, true);
            state.schedule.timer = setTimeout(tick, pageDuration);
        };

        state.schedule.timer = setTimeout(tick, pageDuration);
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

        const actualDelay = Math.max(3000, delay || 12000);

        state.timers.slide = setTimeout(() => {
            if (!state.is_playing || state.mode === 'manual' || state.isCelebrating) return;

            const next = getNextEnabledSlide();
            setActiveSlide(next);

            if (next !== 'schedule') {
                const duration = state.slides[next]?.duration || 12000;
                scheduleNextSlide(duration);
            }
        }, actualDelay);
    }

    function startRotation() {
        stopSlideTimer();
        stopScheduleTimer();

        if (!state.is_playing || state.mode === 'manual' || state.isCelebrating) return;

        const first = (state.slides[state.activeSlide]?.enabled !== false) ? state.activeSlide : getNextEnabledSlide();
        setActiveSlide(first);

        if (first === 'schedule') {
            startSchedulePlayback();
        } else {
            const duration = state.slides[first]?.duration || 12000;
            scheduleNextSlide(duration);
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
                stopScheduleTimer();
                if (state.timers.refresh) {
                    clearTimeout(state.timers.refresh);
                    state.timers.refresh = null;
                }
            } else {
                syncSettings().then(() => {
                    startRefreshLoop();
                    if (state.mode === 'auto' && !state.isCelebrating) {
                        if (state.activeSlide === 'schedule') {
                            startSchedulePlayback();
                        } else {
                            scheduleNextSlide(1000);
                        }
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
