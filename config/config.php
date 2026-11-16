<?php
/**
 * PharmaSys Hub - Application Configuration & Environment Isolation
 * Conforms to Level-4 CDSCO & ISO/IEC 27001 Security Standards.
 */

// ---------------------------------------------------------------------
// 1. Lightweight .env Parser
// ---------------------------------------------------------------------
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            // Strip outer quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!isset($_SERVER[$key]) && !isset($_ENV[$key])) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

function env(string $key, mixed $default = null): mixed {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    if ($val === 'true' || $val === '(true)') return true;
    if ($val === 'false' || $val === '(false)') return false;
    if ($val === 'null' || $val === '(null)') return null;
    return $val;
}

// ---------------------------------------------------------------------
// 2. Hardened Session Security Configuration
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// ---------------------------------------------------------------------
// 3. Application Constants
// ---------------------------------------------------------------------
define('APP_NAME', 'PharmaSys Hub');
define('APP_TAGLINE', 'Enterprise Pharmaceutical Supply Chain Operating System');
define('APP_VERSION', '2.0.0-PROD');
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', env('APP_DEBUG', false));
define('APP_SECRET', env('APP_SECRET', 'pharmasys_secret_fallback_key_2026'));
define('APP_URL', env('APP_URL', 'http://localhost:8000'));

// Database Credentials & Cloud URL Support
$dbUrl = env('DATABASE_URL') ?: env('MYSQL_URL') ?: getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
if ($dbUrl) {
    $parsedUrl = parse_url($dbUrl);
    $cfgDbType = 'mysql';
    $cfgDbHost = $parsedUrl['host'] ?? '127.0.0.1';
    $cfgDbPort = (string)($parsedUrl['port'] ?? '3306');
    $cfgDbUser = $parsedUrl['user'] ?? 'root';
    $cfgDbPass = $parsedUrl['pass'] ?? '';
    $cfgDbName = ltrim($parsedUrl['path'] ?? 'pharmasys_hub', '/');
} else {
    $cfgDbType = env('DB_TYPE', 'auto');
    $cfgDbHost = env('DB_HOST', '127.0.0.1');
    $cfgDbPort = (string)env('DB_PORT', '3306');
    $cfgDbUser = env('DB_USER', 'root');
    $cfgDbPass = env('DB_PASS', '');
    $cfgDbName = env('DB_NAME', 'pharmasys_hub');
}

define('DB_TYPE', $cfgDbType); // 'mysql', 'sqlite', or 'auto' (tries MySQL first; falls back to live SQLite)
define('DB_HOST', $cfgDbHost);
define('DB_PORT', $cfgDbPort);
define('DB_NAME', $cfgDbName);
define('DB_USER', $cfgDbUser);
define('DB_PASS', $cfgDbPass);

// Serverless / Vercel SQLite persistence path handling
$isServerless = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || getenv('VERCEL') || defined('VERCEL_ENVIRONMENT');
$defaultSqlite = __DIR__ . '/../database/pharmasys_live.db';
if ($isServerless) {
    $tmpSqlite = sys_get_temp_dir() . '/pharmasys_live.db';
    if (!file_exists($tmpSqlite) && file_exists($defaultSqlite)) {
        @copy($defaultSqlite, $tmpSqlite);
    }
    define('DB_SQLITE_PATH', env('DB_SQLITE_PATH', $tmpSqlite));
} else {
    define('DB_SQLITE_PATH', env('DB_SQLITE_PATH', $defaultSqlite));
}

// Meta WhatsApp Cloud API Credentials
define('WHATSAPP_API_TOKEN', env('WHATSAPP_API_TOKEN', 'EAAG_MOCK_META_TOKEN_PHARMASYS_DEMO'));
define('WHATSAPP_PHONE_NUMBER_ID', env('WHATSAPP_PHONE_NUMBER_ID', '109876543210987'));
define('WHATSAPP_BUSINESS_ACCOUNT_ID', env('WHATSAPP_BUSINESS_ACCOUNT_ID', '209876543210987'));
define('WHATSAPP_API_VERSION', env('WHATSAPP_API_VERSION', 'v19.0'));

// GST & Operational Defaults
define('DEFAULT_GST_RATE', 0.12); // 12% Indian Pharma GST (6% CGST + 6% SGST)
define('DEFAULT_RESTOCK_THRESHOLD', 10); // Units at or below which auto-restock triggers (stock <= 10)

// Load Security Layer
require_once __DIR__ . '/../includes/security.php';
