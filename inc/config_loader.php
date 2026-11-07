<?php
/**
 * Where the database settings come from (first match wins). Nothing secret is stored in the code.
 *
 *  1. Environment variables DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *     (same names as the GitHub Actions secrets).
 *  2. A config file OUTSIDE the web folder:  <one level above the app>/schoolfee-config.php
 *     e.g. /home/u123/domains/yourschool.in/schoolfee-config.php when the app is in public_html.
 *     This is where the installer saves it on Hostinger, so it can never be opened from a browser.
 *  3. config.php in the app folder (blocked from the web by .htaccess) – fallback only.
 */
function config_paths(): array
{
    $root = dirname(__DIR__);
    return ['outside' => dirname($root) . '/schoolfee-config.php', 'inside' => $root . '/config.php'];
}

function load_config(): ?array
{
    $env = fn(string $k) => ($v = getenv($k)) !== false && $v !== '' ? $v : ($_SERVER[$k] ?? null);
    if ($env('DB_DATABASE') && $env('DB_USERNAME')) {
        return [
            'db_host' => $env('DB_HOST') ?: 'localhost', 'db_port' => (int)($env('DB_PORT') ?: 3306),
            'db_name' => $env('DB_DATABASE'), 'db_user' => $env('DB_USERNAME'), 'db_pass' => (string)$env('DB_PASSWORD'),
            'timezone' => $env('APP_TIMEZONE') ?: 'Asia/Kolkata', 'debug' => $env('APP_DEBUG') === '1',
        ];
    }
    foreach (config_paths() as $f) {
        if (is_file($f)) {
            $c = require $f;
            if (is_array($c)) return $c + ['db_port' => 3306, 'timezone' => 'Asia/Kolkata', 'debug' => false];
        }
    }
    return null;
}

function db_dsn(array $c): string
{
    return 'mysql:host=' . $c['db_host'] . ';port=' . (int)($c['db_port'] ?? 3306) . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
}
