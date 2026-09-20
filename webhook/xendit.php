<?php
// ============================================================
// webhook/xendit.php  –  Xendit payment notification handler
// ============================================================
// Xendit sends a POST request here when a payment status changes.
// We verify the x-callback-token BEFORE updating the database.
// All incoming requests are logged to webhook/webhook_log.txt.
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/xendit.php';

function logWebhook(string $message, array $context = []): void
{
    $logFile = __DIR__ . '/webhook_log.txt';
    $entry = sprintf(
        "[%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $message,
        !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// ── Reject anything that is not a POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ── Read raw body ─────────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');

// ── Verify x-callback-token ───────────────────────────────────────────────────
$callbackToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? $_SERVER['X_CALLBACK_TOKEN'] ?? '';

// If a specific verification token is configured in config/xendit.php (not default placeholder)
if (defined('XENDIT_WEBHOOK_TOKEN') && XENDIT_WEBHOOK_TOKEN !== 'YOUR_XENDIT_WEBHOOK_VERIFICATION_TOKEN_HERE' && !empty(XENDIT_WEBHOOK_TOKEN)) {
    if (!hash_equals(XENDIT_WEBHOOK_TOKEN, $callbackToken)) {
        logWebhook('FAILED: Invalid callback token', ['received_token' => $callbackToken]);
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — invalid callback token']);
        exit;
    }
}

// ── Decode payload ────────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    logWebhook('FAILED: Invalid JSON payload', ['raw' => $rawBody]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

logWebhook('RECEIVED_WEBHOOK', [
    'event' => $payload['event'] ?? 'unknown',
    'status' => $payload['status'] ?? ($payload['data']['status'] ?? ''),
    'raw_snippet' => substr($rawBody, 0, 500),
]);

// ── Normalize payload data (support both flat and nested 'data' formats) ──────
$data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
$event = strtolower((string) ($payload['event'] ?? ''));

// Extract payment identifiers
$xenditPaymentRequestId = $data['payment_request_id'] ?? ($data['payment_id'] ?? ($data['id'] ?? ''));
$referenceId            = $data['reference_id'] ?? ($payload['reference_id'] ?? '');
$rawStatus              = strtoupper((string) ($data['status'] ?? ''));

// ── Determine DB status from status or event ─────────────────────────────────
$dbPaymentStatus = null;

if (in_array($rawStatus, ['SUCCEEDED', 'PAID', 'CAPTURED', 'SUCCESS', 'COMPLETED'], true) ||
    in_array($event, ['payment.capture', 'payment.succeeded', 'payment_request.succeeded', 'ewallet.capture'], true)) {
    $dbPaymentStatus = 'paid';
} elseif (in_array($rawStatus, ['FAILED', 'DECLINED'], true) || in_array($event, ['payment.failed'], true)) {
    $dbPaymentStatus = 'failed';
} elseif (in_array($rawStatus, ['EXPIRED'], true) || in_array($event, ['payment.expired'], true)) {
    $dbPaymentStatus = 'expired';
} elseif (in_array($rawStatus, ['VOIDED', 'CANCELLED'], true)) {
    $dbPaymentStatus = 'voided';
}

if ($dbPaymentStatus === null) {
    logWebhook('IGNORED: Intermediate or unhandled status', ['raw_status' => $rawStatus, 'event' => $event]);
    http_response_code(200);
    echo json_encode(['message' => 'Status not actionable, ignored']);
    exit;
}

// ── Connect to DB and run idempotent update ───────────────────────────────────
try {
    $pdo = getDatabaseConnection();

    // Look up our payment row by Xendit ID OR reference ID
    $payment = null;
    if (!empty($xenditPaymentRequestId)) {
        $pStmt = $pdo->prepare("SELECT id, order_id, status FROM payments WHERE xendit_payment_request_id = :xid LIMIT 1");
        $pStmt->execute(['xid' => $xenditPaymentRequestId]);
        $payment = $pStmt->fetch();
    }

    if (!$payment && !empty($referenceId)) {
        $pStmt = $pdo->prepare("SELECT id, order_id, status FROM payments WHERE reference_id = :ref LIMIT 1");
        $pStmt->execute(['ref' => $referenceId]);
        $payment = $pStmt->fetch();
    }

    if (!$payment) {
        logWebhook('IGNORED: Payment record not found in DB', ['xendit_id' => $xenditPaymentRequestId, 'ref' => $referenceId]);
        http_response_code(200);
        echo json_encode(['message' => 'Payment record not found, ignored']);
        exit;
    }

    // ── Idempotency check ──────────────────────────────────────────────────
    if (in_array($payment['status'], ['paid', 'failed', 'expired', 'voided'], true)) {
        logWebhook('IDEMPOTENT_SKIP: Already terminal', ['status' => $payment['status'], 'ref' => $referenceId]);
        http_response_code(200);
        echo json_encode(['message' => 'Already processed, idempotent skip']);
        exit;
    }

    $orderId = (int) $payment['order_id'];
    $rawJson = json_encode($payload);

    $pdo->beginTransaction();

    // 1. Update payment status
    $pdo->prepare(
        "UPDATE payments
         SET status = :status, xendit_raw_response = :raw, updated_at = NOW()
         WHERE id = :id"
    )->execute([
        'status' => $dbPaymentStatus,
        'raw'    => $rawJson,
        'id'     => (int) $payment['id'],
    ]);

    if ($dbPaymentStatus === 'paid') {
        // 2. Update order status to processing
        $pdo->prepare(
            "UPDATE orders SET status = 'processing', updated_at = NOW() WHERE id = :oid"
        )->execute(['oid' => $orderId]);

        // 3. Deduct stock for each order item — clamp to 0, never negative
        $items = $pdo->prepare(
            "SELECT variant_id, quantity FROM order_items WHERE order_id = :oid"
        );
        $items->execute(['oid' => $orderId]);

        $deduct = $pdo->prepare(
            "UPDATE inventory_item_variants
             SET quantity = GREATEST(0, quantity - :qty)
             WHERE id = :vid"
        );
        foreach ($items->fetchAll() as $item) {
            $deduct->execute([
                'qty' => (int) $item['quantity'],
                'vid' => (int) $item['variant_id'],
            ]);
        }

        // 4. Mark variants as unavailable if stock hits 0
        $pdo->prepare(
            "UPDATE inventory_item_variants
             SET availability = 'unavailable'
             WHERE id IN (SELECT variant_id FROM order_items WHERE order_id = :oid)
               AND quantity = 0"
        )->execute(['oid' => $orderId]);

    } else {
        // Payment failed / expired / voided → cancel order
        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = :oid"
        )->execute(['oid' => $orderId]);
    }

    $pdo->commit();

    logWebhook('SUCCESS: Updated DB', [
        'order_id' => $orderId,
        'payment_status' => $dbPaymentStatus,
        'reference_id' => $referenceId,
    ]);

    http_response_code(200);
    echo json_encode(['message' => 'Webhook processed', 'payment_status' => $dbPaymentStatus]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logWebhook('ERROR: Exception during DB update', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log('[BreadBreak Webhook] ' . $e->getMessage());
}
