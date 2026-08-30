<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function redirectTo($path)
{
    header('Location: ' . $path);
    exit;
}

function requireLogin()
{
    if (!isset($_SESSION['user_id'])) {
        redirectTo('/BreadBreak/login.php');
    }
}

function requireRole($requiredRole)
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== $requiredRole) {
        redirectTo('/BreadBreak/login.php');
    }
}
