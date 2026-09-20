<?php
// ============================================================
// database/verify_idempotency.php
// Full verification of Webhook Idempotency and Stock Deductions
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/xendit.php';

$pdo = getDatabaseConnection();

echo "====================================================\n";
echo " TEST 1: Verify Real Order (BB-4CC9EDBA85E370EA)\n";
echo "====================================================\n";

$realRef = 'BB-4CC9EDBA85E370EA';

// 1. Fetch payment and order
$pStmt = $pdo->prepare("SELECT * FROM payments WHERE reference_id = :ref");
$pStmt->execute(['ref' => $realRef]);
$payments = $pStmt->fetchAll();

$oStmt = $pdo->prepare("SELECT o.*, oi.variant_id, oi.quantity AS order_qty, v.quantity AS current_stock, v.availability
                        FROM orders o
                        JOIN order_items oi ON oi.order_id = o.id
                        JOIN inventory_item_variants v ON v.id = oi.variant_id
                        WHERE o.reference_id = :ref");
$oStmt->execute(['ref' => $realRef]);
$orderItems = $oStmt->fetchAll();

echo "Payments Count       : " . count($payments) . " (Expected: 1)\n";
echo "Payment Status       : " . ($payments[0]['status'] ?? 'N/A') . " (Expected: paid)\n";
echo "Order Status         : " . ($orderItems[0]['status'] ?? 'N/A') . " (Expected: processing)\n";
echo "Variant ID           : " . ($orderItems[0]['variant_id'] ?? 'N/A') . "\n";
echo "Ordered Quantity     : " . ($orderItems[0]['order_qty'] ?? 'N/A') . "\n";
echo "Current Variant Stock: " . ($orderItems[0]['current_stock'] ?? 'N/A') . "\n\n";

$stockBeforeDuplicate = (int) $orderItems[0]['current_stock'];

echo "--- Simulating Duplicate Webhook POST via HTTP ---\n";

$duplicatePayload = [
    'event' => 'payment.capture',
    'data' => [
        'payment_id'         => 'py-cf564763-4db4-4d35-a69e-c5d38922b594',
        'payment_request_id' => $payments[0]['xendit_payment_request_id'],
        'reference_id'       => $realRef,
        'status'             => 'SUCCEEDED',
        'request_amount'     => 700,
        'channel_code'       => 'GCASH'
    ]
];

// Send duplicate webhook via cURL to local endpoint
$ch = curl_init('http://localhost/BreadBreak/webhook/xendit.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($duplicatePayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-callback-token: ' . XENDIT_WEBHOOK_TOKEN
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code Returned   : $httpCode (Expected: 200)\n";
echo "Response Body        : $res\n";

// Re-fetch stock and statuses
$pStmt->execute(['ref' => $realRef]);
$paymentsAfter = $pStmt->fetchAll();
$oStmt->execute(['ref' => $realRef]);
$orderItemsAfter = $oStmt->fetchAll();
$stockAfterDuplicate = (int) $orderItemsAfter[0]['current_stock'];

echo "Payment Count After  : " . count($paymentsAfter) . " (Expected: 1)\n";
echo "Payment Status After : " . ($paymentsAfter[0]['status'] ?? 'N/A') . " (Expected: paid)\n";
echo "Order Status After   : " . ($orderItemsAfter[0]['status'] ?? 'N/A') . " (Expected: processing)\n";
echo "Stock Before & After : Before=$stockBeforeDuplicate, After=$stockAfterDuplicate\n";

if ($stockBeforeDuplicate === $stockAfterDuplicate) {
    echo ">>> PASS: Stock was NOT deducted again on duplicate webhook! <<<\n\n";
} else {
    echo ">>> FAIL: Stock changed! <<<\n\n";
}

echo "====================================================\n";
echo " TEST 2: Brand New Order End-to-End Idempotency Test\n";
echo "====================================================\n";

// Pick a test variant (e.g., variant 1)
$vStmt = $pdo->query("SELECT id, quantity, availability FROM inventory_item_variants WHERE availability = 'available' LIMIT 1");
$testVariant = $vStmt->fetch();
$vid = (int) $testVariant['id'];
$initialStock = (int) $testVariant['quantity'];

echo "Testing with Variant ID #$vid (Initial Stock: $initialStock)\n";

// 1. Create a simulated order
$testRefId = 'BB-TEST-IDEM-' . strtoupper(bin2hex(random_bytes(3)));
$testXenditId = 'pr-test-' . bin2hex(random_bytes(6));

$pdo->beginTransaction();
$pdo->prepare("INSERT INTO orders (customer_id, reference_id, status) VALUES (1, :ref, 'pending')")->execute(['ref' => $testRefId]);
$testOrderId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO order_items (order_id, variant_id, product_name, service_size, sku, unit_price, quantity, line_total)
               VALUES (:oid, :vid, 'Test Product', 'Solo', 'TEST-SKU', 50.00, 2, 100.00)")
    ->execute(['oid' => $testOrderId, 'vid' => $vid]);

$pdo->prepare("INSERT INTO payments (order_id, xendit_payment_request_id, reference_id, amount, currency, payment_method, payment_channel, status)
               VALUES (:oid, :xid, :ref, 100.00, 'PHP', 'EWALLET', 'GCASH', 'pending')")
    ->execute(['oid' => $testOrderId, 'xid' => $testXenditId, 'ref' => $testRefId]);
$pdo->commit();

echo "Created Pending Order: $testRefId (Ordered Qty: 2)\n";

$testPayload = [
    'event' => 'payment.capture',
    'data' => [
        'payment_id'         => 'py-test-' . bin2hex(random_bytes(4)),
        'payment_request_id' => $testXenditId,
        'reference_id'       => $testRefId,
        'status'             => 'SUCCEEDED',
        'request_amount'     => 100.00,
        'channel_code'       => 'GCASH'
    ]
];

// First Webhook Delivery (Initial Payment Success)
$ch = curl_init('http://localhost/BreadBreak/webhook/xendit.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-callback-token: ' . XENDIT_WEBHOOK_TOKEN]);
$res1 = curl_exec($ch);
curl_close($ch);

$vStmt = $pdo->prepare("SELECT quantity FROM inventory_item_variants WHERE id = :vid");
$vStmt->execute(['vid' => $vid]);
$stockAfterDelivery1 = (int) $vStmt->fetchColumn();

$pCheck = $pdo->prepare("SELECT status FROM payments WHERE reference_id = :ref");
$pCheck->execute(['ref' => $testRefId]);
$payStatus1 = $pCheck->fetchColumn();

$oCheck = $pdo->prepare("SELECT status FROM orders WHERE reference_id = :ref");
$oCheck->execute(['ref' => $testRefId]);
$orderStatus1 = $oCheck->fetchColumn();

echo "Delivery 1 (Success Notification):\n";
echo "  Payment Status : $payStatus1 (Expected: paid)\n";
echo "  Order Status   : $orderStatus1 (Expected: processing)\n";
echo "  Stock          : $initialStock -> $stockAfterDelivery1 (Expected: " . ($initialStock - 2) . ")\n";

// Duplicate Webhook Delivery 2
$ch = curl_init('http://localhost/BreadBreak/webhook/xendit.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-callback-token: ' . XENDIT_WEBHOOK_TOKEN]);
$res2 = curl_exec($ch);
curl_close($ch);

$vStmt->execute(['vid' => $vid]);
$stockAfterDelivery2 = (int) $vStmt->fetchColumn();

echo "Delivery 2 (Duplicate Notification):\n";
echo "  Stock          : $stockAfterDelivery1 -> $stockAfterDelivery2 (Expected: $stockAfterDelivery1)\n";

// Duplicate Webhook Delivery 3
$ch = curl_init('http://localhost/BreadBreak/webhook/xendit.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-callback-token: ' . XENDIT_WEBHOOK_TOKEN]);
$res3 = curl_exec($ch);
curl_close($ch);

