<?php
/**
 * PharmaSys Hub - Session Termination
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (!empty($_SESSION['user_id'])) {
    Database::logAudit('AUTH_LOGOUT', 'USER', (string)$_SESSION['user_id'], "User signed out.");
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header('Location: login.php?msg=logged_out');
exit;
