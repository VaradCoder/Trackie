<?php
/**
 * Trackie v1.0 — Database layer
 * Singleton PDO + thin query helpers.
 */

// Credentials may be pre-defined in config/env.php (preferred on shared
// hosts like InfinityFree where putenv() is disabled). Fall back to
// environment variables, then to local XAMPP defaults.
if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: 'sql108.infinityfree.com');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'if0_42120238_trackie');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'if0_42120238');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS')    ?: '5in27Pdbyf');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Sync MySQL's session timezone with PHP's (config/app.php) so that
        // CURDATE()/NOW() in SQL always agree with date('Y-m-d') in PHP. Without
        // this, daily todos/habits reset when the DB server's own day rolls over
        // instead of at the user's actual local midnight.
                try {
                    $pdo->exec("SET time_zone = '" . date('P') . "'");
                } catch (PDOException $e) {
                    error_log('Could not set MySQL session timezone: ' . $e->getMessage());
                }
                    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database unavailable.');
    }
    return $pdo;
}

function fetchAll(string $sql, array $p = []): array {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}

function fetchOne(string $sql, array $p = []): array|false {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetch();
}

function insert(string $sql, array $p = []): int|string {
    $pdo = db();
    $pdo->prepare($sql)->execute($p);
    return $pdo->lastInsertId();
}

function update(string $sql, array $p = []): int {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->rowCount();
}

function delete(string $sql, array $p = []): int {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->rowCount();
}
