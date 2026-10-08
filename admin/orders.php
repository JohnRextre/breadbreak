<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/order_status.php';

$pageTitle = 'Orders Monitoring';
$activePage = 'orders';

$pdo = getDatabaseConnection();

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');

$query = "SELECT o.id, o.reference_id, o.status AS order_status, o.created_at, o.updated_at,
                 o.fulfillment_type, o.delivery_fee, o.delivery_address, o.delivery_landmark, o.delivery_notes,
                 o.discount_type, o.discount_amount, o.discount_id_number, o.discount_name,
                 o.voucher_code, o.voucher_discount,
                 o.subtotal, o.vatable_sales, o.vat_amount, o.vat_exempt_sales, o.total_amount,
                 o.notes, o.payment_method AS order_payment_method, o.cash_amount,
                 o.collected_amount, o.collected_at, o.proof_captured_at, o.proof_note,
                 u.first_name, u.last_name, u.email, u.phone,
                 r.first_name AS rider_first, r.last_name AS rider_last, r.phone AS rider_phone,
                 p.amount, p.payment_method, p.payment_channel, p.status AS payment_status,
                 p.reference_id AS payment_reference, p.created_at AS payment_created_at
          FROM orders o
          JOIN users u ON u.id = o.customer_id
          LEFT JOIN users r ON r.id = o.rider_id
          LEFT JOIN payments p ON p.order_id = o.id
          WHERE 1=1";

$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'processing', 'completed', 'cancelled'], true)) {
    $query .= " AND o.status = :status";
    $params['status'] = $statusFilter;
}

if ($search !== '') {
    // Native prepared statements (EMULATE_PREPARES = off) reject a repeated
    // named placeholder, so every LIKE gets its own bind key.
    $query .= " AND (o.reference_id LIKE :search_ref OR u.first_name LIKE :search_first
                 OR u.last_name LIKE :search_last OR u.email LIKE :search_email)";
    $like = '%' . $search . '%';
    $params['search_ref']   = $like;
    $params['search_first'] = $like;
    $params['search_last']  = $like;
    $params['search_email'] = $like;
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

// Status history (audit trail) shown in the detail modal. Never break the list
// page if the audit table is unavailable on this install.
$historyMap = [];
if (!empty($orders)) {
    try {
        $historyMap = orderStatusHistoryMap($pdo, $orders);
    } catch (Throwable $e) {
        $historyMap = [];
    }
}

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
                        <th style="min-width: 220px;">Purchased Items</th>
                        <th style="min-width: 130px;">Total Amount</th>
                        <th style="min-width: 130px;">Order Status</th>
                        <th style="min-width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o):
                        $oid = (int) $o['id'];
                        $items = $orderItemsMap[$oid] ?? [];
                        $ordStatus = strtolower((string) $o['order_status']);
                        $rowPayLabel = orderPaymentLabel($o['order_payment_method'] ?? null, $o['payment_channel'] ?? null, $o['fulfillment_type'] ?? 'delivery');
                        $rowIsCash = orderIsCashPayment($o['order_payment_method'] ?? null, $o['payment_channel'] ?? null);
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
                            <?php if (!empty($o['voucher_code'])): ?>
                                <small style="display: inline-block; font-size: 10px; font-weight: 700; color: #1a6645; background: #e8f7ef; padding: 1px 7px; border-radius: 100px; margin-top: 2px;">
                                    <i class="fa-solid fa-ticket" style="font-size: 9px;"></i> <?php echo htmlspecialchars($o['voucher_code']); ?>
                                </small>
                            <?php endif; ?>
                            <span class="order-pay-channel <?php echo $rowIsCash ? 'is-cash' : ''; ?>">
                                <i class="fa-solid <?php echo $rowIsCash ? 'fa-money-bill-wave' : 'fa-mobile-screen'; ?>"></i> <?php echo htmlspecialchars($rowPayLabel); ?>
                            </span>
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
                        <td>
                            <button class="admin-button secondary order-view-btn" type="button"
                                    data-open-modal="order-detail-<?php echo $oid; ?>"
                                    aria-haspopup="dialog" title="View full order details">
                                <i class="fa-solid fa-eye"></i> View Details
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($orders)): ?>
<?php foreach ($orders as $o):
    $oid = (int) $o['id'];
    $dItems = $orderItemsMap[$oid] ?? [];
    $trail = $historyMap[$oid] ?? [];
    $ordStatus = strtolower((string) $o['order_status']);
    $fulfillment = $o['fulfillment_type'] ?? 'delivery';
    $isPickup = $fulfillment === 'pickup';
    $chip = orderStatusChip($ordStatus, $fulfillment);
    $payStatus = strtolower((string) ($o['payment_status'] ?? 'pending'));
    $payLabel = orderPaymentLabel($o['order_payment_method'] ?? null, $o['payment_channel'] ?? null, $fulfillment);
    $isCash = orderIsCashPayment($o['order_payment_method'] ?? null, $o['payment_channel'] ?? null);
    $customerName = trim($o['first_name'] . ' ' . $o['last_name']);
    $riderName = trim(($o['rider_first'] ?? '') . ' ' . ($o['rider_last'] ?? ''));
    $orderTotal = (float) ($o['total_amount'] ?? 0);
    $proofUrl = BASE_URL . '/api/delivery-proof.php?order=' . $oid;
