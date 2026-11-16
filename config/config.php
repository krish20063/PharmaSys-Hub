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

// Database Credentials
define('DB_TYPE', env('DB_TYPE', 'auto')); // 'auto' tries MySQL first; falls back to live SQLite
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'pharmasys_hub'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_SQLITE_PATH', __DIR__ . '/../database/pharmasys_live.db');

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
