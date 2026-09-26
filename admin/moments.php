<?php
/**
 * BreadMoments moderation — admin side.
 *
 * FB-style flow: community reports a post → admin reviews it → remove it with
 * a message for the owner (or dismiss the reports), plus the review queue for
 * store promotions submitted by the staff (approve / reject with a reason the
 * staff can revise and resubmit).
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/moments.php';

$pdo = momentDb();
$pageTitle = 'BreadMoments';
$activePage = 'moments';

$tab = (string) ($_GET['tab'] ?? 'reports');
if (!in_array($tab, ['reports', 'pending', 'all'], true)) {
    $tab = 'reports';
}

$notice = (string) ($_SESSION['admin_moments_notice'] ?? '');
unset($_SESSION['admin_moments_notice']);

/* ── Actions (post → redirect → render) ──────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action   = (string) ($_POST['action'] ?? '');
    $momentId = (int) ($_POST['moment_id'] ?? 0);
    $reason   = trim((string) ($_POST['reason'] ?? ''));
    $backTab  = in_array($_POST['tab'] ?? '', ['reports', 'pending', 'all'], true) ? $_POST['tab'] : $tab;
    $adminId  = (int) ($_SESSION['user_id'] ?? 0);

    if ($momentId < 1) {
        $_SESSION['admin_moments_notice'] = 'Error: that post could not be found.';
    } else {
        switch ($action) {
            case 'dismiss_reports':
                $pdo->prepare('DELETE FROM bread_moment_reports WHERE moment_id = :id')->execute(['id' => $momentId]);
                $_SESSION['admin_moments_notice'] = 'Reports dismissed — the post stays up.';
                break;

            case 'remove_post':
                if (mb_strlen($reason) < 5) {
                    $_SESSION['admin_moments_notice'] = 'Error: write the message for the owner first (at least 5 characters).';
                    break;
                }
                $remove = $pdo->prepare(
                    "UPDATE bread_moments
                     SET moderation_status = 'removed', moderation_reason = :reason,
                         moderated_by = :admin_id, moderated_at = NOW()
                     WHERE id = :id AND moderation_status <> 'removed'"
                );
                $remove->execute(['reason' => mb_substr($reason, 0, 500), 'admin_id' => $adminId, 'id' => $momentId]);
                if ($remove->rowCount()) {
                    $pdo->prepare('DELETE FROM bread_moment_reports WHERE moment_id = :id')->execute(['id' => $momentId]);
                    $_SESSION['admin_moments_notice'] = 'Post removed — your message was sent to the owner.';
                } else {
                    $_SESSION['admin_moments_notice'] = 'Error: that post was already removed.';
                }
                break;

            case 'restore_post':
                $restore = $pdo->prepare(
                    "UPDATE bread_moments
                     SET moderation_status = 'visible', moderation_reason = NULL,
                         moderated_by = :admin_id, moderated_at = NOW()
                     WHERE id = :id AND moderation_status = 'removed'"
                );
                $restore->execute(['admin_id' => $adminId, 'id' => $momentId]);
                $_SESSION['admin_moments_notice'] = $restore->rowCount()
                    ? 'Post restored — it is back in the feed.'
                    : 'Error: that post is not removed.';
                break;

            case 'approve_promo':
                $approve = $pdo->prepare(
                    "UPDATE bread_moments
                     SET moderation_status = 'visible', moderation_reason = NULL,
                         moderated_by = :admin_id, moderated_at = NOW()
                     WHERE id = :id AND post_type = 'promotion' AND moderation_status IN ('pending', 'rejected')"
                );
                $approve->execute(['admin_id' => $adminId, 'id' => $momentId]);
                $_SESSION['admin_moments_notice'] = $approve->rowCount()
                    ? 'Promotion approved — it is live in BreadMoments now.'
                    : 'Error: that promotion is not waiting for review.';
                $backTab = 'pending';
                break;

            case 'reject_promo':
                if (mb_strlen($reason) < 5) {
                    $_SESSION['admin_moments_notice'] = 'Error: give the staff a reason for the revision (at least 5 characters).';
                    $backTab = 'pending';
                    break;
                }
                $reject = $pdo->prepare(
                    "UPDATE bread_moments
                     SET moderation_status = 'rejected', moderation_reason = :reason,
                         moderated_by = :admin_id, moderated_at = NOW()
                     WHERE id = :id AND post_type = 'promotion' AND moderation_status <> 'visible'"
                );
                $reject->execute(['reason' => mb_substr($reason, 0, 500), 'admin_id' => $adminId, 'id' => $momentId]);
                $_SESSION['admin_moments_notice'] = $reject->rowCount()
                    ? 'Sent back to the staff with your reason.'
                    : 'Error: that promotion cannot be rejected right now.';
                $backTab = 'pending';
                break;

            default:
                $_SESSION['admin_moments_notice'] = 'Error: unknown action.';
        }
    }

    header('Location: ' . BASE_URL . '/admin/moments.php?tab=' . $backTab);
    exit;
}

/* ── Queue data ──────────────────────────────────────────────────────── */
$counts = ['reports' => 0, 'pending' => 0, 'removed' => 0, 'live_promos' => 0, 'total' => 0];
$reportRows = [];
$pendingRows = [];
$allRows = [];

