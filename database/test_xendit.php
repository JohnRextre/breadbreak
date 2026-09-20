<?php
// Quick live test: sends a real /v3/payment_requests call to Xendit TEST and prints the raw response.
// Run: php database/test_xendit.php
require_once __DIR__ . '/../config/xendit.php';

$testRef = 'BB-TEST-' . strtoupper(bin2hex(random_bytes(4)));

$payload = [
    'reference_id'   => $testRef,
    'type'           => 'PAY',
    'country'        => 'PH',
    'currency'       => 'PHP',
    'request_amount' => 15.00,
    'capture_method' => 'AUTOMATIC',
    'channel_code'   => 'GCASH',
    'channel_properties' => [
        'success_return_url' => 'http://localhost/BreadBreak/payment-return.php?status=success&ref=' . $testRef,
        'failure_return_url' => 'http://localhost/BreadBreak/payment-return.php?status=cancel&ref=' . $testRef,
        'cancel_return_url'  => 'http://localhost/BreadBreak/payment-return.php?status=cancel&ref=' . $testRef,
    ],
    'description' => 'BreadBreak test payment',
    'metadata'    => ['source' => 'BreadBreak_CLI_Test'],
];

echo "Sending to Xendit /v3/payment_requests...\n";
echo "reference_id: $testRef\n\n";

$result = xenditRequest('POST', '/v3/payment_requests', $payload);

echo "HTTP Status : " . $result['status'] . "\n";
echo "OK?         : " . ($result['ok'] ? 'YES' : 'NO') . "\n\n";
echo "Response body:\n";
echo json_encode($result['body'], JSON_PRETTY_PRINT) . "\n";

if ($result['ok']) {
    echo "\n=== SUCCESS ===\n";
    echo "Xendit Payment Request ID : " . ($result['body']['id'] ?? 'N/A') . "\n";
    echo "Status                    : " . ($result['body']['status'] ?? 'N/A') . "\n";
    foreach ($result['body']['actions'] ?? [] as $action) {
        echo "Action URL                : " . ($action['url'] ?? '') . "\n";
    }
} else {
    echo "\n=== FAILED ===\n";
    echo "Error code : " . ($result['body']['error_code'] ?? 'N/A') . "\n";
    echo "Message    : " . ($result['body']['message'] ?? 'N/A') . "\n";
}