?>
<div class="admin-modal admin-order-modal" id="order-detail-<?php echo $oid; ?>" role="dialog" aria-modal="true" aria-labelledby="order-detail-title-<?php echo $oid; ?>">
    <div class="modal-heading">
        <div>
            <span class="modal-kicker">Order Details</span>
            <h2 id="order-detail-title-<?php echo $oid; ?>">#<?php echo htmlspecialchars($o['reference_id']); ?></h2>
            <p class="order-detail-sub">
                <i class="fa-regular fa-calendar"></i> <?php echo date('M d, Y · g:i A', strtotime($o['created_at'])); ?>
                &middot; Last updated <?php echo date('M d, Y g:i A', strtotime($o['updated_at'])); ?>
            </p>
        </div>
        <button class="modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
    </div>

    <div class="order-detail-statusbar">
        <span class="status-chip <?php echo $chip[0]; ?>"><i class="fa-solid fa-<?php echo $chip[1]; ?>"></i> <?php echo $chip[2]; ?></span>
        <?php if ($payStatus === 'paid'): ?>
            <span class="status-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
        <?php elseif (in_array($payStatus, ['failed', 'expired', 'voided'], true)): ?>
            <span class="status-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($payStatus); ?></span>
        <?php else: ?>
            <span class="status-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
        <?php endif; ?>
        <span class="order-detail-tag"><i class="fa-solid <?php echo $isPickup ? 'fa-store' : 'fa-truck'; ?>"></i> <?php echo $isPickup ? 'Store Pickup' : 'Delivery'; ?></span>
        <span class="order-detail-tag"><i class="fa-solid fa-mobile-screen"></i> <?php echo htmlspecialchars($payLabel); ?></span>
    </div>

    <div class="order-detail-grid">
        <section class="order-detail-card">
            <h4 class="order-detail-card-title"><i class="fa-solid fa-user"></i> Customer</h4>
            <dl class="order-detail-fields">
                <div><dt>Name</dt><dd><?php echo htmlspecialchars($customerName); ?></dd></div>
                <div><dt>Email</dt><dd><?php echo htmlspecialchars($o['email']); ?></dd></div>
                <div><dt>Phone</dt><dd><a href="tel:<?php echo htmlspecialchars($o['phone']); ?>"><?php echo htmlspecialchars($o['phone']); ?></a></dd></div>
                <?php if (!empty($o['discount_type']) && $o['discount_type'] !== 'none' && (float) $o['discount_amount'] > 0): ?>
                    <div>
                        <dt>Discount claimed</dt>
                        <dd>
                            <?php echo $o['discount_type'] === 'senior' ? '🧓 Senior Citizen 20%' : '♿ PWD 20%'; ?>
                            <?php if (!empty($o['discount_id_number'])): ?>
                                <small class="order-detail-note">ID <?php echo htmlspecialchars($o['discount_id_number']); ?><?php echo !empty($o['discount_name']) ? ' · ' . htmlspecialchars($o['discount_name']) : ''; ?></small>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="order-detail-card">
            <h4 class="order-detail-card-title"><i class="fa-solid <?php echo $isPickup ? 'fa-store' : 'fa-truck'; ?>"></i> <?php echo $isPickup ? 'Pickup' : 'Delivery'; ?></h4>
            <dl class="order-detail-fields">
                <?php if ($isPickup): ?>
                    <div><dt>Handover</dt><dd>Customer picks up at BreadBreak Bakery</dd></div>
                <?php else: ?>
                    <?php if (!empty($o['delivery_address'])): ?>
                        <div class="is-wide">
                            <dt>Address</dt>
                            <dd>
                                <?php echo nl2br(htmlspecialchars($o['delivery_address'])); ?>
                                <a class="order-detail-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo urlencode((string) $o['delivery_address']); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-map"></i> Open in Maps</a>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($o['delivery_landmark'])): ?>
                        <div><dt>Landmark</dt><dd><?php echo htmlspecialchars($o['delivery_landmark']); ?></dd></div>
                    <?php endif; ?>
                    <div>
                        <dt>Rider</dt>
                        <dd>
                            <?php if ($riderName !== ''): ?>
                                <?php echo htmlspecialchars($riderName); ?>
                                <?php if (!empty($o['rider_phone'])): ?>
                                    <a class="order-detail-link" href="tel:<?php echo htmlspecialchars($o['rider_phone']); ?>"><i class="fa-solid fa-phone"></i> Call</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="order-detail-muted">Not assigned yet</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($o['delivery_notes'])): ?>
                    <div class="is-wide"><dt>Delivery instructions</dt><dd><?php echo nl2br(htmlspecialchars($o['delivery_notes'])); ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($o['notes'])): ?>
                    <div class="is-wide"><dt>Order notes</dt><dd><?php echo nl2br(htmlspecialchars($o['notes'])); ?></dd></div>
                <?php endif; ?>
            </dl>
        </section>
    </div>

    <section class="order-detail-card">
        <h4 class="order-detail-card-title"><i class="fa-solid fa-basket-shopping"></i> Items <span class="order-detail-count"><?php echo count($dItems); ?> line<?php echo count($dItems) === 1 ? '' : 's'; ?></span></h4>
        <div class="table-wrap" style="overflow-x: auto; border-radius: 10px; border: 1px solid var(--admin-line);">
            <table class="orders-table order-detail-items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Service Size</th>
                        <th>SKU</th>
                        <th style="text-align: right;">Unit Price</th>
                        <th style="text-align: center;">Qty</th>
                        <th style="text-align: right;">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dItems as $it): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($it['product_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($it['service_size']); ?></td>
                            <td class="order-detail-mono"><?php echo htmlspecialchars($it['sku']); ?></td>
                            <td style="text-align: right;">₱<?php echo number_format((float) $it['unit_price'], 2); ?></td>
                            <td style="text-align: center;"><?php echo (int) $it['quantity']; ?></td>
                            <td style="text-align: right;"><strong>₱<?php echo number_format((float) $it['line_total'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$dItems): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--admin-muted);">No line items recorded for this order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="order-detail-totals">
            <div><span>Subtotal</span><strong>₱<?php echo number_format((float) $o['subtotal'], 2); ?></strong></div>
            <?php if ((float) $o['vat_amount'] > 0): ?>
                <div><span>VAT (12%)</span><strong>₱<?php echo number_format((float) $o['vat_amount'], 2); ?></strong></div>
            <?php endif; ?>
            <?php if ((float) $o['vat_exempt_sales'] > 0): ?>
                <div><span>VAT-exempt sales</span><strong>₱<?php echo number_format((float) $o['vat_exempt_sales'], 2); ?></strong></div>
            <?php endif; ?>
            <?php if ((float) $o['discount_amount'] > 0): ?>
                <div class="is-discount"><span><?php echo $o['discount_type'] === 'pwd' ? 'PWD' : 'Senior'; ?> discount</span><strong>−₱<?php echo number_format((float) $o['discount_amount'], 2); ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($o['voucher_code'])): ?>
                <div class="is-discount">
                    <span><i class="fa-solid fa-ticket"></i> Voucher · <?php echo htmlspecialchars($o['voucher_code']); ?></span>
                    <strong><?php echo (float) ($o['voucher_discount'] ?? 0) > 0 ? '−₱' . number_format((float) $o['voucher_discount'], 2) : 'Free Delivery'; ?></strong>
                </div>
            <?php endif; ?>
            <?php if ((float) $o['delivery_fee'] > 0): ?>
                <div><span>Delivery fee</span><strong>₱<?php echo number_format((float) $o['delivery_fee'], 2); ?></strong></div>
            <?php endif; ?>
            <div class="is-total"><span>Total</span><strong>₱<?php echo number_format($orderTotal, 2); ?></strong></div>
        </div>
    </section>

    <div class="order-detail-grid">
        <section class="order-detail-card">
            <h4 class="order-detail-card-title"><i class="fa-solid fa-receipt"></i> Payment</h4>
            <dl class="order-detail-fields">
                <div>
                    <dt>Method</dt>
                    <dd><span class="order-detail-tag <?php echo $isCash ? 'is-cash' : 'is-online'; ?>"><i class="fa-solid <?php echo $isCash ? 'fa-money-bill-wave' : 'fa-mobile-screen'; ?>"></i> <?php echo htmlspecialchars($payLabel); ?></span></dd>
                </div>
                <div>
                    <dt>Payment status</dt>
                    <dd>
                        <?php if ($payStatus === 'paid'): ?>
                            <span class="status-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
                        <?php elseif (in_array($payStatus, ['failed', 'expired', 'voided'], true)): ?>
                            <span class="status-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($payStatus); ?></span>
                        <?php else: ?>
                            <span class="status-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!empty($o['amount'])): ?>
                    <div><dt>Amount paid</dt><dd>₱<?php echo number_format((float) $o['amount'], 2); ?><?php echo !empty($o['payment_created_at']) ? ' <small class="order-detail-note">' . date('M d, Y g:i A', strtotime($o['payment_created_at'])) . '</small>' : ''; ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($o['payment_channel'])): ?>
                    <div><dt>Channel</dt><dd><?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($o['payment_channel']))); ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($o['payment_reference'])): ?>
                    <div class="is-wide"><dt>Payment reference</dt><dd class="order-detail-mono"><?php echo htmlspecialchars($o['payment_reference']); ?></dd></div>
                <?php endif; ?>
                <?php if ($isCash && (float) ($o['cash_amount'] ?? 0) > 0): ?>
                    <div><dt>Customer will hand over</dt><dd>₱<?php echo number_format((float) $o['cash_amount'], 2); ?></dd></div>
                    <div><dt>Change to return</dt><dd>₱<?php echo number_format(max(0, (float) $o['cash_amount'] - $orderTotal), 2); ?></dd></div>
                <?php endif; ?>
                <?php if ($o['collected_amount'] !== null): ?>
                    <div><dt>Cash collected</dt><dd>₱<?php echo number_format((float) $o['collected_amount'], 2); ?><?php echo $o['collected_at'] ? ' <small class="order-detail-note">' . date('M d, g:i A', strtotime($o['collected_at'])) . '</small>' : ''; ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($o['proof_captured_at'])): ?>
                    <div>
                        <dt>Delivery proof</dt>
                        <dd>
                            <a class="order-detail-link" href="<?php echo htmlspecialchars($proofUrl); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-image"></i> View photo</a>
                            <small class="order-detail-note"><?php echo date('M d, Y g:i A', strtotime($o['proof_captured_at'])); ?></small>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($o['proof_note'])): ?>
                    <div class="is-wide"><dt>Handover note</dt><dd><?php echo htmlspecialchars($o['proof_note']); ?></dd></div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="order-detail-card">
            <h4 class="order-detail-card-title"><i class="fa-solid fa-timeline"></i> Activity Trail</h4>
            <ol class="order-detail-trail">
                <?php foreach (array_reverse($trail) as $entry): ?>
                    <li>
                        <span class="order-detail-trail-dot"><i class="fa-solid fa-<?php echo htmlspecialchars(orderHistoryIcon((string) $entry['to_status'], $entry['from_status'])); ?>"></i></span>
                        <div>
                            <strong><?php echo htmlspecialchars(orderHistoryLabel((string) $entry['to_status'], $entry['from_status'], $fulfillment)); ?></strong>
                            <?php if (!empty($entry['note'])): ?>
                                <p><?php echo htmlspecialchars($entry['note']); ?></p>
                            <?php endif; ?>
                            <small>
                                <?php echo htmlspecialchars($entry['actor_name'] ?: 'System'); ?>
                                <em><?php echo htmlspecialchars(orderActorRoleLabel($entry['actor_role'])); ?></em>
                                · <?php echo date('M d, Y g:i A', strtotime($entry['created_at'])); ?>
                            </small>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$trail): ?>
                    <li><span class="order-detail-trail-dot"><i class="fa-solid fa-receipt"></i></span><div><strong>No activity recorded yet.</strong></div></li>
                <?php endif; ?>
            </ol>
        </section>
    </div>

    <div class="modal-actions">
        <button class="admin-button secondary" type="button" data-close-modal>Close</button>
    </div>
</div>
<?php endforeach; ?>
<div class="modal-backdrop" data-modal-backdrop></div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
