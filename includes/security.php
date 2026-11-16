<?php
/**
 * PharmaSys Hub - Enterprise Security & Defense-in-Depth Module
 * Level-4 Security Controls: CSP, CSRF, Rate Limiting, CORS, Input Sanitization, Secure Sessions
 */

if (!defined('PHARMASYS_SECURITY_INIT')) {
    define('PHARMASYS_SECURITY_INIT', true);
}

// Ensure secure logs directory exists
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

// ---------------------------------------------------------------------
// 1. Production Error & Debug Suppression
// ---------------------------------------------------------------------
$isDebug = defined('APP_DEBUG') && (APP_DEBUG === true || APP_DEBUG === 'true');
if (!$isDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php_errors.log');

// ---------------------------------------------------------------------
// 2. HTTP Security Headers
// ---------------------------------------------------------------------
function send_security_headers(): void {
    if (headers_sent()) {
        return;
    }

    // Prevent Clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME-type Sniffing
    header('X-Content-Type-Options: nosniff');

    // Cross-Site Scripting Filter
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Information Protection
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Restrict Hardware & Browser Feature Access
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

    // Content Security Policy (Allows internal styles, fonts, and trusted CDN Chart.js)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
           "font-src 'self' https://fonts.gstatic.com; " .
           "img-src 'self' data: https:; " .
           "connect-src 'self'; " .
           "frame-ancestors 'self'; " .
           "form-action 'self'; " .
           "base-uri 'self';";
    header("Content-Security-Policy: " . $csp);

    // Enforce HSTS if running over HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// Automatically send security headers on non-CLI invocation
if (php_sapi_name() !== 'cli') {
    send_security_headers();
}

// ---------------------------------------------------------------------
// 3. API CORS & Origin Protection
// ---------------------------------------------------------------------
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $tempDir = sys_get_temp_dir();
        if (is_dir($tempDir) && is_writable($tempDir)) {
            @ini_set('session.save_path', $tempDir);
        }
        @session_start();
    }
}

function handle_api_cors(): void {
    $allowedHosts = [
        '127.0.0.1:8000',
        'localhost:8000',
        '127.0.0.1',
        'localhost'
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($origin)) {
        $parsed = parse_url($origin);
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $hostWithPort = $host . $port;

        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $currentParsedHost = parse_url('http://' . $currentHost, PHP_URL_HOST);

        if (
            in_array($hostWithPort, $allowedHosts, true) ||
            in_array($host, $allowedHosts, true) ||
            ($currentParsedHost && $host === $currentParsedHost) ||
            (str_ends_with($host, '.vercel.app'))
        ) {
            header("Access-Control-Allow-Origin: " . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'));
            header("Access-Control-Allow-Credentials: true");
        }
    }

    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization, X-Requested-With");

    // Handle Preflight Requests
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ---------------------------------------------------------------------
// 4. Centralized CSRF Protection
// ---------------------------------------------------------------------
function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(?string $token = null): bool {
    start_secure_session();

    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) && !empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
            $input = json_decode(file_get_contents('php://input'), true);
            $token = $input['csrf_token'] ?? '';
        }
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($token) || empty($sessionToken)) {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function require_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!verify_csrf_token()) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Security Error: CSRF token verification failed. Please refresh the page and try again.'
                ]);
            } else {
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                  <meta charset="UTF-8">
                  <title>403 Forbidden &bull; CSRF Validation Failed</title>
                  <script>
                    (function() {
                      const savedTheme = localStorage.getItem('pharmasys_theme');
                      const theme = (savedTheme === 'light') ? 'light' : 'dark';
                      document.documentElement.setAttribute('data-theme', theme);
                    })();
                  </script>
                  <link rel="stylesheet" href="assets/css/style.css">
                </head>
                <body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-app);">
                  <div class="card" style="max-width: 500px; padding: 2.25rem; text-align: center;">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: var(--color-danger-bg); color: var(--color-danger); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.5rem;">
                      🛡️
                    </div>
                    <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem;">Security Token Invalid</h2>
                    <p style="font-size: 0.85rem; color: var(--text-subtle); margin-bottom: 1.5rem;">
                      Your form submission could not be verified due to an expired or missing cross-site request token.
                    </p>
                    <a href="javascript:history.back()" class="btn btn-primary">&larr; Return &amp; Try Again</a>
                  </div>
                </body>
                </html>
                <?php
            }
            exit;
        }
    }
}

