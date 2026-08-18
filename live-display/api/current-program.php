<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

// ── Server-side file cache (8 s) ────────────────────────────────────────────
// Multiple screens polling simultaneously will all share the same DB result
// within each 8-second window, reducing PHP processes & DB load on Bluehost.
$cacheFile = sys_get_temp_dir() . '/musabaqa_current_program_cache.json';
$cacheTtl  = 8; // seconds

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false) {
        $etag = '"' . md5($cached) . '"';
        header('Content-Type: application/json; charset=utf-8');
        header('ETag: ' . $etag);
        header('Cache-Control: private, must-revalidate, max-age=0');
        $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($clientEtag === $etag) {
            http_response_code(304);
            exit;
        }
        http_response_code(200);
        echo $cached;
        exit;
    }
}
// ─────────────────────────────────────────────────────────────────────────────

try {
    $payload = [
        'event'       => tv_event_payload(tv_active_event()),
        'current'     => tv_current_program(),
        'leaderboard' => tv_leaderboard(),
        'settings'    => tv_get_settings(),
    ];

    // Write to cache before responding
    $json = json_encode(['success' => true, 'data' => $payload, 'timestamp' => time()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($cacheFile, $json, LOCK_EX);

    tv_json_success($payload);
} catch (Throwable $exception) {
    tv_log($exception, 'TV current program API');
    tv_json_error('Current program is temporarily unavailable.');
}
