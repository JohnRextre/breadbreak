<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('rider');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';
require_once __DIR__ . '/../includes/order_status.php';

$pdo = getDatabaseConnection();
ensureRiderSupport($pdo);
ensureOrderStatusEnum($pdo);

$riderId = (int) $_SESSION['user_id'];
$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int) ($_POST['order_id'] ?? 0);

    $orderStmt = $pdo->prepare(
        "SELECT o.id, o.reference_id, o.status, o.fulfillment_type, o.payment_method, o.rider_id
         FROM orders o
         WHERE o.id = :id AND o.rider_id = :rid AND o.fulfillment_type = 'delivery'
         LIMIT 1"
    );
    $orderStmt->execute(['id' => $orderId, 'rid' => $riderId]);
    $target = $orderStmt->fetch();

    if (!$target) {
        $notice = 'That delivery is not assigned to you.';
        $noticeType = 'danger';
    } elseif ($action === 'start_delivery' && in_array($target['status'], ['pending', 'processing'], true)) {
        $pdo->prepare("UPDATE orders SET status = 'out_for_delivery', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $orderId]);
        $notice = 'Order #' . $target['reference_id'] . ' is now on the way.';
    } elseif ($action === 'mark_delivered' && $target['status'] === 'out_for_delivery') {
        $pdo->prepare("UPDATE orders SET status = 'completed', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $orderId]);
        if (strtoupper((string) $target['payment_method']) === 'CASH') {
            $pdo->prepare("UPDATE payments SET status = 'paid', updated_at = NOW() WHERE order_id = :id AND payment_method = 'CASH'")
                ->execute(['id' => $orderId]);
        }
        $notice = 'Order #' . $target['reference_id'] . ' marked as delivered.';
    } elseif ($action === 'collect_cash' && strtoupper((string) $target['payment_method']) === 'CASH') {
        $pdo->prepare("UPDATE payments SET status = 'paid', updated_at = NOW() WHERE order_id = :id AND payment_method = 'CASH'")
            ->execute(['id' => $orderId]);
        $notice = 'Cash collected for order #' . $target['reference_id'] . '.';
    } else {
        $notice = 'This action is not available for the current order status.';
        $noticeType = 'danger';
    }
}

$jobsStmt = $pdo->prepare(
    "SELECT o.id, o.reference_id, o.status, o.delivery_address, o.total_amount, o.payment_method,
            o.cash_amount, o.created_at, u.first_name, u.last_name, u.phone,
            p.status AS payment_status
     FROM orders o
     JOIN users u ON u.id = o.customer_id
     LEFT JOIN payments p ON p.order_id = o.id
     WHERE o.rider_id = :rid
       AND o.fulfillment_type = 'delivery'
       AND o.status NOT IN ('cancelled')
     ORDER BY FIELD(o.status, 'out_for_delivery', 'processing', 'pending', 'completed'), o.id DESC"
);
$jobsStmt->execute(['rid' => $riderId]);
$jobs = $jobsStmt->fetchAll();

$orderIds = array_column($jobs, 'id');
$itemsMap = [];
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare(
        "SELECT order_id, product_name, service_size, quantity
         FROM order_items WHERE order_id IN ($placeholders) ORDER BY id ASC"
    );
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsMap[$item['order_id']][] = $item;
    }
}

$activeJobs = array_values(array_filter($jobs, fn ($row) => $row['status'] !== 'completed'));
$doneJobs = array_values(array_filter($jobs, fn ($row) => $row['status'] === 'completed'));

$pageTitle = 'Deliveries | BreadBreak';
require __DIR__ . '/../includes/rider_header.php';

