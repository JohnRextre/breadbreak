<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navItems = [
    ['label' => 'Home', 'href' => '/BreadBreak/index.php', 'page' => 'index.php'],
    ['label' => 'Shop', 'href' => '/BreadBreak/menu.php', 'page' => 'menu.php'],
    ['label' => 'About Us', 'href' => '/BreadBreak/about.php', 'page' => 'about.php'],
    ['label' => 'Contact', 'href' => '/BreadBreak/contact.php', 'page' => 'contact.php'],
];
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
                <a href="/BreadBreak/index.php" class="brand" aria-label="BreadBreak home page">
                    <img src="/BreadBreak/assets/breadbreak_png/breadbreak_logo.png" alt="BreadBreak logo" class="brand-logo" width="60" height="60" />
                    <span>
                        <strong>BreadBreak</strong>
                        <small>Bakery &amp; Online Ordering</small>
                    </span>
                </a>
            </div>

            <nav class="main-nav" aria-label="Main navigation">
                <?php foreach ($navItems as $item): ?>
                    <a href="<?php echo $item['href']; ?>" class="nav-link<?php echo $currentPage === $item['page'] ? ' active' : ''; ?>"><?php echo $item['label']; ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="header-tools">
                <a href="/BreadBreak/login.php" class="btn btn-outline">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Login
                </a>
            </div>
        </div>
    </header>
