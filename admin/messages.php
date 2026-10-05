<?php
// =============================================================================
// admin/messages.php  –  Contact message inbox
// Every message sent through the public contact form lands here.
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();
require_once __DIR__ . '/../includes/contact_messages.php';
ensureContactMessageTables($pdo);

/* ── Actions: delete / reply ───────────────────────────────────────────── */
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messageId = (int) ($_POST['message_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete_message' && $messageId > 0) {
        $pdo->prepare('DELETE FROM contact_messages WHERE id = :id')->execute(['id' => $messageId]);
        $pdo->prepare('DELETE FROM contact_replies WHERE message_id = :id')->execute(['id' => $messageId]);
        $notice = 'Message deleted.';
    } elseif (($_POST['action'] ?? '') === 'reply_message' && $messageId > 0) {
        $replyBody = trim((string) ($_POST['reply_body'] ?? ''));
        if (mb_strlen($replyBody) < 2) {
            $notice = 'Error: Please write a reply first.';
        } else {
            $target = $pdo->prepare('SELECT * FROM contact_messages WHERE id = :id');
            $target->execute(['id' => $messageId]);
            $targetRow = $target->fetch() ?: null;
            if ($targetRow) {
                $adminName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin';
                $pdo->prepare('INSERT INTO contact_replies (message_id, reply_body, admin_name) VALUES (:id, :body, :admin)')
                    ->execute(['id' => $messageId, 'body' => $replyBody, 'admin' => $adminName]);
                $pdo->prepare("UPDATE contact_messages SET status = 'replied' WHERE id = :id")->execute(['id' => $messageId]);
                // Best-effort email delivery — the reply is saved regardless,
                // so it still shows in the customer's account inbox.
                @mail((string) $targetRow['email'], 'Re: ' . (string) $targetRow['subject'], $replyBody);
                $notice = 'Reply sent and recorded.';
            } else {
                $notice = 'Error: that message could not be found.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'end_conversation' && $messageId > 0) {
        $pdo->prepare("UPDATE contact_messages SET status = 'ended' WHERE id = :id")->execute(['id' => $messageId]);
        $notice = 'Conversation marked as ended.';
    } else {
        $notice = 'Error: that message could not be found.';
    }
}

/* ── Search & sort ─────────────────────────────────────────────────────── */
$search = trim((string) ($_GET['search'] ?? ''));
$sort   = (string) ($_GET['sort'] ?? 'newest');
$sortOptions = [
    'newest' => 'Sort: Newest',
    'oldest' => 'Sort: Oldest',
    'name'   => 'Sort: Name (A–Z)',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}
$orderBy = match ($sort) {
    'oldest' => 'created_at ASC, id ASC',
    'name'   => 'name ASC',
    default  => 'created_at DESC, id DESC',
};

$searchLike = '%' . $search . '%';
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'new', 'replied', 'ended'], true)) {
    $statusFilter = 'all';
}
$sql = "SELECT * FROM contact_messages WHERE (name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?)";
$params = [$searchLike, $searchLike, $searchLike, $searchLike];
if ($statusFilter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY {$orderBy} LIMIT 200";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$messages = $statement->fetchAll();

$totalMessages  = (int) $pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
$newMessages    = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();
$repliedMessages = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'replied'")->fetchColumn();
$endedMessages   = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'ended'")->fetchColumn();

/* Replies grouped by message, for the inbox thread view. */
$repliesByMessage = [];
foreach ($pdo->query('SELECT * FROM contact_replies ORDER BY id ASC')->fetchAll() as $replyRow) {
    $repliesByMessage[(int) $replyRow['message_id']][] = $replyRow;
}

$pageTitle = 'Contact Messages';
$activePage = 'messages';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro page-intro-hero">
    <div>
        <h2>Contact Messages</h2>
        <p>Every message customers and visitors send through the contact form.</p>
    </div>
</section>

<?php if ($notice !== ''): ?>
<div class="admin-notice <?php echo str_starts_with($notice, 'Error:') ? 'danger' : 'success'; ?>">
    <i class="fa-solid <?php echo str_starts_with($notice, 'Error:') ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
    <?php echo htmlspecialchars(preg_replace('/^Error:\s*/i', '', $notice)); ?>
</div>
<?php endif; ?>

<section class="summary-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
    <div class="summary-card">
        <div class="summary-top"><span>TOTAL MESSAGES</span><span class="summary-icon"><i class="fa-solid fa-envelope"></i></span></div>
        <div class="summary-value"><?php echo $totalMessages; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top"><span>SHOWN</span><span class="summary-icon"><i class="fa-solid fa-filter"></i></span></div>
        <div class="summary-value"><?php echo count($messages); ?></div>
    </div>
</section>

<div class="mod-tabs" style="margin: 22px 0 18px;">
    <a class="mod-tab<?php echo $statusFilter === 'all' ? ' is-active' : ''; ?>" href="?status=all&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>">All <span class="mod-tab-count"><?php echo $totalMessages; ?></span></a>
    <a class="mod-tab<?php echo $statusFilter === 'new' ? ' is-active' : ''; ?>" href="?status=new&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>">New <span class="mod-tab-count"><?php echo $newMessages; ?></span></a>
    <a class="mod-tab<?php echo $statusFilter === 'replied' ? ' is-active' : ''; ?>" href="?status=replied&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>">Replied <span class="mod-tab-count"><?php echo $repliedMessages; ?></span></a>
    <a class="mod-tab<?php echo $statusFilter === 'ended' ? ' is-active' : ''; ?>" href="?status=ended&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>">Ended <span class="mod-tab-count"><?php echo $endedMessages; ?></span></a>
</div>

<section class="panel users-panel">
    <form method="GET" class="reports-toolbar">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>" />
        <div class="reports-toolbar-left">
            <label class="reports-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email, subject…" aria-label="Search messages" />
            </label>
        </div>
        <div class="reports-toolbar-right">
            <?php if ($search !== '' || $sort !== 'newest'): ?>
                <a href="?" class="reports-chip is-clear"><i class="fa-solid fa-xmark"></i> Clear</a>
            <?php endif; ?>
            <span class="reports-count"><?php echo count($messages); ?> messages</span>
            <select name="sort" class="reports-sort" onchange="this.form.submit()" aria-label="Sort messages">
                <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $sort === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!$messages): ?>
        <p class="empty-state">No messages yet. They will appear here as soon as someone uses the contact form.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>From</th><th>Email</th><th>Subject</th><th>Status</th><th>Message</th><th>Received</th><th><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $message): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($message['name']); ?></strong></td>
                    <td><a href="mailto:<?php echo htmlspecialchars($message['email']); ?>" style="color: var(--admin-brown); font-weight: 600; text-decoration: none;"><?php echo htmlspecialchars($message['email']); ?></a></td>
                    <td><span class="mod-status promo"><?php echo htmlspecialchars($message['subject']); ?></span></td>
                    <td><?php $mStatus = (string) ($message['status'] ?? 'new'); ?><span class="mod-status <?php echo $mStatus === 'replied' ? 'live' : ($mStatus === 'ended' ? 'removed' : 'pending'); ?>"><?php echo $mStatus === 'replied' ? 'Replied' : ($mStatus === 'ended' ? 'Ended' : 'New'); ?></span></td>
                    <td style="max-width: 320px; white-space: normal;"><?php echo htmlspecialchars(mb_strimwidth((string) $message['message'], 0, 70, '…')); ?></td>
                    <td style="color: var(--admin-muted); font-size: 12px;"><?php echo date('M j, Y · g:i A', strtotime($message['created_at'])); ?></td>
                    <td class="actions-cell" style="white-space: nowrap;">
                        <div style="display: inline-flex; gap: 7px; align-items: center;">
                        <button type="button" class="admin-button secondary mod-table-btn"
                                data-open-modal="message-modal"
                                data-message-name="<?php echo htmlspecialchars($message['name']); ?>"
                                data-message-email="<?php echo htmlspecialchars($message['email']); ?>"
                                data-message-subject="<?php echo htmlspecialchars($message['subject']); ?>"
                                data-message-date="<?php echo date('M j, Y · g:i A', strtotime($message['created_at'])); ?>"
                                data-message-id="<?php echo (int) $message['id']; ?>"
                                data-message-status="<?php echo htmlspecialchars($mStatus); ?>"
                                data-message-replies="<?php echo htmlspecialchars(json_encode($repliesByMessage[(int) $message['id']] ?? []), ENT_QUOTES); ?>"
                                data-message-body="<?php echo htmlspecialchars($message['message']); ?>">
                            <i class="fa-solid fa-eye"></i> View
                        </button>
                        <form method="POST" class="mod-form" onsubmit="return confirm('Delete this message?');">
                            <input type="hidden" name="action" value="delete_message" />
                            <input type="hidden" name="message_id" value="<?php echo (int) $message['id']; ?>" />
                            <button type="submit" class="admin-button danger-button mod-table-btn"><i class="fa-solid fa-trash-can"></i> Delete</button>
                        </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<div class="modal-backdrop" data-modal-backdrop></div>

