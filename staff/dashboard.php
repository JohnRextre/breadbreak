<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();

$totalProducts = 0;
$totalStockUnits = 0;
$inStock = 0;
$lowStock = 0;
$outOfStock = 0;
$totalCategories = 0;
$inventoryAlerts = [];
$categoryStats = [];
$recentItems = [];

// Summary metrics
$inventorySummary = $pdo->query('
    SELECT 
        COUNT(*) AS item_count, 
        COALESCE(SUM(total_quantity), 0) AS stock_units,
        SUM(total_quantity > 10) AS in_stock,
        SUM(total_quantity BETWEEN 1 AND 10) AS low_stock, 
        SUM(total_quantity <= 0) AS out_of_stock 
    FROM (
        SELECT i.id, COALESCE(SUM(v.quantity), 0) AS total_quantity 
        FROM inventory_items i 
        LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id 
        GROUP BY i.id
    ) inventory_totals
')->fetch();

$totalProducts = (int) ($inventorySummary['item_count'] ?? 0);
$totalStockUnits = (int) ($inventorySummary['stock_units'] ?? 0);
$inStock = (int) ($inventorySummary['in_stock'] ?? 0);
$lowStock = (int) ($inventorySummary['low_stock'] ?? 0);
$outOfStock = (int) ($inventorySummary['out_of_stock'] ?? 0);

$totalCategories = (int) $pdo->query('SELECT COUNT(*) FROM menu_categories')->fetchColumn();

// Low and Out of Stock Alerts
$inventoryAlerts = $pdo->query('
    SELECT 
        i.id, 
        i.name, 
        c.name AS category_name, 
        COUNT(v.id) AS variant_count,
        COALESCE(SUM(v.quantity), 0) AS total_quantity 
    FROM inventory_items i 
    JOIN menu_categories c ON c.id = i.category_id 
    LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id 
    GROUP BY i.id, i.name, c.name 
    HAVING total_quantity <= 10 
    ORDER BY total_quantity ASC, i.name ASC 
    LIMIT 8
')->fetchAll();

// Category stock distribution
$categoryStats = $pdo->query('
    SELECT 
        c.id, 
        c.name, 
        COUNT(DISTINCT i.id) AS item_count, 
        COALESCE(SUM(v.quantity), 0) AS total_quantity 
    FROM menu_categories c 
    LEFT JOIN inventory_items i ON i.category_id = c.id 
    LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id 
    GROUP BY c.id, c.name 
    ORDER BY total_quantity DESC, c.name ASC
')->fetchAll();

// Recently Added Items
$recentItems = $pdo->query('
    SELECT 
        i.id, 
        i.name, 
        i.created_at, 
        c.name AS category_name, 
        COUNT(v.id) AS variant_count,
        COALESCE(SUM(v.quantity), 0) AS total_quantity,
        MIN(v.price) AS min_price,
        MAX(v.price) AS max_price
    FROM inventory_items i 
    JOIN menu_categories c ON c.id = i.category_id 
    LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id 
    GROUP BY i.id, i.name, i.created_at, c.name 
    ORDER BY i.created_at DESC, i.id DESC 
    LIMIT 5
')->fetchAll();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/../includes/staff_header.php';
?>
<section class="access-card" aria-label="Staff operations intro">
    <div class="access-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
    <div>
        <h2>Bakery Inventory Operations</h2>
        <p>Monitor real-time bakery stock levels, track low stock warnings, and organize menu categories.</p>
    </div>
</section>

<section class="welcome-block">
    <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?>!</h2>
    <p>Here is your bakery stock summary for today.</p>
</section>

<!-- Summary Metric Cards -->
<section class="summary-grid staff-summary-grid" aria-label="Inventory metrics">
    <article class="summary-card">
        <div class="summary-top">
            <span>TOTAL BAKERY ITEMS</span>
            <span class="summary-icon"><i class="fa-solid fa-bread-slice"></i></span>
        </div>
        <div class="summary-value"><?php echo $totalProducts; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>TOTAL STOCK UNITS</span>
            <span class="summary-icon"><i class="fa-solid fa-cubes-stacked"></i></span>
        </div>
        <div class="summary-value"><?php echo number_format($totalStockUnits); ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>IN STOCK (GOOD)</span>
            <span class="summary-icon" style="color: var(--admin-green);"><i class="fa-solid fa-circle-check"></i></span>
        </div>
        <div class="summary-value" style="color: var(--admin-green);"><?php echo $inStock; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>LOW STOCK (&le;10)</span>
            <span class="summary-icon" style="color: var(--admin-orange);"><i class="fa-solid fa-triangle-exclamation"></i></span>
        </div>
        <div class="summary-value" style="color: var(--admin-orange);"><?php echo $lowStock; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>OUT OF STOCK</span>
            <span class="summary-icon" style="color: #a34f43;"><i class="fa-solid fa-circle-xmark"></i></span>
        </div>
        <div class="summary-value" style="color: #a34f43;"><?php echo $outOfStock; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>MENU CATEGORIES</span>
            <span class="summary-icon"><i class="fa-solid fa-layer-group"></i></span>
        </div>
        <div class="summary-value"><?php echo $totalCategories; ?></div>
    </article>
</section>

<!-- Main Dashboard Split Grid -->
<section class="dashboard-grid staff-dashboard-grid">
    <!-- Urgent Stock Alerts -->
    <article class="panel inventory-alert-panel" style="margin-top: 0;">
        <div class="panel-heading">
            <div>
                <h3>Stock Alerts</h3>
                <p class="panel-subtitle">Items that require restocking (10 or fewer units).</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/staff/inventory.php" class="text-link">Open Inventory</a>
        </div>
        <?php if ($inventoryAlerts): ?>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventoryAlerts as $alert): 
                            $qty = (int) $alert['total_quantity'];
                            $isOut = $qty === 0;
                            $statusLabel = $isOut ? 'Out of Stock' : 'Low Stock';
                            $statusClass = $isOut ? 'stock-out' : 'stock-low';
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($alert['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($alert['category_name']); ?></td>
                                <td><strong><?php echo $qty; ?></strong> units</td>
                                <td><span class="stock-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                <td><a href="<?php echo BASE_URL; ?>/staff/inventory.php?search=<?php echo urlencode($alert['name']); ?>" class="admin-button secondary" style="padding: 4px 8px; font-size: 11px;">Restock</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state-card" style="text-align: center; padding: 25px 15px; background: #faf8f6; border-radius: 8px;">
                <i class="fa-solid fa-circle-check" style="font-size: 28px; color: var(--admin-green); margin-bottom: 8px;"></i>
                <p style="margin: 0; font-weight: 600; color: var(--admin-ink);">Stock levels are healthy!</p>
                <small style="color: var(--admin-muted);">No products currently have low or depleted stock.</small>
            </div>
        <?php endif; ?>
    </article>

    <!-- Category Stock Distribution -->
    <article class="panel" style="margin-top: 0;">
        <div class="panel-heading">
            <div>
                <h3>Stock by Category</h3>
                <p class="panel-subtitle">Available units across menu categories.</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/staff/reports.php" class="text-link">Full Report</a>
        </div>
        <div class="category-stock-list">
            <?php 
            $maxCategoryStock = max(1, ...array_column($categoryStats, 'total_quantity'));
            foreach ($categoryStats as $cat): 
                $units = (int) $cat['total_quantity'];
                $itemsCount = (int) $cat['item_count'];
                $percent = min(100, round(($units / $maxCategoryStock) * 100));
            ?>
                <div class="category-stock-item" style="margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                        <span style="font-weight: 700; color: var(--admin-brown-dark);"><?php echo htmlspecialchars($cat['name']); ?></span>
                        <span><strong><?php echo number_format($units); ?></strong> units <small style="color: var(--admin-muted);">(<?php echo $itemsCount; ?> <?php echo $itemsCount === 1 ? 'item' : 'items'; ?>)</small></span>
                    </div>
                    <div style="height: 7px; background: #f0ece8; border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?php echo $percent; ?>%; background: var(--admin-brown); border-radius: 4px;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<!-- Recently Added Items & Quick Access -->
<section class="dashboard-grid staff-dashboard-grid" style="margin-top: 18px;">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <h3>Recently Added Products</h3>
                <p class="panel-subtitle">Newest additions to the bakery inventory.</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/staff/inventory.php" class="text-link">View All</a>
        </div>
        <?php if ($recentItems): ?>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Price Range</th>
                            <th>Total Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentItems as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                <td>
                                    ₱<?php echo number_format((float) $item['min_price'], 2); ?>
                                    <?php if ((float) $item['min_price'] !== (float) $item['max_price']): ?>
                                        – ₱<?php echo number_format((float) $item['max_price'], 2); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $item['total_quantity']; ?> units</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty-state">No inventory items recorded yet.</p>
        <?php endif; ?>
    </article>

    <article class="panel quick-access">
        <div class="panel-heading">
            <h3>Quick Actions</h3>
        </div>
        <div class="quick-links">
            <a class="quick-link" href="<?php echo BASE_URL; ?>/staff/inventory.php">
                <i class="fa-solid fa-boxes-stacked"></i> Manage Inventory
            </a>
            <a class="quick-link" href="<?php echo BASE_URL; ?>/staff/inventory.php">
                <i class="fa-solid fa-plus-circle"></i> Add New Product
            </a>
            <a class="quick-link" href="<?php echo BASE_URL; ?>/staff/reports.php">
                <i class="fa-solid fa-chart-pie"></i> Inventory Reports
            </a>
            <a class="quick-link" href="<?php echo BASE_URL; ?>/staff/profile.php">
                <i class="fa-solid fa-circle-user"></i> My Profile
            </a>
        </div>
    </article>
</section>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
