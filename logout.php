<?php
session_start();

// Capture who is signing out before the session is wiped — the audit row is
// written after the cookie is cleared so sign-out never depends on the log.
$logoutUserId = (int) ($_SESSION['user_id'] ?? 0);
$logoutRole = (string) ($_SESSION['role'] ?? '');

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

if ($logoutUserId > 0) {
    try {
        require_once __DIR__ . '/includes/activity_log.php';
        require_once __DIR__ . '/config/database.php';
        logUserActivity(
            getDatabaseConnection(),
            $logoutUserId,
            'logout',
            $logoutRole !== '' ? ucfirst($logoutRole) . ' account' : null
        );
    } catch (Throwable) {
        // Best-effort audit — a failed log row must never block sign-out.
    }
}

header('Location: /BreadBreak/login.php?logged_out=1');
exit;
