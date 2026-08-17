/* global gsap, particlesJS, TV_BOOT */
(() => {
    'use strict';

    const isLowEndDevice = 
        (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) ||
        (navigator.deviceMemory && navigator.deviceMemory < 4) ||
        /SmartTV|GoogleTV|AppleTV|HbbTV|Tizen|WebOS|Android 9|Android 8|Android 7|Android 6|Android 5/i.test(navigator.userAgent) ||
        window.location.search.includes('perf=low');

    if (isLowEndDevice) {
        document.documentElement.classList.add('low-perf-device');
        window.isFlowCanvasPaused = true;
    }

    const state = {
        activeSlide: 'intro',
        is_playing: true,
        mode: 'auto',
        theme: 'emerald',
        slides: {},
        leaderboardData: null,
        leaderboardSignature: '',
        slideOrder: ['intro', 'leaderboard', 'schedule', 'current-program'],
        serverTimeOffset: 0,
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
        },
        // A live action can temporarily bring a slide to the front without
        // turning off the automatic slideshow.
        actionOverride: null,
        lastUpdatedTimestamp: null
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

            // Update MC stage timer
            updateStageTimer();
        };

        update();
        state.timers.clock = setInterval(update, 1000);
    }

    function updateStageTimer() {
        const timerDisplay = document.getElementById('stageTimerDisplay');
        const timerBox = document.getElementById('stageTimerBox');
        if (!timerDisplay) return;

        const running = state.live_timer_running || 0;
        const startTime = state.live_timer_start_time || 0;
        const elapsedOffset = state.live_timer_elapsed || 0;

        const currentData = state.current_program_data || {};
        const performer = currentData.performer || {};
        const isIntro = !performer.name || performer.name === 'Awaiting Performer';
        const isBreak = currentData.is_break;

        if (!isIntro && !isBreak) {
            let totalElapsedMs = elapsedOffset;
            if (running > 0 && startTime > 0) {
                const serverTimeMs = Date.now() + (state.serverClockOffset || 0);
                const deltaMs = serverTimeMs - (startTime * 1000);
                if (deltaMs > 0) {
                    totalElapsedMs = deltaMs;
                }
            }

            const totalSeconds = Math.floor(totalElapsedMs / 1000);
            const mins = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const secs = String(totalSeconds % 60).padStart(2, '0');
            timerDisplay.textContent = `${mins}:${secs}`;
            if (timerBox) {
                timerBox.style.display = 'flex';
                if (running > 0) {
                    timerBox.classList.add('is-running');
                } else {
                    timerBox.classList.remove('is-running');
                }
            }
        } else {
            timerDisplay.textContent = '00:00';
            if (timerBox) {
                timerBox.style.display = 'none';
                timerBox.classList.remove('is-running');
            }
        }
    }

    function initParticles() {
        // Replaced canvas teardown particlesJS with hardware-accelerated CSS ambient particles
    }

    function getSlideElements() {
        return Array.from(document.querySelectorAll('.tv-slide'));
    }

    function getTeamVideoSrc(colorStr) {
        const assets = window.TV_VIDEO_ASSETS || {};
        const c = String(colorStr || '').toLowerCase().trim();

        // 1. Direct name/keyword checks
        if (c.includes('blue') || c.includes('cyan')) return assets.blue || 'assets/videos/bg-blue.mp4';
        if (c.includes('green') || c.includes('emerald')) return assets.green || 'assets/videos/bg-green.mp4';
        if (c.includes('purple') || c.includes('violet') || c.includes('pink') || c.includes('red') || c.includes('magenta')) return assets.purple || 'assets/videos/bg-purple.mp4';
        if (c.includes('yellow') || c.includes('gold') || c.includes('amber')) return assets.yellow || 'assets/videos/bg-yellow.mp4';

        // 2. Parse RGB values from Hex or RGB string
        let r = 0, g = 0, b = 0, parsed = false;

        const hexMatch = c.match(/^#?([0-9a-f]{6})$/i);
        if (hexMatch) {
            r = parseInt(hexMatch[1].substring(0, 2), 16);
            g = parseInt(hexMatch[1].substring(2, 4), 16);
            b = parseInt(hexMatch[1].substring(4, 6), 16);
            parsed = true;
        } else {
            const rgbMatch = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
            if (rgbMatch) {
                r = parseInt(rgbMatch[1], 10);
                g = parseInt(rgbMatch[2], 10);
                b = parseInt(rgbMatch[3], 10);
                parsed = true;
            }
        }

        if (parsed) {
            // High Red & High Green with Low/Moderate Blue -> Yellow / Gold
            if (r > 150 && g > 130 && b < 140) {
                return assets.yellow || 'assets/videos/bg-yellow.mp4';
            }
            // Dominant Blue
            if (b >= r && b >= g) {
                return assets.blue || 'assets/videos/bg-blue.mp4';
            }
            // Dominant Green
            if (g >= r && g >= b) {
                return assets.green || 'assets/videos/bg-green.mp4';
            }
            // Dominant Red / Purple / Pink
            if (r >= g || b > g) {
                return assets.purple || 'assets/videos/bg-purple.mp4';
            }
        }

        return assets.yellow || 'assets/videos/bg-yellow.mp4';
    }

    // Canvas Flow Engine - Premium Light-themed Waving Wind Lines & Particles
    let windColor = { r: 16, g: 185, b: 129, targetR: 16, targetG: 185, targetB: 129 };
    let flowParticles = [];

    function updateParticleTargets(rgb) {
        flowParticles.forEach(p => {
            p.targetR = rgb.r + (Math.random() - 0.5) * 25;
            p.targetG = rgb.g + (Math.random() - 0.5) * 25;
            p.targetB = rgb.b + (Math.random() - 0.5) * 25;
        });
    }

    function initFlowCanvas() {
        const canvas = document.getElementById('flowCanvas');
        if (!canvas) return;
        if (isLowEndDevice) {
            canvas.style.display = 'none';
            return;
        }
        const ctx = canvas.getContext('2d');

        let width = canvas.width = window.innerWidth;
        let height = canvas.height = window.innerHeight;

        window.addEventListener('resize', () => {
            if (!canvas) return;
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        });

        // Soft micro-particles (Slowed down to match wind speed)
        class MorphedParticle {
            constructor() {
                this.reset(true);
            }

            reset(initial = false) {
                this.x = Math.random() * width;
                this.y = initial ? Math.random() * height : height + 10;
                this.size = Math.random() * 3 + 1.2;
                this.vx = (Math.random() - 0.5) * 0.15;
                this.vy = -Math.random() * 0.12 - 0.06; // Ultra slow upward float
                this.alpha = Math.random() * 0.22 + 0.12;
                
                this.r = windColor.r + (Math.random() - 0.5) * 20;
                this.g = windColor.g + (Math.random() - 0.5) * 20;
                this.b = windColor.b + (Math.random() - 0.5) * 20;

                this.targetR = this.r;
                this.targetG = this.g;
                this.targetB = this.b;
            }

            update() {
                this.x += this.vx;
                this.y += this.vy;
                this.alpha -= 0.0006;

                // Interpolate colors smoothly
                this.r += (this.targetR - this.r) * 0.05;
                this.g += (this.targetG - this.g) * 0.05;
                this.b += (this.targetB - this.b) * 0.05;

                if (this.y < -this.size || this.alpha <= 0) {
                    this.reset(false);
                }
            }

            draw() {
                const colorStr = `rgb(${Math.round(this.r)}, ${Math.round(this.g)}, ${Math.round(this.b)})`;
                ctx.save();
                ctx.globalAlpha = this.alpha;
                ctx.fillStyle = colorStr;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            }
        }

        flowParticles = [];
        for (let i = 0; i < 30; i++) {
            flowParticles.push(new MorphedParticle());
        }

        // Draw Flowing Silk Wind Lines (Slower and more visible)
        function drawWindFlow() {
            // Time multiplier 0.00015 for slower movement
            const time = Date.now() * 0.00015;
            const linesCount = 5;

            // Interpolate global wind color towards target color
            windColor.r += (windColor.targetR - windColor.r) * 0.05;
            windColor.g += (windColor.targetG - windColor.g) * 0.05;
            windColor.b += (windColor.targetB - windColor.b) * 0.05;

            const baseColor = `rgb(${Math.round(windColor.r)}, ${Math.round(windColor.g)}, ${Math.round(windColor.b)})`;

            for (let i = 0; i < linesCount; i++) {
                ctx.save();
                
                // Vertical position spacing
                const yBase = (height / (linesCount + 1)) * (i + 1);

                // Horizontal gradient fade at edges - Peak opacity 75% for high visibility
                const lineGrad = ctx.createLinearGradient(0, 0, width, 0);
                lineGrad.addColorStop(0, 'rgba(255, 255, 255, 0)');
                lineGrad.addColorStop(0.15, `rgba(${Math.round(windColor.r)}, ${Math.round(windColor.g)}, ${Math.round(windColor.b)}, 0.25)`);
                lineGrad.addColorStop(0.5, `rgba(${Math.round(windColor.r)}, ${Math.round(windColor.g)}, ${Math.round(windColor.b)}, 0.75)`);
                lineGrad.addColorStop(0.85, `rgba(${Math.round(windColor.r)}, ${Math.round(windColor.g)}, ${Math.round(windColor.b)}, 0.25)`);
                lineGrad.addColorStop(1, 'rgba(255, 255, 255, 0)');

                ctx.strokeStyle = lineGrad;
                ctx.lineWidth = i % 2 === 0 ? 4.5 : 2.5; // Thickness 2.5px to 4.5px
                
                // Neon blur glow blur size 15
                ctx.shadowColor = baseColor;
                ctx.shadowBlur = 15;

                ctx.beginPath();

                const step = 25;
                for (let x = 0; x <= width + step; x += step) {
                    const wave1 = Math.sin(x * 0.0025 + time * 1.5 + i * 1.7) * 45;
                    const wave2 = Math.cos(x * 0.005 - time * 0.8 + i * 2.3) * 20;
                    const wave3 = Math.sin(x * 0.001 + time * 0.4) * 30;

                    const y = yBase + wave1 + wave2 + wave3;

                    if (x === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                }

                ctx.stroke();
                ctx.restore();
            }
        }

        function animate() {
            if (!window.isFlowCanvasPaused) {
                ctx.clearRect(0, 0, width, height);
                drawWindFlow();
                flowParticles.forEach(p => {
                    p.update();
                    p.draw();
                });
            }
            requestAnimationFrame(animate);
        }

        animate();
    }

    if (document.readyState !== 'loading') {
        initFlowCanvas();
    } else {
        document.addEventListener('DOMContentLoaded', initFlowCanvas);
    }

    function syncBackdropVideo(teamColor) {
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
    }

    let introVideoIndex = 0;
    let introTimeline = null;

    function playNextIntroVideo() {
        const videoEl = document.getElementById('introVideoPlayer') || document.querySelector('[data-intro-video]');
        if (!videoEl) return;

        if (videoEl.paused) {
            videoEl.play().catch(() => {});
        }
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


    function renderLeaderboardStageInto(container, rows, completionPercent = 20) {
        if (!container) return;
        const teams = Array.isArray(rows) ? rows : [];

        // Calculate dynamic team ranks with proper tie handling
        let currentRank = 1;
        let prevScore = null;
        teams.forEach((t, idx) => {
            const s = Math.round(Number(t.total_score || 0));
            if (t.rank && Number(t.rank) > 0) {
                t.displayRankStr = String(t.rank).padStart(2, '0');
            } else {
                if (prevScore !== null && s < prevScore) {
                    currentRank = idx + 1;
                }
                t.displayRankStr = String(currentRank).padStart(2, '0');
                prevScore = s;
            }
        });

        // Lead point gap calculation
        const score1 = teams[0] ? Math.round(Number(teams[0].total_score || 0)) : 0;
        const score2 = teams[1] ? Math.round(Number(teams[1].total_score || 0)) : 0;
        const leadGap = Math.max(0, score1 - score2);

        // Map top 4 teams to position 1 (Top/Center), 2 (Right/Silver), 3 (Left/Bronze), 4 (Bottom/Runner-up)
        const posConfigs = [
            { pos: 1, rankStr: '01', defaultColor: '#10b981', isLeading: true, icon: 'fa-crown text-yellow-400' },
            { pos: 2, rankStr: '02', defaultColor: '#f43f5e', isLeading: false, icon: 'fa-medal text-slate-300' },
            { pos: 3, rankStr: '03', defaultColor: '#eab308', isLeading: false, icon: 'fa-medal text-amber-600' },
            { pos: 4, rankStr: '04', defaultColor: '#3b82f6', isLeading: false, icon: 'fa-award text-blue-400' }
        ];

        // Sync global background video with top 1st rank team color smoothly
        const leadingTeamColor = teams[0] && teams[0].team_color ? teams[0].team_color : '#10b981';
        syncBackdropVideo(leadingTeamColor);

        const cardsHtml = posConfigs.map((cfg, idx) => {
            const team = teams[idx] || null;
            const color = team && team.team_color ? team.team_color : cfg.defaultColor;
            const name = team ? (team.team_name || team.short_name || '—') : '—';
            const score = team ? Math.round(Number(team.total_score || 0)) : 0;
            const rankStr = team ? team.displayRankStr : cfg.rankStr;
            const gapText = cfg.isLeading ? `+${leadGap} PTS LEAD` : `-${Math.max(0, score1 - score)} PTS`;
            const gapClass = cfg.isLeading ? 'leader-gap' : 'chaser-gap';
            const badgeContent = `<span class="orbital-gap-pill ${gapClass}">${gapText}</span>`;
            const rankIcon = cfg.icon ? `<i class="fa-solid ${cfg.icon} mr-1" style="font-size:0.9em; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15));"></i>` : '';

            return `
                <div class="orbital-card" data-pos="${cfg.pos}" style="--accent-color: ${escapeHtml(color)};">
                    <div class="orbital-card-dark-wave"></div>
                    <div class="orbital-card-header">
                        ${badgeContent}
                        <span class="orbital-rank-index">${rankIcon}${rankStr}</span>
                    </div>
                    <div class="orbital-team-title" title="${escapeHtml(name)}">${escapeHtml(name)}</div>
                    <div class="orbital-score-wrapper">
                        <span class="orbital-score-digit" data-score="${score}">${score}</span>
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
                        <span>${t.displayRankStr || ('0' + (i + 5))} ${escapeHtml(t.team_name || t.short_name)}:</span>
                        <strong style="color: #10b981">${Math.round(Number(t.total_score || 0))}</strong>
                    </div>
                `).join('<span style="color:rgba(15,23,42,0.2)">|</span>')}
            </div>
        ` : '';

        // Top team color for ambient background video & lines & flow canvas
        const rawTopColor = teams[0]?.team_color || '#eab308';
        const firstTeamColor = rawTopColor.startsWith('#') ? rawTopColor : (colorMap[rawTopColor.toLowerCase()] || rawTopColor);
        document.documentElement.style.setProperty('--first-team-color', firstTeamColor);
        document.documentElement.style.setProperty('--top-team-color', firstTeamColor);
        container.style.setProperty('--first-team-color', firstTeamColor);
        container.style.setProperty('--top-team-color', firstTeamColor);
        syncBackdropVideo(firstTeamColor);

        container.className = 'tv-leaderboard-stage-root';
        container.innerHTML = `
            <div class="orbital-stage-container" style="position: relative; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                <svg class="orbital-vector-canvas" viewBox="0 0 1050 820" preserveAspectRatio="none">
                    <g class="constellation-lasers">
                        <line class="constellation-laser-line" x1="525" y1="330" x2="525" y2="285" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="3" stroke-dasharray="16 12" />
                        <line class="constellation-laser-line" x1="605" y1="410" x2="660" y2="410" stroke="${escapeHtml(teams[1]?.team_color || '#f43f5e')}" stroke-width="3" stroke-dasharray="16 12" />
                        <line class="constellation-laser-line" x1="445" y1="410" x2="390" y2="410" stroke="${escapeHtml(teams[2]?.team_color || '#eab308')}" stroke-width="3" stroke-dasharray="16 12" />
                        <line class="constellation-laser-line" x1="525" y1="490" x2="525" y2="535" stroke="${escapeHtml(teams[3]?.team_color || '#3b82f6')}" stroke-width="3" stroke-dasharray="16 12" />
                    </g>
                </svg>

                <div class="orbital-center-node constellation-star-node">
                    <svg class="constellation-star-svg" viewBox="0 0 160 160">
                        <g class="star-rotation-group">
                            <rect x="25" y="25" width="110" height="110" rx="14" fill="none" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="2.5" opacity="0.5" />
                            <rect x="25" y="25" width="110" height="110" rx="14" transform="rotate(45 80 80)" fill="none" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="2.5" opacity="0.5" />
                            <circle cx="80" cy="80" r="68" fill="none" stroke="rgba(255, 255, 255, 0.2)" stroke-width="1.5" stroke-dasharray="6 4" />
                        </g>
                        <circle cx="80" cy="80" r="52" fill="none" stroke="rgba(255, 255, 255, 0.1)" stroke-width="6" />
                        <circle class="orbital-progress-bar" cx="80" cy="80" r="52" fill="none" stroke="${escapeHtml(teams[0]?.team_color || '#10b981')}" stroke-width="6" stroke-linecap="round" stroke-dasharray="326.72" stroke-dashoffset="${dashoffset}" transform="rotate(-90 80 80)" />
                    </svg>
                    <div class="constellation-center-content">
                        <span class="constellation-kicker">LEAD GAP</span>
                        <span class="constellation-lead-num">+${leadGap}</span>
                        <span class="constellation-unit">PTS</span>
                    </div>
                </div>

                ${cardsHtml}
                ${extraHtml}
            </div>
        `;

        triggerLeaderboardAnimations(container);
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

        renderLeaderboardStageInto(container, teams, completionPercent);

        window.renderLeaderboard = renderLeaderboard;
    }

    function triggerLeaderboardAnimations(customContainer) {
        const root = customContainer || document.querySelector('[data-leaderboard], [data-leaderboard-stage]');
        if (!root || typeof gsap === 'undefined') return;

        const centerNode = root.querySelector('.orbital-center-node');
        const card1 = root.querySelector('.orbital-card[data-pos="1"]');
        const card2 = root.querySelector('.orbital-card[data-pos="2"]');
        const card3 = root.querySelector('.orbital-card[data-pos="3"]');
        const card4 = root.querySelector('.orbital-card[data-pos="4"]');
        const scoreEls = root.querySelectorAll('.orbital-score-digit');
        const laserLines = root.querySelectorAll('.constellation-laser-line');
        const extraBar = root.querySelector('.orbital-extra-teams-bar');

        // Stop any active idle float tweens before starting new entrance
        gsap.killTweensOf([centerNode, card1, card2, card3, card4, extraBar]);

        // 1. Central Medallion Professional Scale & Fade Entrance
        if (centerNode) {
            gsap.fromTo(centerNode, 
                { scale: 0.88, opacity: 0 }, 
                { scale: 1, opacity: 1, duration: 0.75, ease: 'expo.out' }
            );
        }

        // 2. SVG Laser Connectors Fade-In
        if (laserLines.length > 0) {
            gsap.fromTo(laserLines,
                { opacity: 0 },
                { opacity: 1, duration: 0.6, delay: 0.15, stagger: 0.05, ease: 'power2.out' }
            );
        }

        // 3. Precision Broadcast Cards Entrance (Subtle Smooth Slides)
        if (card1) {
            gsap.fromTo(card1, 
                { y: -60, opacity: 0 }, 
                { y: 0, opacity: 1, duration: 0.8, delay: 0.08, ease: 'power3.out' }
            );
        }
        if (card2) {
            gsap.fromTo(card2, 
                { x: 60, opacity: 0 }, 
                { x: 0, opacity: 1, duration: 0.8, delay: 0.16, ease: 'power3.out' }
            );
        }
        if (card3) {
            gsap.fromTo(card3, 
                { x: -60, opacity: 0 }, 
                { x: 0, opacity: 1, duration: 0.8, delay: 0.24, ease: 'power3.out' }
            );
        }
        if (card4) {
            gsap.fromTo(card4, 
                { y: 60, opacity: 0 }, 
                { y: 0, opacity: 1, duration: 0.8, delay: 0.32, ease: 'power3.out' }
            );
        }

        // 4. Extra Teams Bar Entrance
        if (extraBar) {
            gsap.fromTo(extraBar,
                { y: 25, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.6, delay: 0.4, ease: 'power3.out' }
            );
        }

        // 5. Smooth Numerical Score Interpolation (Clean Broadcast Counter)
        scoreEls.forEach(el => {
            const targetScore = parseInt(el.dataset.score || '0', 10);
            const counter = { val: 0 };
            gsap.to(counter, {
                val: targetScore,
                duration: 1.1,
                delay: 0.2,
                ease: 'power2.out',
                onUpdate: () => {
                    el.textContent = Math.round(counter.val);
                }
            });
        });

        // 6. Subtle Micro-Hovering Idle Dynamics (Constellation Float)
        if (card1) gsap.to(card1, { y: '-=4', duration: 3.8, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 0.8 });
        if (card2) gsap.to(card2, { x: '+=4', duration: 4.2, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 1.0 });
        if (card3) gsap.to(card3, { x: '-=4', duration: 4.0, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 1.2 });
        if (card4) gsap.to(card4, { y: '+=4', duration: 4.4, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 1.4 });
    }

    function scheduleRowsPerPage() {
        return 8;
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

        rows.sort((a, b) => {
            const timeA = a.start_time ? new Date(a.start_time).getTime() : 0;
            const timeB = b.start_time ? new Date(b.start_time).getTime() : 0;
            if (timeA === timeB) {
                return (Number(a.id) || 0) - (Number(b.id) || 0);
            }
            return timeA - timeB;
        });

        return rows;
    }

    function buildSchedulePages(scheduleData) {
        let rows = flattenScheduleItems(scheduleData);
        // Filter out breaks
        rows = rows.filter(item => item.type !== 'break' && item.kind !== 'break');
        
        // 1. Find the active program (broadcasted by emcee)
        const liveProgramId = state.current_program_data?.program?.id || state.schedule.data?.live_program_id || null;
        let activeProg = null;
        if (liveProgramId) {
            activeProg = rows.find(item => Number(item.id) === Number(liveProgramId));
        }
        if (!activeProg) {
            activeProg = rows.find(item => item.status === 'scoring' || item.status === 'active-stage');
        }

        // 2. Extract its date (or fallbacks)
        let activeDate = null;
        if (activeProg && activeProg.start_time) {
            activeDate = activeProg.start_time.split(' ')[0];
        }

        if (!activeDate) {
            const firstUpcoming = rows.find(item => item.status !== 'completed' && item.approval_status !== 'approved');
            if (firstUpcoming && firstUpcoming.start_time) {
                activeDate = firstUpcoming.start_time.split(' ')[0];
            }
        }

        if (!activeDate) {
            const firstProg = rows.find(item => item.start_time);
            if (firstProg) {
                activeDate = firstProg.start_time.split(' ')[0];
            }
        }

        // 3. Filter schedule rows to only show this active day's programs
        if (activeDate) {
            rows = rows.filter(item => item.start_time && item.start_time.split(' ')[0] === activeDate);
        }
        
        // 4. Slide/crop past completed programs of the active day
        let activeIdx = rows.findIndex(item => item.status === 'scoring' || item.status === 'active-stage');
        if (activeIdx === -1 && liveProgramId) {
            activeIdx = rows.findIndex(item => Number(item.id) === Number(liveProgramId));
        }
        if (activeIdx === -1) {
            activeIdx = rows.findIndex(item => item.status !== 'completed' && item.approval_status !== 'approved');
        }
        
        if (activeIdx !== -1) {
            const startIdx = Math.max(0, activeIdx - 1);
            rows = rows.slice(startIdx);
        }
        
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
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <span>PROGRAM SCHEDULE</span>
                        <span class="page-count-badge" data-schedule-day-badge style="display: none; font-size: 14px; text-transform: uppercase; background: var(--first-team-color, #10b981); color: #fff; border: none; padding: 4px 14px; border-radius: 20px; font-weight: 800; letter-spacing: 0.05em;">DAY 2</span>
                    </div>
                    <span class="page-count-badge" data-schedule-page-badge style="text-transform: uppercase;">PAGE ${curPage + 1} / ${totalPages}</span>
                </div>
                <div class="schedule-timeline-container" data-schedule-page>
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
            badgeEl.textContent = `PAGE ${index + 1} / ${totalPages}`;
        }

        const currentRows = Array.from(pageEl.querySelectorAll('.program-row'));

        const updateAndAnimateIn = () => {
            if (!page.length) {
                pageEl.innerHTML = `
                    <div class="tv-schedule-empty" style="padding: 48px; text-align: center; color: rgba(255,255,255,0.45); background: rgba(15,23,42,0.4); border-radius: 16px; border: 1px dashed rgba(255,255,255,0.12);">
                        <strong style="font-size: 24px; display: block; margin-bottom: 8px; color: #fff;">No programs scheduled</strong>
                        <span>Stand by for upcoming competition events.</span>
                    </div>
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

            // Sync Header Day Badge for the current page
            const dayBadgeEl = els.schedule?.querySelector('[data-schedule-day-badge]');
            let pageDay = '';
            if (page.length > 0) {
                const firstItem = page[0];
                const itemDate = firstItem.start_time ? firstItem.start_time.split(' ')[0] : '';
                pageDay = dateToDayMap[itemDate] || '';
            }
            if (dayBadgeEl && pageDay) {
                dayBadgeEl.textContent = pageDay.toUpperCase();
                dayBadgeEl.style.display = 'inline-block';
            } else if (dayBadgeEl) {
                dayBadgeEl.style.display = 'none';
            }

            let html = '';

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
                const currentDay = dateToDayMap[itemDate] || '';

                let rowClasses = ['program-row', 'schedule-card'];
                let indexClass = 'num-blue';

                if (isCurrentRunning) {
                    rowClasses.push('is-running-program');
                    indexClass = 'num-green';
                } else if (isCompleted) {
                    indexClass = 'num-gray';
                } else if (isBreak) {
                    rowClasses.push('is-break');
                    indexClass = 'num-amber';
                }

                let resultsHtml = '';
                const results = Array.isArray(item.results)
                    ? item.results.filter(result => Number(result?.rank || result?.final_rank || 0) > 0)
                    : [];
                if (results.length > 0) {
                    const badges = results.map(r => {
                        let badgeText = '';
                        let badgeClass = 'rank-pill';
                        if (r.rank === 1) {
                            badgeText = `1st`;
                            badgeClass += ' rank-1';
                        } else if (r.rank === 2) {
                            badgeText = `2nd`;
                            badgeClass += ' rank-2';
                        } else if (r.rank === 3) {
                            badgeText = `3rd`;
                            badgeClass += ' rank-3';
                        } else {
                            return '';
                        }
                        
                        return `<span class="${badgeClass}" style="--badge-team-color: ${escapeHtml(r.team_color)}">${badgeText}</span>`;
                    }).filter(Boolean);
                    
                    if (badges.length > 0) {
                        resultsHtml = `<div class="schedule-ranks-row">${badges.join('')}</div>`;
                    }
                }
                if (!resultsHtml) {
                    resultsHtml = '<span class="no-results-placeholder">—</span>';
                }

                const dayPill = (uniqueDates.length > 1 && currentDay) ? `<span class="program-day-tag">${escapeHtml(currentDay.toUpperCase())}</span>` : '';
                const secBadge = secName ? `<span class="program-sec-tag">${escapeHtml(secName)}</span>` : '';

                html += `
                    <div class="${rowClasses.join(' ')}" style="opacity: 0;">
                        <div class="schedule-card-accent"></div>
                        <div class="schedule-card-program-col">
                            <span class="schedule-index-inline ${indexClass}">${rowNum}</span>
                            <div class="schedule-program-details-wrap">
                                <h3 class="schedule-program-title">${escapeHtml(title)}</h3>
                                ${secBadge}
                            </div>
                        </div>
                        <div class="schedule-card-ranks-col">
                            ${resultsHtml}
                        </div>
                        <div class="schedule-card-time-col">
                            ${dayPill}
                            <span class="schedule-time-label">${escapeHtml(timeStr)}</span>
                        </div>
                    </div>
                `;
            });

            pageEl.innerHTML = html;

            if (!isSync && typeof gsap !== 'undefined') {
                const rows = pageEl.querySelectorAll('.program-row');
                gsap.fromTo(rows, {
                    opacity: 0,
                    x: 40,
                    filter: 'blur(4px)'
                }, {
                    opacity: 1,
                    x: 0,
                    filter: 'blur(0px)',
                    duration: 0.85,
                    stagger: 0.075,
                    ease: 'power2.out',
                    onComplete: () => {
                        gsap.set(rows, { clearProps: 'transform,opacity,filter' });
                    }
                });
            } else {
                const rows = pageEl.querySelectorAll('.program-row');
                if (typeof gsap !== 'undefined') {
                    gsap.set(rows, { clearProps: 'opacity,transform,filter' });
                } else {
                    rows.forEach(r => { r.style.opacity = '1'; });
                }
            }
        };

        if (animateOut && typeof gsap !== 'undefined' && currentRows.length > 0) {
            gsap.to(currentRows, {
                opacity: 0,
                x: -40,
                filter: 'blur(4px)',
                duration: 0.4,
                stagger: 0.045,
                ease: 'power2.in',
                onComplete: () => {
                    setTimeout(updateAndAnimateIn, 200);
                }
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
        state.current_program_data = currentData;
        if (!els.currentTitle) return;

        const heroInfo = document.querySelector('[data-performer-hero-info]');
        const isBreak = !currentData || currentData.is_break;
        if (isBreak) {
            if (heroInfo) {
                heroInfo.classList.remove('state-active');
                heroInfo.classList.add('state-awaiting');
            }
            if (els.currentStage) els.currentStage.textContent = 'Main Stage';
            if (els.currentTitle) els.currentTitle.textContent = 'BREAK TIME';
            if (els.currentPerformer) els.currentPerformer.textContent = 'Stand by for the next act';
            if (els.currentTeam) els.currentTeam.textContent = 'Awaiting next program';
            if (els.currentCategory) els.currentCategory.textContent = 'All Classes';
            if (els.currentStatus) els.currentStatus.textContent = 'Break';
            if (els.currentRoom) els.currentRoom.textContent = 'Main Hall';

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

        if (els.currentStage) els.currentStage.textContent = program.stage || 'Main Stage';
        if (els.currentTitle) els.currentTitle.textContent = program.title || 'Current Act';
        
        const isIntro = !performer.name || performer.name === 'Awaiting Performer';
        if (heroInfo) {
            if (!isIntro) {
                heroInfo.classList.remove('state-awaiting');
                heroInfo.classList.add('state-active');
            } else {
                heroInfo.classList.remove('state-active');
                heroInfo.classList.add('state-awaiting');
            }
        }

        if (!isIntro) {
            if (els.currentPerformer) els.currentPerformer.textContent = performer.name;
            if (currentChest) currentChest.textContent = performer.number || '—';
            if (els.currentTeam) els.currentTeam.innerHTML = `${performer.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(performer.team_color)}"></span>` : ''}${escapeHtml(performer.team || '—')}`;
        } else {
            if (els.currentPerformer) els.currentPerformer.textContent = 'No active performer';
            if (currentChest) currentChest.textContent = '—';
            if (els.currentTeam) els.currentTeam.textContent = '—';
        }

        if (els.currentCategory) els.currentCategory.textContent = program.category || 'All Classes';
        if (els.currentStatus) els.currentStatus.textContent = currentData.status || 'Active';
        if (els.currentRoom) els.currentRoom.textContent = program.location || 'Stage Room';

        if (els.nextPerformer) {
            els.nextPerformer.textContent = next.name || '—';
        }
        if (els.nextTeam) {
            els.nextTeam.innerHTML = next.team ? `${next.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(next.team_color)}"></span>` : ''}${escapeHtml(next.team)}` : '—';
        }

        if (nextChest) {
            nextChest.textContent = next.number || '—';
            const nextColor = next.team_color || 'var(--first-team-color, #10b981)';
            nextChest.style.color = nextColor;
            nextChest.style.textShadow = `0 0 30px ${nextColor}`;
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
    const SCORE_UPDATE_COOLDOWN_MS = 10000;
    let activeRevealTimer = null;
    let isScoreRevealActive = false;

    function checkScoreRevealEvent(revealData) {
        if (!revealData || !revealData.timestamp || isScoreRevealActive) return;

        const revealTs = Number(revealData.timestamp);
        const now = Date.now();

        // Ignore historical score updates (older than 35 seconds) on page open or tab load
        if (now - revealTs > 35000) {
            try {
                localStorage.setItem('last_score_reveal_ts', String(revealTs));
            } catch (_) {}
            window.LAST_REVEALED_TS = revealTs;
            return;
        }

        let storedTs = 0;
        try {
            storedTs = Number(localStorage.getItem('last_score_reveal_ts') || window.LAST_REVEALED_TS || 0);
        } catch (_) {
            storedTs = Number(window.LAST_REVEALED_TS || 0);
        }

        // On first initialization if no stored timestamp, seed it to avoid triggering on initial page load
        if (storedTs === 0) {
            try {
                localStorage.setItem('last_score_reveal_ts', String(revealTs));
            } catch (_) {}
            window.LAST_REVEALED_TS = revealTs;
            return;
        }

        if (revealTs > storedTs) {
            try {
                localStorage.setItem('last_score_reveal_ts', String(revealTs));
            } catch (_) {}
            window.LAST_REVEALED_TS = revealTs;
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

        const revealTeams = Array.isArray(reveal.teams) && reveal.teams.length > 0 
            ? reveal.teams 
            : (state.leaderboardData || []);

        const sortedTeams = [...revealTeams].sort((a, b) => (Number(b.total_score) || 0) - (Number(a.total_score) || 0));

        // Programs list included in this update
        const programsList = Array.isArray(reveal.programs) && reveal.programs.length > 0 
            ? reveal.programs 
            : [{ title: reveal.program_title || 'Updated Program', category_name: reveal.category_name || '' }];

        const programCountLabel = programsList.length > 1 
            ? `${programsList.length} UPDATED PROGRAMS APPROVED` 
            : 'NEW PROGRAM SCORES APPROVED';

        // Render base overlay HTML — ZERO BUTTONS (DISPLAY ONLY)!
        overlay.innerHTML = `
            <div style="position: absolute; top: 28px; left: 50%; transform: translateX(-50%); z-index: 10; display: flex; flex-direction: column; align-items: center; gap: 8px; pointer-events: none;">
                <div style="background: rgba(16, 185, 129, 0.18); backdrop-filter: blur(12px); border: 1.5px solid rgba(16, 185, 129, 0.4); color: #10b981; font-weight: 900; font-size: 13px; padding: 6px 20px; border-radius: 30px; letter-spacing: 0.08em; text-transform: uppercase; box-shadow: 0 8px 25px rgba(16,185,129,0.25);">
                    <span class="pulse-dot-red" style="background: #10b981; box-shadow: 0 0 10px #10b981; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 8px;"></span> ${escapeHtml(programCountLabel)}
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;">
                    ${programsList.map(p => `
                        <div style="background: rgba(15, 23, 42, 0.72); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.15); padding: 5px 16px; border-radius: 20px; font-size: 13px; color: #fff; font-weight: 800; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                            <i class="fa-solid fa-trophy" style="color: #f59e0b; margin-right: 6px;"></i>
                            <span>${escapeHtml(p.title)}</span>
                            ${p.category_name ? `<small style="opacity: 0.75; margin-left: 4px;">(${escapeHtml(p.category_name)})</small>` : ''}
                        </div>
                    `).join('')}
                </div>
            </div>

            <div id="revealStageContainer" style="width: 100%; height: 100%; position: relative;"></div>
        `;

        requestAnimationFrame(() => overlay.classList.add('is-active'));

        // Render the exact Leaderboard style stage inside overlay
        const stageContainer = overlay.querySelector('#revealStageContainer');
        if (stageContainer) {
            renderLeaderboardStageInto(stageContainer, sortedTeams);
        }

        playTone(1100, 'triangle', 0.4, 0.25);
        setTimeout(() => {
            playCelebrationFanfare();
        }, 1500);

        // Keep each mark update in its cinematic reveal state for ten seconds.
        // While it is active, newer updates remain pending and are picked up on
        // the next refresh instead of interrupting the current reveal.
        if (activeRevealTimer) clearTimeout(activeRevealTimer);
        activeRevealTimer = setTimeout(() => {
            closeScoreRevealOverlay();
        }, SCORE_UPDATE_COOLDOWN_MS);
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
                } else if (state.mode === 'manual' && state.activeSlide) {
                    setActiveSlide(state.activeSlide);
                }
            }, 500);
        }
    }

    function applyBootstrap(data) {
        if (!data) return;

        // Synchronize server-to-client clock offset and live timer start timestamp
        if (data.server_time_ms) {
            state.serverClockOffset = data.server_time_ms - Date.now();
        }
        if (typeof data.live_stage_start_time !== 'undefined') {
            state.live_stage_start_time = data.live_stage_start_time;
        }
        if (typeof data.live_timer_running !== 'undefined') {
            state.live_timer_running = data.live_timer_running;
        }
        if (typeof data.live_timer_start_time !== 'undefined') {
            state.live_timer_start_time = data.live_timer_start_time;
        }
        if (typeof data.live_timer_elapsed !== 'undefined') {
            state.live_timer_elapsed = data.live_timer_elapsed;
        }

        const pathLower = window.location.pathname.toLowerCase();
        window.IS_SINGLE_PAGE = pathLower.includes('/intro') || 
                                pathLower.includes('/leaderboard') || 
                                pathLower.includes('/schedule') || 
                                pathLower.includes('/current-program') || 
                                pathLower.includes('/current-programs');

        const settings = data.settings || {};
        const wasAuto = state.mode === 'auto';
        const wasPlaying = state.is_playing;

        // Respect settings mode on main multi-slide slideshow stage if configured, fallback to auto
        if (!window.IS_SINGLE_PAGE) {
            state.mode = settings.mode || 'auto';
            state.is_playing = typeof settings.is_playing === 'boolean' ? settings.is_playing : true;
        } else {
            if (typeof settings.is_playing === 'boolean') state.is_playing = settings.is_playing;
            if (settings.mode) state.mode = settings.mode;
        }

        if (settings.refresh_interval) state.refresh_interval = settings.refresh_interval;

        // An application action requests a slide without changing auto mode.
        // Let it remain on screen for its own configured duration before the
        // synchronized carousel takes over again.
        const isNewSlideAction = settings.last_updated
            && state.lastUpdatedTimestamp !== null
            && settings.last_updated !== state.lastUpdatedTimestamp;

        if (settings.last_updated) {
            state.lastUpdatedTimestamp = settings.last_updated;
        }

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
        const currentDomSlideKeys = Array.from(document.querySelectorAll('.tv-slide'))
            .map(el => el.dataset.slide)
            .filter(Boolean)
            .sort();

        const supportedSlideKeys = ['intro', 'leaderboard', 'schedule', 'current-program', 'score-reveal'];
        const newEnabledSlideKeys = supportedSlideKeys.filter(key => {
            const s = slidesMap[key];
            return !s || s.enabled !== false;
        }).sort();

        // Commented out to prevent unprofessional page reloads/refreshes between slide changes.
        // if (!window.IS_SINGLE_PAGE && JSON.stringify(currentDomSlideKeys) !== JSON.stringify(newEnabledSlideKeys)) {
        //     window.location.reload();
        //     return;
        // }

        state.slides = {};

        const slideArray = Object.values(slidesMap);
        slideArray.sort((a, b) => (a.sort_order ?? 99) - (b.sort_order ?? 99));

        state.slideOrder = slideArray.map(s => s.key || s.slide_key).filter(Boolean);
        slideArray.forEach(slide => {
            const key = slide.key || slide.slide_key;
            if (key) {
                let rawDur = Number(slide.duration || (key === 'intro' ? 10000 : 7000));
                // Cap manual 999999 duration overrides to 10s for slideshow rotation
                if (rawDur > 20000) rawDur = (key === 'intro' ? 10000 : 7000);

                state.slides[key] = {
                    key,
                    title: slide.title || key,
                    duration: Math.max(3000, rawDur),
                    enabled: slide.enabled !== false,
                    sort_order: slide.sort_order ?? 99,
                    style: slide.style || 'classic'
                };
            }
        });

        if (isNewSlideAction && state.mode === 'auto' && !window.IS_SINGLE_PAGE && settings.active_slide) {
            const actionKey = String(settings.active_slide).replace('_', '-');
            const configuredDuration = Number(state.slides[actionKey]?.duration);
            state.actionOverride = {
                key: actionKey,
                expiresAt: Date.now() + Math.max(3000, configuredDuration || 7000)
            };
            setActiveSlide(actionKey);
        }

        // Fallback: ensure all known slide keys exist so the rotation never stalls
        ['intro', 'leaderboard', 'schedule', 'current-program'].forEach(key => {
            if (!state.slides[key]) {
                state.slides[key] = { key, title: key, duration: 10000, enabled: true, sort_order: 99, style: 'classic' };
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
            stopScheduleTimer();
            if (settings.active_slide && state.activeSlide !== settings.active_slide) {
                setActiveSlide(settings.active_slide);
            }
        } else {
            startRotation();
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

        advanceToNextSlide();
    }

    function startSchedulePlayback() {
        // The action override owns the slide timer while a requested schedule
        // slide is being shown. Do not cancel its configured expiry.
        if (!state.actionOverride) {
            stopSlideTimer();
        }
        stopScheduleTimer();

        if (!els.schedule || state.isCelebrating) {
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
        
        // Locked to perfect timing: 7 seconds (7000ms) per schedule page!
        const rawDur = Number(state.slides.schedule?.duration) || 7000;
        const pageDuration = (rawDur > 20000) ? 7000 : Math.max(4000, Math.min(15000, rawDur));

        renderSchedulePage(0, false);

        const tick = () => {
            updateScheduleClock();

            if (state.activeSlide !== 'schedule' || state.isCelebrating) {
                stopScheduleTimer();
                return;
            }

            const currentTotalPages = Math.max(1, (state.schedule.pages || []).length);

            if (state.schedule.currentPage >= currentTotalPages - 1) {
                if (state.mode === 'manual' || window.location.pathname.includes('/schedule')) {
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

        if (totalPages > 1 || state.mode === 'manual' || window.location.pathname.includes('/schedule')) {
            state.schedule.timer = setTimeout(tick, pageDuration);
        }
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
        els.body.classList.toggle('tv-leaderboard-active', normalizedKey === 'leaderboard');
        state.activeSlide = normalizedKey;

        const bgVideo = document.getElementById('tvBgVideo');
        const isIntro = (normalizedKey === 'intro');
        window.isIntroSlideActive = isIntro;
        window.isFlowCanvasPaused = isIntro || isLowEndDevice;

        if (bgVideo) {
            if (isIntro || isLowEndDevice) {
                if (!bgVideo.paused) {
                    try { bgVideo.pause(); } catch (_) {}
                }
                bgVideo.style.display = 'none';
            } else {
                if (bgVideo.paused) {
                    try { bgVideo.play().catch(() => {}); } catch (_) {}
                }
                bgVideo.style.display = 'block';
            }
        }

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

    function getNowMs() {
        return Date.now() + (state.serverTimeOffset || 0);
    }

    function getGlobalSynchronizedState() {
        const enabledSlides = state.slideOrder.filter(key => {
            const slideConf = state.slides[key];
            const hasEl = document.querySelector(`.tv-slide[data-slide="${key}"]`) || document.getElementById(`slide-${key}`);
            return slideConf && slideConf.enabled !== false && hasEl;
        }).map(key => {
            let rawDur = Number(state.slides[key]?.duration) || (key === 'intro' ? 10000 : 8000);
            if (rawDur > 30000) rawDur = (key === 'intro' ? 10000 : 8000);

            if (key === 'schedule') {
                const schedulePages = buildSchedulePages(state.schedule.data || { sections: [] });
                const totalPages = Math.max(1, schedulePages.length);
                const pageDur = (rawDur > 20000) ? 7000 : Math.max(4000, Math.min(15000, rawDur));
                return { key, duration: totalPages * pageDur, isSchedule: true, totalPages, pageDur };
            }

            return { key, duration: Math.max(3000, rawDur), isSchedule: false, totalPages: 1, pageDur: rawDur };
        });

        if (enabledSlides.length === 0) {
            return { activeKey: 'intro', remainingMs: 10000, schedulePage: 0, totalCycleMs: 10000 };
        }

        const totalCycleMs = enabledSlides.reduce((sum, s) => sum + s.duration, 0);
        if (totalCycleMs <= 0) {
            return { activeKey: enabledSlides[0].key, remainingMs: 10000, schedulePage: 0, totalCycleMs: 10000 };
        }

        const now = getNowMs();
        const cyclePos = ((now % totalCycleMs) + totalCycleMs) % totalCycleMs;

        let elapsed = 0;
        for (let i = 0; i < enabledSlides.length; i++) {
            const slide = enabledSlides[i];
            const slideEnd = elapsed + slide.duration;
            if (cyclePos >= elapsed && cyclePos < slideEnd) {
                const elapsedInSlide = cyclePos - elapsed;

                if (slide.isSchedule) {
                    const schedulePage = Math.min(slide.totalPages - 1, Math.floor(elapsedInSlide / slide.pageDur));
                    const pageEnd = (schedulePage + 1) * slide.pageDur;
                    const remainingInPage = pageEnd - elapsedInSlide;
                    return {
                        activeKey: slide.key,
                        remainingMs: Math.max(100, remainingInPage),
                        schedulePage: schedulePage,
                        totalCycleMs
                    };
                }

                const remainingInSlide = slideEnd - cyclePos;
                return {
                    activeKey: slide.key,
                    remainingMs: Math.max(100, remainingInSlide),
                    schedulePage: 0,
                    totalCycleMs
                };
            }
            elapsed += slide.duration;
        }

        return {
            activeKey: enabledSlides[0].key,
            remainingMs: enabledSlides[0].duration,
            schedulePage: 0,
            totalCycleMs
        };
    }

    function syncGlobalSlideshow() {
        stopSlideTimer();
        stopScheduleTimer();

        if (!state.is_playing || state.mode === 'manual' || state.isCelebrating) return;

        // Keep an action-requested slide visible for its configured time.
        // Once it expires, resume the normal synchronized slideshow cycle.
        if (state.actionOverride) {
            const remainingMs = state.actionOverride.expiresAt - Date.now();
            if (remainingMs > 0) {
                state.timers.slide = setTimeout(() => {
                    syncGlobalSlideshow();
                }, Math.max(100, remainingMs));
                return;
            }
            state.actionOverride = null;
        }

        const sync = getGlobalSynchronizedState();

        const slideChanged = state.activeSlide !== sync.activeKey;
        if (slideChanged) {
            setActiveSlide(sync.activeKey);
        }

        if (sync.activeKey === 'schedule') {
            const pageChanged = state.schedule.currentPage !== sync.schedulePage;
            state.schedule.currentPage = sync.schedulePage;
            state.schedule.playing = true;
            updateScheduleClock();
            
            const hasPageContent = els.schedule?.querySelector('.program-row') !== null;
            if (slideChanged || pageChanged || !hasPageContent) {
                const shouldAnimateOut = !slideChanged && pageChanged && hasPageContent;
                renderSchedulePage(sync.schedulePage, shouldAnimateOut);
            }
        }

        const delay = Math.max(120, sync.remainingMs + 40);
        state.timers.slide = setTimeout(() => {
            syncGlobalSlideshow();
        }, delay);
    }

    function startRotation() {
        if (!state.is_playing || state.mode === 'manual' || state.isCelebrating) return;
        syncGlobalSlideshow();
    }

    function boot() {
        if (isLowEndDevice) {
            const bgVideo = document.getElementById('tvBgVideo');
            if (bgVideo) {
                bgVideo.style.display = 'none';
                try { bgVideo.pause(); } catch (_) {}
            }
        }
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
                        syncGlobalSlideshow();
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
