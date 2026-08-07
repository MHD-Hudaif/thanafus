<?php

require_once __DIR__ . '/env.php';

$DB_CHARSET = env('DB_CHARSET', 'utf8mb4');

// Auto-detect environment (localhost/Laragon vs. Production Bluehost)
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) 
               || str_ends_with($_SERVER['HTTP_HOST'] ?? '', '.local') 
               || env('APP_ENV') === 'development';

if ($isLocalhost) {
    // Localhost Development Credentials (using port 3307 to avoid service conflicts)
    $DB_HOST = '127.0.0.1;port=3307';
    $DB_USER = 'root';
    $DB_PASS = 'abd527-157';
    $dashboard_db_name = 'kauzariyya';
    $musabaqa_db_name = 'kauzariyya_musabaqa';
} else {
    // Production (Bluehost) Credentials from .env
    $DB_HOST = env('DB_HOST', 'localhost');
    $DB_USER = env('DB_USERNAME', 'ensplpmy_hudaif');
    $DB_PASS = env('DB_PASSWORD', 'abd527-157');
    $dashboard_db_name = env('DB_DATABASE', 'ensplpmy_kauzariyya_dashboard');
    $musabaqa_db_name = env('MUSABAQA_DB_DATABASE', 'ensplpmy_kauzariyya_musabaqa');
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

function create_pdo_connection(string $dsn, string $user, string $pass, string $host = ''): PDO {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'",
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // If local connection fails on localhost/127.0.0.1, try falling back to Bluehost remote DB
        if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')) {
            $remoteHost = '162.214.80.164';
            $remoteUser = 'ensplpmy_hudaif';
            $remotePass = 'abd527-157';
            
            // Swap to remote DSN and production database names
            $remoteDsn = preg_replace('/host=[^;]+/', 'host=' . $remoteHost, $dsn);
            $remoteDsn = preg_replace('/port=[^;]+;?/', '', $remoteDsn); // strip port to connect to default remote port 3306
            $remoteDsn = str_replace('dbname=kauzariyya_musabaqa', 'dbname=ensplpmy_kauzariyya_musabaqa', $remoteDsn);
            $remoteDsn = str_replace('dbname=kauzariyya', 'dbname=ensplpmy_kauzariyya_dashboard', $remoteDsn);
            
            try {
                return new PDO($remoteDsn, $remoteUser, $remotePass, $options);
            } catch (PDOException $remoteEx) {
                // If remote fallback fails, let it throw the original local exception
            }
        }
        throw $e;
    }
}

function get_dashboard_pdo(): PDO {
    global $dashboard_dsn, $DB_USER, $DB_PASS, $DB_HOST;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = create_pdo_connection($dashboard_dsn, $DB_USER, $DB_PASS, $DB_HOST);
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
    global $musabaqa_dsn, $musabaqa_user, $musabaqa_pass, $musabaqa_host;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = create_pdo_connection($musabaqa_dsn, $musabaqa_user, $musabaqa_pass, $musabaqa_host);
    }
    return $pdo;
}

$musabaqa_pdo = new LazyPDO(function() {
    return get_musabaqa_pdo();
});
