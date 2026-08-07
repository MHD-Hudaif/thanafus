<?php

require_once __DIR__ . '/env.php';

$DB_CHARSET = env('DB_CHARSET', 'utf8mb4');

// Auto-detect environment (localhost/Laragon vs. Production Bluehost)
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) 
               || str_ends_with($_SERVER['HTTP_HOST'] ?? '', '.local') 
               || env('APP_ENV') === 'development';

$localPort = 3307;
$isLocalMysqlRunning = false;
if ($isLocalhost) {
    // 150ms timeout is more than enough to test local socket
    $fp = @fsockopen('127.0.0.1', $localPort, $errno, $errstr, 0.15);
    if ($fp) {
        $isLocalMysqlRunning = true;
        fclose($fp);
    }
}

if ($isLocalhost && $isLocalMysqlRunning) {
    // Localhost Development Credentials (using port 3307 to avoid service conflicts)
    $DB_HOST = "127.0.0.1;port={$localPort}";
    $DB_USER = 'root';
    $DB_PASS = 'abd527-157';
    $dashboard_db_name = 'kauzariyya';
    $musabaqa_db_name = 'kauzariyya_musabaqa';
} else {
    // Production (Bluehost) or Localhost with MySQL down (fallback to Bluehost)
    if ($isLocalhost) {
        // Fallback connection to remote Bluehost database from local environment
        $DB_HOST = '162.214.80.164';
        $DB_USER = 'ensplpmy_hudaif';
        $DB_PASS = 'abd527-157';
        $dashboard_db_name = 'ensplpmy_kauzariyya_dashboard';
        $musabaqa_db_name = 'ensplpmy_kauzariyya_musabaqa';
    } else {
        // Actual Bluehost Production environment
        $DB_HOST = env('DB_HOST', 'localhost');
        $DB_USER = env('DB_USERNAME', 'ensplpmy_hudaif');
        $DB_PASS = env('DB_PASSWORD', 'abd527-157');
        $dashboard_db_name = env('DB_DATABASE', 'ensplpmy_kauzariyya_dashboard');
        $musabaqa_db_name = env('MUSABAQA_DB_DATABASE', 'ensplpmy_kauzariyya_musabaqa');
    }
}

/*
|--------------------------------------------------------------------------
| MAIN DASHBOARD DATABASE
|--------------------------------------------------------------------------
*/

if (!defined('DB_MAIN_NAME')) {
    define('DB_MAIN_NAME', $dashboard_db_name);
}
if (!defined('DB_MUSABAQA_NAME')) {
    define('DB_MUSABAQA_NAME', $musabaqa_db_name);
}

$dashboard_dsn = "mysql:host={$DB_HOST};dbname={$dashboard_db_name};charset={$DB_CHARSET}";

if (!class_exists('LazyPDO')) {
    class LazyPDO extends PDO {
        private ?PDO $pdo = null;
        private Closure $initializer;

        public function __construct(Closure $initializer) {
            $this->initializer = $initializer;
        }

        public function getRealPdo(): PDO {
            if ($this->pdo === null) {
                $this->pdo = ($this->initializer)();
            }
            return $this->pdo;
        }

        public function __call(string $name, array $arguments): mixed {
            return $this->getRealPdo()->$name(...$arguments);
        }

        public function prepare(string $query, array $options = []): PDOStatement|false {
            return $this->getRealPdo()->prepare($query, $options);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            if ($fetchMode === null) {
                return $this->getRealPdo()->query($query);
            }
            return $this->getRealPdo()->query($query, $fetchMode, ...$fetchModeArgs);
        }

        public function exec(string $statement): int|false {
            return $this->getRealPdo()->exec($statement);
        }

        public function lastInsertId(?string $name = null): string|false {
            return $this->getRealPdo()->lastInsertId($name);
        }

        public function beginTransaction(): bool {
            return $this->getRealPdo()->beginTransaction();
        }

        public function commit(): bool {
            return $this->getRealPdo()->commit();
        }

        public function rollBack(): bool {
            return $this->getRealPdo()->rollBack();
        }

        public function inTransaction(): bool {
            return $this->getRealPdo()->inTransaction();
        }

        public function setAttribute(int $attribute, mixed $value): bool {
            return $this->getRealPdo()->setAttribute($attribute, $value);
        }

        public function getAttribute(int $attribute): mixed {
            return $this->getRealPdo()->getAttribute($attribute);
        }

        public function errorCode(): ?string {
            return $this->getRealPdo()->errorCode();
        }

        public function errorInfo(): array {
            return $this->getRealPdo()->errorInfo();
        }

        public function quote(string $string, int $type = PDO::PARAM_STR): string|false {
            return $this->getRealPdo()->quote($string, $type);
        }
    }
}

function create_pdo_connection(string $dsn, string $user, string $pass): PDO {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'",
    ];

    return new PDO($dsn, $user, $pass, $options);
}

function get_dashboard_pdo(): PDO {
    global $dashboard_dsn, $DB_USER, $DB_PASS;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = create_pdo_connection($dashboard_dsn, $DB_USER, $DB_PASS);
    }
    return $pdo;
}

$dashboard_pdo = new LazyPDO(function() {
    return get_dashboard_pdo();
});



/*
|--------------------------------------------------------------------------
| MUSABAQA DATABASE
|--------------------------------------------------------------------------
*/

$musabaqa_host = $DB_HOST;
$musabaqa_user = $DB_USER;
$musabaqa_pass = $DB_PASS;

$musabaqa_dsn = "mysql:host={$musabaqa_host};dbname={$musabaqa_db_name};charset={$DB_CHARSET}";

function get_musabaqa_pdo(): PDO {
    global $musabaqa_dsn, $musabaqa_user, $musabaqa_pass;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = create_pdo_connection($musabaqa_dsn, $musabaqa_user, $musabaqa_pass);
    }
    return $pdo;
}

$musabaqa_pdo = new LazyPDO(function() {
    return get_musabaqa_pdo();
});
