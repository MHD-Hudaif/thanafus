<?php
$pageTitle = 'Account Management';

require_once __DIR__ . '/../../includes/admin-helpers.php';
require_login();

$pdo       = $GLOBALS['musabaqa_pdo'];
$dashPdo   = $GLOBALS['dashboard_pdo'];
$me        = current_user();
$myId      = (int)($me['id'] ?? 0);
$adminMode = is_admin();

/* ─── POST HANDLERS ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        admin_flash('error', 'Invalid security token.');
        admin_redirect('/admin/event-manager/accounts.php');
    }

    $action = (string)($_POST['action'] ?? '');

    /* ── 1. Add new user (admin only) ── */
    if ($action === 'add_user' && $adminMode) {
        $uName  = trim((string)($_POST['new_username']  ?? ''));
        $uFull  = trim((string)($_POST['new_full_name'] ?? ''));
        $uEmail = trim((string)($_POST['new_email']     ?? ''));
        $uPhone = trim((string)($_POST['new_phone']     ?? ''));
        $uPass  = (string)($_POST['new_password'] ?? '');
        $uRoles = array_map('intval', (array)($_POST['new_roles'] ?? []));

        if ($uName === '' || $uFull === '' || strlen($uPass) < 6) {
            admin_flash('error', 'Username, full name, and a password (min 6 chars) are required.');
        } else {
            try {
                $dup = $dashPdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
                $dup->execute([$uName]);
                if ($dup->fetchColumn()) {
                    admin_flash('error', "Username '{$uName}' is already taken.");
                } else {
                    $dashPdo->prepare("INSERT INTO users (username, full_name, email, phone, password, status, created_at)
                                       VALUES (?, ?, ?, ?, ?, 'active', NOW())")
                            ->execute([$uName, $uFull, $uEmail, $uPhone, password_hash($uPass, PASSWORD_DEFAULT)]);
                    $newId = (int)$dashPdo->lastInsertId();

                    // Assign selected roles
                    if ($uRoles) {
                        $ins = $dashPdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)");
                        foreach ($uRoles as $rid) {
                            if ($rid > 0) $ins->execute([$newId, $rid]);
                        }
                    }
                    admin_flash('success', "Account '{$uFull}' created successfully.");
                }
            } catch (Throwable $e) {
                admin_flash('error', 'Could not create user: ' . $e->getMessage());
            }
        }
        admin_redirect('/admin/event-manager/accounts.php');
    }

    /* ── 2. Edit user (admin only) — name, email, phone, status, roles ── */
    if ($action === 'edit_user' && $adminMode) {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $uFull  = trim((string)($_POST['edit_full_name'] ?? ''));
        $uEmail = trim((string)($_POST['edit_email']     ?? ''));
        $uPhone = trim((string)($_POST['edit_phone']     ?? ''));
        $uStat  = in_array($_POST['edit_status'] ?? '', ['active', 'pending', 'suspended'], true)
                    ? $_POST['edit_status'] : 'active';
        $uRoles = array_map('intval', (array)($_POST['edit_roles'] ?? []));

        if ($uid < 1 || $uFull === '') {
            admin_flash('error', 'User ID and full name are required.');
        } else {
            try {
                // Update basic info (always) — status only if not editing self
                if ($uid === $myId) {
                    $dashPdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                            ->execute([$uFull, $uEmail, $uPhone, $uid]);
                    $_SESSION['user'] = load_user($uid);
                } else {
                    $dashPdo->prepare("UPDATE users SET full_name=?, email=?, phone=?, status=? WHERE id=?")
                            ->execute([$uFull, $uEmail, $uPhone, $uStat, $uid]);
                }

                // Sync roles
                $dashPdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$uid]);
                if ($uRoles) {
                    $ins = $dashPdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)");
                    foreach ($uRoles as $rid) {
                        if ($rid > 0) $ins->execute([$uid, $rid]);
                    }
                }
                admin_flash('success', 'User updated successfully.');
            } catch (Throwable $e) {
                admin_flash('error', 'Could not update user: ' . $e->getMessage());
            }
        }
        admin_redirect('/admin/event-manager/accounts.php');
    }

    /* ── 3. Reset user password (admin only) ── */
    if ($action === 'reset_password' && $adminMode) {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $npass = (string)($_POST['reset_pass'] ?? '');
        if ($uid && strlen($npass) >= 6) {
            try {
                $dashPdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($npass, PASSWORD_DEFAULT), $uid]);
                admin_flash('success', 'Password reset successfully.');
            } catch (Throwable $e) {
                admin_flash('error', 'Could not reset password.');
            }
        } else {
            admin_flash('error', 'Password must be at least 6 characters.');
        }
        admin_redirect('/admin/event-manager/accounts.php');
    }
}

