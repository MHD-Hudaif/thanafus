<?php
$pageTitle = 'Live Switcher Panel';

define('EVENT_AUTHORITY_SCOPE', 'control-live-display');
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$activeEvent = admin_require_active_event($pdo);
$activeEventId = (int)$activeEvent['id'];

// Load TV functions to fetch settings
require_once __DIR__ . '/../../live-display/includes/functions.php';

$tvSettings = tv_get_settings($activeEventId);
$flash = admin_take_flash();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
/* Pulsing ON AIR Icon */
@keyframes onAirPulse {
    0% { opacity: 0.3; transform: scale(0.95); }
    50% { opacity: 1; transform: scale(1.05); }
    100% { opacity: 0.3; transform: scale(0.95); }
}

.on-air-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.on-air-badge .pulse-dot {
    width: 8px;
    height: 8px;
    background-color: #ef4444;
    border-radius: 50%;
    animation: onAirPulse 1.8s infinite ease-in-out;
    box-shadow: 0 0 8px #ef4444;
}

.remote-dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 32px;
    margin-top: 24px;
}

@media (max-width: 1024px) {
    .remote-dashboard-grid {
        grid-template-columns: 1fr;
        gap: 24px;
    }
}

/* Remote Control Slide Switcher Cards */
.remote-switcher-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
}

.remote-card {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 24px;
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 200px;
    user-select: none;
}

.remote-card:hover {
    background: rgba(255, 255, 255, 0.02);
    border-color: rgba(255, 255, 255, 0.15);
    transform: translateY(-2px);
}

.remote-card.is-on-air {
    background: rgba(16, 185, 129, 0.05);
    border-color: rgba(52, 211, 153, 0.4);
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.remote-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 20px;
    transition: all 0.25s ease;
    margin-bottom: 16px;
}

.remote-card.is-on-air .remote-card-icon {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(52, 211, 153, 0.3);
    color: #10b981;
}

.remote-card-title {
    font-size: 17px;
    font-weight: 800;
    color: #ffffff;
    margin-bottom: 6px;
}

.remote-card-desc {
    font-size: 12.5px;
    color: var(--muted);
    line-height: 1.5;
}

.remote-card-status {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    padding-top: 14px;
}

/* Master Control Ribbon Styles */
.master-control-panel {
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 20px;
    padding: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}

.remote-playback-toggle {
    display: flex;
    gap: 6px;
    background: rgba(0, 0, 0, 0.2);
    padding: 4px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.06);
}

