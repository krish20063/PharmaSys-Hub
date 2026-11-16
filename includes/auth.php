<?php
/**
 * PharmaSys Hub - Role-Based Access Control (RBAC) & Session Guard
 * Strictly forbids unauthorized cross-role access and protects all dashboard telemetry.
 */
require_once __DIR__ . '/../config/config.php';

function is_logged_in(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('start_secure_session')) {
            start_secure_session();
        } else {
            @session_start();
        }
    }
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? 'User',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $_SESSION['user_role'],
        'license_no' => $_SESSION['user_license'] ?? '',
        'phone' => $_SESSION['user_phone'] ?? ''
    ];
}

function require_auth(): void {
    if (!is_logged_in()) {
        $currentUri = $_SERVER['REQUEST_URI'];
        header('Location: login.php?redirect=' . urlencode($currentUri) . '&error=auth_required');
        exit;
    }
}

function require_login(): void {
    require_auth();
}

/**
 * Enforces role restriction.
 * If user does not have an allowed role, execution stops immediately with a 403 page.
 *
 * @param array|string $allowedRoles Single role string or array of allowed roles
 */
function require_role($allowedRoles): void {
    require_auth();

    if (is_string($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $userRole = $_SESSION['user_role'] ?? '';

    if (!in_array($userRole, $allowedRoles, true)) {
        http_response_code(403);
        
        $roleHomes = [
            'manufacturer' => 'manufacturer.php',
            'distributor' => 'distributor.php',
            'retailer' => 'retailer_pos.php',
            'consumer' => 'consumer.php'
        ];
        $myDashboard = $roleHomes[$userRole] ?? 'index.php';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <title>403 Forbidden &bull; Role Authorization Conflict</title>
          <link rel="stylesheet" href="assets/css/style.css">
        </head>
        <body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #F8FAFC;">
          <div class="card" style="max-width: 540px; padding: 2.5rem; text-align: center;">
            <div style="width: 54px; height: 54px; border-radius: 12px; background: #FEE2E2; color: #DC2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
            </div>
            <h2 style="font-size: 1.4rem; font-weight: 700; color: #0F172A; margin-bottom: 0.5rem;">Access Denied (403 Forbidden)</h2>
            <p style="font-size: 0.9rem; color: #64748B; margin-bottom: 1.5rem; line-height: 1.6;">
              You are authenticated as <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></strong> with the role <span class="badge badge-primary"><?= ucfirst(htmlspecialchars($userRole)) ?></span>.<br>
              Network safety policies strictly prevent users from viewing or modifying other participants' operational dashboards.
            </p>
            <div style="display: flex; gap: 0.75rem; justify-content: center;">
              <a href="<?= $myDashboard ?>" class="btn btn-primary">&larr; Return to My Workspace</a>
              <a href="logout.php" class="btn btn-outline">Sign Out</a>
            </div>
          </div>
        </body>
        </html>
        <?php
        exit;
    }
}

