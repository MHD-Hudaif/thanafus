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

    function initParticles() {
        // Replaced canvas teardown particlesJS with hardware-accelerated CSS ambient particles
    }

    function getSlideElements() {
        return Array.from(document.querySelectorAll('.tv-slide'));
    }

    let introVideoIndex = 0;
    let introTimeline = null;

    function playNextIntroVideo() {
        const videoEl = document.getElementById('introVideoPlayer') || document.querySelector('[data-intro-video]');
        const videos = window.TV_INTRO_VIDEOS;
        if (!videoEl || !Array.isArray(videos) || videos.length === 0) return;

        const src = videos[introVideoIndex % videos.length];
        introVideoIndex = (introVideoIndex + 1) % videos.length;

        if (videoEl.src !== src) {
            videoEl.src = src;
            videoEl.load();
        }

        videoEl.play().catch(() => {});
    }

    function triggerIntroAnimations() {
        playNextIntroVideo();

        const thanafusLogo = document.getElementById('introThanafusLogo');
        const kauzariyyaLogo = document.getElementById('introKauzariyyaLogo');

        if (!thanafusLogo || !kauzariyyaLogo || typeof gsap === 'undefined') return;

        if (introTimeline) {
            introTimeline.kill();
        }

        gsap.killTweensOf([thanafusLogo, kauzariyyaLogo]);

        gsap.set([thanafusLogo, kauzariyyaLogo], { opacity: 0, scale: 0.92 });

        introTimeline = gsap.timeline();

        // 1. Fade in Thanafus logo in center
        introTimeline.to(thanafusLogo, {
            opacity: 1,
            scale: 1,
            duration: 1.2,
            ease: 'power2.out'
        })
        // Hold for 2.2s then fade out
        .to(thanafusLogo, {
            opacity: 0,
            scale: 1.06,
            duration: 0.8,
            ease: 'power2.in',
            delay: 2.2
        })
        // 2. Fade in Kauzariyya logo in center
        .to(kauzariyyaLogo, {
            opacity: 1,
            scale: 1,
            duration: 1.2,
            ease: 'power2.out'
        }, '+=0.2');
    }

    function animateEntrance(scope) {
        const root = document.querySelector(scope);
        if (!root || typeof gsap === 'undefined') return;

        gsap.killTweensOf(root.querySelectorAll('*'));

        if (scope === '#slide-intro') {
            triggerIntroAnimations();
            return;
        }

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


    function renderLeaderboard(rows, completionPercent = 20) {
        state.leaderboardData = rows;
        const teams = Array.isArray(rows) ? rows : [];

        const container = document.querySelector('[data-leaderboard], [data-leaderboard-stage]');
        if (!container) return;

        const signature = JSON.stringify((teams || []).slice(0, 8).map((team) => [
            team?.id, team?.rank, team?.team_name, team?.total_score, team?.team_color
        ]));

        if (state.leaderboardSignature === signature && container.querySelector('.orbital-stage-container')) {
            return;
        }
        state.leaderboardSignature = signature;

        // Lead point gap calculation
        const score1 = teams[0] ? Math.round(Number(teams[0].total_score || 0)) : 0;
        const score2 = teams[1] ? Math.round(Number(teams[1].total_score || 0)) : 0;
        const leadGap = Math.max(0, score1 - score2);

        // Map top 4 teams to position 1 (Top), 2 (Right), 3 (Left), 4 (Bottom)
        const posConfigs = [
            { pos: 1, rankStr: '01', defaultColor: '#10b981', isLeading: true },
            { pos: 2, rankStr: '02', defaultColor: '#f43f5e', isLeading: false },
            { pos: 3, rankStr: '03', defaultColor: '#eab308', isLeading: false },
            { pos: 4, rankStr: '04', defaultColor: '#3b82f6', isLeading: false }
        ];

        function getTeamVideoSrc(colorStr) {
            const assets = window.TV_VIDEO_ASSETS || {};
            const c = String(colorStr || '').toLowerCase().trim();
            if (c.includes('blue') || c.includes('cyan') || c.includes('3b82f6') || c.includes('2563eb') || c.includes('0284c7') || c.includes('60a5fa') || c.includes('#3b82f6')) {
                return assets.blue || '/assets/videos/bg-blue.mp4';
            }
            if (c.includes('green') || c.includes('emerald') || c.includes('10b981') || c.includes('22c55e') || c.includes('059669') || c.includes('34d399') || c.includes('#10b981')) {
                return assets.green || '/assets/videos/bg-green.mp4';
            }
            if (c.includes('purple') || c.includes('violet') || c.includes('pink') || c.includes('red') || c.includes('f43f5e') || c.includes('8b5cf6') || c.includes('a855f7') || c.includes('ec4899') || c.includes('6400a6') || c.includes('#f43f5e') || c.includes('#8b5cf6')) {
                return assets.purple || '/assets/videos/bg-purple.mp4';
            }
            return assets.yellow || '/assets/videos/bg-yellow.mp4';
        }

        function syncBackdropVideo(teamColor) {
            const bgVideo = document.getElementById('tvBgVideo');
            if (!bgVideo) return;
            const targetSrc = getTeamVideoSrc(teamColor);
            if (bgVideo.getAttribute('data-current-src') !== targetSrc) {
                bgVideo.setAttribute('data-current-src', targetSrc);
                bgVideo.src = targetSrc;
                bgVideo.load();
                bgVideo.play().catch(() => {});
            }
        }

        const cardsHtml = posConfigs.map((cfg, idx) => {
            const team = teams[idx] || null;
            const color = team && team.team_color ? team.team_color : cfg.defaultColor;
            const name = team ? (team.team_name || team.short_name || '—') : '—';
            const score = team ? Math.round(Number(team.total_score || 0)) : 0;
            const leadingBadge = cfg.isLeading ? `<span class="orbital-badge-leading">👑 CHAMPION LEADER</span>` : '';
            const gapText = cfg.isLeading ? `+${leadGap} PTS LEAD` : `-${Math.max(0, score1 - score)} PTS`;
            const gapClass = cfg.isLeading ? 'leader-gap' : 'chaser-gap';
            const teamVideoSrc = getTeamVideoSrc(color);

            return `
                <div class="orbital-card" data-pos="${cfg.pos}" style="--accent-color: ${escapeHtml(color)};">
                    <!-- Embedded Team Lead Background Video -->
                    <video autoplay loop muted playsinline src="${escapeHtml(teamVideoSrc)}" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0.25; pointer-events: none; border-radius: inherit; z-index: 0;"></video>
                    <div class="orbital-card-dark-wave"></div>
                    <div class="orbital-card-header">
                        ${leadingBadge}
                        <span class="orbital-gap-pill ${gapClass}">${gapText}</span>
                        <span class="orbital-rank-index">${cfg.rankStr}</span>
                    </div>
                    <div class="orbital-team-title" title="${escapeHtml(name)}">${escapeHtml(name)}</div>
                    <div class="orbital-score-wrapper">
                        <span class="orbital-score-digit" data-score="${score}">0</span>
                        <span class="orbital-score-label">MARKS</span>
                    </div>
                </div>
            `;
        }).join('');

        // Progress percentage for central ring
        const pct = Math.min(100, Math.max(0, Math.round(Number(completionPercent || 20))));
        const dashoffset = 326.72 - (326.72 * pct / 100);

        // Extra teams (Rank 5+)
        const extraTeams = teams.slice(4);
        const extraHtml = extraTeams.length > 0 ? `
            <div class="orbital-extra-teams-bar">
                ${extraTeams.map((t, i) => `
                    <div class="orbital-extra-team-item">
                        <span class="tv-team-dot" style="background:${escapeHtml(t.team_color || '#94a3b8')};"></span>
                        <span>0${i + 5} ${escapeHtml(t.team_name || t.short_name)}:</span>
                        <strong style="color: #10b981">${Math.round(Number(t.total_score || 0))}</strong>
                    </div>
                `).join('<span style="color:rgba(15,23,42,0.2)">|</span>')}
            </div>
        ` : '';

        // Top team color for ambient background video & lines
        const rawTopColor = teams[0]?.team_color || '#eab308';
        const firstTeamColor = rawTopColor.startsWith('#') ? rawTopColor : (colorMap[rawTopColor.toLowerCase()] || rawTopColor);
        document.documentElement.style.setProperty('--first-team-color', firstTeamColor);
        document.documentElement.style.setProperty('--top-team-color', firstTeamColor);
        if (container) {
            container.style.setProperty('--first-team-color', firstTeamColor);
            container.style.setProperty('--top-team-color', firstTeamColor);
        }

        // Sync main background video to #1 leading team's color
        syncBackdropVideo(firstTeamColor);

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

            <!-- Dynamic Geometric Chevron Vectors -->
            <svg class="side-chevrons-svg full-screen" viewBox="0 0 1920 1080" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g class="animated-chevron-group">
                    <path class="animated-dash-line" d="M-100 1200 L650 540 L-100 -100" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="3" stroke-linecap="round" />
                    <path class="animated-dash-line" d="M-150 1050 L480 540 L-150 30" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="2" opacity="0.8" stroke-linecap="round" />
                    <line class="animated-cross-line" x1="200" y1="900" x2="420" y2="680" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="2" opacity="0.75" />
                    <line class="animated-cross-line" x1="320" y1="780" x2="540" y2="560" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="2" opacity="0.75" />

                    <path class="animated-dash-line" d="M2020 1200 L1270 540 L2020 -100" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="3" stroke-linecap="round" />
                    <path class="animated-dash-line" d="M2070 1050 L1440 540 L2070 30" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="2" opacity="0.8" stroke-linecap="round" />
                    <line class="animated-cross-line" x1="1720" y1="900" x2="1500" y2="680" stroke="var(--first-team-color, ${firstTeamColor})" stroke-width="2" opacity="0.75" />
                </g>
            </svg>

            <div class="ambient-mesh-bg"></div>

            <div class="orbital-stage-container">
                <!-- Glowing Background Auras behind each card direction -->
                <div class="orbital-aura-bg">
                    <div class="orbital-aura-spot top" style="--card-1-color: ${escapeHtml(teams[0]?.team_color || '#10b981')}"></div>
                    <div class="orbital-aura-spot right" style="--card-2-color: ${escapeHtml(teams[1]?.team_color || '#f43f5e')}"></div>
                    <div class="orbital-aura-spot left" style="--card-3-color: ${escapeHtml(teams[2]?.team_color || '#eab308')}"></div>
                    <div class="orbital-aura-spot bottom" style="--card-4-color: ${escapeHtml(teams[3]?.team_color || '#3b82f6')}"></div>
                </div>

                <!-- SVG Connecting Vector Lines, Outer Ring & Pulsing Lasers -->
                <svg class="orbital-vector-canvas" viewBox="0 0 1050 820" preserveAspectRatio="none">
                    <!-- Outer Connection Ring -->
                    <circle class="orbital-ring-path" cx="525" cy="410" r="290" fill="none" stroke="rgba(15, 23, 42, 0.16)" stroke-width="1.5" />
                    
                    <!-- Horizontal Crosshair -->
                    <line class="orbital-crosshair-line" x1="60" y1="410" x2="990" y2="410" stroke="rgba(15, 23, 42, 0.12)" stroke-width="1.5" />
                    
                    <!-- Vertical Crosshair -->
                    <line class="orbital-crosshair-line" x1="525" y1="50" x2="525" y2="770" stroke="rgba(15, 23, 42, 0.12)" stroke-width="1.5" />

                    <!-- Pulsing Radial Laser Connector Beams -->
                    <g class="constellation-lasers">
                        <line class="constellation-laser-line" x1="525" y1="330" x2="525" y2="135" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="3" stroke-dasharray="16 12" />
                        <line class="constellation-laser-line" x1="605" y1="410" x2="880" y2="410" stroke="${escapeHtml(teams[1]?.team_color || '#f43f5e')}" stroke-width="3" stroke-dasharray="16 12" />
                        <line class="constellation-laser-line" x1="445" y1="410" x2="170" y2="410" stroke="${escapeHtml(teams[2]?.team_color || '#eab308')}" stroke-width="3" stroke-dasharray="16 12" />
                        <line class="constellation-laser-line" x1="525" y1="490" x2="525" y2="685" stroke="${escapeHtml(teams[3]?.team_color || '#3b82f6')}" stroke-width="3" stroke-dasharray="16 12" />
                    </g>
                </svg>

                <!-- Center Islamic 8-Point Star Medallion Node -->
                <div class="orbital-center-node constellation-star-node">
                    <svg class="constellation-star-svg" viewBox="0 0 160 160">
                        <!-- Rotating Outer 8-Point Star Medallion -->
                        <g class="star-rotation-group">
                            <rect x="25" y="25" width="110" height="110" rx="14" fill="none" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="2.5" opacity="0.5" />
                            <rect x="25" y="25" width="110" height="110" rx="14" transform="rotate(45 80 80)" fill="none" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="2.5" opacity="0.5" />
                            <circle cx="80" cy="80" r="68" fill="none" stroke="rgba(15, 23, 42, 0.12)" stroke-width="1.5" stroke-dasharray="6 4" />
                        </g>
                        <!-- Inner Progress Arc -->
                        <circle cx="80" cy="80" r="52" fill="none" stroke="rgba(15, 23, 42, 0.08)" stroke-width="6" />
                        <circle class="orbital-progress-bar" cx="80" cy="80" r="52" fill="none" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="6" stroke-linecap="round" stroke-dasharray="326.72" stroke-dashoffset="${dashoffset}" transform="rotate(-90 80 80)" />
                    </svg>
                    <div class="constellation-center-content">
                        <span class="constellation-kicker">LEAD GAP</span>
                        <span class="constellation-lead-num">+${leadGap}</span>
                        <span class="constellation-unit">PTS</span>
                    </div>
                </div>

                <!-- 4 Quadrant Cards -->
                ${cardsHtml}

                <!-- Extra Teams Bar if 5+ teams -->
                ${extraHtml}
            </div>
        `;

        window.renderLeaderboard = renderLeaderboard;

        triggerLeaderboardAnimations(container);
    }

    function triggerLeaderboardAnimations(customContainer) {
        const root = customContainer || document.querySelector('[data-leaderboard], [data-leaderboard-stage]');
        if (!root || typeof gsap === 'undefined') return;

        const centerNode = root.querySelector('.orbital-center-node');
        const ringPath = root.querySelector('.orbital-ring-path');
        const crosshairLines = root.querySelectorAll('.orbital-crosshair-line');
        const card1 = root.querySelector('.orbital-card[data-pos="1"]');
        const card2 = root.querySelector('.orbital-card[data-pos="2"]');
        const card3 = root.querySelector('.orbital-card[data-pos="3"]');
        const card4 = root.querySelector('.orbital-card[data-pos="4"]');
        const scoreEls = root.querySelectorAll('.orbital-score-digit');

        if (centerNode) {
            gsap.fromTo(centerNode, { scale: 0.5, opacity: 0, rotationZ: -90 }, { scale: 1, opacity: 1, rotationZ: 0, duration: 1.1, ease: 'back.out(1.5)' });
        }

        if (ringPath) {
            gsap.fromTo(ringPath, { opacity: 0, scale: 0.9 }, { opacity: 1, scale: 1, duration: 1.1, ease: 'power2.out' });
        }

        if (crosshairLines.length) {
            gsap.fromTo(crosshairLines, { opacity: 0 }, { opacity: 1, duration: 0.8, delay: 0.2 });
        }

        if (card1) {
            gsap.fromTo(card1, { y: -150, rotationX: -35, scale: 0.8, opacity: 0 }, { y: 0, rotationX: 0, scale: 1, opacity: 1, duration: 0.95, delay: 0.15, ease: 'power4.out' });
        }
        if (card2) {
            gsap.fromTo(card2, { x: 150, rotationY: 35, scale: 0.8, opacity: 0 }, { x: 0, rotationY: 0, scale: 1, opacity: 1, duration: 0.95, delay: 0.25, ease: 'power4.out' });
        }
        if (card3) {
            gsap.fromTo(card3, { x: -150, rotationY: -35, scale: 0.8, opacity: 0 }, { x: 0, rotationY: 0, scale: 1, opacity: 1, duration: 0.95, delay: 0.35, ease: 'power4.out' });
        }
        if (card4) {
            gsap.fromTo(card4, { y: 150, rotationX: 35, scale: 0.8, opacity: 0 }, { y: 0, rotationX: 0, scale: 1, opacity: 1, duration: 0.95, delay: 0.45, ease: 'power4.out' });
        }

        scoreEls.forEach(el => {
            const targetScore = parseInt(el.dataset.score || '0', 10);
            const counter = { val: 0 };
            gsap.to(counter, {
                val: targetScore,
                duration: 1.4,
                delay: 0.3,
                ease: 'power3.out',
                onUpdate: () => {
                    el.textContent = Math.round(counter.val);
                }
            });
        });
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
                // Remove breaks and completed programs from the live display slideshow table
                if (item.type === 'break' || item.status === 'completed' || item.approval_status === 'approved') {
                    return;
                }
                rows.push({
                    ...item,
                    section_name: section.name || 'Schedule',
                    section_time_label: section.time_label || ''
                });
            });
        });

        if (!rows.length && Array.isArray(scheduleData?.timeline)) {
            scheduleData.timeline.forEach((item) => {
                // Remove breaks and completed programs from the live display slideshow table
                if (item.type === 'break' || item.status === 'completed' || item.approval_status === 'approved') {
                    return;
                }
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
                <div class="schedule-table-card">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align: center;">#</th>
                                <th style="width: 140px;">TIME</th>
                                <th>PROGRAM</th>
                                <th style="width: 200px;">STAGE / VENUE</th>
                                <th style="width: 160px; text-align: right;">STATUS</th>
                            </tr>
                        </thead>
                        <tbody data-schedule-page>
                        </tbody>
                    </table>
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

    function renderSchedulePage(index, animateOut = false, isSync = false) {
        const pageEl = els.schedule?.querySelector('[data-schedule-page]');
        if (!pageEl) return;

        const pages = state.schedule.pages || [];
        const page = pages[index] || [];
        const totalPages = Math.max(1, pages.length);

        const badgeEl = els.schedule?.querySelector('[data-schedule-page-badge]');
        if (badgeEl) {
            badgeEl.textContent = `Page ${index + 1} / ${totalPages}`;
        }

        const currentRows = Array.from(pageEl.querySelectorAll('.program-row, .date-header-row'));

        const updateAndAnimateIn = () => {
            if (!page.length) {
                pageEl.innerHTML = `
                    <tr>
                        <td colspan="5" class="tv-schedule-empty" style="padding: 48px; text-align: center; color: #64748b;">
                            <strong style="font-size: 24px; display: block; margin-bottom: 8px; color: #0f172a;">No programs scheduled</strong>
                            <span>Stand by for upcoming competition events.</span>
                        </td>
                    </tr>
                `;
                return;
            }

            const allRows = state.schedule.allRows || flattenScheduleItems(state.schedule.data);
            const liveProgramId = state.currentData?.program?.id || state.schedule.data?.live_program_id || null;

            let runningGlobalIndex = -1;
            if (liveProgramId) {
                runningGlobalIndex = allRows.findIndex(item => Number(item.id) === Number(liveProgramId));
            }
            if (runningGlobalIndex === -1) {
                runningGlobalIndex = allRows.findIndex(item => {
                    return item.status === 'scoring' || item.status === 'active-stage';
                });
            }

            // Dynamically build a map of date -> Day X across all rows
            const uniqueDates = [...new Set(allRows.map(r => r.start_time ? r.start_time.split(' ')[0] : ''))]
                .filter(Boolean)
                .sort();
            const dateToDayMap = {};
            uniqueDates.forEach((d, idx) => {
                dateToDayMap[d] = `Day ${idx + 1}`;
            });

            let html = '';
            let lastDay = null;

            page.forEach((item, rowIndex) => {
                const globalIndex = (index * scheduleRowsPerPage()) + rowIndex;
                const rowNum = String(globalIndex + 1).padStart(2, '0');
                const isCurrentRunning = (globalIndex === runningGlobalIndex && item.type !== 'break') || item.status === 'scoring' || item.status === 'active-stage';
                const isBreak = (item.type === 'break');
                const isCompleted = item.status === 'completed' || item.approval_status === 'approved';
                
                let timeStr = '—';
                if (item.start_label) {
                    timeStr = item.start_label;
                    if (item.end_label) {
                        timeStr += ` - ${item.end_label}`;
                    }
                } else if (item.start_time) {
                    timeStr = item.start_time;
                }

                const rawCategory = item.category || item.class_type_name || item.class_name || item.section_name || '';
                const title = (item.title || item.name || 'Program').toUpperCase();
                const secName = tvFormatScheduleSectionName(rawCategory);
                const venueName = item.venue || item.stage_name || item.room || 'Normal Stage';

                const itemDate = item.start_time ? item.start_time.split(' ')[0] : '';
                const currentDay = dateToDayMap[itemDate] || 'Unknown Day';
                if (lastDay !== currentDay) {
                    html += `
                        <tr class="date-header-row">
                            <td colspan="5" class="date-header">${escapeHtml(currentDay)}</td>
                        </tr>
                    `;
                    lastDay = currentDay;
                }

                let rowClasses = ['program-row'];
                let statusHtml = '';
                let numBadgeHtml = '';

                if (isCurrentRunning) {
                    rowClasses.push('is-running-program');
                    statusHtml = '<span class="status-pill status-in-progress">IN PROGRESS</span>';
                    numBadgeHtml = `<span class="num-badge num-green">| ${rowNum} |</span>`;
                } else if (isCompleted) {
                    statusHtml = '<span class="status-pill status-completed">COMPLETED</span>';
                    numBadgeHtml = `<span class="num-badge num-gray">| ${rowNum} |</span>`;
                } else if (isBreak) {
                    rowClasses.push('is-break');
                    statusHtml = '<span class="status-pill status-break">BREAK</span>';
                    numBadgeHtml = `<span class="num-badge num-amber">| ${rowNum} |</span>`;
                } else {
                    statusHtml = '<span class="status-pill status-upcoming">UPCOMING</span>';
                    numBadgeHtml = `<span class="num-badge num-blue">| ${rowNum} |</span>`;
                }

                const secBadge = secName ? `<span class="program-sec-tag">${escapeHtml(secName)}</span>` : '';

                html += `
                    <tr class="${rowClasses.join(' ')}">
                        <td style="text-align: center;">${numBadgeHtml}</td>
                        <td class="col-time">${escapeHtml(timeStr)}</td>
                        <td class="col-program">${escapeHtml(title)} ${secBadge}</td>
                        <td class="col-venue">${escapeHtml(venueName)}</td>
                        <td style="text-align: right;">${statusHtml}</td>
                    </tr>
                `;
            });

            pageEl.innerHTML = html;

            if (!isSync && typeof gsap !== 'undefined') {
                const rows = pageEl.querySelectorAll('.program-row, .date-header-row');
                gsap.fromTo(rows, {
                    opacity: 0,
                    x: 35,
                    scale: 0.99
                }, {
                    opacity: 1,
                    x: 0,
                    scale: 1,
                    duration: 0.5,
                    stagger: 0.04,
                    ease: 'power2.out',
                    onComplete: () => {
                        gsap.set(rows, { clearProps: 'transform,opacity' });
                    }
                });
            }
        };

        if (animateOut && typeof gsap !== 'undefined' && currentRows.length > 0) {
            gsap.to(currentRows, {
                opacity: 0,
                x: -35,
                duration: 0.28,
                stagger: 0.025,
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

        const wasPlaying = state.schedule.playing;
        state.schedule.signature = signature;
        state.schedule.data = scheduleData || { sections: [] };
        state.schedule.pages = buildSchedulePages(state.schedule.data);

        if (!wasPlaying || !els.schedule.querySelector('[data-schedule-page]')) {
            stopScheduleTimer();
            state.schedule.currentPage = 0;
            renderScheduleFrame(state.schedule.data);
            updateScheduleClock();
            renderSchedulePage(0, false, false);

            if (state.activeSlide === 'schedule') {
                startSchedulePlayback();
            }
        } else {
            renderSchedulePage(state.schedule.currentPage, false, true);
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

    // ----------------------------------------------------
    // Web Audio Synthesizer for Countdown & Celebration
    // ----------------------------------------------------
    function playTone(freq = 440, type = 'sine', duration = 0.15, gainVal = 0.1) {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!window.tvAudioCtx) {
                window.tvAudioCtx = new AudioCtx();
            }
            if (window.tvAudioCtx.state === 'suspended') {
                window.tvAudioCtx.resume();
            }
            const osc = window.tvAudioCtx.createOscillator();
            const gain = window.tvAudioCtx.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(gainVal, window.tvAudioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, window.tvAudioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(window.tvAudioCtx.destination);
            osc.start();
            osc.stop(window.tvAudioCtx.currentTime + duration);
        } catch (_) {}
    }

    function playCelebrationFanfare() {
        setTimeout(() => playTone(523.25, 'triangle', 0.25, 0.15), 0);   // C5
        setTimeout(() => playTone(659.25, 'triangle', 0.25, 0.15), 200); // E5
        setTimeout(() => playTone(783.99, 'triangle', 0.45, 0.25), 400); // G5
        setTimeout(() => playTone(1046.50, 'triangle', 0.6, 0.3), 700);  // C6
    }

    // ----------------------------------------------------
    // 3-Phase Score Update Reveal Overlay Controller
    // ----------------------------------------------------
    let activeRevealTimer = null;
    let isScoreRevealActive = false;

    function checkScoreRevealEvent(revealData) {
        if (!revealData || !revealData.timestamp || isScoreRevealActive) return;

        const storedTs = Number(sessionStorage.getItem('last_score_reveal_ts') || window.LAST_REVEALED_TS || 0);
        if (revealData.timestamp > storedTs) {
            sessionStorage.setItem('last_score_reveal_ts', String(revealData.timestamp));
            window.LAST_REVEALED_TS = revealData.timestamp;
            launchScoreUpdateReveal(revealData);
        }
    }

    function launchScoreUpdateReveal(reveal) {
        isScoreRevealActive = true;
        state.isCelebrating = true;
        stopSlideTimer();
        stopScheduleTimer();

        let overlay = document.getElementById('scoreRevealOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'scoreRevealOverlay';
            overlay.className = 'score-reveal-overlay';
            document.body.appendChild(overlay);
        }

        // Shuffle teams randomly for phase 2 reveal
        const originalTeams = Array.isArray(reveal.teams) ? reveal.teams : [];
        const randomTeams = [...originalTeams].sort(() => Math.random() - 0.5);

        // Programs list included in this update
        const programsList = Array.isArray(reveal.programs) && reveal.programs.length > 0 
            ? reveal.programs 
            : [{ title: reveal.program_title || 'Updated Program', category_name: reveal.category_name || '' }];

        const programCountLabel = programsList.length > 1 
            ? `${programsList.length} UPDATED PROGRAMS APPROVED` 
            : 'NEW PROGRAM SCORES APPROVED';

        // Render base overlay HTML
        overlay.innerHTML = `
            <button type="button" class="reveal-close-btn" id="btnCloseReveal"><i class="fa-solid fa-xmark mr-1"></i> Close</button>
            <div class="reveal-header-badge">
                <span class="pulse-dot-red"></span> ${escapeHtml(programCountLabel)}
            </div>
            <h1 class="reveal-title">UPDATED TEAM STANDINGS</h1>
            
            <div class="reveal-programs-list">
                ${programsList.map(p => `
                    <div class="reveal-program-chip">
                        <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i>
                        <span>${escapeHtml(p.title)}</span>
                        ${p.category_name ? `<small>(${escapeHtml(p.category_name)})</small>` : ''}
                    </div>
                `).join('')}
            </div>
            
            <div style="font-size: 15px; font-weight: 800; color: #64748b; letter-spacing: 2px; text-transform: uppercase;" id="revealStatusText">
                REVEALING UPDATED TEAM SCORES...
            </div>
        `;

        requestAnimationFrame(() => overlay.classList.add('is-active'));

        document.getElementById('btnCloseReveal')?.addEventListener('click', closeScoreRevealOverlay);

        // Play reveal start tone and display results immediately (bypass 10s wait)
        playTone(1100, 'triangle', 0.4, 0.25);
        startPhase2TeamReveal(overlay, reveal, randomTeams, programsList);
    }

    function startPhase2TeamReveal(overlay, reveal, randomTeams, programsList) {
        const titleEl = overlay.querySelector('.reveal-title');
        const countBox = document.getElementById('revealCountdownBox');
        const statusText = document.getElementById('revealStatusText');

        if (titleEl) titleEl.textContent = 'UPDATED TEAM STANDINGS';
        if (statusText) statusText.textContent = 'REVEALING UPDATED TEAM SCORES...';

        if (countBox) countBox.style.display = 'none';

        // Sort teams by total score for final ranking display
        const sortedTeams = [...randomTeams].sort((a, b) => (Number(b.total_score) || 0) - (Number(a.total_score) || 0));
        const highestGainer = [...randomTeams].sort((a, b) => (Number(b.program_points) || 0) - (Number(a.program_points) || 0))[0];

        // Build Team Cards HTML
        const gridHtml = `
            <div class="reveal-teams-grid" id="revealTeamsGrid">
                ${randomTeams.map((t, idx) => {
                    const gainedPts = Number(t.program_points || 0);
                    const breakdownEntries = t.breakdown && typeof t.breakdown === 'object' ? Object.entries(t.breakdown) : [];
                    return `
                        <div class="reveal-team-card reveal-card-stagger" data-team-id="${t.id}" style="animation-delay: ${idx * 0.15}s;">
                            <div class="reveal-team-header">
                                ${colorDot(t.team_color)} ${escapeHtml(t.team_name)}
                            </div>
                            <div class="reveal-team-score" data-target-score="${t.total_score}">0</div>
                            <div style="margin-top: 6px;">
                                ${gainedPts > 0 
                                    ? `<span class="reveal-gained-pts-badge"><i class="fa-solid fa-arrow-up-right mr-1"></i> +${gainedPts} Pts Total Gained</span>`
                                    : `<span style="font-size: 12px; font-weight: 700; color: #94a3b8;">No Points Added</span>`
                                }
                            </div>
                            ${breakdownEntries.length > 0 ? `
                                <div class="reveal-breakdown-box">
                                    ${breakdownEntries.map(([pTitle, pPts]) => `
                                        <div class="reveal-breakdown-item">
                                            <span>${escapeHtml(pTitle)}</span>
                                            <strong>+${pPts}</strong>
                                        </div>
                                    `).join('')}
                                </div>
                            ` : ''}
                        </div>
                    `;
                }).join('')}
            </div>
            
            <div style="margin-top: 28px; text-align: center;" id="revealDoneContainer">
                <button type="button" class="btn btn-primary btn-md" id="btnDoneReveal" style="padding: 12px 40px; border-radius: 999px; font-weight: 800; font-size: 15px; background: linear-gradient(135deg, #4f46e5, #3b82f6); color: #fff; border: none; box-shadow: 0 8px 24px rgba(79, 70, 229, 0.35); cursor: pointer; transition: all 0.2s ease;">
                    Done & Return to Live Display
                </button>
            </div>
        `;

        const existingGrid = overlay.querySelector('.reveal-teams-grid');
        if (existingGrid) existingGrid.remove();
        const existingDone = document.getElementById('revealDoneContainer');
        if (existingDone) existingDone.remove();

        overlay.insertAdjacentHTML('beforeend', gridHtml);
        document.getElementById('btnDoneReveal')?.addEventListener('click', closeScoreRevealOverlay);

        // Stage 1: Slot Counter Roll Animation for Scores (0s - 4.5s)
        overlay.querySelectorAll('.reveal-team-score').forEach(el => {
            const target = Number(el.dataset.targetScore || 0);
            let current = 0;
            const step = Math.max(1, target / 40);
            const rollTimer = setInterval(() => {
                current += step;
                if (current >= target) {
                    current = target;
                    clearInterval(rollTimer);
                }
                el.textContent = Math.round(current);
            }, 50);
        });

        // Stage 2: Highlight Points Gainers (At 5.0 seconds)
        setTimeout(() => {
            if (statusText) statusText.textContent = '⚡ HIGHLIGHTING PROGRAM SCORE GAINS...';
            
            if (highestGainer && Number(highestGainer.program_points || 0) > 0) {
                const gainerCard = overlay.querySelector(`.reveal-team-card[data-team-id="${highestGainer.id}"]`);
                if (gainerCard) {
                    gainerCard.classList.add('is-highest-gainer');
                    gainerCard.insertAdjacentHTML('afterbegin', `<div class="reveal-top-gainer-badge"><i class="fa-solid fa-bolt mr-1"></i> TOP POINTS GAINER (+${highestGainer.program_points} PTS)</div>`);
                    playTone(784, 'triangle', 0.2, 0.15);
                }
            }

            // Stage 3: Grand Coronation of #1 Overall Rank Leader (At 10.0 seconds)
            setTimeout(() => {
                if (statusText) statusText.textContent = '👑 ANNOUNCING OVERALL LEADERBOARD CHAMPION...';

                const topTeam = reveal.top_team || sortedTeams[0];
                if (topTeam) {
                    const topCard = overlay.querySelector(`.reveal-team-card[data-team-id="${topTeam.id}"]`);
                    if (topCard) {
                        topCard.classList.add('is-top-rank');
                        topCard.insertAdjacentHTML('afterbegin', `<div class="reveal-rank1-banner">👑 OVERALL RANK #1 LEADER</div>`);
                        playCelebrationFanfare();
                    }
                }

                // Stage 4: Showcase Complete & Final Status (At 15.0 seconds)
                setTimeout(() => {
                    if (statusText) statusText.textContent = '🎉 SCORE REVEAL COMPLETE · LEADERBOARD UPDATED';
                }, 5000);

            }, 5000);

        }, 5000);

        // Auto-close overlay after 20 seconds
        activeRevealTimer = setTimeout(() => {
            closeScoreRevealOverlay();
        }, 20000);
    }

    function startPhase3ProgramBreakdown(overlay, reveal) {
        const titleEl = overlay.querySelector('.reveal-title');
        const statusText = document.getElementById('revealStatusText');
        const teamsGrid = overlay.querySelector('.reveal-teams-grid');

        if (titleEl) titleEl.textContent = 'PROGRAM MARKS & WINNERS';
        if (statusText) statusText.style.display = 'none';
        if (teamsGrid) teamsGrid.style.display = 'none';

        const entries = Array.isArray(reveal.entries) ? reveal.entries : [];

        const winnersHtml = `
            <div class="reveal-winners-container">
                <div class="reveal-winners-grid">
                    ${entries.map((e) => {
                        const rank = Number(e.final_rank || 0);
                        const badge = rank === 1 ? '🥇 1st Rank' : (rank === 2 ? '🥈 2nd Rank' : (rank === 3 ? '🥉 3rd Rank' : `⭐ Rank ${rank} (+3 Bonus)`));
                        return `
                            <div class="reveal-winner-card">
                                <div>
                                    <div style="font-size: 11px; font-weight: 800; color: #38bdf8; text-transform: uppercase;">${badge}</div>
                                    <div style="font-size: 16px; font-weight: 800; color: #fff; margin: 2px 0;">${escapeHtml(e.entry_name)}</div>
                                    <div style="font-size: 12px; color: rgba(255,255,255,0.7); display: flex; align-items: center; gap: 6px;">
                                        ${colorDot(e.team_color)} ${escapeHtml(e.team_name)} ${e.entry_number ? `&bull; Chest #${escapeHtml(e.entry_number)}` : ''}
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 18px; font-weight: 900; color: #10b981;">+${e.team_score} Pts</div>
                                    <div style="font-size: 11px; color: rgba(255,255,255,0.6);">${Number(e.final_score).toFixed(2)} Marks</div>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>

                <div style="margin-top: 24px; text-align: center;">
                    <button type="button" class="btn btn-primary btn-md" id="btnDoneReveal" style="padding: 10px 30px; border-radius: 999px; font-weight: 800; font-size: 14px;">
                        Done & Return to Live Display
                    </button>
                </div>
            </div>
        `;

        overlay.insertAdjacentHTML('beforeend', winnersHtml);

        document.getElementById('btnDoneReveal')?.addEventListener('click', closeScoreRevealOverlay);

        // Auto-close overlay after 16 seconds
        activeRevealTimer = setTimeout(() => {
            closeScoreRevealOverlay();
        }, 16000);
    }

    function closeScoreRevealOverlay() {
        if (activeRevealTimer) clearTimeout(activeRevealTimer);
        const overlay = document.getElementById('scoreRevealOverlay');
        if (overlay) {
            overlay.classList.remove('is-active');
            setTimeout(() => {
                overlay.remove();
                isScoreRevealActive = false;
                state.isCelebrating = false;
                if (state.mode === 'auto') {
                    startRotation();
                }
            }, 500);
        }
    }

    function applyBootstrap(data) {
        if (!data) return;

        const settings = data.settings || {};
        const wasAuto = state.mode === 'auto';
        const wasPlaying = state.is_playing;

        // Sync playback state
        if (typeof settings.is_playing === 'boolean') state.is_playing = settings.is_playing;
        if (settings.mode) state.mode = window.IS_SINGLE_PAGE ? 'manual' : settings.mode;
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

        // Compare DOM-rendered slides with the newly fetched settings.
        // If slide configurations (enabled/disabled) differ, reload the page to render updated structures.
        const currentDomSlideKeys = Array.from(document.querySelectorAll('.tv-slide'))
            .map(el => el.dataset.slide)
            .filter(Boolean)
            .sort();

        const newEnabledSlideKeys = Object.values(slidesMap)
            .filter(s => s.enabled !== false)
            .map(s => s.key || s.slide_key)
            .filter(Boolean)
            .sort();

        if (!window.IS_SINGLE_PAGE && JSON.stringify(currentDomSlideKeys) !== JSON.stringify(newEnabledSlideKeys)) {
            window.location.reload();
            return;
        }

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
                    duration: slide.duration || (key === 'intro' ? 10000 : 5000),
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
        if (data.score_reveal) checkScoreRevealEvent(data.score_reveal);

        // Control slideshow flow based on mode settings
        if (state.mode === 'manual') {
            stopSlideTimer();
            if (!window.IS_SINGLE_PAGE && settings.active_slide && state.activeSlide !== settings.active_slide) {
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

    function initViewportScaler() {
        const scaler = document.getElementById('tvViewportScaler');
        if (!scaler) return;

        const DESIGN_WIDTH = 1920;
        const DESIGN_HEIGHT = 1080;

        const updateScale = () => {
            const vw = window.innerWidth;
            const vh = window.innerHeight;

            // Compute uniform scale factor fitting 1920x1080 landscape canvas inside current screen width & height
            const scale = Math.min(vw / DESIGN_WIDTH, vh / DESIGN_HEIGHT);

            scaler.style.position = 'absolute';
            scaler.style.width = `${DESIGN_WIDTH}px`;
            scaler.style.height = `${DESIGN_HEIGHT}px`;
            scaler.style.left = '50%';
            scaler.style.top = '50%';
            scaler.style.transformOrigin = 'center center';
            scaler.style.transform = `translate(-50%, -50%) scale(${scale})`;
            scaler.style.margin = '0';
            scaler.style.overflow = 'hidden';
            scaler.style.boxSizing = 'border-box';
        };

        updateScale();

        window.addEventListener('resize', updateScale, { passive: true });
        window.addEventListener('orientationchange', () => setTimeout(updateScale, 150), { passive: true });

        // Attempt locking orientation to landscape if Screen Orientation API is supported
        if (screen.orientation && typeof screen.orientation.lock === 'function') {
            screen.orientation.lock('landscape').catch(() => {});
        }
    }

    window.toggleTvFullscreen = function() {
        const doc = window.document;
        const docEl = doc.documentElement;

        const requestFS = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen || docEl.msRequestFullscreen;
        const cancelFS = doc.exitFullscreen || doc.webkitExitFullscreen || doc.mozCancelFullScreen || doc.msExitFullscreen;

        const isFS = doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement;

        if (!isFS) {
            if (requestFS) {
                const fsPromise = requestFS.call(docEl);
                if (fsPromise && typeof fsPromise.then === 'function') {
                    fsPromise.then(() => {
                        if (screen.orientation && typeof screen.orientation.lock === 'function') {
                            screen.orientation.lock('landscape').catch(() => {});
                        }
                    }).catch(() => {});
                }
            }
        } else {
            if (cancelFS) {
                cancelFS.call(doc).catch(() => {});
            }
        }
    };

    function updateFsIcon() {
        const doc = window.document;
        const isFS = !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement);
        const fsIcon = document.getElementById('tvFsIcon');
        if (fsIcon) {
            fsIcon.className = isFS ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
        }
    }

    ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(evt => {
        document.addEventListener(evt, updateFsIcon, { passive: true });
    });

    let tvWakeLock = null;
    async function initWakeLock() {
        if ('wakeLock' in navigator) {
            try {
                tvWakeLock = await navigator.wakeLock.request('screen');
            } catch (_) {}
        }
    }

    function initIdleCursor() {
        let idleTimer = null;
        const resetTimer = () => {
            document.body.classList.remove('tv-idle-cursor');
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                document.body.classList.add('tv-idle-cursor');
            }, 3000);
        };

        window.addEventListener('mousemove', resetTimer, { passive: true });
        window.addEventListener('touchstart', resetTimer, { passive: true });
        resetTimer();
    }

    function initBroadcastChannel() {
        if (typeof BroadcastChannel !== 'undefined') {
            try {
                window.tvBroadcastChannel = new BroadcastChannel('musabaqa_tv_sync');
                window.tvBroadcastChannel.onmessage = (event) => {
                    if (event.data && event.data.type === 'SLIDE_CHANGE') {
                        setActiveSlide(event.data.slide);
                    }
                };
            } catch (_) {}
        }
    }

    function setActiveSlide(key) {
        if (!key) return;
        let normalizedKey = String(key).replace('_', '-');
        if (normalizedKey === 'current-programs') {
            normalizedKey = 'current-program';
        }

        const currentActive = document.querySelector('.tv-slide.tv-slide--active');
        const nextTarget = document.querySelector(`.tv-slide[data-slide="${normalizedKey}"]`) || document.getElementById(`slide-${normalizedKey}`);

        if (!nextTarget) {
            return;
        }

        if (state.activeSlide === normalizedKey && currentActive === nextTarget) {
            return;
        }

        const slides = Array.from(document.querySelectorAll('.tv-slide'));
        els.body.classList.toggle('tv-schedule-active', normalizedKey === 'schedule');
        state.activeSlide = normalizedKey;

        if (window.tvBroadcastChannel) {
            try {
                window.tvBroadcastChannel.postMessage({ type: 'SLIDE_CHANGE', slide: normalizedKey });
            } catch (_) {}
        }

        if (currentActive && nextTarget && currentActive !== nextTarget) {
            currentActive.classList.add('tv-slide--exiting');
            currentActive.classList.remove('tv-slide--active');

            setTimeout(() => {
                currentActive.classList.remove('tv-slide--exiting');
                slides.forEach(s => {
                    if (s !== nextTarget) {
                        s.classList.remove('tv-slide--active', 'tv-slide--exiting');
                    }
                });

                nextTarget.classList.add('tv-slide--active');

                if (normalizedKey !== 'schedule') {
                    stopScheduleTimer();
                }

                if (normalizedKey === 'intro') {
                    const video = document.querySelector('[data-intro-video]') || document.querySelector('.tv-intro-video video');
                    if (video) {
                        try {
                            video.currentTime = 0;
                            video.play().catch(() => {});
                        } catch (_) {}
                    }
                }

                animateEntrance(`#slide-${normalizedKey}`);

                if (normalizedKey === 'schedule') {
                    startSchedulePlayback();
                }
            }, 400);
        } else {
            slides.forEach(slide => {
                const slideKey = slide.dataset.slide || (slide.id ? slide.id.replace('slide-', '') : '');
                if (slideKey === normalizedKey || slide.id === `slide-${normalizedKey}`) {
                    slide.classList.add('tv-slide--active');
                } else {
                    slide.classList.remove('tv-slide--active', 'tv-slide--exiting');
                }
            });

            if (normalizedKey !== 'schedule') {
                stopScheduleTimer();
            }

            if (normalizedKey === 'intro') {
                const video = document.querySelector('[data-intro-video]') || document.querySelector('.tv-intro-video video');
                if (video) {
                    try {
                        video.currentTime = 0;
                        video.play().catch(() => {});
                    } catch (_) {}
                }
            }

            animateEntrance(`#slide-${normalizedKey}`);

            if (normalizedKey === 'schedule') {
                startSchedulePlayback();
            }
        }
    }

    function getNextEnabledSlide() {
        const enabledKeys = state.slideOrder.filter(key => {
            const slideConf = state.slides[key];
            const hasEl = document.querySelector(`.tv-slide[data-slide="${key}"]`) || document.getElementById(`slide-${key}`);
            return slideConf && slideConf.enabled !== false && hasEl;
        });

        if (enabledKeys.length === 0) {
            const firstEl = document.querySelector('.tv-slide');
            return firstEl ? (firstEl.dataset.slide || firstEl.id.replace('slide-', '')) : 'intro';
        }

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
        initViewportScaler();
        initWakeLock();
        initIdleCursor();
        initBroadcastChannel();

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
                initWakeLock();
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
