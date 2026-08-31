<?php
$navigation = [
    'main' => [
        ['dashboard', 'Dashboard', 'grid'],
        ['users', 'User Management', 'users'],
        ['inventory', 'Inventory', 'box'],
        ['orders', 'Orders', 'clipboard'],
        ['reports', 'Reports & Analytics', 'chart'],
    ],
    'system' => [
        ['logs', 'System Logs', 'activity'],
        ['settings', 'Settings', 'settings'],
    ],
    'account' => [
        ['profile', 'My Profile', 'profile'],
    ],
];

$icons = [
    'grid' => '<i class="fa-solid fa-table-cells-large"></i>',
    'users' => '<i class="fa-solid fa-users"></i>',
    'box' => '<i class="fa-solid fa-boxes-stacked"></i>',
    'clipboard' => '<i class="fa-solid fa-receipt"></i>',
    'chart' => '<i class="fa-solid fa-chart-line"></i>',
    'activity' => '<i class="fa-solid fa-clock-rotate-left"></i>',
    'settings' => '<i class="fa-solid fa-gear"></i>',
    'profile' => '<i class="fa-solid fa-circle-user"></i>',
    'logout' => '<i class="fa-solid fa-right-from-bracket"></i>',
];
?>
<aside class="admin-sidebar" id="admin-sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="sidebar-logo">
            <img src="<?php echo BASE_URL; ?>/assets/breadbreak_png/breadbreak_logo.png" class="sidebar-logo-img" alt="BreadBreak logo" width="36" height="36" />
            <span><strong>BreadBreak</strong><small>ADMIN PANEL</small></span>
        </a>
        <button class="sidebar-close" type="button" aria-label="Close navigation"><i class="fa-solid fa-xmark"></i></button>
        <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar" aria-expanded="true"><i class="fa-solid fa-angles-left collapse-icon"></i></button>
    </div>
    <nav class="sidebar-nav" aria-label="Admin navigation">
        <?php foreach ($navigation as $section => $items): ?>
            <div class="nav-section">
                <span class="nav-label"><?php echo strtoupper($section); ?></span>
                <?php foreach ($items as [$page, $label, $icon]): ?>
                    <a class="sidebar-link <?php echo $activePage === $page ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/<?php echo $page; ?>.php" title="<?php echo htmlspecialchars($label); ?>">
                        <?php echo $icons[$icon]; ?><span><?php echo htmlspecialchars($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="nav-section nav-account-last">
            <a class="sidebar-link" href="<?php echo BASE_URL; ?>/logout.php" title="Logout">
                <?php echo $icons['logout']; ?><span>Logout</span>
            </a>
        </div>
    </nav>
    <div class="sidebar-footnote">BreadBreak Bakery<br><span>Management system</span></div>
</aside>
<div class="sidebar-overlay"></div>
