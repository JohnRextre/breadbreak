<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
$pageTitle = 'Orders';
$activePage = 'orders';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>Orders</h2><p>Review customer orders and order history.</p></section>
<section class="panel placeholder-panel"><div><strong>Order monitoring will be implemented here.</strong><p>Admin access is view-only. Order processing is handled by Staff.</p></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
