<?php
/**
 * Checks that the configured public webhook URL actually reaches the app.
 * The URL is read from config/xendit.php so there is a single source of truth —
 * a hardcoded copy here goes stale the moment the ngrok URL changes.
 *
 * Prefer: php database/payment-health.php   (covers this plus much more)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../config/xendit.php';

$url = defined('XENDIT_WEBHOOK_URL') ? (string) XENDIT_WEBHOOK_URL : '';

if ($url === '') {
    echo "XENDIT_WEBHOOK_URL is not defined in config/xendit.php\n";
    exit(1);
}

echo "Target URL   : $url\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP Status  : " . ($status ?: 'none') . ($err ? "  (curl: $err)" : '') . "\n";

// The endpoint only accepts POST, so any answer that is not a routing error means
// the tunnel is alive and the path resolved.
if (in_array($status, [200, 401, 403, 405, 500], true)) {
    echo "SUCCESS: the tunnel is alive and /webhook/xendit.php is reachable.\n";
    echo "         Xendit should be able to deliver callbacks.\n";
    exit(0);
}

echo "FAIL: Xendit cannot reach this URL (HTTP $status).\n";
echo "      The tunnel is down or the URL is stale. If you restarted ngrok the\n";
echo "      random subdomain changed — update XENDIT_WEBHOOK_URL in config/xendit.php\n";
echo "      AND the webhook URL in the Xendit dashboard.\n";
exit(1);
