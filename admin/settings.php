<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
$pageTitle = 'Settings';
$activePage = 'settings';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>Settings</h2><p>Manage BreadBreak system configuration.</p></section>
<section class="panel placeholder-panel"><div><strong>System settings will be implemented here.</strong><p>Configuration controls will be added in a future version.</p></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
