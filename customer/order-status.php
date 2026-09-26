<?php
/**
 * Customer My Orders.
 *
 * Split into two screens-in-one:
 *   Active   — still in the kitchen / on the road, plus delivered orders that are
 *              still waiting for the customer to press "Order Received".
 *   History  — everything closed: delivered-and-received, and cancelled.
 *
 * Acknowledged delivered orders leave the top list, so the customer is never
 * scrolling past finished work to find the order that needs them.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/order_status.php';
require_once __DIR__ . '/../includes/rider.php';
require_once __DIR__ . '/../includes/reviews.php';

$pdo = getDatabaseConnection();
ensureOrderStatusEnum($pdo);
ensureRiderSupport($pdo);
ensureOrderStatusHistory($pdo);
ensureReviewSupport($pdo);
$customerId = (int) $_SESSION['user_id'];

$selectedRef = trim($_GET['ref'] ?? '');
// 'delivered' is the label staff and customers use; the stored status is
// 'completed'. Map the tab to the status rather than comparing them directly.
$historyFilter = in_array($_GET['history'] ?? '', ['delivered', 'cancelled'], true)
    ? (string) $_GET['history']
    : 'all';
$historyFilterStatus = $historyFilter === 'delivered' ? 'completed' : 'cancelled';

$notice = '';
if (isset($_GET['skipped'])) {
    $notice = 'No problem. This order is now in your Order History, and you can still review it any time.';
} elseif (isset($_GET['reviewed'])) {
    $notice = 'Thank you! Your review and rating have been saved.';
} elseif (isset($_GET['received'])) {
    $notice = 'Great — rate your food and the service below.';
}

$ordersStmt = $pdo->prepare(
    "SELECT o.id, o.reference_id, o.status, o.fulfillment_type, o.subtotal,
            o.vat_amount, o.vat_exempt_sales, o.discount_type, o.discount_amount,
            o.discount_id_number, o.discount_name, o.delivery_fee, o.total_amount,
            o.payment_method, o.cash_amount, o.collected_amount, o.collected_at,
            o.delivery_address, o.notes, o.created_at, o.rider_id,
            o.review_acknowledged_at, o.proof_captured_at, o.proof_note,
            o.chat_closed_at, o.chat_closed_by,
            (o.proof_photo_data IS NOT NULL) AS has_proof,
            (SELECT COUNT(*) FROM order_reviews rv WHERE rv.order_id = o.id) AS has_review,
            (SELECT rv.food_rating FROM order_reviews rv WHERE rv.order_id = o.id LIMIT 1) AS food_rating,
            (SELECT ROUND(
                     (COALESCE(rv.shop_service_rating, 0) + COALESCE(rv.delivery_speed_rating, 0)
                        + COALESCE(rv.driver_service_rating, 0))
                     / NULLIF(
                         (rv.shop_service_rating IS NOT NULL) + (rv.delivery_speed_rating IS NOT NULL)
                         + (rv.driver_service_rating IS NOT NULL), 0)
                ) FROM order_reviews rv WHERE rv.order_id = o.id LIMIT 1) AS service_rating,
            (SELECT p.status FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) AS payment_status,
            r.first_name AS rider_first, r.last_name AS rider_last, r.phone AS rider_phone
     FROM orders o
     LEFT JOIN users r ON r.id = o.rider_id
     WHERE o.customer_id = :cid
     ORDER BY o.id DESC"
);
$ordersStmt->execute(['cid' => $customerId]);
$orders = $ordersStmt->fetchAll();

foreach ($orders as $i => $row) {
    $orders[$i]['is_acknowledged'] = trim((string) ($row['review_acknowledged_at'] ?? '')) !== '';
    $orders[$i]['needs_received'] = $row['status'] === 'completed' && !$orders[$i]['is_acknowledged'];
    $orders[$i]['has_review'] = (int) $row['has_review'] > 0;
}

// ── Split ──────────────────────────────────────────────────────────────────
// A delivered order stays in Active only while it still needs the customer to
// acknowledge it. Everything closed lands in History.
$activeOrders = [];
$historyOrders = [];
foreach ($orders as $row) {
    if (in_array($row['status'], ['completed', 'cancelled'], true)) {
        if ($row['needs_received']) {
            $activeOrders[] = $row;
        } else {
            $historyOrders[] = $row;
        }
    } else {
        $activeOrders[] = $row;
    }
}

// In-progress first (newest on top), then the orders still waiting to be
// received, longest-waiting first so nothing gets buried.
usort($activeOrders, static function (array $a, array $b): int {
    $aWaiting = $a['needs_received'] ? 1 : 0;
    $bWaiting = $b['needs_received'] ? 1 : 0;
    if ($aWaiting !== $bWaiting) {
        return $aWaiting - $bWaiting;
    }
    if ($aWaiting) {
        return strtotime((string) $a['created_at']) - strtotime((string) $b['created_at']);
    }
    return (int) $b['id'] <=> (int) $a['id'];
});

$historyCounts = ['all' => count($historyOrders), 'delivered' => 0, 'cancelled' => 0];
foreach ($historyOrders as $row) {
    $historyCounts[$row['status'] === 'cancelled' ? 'cancelled' : 'delivered']++;
}
$visibleHistory = $historyFilter === 'all'
    ? $historyOrders
    : array_values(array_filter($historyOrders, static fn ($r) => $r['status'] === $historyFilterStatus));

// Highlight the newest active order; ?ref= wins so deep links keep working.
if ($selectedRef === '' && $activeOrders) {
    $selectedRef = (string) $activeOrders[0]['reference_id'];
}

// ── Detail data for every card, used by the View Details screen ────────────
$allIds = array_column($orders, 'id');
$itemsMap = [];
if ($allIds) {
    $in = implode(',', array_fill(0, count($allIds), '?'));
    $itemsStmt = $pdo->prepare(
        "SELECT order_id, product_name, service_size, sku, unit_price, quantity, line_total
         FROM order_items WHERE order_id IN ($in) ORDER BY id ASC"
    );
    $itemsStmt->execute($allIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsMap[(int) $item['order_id']][] = $item;
    }
}
$historyMap = orderStatusHistoryMap($pdo, $orders);

$hasActive = !empty($activeOrders);
$hasAny = !empty($orders);

$pageTitle = 'My Orders | BreadBreak';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/checkout.css" />
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/order-status.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/order-status.css') ? filemtime(__DIR__ . '/../assets/css/order-status.css') : time(); ?>" />

<main class="os-page">
    <div class="container">

        <header class="os-hero">
            <div>
                <span class="eyebrow"><?php echo $hasActive ? 'Live tracking' : 'Your bakery history'; ?></span>
                <h1>My Orders</h1>
                <p><?php echo $hasActive
                    ? 'Follow your breads from the oven to your door, or to the pickup counter.'
                    : 'Every order you have placed, all in one place.'; ?></p>
            </div>
            <?php if ($hasAny): ?>
                <div class="os-hero-stats">
                    <div class="os-stat">
                        <span>In progress</span>
                        <strong><?php echo count(array_filter($activeOrders, static fn ($r) => !$r['needs_received'])); ?></strong>
                    </div>
                    <div class="os-stat is-await">
                        <span>Awaiting review</span>
                        <strong><?php echo count(array_filter($activeOrders, static fn ($r) => $r['needs_received'])); ?></strong>
                    </div>
                    <div class="os-stat is-done">
                        <span>Delivered</span>
                        <strong><?php echo $historyCounts['delivered']; ?></strong>
                    </div>
                    <div class="os-stat is-void">
                        <span>Cancelled</span>
                        <strong><?php echo $historyCounts['cancelled']; ?></strong>
                    </div>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($notice): ?>
            <div class="os-alert is-success" role="status">
                <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasAny): ?>
            <div class="os-empty">
                <div class="os-empty-icon"><i class="fa-solid fa-bread-slice"></i></div>
                <h2>No orders yet</h2>
                <p>When you place an order, you can track it here in real time.</p>
                <a href="<?php echo BASE_URL; ?>/customer/menu_dashboard.php" class="os-btn is-primary">
                    <i class="fa-solid fa-basket-shopping"></i> Browse the menu
                </a>
            </div>

        <?php else: ?>

            <!-- ── Active orders ──────────────────────────────────────────── -->
            <section class="os-section">
                <div class="os-section-head">
                    <div>
                        <h2><i class="fa-solid fa-fire-burner"></i> Active Orders</h2>
                        <p>Orders still being prepared, delivered, or waiting for your review.</p>
                    </div>
                    <span class="os-section-count"><?php echo count($activeOrders); ?></span>
                </div>

                <?php if (!$activeOrders): ?>
                    <div class="os-section-empty">
                        <i class="fa-solid fa-mug-hot"></i>
                        Nothing on the go right now. Your finished orders are in Order History below.
                    </div>
                <?php else: ?>
                    <div class="os-card-grid">
                        <?php foreach ($activeOrders as $row):
                            $ref = (string) $row['reference_id'];
                            $ftype = $row['fulfillment_type'] ?? 'delivery';
                            $isFeatured = $ref === $selectedRef;
                            $chip = orderStatusChip($row['status'], $ftype);
                            $items = $itemsMap[(int) $row['id']] ?? [];
                            $unitCount = array_sum(array_map(static fn ($i) => (int) $i['quantity'], $items));
                            $riderName = !empty($row['rider_id'])
                                ? trim(($row['rider_first'] ?? '') . ' ' . ($row['rider_last'] ?? ''))
                                : '';
                        ?>
                        <article class="os-card<?php echo $isFeatured ? ' is-featured' : ''; ?><?php echo $row['needs_received'] ? ' needs-review' : ''; ?>"
                                 id="order-<?php echo htmlspecialchars($ref); ?>">
                            <div class="os-card-top">
                                <div>
                                    <h3>Order #<?php echo htmlspecialchars($ref); ?></h3>
                                    <p>
                                        <?php echo date('M j, Y · g:i A', strtotime($row['created_at'])); ?>
                                        · <?php echo $ftype === 'pickup' ? 'Store pickup' : 'Delivery'; ?>
                                    </p>
                                </div>
                                <span class="os-chip <?php echo htmlspecialchars($chip[0]); ?>">
                                    <i class="fa-solid fa-<?php echo htmlspecialchars($chip[1]); ?>"></i>
                                    <?php echo htmlspecialchars($chip[2]); ?>
                                </span>
                            </div>

                            <div class="os-card-track">
                                <?php echo renderOrderStatusTracker($row['status'], $ftype, $isFeatured ? 'large' : 'compact'); ?>
                            </div>
                            <p class="os-card-label"><?php echo htmlspecialchars(orderStatusCustomerLabel($row['status'], $ftype)); ?></p>
                            <p class="os-card-message"><?php echo htmlspecialchars(orderStatusCustomerMessage($row['status'], $ftype)); ?></p>

                            <div class="os-card-meta">
                                <span><i class="fa-solid fa-receipt"></i> ₱<?php echo number_format((float) $row['total_amount'], 2); ?></span>
                                <span><i class="fa-solid fa-basket-shopping"></i> <?php echo count($items); ?> <?php echo count($items) === 1 ? 'item' : 'items'; ?> · <?php echo $unitCount; ?> <?php echo $unitCount === 1 ? 'pc' : 'pcs'; ?></span>
                                <span><i class="fa-solid fa-wallet"></i> <?php echo htmlspecialchars(orderPaymentLabelShort($row['payment_method'])); ?></span>
                                <?php if ($riderName !== ''): ?>
                                    <span><i class="fa-solid fa-motorcycle"></i> <?php echo htmlspecialchars($riderName); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($row['needs_received']): ?>
                                <div class="os-received-bar">
                                    <div>
                                        <strong>How did we do?</strong>
                                        <p>This order was delivered. Rate your food and the service to help us improve.</p>
                                    </div>
                                    <a class="os-btn is-primary" href="<?php echo BASE_URL; ?>/customer/review.php?ref=<?php echo urlencode($ref); ?>">
                                        <i class="fa-solid fa-star"></i> Order Received
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="os-card-actions">
                                <button class="os-btn is-ghost" type="button" data-os-open="<?php echo (int) $row['id']; ?>"
                                        aria-controls="os-detail-<?php echo (int) $row['id']; ?>">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> View details
                                </button>
                                <?php if ($ftype !== 'pickup' && !empty($row['rider_id'])): ?>
                                    <?php if (in_array($row['status'], ['completed', 'cancelled'], true)): ?>
                                        <span class="os-btn is-off" title="Chat with your rider ends once the order is <?php echo $row['status'] === 'cancelled' ? 'cancelled' : 'delivered'; ?>.">
                                            <i class="fa-solid fa-lock"></i> Chat ended
                                        </span>
                                    <?php else: ?>
                                        <a class="os-btn is-soft" href="<?php echo BASE_URL; ?>/customer/chat.php?ref=<?php echo urlencode($ref); ?>">
                                            <i class="fa-solid fa-comments"></i> Message rider
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ── Order History: delivered + cancelled ────────────────────── -->
            <section class="os-section is-history">
                <div class="os-section-head">
                    <div>
                        <h2><i class="fa-solid fa-clock-rotate-left"></i> Order History</h2>
                        <p>Every delivered and cancelled order. You can still rate anything you have not reviewed.</p>
                    </div>
                    <div class="os-hist-tabs" role="tablist" aria-label="Filter order history">
                        <?php foreach ([
                            'all' => 'All',
                            'delivered' => 'Delivered',
                            'cancelled' => 'Cancelled',
                        ] as $key => $label): ?>
                            <a class="os-hist-tab<?php echo $historyFilter === $key ? ' is-on' : ''; ?>"
                               role="tab" aria-selected="<?php echo $historyFilter === $key ? 'true' : 'false'; ?>"
                               href="?history=<?php echo $key; ?><?php echo $selectedRef !== '' ? '&ref=' . urlencode($selectedRef) : ''; ?>">
                                <?php echo $label; ?>
                                <span><?php echo $historyCounts[$key]; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!$visibleHistory): ?>
                    <div class="os-section-empty">
                        <i class="fa-solid fa-box-open"></i>
                        <?php echo $historyOrders
                            ? 'No ' . htmlspecialchars($historyFilter) . ' orders yet.'
                            : 'Once an order is delivered or cancelled, it moves here automatically.'; ?>
                    </div>
                <?php else: ?>
                    <div class="os-hist-list">
                        <?php foreach ($visibleHistory as $row):
                            $ref = (string) $row['reference_id'];
                            $ftype = $row['fulfillment_type'] ?? 'delivery';
                            $cancelled = $row['status'] === 'cancelled';
                            $chip = orderStatusChip($row['status'], $ftype);
                            $items = $itemsMap[(int) $row['id']] ?? [];
                            $riderName = !empty($row['rider_id'])
                                ? trim(($row['rider_first'] ?? '') . ' ' . ($row['rider_last'] ?? ''))
                                : '';
                        ?>
                        <article class="os-hist-row<?php echo $cancelled ? ' is-cancelled' : ''; ?>">
                            <span class="os-hist-icon <?php echo $cancelled ? 'is-void' : 'is-done'; ?>">
                                <i class="fa-solid <?php echo $cancelled ? 'fa-ban' : 'fa-circle-check'; ?>"></i>
                            </span>

                            <div class="os-hist-main">
                                <div class="os-hist-title">
                                    <strong>Order #<?php echo htmlspecialchars($ref); ?></strong>
                                    <span class="os-chip <?php echo htmlspecialchars($chip[0]); ?>">
                                        <i class="fa-solid fa-<?php echo htmlspecialchars($chip[1]); ?>"></i>
                                        <?php echo htmlspecialchars($chip[2]); ?>
                                    </span>
                                </div>
                                <p>
                                    <?php echo date('M j, Y · g:i A', strtotime($row['created_at'])); ?>
                                    · <?php echo $ftype === 'pickup' ? 'Store pickup' : 'Delivery'; ?>
                                    · <?php echo htmlspecialchars(orderPaymentLabelShort($row['payment_method'])); ?>
                                    <?php if ($riderName !== ''): ?>
                                        · <?php echo htmlspecialchars($riderName); ?>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="os-hist-money">
                                <strong>₱<?php echo number_format((float) $row['total_amount'], 2); ?></strong>
                                <small><?php echo count($items); ?> <?php echo count($items) === 1 ? 'item' : 'items'; ?></small>
                            </div>

                            <div class="os-hist-actions">
                                <?php if ($cancelled): ?>
                                    <span class="os-tag is-void"><i class="fa-solid fa-ban"></i> Cancelled</span>
                                    <button class="os-btn is-ghost" type="button" data-os-open="<?php echo (int) $row['id']; ?>"
                                            aria-controls="os-detail-<?php echo (int) $row['id']; ?>">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Details
                                    </button>
                                <?php elseif ($row['has_review']): ?>
                                    <span class="os-tag is-done" title="Food <?php echo (int) $row['food_rating']; ?>/5 · Service <?php echo (int) $row['service_rating']; ?>/5">
                                        <i class="fa-solid fa-star"></i>
                                        <?php echo (int) $row['food_rating']; ?>.0
                                    </span>
                                    <a class="os-btn is-soft" href="<?php echo BASE_URL; ?>/customer/review.php?ref=<?php echo urlencode($ref); ?>">
                                        <i class="fa-solid fa-eye"></i> View review
                                    </a>
                                <?php else: ?>
                                    <span class="os-tag is-await"><i class="fa-regular fa-clock"></i> Not rated</span>
                                    <a class="os-btn is-primary" href="<?php echo BASE_URL; ?>/customer/review.php?ref=<?php echo urlencode($ref); ?>">
                                        <i class="fa-solid fa-star"></i> Rate
                                    </a>
                                <?php endif; ?>
                                <button class="os-btn is-ghost is-icon" type="button" data-os-open="<?php echo (int) $row['id']; ?>"
                                        aria-controls="os-detail-<?php echo (int) $row['id']; ?>" title="View full order details">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php
/**
 * One full-screen details panel per order: tracker, receipt, payment, delivery,
 * rider, proof photo and the activity trail. Rendered hidden; a button opens it.
 */
