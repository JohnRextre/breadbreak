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
    'grid' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
    'box' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/></svg>',
    'clipboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 4V2h6v2M8 10h8M8 14h6"/></svg>',
    'chart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h17"/><path d="m7 15 4-5 3 2 5-6"/></svg>',
    'profile' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-6"/></svg>',
];
?>
<aside class="admin-sidebar" id="admin-sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>/staff/dashboard.php" class="sidebar-logo">
            <span class="sidebar-mark">B</span>
            <span><strong>BreadBreak</strong><small>STAFF PORTAL</small></span>
        </a>
        <button class="sidebar-close" type="button" aria-label="Close navigation">&times;</button>
        <button class="sidebar-collapse" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="collapse-icon">&laquo;</span></button>
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
