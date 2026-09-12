<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

function cartRedirect(): void
{
    header('Location: /BreadBreak/cart.php');
    exit;
}

$action = $_POST['action'] ?? '';
$variantId = (int) ($_POST['variant_id'] ?? 0);
$customerId = (($_SESSION['role'] ?? '') === 'customer') ? (int) ($_SESSION['user_id'] ?? 0) : 0;

try {
    $pdo = getDatabaseConnection();
    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_cart (user_id INT NOT NULL, variant_id INT NOT NULL, quantity INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (user_id, variant_id), CONSTRAINT fk_customer_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_customer_cart_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON DELETE CASCADE)');
    if ($action === 'add' || $action === 'update') {
        $statement = $pdo->prepare("SELECT id, quantity, availability FROM inventory_item_variants WHERE id = :id LIMIT 1");
        $statement->execute(['id' => $variantId]);
        $variant = $statement->fetch();
        if ($variant && $variant['availability'] === 'available' && (int) $variant['quantity'] > 0) {
            $requested = $action === 'add' ? ((int) ($_SESSION['cart'][$variantId] ?? 0) + max(1, (int) ($_POST['quantity'] ?? 1))) : max(0, (int) ($_POST['quantity'] ?? 0));
            $_SESSION['cart'][$variantId] = min($requested, (int) $variant['quantity']);
        }
    } elseif ($action === 'remove') {
        unset($_SESSION['cart'][$variantId]);
    }
    if ($customerId > 0) {
        $pdo->prepare('DELETE FROM customer_cart WHERE user_id = :user_id')->execute(['user_id' => $customerId]);
        $saveCart = $pdo->prepare('INSERT INTO customer_cart (user_id, variant_id, quantity) VALUES (:user_id, :variant_id, :quantity)');
        foreach ($_SESSION['cart'] as $savedVariantId => $savedQuantity) {
            if ((int) $savedQuantity > 0) $saveCart->execute(['user_id' => $customerId, 'variant_id' => (int) $savedVariantId, 'quantity' => (int) $savedQuantity]);
        }
    }
} catch (Throwable $exception) {
    $_SESSION['cart_error'] = 'Unable to update the cart right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') cartRedirect();

$cartItems = [];
$cartTotal = 0;
if ($_SESSION['cart']) {
    $ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare("SELECT v.id AS variant_id, v.service_size, v.price, v.quantity AS stock_quantity, i.name, i.photo, i.photo_data, i.photo_mime FROM inventory_item_variants v JOIN inventory_items i ON i.id = v.inventory_item_id WHERE v.id IN ($placeholders)");
    $statement->execute($ids);
    foreach ($statement->fetchAll() as $item) {
        $quantity = min((int) $_SESSION['cart'][$item['variant_id']], (int) $item['stock_quantity']);
        if ($quantity < 1) { unset($_SESSION['cart'][$item['variant_id']]); continue; }
        $photo = (string) ($item['photo'] ?? '');
        if (!empty($item['photo_data']) && !empty($item['photo_mime'])) $photo = 'data:' . $item['photo_mime'] . ';base64,' . base64_encode($item['photo_data']);
        $item['photo'] = $photo;
        $item['quantity'] = $quantity;
        $item['line_total'] = $quantity * (float) $item['price'];
        $cartTotal += $item['line_total'];
        $cartItems[] = $item;
    }
}
$cartCount = array_sum($_SESSION['cart']);
$isCustomer = ($_SESSION['role'] ?? '') === 'customer';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Cart | BreadBreak</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/style.css" />
</head>
<body>
<main class="section cart-page"><div class="container">
    <div class="section-heading"><span class="eyebrow">Your order</span><h1>Shopping Cart</h1><p>Review your selected bakery items before checkout.</p></div>
    <?php if (!empty($_SESSION['cart_error'])): ?><p class="cart-message"><?php echo htmlspecialchars($_SESSION['cart_error']); unset($_SESSION['cart_error']); ?></p><?php endif; ?>
    <?php if (!$cartItems): ?>
        <div class="cart-empty"><i class="fa-solid fa-basket-shopping"></i><h2>Your cart is empty</h2><p>Browse the menu and add your favorites.</p><a class="btn btn-primary" href="/BreadBreak/menu.php">Browse Products</a></div>
    <?php else: ?>
        <div class="cart-layout"><div class="cart-items">
        <?php foreach ($cartItems as $item): ?><article class="cart-item">
            <div class="cart-item-image"><?php if ($item['photo']): ?><img src="<?php echo htmlspecialchars($item['photo']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" /><?php else: ?><i class="fa-solid fa-bread-slice"></i><?php endif; ?></div>
            <div class="cart-item-details"><h2><?php echo htmlspecialchars($item['name']); ?></h2><p><?php echo htmlspecialchars($item['service_size']); ?> · ₱<?php echo number_format((float) $item['price'], 2); ?></p>
                <form class="cart-quantity" method="POST"><input type="hidden" name="action" value="update" /><input type="hidden" name="variant_id" value="<?php echo (int) $item['variant_id']; ?>" /><button type="submit" name="quantity" value="<?php echo $item['quantity'] - 1; ?>" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button><output><?php echo $item['quantity']; ?></output><button type="submit" name="quantity" value="<?php echo min($item['quantity'] + 1, (int) $item['stock_quantity']); ?>" aria-label="Increase quantity" <?php echo $item['quantity'] >= (int) $item['stock_quantity'] ? 'disabled' : ''; ?>><i class="fa-solid fa-plus"></i></button></form>
            </div><div class="cart-item-total"><strong>₱<?php echo number_format($item['line_total'], 2); ?></strong><form method="POST"><input type="hidden" name="action" value="remove" /><input type="hidden" name="variant_id" value="<?php echo (int) $item['variant_id']; ?>" /><button class="remove-cart-item" type="submit">Remove</button></form></div>
        </article><?php endforeach; ?></div>
        <aside class="cart-summary"><h2>Order Summary</h2><div><span>Items</span><strong><?php echo (int) $cartCount; ?></strong></div><div class="cart-total"><span>Total</span><strong>₱<?php echo number_format($cartTotal, 2); ?></strong></div><?php if ($isCustomer): ?><button class="btn btn-primary" type="button" disabled title="Checkout is coming soon">Proceed to Checkout</button><?php else: ?><a class="btn btn-primary" href="/BreadBreak/login.php?redirect=checkout">Proceed to Checkout</a><?php endif; ?><a class="cart-continue" href="/BreadBreak/menu.php">Continue Shopping</a></aside></div>
    <?php endif; ?>
</div></main>
</body>
</html>
