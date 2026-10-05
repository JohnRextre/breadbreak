<?php
// =============================================================================
// admin/dashboard.php  –  Admin dashboard
// At-a-glance system health: revenue, orders, team, stock alerts and the live
// activity trail. Every panel is backed by real data.
// =============================================================================
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

/* ── Team & account counts ────────────────────────────────────────────────── */
$userCounts = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(role = 'customer'), 0) AS customers,
            COALESCE(SUM(role = 'staff'), 0) AS staff,
            COALESCE(SUM(role = 'rider'), 0) AS riders,
            COALESCE(SUM(role = 'admin'), 0) AS admins
     FROM users"
)->fetch() ?: [];

/* ── Inventory health ─────────────────────────────────────────────────────── */
$totalProducts    = 0;
$totalStockUnits  = 0;
$lowStock         = 0;
$outOfStock       = 0;
$inventoryValue   = 0.0;
$inventoryAlerts  = [];
$hasInventoryTables = adminTableExists($pdo, 'inventory_items') && adminTableExists($pdo, 'inventory_item_variants');

if ($hasInventoryTables) {
    $inventorySummary = $pdo->query(
        'SELECT COUNT(*) AS item_count,
                COALESCE(SUM(total_quantity), 0) AS stock_units,
                COALESCE(SUM(stock_value), 0) AS stock_value,
                SUM(total_quantity BETWEEN 1 AND 10) AS low_stock,
                SUM(total_quantity <= 0) AS out_of_stock
         FROM (
             SELECT i.id,
                    COALESCE(SUM(v.quantity), 0) AS total_quantity,
                    COALESCE(SUM(v.quantity * v.price), 0) AS stock_value
             FROM inventory_items i
             LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
             GROUP BY i.id
         ) inventory_totals'
    )->fetch() ?: [];

    $totalProducts   = (int) ($inventorySummary['item_count'] ?? 0);
    $totalStockUnits = (int) ($inventorySummary['stock_units'] ?? 0);
    $inventoryValue  = (float) ($inventorySummary['stock_value'] ?? 0);
    $lowStock        = (int) ($inventorySummary['low_stock'] ?? 0);
    $outOfStock      = (int) ($inventorySummary['out_of_stock'] ?? 0);

    $inventoryAlerts = $pdo->query(
        "SELECT i.id, i.name, c.name AS category_name, COALESCE(SUM(v.quantity), 0) AS total_quantity
         FROM inventory_items i
         JOIN menu_categories c ON c.id = i.category_id
         LEFT JOIN inventory_item_variants v ON v.inventory_item_id = i.id
         GROUP BY i.id, i.name, c.name
         HAVING total_quantity <= 10
         ORDER BY total_quantity ASC, i.name ASC
         LIMIT 6"
    )->fetchAll();
}

/* ── Orders & revenue ─────────────────────────────────────────────────────── */
$totalOrders       = 0;
$openOrders        = 0;
$completedOrders   = 0;
$cancelledOrders   = 0;
$paidRevenue       = 0.0;
$recentOrders      = [];
$hasOrdersTable    = adminTableExists($pdo, 'orders');

if ($hasOrdersTable) {
    $orderCounts = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'pending'), 0) + COALESCE(SUM(status = 'processing'), 0)
                    + COALESCE(SUM(status = 'out_for_delivery'), 0) + COALESCE(SUM(status = 'ready_for_pickup'), 0) AS open_orders,
                COALESCE(SUM(status = 'completed'), 0) AS completed,
                COALESCE(SUM(status = 'cancelled'), 0) AS cancelled
         FROM orders"
    )->fetch() ?: [];

    $totalOrders     = (int) ($orderCounts['total'] ?? 0);
    $openOrders      = (int) ($orderCounts['open_orders'] ?? 0);
    $completedOrders = (int) ($orderCounts['completed'] ?? 0);
    $cancelledOrders = (int) ($orderCounts['cancelled'] ?? 0);

    if (adminTableExists($pdo, 'payments')) {
        $paidRevenue = (float) $pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'"
        )->fetchColumn();
    }

    $recentOrders = $pdo->query(
        "SELECT o.reference_id, o.total_amount, o.status, o.fulfillment_type, o.created_at,
                u.first_name, u.last_name
         FROM orders o
         JOIN users u ON u.id = o.customer_id
         ORDER BY o.id DESC
         LIMIT 6"
    )->fetchAll();
}

