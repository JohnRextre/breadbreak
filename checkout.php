<?php
// ============================================================
// checkout.php  –  BreadBreak order review + Xendit payment
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/xendit.php';

$pdo = getDatabaseConnection();

// ── Ensure tables exist (safe to run on every page load) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    reference_id VARCHAR(32) NOT NULL UNIQUE,
    status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_address TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    variant_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    service_size VARCHAR(100) NOT NULL,
    sku VARCHAR(80) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_order_item_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON UPDATE CASCADE ON DELETE RESTRICT
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    xendit_payment_request_id VARCHAR(255) NOT NULL UNIQUE,
    reference_id VARCHAR(64) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    payment_method VARCHAR(60) NULL,
    payment_channel VARCHAR(60) NULL,
    status ENUM('pending','paid','failed','expired','voided') NOT NULL DEFAULT 'pending',
    xendit_raw_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT
)");

$customerId = (int) $_SESSION['user_id'];

// ── Load cart items with FRESH prices from DB (never trust JS values) ──
$cartItems  = [];
$cartTotal  = 0.0;
$checkoutError = '';

if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $ids          = array_map('intval', array_keys($_SESSION['cart']));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT v.id AS variant_id, v.service_size, v.sku, v.price,
                v.quantity AS stock_quantity, v.availability,
                i.name AS product_name, i.photo_data, i.photo_mime
         FROM inventory_item_variants v
         JOIN inventory_items i ON i.id = v.inventory_item_id
         WHERE v.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $vid = (int) $row['variant_id'];
        if ($row['availability'] !== 'available') { unset($_SESSION['cart'][$vid]); continue; }
        $qty = min((int) $_SESSION['cart'][$vid], (int) $row['stock_quantity']);
        if ($qty < 1) { unset($_SESSION['cart'][$vid]); continue; }
        $photo = '';
        if (!empty($row['photo_data']) && !empty($row['photo_mime'])) {
            $photo = 'data:' . $row['photo_mime'] . ';base64,' . base64_encode($row['photo_data']);
        }
        $lineTotal    = $qty * (float) $row['price'];
        $cartTotal   += $lineTotal;
        $cartItems[]  = [
            'variant_id'   => $vid,
            'product_name' => $row['product_name'],
            'service_size' => $row['service_size'],
            'sku'          => $row['sku'],
            'unit_price'   => (float) $row['price'],
            'quantity'     => $qty,
            'stock_qty'    => (int) $row['stock_quantity'],
            'line_total'   => $lineTotal,
            'photo'        => $photo,
        ];
    }
}

if (empty($cartItems)) {
    header('Location: /BreadBreak/cart.php');
    exit;
}

// ── Load delivery settings for server-side validation ──
function getDeliverySetting(PDO $pdo, string $key, string $default = ''): string {
    $s = $pdo->prepare('SELECT setting_value FROM delivery_settings WHERE setting_key = :k LIMIT 1');
    $s->execute(['k' => $key]);
    return (string) ($s->fetchColumn() ?: $default);
}

$deliveryMode  = getDeliverySetting($pdo, 'delivery_mode', 'simple');
$inRangeAreas  = json_decode(getDeliverySetting($pdo, 'in_range_areas', '[]'), true) ?: [];
$deliveryZones = json_decode(getDeliverySetting($pdo, 'delivery_zones', '[]'), true) ?: [];

// ── Server-side delivery zone check (same logic as API, inline) ──
function serverCheckDelivery(string $address, string $mode, array $inAreas, array $zones): array {
    if ($mode === 'simple') {
        $normalized = mb_strtolower(trim($address));
        foreach ($inAreas as $area) {
            if (str_contains($normalized, mb_strtolower($area))) {
                $zone = $zones[0] ?? ['name' => 'zone_1', 'fee' => 0, 'min_order' => 0];
                return ['allowed' => true, 'fee' => (int) $zone['fee'], 'min_order' => (int) $zone['min_order']];
            }
        }
        return ['allowed' => false, 'fee' => 0, 'min_order' => 0];
    }
    // Full mode: trust the JS-submitted fee (server-side geocode done in API)
    // Accept if address is non-empty; the API already blocked on the frontend
    return ['allowed' => true, 'fee' => 0, 'min_order' => 0];
}

