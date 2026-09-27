<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Shared connection factory. Requiring this file does not open a connection. */
function sagipbroDatabase(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $setting = static function (string $name, string $default): string {
        $value = getenv($name);
        return $value === false ? $default : $value;
    };
    $host = $setting('SAGIPBRO_DB_HOST', '127.0.0.1');
    $port = $setting('SAGIPBRO_DB_PORT', '3306');
    $database = $setting('SAGIPBRO_DB_NAME', 'sagipbro_db');
    if (!ctype_digit($port) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $database) || strpbrk($host, ";\r\n") !== false) {
        throw new RuntimeException('Invalid database configuration.');
    }
    $connection = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $setting('SAGIPBRO_DB_USER', 'root'),
        $setting('SAGIPBRO_DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false,
         PDO::ATTR_TIMEOUT => 5]
    );
    $connection->exec("SET time_zone = '+08:00'");
    $connection->exec('SET SESSION lock_wait_timeout = 5');
    $connection->exec('SET SESSION innodb_lock_wait_timeout = 5');
    if (stripos((string) $connection->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') !== false) {
        $connection->exec('SET SESSION max_statement_time = 5');
    } else {
        $connection->exec('SET SESSION max_execution_time = 5000');
    }
    return $connection;
}
