<?php
declare(strict_types=1);

if (!defined('TV_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $tvBodyClass = trim(($tvBodyClass ?? '') . ' tv-current-schedule-theme');
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'current-program';
    $settings['slides']['current-program']['enabled'] = true;
    $settings['slides']['current-program']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-current-program" data-slide="current-program" style="opacity: 1; visibility: visible; transform: scale(1);">';
}
?>
<?php if (!defined('TV_STAGE')): ?>
<script>
document.body.classList.add('tv-current-schedule-theme');
document.querySelector('.tv-topbar')?.setAttribute('hidden', '');
</script>
<?php endif; ?>

<style>
body.tv-current-schedule-theme .tv-topbar,
body:has(#slide-current-program.tv-slide--active) .tv-topbar {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}

#slide-current-program {
    padding: 0 !important;
    overflow: hidden;
}

.current-schedule {
    --schedule-team-1: #ffe34a;
    --schedule-team-2: #ff42f5;
    --schedule-team-3: #25ff8a;
    --schedule-team-4: #2ee8ff;
    --current-neon: #25ff8a;
    width: 100%;
    height: 100%;
}

.current-schedule .tv-schedule-title-block {
    margin-top: 4px;
}

.current-schedule-board {
    position: relative;
    z-index: 2;
    width: min(1460px, calc(100vw - 320px));
    height: min(610px, calc(100vh - 332px));
    margin: 18px auto 0;
    border: 2px solid var(--schedule-team-4);
    background:
        radial-gradient(circle at 50% 0%, color-mix(in srgb, var(--current-neon) 18%, transparent), transparent 34%),
        linear-gradient(180deg, rgba(2, 12, 18, .94), rgba(0, 7, 12, .92));
    box-shadow:
        0 0 0 2px color-mix(in srgb, var(--schedule-team-1) 44%, transparent),
        0 0 30px color-mix(in srgb, var(--schedule-team-4) 62%, transparent),
        0 0 64px color-mix(in srgb, var(--current-neon) 28%, transparent),
        0 28px 80px rgba(0,0,0,.62);
    clip-path: polygon(22px 0, calc(100% - 22px) 0, 100% 22px, 100% calc(100% - 22px), calc(100% - 22px) 100%, 22px 100%, 0 calc(100% - 22px), 0 22px);
    padding: 28px 34px;
    overflow: hidden;
}

.current-schedule-board::before,
.current-schedule-board::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.current-schedule-board::before {
    z-index: 0;
    background:
        linear-gradient(90deg, transparent 0%, color-mix(in srgb, var(--schedule-team-2) 18%, transparent) 48%, transparent 72%),
        linear-gradient(180deg, color-mix(in srgb, var(--schedule-team-4) 8%, transparent), transparent 42%);
    transform: translateX(-72%) skewX(-18deg);
    animation: schedule-board-sweep 6.8s ease-in-out infinite;
}

.current-schedule-board::after {
    z-index: 0;
    border: 1px solid color-mix(in srgb, var(--current-neon) 40%, transparent);
    box-shadow:
        0 0 20px color-mix(in srgb, var(--schedule-team-4) 28%, transparent) inset,
        0 0 34px color-mix(in srgb, var(--schedule-team-2) 20%, transparent);
    opacity: .66;
    animation: schedule-board-breathe 3.8s ease-in-out infinite;
}

.current-schedule-board > * {
    position: relative;
    z-index: 1;
}

.current-program-grid {
    height: 100%;
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(360px, .7fr);
    gap: 28px;
    min-height: 0;
}

.current-main-panel,
.current-side-panel {
    min-height: 0;
    border-left: 3px solid color-mix(in srgb, var(--current-neon) 82%, transparent);
    background:
        linear-gradient(90deg, color-mix(in srgb, var(--current-neon) 13%, transparent), transparent 58%),
        rgba(0,0,0,.16);
    box-shadow: 10px 0 26px color-mix(in srgb, var(--current-neon) 7%, transparent) inset;
}

.current-main-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 26px 34px;
}

.current-side-panel {
    display: grid;
    grid-template-rows: 1fr 1fr 1fr;
    gap: 16px;
    padding: 18px;
}

