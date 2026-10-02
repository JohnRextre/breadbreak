<?php
// =============================================================================
// api/vouchers/validate.php  –  BreadBreak Voucher Check
//
// POST /BreadBreak/api/vouchers/validate.php
// Body (JSON): { "code": "WELCOME", "subtotal": 250.00, "fulfillment": "delivery" }
//
// Returns JSON:
//   { ok, message, code?, title?, discount_type? }
// NOTE: This is a pre-check for live UI feedback. checkout.php re-validates
// everything server-side when the order is actually placed.
// =============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/vouchers.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Please sign in to use a voucher.']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true);
$code        = strtoupper(trim((string) ($body['code'] ?? '')));
$subtotal    = max(0.0, (float) ($body['subtotal'] ?? 0));
$fulfillment = in_array($body['fulfillment'] ?? '', ['delivery', 'pickup'], true) ? $body['fulfillment'] : 'delivery';

if ($code === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Please enter a voucher code.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $result = validateVoucherForOrder($pdo, $code, (int) $_SESSION['user_id'], $subtotal, $fulfillment);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to check the voucher right now. Please try again.']);
    exit;
}

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'message' => $result['error']]);
    exit;
}

$voucher = $result['voucher'];
echo json_encode([
    'ok'            => true,
    'message'       => 'Voucher applied!',
    'code'          => $voucher['code'],
    'title'         => $voucher['title'],
    'discount_type' => $voucher['discount_type'],
    'description'   => $voucher['description'] ?? '',
]);
