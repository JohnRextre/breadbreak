<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();

// ── Customer reply to a conversation ─────────────────────────────
$contactReplyError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact_customer_reply') {
    $contactMessageId = (int) ($_POST['message_id'] ?? 0);
    $contactReplyBody = trim((string) ($_POST['reply_body'] ?? ''));
    if ($contactReplyBody === '' || mb_strlen($contactReplyBody) < 2) {
        $contactReplyError = 'Please write a message first.';
    } else {
        require_once __DIR__ . '/../includes/contact_messages.php';
        ensureContactMessageTables($pdo);
        $email = (string) ($_SESSION['email'] ?? '');
        $contactReplyMatch = $pdo->prepare('SELECT id, name FROM contact_messages WHERE id = :id AND email = :email LIMIT 1');
        $contactReplyMatch->execute(['id' => $contactMessageId, 'email' => $email]);
        $matchedMessage = $contactReplyMatch->fetch();
        if ($matchedMessage) {
            $customerName = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? '')) ?: (string) $matchedMessage['name'];
            $pdo->prepare("INSERT INTO contact_replies (message_id, reply_body, admin_name, sender) VALUES (:id, :body, :name, 'customer')")
                ->execute(['id' => $contactMessageId, 'body' => $contactReplyBody, 'name' => $customerName]);
            $pdo->prepare("UPDATE contact_messages SET status = 'new' WHERE id = :id")->execute(['id' => $contactMessageId]);
            header('Location: /BreadBreak/customer/messages.php?thread=' . $contactMessageId);
            exit;
        }
        $contactReplyError = 'We could not find that message.';
    }
}

/* ── Customer contact messages + admin replies ────────────────── */
$customerMessages = [];
try {
    require_once __DIR__ . '/../includes/contact_messages.php';
    ensureContactMessageTables($pdo);
    $email = (string) ($_SESSION['email'] ?? '');
    $fullname = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
    $messagesStmt = $pdo->prepare('SELECT * FROM contact_messages WHERE email = :email OR name = :fullname ORDER BY created_at DESC, id DESC');
    $messagesStmt->execute(['email' => $email, 'fullname' => $fullname]);
    $customerMessages = $messagesStmt->fetchAll() ?: [];

    foreach ($customerMessages as &$customerMessageRow) {
        $repliesStmt = $pdo->prepare('SELECT * FROM contact_replies WHERE message_id = :id ORDER BY id ASC');
        $repliesStmt->execute(['id' => (int) $customerMessageRow['id']]);
        $customerMessageRow['replies'] = $repliesStmt->fetchAll() ?: [];
    }
    unset($customerMessageRow);
} catch (Throwable $e) {
    $customerMessages = [];
}

$selectedThreadId = (int) ($_GET['thread'] ?? 0);
$hasSelected = false;
foreach ($customerMessages as $customerMessageRow) {
    if ((int) $customerMessageRow['id'] === $selectedThreadId) { $hasSelected = true; break; }
}
if (!$hasSelected && $customerMessages) {
    $selectedThreadId = (int) $customerMessages[0]['id'];
}

$pageTitle = 'Messages | BreadBreak';
$fullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$profileInitial = strtoupper(substr(trim((string) ($_SESSION['first_name'] ?? '')), 0, 1)) ?: '?';

require __DIR__ . '/../includes/header.php';
?>

