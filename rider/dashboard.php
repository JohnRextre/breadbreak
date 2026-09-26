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
ensureOrderStatusHistory($pdo);

$riderId = (int) $_SESSION['user_id'];
$actor = orderActor();
$notice = '';
$noticeType = 'success';
$panelOrderId = 0; // Keeps the "complete delivery" panel open after a failed attempt.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $error = '';
    $success = '';

    $orderStmt = $pdo->prepare(
        "SELECT o.id, o.reference_id, o.status, o.fulfillment_type, o.payment_method, o.rider_id,
                o.total_amount, o.cash_amount, o.collected_amount,
                (SELECT p.status FROM payments p WHERE p.order_id = o.id LIMIT 1) AS payment_status
         FROM orders o
         WHERE o.id = :id AND o.rider_id = :rid AND o.fulfillment_type = 'delivery'
         LIMIT 1"
    );
    $orderStmt->execute(['id' => $orderId, 'rid' => $riderId]);
    $target = $orderStmt->fetch();

    if (!$target) {
        $error = 'That delivery is not assigned to you.';
    } else {
        $cash = orderCashSummary($target);
        $total = $cash['total'];

        if ($action === 'start_delivery' && $target['status'] === 'processing') {
            // Normally staff dispatch the order by assigning a rider; this button
            // only covers trips that were assigned before that rule existed.
            $result = applyOrderStatus(
                $pdo,
                $orderId,
                'out_for_delivery',
                $actor,
                'Rider started the trip.'
            );
            if ($result['ok']) {
                $success = 'Order #' . $target['reference_id'] . ' is now on the way.';
            } else {
                $error = $result['error'];
            }

        } elseif ($action === 'start_delivery' && $target['status'] === 'pending') {
            $error = 'The bakery has not accepted this order yet. Start it from your list once it reaches Baking.';

        } elseif ($action === 'collect_cash' && $cash['is_cash']) {
            $received = null;
            $raw = trim((string) ($_POST['amount_received'] ?? ''));
            if ($raw !== '') {
                $received = round((float) str_replace([',', ' '], '', $raw), 2);
                if ($received < 0) {
                    $error = 'Enter a valid cash amount received.';
                } elseif ($received + 0.005 < $total) {
                    $error = 'Cash received (₱' . number_format($received, 2) . ') is less than the order total (₱'
                        . number_format($total, 2) . '). Enter the correct amount.';
                }
            } else {
                $received = $cash['declared'] > 0 ? $cash['declared'] : $total;
            }

            if ($error === '') {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "UPDATE payments SET status = 'paid', updated_at = NOW()
                         WHERE order_id = :id AND status IN ('pending', 'paid')"
                    )->execute(['id' => $orderId]);
                    $pdo->prepare(
                        'UPDATE orders SET collected_amount = :amt, collected_at = NOW() WHERE id = :id'
                    )->execute(['amt' => $received, 'id' => $orderId]);

                    $change = max(0, $received - $total);
                    logOrderStatusChange(
                        $pdo,
                        $orderId,
                        $target['status'],
                        $target['status'],
                        $actor,
                        'Cash collected ₱' . number_format($received, 2) . ' · change ₱' . number_format($change, 2)
                    );
                    $pdo->commit();
                } catch (Throwable) {
                    $pdo->rollBack();
                    $error = 'Unable to record the cash collection. Please try again.';
                }

                if ($error === '') {
                    $success = '₱' . number_format($received, 2) . ' collected for order #'
                        . $target['reference_id'] . ' · Change ₱' . number_format(max(0, $received - $total), 2) . '.';
                }
            }

        } elseif ($action === 'mark_delivered') {
            $panelOrderId = $orderId;

            if ($target['status'] !== 'out_for_delivery') {
                $error = 'This action is not available for the current order status.';
            } elseif ($cash['is_cash'] && !$cash['paid']) {
                // Cash on delivery must be settled before the order can be closed out.
                $error = 'Collect the cash first — then mark the order as delivered.';
            } else {
                try {
                    $proof = readDeliveryProofUpload('proof_photo');
                } catch (RuntimeException $uploadError) {
                    $proof = null;
                    $error = $uploadError->getMessage();
                }

                if ($error === '' && !$proof) {
                    $error = 'A delivery photo is required. Take or upload a picture of the handover before continuing.';
                }

                if ($error === '') {
                    $note = trim((string) ($_POST['proof_note'] ?? ''));
                    $pdo->beginTransaction();
                    try {
                        if ($cash['is_cash']) {
                            $pdo->prepare(
                                "UPDATE payments SET status = 'paid', updated_at = NOW()
                                 WHERE order_id = :id AND status IN ('pending', 'paid')"
                            )->execute(['id' => $orderId]);
                        }
                        $pdo->prepare(
                            "UPDATE orders
                             SET proof_photo_data = :photo,
                                 proof_photo_mime = :mime,
                                 proof_note = :note,
                                 proof_captured_at = NOW(),
                                 updated_at = NOW()
                             WHERE id = :id"
                        )->execute([
                            'id' => $orderId,
                            'photo' => $proof['data'],
                            'mime' => $proof['mime'],
                            'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                        ]);

                        // applyOrderStatus flips the status, closes the chat, and writes
                        // the audit entry — all inside this same transaction.
                        $result = applyOrderStatus(
                            $pdo,
                            $orderId,
                            'completed',
                            $actor,
                            'Delivered with proof photo'
                                . ($note !== '' ? ' · handover note: ' . $note : ''),
                            ['force' => true]
                        );

                        if (!$result['ok']) {
                            throw new RuntimeException($result['error']);
                        }

                        $pdo->commit();
                    } catch (Throwable $saveError) {
                        $pdo->rollBack();
                        $error = $saveError instanceof RuntimeException
                            ? $saveError->getMessage()
                            : 'Unable to save the delivery. Please try again.';
                    }

                    if ($error === '') {
                        logDeliverySystemMessage(
                            $pdo,
                            $orderId,
                            $riderId,
                            'Delivered! Thanks for ordering from BreadBreak.'
                        );
                        $panelOrderId = 0;
                        $success = 'Order #' . $target['reference_id'] . ' delivered. Chat closed and proof photo saved.';
                    }
                }
            }

        } else {
            $error = 'This action is not available for the current order status.';
        }
    }

    $notice = $error !== '' ? $error : $success;
    $noticeType = $error !== '' ? 'danger' : 'success';
}

