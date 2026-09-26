<?php
// Shared order-status helpers for confirmation, tracking, and staff updates.

function ensureOrderStatusEnum(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "ALTER TABLE orders
             MODIFY COLUMN status ENUM(
                'pending',
                'processing',
                'out_for_delivery',
                'ready_for_pickup',
                'completed',
                'cancelled'
             ) NOT NULL DEFAULT 'pending'"
        );
    } catch (Throwable) {
        // Column already matches, or the account cannot ALTER — ignore.
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Audit trail
//
// Every status change writes one row here so the Orders screen can be read as a
// log of who did what, when. Nothing in the app depends on this table existing,
// so a failed insert never blocks a real status change.
// ─────────────────────────────────────────────────────────────────────────────
function ensureOrderStatusHistory(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_status_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL,
                from_status VARCHAR(30) NULL,
                to_status VARCHAR(30) NOT NULL,
                actor_id INT NULL,
                actor_role VARCHAR(20) NULL,
                actor_name VARCHAR(150) NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_osh_order (order_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable) {
        // Table already exists, or the account cannot CREATE.
    }
}

function orderActor(): array
{
    $name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    return [
        'actor_id' => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'actor_role' => (string) ($_SESSION['role'] ?? 'system'),
        'actor_name' => $name !== '' ? $name : 'System',
    ];
}

function logOrderStatusChange(
    PDO $pdo,
    int $orderId,
    ?string $from,
    string $to,
    array $actor = [],
    ?string $note = null
): void {
    try {
        $pdo->prepare(
            "INSERT INTO order_status_history
                (order_id, from_status, to_status, actor_id, actor_role, actor_name, note)
             VALUES (:oid, :from, :to, :aid, :arole, :aname, :note)"
        )->execute([
            'oid' => $orderId,
            'from' => ($from !== null && $from !== '') ? $from : null,
            'to' => $to,
            'aid' => !empty($actor['actor_id']) ? (int) $actor['actor_id'] : null,
            'arole' => !empty($actor['actor_role']) ? (string) $actor['actor_role'] : 'system',
            'aname' => !empty($actor['actor_name']) ? mb_substr((string) $actor['actor_name'], 0, 150) : 'System',
            'note' => ($note !== null && trim($note) !== '') ? mb_substr(trim($note), 0, 255) : null,
        ]);
    } catch (Throwable) {
        // Auditing is best-effort and must never break the status change itself.
    }
}

/**
 * Returns [orderId => [rows oldest-first]]. Legacy orders that predate the audit
 * table are seeded with their current state so no order ever renders an empty trail.
 */
function orderStatusHistoryMap(PDO $pdo, array $orders): array
{
    if (!$orders) {
        return [];
    }

    ensureOrderStatusHistory($pdo);

    $ids = array_map('intval', array_column($orders, 'id'));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $select = "SELECT id, order_id, from_status, to_status, actor_role, actor_name, note, created_at
               FROM order_status_history
               WHERE order_id IN ($in)
               ORDER BY id ASC";

    $readStmt = $pdo->prepare($select);
    $readStmt->execute($ids);
    $rows = $readStmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['order_id']][] = $row;
    }

    $seeded = false;
    foreach ($orders as $order) {
        $oid = (int) $order['id'];
        if (!empty($map[$oid])) {
            continue;
        }
        $status = (string) ($order['order_status'] ?? $order['status'] ?? '');
        logOrderStatusChange(
            $pdo,
            $oid,
            null,
            $status,
            ['actor_role' => 'system', 'actor_name' => 'System'],
            'Backfilled from existing order data.'
        );
        $seeded = true;
    }

    if ($seeded) {
        $readStmt->execute($ids);
        $rows = $readStmt->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['order_id']][] = $row;
        }
    }

    return $map;
}

// Compact variant for the list row, where the full sentence will not fit on one line.
function orderHistoryLabelShort(string $to, ?string $from, string $fulfillmentType = 'delivery'): string
{
    if ($from !== null && $from !== '' && $from === $to) {
        return 'Updated';
    }
    return match ($to) {
        'pending' => 'Order received',
        'processing' => 'Accepted',
        'out_for_delivery' => 'Rider dispatched',
        'ready_for_pickup' => 'Ready for pickup',
        'completed' => $fulfillmentType === 'pickup' ? 'Picked up' : 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $to)),
    };
}

