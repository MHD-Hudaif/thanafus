<?php

require_once __DIR__ . '/bootstrap.php';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {

    function e($value): string {

        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function generate_csrf_token(): string {

    if (empty($_SESSION['csrf_token'])) {

        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(24));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {

    return
        !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals(
            $_SESSION['csrf_token'],
            $token
        );
}

/*
|--------------------------------------------------------------------------
| LOAD USER
|--------------------------------------------------------------------------
*/

function load_user(int $userId): ?array {

    $pdo = $GLOBALS['dashboard_pdo'];

    $stmt = $pdo->prepare("
        SELECT
            id,
            username,
            email,
            phone,
            full_name,
            profile_photo,
            status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD ROLES
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.name,
            r.slug
        FROM roles r
        JOIN user_roles ur
            ON ur.role_id = r.id
        WHERE ur.user_id = ?
        ORDER BY r.name
    ");

    $stmt->execute([$userId]);

    $roleRows =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    $roles = [];
    $roleNames = [];

    foreach ($roleRows as $roleRow) {

        $roles[] =
            $roleRow['slug'];

        $roleNames[] =
            $roleRow['name'];
    }

    $user['roles'] = $roles;
    $user['role_names'] = $roleNames;

    return $user;
}

/*
|--------------------------------------------------------------------------
| LOGIN SESSION
|--------------------------------------------------------------------------
*/

function login_user_session(int $userId): void {

    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;

    $_SESSION['user'] =
        load_user($userId);
}

/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

function require_login(): void {

    if (empty($_SESSION['user_id'])) {

        header(
            'Location: '
            . app_url('/auth/login')
        );

        exit;
    }

    $_SESSION['user'] =
        load_user(
            (int)$_SESSION['user_id']
        );

    if (
        empty($_SESSION['user'])
        || ($_SESSION['user']['status'] ?? '')
            !== 'active'
    ) {

        logout_user();

        header(
            'Location: '
            . app_url('/auth/login')
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| ROLE HELPERS
|--------------------------------------------------------------------------
*/

function current_user_has_role(
    string $roleSlug
): bool {

    if (empty($_SESSION['user']['roles'])) {
        return false;
    }

    return in_array(
        $roleSlug,
        (array)$_SESSION['user']['roles'],
        true
    );
}

function is_admin(): bool {

    return current_user_has_role('admin');
}

function require_role(string $roleSlug): void {

    if (is_admin()) {
        return;
    }

    if (
        !current_user_has_role($roleSlug)
    ) {

        http_response_code(403);

        exit('Access denied');
    }
}

function require_roles(array $roles): void {

    if (is_admin()) {
        return;
    }

    foreach ($roles as $role) {

        if (
            current_user_has_role($role)
        ) {
            return;
        }
    }

    http_response_code(403);

    exit('Access denied');
}

/*
|--------------------------------------------------------------------------
| PERMISSIONS
|--------------------------------------------------------------------------
*/

function current_user_has_permission(
    string $permission
): bool {

    if (current_user_has_role('admin')) {
        return true;
    }

    $pdo = $GLOBALS['dashboard_pdo'];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM permissions p
        JOIN role_permissions rp
            ON rp.permission_id = p.id
        JOIN user_roles ur
            ON ur.role_id = rp.role_id
        WHERE ur.user_id = ?
        AND p.slug = ?
    ");

    $stmt->execute([
        $_SESSION['user']['id'],
        $permission
    ]);

    return $stmt->fetchColumn() > 0;
}

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

function logout_user(): void {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params =
            session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/

function current_user(): ?array {

    return $_SESSION['user'] ?? null;
}

/*
|--------------------------------------------------------------------------
| AVATAR URL
|--------------------------------------------------------------------------
*/

function avatar_url(?string $photo): ?string {
    if (empty($photo)) {
        return null;
    }
    if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
        return $photo;
    }
    return "/kauzariyya-dashboard/profile-pic-uploads/" . $photo;
}
