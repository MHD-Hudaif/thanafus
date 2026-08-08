<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/admin-helpers.php';

$pdo = $GLOBALS['musabaqa_pdo'] ?? null;
$isAdminUser = false;
if (function_exists('is_admin')) {
    $isAdminUser = is_admin();
} elseif (!empty($_SESSION['user_id'])) {
    $userRole = $_SESSION['user']['role'] ?? '';
    $userIsAdmin = $_SESSION['user']['is_admin'] ?? false;
    $isAdminUser = ($userIsAdmin || $userRole === 'admin');
}

$settings = $pdo ? admin_get_settings($pdo) : [];
$maxJudges = max(2, min(10, (int)($settings['max_judges_count'] ?? 2)));
?>

<!-- =========================================================
     CTRL + K COMMAND PALETTE MODAL OVERLAY
========================================================= -->
<style>
    .cmd-palette-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.85);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        z-index: 999999;
        display: none;
        align-items: flex-start;
        justify-content: center;
        padding-top: 100px;
        padding-left: 16px;
        padding-right: 16px;
    }
    .cmd-palette-card {
        width: 100%;
        max-width: 640px;
        background: rgba(6, 30, 18, 0.95);
        border: 1px solid rgba(16, 185, 129, 0.35);
        border-radius: 20px;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9), 0 0 40px rgba(16, 185, 129, 0.2);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        animation: cmdPaletteFadeIn 0.2s ease-out;
    }
    @keyframes cmdPaletteFadeIn {
        from { opacity: 0; transform: scale(0.96) translateY(-10px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .cmd-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid rgba(16, 185, 129, 0.2);
        background: rgba(0, 0, 0, 0.4);
    }
    .cmd-input {
        width: 100%;
        background: transparent;
        border: none;
        color: #fff;
        font-size: 16px;
        font-weight: 600;
        outline: none;
        font-family: inherit;
    }
    .cmd-input::placeholder {
        color: rgba(255, 255, 255, 0.4);
    }
    .cmd-kbd-badge {
        font-size: 10px;
        font-weight: 800;
        color: rgba(52, 211, 153, 0.8);
        background: rgba(0, 0, 0, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.3);
        padding: 3px 8px;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }
    .cmd-results-list {
        max-height: 380px;
        overflow-y: auto;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .cmd-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        border-radius: 12px;
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid transparent;
        color: #fff;
        cursor: pointer;
        transition: all 0.15s ease;
        user-select: none;
    }
    .cmd-item:hover, .cmd-item.active {
        background: rgba(16, 185, 129, 0.2);
        border-color: #34d399;
        transform: translateX(3px);
    }
    .cmd-item-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .cmd-item-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #34d399;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
    }
    .cmd-item-title {
        font-size: 14.5px;
        font-weight: 700;
        color: #fff;
    }
    .cmd-item-subtitle {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.5);
        margin-top: 1px;
    }
    .cmd-footer {
        padding: 10px 20px;
        background: rgba(0, 0, 0, 0.6);
        border-top: 1px solid rgba(16, 185, 129, 0.15);
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 11.5px;
        color: rgba(255, 255, 255, 0.5);
    }
    .cmd-footer-keys {
        display: flex;
        gap: 12px;
    }
    .cmd-footer-keys span {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .cmd-footer-keys kbd {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 1px 5px;
        border-radius: 4px;
        font-size: 10px;
        color: #34d399;
    }
    .floating-cmd-badge {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: rgba(6, 30, 18, 0.85);
        border: 1px solid rgba(16, 185, 129, 0.4);
        color: #34d399;
        padding: 8px 14px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 10px 25px rgba(0,0,0,0.6);
        backdrop-filter: blur(10px);
        z-index: 99990;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .floating-cmd-badge:hover {
        background: rgba(16, 185, 129, 0.25);
        border-color: #34d399;
        color: #fff;
        transform: translateY(-2px);
    }
    
    /* Judge Passkey PIN Input Modal */
    .judge-pin-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.88);
        backdrop-filter: blur(20px);
        z-index: 9999999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .judge-pin-card {
        width: 100%;
        max-width: 420px;
        background: rgba(5, 25, 14, 0.96);
        border: 2px solid rgba(16, 185, 129, 0.4);
        border-radius: 24px;
        padding: 32px 28px;
        text-align: center;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.95), 0 0 50px rgba(16, 185, 129, 0.2);
    }
    .judge-pin-input {
        width: 100%;
        max-width: 280px;
        text-align: center;
        font-size: 28px;
        font-weight: 900;
        letter-spacing: 8px;
        background: rgba(0, 0, 0, 0.7);
        border: 2px solid rgba(16, 185, 129, 0.35);
        color: #34d399;
        padding: 12px 16px;
        border-radius: 16px;
        outline: none;
        margin: 20px auto;
        display: block;
        transition: all 0.25s ease;
    }
    .judge-pin-input:focus {
        border-color: #34d399;
        box-shadow: 0 0 25px rgba(16, 185, 129, 0.4);
    }