<div class="admin-modal" id="message-modal" role="dialog" aria-modal="true" aria-labelledby="message-modal-title">
    <div class="modal-heading">
        <div><span class="modal-kicker">Contact message</span><h2 id="message-modal-title">Full message</h2></div>
        <button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <dl class="item-detail-list" style="display: grid; gap: 8px; margin: 0 0 16px; font-size: 13px;">
        <div style="display: flex; justify-content: space-between; gap: 15px; border-bottom: 1px solid #f0ece8; padding-bottom: 7px;"><dt style="color: var(--admin-muted);">From</dt><dd class="modal-message-name" style="margin: 0; font-weight: 600; text-align: right;"></dd></div>
        <div style="display: flex; justify-content: space-between; gap: 15px; border-bottom: 1px solid #f0ece8; padding-bottom: 7px;"><dt style="color: var(--admin-muted);">Email</dt><dd class="modal-message-email" style="margin: 0; font-weight: 600; text-align: right;"></dd></div>
        <div style="display: flex; justify-content: space-between; gap: 15px; border-bottom: 1px solid #f0ece8; padding-bottom: 7px;"><dt style="color: var(--admin-muted);">Subject</dt><dd class="modal-message-subject" style="margin: 0; font-weight: 600; text-align: right;"></dd></div>
        <div style="display: flex; justify-content: space-between; gap: 15px;"><dt style="color: var(--admin-muted);">Received</dt><dd class="modal-message-date" style="margin: 0; font-weight: 600; text-align: right;"></dd></div>
    </dl>
    <p class="modal-message-body" style="margin: 0 0 16px; padding: 14px 16px; border: 1px solid var(--admin-line); border-radius: 10px; background: #fdfaf7; font-size: 13px; line-height: 1.65; white-space: pre-wrap;"></p>
    <div class="modal-message-replies" style="display: grid; gap: 10px; margin-bottom: 16px;"></div>
    <p class="modal-message-ended" style="display: none; margin: 0 0 16px; padding: 12px 16px; border: 1px solid #efe0d3; border-radius: 10px; background: #fdf6ef; font-size: 13px; color: #8a5a33;"><i class="fa-solid fa-check-double" style="margin-right: 6px;"></i>This conversation has ended. Reply to the customer only if they send a new message (which reopens it).</p>
    <div class="modal-reply-wrap">
    <form method="POST" class="admin-form" action="">
        <input type="hidden" name="action" value="reply_message" />
        <input type="hidden" name="message_id" class="modal-message-id-input" value="0" />
        <div class="form-field">
            <label for="modal-reply-body">Write a reply</label>
            <textarea id="modal-reply-body" name="reply_body" placeholder="Salamat sa message mo! Narito ang sagot…" required></textarea>
            <small class="mod-hint">Saved to the inbox and shown in the customer's account. A copy is also emailed when your server mail is set up.</small>
        </div>
        <div class="modal-actions" style="margin-top: 12px;">
            <button class="admin-button primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Send Reply</button>
            <a class="admin-button secondary modal-message-reply" href="#"><i class="fa-solid fa-envelope"></i> Open Email App</a>
            <button class="admin-button secondary" type="button" data-close-modal>Close</button>
        </div>
    </form>
    </div>
    <div class="modal-end-wrap">
    <form method="POST" onsubmit="return confirm('Mark this conversation as ended?');" style="margin-top: 10px;">
        <input type="hidden" name="action" value="end_conversation" />
        <input type="hidden" name="message_id" class="modal-message-id-input" value="0" />
        <button type="submit" class="admin-button secondary"><i class="fa-solid fa-check-double"></i> End conversation</button>
    </form>
    </div>