/* ─── LOAD DATA ───────────────────────────────────────────────── */
$flash = admin_take_flash();

// Re-load self fresh
$stmtMe = $dashPdo->prepare("SELECT id, username, email, phone, full_name, profile_photo, status FROM users WHERE id=? LIMIT 1");
$stmtMe->execute([$myId]);
$me = $stmtMe->fetch(PDO::FETCH_ASSOC) ?: $me;

// All available roles
$allRoles = [];
try {
    $allRoles = $dashPdo->query("SELECT id, name, slug FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// All users with their role IDs (admin only)
$allUsers = [];
if ($adminMode) {
    $stmtAll = $dashPdo->prepare("
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.status, u.created_at,
               GROUP_CONCAT(r.name  ORDER BY r.name  SEPARATOR ', ') AS role_names,
               GROUP_CONCAT(ur.role_id ORDER BY r.name SEPARATOR ',') AS role_ids
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r       ON r.id = ur.role_id
        GROUP BY u.id
        ORDER BY u.id ASC
    ");
    $stmtAll->execute();
    $allUsers = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="topbar">
        <div>
            <div class="page-title"><i class="fa-solid fa-users-gear mr-2" style="color:var(--accent);"></i> Account Management</div>
            <div class="page-subtitle">Manage all system accounts, roles, and permissions</div>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="<?= app_url('/') ?>" class="btn btn-secondary btn-md" target="_blank">
                <i class="fa-solid fa-house"></i> Home Page
            </a>
            <?php if ($adminMode): ?>
            <button class="btn btn-success btn-md" data-open-modal="addUserModal">
                <i class="fa-solid fa-user-plus"></i> Add Account
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> mb-4">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mr-2"></i>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($adminMode): ?>

    <!-- ── USER TABLE ── -->
    <div class="panel">
        <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="panel-title"><i class="fa-solid fa-users mr-2" style="color:var(--accent);"></i> All Accounts (<?= count($allUsers) ?>)</h3>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Email / Phone</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th style="text-align:right;width:150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allUsers as $u): ?>
                    <?php $roleIdArr = $u['role_ids'] ? array_map('intval', explode(',', $u['role_ids'])) : []; ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:34px;height:34px;border-radius:9px;overflow:hidden;flex-shrink:0;border:1px solid rgba(255,255,255,0.1);">
                                    <img src="<?= !empty($u['profile_photo'])
                                        ? avatar_url($u['profile_photo'])
                                        : 'https://ui-avatars.com/api/?name=' . urlencode($u['full_name'] ?: $u['username']) . '&background=073a69&color=10b981&bold=true&size=64' ?>"
                                         alt="" style="width:100%;height:100%;object-fit:cover;">
                                </div>
                                <div>
                                    <strong style="font-size:13px;"><?= e($u['full_name'] ?: '—') ?></strong>
                                    <?php if ((int)$u['id'] === $myId): ?>
                                        <span class="badge badge-success" style="font-size:9px;vertical-align:middle;">You</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><code style="font-size:12px;">@<?= e($u['username']) ?></code></td>
                        <td>
                            <div style="font-size:12px;"><?= e($u['email'] ?: '—') ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?= e($u['phone'] ?: '') ?></div>
                        </td>
                        <td>
                            <?php if ($u['role_names']): ?>
                                <?php foreach (explode(', ', $u['role_names']) as $rn): ?>
                                    <span class="badge badge-neutral" style="font-size:10px;margin:1px;"><?= e($rn) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:12px;">No role</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $u['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
                                <?= strtoupper(e($u['status'])) ?>
                            </span>
                        </td>
                        <td style="text-align:right;">
                            <button class="btn btn-secondary btn-sm"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                        'id'        => $u['id'],
                                        'full_name' => $u['full_name'],
                                        'email'     => $u['email'],
                                        'phone'     => $u['phone'],
                                        'status'    => $u['status'],
                                        'role_ids'  => $roleIdArr,
                                    ]), ENT_QUOTES) ?>)">
                                <i class="fa-solid fa-pen"></i> Edit
                            </button>
                            <button class="btn btn-secondary btn-sm" style="margin-top:4px;"
                                    onclick="openResetModal(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['full_name'] ?: $u['username'])) ?>')">
                                <i class="fa-solid fa-key"></i> Pass
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         MODAL: ADD USER
    ══════════════════════════════════════════════ -->
    <div class="modal-overlay" id="addUserModal" aria-hidden="true">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-user-plus mr-2"></i> Add New Account</div>
                <button class="modal-close" type="button" data-modal-close><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="add_user">
                <div class="form-grid">
                    <div class="input-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="new_full_name" required>
                    </div>
                    <div class="input-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="new_username" required autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Email</label>
                        <input type="email" name="new_email">
                    </div>
                    <div class="input-group">
                        <label>Phone</label>
                        <input type="tel" name="new_phone">
                    </div>
                    <div class="input-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="input-group">
                        <label>Roles</label>
                        <div style="display:flex;flex-direction:column;gap:6px;padding:8px 0;">
                            <?php foreach ($allRoles as $role): ?>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                                <input type="checkbox" name="new_roles[]" value="<?= (int)$role['id'] ?>"
                                       style="width:15px;height:15px;accent-color:#10b981;">
                                <span><?= e($role['name']) ?></span>
                                <code style="font-size:10px;color:var(--text-muted);"><?= e($role['slug']) ?></code>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary btn-md" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-success btn-md"><i class="fa-solid fa-user-plus mr-1"></i> Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         MODAL: EDIT USER
    ══════════════════════════════════════════════ -->
    <div class="modal-overlay" id="editUserModal" aria-hidden="true">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-user-pen mr-2"></i> Edit Account</div>
                <button class="modal-close" type="button" data-modal-close><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" id="editUserForm">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="form-grid">
                    <div class="input-group full-width">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="edit_full_name" id="editFullName" required>
                    </div>
                    <div class="input-group">
                        <label>Email</label>
                        <input type="email" name="edit_email" id="editEmail">
                    </div>
                    <div class="input-group">
                        <label>Phone</label>
                        <input type="tel" name="edit_phone" id="editPhone">
                    </div>
                    <div class="input-group" id="editStatusRow">
                        <label>Status</label>
                        <select name="edit_status" id="editStatus">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    <div class="input-group full-width">
                        <label>Roles</label>
                        <div id="editRolesContainer" style="display:flex;flex-direction:column;gap:6px;padding:8px 0;">
                            <?php foreach ($allRoles as $role): ?>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                                <input type="checkbox" class="edit-role-cb" name="edit_roles[]"
                                       value="<?= (int)$role['id'] ?>"
                                       data-role-id="<?= (int)$role['id'] ?>"
                                       style="width:15px;height:15px;accent-color:#10b981;">
                                <span><?= e($role['name']) ?></span>
                                <code style="font-size:10px;color:var(--text-muted);"><?= e($role['slug']) ?></code>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary btn-md" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-success btn-md"><i class="fa-solid fa-save mr-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════
         MODAL: RESET PASSWORD
    ══════════════════════════════════════════════ -->
    <div class="modal-overlay" id="resetPassModal" aria-hidden="true">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-key mr-2"></i> Reset Password</div>
                <button class="modal-close" type="button" data-modal-close><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div class="panel" style="margin:0;">
                    <p style="margin-bottom:12px;font-size:14px;">Set new password for <strong id="resetUserName"></strong>:</p>
                    <div class="input-group full-width">
                        <label>New Password <span class="required">*</span></label>
                        <input type="password" name="reset_pass" required minlength="6" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary btn-md" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-danger btn-md"><i class="fa-solid fa-key mr-1"></i> Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

</div>

<script>
const MY_ID = <?= $myId ?>;

/* Wire open-modal buttons */
document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', () => window.openModal(btn.dataset.openModal));
});

/* ── Edit User ── */
function openEditModal(u) {
    document.getElementById('editUserId').value   = u.id;
    document.getElementById('editFullName').value = u.full_name || '';
    document.getElementById('editEmail').value    = u.email     || '';
    document.getElementById('editPhone').value    = u.phone     || '';

    // Status: hide row when editing self
    const statusRow = document.getElementById('editStatusRow');
    if (parseInt(u.id) === MY_ID) {
        statusRow.style.display = 'none';
    } else {
        statusRow.style.display = '';
        document.getElementById('editStatus').value = u.status || 'active';
    }

    // Roles: tick the ones this user has
    document.querySelectorAll('.edit-role-cb').forEach(cb => {
        cb.checked = (u.role_ids || []).includes(parseInt(cb.dataset.roleId));
    });

    window.openModal('editUserModal');
}

/* ── Reset Password ── */
function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = userName;
    window.openModal('resetPassModal');
}
</script>

<?php admin_close_page(); ?>
