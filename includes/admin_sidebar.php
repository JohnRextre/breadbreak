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
    'grid' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
    'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'box' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/></svg>',
    'clipboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 4V2h6v2M8 10h8M8 14h6"/></svg>',
    'chart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h17"/><path d="m7 15 4-5 3 2 5-6"/></svg>',
    'activity' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>',
    'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.7 1.7-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V20h-2.4v-.2a1.7 1.7 0 0 0-1.03-1.56 1.7 1.7 0 0 0-1.88.34l-.06.06-1.7-1.7.06-.06A1.7 1.7 0 0 0 6.4 15a1.7 1.7 0 0 0-1.56-1.03H4v-2.4h.84A1.7 1.7 0 0 0 6.4 10a1.7 1.7 0 0 0-.34-1.88L6 8.06l1.7-1.7.06.06A1.7 1.7 0 0 0 9.64 6.4 1.7 1.7 0 0 0 10.67 4.84V4h2.4v.84A1.7 1.7 0 0 0 14.1 6.4a1.7 1.7 0 0 0 1.88-.34l.06-.06 1.7 1.7-.06.06A1.7 1.7 0 0 0 17.99 10c.2.6.76 1.03 1.56 1.03h.84v2.4h-.84A1.7 1.7 0 0 0 19.4 15Z"/></svg>',
    'profile' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-6"/></svg>',
];
?>
<aside class="admin-sidebar" id="admin-sidebar">
    <div class="sidebar-brand">
        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="sidebar-logo">
            <span class="sidebar-mark">B</span>
            <span><strong>BreadBreak</strong><small>ADMIN PANEL</small></span>
        </a>
        <button class="sidebar-close" type="button" aria-label="Close navigation">&times;</button>
    </div>
    <nav class="sidebar-nav" aria-label="Admin navigation">
        <?php foreach ($navigation as $section => $items): ?>
            <div class="nav-section">
                <span class="nav-label"><?php echo strtoupper($section); ?></span>
                <?php foreach ($items as [$page, $label, $icon]): ?>
                    <a class="sidebar-link <?php echo $activePage === $page ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/<?php echo $page; ?>.php">
                        <?php echo $icons[$icon]; ?><span><?php echo htmlspecialchars($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="nav-section nav-account-last">
            <a class="sidebar-link" href="<?php echo BASE_URL; ?>/logout.php">
                <?php echo $icons['logout']; ?><span>Logout</span>
            </a>
        </div>
    </nav>
    <div class="sidebar-footnote">BreadBreak Bakery<br><span>Management system</span></div>
</aside>
<div class="sidebar-overlay"></div>