</div>

<script>
    document.querySelectorAll('[data-open-modal="message-modal"]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelector('#message-modal .modal-message-name').textContent = button.getAttribute('data-message-name') || '';
            document.querySelector('#message-modal .modal-message-email').textContent = button.getAttribute('data-message-email') || '';
            document.querySelector('#message-modal .modal-message-subject').textContent = button.getAttribute('data-message-subject') || '';
            document.querySelector('#message-modal .modal-message-date').textContent = button.getAttribute('data-message-date') || '';
            document.querySelector('#message-modal .modal-message-body').textContent = button.getAttribute('data-message-body') || '';
            document.querySelectorAll('#message-modal .modal-message-id-input').forEach(function (i) { i.value = button.getAttribute('data-message-id') || '0'; });
            var mStatus = (button.getAttribute('data-message-status') || 'new');
            var replyWrap = document.querySelector('#message-modal .modal-reply-wrap');
            var endWrap = document.querySelector('#message-modal .modal-end-wrap');
            var endedNote = document.querySelector('#message-modal .modal-message-ended');
            if (mStatus === 'ended') {
                if (replyWrap) replyWrap.style.display = 'none';
                if (endWrap) endWrap.style.display = 'none';
                if (endedNote) endedNote.style.display = 'block';
            } else {
                if (replyWrap) replyWrap.style.display = 'block';
                if (endWrap) endWrap.style.display = 'block';
                if (endedNote) endedNote.style.display = 'none';
            }
            var reply = document.querySelector('#message-modal .modal-message-reply');
            reply.setAttribute('href', 'mailto:' + (button.getAttribute('data-message-email') || ''));
            var repliesBox = document.querySelector('#message-modal .modal-message-replies');
            var replies = [];
            try { replies = JSON.parse(button.getAttribute('data-message-replies') || '[]'); } catch (e) { replies = []; }
            repliesBox.innerHTML = replies.map(function (r) {
                var fromAdmin = r.sender !== 'customer';
                return '<div style="max-width: 85%; ' + (fromAdmin ? 'margin-left: auto; border-radius: 14px 14px 4px 14px; background: #85583f; color: #fff;' : 'margin-right: auto; border-radius: 14px 14px 14px 4px; background: #faf6f2; color: #332b27; border: 1px solid #efe8e0;') + ' padding: 10px 14px; font-size: 13px; line-height: 1.6; white-space: pre-wrap;">' +
                    '<strong style="font-size: 10.5px; display: block; opacity: .65; margin-bottom: 3px;">' + (r.admin_name || (fromAdmin ? 'Admin' : 'Customer')) + ' · ' + r.created_at + '</strong>' +
                    String(r.reply_body || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
            }).join('');
        });
    });
</script>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
