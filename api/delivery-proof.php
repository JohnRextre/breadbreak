<?php
// Streams the delivery proof photo stored on orders.proof_photo_data.
// Access: the rider assigned to the order, the customer who ordered it, or bakery staff.
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';

$orderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);
if (!$orderId) {
    http_response_code(400);
    exit;
}

$viewerId = (int) $_SESSION['user_id'];
$viewerRole = (string) ($_SESSION['role'] ?? '');

try {
    $pdo = getDatabaseConnection();
    ensureRiderSupport($pdo);

    $stmt = $pdo->prepare(
        'SELECT customer_id, rider_id, proof_photo_data, proof_photo_mime
         FROM orders
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
} catch (Throwable) {
    $order = false;
}

$allowed = false;
if ($order) {
    if (in_array($viewerRole, ['admin', 'staff'], true)) {
        $allowed = true;
    } elseif ($viewerRole === 'rider' && (int) $order['rider_id'] === $viewerId) {
        $allowed = true;
    } elseif ($viewerRole === 'customer' && (int) $order['customer_id'] === $viewerId) {
        $allowed = true;
    }
}

if (!$allowed || empty($order['proof_photo_data']) || empty($order['proof_photo_mime'])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $order['proof_photo_mime']);
header('Content-Length: ' . strlen($order['proof_photo_data']));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $order['proof_photo_data'];
