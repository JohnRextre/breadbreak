<?php
/**
 * Shared query helpers for the two staff order screens.
 *
 * staff/orders.php        -> in-process only (waiting, baking, on the way, ready)
 * staff/order-history.php -> closed only (delivered, cancelled)
 *
 * Keeping both in one place stops the two screens drifting apart.
 */

/** Statuses that still need staff attention. */
function inProcessOrderStatuses(): array
{
    return ['pending', 'processing', 'out_for_delivery', 'ready_for_pickup'];
}

/** Statuses that are finished and belong in history. */
function closedOrderStatuses(): array
{
    return ['completed', 'cancelled'];
}

function orderSelectSql(string $statusWhere = ''): string
{
    return "SELECT o.id, o.reference_id, o.status AS order_status, o.created_at, o.notes,
                   o.fulfillment_type, o.subtotal, o.vat_amount, o.vat_exempt_sales,
                   o.discount_type, o.discount_amount, o.discount_id_number, o.discount_name,
                   o.delivery_fee, o.delivery_address, o.total_amount, o.payment_method, o.cash_amount,
                   o.collected_amount, o.collected_at, o.proof_note, o.proof_captured_at,
                   (o.proof_photo_data IS NOT NULL) AS has_proof, o.rider_id, o.customer_id,
                   o.chat_closed_at, o.review_acknowledged_at,
                   u.first_name, u.last_name, u.email, u.phone,
                   p.amount, p.payment_method AS pay_method, p.payment_channel, p.status AS payment_status,
                   r.first_name AS rider_first, r.last_name AS rider_last, r.phone AS rider_phone
            FROM orders o
            JOIN users u ON u.id = o.customer_id
            LEFT JOIN payments p ON p.order_id = o.id
            LEFT JOIN users r ON r.id = o.rider_id" . $statusWhere;
}

/**
 * Builds the WHERE clause shared by both screens.
 *
 * @param array  $statuses     statuses the screen is allowed to show
 * @param string $search       free text (order #, customer, email, phone)
 * @param string $payment      '' | 'cash' | 'online' | a specific method
 * @param string $rider        '' | 'unassigned' | a rider id
 * @param string $fromDate     YYYY-MM-DD
 * @param string $toDate       YYYY-MM-DD
 */
function orderFilterClause(array $statuses, string $search, string $payment, string $rider, string $fromDate, string $toDate): array
{
    $where = ' WHERE o.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
    $params = $statuses;

    if ($search !== '') {
        $where .= " AND (o.reference_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?
                       OR u.email LIKE ? OR u.phone LIKE ?
                       OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id
                                  AND (oi.product_name LIKE ? OR oi.sku LIKE ?)))";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($payment === 'cash') {
        $where .= " AND UPPER(o.payment_method) = 'CASH'";
    } elseif ($payment === 'online') {
        $where .= " AND (o.payment_method IS NULL OR UPPER(o.payment_method) <> 'CASH')";
    } elseif ($payment !== '') {
        $where .= ' AND UPPER(o.payment_method) = ?';
        $params[] = strtoupper($payment);
    }

    if ($rider === 'unassigned') {
        $where .= ' AND o.rider_id IS NULL';
    } elseif ($rider !== '' && ctype_digit($rider)) {
        $where .= ' AND o.rider_id = ?';
        $params[] = (int) $rider;
    }

    if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $where .= ' AND DATE(o.created_at) >= ?';
        $params[] = $fromDate;
    }
    if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $where .= ' AND DATE(o.created_at) <= ?';
        $params[] = $toDate;
    }

    return [$where, $params];
}

/** Distinct payment methods present in the data, for the filter dropdown. */
function orderPaymentOptions(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT DISTINCT payment_method FROM orders WHERE payment_method IS NOT NULL AND payment_method <> ''") as $row) {
        $out[] = (string) $row['payment_method'];
    }
    sort($out);
    return $out;
}

/**
 * The one action staff should take next, if any. Everything else is automatic.
 * Returns ['kind' => 'accept'|'rider'|'ontheway'|'await'|'none', 'label', 'icon', 'hint'].
 */
function orderNextAction(array $o, array $riders): array
{
    $status = (string) $o['order_status'];
    $payStatus = strtolower((string) ($o['payment_status'] ?? 'pending'));
    $isCash = orderIsCashPayment($o['payment_method'] ?? '', $o['payment_channel'] ?? null);
    $isPickup = ($o['fulfillment_type'] ?? 'delivery') === 'pickup';

    if ($status === 'cancelled') {
        return ['kind' => 'none', 'label' => 'Cancelled', 'icon' => 'ban', 'hint' => 'No further action.'];
    }
    if ($status === 'completed') {
        return ['kind' => 'none', 'label' => 'Closed', 'icon' => 'circle-check', 'hint' => 'Delivered and closed.'];
    }
    if ($status === 'out_for_delivery') {
        return ['kind' => 'none', 'label' => 'With rider', 'icon' => 'motorcycle', 'hint' => 'Rider closes this out with a photo.'];
    }
    if ($status === 'ready_for_pickup') {
        return ['kind' => 'await', 'label' => 'Awaiting pickup', 'icon' => 'hourglass', 'hint' => 'Customer will collect this order.'];
    }
    if ($status === 'pending') {
        if ($isCash) {
            return ['kind' => 'accept', 'label' => 'Accept order', 'icon' => 'thumbs-up', 'hint' => 'Cash order — confirm to start baking.'];
        }
        if ($payStatus === 'paid') {
            return ['kind' => 'accept', 'label' => 'Start baking', 'icon' => 'bread-slice', 'hint' => 'Payment is in, but baking has not started.'];
        }
        return ['kind' => 'await', 'label' => 'Awaiting payment', 'icon' => 'hourglass', 'hint' => 'Waiting on Xendit to confirm the payment.'];
    }

    // status === processing
    if ($isPickup) {
        return ['kind' => 'rider', 'label' => 'Mark ready', 'icon' => 'store', 'hint' => 'Let the customer know it is ready to collect.'];
    }
    if (empty($o['rider_id'])) {
        return [
            'kind' => 'rider',
            'label' => 'Assign rider',
            'icon' => 'user-plus',
            'hint' => $riders ? 'Assigning sends the order on the way.' : 'No active riders — create one in Admin → Users.',
        ];
    }
    return ['kind' => 'ontheway', 'label' => 'Send on the way', 'icon' => 'motorcycle', 'hint' => 'A rider is assigned but the order has not been dispatched yet.'];
}