.current-label {
    color: #ffe34a;
    font-size: 18px;
    font-weight: 900;
    letter-spacing: .22em;
    text-transform: uppercase;
    text-shadow: 0 0 12px rgba(255, 210, 41, .55);
}

.current-title {
    margin: 14px 0 18px;
    color: #f7f8fb;
    font-size: clamp(62px, 6.7vw, 118px);
    line-height: .86;
    letter-spacing: .02em;
    text-transform: uppercase;
    text-shadow: 0 0 24px rgba(255,255,255,.34), 0 8px 0 rgba(0,0,0,.34);
    overflow-wrap: anywhere;
}

.current-performer-row {
    display: grid;
    grid-template-columns: 66px minmax(0, 1fr);
    gap: 18px;
    align-items: center;
    margin-top: 8px;
}

.current-avatar,
.current-next-avatar {
    display: grid;
    place-items: center;
    color: var(--current-neon);
    border: 1px solid var(--current-neon);
    text-shadow: 0 0 14px var(--current-neon);
    box-shadow: 0 0 18px color-mix(in srgb, var(--current-neon) 46%, transparent), inset 0 0 12px color-mix(in srgb, var(--current-neon) 20%, transparent);
    font-weight: 900;
    clip-path: polygon(50% 0, 100% 20%, 100% 80%, 50% 100%, 0 80%, 0 20%);
}

.current-avatar {
    width: 62px;
    height: 62px;
    font-size: 24px;
}

.current-performer {
    color: #fff;
    font-size: clamp(30px, 3.2vw, 54px);
    font-weight: 900;
    line-height: 1;
    text-shadow: 0 0 16px rgba(255,255,255,.2);
}

[data-current-team],
[data-next-team] {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 9px;
    color: var(--current-neon);
    font-size: 16px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    text-shadow: 0 0 12px var(--current-neon);
}

.current-meta-row {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-top: 28px;
}

.current-meta-card,
.current-side-card {
    border: 1px solid color-mix(in srgb, var(--current-neon) 36%, transparent);
    background: linear-gradient(90deg, color-mix(in srgb, var(--current-neon) 10%, transparent), rgba(0,0,0,.12));
    box-shadow: 0 0 24px color-mix(in srgb, var(--current-neon) 13%, transparent) inset;
}

.current-meta-card {
    padding: 16px 18px;
}

