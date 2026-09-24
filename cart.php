<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

// Where should the visitor land after a cart POST?
//   cart     -> stay on the cart (shop add-to-cart)
//   login    -> signed-out visitors are sent to sign-in, then back to their cart
//   checkout -> straight to checkout when already signed in
$allowedNext = ['cart', 'login', 'checkout'];
$next = $_POST['next'] ?? $_GET['next'] ?? 'cart';
if (!in_array($next, $allowedNext, true)) {
    $next = 'cart';
}

function cartRedirect(string $next): void
{
    $isCustomer = ($_SESSION['role'] ?? '') === 'customer';

    if ($next === 'login' && !$isCustomer) {
        header('Location: /BreadBreak/login.php?redirect=cart');
        exit;
    }

    if ($next === 'checkout') {
        header('Location: ' . ($isCustomer ? '/BreadBreak/checkout.php' : '/BreadBreak/login.php?redirect=checkout'));
        exit;
    }

    header('Location: /BreadBreak/cart.php');
    exit;
}

$action = $_POST['action'] ?? '';
$variantId = (int) ($_POST['variant_id'] ?? 0);
$customerId = (($_SESSION['role'] ?? '') === 'customer') ? (int) ($_SESSION['user_id'] ?? 0) : 0;
$pdo = null;

