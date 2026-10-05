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
<div class="staff-dash">
<!-- ── Hero ── -->
<section class="welcome-block dashboard-welcome staff-hero">
    <div>
        <span class="staff-dash-kicker"><i class="fa-solid fa-boxes-stacked"></i> Bakery Inventory Operations</span>
        <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?>!</h2>
        <p>Monitor real-time bakery stock levels, track low stock warnings, and organize menu categories.</p>
    </div>
    <span class="dashboard-date"><i class="fa-regular fa-calendar"></i> <?php echo date('M j, Y'); ?></span>
</section>

<!-- Summary Metric Cards -->
<section class="summary-grid staff-summary-grid" aria-label="Inventory metrics">
    <article class="summary-card staff-stat is-brown">
        <div class="summary-top">
            <span>Total Bakery Items</span>
            <span class="summary-icon"><i class="fa-solid fa-bread-slice"></i></span>
        </div>
        <div class="summary-value"><?php echo $totalProducts; ?></div>
        <small class="staff-stat-note">Across <?php echo $totalCategories; ?> menu categories</small>
    </article>
    <article class="summary-card staff-stat is-blue">
        <div class="summary-top">
            <span>Total Stock Units</span>
            <span class="summary-icon"><i class="fa-solid fa-cubes-stacked"></i></span>
        </div>
        <div class="summary-value"><?php echo number_format($totalStockUnits); ?></div>
        <small class="staff-stat-note">Units currently on hand</small>
    </article>
    <article class="summary-card staff-stat is-green">
        <div class="summary-top">
            <span>In Stock (Good)</span>
            <span class="summary-icon"><i class="fa-solid fa-circle-check"></i></span>
        </div>
        <div class="summary-value"><?php echo $inStock; ?></div>
        <small class="staff-stat-note">Items holding more than 10 units</small>
    </article>
    <article class="summary-card staff-stat is-orange">
        <div class="summary-top">
            <span>Low Stock (&le;10)</span>
            <span class="summary-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        </div>
        <div class="summary-value"><?php echo $lowStock; ?></div>
        <small class="staff-stat-note">Needs restocking soon</small>
    </article>
    <article class="summary-card staff-stat is-red">
        <div class="summary-top">
            <span>Out of Stock</span>
            <span class="summary-icon"><i class="fa-solid fa-circle-xmark"></i></span>
        </div>
        <div class="summary-value"><?php echo $outOfStock; ?></div>
        <small class="staff-stat-note">No units left on the shelf</small>
    </article>
    <article class="summary-card staff-stat is-accent">
        <div class="summary-top">
            <span>Menu Categories</span>
            <span class="summary-icon"><i class="fa-solid fa-layer-group"></i></span>
        </div>
        <div class="summary-value"><?php echo $totalCategories; ?></div>
        <small class="staff-stat-note">Groups used to sort the menu</small>
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
            <a href="<?php echo BASE_URL; ?>/staff/inventory.php" class="text-link">Open Inventory <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <?php if ($inventoryAlerts): ?>
            <div class="table-wrap">
                <table class="admin-table dash-table dash-table-alerts">
                    <thead>
                        <tr>
                            <th>Item</th>
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
                                <td>
                                    <strong class="dash-item-name"><?php echo htmlspecialchars($alert['name']); ?></strong>
                                    <small class="dash-item-sub"><?php echo htmlspecialchars($alert['category_name']); ?></small>
                                </td>
                                <td><strong><?php echo $qty; ?></strong> units</td>
                                <td><span class="stock-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                <td class="dash-row-action"><a href="<?php echo BASE_URL; ?>/staff/inventory.php?search=<?php echo urlencode($alert['name']); ?>" class="admin-button secondary">Restock</a></td>
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
            <a href="<?php echo BASE_URL; ?>/staff/reports.php" class="text-link">Full Report <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="category-stock-list">
            <?php 
            $maxCategoryStock = max(1, ...array_column($categoryStats, 'total_quantity'));
            foreach ($categoryStats as $cat): 
                $units = (int) $cat['total_quantity'];
                $itemsCount = (int) $cat['item_count'];
                $percent = min(100, round(($units / $maxCategoryStock) * 100));
            ?>
                <div class="category-stock-item">
                    <div class="category-stock-head">
                        <span class="category-stock-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                        <span class="category-stock-count"><strong><?php echo number_format($units); ?></strong> units <small>(<?php echo $itemsCount; ?> <?php echo $itemsCount === 1 ? 'item' : 'items'; ?>)</small></span>
                    </div>
                    <div class="category-stock-track"><span style="width: <?php echo $percent; ?>%;"></span></div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<!-- Recently Added Products -->
<section class="dashboard-grid staff-dashboard-grid is-single">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <h3>Recently Added Products</h3>
                <p class="panel-subtitle">Newest additions to the bakery inventory.</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/staff/inventory.php" class="text-link">View All <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <?php if ($recentItems): ?>
            <div class="table-wrap">
                <table class="admin-table dash-table dash-table-recent">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Price Range</th>
                            <th>Total Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentItems as $item): ?>
                            <tr>
                                <td>
                                    <strong class="dash-item-name"><?php echo htmlspecialchars($item['name']); ?></strong>
                                    <small class="dash-item-sub"><?php echo htmlspecialchars($item['category_name']); ?></small>
                                </td>
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
</section>
</div>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
