<?php
// ============================================================
// webhook/test.php  –  Local webhook simulation tool
// ============================================================
// Use this to test the webhook WITHOUT ngrok.
// It simulates what Xendit would POST to your webhook endpoint.
//
// USAGE (from browser):
//   http://localhost/BreadBreak/webhook/test.php?ref=BB-XXXX&status=SUCCEEDED
//
// USAGE (from CLI):
//   php webhook/test.php BB-XXXXXXXXXXXX SUCCEEDED
// ============================================================

// Only allow from localhost for safety
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isCli    = PHP_SAPI === 'cli';
if (!$isCli && !in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('This tool is only accessible from localhost.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/xendit.php';

// Get parameters
if ($isCli) {
    $referenceId     = $argv[1] ?? '';
    $simulatedStatus = strtoupper($argv[2] ?? 'SUCCEEDED');
} else {
    $referenceId     = trim($_GET['ref'] ?? '');
    $simulatedStatus = strtoupper(trim($_GET['status'] ?? 'SUCCEEDED'));
}

if (!$referenceId) {
    die("Usage:\n  Browser: ?ref=BB-XXXX&status=SUCCEEDED\n  CLI:     php webhook/test.php BB-XXXX SUCCEEDED\n\nValid statuses: SUCCEEDED, FAILED, EXPIRED\n");
}

// Look up the payment record for this reference
$pdo  = getDatabaseConnection();
$pStmt = $pdo->prepare("SELECT * FROM payments WHERE reference_id = :ref ORDER BY id DESC LIMIT 1");
$pStmt->execute(['ref' => $referenceId]);
$payment = $pStmt->fetch();

if (!$payment) {
    die("No payment found with reference_id = '$referenceId'\n");
}

echo "=== BreadBreak Webhook Simulator ===\n\n";
echo "Reference ID              : $referenceId\n";
echo "Xendit Payment Request ID : " . $payment['xendit_payment_request_id'] . "\n";
echo "Current DB Status         : " . $payment['status'] . "\n";
echo "Simulating Xendit status  : $simulatedStatus\n\n";

// Build a simulated Xendit webhook payload
$simulatedPayload = [
    'payment_request_id' => $payment['xendit_payment_request_id'],
    'reference_id'       => $referenceId,
    'status'             => $simulatedStatus,
    'currency'           => 'PHP',
    'channel_code'       => 'GCASH',
    'created'            => date('c'),
    'updated'            => date('c'),
    'metadata'           => ['source' => 'BreadBreak_LocalTest'],
];

// POST the simulated payload to our own webhook endpoint using cURL
$webhookUrl   = 'http://localhost/BreadBreak/webhook/xendit.php';
$payloadJson  = json_encode($simulatedPayload);

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-callback-token: ' . XENDIT_WEBHOOK_TOKEN,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response   = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

echo "--- Webhook POST to: $webhookUrl ---\n";
if ($curlError) {
    echo "cURL error: $curlError\n";
} else {
    echo "HTTP Status : $httpStatus\n";
    echo "Response    : $response\n";
}

echo "\n--- Current DB State After Webhook ---\n";
$pStmt->execute(['ref' => $referenceId]);
$updated = $pStmt->fetch();
echo "Payment Status : " . ($updated['status'] ?? 'N/A') . "\n";

$oStmt = $pdo->prepare("SELECT status FROM orders WHERE id = :oid LIMIT 1");
$oStmt->execute(['oid' => $updated['order_id'] ?? 0]);
$order = $oStmt->fetch();
echo "Order Status   : " . ($order['status'] ?? 'N/A') . "\n";

