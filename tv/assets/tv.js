/* global gsap, TV_APP */
(() => {
    'use strict';

    const state = {
        activeSlide: 'intro',
        leaderboard: [],
        schedule: { upcoming: [], inProgress: [], completed: [] },
        now: { break: true },
        timers: {
            slide: null,
            clock: null,
            refresh: null
        },
        slideOrder: ['intro', 'leaderboard', 'schedule', 'current']
    };

    const els = {
        clock: document.getElementById('tvClock'),
        introVideo: document.getElementById('introVideo'),
        leaderboard: document.getElementById('leaderboardContainer'),
        upcoming: document.getElementById('upcomingPrograms'),
        inProgress: document.getElementById('inProgressPrograms'),
        completed: document.getElementById('completedPrograms'),
        currentProgramTitle: document.getElementById('currentProgramTitle'),
        currentPerformerName: document.getElementById('currentPerformerName'),
        currentTeamBadge: document.getElementById('currentTeamBadge'),
        nextPerformerName: document.getElementById('nextPerformerName'),
        nextPerformerTeam: document.getElementById('nextPerformerTeam'),
        nextProgramName: document.getElementById('nextProgramName'),
    };

    const slideEls = Array.from(document.querySelectorAll('.tv-slide'));

    function startClock() {
        const update = () => {
            const now = new Date();
            const time = now.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            if (els.clock) els.clock.textContent = time;
        };

        update();
        state.timers.clock = setInterval(update, 1000);
    }

    function setActiveSlide(name) {
        state.activeSlide = name;
        slideEls.forEach((slide) => {
            slide.classList.toggle('tv-slide--active', slide.dataset.slide === name);
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

        if (name === 'leaderboard') animateEntrance('#slide-leaderboard');
        if (name === 'schedule') animateEntrance('#slide-schedule');
        if (name === 'current') animateEntrance('#slide-current');
    }

    function animateEntrance(scope) {
        const root = document.querySelector(scope);
        if (!root || typeof gsap === 'undefined') return;

        gsap.killTweensOf(root.querySelectorAll('*'));
        const items = root.querySelectorAll('.tv-card-rank, .tv-item, .tv-card, .tv-team-badge, .tv-now-main');
        gsap.fromTo(items, {
            opacity: 0,
            y: 18
        }, {
            opacity: 1,
            y: 0,
            duration: 0.75,
            stagger: 0.05,
            ease: 'power3.out'
        });
    }

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
        const n = Number(score || 0);
        if (Number.isInteger(n)) return String(n);
        return n.toFixed(2).replace(/\.00$/, '');
    }

    function renderLeaderboard(rows) {
        if (!els.leaderboard) return;

        const html = rows.map((team, index) => {
            const rank = index + 1;
            const accent = rank <= 3 ? 'rank-top' : '';
            const barStyle = team.team_color ? `style="background: linear-gradient(90deg, ${escapeHtml(team.team_color)}, transparent);"` : '';
            return `
                <article class="tv-card-rank ${accent}" data-rank="${rank}">
                    <div class="tv-rank-number">Rank ${rank}</div>
                    <div class="tv-rank-team">${colorDot(team.team_color)}${escapeHtml(team.team_name || 'Unnamed Team')}</div>
                    <div class="tv-rank-score" data-score="${escapeHtml(team.total_score || 0)}">0</div>
                    <div class="tv-rank-bar" ${barStyle}></div>
                </article>
            `;
        }).join('');

        els.leaderboard.innerHTML = html || `
            <div class="tv-item">
                <div class="tv-item-title">No teams found</div>
            </div>
        `;

        if (typeof gsap !== 'undefined') {
            gsap.fromTo('#leaderboardContainer .tv-card-rank', {
                opacity: 0,
                y: 20,
                scale: 0.98
            }, {
                opacity: 1,
                y: 0,
                scale: 1,
                duration: 0.7,
                ease: 'power3.out',
                stagger: 0.06
            });

            const scoreNodes = document.querySelectorAll('#leaderboardContainer .tv-rank-score');
            scoreNodes.forEach((node) => {
                const target = Number(node.dataset.score || 0);
                const obj = { value: 0 };
                gsap.to(obj, {
                    value: target,
                    duration: 1.1,
                    ease: 'power2.out',
                    onUpdate: () => {
                        node.textContent = formatScore(obj.value);
                    }
                });
            });
        }
    }

    function renderSchedule(schedule) {
        if (!els.upcoming || !els.completed || !els.inProgress) return;

        const upcomingHtml = (schedule.upcoming || []).map((item) => `
            <article class="tv-item">
                <div class="tv-item-head">
                    <div class="tv-item-title">${escapeHtml(item.title || 'Program')}</div>
                    <span class="tv-status upcoming">${escapeHtml(item.status || 'Upcoming')}</span>
                </div>
            </article>
        `).join('');

        const inProgressHtml = (schedule.inProgress || []).map((item) => `
            <article class="tv-item">
                <div class="tv-item-head">
                    <div class="tv-item-title">${escapeHtml(item.title || 'Program')}</div>
                    <span class="tv-status inprogress">${escapeHtml(item.status || 'In Progress')}</span>
                </div>
            </article>
        `).join('');

        const completedHtml = (schedule.completed || []).map((item) => `
            <article class="tv-item">
                <div class="tv-item-head">
                    <div class="tv-item-title">${escapeHtml(item.title || 'Program')}</div>
                    <span class="tv-status">Completed</span>
                </div>
                ${(item.results || []).map((row) => `
                    <div class="tv-result-row">
                        <div class="tv-place">${escapeHtml(row.place)}${suffix(row.place)}</div>
                        <div>
                            <div class="tv-result-team">${colorDot(row.team_color)}${escapeHtml(row.team_name || 'Team')}</div>
                        </div>
                    </div>
                `).join('')}
            </article>
        `).join('');

        els.upcoming.innerHTML = upcomingHtml || `
            <div class="tv-item">
                <div class="tv-item-title">No upcoming programs</div>
            </div>
        `;

        els.inProgress.innerHTML = inProgressHtml ? `
            <div class="tv-item">
                <div class="tv-item-head">
                    <div class="tv-item-title">In Progress</div>
                    <span class="tv-status inprogress">Live</span>
                </div>
            </div>
            ${inProgressHtml}
        ` : '';

        els.completed.innerHTML = completedHtml || `
            <div class="tv-item">
                <div class="tv-item-title">No completed programs yet</div>
            </div>
        `;

        if (typeof gsap !== 'undefined') {
            gsap.fromTo('#slide-schedule .tv-item', {
                opacity: 0,
                y: 16
            }, {
                opacity: 1,
                y: 0,
                duration: 0.65,
                ease: 'power3.out',
                stagger: 0.04
            });
        }
    }

    function suffix(place) {
        const n = Number(place);
        if (n === 1) return 'st';
        if (n === 2) return 'nd';
        if (n === 3) return 'rd';
        return 'th';
    }

    function renderNow(now) {
        if (!els.currentProgramTitle) return;

        const isBreak = !now || now.break;
        if (isBreak) {
            els.currentProgramTitle.textContent = 'BREAK TIME';
            els.currentPerformerName.textContent = 'Stand by for the next act';
            els.currentTeamBadge.textContent = 'No active performer';
            els.nextPerformerName.textContent = '—';
            els.nextPerformerTeam.textContent = '—';
            els.nextProgramName.textContent = '—';
            return;
        }

        const program = now.program || {};
        const current = now.current || {};
        const next = now.next || null;

        els.currentProgramTitle.textContent = program.title || 'Current Program';
        els.currentPerformerName.textContent = current.name || 'Current Performer';
        els.currentTeamBadge.innerHTML = `${current.team_color ? `<span class="tv-team-dot" style="background:${escapeHtml(current.team_color)}"></span>` : ''}${escapeHtml(current.team_name || '—')}`;
        els.nextPerformerName.textContent = next ? (next.name || '—') : '—';
        els.nextPerformerTeam.textContent = next ? (next.team_name || '—') : '—';
        els.nextProgramName.textContent = now.nextProgram || '—';
    }

    function applyBootstrap(data) {
        renderLeaderboard(data.leaderboard || []);
        renderSchedule({
            upcoming: data.upcoming || [],
            inProgress: data.inProgress || [],
            completed: data.completed || []
        });
        renderNow(data.now || { break: true });
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            cache: 'no-store',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        return res.json();
    }

    async function refreshLeaderboard() {
        try {
            const json = await fetchJson(TV_APP.api.leaderboard);
            if (json?.success && json.data) {
                renderLeaderboard(json.data.leaderboard || []);
            }
        } catch (error) {
            console.error('Leaderboard refresh failed:', error);
        }
    }

    async function refreshSchedule() {
        try {
            const json = await fetchJson(TV_APP.api.schedule);
            if (json?.success && json.data) {
                renderSchedule({
                    upcoming: json.data.upcoming || [],
                    inProgress: json.data.inProgress || [],
                    completed: json.data.completed || []
                });
            }
        } catch (error) {
            console.error('Schedule refresh failed:', error);
        }
    }

    async function refreshCurrent() {
        try {
            const json = await fetchJson(TV_APP.api.current);
            if (json?.success && json.data) {
                renderNow(json.data.now || { break: true });
            }
        } catch (error) {
            console.error('Current refresh failed:', error);
        }
    }

    async function refreshAll() {
        await Promise.allSettled([
            refreshLeaderboard(),
            refreshSchedule(),
            refreshCurrent(),
        ]);
    }

    function stopSlideTimer() {
        if (state.timers.slide) {
            clearTimeout(state.timers.slide);
            state.timers.slide = null;
        }
    }

    function nextSlideName() {
        const idx = state.slideOrder.indexOf(state.activeSlide);
        return state.slideOrder[(idx + 1) % state.slideOrder.length];
    }

    function scheduleNextSlide(delay) {
        stopSlideTimer();
        state.timers.slide = setTimeout(() => {
            const next = nextSlideName();

            if (state.activeSlide === 'intro' && els.introVideo && !els.introVideo.ended) {
                scheduleNextSlide(1000);
                return;
            }

            if (state.activeSlide === 'intro' && next !== 'leaderboard') {
                setActiveSlide('leaderboard');
                scheduleNextSlide(TV_APP.slideDurations.leaderboard);
                return;
            }

            setActiveSlide(next);
            if (next === 'leaderboard') scheduleNextSlide(TV_APP.slideDurations.leaderboard);
            else if (next === 'schedule') scheduleNextSlide(TV_APP.slideDurations.schedule);
            else if (next === 'current') scheduleNextSlide(TV_APP.slideDurations.current);
            else scheduleNextSlide(1000);
        }, delay);
    }

    function startRotation() {
        if (els.introVideo) {
            const introDone = () => {
                setActiveSlide('leaderboard');
                scheduleNextSlide(TV_APP.slideDurations.leaderboard);
            };

            els.introVideo.addEventListener('ended', introDone, { once: true });
            els.introVideo.addEventListener('error', introDone, { once: true });

            const playPromise = els.introVideo.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(() => {
                    setActiveSlide('leaderboard');
                    scheduleNextSlide(TV_APP.slideDurations.leaderboard);
                });
            }
        } else {
            setActiveSlide('leaderboard');
            scheduleNextSlide(TV_APP.slideDurations.leaderboard);
        }
    }

    function startRefreshLoop() {
        state.timers.refresh = setInterval(refreshAll, 5000);
    }

    function onVisibilityChange() {
        if (document.hidden) {
            stopSlideTimer();
        } else {
            scheduleNextSlide(1000);
            refreshAll();
        }
    }

    async function boot() {
        try {
            const bootstrap = await fetchJson(TV_APP.api.bootstrap);
            if (bootstrap?.success && bootstrap.data) {
                applyBootstrap(bootstrap.data);
            }
        } catch (error) {
            console.error('Bootstrap failed:', error);
        }

        startClock();
        startRotation();
        startRefreshLoop();
        refreshAll();

        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('focus', refreshAll);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