try {
    $pdo = getDatabaseConnection();
    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_cart (user_id INT NOT NULL, variant_id INT NOT NULL, quantity INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (user_id, variant_id), CONSTRAINT fk_customer_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_customer_cart_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON DELETE CASCADE)');
    if ($action === 'add' || $action === 'update') {
        $statement = $pdo->prepare("SELECT v.id, v.quantity, v.availability, v.service_size, i.name FROM inventory_item_variants v JOIN inventory_items i ON i.id = v.inventory_item_id WHERE v.id = :id LIMIT 1");
        $statement->execute(['id' => $variantId]);
        $variant = $statement->fetch();
        if ($variant && $variant['availability'] === 'available' && (int) $variant['quantity'] > 0) {
            $requested = $action === 'add' ? ((int) ($_SESSION['cart'][$variantId] ?? 0) + max(1, (int) ($_POST['quantity'] ?? 1))) : max(0, (int) ($_POST['quantity'] ?? 0));
            $_SESSION['cart'][$variantId] = min($requested, (int) $variant['quantity']);
            if ($action === 'add') {
                $_SESSION['cart_notice'] = $variant['name'] . ' (' . $variant['service_size'] . ') was added to your cart.';
            }
        } elseif ($action === 'add') {
            $_SESSION['cart_error'] = 'Sorry, that item is currently unavailable.';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') cartRedirect($next);

$cartItems = [];
$cartTotal = 0;
if (!empty($_SESSION['cart']) && $pdo) {
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

$pageTitle = 'Shopping Cart | BreadBreak';
$cartNotice = (string) ($_SESSION['cart_notice'] ?? '');
$cartError = (string) ($_SESSION['cart_error'] ?? '');
unset($_SESSION['cart_notice'], $_SESSION['cart_error']);
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="cart-page section">
    <div class="container">
        <section class="breadcrumb-section">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="/BreadBreak/index.php">Home</a>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Shopping Cart</span>
            </nav>
        </section>

        <div class="section-heading cart-heading">
            <span class="eyebrow">Your order</span>
            <h1>Shopping Cart</h1>
            <p>Review your selected bakery items before checkout.</p>
        </div>

        <?php if ($cartNotice): ?>
        <p class="cart-notice is-success" role="status"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($cartNotice); ?></p>
        <?php endif; ?>
        <?php if ($cartError): ?>
        <p class="cart-notice is-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($cartError); ?></p>
        <?php endif; ?>

        <?php if (!$cartItems): ?>
            <div class="cart-empty">
                <span class="cart-empty-icon"><i class="fa-solid fa-basket-shopping"></i></span>
                <h2>Your cart is empty</h2>
                <p>Browse the menu and add your favorites — they'll show up right here.</p>
                <div class="cart-empty-actions">
                    <a class="btn btn-primary" href="/BreadBreak/menu.php"><i class="fa-solid fa-bread-slice"></i> Browse Products</a>
                    <a class="btn btn-secondary" href="/BreadBreak/index.php">Back to Home</a>
                </div>
            </div>
        <?php else: ?>
            <div class="cart-layout">
                <div class="cart-items">
                    <div class="cart-items-head">
                        <span><i class="fa-solid fa-bag-shopping"></i> <?php echo (int) $cartCount; ?> item<?php echo $cartCount === 1 ? '' : 's'; ?> in your cart</span>
                        <a href="/BreadBreak/menu.php">Add more <i class="fa-solid fa-plus"></i></a>
                    </div>

                    <?php foreach ($cartItems as $item): ?>
                    <article class="cart-item">
                        <div class="cart-item-image">
                            <?php if ($item['photo']): ?>
                                <img src="<?php echo htmlspecialchars($item['photo']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" />
                            <?php else: ?>
                                <i class="fa-solid fa-bread-slice"></i>
                            <?php endif; ?>
                        </div>

                        <div class="cart-item-details">
                            <h2><?php echo htmlspecialchars($item['name']); ?></h2>
                            <p class="cart-item-variant"><span class="cart-chip"><?php echo htmlspecialchars($item['service_size']); ?></span> ₱<?php echo number_format((float) $item['price'], 2); ?> each</p>
                            <div class="cart-item-tools">
                                <form class="cart-quantity" method="POST">
                                    <input type="hidden" name="action" value="update" />
                                    <input type="hidden" name="variant_id" value="<?php echo (int) $item['variant_id']; ?>" />
                                    <button type="submit" name="quantity" value="<?php echo $item['quantity'] - 1; ?>" aria-label="Decrease quantity" <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>><i class="fa-solid fa-minus"></i></button>
                                    <output><?php echo $item['quantity']; ?></output>
                                    <button type="submit" name="quantity" value="<?php echo min($item['quantity'] + 1, (int) $item['stock_quantity']); ?>" aria-label="Increase quantity" <?php echo $item['quantity'] >= (int) $item['stock_quantity'] ? 'disabled' : ''; ?>><i class="fa-solid fa-plus"></i></button>
                                </form>
                                <span class="cart-stock"><i class="fa-solid fa-box"></i> <?php echo (int) $item['stock_quantity']; ?> left</span>
                            </div>
                        </div>

                        <div class="cart-item-total">
                            <strong>₱<?php echo number_format($item['line_total'], 2); ?></strong>
                            <form method="POST">
                                <input type="hidden" name="action" value="remove" />
                                <input type="hidden" name="variant_id" value="<?php echo (int) $item['variant_id']; ?>" />
                                <button class="remove-cart-item" type="submit"><i class="fa-solid fa-trash-can"></i> Remove</button>
                            </form>
                        </div>
                    </article>
                    <?php endforeach; ?>

                    <a class="cart-continue-inline" href="/BreadBreak/menu.php"><i class="fa-solid fa-arrow-left"></i> Continue shopping</a>
                </div>

                <aside class="cart-summary">
                    <h2><i class="fa-solid fa-receipt"></i> Order Summary</h2>
                    <div><span>Items</span><strong><?php echo (int) $cartCount; ?></strong></div>
                    <div><span>Subtotal</span><strong>₱<?php echo number_format($cartTotal, 2); ?></strong></div>
                    <div><span>Delivery fee</span><strong class="cart-summary-muted">Calculated at checkout</strong></div>
                    <div class="cart-total"><span>Total</span><strong>₱<?php echo number_format($cartTotal, 2); ?></strong></div>

                    <?php if ($isCustomer): ?>
                        <a class="btn btn-primary" href="/BreadBreak/checkout.php">Proceed to Checkout <i class="fa-solid fa-arrow-right"></i></a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="/BreadBreak/login.php?redirect=checkout">Proceed to Checkout <i class="fa-solid fa-arrow-right"></i></a>
                        <p class="cart-signin-note"><i class="fa-solid fa-lock"></i> We'll ask you to sign in first, then bring you right back to your cart.</p>
                    <?php endif; ?>

                    <a class="cart-continue" href="/BreadBreak/menu.php">Continue Shopping</a>

                    <ul class="cart-assurances">
                        <li><i class="fa-solid fa-wheat-awn"></i> Baked fresh daily</li>
                        <li><i class="fa-solid fa-truck-fast"></i> Pickup or delivery</li>
                        <li><i class="fa-solid fa-shield-halved"></i> Secure checkout</li>
                    </ul>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