function orderHistoryLabel(string $to, ?string $from, string $fulfillmentType = 'delivery'): string
{
    if ($from !== null && $from !== '' && $from === $to) {
        return 'Updated';
    }
    return match ($to) {
        'pending' => 'Order received',
        'processing' => 'Accepted — now baking',
        'out_for_delivery' => 'Rider dispatched',
        'ready_for_pickup' => 'Ready for pickup',
        'completed' => $fulfillmentType === 'pickup' ? 'Picked up' : 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $to)),
    };
}

function orderHistoryIcon(string $to, ?string $from): string
{
    if ($from !== null && $from !== '' && $from === $to) {
        return 'pen';
    }
    return match ($to) {
        'pending' => 'receipt',
        'processing' => 'fire-burner',
        'out_for_delivery' => 'motorcycle',
        'ready_for_pickup' => 'store',
        'completed' => 'circle-check',
        'cancelled' => 'ban',
        default => 'circle-dot',
    };
}

function orderActorRoleLabel(?string $role): string
{
    return match ((string) $role) {
        'customer' => 'Customer',
        'rider' => 'Rider',
        'staff' => 'Staff',
        'admin' => 'Admin',
        'system' => 'Automatic',
        default => 'System',
    };
}

function orderHistoryRelativeTime(string $timestamp): string
{
    $time = strtotime($timestamp);
    if (!$time) {
        return '';
    }
    $diff = time() - $time;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . 'd ago';
    }
    return date('M j, Y', $time);
}

// ─────────────────────────────────────────────────────────────────────────────
// Status machine
//
// Staff do not hand-pick statuses any more. The only manual steps left are
// Accept (cash orders) and Cancel; "On the Way" happens by itself the moment a
// rider is assigned, and "Delivered" belongs to the rider.
// ─────────────────────────────────────────────────────────────────────────────
function allowedStaffTransitions(string $status, string $fulfillmentType = 'delivery'): array
{
    if ($fulfillmentType === 'pickup') {
        return match ($status) {
            'pending' => ['processing', 'cancelled'],
            'processing' => ['ready_for_pickup', 'cancelled'],
            'ready_for_pickup' => ['completed', 'cancelled'],
            default => [],
        };
    }

    return match ($status) {
        'pending' => ['processing', 'cancelled'],
        'processing' => ['out_for_delivery', 'cancelled'],
        default => [],
    };
}

// Cancel is deliberately narrow: only before a rider is on the job, so the audit
// trail can never contradict itself by showing a cancelled order out for delivery.
// Accepts both `status` and `order_status` because the list query aliases the column.
function canStaffCancel(array $order): bool
{
    $status = (string) ($order['status'] ?? $order['order_status'] ?? '');
    if (!in_array($status, ['pending', 'processing'], true)) {
        return false;
    }
    if (($order['fulfillment_type'] ?? 'delivery') === 'pickup') {
        return true;
    }
    return empty($order['rider_id']);
}

function orderCancelReasons(): array
{
    return [
        'Duplicate order',
        'Out of stock',
        'Customer changed mind',
        'Suspicious order',
        'Unreachable customer',
    ];
}

/**
 * Human label for how an order is being paid.
 *
 * `orders.payment_method` is the only trustworthy source: it records what the
 * customer actually chose at checkout. Do NOT use `payments.payment_method` for
 * this — Xendit reports the generic code "CASH" for e-wallet payments too, which
 * makes a GCash order look like a cash-on-delivery one.
 *
 * `payment_channel` is only a fallback for rows written before the order recorded
 * its own method.
 */
function orderPaymentLabel(?string $method, ?string $channel = null, string $fulfillmentType = 'delivery'): string
{
    $raw = strtoupper(trim((string) $method));
    if ($raw === '') {
        $raw = strtoupper(trim((string) $channel));
    }

    return match ($raw) {
        'CASH' => $fulfillmentType === 'pickup' ? 'Cash on Pickup' : 'Cash on Delivery',
        'GCASH' => 'GCash',
        'PAYMAYA' => 'PayMaya',
        'GRABPAY' => 'GrabPay',
        'SHOPEEPAY' => 'Shopee Pay',
        'CARD', 'CREDIT_CARD' => 'Card',
        'BANK_TRANSFER' => 'Bank Transfer',
        'EWALLET', 'ONLINE' => 'Online Wallet',
        '' => 'Unpaid',
        default => ucfirst(strtolower($raw)),
    };
}

