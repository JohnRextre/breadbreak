<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/../includes/staff_header.php';
?>
<section class="access-card"><div class="access-icon">i</div><div><h2>Staff Operations</h2><p>Manage BreadBreak products, menu categories, and stock levels.</p></div></section>
<section class="welcome-block"><h2>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?>!</h2><p>Keep the bakery inventory accurate and ready for customers.</p></section>
<section class="panel quick-access"><div class="panel-heading"><h3>Quick Access</h3></div><div class="quick-links"><a class="quick-link" href="<?php echo BASE_URL; ?>/staff/inventory.php">Manage Inventory</a><a class="quick-link" href="<?php echo BASE_URL; ?>/staff/orders.php">View Orders</a><a class="quick-link" href="<?php echo BASE_URL; ?>/staff/reports.php">View Reports</a><a class="quick-link" href="<?php echo BASE_URL; ?>/staff/profile.php">My Profile</a></div></section>
<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
