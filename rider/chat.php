<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('rider');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';

$pdo = getDatabaseConnection();
ensureRiderSupport($pdo);

$riderId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['order'] ?? $_POST['order_id'] ?? 0);
$chatError = '';

$order = $orderId > 0 ? deliveryChatOrder($pdo, $orderId) : null;
if (!$order || (int) $order['rider_id'] !== $riderId || ($order['fulfillment_type'] ?? '') !== 'delivery') {
    header('Location: ' . BASE_URL . '/rider/dashboard.php');
    exit;
}

$chatLocked = deliveryChatClosed($order);
$chatClosedReason = deliveryChatClosedReason($order);
$hasProof = !empty($order['has_proof']);
$proofUrl = BASE_URL . '/api/delivery-proof.php?order=' . $orderId;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message' && !$chatLocked) {
    $chatError = sendDeliveryMessage($pdo, $orderId, $riderId, 'rider', (string) ($_POST['body'] ?? ''));
    if ($chatError === '') {
        header('Location: ' . BASE_URL . '/rider/chat.php?order=' . $orderId);
        exit;
    }
}

$messages = deliveryMessages($pdo, $orderId);
$customerName = trim($order['customer_first'] . ' ' . $order['customer_last']);
$customerInitial = strtoupper(substr($customerName, 0, 1)) ?: '?';
$customerPhoto = userPhotoDataUri($order['customer_photo'] ?? null, $order['customer_mime'] ?? null);
$quickReplies = [
    "Hi {$order['customer_first']}! I'm your BreadBreak rider. I'll message you with updates on your order.",
    "I'm on the way with your breads. Please keep your phone nearby.",
    "I've arrived. Please meet me at the gate or reply with landmarks.",
];

$riderChatMode = true;
$pageTitle = 'Message ' . $customerName . ' | BreadBreak';
require __DIR__ . '/../includes/rider_header.php';
?>

<section class="chat-app">
    <header class="chat-head">
        <a class="chat-back" href="<?php echo BASE_URL; ?>/rider/dashboard.php" aria-label="Back to deliveries"><i class="fa-solid fa-chevron-left"></i></a>
        <div class="chat-person">
            <?php if ($customerPhoto): ?>
                <img src="<?php echo htmlspecialchars($customerPhoto); ?>" alt="" class="chat-avatar-img" />
            <?php else: ?>
                <span class="chat-avatar"><?php echo htmlspecialchars($customerInitial); ?></span>
            <?php endif; ?>
            <div>
                <strong><?php echo htmlspecialchars($customerName); ?></strong>
                <small>Order #<?php echo htmlspecialchars($order['reference_id']); ?> · Customer</small>
            </div>
        </div>
        <a class="chat-call" href="tel:<?php echo htmlspecialchars($order['customer_phone']); ?>" aria-label="Call customer"><i class="fa-solid fa-phone"></i></a>
    </header>

    <div class="chat-thread" id="chat-thread">
        <div class="chat-system">
            <?php if ($chatLocked): ?>
                Chat closed — this delivery is <?php echo $order['status'] === 'cancelled' ? 'cancelled' : 'completed'; ?>.
            <?php else: ?>
                Chat opened for this delivery. Send the first message — the customer can reply after that.
            <?php endif; ?>
        </div>
        <?php if (!$messages): ?>
            <div class="chat-empty">
                <span class="chat-avatar lg"><?php echo htmlspecialchars($customerInitial); ?></span>
                <strong><?php echo htmlspecialchars($customerName); ?></strong>
                <p>Say hello and share an ETA, a landmark, or cash-on-delivery notes.</p>
            </div>
        <?php endif; ?>
        <?php foreach ($messages as $message):
            $mine = $message['sender_role'] === 'rider';
        ?>
            <div class="chat-row <?php echo $mine ? 'is-mine' : 'is-theirs'; ?>">
                <div class="chat-bubble">
                    <?php echo nl2br(htmlspecialchars($message['body'])); ?>
                    <time><?php echo date('g:i A', strtotime($message['created_at'])); ?></time>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($chatLocked && $hasProof): ?>
            <div class="chat-row is-theirs">
                <div class="chat-proof">
                    <a href="<?php echo htmlspecialchars($proofUrl); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo htmlspecialchars($proofUrl); ?>" alt="Delivery proof photo" loading="lazy" />
                    </a>
                    <span><i class="fa-solid fa-camera"></i> Delivery proof<?php echo $order['proof_captured_at'] ? ' · ' . date('M j, g:i A', strtotime($order['proof_captured_at'])) : ''; ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($chatError): ?><p class="chat-error"><?php echo htmlspecialchars($chatError); ?></p><?php endif; ?>

    <?php if ($chatLocked): ?>
        <p class="chat-locked"><i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars($chatClosedReason); ?></p>
    <?php else: ?>
        <?php if (!$messages): ?>
            <div class="chat-quick">
                <?php foreach ($quickReplies as $reply): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="send_message" />
                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>" />
                        <input type="hidden" name="body" value="<?php echo htmlspecialchars($reply, ENT_QUOTES); ?>" />
                        <button type="submit"><?php echo htmlspecialchars($reply); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form class="chat-composer" method="POST">
            <input type="hidden" name="action" value="send_message" />
            <input type="hidden" name="order_id" value="<?php echo $orderId; ?>" />
            <textarea name="body" rows="1" maxlength="500" placeholder="Message <?php echo htmlspecialchars($order['customer_first']); ?>…" required></textarea>
            <button type="submit" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    <?php endif; ?>
</section>

<script>
(function () {
    var thread = document.getElementById('chat-thread');
    if (thread) thread.scrollTop = thread.scrollHeight;
    setTimeout(function () {
        if (document.querySelector('.chat-composer textarea') !== document.activeElement) {
            window.location.reload();
        }
    }, 8000);
})();
</script>
<?php require __DIR__ . '/../includes/rider_footer.php'; ?>
