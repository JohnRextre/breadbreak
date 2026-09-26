<?php
/**
 * Staff Order History — every delivered or cancelled order.
 *
 * staff/orders.php only shows what still needs work, so this is the archive:
 * searchable and filterable by status, payment method, rider and date range.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/order_status.php';
require_once __DIR__ . '/../includes/rider.php';
require_once __DIR__ . '/../includes/staff_orders.php';

$pageTitle = 'Order History';
$activePage = 'order-history';

$pdo = getDatabaseConnection();
ensureOrderStatusEnum($pdo);
ensureRiderSupport($pdo);
ensureOrderStatusHistory($pdo);

$riders = activeRiders($pdo);
$closed = closedOrderStatuses();

// ─────────────────────────────────────────────────────────────────────────────
// Filters
// ─────────────────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$paymentFilter = trim($_GET['payment'] ?? '');
$riderFilter = trim($_GET['rider'] ?? '');
$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? '');

$statusScope = in_array($statusFilter, closedOrderStatuses(), true)
    ? [$statusFilter]
    : $closed;

[$where, $params] = orderFilterClause($statusScope, $search, $paymentFilter, $riderFilter, $fromDate, $toDate);

$sortMap = [
    'newest' => 'o.created_at DESC, o.id DESC',
    'oldest' => 'o.created_at ASC, o.id ASC',
    'total_desc' => 'o.total_amount DESC',
    'total_asc' => 'o.total_amount ASC',
    'delivered_first' => "FIELD(o.status, 'completed', 'cancelled'), o.created_at DESC",
];
$sort = array_key_exists($_GET['sort'] ?? '', $sortMap) ? $_GET['sort'] : 'newest';

$query = orderSelectSql($where)
    . " GROUP BY o.id, p.id"
    . " ORDER BY " . $sortMap[$sort];

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$orderIds = array_column($orders, 'id');
$itemsMap = [];
if ($orderIds) {
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare(
        "SELECT order_id, product_name, service_size, sku, unit_price, quantity, line_total
         FROM order_items WHERE order_id IN ($in) ORDER BY id ASC"
    );
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsMap[(int) $item['order_id']][] = $item;
    }
}

$historyMap = orderStatusHistoryMap($pdo, $orders);

// Totals across the whole archive, not just the filtered page.
$archiveStmt = $pdo->query(
    "SELECT
        SUM(status = 'completed') AS delivered,
        SUM(status = 'cancelled') AS cancelled,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount END), 0) AS revenue
     FROM orders WHERE status IN ('completed','cancelled')"
);
$archive = $archiveStmt ? $archiveStmt->fetch(PDO::FETCH_ASSOC) : [];
$deliveredCount = (int) ($archive['delivered'] ?? 0);
$cancelledCount = (int) ($archive['cancelled'] ?? 0);
$archiveRevenue = (float) ($archive['revenue'] ?? 0);
$inProcessTotal = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing','out_for_delivery','ready_for_pickup')"
)->fetchColumn();

$hasFilters = $search !== '' || $paymentFilter !== '' || $riderFilter !== ''
    || $fromDate !== '' || $toDate !== '' || $statusFilter !== 'all';

$cancelReasons = orderCancelReasons();
$payOptions = orderPaymentOptions($pdo);

$presets = [
    '' => 'Any time',
    '7' => 'Last 7 days',
    '30' => 'Last 30 days',
    '90' => 'Last 90 days',
];

require __DIR__ . '/../includes/staff_header.php';
?>

<section class="page-intro users-page-intro inventory-page-intro">
    <div>
        <span class="eyebrow">Archive</span>
        <h2>Order History</h2>
        <p>Every delivered and cancelled order. Live work stays on the Orders page.</p>
    </div>
    <a class="admin-button secondary" href="<?php echo BASE_URL; ?>/staff/orders.php" style="height:44px; padding:0 20px; border-radius:999px; font-weight:800; display:inline-flex; align-items:center; gap:7px;">
        <i class="fa-solid fa-receipt"></i> Open orders (<?php echo $inProcessTotal; ?>)
    </a>
</section>

<section class="orders-summary-grid">
    <article class="summary-card">
        <div class="summary-top">
            <span>Delivered</span>
            <div class="summary-icon" style="background: rgba(31,157,99,.1); color: #1a6645;"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="summary-value" style="color: #1a6645;"><?php echo $deliveredCount; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Cancelled</span>
            <div class="summary-icon" style="background: rgba(163,79,67,.1); color: #a34f43;"><i class="fa-solid fa-ban"></i></div>
        </div>
        <div class="summary-value" style="color: #a34f43;"><?php echo $cancelledCount; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Delivered revenue</span>
            <div class="summary-icon" style="background: rgba(123,82,59,.1); color: var(--admin-brown);"><i class="fa-solid fa-sack-dollar"></i></div>
        </div>
        <div class="summary-value" style="font-size:24px; color: var(--admin-brown);">₱<?php echo number_format($archiveRevenue, 2); ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Showing</span>
            <div class="summary-icon" style="background: rgba(32,82,168,.1); color: #2052a8;"><i class="fa-solid fa-filter"></i></div>
        </div>
        <div class="summary-value" style="color: #2052a8;"><?php echo count($orders); ?></div>
    </article>
</section>

<section class="panel">
    <form method="GET" class="orders-filter-bar history-filter-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="search" placeholder="Search order #, customer, phone, item or SKU..." value="<?php echo htmlspecialchars($search); ?>" />
        </div>

        <div class="history-filter-grid">
            <label class="select-control">
                <span>Status</span>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All closed</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </label>

            <label class="select-control">
                <span>Payment</span>
                <select name="payment" onchange="this.form.submit()">
                    <option value="">Any payment</option>
                    <option value="online" <?php echo $paymentFilter === 'online' ? 'selected' : ''; ?>>Online / e-wallet</option>
                    <option value="cash" <?php echo $paymentFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <?php foreach ($payOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $paymentFilter === $option ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(orderPaymentLabelShort($option)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="select-control">
                <span>Rider</span>
                <select name="rider" onchange="this.form.submit()">
                    <option value="">Any rider</option>
                    <?php foreach ($riders as $rider): ?>
                        <option value="<?php echo (int) $rider['id']; ?>" <?php echo $riderFilter === (string) $rider['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(riderDisplayName($rider)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="select-control">
                <span>Placed</span>
                <select name="range" id="history-range" onchange="applyRange(this.value)">
                    <?php foreach ($presets as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>" data-preset="<?php echo htmlspecialchars($value); ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="select-control">
                <span>From</span>
                <input type="date" name="from" value="<?php echo htmlspecialchars($fromDate); ?>" />
            </label>

            <label class="select-control">
                <span>To</span>
                <input type="date" name="to" value="<?php echo htmlspecialchars($toDate); ?>" />
            </label>
        </div>

        <input type="hidden" name="sort" id="history-sort" value="<?php echo htmlspecialchars($sort); ?>" />

        <div class="filter-actions">
            <div class="select-control" style="margin:0;">
                <select name="sort-select" onchange="document.getElementById('history-sort').value = this.value; this.form.submit();" aria-label="Sort">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                    <option value="delivered_first" <?php echo $sort === 'delivered_first' ? 'selected' : ''; ?>>Delivered before cancelled</option>
                    <option value="total_desc" <?php echo $sort === 'total_desc' ? 'selected' : ''; ?>>Highest total</option>
                    <option value="total_asc" <?php echo $sort === 'total_asc' ? 'selected' : ''; ?>>Lowest total</option>
                </select>
            </div>
            <button type="submit" class="admin-button primary" style="height:42px; border-radius:8px; padding:0 18px;">
                <i class="fa-solid fa-filter"></i> Apply
            </button>
            <?php if ($hasFilters): ?>
                <a href="order-history.php" class="admin-button secondary" style="height:42px; border-radius:8px; padding:0 16px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($orders)): ?>
        <div class="empty-state" style="text-align:center; padding:60px 20px;">
            <div style="width:64px; height:64px; margin:0 auto 16px; border-radius:50%; background:#faf7f4; display:flex; align-items:center; justify-content:center; font-size:26px; color:var(--admin-muted);">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <h3 style="color:var(--admin-brown-dark); margin:0 0 6px; font-size:16px;">
                <?php echo $hasFilters ? 'Nothing matches those filters' : 'No closed orders yet'; ?>
            </h3>
            <p style="color:var(--admin-muted); font-size:13px; margin:0;">
                <?php echo $hasFilters
                    ? 'Try widening the date range or clearing the filters.'
                    : 'Delivered and cancelled orders will be archived here automatically.'; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="overflow-x:auto; border-radius:10px; border:1px solid var(--admin-line);">
            <table class="orders-table orders-table-slim">
                <colgroup>
                    <col style="width:18%;" /><col style="width:15%;" /><col style="width:14%;" />
                    <col style="width:12%;" /><col style="width:24%;" /><col style="width:17%;" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Order</th><th>Customer</th><th>Contents</th>
                        <th>Payment</th><th>Status &amp; Rider</th><th class="is-action">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o):
                        $oid = (int) $o['id'];
                        $items = $itemsMap[$oid] ?? [];
                        $trail = $historyMap[$oid] ?? [];
                        $lastEntry = $trail ? end($trail) : null;
                        $ordStatus = strtolower((string) $o['order_status']);
                        $fulfillment = $o['fulfillment_type'] ?? 'delivery';
                        $payStatus = strtolower((string) ($o['payment_status'] ?? 'pending'));
                        $channel = (string) ($o['payment_channel'] ?? '');
                        $isCash = orderIsCashPayment($o['payment_method'] ?? '', $channel);
                        $chip = orderStatusChip($ordStatus, $fulfillment);
                        $itemCount = count($items);
                        $unitCount = array_sum(array_map(fn ($i) => (int) $i['quantity'], $items));
                        $riderName = !empty($o['rider_id']) ? trim(($o['rider_first'] ?? '') . ' ' . ($o['rider_last'] ?? '')) : '';
                    ?>
                    <tr class="is-archived<?php echo $ordStatus === 'cancelled' ? ' is-cancelled-row' : ''; ?>">
                        <td>
                            <span class="order-ref-badge">
                                <i class="fa-solid fa-receipt"></i>#<?php echo htmlspecialchars($o['reference_id']); ?>
                            </span>
                            <span class="order-date-text"><?php echo date('M j, Y · g:i A', strtotime($o['created_at'])); ?></span>
                            <div class="order-tag-row">
                                <?php if ($fulfillment === 'pickup'): ?>
                                    <span class="order-mini-tag"><i class="fa-solid fa-store"></i> Pickup</span>
                                <?php endif; ?>
                                <?php if (!empty($o['discount_type']) && $o['discount_type'] !== 'none'): ?>
                                    <span class="order-mini-tag is-discount">
                                        <?php echo $o['discount_type'] === 'senior' ? 'Senior 20%' : 'PWD 20%'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($o['has_proof'])): ?>
                                    <span class="order-mini-tag is-proof"><i class="fa-solid fa-camera"></i> Proof</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="customer-info-cell">
                            <strong><?php echo htmlspecialchars(trim($o['first_name'] . ' ' . $o['last_name'])); ?></strong>
                            <small><?php echo htmlspecialchars($o['phone']); ?></small>
                        </td>
                        <td>
                            <div class="order-price-val">₱<?php echo number_format((float) $o['total_amount'], 2); ?></div>
                            <?php if ($itemCount): ?>
                                <span class="order-contents-line" title="<?php echo htmlspecialchars(implode(', ', array_map(
                                    fn ($i) => $i['product_name'] . ' (' . $i['service_size'] . ' ×' . (int) $i['quantity'] . ')', $items
                                ))); ?>">
                                    <?php echo $itemCount; ?> <?php echo $itemCount === 1 ? 'item' : 'items'; ?> · <?php echo $unitCount; ?> <?php echo $unitCount === 1 ? 'pc' : 'pcs'; ?>
                                </span>
                            <?php else: ?>
                                <span class="order-contents-line is-muted">No items</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($payStatus === 'paid'): ?>
                                <span class="status-chip is-paid"><i class="fa-solid fa-circle-check"></i> Paid</span>
                            <?php elseif (in_array($payStatus, ['failed', 'expired', 'voided'], true)): ?>
                                <span class="status-chip is-failed"><i class="fa-solid fa-circle-xmark"></i> <?php echo ucfirst($payStatus); ?></span>
                            <?php else: ?>
                                <span class="status-chip is-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                            <span class="order-pay-channel <?php echo $isCash ? 'is-cash' : 'is-online'; ?>">
                                <i class="fa-solid <?php echo $isCash ? 'fa-money-bill-wave' : ($channel !== '' ? strtolower('fa-' . $channel) : 'fa-mobile-screen'); ?>"></i>
                                <?php echo htmlspecialchars(orderPaymentLabelShort($o['payment_method'] ?? '', $channel)); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-chip <?php echo $chip[0]; ?>"><i class="fa-solid fa-<?php echo $chip[1]; ?>"></i> <?php echo $chip[2]; ?></span>
                            <?php if ($riderName !== ''): ?>
                                <span class="order-trail-line">
                                    <em><i class="fa-solid fa-motorcycle"></i> <?php echo htmlspecialchars($riderName); ?></em>
                                    <?php if ($lastEntry): ?>
                                        <em><?php echo htmlspecialchars(orderHistoryRelativeTime($lastEntry['created_at'])); ?></em>
                                    <?php endif; ?>
                                </span>
                            <?php elseif ($lastEntry): ?>
                                <span class="order-trail-line">
                                    <?php echo htmlspecialchars(orderHistoryLabelShort((string) $lastEntry['to_status'], $lastEntry['from_status'], $fulfillment)); ?>
                                    <em><?php echo htmlspecialchars($lastEntry['actor_name'] ?: 'System'); ?></em>
                                    <em><?php echo htmlspecialchars(orderHistoryRelativeTime($lastEntry['created_at'])); ?></em>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="is-action">
                            <div class="order-next-cell">
                                <span class="order-action-idle"><?php echo date('M j', strtotime($o['created_at'])); ?></span>
                                <button class="order-view-btn" type="button" data-order-drawer="order-drawer-<?php echo $oid; ?>"
                                        aria-controls="order-drawer-<?php echo $oid; ?>" title="View full order details">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="orders-result-count">
            Showing <?php echo count($orders); ?> of <?php echo $deliveredCount + $cancelledCount; ?> closed
            <?php echo $hasFilters ? '(filtered)' : ''; ?>
        </p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/staff_order_drawer.php'; ?>
<?php require __DIR__ . '/../includes/staff_order_drawer_script.php'; ?>
<script>
/* Date-range presets write into the real from/to inputs so the form stays a plain GET. */
function applyRange(value) {
    var form = document.querySelector('.history-filter-bar');
    if (!form) return;
    var from = form.querySelector('input[name="from"]');
    var to = form.querySelector('input[name="to"]');
    if (!from || !to) return;
    if (value === '') { from.value = ''; to.value = ''; }
    else {
        var days = parseInt(value, 10) || 30;
        var start = new Date();
        start.setDate(start.getDate() - days);
        to.value = '';
        from.value = start.toISOString().slice(0, 10);
    }
    form.submit();
}

/* Reflect an existing from/to back into the preset dropdown. */
(function () {
    var select = document.getElementById('history-range');
    if (!select) return;
    var from = document.querySelector('input[name="from"]');
    if (from && from.value) select.value = '';
})();
</script>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
