<?php
declare(strict_types=1);

if (!defined('LIVE_DISPLAY_STAGE')) {
    require_once dirname(__DIR__) . '/router.php';
    $event = tv_active_event();
    $settings = tv_get_settings((int)($event['id'] ?? 0));
    $tvBodyClass = trim(($tvBodyClass ?? '') . ' tv-current-programs-theme');
    $settings['mode'] = 'manual';
    $settings['active_slide'] = 'current-program';
    $settings['slides']['current-program']['enabled'] = true;
    $settings['slides']['current-program']['duration'] = 999999;
    require dirname(__DIR__) . '/includes/header.php';
    echo '<section class="tv-slide tv-slide--active" id="slide-current-program" data-slide="current-program" style="opacity: 1; visibility: visible; transform: scale(1);">';
}

// Fetch current leader info for dynamic background aura
$leaderboard = tv_leaderboard((int)($event['id'] ?? 0));
$firstTeam = !empty($leaderboard) ? $leaderboard[0] : null;
$firstTeamColor = !empty($firstTeam['team_color']) ? live_display_color($firstTeam['team_color']) : '#10b981';
$firstTeamName = !empty($firstTeam['team_name']) ? $firstTeam['team_name'] : 'Leader';
?>
<?php if (!defined('LIVE_DISPLAY_STAGE')): ?>
<script>
document.body.classList.add('tv-current-programs-theme');
document.querySelector('.tv-topbar')?.setAttribute('hidden', '');
</script>
<?php endif; ?>

<style>
body.tv-current-programs-theme .tv-topbar,
body:has(#slide-current-program.tv-slide--active) .tv-topbar {
    display: none !important;
}

#slide-current-program {
    padding: 0 !important;
    overflow: hidden;
    background: #030712;
    font-family: 'Inter', 'Cairo', system-ui, -apple-system, sans-serif;
    color: #f8fafc;
    width: 100vw;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.programs-wrapper {
    --first-team-color: <?= e($firstTeamColor) ?>;
    --current-neon: #10b981;
    --panel-glow: rgba(16, 185, 129, 0.12);
    width: 100%;
    max-width: 1680px;
    height: 100vh;
    padding: 50px 60px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    position: relative;
    z-index: 1;
}

/* Reliable Pure CSS Ambient Mesh Background */
.ambient-mesh-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background: 
        radial-gradient(circle at 18% 25%, color-mix(in srgb, var(--first-team-color) 22%, transparent) 0%, transparent 55%),
        radial-gradient(circle at 82% 75%, color-mix(in srgb, var(--first-team-color) 14%, transparent) 0%, transparent 48%),
        radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.05) 0%, transparent 70%),
        linear-gradient(180deg, #030712 0%, #02040a 100%);
    animation: ambient-pulse 12s ease-in-out infinite alternate;
}

@keyframes ambient-pulse {
    0% { opacity: 0.8; transform: scale(1); }
    100% { opacity: 1; transform: scale(1.04); }
}

/* Main Grid Layout: Perfectly Balanced 2 Columns */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 40px;
    width: 100%;
    height: 82vh;
    align-items: stretch;
}

/* Modern Premium Glass Card Base */
.glass-panel {
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(30px);
    -webkit-backdrop-filter: blur(30px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 28px;
    padding: 55px;
    display: flex;
    flex-direction: column;
    box-shadow: 
        0 25px 50px -12px rgba(0, 0, 0, 0.7),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
    position: relative;
    overflow: hidden;
    box-sizing: border-box;
}

/* Primary Card (Now Performing) */
.now-performing-card {
    border-left: 6px solid var(--current-neon);
    box-shadow: 
        0 25px 50px -12px rgba(0, 0, 0, 0.7),
        0 0 40px var(--panel-glow);
}

.panel-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 25px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #34d399;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #34d399;
    box-shadow: 0 0 10px #34d399;
    animation: live-pulse-dot 1.8s infinite;
}

@keyframes live-pulse-dot {
    0% { transform: scale(0.9); opacity: 0.7; }
    50% { transform: scale(1.3); opacity: 1; }
    100% { transform: scale(0.9); opacity: 0.7; }
}

.chest-badge {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.15);
    padding: 8px 20px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: #f1f5f9;
}

