<?php
require_once __DIR__ . '/../../includes/admin-helpers.php';
require_once __DIR__ . '/../../includes/id-card-helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();

    $pdo = $GLOBALS['musabaqa_pdo'];
    $activeEvent = admin_require_active_event($pdo);
    $activeEventId = (int)$activeEvent['id'];

    $action = $_REQUEST['action'] ?? '';

    if ($action === 'get_template') {
        $teamId = isset($_GET['team_id']) && $_GET['team_id'] !== '' ? (int)$_GET['team_id'] : null;
        $template = id_card_get_template($pdo, $activeEventId, $teamId);

        echo json_encode([
            'success' => true,
            'template' => $template,
            'teams' => $pdo->query("SELECT id, team_name, team_color FROM musabaqa_teams WHERE event_id = {$activeEventId} ORDER BY team_name ASC")->fetchAll(PDO::FETCH_ASSOC)
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    // CSRF verification
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!verify_csrf_token($token)) {
        throw new Exception('Invalid security token.');
    }

    $teamId = isset($_POST['team_id']) && $_POST['team_id'] !== '' ? (int)$_POST['team_id'] : null;

    if ($action === 'upload_background') {
        if (!isset($_FILES['background']) || $_FILES['background']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No valid image file uploaded.');
        }

        $file = $_FILES['background'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('Invalid file type. Please upload PNG, JPG, WEBP, or SVG.');
        }

        $uploadDir = __DIR__ . '/../../uploads/id_card_templates/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $teamSuffix = ($teamId !== null && $teamId > 0) ? "team_{$teamId}" : 'default';
        $filename = "bg_evt_{$activeEventId}_{$teamSuffix}_" . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save uploaded file.');
        }

        $relativePath = 'uploads/id_card_templates/' . $filename;
        $existing = id_card_get_template($pdo, $activeEventId, $teamId);

        id_card_save_template(
            $pdo,
            $activeEventId,
            $teamId,
            $relativePath,
            $existing['layout_config'] ?? id_card_default_layout(),
            $existing['orientation'] ?? 'portrait',
            (int)($existing['card_width'] ?? 600),
            (int)($existing['card_height'] ?? 950)
        );

        echo json_encode([
            'success' => true,
            'message' => 'Background image uploaded successfully!',
            'background_url' => app_url('/' . $relativePath),
            'background_path' => $relativePath
        ]);
        exit;
    }

    if ($action === 'save_layout') {
        $rawConfig = $_POST['layout_config'] ?? '';
        $layoutConfig = json_decode($rawConfig, true);
        if (!is_array($layoutConfig)) {
            throw new Exception('Invalid layout configuration payload.');
        }

        $orientation = in_array($_POST['orientation'] ?? '', ['portrait', 'landscape'], true) ? $_POST['orientation'] : 'portrait';
        $cardWidth = max(300, min(2000, (int)($_POST['card_width'] ?? 600)));
        $cardHeight = max(300, min(2000, (int)($_POST['card_height'] ?? 950)));
        $bgPath = !empty($_POST['background_path']) ? trim($_POST['background_path']) : null;

        $saved = id_card_save_template(
            $pdo,
            $activeEventId,
            $teamId,
            $bgPath,
            $layoutConfig,
            $orientation,
            $cardWidth,
            $cardHeight
        );

        if (!$saved) {
            throw new Exception('Database error while saving ID card layout.');
        }

        echo json_encode([
            'success' => true,
            'message' => 'ID Card layout & positions saved successfully!'
        ]);
        exit;
    }

    if ($action === 'reset_layout') {
        $defaultConfig = id_card_default_layout();
        $existing = id_card_get_template($pdo, $activeEventId, $teamId);

        id_card_save_template(
            $pdo,
            $activeEventId,
            $teamId,
            $existing['background_image'] ?? null,
            $defaultConfig,
            'portrait',
            600,
            950
        );

        echo json_encode([
            'success' => true,
            'message' => 'Layout reset to default baseline successfully!',
            'layout_config' => $defaultConfig
        ]);
        exit;
    }

    throw new Exception('Unknown API action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
