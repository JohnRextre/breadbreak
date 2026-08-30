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
    <link rel="stylesheet" href="/BreadBreak/assets/css/style.css" />
    <script defer src="/BreadBreak/assets/js/script.js"></script>
</head>
<body>
    <header class="site-header">
        <div class="container navbar">
            <div class="brand-wrap">
                <a href="/BreadBreak/index.php" class="brand" aria-label="BreadBreak home page">
                    <span class="brand-mark">B</span>
                    <span>
                        <strong>BreadBreak</strong>
                        <small>Bakery &amp; Online Ordering</small>
                    </span>
                </a>
            </div>

            <nav class="main-nav" aria-label="Main navigation">
                <a href="/BreadBreak/index.php" class="nav-link active">Home</a>
                <a href="/BreadBreak/shop.php" class="nav-link">Shop</a>
                <a href="/BreadBreak/about.php" class="nav-link">About Us</a>
                <a href="/BreadBreak/contact.php" class="nav-link">Contact</a>
            </nav>

            <div class="header-tools">
                <button class="icon-button" type="button" aria-label="Search">
                    🔎
                </button>
                <button class="cart-button" type="button" aria-label="Cart">
                    🛒
                    <span class="cart-count">0</span>
                </button>
                <a href="/BreadBreak/login.php" class="btn btn-outline">Login</a>
            </div>
        </div>
    </header>
