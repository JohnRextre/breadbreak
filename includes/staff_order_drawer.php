<?php
/**
 * Detail drawer for a staff order. Expects in scope:
 *   $orders, $orderIds / $itemsMap, $historyMap, $riders, $cancelReasons
 * Renders one hidden drawer per order; staff/orders.php and staff/order-history.php
 * both include this so the two screens always show the same detail view.
 */
if (!isset($orders)) {
    return;
}

$drawerIndex = 'hx';
?>
<?php foreach ($orders as $o):
    $oid = (int) $o['id'];
    $items = $itemsMap[$oid] ?? [];
    $trail = $historyMap[$oid] ?? [];
    $ordStatus = strtolower((string) $o['order_status']);
    $fulfillment = $o['fulfillment_type'] ?? 'delivery';
    $channel = (string) ($o['payment_channel'] ?? '');
    $isCash = orderIsCashPayment($o['payment_method'] ?? '', $channel);
    $payLabel = orderPaymentLabel($o['payment_method'] ?? '', $channel, $fulfillment);
    $payStatus = strtolower((string) ($o['payment_status'] ?? 'pending'));
    $chip = orderStatusChip($ordStatus, $fulfillment);
    $customerName = trim($o['first_name'] . ' ' . $o['last_name']);
    $riderName = !empty($o['rider_id']) ? trim(($o['rider_first'] ?? '') . ' ' . ($o['rider_last'] ?? '')) : '';
    $action = orderNextAction($o, $riders);
    $cancellable = canStaffCancel($o);
    $total = (float) $o['total_amount'];
    $cashCollected = $o['collected_amount'] !== null ? (float) $o['collected_amount'] : null;
    $proofUrl = BASE_URL . '/api/delivery-proof.php?order=' . $oid;
