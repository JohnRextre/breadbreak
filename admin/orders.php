<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Orders Monitoring';
$activePage = 'orders';

$pdo = getDatabaseConnection();

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');

$query = "SELECT o.id, o.reference_id, o.status AS order_status, o.created_at,
                 o.fulfillment_type, o.delivery_fee, o.delivery_address, o.discount_type, o.discount_amount, o.discount_id_number, o.discount_name,
                 u.first_name, u.last_name, u.email, u.phone,
                 p.amount, p.payment_method, p.payment_channel, p.status AS payment_status
          FROM orders o
          JOIN users u ON u.id = o.customer_id
          LEFT JOIN payments p ON p.order_id = o.id
          WHERE 1=1";

$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'processing', 'completed', 'cancelled'], true)) {
    $query .= " AND o.status = :status";
    $params['status'] = $statusFilter;
}

if ($search !== '') {
    $query .= " AND (o.reference_id LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

$query .= " ORDER BY o.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Fetch items for all visible orders
$orderIds = array_column($orders, 'id');
$orderItemsMap = [];
if (!empty($orderIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare(
        "SELECT order_id, product_name, service_size, sku, unit_price, quantity, line_total
         FROM order_items
         WHERE order_id IN ($inPlaceholders)
         ORDER BY id ASC"
    );
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $orderItemsMap[$item['order_id']][] = $item;
    }
}

// Summary counts
$totalOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalSales = (float) $pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'paid'")->fetchColumn();
$processingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'processing'")->fetchColumn();
$completedOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'")->fetchColumn();

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro users-page-intro" style="margin-bottom: 22px;">
    <div>
        <span class="eyebrow" style="font-size: 11px; font-weight: 800; color: var(--admin-brown); letter-spacing: 0.1em; text-transform: uppercase;">Sales &amp; Analytics</span>
        <h2 style="margin: 4px 0 6px; font-family: 'Manrope', sans-serif; font-size: 26px; color: var(--admin-ink);">Orders Monitoring</h2>
        <p style="margin: 0; color: var(--admin-muted); font-size: 13px;">View customer orders, real-time Xendit GCash payments, and sales analytics.</p>
    </div>
</section>

<!-- Summary Cards Grid -->
<section class="orders-summary-grid">
    <article class="summary-card">
        <div class="summary-top">
            <span>Total Orders</span>
            <div class="summary-icon" style="background: rgba(123, 82, 59, 0.1); color: var(--admin-brown);">
                <i class="fa-solid fa-receipt"></i>
            </div>
        </div>
        <div class="summary-value"><?php echo $totalOrders; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Total Paid Sales</span>
            <div class="summary-icon" style="background: rgba(40, 160, 103, 0.1); color: #28a067;">
                <i class="fa-solid fa-peso-sign"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #28a067;">₱<?php echo number_format($totalSales, 2); ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Processing</span>
            <div class="summary-icon" style="background: rgba(32, 82, 168, 0.1); color: #2052a8;">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #2052a8;"><?php echo $processingOrders; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Completed</span>
            <div class="summary-icon" style="background: rgba(201, 121, 64, 0.12); color: #c97940;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="summary-value" style="color: var(--admin-brown);"><?php echo $completedOrders; ?></div>
    </article>
</section>

<!-- Main Orders Panel -->
<section class="panel" style="background: #fff; border: 1px solid var(--admin-line); border-radius: 14px; padding: 24px; box-shadow: 0 4px 20px rgba(39, 29, 23, 0.04);">
    <form method="GET" class="orders-filter-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="search" placeholder="Search by order #, customer name, email..." value="<?php echo htmlspecialchars($search); ?>" />
        </div>
        <div class="filter-actions">
            <div class="select-control" style="margin: 0;">
                <select name="status" onchange="this.form.submit()" style="height: 42px; border-radius: 8px; border: 1px solid var(--admin-line); padding: 0 32px 0 12px; font-size: 13px; font-weight: 600; color: var(--admin-ink);">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="processing" <?php echo $statusFilter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <button type="submit" class="admin-button primary" style="height: 42px; border-radius: 8px; padding: 0 18px;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                <a href="orders.php" class="admin-button secondary" style="height: 42px; border-radius: 8px; padding: 0 16px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($orders)): ?>
        <div class="empty-state" style="text-align: center; padding: 60px 20px;">
            <div style="width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; background: #faf7f4; display: flex; align-items: center; justify-content: center; font-size: 26px; color: var(--admin-muted);">
                <i class="fa-solid fa-basket-shopping"></i>
            </div>
            <h3 style="color: var(--admin-brown-dark); margin: 0 0 6px; font-size: 16px;">No orders found</h3>
            <p style="color: var(--admin-muted); font-size: 13px; margin: 0;">There are no customer orders matching your search or status filter.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="overflow-x: auto; border-radius: 10px; border: 1px solid var(--admin-line);">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th style="min-width: 170px;">Order Reference</th>
                        <th style="min-width: 170px;">Customer</th>
                        <th style="min-width: 260px;">Purchased Items</th>
                        <th style="min-width: 130px;">Total Amount</th>
                        <th style="min-width: 130px;">Payment</th>
                        <th style="min-width: 130px;">Order Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o):
                        $oid = (int) $o['id'];
                        $items = $orderItemsMap[$oid] ?? [];
                        $payStatus = strtolower((string) ($o['payment_status'] ?? 'pending'));
                        $ordStatus = strtolower((string) $o['order_status']);
                    ?>
                    <tr>
                        <td>
                            <span class="order-ref-badge">
                                <i class="fa-solid fa-receipt" style="font-size: 10px; opacity: 0.7;"></i>
                                #<?php echo htmlspecialchars($o['reference_id']); ?>
                            </span>
                            <span class="order-date-text">
                                <?php echo date('M d, Y · g:i A', strtotime($o['created_at'])); ?>
                            </span>
                            <?php if (!empty($o['discount_type']) && $o['discount_type'] !== 'none'): ?>
                                <div style="margin-top: 5px;">
                                    <span style="display: inline-block; background: #e8f7ef; color: #1a6645; border: 1px solid #a3d9bc; padding: 2px 6px; border-radius: 6px; font-size: 10px; font-weight: 700;">
                                        <?php echo $o['discount_type'] === 'senior' ? '🧓 Senior 20%' : '♿ PWD 20%'; ?>
                                    </span>
                                    <small style="display: block; font-size: 10px; color: var(--admin-muted); margin-top: 2px;">
                                        ID: <strong><?php echo htmlspecialchars($o['discount_id_number'] ?? '—'); ?></strong>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="customer-info-cell">
                            <strong><i class="fa-solid fa-user" style="font-size: 11px; margin-right: 4px; color: var(--admin-muted);"></i><?php echo htmlspecialchars($o['first_name'] . ' ' . $o['last_name']); ?></strong>
                            <small><i class="fa-solid fa-phone" style="font-size: 9px; margin-right: 3px;"></i><?php echo htmlspecialchars($o['phone']); ?></small>
                            <?php if (($o['fulfillment_type'] ?? 'delivery') === 'pickup'): ?>
                                <span style="display: inline-block; background: #e8f7ef; color: #1a6645; border: 1px solid #a3d9bc; padding: 2px 6px; border-radius: 6px; font-size: 10px; font-weight: 700; margin-top: 3px;">
                                    <i class="fa-solid fa-store"></i> Store Pickup
                                </span>
                            <?php elseif (!empty($o['delivery_address'])): ?>
                                <small style="color: #7b523b; display: block; margin-top: 3px; max-width: 180px; line-height: 1.3;" title="<?php echo htmlspecialchars($o['delivery_address']); ?>">
                                    <i class="fa-solid fa-truck" style="font-size: 9px; margin-right: 2px; color: var(--admin-brown);"></i><?php echo htmlspecialchars(mb_strimwidth($o['delivery_address'], 0, 45, '...')); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="order-items-list">
                                <?php foreach ($items as $it): ?>
                                    <div class="order-item-pill">
                                        <strong><?php echo htmlspecialchars($it['product_name']); ?></strong>
                                        <span class="item-size"><?php echo htmlspecialchars($it['service_size']); ?></span>
                                        <span class="item-qty">× <?php echo (int) $it['quantity']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <div class="order-price-val">
                                ₱<?php echo number_format((float) ($o['amount'] ?? 0), 2); ?>
                            </div>
                            <?php if ((float)($o['delivery_fee'] ?? 0) > 0): ?>
                                <small style="display: block; font-size: 10px; color: var(--admin-muted);">+ ₱<?php echo number_format((float) $o['delivery_fee'], 2); ?> Del.</small>
                            <?php endif; ?>
                            <span class="order-pay-channel">
                                <i class="fa-solid fa-mobile-screen"></i> GCash
                            </span>
                        </td>
                        <td>
                            <?php if ($payStatus === 'paid'): ?>
                                <span class="status-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
                            <?php elseif ($payStatus === 'failed'): ?>
                                <span class="status-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> Failed</span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ordStatus === 'processing'): ?>
                                <span class="status-chip is-processing"><i class="fa-solid fa-rotate"></i> Processing</span>
                            <?php elseif ($ordStatus === 'completed'): ?>
                                <span class="status-chip is-completed"><i class="fa-solid fa-check"></i> Completed</span>
                            <?php elseif ($ordStatus === 'cancelled'): ?>
                                <span class="status-chip is-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
