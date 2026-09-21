<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';

$pdo = getDatabaseConnection();
ensureRiderSupport($pdo);

$customerId = (int) $_SESSION['user_id'];
$ref = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
$chatError = '';

$lookup = $pdo->prepare('SELECT id FROM orders WHERE reference_id = :ref AND customer_id = :cid LIMIT 1');
$lookup->execute(['ref' => $ref, 'cid' => $customerId]);
$orderId = (int) ($lookup->fetchColumn() ?: 0);
$order = $orderId ? deliveryChatOrder($pdo, $orderId) : null;

if (!$order || (int) $order['customer_id'] !== $customerId || empty($order['rider_id']) || ($order['fulfillment_type'] ?? '') !== 'delivery') {
    header('Location: /BreadBreak/customer/order-status.php');
    exit;
}

$messages = deliveryMessages($pdo, $orderId);
$riderStarted = deliveryRiderHasMessaged($messages);
$chatLocked = in_array($order['status'], ['cancelled'], true);
$canReply = $riderStarted && !$chatLocked;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    if (!$canReply) {
        $chatError = 'Please wait for your rider to send the first message.';
    } else {
        $chatError = sendDeliveryMessage($pdo, $orderId, $customerId, 'customer', (string) ($_POST['body'] ?? ''));
        if ($chatError === '') {
            header('Location: /BreadBreak/customer/chat.php?ref=' . urlencode($ref));
            exit;
        }
    }
    $messages = deliveryMessages($pdo, $orderId);
}

$riderName = trim(($order['rider_first'] ?? '') . ' ' . ($order['rider_last'] ?? '')) ?: 'Your rider';
$riderInitial = strtoupper(substr($riderName, 0, 1)) ?: 'R';
$riderPhoto = userPhotoDataUri($order['rider_photo'] ?? null, $order['rider_mime'] ?? null);

$pageTitle = 'Message rider | BreadBreak';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/BreadBreak/assets/css/rider.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/rider.css'); ?>" />

<main class="customer-chat-page">
<section class="chat-app is-customer">
    <header class="chat-head">
        <a class="chat-back" href="/BreadBreak/customer/order-status.php?ref=<?php echo urlencode($ref); ?>" aria-label="Back to order status"><i class="fa-solid fa-chevron-left"></i></a>
        <div class="chat-person">
            <?php if ($riderPhoto): ?>
                <img src="<?php echo htmlspecialchars($riderPhoto); ?>" alt="" class="chat-avatar-img" />
            <?php else: ?>
                <span class="chat-avatar"><?php echo htmlspecialchars($riderInitial); ?></span>
            <?php endif; ?>
            <div>
                <strong><?php echo htmlspecialchars($riderName); ?></strong>
                <small>Order #<?php echo htmlspecialchars($order['reference_id']); ?> · BreadBreak rider</small>
            </div>
        </div>
        <?php if (!empty($order['rider_phone'])): ?>
            <a class="chat-call" href="tel:<?php echo htmlspecialchars($order['rider_phone']); ?>" aria-label="Call rider"><i class="fa-solid fa-phone"></i></a>
        <?php endif; ?>
    </header>

    <div class="chat-thread" id="chat-thread">
        <div class="chat-system">
            <?php if ($riderStarted): ?>
                You can reply to your rider about this delivery.
            <?php else: ?>
                Your rider will start this chat. You’ll be able to reply after their first message.
            <?php endif; ?>
        </div>
        <?php if (!$messages): ?>
            <div class="chat-empty">
                <span class="chat-avatar lg"><?php echo htmlspecialchars($riderInitial); ?></span>
                <strong><?php echo htmlspecialchars($riderName); ?></strong>
                <p>Waiting for your rider to say hello.</p>
            </div>
        <?php endif; ?>
        <?php foreach ($messages as $message):
            $mine = $message['sender_role'] === 'customer';
        ?>
            <div class="chat-row <?php echo $mine ? 'is-mine' : 'is-theirs'; ?>">
                <div class="chat-bubble">
                    <?php echo nl2br(htmlspecialchars($message['body'])); ?>
                    <time><?php echo date('g:i A', strtotime($message['created_at'])); ?></time>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($chatError): ?><p class="chat-error"><?php echo htmlspecialchars($chatError); ?></p><?php endif; ?>

    <?php if ($chatLocked): ?>
        <p class="chat-locked">This order was cancelled. Chat is closed.</p>
    <?php elseif (!$canReply): ?>
        <p class="chat-locked">Waiting for your rider to send the first message.</p>
    <?php else: ?>
        <form class="chat-composer" method="POST">
            <input type="hidden" name="action" value="send_message" />
            <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>" />
            <textarea name="body" rows="1" maxlength="500" placeholder="Reply to your rider…" required></textarea>
            <button type="submit" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    <?php endif; ?>
</section>
</main>

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
</body>
</html>
