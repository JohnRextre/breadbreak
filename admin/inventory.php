<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
$pageTitle = 'Inventory';
$activePage = 'inventory';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>Inventory</h2><p>Monitor products and stock levels across BreadBreak.</p></section>
<section class="panel placeholder-panel"><div><strong>Inventory monitoring will be implemented here.</strong><p>Admin access is view-only. Product changes are handled by Staff.</p></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