if ($pdo) {
    $counts['reports']    = (int) $pdo->query('SELECT COUNT(DISTINCT moment_id) FROM bread_moment_reports')->fetchColumn();
    $counts['pending']    = (int) $pdo->query("SELECT COUNT(*) FROM bread_moments WHERE moderation_status = 'pending'")->fetchColumn();
    $counts['removed']    = (int) $pdo->query("SELECT COUNT(*) FROM bread_moments WHERE moderation_status = 'removed'")->fetchColumn();
    $counts['live_promos'] = (int) $pdo->query("SELECT COUNT(*) FROM bread_moments WHERE post_type = 'promotion' AND moderation_status = 'visible'")->fetchColumn();
    $counts['total']      = (int) $pdo->query('SELECT COUNT(*) FROM bread_moments')->fetchColumn();

    if ($tab === 'reports') {
        $reportRows = $pdo->query("
            SELECT m.id, m.title, m.post_type, m.moderation_status, m.created_at,
                   u.first_name,
                   COUNT(r.id) AS report_count,
                   GROUP_CONCAT(DISTINCT r.reason ORDER BY r.id SEPARATOR ' · ') AS reasons,
                   MIN(r.created_at) AS first_reported,
                   (SELECT p.id FROM bread_moment_photos p WHERE p.moment_id = m.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id
            FROM bread_moment_reports r
            JOIN bread_moments m ON m.id = r.moment_id
            JOIN users u ON u.id = m.user_id
            GROUP BY m.id, m.title, m.post_type, m.moderation_status, m.created_at, u.first_name
            ORDER BY report_count DESC, first_reported ASC
        ")->fetchAll();
    }

    if ($tab === 'pending') {
        $pendingRows = $pdo->query("
            SELECT m.id, m.title, m.body, m.created_at, m.moderation_status, m.moderation_reason, m.moderated_at,
                   u.first_name, pi.name AS product_name,
                   (SELECT p.id FROM bread_moment_photos p WHERE p.moment_id = m.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id
            FROM bread_moments m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN inventory_items pi ON pi.id = m.product_id
            WHERE m.moderation_status = 'pending' AND m.post_type = 'promotion'
            ORDER BY m.created_at ASC
        ")->fetchAll();
    }

    if ($tab === 'all') {
        $allRows = $pdo->query("
            SELECT m.id, m.title, m.post_type, m.product_id, m.moderation_status, m.moderation_reason,
                   m.views_count, m.created_at, u.first_name, pi.name AS product_name,
                   (SELECT p.id FROM bread_moment_photos p WHERE p.moment_id = m.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
                   (SELECT COUNT(*) FROM bread_moment_reports r WHERE r.moment_id = m.id) AS report_count
            FROM bread_moments m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN inventory_items pi ON pi.id = m.product_id
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT 50
        ")->fetchAll();
    }
}

/** Promotions are authored by the store, everyone else by their first name. */
$modAuthorName = static function (array $row): string {
    return ($row['post_type'] ?? 'moment') === 'promotion' ? MOMENT_STORE_NAME : trim((string) ($row['first_name'] ?? ''));
};

$noticeIsError = str_starts_with($notice, 'Error:');

require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro">
    <div>
        <h2>BreadMoments moderation</h2>
        <p>Review reported posts, approve store promotions from the staff, and keep the feed within the community guidelines.</p>
    </div>
</section>

<?php if ($notice !== ''): ?>
<div class="admin-notice <?php echo $noticeIsError ? 'danger' : 'success'; ?>" role="<?php echo $noticeIsError ? 'alert' : 'status'; ?>">
    <i class="fa-solid <?php echo $noticeIsError ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
    <?php echo htmlspecialchars(preg_replace('/^Error:\s*/i', '', $notice)); ?>
</div>
<?php endif; ?>

<section class="summary-grid">
    <div class="summary-card">
        <div class="summary-top"><span>REPORTED POSTS</span><span class="summary-icon"><i class="fa-solid fa-flag"></i></span></div>
        <div class="summary-value"><?php echo $counts['reports']; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top"><span>AWAITING REVIEW</span><span class="summary-icon"><i class="fa-solid fa-hourglass-half"></i></span></div>
        <div class="summary-value"><?php echo $counts['pending']; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top"><span>LIVE PROMOTIONS</span><span class="summary-icon"><i class="fa-solid fa-bullhorn"></i></span></div>
        <div class="summary-value"><?php echo $counts['live_promos']; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top"><span>REMOVED POSTS</span><span class="summary-icon"><i class="fa-solid fa-shield-halved"></i></span></div>
        <div class="summary-value"><?php echo $counts['removed']; ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-top"><span>TOTAL POSTS</span><span class="summary-icon"><i class="fa-solid fa-camera-retro"></i></span></div>
        <div class="summary-value"><?php echo $counts['total']; ?></div>
    </div>
</section>

<nav class="mod-tabs" aria-label="BreadMoments sections">
    <a class="mod-tab<?php echo $tab === 'reports' ? ' is-active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/moments.php?tab=reports">
        <i class="fa-solid fa-flag"></i> Reported posts <span class="mod-tab-count"><?php echo $counts['reports']; ?></span>
    </a>
    <a class="mod-tab<?php echo $tab === 'pending' ? ' is-active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/moments.php?tab=pending">
        <i class="fa-solid fa-hourglass-half"></i> Promotion review <span class="mod-tab-count"><?php echo $counts['pending']; ?></span>
    </a>
    <a class="mod-tab<?php echo $tab === 'all' ? ' is-active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/moments.php?tab=all">
        <i class="fa-solid fa-layer-group"></i> All posts
    </a>
</nav>

<?php if ($tab === 'reports'): ?>
<section class="panel mod-panel">
    <div class="panel-heading">
        <div>
            <h3>Reported posts</h3>
            <p class="panel-subtitle">Flagged by the community — dismiss the reports, or remove the post with a message for the owner.</p>
        </div>
    </div>
    <?php if (!$reportRows): ?>
        <p class="empty-state">No reported posts right now — the feed is clean.</p>
    <?php else: ?>
        <?php foreach ($reportRows as $row): ?>
        <article class="mod-card">
            <span class="mod-thumb">
                <?php if ($row['photo_id']): ?>
                <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $row['photo_id']; ?>" alt="" loading="lazy" />
                <?php else: ?>
                <i class="fa-solid fa-bread-slice" aria-hidden="true"></i>
                <?php endif; ?>
            </span>
            <div class="mod-body">
                <div class="mod-head">
                    <strong class="mod-title"><?php echo htmlspecialchars($row['title']); ?></strong>
                    <?php if (($row['post_type'] ?? 'moment') === 'promotion'): ?><span class="mod-status promo">Promotion</span><?php endif; ?>
                    <?php [$pillLabel, $pillClass] = momentStatusLabel((string) $row['moderation_status']); ?>
                    <?php if ($row['moderation_status'] !== 'visible'): ?><span class="mod-status <?php echo $pillClass; ?>"><?php echo $pillLabel; ?></span><?php endif; ?>
                </div>
                <p class="mod-meta">
                    <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($modAuthorName($row)); ?>
                    · <i class="fa-solid fa-flag"></i> <?php echo (int) $row['report_count']; ?> report<?php echo (int) $row['report_count'] === 1 ? '' : 's'; ?>
                    · first reported <?php echo htmlspecialchars(momentTimeAgo($row['first_reported'])); ?>
                </p>
                <p class="mod-reasons"><i class="fa-solid fa-comment-dots"></i> <?php echo htmlspecialchars((string) $row['reasons']); ?></p>
            </div>
            <div class="mod-actions">
                <form method="POST" class="mod-form">
                    <input type="hidden" name="action" value="dismiss_reports" />
                    <input type="hidden" name="moment_id" value="<?php echo (int) $row['id']; ?>" />
                    <input type="hidden" name="tab" value="reports" />
                    <button class="admin-button secondary" type="submit"><i class="fa-solid fa-check"></i> Dismiss reports</button>
                </form>
                <button type="button" class="admin-button danger-button"
                        data-open-modal="remove-modal"
                        data-moment-id="<?php echo (int) $row['id']; ?>"
                        data-moment-title="<?php echo htmlspecialchars($row['title']); ?>"
                        <?php if ($row['moderation_status'] === 'removed'): ?>disabled<?php endif; ?>>
                    <i class="fa-solid fa-trash-can"></i> Remove post
                </button>
            </div>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'pending'): ?>
<section class="panel mod-panel">
    <div class="panel-heading">
        <div>
            <h3>Promotions waiting for review</h3>
            <p class="panel-subtitle">Staff promotions — approve to publish, or send back with a reason so the staff can revise and resubmit.</p>
        </div>
    </div>
    <?php if (!$pendingRows): ?>
        <p class="empty-state">Nothing waiting for review — every submitted promotion has been handled.</p>
    <?php else: ?>
        <?php foreach ($pendingRows as $row): ?>
        <article class="mod-card">
            <span class="mod-thumb">
                <?php if ($row['photo_id']): ?>
                <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $row['photo_id']; ?>" alt="" loading="lazy" />
                <?php else: ?>
                <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
                <?php endif; ?>
            </span>
            <div class="mod-body">
                <div class="mod-head">
                    <strong class="mod-title"><?php echo htmlspecialchars($row['title']); ?></strong>
                    <span class="mod-status promo">Promotion</span>
                    <span class="mod-status pending">Pending review</span>
                </div>
                <p class="mod-meta">
                    <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($row['first_name']); ?> (staff)
                    <?php if (!empty($row['product_name'])): ?>
                    · <i class="fa-solid fa-bread-slice"></i> <?php echo htmlspecialchars($row['product_name']); ?>
                    <?php endif; ?>
                    · submitted <?php echo htmlspecialchars(momentTimeAgo($row['created_at'])); ?>
                </p>
                <p class="mod-caption"><?php echo htmlspecialchars(mb_substr((string) $row['body'], 0, 180)); ?><?php echo mb_strlen((string) $row['body']) > 180 ? '…' : ''; ?></p>
                <?php if (!empty($row['moderation_reason'])): ?>
                <p class="mod-feedback"><i class="fa-solid fa-message"></i> Your previous note: <?php echo htmlspecialchars($row['moderation_reason']); ?></p>
                <?php endif; ?>
            </div>
            <div class="mod-actions">
                <form method="POST" class="mod-form">
                    <input type="hidden" name="action" value="approve_promo" />
                    <input type="hidden" name="moment_id" value="<?php echo (int) $row['id']; ?>" />
                    <input type="hidden" name="tab" value="pending" />
                    <button class="admin-button primary" type="submit"><i class="fa-solid fa-check"></i> Approve &amp; publish</button>
                </form>
                <button type="button" class="admin-button secondary"
                        data-open-modal="reject-modal"
                        data-moment-id="<?php echo (int) $row['id']; ?>"
                        data-moment-title="<?php echo htmlspecialchars($row['title']); ?>">
                    <i class="fa-solid fa-rotate-left"></i> Reject with reason
                </button>
            </div>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php else: ?>
<section class="panel mod-panel">
    <div class="panel-heading">
        <div>
            <h3>All posts</h3>
            <p class="panel-subtitle">Every BreadMoment with its status — the last 50.</p>
        </div>
    </div>
    <?php if (!$allRows): ?>
        <p class="empty-state">No posts yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="admin-table mod-table">
            <thead>
                <tr>
                    <th>Post</th><th>Author</th><th>Type</th><th>Status</th><th>Views</th><th>Reports</th><th>Posted</th><th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allRows as $row): ?>
                <tr>
                    <td class="mod-cell-post">
                        <span class="mod-table-thumb">
                            <?php if ($row['photo_id']): ?>
                            <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $row['photo_id']; ?>" alt="" loading="lazy" />
                            <?php else: ?>
                            <i class="fa-solid fa-bread-slice" aria-hidden="true"></i>
                            <?php endif; ?>
                        </span>
                        <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                    </td>
                    <td><?php echo htmlspecialchars($modAuthorName($row)); ?></td>
                    <td><?php if (($row['post_type'] ?? 'moment') === 'promotion'): ?><span class="mod-status promo">Promotion</span><?php else: ?>Moment<?php endif; ?></td>
                    <td>
                        <?php [$pillLabel, $pillClass] = momentStatusLabel((string) $row['moderation_status']); ?>
                        <span class="mod-status <?php echo $pillClass; ?>"><?php echo $pillLabel; ?></span>
                    </td>
                    <td><?php echo (int) $row['views_count']; ?></td>
                    <td><?php echo (int) $row['report_count']; ?></td>
                    <td><?php echo htmlspecialchars(momentTimeAgo($row['created_at'])); ?></td>
                    <td class="mod-cell-actions">
                        <?php if ($row['moderation_status'] === 'visible'): ?>
                            <button type="button" class="admin-button danger-button mod-table-btn"
                                    data-open-modal="remove-modal"
                                    data-moment-id="<?php echo (int) $row['id']; ?>"
                                    data-moment-title="<?php echo htmlspecialchars($row['title']); ?>">
                                <i class="fa-solid fa-trash-can"></i> Remove
                            </button>
                        <?php elseif ($row['moderation_status'] === 'removed'): ?>
                            <form method="POST" class="mod-form">
                                <input type="hidden" name="action" value="restore_post" />
                                <input type="hidden" name="moment_id" value="<?php echo (int) $row['id']; ?>" />
                                <input type="hidden" name="tab" value="all" />
                                <button class="admin-button secondary mod-table-btn" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                            </form>
                        <?php elseif ($row['moderation_status'] === 'pending' && ($row['post_type'] ?? '') === 'promotion'): ?>
                            <form method="POST" class="mod-form">
                                <input type="hidden" name="action" value="approve_promo" />
                                <input type="hidden" name="moment_id" value="<?php echo (int) $row['id']; ?>" />
                                <input type="hidden" name="tab" value="all" />
                                <button class="admin-button primary mod-table-btn" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                            </form>
                        <?php elseif ($row['moderation_status'] === 'rejected'): ?>
                            <span class="mod-note">Awaiting revision</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="modal-backdrop" data-modal-backdrop></div>

<div class="admin-modal compact warning-modal" id="remove-modal" role="dialog" aria-modal="true" aria-labelledby="remove-modal-title">
    <div class="modal-heading">
        <div><span class="modal-kicker warning">Community guidelines</span><h2 id="remove-modal-title">Remove this post?</h2></div>
        <button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <p>The post disappears from BreadMoments and the owner sees your message explaining why it did not follow the guidelines.</p>
    <form method="POST" class="admin-form">
        <input type="hidden" name="action" value="remove_post" />
        <input type="hidden" name="tab" value="<?php echo $tab; ?>" />
        <input type="hidden" name="moment_id" class="modal-moment-id" value="0" />
        <div class="form-field">
            <label for="remove-post-title">Post</label>
            <input id="remove-post-title" class="modal-moment-title" type="text" readonly value="" />
        </div>
        <div class="form-field">
            <label for="remove-reason">Message to the owner</label>
            <textarea id="remove-reason" name="reason" required placeholder="Hindi ito pasok sa community guidelines dahil…"></textarea>
            <small class="mod-hint">Shown to the owner as the reason the post was taken down.</small>
        </div>
        <div class="modal-actions">
            <button class="admin-button secondary" type="button" data-close-modal>Cancel</button>
            <button class="admin-button danger-button" type="submit"><i class="fa-solid fa-trash-can"></i> Remove post</button>
        </div>
    </form>
</div>

<div class="admin-modal compact warning-modal" id="reject-modal" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="modal-heading">
        <div><span class="modal-kicker warning">Store promotion</span><h2 id="reject-modal-title">Send back for revision?</h2></div>
        <button class="modal-close" type="button" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <p>The staff member sees your reason, revises the promotion, and can resubmit it for review.</p>
    <form method="POST" class="admin-form">
        <input type="hidden" name="action" value="reject_promo" />
        <input type="hidden" name="tab" value="pending" />
        <input type="hidden" name="moment_id" class="modal-moment-id" value="0" />
        <div class="form-field">
            <label for="reject-post-title">Promotion</label>
            <input id="reject-post-title" class="modal-moment-title" type="text" readonly value="" />
        </div>
        <div class="form-field">
            <label for="reject-reason">Reason for the revision</label>
            <textarea id="reject-reason" name="reason" required placeholder="Halimbawa: ilagay ang presyo at dagdagan ang caption…"></textarea>
            <small class="mod-hint">Sent to the staff together with their promotion.</small>
        </div>
        <div class="modal-actions">
            <button class="admin-button secondary" type="button" data-close-modal>Cancel</button>
            <button class="admin-button danger-button" type="submit"><i class="fa-solid fa-rotate-left"></i> Reject & send back</button>
        </div>
    </form>
</div>

<script>
    // Copy the clicked row's post into whichever decision modal is opening.
    document.querySelectorAll('[data-moment-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-moment-id') || '0';
            var title = button.getAttribute('data-moment-title') || '';
            document.querySelectorAll('.modal-moment-id').forEach(function (input) { input.value = id; });
            document.querySelectorAll('.modal-moment-title').forEach(function (input) { input.value = title; });
        });
    });
</script>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
