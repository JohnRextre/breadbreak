<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';

function adminTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $statement->execute(['table_name' => $table]);
    return (bool) $statement->fetchColumn();
}

$pdo = getDatabaseConnection();
ensureRiderSupport($pdo);
$userCounts = $pdo->query("SELECT COUNT(*) AS total, SUM(role = 'customer') AS customers, SUM(role = 'staff') AS staff, SUM(role = 'rider') AS riders FROM users")->fetch();
$totalProducts = 0;
$totalStockUnits = 0;
$lowStock = 0;
$outOfStock = 0;
$totalOrders = 0;
$inventoryAlerts = [];
$hasInventoryTables = adminTableExists($pdo, 'inventory_items') && adminTableExists($pdo, 'inventory_item_variants');

if ($hasInventoryTables) {
    $inventorySummary = $pdo->query('SELECT COUNT(*) AS item_count, COALESCE(SUM(total_quantity), 0) AS stock_units, SUM(total_quantity BETWEEN 1 AND 10) AS low_stock, SUM(total_quantity <= 0) AS out_of_stock FROM (SELECT i.id, COALESCE(SUM(v.quantity), 0) AS total_quantity FROM inventory_items i LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id GROUP BY i.id) inventory_totals')->fetch();
    $totalProducts = (int) ($inventorySummary['item_count'] ?? 0);
    $totalStockUnits = (int) ($inventorySummary['stock_units'] ?? 0);
    $lowStock = (int) ($inventorySummary['low_stock'] ?? 0);
    $outOfStock = (int) ($inventorySummary['out_of_stock'] ?? 0);
    $inventoryAlerts = $pdo->query("SELECT i.name, c.name AS category_name, COALESCE(SUM(v.quantity), 0) AS total_quantity FROM inventory_items i JOIN menu_categories c ON c.id = i.category_id LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id GROUP BY i.id, i.name, c.name HAVING total_quantity <= 10 ORDER BY total_quantity ASC, i.name ASC LIMIT 6")->fetchAll();
}

if (!$hasInventoryTables && adminTableExists($pdo, 'products')) {
    if (!$totalProducts) $totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
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
<section class="access-card" aria-label="Admin access control"><div class="access-icon"><i class="fa-solid fa-circle-info"></i></div><div><h2>Admin Access Control</h2><p>System configuration, audit logs, and account management rights. Inventory and orders are view-only.</p></div></section>
<section class="welcome-block"><h2>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</h2><p>Here's an overview of your BreadBreak system.</p></section>
<section class="summary-grid" aria-label="System summary">
    <article class="summary-card"><div class="summary-top"><span>TOTAL USERS</span><span class="summary-icon"><i class="fa-solid fa-users"></i></span></div><div class="summary-value"><?php echo (int) ($userCounts['total'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL CUSTOMERS</span><span class="summary-icon"><i class="fa-solid fa-user-group"></i></span></div><div class="summary-value"><?php echo (int) ($userCounts['customers'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL STAFF</span><span class="summary-icon"><i class="fa-solid fa-user-tie"></i></span></div><div class="summary-value"><?php echo (int) ($userCounts['staff'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL RIDERS</span><span class="summary-icon"><i class="fa-solid fa-bicycle"></i></span></div><div class="summary-value"><?php echo (int) ($userCounts['riders'] ?? 0); ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>INVENTORY ITEMS</span><span class="summary-icon"><i class="fa-solid fa-bread-slice"></i></span></div><div class="summary-value"><?php echo $totalProducts; ?></div></article>
    <article class="summary-card"><div class="summary-top"><span>TOTAL ORDERS</span><span class="summary-icon"><i class="fa-solid fa-receipt"></i></span></div><div class="summary-value"><?php echo $totalOrders; ?></div></article>
</section>
<section class="dashboard-grid">
    <article class="panel"><div class="panel-heading"><h3>Inventory Overview</h3><a href="<?php echo BASE_URL; ?>/admin/inventory.php">View Inventory</a></div><div class="inventory-stats"><div class="inventory-stat"><span>Inventory Items</span><span><?php echo $totalProducts; ?></span></div><div class="inventory-stat"><span>Total Stock Units</span><span><?php echo $totalStockUnits; ?></span></div><div class="inventory-stat low"><span>Low Stock</span><span><?php echo $lowStock; ?></span></div><div class="inventory-stat empty"><span>Out of Stock</span><span><?php echo $outOfStock; ?></span></div></div></article>
    <article class="panel"><div class="panel-heading"><h3>Recent Orders</h3><a href="<?php echo BASE_URL; ?>/admin/orders.php">View All Orders</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Order ID</th><th>Customer</th><th>Order Date</th><th>Total</th><th>Status</th></tr></thead><tbody><tr><td colspan="5">Order records will appear here when the order module is available.</td></tr></tbody></table></div></article>
</section>
<section class="panel inventory-alert-panel"><div class="panel-heading"><div><h3>Inventory Monitoring</h3><p class="panel-subtitle">Items with ten or fewer units remaining.</p></div><a href="<?php echo BASE_URL; ?>/admin/inventory.php">Open Monitoring</a></div><?php if ($inventoryAlerts): ?><div class="table-wrap"><table class="admin-table"><thead><tr><th>Item</th><th>Category</th><th>Stock</th><th>Status</th></tr></thead><tbody><?php foreach ($inventoryAlerts as $alert): ?><tr><td><strong><?php echo htmlspecialchars($alert['name']); ?></strong></td><td><?php echo htmlspecialchars($alert['category_name']); ?></td><td><?php echo (int) $alert['total_quantity']; ?> units</td><td><span class="inventory-monitor-badge <?php echo (int) $alert['total_quantity'] === 0 ? 'empty' : 'low'; ?>"><?php echo (int) $alert['total_quantity'] === 0 ? 'Out of Stock' : 'Low Stock'; ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty-state">No low-stock items. Inventory levels are healthy.</p><?php endif; ?></section>
<section class="panel activity-panel"><div class="panel-heading"><h3>Recent System Activity</h3><a href="<?php echo BASE_URL; ?>/admin/logs.php">View Logs</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Activity</th><th>User</th><th>Date</th><th>Time</th></tr></thead><tbody><tr><td colspan="4">System activity will appear here when activity logging is available.</td></tr></tbody></table></div></section>
<section class="panel quick-access"><div class="panel-heading"><h3>Quick Access</h3></div><div class="quick-links"><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/users.php"><i class="fa-solid fa-users-gear"></i> User Management</a><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/inventory.php"><i class="fa-solid fa-boxes-stacked"></i> View Inventory</a><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/orders.php"><i class="fa-solid fa-receipt"></i> View Orders</a><a class="quick-link" href="<?php echo BASE_URL; ?>/admin/reports.php"><i class="fa-solid fa-chart-line"></i> View Reports</a></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