$jobsStmt = $pdo->prepare(
    "SELECT o.id, o.reference_id, o.status, o.delivery_address, o.total_amount, o.payment_method,
            o.cash_amount, o.collected_amount, o.collected_at, o.created_at,
            o.proof_captured_at, (o.proof_photo_data IS NOT NULL) AS has_proof, o.chat_closed_at,
            u.first_name, u.last_name, u.phone,
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

$onTheWay = count(array_filter($activeJobs, fn ($row) => $row['status'] === 'out_for_delivery'));
$waiting = count($activeJobs) - $onTheWay;
$pendingCash = 0.0;
foreach ($activeJobs as $row) {
    $cash = orderCashSummary($row);
    if ($cash['is_cash'] && !$cash['paid']) {
        $pendingCash += max(0, $cash['declared'] > 0 ? $cash['declared'] : $cash['total']);
    }
}
$deliveredCount = count($doneJobs);

$pageTitle = 'Deliveries | BreadBreak';
require __DIR__ . '/../includes/rider_header.php';

function riderProgressSteps(string $status): string
{
    $current = orderStatusStepIndex($status);
    $html = '<ol class="rider-steps">';
    foreach (orderFulfillmentSteps('delivery') as $index => $step) {
        $state = $index < $current ? 'is-done' : ($index === $current ? 'is-current' : 'is-upcoming');
        $html .= '<li class="' . $state . '">'
            . '<span class="rider-step-dot"><i class="fa-solid fa-' . htmlspecialchars($step['icon']) . '"></i></span>'
            . '<span class="rider-step-label">' . htmlspecialchars($step['label']) . '</span>'
            . '</li>';
    }
    return $html . '</ol>';
}

function riderJobCard(array $o, array $items, bool $history = false): void
{
    global $panelOrderId;

    $orderId = (int) $o['id'];
    $status = $o['status'];
    $customerName = trim($o['first_name'] . ' ' . $o['last_name']);
    $customerInitial = strtoupper(substr($customerName, 0, 1)) ?: '?';
    $chip = match ($status) {
        'out_for_delivery' => ['is-way', 'On the way', 'fa-bicycle'],
        'completed' => ['is-done', 'Delivered', 'fa-circle-check'],
        'processing' => ['is-baking', 'Baking', 'fa-fire-burner'],
        default => ['', 'Assigned', 'fa-box'],
    };
    $cash = orderCashSummary($o);
    $total = $cash['total'];
    $maps = 'https://www.google.com/maps/search/?api=1&query=' . urlencode((string) $o['delivery_address']);
    $panelId = 'rider-panel-' . $orderId;
    $panelOpen = !$history && $status === 'out_for_delivery' && (int) $panelOrderId === $orderId;
    $proofUrl = BASE_URL . '/api/delivery-proof.php?order=' . $orderId;
    ?>
    <article class="rider-card<?php echo $status === 'out_for_delivery' && !$history ? ' is-urgent' : ''; ?><?php echo $history ? ' is-history' : ''; ?>">
        <div class="rider-card-head">
            <div class="rider-ref">
                <span class="rider-ref-label">Order</span>
                <h2>#<?php echo htmlspecialchars($o['reference_id']); ?></h2>
                <small><i class="fa-regular fa-clock"></i> <?php echo date('M j, g:i A', strtotime($o['created_at'])); ?></small>
            </div>
            <span class="rider-chip <?php echo $chip[0]; ?>"><i class="fa-solid <?php echo $chip[2]; ?>"></i> <?php echo $chip[1]; ?></span>
        </div>

        <div class="rider-customer">
            <span class="rider-avatar-sm"><?php echo htmlspecialchars($customerInitial); ?></span>
            <div>
                <strong><?php echo htmlspecialchars($customerName); ?></strong>
                <small><?php echo htmlspecialchars($o['phone']); ?></small>
            </div>
        </div>

        <div class="rider-block">
            <span class="rider-block-label"><i class="fa-solid fa-location-dot"></i> Deliver to</span>
            <p><?php echo nl2br(htmlspecialchars($o['delivery_address'] ?: 'No address on file')); ?></p>
            <?php if ($o['delivery_address']): ?>
                <a class="rider-map-link" href="<?php echo htmlspecialchars($maps); ?>" target="_blank" rel="noopener noreferrer">
                    Open in Google Maps <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($items): ?>
            <ul class="rider-item-list">
                <?php foreach ($items as $item): ?>
                    <li>
                        <span class="rider-item-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                        <span class="rider-item-qty"><?php echo htmlspecialchars($item['service_size']); ?> × <?php echo (int) $item['quantity']; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="rider-summary">
            <div>
                <small>Total</small>
                <strong>₱<?php echo number_format($total, 2); ?></strong>
            </div>
            <div>
                <small>Payment</small>
                <strong><?php echo $cash['is_cash'] ? 'Cash on delivery' : htmlspecialchars(ucfirst(strtolower((string) $o['payment_method']))); ?></strong>
            </div>
        </div>

        <?php if ($cash['is_cash']): ?>
            <div class="rider-cash<?php echo $cash['paid'] ? ' is-collected' : ''; ?>">
                <div class="rider-cash-row">
                    <span><i class="fa-solid fa-coins"></i> Collect</span>
                    <strong>₱<?php echo number_format($cash['declared'] > 0 ? $cash['declared'] : $total, 2); ?></strong>
                </div>
                <div class="rider-cash-row">
                    <span>Change</span>
                    <strong>₱<?php echo number_format($cash['change'], 2); ?></strong>
                </div>
                <div class="rider-cash-foot">
                    <?php if ($cash['paid']): ?>
                        <i class="fa-solid fa-circle-check"></i>
                        Cash collected<?php echo $cash['collected_at'] ? ' · ' . date('g:i A', strtotime($cash['collected_at'])) : ''; ?>
                    <?php else: ?>
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Not collected yet — required before delivery
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($history): ?>
            <div class="rider-proof-row">
                <?php if (!empty($o['has_proof'])): ?>
                    <a class="rider-proof-thumb" href="<?php echo htmlspecialchars($proofUrl); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo htmlspecialchars($proofUrl); ?>" alt="Delivery proof photo" loading="lazy" />
                        <span><i class="fa-solid fa-image"></i> View proof photo</span>
                    </a>
                <?php else: ?>
                    <div class="rider-proof-thumb is-empty">
                        <span><i class="fa-solid fa-image"></i> No proof photo on file</span>
                    </div>
                <?php endif; ?>
                <div class="rider-proof-meta">
                    <strong>Delivered<?php echo !empty($o['proof_captured_at']) ? ' · ' . date('M j, g:i A', strtotime($o['proof_captured_at'])) : ''; ?></strong>
                    <small><i class="fa-solid fa-lock"></i> Conversation ended</small>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$history): ?>
            <?php echo riderProgressSteps($status); ?>

            <div class="rider-actions">
                <?php if ($status === 'out_for_delivery'): ?>
                    <button
                        class="rider-btn success grow"
                        type="button"
                        data-rider-panel-open="<?php echo $panelId; ?>"
                        aria-controls="<?php echo $panelId; ?>"
                    ><i class="fa-solid fa-camera"></i> Mark delivered</button>
                <?php endif; ?>
                <?php if ($cash['is_cash'] && !$cash['paid'] && $status === 'out_for_delivery'): ?>
                    <button
                        class="rider-btn coins"
                        type="button"
                        data-rider-panel-open="<?php echo $panelId; ?>"
                        aria-controls="<?php echo $panelId; ?>"
                    ><i class="fa-solid fa-coins"></i> Collect cash</button>
                <?php endif; ?>
                <?php if ($status === 'processing'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="start_delivery" />
                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>" />
                        <button class="rider-btn primary grow" type="submit"><i class="fa-solid fa-bicycle"></i> Start delivery</button>
                    </form>
                <?php elseif ($status === 'pending'): ?>
                    <span class="rider-waiting"><i class="fa-solid fa-hourglass"></i> Waiting for the bakery to accept this order</span>
                <?php endif; ?>
                <a class="rider-btn outline" href="tel:<?php echo htmlspecialchars($o['phone']); ?>"><i class="fa-solid fa-phone"></i> Call</a>
                <a class="rider-btn outline" href="<?php echo BASE_URL; ?>/rider/chat.php?order=<?php echo $orderId; ?>"><i class="fa-solid fa-comments"></i> Message</a>
            </div>

            <?php if ($status === 'out_for_delivery'): ?>
                <div class="rider-panel" id="<?php echo $panelId; ?>"<?php echo $panelOpen ? '' : ' hidden'; ?>>
                    <div class="rider-panel-backdrop" data-rider-panel-close></div>
                    <div class="rider-panel-inner">
                        <div class="rider-panel-head">
                            <div>
                                <h3>Complete this delivery</h3>
                                <p>Order #<?php echo htmlspecialchars($o['reference_id']); ?></p>
                            </div>
                            <button class="rider-panel-close" type="button" data-rider-panel-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                        </div>

                        <?php if ($cash['is_cash']): ?>
                            <section class="rider-step<?php echo $cash['paid'] ? ' is-done' : ' is-todo'; ?>"<?php echo $cash['paid'] ? ' data-step="done"' : ''; ?>>
                                <div class="rider-step-head">
                                    <span class="rider-step-badge"><?php echo $cash['paid'] ? '1' : '1'; ?></span>
                                    <div>
                                        <strong>Collect cash</strong>
                                        <small>Cash on delivery · total ₱<?php echo number_format($total, 2); ?></small>
                                    </div>
                                </div>
                                <?php if ($cash['paid']): ?>
                                    <p class="rider-step-done">
                                        <i class="fa-solid fa-circle-check"></i>
                                        ₱<?php echo number_format($cash['received'], 2); ?> received · change ₱<?php echo number_format($cash['change'], 2); ?>
                                    </p>
                                <?php else: ?>
                                    <form method="POST" class="rider-cash-form">
                                        <input type="hidden" name="action" value="collect_cash" />
                                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>" />
                                        <label>
                                            <span>Cash received</span>
                                            <input
                                                type="number"
                                                name="amount_received"
                                                step="0.01"
                                                min="<?php echo htmlspecialchars(number_format($total, 2, '.', '')); ?>"
                                                value="<?php echo htmlspecialchars(number_format($cash['declared'] > 0 ? $cash['declared'] : $total, 2, '.', '')); ?>"
                                                inputmode="decimal"
                                                required
                                            />
                                        </label>
                                        <p class="rider-step-hint">Change to give back: <strong>₱<?php echo number_format($cash['change'], 2); ?></strong></p>
                                        <button class="rider-btn coins" type="submit"><i class="fa-solid fa-coins"></i> Confirm cash collected</button>
                                    </form>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <section class="rider-step<?php echo $cash['is_cash'] && !$cash['paid'] ? ' is-blocked' : ' is-todo'; ?>"<?php echo $cash['is_cash'] && !$cash['paid'] ? ' data-step="blocked"' : ''; ?>>
                            <div class="rider-step-head">
                                <span class="rider-step-badge"><?php echo $cash['is_cash'] ? '2' : '1'; ?></span>
                                <div>
                                    <strong>Delivery photo</strong>
                                    <small>Proof of handover is required</small>
                                </div>
                            </div>

                            <?php if ($cash['is_cash'] && !$cash['paid']): ?>
                                <p class="rider-step-blocked"><i class="fa-solid fa-lock"></i> Collect the cash first to unlock this step.</p>
                            <?php else: ?>
                                <form method="POST" enctype="multipart/form-data" class="rider-proof-form">
                                    <input type="hidden" name="action" value="mark_delivered" />
                                    <input type="hidden" name="order_id" value="<?php echo $orderId; ?>" />

                                    <label class="rider-drop" data-rider-drop for="rider-proof-<?php echo $orderId; ?>">
                                        <img class="rider-drop-preview" alt="Delivery photo preview" hidden />
                                        <span class="rider-drop-icon"><i class="fa-solid fa-camera"></i></span>
                                        <strong class="rider-drop-title">Take or upload a photo</strong>
                                        <small class="rider-drop-hint">JPG, PNG or WEBP · up to 5 MB</small>
                                        <input
                                            id="rider-proof-<?php echo $orderId; ?>"
                                            type="file"
                                            name="proof_photo"
                                            accept="image/jpeg,image/png,image/webp"
                                            capture="environment"
                                            required
                                        />
                                    </label>

                                    <label class="rider-note">
                                        <span>Handover note <em>(optional)</em></span>
                                        <input type="text" name="proof_note" maxlength="255" placeholder="Left with the gatehouse / received by Ana" />
                                    </label>

                                    <button class="rider-btn success grow" type="submit">
                                        <i class="fa-solid fa-circle-check"></i> Confirm delivery
                                    </button>
                                    <p class="rider-step-hint rider-step-hint-center">
                                        <i class="fa-solid fa-lock"></i> Confirming ends the chat with the customer.
                                    </p>
                                </form>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </article>
    <?php
}
?>

<div class="rider-hero">
    <span class="rider-chip">Rider portal</span>
    <h1>Your deliveries</h1>
    <p>Only orders assigned to you by bakery staff appear here. A delivery photo is required before you can close out a trip.</p>
</div>

<div class="rider-stats">
    <div class="rider-stat">
        <span class="rider-stat-icon is-way"><i class="fa-solid fa-bicycle"></i></span>
        <div><strong><?php echo $onTheWay; ?></strong><small>On the way</small></div>
    </div>
    <div class="rider-stat">
        <span class="rider-stat-icon"><i class="fa-solid fa-box"></i></span>
        <div><strong><?php echo $waiting; ?></strong><small>Waiting to start</small></div>
    </div>
    <div class="rider-stat">
        <span class="rider-stat-icon is-done"><i class="fa-solid fa-circle-check"></i></span>
        <div><strong><?php echo $deliveredCount; ?></strong><small>Delivered</small></div>
    </div>
    <div class="rider-stat">
        <span class="rider-stat-icon is-cash"><i class="fa-solid fa-coins"></i></span>
        <div><strong>₱<?php echo number_format($pendingCash, 2); ?></strong><small>Cash to collect</small></div>
    </div>
</div>

<?php if ($notice): ?>
    <div class="rider-notice <?php echo $noticeType; ?>">
        <i class="fa-solid <?php echo $noticeType === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
        <?php echo htmlspecialchars($notice); ?>
    </div>
<?php endif; ?>

<?php if (!$activeJobs): ?>
    <div class="rider-empty">
        <i class="fa-solid fa-bread-slice"></i>
        <h2>No assigned deliveries yet</h2>
        <p>When staff assigns a delivery to you, it will show up on this page.</p>
    </div>
<?php else: ?>
    <div class="rider-section-head">
        <h2>Active trips</h2>
        <span><?php echo count($activeJobs); ?></span>
    </div>
    <?php foreach ($activeJobs as $job): ?>
        <?php riderJobCard($job, $itemsMap[$job['id']] ?? []); ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($doneJobs): ?>
    <div class="rider-section-head">
        <h2>Recently delivered</h2>
        <span><?php echo count($doneJobs); ?></span>
    </div>
    <?php foreach (array_slice($doneJobs, 0, 8) as $job): ?>
        <?php riderJobCard($job, $itemsMap[$job['id']] ?? [], true); ?>
    <?php endforeach; ?>
<?php endif; ?>

<script>
(function () {
    var openPanel = function (id) {
        var panel = document.getElementById(id);
        if (!panel) return;
        panel.hidden = false;
        document.body.classList.add('rider-panel-open');
        var input = panel.querySelector('input[type="file"]');
        if (input) input.focus();
    };

    var closePanel = function (panel) {
        panel.hidden = true;
        if (!document.querySelector('.rider-panel:not([hidden])')) {
            document.body.classList.remove('rider-panel-open');
        }
    };

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-rider-panel-open]');
        if (opener) {
            event.preventDefault();
            openPanel(opener.getAttribute('data-rider-panel-open'));
            return;
        }
        var closer = event.target.closest('[data-rider-panel-close]');
        if (closer) {
            event.preventDefault();
            var open = closer.closest('.rider-panel');
            if (open) closePanel(open);
            return;
        }
        if (event.target.classList.contains('rider-panel-backdrop')) {
            closePanel(event.target);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        var open = document.querySelector('.rider-panel:not([hidden])');
        if (open) closePanel(open);
    });

    // Photo preview + label swap.
    document.addEventListener('change', function (event) {
        var input = event.target.closest('.rider-drop input[type="file"]');
        if (!input || !input.files || !input.files[0]) return;
        var drop = input.closest('.rider-drop');
        var preview = drop.querySelector('.rider-drop-preview');
        var title = drop.querySelector('.rider-drop-title');
        var hint = drop.querySelector('.rider-drop-hint');
        var file = input.files[0];
        if (title) title.textContent = file.name;
        if (hint) hint.textContent = (file.size / 1048576).toFixed(1) + ' MB · ready to upload';
        if (preview && window.FileReader) {
            var reader = new FileReader();
            reader.onload = function (e) {
                preview.src = e.target.result;
                preview.hidden = false;
                drop.classList.add('has-image');
            };
            reader.readAsDataURL(file);
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/rider_footer.php'; ?>