?>
<?php foreach ($orders as $row):
    $oid = (int) $row['id'];
    $ref = (string) $row['reference_id'];
    $ftype = $row['fulfillment_type'] ?? 'delivery';
    $chip = orderStatusChip($row['status'], $ftype);
    $items = $itemsMap[$oid] ?? [];
    $trail = $historyMap[$oid] ?? [];
    $total = (float) $row['total_amount'];
    $isCash = orderIsCashPayment($row['payment_method']);
    $riderName = !empty($row['rider_id'])
        ? trim(($row['rider_first'] ?? '') . ' ' . ($row['rider_last'] ?? ''))
        : '';
    // A missing payments row must not masquerade as "still pending" on an order
    // that is already delivered or cancelled.
    $payStatus = $row['payment_status'] === null
        ? 'no_record'
        : strtolower((string) $row['payment_status']);
?>
<div class="os-modal" id="os-detail-<?php echo $oid; ?>" hidden role="dialog" aria-modal="true" aria-label="Order #<?php echo htmlspecialchars($ref); ?> details">
    <div class="os-modal-backdrop" data-os-close></div>
    <div class="os-modal-card">
        <header class="os-modal-head">
            <div>
                <span class="os-modal-kicker">Order #<?php echo htmlspecialchars($ref); ?></span>
                <h2>Order details</h2>
                <p>
                    <?php echo date('M j, Y · g:i A', strtotime($row['created_at'])); ?>
                    · <?php echo $ftype === 'pickup' ? 'Store pickup' : 'Delivery'; ?>
                    · <?php echo htmlspecialchars(orderPaymentLabel($row['payment_method'], null, $ftype)); ?>
                </p>
            </div>
            <div class="os-modal-head-right">
                <span class="os-chip <?php echo htmlspecialchars($chip[0]); ?>">
                    <i class="fa-solid fa-<?php echo htmlspecialchars($chip[1]); ?>"></i>
                    <?php echo htmlspecialchars($chip[2]); ?>
                </span>
                <button class="os-modal-close" type="button" data-os-close aria-label="Close details">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </header>

        <div class="os-modal-body">
            <section class="os-block">
                <h3 class="os-block-title"><i class="fa-solid fa-location-crosshairs"></i> Progress</h3>
                <div class="os-modal-track">
                    <?php echo renderOrderStatusTracker($row['status'], $ftype, 'large'); ?>
                </div>
                <p class="os-block-lead"><?php echo htmlspecialchars(orderStatusCustomerLabel($row['status'], $ftype)); ?></p>
                <p class="os-block-sub"><?php echo htmlspecialchars(orderStatusCustomerMessage($row['status'], $ftype)); ?></p>
            </section>

            <section class="os-block">
                <h3 class="os-block-title"><i class="fa-solid fa-receipt"></i> Receipt</h3>
                <div class="os-receipt">
                    <?php foreach ($items as $item): ?>
                        <div class="os-receipt-row">
                            <div>
                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                <small><?php echo htmlspecialchars($item['service_size']); ?> · <?php echo htmlspecialchars($item['sku']); ?></small>
                            </div>
                            <div class="os-receipt-num">
                                <span>× <?php echo (int) $item['quantity']; ?></span>
                                <strong>₱<?php echo number_format((float) $item['line_total'], 2); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$items): ?>
                        <p class="os-block-sub">No items recorded for this order.</p>
                    <?php endif; ?>
                </div>
                <div class="os-totals">
                    <div><span>Subtotal</span><strong>₱<?php echo number_format((float) $row['subtotal'], 2); ?></strong></div>
                    <?php if ((float) $row['vat_amount'] > 0): ?>
                        <div><span>VAT</span><strong>₱<?php echo number_format((float) $row['vat_amount'], 2); ?></strong></div>
                    <?php endif; ?>
                    <?php if ((float) $row['vat_exempt_sales'] > 0): ?>
                        <div><span>VAT-exempt sales</span><strong>₱<?php echo number_format((float) $row['vat_exempt_sales'], 2); ?></strong></div>
                    <?php endif; ?>
                    <?php if ((float) $row['discount_amount'] > 0): ?>
                        <div class="is-discount">
                            <span>
                                Discount<?php echo !empty($row['discount_name']) ? ' · ' . htmlspecialchars($row['discount_name']) : ''; ?>
                                <?php if (!empty($row['discount_id_number'])): ?>
                                    <em>ID <?php echo htmlspecialchars($row['discount_id_number']); ?></em>
                                <?php endif; ?>
                            </span>
                            <strong>−₱<?php echo number_format((float) $row['discount_amount'], 2); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ((float) $row['delivery_fee'] > 0): ?>
                        <div><span>Delivery fee</span><strong>₱<?php echo number_format((float) $row['delivery_fee'], 2); ?></strong></div>
                    <?php endif; ?>
                    <div class="is-total"><span>Total</span><strong>₱<?php echo number_format($total, 2); ?></strong></div>
                </div>
                <a class="os-btn is-ghost is-wide" href="<?php echo BASE_URL; ?>/order-confirmation.php?ref=<?php echo urlencode($ref); ?>">
                    <i class="fa-solid fa-file-invoice"></i> Open full receipt page
                </a>
            </section>

            <section class="os-block">
                <h3 class="os-block-title"><i class="fa-solid fa-circle-info"></i> Payment &amp; delivery</h3>
                <dl class="os-fields">
                    <div>
                        <dt>Payment status</dt>
                        <dd>
                            <?php if ($payStatus === 'paid'): ?>
                                <span class="os-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
                            <?php elseif (in_array($payStatus, ['failed', 'expired', 'voided'], true)): ?>
                                <span class="os-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($payStatus); ?></span>
                            <?php elseif ($row['status'] === 'cancelled'): ?>
                                <span class="os-chip is-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                            <?php elseif ($payStatus === 'no_record'): ?>
                                <span class="os-chip is-pending"><i class="fa-solid fa-minus"></i> Not recorded</span>
                            <?php else: ?>
                                <span class="os-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if ($isCash && (float) $row['cash_amount'] > 0): ?>
                        <div>
                            <dt>Customer handed over</dt>
                            <dd>₱<?php echo number_format((float) $row['cash_amount'], 2); ?></dd>
                        </div>
                        <div>
                            <dt>Change returned</dt>
                            <dd>₱<?php echo number_format(max(0, (float) $row['cash_amount'] - $total), 2); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($row['collected_amount'] !== null): ?>
                        <div>
                            <dt>Collected by rider</dt>
                            <dd>₱<?php echo number_format((float) $row['collected_amount'], 2); ?><?php echo $row['collected_at'] ? ' · ' . date('M d, g:i A', strtotime($row['collected_at'])) : ''; ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($ftype !== 'pickup'): ?>
                        <div>
                            <dt>Rider</dt>
                            <dd>
                                <?php if ($riderName !== ''): ?>
                                    <?php echo htmlspecialchars($riderName); ?>
                                    <?php if (!empty($row['rider_phone'])): ?>
                                        <a class="os-inline-link" href="tel:<?php echo htmlspecialchars($row['rider_phone']); ?>">
                                            <i class="fa-solid fa-phone"></i> Call
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="os-muted">Not assigned yet</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($row['chat_closed_at'])): ?>
                        <div>
                            <dt>Chat</dt>
                            <dd>
                                <span class="os-muted">
                                    Closed <?php echo date('M j, g:i A', strtotime($row['chat_closed_at'])); ?>
                                    (<?php echo ($row['chat_closed_by'] ?? '') === 'customer' ? 'by you' : 'by the bakery'; ?>)
                                </span>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($ftype !== 'pickup' && !empty($row['delivery_address'])): ?>
                        <div class="is-wide">
                            <dt>Delivery address</dt>
                            <dd>
                                <?php echo nl2br(htmlspecialchars($row['delivery_address'])); ?>
                                <a class="os-inline-link" style="margin-top:6px;"
                                   href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo urlencode((string) $row['delivery_address']); ?>"
                                   target="_blank" rel="noopener"><i class="fa-solid fa-map"></i> Open in Maps</a>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($row['notes'])): ?>
                        <div class="is-wide">
                            <dt>Order notes</dt>
                            <dd><?php echo nl2br(htmlspecialchars($row['notes'])); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>

            <?php if (!empty($row['proof_captured_at']) || !empty($row['has_proof'])): ?>
                <section class="os-block">
                    <h3 class="os-block-title"><i class="fa-solid fa-camera"></i> Delivery proof</h3>
                    <?php if (!empty($row['has_proof'])): ?>
                        <a class="os-proof" href="<?php echo BASE_URL; ?>/api/delivery-proof.php?order=<?php echo $oid; ?>" target="_blank" rel="noopener">
                            <img src="<?php echo BASE_URL; ?>/api/delivery-proof.php?order=<?php echo $oid; ?>"
                                 alt="Delivery proof photo for order <?php echo htmlspecialchars($ref); ?>" loading="lazy" />
                            <span><i class="fa-solid fa-expand"></i> View full size</span>
                        </a>
                    <?php else: ?>
                        <p class="os-block-sub">No photo was attached to this order.</p>
                    <?php endif; ?>
                    <?php if (!empty($row['proof_note'])): ?>
                        <p class="os-block-sub"><strong>Handover note:</strong> <?php echo htmlspecialchars($row['proof_note']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($row['proof_captured_at'])): ?>
                        <p class="os-block-sub"><i class="fa-regular fa-clock"></i> Captured <?php echo date('M j, Y g:i A', strtotime($row['proof_captured_at'])); ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="os-block">
                <h3 class="os-block-title"><i class="fa-solid fa-timeline"></i> Activity trail</h3>
                <ol class="os-trail">
                    <?php foreach (array_reverse($trail) as $entry): ?>
                        <li class="os-trail-item is-<?php echo htmlspecialchars((string) $entry['to_status']); ?>">
                            <span class="os-trail-dot">
                                <i class="fa-solid fa-<?php echo htmlspecialchars(orderHistoryIcon((string) $entry['to_status'], $entry['from_status'])); ?>"></i>
                            </span>
                            <div>
                                <strong><?php echo htmlspecialchars(orderHistoryLabel((string) $entry['to_status'], $entry['from_status'], $ftype)); ?></strong>
                                <?php if (!empty($entry['note'])): ?>
                                    <p><?php echo htmlspecialchars($entry['note']); ?></p>
                                <?php endif; ?>
                                <small>
                                    <?php echo htmlspecialchars($entry['actor_name'] ?: 'System'); ?>
                                    <em><?php echo htmlspecialchars(orderActorRoleLabel($entry['actor_role'])); ?></em>
                                    · <?php echo date('M d, Y g:i A', strtotime($entry['created_at'])); ?>
                                </small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$trail): ?>
                        <li class="os-trail-item">
                            <span class="os-trail-dot"><i class="fa-solid fa-receipt"></i></span>
                            <div><strong>No activity recorded yet.</strong></div>
                        </li>
                    <?php endif; ?>
                </ol>
            </section>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    var open = function (id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('os-modal-open');
        var close = modal.querySelector('.os-modal-close');
        if (close) close.focus();
    };

    var closeAll = function () {
        var any = document.querySelector('.os-modal:not([hidden])');
        if (any) any.hidden = true;
        document.body.classList.remove('os-modal-open');
    };

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-os-open]');
        if (trigger) {
            event.preventDefault();
            open('os-detail-' + trigger.getAttribute('data-os-open'));
            return;
        }
        if (event.target.closest('[data-os-close]')) {
            event.preventDefault();
            closeAll();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeAll();
    });

    // The page auto-refreshes every 20s while orders are active. A refresh would
    // slam the details screen shut mid-read, so hold the timer off until the
    // customer closes it. The re-check keeps waiting rather than firing late.
<?php if ($hasActive): ?>
    var RELOAD_MS = 20000;
    var elapsed = 0;

    window.setInterval(function () {
        if (document.querySelector('.os-modal:not([hidden])')) return;   // still reading
        elapsed += 1000;
        if (elapsed >= RELOAD_MS) {
            elapsed = 0;
            window.location.reload();
        }
    }, 1000);
<?php endif; ?>
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
