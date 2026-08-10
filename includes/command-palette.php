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
?>

<!-- =========================================================
     ADMIN CORE OPERATIONS WORKSPACE OVERLAY (CTRL + K)
========================================================= -->
<style>
    .cmd-palette-overlay {
        position: fixed;
        inset: 0;
        background: rgba(4, 12, 8, 0.88);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        z-index: 999999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }
    .cmd-palette-card {
        width: 100%;
        max-width: 900px;
        background: rgba(6, 26, 17, 0.96);
        border: 1.5px solid rgba(16, 185, 129, 0.35);
        border-radius: 24px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.95), 0 0 50px rgba(16, 185, 129, 0.18);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        animation: opsWorkspaceFadeIn 0.22s ease-out;
    }
    @keyframes opsWorkspaceFadeIn {
        from { opacity: 0; transform: scale(0.96) translateY(-12px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .ops-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 28px;
        border-bottom: 1px solid rgba(16, 185, 129, 0.2);
        background: rgba(0, 0, 0, 0.45);
    }
    .ops-header-title {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .ops-header-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #10b981, #059669);
        color: #fff;
        display: grid;
        place-items: center;
        font-size: 20px;
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
    }
    .ops-header h2 {
        margin: 0;
        font-size: 20px;
        font-weight: 900;
        color: #fff;
        letter-spacing: -0.01em;
    }
    .ops-header p {
        margin: 2px 0 0;
        font-size: 12.5px;
        color: rgba(255, 255, 255, 0.6);
        font-weight: 500;
    }
    .ops-close-btn {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #e2e8f0;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .ops-close-btn:hover {
        background: rgba(239, 68, 68, 0.2);
        border-color: #ef4444;
        color: #fca5a5;
    }
    .ops-body {
        padding: 24px 28px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        max-height: 70vh;
        overflow-y: auto;
    }
    @media (max-width: 768px) {
        .ops-body {
            grid-template-columns: 1fr;
        }
    }
    .ops-section {
        background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 18px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .ops-section-title {
        font-size: 13px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #34d399;
        display: flex;
        align-items: center;
        gap: 8px;
        padding-bottom: 8px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .ops-link-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
        color: #fff;
        text-decoration: none;
        transition: all 0.18s ease;
    }
    .ops-link-card:hover {
        background: rgba(16, 185, 129, 0.18);
        border-color: rgba(52, 211, 153, 0.5);
        transform: translateX(4px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
    }
    .ops-link-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: rgba(16, 185, 129, 0.15);
        color: #34d399;
        display: grid;
        place-items: center;
        font-size: 16px;
        flex-shrink: 0;
    }
    .ops-link-info {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .ops-link-title {
        font-size: 14px;
        font-weight: 800;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ops-link-sub {
        font-size: 11.5px;
        color: rgba(255, 255, 255, 0.5);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-top: 1px;
    }
    .ops-footer {
        padding: 12px 28px;
        background: rgba(0, 0, 0, 0.6);
        border-top: 1px solid rgba(16, 185, 129, 0.15);
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 12px;
        color: rgba(255, 255, 255, 0.5);
    }
    .ops-kbd-pill {
        background: rgba(0, 0, 0, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #34d399;
        padding: 2px 7px;
        border-radius: 5px;
        font-weight: 800;
        font-size: 10px;
    }
    .floating-cmd-badge {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: rgba(6, 26, 17, 0.9);
        border: 1.5px solid rgba(16, 185, 129, 0.4);
        color: #34d399;
        padding: 9px 16px;
        border-radius: 999px;
        font-size: 12.5px;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 12px 30px rgba(0,0,0,0.7);
        backdrop-filter: blur(12px);
        z-index: 99990;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    .floating-cmd-badge:hover {
        background: rgba(16, 185, 129, 0.25);
        border-color: #34d399;
        color: #fff;
        transform: translateY(-2px);
    }
</style>

<!-- Floating Corner Trigger Badge -->
<div class="floating-cmd-badge" onclick="openCmdPalette()" title="Press Ctrl + K to open Operations Workspace">
    <i class="fa-solid fa-bolt" style="color:#34d399;"></i>
    <span><span class="ops-kbd-pill">Ctrl</span> + <span class="ops-kbd-pill">K</span> Core Operations</span>
</div>

<!-- Operations Workspace Modal Overlay -->
<div id="commandPaletteModal" class="cmd-palette-overlay" onclick="closeCmdPaletteOnBackdrop(event)">
    <div class="cmd-palette-card">
        
        <div class="ops-header">
            <div class="ops-header-title">
                <div class="ops-header-icon">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div>
                    <h2>Admin Core Operations Workspace</h2>
                    <p>Controlled operations hub • Accessible via <span class="ops-kbd-pill">Ctrl + K</span> shortcut</p>
                </div>
            </div>
            <button type="button" class="ops-close-btn" onclick="closeCmdPalette()">ESC ✕</button>
        </div>

        <div class="ops-body">
            
            <!-- SECTION 1: PARTICIPANT REGISTRATION -->
            <div class="ops-section">
                <div class="ops-section-title">
                    <i class="fa-solid fa-user-plus"></i> Participant Registration
                </div>
                
                <a href="<?= app_url('/admin/registrar/entries.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon">
                        <i class="fa-solid fa-id-badge"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Register Participants</span>
                        <span class="ops-link-sub">Manage student entries &amp; program slots</span>
                    </div>
                </a>

                <a href="<?= app_url('/admin/event-manager/add-members.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon">
                        <i class="fa-solid fa-users-medical"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Add Team Members</span>
                        <span class="ops-link-sub">Assign students to event team rosters</span>
                    </div>
                </a>
            </div>

            <!-- SECTION 2: MARK ENTRY -->
            <div class="ops-section">
                <div class="ops-section-title">
                    <i class="fa-solid fa-pen-to-square"></i> Mark Entry (Score Input)
                </div>

                <a href="<?= app_url('/admin/score-entry/score-entry.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(59, 130, 246, 0.18); color: #60a5fa;">
                        <i class="fa-solid fa-square-poll-vertical"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Score Entry Page</span>
                        <span class="ops-link-sub">Direct judge score entry &amp; evaluation</span>
                    </div>
                </a>

                <a href="<?= app_url('/admin/score-entry/program-scores.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(59, 130, 246, 0.18); color: #60a5fa;">
                        <i class="fa-solid fa-file-waveform"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Score Audit View</span>
                        <span class="ops-link-sub">Monitor submitted program score breakdown</span>
                    </div>
                </a>
            </div>

            <!-- SECTION 3: MARK APPROVAL -->
            <div class="ops-section">
                <div class="ops-section-title">
                    <i class="fa-solid fa-clipboard-check"></i> Mark Approval &amp; Rankings
                </div>

                <a href="<?= app_url('/admin/score-update/score-approval.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(168, 85, 247, 0.18); color: #c084fc;">
                        <i class="fa-solid fa-shield-check"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Approve Standings &amp; Marks</span>
                        <span class="ops-link-sub">Review &amp; approve program scores</span>
                    </div>
                </a>

                <a href="<?= app_url('/admin/score-update/approval-marks.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(168, 85, 247, 0.18); color: #c084fc;">
                        <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Final Rankings &amp; Points</span>
                        <span class="ops-link-sub">View official published standings</span>
                    </div>
                </a>

                <a href="<?= app_url('/admin/score-update/reviews.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(168, 85, 247, 0.18); color: #c084fc;">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Audit Reviews</span>
                        <span class="ops-link-sub">Score dispute &amp; audit history</span>
                    </div>
                </a>
            </div>

            <!-- SECTION 4: IMPORTANT OPERATIONS -->
            <div class="ops-section">
                <div class="ops-section-title">
                    <i class="fa-solid fa-sliders"></i> Important Operations
                </div>

                <?php if ($isAdminUser): ?>
                <a href="<?= app_url('/admin/event-manager/user-approvals.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(245, 158, 11, 0.18); color: #fbbf24;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">User Approvals</span>
                        <span class="ops-link-sub">Approve staff &amp; admin account requests</span>
                    </div>
                </a>

                <a href="<?= app_url('/admin/event-manager/settings.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(245, 158, 11, 0.18); color: #fbbf24;">
                        <i class="fa-solid fa-gear"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">System Settings</span>
                        <span class="ops-link-sub">Broadcast, judges &amp; event configuration</span>
                    </div>
                </a>
                <?php endif; ?>

                <a href="<?= app_url('/admin/event-manager/sections.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(245, 158, 11, 0.18); color: #fbbf24;">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Schedule Sessions</span>
                        <span class="ops-link-sub">Configure stage session blocks</span>
                    </div>
                </a>

                <a href="<?= app_url('/admin/event-manager/programs.php') ?>" class="ops-link-card">
                    <div class="ops-link-icon" style="background: rgba(245, 158, 11, 0.18); color: #fbbf24;">
                        <i class="fa-solid fa-rectangle-list"></i>
                    </div>
                    <div class="ops-link-info">
                        <span class="ops-link-title">Programs Manager</span>
                        <span class="ops-link-sub">Add &amp; edit competition programs</span>
                    </div>
                </a>
            </div>

        </div>

        <div class="ops-footer">
            <div>Press <span class="ops-kbd-pill">ESC</span> or click outside to dismiss</div>
            <div>Admin Core Operations Console • Kauzariyya Musabaqa</div>
        </div>

    </div>
</div>

<script>
function openCmdPalette() {
    const modal = document.getElementById('commandPaletteModal');
    if (!modal) return;
    modal.style.display = 'flex';
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

// Global Secret Ctrl + K Keybind Listener
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        const modal = document.getElementById('commandPaletteModal');
        if (modal && modal.style.display === 'flex') {
            closeCmdPalette();
        } else {
            openCmdPalette();
        }
        return;
    }

    if (e.key === 'Escape') {
        const modal = document.getElementById('commandPaletteModal');
        if (modal && modal.style.display === 'flex') {
            closeCmdPalette();
        }
    }
});
</script>
