<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/order_status.php';
require_once __DIR__ . '/../includes/rider.php';

$pdo = getDatabaseConnection();
ensureOrderStatusEnum($pdo);
ensureRiderSupport($pdo);
$customerId = (int) $_SESSION['user_id'];
$selectedRef = trim($_GET['ref'] ?? '');

$ordersStmt = $pdo->prepare(
    "SELECT id, reference_id, status, fulfillment_type, total_amount, payment_method,
            cash_amount, delivery_address, created_at, rider_id
     FROM orders
     WHERE customer_id = :cid
     ORDER BY id DESC"
);
$ordersStmt->execute(['cid' => $customerId]);
$orders = $ordersStmt->fetchAll();

$selected = null;
foreach ($orders as $row) {
    if ($selectedRef !== '' && $row['reference_id'] === $selectedRef) {
        $selected = $row;
        break;
    }
}
if (!$selected && $orders) {
    $selected = $orders[0];
    $selectedRef = (string) $selected['reference_id'];
}

$hasActive = false;
foreach ($orders as $row) {
    if (!in_array($row['status'], ['completed', 'cancelled'], true)) {
        $hasActive = true;
        break;
    }
}

$pageTitle = 'Order Status | BreadBreak';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/BreadBreak/assets/css/checkout.css" />

<main class="order-status-page">
    <div class="container">
        <div class="order-status-hero">
            <span class="eyebrow">Live tracking</span>
            <h1>Order Status</h1>
            <p>Follow your breads from the oven to your door, or to the pickup counter.</p>
        </div>

        <?php if (!$orders): ?>
            <div class="order-status-empty">
                <i class="fa-solid fa-bread-slice"></i>
                <h2>No orders yet</h2>
                <p>When you place an order, you can track it here in real time.</p>
                <a href="/BreadBreak/customer/menu_dashboard.php" class="btn btn-primary" style="margin-top:1rem;">Browse the menu</a>
            </div>
        <?php else: ?>
            <div class="order-status-list">
                <?php foreach ($orders as $row):
                    $isFeatured = $row['reference_id'] === $selectedRef;
                    $ftype = $row['fulfillment_type'] ?? 'delivery';
                    $payMethod = strtoupper((string) $row['payment_method']);
                    $payLabel = $payMethod === 'CASH'
                        ? ('Cash on ' . ($ftype === 'pickup' ? 'Pickup' : 'Delivery'))
                        : ucfirst(strtolower($payMethod));
                ?>
                <article class="order-status-card<?php echo $isFeatured ? ' is-featured' : ''; ?>" id="order-<?php echo htmlspecialchars($row['reference_id']); ?>">
                    <div class="order-status-card-top">
                        <div>
                            <h2>Order #<?php echo htmlspecialchars($row['reference_id']); ?></h2>
                            <p>
                                <?php echo date('M j, Y · g:i A', strtotime($row['created_at'])); ?>
                                · <?php echo $ftype === 'pickup' ? 'Store pickup' : 'Delivery'; ?>
                                · <?php echo htmlspecialchars($payLabel); ?>
                            </p>
                        </div>
                        <strong style="color:var(--accent);">₱<?php echo number_format((float) $row['total_amount'], 2); ?></strong>
                    </div>

                    <div class="order-status-track">
                        <?php echo renderOrderStatusTracker($row['status'], $ftype, $isFeatured ? 'large' : 'compact'); ?>
                    </div>
                    <p class="order-status-label"><?php echo htmlspecialchars(orderStatusCustomerLabel($row['status'], $ftype)); ?></p>
                    <p class="order-status-message"><?php echo htmlspecialchars(orderStatusCustomerMessage($row['status'], $ftype)); ?></p>

                    <?php if ($ftype !== 'pickup' && !empty($row['delivery_address'])): ?>
                        <p class="order-status-address">
                            <i class="fa-solid fa-location-dot"></i>
                            <?php echo htmlspecialchars($row['delivery_address']); ?>
                        </p>
                    <?php endif; ?>

                    <div class="confirmation-actions" style="border:0;padding:1rem 0 0;justify-content:flex-start;">
                        <a href="/BreadBreak/order-confirmation.php?ref=<?php echo urlencode($row['reference_id']); ?>" class="btn btn-outline">View receipt</a>
                        <?php if ($ftype !== 'pickup' && !empty($row['rider_id'])): ?>
                            <a href="/BreadBreak/customer/chat.php?ref=<?php echo urlencode($row['reference_id']); ?>" class="btn btn-primary">Message rider</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php if ($hasActive): ?>
<script>setTimeout(function () { window.location.reload(); }, 20000);</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
