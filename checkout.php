<?php
// ============================================================
// checkout.php  –  BreadBreak order review + Xendit payment
// Features:
// 1. Delivery vs Store Pickup (with branch location map)
// 2. Saved Address Book & Real-time Zone Check
// 3. 12% VAT Breakdown & Senior Citizen / PWD 20% Discount
// 4. Smart ID & Name validation checkers
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/xendit.php';

$pdo = getDatabaseConnection();

// ── Ensure tables & columns exist ──
$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    reference_id VARCHAR(32) NOT NULL UNIQUE,
    status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
    fulfillment_type ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vatable_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_exempt_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_type ENUM('none','senior','pwd') NOT NULL DEFAULT 'none',
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_id_number VARCHAR(100) NULL,
    discount_name VARCHAR(150) NULL,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_address TEXT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
)");

$customerId = (int) $_SESSION['user_id'];

// ── Load cart items with FRESH prices from DB ──
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

// ── Load delivery settings ──
function getDeliverySetting(PDO $pdo, string $key, string $default = ''): string {
    $s = $pdo->prepare('SELECT setting_value FROM delivery_settings WHERE setting_key = :k LIMIT 1');
    $s->execute(['k' => $key]);
    return (string) ($s->fetchColumn() ?: $default);
}

$deliveryMode  = getDeliverySetting($pdo, 'delivery_mode', 'simple');
$inRangeAreas  = json_decode(getDeliverySetting($pdo, 'in_range_areas', '[]'), true) ?: [];
$deliveryZones = json_decode(getDeliverySetting($pdo, 'delivery_zones', '[]'), true) ?: [];
$branchLat     = (float) getDeliverySetting($pdo, 'branch_lat', '14.830905');
$branchLng     = (float) getDeliverySetting($pdo, 'branch_lng', '120.869675');

function deliveryHaversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function maxDeliveryKmFromZones(array $zones): float {
    $maxKm = 0.0;
    foreach ($zones as $zone) {
        $maxKm = max($maxKm, (float) ($zone['max_km'] ?? 0));
    }
    return $maxKm > 0 ? $maxKm : 8.0;
}

$maxDeliveryKm = maxDeliveryKmFromZones($deliveryZones);

function serverCheckDelivery(string $address, string $mode, array $inAreas, array $zones): array {
    if ($mode === 'simple') {
        $normalized = mb_strtolower(trim($address));
        foreach ($inAreas as $area) {
            if (str_contains($normalized, mb_strtolower($area))) {
                $zone = $zones[0] ?? ['name' => 'zone_1', 'fee' => 50, 'min_order' => 0];
                $fee = isset($zone['fee']) ? (int) $zone['fee'] : 50;
                return ['allowed' => true, 'fee' => $fee, 'min_order' => (int) ($zone['min_order'] ?? 0)];
            }
        }
        return ['allowed' => false, 'fee' => 0, 'min_order' => 0];
    }
    return ['allowed' => true, 'fee' => 50, 'min_order' => 0];
}

