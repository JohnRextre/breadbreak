<?php
$staffNavigation = [
    'main' => [
        ['dashboard', 'Dashboard', 'grid'],
        ['inventory', 'Inventory', 'box'],
        ['orders', 'Orders', 'clipboard'],
        ['reports', 'Reports & Analytics', 'chart'],
    ],
    'account' => [
        ['profile', 'My Profile', 'profile'],
    ],
];
$staffIcons = [
    'grid' => '<i class="fa-solid fa-table-cells-large"></i>',
    'box' => '<i class="fa-solid fa-boxes-stacked"></i>',
    'clipboard' => '<i class="fa-solid fa-receipt"></i>',
    'chart' => '<i class="fa-solid fa-chart-line"></i>',
    'profile' => '<i class="fa-solid fa-circle-user"></i>',
    'logout' => '<i class="fa-solid fa-right-from-bracket"></i>',
];
?>
<aside class="admin-sidebar" id="admin-sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>/staff/dashboard.php" class="sidebar-logo">
            <img src="<?php echo BASE_URL; ?>/assets/breadbreak_png/breadbreak_logo.png" class="sidebar-logo-img" alt="BreadBreak logo" width="36" height="36" />
            <span><strong>BreadBreak</strong><small>STAFF PORTAL</small></span>
        </a>
        <button class="sidebar-close" type="button" aria-label="Close navigation"><i class="fa-solid fa-xmark"></i></button>
        <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar" aria-expanded="true"><i class="fa-solid fa-angles-left collapse-icon"></i></button>
    </div>
    <nav class="sidebar-nav" aria-label="Staff navigation">
        <?php foreach ($staffNavigation as $section => $navItems): ?>
            <div class="nav-section"><span class="nav-label"><?php echo strtoupper($section); ?></span>
            <?php foreach ($navItems as [$page, $label, $icon]): ?>
                    <a class="sidebar-link <?php echo $activePage === $page ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/staff/<?php echo $page; ?>.php" title="<?php echo htmlspecialchars($label); ?>"><?php echo $staffIcons[$icon]; ?><span><?php echo htmlspecialchars($label); ?></span></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="nav-section nav-account-last"><a class="sidebar-link" href="<?php echo BASE_URL; ?>/logout.php" title="Logout"><?php echo $staffIcons['logout']; ?><span>Logout</span></a></div>
    </nav>
    <div class="sidebar-footnote">BreadBreak Bakery<br><span>Staff operations</span></div>
</aside>
<div class="sidebar-overlay"></div>