/** True when the customer pays in physical cash on handover. */
function orderIsCashPayment(?string $method, ?string $channel = null): bool
{
    $raw = strtoupper(trim((string) $method));
    if ($raw === '') {
        $raw = strtoupper(trim((string) $channel));
    }
    return $raw === 'CASH';
}

/** Compact label for tight spots like table chips. */
function orderPaymentLabelShort(?string $method, ?string $channel = null): string
{
    $raw = strtoupper(trim((string) $method));
    if ($raw === '') {
        $raw = strtoupper(trim((string) $channel));
    }

    return match ($raw) {
        'CASH' => 'Cash',
        'GCASH' => 'GCash',
        'PAYMAYA' => 'PayMaya',
        'GRABPAY' => 'GrabPay',
        'SHOPEEPAY' => 'Shopee Pay',
        'CARD', 'CREDIT_CARD' => 'Card',
        'EWALLET', 'ONLINE' => 'Online',
        '' => 'Unpaid',
        default => ucfirst(strtolower($raw)),
    };
}

// Explains *why* the cancel option is missing, instead of one blanket message.
function staffCancelBlockedReason(array $order): string
{
    $status = (string) ($order['status'] ?? $order['order_status'] ?? '');

    if (in_array($status, ['completed', 'cancelled'], true)) {
        return 'This order is already closed, so it can no longer be cancelled.';
    }
    if (($order['fulfillment_type'] ?? 'delivery') === 'delivery' && !empty($order['rider_id'])) {
        return 'A rider is already assigned to this order, so it can no longer be cancelled.';
    }
    if (in_array($status, ['out_for_delivery', 'ready_for_pickup'], true)) {
        return 'This order is already in progress, so it can no longer be cancelled.';
    }
    return 'This order can no longer be cancelled.';
}

function orderTransitionError(string $from, string $to, string $fulfillmentType = 'delivery'): string
{
    if ($from === 'out_for_delivery' && $to !== 'completed') {
        return 'This order is already on the way. Ask the rider to finish it, or contact support if something went wrong.';
    }
    if (in_array($from, ['completed', 'cancelled'], true)) {
        return 'This order is already closed, so its status can no longer change.';
    }
    if ($to === 'completed' && $fulfillmentType !== 'pickup') {
        return 'Deliveries are marked delivered by the rider, with a photo.';
    }
    if ($to === 'out_for_delivery') {
        return 'Assign a rider first — the order goes on the way automatically after that.';
    }
    return 'That status change is not available for an order that is currently '
        . strtolower(orderStatusCustomerLabel($from, $fulfillmentType)) . '.';
}

/**
 * Single entry point for every status change in the app.
 * Returns ['ok' => bool, 'error' => string] or ['ok' => true, ...] on success.
 */
