
<?php

require_once __DIR__ . '/../../config/auth.php';

require_login();

$musabaqa_pdo =
    $GLOBALS['musabaqa_pdo'];

$id =
    (int)($_GET['id'] ?? 0);

$activeEventId =
    $_SESSION['active_event_id']
    ?? null;

/*
|--------------------------------------------------------------------------
| REQUIRE EVENT
|--------------------------------------------------------------------------
*/

if(!$activeEventId){

    header(
        'Location: '
        . app_url('/admin/events')
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDATE TEAM ID
|--------------------------------------------------------------------------
*/

if($id <= 0){

    header(
        'Location: '
        . app_url('/admin/teams')
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| FIND TEAM
|--------------------------------------------------------------------------
*/

$stmt = $musabaqa_pdo->prepare("
    SELECT id
    FROM musabaqa_teams
    WHERE id = ?
    AND event_id = ?
    LIMIT 1
");

$stmt->execute([

    $id,
    $activeEventId

]);

$team =
    $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| INVALID TEAM
|--------------------------------------------------------------------------
*/

if(!$team){

    header(
        'Location: '
        . app_url('/admin/teams')
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| SET ACTIVE TEAM
|--------------------------------------------------------------------------
*/

$_SESSION['active_team_id'] = $id;

/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/

header(
    'Location: '
    . app_url('/admin/dashboard')
);

exit;
