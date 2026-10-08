<?php

require_once __DIR__ . '/database.php'; // localConfig() for the production override

const BASE_URL = '/BreadBreak';

/**
 * Absolute URL to a path inside BreadBreak — for links that leave the request
 * cycle (email verification and password-reset links).
 *
 * Priority: config/local.php app.base_url (production) → request host → CLI default.
 */
function appUrl(string $path = ''): string
{
    $override = trim((string) (localConfig('app')['base_url'] ?? ''));
    if ($override !== '') {
        return rtrim($override, '/') . '/' . ltrim($path, '/');
    }

    if (PHP_SAPI === 'cli') {
        return 'http://localhost/BreadBreak' . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return ($https ? 'https' : 'http') . '://' . $host . BASE_URL . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
