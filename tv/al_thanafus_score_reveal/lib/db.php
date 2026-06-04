<?php
declare(strict_types=1);

function reveal_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'db' => [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'name' => getenv('DB_NAME') ?: 'kauzariyya_musabaqa',
            'user' => getenv('DB_USER') ?: 'root',
            'pass' => getenv('DB_PASS') ?: '',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ],
        'stream' => [
            'poll_interval' => max(1, (int) (getenv('STREAM_POLL_INTERVAL') ?: 2)),
            'batch_gap_seconds' => max(1, (int) (getenv('STREAM_BATCH_GAP') ?: 5)),
        ],
    ];

    return $config;
}

function reveal_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = reveal_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['charset']}"
    ]);

    return $pdo;
}

function reveal_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = reveal_pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function reveal_fetch_all(string $sql, array $params = []): array
{
    $stmt = reveal_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function reveal_fetch_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $stmt = reveal_pdo()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function reveal_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reveal_number(mixed $value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        $value = 0;
    }
    return number_format((float) $value, $decimals, '.', ',');
}

function reveal_safe_hex(?string $color, string $fallback = '#10b981'): string
{
    $color = trim((string) $color);
    if ($color === '') {
        return $fallback;
    }

    if (preg_match('/^#?[0-9a-fA-F]{3}$/', $color) || preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) {
        return $color[0] === '#' ? $color : '#'.$color;
    }

    return $fallback;
}

function reveal_best_event(): ?array
{
    $active = reveal_fetch_one("SELECT * FROM musabaqa_events WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    if ($active) {
        return $active;
    }

    return reveal_fetch_one("SELECT * FROM musabaqa_events ORDER BY FIELD(status,'draft','completed') ASC, id DESC LIMIT 1");
}
