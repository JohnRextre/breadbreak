<?php
// Streams a photo / video attached to a customer order review
// (order_review_media.media_data is a BLOB, same pattern as delivery-proof).
// Access: the customer who wrote the review, or bakery staff.
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../config/database.php';

$mediaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$mediaId) {
    http_response_code(400);
    exit;
}

$viewerId = (int) $_SESSION['user_id'];
$viewerRole = (string) ($_SESSION['role'] ?? '');

try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare(
        'SELECT m.media_data, m.media_mime, r.customer_id
         FROM order_review_media m
         JOIN order_reviews r ON r.id = m.review_id
         WHERE m.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $mediaId]);
    $media = $stmt->fetch();
} catch (Throwable) {
    $media = false;
}

$allowed = false;
if ($media) {
    if (in_array($viewerRole, ['admin', 'staff'], true)) {
        $allowed = true;
    } elseif ($viewerRole === 'customer' && (int) $media['customer_id'] === $viewerId) {
        $allowed = true;
    }
}

if (!$allowed || empty($media['media_data']) || empty($media['media_mime'])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $media['media_mime']);
header('Content-Length: ' . strlen($media['media_data']));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $media['media_data'];
