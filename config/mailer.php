<?php

/**
 * Mail transport settings.
 *
 * Default = file-outbox mode (smtp_host empty): every message is written to
 * storage/outbox/ instead of being sent, so development and automated tests
 * never need real credentials and never accidentally email anyone.
 *
 * Production override (config/local.php, gitignored):
 *
 *   return [
 *       'mail' => [
 *           'smtp_host'     => 'smtp.gmail.com',
 *           'smtp_port'     => 587,
 *           'smtp_username' => 'you@gmail.com',
 *           'smtp_password' => 'your Gmail app password',
 *           'from_address'  => 'you@gmail.com',   // optional; defaults to smtp_username
 *           'from_name'     => 'BreadBreak',
 *       ],
 *       // …db, app, xendit sections for the other configs
 *   ];
 */
require_once __DIR__ . '/database.php';

function mailConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = array_merge([
            'from_address' => '',
            'from_name' => 'BreadBreak',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
        ], localConfig('mail'));
    }

    return $config;
}
