<?php
// ============================================================
// order-confirmation.php  –  Live order status page
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/order_status.php';

$customerId = (int) $_SESSION['user_id'];
$ref        = trim($_GET['ref'] ?? '');
$order      = null;
$payment    = null;
$orderItems = [];
$notFound   = false;

if ($ref) {
    $pdo = getDatabaseConnection();

    // Load order (must belong to this customer)
    $oStmt = $pdo->prepare(
        "SELECT id, reference_id, status, fulfillment_type, subtotal, vatable_sales, vat_amount, vat_exempt_sales,
                discount_type, discount_amount, discount_id_number, discount_name,
                delivery_fee, delivery_address, total_amount, payment_method, cash_amount, created_at
         FROM orders
         WHERE reference_id = :ref AND customer_id = :cid LIMIT 1"
    );
    $oStmt->execute(['ref' => $ref, 'cid' => $customerId]);
    $order = $oStmt->fetch();

    if ($order) {
        ensureOrderStatusEnum($pdo);
        // Load order items
        $iStmt = $pdo->prepare(
            "SELECT product_name, service_size, sku, unit_price, quantity, line_total
             FROM order_items WHERE order_id = :oid ORDER BY id ASC"
        );
        $iStmt->execute(['oid' => $order['id']]);
        $orderItems = $iStmt->fetchAll();

        // Load payment
        $pStmt = $pdo->prepare(
            "SELECT id, xendit_payment_request_id, reference_id, amount, payment_method,
                    payment_channel, status, created_at, updated_at
             FROM payments WHERE order_id = :oid ORDER BY id DESC LIMIT 1"
        );
        $pStmt->execute(['oid' => $order['id']]);
        $payment = $pStmt->fetch();
    } else {
        $notFound = true;
    }
} else {
    $notFound = true;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function paymentStatusBadge(string $status, bool $isCashOrder = false): string
{
    if ($isCashOrder && $status === 'pending') {
        return '<span class="badge badge-cash"><i class="fa-solid fa-coins"></i> Pay on arrival</span>';
    }
    $map = [
        'pending' => ['badge-pending', 'clock',         'Awaiting payment'],
        'paid'    => ['badge-paid',    'circle-check',   'Paid'],
        'failed'  => ['badge-failed',  'circle-xmark',   'Failed'],
        'expired' => ['badge-expired', 'hourglass-end',  'Expired'],
        'voided'  => ['badge-expired', 'ban',            'Voided'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['badge-pending', 'circle-question', ucfirst($status)];
    return '<span class="badge ' . $cls . '"><i class="fa-solid fa-' . $icon . '"></i> ' . $label . '</span>';
}

function orderStatusBadge(string $status, string $fulfillmentType = 'delivery'): string
{
    $map = [
        'pending'            => ['badge-pending',    'clipboard-list',  'Received'],
        'processing'         => ['badge-processing', 'bread-slice',     'Baking'],
        'out_for_delivery'   => ['badge-processing', 'motorcycle',      'On the way'],
        'ready_for_pickup'   => ['badge-processing', 'store',           'Ready'],
        'completed'          => ['badge-paid',       'circle-check',    $fulfillmentType === 'pickup' ? 'Picked up' : 'Delivered'],
        'cancelled'          => ['badge-failed',     'circle-xmark',    'Cancelled'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['badge-pending', 'circle-question', ucfirst(str_replace('_', ' ', $status))];
    return '<span class="badge ' . $cls . '"><i class="fa-solid fa-' . $icon . '"></i> ' . $label . '</span>';
}

// Baseline values — always set, whatever happens in the verification below.
$paymentStatus = (string) ($payment['status'] ?? 'pending');
$orderStatus   = is_array($order) ? (string) ($order['status'] ?? 'pending') : 'pending';

// Safety net for a dead webhook: ask Xendit directly what happened to this
// payment. The webhook stays authoritative; this only rescues orders that would
// otherwise sit at "awaiting payment" forever because the public URL is down.
$paymentVerification = null;
if (is_array($order) && !in_array(strtolower($paymentStatus), ['paid', 'failed', 'expired', 'voided'], true)) {
    try {
        require_once __DIR__ . '/includes/payments.php';
        $paymentVerification = verifyOrderPaymentAtXendit($pdo, (int) $order['id']);
    } catch (Throwable) {
        $paymentVerification = ['checked' => false, 'status' => null, 'reason' => 'lookup failed'];
    }

    if (($paymentVerification['status'] ?? null) === 'paid') {
        $refresh = $pdo->prepare('SELECT status FROM payments WHERE id = :id');
        $refresh->execute(['id' => (int) $payment['id']]);
        $paymentStatus = (string) ($refresh->fetchColumn() ?: 'paid');

        $refreshOrder = $pdo->prepare('SELECT status FROM orders WHERE id = :id');
        $refreshOrder->execute(['id' => (int) $order['id']]);
        $orderStatus = (string) ($refreshOrder->fetchColumn() ?: 'processing');
    }
}

$orderPayMethod = strtoupper(is_array($order) ? ($order['payment_method'] ?? 'GCASH') : 'GCASH');
$isCashOrder   = ($orderPayMethod === 'CASH');
$fulfillmentType = is_array($order) ? ($order['fulfillment_type'] ?? 'delivery') : 'delivery';
$isPickupOrder = $fulfillmentType === 'pickup';
$cashAmount = (float) (is_array($order) ? ($order['cash_amount'] ?? 0) : 0);
$orderTotal = (float) (is_array($order) ? ($order['total_amount'] ?? 0) : 0);
$cashChange = max(0, $cashAmount - $orderTotal);
$orderStatusUrl = '/BreadBreak/customer/order-status.php' . (is_array($order) ? '?ref=' . urlencode((string) $order['reference_id']) : '');
// Cash orders with pending status are awaiting physical payment — don't auto-refresh
$isTerminal    = in_array($paymentStatus, ['paid', 'failed', 'expired', 'voided'], true) || $isCashOrder;

$iconClass = match(true) {
    $paymentStatus === 'paid' || $isCashOrder            => 'icon-paid',
    in_array($paymentStatus, ['failed','voided'], true) => 'icon-failed',
    $paymentStatus === 'expired'                         => 'icon-expired',
    default                                              => 'icon-pending',
};
$iconSymbol = match(true) {
    $paymentStatus === 'paid' || $isCashOrder            => 'circle-check',
    in_array($paymentStatus, ['failed','voided'], true) => 'circle-xmark',
    $paymentStatus === 'expired'                         => 'hourglass-end',
    default                                              => 'clock',
};

$pageTitle = 'Order Confirmation | BreadBreak';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/BreadBreak/assets/css/checkout.css?v=<?php echo file_exists(__DIR__ . '/assets/css/checkout.css') ? filemtime(__DIR__ . '/assets/css/checkout.css') : time(); ?>" />
<?php if (!$isTerminal && !$notFound && $order): ?>
<script>setTimeout(function () { window.location.reload(); }, 5000);</script>
<?php endif; ?>

<main class="confirmation-page">
<div class="container">

<?php if ($notFound || !$order): ?>
    <div class="confirmation-hero">
        <div class="confirmation-icon icon-failed">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <h1>Order Not Found</h1>
        <p>We couldn't find an order matching this reference. Please check your confirmation email or browse your order history.</p>
        <div class="confirmation-actions" style="border:0;padding-top:1rem;">
            <a href="/BreadBreak/customer/menu_dashboard.php" class="btn btn-primary">Return to Menu</a>
            <a href="/BreadBreak/customer/order-status.php" class="btn btn-outline">View Order Status</a>
        </div>
    </div>
<?php else:
    $pmLabels = [
        'GCASH'     => ['GCash', '/BreadBreak/assets/images/payments/GCash-Emblem.png'],
        'PAYMAYA'   => ['Maya', '/BreadBreak/assets/images/payments/maya-emblem.png'],
        'GRABPAY'   => ['GrabPay', '/BreadBreak/assets/images/payments/grabpay-emblem.png'],
        'SHOPEEPAY' => ['ShopeePay', '/BreadBreak/assets/images/payments/Shopeepay-emblem.png'],
        'CARD'      => ['Credit / Debit Card', '/BreadBreak/assets/images/payments/card.svg'],
        'CASH'      => ['Cash on ' . ($isPickupOrder ? 'Pickup' : 'Delivery'), '/BreadBreak/assets/images/payments/P-Cash-emblem.png'],
    ];
    [$pmName, $pmLogo] = $pmLabels[$orderPayMethod] ?? [$orderPayMethod, ''];
?>

    <div class="confirmation-hero">
        <div class="confirmation-icon <?php echo $iconClass; ?>">
            <i class="fa-solid fa-<?php echo $iconSymbol; ?>"></i>
        </div>
        <?php if ($paymentStatus === 'paid'): ?>
            <h1>Payment confirmed</h1>
            <p>Your BreadBreak order is in. We’ll start baking it next.</p>
        <?php elseif ($isCashOrder): ?>
            <h1>Order placed</h1>
            <p><?php echo $isPickupOrder
                ? 'Bring cash when you pick up at the bakery.'
                : 'Prepare cash — our rider will collect it on delivery.'; ?></p>
        <?php elseif (in_array($paymentStatus, ['failed', 'voided'], true)): ?>
            <h1>Payment failed</h1>
            <p>Your payment was not completed. Please try placing a new order.</p>
        <?php elseif ($paymentStatus === 'expired'): ?>
            <h1>Payment expired</h1>
            <p>Your payment session has expired. Please try placing a new order.</p>
        <?php else: ?>
            <h1>Order received</h1>
            <p>Waiting for payment confirmation. This page updates automatically.</p>
        <?php endif; ?>
    </div>

    <div class="confirmation-card">
        <div class="confirmation-card-header">
            <div>
                <p class="confirmation-kicker"><?php echo $isPickupOrder ? 'Store pickup' : 'Delivery order'; ?></p>
                <h2>Order #<?php echo htmlspecialchars($order['reference_id']); ?></h2>
            </div>
            <?php echo paymentStatusBadge($paymentStatus, $isCashOrder); ?>
        </div>

        <div class="confirmation-card-body">
            <section class="confirmation-tracker-block">
                <?php echo renderOrderStatusTracker($orderStatus, $fulfillmentType, 'large'); ?>
                <div class="confirmation-tracker-copy">
                    <strong><?php echo htmlspecialchars(orderStatusCustomerLabel($orderStatus, $fulfillmentType)); ?></strong>
                    <p><?php echo htmlspecialchars(orderStatusCustomerMessage($orderStatus, $fulfillmentType)); ?></p>
                </div>
            </section>

            <div class="confirmation-facts">
                <div>
                    <span>Placed</span>
                    <strong><?php echo date('M j, Y · g:i A', strtotime($order['created_at'])); ?></strong>
                </div>
                <div>
                    <span>Payment</span>
                    <strong class="confirmation-pay-method">
                        <?php if ($pmLogo): ?>
                            <img src="<?php echo $pmLogo; ?>" alt="" />
                        <?php endif; ?>
                        <?php echo htmlspecialchars($pmName); ?>
                    </strong>
                </div>
                <div>
                    <span><?php echo $isCashOrder ? 'To pay' : 'Paid'; ?></span>
                    <strong class="confirmation-total">₱<?php echo number_format($orderTotal, 2); ?></strong>
                </div>
            </div>

            <?php if ($isCashOrder && $cashAmount > 0): ?>
            <aside class="cash-pay-card">
                <div class="cash-pay-amounts">
                    <div>
                        <span>Prepare</span>
                        <strong>₱<?php echo number_format($cashAmount, 2); ?></strong>
                    </div>
                    <div>
                        <span>Change</span>
                        <strong>₱<?php echo number_format($cashChange, 2); ?></strong>
                    </div>
                </div>
                <p>
                    <?php if ($isPickupOrder): ?>
                        Bring this amount to Estrella Village. Staff will collect payment before releasing your order.
                    <?php else: ?>
                        Have ₱<?php echo number_format($cashAmount, 2); ?> ready. The rider will return ₱<?php echo number_format($cashChange, 2); ?>.
                    <?php endif; ?>
                </p>
            </aside>
            <?php endif; ?>

            <?php if (!empty($order['discount_type']) && $order['discount_type'] !== 'none'): ?>
            <p class="confirmation-discount-note">
                <?php echo $order['discount_type'] === 'senior' ? 'Senior Citizen discount (20% off + VAT exempt)' : 'PWD discount (20% off + VAT exempt)'; ?>
                · ID <?php echo htmlspecialchars($order['discount_id_number'] ?? '—'); ?>
                (<?php echo htmlspecialchars($order['discount_name'] ?? '—'); ?>)
            </p>
            <?php endif; ?>

            <?php if ($isPickupOrder): ?>
            <section class="confirmation-place">
                <div>
                    <span>Pickup at</span>
                    <strong>BreadBreak Bakery — Estrella Village</strong>
                    <p>Estrella Village, Guiguinto, Bulacan · 7:00 AM – 8:00 PM</p>
                </div>
                <a href="https://www.google.com/maps/search/?api=1&query=Bread+Break+Estrella+Village+Guiguinto+Bulacan" target="_blank" rel="noopener noreferrer">Get directions</a>
            </section>
            <div class="confirmation-map">
                <iframe
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3856.89935785534!2d120.86967517519058!3d14.830905485683221!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x339653f14f7970b1%3A0x6472797c39ec3f75!2sBread%20Break!5e0!3m2!1sen!2sph!4v1789883477934!5m2!1sen!2sph"
                    width="100%"
                    height="180"
                    style="border:0;display:block;"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin">
                </iframe>
            </div>
            <?php elseif (!empty($order['delivery_address'])): ?>
            <section class="confirmation-place">
                <div>
                    <span>Deliver to</span>
                    <strong><?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?></strong>
                </div>
            </section>
            <?php endif; ?>

            <?php
            $itemsSum = 0.0;
            foreach ($orderItems as $item) {
                $itemsSum += (float) $item['line_total'];
            }
            ?>
            <h3 class="confirmation-items-title">Your items</h3>
            <ul class="confirmation-item-list">
                <?php foreach ($orderItems as $item): ?>
                    <li>
                        <div>
                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                            <span><?php echo htmlspecialchars($item['service_size']); ?> · Qty <?php echo (int) $item['quantity']; ?></span>
                        </div>
                        <em>₱<?php echo number_format((float) $item['line_total'], 2); ?></em>
                    </li>
                <?php endforeach; ?>
            </ul>
            <dl class="confirmation-totals">
                <div>
                    <dt>Subtotal</dt>
                    <dd>₱<?php echo number_format($itemsSum, 2); ?></dd>
                </div>
                <?php if (!empty($order['discount_type']) && $order['discount_type'] !== 'none'):
                    $vatExemptAmount = $itemsSum - ((float) $order['vat_exempt_sales']);
                ?>
                <div>
                    <dt>VAT exemption</dt>
                    <dd>-₱<?php echo number_format($vatExemptAmount, 2); ?></dd>
                </div>
                <div>
                    <dt>20% <?php echo strtoupper($order['discount_type']); ?> discount</dt>
                    <dd>-₱<?php echo number_format((float) $order['discount_amount'], 2); ?></dd>
                </div>
                <?php endif; ?>
                <?php if ((float)($order['delivery_fee'] ?? 0) > 0): ?>
                <div>
                    <dt>Delivery fee</dt>
                    <dd>₱<?php echo number_format((float) $order['delivery_fee'], 2); ?></dd>
                </div>
                <?php endif; ?>
                <div class="is-total">
                    <dt><?php echo $isCashOrder ? 'Total to pay' : 'Total paid'; ?></dt>
                    <dd>₱<?php echo number_format((float) $order['total_amount'], 2); ?></dd>
                </div>
            </dl>

            <?php if (!$isTerminal): ?>
            <div class="confirmation-refresh-note">
                <i class="fa-solid fa-rotate"></i>
                <span>This page refreshes every 5 seconds until Xendit confirms your payment.</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="confirmation-actions">
            <a href="/BreadBreak/customer/menu_dashboard.php" class="btn btn-primary">
                <i class="fa-solid fa-house"></i> Return to Menu
            </a>
            <a href="<?php echo htmlspecialchars($orderStatusUrl); ?>" class="btn btn-outline">
                <i class="fa-solid fa-bread-slice"></i> View Order Status
            </a>
            <?php if (!$isTerminal): ?>
            <a href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" class="btn btn-outline">
                <i class="fa-solid fa-rotate"></i> Refresh Now
            </a>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

