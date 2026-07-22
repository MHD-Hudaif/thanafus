<?php
/* includes/event-guard.php - Musabaqa Active Event Guard & Role Detection Helper */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

function get_active_musabaqa(): ?array {
    $pdo = $GLOBALS['musabaqa_pdo'];
    $stmt = $pdo->prepare("SELECT * FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    return $event ?: null;
}

function get_accessible_musabaqas(?array $user = null): array {
    $pdo = $GLOBALS['musabaqa_pdo'];
    $user ??= current_user();

    if (!$user) return [];

    // Admins see all musabaqas
    if (is_admin()) {
        $stmt = $pdo->query("SELECT * FROM musabaqa_events ORDER BY status = 'active' DESC, id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Non-admins see only active & scheduled musabaqas
    $stmt = $pdo->query("SELECT * FROM musabaqa_events WHERE status IN ('active', 'scheduled') ORDER BY status = 'active' DESC, id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function render_no_active_event_guard(): void {
    ?>
    <div class="no-active-guard-card">
        <div class="no-active-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="no-active-title">No Active Event Detected</div>
        <div class="no-active-desc">
            There is currently no active program or event running in the system.<br>
            <strong>Please contact the system administrator</strong> to activate an event to access your role dashboard.
        </div>
    </div>
    <?php
}

function render_animated_role_welcome(?string $roleName = null): void {
    if (empty($roleName)) {
        $user = current_user();
        $roles = $user['role_names'] ?? ['Coordinator'];
        $roleName = implode(' & ', $roles);
    }
    ?>
    <div id="roleSplashOverlay" class="role-splash-overlay">
        <div class="role-splash-content">
            <div class="role-splash-badge">KAUZARIYYA MUSABAQA</div>
            <div class="role-splash-text-container">
                <h2 class="role-splash-heading">
                    Your Role Is <span class="role-splash-role-name" id="typewriterRole"></span>
                </h2>
            </div>
            <div class="mt-4" style="margin-top: 1.5rem;">
                <button class="btn btn-primary" onclick="dismissRoleSplash()" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; padding: 0.8rem 2rem; border-radius: 50px; font-weight: 700; color: #fff; cursor: pointer; box-shadow: 0 10px 20px rgba(99,102,241,0.4);">
                    <i class="fa-solid fa-right-to-bracket"></i> Enter Dashboard
                </button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const fullText = <?= json_encode($roleName) ?>;
        const target = document.getElementById('typewriterRole');
        let idx = 0;

        function typeChar() {
            if (idx <= fullText.length) {
                target.textContent = fullText.substring(0, idx) + (idx < fullText.length ? '...' : '');
                idx++;
                setTimeout(typeChar, 70);
            }
        }
        setTimeout(typeChar, 300);

        window.dismissRoleSplash = function() {
            const overlay = document.getElementById('roleSplashOverlay');
            if (overlay) {
                overlay.classList.add('fade-out');
                setTimeout(() => overlay.remove(), 600);
            }
        };

        // Auto-dismiss after 4.5 seconds if not clicked
        setTimeout(() => {
            if (typeof window.dismissRoleSplash === 'function') window.dismissRoleSplash();
        }, 4500);
    })();
    </script>
    <?php
}