/* ── Voucher pulse ────────────────────────────────────────────────────────── */
$voucherTotals = ['active' => 0, 'promoted' => 0, 'saved' => 0.0, 'used' => 0];
if (adminTableExists($pdo, 'vouchers')) {
    $voucherRow = $pdo->query(
        "SELECT COUNT(*) AS active, COALESCE(SUM(is_promoted = 1), 0) AS promoted FROM vouchers WHERE status = 'active'"
    )->fetch() ?: [];
    $voucherTotals['active']   = (int) ($voucherRow['active'] ?? 0);
    $voucherTotals['promoted'] = (int) ($voucherRow['promoted'] ?? 0);

    if (adminTableExists($pdo, 'customer_vouchers')) {
        $voucherTotals['used'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM customer_vouchers WHERE status = 'used'"
        )->fetchColumn();
    }
    if ($hasOrdersTable) {
        $voucherTotals['saved'] = (float) $pdo->query(
            'SELECT COALESCE(SUM(voucher_discount), 0) FROM orders'
        )->fetchColumn();
    }
}

/* ── Activity trail ───────────────────────────────────────────────────────── */
$recentActivity = [];
if (adminTableExists($pdo, 'order_status_history')) {
    $recentActivity = $pdo->query(
        "SELECT h.to_status, h.actor_role, h.actor_name, h.note, h.created_at, o.reference_id
         FROM order_status_history h
         LEFT JOIN orders o ON o.id = h.order_id
         ORDER BY h.id DESC
         LIMIT 3"
    )->fetchAll();
}

/** Maps a status change to a human label + icon. */
function dashboardActivityLabel(string $status): array
{
    $map = [
        'pending'          => ['Order placed', 'fa-receipt', 'is-info'],
        'processing'       => ['Baking started', 'fa-bread-slice', 'is-warn'],
        'out_for_delivery' => ['Out for delivery', 'fa-motorcycle', 'is-info'],
        'ready_for_pickup' => ['Ready for pickup', 'fa-store', 'is-warn'],
        'completed'        => ['Order completed', 'fa-circle-check', 'is-good'],
        'cancelled'        => ['Order cancelled', 'fa-ban', 'is-bad'],
    ];
    return $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'fa-circle', 'is-info'];
}

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/../includes/admin_header.php';
?><!-- ── Welcome ── -->
<section class="welcome-block dashboard-welcome">
    <div>
        <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</h2>
        <p>Here's what's happening across BreadBreak today.</p>
    </div>
    <span class="dashboard-date"><i class="fa-regular fa-calendar"></i> <?php echo date('M j, Y'); ?></span>
</section>

<!-- ── KPI Cards ── -->
<section class="orders-summary-grid is-triplet" aria-label="System summary">
    <article class="summary-card">
        <div class="summary-top">
            <span>Paid Revenue</span>
            <div class="summary-icon" style="background: rgba(40, 160, 103, 0.12); color: #28a067;"><i class="fa-solid fa-peso-sign"></i></div>
        </div>
        <div class="summary-value" style="color: #28a067;">₱<?php echo number_format($paidRevenue, 2); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">Across all settled payments</small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Total Orders</span>
            <div class="summary-icon" style="background: rgba(201, 121, 64, 0.12); color: #c97940;"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="summary-value" style="color: var(--admin-brown);"><?php echo $totalOrders; ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo $openOrders; ?> still open · <?php echo $completedOrders; ?> completed
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Active Vouchers</span>
            <div class="summary-icon" style="background: rgba(201, 121, 64, 0.12); color: #c97940;"><i class="fa-solid fa-ticket"></i></div>
        </div>
        <div class="summary-value" style="color: #c97940;"><?php echo $voucherTotals['active']; ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo $voucherTotals['promoted']; ?> promoted · ₱<?php echo number_format($voucherTotals['saved'], 0); ?> waived
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Customers</span>
            <div class="summary-icon" style="background: rgba(32, 82, 168, 0.1); color: #2052a8;"><i class="fa-solid fa-basket-shopping"></i></div>
        </div>
        <div class="summary-value" style="color: #2052a8;"><?php echo (int) ($userCounts['customers'] ?? 0); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo (int) ($userCounts['total'] ?? 0); ?> accounts in total
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Team</span>
            <div class="summary-icon" style="background: rgba(43, 135, 98, 0.1); color: #2b8762;"><i class="fa-solid fa-people-group"></i></div>
        </div>
        <div class="summary-value"><?php echo (int) ($userCounts['staff'] ?? 0) + (int) ($userCounts['riders'] ?? 0); ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo (int) ($userCounts['staff'] ?? 0); ?> staff · <?php echo (int) ($userCounts['riders'] ?? 0); ?> rider<?php echo (int) ($userCounts['riders'] ?? 0) === 1 ? '' : 's'; ?>
        </small>
    </article>

    <article class="summary-card">
        <div class="summary-top">
            <span>Stock Health</span>
            <div class="summary-icon" style="background: <?php echo $lowStock + $outOfStock > 0 ? 'rgba(190, 118, 43, 0.12)' : 'rgba(43, 135, 98, 0.1)'; ?>; color: <?php echo $lowStock + $outOfStock > 0 ? '#be762b' : '#2b8762'; ?>;">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>
        <div class="summary-value"><?php echo $totalStockUnits; ?></div>
        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--admin-muted);">
            <?php echo $lowStock + $outOfStock > 0
                ? $lowStock . ' low · ' . $outOfStock . ' out of stock'
                : 'All levels healthy'; ?>
        </small>
    </article>