// ---------------------------------------------------------------------
// 5. Rate Limiting Engine
// ---------------------------------------------------------------------
function check_rate_limit(string $actionKey, int $maxAttempts = 5, int $decaySeconds = 900): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $bucketKey = 'rate_' . md5($actionKey . '_' . $ip);
    $now = time();

    start_secure_session();

    if (!isset($_SESSION[$bucketKey])) {
        $_SESSION[$bucketKey] = [
            'attempts' => 1,
            'reset_time' => $now + $decaySeconds
        ];
        return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
    }

    $bucket = &$_SESSION[$bucketKey];

    // Reset if window has elapsed
    if ($now >= $bucket['reset_time']) {
        $bucket['attempts'] = 1;
        $bucket['reset_time'] = $now + $decaySeconds;
        return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
    }

    $bucket['attempts']++;

    if ($bucket['attempts'] > $maxAttempts) {
        $retryAfter = $bucket['reset_time'] - $now;
        return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
    }

    return ['allowed' => true, 'remaining' => $maxAttempts - $bucket['attempts'], 'retry_after' => 0];
}

function enforce_rate_limit(string $actionKey, int $maxAttempts = 5, int $decaySeconds = 900): void {
    $status = check_rate_limit($actionKey, $maxAttempts, $decaySeconds);
    if (!$status['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $status['retry_after']);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => "Too many requests. For security, please wait {$status['retry_after']} seconds before retrying."
            ]);
        } else {
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <title>429 Too Many Requests &bull; Rate Limit Lock</title>
              <script>
                (function() {
                  const savedTheme = localStorage.getItem('pharmasys_theme');
                  const theme = (savedTheme === 'light') ? 'light' : 'dark';
                  document.documentElement.setAttribute('data-theme', theme);
                })();
              </script>
              <link rel="stylesheet" href="assets/css/style.css">
            </head>
            <body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-app);">
              <div class="card" style="max-width: 480px; padding: 2.25rem; text-align: center;">
                <div style="font-size: 2rem; margin-bottom: 0.75rem;">⏱️</div>
                <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem;">Rate Limit Exceeded</h2>
                <p style="font-size: 0.85rem; color: var(--text-subtle); margin-bottom: 1.25rem;">
                  Too many attempts detected from your connection. Please wait <strong><?= $status['retry_after'] ?> seconds</strong> before trying again.
                </p>
                <a href="javascript:location.reload()" class="btn btn-primary">Refresh Page</a>
              </div>
            </body>
            </html>
            <?php
        }
        exit;
    }
}

// ---------------------------------------------------------------------
// 6. Input Sanitization & HTML Encoding Helpers
// ---------------------------------------------------------------------
function clean_string(?string $str): string {
    if ($str === null) return '';
    return trim(strip_tags($str));
}

function e(?string $str): string {
    if ($str === null) return '';
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function clean_int($val, int $default = 0): int {
    if (!is_numeric($val)) return $default;
    return (int)$val;
}

function clean_float($val, float $default = 0.0): float {
    if (!is_numeric($val)) return $default;
    return (float)$val;
}

// ---------------------------------------------------------------------
// 7. File Upload Validation Guard
// ---------------------------------------------------------------------
function validate_prescription_upload(array $file): array {
    $maxBytes = 5 * 1024 * 1024; // 5 MB

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['valid' => false, 'error' => 'Invalid file upload parameter.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'Upload error code: ' . $file['error']];
    }

    if ($file['size'] > $maxBytes) {
        return ['valid' => false, 'error' => 'Document exceeds maximum allowed size (5 MB limit).'];
    }

    $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!array_key_exists($mime, $allowedMimes)) {
        return ['valid' => false, 'error' => 'Disallowed file type. Only certified PDF and JPG/PNG/WebP images are accepted.'];
    }

    $ext = $allowedMimes[$mime];
    return ['valid' => true, 'extension' => $ext, 'mime' => $mime];
}

