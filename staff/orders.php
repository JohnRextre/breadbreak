<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
$pageTitle = 'Orders';
$activePage = 'orders';
require __DIR__ . '/../includes/staff_header.php';
?>
<section class="page-intro"><h2>Orders</h2><p>Review and process customer orders.</p></section><section class="panel placeholder-panel"><div><strong>Order processing will be implemented here.</strong><p>Staff order tools are coming next.</p></div></section>
<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
