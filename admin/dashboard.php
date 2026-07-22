<?php
$pageTitle = 'Musabaqa Control Hub';

require_once __DIR__ . '/../includes/admin-helpers.php';
require_once __DIR__ . '/../includes/event-guard.php';
require_login();

$pdo = $GLOBALS['musabaqa_pdo'];
$flash = admin_take_flash();
$user = current_user();
$isAdmin = is_admin();

// Fetch active & accessible musabaqas
$activeEvent = get_active_musabaqa();
$accessibleEvents = get_accessible_musabaqas($user);

// Handle POST actions (Status updates & Image uploads for admin)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid CSRF security token.');
        admin_redirect('/admin/dashboard.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $eventId = (int)($_POST['event_id'] ?? 0);

    if ($action === 'set_status' && $eventId > 0) {
        $newStatus = (string)($_POST['status'] ?? 'draft');
        $validStatuses = ['active', 'scheduled', 'draft', 'unactive', 'completed'];

        if (in_array($newStatus, $validStatuses, true)) {
            try {
                $pdo->beginTransaction();
                // If setting to active, enforce ONLY ONE active musabaqa by setting others to unactive
                if ($newStatus === 'active') {
                    $pdo->exec("UPDATE musabaqa_events SET status = 'unactive' WHERE status = 'active'");
                }
                $stmt = $pdo->prepare("UPDATE musabaqa_events SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $eventId]);
                $pdo->commit();
                admin_flash('success', 'Musabaqa status updated successfully.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                admin_flash('error', 'Failed to update status: ' . $e->getMessage());
            }
        }
        admin_redirect('/admin/dashboard.php');
    }

    if ($action === 'upload_image' && $eventId > 0 && isset($_FILES['poster_image'])) {
        $file = $_FILES['poster_image'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
            if (in_array($ext, $allowed, true)) {
                $uploadDir = __DIR__ . '/../uploads/musabaqa_images/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'musabaqa_' . $eventId . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $relPath = 'uploads/musabaqa_images/' . $filename;
                    $stmt = $pdo->prepare("UPDATE musabaqa_events SET image_path = ? WHERE id = ?");
                    $stmt->execute([$relPath, $eventId]);
                    admin_flash('success', 'Poster image uploaded successfully.');
                } else {
                    admin_flash('error', 'Failed to save uploaded image.');
                }
            } else {
                admin_flash('error', 'Invalid image file format. Allowed: JPG, PNG, WEBP, GIF, SVG.');
            }
        }
        admin_redirect('/admin/dashboard.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= asset_url('css/musabaqa-categories.css') ?>">
<script src="<?= asset_url('js/musabaqa-animated-bg.js') ?>"></script>

<div class="main-content">

    <!-- Topbar Header -->
    <div class="musabaqa-hub-header">
        <div>
            <h1><i class="fa-solid fa-crown" style="color: #f59e0b;"></i> Musabaqa Control Center</h1>
            <p>Institutional Event Management, Roles & Category Operations</p>
        </div>
        <?php if ($isAdmin): ?>
            <a class="btn btn-success btn-md" href="<?= app_url('/admin/events.php') ?>" style="background: linear-gradient(135deg, #10b981, #059669); border: none;">
                <i class="fa-solid fa-calendar-plus"></i> Events Hub
            </a>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 1.5rem;">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- Non-Admin Active Event Guard Check -->
    <?php if (!$isAdmin && !$activeEvent): ?>
        <?php render_no_active_event_guard(); ?>
    <?php else: ?>

        <!-- Trigger Animated Role Welcome Splash -->
        <?php render_animated_role_welcome(); ?>

        <!-- Admin Musabaqa Management Hub -->
        <?php if ($isAdmin): ?>
            <div class="panel mb-6" style="background: rgba(18, 24, 38, 0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 2rem;">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h2 style="font-family: var(--font-heading); font-size: 1.5rem; font-weight: 700; color: #fff; margin: 0;">
                            <i class="fa-solid fa-trophy" style="color:#eab308;"></i> Musabaqas Database Management
                        </h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                            Activate, Schedule, Draft, Deactivate or Complete competitions. Strictly <strong>ONE</strong> active Musabaqa at a time.
                        </p>
                    </div>
                </div>

                <div class="musabaqa-grid">
                    <?php foreach ($accessibleEvents as $ev): ?>
                        <?php
                            $status = strtolower((string)($ev['status'] ?? 'draft'));
                            $imagePath = !empty($ev['image_path']) ? app_url('/' . $ev['image_path']) : asset_url('kauzariyya8.png');
                            $pillClass = match($status) {
                                'active' => 'pill-active',
                                'scheduled' => 'pill-scheduled',
                                'draft' => 'pill-draft',
                                'unactive' => 'pill-unactive',
                                'completed' => 'pill-completed',
                                default => 'pill-draft'
                            };
                            $cardClass = 'musabaqa-card status-' . $status;
                        ?>
                        <div class="<?= $cardClass ?>">
                            <span class="musabaqa-status-pill <?= $pillClass ?>">
                                <?= strtoupper($status) ?>
                            </span>

                            <div class="musabaqa-card-media">
                                <img src="<?= e($imagePath) ?>" alt="<?= e($ev['title']) ?>" class="musabaqa-card-img">
                            </div>

                            <div class="musabaqa-card-body">
                                <h3 class="musabaqa-card-title"><?= e($ev['title']) ?></h3>
                                <p class="musabaqa-card-desc"><?= e($ev['description'] ?: 'No description provided.') ?></p>

                                <!-- Status Control Form for Admin -->
                                <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.08);">
                                    <form method="POST" action="" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.8rem;">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">

                                        <?php if ($status !== 'active'): ?>
                                            <button type="submit" name="status" value="active" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; font-size:0.75rem; padding:0.4rem 0.8rem; border-radius:8px;">
                                                <i class="fa-solid fa-play"></i> Activate
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($status !== 'scheduled'): ?>
                                            <button type="submit" name="status" value="scheduled" class="btn btn-sm" style="background:#f97316; color:#fff; border:none; font-size:0.75rem; padding:0.4rem 0.8rem; border-radius:8px;">
                                                <i class="fa-solid fa-clock"></i> Schedule
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($status !== 'draft'): ?>
                                            <button type="submit" name="status" value="draft" class="btn btn-sm" style="background:#eab308; color:#000; border:none; font-size:0.75rem; padding:0.4rem 0.8rem; border-radius:8px;">
                                                <i class="fa-solid fa-file-pen"></i> Draft
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($status !== 'unactive'): ?>
                                            <button type="submit" name="status" value="unactive" class="btn btn-sm" style="background:#ef4444; color:#fff; border:none; font-size:0.75rem; padding:0.4rem 0.8rem; border-radius:8px;">
                                                <i class="fa-solid fa-power-off"></i> Deactivate
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($status !== 'completed'): ?>
                                            <button type="submit" name="status" value="completed" class="btn btn-sm" style="background:#6b7280; color:#fff; border:none; font-size:0.75rem; padding:0.4rem 0.8rem; border-radius:8px;">
                                                <i class="fa-solid fa-flag-checkered"></i> Complete
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Upload Poster Image Form -->
                                    <form method="POST" action="" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 0.5rem;">
                                        <?= admin_csrf_field() ?>
                                        <input type="hidden" name="action" value="upload_image">
                                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                        <input type="file" name="poster_image" accept="image/*" required style="font-size:0.75rem; color:#9ca3af; max-width: 170px;">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="font-size:0.75rem; padding:0.3rem 0.7rem;">
                                            <i class="fa-solid fa-upload"></i> Upload Image
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Category Roles Grid (Available for all roles & admin) -->
        <div class="panel" style="background: rgba(18, 24, 38, 0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 2rem;">
            <h2 style="font-family: var(--font-heading); font-size: 1.5rem; font-weight: 700; color: #fff; margin-bottom: 0.5rem;">
                <i class="fa-solid fa-layer-group" style="color:#6366f1;"></i> Category Workspaces
            </h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">
                Select a category role space to manage specialized functions:
            </p>

            <div class="role-buttons-grid">
                <a href="<?= app_url('/admin/category/event-manager.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                        <i class="fa-solid fa-calendar-gear"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">1. Event Manager</div>
                        <div class="role-action-subtitle">Event Settings, Programs & Schedule</div>
                    </div>
                </a>

                <a href="<?= app_url('/admin/category/team-manager.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                        <i class="fa-solid fa-users-gear"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">2. Team Manager</div>
                        <div class="role-action-subtitle">Teams, Members & Chest Numbers</div>
                    </div>
                </a>

                <a href="<?= app_url('/admin/category/printer.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                        <i class="fa-solid fa-print"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">3. Printer</div>
                        <div class="role-action-subtitle">ID Cards, Chest Tags, CSVs & Print Updates</div>
                    </div>
                </a>

                <a href="<?= app_url('/admin/category/registrar.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                        <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">4. Registrar</div>
                        <div class="role-action-subtitle">Manages Program Entries</div>
                    </div>
                </a>

                <a href="<?= app_url('/admin/category/live-display.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                        <i class="fa-solid fa-tv"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">5. Live Display Manager</div>
                        <div class="role-action-subtitle">Controls Scoreboard TV & Overlays</div>
                    </div>
                </a>

                <a href="<?= app_url('/admin/category/score-entry.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(139, 92, 246, 0.2); color: #a78bfa;">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">6. Score Entry Agent</div>
                        <div class="role-action-subtitle">Enters Judge Scores & Requests Approval</div>
                    </div>
                </a>

                <a href="<?= app_url('/admin/category/score-update.php') ?>" class="role-action-btn">
                    <div class="role-action-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                        <i class="fa-solid fa-square-check"></i>
                    </div>
                    <div class="role-action-info">
                        <div class="role-action-title">7. Score Update Agent</div>
                        <div class="role-action-subtitle">Approves Scores & Updates Team Totals</div>
                    </div>
                </a>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php admin_close_page(); ?>
