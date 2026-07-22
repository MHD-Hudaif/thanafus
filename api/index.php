<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/public-data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$resource = $_GET['resource'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    match ($resource) {
        'status' => respond(['ok' => true, 'database' => 'connected', 'time' => gmdate(DATE_ATOM)]),
        'teams', 'scoreboard' => respond(['data' => teams()]),
        'schedule', 'programs' => respond(['data' => schedule_items()]),
        'participants' => respond(['data' => participants(trim((string)($_GET['q'] ?? '')))]),
        'results' => respond(['data' => result_items()]),
        default => respond(['error' => 'Unknown resource'], 404),
    };
}

header('Allow: GET');
respond(['error' => 'Method not allowed'], 405);