.chest-badge strong {
    color: var(--current-neon);
    font-size: 20px;
    margin-left: 6px;
}

/* Program Title */
.program-title-display {
    font-size: 52px;
    font-weight: 900;
    line-height: 1.15;
    margin: 10px 0 45px 0;
    color: #ffffff;
    letter-spacing: -0.01em;
    text-transform: uppercase;
}

/* Hero Performer Info */
.performer-hero-info {
    display: flex;
    align-items: center;
    gap: 35px;
    margin-bottom: auto;
}

.performer-avatar {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, rgba(255,255,255,0.02) 100%);
    border: 3px solid var(--current-neon);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 60px;
    font-weight: 900;
    color: #ffffff;
    box-shadow: 0 0 35px var(--panel-glow);
    flex-shrink: 0;
}

.performer-details {
    flex: 1;
    min-width: 0;
}

.performer-name {
    font-size: 68px;
    font-weight: 900;
    margin: 0;
    color: #ffffff;
    letter-spacing: -0.02em;
    line-height: 1.1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.team-pill {
    margin-top: 14px;
    font-size: 22px;
    font-weight: 700;
    color: #cbd5e1;
    display: flex;
    align-items: center;
    gap: 12px;
}

.tv-team-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 12px currentColor;
}

/* Bottom Progress Tracker */
.progress-container {
    margin-top: 35px;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

.progress-header span {
    font-size: 13px;
    font-weight: 800;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}

.progress-header strong {
    font-size: 16px;
    font-weight: 800;
    color: var(--current-neon);
}

.progress-bar-outer {
    height: 10px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-inner {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--current-neon) 0%, #a7f3d0 100%);
    border-radius: 10px;
    box-shadow: 0 0 16px var(--current-neon);
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Right Panel: Up Next */
.side-panel {
    border-left: 1px solid rgba(255, 255, 255, 0.1);
    justify-content: space-between;
}

.up-next-status-badge {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #cbd5e1;
}

.up-next-content {
    display: flex;
    align-items: center;
    gap: 30px;
    margin: auto 0;
}

.up-next-avatar {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.03);
    border: 2px solid rgba(255, 255, 255, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    font-weight: 900;
    color: #cbd5e1;
    flex-shrink: 0;
}

.up-next-details {
    flex: 1;
    min-width: 0;
}

.up-next-name {
    font-size: 48px;
    font-weight: 900;
    color: #ffffff;
    margin: 0;
    line-height: 1.15;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.up-next-meta {
    font-size: 20px;
    font-weight: 700;
    color: #94a3b8;
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Bottom Watermark */
.aura-watermark {
    position: absolute;
    bottom: 18px;
    right: 60px;
    font-size: 13px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.25);
    text-transform: uppercase;
    letter-spacing: 0.15em;
    pointer-events: none;
    z-index: 10;
}
</style>

<div class="ambient-mesh-bg"></div>

<div class="programs-wrapper" data-current-theme-root>
    <!-- Main Grid Workspace: Perfectly Balanced 2 Columns -->
    <div class="dashboard-grid">
        <!-- Main Panel (Active Performer) -->
        <main class="glass-panel now-performing-card">
            <div class="panel-header-row">
                <div class="status-badge">
                    <span class="status-dot"></span>
                    <span data-current-status>NOW PERFORMING</span>
                </div>
                <div class="chest-badge">
                    CHEST NO: <strong data-current-chest>—</strong>
                </div>
            </div>
            
            <h1 class="program-title-display" data-current-title>Loading active program...</h1>

            <div class="performer-hero-info">
                <div class="performer-avatar" id="currentInitial">?</div>
                <div class="performer-details">
                    <h2 class="performer-name" data-current-performer>Awaiting performer</h2>
                    <div class="team-pill" data-current-team>
                        —
                    </div>
                </div>
            </div>

            <!-- Progress Tracker -->
            <div class="progress-container">
                <div class="progress-header">
                    <span>Performance Progress</span>
                    <strong data-current-progress-label>—</strong>
                </div>
                <div class="progress-bar-outer">
                    <div class="progress-bar-inner" data-current-progress-fill></div>
                </div>
            </div>
        </main>

        <!-- Sidebar Panel: Coming Up Next -->
        <aside class="glass-panel side-panel">
            <div class="panel-header-row">
                <div class="status-badge up-next-status-badge">
                    UP NEXT
                </div>
                <div class="chest-badge">
                    CHEST NO: <strong data-next-chest>—</strong>
                </div>
            </div>

            <div class="up-next-content">
                <div class="up-next-avatar" id="nextInitial">?</div>
                <div class="up-next-details">
                    <h3 class="up-next-name" data-next-performer>—</h3>
                    <div class="up-next-meta" data-next-team>
                        —
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Hidden elements to preserve selector tracking in the television slider app -->
    <div hidden>
        <span data-current-stage>Stage</span>
        <span data-current-category>Category</span>
        <span data-current-room>Venue</span>
        <span data-current-entry-count>0</span>
        <span data-judges>No judges</span>
        <span data-next-program>No program</span>
    </div>

    <!-- Dynamic Aura Indicator Watermark -->
    <div class="aura-watermark">
        Leading Team: <?= e($firstTeamName) ?>
    </div>
</div>

<script>
(() => {
    const root = document.querySelector('[data-current-theme-root]');
    const currentInitial = document.getElementById('currentInitial');
    const nextInitial = document.getElementById('nextInitial');

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
        if (current) {
            root.style.setProperty('--current-neon', current);
            root.style.setProperty('--panel-glow', `rgba(${parseInt(current.slice(1,3), 16)}, ${parseInt(current.slice(3,5), 16)}, ${parseInt(current.slice(5,7), 16)}, 0.15)`);
        } else {
            root.style.setProperty('--current-neon', '#10b981');
            root.style.setProperty('--panel-glow', 'rgba(16, 185, 129, 0.12)');
        }
        if (currentInitial) {
            currentInitial.textContent = firstInitial('[data-current-performer]', 'Awaiting performer');
        }
        if (nextInitial) {
            nextInitial.textContent = firstInitial('[data-next-performer]', '—');
        }
    }

    syncTheme();

    const watched = [
        '[data-current-team]',
        '[data-next-team]',
        '[data-current-performer]',
        '[data-next-performer]'
    ].map((selector) => document.querySelector(selector)).filter(Boolean);
    const observer = new MutationObserver(syncTheme);
    watched.forEach((node) => observer.observe(node, { childList: true, subtree: true, characterData: true, attributes: true }));

    // High End Fluid GSAP Animation Sequence
    window.triggerCurrentProgramAnimations = function() {
        if (typeof gsap === 'undefined') return;
        const mainCard = document.querySelector('.now-performing-card');
        const sideCard = document.querySelector('.side-panel');
        
        const mainItems = mainCard.querySelectorAll('.panel-header-row, .program-title-display, .performer-hero-info, .progress-container');
        const sideItems = sideCard.querySelectorAll('.panel-header-row, .up-next-content');

        gsap.killTweensOf([mainCard, sideCard, ...mainItems, ...sideItems]);

        // Main Entrance Transitions using power4.out curves
        gsap.fromTo(mainCard, 
            { opacity: 0, scale: 0.97, y: 25 }, 
            { opacity: 1, scale: 1, y: 0, duration: 0.9, ease: 'power4.out' }
        );
        gsap.fromTo(sideCard, 
            { opacity: 0, scale: 0.97, x: 30 }, 
            { opacity: 1, scale: 1, x: 0, duration: 1.0, ease: 'power4.out', delay: 0.12 }
        );

        // Fluid Item Slide-ins
        gsap.fromTo(mainItems,
            { opacity: 0, y: 15 },
            { opacity: 1, y: 0, duration: 0.75, stagger: 0.07, ease: 'power3.out', delay: 0.25 }
        );
        gsap.fromTo(sideItems,
            { opacity: 0, y: 15 },
            { opacity: 1, y: 0, duration: 0.75, stagger: 0.07, ease: 'power3.out', delay: 0.35 }
        );
    };

    setTimeout(() => {
        syncTheme();
        window.triggerCurrentProgramAnimations?.();
    }, 150);
})();
</script>

<?php
if (!defined('LIVE_DISPLAY_STAGE')) {
    echo '</section>';
    require dirname(__DIR__) . '/includes/footer.php';
}
?>
