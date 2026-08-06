<?php

require_once __DIR__ . '/env.php';

$DB_HOST = env('DB_HOST', '127.0.0.1');
$DB_USER = env('DB_USERNAME', 'root');
$DB_PASS = env('DB_PASSWORD', '');
$DB_CHARSET = env('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| MAIN DASHBOARD DATABASE
|--------------------------------------------------------------------------
*/

$dashboard_db_name = env('DB_DATABASE', 'kauzariyya');
if (!defined('DB_MAIN_NAME')) {
    define('DB_MAIN_NAME', $dashboard_db_name);
}
$musabaqa_db_name = env('MUSABAQA_DB_DATABASE', 'kauzariyya_musabaqa');
if (!defined('DB_MUSABAQA_NAME')) {
    define('DB_MUSABAQA_NAME', $musabaqa_db_name);
}

$dashboard_dsn =
"mysql:host={$DB_HOST};dbname={$dashboard_db_name};charset={$DB_CHARSET}";

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
        if (($host === 'localhost' || $host === '127.0.0.1') && str_contains($user, 'ensplpmy')) {
            $remoteDsn = str_replace(['host=localhost', 'host=127.0.0.1'], 'host=162.214.80.164', $dsn);
            try {
                return new PDO($remoteDsn, $user, $pass, $options);
            } catch (PDOException $remoteEx) {
                // fall through
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

$musabaqa_host = env('MUSABAQA_DB_HOST', $DB_HOST);
$musabaqa_user = env('MUSABAQA_DB_USERNAME', $DB_USER);
$musabaqa_pass = env('MUSABAQA_DB_PASSWORD', $DB_PASS);

$musabaqa_dsn =
"mysql:host={$musabaqa_host};dbname=" . env('MUSABAQA_DB_DATABASE', 'kauzariyya_musabaqa') . ";charset={$DB_CHARSET}";

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
