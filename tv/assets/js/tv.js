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

    function renderLeaderboard(rows, completionPercent = 0) {
        const barsList = document.getElementById('chart-bars-list');
        const legend = document.getElementById('chart-legend');
        const axis = document.getElementById('chart-axis');
        const gridlines = document.getElementById('chart-gridlines');
        if (!barsList || !legend || !axis || !gridlines) return;

        if (rows.length === 0) {
            barsList.innerHTML = `
                <div class="tv-chart-row" style="display: flex; align-items: center; justify-content: center; border-color: rgba(255,255,255,0.08);">
                    <div style="font-size: 20px; color: var(--muted);">No teams or scores recorded yet</div>
                </div>
            `;
            legend.innerHTML = '';
            axis.innerHTML = '';
            gridlines.innerHTML = '';
            return;
        }

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

        // 2. Render horizontal Legend at the top (sorted by rank)
        legend.innerHTML = rows.map(team => `
            <div class="tv-legend-item">
                <span class="tv-legend-dot" style="background: ${escapeHtml(team.team_color || '#3b82f6')}; color: ${escapeHtml(team.team_color || '#3b82f6')};"></span>
                <span>${escapeHtml(team.team_name || 'Team')}</span>
            </div>
        `).join('');

        // 3. Compute scale target and ticks
        const maxVal = Math.max(...rows.map(r => Number(r.total_score || 0)), 10);
        
        let step = 50;
        if (maxVal <= 100) {
            step = 25;
        } else if (maxVal <= 300) {
            step = 50;
        } else {
            step = 100;
        }
        
        const scaleMax = Math.ceil((maxVal * 1.05) / step) * step;
        const ticks = [];
        for (let val = 0; val <= scaleMax; val += step) {
            ticks.push(val);
        }
        const targetVal = scaleMax;

        // Render Axis ticks
        axis.innerHTML = ticks.map(t => `<span class="tv-axis-tick">${t}</span>`).join('');

        // Render Background Gridlines
        gridlines.innerHTML = ticks.map(() => `<div class="tv-gridline"></div>`).join('');

        // 4. Render or update bars
        const renderCard = (team, index) => {
            const cardId = `team-card-${team.id}`;
            let card = document.getElementById(cardId);
            const isNew = !card;
            
            const score = Number(team.total_score || 0);
            const percent = Math.min(100, Math.round((score / targetVal) * 100));
            const color = team.team_color || '#3b82f6';
            const rgb = hexToRgb(color);
            const rgbStr = `${rgb.r}, ${rgb.g}, ${rgb.b}`;
            
            if (isNew) {
                card = document.createElement('div');
                card.className = 'tv-chart-row-wrapper';
                card.id = cardId;
                card.style.setProperty('--card-team-color', color);
                card.style.setProperty('--card-team-color-rgb', rgbStr);
                
                card.innerHTML = `
                    <article class="tv-chart-row">
                        <div class="tv-chart-bar-bg">
                            <div class="tv-chart-bar-fill" style="background: ${escapeHtml(color)}; box-shadow: 0 0 25px ${rgbaColor(color, 0.25)}, 0 0 50px ${rgbaColor(color, 0.15)}; width: 0%;">
                                <div class="tv-chart-bar-badge" style="--card-team-color: ${escapeHtml(color)}; --card-team-color-rgb: ${rgbStr};">
                                    <span class="tv-chart-bar-badge-arrow"></span>
                                    <span>${escapeHtml(team.team_name)}:</span>
                                    <span class="tv-chart-bar-badge-val" data-score="0">0</span>
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            } else {
                card.style.setProperty('--card-team-color', color);
                card.style.setProperty('--card-team-color-rgb', rgbStr);
                
                const fillEl = card.querySelector('.tv-chart-bar-fill');
                const badgeEl = card.querySelector('.tv-chart-bar-badge');
                const scoreEl = card.querySelector('.tv-chart-bar-badge-val');
                
                if (badgeEl) {
                    badgeEl.style.setProperty('--card-team-color', color);
                    badgeEl.style.setProperty('--card-team-color-rgb', rgbStr);
                }
                
                if (state.activeSlide === 'leaderboard') {
                    if (scoreEl) {
                        const prevScore = Number(scoreEl.dataset.score || 0);
                        if (prevScore !== score) {
                            scoreEl.dataset.score = score;
                            const scoreObj = { value: prevScore };
                            gsap.to(scoreObj, {
                                value: score,
                                duration: 1.8,
                                ease: 'power2.out',
                                onUpdate: () => {
                                    scoreEl.textContent = Math.round(scoreObj.value);
                                }
                            });
                            
                            const article = card.querySelector('.tv-chart-row');
                            if (article) {
                                gsap.timeline()
                                    .to(article, { scaleY: 1.05, duration: 0.3, ease: 'power2.out' })
                                    .to(article, { scaleY: 1, duration: 0.35, ease: 'power2.in', clearProps: 'transform' });
                            }
                        }
                    }
                    
                    if (fillEl) {
                        fillEl.style.background = color;
                        fillEl.style.boxShadow = `0 0 25px ${rgbaColor(color, 0.25)}, 0 0 50px ${rgbaColor(color, 0.15)}`;
                        gsap.to(fillEl, { width: `${percent}%`, duration: 1.8, ease: 'power3.out' });
                    }
                } else {
                    if (scoreEl) {
                        scoreEl.dataset.score = 0;
                        scoreEl.textContent = '0';
                    }
                    if (fillEl) {
                        fillEl.style.width = '0%';
                    }
                }
            }
            
            return { card, isNew };
        };

        // Measure initial positions of wrappers in DOM
        const rects = {};
        rows.forEach(team => {
            const card = document.getElementById(`team-card-${team.id}`);
            if (card) {
                rects[team.id] = card.getBoundingClientRect();
            }
        });

        // Render or move elements to new slots
        rows.forEach((team, idx) => {
            const { card, isNew } = renderCard(team, idx);
            if (isNew) {
                barsList.appendChild(card);
                
                if (state.activeSlide === 'leaderboard') {
                    // Score counter and fill animation from 0% width starting on load
                    setTimeout(() => {
                        const scoreEl = card.querySelector('.tv-chart-bar-badge-val');
                        const fillEl = card.querySelector('.tv-chart-bar-fill');
                        const score = Number(team.total_score || 0);
                        const percent = Math.min(100, Math.round((score / targetVal) * 100));

                        if (scoreEl) {
                            scoreEl.dataset.score = score;
                            const scoreObj = { value: 0 };
                            gsap.to(scoreObj, {
                                value: score,
                                duration: 1.8,
                                ease: 'power2.out',
                                onUpdate: () => {
                                    scoreEl.textContent = Math.round(scoreObj.value);
                                }
                            });
                        }

                        if (fillEl) {
                            gsap.to(fillEl, {
                                width: `${percent}%`,
                                duration: 1.8,
                                ease: 'power3.out'
                            });
                        }
                    }, 50);
                }
            } else {
                if (barsList.children[idx] !== card) {
                    barsList.insertBefore(card, barsList.children[idx] || null);
                }
            }
        });

        // FLIP Animation: animate vertical position swaps
        if (state.activeSlide === 'leaderboard') {
            rows.forEach(team => {
                const card = document.getElementById(`team-card-${team.id}`);
                const oldRect = rects[team.id];
                if (card && oldRect) {
                    const newRect = card.getBoundingClientRect();
                    const deltaX = oldRect.left - newRect.left;
                    const deltaY = oldRect.top - newRect.top;
                    
                    if (deltaX !== 0 || deltaY !== 0) {
                        const article = card.querySelector('.tv-chart-row');
                        gsap.fromTo(article, {
                            x: deltaX,
                            y: deltaY - 40,
                            scale: 1.05
                        }, {
                            x: 0,
                            y: 0,
                            scale: 1,
                            duration: 0.9,
                            ease: 'back.out(1.2)',
                            clearProps: 'transform'
                        });
                    }
                }
            });
        }
    }

    function triggerLeaderboardAnimations() {
        if (!state.leaderboardData || state.leaderboardData.length === 0) return;

        const maxVal = Math.max(...state.leaderboardData.map(r => Number(r.total_score || 0)), 10);
        let step = 50;
        if (maxVal <= 100) {
            step = 25;
        } else if (maxVal <= 300) {
            step = 50;
        } else {
            step = 100;
        }
        const scaleMax = Math.ceil((maxVal * 1.05) / step) * step;
        const targetVal = scaleMax;

        state.leaderboardData.forEach((team) => {
            const card = document.getElementById(`team-card-${team.id}`);
            if (!card) return;

            const score = Number(team.total_score || 0);
            const percent = Math.min(100, Math.round((score / targetVal) * 100));
            const fillEl = card.querySelector('.tv-chart-bar-fill');
            const scoreEl = card.querySelector('.tv-chart-bar-badge-val');

            if (scoreEl) {
                const prevScore = Number(scoreEl.dataset.score || 0);
                scoreEl.dataset.score = score;
                const scoreObj = { value: prevScore };
                
                gsap.killTweensOf(scoreObj);
                gsap.to(scoreObj, {
                    value: score,
                    duration: 1.8,
                    ease: 'power2.out',
                    onUpdate: () => {
                        scoreEl.textContent = Math.round(scoreObj.value);
                    }
                });
            }

            if (fillEl) {
                gsap.killTweensOf(fillEl);
                gsap.to(fillEl, {
                    width: `${percent}%`,
                    duration: 1.8,
                    ease: 'power3.out'
                });
            }
        });
    }
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