</style>

<!-- Floating Corner Trigger Badge -->
<div class="floating-cmd-badge" onclick="openCmdPalette()" title="Press Ctrl + K to open secret navigation">
    <i class="fa-solid fa-compass"></i>
    <span><kbd style="background:rgba(0,0,0,0.5); padding:1px 5px; border-radius:4px;">Ctrl</kbd> + <kbd style="background:rgba(0,0,0,0.5); padding:1px 5px; border-radius:4px;">K</kbd> Quick Nav</span>
</div>

<!-- Command Palette Modal -->
<div id="commandPaletteModal" class="cmd-palette-overlay" onclick="closeCmdPaletteOnBackdrop(event)">
    <div class="cmd-palette-card">
        
        <div class="cmd-header">
            <i class="fa-solid fa-magnifying-glass" style="color: #34d399; font-size: 18px;"></i>
            <input type="text" id="cmdSearchInput" class="cmd-input" placeholder="Type a command or portal (e.g. Judge 1, Judge 2, Emcee, Admin)..." onkeyup="filterCmdItems(this.value)">
            <span class="cmd-kbd-badge">ESC to close</span>
        </div>

        <div id="cmdResultsList" class="cmd-results-list">
            
            <!-- JUDGE PORTALS -->
            <?php for ($j = 1; $j <= min(4, $maxJudges); $j++): ?>
                <div class="cmd-item" data-title="Judge <?= $j ?> Marking Portal Passkey" data-action="judge_passkey" data-judge="<?= $j ?>" onclick="promptJudgePasskey(<?= $j ?>)">
                    <div class="cmd-item-left">
                        <div class="cmd-item-icon">
                            <i class="fa-solid fa-gavel"></i>
                        </div>
                        <div>
                            <div class="cmd-item-title">Judge <?= $j ?> Marking Portal</div>
                            <div class="cmd-item-subtitle">Access Judge #<?= $j ?> score sheet (Passkey Required)</div>
                        </div>
                    </div>
                    <span class="cmd-kbd-badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">Passkey Auth</span>
                </div>
            <?php endfor; ?>

            <!-- EMCEE STAGE DECK -->
            <div class="cmd-item" data-title="Emcee Stage Control Deck Master" data-url="<?= app_url('/emcee/index.php') ?>" onclick="navigateCmdUrl(this)">
                <div class="cmd-item-left">
                    <div class="cmd-item-icon">
                        <i class="fa-solid fa-tower-broadcast"></i>
                    </div>
                    <div>
                        <div class="cmd-item-title">Emcee Stage Deck</div>
                        <div class="cmd-item-subtitle">Live Stage Controller &amp; Contestant Queue</div>
                    </div>
                </div>
                <span class="cmd-kbd-badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">Master Deck</span>
            </div>

            <!-- ADMIN PORTAL -->
            <div class="cmd-item" data-title="Admin Management Console Portal" data-url="<?= $isAdminUser ? app_url('/admin/index.php') : app_url('/auth/login') ?>" onclick="navigateCmdUrl(this)">
                <div class="cmd-item-left">
                    <div class="cmd-item-icon" style="<?= $isAdminUser ? 'color:#34d399;' : 'color:#fbbf24;' ?>">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <div class="cmd-item-title">Admin Console</div>
                        <div class="cmd-item-subtitle"><?= $isAdminUser ? 'Direct Access (Admin Logged In)' : 'Admin Authentication Required' ?></div>
                    </div>
                </div>
                <span class="cmd-kbd-badge" style="<?= $isAdminUser ? 'background: rgba(16,185,129,0.2); color:#34d399;' : 'background: rgba(245,158,11,0.2); color:#fbbf24;' ?>">
                    <?= $isAdminUser ? 'Admin Active' : 'Login Needed' ?>
                </span>
            </div>

            <!-- LIVE TV DISPLAY FEED -->
            <div class="cmd-item" data-title="Live Display TV Dashboard Stream" data-url="<?= app_url('/live-display/dashboard.php') ?>" onclick="navigateCmdUrl(this)">
                <div class="cmd-item-left">
                    <div class="cmd-item-icon">
                        <i class="fa-solid fa-tv"></i>
                    </div>
                    <div>
                        <div class="cmd-item-title">Live TV Display Feed</div>
                        <div class="cmd-item-subtitle">Stage Screen &amp; Leaderboard Dashboard</div>
                    </div>
                </div>
                <span class="cmd-kbd-badge" style="background: rgba(255,255,255,0.1); color: #aaa;">Live Feed</span>
            </div>

            <!-- SCOREBOARD STANDINGS -->
            <div class="cmd-item" data-title="Musabaqa Scoreboard Leaderboard Standings" data-url="<?= app_url('/scoreboard.php') ?>" onclick="navigateCmdUrl(this)">
                <div class="cmd-item-left">
                    <div class="cmd-item-icon">
                        <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div>
                        <div class="cmd-item-title">Team Leaderboard Standings</div>
                        <div class="cmd-item-subtitle">Real-time Team Points &amp; Rankings</div>
                    </div>
                </div>
                <span class="cmd-kbd-badge" style="background: rgba(255,255,255,0.1); color: #aaa;">Public Standings</span>
            </div>

            <!-- EVENT SCHEDULE -->
            <div class="cmd-item" data-title="Event Program Schedule Timeline" data-url="<?= app_url('/schedule.php') ?>" onclick="navigateCmdUrl(this)">
                <div class="cmd-item-left">
                    <div class="cmd-item-icon">
                        <i class="fa-regular fa-calendar-days"></i>
                    </div>
                    <div>
                        <div class="cmd-item-title">Program Schedule Timeline</div>
                        <div class="cmd-item-subtitle">Full Program List &amp; Stage Sections</div>
                    </div>
                </div>
                <span class="cmd-kbd-badge" style="background: rgba(255,255,255,0.1); color: #aaa;">Schedule</span>
            </div>

        </div>

        <div class="cmd-footer">
            <div class="cmd-footer-keys">
                <span><kbd>↑</kbd> <kbd>↓</kbd> Navigate</span>
                <span><kbd>↵</kbd> Select</span>
                <span><kbd>ESC</kbd> Close</span>
            </div>
            <div>Musabaqa Secret Console</div>
        </div>

    </div>
