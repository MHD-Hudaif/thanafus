(() => {
    'use strict';

    const SLOT_IDS = ['top', 'left', 'right', 'bottom'];
    const SLOT_LABELS = {
        top: 'TOP',
        left: 'LEFT',
        right: 'RIGHT',
        bottom: 'BOTTOM',
    };

    const DEFAULT_STATE = {
        event: { title: 'Al-Thanafus', status: 'draft', scoreboard_mode: 'system' },
        teams: [],
        leaderboard: [],
        completion: { approved_programs: 0, total_programs: 0, percentage: 0 },
        latest_log_id: 0,
        program_count: 0,
    };

    const ORB_RADIUS = 44;


    const app = {
        snapshot: normalizeSnapshot(window.__INITIAL_STATE__?.snapshot || DEFAULT_STATE),
        cursor: 0,
        processing: false,
        queue: [],
        stream: null,
        metadataTimer: null,
        driftTimer: null,
        particles: [],
        burstSeeds: [],
        teamToSlot: new Map(),
        slotToTeam: new Map(),
        cards: new Map(),
        previousPercent: 0,
        statusLock: false,
        pendingBatchIds: new Set(),
    };

    const els = {
        title: document.getElementById('eventTitle'),
        subtitle: document.getElementById('eventSubtitle'),
        connectionStatus: document.getElementById('connectionStatus'),
        approvalState: document.getElementById('approvalState'),
        orbPercent: document.getElementById('orbPercent'),
        orbProgress: document.getElementById('orbProgress'),
        approvedCount: document.getElementById('approvedCount'),
        totalCount: document.getElementById('totalCount'),
        banner: document.getElementById('banner'),
        panel: document.getElementById('updatesPanel'),
        panelChip: document.getElementById('batchChip'),
        panelGrid: document.getElementById('panelGrid'),
        canvas: document.getElementById('particle-canvas'),
        arena: document.getElementById('arena'),
    };

    const slotElements = {
        top: {
            card: document.getElementById('teamCardTop'),
            rank: document.getElementById('rankTop'),
            name: document.getElementById('nameTop'),
            sub: document.getElementById('subTop'),
            score: document.getElementById('scoreTop'),
            delta: document.getElementById('deltaTop'),
        },
        left: {
            card: document.getElementById('teamCardLeft'),
            rank: document.getElementById('rankLeft'),
            name: document.getElementById('nameLeft'),
            sub: document.getElementById('subLeft'),
            score: document.getElementById('scoreLeft'),
            delta: document.getElementById('deltaLeft'),
        },
        right: {
            card: document.getElementById('teamCardRight'),
            rank: document.getElementById('rankRight'),
            name: document.getElementById('nameRight'),
            sub: document.getElementById('subRight'),
            score: document.getElementById('scoreRight'),
            delta: document.getElementById('deltaRight'),
        },
        bottom: {
            card: document.getElementById('teamCardBottom'),
            rank: document.getElementById('rankBottom'),
            name: document.getElementById('nameBottom'),
            sub: document.getElementById('subBottom'),
            score: document.getElementById('scoreBottom'),
            delta: document.getElementById('deltaBottom'),
        },
    };

    const ctx = els.canvas.getContext('2d');
    let dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 2));

    function normalizeSnapshot(snapshot) {
        const s = structuredClone(snapshot || DEFAULT_STATE);
        s.event = s.event || DEFAULT_STATE.event;
        s.teams = Array.isArray(s.teams) ? s.teams : [];
        s.leaderboard = Array.isArray(s.leaderboard) && s.leaderboard.length ? s.leaderboard : s.teams;
        s.completion = s.completion || DEFAULT_STATE.completion;
        s.latest_log_id = Number(s.latest_log_id || 0);
        s.program_count = Number(s.program_count || s.completion.total_programs || 0);

        const allTeams = s.leaderboard.map(team => ({
            id: Number(team.id),
            team_name: team.team_name || `Team ${team.id}`,
            short_name: team.short_name || '',
            team_color: team.team_color || '#10b981',
            total_score: Number(team.total_score || 0),
            rank: Number(team.rank || 0),
        }));

        s.teams = allTeams;
        s.leaderboard = allTeams;
        s.teamMap = Object.fromEntries(allTeams.map(team => [team.id, team]));
        return s;
    }

    function formatScore(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(value || 0));
    }

    function formatInt(value) {
        return new Intl.NumberFormat('en-US').format(Number(value || 0));
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function lerp(a, b, t) {
        return a + (b - a) * t;
    }

    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    function shuffle(input) {
        const arr = input.slice();
        for (let i = arr.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    function setStatus(text, type = 'live') {
        if (type === 'live') {
            els.connectionStatus.textContent = text;
            els.connectionStatus.style.background = 'rgba(16,185,129,.14)';
            els.connectionStatus.style.borderColor = 'rgba(16,185,129,.26)';
        } else if (type === 'warn') {
            els.connectionStatus.textContent = text;
            els.connectionStatus.style.background = 'rgba(245,158,11,.14)';
            els.connectionStatus.style.borderColor = 'rgba(245,158,11,.28)';
        } else {
            els.connectionStatus.textContent = text;
            els.connectionStatus.style.background = 'rgba(255,255,255,.05)';
            els.connectionStatus.style.borderColor = 'rgba(255,255,255,.08)';
        }
    }

    function setApprovalText(text) {
        els.approvalState.textContent = text;
    }

    function updateOrb(completion, animate = true) {
        const percent = clamp(Number(completion.percentage || 0), 0, 100);
        const radius = ORB_RADIUS;
        const circumference = 2 * Math.PI * radius;
        const dashOffset = circumference - (percent / 100) * circumference;

        if (animate) {
            els.orbProgress.style.strokeDashoffset = String(dashOffset);
        } else {
            els.orbProgress.style.transition = 'none';
            els.orbProgress.style.strokeDashoffset = String(dashOffset);
            requestAnimationFrame(() => {
                els.orbProgress.style.transition = '';
            });
        }

        els.orbPercent.textContent = `${Math.round(percent)}%`;
        els.approvedCount.textContent = formatInt(completion.approved_programs || 0);
        els.totalCount.textContent = formatInt(completion.total_programs || 0);
    }

    function updateHeader(snapshot) {
        els.title.textContent = snapshot.event?.title || 'Al-Thanafus';
        const status = snapshot.event?.status || 'draft';
        const mode = snapshot.event?.scoreboard_mode || 'system';
        const modeText = mode === 'manual' ? 'Manual scoreboard mode' : 'Program approval system';
        els.subtitle.textContent = `${modeText} · ${status.charAt(0).toUpperCase() + status.slice(1)} event`;
    }

    function slotPosition(slot) {
        switch (slot) {
            case 'top': return { x: '50%', y: '22%' };
            case 'left': return { x: '23%', y: '50%' };
            case 'right': return { x: '77%', y: '50%' };
            case 'bottom': return { x: '50%', y: '78%' };
            default: return { x: '50%', y: '50%' };
        }
    }

    function updateCard(slot, team, opts = {}) {
        const el = slotElements[slot];
        if (!el || !team) {
            if (el?.card) {
                el.card.style.opacity = '0';
                el.card.style.pointerEvents = 'none';
            }
            return;
        }

        const color = team.team_color || '#10b981';
        el.card.style.setProperty('--team-color', color);
        el.card.dataset.teamId = String(team.id);
        el.card.dataset.slot = slot;
        el.card.style.opacity = '1';
        el.card.style.pointerEvents = 'auto';

        if (opts.reassign !== false) {
            const pos = slotPosition(slot);
            el.card.style.left = pos.x;
            el.card.style.top = pos.y;
        }

        el.rank.textContent = `Rank ${team.rank || SLOT_IDS.indexOf(slot) + 1}`;
        el.name.textContent = team.team_name || `Team ${team.id}`;
        el.sub.textContent = team.short_name ? team.short_name.toUpperCase() : `TEAM ${team.id}`;
        el.score.textContent = formatScore(team.total_score || 0);
        el.delta.textContent = '';
        el.delta.classList.remove('positive');
        el.card.classList.remove('reveal', 'dim', 'active');
        el.card.classList.add('settled');
    }

    function renderTeams(snapshot, opts = {}) {
        const allTeams = snapshot.leaderboard.slice(0, 4);
        app.teamToSlot.clear();
        app.slotToTeam.clear();

        SLOT_IDS.forEach((slot, index) => {
            const team = allTeams[index] || null;
            if (team) {
                app.teamToSlot.set(team.id, slot);
                app.slotToTeam.set(slot, team.id);
                updateCard(slot, team, opts);
            } else {
                const el = slotElements[slot];
                el.card.style.opacity = '0';
                el.card.style.pointerEvents = 'none';
                el.rank.textContent = '—';
                el.name.textContent = '—';
                el.sub.textContent = '—';
                el.score.textContent = '0.00';
                el.delta.textContent = '';
            }
        });
    }

    function refreshTeamColors(snapshot) {
        const teams = snapshot.leaderboard.slice(0, 4);
        teams.forEach((team, index) => {
            const slot = SLOT_IDS[index];
            const el = slotElements[slot];
            if (!el) return;
            if (String(el.card.dataset.teamId) === String(team.id)) {
                el.card.style.setProperty('--team-color', team.team_color || '#10b981');
                el.name.textContent = team.team_name || `Team ${team.id}`;
                el.sub.textContent = team.short_name ? team.short_name.toUpperCase() : `TEAM ${team.id}`;
            }
        });
    }

    function syncSnapshot(snapshot, opts = {}) {
        app.snapshot = normalizeSnapshot(snapshot);
        app.cursor = Number(app.snapshot.latest_log_id || app.cursor || 0);

        updateHeader(app.snapshot);
        updateOrb(app.snapshot.completion, opts.animate !== false);
        renderTeams(app.snapshot, opts);

        if (app.processing) return;
        setApprovalText('Waiting for approved scores');
        setStatus('LIVE', 'live');
        rootClass('idle', true);
    }

    function rootClass(name, enabled) {
        document.documentElement.classList.toggle(name, Boolean(enabled));
        document.body.classList.toggle(name, Boolean(enabled));
    }

    function setTeamHighlight(teamId, active = true) {
        SLOT_IDS.forEach(slot => {
            const el = slotElements[slot];
            if (!el) return;
            const isCurrent = String(el.card.dataset.teamId) === String(teamId);
            el.card.classList.toggle('active', active && isCurrent);
            el.card.classList.toggle('dim', active && !isCurrent);
        });
    }

    function clearHighlights() {
        SLOT_IDS.forEach(slot => {
            const el = slotElements[slot];
            if (!el) return;
            el.card.classList.remove('active', 'dim', 'reveal');
        });
    }

    function showBanner(message) {
        els.banner.textContent = message;
        els.banner.classList.add('show');
    }

    function hideBanner() {
        els.banner.classList.remove('show');
    }

    function showPanel(batch) {
        els.panel.classList.remove('hidden');
        els.panel.classList.add('progress-fade');
        els.panelChip.textContent = `${batch.programs.length} Program${batch.programs.length === 1 ? '' : 's'}`;
        renderPanelGrid(batch);
    }

    function hidePanel() {
        els.panel.classList.add('hidden');
        els.panel.classList.remove('progress-fade');
        els.panelGrid.innerHTML = '';
    }

    function renderPanelGrid(batch) {
        const frag = document.createDocumentFragment();

        batch.programs.forEach(program => {
            const card = document.createElement('article');
            card.className = 'program-card';

            const placements = Array.isArray(program.placements) ? program.placements.slice(0, 3) : [];
            const leadColor = placements[0]?.team_color || '#10b981';
            card.style.setProperty('--program-accent', leadColor);

            const title = document.createElement('h3');
            title.className = 'program-title';
            title.textContent = program.title;

            const sub = document.createElement('div');
            sub.className = 'program-sub';
            sub.textContent = program.stage_label || (program.stage_name || 'Program results');

            const list = document.createElement('div');
            list.className = 'placement-list';

            placements.forEach((entry, index) => {
                const row = document.createElement('div');
                row.className = 'placement-row';
                row.style.setProperty('--team-color', entry.team_color || leadColor);

                const rank = document.createElement('div');
                rank.className = 'placement-rank';
                rank.textContent = index === 0 ? '🥇' : index === 1 ? '🥈' : '🥉';

                const token = document.createElement('div');
                token.className = 'team-token';

                const dot = document.createElement('span');
                dot.className = 'dot';

                const name = document.createElement('span');
                name.textContent = entry.team_name;

                token.append(dot, name);
                row.append(rank, token);
                list.append(row);
            });

            card.append(title, sub, list);
            frag.append(card);
        });

        els.panelGrid.innerHTML = '';
        els.panelGrid.append(frag);
    }

    function getVisibleTeams(snapshot) {
        return snapshot.leaderboard.slice(0, 4);
    }

    function teamContributionsForBatch(batch, teamId) {
        const out = [];
        for (const program of batch.programs || []) {
            const entries = Array.isArray(program.entries) ? program.entries : [];
            const total = entries
                .filter(entry => Number(entry.team_id) === Number(teamId))
                .reduce((sum, entry) => sum + Number(entry.final_score || 0), 0);

            if (total > 0) {
                out.push({
                    program_id: program.id,
                    title: program.title,
                    score: total,
                });
            }
        }
        return out;
    }

    function cardForTeam(teamId) {
        const slot = app.teamToSlot.get(Number(teamId));
        return slot ? slotElements[slot] : null;
    }

    function spawnBurstFromCard(teamId, count = 12) {
        const slot = app.teamToSlot.get(Number(teamId));
        if (!slot) return;
        const el = slotElements[slot]?.card;
        if (!el) return;

        const rect = el.getBoundingClientRect();
        const color = getComputedStyle(el).getPropertyValue('--team-color').trim() || '#10b981';

        for (let i = 0; i < count; i += 1) {
            app.particles.push({
                x: rect.left + rect.width / 2 + (Math.random() - 0.5) * rect.width * 0.18,
                y: rect.top + rect.height / 2 + (Math.random() - 0.5) * rect.height * 0.18,
                vx: (Math.random() - 0.5) * 1.8,
                vy: -Math.random() * 2.2 - 0.4,
                life: 1,
                decay: 0.015 + Math.random() * 0.02,
                size: 1.8 + Math.random() * 2.8,
                color,
            });
        }
    }

    function spawnAmbientParticles() {
        const width = window.innerWidth;
        const height = window.innerHeight;

        for (let i = 0; i < 45; i += 1) {
            app.particles.push({
                x: Math.random() * width,
                y: Math.random() * height,
                vx: (Math.random() - 0.5) * 0.22,
                vy: (Math.random() - 0.5) * 0.22,
                life: 0.3 + Math.random() * 0.6,
                decay: 0.0008 + Math.random() * 0.0015,
                size: 0.8 + Math.random() * 1.4,
                color: 'rgba(24, 199, 127, 0.48)',
                ambient: true,
            });
        }
    }

    function resizeCanvas() {
        dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 2));
        els.canvas.width = Math.floor(window.innerWidth * dpr);
        els.canvas.height = Math.floor(window.innerHeight * dpr);
        els.canvas.style.width = `${window.innerWidth}px`;
        els.canvas.style.height = `${window.innerHeight}px`;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function drawParticles() {
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);

        for (const p of app.particles) {
            p.x += p.vx;
            p.y += p.vy;
            p.life -= p.decay;

            if (p.ambient) {
                if (p.x < -50) p.x = window.innerWidth + 50;
                if (p.x > window.innerWidth + 50) p.x = -50;
                if (p.y < -50) p.y = window.innerHeight + 50;
                if (p.y > window.innerHeight + 50) p.y = -50;
            }

            ctx.globalAlpha = Math.max(0, p.life);
            ctx.beginPath();
            ctx.fillStyle = p.color;
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.globalAlpha = 1;
        app.particles = app.particles.filter(p => p.life > 0);
        requestAnimationFrame(drawParticles);
    }

    function animateValue(from, to, duration, onUpdate) {
        return new Promise(resolve => {
            const start = performance.now();
            function frame(now) {
                const t = clamp((now - start) / duration, 0, 1);
                const eased = easeOutCubic(t);
                const value = lerp(from, to, eased);
                onUpdate(value);
                if (t < 1) {
                    requestAnimationFrame(frame);
                } else {
                    resolve();
                }
            }
            requestAnimationFrame(frame);
        });
    }

    async function animateTeamScore(teamId, contributions) {
        const card = cardForTeam(teamId);
        if (!card) return;

        const scoreEl = card.score;
        const deltaEl = card.delta;
        const team = app.snapshot.teamMap[teamId];
        let current = Number(team?.total_score || 0);

        setTeamHighlight(teamId, true);
        card.card.classList.add('active', 'reveal');

        for (const item of contributions) {
            const next = current + Number(item.score || 0);
            deltaEl.textContent = `+${formatScore(item.score)}`;
            deltaEl.classList.add('positive');
            showBanner(item.title);
            spawnBurstFromCard(teamId, 11);

            await animateValue(current, next, 760, value => {
                scoreEl.textContent = formatScore(value);
            });

            current = next;
            team.total_score = current;
            app.snapshot.teamMap[teamId].total_score = current;
            await sleep(120);
        }

        deltaEl.textContent = '';
        card.card.classList.remove('reveal');
        card.card.classList.add('settled');
        await sleep(160);
    }

    function computeNextLeaderboard() {
        const teams = Object.values(app.snapshot.teamMap || {}).map(team => ({
            ...team,
            total_score: Number(team.total_score || 0),
        }));

        teams.sort((a, b) => {
            if (b.total_score === a.total_score) {
                return String(a.team_name).localeCompare(String(b.team_name));
            }
            return b.total_score - a.total_score;
        });

        teams.forEach((team, idx) => {
            team.rank = idx + 1;
        });

        return teams;
    }

    async function animateCompletionUpdate(oldSnapshot, newSnapshot) {
        const startPercent = Number(oldSnapshot.completion.percentage || 0);
        const endPercent = Number(newSnapshot.completion.percentage || 0);
        const startApproved = Number(oldSnapshot.completion.approved_programs || 0);
        const endApproved = Number(newSnapshot.completion.approved_programs || 0);
        const total = Number(newSnapshot.completion.total_programs || 0);

        await Promise.all([
            animateValue(startPercent, endPercent, 1050, value => {
                const percent = Math.round(value);
                els.orbPercent.textContent = `${percent}%`;
                const radius = ORB_RADIUS;
                const circumference = 2 * Math.PI * radius;
                els.orbProgress.style.strokeDashoffset = String(circumference - (value / 100) * circumference);
            }),
            animateValue(startApproved, endApproved, 1050, value => {
                els.approvedCount.textContent = formatInt(value);
                els.totalCount.textContent = formatInt(total);
            }),
        ]);

        app.snapshot.completion = {
            approved_programs: endApproved,
            total_programs: total,
            percentage: endPercent,
        };
    }

    function repositionByRank(teams) {
        const slots = SLOT_IDS.slice();
        const ordered = teams.slice(0, 4);

        ordered.forEach((team, idx) => {
            const slot = slots[idx];
            const card = slotElements[slot];
            if (!card) return;
            const pos = slotPosition(slot);

            card.card.dataset.slot = slot;
            card.card.style.left = pos.x;
            card.card.style.top = pos.y;
            card.card.style.setProperty('--team-color', team.team_color || '#10b981');
            card.card.dataset.teamId = String(team.id);
            card.rank.textContent = `Rank ${team.rank}`;
            card.name.textContent = team.team_name;
            card.sub.textContent = team.short_name ? team.short_name.toUpperCase() : `TEAM ${team.id}`;
            card.score.textContent = formatScore(team.total_score);
            card.card.classList.add('settled');
            app.teamToSlot.set(team.id, slot);
            app.slotToTeam.set(slot, team.id);
        });

        SLOT_IDS.slice(ordered.length).forEach(slot => {
            const card = slotElements[slot];
            if (!card) return;
            card.card.style.opacity = '0';
            card.card.style.pointerEvents = 'none';
        });
    }

    async function runBatch(batch) {
        if (!batch || !Array.isArray(batch.programs) || batch.programs.length === 0) {
            app.cursor = Math.max(app.cursor, Number(batch?.batch_id || 0));
            return;
        }

        app.processing = true;
        app.statusLock = true;
        rootClass('idle', false);

        setStatus('REVEALING', 'warn');
        setApprovalText('New approvals received');
        showBanner('NEW APPROVALS RECEIVED');
        els.panel.classList.add('hidden');
        els.panelGrid.innerHTML = '';

        const oldSnapshot = normalizeSnapshot({
            ...app.snapshot,
            completion: { ...app.snapshot.completion },
            teams: app.snapshot.leaderboard.map(team => ({ ...team })),
            leaderboard: app.snapshot.leaderboard.map(team => ({ ...team })),
        });

        await sleep(1100);
        hideBanner();

        const visibleTeams = getVisibleTeams(app.snapshot);
        const affectedTeamIds = new Set(
            (batch.team_deltas || []).map(row => Number(row.team_id)).filter(Boolean)
        );

        if (affectedTeamIds.size === 0) {
            batch.programs.forEach(program => {
                program.entries.forEach(entry => affectedTeamIds.add(Number(entry.team_id)));
            });
        }

        const revealOrder = shuffle([...affectedTeamIds]);
        for (const teamId of revealOrder) {
            setTeamHighlight(teamId, true);
            const contributions = teamContributionsForBatch(batch, teamId);
            if (contributions.length > 0) {
                await animateTeamScore(teamId, contributions);
            }
            await sleep(220);
        }

        clearHighlights();
        const oldLeaderboard = oldSnapshot.leaderboard.map(team => ({ ...team }));
        const updatedTeams = computeNextLeaderboard();
        const newSnapshot = normalizeSnapshot({
            ...app.snapshot,
            leaderboard: updatedTeams,
            teams: updatedTeams,
            completion: {
                approved_programs: Number(app.snapshot.completion.approved_programs || 0) + batch.programs.length,
                total_programs: Number(app.snapshot.completion.total_programs || 0),
                percentage: 0,
            },
            latest_log_id: Math.max(Number(app.cursor || 0), Number(batch.batch_id || 0)),
        });

        newSnapshot.completion.percentage = newSnapshot.completion.total_programs > 0
            ? Number(((newSnapshot.completion.approved_programs / newSnapshot.completion.total_programs) * 100).toFixed(2))
            : 0;

        app.snapshot = newSnapshot;

        await animateCompletionUpdate(oldSnapshot, newSnapshot);
        repositionByRank(newSnapshot.leaderboard);

        SLOT_IDS.forEach(slot => {
            const card = slotElements[slot];
            if (!card) return;
            card.card.classList.add('settled');
            card.card.classList.remove('dim', 'active');
        });

        // rank labels become the headline after the move settles
        setApprovalText('Rankings updated from approved results');
        showPanel(batch);

        const settleDuration = 2600 + (batch.programs.length * 240);
        await sleep(settleDuration);

        hidePanel();
        hideBanner();
        setApprovalText('Waiting for approved scores');
        setStatus('LIVE', 'live');
        app.cursor = Math.max(app.cursor, Number(batch.batch_id || 0));
        app.pendingBatchIds.delete(Number(batch.batch_id || 0));
        app.processing = false;
        app.statusLock = false;
        rootClass('idle', true);
    }

    function queueBatch(batch) {
        if (!batch) return;
        const batchId = Number(batch.batch_id || 0);
        if (!batchId || batchId <= Number(app.cursor || 0) || app.pendingBatchIds.has(batchId)) {
            return;
        }
        app.pendingBatchIds.add(batchId);
        app.queue.push(batch);
        if (!app.processing) {
            void drainQueue();
        }
    }

    async function drainQueue() {
        if (app.processing) return;
        const next = app.queue.shift();
        if (!next) return;
        try {
            await runBatch(next);
        } catch (error) {
            console.error(error);
            if (next?.batch_id) app.pendingBatchIds.delete(Number(next.batch_id));
            setStatus('RECONNECTING', 'warn');
            setApprovalText('Connection interruption detected');
            app.processing = false;
            app.statusLock = false;
            rootClass('idle', true);
        } finally {
            if (app.queue.length > 0) {
                await sleep(120);
                void drainQueue();
            }
        }
    }

    function connectStream() {
        if (!('EventSource' in window)) {
            setStatus('POLLING', 'warn');
            startFallbackPolling();
            return;
        }

        const streamUrl = `api/stream.php?since=${encodeURIComponent(app.cursor || 0)}`;
        const es = new EventSource(streamUrl);
        app.stream = es;

        es.addEventListener('hello', () => {
            setStatus('LIVE', 'live');
            setApprovalText('Waiting for approved scores');
        });

        es.addEventListener('batch', event => {
            try {
                const batch = JSON.parse(event.data);
                if (Number(batch.batch_id || 0) <= Number(app.cursor || 0)) return;
                queueBatch(batch);
            } catch (error) {
                console.error('Invalid batch payload', error);
            }
        });

        es.addEventListener('error', () => {
            setStatus('RECONNECTING', 'warn');
            if (!app.pollTimer) {
                startFallbackPolling();
            }
        });
    }

    async function pollStateSinceCursor() {
        try {
            const response = await fetch(`api/state.php?since=${encodeURIComponent(app.cursor || 0)}`, {
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) return;
            const data = await response.json();
            if (data?.snapshot) {
                const serverSnapshot = normalizeSnapshot(data.snapshot);
                if (!app.processing && Number(serverSnapshot.latest_log_id || 0) === Number(app.cursor || 0)) {
                    // Safe metadata refresh only.
                    syncSnapshot(serverSnapshot, { animate: false });
                } else {
                    if (Array.isArray(data.batches) && data.batches.length) {
                        data.batches.forEach(queueBatch);
                    }
                }
            }
        } catch (error) {
            console.error('Polling failed', error);
        }
    }

    function startFallbackPolling() {
        if (app.pollTimer) return;
        app.pollTimer = setInterval(() => {
            void pollStateSinceCursor();
        }, 4500);
        void pollStateSinceCursor();
    }

    async function refreshMetadataLoop() {
        if (app.metadataTimer) return;
        app.metadataTimer = setInterval(async () => {
            try {
                const response = await fetch('api/bootstrap.php', { cache: 'no-store' });
                if (!response.ok) return;
                const data = await response.json();
                if (!data?.snapshot) return;
                const fresh = normalizeSnapshot(data.snapshot);
                updateHeader(fresh);
                refreshTeamColors(fresh);

                if (!app.processing && Number(fresh.latest_log_id || 0) === Number(app.cursor || 0)) {
                    syncSnapshot(fresh, { animate: false });
                }
            } catch (error) {
                console.error('Metadata refresh failed', error);
            }
        }, 30000);
    }

    function idleDrift() {
        if (app.driftTimer) return;
        const apply = () => {
            if (app.processing) return;

            SLOT_IDS.forEach(slot => {
                const card = slotElements[slot]?.card;
                if (!card || card.style.opacity === '0') return;
                const dx = Math.round((Math.random() - 0.5) * 14);
                const dy = Math.round((Math.random() - 0.5) * 14);
                card.style.setProperty('--dx', `${dx}px`);
                card.style.setProperty('--dy', `${dy}px`);
            });
        };

        apply();
        app.driftTimer = setInterval(apply, 5200);
    }

    function updateInitialLayout() {
        const snapshot = app.snapshot;
        updateHeader(snapshot);
        updateOrb(snapshot.completion, false);
        renderTeams(snapshot, { animate: false });
        setApprovalText('Waiting for approved scores');
        rootClass('idle', true);
    }

    function buildAmbientParticles() {
        spawnAmbientParticles();
        setInterval(() => {
            if (app.particles.length < 160) {
                spawnAmbientParticles();
            }
        }, 9000);
    }

    function init() {
        app.cursor = Number(app.snapshot.latest_log_id || 0);
        updateInitialLayout();
        resizeCanvas();
        buildAmbientParticles();
        drawParticles();
        connectStream();
        refreshMetadataLoop();
        idleDrift();

        window.addEventListener('resize', () => {
            resizeCanvas();
        });

        window.addEventListener('beforeunload', () => {
            if (app.stream) app.stream.close();
            if (app.metadataTimer) clearInterval(app.metadataTimer);
            if (app.driftTimer) clearInterval(app.driftTimer);
            if (app.pollTimer) clearInterval(app.pollTimer);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
