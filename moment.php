<?php
$pageTitle = 'BreadMoment | BreadBreak';

require_once __DIR__ . '/includes/moments.php';

$momentPdo = momentDb();
$momentDbError = momentDbError();

$momentUserId = (int) ($_SESSION['user_id'] ?? 0);
$momentIsSignedIn = $momentUserId > 0;
$momentFirstName = trim((string) ($_SESSION['first_name'] ?? ''));

$momentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$momentId) {
    // The 3-dots forms may post without a query string — accept the body too.
    $momentId = (int) ($_POST['moment_id'] ?? 0);
}
if (!$momentId) {
    $_SESSION['moments_notice'] = 'That BreadMoment could not be found.';
    header('Location: /BreadBreak/moments.php');
    exit;
}

/* ── POST: delete (own post) / report (others) ────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$momentPdo) {
        $_SESSION['moments_notice'] = 'We could not reach the database just yet — please try again.';
        header('Location: /BreadBreak/moment.php?id=' . $momentId);
        exit;
    }

    $momentAction = (string) ($_POST['action'] ?? '');

    if ($momentAction === 'delete') {
        if (!$momentIsSignedIn) {
            header('Location: /BreadBreak/login.php?redirect=moments');
            exit;
        }
        // Store promotions are never deleted by staff here — the admin manages them.
        $momentDelete = $momentPdo->prepare("DELETE FROM bread_moments WHERE id = :id AND user_id = :user_id AND post_type = 'moment'");
        $momentDelete->execute(['id' => $momentId, 'user_id' => $momentUserId]);
        $_SESSION['moments_notice'] = $momentDelete->rowCount()
            ? 'Your BreadMoment was deleted.'
            : 'You can only delete your own posts.';
        header('Location: /BreadBreak/moments.php');
        exit;
    }

    if ($momentAction === 'report') {
        if (!$momentIsSignedIn) {
            header('Location: /BreadBreak/login.php?redirect=moments');
            exit;
        }
        $momentOwnerStatement = $momentPdo->prepare('SELECT user_id, moderation_status FROM bread_moments WHERE id = :id LIMIT 1');
        $momentOwnerStatement->execute(['id' => $momentId]);
        $momentOwnerRow = $momentOwnerStatement->fetch() ?: null;
        if (!$momentOwnerRow || $momentOwnerRow['moderation_status'] !== 'visible') {
            $_SESSION['moment_notice'] = 'That post is not available right now.';
            header('Location: /BreadBreak/moment.php?id=' . $momentId);
            exit;
        }
        $momentOwner = (int) $momentOwnerRow['user_id'];
        if ($momentOwner === $momentUserId) {
            $_SESSION['moment_notice'] = 'You cannot report your own post.';
            header('Location: /BreadBreak/moment.php?id=' . $momentId);
            exit;
        }
        $momentReason = (string) ($_POST['reason'] ?? '');
        if (!in_array($momentReason, momentReportReasons(), true)) {
            $momentReason = 'Something else';
        }
        try {
            $momentReport = $momentPdo->prepare(
                'INSERT IGNORE INTO bread_moment_reports (moment_id, user_id, reason) VALUES (:moment_id, :user_id, :reason)'
            );
            $momentReport->execute(['moment_id' => $momentId, 'user_id' => $momentUserId, 'reason' => $momentReason]);
            $_SESSION['moment_notice'] = $momentReport->rowCount()
                ? 'Thanks! Your report was received — our team will review this post.'
                : 'You already reported this post. Our team will review it soon.';
        } catch (Throwable $momentReportException) {
            $_SESSION['moment_notice'] = 'That post could not be reported just now — please try again.';
        }
        header('Location: /BreadBreak/moment.php?id=' . $momentId);
        exit;
    }

    header('Location: /BreadBreak/moment.php?id=' . $momentId);
    exit;
}

/* ── Load the post ────────────────────────────────────────────────────── */
$momentPost = null;
if ($momentPdo) {
    $momentPostStatement = $momentPdo->prepare("
        SELECT m.id, m.user_id, m.category_id, m.title, m.body, m.topics, m.views_count, m.created_at,
               m.post_type, m.product_id, m.moderation_status, m.moderation_reason,
               u.first_name, u.profile_data, u.profile_mime, c.name AS category_name,
               pi.name AS product_name
        FROM bread_moments m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN menu_categories c ON c.id = m.category_id
        LEFT JOIN inventory_items pi ON pi.id = m.product_id
        WHERE m.id = :id
        LIMIT 1
    ");
    $momentPostStatement->execute(['id' => $momentId]);
    $momentPost = $momentPostStatement->fetch() ?: null;
}

if (!$momentPost) {
    $_SESSION['moments_notice'] = 'That BreadMoment could not be found.';
    header('Location: /BreadBreak/moments.php');
    exit;
}

$momentIsOwn = $momentIsSignedIn && (int) $momentPost['user_id'] === $momentUserId;
$momentStatus = (string) ($momentPost['moderation_status'] ?? 'visible');
$momentIsPromo = ($momentPost['post_type'] ?? 'moment') === 'promotion';
$momentIsAdmin = (($_SESSION['role'] ?? '') === 'admin');

// Hidden posts (waiting for review, rejected or removed) answer only to their
// owner and the admin — everyone else is sent back to the feed.
if ($momentStatus !== 'visible' && !$momentIsOwn && !$momentIsAdmin) {
    $_SESSION['moments_notice'] = 'That BreadMoment is not available.';
    header('Location: /BreadBreak/moments.php');
    exit;
}

/* ── Count this view (once per visitor — live posts only) ─────────────── */
if ($momentPdo && $momentStatus === 'visible') {
    try {
        $momentViewInsert = $momentPdo->prepare(
            'INSERT IGNORE INTO bread_moment_views (moment_id, viewer_key) VALUES (:moment_id, :viewer_key)'
        );
        $momentViewInsert->execute(['moment_id' => $momentId, 'viewer_key' => momentViewerKey()]);
        if ($momentViewInsert->rowCount() > 0) {
            $momentViewUpdate = $momentPdo->prepare('UPDATE bread_moments SET views_count = views_count + 1 WHERE id = :id');
            $momentViewUpdate->execute(['id' => $momentId]);
            // The post row was loaded before the bump — keep the page in sync.
            $momentPost['views_count'] = (int) $momentPost['views_count'] + 1;
        }
    } catch (Throwable $momentViewException) {
        // Views are cosmetic — never block the page on them.
    }
}

$momentInitial = strtoupper(substr(trim((string) $momentPost['first_name']), 0, 1)) ?: '?';
$momentPostTopics = array_values(array_filter(array_map('trim', explode(',', (string) $momentPost['topics']))));

// Author profile photo (falls back to the initial avatar).
$momentAuthorProfileSrc = '';
if (!empty($momentPost['profile_data']) && !empty($momentPost['profile_mime'])) {
    $momentAuthorProfileSrc = 'data:' . $momentPost['profile_mime'] . ';base64,' . base64_encode($momentPost['profile_data']);
}

/* ── Photos, likes, comments ──────────────────────────────────────────── */
$momentPhotos = [];
$momentLikeCount = 0;
$momentLikedByMe = false;
$momentCommentCount = 0;
$momentTopLevel = [];
$momentRepliesByParent = [];

if ($momentPdo) {
    foreach ($momentPdo->query('SELECT id FROM bread_moment_photos WHERE moment_id = ' . (int) $momentId . ' ORDER BY position ASC, id ASC') as $momentPhotoRow) {
        $momentPhotos[] = (int) $momentPhotoRow['id'];
    }

    $momentLikeCountStatement = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_likes WHERE moment_id = :id');
    $momentLikeCountStatement->execute(['id' => $momentId]);
    $momentLikeCount = (int) $momentLikeCountStatement->fetchColumn();

    if ($momentIsSignedIn) {
        $momentLikedStatement = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_likes WHERE moment_id = :id AND user_id = :user_id');
        $momentLikedStatement->execute(['id' => $momentId, 'user_id' => $momentUserId]);
        $momentLikedByMe = (bool) $momentLikedStatement->fetchColumn();
    }

    $momentCommentStatement = $momentPdo->prepare("
        SELECT c.id, c.moment_id, c.user_id, c.parent_id, c.body, c.created_at,
               u.first_name, u.profile_data, u.profile_mime,
               (SELECT COUNT(*) FROM bread_moment_comment_likes cl WHERE cl.comment_id = c.id) AS like_count,
               EXISTS(SELECT 1 FROM bread_moment_comment_likes cl WHERE cl.comment_id = c.id AND cl.user_id = :viewer_id) AS liked
        FROM bread_moment_comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.moment_id = :moment_id
        ORDER BY c.created_at ASC, c.id ASC
    ");
    $momentCommentStatement->execute(['moment_id' => $momentId, 'viewer_id' => $momentUserId]);
    foreach ($momentCommentStatement->fetchAll() as $momentCommentRow) {
        $momentCommentCount++;
        if ($momentCommentRow['parent_id'] === null) {
            $momentTopLevel[] = $momentCommentRow;
        } else {
            $momentRepliesByParent[(int) $momentCommentRow['parent_id']][] = $momentCommentRow;
        }
    }

    // The signed-in viewer's photo for the comment box avatar.
    $momentViewerProfileSrc = '';
    if ($momentIsSignedIn) {
        $momentViewerStatement = $momentPdo->prepare('SELECT profile_data, profile_mime FROM users WHERE id = :id LIMIT 1');
        $momentViewerStatement->execute(['id' => $momentUserId]);
        $momentViewerRow = $momentViewerStatement->fetch();
        if ($momentViewerRow && !empty($momentViewerRow['profile_data']) && !empty($momentViewerRow['profile_mime'])) {
            $momentViewerProfileSrc = 'data:' . $momentViewerRow['profile_mime'] . ';base64,' . base64_encode($momentViewerRow['profile_data']);
        }
    }
}

$momentNotice = (string) ($_SESSION['moment_notice'] ?? '');
unset($_SESSION['moment_notice']);
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="moment-detail">
    <div class="container moment-detail-shell">

        <?php if ($momentNotice !== ''): ?>
            <p class="form-notice is-success" role="status"><i class="fa-solid fa-circle-check"></i> <?php echo momentEscape($momentNotice); ?></p>
        <?php endif; ?>
        <?php if ($momentDbError !== '' && !$momentPdo): ?>
            <p class="form-notice is-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo momentEscape($momentDbError); ?></p>
        <?php endif; ?>

        <?php /* Moderation banners — visible to the owner (and the admin). */ ?>
        <?php if ($momentStatus === 'removed'): ?>
            <p class="moment-moderation-note is-removed" role="alert">
                <i class="fa-solid fa-shield-halved"></i>
                <span><strong>This post was removed for not following our community guidelines.</strong>
                <?php if (!empty($momentPost['moderation_reason'])): ?>
                <span class="moment-moderation-reason">Message from the admin: <?php echo momentEscape($momentPost['moderation_reason']); ?></span>
                <?php endif; ?></span>
            </p>
        <?php elseif ($momentStatus === 'pending'): ?>
            <p class="moment-moderation-note is-pending" role="status">
                <i class="fa-solid fa-hourglass-half"></i>
                <span><strong>Waiting for admin review.</strong>
                <span class="moment-moderation-reason">This promotion appears in BreadMoments once the admin approves it.</span></span>
            </p>
        <?php elseif ($momentStatus === 'rejected'): ?>
            <p class="moment-moderation-note is-rejected" role="alert">
                <i class="fa-solid fa-circle-xmark"></i>
                <span><strong>Not approved by the admin.</strong>
                <?php if (!empty($momentPost['moderation_reason'])): ?>
                <span class="moment-moderation-reason"><?php echo momentEscape($momentPost['moderation_reason']); ?></span>
                <?php endif; ?></span>
            </p>
        <?php endif; ?>

        <div class="moment-detail-top">
            <a class="moment-back" href="/BreadBreak/moments.php"><i class="fa-solid fa-arrow-left"></i> Back to BreadMoments</a>

            <?php if (!($momentIsOwn && $momentIsPromo)): ?>
            <!-- 3-dots menu -->
            <div class="moment-menu">
                <button type="button" class="moment-menu-btn" id="momentMenuBtn" aria-haspopup="true" aria-expanded="false" aria-label="Post options">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <div class="moment-menu-pop" id="momentMenuPop" hidden>
                    <?php if ($momentIsOwn): ?>
                        <a href="/BreadBreak/moments.php?edit=<?php echo (int) $momentId; ?>&next=moment"><i class="fa-solid fa-pen"></i> Edit post</a>
                        <form method="POST" action="/BreadBreak/moment.php" data-confirm-post-delete>
                            <input type="hidden" name="action" value="delete" />
                            <input type="hidden" name="moment_id" value="<?php echo (int) $momentId; ?>" />
                            <button type="submit" class="is-danger"><i class="fa-solid fa-trash"></i> Delete post</button>
                        </form>
                    <?php else: ?>
                        <button type="button" id="momentReportOpen"><i class="fa-solid fa-flag"></i> Report post</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <article class="moment-detail-card">

            <!-- Header: name + time + views -->
            <header class="moment-detail-head">
                <?php if ($momentIsPromo): ?>
                <span class="moment-avatar moment-avatar-lg moment-avatar-store" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
                <?php elseif ($momentAuthorProfileSrc): ?>
                <span class="moment-avatar moment-avatar-lg"><img src="<?php echo momentEscape($momentAuthorProfileSrc); ?>" alt="<?php echo momentEscape($momentPost['first_name']); ?>" /></span>
                <?php else: ?>
                <span class="moment-avatar moment-avatar-lg" aria-hidden="true"><?php echo momentEscape($momentInitial); ?></span>
                <?php endif; ?>
                <div class="moment-detail-who">
                    <strong><?php echo $momentIsPromo ? momentEscape(MOMENT_STORE_NAME) : momentEscape($momentPost['first_name']); ?><?php if ($momentIsPromo): ?> <span class="moment-promo-chip"><i class="fa-solid fa-bullhorn"></i> Promotion</span><?php endif; ?></strong>
                    <span class="moment-detail-meta">
                        <time datetime="<?php echo momentEscape($momentPost['created_at']); ?>"><?php echo momentEscape(momentTimeAgo($momentPost['created_at'])); ?></time>
                        <?php if ($momentIsPromo): ?>
                        <span class="moment-posted-by">Posted by store</span>
                        <?php endif; ?>
                        <span class="moment-detail-views" title="Views">
                            <i class="fa-solid fa-eye"></i> <b><?php echo (int) $momentPost['views_count']; ?></b>
                        </span>
                    </span>
                </div>
            </header>

            <!-- Images: lahat, unang image muna sa taas -->
            <?php if ($momentPhotos): ?>
            <div class="moment-detail-media">
                <?php foreach ($momentPhotos as $momentPhotoIndex => $momentPhotoId): ?>
                    <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $momentPhotoId; ?>"
                         alt="<?php echo momentEscape($momentPost['title']); ?> photo <?php echo $momentPhotoIndex + 1; ?>"
                         loading="<?php echo $momentPhotoIndex === 0 ? 'eager' : 'lazy'; ?>" />
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="moment-detail-body">
                <h1 class="moment-detail-title"><?php echo momentEscape($momentPost['title']); ?></h1>

                <section class="moment-detail-section">
                    <span class="moment-detail-label"><i class="fa-solid fa-feather-pointed"></i> <?php echo $momentIsPromo ? 'Store promotion' : 'Your moment'; ?></span>
                    <p class="moment-detail-text"><?php echo nl2br(momentEscape($momentPost['body'])); ?></p>
                </section>

                <?php if ($momentIsPromo && !empty($momentPost['product_name'])): ?>
                <div class="moment-detail-tags">
                    <span class="moment-detail-label"><i class="fa-solid fa-star"></i> Promoting</span>
                    <a class="moment-product-chip" href="/BreadBreak/menu.php#/item/<?php echo (int) $momentPost['product_id']; ?>">
                        <?php echo momentEscape($momentPost['product_name']); ?> <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($momentPost['category_name']): ?>
                <div class="moment-detail-tags">
                    <span class="moment-detail-label"><i class="fa-solid fa-bread-slice"></i> Category</span>
                    <a class="moment-category" href="/BreadBreak/moments.php?cat=<?php echo (int) $momentPost['category_id']; ?>">
                        <?php echo momentEscape($momentPost['category_name']); ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($momentPostTopics): ?>
                <div class="moment-detail-tags">
                    <span class="moment-detail-label"><i class="fa-solid fa-hashtag"></i> Topic</span>
                    <div class="moment-topics">
                        <?php foreach ($momentPostTopics as $momentPostTopic): ?>
                            <a href="/BreadBreak/moments.php?topic=<?php echo urlencode($momentPostTopic); ?>">#<?php echo momentEscape($momentPostTopic); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Likes total sits ABOVE the like icon row -->
            <footer class="moment-detail-foot">
                <p class="moment-likes-row"><i class="fa-solid fa-heart"></i> <b id="momentLikeCount"><?php echo $momentLikeCount; ?></b> like<?php echo $momentLikeCount === 1 ? '' : 's'; ?></p>
                <div class="moment-actions-row">
                    <?php if ($momentIsSignedIn): ?>
                        <button type="button" class="moment-action-btn<?php echo $momentLikedByMe ? ' is-on' : ''; ?>" id="momentLikePost"
                                data-moment="<?php echo (int) $momentId; ?>" aria-pressed="<?php echo $momentLikedByMe ? 'true' : 'false'; ?>">
                            <i class="fa-solid fa-thumbs-up"></i> <span id="momentLikeLabel"><?php echo $momentLikedByMe ? 'Liked' : 'Like'; ?></span>
                        </button>
                        <button type="button" class="moment-action-btn" id="momentJumpComment">
                            <i class="fa-regular fa-comment"></i> Comment <span class="moment-action-count">(<?php echo $momentCommentCount; ?>)</span>
                        </button>
                    <?php else: ?>
                        <a class="moment-action-btn" href="/BreadBreak/login.php?redirect=moments"><i class="fa-solid fa-thumbs-up"></i> Like</a>
                        <a class="moment-action-btn" href="/BreadBreak/login.php?redirect=moments">
                            <i class="fa-regular fa-comment"></i> Comment <span class="moment-action-count">(<?php echo $momentCommentCount; ?>)</span>
                        </a>
                    <?php endif; ?>
                </div>
            </footer>
        </article>

        <!-- Comments -->
        <section class="moment-comments" id="momentComments">
            <h2 class="moment-comments-title">
                <i class="fa-regular fa-comments"></i> Comments <span id="momentCommentCount">(<?php echo $momentCommentCount; ?>)</span>
            </h2>

            <?php if ($momentIsSignedIn): ?>
            <form class="moment-comment-form" id="momentCommentForm" data-moment="<?php echo (int) $momentId; ?>">
                <?php if ($momentViewerProfileSrc): ?>
                <span class="moment-avatar"><img src="<?php echo momentEscape($momentViewerProfileSrc); ?>" alt="<?php echo momentEscape($momentFirstName); ?>" /></span>
                <?php else: ?>
                <span class="moment-avatar" aria-hidden="true"><?php echo momentEscape(strtoupper(substr($momentFirstName, 0, 1)) ?: 'Y'); ?></span>
                <?php endif; ?>
                <input type="text" id="momentCommentInput" class="moment-comment-input" placeholder="Write a comment…" maxlength="1000" autocomplete="off" />
                <button type="submit" class="moment-comment-send" aria-label="Send comment"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
            <?php else: ?>
            <p class="moment-signin-note">
                <i class="fa-solid fa-right-to-bracket"></i>
                <a href="/BreadBreak/login.php?redirect=moments">Sign in</a> to like or comment on this BreadMoment.
            </p>
            <?php endif; ?>

            <ol class="moment-comment-list" id="momentCommentList">
                <?php if (!$momentTopLevel): ?>
                    <li class="moment-no-comments" id="momentNoComments">No comments yet — be the first to share your thought.</li>
                <?php endif; ?>
                <?php foreach ($momentTopLevel as $momentCommentRow): ?>
                    <?php echo momentCommentHtml($momentCommentRow, $momentIsSignedIn, $momentId, $momentRepliesByParent[(int) $momentCommentRow['id']] ?? []); ?>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</main>

<!-- Report dialog -->
<div class="moment-report-backdrop" id="momentReportBackdrop" hidden></div>
<div class="moment-report" id="momentReportModal" role="dialog" aria-modal="true" aria-labelledby="momentReportTitle" hidden>
    <h2 id="momentReportTitle"><i class="fa-solid fa-flag"></i> Report post</h2>
    <p class="moment-report-sub">Why are you reporting this BreadMoment?</p>
    <form method="POST" action="/BreadBreak/moment.php">
        <input type="hidden" name="action" value="report" />
        <input type="hidden" name="moment_id" value="<?php echo (int) $momentId; ?>" />
        <div class="moment-report-options">
            <?php foreach (momentReportReasons() as $momentReportIndex => $momentReportReason): ?>
            <label class="moment-report-option">
                <input type="radio" name="reason" value="<?php echo momentEscape($momentReportReason); ?>"<?php echo $momentReportIndex === 0 ? ' required' : ''; ?> />
                <span><?php echo momentEscape($momentReportReason); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="moment-report-actions">
            <button type="button" class="btn btn-ghost" id="momentReportCancel">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-flag"></i> Submit report</button>
        </div>
    </form>
</div>

<!-- Simple confirm (delete post / delete comment) -->
<div class="bb-confirm-backdrop" id="bbConfirmBackdrop" hidden></div>
<div class="bb-confirm" id="bbConfirm" role="alertdialog" aria-modal="true" aria-labelledby="bbConfirmText" hidden>
    <span class="bb-confirm-icon" aria-hidden="true"><i class="fa-solid fa-trash"></i></span>
    <p id="bbConfirmText">Delete this post?</p>
    <div class="bb-confirm-actions">
        <button type="button" class="btn btn-ghost" id="bbConfirmCancel">Cancel</button>
        <button type="button" class="btn btn-danger" id="bbConfirmOk">Delete</button>
    </div>
</div>

<script>
(function () {
    var api = '/BreadBreak/api/moment-actions.php';
    var momentId = <?php echo (int) $momentId; ?>;
    var signedIn = <?php echo $momentIsSignedIn ? 'true' : 'false'; ?>;

    function post(data) {
        var body = new URLSearchParams(data);
        return fetch(api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || json.ok === false) {
                    var error = new Error(json.error || 'Something went wrong.');
                    error.status = response.status;
                    throw error;
                }
                return json;
            });
        });
    }

    function setText(id, text) {
        var node = document.getElementById(id);
        if (node) node.textContent = text;
    }

    /* ── Simple confirm dialog (delete post / delete comment) ── */
    var confirmBox = document.getElementById('bbConfirm');
    var confirmBackdrop = document.getElementById('bbConfirmBackdrop');
    var confirmText = document.getElementById('bbConfirmText');
    var confirmOk = document.getElementById('bbConfirmOk');
    var confirmPending = null;

    function closeConfirm(result) {
        if (confirmBox) confirmBox.hidden = true;
        if (confirmBackdrop) confirmBackdrop.hidden = true;
        if (confirmPending) {
            var resolve = confirmPending;
            confirmPending = null;
            resolve(result);
        }
    }

    function simpleConfirm(message, okLabel) {
        if (!confirmBox || !confirmBackdrop) return Promise.resolve(true);
        if (confirmText) confirmText.textContent = message;
        if (confirmOk) confirmOk.textContent = okLabel || 'Delete';
        confirmBox.hidden = false;
        confirmBackdrop.hidden = false;
        var cancelBtn = document.getElementById('bbConfirmCancel');
        if (cancelBtn) cancelBtn.focus();
        return new Promise(function (resolve) { confirmPending = resolve; });
    }

    var confirmCancelBtn = document.getElementById('bbConfirmCancel');
    if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', function () { closeConfirm(false); });
    if (confirmOk) confirmOk.addEventListener('click', function () { closeConfirm(true); });
    if (confirmBackdrop) confirmBackdrop.addEventListener('click', function () { closeConfirm(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && confirmBox && !confirmBox.hidden) closeConfirm(false);
    });

    /* ── 3-dots menu ── */
    var menuBtn = document.getElementById('momentMenuBtn');
    var menuPop = document.getElementById('momentMenuPop');
    if (menuBtn && menuPop) {
        menuBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = menuPop.hidden;
            menuPop.hidden = !open;
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!menuPop.hidden && !menuPop.contains(event.target)) {
                menuPop.hidden = true;
                menuBtn.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                menuPop.hidden = true;
                menuBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* ── Delete post → simple confirm ── */
    var deletePostForm = menuPop ? menuPop.querySelector('form[data-confirm-post-delete]') : null;
    if (deletePostForm) {
        deletePostForm.addEventListener('submit', function (event) {
            event.preventDefault();
            menuPop.hidden = true;
            menuBtn.setAttribute('aria-expanded', 'false');
            simpleConfirm('Delete this post?', 'Delete').then(function (confirmed) {
                if (confirmed) deletePostForm.submit();
            });
        });
    }

    /* ── Report dialog ── */
    var reportOpen = document.getElementById('momentReportOpen');
    var reportModal = document.getElementById('momentReportModal');
    var reportBackdrop = document.getElementById('momentReportBackdrop');
    function closeReport() {
        if (reportModal) reportModal.hidden = true;
        if (reportBackdrop) reportBackdrop.hidden = true;
    }
    if (reportOpen) {
        reportOpen.addEventListener('click', function () {
            if (!signedIn) {
                window.location.href = '/BreadBreak/login.php?redirect=moments';
                return;
            }
            if (reportModal) reportModal.hidden = false;
            if (reportBackdrop) reportBackdrop.hidden = false;
            if (menuPop) menuPop.hidden = true;
        });
    }
    var reportCancel = document.getElementById('momentReportCancel');
    if (reportCancel) reportCancel.addEventListener('click', closeReport);
    if (reportBackdrop) reportBackdrop.addEventListener('click', closeReport);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeReport();
    });

    /* ── Like the post ── */
    var likeBtn = document.getElementById('momentLikePost');
    if (likeBtn) {
        likeBtn.addEventListener('click', function () {
            if (likeBtn.disabled) return;
            likeBtn.disabled = true;
            post({ action: 'like_post', moment_id: momentId })
                .then(function (json) {
                    likeBtn.classList.toggle('is-on', !!json.liked);
                    likeBtn.setAttribute('aria-pressed', json.liked ? 'true' : 'false');
                    setText('momentLikeLabel', json.liked ? 'Liked' : 'Like');
                    var row = document.getElementById('momentLikeCount');
                    if (row) row.parentNode.innerHTML = '<i class="fa-solid fa-heart"></i> <b id="momentLikeCount">' + json.likes + '</b> like' + (json.likes === 1 ? '' : 's');
                })
                .catch(function (error) {
                    if (error.status === 401) window.location.href = '/BreadBreak/login.php?redirect=moments';
                })
                .then(function () { likeBtn.disabled = false; });
        });
    }

    /* ── Jump to comment box ── */
    var jump = document.getElementById('momentJumpComment');
    if (jump) {
        jump.addEventListener('click', function () {
            var section = document.getElementById('momentComments');
            var input = document.getElementById('momentCommentInput');
            if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (input) input.focus();
        });
    }

    function refreshCommentCount(delta) {
        var counter = document.getElementById('momentCommentCount');
        if (!counter) return;
        var current = parseInt((counter.textContent || '').replace(/[^\d]/g, ''), 10) || 0;
        var next = Math.max(0, current + delta);
        counter.textContent = '(' + next + ')';
        var actionCount = document.querySelector('.moment-action-count');
        if (actionCount) actionCount.textContent = '(' + next + ')';
        if (next === 0) {
            var list = document.getElementById('momentCommentList');
            if (list && !document.getElementById('momentNoComments')) {
                list.insertAdjacentHTML('afterbegin', '<li class="moment-no-comments" id="momentNoComments">No comments yet — be the first to share your thought.</li>');
            }
        } else {
            var empty = document.getElementById('momentNoComments');
            if (empty) empty.remove();
        }
    }

    /* ── Post a comment ── */
    var commentForm = document.getElementById('momentCommentForm');
    var commentInput = document.getElementById('momentCommentInput');
    if (commentForm) {
        commentForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var text = commentInput.value.trim();
            if (!text) return;
            post({ action: 'add_comment', moment_id: momentId, body: text })
                .then(function (json) {
                    var list = document.getElementById('momentCommentList');
                    list.insertAdjacentHTML('beforeend', json.html);
                    commentInput.value = '';
                    refreshCommentCount(1);
                })
                .catch(function (error) {
                    if (error.status === 401) window.location.href = '/BreadBreak/login.php?redirect=moments';
                    else alert(error.message);
                });
        });
    }

    /* ── Delegated: like a comment / reply / more replies / delete ── */
    document.addEventListener('click', function (event) {
        var likeBtn2 = event.target.closest('[data-like-comment]');
        if (likeBtn2) {
            if (likeBtn2.disabled) return;
            likeBtn2.disabled = true;
            post({ action: 'like_comment', comment_id: likeBtn2.getAttribute('data-like-comment') })
                .then(function (json) {
                    likeBtn2.classList.toggle('is-on', !!json.liked);
                    likeBtn2.setAttribute('aria-pressed', json.liked ? 'true' : 'false');
                    var label2 = likeBtn2.querySelector('[data-like-label]');
                    if (label2) label2.textContent = json.liked ? 'Liked' : 'Like';
                    var actionsRow = likeBtn2.closest('.moment-comment-actions');
                    var countNode = actionsRow ? actionsRow.querySelector('[data-like-count]') : null;
                    if (countNode) {
                        countNode.textContent = json.likes;
                        countNode.hidden = json.likes === 0;
                    }
                })
                .catch(function (error) {
                    if (error.status === 401) window.location.href = '/BreadBreak/login.php?redirect=moments';
                })
                .then(function () { likeBtn2.disabled = false; });
            return;
        }

        var replyOpen = event.target.closest('[data-reply-to]');
        if (replyOpen) {
            var comment = replyOpen.closest('.moment-comment');
            var form = comment ? comment.querySelector('.moment-reply-form') : null;
            if (form) {
                form.hidden = false;
                var replyInput = form.querySelector('.moment-reply-input');
                if (replyInput) replyInput.focus();
            }
            return;
        }

        var replyClose = event.target.closest('[data-cancel-reply]');
        if (replyClose) {
            var host = replyClose.closest('.moment-reply-form');
            if (host) {
                host.hidden = true;
                var input = host.querySelector('.moment-reply-input');
                if (input) input.value = '';
            }
            return;
        }

        var moreReplies = event.target.closest('[data-more-replies]');
        if (moreReplies) {
            var root = moreReplies.closest('.moment-comment');
            if (root) {
                Array.prototype.forEach.call(root.querySelectorAll('.moment-comment.is-reply[hidden]'), function (reply) {
                    reply.hidden = false;
                });
            }
            // Hidden replies are already part of the total count — just reveal.
            moreReplies.remove();
            return;
        }

        var deleteComment = event.target.closest('[data-delete-comment]');
        if (deleteComment) {
            simpleConfirm('Delete this comment?', 'Delete').then(function (confirmed) {
                if (!confirmed) return;
                var commentEl = deleteComment.closest('.moment-comment');
                var removedIds = [deleteComment.getAttribute('data-delete-comment')];
                if (commentEl && !commentEl.classList.contains('is-reply')) {
                    Array.prototype.forEach.call(commentEl.querySelectorAll('.moment-replies .moment-comment'), function (reply) {
                        removedIds.push(reply.getAttribute('data-comment-id'));
                    });
                }
                post({ action: 'delete_comment', moment_id: momentId, comment_id: removedIds[0] })
                .then(function () {
                    if (commentEl) commentEl.remove();
                    var counter = document.getElementById('momentCommentCount');
                    var current = parseInt((counter ? counter.textContent : '').replace(/[^\d]/g, ''), 10) || 0;
                    var next = Math.max(0, current - removedIds.length);
                    if (counter) counter.textContent = '(' + next + ')';
                    var actionCount = document.querySelector('.moment-action-count');
                    if (actionCount) actionCount.textContent = '(' + next + ')';
                    if (next === 0) {
                        var list = document.getElementById('momentCommentList');
                        if (list && !document.getElementById('momentNoComments')) {
                            list.insertAdjacentHTML('afterbegin', '<li class="moment-no-comments" id="momentNoComments">No comments yet — be the first to share your thought.</li>');
                        }
                    } else {
                        var empty = document.getElementById('momentNoComments');
                        if (empty) empty.remove();
                    }
                })
                .catch(function (error) {
                    alert(error.message);
                });
            });
            return;
        }
    });

    /* ── Reply submit ── */
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.moment-reply-form');
        if (!form) return;
        event.preventDefault();
        var input = form.querySelector('.moment-reply-input');
        var text = input ? input.value.trim() : '';
        if (!text) return;
        var root = form.closest('.moment-comment');
        post({
            action: 'add_comment',
            moment_id: momentId,
            parent_id: form.getAttribute('data-parent'),
            body: text
        }).then(function (json) {
            var replies = root.querySelector('.moment-replies');
            if (!replies) {
                // Replies belong INSIDE the comment column so they sit below it.
                var commentMain = root.querySelector('.moment-comment-main');
                if (commentMain) commentMain.insertAdjacentHTML('beforeend', '<ol class="moment-replies"></ol>');
                replies = root.querySelector('.moment-replies');
            }
            if (replies) replies.insertAdjacentHTML('beforeend', json.html);
            var inserted = replies ? replies.lastElementChild : null;
            if (inserted) inserted.hidden = false;
            input.value = '';
            form.hidden = true;
            refreshCommentCount(1);
        }).catch(function (error) {
            if (error.status === 401) window.location.href = '/BreadBreak/login.php?redirect=moments';
            else alert(error.message);
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
