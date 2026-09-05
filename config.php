<?php
/**
 * FamPay Payment Gateway - Central Configuration
 * Version : 2.0
 * Author  : @lazzy_guy (Telegram)
 *
 * Every sensitive value is read from environment variables (Render dashboard /
 * render.yaml). Sane defaults are provided for local development only.
 */

declare(strict_types=1);

if (defined('FAMPAY_CONFIG_LOADED')) {
    return;
}
define('FAMPAY_CONFIG_LOADED', true);

// ---------------------------------------------------------------------------
// Error handling: never leak stack traces to the client, always log them.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

/**
 * Read an environment variable with a fallback.
 */
function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            $value = (string) $_ENV[$key];
        } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            $value = (string) $_SERVER[$key];
        } else {
            return $default;
        }
    }
    return (string) $value;
}

// ---------------------------------------------------------------------------
// Load a local .env file when present (local development convenience).
// ---------------------------------------------------------------------------
(function (): void {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && $v[0] === substr($v, -1)) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})();

// ---------------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------------
define('APP_NAME', 'FamPay Payment Gateway');
define('APP_VERSION', '2.0');
define('APP_DEVELOPER', '@lazzy_guy');
define('APP_TELEGRAM', 'https://t.me/lazzy_guy');

define('ADMIN_PASSWORD', env_value('ADMIN_PASSWORD', 'kankan201028'));
define('ADMIN_PANEL_PATH', '/cpanel-admin-2025');

/** Secret used to encrypt Gmail app passwords at rest. */
define('APP_SECRET', env_value('APP_SECRET', 'fampay-' . ADMIN_PASSWORD . '-v2'));

define('ORDER_EXPIRY_MINUTES', (int) (env_value('ORDER_EXPIRY_MINUTES', '15')));
define('API_KEY_LENGTH', 6);
define('MIN_AMOUNT', 1.0);
define('MAX_AMOUNT', 100000.0);

define('QR_SIZE', (int) env_value('QR_SIZE', '400'));
define('QR_LOGO_SIZE', (int) env_value('QR_LOGO_SIZE', '80'));      // 20% of 400
define('QR_LOGO_BG_SIZE', (int) env_value('QR_LOGO_BG_SIZE', '100')); // white circle
define('QR_MERCHANT_NAME', env_value('QR_MERCHANT_NAME', 'FamPay'));

/** Rate limiting (per IP). */
define('RATE_LIMIT_REQUESTS', (int) env_value('RATE_LIMIT_REQUESTS', '60'));
define('RATE_LIMIT_WINDOW', (int) env_value('RATE_LIMIT_WINDOW', '60'));

/** Writable scratch directory (Render containers allow /tmp). */
define('APP_TMP_DIR', env_value('APP_TMP_DIR', sys_get_temp_dir() . '/fampay-gateway'));
if (!is_dir(APP_TMP_DIR)) {
    @mkdir(APP_TMP_DIR, 0775, true);
}

/**
 * Public base URL of the deployment. Auto-detected when APP_URL is not set.
 */
function app_url(): string
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    $configured = env_value('APP_URL');
    if ($configured) {
        return $url = rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-:_]/', '', (string) $host);
    return $url = ($https ? 'https://' : 'http://') . $host;
}

// ---------------------------------------------------------------------------
// Database (PostgreSQL on Render)
// ---------------------------------------------------------------------------

/**
 * Build the PDO DSN + credentials from either DATABASE_URL or DB_* variables.
 *
 * @return array{dsn:string,user:string,pass:string,name:string,host:string}
 */
function db_config(): array
{
    $databaseUrl = env_value('DATABASE_URL');
    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        if ($parts !== false && isset($parts['host'])) {
            $host = $parts['host'];
            $port = (string) ($parts['port'] ?? 5432);
            $name = ltrim($parts['path'] ?? '/postgres', '/');
            $user = urldecode($parts['user'] ?? 'postgres');
            $pass = urldecode($parts['pass'] ?? '');
            $sslmode = env_value('DB_SSLMODE', 'prefer');
            return [
                'dsn'  => "pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslmode",
                'user' => $user,
                'pass' => $pass,
                'name' => $name,
                'host' => $host,
            ];
        }
    }

    $host = env_value('DB_HOST', 'localhost');
    $port = env_value('DB_PORT', '5432');
    $name = env_value('DB_NAME', 'fampay');
    $user = env_value('DB_USER', 'fampay_user');
    $pass = env_value('DB_PASS', '');
    $sslmode = env_value('DB_SSLMODE', 'prefer');

    return [
        'dsn'  => "pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslmode",
        'user' => (string) $user,
        'pass' => (string) $pass,
        'name' => (string) $name,
        'host' => (string) $host,
    ];
}

/**
 * Shared PDO handle. Throws RuntimeException on failure (never fatals).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO pgsql driver is not installed on this server.');
    }

    $cfg = db_config();
    try {
        $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    } catch (PDOException $e) {
        error_log('[fampay] DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Check DB_* environment variables.');
    }

    // Align the PostgreSQL session time zone with PHP. Render databases run in
    // UTC; without this every naive CURRENT_TIMESTAMP would be misread by PHP
    // and orders would look expired the moment they are created.
    try {
        $tz = date_default_timezone_get();
        $stmt = $pdo->prepare('SET TIME ZONE ' . $pdo->quote($tz));
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[fampay] could not set DB time zone: ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * Idempotently create the schema (safe to call on every boot).
 * Returns true when the schema is present/created.
 */
function db_ensure_schema(bool $force = false): bool
{
    static $done = false;
    if ($done && !$force) {
        return true;
    }

    $pdo = db();
    $exists = $pdo->query("SELECT to_regclass('public.orders') IS NOT NULL AS ok")->fetch();
    if (!$force && $exists && ($exists['ok'] === true || $exists['ok'] === 't' || $exists['ok'] === 1)) {
        return $done = true;
    }

    $sqlFile = __DIR__ . '/migrations/001_initial_schema.sql';
    if (!is_file($sqlFile)) {
        throw new RuntimeException('Migration file migrations/001_initial_schema.sql is missing.');
    }
    $pdo->exec((string) file_get_contents($sqlFile));
    return $done = true;
}
