<?php
declare(strict_types=1);

header('Content-Type: application/json');

$opcacheCleared = false;
if (function_exists('opcache_reset')) {
    $opcacheCleared = @opcache_reset();
}

$gitOutput = null;
try {
    $gitOutput = trim((string)@shell_exec('git pull origin main 2>&1'));
} catch (Throwable $e) {
    $gitOutput = $e->getMessage();
}

$commitHash = null;
try {
    $commitHash = trim((string)@shell_exec('git rev-parse --short HEAD 2>&1'));
} catch (Throwable $e) {}

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'commit' => $commitHash,
    'git_output' => $gitOutput,
    'opcache_cleared' => $opcacheCleared,
]);
