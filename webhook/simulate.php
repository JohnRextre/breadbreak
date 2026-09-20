<?php
// ============================================================
// webhook/simulate.php  –  Direct DB webhook simulation
// ============================================================
// Bypasses HTTP and directly runs the webhook logic against the DB.
// Use this when XENDIT_WEBHOOK_TOKEN hasn't been set yet.
// LOCALHOST ONLY — restricted by IP.
// ============================================================
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isCli    = PHP_SAPI === 'cli';
if (!$isCli && !in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('Localhost only.');
}

require_once __DIR__ . '/../config/database.php';

if ($isCli) {
    $referenceId     = $argv[1] ?? '';
    $simulatedStatus = strtoupper($argv[2] ?? 'SUCCEEDED');
} else {
    $referenceId     = trim($_GET['ref'] ?? '');
    $simulatedStatus = strtoupper(trim($_GET['status'] ?? 'SUCCEEDED'));
}

if (!$referenceId) {
    die("Usage: ?ref=BB-XXXX&status=SUCCEEDED\nStatuses: SUCCEEDED / FAILED / EXPIRED\n");
}

$dbPaymentStatus = match ($simulatedStatus) {
    'SUCCEEDED'           => 'paid',
    'FAILED'              => 'failed',
    'EXPIRED'             => 'expired',
    'VOIDED','CANCELLED'  => 'voided',
    default               => null,
};

if (!$dbPaymentStatus) {
    die("Unknown status '$simulatedStatus'. Use: SUCCEEDED / FAILED / EXPIRED\n");
}

$pdo = getDatabaseConnection();

// Look up payment
$pStmt = $pdo->prepare("SELECT * FROM payments WHERE reference_id = :ref ORDER BY id DESC LIMIT 1");
$pStmt->execute(['ref' => $referenceId]);
$payment = $pStmt->fetch();

if (!$payment) {
    die("No payment found for reference_id='$referenceId'\n");
}

echo "=== Webhook Simulator (Direct DB) ===\n\n";
echo "Reference ID   : $referenceId\n";
echo "DB Status Now  : " . $payment['status'] . "\n";
echo "Simulating     : $simulatedStatus → $dbPaymentStatus\n\n";

// ── Idempotency check ──────────────────────────────────────────────────────
if (in_array($payment['status'], ['paid','failed','expired','voided'], true)) {
    echo "IDEMPOTENT SKIP — already in terminal state: " . $payment['status'] . "\n";

    // Check stock to confirm it was NOT deducted again
    $orderId = (int) $payment['order_id'];
    $items = $pdo->prepare("SELECT oi.variant_id, oi.quantity, v.quantity AS stock, v.availability
                             FROM order_items oi
                             JOIN inventory_item_variants v ON v.id = oi.variant_id
                             WHERE oi.order_id = :oid");
    $items->execute(['oid' => $orderId]);
    echo "\nVariant Stock (should be unchanged):\n";
    foreach ($items->fetchAll() as $item) {
        echo "  variant_id=" . $item['variant_id'] . "  stock=" . $item['stock'] . "  availability=" . $item['availability'] . "\n";
    }
    exit;
}

$orderId = (int) $payment['order_id'];

// ── Record stock BEFORE ────────────────────────────────────────────────────
$iStmt = $pdo->prepare("SELECT oi.variant_id, oi.quantity AS ordered, v.quantity AS stock_before
                         FROM order_items oi
                         JOIN inventory_item_variants v ON v.id = oi.variant_id
                         WHERE oi.order_id = :oid");
$iStmt->execute(['oid' => $orderId]);
$stockBefore = $iStmt->fetchAll();

// ── Run update in transaction ──────────────────────────────────────────────
$pdo->beginTransaction();

$pdo->prepare("UPDATE payments SET status = :status, xendit_raw_response = :raw, updated_at = NOW() WHERE id = :id")
    ->execute(['status' => $dbPaymentStatus, 'raw' => json_encode(['simulated' => true, 'status' => $simulatedStatus]), 'id' => (int) $payment['id']]);

if ($dbPaymentStatus === 'paid') {
    $pdo->prepare("UPDATE orders SET status = 'processing', updated_at = NOW() WHERE id = :oid")
        ->execute(['oid' => $orderId]);

    $items = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = :oid");
    $items->execute(['oid' => $orderId]);
    $deduct = $pdo->prepare("UPDATE inventory_item_variants SET quantity = GREATEST(0, quantity - :qty) WHERE id = :vid");
    foreach ($items->fetchAll() as $item) {
        $deduct->execute(['qty' => (int) $item['quantity'], 'vid' => (int) $item['variant_id']]);
    }

    $pdo->prepare("UPDATE inventory_item_variants SET availability = 'unavailable'
                   WHERE id IN (SELECT variant_id FROM order_items WHERE order_id = :oid) AND quantity = 0")
        ->execute(['oid' => $orderId]);

} else {
    $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = :oid")
        ->execute(['oid' => $orderId]);
}

$pdo->commit();

// ── Report results ─────────────────────────────────────────────────────────
$pStmt->execute(['ref' => $referenceId]);
$updatedPayment = $pStmt->fetch();
$oStmt = $pdo->prepare("SELECT status FROM orders WHERE id = :oid LIMIT 1");
$oStmt->execute(['oid' => $orderId]);
$updatedOrder = $oStmt->fetch();

echo "✅ Webhook processed successfully!\n\n";
echo "Payment status : " . $payment['status'] . " → " . $updatedPayment['status'] . "\n";
echo "Order status   : pending → " . $updatedOrder['status'] . "\n";

if ($dbPaymentStatus === 'paid') {
    echo "\nStock changes:\n";
    $iStmt->execute(['oid' => $orderId]);
    $stockAfter = $iStmt->fetchAll();
    foreach ($stockBefore as $i => $before) {
        $after = $stockAfter[$i];
        $deducted = $before['stock_before'] - $after['stock'];
        echo "  variant_id=" . $before['variant_id']
            . "  ordered=" . $before['ordered']
            . "  stock: " . $before['stock_before'] . " → " . $after['stock']
            . "  (deducted $deducted)\n";
    }
}

echo "\n--- Run AGAIN to test idempotency ---\n";
echo "  php webhook/simulate.php $referenceId $simulatedStatus\n";