</div>

<!-- Judge Passkey PIN Verification Modal -->
<div id="judgePinModal" class="judge-pin-overlay" onclick="closeJudgePinModalOnBackdrop(event)">
    <div class="judge-pin-card">
        <div style="font-size: 32px; color: #34d399; margin-bottom: 8px;">
            <i class="fa-solid fa-gavel"></i>
        </div>
        <h3 id="judgePinTitle" style="margin: 0; font-size: 22px; font-weight: 800; color: #fff;">
            Enter Judge Passkey PIN
        </h3>
        <div id="judgePinSubtitle" style="font-size: 13px; color: rgba(255,255,255,0.6); margin-top: 4px;">
            Input passkey PIN to authenticate
        </div>

        <form method="POST" action="<?= app_url('/judges/index.php') ?>" id="judgePinForm">
            <input type="hidden" name="action" value="verify_judge_passkey">
            <input type="hidden" name="judge_no" id="judgeNoInput" value="1">
            <input type="password" name="passkey" id="judgePasskeyInput" class="judge-pin-input" placeholder="••••" maxlength="10" required autocomplete="off">
            
            <div style="display: flex; gap: 12px; justify-content: center; margin-top: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeJudgePinModal()" style="background: rgba(0,0,0,0.6); border: 1px solid rgba(16,185,129,0.3); color: #fff; border-radius: 12px; padding: 10px 20px;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #10b981, #047857); border: 1px solid #34d399; font-weight: 800; border-radius: 12px; padding: 10px 24px; color: #fff;">
                    <i class="fa-solid fa-key mr-1"></i> Verify &amp; Enter
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentActiveIdx = -1;

