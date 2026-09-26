<?php

/**
 * BreadBreak runs on Philippine time.
 *
 * php.ini on this machine shipped with date.timezone = Europe/Berlin (UTC+2) while
 * the MySQL server's system zone is UTC+8. That 6-hour skew silently broke anything
 * that compares PHP's clock to a database timestamp — payment-verification
 * throttles, "x minutes ago" labels, audit-trail ordering.
 *
 * Pinning BOTH sides here makes them agree regardless of what php.ini or the host
 * machine says. Asia/Manila and +08:00 share the same offset all year (the
 * Philippines has no daylight saving), so date() and NOW() return identical values.
 */
const APP_TIMEZONE = 'Asia/Manila';
const APP_UTC_OFFSET = '+08:00';

date_default_timezone_set(APP_TIMEZONE);

function getDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $database = getenv('DB_NAME') ?: 'breadbreak_db';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';

    $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (PDOException $exception) {
        throw new RuntimeException('Database connection failed: ' . $exception->getMessage());
    }

    // Pin the session zone so NOW()/CURRENT_TIMESTAMP are +08:00 no matter what the
    // MySQL host's system timezone is. This is what makes date() and NOW() comparable.
    try {
        $pdo->exec('SET time_zone = ' . $pdo->quote(APP_UTC_OFFSET));
    } catch (PDOException) {
        // A restricted account may not be allowed to set the session zone. The server
        // default is then used instead, which database/check-payments.php will report.
    }

    return $pdo;
}
