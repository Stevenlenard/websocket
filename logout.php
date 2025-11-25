<?php
// logout.php - clears session and redirects to unified login page
require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// unset role-specific ids
unset($_SESSION['admin_id'], $_SESSION['janitor_id'], $_SESSION['email'], $_SESSION['role']);

// destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"] ?? false, $params["httponly"] ?? false
    );
}
session_destroy();

header('Location: login.php');
exit;