</section>

<!-- ── Recent Orders + Stock Alerts ── -->
<div class="dashboard-grid">
    <article class="panel">
        <div class="panel-heading">
            <h3>Stock Alerts</h3>
            <a href="<?php echo BASE_URL; ?>/admin/inventory.php?stock=low">Open Monitoring</a>
        </div>
        <?php if ($inventoryAlerts): ?>
        <div class="inventory-stats">
            <?php foreach ($inventoryAlerts as $alert):
                $alertQty = (int) $alert['total_quantity'];
            ?>
                <a class="inventory-stat <?php echo $alertQty === 0 ? 'empty' : 'low'; ?>" href="<?php echo BASE_URL; ?>/admin/inventory.php?search=<?php echo urlencode($alert['name']); ?>">
                    <span><?php echo htmlspecialchars($alert['name']); ?></span>
                    <span><?php echo $alertQty; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p class="empty-state">No low-stock items. Inventory levels are healthy.</p>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="panel-heading">
            <h3>Recent Orders</h3>
            <a href="<?php echo BASE_URL; ?>/admin/orders.php">View All Orders</a>
        </div>
        <?php if ($recentOrders): ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentOrders as $order):
                    $orderStatus = strtolower((string) $order['status']);
                ?>
                    <tr>
                        <td><span class="order-ref-badge">#<?php echo htmlspecialchars($order['reference_id']); ?></span></td>
                        <td><?php echo htmlspecialchars(trim($order['first_name'] . ' ' . $order['last_name'])); ?></td>
                        <td><strong style="color: var(--admin-brown);">₱<?php echo number_format((float) $order['total_amount'], 2); ?></strong></td>
                        <td><span class="status-chip is-<?php echo htmlspecialchars($orderStatus); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $orderStatus))); ?></span></td>
                        <td style="color: var(--admin-muted); font-size: 12px;"><?php echo date('M j, Y · g:i A', strtotime($order['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="empty-state">No orders yet. They will appear here as soon as customers start ordering.</p>
        <?php endif; ?>
    </article>
</div>

<!-- ── Activity Trail ── -->
<section class="panel activity-panel">
    <div class="panel-heading">
        <div>
            <h3>Recent Activity</h3>
            <p class="panel-subtitle">Live trail of order status changes and actions.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/logs.php">View Logs</a>
    </div>
    <?php if ($recentActivity): ?>
    <ol class="activity-timeline">
        <?php foreach ($recentActivity as $entry):
            list($entryLabel, $entryIcon, $entryClass) = dashboardActivityLabel((string) $entry['to_status']);
        ?>
            <li class="activity-row">
                <span class="activity-dot <?php echo $entryClass; ?>"><i class="fa-solid <?php echo $entryIcon; ?>"></i></span>
                <div class="activity-body">
                    <strong><?php echo htmlspecialchars($entryLabel); ?><?php if (!empty($entry['reference_id'])): ?> · <span class="order-ref-badge">#<?php echo htmlspecialchars($entry['reference_id']); ?></span><?php endif; ?></strong>
                    <?php if (!empty($entry['note'])): ?>
                        <p><?php echo htmlspecialchars($entry['note']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="activity-meta">
                    <span><?php echo htmlspecialchars($entry['actor_name'] ?: ucfirst((string) $entry['actor_role'])); ?></span>
                    <small><?php echo date('M j, Y · g:i A', strtotime($entry['created_at'])); ?></small>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php else: ?>
        <p class="empty-state">Activity will appear here as orders move through the bakery.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>