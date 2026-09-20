<?php
// ============================================================
// order-confirmation.php  –  Live order status page
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/config/database.php';

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
                delivery_fee, delivery_address, total_amount, created_at
         FROM orders
         WHERE reference_id = :ref AND customer_id = :cid LIMIT 1"
    );
    $oStmt->execute(['ref' => $ref, 'cid' => $customerId]);
    $order = $oStmt->fetch();

    if ($order) {
        // Load order items
        $iStmt = $pdo->prepare(
            "SELECT product_name, service_size, sku, unit_price, quantity, line_total
             FROM order_items WHERE order_id = :oid ORDER BY id ASC"
        );
        $iStmt->execute(['oid' => $order['id']]);
        $orderItems = $iStmt->fetchAll();

        // Load payment
        $pStmt = $pdo->prepare(
            "SELECT xendit_payment_request_id, reference_id, amount, payment_method,
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
function paymentStatusBadge(string $status): string
{
    $map = [
        'pending' => ['badge-pending', 'clock',         'Pending Verification'],
        'paid'    => ['badge-paid',    'circle-check',   'Paid'],
        'failed'  => ['badge-failed',  'circle-xmark',   'Failed'],
        'expired' => ['badge-expired', 'hourglass-end',  'Expired'],
        'voided'  => ['badge-expired', 'ban',            'Voided'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['badge-pending', 'circle-question', ucfirst($status)];
    return '<span class="badge ' . $cls . '"><i class="fa-solid fa-' . $icon . '"></i> ' . $label . '</span>';
}

function orderStatusBadge(string $status): string
{
    $map = [
        'pending'    => ['badge-pending',    'hourglass-half',  'Pending'],
        'processing' => ['badge-processing', 'rotate',          'Processing'],
        'completed'  => ['badge-paid',       'circle-check',    'Completed'],
        'cancelled'  => ['badge-failed',     'circle-xmark',    'Cancelled'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['badge-pending', 'circle-question', ucfirst($status)];
    return '<span class="badge ' . $cls . '"><i class="fa-solid fa-' . $icon . '"></i> ' . $label . '</span>';
}

$paymentStatus = $payment['status'] ?? 'pending';
$orderStatus   = $order['status']   ?? 'pending';
$isTerminal    = in_array($paymentStatus, ['paid', 'failed', 'expired', 'voided'], true);

$iconClass = match($paymentStatus) {
    'paid'           => 'icon-paid',
    'failed','voided'=> 'icon-failed',
    'expired'        => 'icon-expired',
    default          => 'icon-pending',
};
$iconSymbol = match($paymentStatus) {
    'paid'           => 'circle-check',
    'failed','voided'=> 'circle-xmark',
    'expired'        => 'hourglass-end',
    default          => 'clock',
};

$pageTitle = 'Order Confirmation | BreadBreak';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/style.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/checkout.css" />
    <?php if (!$isTerminal && !$notFound && $order): ?>
    <!-- Auto-refresh every 5 seconds while payment is still pending -->
    <meta http-equiv="refresh" content="5" />
    <?php endif; ?>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="confirmation-page">
<div class="container">

<?php if ($notFound || !$order): ?>
    <div class="confirmation-hero">
        <div class="confirmation-icon icon-failed">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <h1>Order Not Found</h1>
        <p>We couldn't find an order matching this reference. Please check your confirmation email or browse your order history.</p>
        <a href="/BreadBreak/customer/menu_dashboard.php" class="btn btn-primary" style="margin-top:1rem;">Return to Menu</a>
    </div>
<?php else: ?>

    <!-- Hero -->
    <div class="confirmation-hero">
        <div class="confirmation-icon <?php echo $iconClass; ?>">
            <i class="fa-solid fa-<?php echo $iconSymbol; ?>"></i>
        </div>
        <?php if ($paymentStatus === 'paid'): ?>
            <h1 style="color:var(--brown-900)">Payment Confirmed!</h1>
            <p>Your BreadBreak order has been received and is now being processed. Thank you for your purchase!</p>
        <?php elseif (in_array($paymentStatus, ['failed', 'voided'], true)): ?>
            <h1 style="color:var(--brown-900)">Payment Failed</h1>
            <p>Unfortunately your payment was not completed. Please try placing a new order.</p>
        <?php elseif ($paymentStatus === 'expired'): ?>
            <h1 style="color:var(--brown-900)">Payment Expired</h1>
            <p>Your payment session has expired. Please try placing a new order.</p>
        <?php else: ?>
            <h1 style="color:var(--brown-900)">Order Received</h1>
            <p>We're waiting for payment confirmation from Xendit. This page will update automatically.</p>
        <?php endif; ?>
    </div>

    <!-- Order Card -->
    <div class="confirmation-card">
        <div class="confirmation-card-header">
            <h2>
                <i class="fa-solid fa-receipt" style="margin-right:.4rem;color:var(--accent)"></i>
                Order #<?php echo htmlspecialchars($order['reference_id']); ?>
            </h2>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <?php echo orderStatusBadge($orderStatus); ?>
                <?php echo paymentStatusBadge($paymentStatus); ?>
            </div>
        </div>

        <div class="confirmation-card-body">
            <!-- Meta grid -->
            <dl class="confirmation-meta">
                <dt>Order Reference</dt>
                <dd><?php echo htmlspecialchars($order['reference_id']); ?></dd>

                <dt>Order Date</dt>
                <dd><?php echo date('F j, Y g:i A', strtotime($order['created_at'])); ?></dd>

                <dt>Payment Method</dt>
                <dd><?php echo htmlspecialchars(($payment['payment_method'] ?? 'GCash') . ' · ' . ($payment['payment_channel'] ?? 'GCASH')); ?></dd>

                <dt>Total Amount</dt>
                <dd><strong style="color:var(--accent)">₱<?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?></strong></dd>

                <dt>Fulfillment</dt>
                <dd>
                    <?php if (($order['fulfillment_type'] ?? 'delivery') === 'pickup'): ?>
                        <span style="background:#e8f7ef;color:#1a6645;border:1px solid #a3d9bc;padding:.15rem .6rem;border-radius:100px;font-weight:700;font-size:.82rem;">
                            <i class="fa-solid fa-store"></i> Store Pickup (Free)
                        </span>
                    <?php else: ?>
                        <span style="background:#fff3e0;color:#b85c00;border:1px solid #f5c842;padding:.15rem .6rem;border-radius:100px;font-weight:700;font-size:.82rem;">
                            <i class="fa-solid fa-truck"></i> Delivery
                        </span>
                    <?php endif; ?>
                </dd>

                <?php if (!empty($order['discount_type']) && $order['discount_type'] !== 'none'): ?>
                <dt style="grid-column:1/-1;margin-top:.3rem;border-top:1px solid var(--border);padding-top:.5rem;">
                    <i class="fa-solid fa-id-card" style="margin-right:.3rem;color:var(--accent);"></i>Applied Discount
                </dt>
                <dd style="grid-column:1/-1;">
                    <span style="background:#e8f7ef;color:#1a6645;border:1px solid #a3d9bc;padding:.2rem .6rem;border-radius:100px;font-weight:700;font-size:.82rem;">
                        <?php echo $order['discount_type'] === 'senior' ? '🧓 Senior Citizen (20% Off + VAT Exempt)' : '♿ PWD (20% Off + VAT Exempt)'; ?>
                    </span>
                    <span style="font-size:.85rem;color:var(--muted);margin-left:.5rem;">
                        ID: <strong><?php echo htmlspecialchars($order['discount_id_number'] ?? '—'); ?></strong> (<?php echo htmlspecialchars($order['discount_name'] ?? '—'); ?>)
                    </span>
                </dd>
                <?php endif; ?>

                <?php if (($order['fulfillment_type'] ?? 'delivery') === 'pickup'): ?>
                <dt style="grid-column:1/-1;margin-top:.3rem;border-top:1px solid var(--border);padding-top:.5rem;">
                    <i class="fa-solid fa-store" style="margin-right:.3rem;color:var(--accent);"></i>Pickup Location
                </dt>
                <dd style="grid-column:1/-1;">
                    <strong>BreadBreak Bakery — Estrella Village Branch</strong><br>
                    <span style="color:var(--muted);">Estrella Village, Guiguinto, Bulacan</span><br>
                    <small style="color:var(--muted);"><i class="fa-solid fa-clock" style="margin-right:.25rem;color:var(--accent);"></i>7:00 AM – 8:00 PM · Ready in 30–45 mins</small>

                    <div style="margin-top:.8rem;border-radius:12px;overflow:hidden;border:1.5px solid var(--border);max-width:480px;">
                        <img src="/BreadBreak/assets/breadbreak_png/breadbreak_location.png" alt="BreadBreak Location" style="width:100%;height:auto;display:block;" />
                    </div>
                </dd>
                <?php elseif (!empty($order['delivery_address'])): ?>
                <dt style="grid-column:1/-1;margin-top:.3rem;border-top:1px solid var(--border);padding-top:.5rem;">
                    <i class="fa-solid fa-location-dot" style="margin-right:.3rem;color:var(--accent);"></i>Delivery Address
                </dt>
                <dd style="grid-column:1/-1;"><?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?></dd>
                <?php endif; ?>
            </dl>

            <!-- Order items table -->
            <table class="order-table" style="margin-top:.5rem;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Service Size</th>
                        <th>SKU</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $itemsSum = 0.0;
                foreach ($orderItems as $item): 
                    $itemsSum += (float) $item['line_total'];
                ?>
                    <tr>
                        <td class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['service_size']); ?></td>
                        <td class="item-meta"><?php echo htmlspecialchars($item['sku']); ?></td>
                        <td class="text-right"><?php echo (int) $item['quantity']; ?></td>
                        <td class="text-right price-cell">₱<?php echo number_format((float) $item['unit_price'], 2); ?></td>
                        <td class="text-right price-cell">₱<?php echo number_format((float) $item['line_total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>

                    <!-- Items Subtotal -->
                    <tr style="border-top:2px solid var(--border);">
                        <td colspan="5" class="text-right" style="color:var(--muted);font-size:.9rem;">Subtotal (VAT-Inc)</td>
                        <td class="text-right price-cell">₱<?php echo number_format($itemsSum, 2); ?></td>
                    </tr>

                    <?php if (!empty($order['discount_type']) && $order['discount_type'] !== 'none'): 
                        $vatExemptAmount = $itemsSum - ((float) $order['vat_exempt_sales']);
                    ?>
                    <!-- Senior / PWD Discounts -->
                    <tr style="color:#1a6645;">
                        <td colspan="5" class="text-right" style="font-size:.88rem;">
                            <i class="fa-solid fa-percent" style="margin-right:.3rem;"></i>Less: 12% VAT Exemption
                        </td>
                        <td class="text-right price-cell" style="color:#1a6645;">-₱<?php echo number_format($vatExemptAmount, 2); ?></td>
                    </tr>
                    <tr style="color:#1a6645;">
                        <td colspan="5" class="text-right" style="font-size:.88rem;">
                            <i class="fa-solid fa-tag" style="margin-right:.3rem;"></i>Less: 20% <?php echo strtoupper($order['discount_type']); ?> Discount
                        </td>
                        <td class="text-right price-cell" style="color:#1a6645;">-₱<?php echo number_format((float) $order['discount_amount'], 2); ?></td>
                    </tr>
                    <?php else: ?>
                    <!-- Regular VAT Breakdown -->
                    <tr style="font-size:.82rem;color:var(--muted);">
                        <td colspan="5" class="text-right">VATable Sales</td>
                        <td class="text-right price-cell" style="font-size:.82rem;color:var(--muted);">₱<?php echo number_format((float) ($order['vatable_sales'] ?: ($itemsSum / 1.12)), 2); ?></td>
                    </tr>
                    <tr style="font-size:.82rem;color:var(--muted);">
                        <td colspan="5" class="text-right">12% VAT (Included)</td>
                        <td class="text-right price-cell" style="font-size:.82rem;color:var(--muted);">₱<?php echo number_format((float) ($order['vat_amount'] ?: ($itemsSum - ($itemsSum / 1.12))), 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ((float)($order['delivery_fee'] ?? 0) > 0): ?>
                    <tr>
                        <td colspan="5" class="text-right" style="color:var(--muted);font-size:.9rem;">
                            <i class="fa-solid fa-truck" style="margin-right:.3rem;"></i>Delivery Fee
                        </td>
                        <td class="text-right price-cell">₱<?php echo number_format((float) $order['delivery_fee'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <!-- Grand Total -->
                    <tr style="border-top:2px solid var(--border);">
                        <td colspan="5" class="text-right" style="font-weight:700;color:var(--brown-900);padding-top:1rem;font-size:1rem;">Total Amount Paid</td>
                        <td class="text-right price-cell" style="font-size:1.2rem;padding-top:1rem;">
                            ₱<?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if (!$isTerminal): ?>
            <div class="confirmation-refresh-note">
                <i class="fa-solid fa-rotate" style="color:var(--accent)"></i>
                <span>This page automatically refreshes every 5 seconds until your payment is confirmed by Xendit.</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="confirmation-actions">
            <a href="/BreadBreak/customer/menu_dashboard.php" class="btn btn-primary">
                <i class="fa-solid fa-house" style="margin-right:.4rem;"></i> Return to Menu
            </a>
            <?php if (!$isTerminal): ?>
            <a href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" class="btn btn-outline">
                <i class="fa-solid fa-rotate" style="margin-right:.4rem;"></i> Refresh Now
            </a>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