// ── Handle POST: create order + payment request ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_order') {

    // CSRF: simple session token check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals((string) ($_SESSION['checkout_csrf'] ?? ''), $token)) {
        $checkoutError = 'Invalid form submission. Please try again.';
    }

    // Delivery address validation
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $deliveryFeePost = max(0, (int) ($_POST['delivery_fee'] ?? 0));

    if (!$checkoutError && $deliveryAddress === '') {
        $checkoutError = 'Please enter your delivery address.';
    }

    if (!$checkoutError) {
        $zoneCheck = serverCheckDelivery($deliveryAddress, $deliveryMode, $inRangeAreas, $deliveryZones);
        if (!$zoneCheck['allowed']) {
            $checkoutError = "Sorry, we don't deliver to your area yet. BreadBreak delivers within 8km of our branch in Estrella Village, Guiguinto.";
        }
        // Use server-computed fee for simple mode; use submitted fee for full mode (API-validated on frontend)
        if ($deliveryMode === 'simple') {
            $deliveryFeePost = $zoneCheck['fee'];
        }
    }

    if (!$checkoutError) {
        // Re-validate stock one more time before touching DB
        foreach ($cartItems as $item) {
            if ($item['quantity'] > $item['stock_qty']) {
                $checkoutError = htmlspecialchars($item['product_name']) . ' (' . htmlspecialchars($item['service_size']) . ') no longer has enough stock. Please update your cart.';
                break;
            }
        }
    }

    if (!$checkoutError) {
        $grandTotal = $cartTotal + $deliveryFeePost;
        $pdo->beginTransaction();
        try {
            // 1. Create order row
            $referenceId = generateReferenceId();
            $pdo->prepare("INSERT INTO orders (customer_id, reference_id, status, delivery_fee, delivery_address) VALUES (:cid, :ref, 'pending', :fee, :addr)")
                ->execute(['cid' => $customerId, 'ref' => $referenceId, 'fee' => $deliveryFeePost, 'addr' => $deliveryAddress]);
            $orderId = (int) $pdo->lastInsertId();

            // 2. Create order_items rows
            $insertItem = $pdo->prepare(
                "INSERT INTO order_items
                    (order_id, variant_id, product_name, service_size, sku, unit_price, quantity, line_total)
                 VALUES (:oid, :vid, :name, :size, :sku, :price, :qty, :total)"
            );
            foreach ($cartItems as $item) {
                $insertItem->execute([
                    'oid'   => $orderId,
                    'vid'   => $item['variant_id'],
                    'name'  => $item['product_name'],
                    'size'  => $item['service_size'],
                    'sku'   => $item['sku'],
                    'price' => $item['unit_price'],
                    'qty'   => $item['quantity'],
                    'total' => $item['line_total'],
                ]);
            }

            // 3. Log delivery zone analytics
            try {
                $pdo->prepare(
                    "INSERT INTO delivery_zone_logs
                        (order_id, customer_address, zone, delivery_fee_charged, mode_used)
                     VALUES (:oid, :addr, 'zone_1', :fee, :mode)"
                )->execute(['oid' => $orderId, 'addr' => $deliveryAddress, 'fee' => $deliveryFeePost, 'mode' => $deliveryMode]);
            } catch (Throwable) { /* non-fatal */ }

            // 4. Fetch customer name + email for Xendit
            $custStmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM users WHERE id = :id LIMIT 1');
            $custStmt->execute(['id' => $customerId]);
            $customer = $custStmt->fetch();

            // 5. Build description
            $descParts = array_map(fn($it) => $it['product_name'] . ' (' . $it['service_size'] . ') x' . $it['quantity'], $cartItems);
            $description = implode(', ', $descParts);
            if (strlen($description) > 255) $description = substr($description, 0, 252) . '...';

            // 6. Call Xendit /v3/payment_requests with GCash (v3 flat format)
            $xenditPayload = [
                'reference_id'     => $referenceId,
                'type'             => 'PAY',
                'country'          => 'PH',
                'currency'         => 'PHP',
                'request_amount'   => $grandTotal,
                'capture_method'   => 'AUTOMATIC',
                'channel_code'     => 'GCASH',
                'channel_properties' => [
                    'success_return_url' => XENDIT_SUCCESS_RETURN_URL . '&ref=' . urlencode($referenceId),
                    'failure_return_url' => XENDIT_FAILURE_RETURN_URL . '&ref=' . urlencode($referenceId),
                    'cancel_return_url'  => XENDIT_FAILURE_RETURN_URL . '&ref=' . urlencode($referenceId),
                ],
                'description' => $description,
                'metadata'    => ['order_id' => $orderId, 'source' => 'BreadBreak'],
            ];

            $xenditResult = xenditRequest('POST', '/v3/payment_requests', $xenditPayload);

            if (!$xenditResult['ok']) {
                $errBody = $xenditResult['body'];
                $errMsg  = $errBody['message'] ?? $errBody['error_code'] ?? json_encode($errBody);
                throw new RuntimeException('Xendit error: ' . $errMsg);
            }

            $xenditBody = $xenditResult['body'];
            $xenditId   = $xenditBody['payment_request_id'] ?? ($xenditBody['id'] ?? '');

            // 7. Create payments row
            $pdo->prepare(
                "INSERT INTO payments
                    (order_id, xendit_payment_request_id, reference_id, amount, currency,
                     payment_method, payment_channel, status, xendit_raw_response)
                 VALUES (:oid, :xid, :ref, :amt, 'PHP', 'EWALLET', 'GCASH', 'pending', :raw)"
            )->execute([
                'oid' => $orderId,
                'xid' => $xenditId,
                'ref' => $referenceId,
                'amt' => $grandTotal,
                'raw' => json_encode($xenditBody),
            ]);

            $pdo->commit();

            // 8. Clear cart
            $_SESSION['cart'] = [];
            $pdo->prepare('DELETE FROM customer_cart WHERE user_id = :uid')->execute(['uid' => $customerId]);

            // 9. Redirect customer to Xendit GCash payment page
            $paymentUrl = '';
            foreach ($xenditBody['actions'] ?? [] as $action) {
                $url = $action['value'] ?? $action['url'] ?? '';
                if ($url) { $paymentUrl = $url; break; }
            }

            if ($paymentUrl) {
                header('Location: ' . $paymentUrl);
                exit;
            }

            header('Location: /BreadBreak/order-confirmation.php?ref=' . urlencode($referenceId));
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $checkoutError = $e->getMessage();
        }
    }
}