function applyOrderStatus(
    PDO $pdo,
    int $orderId,
    string $to,
    array $actor = [],
    ?string $note = null,
    array $options = []
): array {
    $stmt = $pdo->prepare(
        'SELECT id, reference_id, status, fulfillment_type, rider_id, customer_id
         FROM orders WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return ['ok' => false, 'error' => 'Order was not found.'];
    }

    $from = (string) $order['status'];
    $fulfillment = $order['fulfillment_type'] ?? 'delivery';

    if ($from === $to) {
        return ['ok' => false, 'error' => 'This order is already ' . strtolower(orderStatusCustomerLabel($to, $fulfillment)) . '.'];
    }

    $force = !empty($options['force']);
    if (!$force && !in_array($to, allowedStaffTransitions($from, $fulfillment), true)) {
        return ['ok' => false, 'error' => orderTransitionError($from, $to, $fulfillment)];
    }

    if ($to === 'cancelled') {
        if (!canStaffCancel($order)) {
            return ['ok' => false, 'error' => 'This order can no longer be cancelled because a rider is already assigned.'];
        }
        if (trim((string) $note) === '') {
            return ['ok' => false, 'error' => 'Pick a reason so the audit trail records why this order was cancelled.'];
        }
    }

    if ($to === 'out_for_delivery' && empty($order['rider_id']) && empty($options['rider_id'])) {
        return ['ok' => false, 'error' => 'Assign a rider before this order goes on the way.'];
    }

    $terminal = in_array($to, ['completed', 'cancelled'], true);
    $wasTerminal = in_array($from, ['completed', 'cancelled'], true);

    $sets = ['status = :status', 'updated_at = NOW()'];
    $params = ['status' => $to, 'id' => $orderId];

    if (!empty($options['rider_id'])) {
        $sets[] = 'rider_id = :rid';
        $params['rid'] = (int) $options['rider_id'];
    }

    // The rider/customer thread closes with the order and reopens if it comes back.
    if ($terminal) {
        $sets[] = 'chat_closed_at = IFNULL(chat_closed_at, NOW())';
        $sets[] = 'chat_closed_by = IFNULL(chat_closed_by, :closer)';
        $params['closer'] = (string) ($actor['actor_role'] ?? 'system');
    } else {
        $sets[] = 'chat_closed_at = NULL';
        $sets[] = 'chat_closed_by = NULL';
    }

    // Reopening a closed order means the trip starts over, so stale proof and
    // cash figures must not survive into the new attempt.
    if ($wasTerminal && !$terminal) {
        $sets[] = 'proof_photo_data = NULL';
        $sets[] = 'proof_photo_mime = NULL';
        $sets[] = 'proof_note = NULL';
        $sets[] = 'proof_captured_at = NULL';
        $sets[] = 'collected_amount = NULL';
        $sets[] = 'collected_at = NULL';
    }

    $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

    logOrderStatusChange($pdo, $orderId, $from, $to, $actor, $note);

    return [
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'reference_id' => $order['reference_id'],
        'auto_note' => $note,
    ];
}

// Commits stock when an order is accepted. Online orders are handled by the Xendit
// webhook; cash orders have no webhook, so this is the only place their stock moves.
function deductOrderStock(PDO $pdo, int $orderId): void
{
    $items = $pdo->prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = :oid');
    $items->execute(['oid' => $orderId]);

    $deduct = $pdo->prepare(
        'UPDATE inventory_item_variants
         SET quantity = GREATEST(0, quantity - :qty)
         WHERE id = :vid'
    );
    foreach ($items->fetchAll() as $item) {
        $deduct->execute(['qty' => (int) $item['quantity'], 'vid' => (int) $item['variant_id']]);
    }

    $pdo->prepare(
        "UPDATE inventory_item_variants
         SET availability = 'unavailable'
         WHERE id IN (SELECT variant_id FROM order_items WHERE order_id = :oid)
           AND quantity = 0"
    )->execute(['oid' => $orderId]);
}

function orderStatusChip(string $status, string $fulfillmentType = 'delivery'): array
{
    return match ($status) {
        'pending' => ['is-pending', 'clipboard-list', 'Received'],
        'processing' => ['is-processing', 'bread-slice', 'Baking'],
        'out_for_delivery' => ['is-processing', 'motorcycle', 'On the Way'],
        'ready_for_pickup' => ['is-processing', 'store', 'Ready for Pickup'],
        'completed' => ['is-completed', 'circle-check', $fulfillmentType === 'pickup' ? 'Picked Up' : 'Delivered'],
        'cancelled' => ['is-cancelled', 'ban', 'Cancelled'],
        default => ['is-pending', 'clipboard-list', ucfirst(str_replace('_', ' ', $status))],
    };
}

function orderFulfillmentSteps(string $fulfillmentType): array
{
    if ($fulfillmentType === 'pickup') {
        return [
            ['key' => 'pending', 'label' => 'Received', 'icon' => 'receipt'],
            ['key' => 'processing', 'label' => 'Baking', 'icon' => 'bread-slice'],
            ['key' => 'ready_for_pickup', 'label' => 'Ready', 'icon' => 'store'],
            ['key' => 'completed', 'label' => 'Picked Up', 'icon' => 'bag-shopping'],
        ];
    }

    return [
        ['key' => 'pending', 'label' => 'Received', 'icon' => 'receipt'],
        ['key' => 'processing', 'label' => 'Baking', 'icon' => 'bread-slice'],
        ['key' => 'out_for_delivery', 'label' => 'On the Way', 'icon' => 'bicycle'],
        ['key' => 'completed', 'label' => 'Delivered', 'icon' => 'house-chimney'],
    ];
}

