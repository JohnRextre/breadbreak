<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Reports & Analytics';
$activePage = 'reports';

$pdo = getDatabaseConnection();

// Time range filter (all_time, today, 7_days, 30_days)
$range = trim($_GET['range'] ?? 'all_time');
$dateCondition = "";
if ($range === 'today') {
    $dateCondition = " AND DATE(o.created_at) = CURDATE()";
} elseif ($range === '7_days') {
    $dateCondition = " AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($range === '30_days') {
    $dateCondition = " AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

// 1. Executive Sales & Orders KPIs
$kpiStmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS total_revenue,
        COUNT(DISTINCT o.id) AS total_orders,
        COUNT(DISTINCT CASE WHEN p.status = 'paid' THEN o.id ELSE NULL END) AS paid_orders,
        COUNT(DISTINCT CASE WHEN o.status = 'processing' THEN o.id ELSE NULL END) AS processing_orders,
        COUNT(DISTINCT CASE WHEN o.status = 'completed' THEN o.id ELSE NULL END) AS completed_orders,
        COALESCE(AVG(CASE WHEN p.status = 'paid' THEN p.amount ELSE NULL END), 0) AS average_order_value
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.id
    WHERE 1=1 $dateCondition
");
$kpi = $kpiStmt->fetch();

$totalRevenue = (float) ($kpi['total_revenue'] ?? 0);
$totalOrders = (int) ($kpi['total_orders'] ?? 0);
$paidOrders = (int) ($kpi['paid_orders'] ?? 0);
$processingOrders = (int) ($kpi['processing_orders'] ?? 0);
$completedOrders = (int) ($kpi['completed_orders'] ?? 0);
$aov = (float) ($kpi['average_order_value'] ?? 0);

// Total items sold
$unitsSoldStmt = $pdo->query("
    SELECT COALESCE(SUM(oi.quantity), 0) 
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN payments p ON p.order_id = o.id
    WHERE p.status = 'paid' $dateCondition
");
$totalUnitsSold = (int) $unitsSoldStmt->fetchColumn();

// 2. Top-Selling Products (Best Sellers)
$topProductsStmt = $pdo->query("
    SELECT 
        oi.product_name,
        oi.service_size,
        oi.sku,
        SUM(oi.quantity) AS units_sold,
        SUM(oi.line_total) AS total_sales,
        AVG(oi.unit_price) AS unit_price
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN payments p ON p.order_id = o.id
    WHERE p.status = 'paid' $dateCondition
    GROUP BY oi.product_name, oi.service_size, oi.sku
    ORDER BY units_sold DESC, total_sales DESC
    LIMIT 6
");
$topProducts = $topProductsStmt->fetchAll();

// 3. Category Breakdown & Stock Valuation
$catStmt = $pdo->query("
    SELECT 
        c.name AS category_name,
        COUNT(DISTINCT i.id) AS product_count,
        COALESCE(SUM(v.quantity), 0) AS total_stock,
        COALESCE(SUM(v.quantity * v.price), 0) AS inventory_value
    FROM menu_categories c
    LEFT JOIN inventory_items i ON i.category_id = c.id
    LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
    GROUP BY c.id, c.name
    ORDER BY total_stock DESC, c.name ASC
");
$categorySummary = $catStmt->fetchAll();

$totalInventoryValue = array_sum(array_column($categorySummary, 'inventory_value'));
$totalStockUnits = array_sum(array_column($categorySummary, 'total_stock'));

// 4. Recent Verified Payments
$recentPaymentsStmt = $pdo->query("
    SELECT 
        o.reference_id,
        u.first_name,
        u.last_name,
        p.amount,
        p.payment_method,
        p.payment_channel,
        p.status AS payment_status,
        o.status AS order_status,
        o.created_at
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    JOIN users u ON u.id = o.customer_id
    ORDER BY p.id DESC
    LIMIT 8
");
$recentPayments = $recentPaymentsStmt->fetchAll();

require __DIR__ . '/../includes/staff_header.php';
?>

<section class="page-intro inventory-page-intro" style="margin-bottom: 22px;">
    <div>
        <span class="eyebrow" style="font-size: 11px; font-weight: 800; color: var(--admin-brown); letter-spacing: 0.1em; text-transform: uppercase;">Staff Analytics &amp; Performance</span>
        <h2 style="margin: 4px 0 6px; font-family: 'Manrope', sans-serif; font-size: 26px; color: var(--admin-ink);">Reports &amp; Analytics</h2>
        <p style="margin: 0; color: var(--admin-muted); font-size: 13px;">Overview of sales performance, paid revenue from Xendit, and inventory valuation.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <button onclick="window.print()" class="admin-button secondary" style="height: 42px; border-radius: 8px;">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</section>

<!-- Time Range Filter -->
<section class="panel" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <span style="font-size: 13px; font-weight: 700; color: var(--admin-brown-dark);">
        <i class="fa-solid fa-calendar-days" style="margin-right: 6px; color: var(--admin-muted);"></i> Reporting Period
    </span>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="?range=all_time" class="admin-button <?php echo $range === 'all_time' ? 'primary' : 'secondary'; ?>" style="height: 34px; font-size: 11px; padding: 0 14px; border-radius: 6px;">All Time</a>
        <a href="?range=today" class="admin-button <?php echo $range === 'today' ? 'primary' : 'secondary'; ?>" style="height: 34px; font-size: 11px; padding: 0 14px; border-radius: 6px;">Today</a>
        <a href="?range=7_days" class="admin-button <?php echo $range === '7_days' ? 'primary' : 'secondary'; ?>" style="height: 34px; font-size: 11px; padding: 0 14px; border-radius: 6px;">Last 7 Days</a>
        <a href="?range=30_days" class="admin-button <?php echo $range === '30_days' ? 'primary' : 'secondary'; ?>" style="height: 34px; font-size: 11px; padding: 0 14px; border-radius: 6px;">Last 30 Days</a>
    </div>
</section>

<!-- KPI Cards -->
<section class="orders-summary-grid">
    <article class="summary-card">
        <div class="summary-top">
            <span>Total Revenue (Paid)</span>
            <div class="summary-icon" style="background: rgba(40, 160, 103, 0.12); color: #28a067;">
                <i class="fa-solid fa-peso-sign"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #28a067;">₱<?php echo number_format($totalRevenue, 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            From <?php echo $paidOrders; ?> paid customer transactions
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Average Order Value</span>
            <div class="summary-icon" style="background: rgba(32, 82, 168, 0.1); color: #2052a8;">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #2052a8;">₱<?php echo number_format($aov, 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            Per completed transaction
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Units Sold</span>
            <div class="summary-icon" style="background: rgba(201, 121, 64, 0.12); color: #c97940;">
                <i class="fa-solid fa-bag-shopping"></i>
            </div>
        </div>
        <div class="summary-value" style="color: var(--admin-brown);"><?php echo $totalUnitsSold; ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            Total bakery items fulfilled
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Inventory Valuation</span>
            <div class="summary-icon" style="background: rgba(123, 82, 59, 0.1); color: var(--admin-brown);">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>
        <div class="summary-value">₱<?php echo number_format($totalInventoryValue, 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            Across <?php echo $totalStockUnits; ?> current stock units
        </small>
    </article>
</section>

<!-- Dual Grid: Best Sellers & Category Breakdown -->
<div style="display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 20px; margin-bottom: 24px;">

    <!-- Top Selling Products -->
    <section class="panel" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 22px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
        <div class="panel-heading" style="margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Top Selling Bakery Items</h3>
                <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">Best-selling products by quantity and revenue generated.</p>
            </div>
            <i class="fa-solid fa-fire" style="color: #c97940; font-size: 18px;"></i>
        </div>

        <?php if (empty($topProducts)): ?>
            <div style="text-align: center; padding: 30px; color: var(--admin-muted); font-size: 13px;">
                <i class="fa-solid fa-receipt" style="font-size: 30px; opacity: 0.4; margin-bottom: 8px; display: block;"></i>
                No sales recorded for this reporting period.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Product &amp; Size</th>
                            <th>SKU</th>
                            <th style="text-align: right;">Qty Sold</th>
                            <th style="text-align: right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $tp): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($tp['product_name']); ?></strong>
                                <span style="display: block; font-size: 11px; color: var(--admin-muted);">
                                    <?php echo htmlspecialchars($tp['service_size']); ?> · ₱<?php echo number_format((float) $tp['unit_price'], 2); ?>
                                </span>
                            </td>
                            <td><span style="font-family: monospace; font-size: 11px; color: var(--admin-muted);"><?php echo htmlspecialchars($tp['sku']); ?></span></td>
                            <td style="text-align: right;"><strong style="color: var(--admin-brown);"><?php echo (int) $tp['units_sold']; ?></strong></td>
                            <td style="text-align: right;"><strong style="color: #28a067;">₱<?php echo number_format((float) $tp['total_sales'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Category Valuation Breakdown -->
    <section class="panel" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 22px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
        <div class="panel-heading" style="margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Category Stock &amp; Value</h3>
                <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">Inventory units and valuation by menu category.</p>
            </div>
            <i class="fa-solid fa-layer-group" style="color: var(--admin-brown); font-size: 18px;"></i>
        </div>

        <div class="table-wrap">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th style="text-align: right;">Items</th>
                        <th style="text-align: right;">Stock Units</th>
                        <th style="text-align: right;">Est. Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categorySummary as $cat): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                        <td style="text-align: right;"><?php echo (int) $cat['product_count']; ?></td>
                        <td style="text-align: right;"><span class="stock-badge <?php echo (int) $cat['total_stock'] <= 10 ? 'stock-low' : 'stock-in'; ?>"><?php echo (int) $cat['total_stock']; ?></span></td>
                        <td style="text-align: right; font-weight: 700; color: var(--admin-ink);">₱<?php echo number_format((float) $cat['inventory_value'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<!-- Recent Transactions Table -->
<section class="panel" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 24px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
    <div class="panel-heading" style="margin-bottom: 18px;">
        <div>
            <h3 style="font-size: 16px; margin: 0; color: var(--admin-ink);">Recent Payment Transactions</h3>
            <p style="margin: 3px 0 0; color: var(--admin-muted); font-size: 12px;">Real-time payment logs and verification statuses from Xendit.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/staff/orders.php" class="admin-button secondary" style="height: 36px; padding: 0 14px; font-size: 11px;">
            View All Orders
        </a>
    </div>

    <?php if (empty($recentPayments)): ?>
        <div class="empty-state" style="text-align: center; padding: 40px 20px;">
            <p style="color: var(--admin-muted); font-size: 13px;">No transaction records found.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="border-radius: 10px; border: 1px solid var(--admin-line);">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Channel</th>
                        <th>Payment Status</th>
                        <th>Order Status</th>
                        <th>Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $rp): 
                        $pStatus = strtolower((string) $rp['payment_status']);
                        $oStatus = strtolower((string) $rp['order_status']);
                    ?>
                    <tr>
                        <td>
                            <span class="order-ref-badge">
                                #<?php echo htmlspecialchars($rp['reference_id']); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']); ?></strong>
                        </td>
                        <td>
                            <strong style="color: var(--admin-brown); font-size: 14px;">
                                ₱<?php echo number_format((float) $rp['amount'], 2); ?>
                            </strong>
                        </td>
                        <td>
                            <span class="order-pay-channel">
                                <i class="fa-solid fa-mobile-screen"></i> <?php echo htmlspecialchars($rp['payment_channel'] ?? 'GCash'); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($pStatus === 'paid'): ?>
                                <span class="status-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
                            <?php elseif ($pStatus === 'failed'): ?>
                                <span class="status-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> Failed</span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($oStatus === 'processing'): ?>
                                <span class="status-chip is-processing"><i class="fa-solid fa-rotate"></i> Processing</span>
                            <?php elseif ($oStatus === 'completed'): ?>
                                <span class="status-chip is-completed"><i class="fa-solid fa-check"></i> Completed</span>
                            <?php elseif ($oStatus === 'cancelled'): ?>
                                <span class="status-chip is-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="color: var(--admin-muted); font-size: 12px;">
                            <?php echo date('M d, Y · g:i A', strtotime($rp['created_at'])); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
