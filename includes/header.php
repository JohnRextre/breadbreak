<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$currentPage = basename($_SERVER['PHP_SELF']);
$headerCartCount = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
$isCustomerHeader = ($_SESSION['role'] ?? '') === 'customer';
$customerProfileImage = $profileImage ?? '';
$customerInitial = strtoupper(substr(trim((string) ($_SESSION['first_name'] ?? '')), 0, 1));
$headerActiveOrders = 0;
if ($isCustomerHeader && !empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
    $headerPdo = getDatabaseConnection();
    if (!$customerProfileImage) {
        $headerAccountStatement = $headerPdo->prepare('SELECT first_name, profile_data, profile_mime FROM users WHERE id = :id LIMIT 1');
        $headerAccountStatement->execute(['id' => (int) $_SESSION['user_id']]);
        $headerAccount = $headerAccountStatement->fetch() ?: [];
        $customerInitial = strtoupper(substr(trim((string) ($headerAccount['first_name'] ?? '')), 0, 1));
        if (!empty($headerAccount['profile_data']) && !empty($headerAccount['profile_mime'])) {
            $customerProfileImage = 'data:' . $headerAccount['profile_mime'] . ';base64,' . base64_encode($headerAccount['profile_data']);
        }
    }
    $activeStmt = $headerPdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE customer_id = :cid AND status NOT IN ('completed', 'cancelled')"
    );
    $activeStmt->execute(['cid' => (int) $_SESSION['user_id']]);
    $headerActiveOrders = (int) $activeStmt->fetchColumn();
}
$customerInitial = $customerInitial ?: '?';
$navItems = [
    ['label' => 'Home', 'href' => '/BreadBreak/index.php', 'page' => 'index.php'],
    ['label' => 'Shop', 'href' => '/BreadBreak/menu.php', 'page' => 'menu.php'],
    ['label' => 'About Us', 'href' => '/BreadBreak/about.php', 'page' => 'about.php'],
    ['label' => 'Contact', 'href' => '/BreadBreak/contact.php', 'page' => 'contact.php'],
];
$showCartPages = ['menu.php', 'shop.php', 'menu_dashboard.php', 'cart.php', 'checkout.php', 'order-confirmation.php', 'order-status.php'];
$showCartIcon = in_array($currentPage, $showCartPages, true);
$isOrderStatusPage = $currentPage === 'order-status.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo isset($pageTitle) ? $pageTitle : 'BreadBreak'; ?></title>
    <meta name="description" content="BreadBreak bakery ordering and inventory management system homepage." />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/style.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time(); ?>" />
    <script defer src="/BreadBreak/assets/js/script.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/script.js') ? filemtime(__DIR__ . '/../assets/js/script.js') : time(); ?>"></script>
</head>
<body>
    <header class="site-header">
        <div class="container navbar">
            <div class="brand-wrap">
                <a href="<?php echo $isCustomerHeader ? '/BreadBreak/customer/menu_dashboard.php' : '/BreadBreak/index.php'; ?>" class="brand" aria-label="BreadBreak <?php echo $isCustomerHeader ? 'customer dashboard' : 'home page'; ?>">
                    <img src="/BreadBreak/assets/breadbreak_png/breadbreak_logo.png" alt="BreadBreak logo" class="brand-logo" width="60" height="60" />
                    <span>
                        <strong>BreadBreak</strong>
                        <small>Bakery &amp; Online Ordering</small>
                    </span>
                </a>
            </div>

            <?php if (!$isCustomerHeader): ?><nav class="main-nav" aria-label="Main navigation">
                <?php foreach ($navItems as $item): ?>
                    <a href="<?php echo $item['href']; ?>" class="nav-link<?php echo $currentPage === $item['page'] ? ' active' : ''; ?>"><?php echo $item['label']; ?></a>
                <?php endforeach; ?>
            </nav><?php endif; ?>

            <div class="header-tools">
                <?php if ($isCustomerHeader): ?>
                <a href="/BreadBreak/customer/order-status.php" class="customer-header-icon<?php echo $isOrderStatusPage ? ' is-active' : ''; ?>" aria-label="Order status" title="Order Status">
                    <i class="fa-solid fa-bread-slice"></i>
                    <?php if ($headerActiveOrders > 0): ?>
                        <span class="cart-count"><?php echo $headerActiveOrders > 9 ? '9+' : $headerActiveOrders; ?></span>
                    <?php endif; ?>
                </a>
                <a href="/BreadBreak/customer/account.php" class="customer-profile-button" aria-label="Open My Account" title="My Account"><?php if ($customerProfileImage): ?><img src="<?php echo htmlspecialchars($customerProfileImage); ?>" alt="Profile photo" /><?php else: ?><span aria-hidden="true"><?php echo htmlspecialchars($customerInitial); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <?php if ($showCartIcon): ?>
                <a href="/BreadBreak/cart.php" class="cart-button" aria-label="Shopping cart" title="Shopping cart">
                    <i class="fa-solid fa-cart-shopping"></i><span class="cart-count" data-cart-count><?php echo (int) $headerCartCount; ?></span>
                </a>
                <?php endif; ?>
                <?php if ($isCustomerHeader): ?><a href="/BreadBreak/logout.php" class="btn btn-outline"><i class="fa-solid fa-right-from-bracket"></i> Logout</a><?php else: ?><a href="/BreadBreak/login.php" class="btn btn-outline"><i class="fa-solid fa-right-to-bracket"></i> Login</a><?php endif; ?>
            </div>
        </div>
    </header>
