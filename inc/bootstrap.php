<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'School Manager');
define('APP_VERSION', '1.0.0');

require __DIR__ . '/config_loader.php';
$CONFIG = load_config();
if ($CONFIG === null) {
    header('Location: install/index.php');
    exit;
}
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Kolkata');

if (!empty($CONFIG['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
session_name('SCHOOLSESS');
session_start();

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/fees.php';
require __DIR__ . '/students.php';
require __DIR__ . '/lookups.php';

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $pdo = new PDO(db_dsn($CONFIG), $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function row(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r ?: null; }
function val(string $sql, array $params = []) { $v = q($sql, $params)->fetchColumn(); return $v === false ? null : $v; }
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}
function update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
    return q("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $whereParams))->rowCount();
}

set_exception_handler(function (Throwable $e) {
    global $CONFIG;
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if (!empty($CONFIG['debug'])) {
        echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        echo '<div style="font-family:sans-serif;padding:40px"><h2>Something went wrong</h2><p>Please go back and try again. If it keeps happening, contact the administrator.</p><a href="index.php">Home</a></div>';
    }
});

// Apply database upgrades automatically after a new version is uploaded.
run_migrations();