<main class="messages-page">
    <div class="messages-head">
        <div>
            <span class="eyebrow">Support</span>
            <h1>Messages</h1>
            <p>Chat with the BreadBreak team — replies show up here.</p>
        </div>
        <a class="btn btn-primary" href="/BreadBreak/contact.php"><i class="fa-solid fa-plus"></i> New message</a>
    </div>

    <?php if ($contactReplyError): ?>
    <p class="account-form-error" role="alert"><?php echo htmlspecialchars($contactReplyError); ?></p>
    <?php endif; ?>

    <?php if (!$customerMessages): ?>
    <div class="shop-empty">
        <i class="fa-solid fa-envelope-open-text"></i>
        <p>No messages yet. Send us one through the Contact page.</p>
        <a class="btn btn-primary" href="/BreadBreak/contact.php"><i class="fa-solid fa-paper-plane"></i> Send a message</a>
    </div>
    <?php else: ?>
    <div class="messages-app">
        <aside class="messages-list">
            <?php foreach ($customerMessages as $customerMessageRow):
                $msgStatus = ($customerMessageRow['status'] ?? 'new') === 'replied' ? 'replied' : (($customerMessageRow['status'] ?? 'new') === 'ended' ? 'ended' : 'new');
                $msgStatusLabel = $msgStatus === 'replied' ? 'Replied' : ($msgStatus === 'ended' ? 'Ended' : 'New');
            ?>
            <button type="button" class="messages-item<?php echo ((int) $customerMessageRow['id'] === $selectedThreadId) ? ' active' : ''; ?>"
                data-thread-id="<?php echo (int) $customerMessageRow['id']; ?>"
                data-subject="<?php echo htmlspecialchars($customerMessageRow['subject']); ?>"
                data-date="<?php echo date('M j, Y · g:i A', strtotime($customerMessageRow['created_at'])); ?>"
                data-time="<?php echo date('g:i A', strtotime($customerMessageRow['created_at'])); ?>"
                data-status="<?php echo htmlspecialchars($msgStatusLabel); ?>"
                data-message="<?php echo htmlspecialchars($customerMessageRow['message']); ?>"
                data-replies="<?php echo htmlspecialchars(json_encode($customerMessageRow['replies'] ?? []), ENT_QUOTES); ?>">
                <span class="messages-avatar"><?php echo htmlspecialchars(strtoupper(substr((string) $customerMessageRow['subject'], 0, 1))); ?></span>
                <span class="messages-item-body">
                    <span class="messages-item-top">
                        <strong><?php echo htmlspecialchars($customerMessageRow['subject']); ?></strong>
                        <small><?php echo date('M j', strtotime($customerMessageRow['created_at'])); ?></small>
                    </span>
                    <span class="messages-item-preview"><?php echo htmlspecialchars(mb_strimwidth((string) $customerMessageRow['message'], 0, 46, '…')); ?></span>
                    <span class="messages-item-status is-<?php echo $msgStatus; ?>"><?php echo $msgStatusLabel; ?></span>
                </span>
            </button>
            <?php endforeach; ?>
        </aside>

        <section class="messages-pane">
            <header class="messages-pane-head">
                <strong class="messages-pane-subject"></strong>
                <span class="messages-pane-status"></span>
            </header>
            <div class="messages-thread"></div>
            <form class="messages-composer" method="POST" action="/BreadBreak/customer/messages.php">
                <input type="hidden" name="action" value="contact_customer_reply" />
                <input type="hidden" name="message_id" class="messages-composer-id" value="0" />
                <textarea name="reply_body" rows="1" maxlength="500" placeholder="Type your reply…" required></textarea>
                <button type="submit" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
        </section>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var items = Array.prototype.slice.call(document.querySelectorAll('.messages-item'));
    var threadBox = document.querySelector('.messages-thread');
    var paneSubject = document.querySelector('.messages-pane-subject');
    var paneStatus = document.querySelector('.messages-pane-status');
    var composerId = document.querySelector('.messages-composer-id');
    if (!items.length || !threadBox) return;

    function esc(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function renderThread(item) {
        var replies = [];
        try { replies = JSON.parse(item.getAttribute('data-replies') || '[]'); } catch (e) { replies = []; }
        var html = '<div class="msg-row is-mine"><div class="msg-bubble">' + esc(item.getAttribute('data-message')) +
            '<time>' + esc(item.getAttribute('data-time')) + '</time></div></div>';
        replies.forEach(function (r) {
            var mine = r.sender !== 'admin';
            html += '<div class="msg-row ' + (mine ? 'is-mine' : 'is-theirs') + '"><div class="msg-bubble">' +
                '<span class="msg-name">' + esc(mine ? 'You' : 'Admin') + '</span>' +
                esc(r.reply_body) + '<time>' + esc(r.created_at) + '</time></div></div>';
        });
        threadBox.innerHTML = html;
        threadBox.scrollTop = threadBox.scrollHeight;
        paneSubject.textContent = item.getAttribute('data-subject') || '';
        var status = item.getAttribute('data-status') || 'New';
        paneStatus.textContent = status + (status === 'Ended' ? ' — closed by BreadBreak. You can still reply to reopen.' : '');
        composerId.value = item.getAttribute('data-thread-id') || '0';
    }

    items.forEach(function (item) {
        item.addEventListener('click', function () {
            items.forEach(function (other) { other.classList.remove('active'); });
            item.classList.add('active');
            renderThread(item);
        });
    });

    var firstActive = document.querySelector('.messages-item.active') || items[0];
    renderThread(firstActive);

    var composerTextarea = document.querySelector('.messages-composer textarea');
    if (composerTextarea) {
        composerTextarea.addEventListener('input', function () {
            composerTextarea.style.height = 'auto';
            composerTextarea.style.height = Math.min(composerTextarea.scrollHeight, 120) + 'px';
        });
    }
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
