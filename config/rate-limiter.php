<?php
/**
 * Rate Limiter Utility
 *
 * Simple file-based rate limiter for login attempts and API requests.
 * In production, consider using Redis or a database for distributed systems.
 */

if (!function_exists('rate_limit_key')) {
    function rate_limit_key(string $identifier): string
    {
        $prefix = env('RATE_LIMIT_PREFIX', 'ratelimit_');
        $dir = sys_get_temp_dir() . '/' . $prefix;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safeId = preg_replace('/[^a-zA-Z0-9_:.-]/', '_', $identifier);
        return $dir . '/' . hash('sha256', $safeId) . '.json';
    }
}

if (!function_exists('rate_limit_read')) {
    function rate_limit_read(string $identifier): array
    {
        $file = rate_limit_key($identifier);
        if (!file_exists($file)) {
            return ['hits' => 0, 'window_start' => time()];
        }
        $data = @json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : ['hits' => 0, 'window_start' => time()];
    }
}

if (!function_exists('rate_limit_write')) {
    function rate_limit_write(string $identifier, array $data): bool
    {
        $file = rate_limit_key($identifier);
        $tmp = $file . '.tmp';
        $result = file_put_contents($tmp, json_encode($data));
        if ($result === false) {
            return false;
        }
        return rename($tmp, $file);
    }
}

if (!function_exists('rate_limit_check')) {
    function rate_limit_check(string $identifier, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();
        $data = rate_limit_read($identifier);

        // Reset window if expired
        if ($now - ($data['window_start'] ?? $now) >= $windowSeconds) {
            $data = ['hits' => 0, 'window_start' => $now];
        }

        $remaining = max(0, $maxAttempts - ($data['hits'] ?? 0));
        $retryAfter = $remaining === 0
            ? $windowSeconds - ($now - ($data['window_start'] ?? $now))
            : 0;

        return [
            'allowed' => $remaining > 0,
            'remaining' => $remaining,
            'retry_after' => max(1, $retryAfter),
            'limit' => $maxAttempts,
            'window' => $windowSeconds,
        ];
    }
}

if (!function_exists('rate_limit_hit')) {
    function rate_limit_hit(string $identifier, int $maxAttempts, int $windowSeconds): void
    {
        $now = time();
        $data = rate_limit_read($identifier);

        // Reset window if expired
        if ($now - ($data['window_start'] ?? $now) >= $windowSeconds) {
            $data = ['hits' => 0, 'window_start' => $now];
        }

        $data['hits'] = ($data['hits'] ?? 0) + 1;
        rate_limit_write($identifier, $data);
    }
}

if (!function_exists('rate_limit_clear')) {
    function rate_limit_clear(string $identifier): void
    {
        $file = rate_limit_key($identifier);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('rate_limit_cleanup')) {
    function rate_limit_cleanup(int $maxAge = 3600): void
    {
        $prefix = env('RATE_LIMIT_PREFIX', 'ratelimit_');
        $dir = sys_get_temp_dir() . '/' . $prefix;
        if (!is_dir($dir)) {
            return;
        }

        $now = time();
        foreach (glob($dir . '/*.json') as $file) {
            if ($now - filemtime($file) > $maxAge) {
                @unlink($file);
            }
        }
    }
}

// Periodic cleanup (1% chance per request)
if (mt_rand(1, 100) === 1) {
    rate_limit_cleanup();
}