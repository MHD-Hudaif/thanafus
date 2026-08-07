/* global gsap, TVSlide, TV_SCHEDULE_DATA */
(() => {
    'use strict';

    const data = window.TV_SCHEDULE_DATA || {};
    const dayGroups = Array.isArray(data.day_groups) ? data.day_groups : [];
    const els = {
        clock: document.getElementById('scheduleClock'),
        progress: document.getElementById('scheduleProgress'),
        shell: document.getElementById('schedulePageShell'),
    };

    const state = {
        pages: [],
        currentPage: 0,
        complete: false,
        clockTimer: null,
        playing: false,
        layoutSignature: '',
        resizeTimer: null,
    };

    const timings = {
        enter: 820,
        hold: 4200,
        exit: 650,
        stagger: 0.06,
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function formatClock() {
        const now = new Date();
        return now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function updateClock() {
        if (els.clock) {
            els.clock.textContent = formatClock();
        }
    }

    function setProgressText() {
        if (!els.progress) return;
        const total = state.pages.length;
        const label = total > 0
            ? `${Math.min(state.currentPage + 1, total)} / ${total}`
            : '0 / 0';

        els.progress.textContent = label;

        if (window.TVSlide) {
            window.TVSlide.currentPage = state.currentPage;
            window.TVSlide.totalPages = total;
            window.TVSlide.batchSize = getPageSize().pageSize;
        }
    }

    function setComplete() {
        state.complete = true;
        state.playing = false;
        if (state.clockTimer) {
            clearInterval(state.clockTimer);
            state.clockTimer = null;
        }
        if (window.TVSlide) {
            window.TVSlide.complete = true;
        }
        window.dispatchEvent(new CustomEvent('slideComplete', {
            detail: { slide: 'schedule' }
        }));
        setProgressText();
    }

    function ordinal(n) {
        if (n === 1) return 'st';
        if (n === 2) return 'nd';
        if (n === 3) return 'rd';
        return 'th';
    }

    function timeText(value) {
        if (!value) return '—';
        const dt = new Date(value);
        if (Number.isNaN(dt.getTime())) {
            return String(value);
        }
        return dt.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function timeRange(startValue, endValue) {
        const start = timeText(startValue);
        const end = timeText(endValue);
        if (start === '—' && end === '—') return '—';
        if (start === '—') return end;
        if (end === '—') return start;
        return `${start} - ${end}`;
    }

    function getPageSize() {
        const width = window.innerWidth || 1280;
        const height = window.innerHeight || 720;
        const twoColumn = width >= 1280;

        const topbarApprox = Math.min(130, Math.max(92, Math.round(height * 0.12)));
        const safeArea = Math.max(360, height - topbarApprox - 36);

        const cardMin = width >= 1600 ? 86 : width >= 1200 ? 92 : 98;
        const gap = width >= 1600 ? 14 : 16;

        let rows = Math.floor((safeArea + gap) / (cardMin + gap));
        rows = Math.max(4, Math.min(rows, width >= 1600 ? 8 : 7));

        const cols = twoColumn ? 2 : 1;
        const pageSize = Math.max(4, rows * cols);

        return {
            pageSize,
            cols,
            rows,
            signature: `${width}x${height}:${pageSize}:${cols}`,
        };
    }

    function chunkItems(items, size) {
        const result = [];
        const step = Math.max(1, size);

        for (let i = 0; i < items.length; i += step) {
            result.push(items.slice(i, i + step));
        }

        return result;
    }

    function buildPages() {
        const { pageSize } = getPageSize();
        const pages = [];

        for (const group of dayGroups) {
            const items = Array.isArray(group?.items) ? group.items : [];
            const chunks = chunkItems(items, pageSize);

            chunks.forEach((chunk, idx) => {
                pages.push({
                    day_number: Number(group?.day_number || 1),
                    day_label: `Day ${Number(group?.day_number || 1)}`,
                    display_date: String(group?.display_date || '—'),
                    page_index: idx + 1,
                    page_total: chunks.length || 1,
                    items: chunk,
                });
            });
        }

        return pages;
    }

    function createCard(item) {
        const kind = item?.kind || 'program';
        const card = document.createElement('article');
        card.className = `schedule-card ${kind === 'break' ? 'schedule-card--break' : ''}`;
        card.dataset.kind = kind;

        const title = String(item?.title || 'Program');
        const place = String(item?.place || '').trim();
        const time = timeRange(item?.start_time || null, item?.end_time || null);

        if (kind === 'break') {
            card.innerHTML = `
                <div class="schedule-card-head">
                    <div class="schedule-card-title">${escapeHtml(title)}</div>
                    <div class="schedule-card-time">${escapeHtml(time)}</div>
                </div>
                ${place ? `<div class="schedule-card-place">${escapeHtml(place)}</div>` : ''}
                <div class="schedule-card-body schedule-card-body--center">
                    <div class="schedule-break-badge">BREAK</div>
                </div>
            `;
            return card;
        }

        const results = Array.isArray(item?.results) ? item.results.slice(0, 3) : [];
        const status = String(item?.status || 'pending');
        const statusLabel = status === 'completed' ? 'Completed' : (status === 'scoring' ? 'Scoring' : 'Pending');
        const statusClass = status === 'completed' ? 'done' : (status === 'scoring' ? 'live' : 'pending');

        const ranksHtml = results.length
            ? `<div class="schedule-ranks">${results.map((r) => {
                const rank = Number(r?.final_rank || 0);
                const color = String(r?.team_color || '#1fe08a');
                const teamName = String(r?.team_name || 'Team');
                return `<span class="schedule-rank-pill schedule-rank-pill--${rank}" style="background:${escapeHtml(color)};"><span>${rank}${ordinal(rank)}</span><span class="schedule-rank-team">${escapeHtml(teamName)}</span></span>`;
            }).join('')}</div>`
            : '';

        card.innerHTML = `
            <div class="schedule-card-head">
                <div class="schedule-card-title">${escapeHtml(title)}</div>
                <div class="schedule-card-time">${escapeHtml(time)}</div>
            </div>
            ${place ? `<div class="schedule-card-place">${escapeHtml(place)}</div>` : ''}
            <div class="schedule-card-subhead">
                <span class="schedule-status schedule-status--${statusClass}">${escapeHtml(statusLabel)}</span>
            </div>
            <div class="schedule-card-body">
                ${ranksHtml}
            </div>
        `;

        return card;
    }

    function createPage(pageData, index) {
        const page = document.createElement('section');
        page.className = 'schedule-page';
        page.dataset.page = String(index);

        const head = document.createElement('div');
        head.className = 'schedule-page-head';

        const dayLabel = document.createElement('div');
        dayLabel.innerHTML = `
            <div class="schedule-day-label">${escapeHtml(pageData?.day_label || 'Day 1')}</div>
            <div class="schedule-page-date">${escapeHtml(pageData?.display_date || '—')}</div>
        `;

        const count = document.createElement('div');
        count.className = 'schedule-page-count';
        count.textContent = `Part ${Number(pageData?.page_index || 1)} / ${Number(pageData?.page_total || 1)}`;

        head.appendChild(dayLabel);
        head.appendChild(count);

        const grid = document.createElement('div');
        grid.className = 'schedule-card-grid';
        if ((getPageSize().cols || 1) > 1) {
            grid.classList.add('is-two-column');
        }

        (Array.isArray(pageData?.items) ? pageData.items : []).forEach((item) => {
            grid.appendChild(createCard(item));
        });

        page.appendChild(head);
        page.appendChild(grid);

        return page;
    }

    function setPageGridMode(page) {
        const grid = page.querySelector('.schedule-card-grid');
        if (!grid) return;
        const { cols } = getPageSize();
        grid.classList.toggle('is-two-column', cols > 1);
    }

    function hideActivePage(direction = -1) {
        const active = els.shell.querySelector('.schedule-page.is-active');
        if (!active) {
            return Promise.resolve();
        }

        const cards = active.querySelectorAll('.schedule-card');

        return new Promise((resolve) => {
            if (typeof gsap !== 'undefined') {
                const tl = gsap.timeline({
                    onComplete: () => {
                        active.remove();
                        resolve();
                    }
                });

                tl.to(cards, {
                    x: direction < 0 ? -42 : 42,
                    opacity: 0,
                    duration: 0.45,
                    stagger: timings.stagger * 0.75,
                    ease: 'power2.in',
                }, 0)
                .to(active, {
                    x: direction < 0 ? '-12vw' : '12vw',
                    opacity: 0,
                    duration: timings.exit / 1000,
                    ease: 'power2.inOut',
                }, 0.05);
            } else {
                active.remove();
                resolve();
            }
        });
    }

    function showPage(page) {
        page.classList.add('is-active');
        page.style.opacity = '0';
        page.style.visibility = 'visible';
        page.style.transform = 'translateX(18vw)';
        els.shell.appendChild(page);

        setPageGridMode(page);

        if (typeof gsap !== 'undefined') {
            const cards = page.querySelectorAll('.schedule-card');
            gsap.set(page, { x: '16vw', opacity: 0 });
            gsap.fromTo(page, {
                x: '16vw',
                opacity: 0,
            }, {
                x: '0vw',
                opacity: 1,
                duration: timings.enter / 1000,
                ease: 'power3.out',
            });

            gsap.fromTo(cards, {
                x: 48,
                opacity: 0,
            }, {
                x: 0,
                opacity: 1,
                duration: 0.7,
                stagger: timings.stagger,
                ease: 'power3.out',
                delay: 0.05,
            });
        } else {
            page.style.opacity = '1';
            page.style.transform = 'translateX(0)';
        }

        setProgressText();
    }

    function renderPages(resetCurrent = true) {
        const layout = getPageSize();
        if (state.layoutSignature === layout.signature && els.shell.children.length > 0) {
            return false;
        }

        state.layoutSignature = layout.signature;
        state.pages = buildPages();
        state.currentPage = resetCurrent ? 0 : Math.min(state.currentPage, Math.max(0, state.pages.length - 1));

        if (els.shell) {
            els.shell.innerHTML = '';
            if (state.pages.length === 0) {
                const empty = document.createElement('section');
                empty.className = 'schedule-page is-empty is-active';
                empty.dataset.page = 'empty';
                empty.innerHTML = `
                    <div class="schedule-empty">
                        <h2>No schedule items found</h2>
                        <p>There are no programs or breaks available for the current event.</p>
                    </div>
                `;
                els.shell.appendChild(empty);
            }
        }

        setProgressText();
        return true;
    }

    function wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    async function play() {
        if (state.playing) return;
        state.playing = true;

        renderPages(true);

        if (!state.pages.length) {
            setComplete();
            return;
        }

        updateClock();
        setProgressText();

        for (let i = 0; i < state.pages.length; i++) {
            state.currentPage = i;
            setProgressText();

            const page = createPage(state.pages[i], i);
            showPage(page);

            await wait(timings.enter + timings.hold);

            if (i < state.pages.length - 1) {
                await hideActivePage(-1);
                await wait(120);
            }
        }

        setComplete();
    }

    function startClock() {
        updateClock();
        state.clockTimer = setInterval(updateClock, 1000);
    }

    function restartIfNeeded() {
        const oldSignature = state.layoutSignature;
        const next = getPageSize().signature;

        if (oldSignature && oldSignature !== next) {
            window.location.reload();
        }
    }

    function boot() {
        renderPages(true);
        startClock();
        play();
    }

    const onResize = () => {
        clearTimeout(state.resizeTimer);
        state.resizeTimer = setTimeout(restartIfNeeded, 200);
    };

    window.addEventListener('resize', onResize, { passive: true });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            return;
        }
        updateClock();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