?>
<div class="order-drawer" id="order-drawer-<?php echo $oid; ?>" hidden>
    <div class="order-drawer-backdrop" data-order-drawer-close></div>
    <aside class="order-drawer-panel" role="dialog" aria-modal="true" aria-label="Order #<?php echo htmlspecialchars($o['reference_id']); ?> details">
        <header class="order-drawer-head">
            <div>
                <span class="order-drawer-kicker">Order #<?php echo htmlspecialchars($o['reference_id']); ?></span>
                <h3><?php echo htmlspecialchars($customerName); ?></h3>
                <p><?php echo date('M j, Y · g:i A', strtotime($o['created_at'])); ?> · <?php echo $fulfillment === 'pickup' ? 'Store pickup' : 'Delivery'; ?></p>
            </div>
            <div class="order-drawer-head-right">
                <span class="status-chip <?php echo $chip[0]; ?>"><i class="fa-solid fa-<?php echo $chip[1]; ?>"></i> <?php echo $chip[2]; ?></span>
                <button class="order-drawer-close" type="button" data-order-drawer-close aria-label="Close details">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </header>

        <div class="order-drawer-body">
            <section class="drawer-block">
                <h4 class="drawer-block-title"><i class="fa-solid fa-timeline"></i> Activity Trail</h4>
                <ol class="audit-trail">
                    <?php foreach (array_reverse($trail) as $entry): ?>
                        <li class="audit-entry is-<?php echo htmlspecialchars((string) $entry['to_status']); ?>">
                            <span class="audit-dot"><i class="fa-solid fa-<?php echo htmlspecialchars(orderHistoryIcon((string) $entry['to_status'], $entry['from_status'])); ?>"></i></span>
                            <div class="audit-content">
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
                        <li class="audit-entry">
                            <span class="audit-dot"><i class="fa-solid fa-receipt"></i></span>
                            <div class="audit-content"><strong>No activity recorded yet.</strong></div>
                        </li>
                    <?php endif; ?>
                </ol>
            </section>

            <section class="drawer-block">
                <h4 class="drawer-block-title"><i class="fa-solid fa-basket-shopping"></i> Items</h4>
                <div class="drawer-items">
                    <?php foreach ($items as $item): ?>
                        <div class="drawer-item">
                            <div>
                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                <small><?php echo htmlspecialchars($item['service_size']); ?> · <?php echo htmlspecialchars($item['sku']); ?></small>
                            </div>
                            <div class="drawer-item-num">
                                <span>× <?php echo (int) $item['quantity']; ?></span>
                                <strong>₱<?php echo number_format((float) $item['line_total'], 2); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="drawer-totals">
                    <div><span>Subtotal</span><strong>₱<?php echo number_format((float) $o['subtotal'], 2); ?></strong></div>
                    <?php if ((float) $o['vat_amount'] > 0): ?>
                        <div><span>VAT</span><strong>₱<?php echo number_format((float) $o['vat_amount'], 2); ?></strong></div>
                    <?php endif; ?>
                    <?php if ((float) $o['vat_exempt_sales'] > 0): ?>
                        <div><span>VAT-exempt sales</span><strong>₱<?php echo number_format((float) $o['vat_exempt_sales'], 2); ?></strong></div>
                    <?php endif; ?>
                    <?php if ((float) $o['discount_amount'] > 0): ?>
                        <div class="is-discount">
                            <span>
                                Discount<?php echo !empty($o['discount_name']) ? ' · ' . htmlspecialchars($o['discount_name']) : ''; ?>
                                <?php if (!empty($o['discount_id_number'])): ?>
                                    <em>ID <?php echo htmlspecialchars($o['discount_id_number']); ?></em>
                                <?php endif; ?>
                            </span>
                            <strong>−₱<?php echo number_format((float) $o['discount_amount'], 2); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ((float) $o['delivery_fee'] > 0): ?>
                        <div><span>Delivery fee</span><strong>₱<?php echo number_format((float) $o['delivery_fee'], 2); ?></strong></div>
                    <?php endif; ?>
                    <div class="is-total"><span>Total</span><strong>₱<?php echo number_format($total, 2); ?></strong></div>
                </div>
            </section>

            <section class="drawer-block">
                <h4 class="drawer-block-title"><i class="fa-solid fa-receipt"></i> Payment &amp; Delivery</h4>
                <dl class="drawer-fields">
                    <div>
                        <dt>Method</dt>
                        <dd>
                            <span class="drawer-pay-method <?php echo $isCash ? 'is-cash' : 'is-online'; ?>">
                                <i class="fa-solid <?php echo $isCash ? 'fa-money-bill-wave' : 'fa-mobile-screen'; ?>"></i>
                                <?php echo htmlspecialchars($payLabel); ?>
                            </span>
                        </dd>
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
                    <?php if ($isCash && (float) $o['cash_amount'] > 0): ?>
                        <div>
                            <dt>Customer will hand over</dt>
                            <dd>₱<?php echo number_format((float) $o['cash_amount'], 2); ?></dd>
                        </div>
                        <div>
                            <dt>Change to return</dt>
                            <dd>₱<?php echo number_format(max(0, (float) $o['cash_amount'] - $total), 2); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($cashCollected !== null): ?>
                        <div>
                            <dt>Cash collected by rider</dt>
                            <dd>₱<?php echo number_format($cashCollected, 2); ?><?php echo $o['collected_at'] ? ' · ' . date('M d, g:i A', strtotime($o['collected_at'])) : ''; ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($o['proof_captured_at'])): ?>
                        <div>
                            <dt>Proof photo</dt>
                            <dd>
                                <a class="drawer-proof-link" href="<?php echo htmlspecialchars($proofUrl); ?>" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-image"></i> View photo
                                </a>
                                <small class="drawer-field-note"><?php echo date('M d, Y g:i A', strtotime($o['proof_captured_at'])); ?></small>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($o['proof_note'])): ?>
                        <div>
                            <dt>Handover note</dt>
                            <dd><?php echo htmlspecialchars($o['proof_note']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($fulfillment !== 'pickup' && !empty($o['delivery_address'])): ?>
                        <div class="is-wide">
                            <dt>Delivery address</dt>
                            <dd><?php echo nl2br(htmlspecialchars($o['delivery_address'])); ?>
                                <a class="drawer-proof-link" style="margin-top:6px;"
                                   href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo urlencode((string) $o['delivery_address']); ?>"
                                   target="_blank" rel="noopener"><i class="fa-solid fa-map"></i> Open in Maps</a>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($fulfillment !== 'pickup'): ?>
                        <div>
                            <dt>Rider</dt>
                            <dd>
                                <?php if ($riderName !== ''): ?>
                                    <?php echo htmlspecialchars($riderName); ?>
                                    <?php if (!empty($o['rider_phone'])): ?>
                                        <a class="drawer-proof-link" style="margin-left:8px;" href="tel:<?php echo htmlspecialchars($o['rider_phone']); ?>">
                                            <i class="fa-solid fa-phone"></i> Call
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="drawer-muted">Not assigned yet</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($o['review_acknowledged_at'])): ?>
                        <div>
                            <dt>Customer review</dt>
                            <dd>
                                <?php if (!empty($o['has_review'])): ?>
                                    <span class="drawer-muted">Submitted</span>
                                <?php else: ?>
                                    <span class="drawer-muted">Received, not yet rated</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($o['notes'])): ?>
                        <div class="is-wide">
                            <dt>Order notes</dt>
                            <dd><?php echo nl2br(htmlspecialchars($o['notes'])); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>

            <?php if (!in_array($ordStatus, closedOrderStatuses(), true)): ?>
                <section class="drawer-block">
                    <h4 class="drawer-block-title"><i class="fa-solid fa-bolt"></i> Actions</h4>
                    <div class="drawer-actions">
                        <?php if ($action['kind'] === 'accept'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="accept_order" />
                                <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                <button class="order-action-btn is-primary" type="submit">
                                    <i class="fa-solid fa-<?php echo $action['icon']; ?>"></i> <?php echo $action['label']; ?>
                                </button>
                            </form>
                        <?php elseif ($action['kind'] === 'rider' && $fulfillment === 'delivery'): ?>
                            <form method="POST" class="drawer-rider-form">
                                <input type="hidden" name="action" value="assign_rider" />
                                <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                <select name="rider_id" required>
                                    <option value="">Choose a rider…</option>
                                    <?php foreach ($riders as $rider): ?>
                                        <option value="<?php echo (int) $rider['id']; ?>"><?php echo htmlspecialchars(riderDisplayName($rider)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="order-action-btn is-primary" type="submit">
                                    <i class="fa-solid fa-user-plus"></i> Assign &amp; dispatch
                                </button>
                            </form>
                        <?php elseif ($action['kind'] === 'rider'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="send_on_the_way" />
                                <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                <button class="order-action-btn is-primary" type="submit">
                                    <i class="fa-solid fa-store"></i> Mark ready for pickup
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
                            <p class="drawer-hint"><i class="fa-solid fa-circle-info"></i> <?php echo htmlspecialchars($action['hint']); ?></p>
                        <?php endif; ?>

                        <?php if ($fulfillment === 'delivery' && $riderName !== ''): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="assign_rider" />
                                <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                <select name="rider_id" required>
                                    <option value="">Reassign rider…</option>
                                    <?php foreach ($riders as $rider): ?>
                                        <option value="<?php echo (int) $rider['id']; ?>"><?php echo htmlspecialchars(riderDisplayName($rider)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="order-action-btn is-ghost" type="submit">Reassign</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($cancellable): ?>
                            <form method="POST" class="drawer-cancel-form" data-cancel-form>
                                <input type="hidden" name="action" value="cancel_order" />
                                <input type="hidden" name="order_id" value="<?php echo $oid; ?>" />
                                <select name="reason" required>
                                    <option value="">Cancel — pick a reason…</option>
                                    <?php foreach ($cancelReasons as $reason): ?>
                                        <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="order-action-btn is-danger" type="submit">
                                    <i class="fa-solid fa-ban"></i> Cancel order
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="drawer-note">
                                <i class="fa-solid fa-lock"></i>
                                <?php echo htmlspecialchars(staffCancelBlockedReason($o)); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="drawer-block">
                    <h4 class="drawer-block-title"><i class="fa-solid fa-flag-checkered"></i> Closed</h4>
                    <p class="drawer-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        <?php echo $ordStatus === 'completed'
                            ? 'Delivered and closed. The chat ended automatically and the proof photo is on file.'
                            : 'This order was cancelled and can no longer be changed.'; ?>
                    </p>
                </section>
            <?php endif; ?>
        </div>
    </aside>
</div>
<?php endforeach; ?>
