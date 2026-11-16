<?php
/**
 * PharmaSys Hub - Built-in Development & Production Server Router
 * Blocks exposed sensitive files, prevents direct database downloads, and enforces security headers.
 */

$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($rawUri, PHP_URL_PATH) ?: '/';

// If routing through the serverless entrypoint or root, default to index.php
if ($uri === '/api/index.php' || $uri === '/api/' || $uri === '/api' || $uri === '/router.php') {
    $uri = '/';
}

$file = __DIR__ . $uri;

// 1. Strict Blocklist: Forbidden Extensions & Sensitive Files
$blockedPatterns = [
    '/\.env/i',
    '/\.git/i',
    '/\.vscode/i',
    '/\.db$/i',
    '/\.sqlite$/i',
    '/\.sqlite3$/i',
    '/\.sql$/i',
    '/\.log$/i',
    '/\.md$/i',
    '/^(\/)?config\//i',
    '/^(\/)?database\//i',
    '/^(\/)?logs\//i',
    '/^(\/)?scripts\//i',
    '/^(\/)?uploads\/.*\.php/i'
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $uri)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden: Direct access to protected system assets is strictly blocked.";
        exit;
    }
}

// 2. Prevent Directory Traversal / Path Traversal Attacks
$realBase = realpath(__DIR__);
$realPath = realpath($file);

if ($realPath !== false && strpos($realPath, $realBase) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden: Path traversal attempt blocked.";
    exit;
}

// 3. Serve Static Assets Safely
$staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'ico'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

if ($ext && in_array($ext, $staticExtensions, true)) {
    if (file_exists($file) && is_file($file)) {
        // Safe MIME type mapping
        $mimes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'ico' => 'image/x-icon'
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        if ($ext === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        }
        readfile($file);
        exit;
    } else {
        http_response_code(404);
        echo "404 Not Found";
        exit;
    }
}

// 4. Default Document Handling
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    exit;
}

// 5. Execute PHP Scripts
if (file_exists($file) && is_file($file) && $ext === 'php') {
    require $file;
    exit;
}

// Check if appending .php matches an existing file
if (file_exists($file . '.php') && is_file($file . '.php')) {
    require $file . '.php';
    exit;
}

// Fallback: 404
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>404 Not Found &bull; PharmaSys Hub</title>
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
  <div class="card" style="max-width: 480px; padding: 2.5rem; text-align: center;">
    <h1 style="font-size: 2.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">404</h1>
    <h2 style="font-size: 1.15rem; color: var(--text-muted); margin-bottom: 1.25rem;">Requested Document Not Found</h2>
    <a href="index.php" class="btn btn-primary">&larr; Return to Workspace</a>
  </div>
</body>
</html>