.remote-toggle-btn {
    background: transparent;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    color: rgba(255, 255, 255, 0.5) !important;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.remote-toggle-btn.active.play-btn {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399 !important;
}

.remote-toggle-btn.active.pause-btn {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5 !important;
}

.remote-toggle-btn.active.mode-btn {
    background: rgba(59, 130, 246, 0.15);
    color: #60a5fa !important;
}

/* Live TV Frame styling */
.remote-preview-panel {
    position: sticky;
    top: 96px;
}

.tv-bezel-outer {
    background: #1e293b;
    border: 8px solid #0f172a;
    border-radius: 16px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    position: relative;
    aspect-ratio: 16/9;
}

.tv-bezel-screen {
    width: 100%;
    height: 100%;
    background: #020617;
    position: relative;
    overflow: hidden;
}

.tv-bezel-screen iframe {
    width: 1920px;
    height: 1080px;
    border: none;
    transform-origin: top left;
}

.tv-base-stand {
    width: 40px;
    height: 30px;
    background: #0f172a;
    margin: 0 auto;
}

.tv-base-plate {
    width: 160px;
    height: 8px;
    background: #1e293b;
    border-radius: 4px;
    margin: 0 auto;
    box-shadow: 0 4px 10px rgba(0,0,0,0.3);
}
</style>

<div class="main-content">
    <div class="workspace-hero" style="margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 4px;">
                <span class="eyebrow"><i class="fa-solid fa-tower-broadcast"></i> Broadcaster Console</span>
                <span class="on-air-badge" id="masterOnAirBadge" style="display: none;">
                    <span class="pulse-dot"></span> On Air
                </span>
            </div>
            <h1>Live Switcher Panel</h1>
            <p>Select which page is currently showing on the TV Screen in real-time, or toggle Auto-Loop play mode.</p>
        </div>
        <div class="hero-actions">
            <a href="<?= app_url('/live-display/index.php') ?>" target="_blank" class="btn btn-primary btn-md" data-ajax-ignore>
                <i class="fa-solid fa-square-rss mr-2"></i> Launch TV Screen
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-6">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="remote-dashboard-grid">
        <!-- LEFT COLUMN: CONTROLLER BUTTONS -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Playback Mode Ribbon -->
            <div class="master-control-panel">
                <div>
                    <strong style="color: #fff; font-size: 15px; display: block;">Playback Mode</strong>
                    <span style="font-size: 12.5px; color: var(--muted);" id="currentPlaybackStatusText">Synching live state...</span>
                </div>
                
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <!-- Auto vs Manual Switcher -->
                    <div class="remote-playback-toggle">
                        <button type="button" class="remote-toggle-btn mode-btn" id="btnModeAuto" data-mode="auto">
                            <i class="fa-solid fa-arrows-spin"></i> Auto Loop
                        </button>
                        <button type="button" class="remote-toggle-btn mode-btn" id="btnModeManual" data-mode="manual">
                            <i class="fa-solid fa-hand-pointer"></i> Manual Remote
                        </button>
                    </div>

                    <!-- Play vs Pause -->
                    <div class="remote-playback-toggle">
                        <button type="button" class="remote-toggle-btn play-btn" id="btnPlay">
                            <i class="fa-solid fa-play"></i> Play
                        </button>
                        <button type="button" class="remote-toggle-btn pause-btn" id="btnPause">
                            <i class="fa-solid fa-pause"></i> Pause
                        </button>
                    </div>
                </div>
            </div>

            <!-- Slide Switcher cards -->
            <div class="remote-switcher-grid">
                <!-- Slide 1: Welcome Intro -->
                <div class="remote-card" id="remoteCard_intro" data-slide="intro">
                    <div>
                        <div class="remote-card-icon">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                        </div>
                        <div class="remote-card-title">Welcome Intro</div>
                        <div class="remote-card-desc">Show the introductory slide with grand opening titles, welcome notes, and dynamic background animations.</div>
                    </div>
                    <div class="remote-card-status">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted);" class="card-status-lbl">Standby</span>
                        <i class="fa-solid fa-circle-check" style="font-size: 16px; color: rgba(255,255,255,0.1);" class="card-status-icon"></i>
                    </div>
                </div>

                <!-- Slide 2: Team Leaderboard -->
                <div class="remote-card" id="remoteCard_leaderboard" data-slide="leaderboard">
                    <div>
                        <div class="remote-card-icon">
                            <i class="fa-solid fa-award"></i>
                        </div>
                        <div class="remote-card-title">Team Leaderboard</div>
                        <div class="remote-card-desc">Display live team standings, points, rank positions, and beautiful 3D podium animations of the top teams.</div>
                    </div>
                    <div class="remote-card-status">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted);" class="card-status-lbl">Standby</span>
                        <i class="fa-solid fa-circle-check" style="font-size: 16px; color: rgba(255,255,255,0.1);" class="card-status-icon"></i>
                    </div>
                </div>

                <!-- Slide 3: Schedule Grid -->
                <div class="remote-card" id="remoteCard_schedule" data-slide="schedule">
                    <div>
                        <div class="remote-card-icon">
                            <i class="fa-solid fa-calendar-days"></i>
                        </div>
                        <div class="remote-card-title">Upcoming Schedule</div>
                        <div class="remote-card-desc">Show a grid of upcoming programs, scheduled times, stage locations, and currently active sessions.</div>
                    </div>
                    <div class="remote-card-status">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted);" class="card-status-lbl">Standby</span>
                        <i class="fa-solid fa-circle-check" style="font-size: 16px; color: rgba(255,255,255,0.1);" class="card-status-icon"></i>
                    </div>
                </div>

                <!-- Slide 4: Current Stage -->
                <div class="remote-card" id="remoteCard_current-program" data-slide="current-program">
                    <div>
                        <div class="remote-card-icon">
                            <i class="fa-solid fa-microphone"></i>
                        </div>
                        <div class="remote-card-title">Main Stage</div>
                        <div class="remote-card-desc">Display details of the currently active program, performer name, chest number, team color, and next performer.</div>
                    </div>
                    <div class="remote-card-status">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--muted);" class="card-status-lbl">Standby</span>
                        <i class="fa-solid fa-circle-check" style="font-size: 16px; color: rgba(255,255,255,0.1);" class="card-status-icon"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN: TV SCREEN FRAME PREVIEW -->
        <div class="remote-preview-panel">
            <div class="panel">
                <div class="flex justify-between items-center mb-4" style="border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 15px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-display text-primary"></i> Stage Monitor Preview
                    </h3>
                </div>

                <div class="tv-bezel-outer">
                    <div class="tv-bezel-screen">
                        <iframe id="tvFrame" src="<?= app_url('/live-display/index.php') ?>" frameborder="0"></iframe>
                    </div>
                </div>
                <div class="tv-base-stand"></div>
                <div class="tv-base-plate"></div>

                <div style="display: flex; justify-content: center; gap: 8px; margin-top: 16px;">
                    <button type="button" class="btn btn-secondary btn-sm" id="btnResetLoopFrame">
                        <i class="fa-solid fa-arrows-spin mr-1"></i> Reset Loop
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnRefreshFrame">
                        <i class="fa-solid fa-arrows-rotate mr-1"></i> Refresh Preview
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const API_URL = <?= json_encode(app_url('/live-display/api/settings.php'), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF = <?= json_encode(generate_csrf_token()) ?>;

    const iframe = document.getElementById('tvFrame');
    const screen = document.querySelector('.tv-bezel-screen');

    // Scale TV preview monitor iframe dynamically
    if (screen && iframe) {
        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const scale = entry.contentRect.width / 1920;
                iframe.style.transform = `scale(${scale})`;
            }
        });
        resizeObserver.observe(screen);
    }

    // Refresh control buttons
    document.getElementById('btnResetLoopFrame')?.addEventListener('click', () => {
        if (iframe) iframe.src = '<?= app_url('/live-display/index.php') ?>';
    });
    document.getElementById('btnRefreshFrame')?.addEventListener('click', () => {
        if (iframe) iframe.src = iframe.src;
    });

    // Helper POST API function
    async function postSettings(action, data = {}) {
        const formData = new FormData();
        formData.append('csrf_token', CSRF);
        formData.append('action', action);
        for (const [key, val] of Object.entries(data)) {
            formData.append(key, val);
        }
        try {
            const resp = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const res = await resp.json();
            if (res.success && res.data?.settings) {
                renderState(res.data.settings);
            } else {
                console.error('[Remote API] Action failed:', res.message);
            }
        } catch (e) {
            console.error('[Remote API] Connection failed:', e);
        }
    }

    // Bind playback triggers
    document.getElementById('btnPlay')?.addEventListener('click', () => postSettings('play'));
    document.getElementById('btnPause')?.addEventListener('click', () => postSettings('pause'));
    document.getElementById('btnModeAuto')?.addEventListener('click', () => postSettings('mode', { mode: 'auto' }));
    document.getElementById('btnModeManual')?.addEventListener('click', () => postSettings('mode', { mode: 'manual' }));

    // Bind slide selector cards
    document.querySelectorAll('.remote-card').forEach(card => {
        card.addEventListener('click', function() {
            const slide = this.dataset.slide;
            postSettings('slide', { slide });
            
            // Instantly push change to iframe preview in real-time
            if (iframe) {
                iframe.src = `<?= app_url('/live-display/index.php') ?>?slide=${slide}`;
            }
        });
    });

    // Sync UI with state payload
    function renderState(settings) {
        if (!settings) return;

        const isPlaying = !!settings.is_playing;
        const mode = settings.mode || 'auto';
        const activeSlide = settings.active_slide || 'intro';

        // Play/Pause button highlights
        document.getElementById('btnPlay')?.classList.toggle('active', isPlaying);
        document.getElementById('btnPause')?.classList.toggle('active', !isPlaying);

        // Auto/Manual mode highlights
        document.getElementById('btnModeAuto')?.classList.toggle('active', mode === 'auto');
        document.getElementById('btnModeManual')?.classList.toggle('active', mode === 'manual');

        // Playback Ribbon Subtext Update
        const statusText = document.getElementById('currentPlaybackStatusText');
        const onAirBadge = document.getElementById('masterOnAirBadge');
        
        if (statusText) {
            if (mode === 'auto') {
                statusText.innerHTML = `🔁 Loop active (playing: ${isPlaying ? 'Yes' : 'Paused'})`;
                if (onAirBadge) onAirBadge.style.display = 'none';
            } else {
                statusText.innerHTML = `🔴 Remote active (active slide: <strong>${activeSlide.toUpperCase()}</strong>)`;
                if (onAirBadge) onAirBadge.style.display = 'inline-flex';
            }
        }

        // Slide card indicator syncs
        document.querySelectorAll('.remote-card').forEach(card => {
            const cardSlide = card.dataset.slide;
            const statusLbl = card.querySelector('.card-status-lbl');
            const statusIcon = card.querySelector('.card-status-icon');
            
            const isCurrent = (mode === 'manual' && cardSlide === activeSlide);
            card.classList.toggle('is-on-air', isCurrent);

            if (statusLbl) {
                statusLbl.textContent = isCurrent ? 'On Air' : (mode === 'auto' ? 'In Loop' : 'Standby');
                statusLbl.style.color = isCurrent ? '#34d399' : '';
            }
            if (statusIcon) {
                statusIcon.style.color = isCurrent ? '#10b981' : '';
                statusIcon.className = isCurrent ? 'fa-solid fa-circle-play' : 'fa-solid fa-circle-check';
            }
        });
    }

    // Set up continuous active state sync poll (every 2.5 seconds)
    async function syncState() {
        try {
            const resp = await fetch(API_URL, { cache: 'no-store' });
            const res = await resp.json();
            if (res.success && res.data?.settings) {
                renderState(res.data.settings);
            }
        } catch (e) {
            console.warn('[Remote Sync] Polling error:', e);
        }
    }

    // Run first sync immediately and schedule intervals
    syncState();
    const intervalId = setInterval(syncState, 2500);

    // Cancel state poll on AJAX content swap page transition
    document.addEventListener('admin:content-swapped', () => {
        clearInterval(intervalId);
    }, { once: true });

})();
</script>

<?php admin_close_page(); ?>
