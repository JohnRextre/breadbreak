<?php

// =============================================================================
// Xendit TEST MODE Configuration
// =============================================================================
// IMPORTANT: This file must NEVER be served directly to the browser.
//            Keep the secret key here only — never in JavaScript or HTML.
// =============================================================================

// -----------------------------------------------------------------------
// TODO: Paste your Xendit TEST MODE Secret Key below (starts with xnd_development_…)
// -----------------------------------------------------------------------
define('XENDIT_SECRET_KEY', 'xnd_development_ytE7wCPSnbmjfMz95F08GTkgyLMaHEbxw7L2BAUWpTCvWJBj0YyxGuetYs0PZAqk');

// -----------------------------------------------------------------------
// TODO: Set this to the token shown in your Xendit Dashboard → Webhooks.
//       Go to: Settings → Webhooks → copy the "Webhook Verification Token".
// -----------------------------------------------------------------------
define('XENDIT_WEBHOOK_TOKEN', 'YOUR_XENDIT_WEBHOOK_VERIFICATION_TOKEN_HERE');

// -----------------------------------------------------------------------
// Webhook URL — the public HTTPS URL that Xendit will POST to.
// -----------------------------------------------------------------------
define('XENDIT_WEBHOOK_URL', 'https://phosphate-upcountry-paprika.ngrok-free.dev/BreadBreak/webhook/xendit.php');

// Success / failure return URLs after the customer completes payment in Xendit.
define('XENDIT_SUCCESS_RETURN_URL', 'http://localhost/BreadBreak/payment-return.php?status=success');
define('XENDIT_FAILURE_RETURN_URL', 'http://localhost/BreadBreak/payment-return.php?status=cancel');

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