function openCmdPalette() {
    const modal = document.getElementById('commandPaletteModal');
    if (!modal) return;
    modal.style.display = 'flex';
    const input = document.getElementById('cmdSearchInput');
    input.value = '';
    filterCmdItems('');
    input.focus();
}

function closeCmdPalette() {
    const modal = document.getElementById('commandPaletteModal');
    if (modal) modal.style.display = 'none';
}

function closeCmdPaletteOnBackdrop(e) {
    if (e.target.id === 'commandPaletteModal') {
        closeCmdPalette();
    }
}

function filterCmdItems(query) {
    const items = document.querySelectorAll('.cmd-item');
    const q = query.toLowerCase().trim();
    let visibleItems = [];

    items.forEach(item => {
        const title = (item.dataset.title || '').toLowerCase();
        if (!q || title.includes(q)) {
            item.style.display = 'flex';
            visibleItems.push(item);
        } else {
            item.style.display = 'none';
            item.classList.remove('active');
        }
    });

    currentActiveIdx = visibleItems.length > 0 ? 0 : -1;
    updateActiveCmdItem(visibleItems);
}

function updateActiveCmdItem(visibleItems) {
    const allItems = document.querySelectorAll('.cmd-item');
    allItems.forEach(i => i.classList.remove('active'));

    if (visibleItems && visibleItems[currentActiveIdx]) {
        visibleItems[currentActiveIdx].classList.add('active');
        visibleItems[currentActiveIdx].scrollIntoView({ block: 'nearest' });
    }
}

function navigateCmdUrl(element) {
    const url = element.dataset.url;
    if (url) {
        window.location.href = url;
    }
}

function promptJudgePasskey(judgeNo) {
    closeCmdPalette();
    document.getElementById('judgeNoInput').value = judgeNo;
    document.getElementById('judgePinTitle').innerText = `Enter Judge #${judgeNo} Passkey PIN`;
    document.getElementById('judgePinSubtitle').innerText = `Authenticate Judge #${judgeNo} session`;
    
    const pinModal = document.getElementById('judgePinModal');
    const pinInput = document.getElementById('judgePasskeyInput');
    pinModal.style.display = 'flex';
    pinInput.value = '';
    pinInput.focus();
}

function closeJudgePinModal() {
    document.getElementById('judgePinModal').style.display = 'none';
}

function closeJudgePinModalOnBackdrop(e) {
    if (e.target.id === 'judgePinModal') {
        closeJudgePinModal();
    }
}

// Global Secret Ctrl + K Keybind Listener
document.addEventListener('keydown', (e) => {
    // Secret Trigger: Ctrl + K or Cmd + K
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        openCmdPalette();
        return;
    }

    const cmdModal = document.getElementById('commandPaletteModal');
    const pinModal = document.getElementById('judgePinModal');

    if (e.key === 'Escape') {
        if (pinModal && pinModal.style.display === 'flex') {
            closeJudgePinModal();
        } else if (cmdModal && cmdModal.style.display === 'flex') {
            closeCmdPalette();
        }
        return;
    }

    if (cmdModal && cmdModal.style.display === 'flex') {
        const visibleItems = Array.from(document.querySelectorAll('.cmd-item')).filter(i => i.style.display !== 'none');
        if (visibleItems.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentActiveIdx = (currentActiveIdx + 1) % visibleItems.length;
            updateActiveCmdItem(visibleItems);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentActiveIdx = (currentActiveIdx - 1 + visibleItems.length) % visibleItems.length;
            updateActiveCmdItem(visibleItems);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (visibleItems[currentActiveIdx]) {
                visibleItems[currentActiveIdx].click();
            }
        }
    }
});
</script>