$vStmt->execute(['vid' => $vid]);
$stockAfterDelivery3 = (int) $vStmt->fetchColumn();

echo "Delivery 3 (Duplicate Notification):\n";
echo "  Stock          : $stockAfterDelivery2 -> $stockAfterDelivery3 (Expected: $stockAfterDelivery1)\n";

// Clean up test order and restore stock
$pdo->prepare("UPDATE inventory_item_variants SET quantity = :qty WHERE id = :vid")->execute(['qty' => $initialStock, 'vid' => $vid]);
$pdo->prepare("DELETE FROM payments WHERE order_id = :oid")->execute(['oid' => $testOrderId]);
$pdo->prepare("DELETE FROM orders WHERE id = :oid")->execute(['oid' => $testOrderId]);

echo "\nCleaned up temporary test order $testRefId and restored original stock ($initialStock).\n";

if ($stockAfterDelivery1 === ($initialStock - 2) &&
    $stockAfterDelivery2 === $stockAfterDelivery1 &&
    $stockAfterDelivery3 === $stockAfterDelivery1) {
    echo "\n====================================================\n";
    echo " ✅ ALL IDEMPOTENCY & STOCK VERIFICATION TESTS PASSED!\n";
    echo "====================================================\n";
} else {
    echo "\n❌ VERIFICATION FAILED\n";
}