function riderJobCard(array $o, array $items, bool $history = false): void
{
    $status = $o['status'];
    $chip = match ($status) {
        'out_for_delivery' => ['is-way', 'On the way'],
        'completed' => ['is-done', 'Delivered'],
        'processing' => ['', 'Baking — ready soon'],
        default => ['', 'Assigned'],
    };
    $isCash = strtoupper((string) $o['payment_method']) === 'CASH';
    $cashAmount = (float) ($o['cash_amount'] ?? 0);
    $total = (float) $o['total_amount'];
    $change = max(0, $cashAmount - $total);
    $payStatus = strtolower((string) ($o['payment_status'] ?? 'pending'));
    $maps = 'https://www.google.com/maps/search/?api=1&query=' . urlencode((string) $o['delivery_address']);
    ?>
    <article class="rider-card">
        <div class="rider-card-top">
            <div>
                <h2>Order #<?php echo htmlspecialchars($o['reference_id']); ?></h2>
                <p><?php echo htmlspecialchars(trim($o['first_name'] . ' ' . $o['last_name'])); ?> · <?php echo htmlspecialchars($o['phone']); ?></p>
            </div>
            <span class="rider-chip <?php echo $chip[0]; ?>"><?php echo $chip[1]; ?></span>
        </div>
        <p class="rider-address"><i class="fa-solid fa-location-dot"></i> <?php echo nl2br(htmlspecialchars($o['delivery_address'] ?: 'No address on file')); ?></p>
        <p class="rider-items">
            <?php foreach ($items as $item): ?>
                <?php echo htmlspecialchars($item['product_name']); ?>
                (<?php echo htmlspecialchars($item['service_size']); ?> × <?php echo (int) $item['quantity']; ?>)
                <?php echo $item !== end($items) ? ' · ' : ''; ?>
            <?php endforeach; ?>
        </p>
        <p class="rider-meta">Total ₱<?php echo number_format($total, 2); ?> · <?php echo $isCash ? 'Cash on delivery' : htmlspecialchars(ucfirst(strtolower((string) $o['payment_method']))); ?></p>
        <?php if ($isCash && $cashAmount > 0): ?>
            <div class="rider-cash">
                Collect ₱<?php echo number_format($cashAmount, 2); ?>
                <?php if ($change > 0): ?> · Change ₱<?php echo number_format($change, 2); ?><?php endif; ?>
                <?php if ($payStatus === 'paid'): ?> · Cash received<?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!$history): ?>
        <div class="rider-actions">
            <?php if ($o['delivery_address']): ?>
                <a class="rider-btn outline" href="<?php echo htmlspecialchars($maps); ?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-map"></i> Maps</a>
            <?php endif; ?>
            <a class="rider-btn outline" href="tel:<?php echo htmlspecialchars($o['phone']); ?>"><i class="fa-solid fa-phone"></i> Call</a>
            <a class="rider-btn outline" href="/BreadBreak/rider/chat.php?order=<?php echo (int) $o['id']; ?>"><i class="fa-solid fa-comments"></i> Message</a>
            <?php if (in_array($status, ['pending', 'processing'], true)): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="start_delivery" />
                    <input type="hidden" name="order_id" value="<?php echo (int) $o['id']; ?>" />
                    <button class="rider-btn primary" type="submit"><i class="fa-solid fa-bicycle"></i> Start delivery</button>
                </form>
            <?php endif; ?>
            <?php if ($status === 'out_for_delivery'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="mark_delivered" />
                    <input type="hidden" name="order_id" value="<?php echo (int) $o['id']; ?>" />
                    <button class="rider-btn success" type="submit"><i class="fa-solid fa-circle-check"></i> Delivered</button>
                </form>
            <?php endif; ?>
            <?php if ($isCash && $payStatus !== 'paid' && $status !== 'completed'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="collect_cash" />
                    <input type="hidden" name="order_id" value="<?php echo (int) $o['id']; ?>" />
                    <button class="rider-btn outline" type="submit"><i class="fa-solid fa-coins"></i> Cash collected</button>
                </form>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="rider-actions">
            <a class="rider-btn outline" href="/BreadBreak/rider/chat.php?order=<?php echo (int) $o['id']; ?>"><i class="fa-solid fa-comments"></i> Message</a>
        </div>
        <?php endif; ?>
    </article>
    <?php
}
?>

<div class="rider-hero">
    <span class="rider-chip">Assigned trips</span>
    <h1>Your deliveries</h1>
    <p>Only orders assigned to you by bakery staff appear here.</p>
</div>

<?php if ($notice): ?>
    <div class="rider-notice <?php echo $noticeType; ?>"><?php echo htmlspecialchars($notice); ?></div>
<?php endif; ?>

<?php if (!$activeJobs): ?>
    <div class="rider-empty">
        <i class="fa-solid fa-bread-slice"></i>
        <h2>No assigned deliveries yet</h2>
        <p>When staff assigns a delivery to you, it will show up on this page.</p>
    </div>
<?php else: ?>
    <?php foreach ($activeJobs as $job): ?>
        <?php riderJobCard($job, $itemsMap[$job['id']] ?? []); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($doneJobs): ?>
    <h2 style="margin: 2rem 0 .4rem; font-size: 1rem;">Recently delivered</h2>
    <?php foreach (array_slice($doneJobs, 0, 8) as $job): ?>
        <?php riderJobCard($job, $itemsMap[$job['id']] ?? [], true); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/rider_footer.php'; ?>
