<?php

// =============================================================================
// Xendit Configuration
// =============================================================================
// IMPORTANT: This file must NEVER be served directly to the browser.
//            Keep the secret key here only — never in JavaScript or HTML.
//
// Defaults below are TEST-MODE values for local development. Production
// overrides (secret key, webhook token, webhook + return URLs) come from
// config/local.php → 'xendit' section — see config/local.php.example.
// The webhook token placeholder means webhook verification is SKIPPED, so
// production MUST set a real token here before going live.
// =============================================================================

require_once __DIR__ . '/database.php'; // localConfig() overrides

$xenditConfig = array_merge([
    // -----------------------------------------------------------------------
    // Xendit TEST MODE Secret Key (starts with xnd_development_…)
    // -----------------------------------------------------------------------
    'secret_key' => 'xnd_development_ytE7wCPSnbmjfMz95F08GTkgyLMaHEbxw7L2BAUWpTCvWJBj0YyxGuetYs0PZAqk',

    // -----------------------------------------------------------------------
    // Webhook Verification Token (Xendit Dashboard → Settings → Webhooks).
    // The placeholder below disables verification — acceptable only locally.
    // -----------------------------------------------------------------------
    'webhook_token' => 'YOUR_XENDIT_WEBHOOK_VERIFICATION_TOKEN_HERE',

    // Public HTTPS URL Xendit will POST to (full URL, incl. /xendit.php).
    'webhook_url' => 'https://phosphate-upcountry-paprika.ngrok-free.dev/BreadBreak/webhook/xendit.php',

    // Success / failure return URLs after the customer completes payment.
    'return_success_url' => 'http://localhost/BreadBreak/payment-return.php?status=success',
    'return_failure_url' => 'http://localhost/BreadBreak/payment-return.php?status=cancel',
], localConfig('xendit'));

define('XENDIT_SECRET_KEY', $xenditConfig['secret_key']);
define('XENDIT_WEBHOOK_TOKEN', $xenditConfig['webhook_token']);
define('XENDIT_WEBHOOK_URL', $xenditConfig['webhook_url']);
define('XENDIT_SUCCESS_RETURN_URL', $xenditConfig['return_success_url']);
define('XENDIT_FAILURE_RETURN_URL', $xenditConfig['return_failure_url']);

// Xendit API base URL (v3 Payment Requests)
define('XENDIT_API_BASE', 'https://api.xendit.co');

// =============================================================================
// Helper: send a request to the Xendit API
// =============================================================================
// $method   – 'POST' | 'GET' | 'PATCH'
// $endpoint – e.g. '/v3/payment_requests'
// $payload  – associative array (will be JSON-encoded for POST/PATCH)
//
// Returns ['ok' => bool, 'status' => int, 'body' => array]
// =============================================================================
function xenditRequest(string $method, string $endpoint, array $payload = []): array
{
    $url  = XENDIT_API_BASE . $endpoint;
    $auth = base64_encode(XENDIT_SECRET_KEY . ':');

    $headers = [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json',
        'api-version: 2024-11-11',   // Required by Xendit v3 Payment Requests API
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response   = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'status' => 0, 'body' => ['error_code' => 'CURL_ERROR', 'message' => $curlError]];
    }

    $body = json_decode((string) $response, true) ?: [];
    $ok   = $httpStatus >= 200 && $httpStatus < 300;

    return ['ok' => $ok, 'status' => $httpStatus, 'body' => $body];
}

// =============================================================================
// Helper: generate a URL-safe unique reference ID for an order
// =============================================================================
function generateReferenceId(): string
{
    return 'BB-' . strtoupper(bin2hex(random_bytes(8)));
}

