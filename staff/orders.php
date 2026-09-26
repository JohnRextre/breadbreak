<?php
/**
 * Staff Orders — in-process only.
 *
 * Orders that still need attention: waiting for payment/acceptance, baking, on the
 * way, or waiting for pickup. Anything delivered or cancelled lives in
 * staff/order-history.php.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/order_status.php';
require_once __DIR__ . '/../includes/rider.php';
require_once __DIR__ . '/../includes/staff_orders.php';

$pageTitle = 'Orders';
$activePage = 'orders';

$pdo = getDatabaseConnection();
ensureOrderStatusEnum($pdo);
ensureRiderSupport($pdo);
ensureOrderStatusHistory($pdo);

$riders = activeRiders($pdo);
$actor = orderActor();
$inProcess = inProcessOrderStatuses();

// ─────────────────────────────────────────────────────────────────────────────
// Actions
// ─────────────────────────────────────────────────────────────────────────────
$statusSuccess = '';
$statusError = '';
$cancelReasons = orderCancelReasons();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $orderId = (int) ($_POST['order_id'] ?? 0);

    $lookup = $pdo->prepare(
        'SELECT id, reference_id, status, fulfillment_type, rider_id, payment_method, customer_id
         FROM orders WHERE id = :id LIMIT 1'
    );
    $lookup->execute(['id' => $orderId]);
    $order = $lookup->fetch();

    if (!$order) {
        $statusError = 'Order was not found.';
    } else {
        $fulfillment = $order['fulfillment_type'] ?? 'delivery';
        $ref = $order['reference_id'];

        if ($action === 'accept_order') {
            $result = applyOrderStatus(
                $pdo,
                $orderId,
                'processing',
                $actor,
                'Cash order accepted by the bakery — stock committed.'
            );

            if (!$result['ok']) {
                $statusError = $result['error'];
            } else {
                $pdo->beginTransaction();
                try {
                    deductOrderStock($pdo, $orderId);
                    $pdo->commit();
                } catch (Throwable) {
                    $pdo->rollBack();
                }
                $statusSuccess = 'Order #' . $ref . ' accepted and moved to Baking.';
            }

        } elseif ($action === 'assign_rider') {
            $riderId = (int) ($_POST['rider_id'] ?? 0);
            $riderName = '';
            foreach ($riders as $candidate) {
                if ((int) $candidate['id'] === $riderId) {
                    $riderName = riderDisplayName($candidate);
                    break;
                }
            }

            if ($fulfillment === 'pickup') {
                $statusError = 'Store pickup orders do not need a rider.';
            } elseif ($riderId <= 0 || $riderName === '') {
                $statusError = 'Choose a rider to assign.';
            } else {
                $previousRider = (int) $order['rider_id'];
                $pdo->prepare('UPDATE orders SET rider_id = :rid, updated_at = NOW() WHERE id = :id')
                    ->execute(['rid' => $riderId, 'id' => $orderId]);

                if ($order['status'] === 'processing') {
                    // Assigning a rider is the trigger: no separate "On the Way" step.
                    $result = applyOrderStatus(
                        $pdo,
                        $orderId,
                        'out_for_delivery',
                        $actor,
                        $riderName . ' assigned — the order is now on the way.',
                        ['rider_id' => $riderId]
                    );
                    $statusSuccess = $result['ok']
                        ? $riderName . ' assigned to order #' . $ref . ' — now on the way.'
                        : 'Rider assigned to order #' . $ref . '.';
                    if (!$result['ok']) {
                        $statusError = $result['error'];
                    }
                } else {
                    logOrderStatusChange(
                        $pdo,
                        $orderId,
                        $order['status'],
                        $order['status'],
                        $actor,
                        $previousRider > 0
                            ? 'Rider changed to ' . $riderName . '.'
                            : $riderName . ' assigned.'
                    );
                    $statusSuccess = $riderName . ' assigned to order #' . $ref . '.';
                }
            }

        } elseif ($action === 'send_on_the_way') {
            $result = applyOrderStatus(
                $pdo,
                $orderId,
                'out_for_delivery',
                $actor,
                'Marked on the way by staff.',
                ['rider_id' => (int) $order['rider_id']]
            );
            if ($result['ok']) {
                $statusSuccess = 'Order #' . $ref . ' is now on the way.';
            } else {
                $statusError = $result['error'];
            }

        } elseif ($action === 'cancel_order') {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $result = applyOrderStatus($pdo, $orderId, 'cancelled', $actor, $reason);

            if ($result['ok']) {
                $statusSuccess = 'Order #' . $ref . ' cancelled — ' . $reason . '.';
            } else {
                $statusError = $result['error'];
            }

        } elseif ($action === 'update_status' || $action === 'assign_rider_legacy') {
            $statusError = 'Orders no longer use a manual status picker. Use Accept, Assign Rider, or Cancel.';
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// List — in-process only
// ─────────────────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$paymentFilter = trim($_GET['payment'] ?? '');
$riderFilter = trim($_GET['rider'] ?? '');

[$where, $params] = orderFilterClause($inProcess, $search, $paymentFilter, $riderFilter, '', '');

$sortMap = [
    'newest' => 'o.created_at DESC',
    'oldest' => 'o.created_at ASC',
    'total_desc' => 'o.total_amount DESC',
    'total_asc' => 'o.total_amount ASC',
];
$sort = array_key_exists($_GET['sort'] ?? '', $sortMap) ? $_GET['sort'] : 'newest';

$query = orderSelectSql($where)
    . " GROUP BY o.id, p.id"
    . " ORDER BY FIELD(o.status, 'pending', 'processing', 'out_for_delivery', 'ready_for_pickup'), "
    . $sortMap[$sort];

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

// Tiles describe the whole in-process pipeline, not just the filtered page.
$countOf = static function (string $status) use ($pdo): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE status = :s');
    $s->execute(['s' => $status]);
    return (int) $s->fetchColumn();
};
$awaiting = $countOf('pending');
$baking = $countOf('processing');
$onTheWay = $countOf('out_for_delivery');
$readyForPickup = $countOf('ready_for_pickup');
$inProcessTotal = $awaiting + $baking + $onTheWay + $readyForPickup;
$closedTotal = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('completed','cancelled')")->fetchColumn();

$hasFilters = $search !== '' || $paymentFilter !== '' || $riderFilter !== '';

require __DIR__ . '/../includes/staff_header.php';
?>

<section class="page-intro users-page-intro inventory-page-intro">
    <div>
        <span class="eyebrow">Bakery Fulfillment</span>
        <h2>Orders</h2>
        <p>Orders that still need work. Everything delivered or cancelled moves to Order History.</p>
    </div>
</section>

<section class="orders-summary-grid">
    <a class="summary-card is-link <?php echo $awaiting ? 'is-warn' : ''; ?>" href="<?php echo BASE_URL; ?>/staff/orders.php?status=pending">
        <div class="summary-top">
            <span>Needs action</span>
            <div class="summary-icon" style="background: rgba(201,121,64,.12); color: #c97940;"><i class="fa-solid fa-bell"></i></div>
        </div>
        <div class="summary-value" style="color: #c97940;"><?php echo $awaiting; ?></div>
    </a>
    <div class="summary-card">
        <div class="summary-top">
            <span>In the kitchen</span>
            <div class="summary-icon" style="background: rgba(32,82,168,.1); color: #2052a8;"><i class="fa-solid fa-bread-slice"></i></div>
        </div>
        <div class="summary-value" style="color: #2052a8;"><?php echo $baking; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top">
            <span>On the way</span>
            <div class="summary-icon" style="background: rgba(154,93,10,.1); color: #9a5d0a;"><i class="fa-solid fa-motorcycle"></i></div>
        </div>
        <div class="summary-value" style="color: #9a5d0a;"><?php echo $onTheWay; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top">
            <span>Ready for pickup</span>
            <div class="summary-icon" style="background: rgba(31,157,99,.1); color: #1a6645;"><i class="fa-solid fa-store"></i></div>
        </div>
        <div class="summary-value" style="color: #1a6645;"><?php echo $readyForPickup; ?></div>
    </div>
</section>

<?php if ($statusSuccess): ?>
    <div class="admin-notice success" style="margin-bottom:20px;"><i class="fa-solid fa-circle-check"></i> <?php echo $statusSuccess; ?></div>
<?php endif; ?>
<?php if ($statusError): ?>
    <div class="admin-notice danger" style="margin-bottom:20px;"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $statusError; ?></div>
<?php endif; ?>

<section class="panel">
    <form method="GET" class="orders-filter-bar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="search" placeholder="Search order #, customer, phone, item or SKU..." value="<?php echo htmlspecialchars($search); ?>" />
        </div>
        <div class="filter-actions">
            <div class="select-control" style="margin:0;">
                <select name="sort" onchange="this.form.submit()" aria-label="Sort">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                    <option value="total_desc" <?php echo $sort === 'total_desc' ? 'selected' : ''; ?>>Highest total</option>
                    <option value="total_asc" <?php echo $sort === 'total_asc' ? 'selected' : ''; ?>>Lowest total</option>
                </select>
            </div>
            <div class="select-control" style="margin:0;">
                <select name="payment" onchange="this.form.submit()" aria-label="Payment method">
                    <option value="">All payments</option>
                    <option value="online" <?php echo $paymentFilter === 'online' ? 'selected' : ''; ?>>Online / e-wallet</option>
                    <option value="cash" <?php echo $paymentFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <?php foreach (['GCASH' => 'GCash', 'PAYMAYA' => 'PayMaya', 'GRABPAY' => 'GrabPay', 'SHOPEEPAY' => 'Shopee Pay'] as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $paymentFilter === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="select-control" style="margin:0;">
                <select name="rider" onchange="this.form.submit()" aria-label="Rider">
                    <option value="">All riders</option>
                    <option value="unassigned" <?php echo $riderFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                    <?php foreach ($riders as $rider): ?>
                        <option value="<?php echo (int) $rider['id']; ?>" <?php echo $riderFilter === (string) $rider['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(riderDisplayName($rider)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="admin-button primary" style="height:42px; border-radius:8px; padding:0 18px;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if ($hasFilters): ?>
                <a href="orders.php" class="admin-button secondary" style="height:42px; border-radius:8px; padding:0 16px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/staff/order-history.php" class="admin-button secondary" style="height:42px; border-radius:8px; padding:0 16px;">
                <i class="fa-solid fa-clock-rotate-left"></i> History (<?php echo $closedTotal; ?>)
            </a>
        </div>
    </form>

    <?php if (empty($orders)): ?>
        <div class="empty-state" style="text-align:center; padding:60px 20px;">
            <div style="width:64px; height:64px; margin:0 auto 16px; border-radius:50%; background:#faf7f4; display:flex; align-items:center; justify-content:center; font-size:26px; color:var(--admin-muted);">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>
            <h3 style="color:var(--admin-brown-dark); margin:0 0 6px; font-size:16px;">
                <?php echo $hasFilters ? 'No matching open orders' : 'No open orders'; ?>
            </h3>
            <p style="color:var(--admin-muted); font-size:13px; margin:0 0 1rem;">
                <?php echo $hasFilters
                    ? 'Try clearing the filters.'
                    : 'Every order is delivered or cancelled. Nothing needs your attention right now.'; ?>
            </p>
            <?php if ($hasFilters): ?>
                <a href="orders.php" class="admin-button secondary">Clear filters</a>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/staff/order-history.php" class="admin-button secondary">
                    <i class="fa-solid fa-clock-rotate-left"></i> View Order History
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="overflow-x:auto; border-radius:10px; border:1px solid var(--admin-line);">
            <table class="orders-table orders-table-slim">
                <colgroup>
                    <col style="width:19%;" /><col style="width:15%;" /><col style="width:14%;" />
                    <col style="width:11%;" /><col style="width:22%;" /><col style="width:19%;" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Order</th><th>Customer</th><th>Contents</th>
                        <th>Payment</th><th>Status &amp; Last Activity</th><th class="is-action">Next Step</th>
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
                        $payShort = orderPaymentLabelShort($o['payment_method'] ?? '', $channel);
                        $chip = orderStatusChip($ordStatus, $fulfillment);
                        $action = orderNextAction($o, $riders);
                        $itemCount = count($items);
                        $unitCount = array_sum(array_map(fn ($i) => (int) $i['quantity'], $items));
                    ?>
                    <tr>
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
                            <?php if ((float) ($o['delivery_fee'] ?? 0) > 0): ?>
                                <span class="order-contents-line is-muted">+ ₱<?php echo number_format((float) $o['delivery_fee'], 2); ?> delivery</span>
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
                                <?php echo htmlspecialchars($payShort); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-chip <?php echo $chip[0]; ?>"><i class="fa-solid fa-<?php echo $chip[1]; ?>"></i> <?php echo $chip[2]; ?></span>
                            <?php if ($lastEntry): ?>
                                <span class="order-trail-line" title="<?php echo htmlspecialchars(
                                    orderHistoryLabel((string) $lastEntry['to_status'], $lastEntry['from_status'], $fulfillment)
                                    . ($lastEntry['note'] ? ' — ' . $lastEntry['note'] : '')
                                    . ' · ' . ($lastEntry['actor_name'] ?: 'System')
                                    . ' · ' . date('M d, Y g:i A', strtotime($lastEntry['created_at']))
                                ); ?>">
                                    <?php echo htmlspecialchars(orderHistoryLabelShort((string) $lastEntry['to_status'], $lastEntry['from_status'], $fulfillment)); ?>
                                    <em><?php echo htmlspecialchars($lastEntry['actor_name'] ?: 'System'); ?></em>
                                    <em><?php echo htmlspecialchars(orderHistoryRelativeTime($lastEntry['created_at'])); ?></em>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="is-action">
                            <div class="order-next-cell">
                                <?php if ($action['kind'] === 'accept'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="accept_order" />
                                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                        <button class="order-action-btn is-primary" type="submit">
                                            <i class="fa-solid fa-<?php echo $action['icon']; ?>"></i> <?php echo $action['label']; ?>
                                        </button>
                                    </form>
                                <?php elseif ($action['kind'] === 'rider' && $fulfillment === 'delivery'): ?>
                                    <form method="POST" class="order-rider-form">
                                        <input type="hidden" name="action" value="assign_rider" />
                                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                        <div class="status-select-wrap">
                                            <select name="rider_id" onchange="if (this.value) this.form.submit();">
                                                <option value="0">Assign rider…</option>
                                                <?php foreach ($riders as $rider): ?>
                                                    <option value="<?php echo (int) $rider['id']; ?>"><?php echo htmlspecialchars(riderDisplayName($rider)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </form>
                                <?php elseif ($action['kind'] === 'rider'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="send_on_the_way" />
                                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                        <button class="order-action-btn is-primary" type="submit">
                                            <i class="fa-solid fa-store"></i> Mark ready
                                        </button>
                                    </form>
                                <?php elseif ($action['kind'] === 'ontheway'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="send_on_the_way" />
                                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                        <button class="order-action-btn is-primary" type="submit">
                                            <i class="fa-solid fa-motorcycle"></i> Send on the way
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="order-action-idle" title="<?php echo htmlspecialchars($action['hint']); ?>">
                                        <i class="fa-solid fa-<?php echo $action['icon']; ?>"></i> <?php echo $action['label']; ?>
                                    </span>
                                <?php endif; ?>

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
            Showing <?php echo count($orders); ?> open <?php echo count($orders) === 1 ? 'order' : 'orders'; ?>
            <?php echo $hasFilters ? '(filtered)' : ''; ?> · <?php echo $inProcessTotal; ?> open in total
            · <?php echo $closedTotal; ?> in Order History
        </p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/staff_order_drawer.php'; ?>
<?php require __DIR__ . '/../includes/staff_order_drawer_script.php'; ?>
<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
