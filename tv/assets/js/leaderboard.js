(() => {
    const config = window.TV_LEADERBOARD_CONFIG || {};
    const initial = window.TV_LEADERBOARD_INITIAL || { teams: [], progress: {} };

    const cards = {
        1: document.querySelector('.podium-card[data-rank="1"]'),
        2: document.querySelector('.podium-card[data-rank="2"]'),
        3: document.querySelector('.podium-card[data-rank="3"]'),
        4: document.querySelector('.podium-card[data-rank="4"]')
    };

    const emptyState = document.getElementById('emptyState');
    const refreshStamp = document.getElementById('refreshStamp');
    const progressRing = document.getElementById('ringProgress');
    const progressPercent = document.getElementById('progressPercent');
    const progressCount = document.getElementById('progressCount');

    const state = {
        loadedOnce: false,
        scores: new Map(),
        circumference: 565.5
    };

    function clampNumber(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function safeColor(value) {
        const v = String(value || '').trim();
        if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(v)) return v;
        if (/^rgba?\(\s*[\d.\s,]+\)$/i.test(v)) return v;
        return '#11b07d';
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
    }

    function setStamp(text) {
        if (refreshStamp) {
            refreshStamp.textContent = text;
        }
    }

    function setRingProgress(progress) {
        if (!progressRing) return;

        const percent = clampNumber(progress?.percentage);
        const total = clampNumber(progress?.total);
        const completed = clampNumber(progress?.completed);

        const offset = state.circumference - (state.circumference * percent / 100);

        if (window.gsap) {
            window.gsap.to(progressRing, {
                strokeDashoffset: offset,
                duration: 1.2,
                ease: 'power3.out'
            });
        } else {
            progressRing.style.strokeDashoffset = String(offset);
        }

        if (progressPercent) {
            if (window.gsap) {
                const obj = { value: parseInt(progressPercent.textContent, 10) || 0 };
                window.gsap.to(obj, {
                    value: percent,
                    duration: 1.2,
                    ease: 'power3.out',
                    onUpdate: () => {
                        progressPercent.textContent = `${Math.round(obj.value)}%`;
                    }
                });
            } else {
                progressPercent.textContent = `${Math.round(percent)}%`;
            }
        }

        if (progressCount) {
            progressCount.textContent = `${completed} / ${total}`;
        }
    }

    function animateCount(el, from, to) {
        if (!el) return;

        if (window.gsap) {
            const obj = { value: from };
            window.gsap.killTweensOf(obj);
            window.gsap.to(obj, {
                value: to,
                duration: 1.25,
                ease: 'power3.out',
                onUpdate: () => {
                    el.textContent = formatNumber(Math.round(obj.value));
                }
            });
        } else {
            el.textContent = formatNumber(to);
        }
    }

    function setPlaceText(card, rank) {
        const badge = card.querySelector('.card-badge');
        if (!badge) return;

        badge.textContent = `${rank}${rank === 1 ? 'st' : rank === 2 ? 'nd' : rank === 3 ? 'rd' : 'th'}`;
    }

    function applyTeam(card, team, rank) {
        const nameEl = card.querySelector('.team-name');
        const scoreEl = card.querySelector('.score-value');

        setPlaceText(card, rank);

        if (!team) {
            card.style.setProperty('--accent', '#0f5f49');
            if (nameEl) nameEl.textContent = '—';
            if (scoreEl) {
                scoreEl.textContent = '0';
                scoreEl.dataset.score = '0';
            }
            return;
        }

        const title = String(team.team_name || '—');
        const score = clampNumber(team.total_score);
        const color = safeColor(team.team_color);

        card.style.setProperty('--accent', color);

        if (nameEl) nameEl.textContent = title;

        if (scoreEl) {
            const previous = state.scores.get(rank) ?? 0;
            scoreEl.dataset.score = String(score);
            animateCount(scoreEl, previous, score);
            state.scores.set(rank, score);
        }
    }

    function renderTeams(teams) {
        const list = Array.isArray(teams) ? teams.slice(0, 4) : [];
        const hasAny = list.length > 0;

        if (emptyState) {
            emptyState.style.display = hasAny ? 'none' : 'block';
        }

        applyTeam(cards[1], list[0] || null, 1);
        applyTeam(cards[2], list[1] || null, 2);
        applyTeam(cards[3], list[2] || null, 3);
        applyTeam(cards[4], list[3] || null, 4);

        if (!state.loadedOnce) {
            const animateCards = [cards[2], cards[1], cards[3], cards[4]].filter(Boolean);

            if (window.gsap) {
                window.gsap.fromTo(
                    animateCards,
                    { y: 100, opacity: 0, scale: 0.94 },
                    {
                        y: 0,
                        opacity: 1,
                        scale: 1,
                        duration: 1.05,
                        stagger: 0.12,
                        ease: 'power3.out'
                    }
                );
            }

            state.loadedOnce = true;
        }

        document.body.classList.remove('refresh-flash');
        void document.body.offsetWidth;
        document.body.classList.add('refresh-flash');
    }

    async function loadLeaderboard() {
        try {
            const response = await fetch(config.apiUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch'
                },
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();

            if (!payload || payload.success !== true) {
                throw new Error(payload && payload.message ? payload.message : 'Invalid payload');
            }

            renderTeams(payload.teams || []);
            setRingProgress(payload.progress || { total: 0, completed: 0, percentage: 0 });

            if (refreshStamp) {
                refreshStamp.textContent = `Updated ${new Date().toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                })}`;
            }
        } catch (error) {
            console.error('Leaderboard refresh failed:', error);
            setStamp('Refresh failed • retrying');
        }
    }

    function initRing() {
        if (progressRing && typeof progressRing.getTotalLength === 'function') {
            state.circumference = progressRing.getTotalLength();
            progressRing.style.strokeDasharray = `${state.circumference}`;
            progressRing.style.strokeDashoffset = `${state.circumference}`;
        }
    }

    function init() {
        initRing();
        renderTeams(initial.teams || []);
        setRingProgress(initial.progress || { total: 0, completed: 0, percentage: 0 });
        loadLeaderboard();
        setInterval(loadLeaderboard, Number(config.refreshMs || 5000));
    }

    document.addEventListener('DOMContentLoaded', init);
})();