.current-meta-card span,
.current-side-card span,
.current-progress-head span {
    display: block;
    color: rgba(244,247,246,.72);
    font-size: 13px;
    font-weight: 900;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.current-meta-card strong {
    display: block;
    margin-top: 6px;
    color: #ffe34a;
    font-size: clamp(22px, 2vw, 34px);
    line-height: 1;
    font-weight: 900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.current-progress {
    margin-top: 24px;
}

.current-progress-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 10px;
}

.current-progress-head strong {
    color: var(--current-neon);
    font-size: 18px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.current-progress-track {
    height: 12px;
    border: 1px solid color-mix(in srgb, var(--current-neon) 45%, transparent);
    background: rgba(255,255,255,.05);
    box-shadow: inset 0 0 14px rgba(0,0,0,.5);
    overflow: hidden;
}

.current-progress-fill {
    width: 0;
    height: 100%;
    background: linear-gradient(90deg, var(--current-neon), #fff);
    box-shadow: 0 0 18px color-mix(in srgb, var(--current-neon) 70%, transparent);
    transition: width .7s ease;
}

.current-side-card {
    min-height: 0;
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.current-side-value {
    margin-top: 10px;
    color: #fff;
    font-size: clamp(24px, 2.3vw, 42px);
    font-weight: 900;
    line-height: 1.05;
    overflow-wrap: anywhere;
}

.current-next-row {
    display: grid;
    grid-template-columns: 54px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
}

.current-next-avatar {
    width: 52px;
    height: 52px;
    font-size: 20px;
}

.current-judges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.tv-judge-badge,
.current-judge-avatar {
    display: inline-grid;
    place-items: center;
    min-width: 42px;
    height: 42px;
    padding: 0 10px;
    color: #ffe34a;
    border: 1px solid rgba(255, 227, 74, .58);
    background: rgba(255, 227, 74, .09);
    font-weight: 900;
    text-transform: uppercase;
    box-shadow: 0 0 18px rgba(255, 227, 74, .2);
}

.current-schedule .tv-schedule-stats {
    grid-template-columns: repeat(5, 1fr);
}

@media (max-width: 1400px) {
    .current-schedule-board {
        width: calc(100vw - 48px);
        height: min(650px, calc(100vh - 250px));
        padding: 18px;
    }

    .current-program-grid {
        gap: 16px;
        grid-template-columns: minmax(0, 1.2fr) minmax(300px, .72fr);
    }

    .current-title {
        font-size: clamp(46px, 6vw, 82px);
    }

    .current-performer {
        font-size: clamp(24px, 3vw, 38px);
    }

    .current-side-value {
        font-size: clamp(18px, 2vw, 28px);
    }
}
</style>

<div class="tv-schedule current-schedule" data-current-theme-root>
    <div class="tv-schedule-cinema">
        <div class="tv-schedule-light tv-schedule-light--gold"></div>
        <div class="tv-schedule-light tv-schedule-light--cyan"></div>
        <div class="tv-schedule-side tv-schedule-side--left">
            <span></span>
            <strong>AL</strong>
        </div>
        <div class="tv-schedule-side tv-schedule-side--right">
            <span></span>
            <strong>TH</strong>
        </div>

        <div class="tv-schedule-brand-card">
            <img src="<?= e(asset_url('images/thanafus-logo.png')) ?>" alt="">
            <div>
                <strong><?= e(tv_event_payload($event ?? tv_active_event())['title'] ?? 'Kauzariyya Musabaqa') ?></strong>
                <span>Musabaqa 2026</span>
            </div>
        </div>

        <div class="tv-schedule-live-card">
            <div>
                <strong><span></span> Live</strong>
                <small data-current-stage>Normal Stage</small>
            </div>
            <em id="currentScheduleClock">--:--:--</em>
        </div>

        <div class="tv-schedule-title-block">
            <h2>AL THANAFUS</h2>
            <div>Now Performing</div>
        </div>

        <section class="current-schedule-board">
            <div class="current-program-grid">
                <main class="current-main-panel">
                    <div class="current-label" data-current-status>Active</div>
                    <h1 class="current-title" data-current-title>Break Time</h1>

                    <div class="current-performer-row">
                        <div class="current-avatar" id="currentInitial">?</div>
                        <div>
                            <div class="current-performer" data-current-performer>No active performer</div>
                            <div data-current-team>Awaiting next program</div>
                        </div>
                    </div>

                    <div class="current-meta-row">
                        <div class="current-meta-card">
                            <span>Category</span>
                            <strong data-current-category>All Classes</strong>
                            <strong data-current-category-meta hidden>All Classes</strong>
                        </div>
                        <div class="current-meta-card">
                            <span>Entries</span>
                            <strong data-current-entry-count>0</strong>
                        </div>
                        <div class="current-meta-card">
                            <span>Room</span>
                            <strong data-current-room>Main Hall</strong>
                        </div>
                    </div>

                    <div class="current-progress">
                        <div class="current-progress-head">
                            <span>Entry Progress</span>
                            <strong data-current-progress-label>Waiting for entries</strong>
                        </div>
                        <div class="current-progress-track">
                            <div class="current-progress-fill" data-current-progress-fill></div>
                        </div>
                    </div>
                </main>

                <aside class="current-side-panel">
                    <div class="current-side-card">
                        <span>Next Performer</span>
                        <div class="current-next-row">
                            <div class="current-next-avatar" id="nextInitial">?</div>
                            <div>
                                <div class="current-side-value" data-next-performer>Queued automatically</div>
                                <div data-next-team>Team details pending</div>
                            </div>
                        </div>
                    </div>

                    <div class="current-side-card">
                        <span>Judges Panel</span>
                        <div class="current-judges" data-judges>
                            <div class="current-judge-avatar">?</div>
                        </div>
                    </div>

                    <div class="current-side-card">
                        <span>Next Program</span>
                        <div class="current-side-value" data-next-program>Schedule pending</div>
                    </div>
                </aside>
            </div>
        </section>

        <div class="tv-schedule-stats">
            <div><span>Stage</span><strong data-current-stage>Normal</strong></div>
            <div><span>Status</span><strong data-current-status>Live</strong></div>
            <div><span>Category</span><strong data-current-category-meta>All</strong></div>
            <div><span>Entries</span><strong data-current-entry-count>0</strong></div>
            <div><span>Room</span><strong data-current-room>Main</strong></div>
        </div>
    </div>
</div>

<script>
(() => {
    const root = document.querySelector('[data-current-theme-root]');
    const clock = document.getElementById('currentScheduleClock');
    const currentInitial = document.getElementById('currentInitial');
    const nextInitial = document.getElementById('nextInitial');

    function tick() {
        if (!clock) return;
        const now = new Date();
        clock.textContent = now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
    }

    function parseColor(value) {
        if (!value) return null;
        const hex = String(value).trim().match(/^#?([0-9a-f]{6})$/i);
        if (hex) return `#${hex[1]}`;
        const rgb = String(value).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
        if (rgb) {
            return `rgb(${rgb[1]}, ${rgb[2]}, ${rgb[3]})`;
        }
        return null;
    }

    function teamColor(selector) {
        const dot = document.querySelector(selector)?.querySelector('.tv-team-dot');
        return parseColor(dot?.style.background || dot?.style.backgroundColor);
    }

    function firstInitial(selector, fallback) {
        const text = document.querySelector(selector)?.textContent?.trim() || '';
        return text && text !== fallback && text !== '—' ? text.charAt(0).toUpperCase() : '?';
    }

    function syncTheme() {
        if (!root) return;
        const current = teamColor('[data-current-team]');
        const next = teamColor('[data-next-team]');
        if (current) {
            root.style.setProperty('--current-neon', current);
            root.style.setProperty('--schedule-team-3', current);
        }
        if (next) {
            root.style.setProperty('--schedule-team-4', next);
        }
        if (currentInitial) currentInitial.textContent = firstInitial('[data-current-performer]', 'No active performer');
        if (nextInitial) nextInitial.textContent = firstInitial('[data-next-performer]', 'Queued automatically');
        mirror('[data-current-stage]');
        mirror('[data-current-status]');
        mirror('[data-current-category]');
        mirror('[data-current-entry-count]');
        mirror('[data-current-room]');
        mirror('[data-current-category]', '[data-current-category-meta]');
    }

    function mirror(sourceSelector, targetSelector = sourceSelector) {
        const source = document.querySelector(sourceSelector);
        const targets = Array.from(document.querySelectorAll(targetSelector)).filter((target) => target !== source);
        if (!source || targets.length === 0) return;
        targets.forEach((target) => {
            target.textContent = source.textContent;
        });
    }

    tick();
    syncTheme();
    setInterval(tick, 1000);

    const watched = [
        '[data-current-team]',
        '[data-next-team]',
        '[data-current-performer]',
        '[data-next-performer]'
    ].map((selector) => document.querySelector(selector)).filter(Boolean);
    const observer = new MutationObserver(syncTheme);
    watched.forEach((node) => observer.observe(node, { childList: true, subtree: true, characterData: true, attributes: true }));

    window.triggerCurrentProgramAnimations = function() {
        if (typeof gsap === 'undefined') return;
        const board = document.querySelector('.current-schedule-board');
        const items = document.querySelectorAll('.current-main-panel, .current-side-card, .current-meta-card, .tv-schedule-stats div');
        gsap.fromTo(board, { opacity: 0, y: 24, filter: 'blur(10px)' }, { opacity: 1, y: 0, filter: 'blur(0)', duration: .75, ease: 'power3.out' });
        gsap.fromTo(items, { opacity: 0, x: -18 }, { opacity: 1, x: 0, duration: .55, stagger: .055, ease: 'power3.out', delay: .18 });
    };

    setTimeout(() => {
        syncTheme();
        window.triggerCurrentProgramAnimations?.();
    }, 120);
})();
</script>
<?php
if (!defined('TV_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