function orderStatusStepIndex(string $status): int
{
    return match ($status) {
        'pending' => 0,
        'processing' => 1,
        'out_for_delivery', 'ready_for_pickup' => 2,
        'completed' => 3,
        default => -1,
    };
}

function orderStatusCustomerMessage(string $status, string $fulfillmentType = 'delivery'): string
{
    if ($fulfillmentType === 'pickup') {
        return match ($status) {
            'pending' => 'We received your order and will start preparing it shortly.',
            'processing' => 'Your breads are in the oven. We’ll notify you when they’re ready to pick up.',
            'ready_for_pickup' => 'Your order is ready. Please visit BreadBreak Estrella Village to claim it.',
            'completed' => 'This order has been picked up. Salamat, and enjoy!',
            'cancelled' => 'This order was cancelled. Contact us if you need help placing a new one.',
            default => 'We’re updating your order. Please check back in a moment.',
        };
    }

    return match ($status) {
        'pending' => 'We received your order and will start baking it shortly.',
        'processing' => 'Your breads are being prepared. A rider will head out once they’re packed.',
        'out_for_delivery' => 'Your order is on the way. Please keep your phone nearby.',
        'completed' => 'Your order has been delivered. Salamat, and enjoy!',
        'cancelled' => 'This order was cancelled. Contact us if you need help placing a new one.',
        default => 'We’re updating your order. Please check back in a moment.',
    };
}

function orderStatusCustomerLabel(string $status, string $fulfillmentType = 'delivery'): string
{
    if ($fulfillmentType === 'pickup') {
        return match ($status) {
            'pending' => 'Order received',
            'processing' => 'Now baking',
            'ready_for_pickup' => 'Ready for pickup',
            'completed' => 'Picked up',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    return match ($status) {
        'pending' => 'Order received',
        'processing' => 'Now baking',
        'out_for_delivery' => 'Out for delivery',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function staffOrderStatusOptions(string $fulfillmentType): array
{
    if ($fulfillmentType === 'pickup') {
        return [
            'pending' => 'Received',
            'processing' => 'Baking',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed' => 'Picked Up',
            'cancelled' => 'Cancelled',
        ];
    }

    return [
        'pending' => 'Received',
        'processing' => 'Baking',
        'out_for_delivery' => 'On the Way',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
}

function allowedOrderStatuses(): array
{
    return ['pending', 'processing', 'out_for_delivery', 'ready_for_pickup', 'completed', 'cancelled'];
}

function renderOrderStatusTracker(string $status, string $fulfillmentType, string $size = 'default'): string
{
    $steps = orderFulfillmentSteps($fulfillmentType);
    $current = orderStatusStepIndex($status);
    $cancelled = $status === 'cancelled';
    $html = '<ol class="bb-tracker bb-tracker-' . htmlspecialchars($size) . ($cancelled ? ' is-cancelled' : '') . '" aria-label="Order progress">';

    foreach ($steps as $i => $step) {
        $state = 'is-upcoming';
        if ($cancelled) {
            $state = 'is-cancelled-step';
        } elseif ($current >= 0) {
            if ($i < $current) {
                $state = 'is-done';
            } elseif ($i === $current) {
                $state = 'is-current';
            }
        }

        $html .= '<li class="' . $state . '">';
        $html .= '<span class="bb-tracker-icon" aria-hidden="true"><i class="fa-solid fa-' . htmlspecialchars($step['icon']) . '"></i></span>';
        $html .= '<span class="bb-tracker-label">' . htmlspecialchars($step['label']) . '</span>';
        $html .= '</li>';
    }

    $html .= '</ol>';
    if ($cancelled) {
        $html .= '<p class="bb-tracker-cancelled-note">This order was cancelled.</p>';
    }

    return $html;
}
