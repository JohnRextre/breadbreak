<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';

function adminTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $statement->execute(['table_name' => $table]);
    return (bool) $statement->fetchColumn();
}

$pdo = getDatabaseConnection();
$userCounts = $pdo->query("SELECT COUNT(*) AS total, SUM(role = 'customer') AS customers, SUM(role = 'staff') AS staff FROM users")->fetch();
$totalProducts = 0;
$lowStock = 0;
$outOfStock = 0;
$totalOrders = 0;

if (adminTableExists($pdo, 'products')) {
    $totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $stockColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'stock_quantity'")->fetchColumn();
    if ($stockColumn) {
        $lowStock = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity BETWEEN 1 AND 10')->fetchColumn();
        $outOfStock = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity <= 0')->fetchColumn();
    }
}

if (adminTableExists($pdo, 'orders')) {
    $totalOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
}

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="access-card" aria-label="Admin access control"><div class="access-icon">i</div><div><h2>Admin Access Control</h2><p>System configuration, audit logs, and account management rights. Inventory and orders are view-only.</p></div></section>
<section class="welcome-block"><h2>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</h2><p>Here's an overview of your BreadBreak system.</p></section>
<section class="summary-grid" aria-label="System summary">
    <article class="summary-card"><div class="summary-top"><span>TOTAL USERS</span><span class="summary-icon">◉</span></div><div class="summary-value"><?php echo (int) ($userCounts['total'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL CUSTOMERS</span><span class="summary-icon">◌</span></div><div class="summary-value"><?php echo (int) ($userCounts['customers'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL STAFF</span><span class="summary-icon">♙</span></div><div class="summary-value"><?php echo (int) ($userCounts['staff'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL PRODUCTS</span><span class="summary-icon">□</span></div><div class="summary-value"><?php echo $totalProducts; ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL ORDERS</span><span class="summary-icon">≡</span></div><div class="summary-value"><?php echo $totalOrders; ?></div></article>
</section>
<section class="dashboard-grid">
    <article class="panel"><div class="panel-heading"><h3>Inventory Overview</h3><a href="<?php echo BASE_URL; ?>/admin/inventory.php">View Inventory</a></div><div class="inventory-stats"><div class="inventory-stat"><span>Total Products</span><span><?php echo $totalProducts; ?></span></div><div class="inventory-stat low"><span>Low Stock</span><span><?php echo $lowStock; ?></span></div><div class="inventory-stat empty"><span>Out of Stock</span><span><?php echo $outOfStock; ?></span></div></div></article>
    <article class="panel"><div class="panel-heading"><h3>Recent Orders</h3><a href="<?php echo BASE_URL; ?>/admin/orders.php">View All Orders</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Order ID</th><th>Customer</th><th>Order Date</th><th>Total</th><th>Status</th></tr></thead><tbody><tr><td colspan="5">Order records will appear here when the order module is available.</td></tr></tbody></table></div></article>
</section>
<section class="panel activity-panel"><div class="panel-heading"><h3>Recent System Activity</h3><a href="<?php echo BASE_URL; ?>/admin/logs.php">View Logs</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Activity</th><th>User</th><th>Date</th><th>Time</th></tr></thead><tbody><tr><td colspan="4">System activity will appear here when activity logging is available.</td></tr></tbody></table></div></section>
<section class="panel quick-access"><div class="panel-heading"><h3>Quick Access</h3></div><div class="quick-links"><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/users.php">User Management</a><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/inventory.php">View Inventory</a><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/orders.php">View Orders</a><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/reports.php">View Reports</a></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
