<?php

if (!function_exists('app_normalize_base_url')) {
    function app_normalize_base_url(?string $baseUrl): string
    {
        $baseUrl = trim((string)$baseUrl);

        if ($baseUrl === '' || $baseUrl === '/') {
            return '';
        }

        $baseUrl = str_replace('\\', '/', $baseUrl);

        if (preg_match('#^https?://#i', $baseUrl)) {
            $parts = parse_url($baseUrl);

            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                return '';
            }

            $scheme = strtolower((string)$parts['scheme']);
            $host = (string)$parts['host'];
            $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
            $path = isset($parts['path']) ? '/' . trim((string)$parts['path'], '/') : '';

            return rtrim($scheme . '://' . $host . $port . $path, '/');
        }

        return '/' . trim($baseUrl, '/');
    }
}

if (!function_exists('app_env')) {
    function app_env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string)$value;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $path = trim($path);

        if (
            $path !== ''
            && preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|#)#i', $path)
        ) {
            return $path;
        }

        $base = defined('APP_BASE_URL') ? APP_BASE_URL : (defined('APP_URL') ? APP_URL : '');
        $base = rtrim((string)$base, '/');

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }
}

if (!function_exists('app_absolute_url')) {
    function app_absolute_url(string $path = ''): string
    {
        $url = app_url($path);

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . $url;
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path = ''): string
    {
        $path = trim($path, '/');

        return app_url('/assets' . ($path === '' ? '' : '/' . $path));
    }
}

if (!function_exists('tv_asset_url')) {
    function tv_asset_url(string $path = ''): string
    {
        $path = trim($path, '/');

        return app_url('/tv/assets' . ($path === '' ? '' : '/' . $path));
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        $root = defined('APP_PUBLIC_PATH') ? APP_PUBLIC_PATH : app_path();
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('asset_path')) {
    function asset_path(string $path = ''): string
    {
        return public_path('assets' . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path));
    }
}

if (!function_exists('app_cookie_path')) {
    function app_cookie_path(): string
    {
        $base = defined('APP_BASE_URL') ? APP_BASE_URL : (defined('APP_URL') ? APP_URL : '');
        $path = parse_url((string)$base, PHP_URL_PATH);

        if (!is_string($path) || trim($path, '/') === '') {
            return '/';
        }

        return '/' . trim($path, '/');
    }
}