// Generate / refresh CSRF token
if (empty($_SESSION['checkout_csrf'])) {
    $_SESSION['checkout_csrf'] = bin2hex(random_bytes(24));
}

$pageTitle = 'Checkout | BreadBreak';
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
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="checkout-page">
<div class="container">

    <div class="section-heading" style="margin-bottom:2rem;">
        <span class="eyebrow">Step 2 of 2</span>
        <h1>Checkout</h1>
        <p>Review your order and confirm payment via GCash.</p>
    </div>

    <?php if ($checkoutError): ?>
    <div class="checkout-alert alert-error" role="alert">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($checkoutError); ?></span>
    </div>
    <?php endif; ?>

    <div class="checkout-layout">

        <!-- ── Left: Order Items ── -->
        <div>
            <div class="checkout-card" style="margin-bottom:1.5rem;">
                <div class="checkout-card-header">
                    <h2><i class="fa-solid fa-bag-shopping" style="margin-right:.5rem;color:var(--accent)"></i>Your Order Items</h2>
                </div>
                <div class="checkout-card-body" style="padding:0;">
                    <table class="order-table">
                        <thead>
                            <tr>
                                <th style="width:60px;"></th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cartItems as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['photo']): ?>
                                        <img class="item-image" src="<?php echo htmlspecialchars($item['photo']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" />
                                    <?php else: ?>
                                        <div class="item-image-placeholder"><i class="fa-solid fa-bread-slice"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <div class="item-meta"><?php echo htmlspecialchars($item['service_size']); ?></div>
                                </td>
                                <td><span class="item-meta"><?php echo htmlspecialchars($item['sku']); ?></span></td>
                                <td class="text-right"><?php echo (int) $item['quantity']; ?></td>
                                <td class="text-right price-cell">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                <td class="text-right price-cell">₱<?php echo number_format($item['line_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Delivery Address Card ── -->
            <div class="checkout-card">
                <div class="checkout-card-header">
                    <h2><i class="fa-solid fa-location-dot" style="margin-right:.5rem;color:var(--accent)"></i>Delivery Address</h2>
                </div>
                <div class="checkout-card-body">

                <?php
                // Load customer's saved addresses
                $savedAddressStmt = $pdo->prepare(
                    'SELECT id, label, full_address, barangay, city, province, postal_code, is_default
                     FROM customer_addresses WHERE customer_id = :cid ORDER BY is_default DESC, created_at ASC'
                );
                $hasSavedAddresses = false;
                try {
                    $savedAddressStmt->execute(['cid' => $customerId]);
                    $savedAddresses = $savedAddressStmt->fetchAll();
                    $hasSavedAddresses = !empty($savedAddresses);
                } catch (Throwable) {
                    $savedAddresses = [];
                }
                ?>

                <?php if ($hasSavedAddresses): ?>
                    <!-- Saved address radio cards -->
                    <p style="font-size:.88rem;color:var(--muted);margin:0 0 1rem;">Select your delivery address or use a different one below.</p>
                    <div class="saved-address-list" id="saved-address-list">
                    <?php foreach ($savedAddresses as $i => $addr):
                        $addrText = $addr['full_address'];
                        $addrMeta = implode(', ', array_filter([$addr['barangay'], $addr['city'], $addr['province']]));
                    ?>
                        <label class="saved-address-card<?php echo $addr['is_default'] ? ' is-checked' : ''; ?>" data-address-card>
                            <input type="radio" name="saved_address_radio"
                                value="<?php echo htmlspecialchars($addrText . ($addrMeta ? ', ' . $addrMeta : '')); ?>"
                                data-address-radio
                                <?php echo $addr['is_default'] ? 'checked' : ''; ?> />
                            <div class="saved-address-card-body">
                                <div class="saved-address-card-label">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <?php echo htmlspecialchars($addr['label']); ?>
                                    <?php if ($addr['is_default']): ?>
                                        <span class="address-default-badge" style="font-size:.72rem;"><i class="fa-solid fa-star"></i> Default</span>
                                    <?php endif; ?>
                                </div>
                                <div class="saved-address-card-text"><?php echo htmlspecialchars($addrText); ?></div>
                                <?php if ($addrMeta): ?>
                                    <div class="saved-address-card-meta"><?php echo htmlspecialchars($addrMeta); ?></div>
                                <?php endif; ?>
                                <div class="saved-address-zone-status" id="zone-status-<?php echo $addr['id']; ?>">
                                    <span class="zone-checking"><i class="fa-solid fa-spinner fa-spin"></i> Checking zone…</span>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    </div>

                    <!-- Use different address toggle -->
                    <div style="margin-top:1rem;">
                        <button type="button" class="addr-btn addr-btn-default" id="use-other-addr-toggle" style="width:100%;justify-content:center;">
                            <i class="fa-solid fa-plus" style="margin-right:.4rem;"></i> Use a different address
                        </button>
                        <div id="other-addr-wrap" style="display:none;margin-top:.85rem;">
                            <label class="delivery-address-label" for="delivery-address-input">
                                One-time Delivery Address <span style="color:#c00;">*</span>
                            </label>
                            <textarea id="delivery-address-input" name="delivery_address_display" class="delivery-address-textarea" rows="3"
                                placeholder="e.g. Blk 5 Lot 3, Ilang-Ilang, Guiguinto, Bulacan, 3015"
                            ></textarea>
                            <div id="zone-check-result" class="zone-check-result" aria-live="polite" style="display:none;"></div>
                        </div>
                    </div>

                    <p style="font-size:.8rem;color:var(--muted);margin-top:.75rem;">
                        <i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>
                        You can manage your saved addresses in <a href="/BreadBreak/customer/account.php" style="color:var(--accent);">My Account</a>.
                    </p>

                <?php else: ?>
                    <!-- No saved addresses — free-text input -->
                    <p style="font-size:.88rem;color:var(--muted);margin:0 0 1rem;">
                        Enter your full delivery address. We deliver within 8km of our branch in Estrella Village, Guiguinto, Bulacan.
                        <a href="/BreadBreak/customer/account.php" style="color:var(--accent);white-space:nowrap;"><i class="fa-solid fa-location-dot" style="margin-right:.2rem;"></i>Save addresses</a> in My Account for faster checkout.
                    </p>
                    <label class="delivery-address-label" for="delivery-address-input">
                        Delivery Address <span style="color:#c00;">*</span>
                    </label>
                    <textarea id="delivery-address-input" name="delivery_address_display" class="delivery-address-textarea" rows="3"
                        placeholder="e.g. Blk 5 Lot 3, Ilang-Ilang, Guiguinto, Bulacan, 3015"
                        required
                    ><?php echo htmlspecialchars($_POST['delivery_address'] ?? ''); ?></textarea>
                    <div id="zone-check-result" class="zone-check-result" aria-live="polite" style="display:none;"></div>
                    <p style="font-size:.8rem;color:var(--muted);margin-top:.6rem;">
                        <i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>
                        We'll check if your address is within our delivery zone.
                    </p>
                <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- ── Right: Summary + Payment ── -->
        <div class="summary-panel">
            <div class="summary-panel-header">
                <h2>Order Summary</h2>
            </div>
            <div class="summary-panel-body">
                <div class="payment-channel-badge">
                    <i class="fa-solid fa-mobile-screen"></i> Pay via GCash (TEST MODE)
                </div>

                <?php foreach ($cartItems as $item): ?>
                <div class="summary-row">
                    <span><?php echo htmlspecialchars($item['product_name']); ?> (<?php echo htmlspecialchars($item['service_size']); ?>) × <?php echo $item['quantity']; ?></span>
                    <span>₱<?php echo number_format($item['line_total'], 2); ?></span>
                </div>
                <?php endforeach; ?>

                <!-- Subtotal -->
                <div class="summary-row" style="border-top:1px solid var(--border);margin-top:.5rem;padding-top:.6rem;">
                    <span>Subtotal</span>
                    <span>₱<?php echo number_format($cartTotal, 2); ?></span>
                </div>

                <!-- Delivery fee (dynamic) -->
                <div class="summary-row" id="summary-delivery-row">
                    <span><i class="fa-solid fa-truck" style="margin-right:.3rem;font-size:.85em;"></i>Delivery Fee</span>
                    <span id="summary-delivery-fee" style="color:var(--muted);">—</span>
                </div>

                <!-- Grand total -->
                <div class="summary-row total-row">
                    <span>Total</span>
                    <span class="summary-total-amount" id="summary-grand-total">₱<?php echo number_format($cartTotal, 2); ?></span>
                </div>

                <p class="summary-note">
                    <i class="fa-solid fa-lock" style="margin-right:.35rem;"></i>
                    Prices are confirmed from our database. You will be redirected to Xendit's secure GCash payment page.
                </p>
            </div>
            <div class="summary-panel-footer">
                <form method="POST" id="checkout-form">
                    <input type="hidden" name="action" value="confirm_order" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['checkout_csrf']); ?>" />
                    <!-- Hidden fields filled by JS -->
                    <input type="hidden" name="delivery_address" id="hidden-delivery-address" value="" />
                    <input type="hidden" name="delivery_fee" id="hidden-delivery-fee" value="0" />

                    <button class="btn btn-primary" type="submit" id="place-order-btn" disabled style="width:100%;font-size:1rem;padding:.9rem 1.2rem;opacity:.6;cursor:not-allowed;">
                        <i class="fa-solid fa-mobile-screen" style="margin-right:.5rem;"></i>
                        Confirm &amp; Pay via GCash
                    </button>
                    <p id="place-order-hint" style="font-size:.8rem;color:var(--muted);text-align:center;margin-top:.5rem;">
                        Enter your delivery address to continue.
                    </p>
                </form>
                <a href="/BreadBreak/cart.php" style="display:block;text-align:center;margin-top:1rem;font-size:.9rem;color:var(--muted);">
                    <i class="fa-solid fa-chevron-left" style="margin-right:.3rem;"></i> Back to Cart
                </a>
            </div>
        </div>

    </div>
</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
    'use strict';

    // ── DOM refs ──────────────────────────────────────────────────────────────
    const placeOrderBtn   = document.getElementById('place-order-btn');
    const placeOrderHint  = document.getElementById('place-order-hint');
    const hiddenAddr      = document.getElementById('hidden-delivery-address');
    const hiddenFee       = document.getElementById('hidden-delivery-fee');
    const feeDisplay      = document.getElementById('summary-delivery-fee');
    const grandTotalEl    = document.getElementById('summary-grand-total');
    const cartSubtotal    = <?php echo json_encode($cartTotal); ?>;

    // Saved address mode elements
    const savedAddrList   = document.getElementById('saved-address-list');
    const radios          = savedAddrList ? savedAddrList.querySelectorAll('[data-address-radio]') : [];
    const addrCards       = savedAddrList ? savedAddrList.querySelectorAll('[data-address-card]') : [];
    const useOtherToggle  = document.getElementById('use-other-addr-toggle');
    const otherAddrWrap   = document.getElementById('other-addr-wrap');

    // Free-text mode elements
    const addrTextarea    = document.getElementById('delivery-address-input');
    const resultBox       = document.getElementById('zone-check-result');

    let checkTimer = null;
    let lastChecked = '';
    let usingOtherAddr = false;

    // ── Helpers ───────────────────────────────────────────────────────────────
    function formatPHP(amount) {
        return '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateSummary(fee) {
        if (fee === null) {
            feeDisplay.textContent = '—';
            feeDisplay.style.color = 'var(--muted)';
            grandTotalEl.textContent = formatPHP(cartSubtotal);
        } else if (fee === 0) {
            feeDisplay.textContent = 'Free';
            feeDisplay.style.color = '#1a6645';
            grandTotalEl.textContent = formatPHP(cartSubtotal);
        } else {
            feeDisplay.textContent = formatPHP(fee);
            feeDisplay.style.color = 'var(--text)';
            grandTotalEl.textContent = formatPHP(cartSubtotal + fee);
        }
    }

    function setOrderButton(enabled, hint) {
        placeOrderBtn.disabled = !enabled;
        placeOrderBtn.style.opacity = enabled ? '1' : '.6';
        placeOrderBtn.style.cursor  = enabled ? 'pointer' : 'not-allowed';
        placeOrderHint.textContent  = hint || '';
    }

    function showInlineResult(box, type, message) {
        if (!box) return;
        box.style.display = 'flex';
        box.className = 'zone-check-result zone-' + type;
        const icons = { loading:'fa-spinner fa-spin', allowed:'fa-circle-check', blocked:'fa-circle-xmark', warning:'fa-triangle-exclamation' };
        box.innerHTML = '<i class="fa-solid ' + (icons[type] || 'fa-info') + '"></i><span>' + message + '</span>';
    }

    // ── Zone check API call ───────────────────────────────────────────────────
    async function checkZone(address, onResult) {
        try {
            const resp = await fetch('/BreadBreak/api/delivery/check-address.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ address }),
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            onResult(data);
        } catch (err) {
            onResult({ allowed: true, delivery_fee: 0, min_order: 0, message: 'Unable to verify zone. Order will be reviewed by staff.', _error: true });
        }
    }

    // ── SAVED ADDRESS MODE ────────────────────────────────────────────────────
    if (savedAddrList && radios.length > 0) {

        // Pre-check all addresses on page load
        const addressData = <?php
            $addrJsonList = array_map(fn($a) => [
                'id'   => $a['id'],
                'text' => $a['full_address'] . (
                    ($m = implode(', ', array_filter([$a['barangay'], $a['city'], $a['province']])))
                    ? ', ' . $m : ''
                ),
            ], $savedAddresses ?? []);
            echo json_encode($addrJsonList);
        ?>;

        let checkedCount = 0;

        addressData.forEach(function (addr) {
            const statusEl = document.getElementById('zone-status-' + addr.id);
            checkZone(addr.text, function (data) {
                checkedCount++;
                if (statusEl) {
                    if (data.allowed) {
                        const fee = data.delivery_fee > 0 ? ' · ' + formatPHP(data.delivery_fee) + ' delivery fee' : ' · Free delivery';
                        statusEl.innerHTML = '<span style="color:#1a6645;font-size:.8rem;"><i class="fa-solid fa-circle-check"></i> ' + (data.message || 'Delivery available') + fee + '</span>';
                    } else {
                        statusEl.innerHTML = '<span style="color:#891515;font-size:.8rem;"><i class="fa-solid fa-circle-xmark"></i> Outside delivery zone</span>';
                    }
                    // Store zone data on the radio input
                    const radio = savedAddrList.querySelector('[data-address-radio][value="' + CSS.escape(addr.text) + '"]') ||
                                  [...radios].find(r => r.value === addr.text);
                    if (radio) {
                        radio.dataset.zoneAllowed = data.allowed ? '1' : '0';
                        radio.dataset.zoneFee     = data.delivery_fee ?? 0;
                        radio.dataset.zoneMinOrder= data.min_order ?? 0;
                    }
                }
                // After all checked, init based on currently selected radio
                if (checkedCount === addressData.length) {
                    applySelectedRadio();
                }
            });
        });

        function applySelectedRadio() {
            if (usingOtherAddr) return;
            const checked = [...radios].find(r => r.checked);
            if (!checked) { setOrderButton(false, 'Select a delivery address to continue.'); return; }

            // Update card styles
            addrCards.forEach(c => c.classList.remove('is-checked'));
            if (checked.closest('[data-address-card]')) checked.closest('[data-address-card]').classList.add('is-checked');

            const allowed  = checked.dataset.zoneAllowed;
            const fee      = parseInt(checked.dataset.zoneFee ?? 0, 10);
            const minOrder = parseInt(checked.dataset.zoneMinOrder ?? 0, 10);

            if (allowed === undefined) {
                // Still loading
                setOrderButton(false, 'Checking delivery zone…');
                updateSummary(null);
                return;
            }
            if (allowed === '0') {
                hiddenFee.value  = 0;
                hiddenAddr.value = '';
                updateSummary(null);
                setOrderButton(false, 'This address is outside our delivery zone.');
                return;
            }
            if (minOrder > 0 && cartSubtotal < minOrder) {
                hiddenFee.value  = 0;
                hiddenAddr.value = '';
                updateSummary(null);
                setOrderButton(false, 'Minimum order of ₱' + minOrder.toLocaleString() + ' required for this zone.');
                return;
            }
            hiddenAddr.value = checked.value;
            hiddenFee.value  = fee;
            updateSummary(fee);
            setOrderButton(true, '');
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                usingOtherAddr = false;
                if (otherAddrWrap) otherAddrWrap.style.display = 'none';
                if (useOtherToggle) useOtherToggle.innerHTML = '<i class="fa-solid fa-plus" style="margin-right:.4rem;"></i> Use a different address';
                applySelectedRadio();
            });
        });

        // "Use a different address" toggle
        if (useOtherToggle && otherAddrWrap) {
            useOtherToggle.addEventListener('click', function () {
                usingOtherAddr = !usingOtherAddr;
                otherAddrWrap.style.display = usingOtherAddr ? 'block' : 'none';
                useOtherToggle.innerHTML = usingOtherAddr
                    ? '<i class="fa-solid fa-chevron-up" style="margin-right:.4rem;"></i> Cancel — use saved address'
                    : '<i class="fa-solid fa-plus" style="margin-right:.4rem;"></i> Use a different address';
                if (!usingOtherAddr) {
                    // Revert to radio selection
                    radios.forEach(r => { if (r.checked) r.dispatchEvent(new Event('change')); });
                } else {
                    // Uncheck all radios, reset button
                    radios.forEach(r => r.checked = false);
                    addrCards.forEach(c => c.classList.remove('is-checked'));
                    hiddenAddr.value = '';
                    hiddenFee.value = 0;
                    updateSummary(null);
                    setOrderButton(false, 'Enter your delivery address to continue.');
                    if (resultBox) resultBox.style.display = 'none';
                    lastChecked = '';
                    if (addrTextarea) addrTextarea.focus();
                }
            });
        }

        // Init: trigger apply after a tick (zone checks may still be loading)
        setTimeout(applySelectedRadio, 100);
    }

    // ── FREE-TEXT MODE (no saved addresses, OR "other address") ──────────────
    if (addrTextarea) {
        async function runFreeTextCheck(address) {
            if (address === lastChecked) return;
            lastChecked = address;
            if (resultBox) showInlineResult(resultBox, 'loading', 'Checking delivery availability…');
            setOrderButton(false, 'Checking your address…');
            updateSummary(null);

            await checkZone(address, function (data) {
                hiddenAddr.value = address;
                if (data.allowed) {
                    const fee = data.delivery_fee ?? 0;
                    hiddenFee.value = fee;
                    updateSummary(fee);
                    if (resultBox) showInlineResult(resultBox, data.drive_warning ? 'warning' : 'allowed', data.message || 'Delivery available.');
                    if (data.min_order && cartSubtotal < data.min_order) {
                        if (resultBox) showInlineResult(resultBox, 'blocked', 'Minimum order of ₱' + data.min_order.toLocaleString() + ' required for this zone.');
                        setOrderButton(false, 'Minimum order not met for your zone.');
                    } else {
                        setOrderButton(true, '');
                    }
                } else {
                    hiddenFee.value = 0;
                    updateSummary(null);
                    if (resultBox) showInlineResult(resultBox, 'blocked', data.message || "Sorry, we don't deliver to your area yet.");
                    setOrderButton(false, 'Delivery not available to this address.');
                }
            });
        }

        addrTextarea.addEventListener('input', function () {
            const val = this.value.trim();
            clearTimeout(checkTimer);
            if (val.length < 5) {
                if (resultBox) resultBox.style.display = 'none';
                if (!savedAddrList) setOrderButton(false, 'Enter your delivery address to continue.');
                updateSummary(null);
                lastChecked = '';
                return;
            }
            checkTimer = setTimeout(() => runFreeTextCheck(val), 800);
        });

        addrTextarea.addEventListener('blur', function () {
            const val = this.value.trim();
            if (val.length >= 5) { clearTimeout(checkTimer); runFreeTextCheck(val); }
        });

        // Pre-fill check on load (no saved addresses path)
        if (!savedAddrList) {
            const prefilled = addrTextarea.value.trim();
            if (prefilled.length >= 5) runFreeTextCheck(prefilled);
            else setOrderButton(false, 'Enter your delivery address to continue.');
        }
    }
})();
</script>
</body>
</html>