// ── Handle POST: create order + payment request ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_order') {

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals((string) ($_SESSION['checkout_csrf'] ?? ''), $token)) {
        $checkoutError = 'Invalid form submission. Please try again.';
    }

    $fulfillmentType = in_array($_POST['fulfillment_type'] ?? '', ['delivery', 'pickup'], true) ? $_POST['fulfillment_type'] : 'delivery';
    $deliveryAddress = '';
    $deliveryFeePost = 0.00;

    if ($fulfillmentType === 'pickup') {
        $deliveryAddress = 'BreadBreak Bakery - Estrella Village, Guiguinto, Bulacan (Store Pickup)';
        $deliveryFeePost = 0.00;
    } else {
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $deliveryFeePost = max(0, (float) ($_POST['delivery_fee'] ?? 0));

        if (!$checkoutError && $deliveryAddress === '') {
            $checkoutError = 'Please select or enter your delivery address.';
        }

        $pinValidated = false;
        if (!$checkoutError) {
            $postedLat = trim((string) ($_POST['delivery_lat'] ?? ''));
            $postedLng = trim((string) ($_POST['delivery_lng'] ?? ''));
            if ($postedLat !== '' && $postedLng !== '' && is_numeric($postedLat) && is_numeric($postedLng)) {
                $pinKm = deliveryHaversineKm($branchLat, $branchLng, (float) $postedLat, (float) $postedLng);
                if ($pinKm > $maxDeliveryKm) {
                    $checkoutError = "Sorry, we don't deliver to your area yet. BreadBreak delivers within {$maxDeliveryKm}km of our branch in Estrella Village, Guiguinto.";
                } else {
                    $pinValidated = true;
                    $matchedFee = 50.0;
                    $sortedZones = $deliveryZones;
                    usort($sortedZones, static fn($a, $b) => ((float) ($a['max_km'] ?? 0)) <=> ((float) ($b['max_km'] ?? 0)));
                    foreach ($sortedZones as $zone) {
                        if ($pinKm <= (float) ($zone['max_km'] ?? 0)) {
                            $matchedFee = (float) ($zone['fee'] ?? 50);
                            break;
                        }
                    }
                    $deliveryFeePost = $matchedFee;
                }
            }
        }

        if (!$checkoutError && !$pinValidated) {
            $zoneCheck = serverCheckDelivery($deliveryAddress, $deliveryMode, $inRangeAreas, $deliveryZones);
            if (!$zoneCheck['allowed']) {
                $checkoutError = "Sorry, we don't deliver to your area yet. BreadBreak delivers within 8km of our branch in Estrella Village, Guiguinto.";
            }
            if ($deliveryMode === 'simple') {
                $deliveryFeePost = (float) $zoneCheck['fee'];
            }
        }
    }

    // Discount validation with strict checker
    $applyDiscount    = !empty($_POST['apply_discount']) && $_POST['apply_discount'] === '1';
    $discountTypePost = in_array($_POST['discount_type'] ?? '', ['senior', 'pwd'], true) ? $_POST['discount_type'] : 'none';
    $discountIdNumber = trim(strip_tags($_POST['discount_id_number'] ?? ''));
    $discountName     = trim(strip_tags($_POST['discount_name'] ?? ''));

    if (!$checkoutError && $applyDiscount) {
        // ID number validation
        $cleanId = preg_replace('/\s+/', ' ', $discountIdNumber);
        $idLen = strlen($cleanId);
        $isRepeated = preg_match('/^(.)\1+$/', $cleanId);

        if ($idLen < 4 || $idLen > 30 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\s\/]{2,28}[A-Za-z0-9]$/', $cleanId) || $isRepeated) {
            $checkoutError = 'Please enter a valid Senior Citizen or PWD ID number (4 to 30 characters).';
        }

        // Cardholder name validation
        $cleanName = preg_replace('/\s+/', ' ', $discountName);
        $nameLen = strlen($cleanName);
        if (!$checkoutError && ($nameLen < 4 || $nameLen > 70 || !preg_match('/^[A-Za-zÀ-ÿ\s\.\-]{4,70}$/', $cleanName))) {
            $checkoutError = 'Please enter the cardholder\'s valid full name (letters only, as printed on the ID).';
        }
    }

    // ── Payment method ──
    $allowedMethods = ['GCASH', 'PAYMAYA', 'GRABPAY', 'SHOPEEPAY', 'CARD', 'CASH'];
    $paymentMethod  = strtoupper(trim($_POST['payment_method'] ?? 'GCASH'));
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $paymentMethod = 'GCASH';
    }

    // Cash amount validation (done after grand total calculated below)
    $cashAmountRaw = null;
    if ($paymentMethod === 'CASH') {
        $cashAmountRaw = (float) ($_POST['cash_amount'] ?? 0);
    }

    // Calculations
    $subtotal = $cartTotal;

    if ($applyDiscount && in_array($discountTypePost, ['senior', 'pwd'], true)) {
        $vatExemptSales = round($subtotal / 1.12, 2);
        $vatableSales   = 0.00;
        $vatAmount      = 0.00;
        $discountAmount = round($vatExemptSales * 0.20, 2);
        $netItemsTotal  = round($vatExemptSales - $discountAmount, 2);
        $discountType   = $discountTypePost;
    } else {
        $vatableSales   = round($subtotal / 1.12, 2);
        $vatAmount      = round($subtotal - $vatableSales, 2);
        $vatExemptSales = 0.00;
        $discountAmount = 0.00;
        $discountType   = 'none';
        $discountIdNumber = null;
        $discountName     = null;
        $netItemsTotal    = $subtotal;
    }

    $grandTotal = round($netItemsTotal + $deliveryFeePost, 2);

    // Validate cash amount AFTER grand total is known
    if (!$checkoutError && $paymentMethod === 'CASH') {
        if ($cashAmountRaw < $grandTotal) {
            $checkoutError = 'Cash amount must be at least ₱' . number_format($grandTotal, 2) . ' (the total to pay).';
        } elseif ($cashAmountRaw > ($grandTotal * 3) || $cashAmountRaw > 5000) {
            $checkoutError = 'Cash amount seems too large. Please enter the actual cash denomination you will bring (max ₱5,000).';
        }
    }

    if (!$checkoutError) {
        foreach ($cartItems as $item) {
            if ($item['quantity'] > $item['stock_qty']) {
                $checkoutError = htmlspecialchars($item['product_name']) . ' (' . htmlspecialchars($item['service_size']) . ') no longer has enough stock. Please update your cart.';
                break;
            }
        }
    }

    if (!$checkoutError) {
        $pdo->beginTransaction();
        try {
            $referenceId = generateReferenceId();
            $pdo->prepare(
                "INSERT INTO orders 
                    (customer_id, reference_id, status, fulfillment_type, subtotal, vatable_sales, vat_amount, vat_exempt_sales,
                     discount_type, discount_amount, discount_id_number, discount_name,
                     delivery_fee, delivery_address, total_amount, payment_method, cash_amount)
                 VALUES 
                    (:cid, :ref, 'pending', :ftype, :sub, :vsales, :vamt, :vexempt,
                     :dtype, :damt, :did, :dname,
                     :fee, :addr, :total, :pm, :ca)"
            )->execute([
                'cid'      => $customerId,
                'ref'      => $referenceId,
                'ftype'    => $fulfillmentType,
                'sub'      => $subtotal,
                'vsales'   => $vatableSales,
                'vamt'     => $vatAmount,
                'vexempt'  => $vatExemptSales,
                'dtype'    => $discountType,
                'damt'     => $discountAmount,
                'did'      => $discountIdNumber,
                'dname'    => $discountName,
                'fee'      => $deliveryFeePost,
                'addr'     => $deliveryAddress,
                'total'    => $grandTotal,
                'pm'       => $paymentMethod,
                'ca'       => $paymentMethod === 'CASH' ? $cashAmountRaw : null,
            ]);
            $orderId = (int) $pdo->lastInsertId();

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

            if ($fulfillmentType === 'delivery') {
                try {
                    $pdo->prepare(
                        "INSERT INTO delivery_zone_logs
                            (order_id, customer_address, zone, delivery_fee_charged, mode_used)
                         VALUES (:oid, :addr, 'zone_1', :fee, :mode)"
                    )->execute(['oid' => $orderId, 'addr' => $deliveryAddress, 'fee' => $deliveryFeePost, 'mode' => $deliveryMode]);
                } catch (Throwable) { /* non-fatal */ }
            }

            // ── CASH: skip Xendit, log payment locally ──
            if ($paymentMethod === 'CASH') {
                $pdo->prepare(
                    "INSERT INTO payments
                        (order_id, xendit_payment_request_id, reference_id, amount, currency,
                         payment_method, payment_channel, status, xendit_raw_response)
                     VALUES (:oid, '', :ref, :amt, 'PHP', 'CASH', 'CASH', 'pending', '')"
                )->execute([
                    'oid' => $orderId,
                    'ref' => $referenceId,
                    'amt' => $grandTotal,
                ]);

                $pdo->commit();
                $_SESSION['cart'] = [];
                $pdo->prepare('DELETE FROM customer_cart WHERE user_id = :uid')->execute(['uid' => $customerId]);

                header('Location: /BreadBreak/order-confirmation.php?ref=' . urlencode($referenceId));
                exit;
            }

            // ── E-WALLET / CARD: send to Xendit ──
            $channelCodeMap = [
                'GCASH'    => 'GCASH',
                'PAYMAYA'  => 'PAYMAYA',
                'GRABPAY'  => 'GRABPAY',
                'SHOPEEPAY'=> 'SHOPEEPAY',
            ];

            // Description for Xendit
            $descParts = array_map(fn($it) => $it['product_name'] . ' (' . $it['service_size'] . ') x' . $it['quantity'], $cartItems);
            $description = implode(', ', $descParts);
            $description .= ($fulfillmentType === 'pickup' ? ' [Pickup]' : ' [Delivery]');
            if ($discountType !== 'none') $description .= ' [' . strtoupper($discountType) . ' Disc]';
            if (strlen($description) > 255) $description = substr($description, 0, 252) . '...';

            if ($paymentMethod === 'CARD') {
                // Credit/Debit Card via Xendit Payment Request
                $xenditPayload = [
                    'reference_id'   => $referenceId,
                    'type'           => 'PAY',
                    'country'        => 'PH',
                    'currency'       => 'PHP',
                    'request_amount' => $grandTotal,
                    'capture_method' => 'AUTOMATIC',
                    'payment_method' => [
                        'type'        => 'CARD',
                        'reusability' => 'ONE_TIME_USE',
                        'card'        => [],
                    ],
                    'channel_properties' => [
                        'success_return_url' => XENDIT_SUCCESS_RETURN_URL . '&ref=' . urlencode($referenceId),
                        'failure_return_url' => XENDIT_FAILURE_RETURN_URL . '&ref=' . urlencode($referenceId),
                        'cancel_return_url'  => XENDIT_FAILURE_RETURN_URL . '&ref=' . urlencode($referenceId),
                    ],
                    'description' => $description,
                    'metadata'    => ['order_id' => $orderId, 'source' => 'BreadBreak', 'fulfillment' => $fulfillmentType],
                ];
                $pmLabel = 'CARD';
                $pcLabel = 'CREDIT_CARD';
            } else {
                $channelCode = $channelCodeMap[$paymentMethod] ?? 'GCASH';
                $xenditPayload = [
                    'reference_id'     => $referenceId,
                    'type'             => 'PAY',
                    'country'          => 'PH',
                    'currency'         => 'PHP',
                    'request_amount'   => $grandTotal,
                    'capture_method'   => 'AUTOMATIC',
                    'channel_code'     => $channelCode,
                    'channel_properties' => [
                        'success_return_url' => XENDIT_SUCCESS_RETURN_URL . '&ref=' . urlencode($referenceId),
                        'failure_return_url' => XENDIT_FAILURE_RETURN_URL . '&ref=' . urlencode($referenceId),
                        'cancel_return_url'  => XENDIT_FAILURE_RETURN_URL . '&ref=' . urlencode($referenceId),
                    ],
                    'description' => $description,
                    'metadata'    => ['order_id' => $orderId, 'source' => 'BreadBreak', 'fulfillment' => $fulfillmentType],
                ];
                $pmLabel = 'EWALLET';
                $pcLabel = $channelCode;
            }

            $xenditResult = xenditRequest('POST', '/v3/payment_requests', $xenditPayload);

            if (!$xenditResult['ok']) {
                $errBody = $xenditResult['body'];
                $errMsg  = $errBody['message'] ?? $errBody['error_code'] ?? json_encode($errBody);
                throw new RuntimeException('Xendit error: ' . $errMsg);
            }

            $xenditBody = $xenditResult['body'];
            $xenditId   = $xenditBody['payment_request_id'] ?? ($xenditBody['id'] ?? '');

            $pdo->prepare(
                "INSERT INTO payments
                    (order_id, xendit_payment_request_id, reference_id, amount, currency,
                     payment_method, payment_channel, status, xendit_raw_response)
                 VALUES (:oid, :xid, :ref, :amt, 'PHP', :pm, :pc, 'pending', :raw)"
            )->execute([
                'oid' => $orderId,
                'xid' => $xenditId,
                'ref' => $referenceId,
                'amt' => $grandTotal,
                'pm'  => $pmLabel,
                'pc'  => $pcLabel,
                'raw' => json_encode($xenditBody),
            ]);

            $pdo->commit();

            $_SESSION['cart'] = [];
            $pdo->prepare('DELETE FROM customer_cart WHERE user_id = :uid')->execute(['uid' => $customerId]);

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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="checkout-page">
<div class="container">

    <div class="section-heading" style="margin-bottom:2rem;">
        <span class="eyebrow">Step 2 of 2</span>
        <h1>Checkout</h1>
        <p>Review your order, choose delivery or store pickup, and confirm payment via GCash.</p>
    </div>

    <?php if ($checkoutError): ?>
    <div class="checkout-alert alert-error" role="alert">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($checkoutError); ?></span>
    </div>
    <?php endif; ?>

    <div class="checkout-layout">

        <!-- ── Left Column: Items, Fulfillment Choice, Address, Senior/PWD, Payment ── -->
        <div class="checkout-main">

            <!-- ── 1. Your Order Items Card ── -->
            <div class="checkout-card" id="order-items-card">
                <div class="checkout-card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h2><i class="fa-solid fa-bag-shopping" style="margin-right:.5rem;color:var(--accent)"></i>Your Order Items</h2>
                    <span style="font-size:.82rem;font-weight:700;background:var(--cream);color:var(--brown-900);border:1px solid var(--border);padding:.25rem .7rem;border-radius:100px;">
                        <?php echo count($cartItems); ?> <?php echo count($cartItems) === 1 ? 'item' : 'items'; ?>
                    </span>
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

            <!-- ── 2. Fulfillment Option Card (Below Order Items) ── -->
            <div class="fulfillment-selector-card" id="fulfillment-selector-card">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                    <div>
                        <h2 style="font-size:1.15rem;margin:0;color:var(--brown-900);">
                            <i class="fa-solid fa-box-open" style="margin-right:.5rem;color:var(--accent)"></i>Fulfillment Option
                        </h2>
                        <span style="font-size:.8rem;color:var(--muted);">Select how you want to receive your order</span>
                    </div>
                </div>
                <div class="fulfillment-toggle-group" role="tablist">
                    <button type="button" class="fulfillment-toggle-btn is-active" id="toggle-delivery-btn" data-mode="delivery">
                        <i class="fa-solid fa-truck"></i> Delivery
                    </button>
                    <button type="button" class="fulfillment-toggle-btn" id="toggle-pickup-btn" data-mode="pickup">
                        <i class="fa-solid fa-store"></i> Store Pickup (Free)
                    </button>
                </div>
            </div>

            <!-- ── Delivery Address Section (Hidden when Pickup selected) ── -->
            <div class="checkout-card" id="delivery-address-card" style="margin-bottom:1.5rem;">
                <div class="checkout-card-header">
                    <h2><i class="fa-solid fa-location-dot" style="margin-right:.5rem;color:var(--accent)"></i>Delivery Address</h2>
                </div>
                <div class="checkout-card-body">

                <?php
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

                <?php
                $checkoutMapPickerHtml = '
                    <div class="checkout-map-picker">
                        <div class="checkout-map-picker-head">
                            <div>
                                <strong><i class="fa-solid fa-map-location-dot"></i> Pin Your Location on Map</strong>
                                <p>Click on the map or drag the pin to auto-fill your address. Green circle is our 8km delivery zone.</p>
                            </div>
                            <button type="button" id="checkout-btn-locate-me" class="addr-btn addr-btn-default">
                                <i class="fa-solid fa-crosshairs"></i> Use My GPS Location
                            </button>
                        </div>
                        <div id="checkout-address-map"></div>
                        <div class="checkout-map-picker-foot">
                            <span id="checkout-map-zone-status"><i class="fa-solid fa-circle-info"></i> Click anywhere on map to set your pin</span>
                            <span id="checkout-map-distance-km"></span>
                        </div>
                    </div>
                ';
                ?>

                <?php if ($hasSavedAddresses): ?>
                    <p style="font-size:.88rem;color:var(--muted);margin:0 0 1rem;">Select your delivery address or use a different one below.</p>
                    <div class="saved-address-list" id="saved-address-list">
                    <?php foreach ($savedAddresses as $i => $addr):
                        $addrText = $addr['full_address'];
                        $addrMeta = implode(', ', array_filter([$addr['barangay'], $addr['city'], $addr['province']]));
                        $fullSearchString = $addrText . ($addrMeta ? ', ' . $addrMeta : '');
                        $initCheck = serverCheckDelivery($fullSearchString, $deliveryMode, $inRangeAreas, $deliveryZones);
                        $isAllowed = !empty($initCheck['allowed']);
                        $zoneFee = $isAllowed ? 50 : 0;
                    ?>
                        <label class="saved-address-card<?php echo $addr['is_default'] ? ' is-checked' : ''; ?>" data-address-card>
                            <input type="radio" name="saved_address_radio"
                                value="<?php echo htmlspecialchars($fullSearchString); ?>"
                                data-address-radio
                                data-address-id="<?php echo (int) $addr['id']; ?>"
                                data-zone-allowed="<?php echo $isAllowed ? '1' : '0'; ?>"
                                data-zone-fee="<?php echo $zoneFee; ?>"
                                data-zone-min-order="0"
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
                                    <?php if ($isAllowed): ?>
                                        <span style="color:#1a6645;font-size:.82rem;font-weight:600;"><i class="fa-solid fa-circle-check" style="margin-right:.3rem;"></i>Delivery available · ₱50.00 delivery fee</span>
                                    <?php else: ?>
                                        <div style="color:#891515;font-size:.82rem;margin-top:.4rem;background:#fff5f5;border:1px solid #fecaca;border-radius:10px;padding:.6rem .85rem;">
                                            <div style="font-weight:700;display:flex;align-items:center;gap:.35rem;"><i class="fa-solid fa-circle-xmark" style="color:#e53e3e;"></i> Outside 8km Delivery Zone</div>
                                            <div style="margin-top:.25rem;font-size:.78rem;color:#742a2a;line-height:1.4;">Delivery is unavailable for this address, but you can choose <strong>Store Pickup (Free)</strong>!</div>
                                            <button type="button" class="addr-btn addr-btn-default switch-to-pickup-btn" style="margin-top:.45rem;font-size:.78rem;padding:.3rem .75rem;background:#fff;border:1.5px solid var(--accent);color:var(--accent);font-weight:700;cursor:pointer;border-radius:6px;display:inline-flex;align-items:center;gap:.35rem;"><i class="fa-solid fa-store"></i> Switch to Store Pickup (Free)</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    </div>

                    <div style="margin-top:1rem;">
                        <button type="button" class="addr-btn addr-btn-default" id="use-other-addr-toggle" style="width:100%;justify-content:center;">
                            <i class="fa-solid fa-plus" style="margin-right:.4rem;"></i> Use a different address
                        </button>
                        <div id="other-addr-wrap" style="display:none;margin-top:.85rem;">
                            <?php echo $checkoutMapPickerHtml; ?>
                            <label class="delivery-address-label" for="delivery-address-input">
                                One-time Delivery Address <span style="color:#c00;">*</span>
                            </label>
                            <textarea id="delivery-address-input" name="delivery_address_display" class="delivery-address-textarea" rows="3"
                                placeholder="e.g. Blk 5 Lot 3, Ilang-Ilang, Guiguinto, Bulacan, 3015"
                            ></textarea>
                            <p class="checkout-map-edit-hint">You can edit the auto-filled address if a house number or landmark is missing.</p>
                            <div id="zone-check-result" class="zone-check-result" aria-live="polite" style="display:none;"></div>
                        </div>
                    </div>

                    <p style="font-size:.8rem;color:var(--muted);margin-top:.75rem;">
                        <i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>
                        Manage addresses in <a href="/BreadBreak/customer/account.php" style="color:var(--accent);">My Account</a>.
                    </p>

                <?php else: ?>
                    <p style="font-size:.88rem;color:var(--muted);margin:0 0 1rem;">
                        Pin your location or enter your full delivery address. We deliver within 8km of our branch in Estrella Village, Guiguinto, Bulacan.
                        <a href="/BreadBreak/customer/account.php" style="color:var(--accent);white-space:nowrap;"><i class="fa-solid fa-location-dot" style="margin-right:.2rem;"></i>Save addresses</a> in My Account.
                    </p>
                    <?php echo $checkoutMapPickerHtml; ?>
                    <label class="delivery-address-label" for="delivery-address-input">
                        Delivery Address <span style="color:#c00;">*</span>
                    </label>
                    <textarea id="delivery-address-input" name="delivery_address_display" class="delivery-address-textarea" rows="3"
                        placeholder="e.g. Blk 5 Lot 3, Ilang-Ilang, Guiguinto, Bulacan, 3015"
                        required
                    ><?php echo htmlspecialchars($_POST['delivery_address'] ?? ''); ?></textarea>
                    <p class="checkout-map-edit-hint">You can edit the auto-filled address if a house number or landmark is missing.</p>
                    <div id="zone-check-result" class="zone-check-result" aria-live="polite" style="display:none;"></div>
                <?php endif; ?>

                </div>
            </div>

            <!-- ── Store Pickup Info Card (Shown when Pickup selected) ── -->
            <div class="checkout-card" id="store-pickup-card" style="display:none;margin-bottom:1.5rem;">
                <div class="checkout-card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h2><i class="fa-solid fa-store" style="margin-right:.5rem;color:var(--accent)"></i>Store Pickup Details</h2>
                    <span class="pickup-free-badge"><i class="fa-solid fa-check"></i> Free Pickup</span>
                </div>
                <div class="checkout-card-body">
                    <div class="pickup-branch-header">
                        <div>
                            <h3 class="pickup-branch-title"><i class="fa-solid fa-location-dot"></i> BreadBreak — Estrella Village Branch</h3>
                            <p class="pickup-branch-address">Estrella Village, Guiguinto, Bulacan, Philippines</p>
                            <div style="margin-top:.5rem;font-size:.85rem;color:var(--muted);">
                                <div><i class="fa-solid fa-clock" style="margin-right:.4rem;color:var(--accent)"></i><strong>Operating Hours:</strong> 7:00 AM – 8:00 PM (Monday – Sunday)</div>
                                <div style="margin-top:.2rem;"><i class="fa-solid fa-bell-concierge" style="margin-right:.4rem;color:var(--accent)"></i><strong>Preparation Time:</strong> Ready in 30–45 mins after payment</div>
                            </div>
                        </div>
                    </div>

                    <!-- Interactive Google Maps Embed (100% Free) -->
                    <div class="pickup-map-container">
                        <iframe 
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3856.89935785534!2d120.86967517519058!3d14.830905485683221!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x339653f14f7970b1%3A0x6472797c39ec3f75!2sBread%20Break!5e0!3m2!1sen!2sph!4v1789883477934!5m2!1sen!2sph" 
                            width="100%" 
                            height="280" 
                            style="border:0;display:block;" 
                            allowfullscreen="" 
                            loading="lazy" 
                            referrerpolicy="strict-origin-when-cross-origin">
                        </iframe>
                        <div class="pickup-map-caption">
                            <span><i class="fa-solid fa-map-pin" style="margin-right:.3rem;color:var(--accent)"></i>Estrella Village, Guiguinto, Bulacan</span>
                            <a href="https://www.google.com/maps/search/?api=1&query=Bread+Break+Estrella+Village+Guiguinto+Bulacan" target="_blank" rel="noopener noreferrer" style="font-weight:700;color:var(--accent);text-decoration:none;font-size:.82rem;">
                                <i class="fa-solid fa-diamond-turn-right" style="margin-right:.25rem;"></i>Get Directions
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Senior Citizen / PWD Discount Card with Checker ── -->
            <div class="checkout-card">
                <div class="checkout-card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h2><i class="fa-solid fa-id-card" style="margin-right:.5rem;color:var(--accent)"></i>Senior Citizen / PWD Discount</h2>
                    <span style="font-size:.78rem;color:var(--muted);font-weight:600;">RA 9994 &amp; RA 10754</span>
                </div>
                <div class="checkout-card-body">
                    <div style="display:flex;align-items:center;gap:.6rem;cursor:pointer;">
                        <input type="checkbox" id="apply-discount-checkbox" style="width:18px;height:18px;accent-color:var(--accent);cursor:pointer;" />
                        <label for="apply-discount-checkbox" style="font-size:.92rem;font-weight:700;color:var(--brown-900);cursor:pointer;margin:0;">
                            Apply Senior Citizen / PWD Discount (20% Off + VAT Exempt)
                        </label>
                    </div>

                    <div id="discount-fields-wrap" style="display:none;margin-top:1.2rem;padding-top:1.2rem;border-top:1px dashed var(--border);">
                        <div style="display:flex;gap:1.5rem;margin-bottom:1rem;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.88rem;font-weight:600;color:var(--brown-800);">
                                <input type="radio" name="discount_type_radio" value="senior" checked style="accent-color:var(--accent);" />
                                <span><i class="fa-solid fa-person-cane" style="margin-right:.25rem;color:var(--accent);"></i>Senior Citizen (OSCA ID)</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.88rem;font-weight:600;color:var(--brown-800);">
                                <input type="radio" name="discount_type_radio" value="pwd" style="accent-color:var(--accent);" />
                                <span><i class="fa-solid fa-wheelchair" style="margin-right:.25rem;color:var(--accent);"></i>Person with Disability (PWD ID)</span>
                            </label>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem;">
                            <div>
                                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600;color:var(--brown-800);">
                                    ID Number <span style="color:#c00;">*</span>
                                    <input type="text" id="discount-id-input" placeholder="e.g. SC-123456 or PWD-2026-89" maxlength="30" style="border:1.5px solid var(--border);border-radius:8px;padding:.55rem .8rem;font-family:inherit;font-size:.9rem;" />
                                </label>
                                <span class="input-feedback-msg" id="id-feedback"></span>
                            </div>

                            <div>
                                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:600;color:var(--brown-800);">
                                    Cardholder Full Name <span style="color:#c00;">*</span>
                                    <input type="text" id="discount-name-input" placeholder="e.g. Juan Dela Cruz" maxlength="70" style="border:1.5px solid var(--border);border-radius:8px;padding:.55rem .8rem;font-family:inherit;font-size:.9rem;" />
                                </label>
                                <span class="input-feedback-msg" id="name-feedback"></span>
                            </div>
                        </div>

                        <div class="checkout-alert alert-info" style="margin-top:.7rem;margin-bottom:0;font-size:.82rem;padding:.65rem .9rem;">
                            <i class="fa-solid fa-circle-info"></i>
                            <span><strong>Verification Policy:</strong> Please present the physical Senior Citizen / PWD ID upon receiving the order for verification.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 6. Payment Method Card (Modern E-Commerce Design) ── -->
            <div class="checkout-card" id="payment-method-card">
                <div class="checkout-card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <h2><i class="fa-solid fa-wallet" style="margin-right:.5rem;color:var(--accent)"></i>Payment Method</h2>
                        <span style="font-size:.8rem;color:var(--muted);">Select your preferred payment channel</span>
                    </div>
                    <span style="font-size:.74rem;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:.2rem .6rem;border-radius:6px;">
                        <i class="fa-solid fa-shield-halved" style="color:var(--accent);margin-right:.25rem;"></i>Secure Gateway
                    </span>
                </div>
                <div class="checkout-card-body">
                    <div class="pay-methods-grid" id="pay-method-grid">

                        <!-- GCash -->
                        <label class="pay-method-card is-selected" data-method="GCASH">
                            <input type="radio" name="pay_method_radio" value="GCASH" checked hidden />
                            <div class="pay-radio-circle"></div>
                            <div class="pay-logo-box pay-logo-gcash">
                                <img src="/BreadBreak/assets/images/payments/GCash-Emblem.png" alt="GCash" class="pay-brand-img" />
                            </div>
                            <div class="pay-card-details">
                                <div class="pay-card-name">GCash</div>
                                <div class="pay-card-desc">Instant e-Wallet Payment</div>
                            </div>
                            <span class="pay-tag-popular">Popular</span>
                        </label>

                        <!-- Maya -->
                        <label class="pay-method-card" data-method="PAYMAYA">
                            <input type="radio" name="pay_method_radio" value="PAYMAYA" hidden />
                            <div class="pay-radio-circle"></div>
                            <div class="pay-logo-box pay-logo-maya">
                                <img src="/BreadBreak/assets/images/payments/maya-emblem.png" alt="Maya" class="pay-brand-img" />
                            </div>
                            <div class="pay-card-details">
                                <div class="pay-card-name">Maya</div>
                                <div class="pay-card-desc">Wallet &amp; QR Ph</div>
                            </div>
                        </label>

                        <!-- GrabPay -->
                        <label class="pay-method-card" data-method="GRABPAY">
                            <input type="radio" name="pay_method_radio" value="GRABPAY" hidden />
                            <div class="pay-radio-circle"></div>
                            <div class="pay-logo-box pay-logo-grabpay">
                                <img src="/BreadBreak/assets/images/payments/grabpay-emblem.png" alt="GrabPay" class="pay-brand-img" />
                            </div>
                            <div class="pay-card-details">
                                <div class="pay-card-name">GrabPay</div>
                                <div class="pay-card-desc">GrabPay Wallet</div>
                            </div>
                        </label>

                        <!-- ShopeePay -->
                        <label class="pay-method-card" data-method="SHOPEEPAY">
                            <input type="radio" name="pay_method_radio" value="SHOPEEPAY" hidden />
                            <div class="pay-radio-circle"></div>
                            <div class="pay-logo-box pay-logo-shopeepay">
                                <img src="/BreadBreak/assets/images/payments/Shopeepay-emblem.png" alt="ShopeePay" class="pay-brand-img" />
                            </div>
                            <div class="pay-card-details">
                                <div class="pay-card-name">ShopeePay</div>
                                <div class="pay-card-desc">Shopee e-Wallet</div>
                            </div>
                        </label>

                        <!-- Credit / Debit Card -->
                        <label class="pay-method-card" data-method="CARD">
                            <input type="radio" name="pay_method_radio" value="CARD" hidden />
                            <div class="pay-radio-circle"></div>
                            <div class="pay-logo-box pay-logo-card">
                                <img src="/BreadBreak/assets/images/payments/card.svg" alt="Credit / Debit Card" class="pay-brand-img" />
                            </div>
                            <div class="pay-card-details">
                                <div class="pay-card-name">Credit / Debit Card</div>
                                <div class="pay-card-desc">Visa &amp; Mastercard</div>
                            </div>
                        </label>

                        <!-- Cash -->
                        <label class="pay-method-card" data-method="CASH">
                            <input type="radio" name="pay_method_radio" value="CASH" hidden />
                            <div class="pay-radio-circle"></div>
                            <div class="pay-logo-box pay-logo-cash">
                                <img src="/BreadBreak/assets/images/payments/P-Cash-emblem.png" alt="Cash" class="pay-brand-img" />
                            </div>
                            <div class="pay-card-details">
                                <div class="pay-card-name" id="cash-card-title">Cash on Delivery</div>
                                <div class="pay-card-desc" id="cash-card-desc">Pay cash upon arrival</div>
                            </div>
                        </label>

                    </div>

                    <!-- E-Wallet / Card hint -->
                    <div id="pm-ewallet-hint" class="checkout-alert alert-info" style="margin-top:1rem;font-size:.85rem;padding:.75rem 1rem;display:flex;align-items:center;gap:.6rem;">
                        <i class="fa-solid fa-circle-info" style="color:#0284c7;font-size:1rem;"></i>
                        <span id="pm-ewallet-hint-text">You'll be redirected to GCash to complete payment securely via Xendit. <strong>(TEST MODE)</strong></span>
                    </div>

                    <!-- Cash input panel -->
                    <div id="pm-cash-panel" style="display:none;margin-top:1rem;padding:1.2rem 1.3rem;background:#fffdfa;border:1.5px solid #ebd9c5;border-radius:14px;">
                        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.85rem;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:#e8f7ef;color:#166534;font-size:.95rem;">
                                <i class="fa-solid fa-money-bill-wave"></i>
                            </span>
                            <div>
                                <h4 style="margin:0;font-size:.95rem;color:var(--brown-900);font-weight:800;">Cash Payment Details</h4>
                                <span style="font-size:.78rem;color:var(--muted);">Specify your cash denomination so our rider/store can prepare exact change</span>
                            </div>
                        </div>

                        <!-- Quick denomination buttons -->
                        <div style="margin-bottom:1rem;">
                            <div style="font-size:.78rem;color:var(--muted);font-weight:700;margin-bottom:.4rem;">QUICK SELECT DENOMINATION:</div>
                            <div style="display:flex;flex-wrap:wrap;gap:.45rem;" id="cash-denom-btns">
                                <?php foreach ([50,100,200,500,1000] as $d): ?>
                                <button type="button" class="cash-denom-pill" data-amount="<?php echo $d; ?>">₱<?php echo number_format($d); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="max-width:300px;">
                            <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.85rem;font-weight:700;color:var(--brown-800);">
                                <span>Cash You Will Bring / Pay With <span style="color:#c00;">*</span></span>
                                <div style="position:relative;display:flex;align-items:center;">
                                    <span style="position:absolute;left:12px;font-weight:800;color:var(--muted);font-size:1rem;">₱</span>
                                    <input type="number" id="cash-amount-input" min="1" max="5000" step="1" placeholder="0.00"
                                        style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:.6rem .85rem .6rem 2rem;font-family:inherit;font-size:1.05rem;font-weight:700;color:var(--brown-900);" />
                                </div>
                            </label>
                            <span style="font-size:.75rem;color:var(--muted);margin-top:.25rem;display:block;">Must be at least the total to pay (max ₱5,000).</span>
                        </div>

                        <div id="cash-change-display" style="display:none;margin-top:.85rem;padding:.7rem 1rem;background:#e8f7ef;border:1.5px solid #a3d9bc;border-radius:10px;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:.5rem;">
                                <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:1.05rem;"></i>
                                <span style="font-size:.86rem;color:#166534;font-weight:700;">Calculated Change:</span>
                            </div>
                            <span id="cash-change-amount" style="font-size:1.15rem;font-weight:800;color:#166534;">₱0.00</span>
                        </div>

                        <div id="cash-amount-error" style="display:none;margin-top:.5rem;padding:.5rem .8rem;background:#fff5f5;border:1px solid #fecaca;border-radius:8px;font-size:.82rem;color:#c00;font-weight:600;"></div>

                        <div id="cash-fulfillment-note" class="checkout-alert" style="margin-top:.85rem;margin-bottom:0;font-size:.82rem;padding:.65rem .9rem;background:#fff8e6;border-color:#f5c842;color:#7a4200;">
                            <i class="fa-solid fa-circle-info" style="color:#b85c00;"></i>
                            <span id="cash-note-text"><strong>Delivery:</strong> Our rider will bring your order and provide change. Please have your cash ready.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Right Column: Order Summary + Payment ── -->
        <div class="summary-panel">
            <div class="summary-panel-header">
                <h2>Order Summary</h2>
            </div>
            <div class="summary-panel-body">
                <div class="payment-channel-badge" id="payment-badge-display">
                    <i class="fa-solid fa-mobile-screen"></i> Pay via GCash (TEST MODE)
                </div>

                <?php foreach ($cartItems as $item): ?>
                <div class="summary-row">
                    <span><?php echo htmlspecialchars($item['product_name']); ?> (<?php echo htmlspecialchars($item['service_size']); ?>) × <?php echo $item['quantity']; ?></span>
                    <span>₱<?php echo number_format($item['line_total'], 2); ?></span>
                </div>
                <?php endforeach; ?>

                <!-- Subtotal Base -->
                <div class="summary-row" style="border-top:1px solid var(--border);margin-top:.5rem;padding-top:.6rem;">
                    <span>Subtotal (VAT-Inc)</span>
                    <span id="summary-subtotal">₱<?php echo number_format($cartTotal, 2); ?></span>
                </div>

                <!-- Senior/PWD Exemption & Discount Rows -->
                <div class="summary-row" id="summary-vat-exempt-row" style="display:none;color:#1a6645;font-weight:600;">
                    <span><i class="fa-solid fa-percent" style="margin-right:.3rem;font-size:.85em;"></i>Less: 12% VAT Exemption</span>
                    <span id="summary-vat-exempt-amount">-₱0.00</span>
                </div>
                <div class="summary-row" id="summary-discount-row" style="display:none;color:#1a6645;font-weight:600;">
                    <span><i class="fa-solid fa-tag" style="margin-right:.3rem;font-size:.85em;"></i>Less: 20% Senior/PWD Disc</span>
                    <span id="summary-discount-amount">-₱0.00</span>
                </div>

                <!-- Regular VAT Breakdown Rows -->
                <div class="summary-row" id="summary-vatable-row" style="font-size:.82rem;color:var(--muted);">
                    <span>VATable Sales</span>
                    <span id="summary-vatable-amount">₱<?php echo number_format($cartTotal / 1.12, 2); ?></span>
                </div>
                <div class="summary-row" id="summary-vat-row" style="font-size:.82rem;color:var(--muted);">
                    <span>12% VAT (Included)</span>
                    <span id="summary-vat-amount">₱<?php echo number_format($cartTotal - ($cartTotal / 1.12), 2); ?></span>
                </div>

                <!-- Fulfillment / Delivery Fee Row -->
                <div class="summary-row" id="summary-delivery-row">
                    <span id="summary-delivery-label"><i class="fa-solid fa-truck" style="margin-right:.3rem;font-size:.85em;"></i>Delivery Fee</span>
                    <span id="summary-delivery-fee" style="color:var(--muted);">—</span>
                </div>

                <!-- Grand Total Row -->
                <div class="summary-row total-row">
                    <span>Total to Pay</span>
                    <span class="summary-total-amount" id="summary-grand-total">₱<?php echo number_format($cartTotal, 2); ?></span>
                </div>

                <p class="summary-note" id="summary-payment-note">
                    <i class="fa-solid fa-lock" style="margin-right:.35rem;"></i>
                    Prices and taxes are verified from our database. You will be redirected to GCash to complete payment securely via Xendit.
                </p>
            </div>
            <div class="summary-panel-footer">
                <form method="POST" id="checkout-form">
                    <input type="hidden" name="action" value="confirm_order" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['checkout_csrf']); ?>" />
                    
                    <!-- Hidden fulfillment fields -->
                    <input type="hidden" name="fulfillment_type" id="hidden-fulfillment-type" value="delivery" />
                    <input type="hidden" name="delivery_address" id="hidden-delivery-address" value="" />
                    <input type="hidden" name="delivery_fee" id="hidden-delivery-fee" value="0" />
                    <input type="hidden" name="delivery_lat" id="hidden-delivery-lat" value="" />
                    <input type="hidden" name="delivery_lng" id="hidden-delivery-lng" value="" />

                    <!-- Hidden discount fields -->
                    <input type="hidden" name="apply_discount" id="hidden-apply-discount" value="0" />
                    <input type="hidden" name="discount_type" id="hidden-discount-type" value="none" />
                    <input type="hidden" name="discount_id_number" id="hidden-discount-id-number" value="" />
                    <input type="hidden" name="discount_name" id="hidden-discount-name" value="" />

                    <!-- Hidden payment fields -->
                    <input type="hidden" name="payment_method" id="hidden-payment-method" value="GCASH" />
                    <input type="hidden" name="cash_amount" id="hidden-cash-amount" value="" />

                    <button class="btn btn-primary" type="submit" id="place-order-btn" disabled style="width:100%;font-size:1rem;padding:.9rem 1.2rem;opacity:.6;cursor:not-allowed;">
                        <i class="fa-solid fa-mobile-screen" style="margin-right:.5rem;" id="place-order-btn-icon"></i>
                        <span id="place-order-btn-text">Confirm &amp; Pay via GCash</span>
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
    const hiddenFulfill   = document.getElementById('hidden-fulfillment-type');
    const hiddenAddr      = document.getElementById('hidden-delivery-address');
    const hiddenFee       = document.getElementById('hidden-delivery-fee');
    const hiddenLat       = document.getElementById('hidden-delivery-lat');
    const hiddenLng       = document.getElementById('hidden-delivery-lng');
    const checkoutForm    = document.getElementById('checkout-form');
    const feeDisplay      = document.getElementById('summary-delivery-fee');
    const feeLabel        = document.getElementById('summary-delivery-label');
    const grandTotalEl    = document.getElementById('summary-grand-total');
    const cartSubtotal    = <?php echo json_encode($cartTotal); ?>;
    const STORE_LAT       = <?php echo json_encode($branchLat); ?>;
    const STORE_LNG       = <?php echo json_encode($branchLng); ?>;
    const MAX_DELIVERY_KM = <?php echo json_encode($maxDeliveryKm); ?>;
    let checkoutPinKm     = null;

    function clearDeliveryPin() {
        checkoutPinKm = null;
        if (hiddenLat) hiddenLat.value = '';
        if (hiddenLng) hiddenLng.value = '';
    }

    function setDeliveryPin(lat, lng, distKm) {
        checkoutPinKm = distKm;
        if (hiddenLat) hiddenLat.value = String(lat);
        if (hiddenLng) hiddenLng.value = String(lng);
    }

    // Fulfillment toggle elements
    const toggleDeliveryBtn = document.getElementById('toggle-delivery-btn');
    const togglePickupBtn   = document.getElementById('toggle-pickup-btn');
    const deliveryCard      = document.getElementById('delivery-address-card');
    const pickupCard        = document.getElementById('store-pickup-card');
    let currentFulfillment  = 'delivery';

    // VAT & Discount elements
    const applyDiscountCb = document.getElementById('apply-discount-checkbox');
    const discountWrap    = document.getElementById('discount-fields-wrap');
    const discountIdInput = document.getElementById('discount-id-input');
    const discountNameInput= document.getElementById('discount-name-input');
    const idFeedback      = document.getElementById('id-feedback');
    const nameFeedback    = document.getElementById('name-feedback');

    const hiddenApplyDisc = document.getElementById('hidden-apply-discount');
    const hiddenDiscType  = document.getElementById('hidden-discount-type');
    const hiddenDiscId    = document.getElementById('hidden-discount-id-number');
    const hiddenDiscName  = document.getElementById('hidden-discount-name');

    const vatExemptRow    = document.getElementById('summary-vat-exempt-row');
    const vatExemptAmtEl  = document.getElementById('summary-vat-exempt-amount');
    const discountRow     = document.getElementById('summary-discount-row');
    const discountAmtEl   = document.getElementById('summary-discount-amount');
    const vatableRow      = document.getElementById('summary-vatable-row');
    const vatableAmtEl    = document.getElementById('summary-vatable-amount');
    const vatRow          = document.getElementById('summary-vat-row');
    const vatAmtEl        = document.getElementById('summary-vat-amount');

    // Saved address mode elements
    const savedAddrList   = document.getElementById('saved-address-list');
    const radios          = savedAddrList ? savedAddrList.querySelectorAll('[data-address-radio]') : [];
    const addrCards       = savedAddrList ? savedAddrList.querySelectorAll('[data-address-card]') : [];
    const useOtherToggle  = document.getElementById('use-other-addr-toggle');
    const otherAddrWrap   = document.getElementById('other-addr-wrap');

    // Free-text mode elements
    const addrTextarea    = document.getElementById('delivery-address-input');
    const resultBox       = document.getElementById('zone-check-result');

    let currentDeliveryFee = null;
    let checkTimer = null;
    let lastChecked = '';
    let usingOtherAddr = false;
    let lastSavedRadio = null;

    // ── Helpers ───────────────────────────────────────────────────────────────
    function formatPHP(amount) {
        return '₱' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalculateTotal() {
        const isSeniorOrPwd = applyDiscountCb && applyDiscountCb.checked;
        let netItemsTotal = cartSubtotal;

        if (isSeniorOrPwd) {
            const vatExemptBase = cartSubtotal / 1.12;
            const vatExemptAmount = cartSubtotal - vatExemptBase;
            const discountAmount = vatExemptBase * 0.20;
            netItemsTotal = vatExemptBase - discountAmount;

            vatExemptRow.style.display   = 'flex';
            vatExemptAmtEl.textContent   = '-' + formatPHP(vatExemptAmount);
            discountRow.style.display    = 'flex';
            discountAmtEl.textContent    = '-' + formatPHP(discountAmount);

            vatableRow.style.display     = 'none';
            vatRow.style.display         = 'none';
        } else {
            const vatableSales = cartSubtotal / 1.12;
            const vatAmount    = cartSubtotal - vatableSales;

            vatExemptRow.style.display   = 'none';
            discountRow.style.display    = 'none';

            vatableRow.style.display     = 'flex';
            vatableAmtEl.textContent     = formatPHP(vatableSales);
            vatRow.style.display         = 'flex';
            vatAmtEl.textContent         = formatPHP(vatAmount);
        }

        const fee = currentFulfillment === 'pickup' ? 0 : (currentDeliveryFee !== null ? currentDeliveryFee : 0);
        const totalToPay = netItemsTotal + fee;
        grandTotalEl.textContent = formatPHP(totalToPay);

        if (typeof validateCashInput === 'function') {
            validateCashInput();
        }
    }

    function updateSummaryFee(fee) {
        currentDeliveryFee = fee;
        if (currentFulfillment === 'pickup') {
            feeLabel.innerHTML = '<i class="fa-solid fa-store" style="margin-right:.3rem;font-size:.85em;"></i>Store Pickup';
            feeDisplay.textContent = 'Free';
            feeDisplay.style.color = '#1a6645';
        } else {
            feeLabel.innerHTML = '<i class="fa-solid fa-truck" style="margin-right:.3rem;font-size:.85em;"></i>Delivery Fee';
            if (fee === null) {
                feeDisplay.textContent = '—';
                feeDisplay.style.color = 'var(--muted)';
            } else if (fee === 0) {
                feeDisplay.textContent = 'Free';
                feeDisplay.style.color = '#1a6645';
            } else {
                feeDisplay.textContent = formatPHP(fee);
                feeDisplay.style.color = 'var(--text)';
            }
        }
        recalculateTotal();
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

    // ── Smart ID & Name Checker ───────────────────────────────────────────────
    function validateIdField(val) {
        const clean = val.trim();
        if (!clean) return { valid: false, msg: 'ID number is required.' };
        if (clean.length < 4) return { valid: false, msg: 'ID number is too short (minimum 4 characters).' };
        if (clean.length > 30) return { valid: false, msg: 'ID number is too long (maximum 30 characters).' };
        // Check repeated character (e.g. 111111, aaaaa)
        if (/^(.)\1+$/.test(clean)) return { valid: false, msg: 'Please enter a genuine ID number, not repeated characters.' };
        // Valid characters: letters, digits, hyphens, slashes, spaces
        if (!/^[A-Za-z0-9][A-Za-z0-9\-\s\/]{2,28}[A-Za-z0-9]$/.test(clean)) {
            return { valid: false, msg: 'ID can only contain letters, numbers, hyphens, and slashes.' };
        }
        return { valid: true, msg: 'Valid ID format.' };
    }

    function validateNameField(val) {
        const clean = val.trim();
        if (!clean) return { valid: false, msg: 'Cardholder name is required.' };
        if (clean.length < 4) return { valid: false, msg: 'Name is too short (minimum 4 characters).' };
        if (clean.length > 70) return { valid: false, msg: 'Name is too long.' };
        // Valid characters: letters and spaces/dots/hyphens
        if (!/^[A-Za-zÀ-ÿ\s\.\-]{4,70}$/.test(clean)) {
            return { valid: false, msg: 'Name should only contain letters and spaces.' };
        }
        // At least one space (first and last name) or at least 4 letters
        if (clean.indexOf(' ') === -1 && clean.length < 5) {
            return { valid: false, msg: 'Please enter first and last name.' };
        }
        return { valid: true, msg: 'Valid name format.' };
    }

    // ── Master Validation & Button State ──────────────────────────────────────
    function updateOrderButtonState() {
        // 1. Check fulfillment and address
        if (currentFulfillment === 'pickup') {
            hiddenAddr.value = 'BreadBreak Bakery - Estrella Village, Guiguinto, Bulacan (Store Pickup)';
            hiddenFee.value  = 0;
            clearDeliveryPin();
        } else {
            if (checkoutPinKm != null && checkoutPinKm > MAX_DELIVERY_KM) {
                setOrderButton(false, 'This pin is outside our ' + MAX_DELIVERY_KM + 'km delivery zone. Switch to Store Pickup to continue.');
                return false;
            }
            if (!hiddenAddr.value || hiddenAddr.value.trim() === '') {
                setOrderButton(false, 'Please select or enter a delivery address.');
                return false;
            }
            if (savedAddrList && !usingOtherAddr) {
                const checked = [...radios].find(r => r.checked);
                if (!checked) {
                    setOrderButton(false, 'Select a delivery address to continue.');
                    return false;
                }
                if (checked.dataset.zoneAllowed === '0') {
                    setOrderButton(false, 'This address is outside our delivery zone.');
                    return false;
                }
                if (checked.dataset.zoneAllowed === undefined) {
                    setOrderButton(false, 'Checking delivery zone…');
                    return false;
                }
                const minOrder = parseFloat(checked.dataset.zoneMinOrder ?? 0);
                if (minOrder > 0 && cartSubtotal < minOrder) {
                    setOrderButton(false, 'Minimum order of ₱' + minOrder.toLocaleString() + ' required for this zone.');
                    return false;
                }
            }
        }

        // 2. Check discount if checked
        if (applyDiscountCb && applyDiscountCb.checked) {
            const idRes   = validateIdField(discountIdInput ? discountIdInput.value : '');
            const nameRes = validateNameField(discountNameInput ? discountNameInput.value : '');

            if (!idRes.valid) {
                setOrderButton(false, idRes.msg);
                return false;
            }
            if (!nameRes.valid) {
                setOrderButton(false, nameRes.msg);
                return false;
            }
        }

        // 3. Cash payment: need valid cash amount
        if (typeof currentPayMethod !== 'undefined' && currentPayMethod === 'CASH') {
            const total = getCurrentGrandTotal ? getCurrentGrandTotal() : 0;
            const cashVal = parseFloat(hiddenCashAmount ? hiddenCashAmount.value : 0) || 0;
            if (!cashVal || cashVal <= 0) {
                setOrderButton(false, 'Please enter the cash amount you will bring.');
                return false;
            }
            if (cashVal < total) {
                setOrderButton(false, 'Cash amount must be at least ₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2}) + '.');
                return false;
            }
            const maxA = Math.min(total * 3, 5000);
            if (cashVal > maxA) {
                setOrderButton(false, 'Cash amount is too large. Max: ₱' + maxA.toLocaleString('en-PH', {minimumFractionDigits:2}) + '.');
                return false;
            }
        }

        // All checks passed!
        setOrderButton(true, '');
        return true;
    }

    // ── Fulfillment Mode Toggle ───────────────────────────────────────────────
    function setFulfillmentMode(mode) {
        currentFulfillment = mode;
        hiddenFulfill.value = mode;

        const cashCardTitle = document.getElementById('cash-card-title');
        const cashCardDesc  = document.getElementById('cash-card-desc');
        const cashNoteText  = document.getElementById('cash-note-text');

        if (mode === 'pickup') {
            togglePickupBtn.classList.add('is-active');
            toggleDeliveryBtn.classList.remove('is-active');
            deliveryCard.style.display = 'none';
            pickupCard.style.display   = 'block';
            updateSummaryFee(0);
            if (cashCardTitle) cashCardTitle.textContent = 'Cash on Store Pickup';
            if (cashCardDesc)  cashCardDesc.textContent  = 'Pay at store counter upon claim';
            if (cashNoteText)  cashNoteText.innerHTML    = '<strong>Store Pickup:</strong> Please bring cash to our store counter before claiming your order.';
        } else {
            toggleDeliveryBtn.classList.add('is-active');
            togglePickupBtn.classList.remove('is-active');
            deliveryCard.style.display = 'block';
            pickupCard.style.display   = 'none';
            if (cashCardTitle) cashCardTitle.textContent = 'Cash on Delivery';
            if (cashCardDesc)  cashCardDesc.textContent  = 'Pay cash upon arrival';
            if (cashNoteText)  cashNoteText.innerHTML    = '<strong>Delivery:</strong> Our rider will bring your order and provide change. Please have your cash ready.';
            // Re-apply delivery address selection
            if (savedAddrList && !usingOtherAddr) {
                applySelectedRadio();
            } else if (addrTextarea && addrTextarea.value.trim().length >= 5) {
                runFreeTextCheck(addrTextarea.value.trim());
            } else {
                updateSummaryFee(null);
            }
        }
        updateOrderButtonState();
    }

    toggleDeliveryBtn.addEventListener('click', () => setFulfillmentMode('delivery'));
    togglePickupBtn.addEventListener('click', () => setFulfillmentMode('pickup'));

    // ── Discount Toggle & Live Checkers ────────────────────────────────────────
    if (applyDiscountCb) {
        if (discountWrap) discountWrap.style.display = applyDiscountCb.checked ? 'block' : 'none';

        applyDiscountCb.addEventListener('change', function () {
            const checked = this.checked;
            if (discountWrap) discountWrap.style.display = checked ? 'block' : 'none';
            if (hiddenApplyDisc) hiddenApplyDisc.value = checked ? '1' : '0';

            const selectedType = document.querySelector('input[name="discount_type_radio"]:checked')?.value || 'senior';
            if (hiddenDiscType) hiddenDiscType.value = checked ? selectedType : 'none';

            if (checked) {
                if (hiddenDiscId) hiddenDiscId.value   = discountIdInput.value.trim();
                if (hiddenDiscName) hiddenDiscName.value = discountNameInput.value.trim();
                if (discountIdInput.value.trim() === '') discountIdInput.focus();
                // Run immediate validation feedback
                checkIdInput();
                checkNameInput();
            } else {
                if (hiddenDiscId) hiddenDiscId.value   = '';
                if (hiddenDiscName) hiddenDiscName.value = '';
                if (idFeedback) { idFeedback.textContent = ''; idFeedback.className = 'input-feedback-msg'; }
                if (nameFeedback) { nameFeedback.textContent = ''; nameFeedback.className = 'input-feedback-msg'; }
                if (discountIdInput) discountIdInput.className = '';
                if (discountNameInput) discountNameInput.className = '';
            }

            recalculateTotal();
            updateOrderButtonState();
        });

        document.querySelectorAll('input[name="discount_type_radio"]').forEach(r => {
            r.addEventListener('change', function () {
                if (applyDiscountCb.checked && hiddenDiscType) hiddenDiscType.value = this.value;
            });
        });

        function checkIdInput() {
            if (!applyDiscountCb.checked) return;
            const res = validateIdField(discountIdInput.value);
            if (hiddenDiscId) hiddenDiscId.value = discountIdInput.value.trim();
            if (discountIdInput.value.trim().length > 0) {
                idFeedback.textContent = res.msg;
                idFeedback.className = 'input-feedback-msg ' + (res.valid ? 'is-valid' : 'is-error');
                discountIdInput.className = res.valid ? 'input-has-success' : 'input-has-error';
            } else {
                idFeedback.textContent = '';
                discountIdInput.className = '';
            }
            updateOrderButtonState();
        }

        function checkNameInput() {
            if (!applyDiscountCb.checked) return;
            const res = validateNameField(discountNameInput.value);
            if (hiddenDiscName) hiddenDiscName.value = discountNameInput.value.trim();
            if (discountNameInput.value.trim().length > 0) {
                nameFeedback.textContent = res.msg;
                nameFeedback.className = 'input-feedback-msg ' + (res.valid ? 'is-valid' : 'is-error');
                discountNameInput.className = res.valid ? 'input-has-success' : 'input-has-error';
            } else {
                nameFeedback.textContent = '';
                discountNameInput.className = '';
            }
            updateOrderButtonState();
        }

        if (discountIdInput) {
            discountIdInput.addEventListener('input', checkIdInput);
            discountIdInput.addEventListener('blur', checkIdInput);
        }

        if (discountNameInput) {
            discountNameInput.addEventListener('input', checkNameInput);
            discountNameInput.addEventListener('blur', checkNameInput);
        }
    }

    // ── Zone check API call ───────────────────────────────────────────────────
    async function checkZone(address, onResult, coords) {
        try {
            const payload = { address };
            if (coords && Number.isFinite(coords.lat) && Number.isFinite(coords.lng)) {
                payload.lat = coords.lat;
                payload.lng = coords.lng;
            }
            const resp = await fetch('/BreadBreak/api/delivery/check-address.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            onResult(data);
        } catch (err) {
            if (checkoutPinKm != null && checkoutPinKm > MAX_DELIVERY_KM) {
                onResult({ allowed: false, delivery_fee: 0, min_order: 0, message: 'Outside delivery zone.', _error: true });
                return;
            }
            onResult({ allowed: true, delivery_fee: 50, min_order: 0, message: 'Delivery available · ₱50 delivery fee', _error: true });
        }
    }

    // ── Checkout map picker (same Leaflet flow as My Addresses) ───────────────
    let checkoutMap = null;
    let checkoutPin = null;
    let checkoutGeocodeTimer = null;

    function haversineKm(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    function updateCheckoutMapStatus(distKm) {
        const status = document.getElementById('checkout-map-zone-status');
        const distBadge = document.getElementById('checkout-map-distance-km');
        if (distBadge) distBadge.textContent = distKm.toFixed(2) + ' km away';
        if (!status) return;
        if (distKm <= MAX_DELIVERY_KM) {
            status.innerHTML = '<span style="color:#1d7044;font-weight:700;"><i class="fa-solid fa-circle-check" style="color:#28a067;margin-right:.3rem;"></i>Within ' + MAX_DELIVERY_KM + 'km Delivery Zone (' + distKm.toFixed(1) + ' km)</span>';
        } else {
            status.innerHTML = '<span style="color:#c53030;font-weight:700;"><i class="fa-solid fa-circle-xmark" style="color:#e53e3e;margin-right:.3rem;"></i>Beyond ' + MAX_DELIVERY_KM + 'km Delivery Zone (' + distKm.toFixed(1) + ' km)</span>';
        }
    }

    function showOutOfZoneCheckoutResult() {
        hiddenAddr.value = '';
        hiddenFee.value = 0;
        updateSummaryFee(null);
        if (resultBox) {
            resultBox.style.display = 'flex';
            resultBox.className = 'zone-check-result zone-blocked';
            resultBox.innerHTML = '<i class="fa-solid fa-circle-xmark"></i><div style="flex:1;">' +
                '<strong>Outside ' + MAX_DELIVERY_KM + 'km Delivery Zone</strong>' +
                '<p style="margin:.25rem 0 .4rem;font-size:.82rem;">Delivery is not available for this location. Please pin a spot inside the green circle, or switch to <strong>Store Pickup (Free)</strong>.</p>' +
                '<button type="button" class="addr-btn addr-btn-default switch-to-pickup-btn" style="font-size:.78rem;padding:.3rem .75rem;background:#fff;border:1.5px solid var(--accent);color:var(--accent);font-weight:700;cursor:pointer;border-radius:6px;display:inline-flex;align-items:center;gap:.35rem;"><i class="fa-solid fa-store"></i> Switch to Store Pickup (Free)</button>' +
            '</div>';
        }
        setOrderButton(false, 'This pin is outside our ' + MAX_DELIVERY_KM + 'km delivery zone. Switch to Store Pickup to continue.');
    }

    async function reverseGeocodeCheckout(lat, lng) {
        const status = document.getElementById('checkout-map-zone-status');
        const distKm = haversineKm(STORE_LAT, STORE_LNG, lat, lng);
        if (status && distKm <= MAX_DELIVERY_KM) {
            status.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:var(--accent);margin-right:.3rem;"></i>Finding address details…';
        }
        try {
            const resp = await fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18&addressdetails=1', {
                headers: { 'Accept-Language': 'en' }
            });
            if (!resp.ok) throw new Error('geocode');
            const data = await resp.json();
            const addr = data.address || {};
            const barangay = addr.quarter || addr.suburb || addr.neighbourhood || addr.village || addr.hamlet || '';
            const city = addr.city || addr.town || addr.municipality || '';
            const province = addr.province || addr.state || 'Bulacan';
            const postal = addr.postcode || '';
            const roadParts = [];
            if (addr.house_number) roadParts.push(addr.house_number);
            if (addr.road || addr.pedestrian || addr.footway || addr.building) {
                roadParts.push(addr.road || addr.pedestrian || addr.footway || addr.building);
            }
            const road = roadParts.join(' ');
            const pieces = [];
            if (road) pieces.push(road);
            if (barangay) pieces.push('Brgy. ' + barangay);
            if (city) pieces.push(city);
            if (province) pieces.push(province);
            if (postal) pieces.push(postal);
            const full = pieces.length ? pieces.join(', ') : (data.display_name || '');
            if (addrTextarea && full) {
                addrTextarea.value = full;
            }
            if (distKm > MAX_DELIVERY_KM) {
                lastChecked = full ? full.trim() : '';
                showOutOfZoneCheckoutResult();
            } else if (full) {
                lastChecked = '';
                runFreeTextCheck(full.trim());
            }
        } catch (err) {
            console.warn('Reverse geocoding error:', err);
            if (distKm > MAX_DELIVERY_KM) {
                showOutOfZoneCheckoutResult();
            }
        }
        updateCheckoutMapStatus(distKm);
    }

    function setCheckoutPin(lat, lng, doGeocode) {
        if (!checkoutMap) return;
        if (!checkoutPin) {
            checkoutPin = L.marker([lat, lng], { draggable: true, title: 'Your Location (Drag to adjust)' }).addTo(checkoutMap);
            checkoutPin.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                setCheckoutPin(pos.lat, pos.lng, true);
            });
        } else {
            checkoutPin.setLatLng([lat, lng]);
        }
        const distKm = haversineKm(STORE_LAT, STORE_LNG, lat, lng);
        setDeliveryPin(lat, lng, distKm);
        updateCheckoutMapStatus(distKm);
        checkoutPin.bindPopup('<b>Your Selected Pin</b><br>' + distKm.toFixed(2) + ' km from BreadBreak').openPopup();
        if (distKm > MAX_DELIVERY_KM) {
            lastChecked = addrTextarea ? addrTextarea.value.trim() : '';
            showOutOfZoneCheckoutResult();
        }
        if (doGeocode) {
            clearTimeout(checkoutGeocodeTimer);
            checkoutGeocodeTimer = setTimeout(function () { reverseGeocodeCheckout(lat, lng); }, 300);
        }
    }

    function initCheckoutMap() {
        const el = document.getElementById('checkout-address-map');
        if (!el || typeof L === 'undefined') return;
        if (checkoutMap) {
            checkoutMap.invalidateSize();
            return;
        }
        checkoutMap = L.map('checkout-address-map').setView([STORE_LAT, STORE_LNG], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(checkoutMap);
        L.circle([STORE_LAT, STORE_LNG], {
            radius: MAX_DELIVERY_KM * 1000,
            color: '#28a067',
            fillColor: '#28a067',
            fillOpacity: 0.09,
            weight: 2,
            dashArray: '5, 5'
        }).addTo(checkoutMap);
        const storeIcon = L.divIcon({
            className: 'breadbreak-map-store-icon',
            html: '<div style="background:#c05c28;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 8px rgba(0,0,0,0.35);border:2px solid #fff;"><i class="fa-solid fa-bread-slice" style="font-size:14px;"></i></div>',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -18]
        });
        L.marker([STORE_LAT, STORE_LNG], { icon: storeIcon }).addTo(checkoutMap)
            .bindPopup('<strong>BreadBreak Bakery</strong><br>Estrella Village, Guiguinto, Bulacan<br><span style="color:#28a067;font-weight:700;">8km delivery hub</span>');
        checkoutMap.on('click', function (e) {
            setCheckoutPin(e.latlng.lat, e.latlng.lng, true);
        });
        const locateBtn = document.getElementById('checkout-btn-locate-me');
        if (locateBtn && !locateBtn.dataset.bound) {
            locateBtn.dataset.bound = '1';
            locateBtn.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    alert('Geolocation is not supported by your browser.');
                    return;
                }
                const originalHtml = locateBtn.innerHTML;
                locateBtn.disabled = true;
                locateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Locating…';
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        locateBtn.disabled = false;
                        locateBtn.innerHTML = originalHtml;
                        checkoutMap.setView([pos.coords.latitude, pos.coords.longitude], 15);
                        setCheckoutPin(pos.coords.latitude, pos.coords.longitude, true);
                    },
                    function () {
                        locateBtn.disabled = false;
                        locateBtn.innerHTML = originalHtml;
                        alert('Unable to get your GPS location. Please allow location permissions or click directly on the map to set your pin.');
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            });
        }
    }

    function revealCheckoutMap() {
        initCheckoutMap();
        setTimeout(function () {
            if (checkoutMap) checkoutMap.invalidateSize();
        }, 220);
    }

    function restoreSavedAddressSelection() {
        usingOtherAddr = false;
        clearDeliveryPin();
        if (savedAddrList) savedAddrList.classList.remove('is-paused');
        if (addrTextarea) addrTextarea.value = '';
        if (resultBox) resultBox.style.display = 'none';
        lastChecked = '';
        if (lastSavedRadio) lastSavedRadio.checked = true;
        applySelectedRadio();
    }

    // ── SAVED ADDRESS MODE ────────────────────────────────────────────────────
    function applySelectedRadio() {
        if (usingOtherAddr || currentFulfillment === 'pickup') return;
        clearDeliveryPin();
        const checked = [...radios].find(r => r.checked);
        if (!checked) {
            hiddenAddr.value = '';
            updateOrderButtonState();
            return;
        }

        addrCards.forEach(c => c.classList.remove('is-checked'));
        if (checked.closest('[data-address-card]')) checked.closest('[data-address-card]').classList.add('is-checked');

        const allowed  = checked.dataset.zoneAllowed;
        const fee      = parseFloat(checked.dataset.zoneFee ?? 50);
        const minOrder = parseFloat(checked.dataset.zoneMinOrder ?? 0);

        if (allowed === undefined) {
            setOrderButton(false, 'Checking delivery zone…');
            updateSummaryFee(null);
            return;
        }
        if (allowed === '0') {
            hiddenFee.value  = 0;
            hiddenAddr.value = '';
            updateSummaryFee(null);
            setOrderButton(false, 'This address is outside our 8km delivery zone. Switch to Store Pickup to continue.');
            return;
        }
        if (minOrder > 0 && cartSubtotal < minOrder) {
            hiddenFee.value  = 0;
            hiddenAddr.value = '';
            updateSummaryFee(null);
            setOrderButton(false, 'Minimum order of ₱' + minOrder.toLocaleString() + ' required for this zone.');
            return;
        }
        hiddenAddr.value = checked.value;
        hiddenFee.value  = fee;
        updateSummaryFee(fee);
        updateOrderButtonState();
    }

    if (savedAddrList && radios.length > 0) {
        const addressData = <?php
            $addrJsonList = array_map(function($a) {
                $full = trim($a['full_address']);
                $bar  = trim($a['barangay']);
                $city = trim($a['city']);
                $prov = trim($a['province']);
                $text = $full;
                if ($city && !str_contains($full, $city)) {
                    $text .= ', ' . $city;
                }
                if ($prov && !str_contains($full, $prov)) {
                    $text .= ', ' . $prov;
                }
                return [
                    'id'   => $a['id'],
                    'text' => $text,
                ];
            }, $savedAddresses ?? []);
            echo json_encode($addrJsonList);
        ?>;

        let checkedCount = 0;

        addressData.forEach(function (addr) {
            const statusEl = document.getElementById('zone-status-' + addr.id);
            checkZone(addr.text, function (data) {
                checkedCount++;
                if (statusEl) {
                    if (data.allowed) {
                        const fee = parseFloat(data.delivery_fee ?? 50);
                        const feeText = fee > 0 ? ' · ' + formatPHP(fee) + ' delivery fee' : ' · Free delivery';
                        statusEl.innerHTML = '<span style="color:#1a6645;font-size:.82rem;font-weight:600;"><i class="fa-solid fa-circle-check" style="margin-right:.3rem;"></i>' + (data.message || 'Delivery available') + feeText + '</span>';
                    } else {
                        statusEl.innerHTML = '<div style="color:#891515;font-size:.82rem;margin-top:.4rem;background:#fff5f5;border:1px solid #fecaca;border-radius:10px;padding:.6rem .85rem;">' +
                            '<div style="font-weight:700;display:flex;align-items:center;gap:.35rem;"><i class="fa-solid fa-circle-xmark" style="color:#e53e3e;"></i> Outside 8km Delivery Zone</div>' +
                            '<div style="margin-top:.25rem;font-size:.78rem;color:#742a2a;line-height:1.4;">Delivery is unavailable for this address, but you can choose <strong>Store Pickup (Free)</strong>!</div>' +
                            '<button type="button" class="addr-btn addr-btn-default switch-to-pickup-btn" style="margin-top:.45rem;font-size:.78rem;padding:.3rem .75rem;background:#fff;border:1.5px solid var(--accent);color:var(--accent);font-weight:700;cursor:pointer;border-radius:6px;display:inline-flex;align-items:center;gap:.35rem;"><i class="fa-solid fa-store"></i> Switch to Store Pickup (Free)</button>' +
                        '</div>';
                    }
                    const radio = savedAddrList.querySelector('[data-address-id="' + addr.id + '"]');
                    if (radio) {
                        radio.dataset.zoneAllowed = data.allowed ? '1' : '0';
                        radio.dataset.zoneFee     = data.delivery_fee ?? 50;
                        radio.dataset.zoneMinOrder= data.min_order ?? 0;
                    }
                }
                applySelectedRadio();
            });
        });

        lastSavedRadio = [...radios].find(r => r.checked) || radios[0] || null;

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                lastSavedRadio = this;
                usingOtherAddr = false;
                if (savedAddrList) savedAddrList.classList.remove('is-paused');
                if (otherAddrWrap) otherAddrWrap.style.display = 'none';
                if (useOtherToggle) useOtherToggle.innerHTML = '<i class="fa-solid fa-plus" style="margin-right:.4rem;"></i> Use a different address';
                applySelectedRadio();
            });
        });

        if (useOtherToggle && otherAddrWrap) {
            useOtherToggle.addEventListener('click', function () {
                usingOtherAddr = !usingOtherAddr;
                otherAddrWrap.style.display = usingOtherAddr ? 'block' : 'none';
                useOtherToggle.innerHTML = usingOtherAddr
                    ? '<i class="fa-solid fa-chevron-up" style="margin-right:.4rem;"></i> Cancel — use saved address'
                    : '<i class="fa-solid fa-plus" style="margin-right:.4rem;"></i> Use a different address';
                if (!usingOtherAddr) {
                    restoreSavedAddressSelection();
                } else {
                    lastSavedRadio = [...radios].find(r => r.checked) || lastSavedRadio;
                    radios.forEach(r => r.checked = false);
                    addrCards.forEach(c => c.classList.remove('is-checked'));
                    if (savedAddrList) savedAddrList.classList.add('is-paused');
                    hiddenAddr.value = '';
                    hiddenFee.value = 0;
                    updateSummaryFee(null);
                    setOrderButton(false, 'Pin your location or enter a delivery address to continue.');
                    if (resultBox) resultBox.style.display = 'none';
                    lastChecked = '';
                    revealCheckoutMap();
                    const existing = addrTextarea ? addrTextarea.value.trim() : '';
                    if (existing.length >= 5) runFreeTextCheck(existing);
                }
            });
        }

        setTimeout(applySelectedRadio, 100);
    }

    // ── FREE-TEXT MODE ────────────────────────────────────────────────────────
    async function runFreeTextCheck(address) {
        if (checkoutPinKm != null && checkoutPinKm > MAX_DELIVERY_KM) {
            lastChecked = address;
            showOutOfZoneCheckoutResult();
            return;
        }
        if (address === lastChecked) return;
        lastChecked = address;
        if (resultBox) showInlineResult(resultBox, 'loading', 'Checking delivery availability…');
        setOrderButton(false, 'Checking your address…');
        updateSummaryFee(null);

        const pinCoords = (checkoutPinKm != null && hiddenLat && hiddenLng && hiddenLat.value && hiddenLng.value)
            ? { lat: parseFloat(hiddenLat.value), lng: parseFloat(hiddenLng.value) }
            : null;

        await checkZone(address, function (data) {
            if (checkoutPinKm != null && checkoutPinKm > MAX_DELIVERY_KM) {
                showOutOfZoneCheckoutResult();
                return;
            }
            if (data.allowed) {
                const fee = parseFloat(data.delivery_fee ?? 50);
                hiddenAddr.value = address;
                hiddenFee.value  = fee;
                updateSummaryFee(fee);
                if (resultBox) showInlineResult(resultBox, data.drive_warning ? 'warning' : 'allowed', data.message || 'Delivery available.');
                if (data.min_order && cartSubtotal < data.min_order) {
                    if (resultBox) showInlineResult(resultBox, 'blocked', 'Minimum order of ₱' + data.min_order.toLocaleString() + ' required for this zone.');
                    setOrderButton(false, 'Minimum order not met for your zone.');
                } else {
                    updateOrderButtonState();
                }
            } else {
                showOutOfZoneCheckoutResult();
            }
        }, pinCoords);
    }

    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function (e) {
            if (placeOrderBtn.disabled) {
                e.preventDefault();
                return;
            }
            if (currentFulfillment === 'delivery' && checkoutPinKm != null && checkoutPinKm > MAX_DELIVERY_KM) {
                e.preventDefault();
                showOutOfZoneCheckoutResult();
            }
        });
    }

    // ── Global switch to pickup button listener ────────────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.switch-to-pickup-btn');
        if (btn) {
            e.preventDefault();
            setFulfillmentMode('pickup');
            const selectorCard = document.querySelector('.fulfillment-selector-card');
            if (selectorCard) {
                selectorCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    });

    if (addrTextarea) {
        addrTextarea.addEventListener('input', function () {
            const val = this.value.trim();
            clearTimeout(checkTimer);
            if (val.length < 5) {
                if (resultBox) resultBox.style.display = 'none';
                hiddenAddr.value = '';
                if (!savedAddrList) setOrderButton(false, 'Enter your delivery address to continue.');
                updateSummaryFee(null);
                lastChecked = '';
                return;
            }
            checkTimer = setTimeout(() => runFreeTextCheck(val), 800);
        });

        addrTextarea.addEventListener('blur', function () {
            const val = this.value.trim();
            if (val.length >= 5) { clearTimeout(checkTimer); runFreeTextCheck(val); }
        });

        if (!savedAddrList) {
            const prefilled = addrTextarea.value.trim();
            if (prefilled.length >= 5) runFreeTextCheck(prefilled);
            else setOrderButton(false, 'Pin your location or enter your delivery address to continue.');
            revealCheckoutMap();
        }
    }

    // ── Payment Method Selector ───────────────────────────────────────────────
    const payMethodCards    = document.querySelectorAll('.pay-method-card, .pay-method-option');
    const hiddenPayMethod   = document.getElementById('hidden-payment-method');
    const hiddenCashAmount  = document.getElementById('hidden-cash-amount');
    const ewalletHint       = document.getElementById('pm-ewallet-hint');
    const ewalletHintText   = document.getElementById('pm-ewallet-hint-text');
    const cashPanel         = document.getElementById('pm-cash-panel');
    const cashAmountInput   = document.getElementById('cash-amount-input');
    const cashChangeDisplay = document.getElementById('cash-change-display');
    const cashChangeAmount  = document.getElementById('cash-change-amount');
    const cashAmountError   = document.getElementById('cash-amount-error');
    const payBadgeDisplay   = document.getElementById('payment-badge-display');
    const summaryPayNote    = document.getElementById('summary-payment-note');
    const btnText           = document.getElementById('place-order-btn-text');
    const btnIcon           = document.getElementById('place-order-btn-icon');
    const denomPills        = document.querySelectorAll('.cash-denom-pill, .cash-denom-btn');

    let currentPayMethod = 'GCASH';

    const payMethodMeta = {
        GCASH:     { label: 'GCash',              logo: '/BreadBreak/assets/images/payments/GCash-Emblem.png',     btnLabel: 'Confirm & Pay via GCash',     btnIcon: 'fa-mobile-screen' },
        PAYMAYA:   { label: 'Maya',               logo: '/BreadBreak/assets/images/payments/maya-emblem.png',      btnLabel: 'Confirm & Pay via Maya',      btnIcon: 'fa-leaf' },
        GRABPAY:   { label: 'GrabPay',            logo: '/BreadBreak/assets/images/payments/grabpay-emblem.png',   btnLabel: 'Confirm & Pay via GrabPay',   btnIcon: 'fa-car-side' },
        SHOPEEPAY: { label: 'ShopeePay',          logo: '/BreadBreak/assets/images/payments/Shopeepay-emblem.png', btnLabel: 'Confirm & Pay via ShopeePay', btnIcon: 'fa-bag-shopping' },
        CARD:      { label: 'Credit / Debit Card',logo: '/BreadBreak/assets/images/payments/card.svg',             btnLabel: 'Confirm & Pay via Card',      btnIcon: 'fa-credit-card' },
        CASH:      { label: 'Cash',               logo: '/BreadBreak/assets/images/payments/P-Cash-emblem.png',    btnLabel: 'Confirm Order (Cash)',         btnIcon: 'fa-money-bill-wave' },
    };

    function getCurrentGrandTotal() {
        const raw = grandTotalEl ? grandTotalEl.textContent.replace(/[₱,]/g, '').trim() : '0';
        return parseFloat(raw) || 0;
    }

    function updatePaymentUI(method) {
        currentPayMethod = method;
        if (hiddenPayMethod) hiddenPayMethod.value = method;
        const meta = payMethodMeta[method] || payMethodMeta.GCASH;

        // Update summary badge with clean SVG logo
        if (payBadgeDisplay) {
            payBadgeDisplay.innerHTML = '<img src="' + meta.logo + '" alt="' + meta.label + '" class="payment-badge-logo"> Pay via ' + meta.label + (method === 'CASH' ? '' : ' (TEST MODE)');
        }

        // Update submit button label & icon
        if (btnText) btnText.textContent = meta.btnLabel;
        if (btnIcon) {
            btnIcon.className = 'fa-solid ' + meta.btnIcon;
            btnIcon.style.marginRight = '.5rem';
        }

        if (method === 'CASH') {
            // Show cash panel, hide ewallet hint
            if (ewalletHint) ewalletHint.style.display = 'none';
            if (cashPanel)   cashPanel.style.display   = 'block';
            if (summaryPayNote) {
                summaryPayNote.innerHTML = '<i class="fa-solid fa-lock" style="margin-right:.35rem;"></i>Cash payment — please prepare the exact or nearest cash denomination. Change will be calculated upon arrival.';
            }
            validateCashInput();
        } else {
            // Hide cash panel, show ewallet hint
            if (cashPanel)   cashPanel.style.display   = 'none';
            if (ewalletHint) ewalletHint.style.display = 'flex';
            if (hiddenCashAmount) hiddenCashAmount.value = '';
            if (cashAmountInput)  cashAmountInput.value  = '';
            if (cashChangeDisplay) cashChangeDisplay.style.display = 'none';
            if (cashAmountError)   cashAmountError.style.display   = 'none';
            denomPills.forEach(p => p.style.borderColor = '');

            // Update hint text
            const isCard = method === 'CARD';
            if (ewalletHintText) {
                ewalletHintText.innerHTML = isCard
                    ? 'You\'ll be redirected to enter your Visa/Mastercard card details securely via Xendit. <strong>(TEST MODE)</strong>'
                    : 'You\'ll be redirected to ' + meta.label + ' to complete payment securely via Xendit. <strong>(TEST MODE)</strong>';
            }
            if (summaryPayNote) {
                summaryPayNote.innerHTML = '<i class="fa-solid fa-lock" style="margin-right:.35rem;"></i>Prices and taxes are verified from our database. You will be redirected to ' + meta.label + ' to complete payment securely via Xendit.';
            }
            updateOrderButtonState();
        }
    }

    function validateCashInput() {
        if (currentPayMethod !== 'CASH') return true;
        const total = getCurrentGrandTotal();
        const val = parseFloat(cashAmountInput ? cashAmountInput.value : 0) || 0;

        if (cashAmountError) cashAmountError.style.display = 'none';
        if (cashChangeDisplay) cashChangeDisplay.style.display = 'none';

        // Highlight matched denomination pill
        denomPills.forEach(p => {
            if (parseFloat(p.dataset.amount) === val) {
                p.style.borderColor = 'var(--accent)';
                p.style.background = 'var(--accent)';
                p.style.color = '#fff';
            } else {
                p.style.borderColor = '';
                p.style.background = '';
                p.style.color = '';
            }
        });

        if (!val || val <= 0) {
            if (hiddenCashAmount) hiddenCashAmount.value = '';
            setOrderButton(false, 'Please enter the cash amount you will bring.');
            return false;
        }
        if (val < total) {
            if (cashAmountError) {
                cashAmountError.textContent = 'Cash amount is less than the total to pay (₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2}) + '). Please enter a higher amount.';
                cashAmountError.style.display = 'block';
            }
            if (hiddenCashAmount) hiddenCashAmount.value = '';
            setOrderButton(false, 'Cash amount must be at least ₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2}) + '.');
            return false;
        }
        const maxAllowed = Math.min(Math.max(total * 3, 1000), 5000);
        if (val > maxAllowed) {
            if (cashAmountError) {
                cashAmountError.textContent = 'Cash amount is too large (max ₱' + maxAllowed.toLocaleString('en-PH', {minimumFractionDigits:2}) + ' for this order). Please enter the actual denomination you will bring.';
                cashAmountError.style.display = 'block';
            }
            if (hiddenCashAmount) hiddenCashAmount.value = '';
            setOrderButton(false, 'Cash amount is too large. Max: ₱' + maxAllowed.toLocaleString('en-PH', {minimumFractionDigits:2}) + '.');
            return false;
        }

        // Valid cash amount
        const change = val - total;
        if (hiddenCashAmount) hiddenCashAmount.value = val;
        if (cashChangeDisplay) {
            cashChangeDisplay.style.display = 'flex';
        }
        if (cashChangeAmount) {
            cashChangeAmount.textContent = '₱' + change.toLocaleString('en-PH', {minimumFractionDigits:2});
        }

        return updateOrderButtonState();
    }

    // Wire up payment card selection
    payMethodCards.forEach(function (card) {
        card.addEventListener('click', function () {
            payMethodCards.forEach(c => c.classList.remove('is-selected'));
            this.classList.add('is-selected');
            const radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            const method = this.dataset.method;
            updatePaymentUI(method);
        });
    });

    // Quick denomination buttons
    denomPills.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (cashAmountInput) {
                cashAmountInput.value = this.dataset.amount;
                validateCashInput();
            }
        });
    });

    // Cash amount live input
    if (cashAmountInput) {
        cashAmountInput.addEventListener('input', validateCashInput);
        cashAmountInput.addEventListener('blur', validateCashInput);
    }

    // Initialize payment UI to GCash
    updatePaymentUI('GCASH');
})();
</script>
</body>
</html>
