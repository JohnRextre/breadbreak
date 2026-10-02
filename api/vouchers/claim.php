<?php
// =============================================================================
// api/vouchers/claim.php  –  Claim a promoted voucher from BreadMoments
//
// POST /BreadBreak/api/vouchers/claim.php
// Body (JSON): { "voucher_id": 3 }
//
// Returns JSON: { ok, message }
// Adds the voucher to the signed-in customer's account (respecting the
// per-customer and total usage limits).
// =============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/voucher_promotion.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Please sign in to claim this voucher.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true);
$voucherId = (int) ($body['voucher_id'] ?? 0);

if ($voucherId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'That voucher could not be found.']);
    exit;
}

try {
    $pdo  = getDatabaseConnection();
    $result = claimPromotedVoucher($pdo, $voucherId, (int) $_SESSION['user_id']);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to claim the voucher right now. Please try again.']);
    exit;
}

echo json_encode($result);