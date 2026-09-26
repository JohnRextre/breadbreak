<?php
/**
 * BreadMoments — shared bootstrap for the feed (moments.php), the post detail
 * page (moment.php) and the JSON endpoint (api/moment-actions.php).
 *
 * Provides the PDO connection + idempotent migrations for every BreadMoments
 * table, small formatting helpers and the comment partial so the page and the
 * AJAX endpoint always render comments identically.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

const MOMENT_MAX_PHOTOS = 4;
const MOMENT_MAX_BYTES = 8 * 1024 * 1024; // 8MB per photo
const MOMENT_MAX_TOPICS = 5;
const MOMENT_REPLIES_VISIBLE = 2; // replies shown before the "more" button

/* ── Helpers ──────────────────────────────────────────────────────────── */

function momentEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function momentTimeAgo(?string $datetime): string
{
    if (!$datetime) return '';
    $then = strtotime($datetime);
    if (!$then) return '';
    $diff = time() - $then;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' min ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $then);
}

/**
 * Store identity used wherever a staff promotion shows up (feed cards, detail
 * header) — promotions are posted on behalf of the bakery, not the staff member.
 */
const MOMENT_STORE_NAME = 'BreadBreak Store';

/** Feed visibility SQL — only approved, live posts. */
function momentVisibleSql(string $alias = 'm'): string
{
    return $alias . ".moderation_status = 'visible'";
}

/** [label, css-class] pair for a moderation status pill. */
function momentStatusLabel(string $status): array
{
    switch ($status) {
        case 'pending':  return ['Pending review', 'pending'];
        case 'rejected': return ['Rejected', 'rejected'];
        case 'removed':  return ['Removed', 'removed'];
        default:         return ['Live', 'live'];
    }
}

/** One view per signed-in user, or per guest session — refreshing never inflates it. */
function momentViewerKey(): string
{
    if (!empty($_SESSION['user_id'])) {
        return 'u' . (int) $_SESSION['user_id'];
    }
    return 's' . session_id();
}

/* ── Connection + migrations ──────────────────────────────────────────── */

function momentDb(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        $pdo = getDatabaseConnection();
        momentMigrate($pdo);
    } catch (Throwable $momentDbException) {
        $GLOBALS['moment_db_error'] = 'We could not reach the database just yet — please refresh in a moment.';
        $pdo = null;
    }
    return $pdo;
}

function momentDbError(): string
{
    return $GLOBALS['moment_db_error'] ?? '';
}

/** Creates every BreadMoments table / column on first visit (idempotent). */
function momentMigrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT DEFAULT NULL,
        title VARCHAR(140) NOT NULL,
        body TEXT NOT NULL,
        topics VARCHAR(255) NOT NULL DEFAULT '',
        views_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bread_moments_created (created_at),
        KEY idx_bread_moments_user (user_id),
        CONSTRAINT fk_bread_moments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upgrade posts created before views existed.
    if (!$pdo->query("SHOW COLUMNS FROM bread_moments LIKE 'views_count'")->fetch()) {
        $pdo->exec("ALTER TABLE bread_moments ADD COLUMN views_count INT NOT NULL DEFAULT 0 AFTER topics");
    }

    // Moderation + store promotions (staff submit → admin review → feed).
    $momentModerationColumns = [
        'post_type'         => "ENUM('moment','promotion') NOT NULL DEFAULT 'moment' AFTER id",
        'product_id'        => "INT DEFAULT NULL AFTER category_id",
        'moderation_status' => "ENUM('visible','pending','rejected','removed') NOT NULL DEFAULT 'visible' AFTER views_count",
        'moderation_reason' => "VARCHAR(500) DEFAULT NULL AFTER moderation_status",
        'moderated_by'      => "INT DEFAULT NULL AFTER moderation_reason",
        'moderated_at'      => "DATETIME DEFAULT NULL AFTER moderated_by",
    ];
    foreach ($momentModerationColumns as $momentColumn => $momentDdl) {
        if (!$pdo->query("SHOW COLUMNS FROM bread_moments LIKE '{$momentColumn}'")->fetch()) {
            $pdo->exec("ALTER TABLE bread_moments ADD COLUMN {$momentColumn} {$momentDdl}");
        }
    }
    if (!$pdo->query("SHOW INDEX FROM bread_moments WHERE Key_name = 'idx_bread_moments_moderation'")->fetch()) {
        $pdo->exec("ALTER TABLE bread_moments ADD KEY idx_bread_moments_moderation (moderation_status)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moment_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moment_id INT NOT NULL,
        photo_data MEDIUMBLOB NOT NULL,
        photo_mime VARCHAR(64) NOT NULL,
        position TINYINT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_bread_moment_photos_moment (moment_id),
        CONSTRAINT fk_bread_moment_photos_moment FOREIGN KEY (moment_id) REFERENCES bread_moments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moment_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moment_id INT NOT NULL,
        viewer_key VARCHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bread_moment_viewer (moment_id, viewer_key),
        CONSTRAINT fk_bread_moment_views_moment FOREIGN KEY (moment_id) REFERENCES bread_moments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moment_likes (
        moment_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (moment_id, user_id),
        CONSTRAINT fk_bread_moment_likes_moment FOREIGN KEY (moment_id) REFERENCES bread_moments(id) ON DELETE CASCADE,
        CONSTRAINT fk_bread_moment_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moment_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moment_id INT NOT NULL,
        user_id INT NOT NULL,
        parent_id INT DEFAULT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bread_moment_comments_moment (moment_id),
        KEY idx_bread_moment_comments_parent (parent_id),
        CONSTRAINT fk_bread_moment_comments_moment FOREIGN KEY (moment_id) REFERENCES bread_moments(id) ON DELETE CASCADE,
        CONSTRAINT fk_bread_moment_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_bread_moment_comments_parent FOREIGN KEY (parent_id) REFERENCES bread_moment_comments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moment_comment_likes (
        comment_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (comment_id, user_id),
        CONSTRAINT fk_bread_moment_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES bread_moment_comments(id) ON DELETE CASCADE,
        CONSTRAINT fk_bread_moment_comment_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bread_moment_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moment_id INT NOT NULL,
        user_id INT NOT NULL,
        reason VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bread_moment_reporter (moment_id, user_id),
        CONSTRAINT fk_bread_moment_reports_moment FOREIGN KEY (moment_id) REFERENCES bread_moments(id) ON DELETE CASCADE,
        CONSTRAINT fk_bread_moment_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Whitelisted report reasons — anything else is rejected server-side. */
function momentReportReasons(): array
{
    return [
        'Spam or misleading',
        'Inappropriate content',
        'Harassment or bullying',
        'Not a bakery moment',
        'Something else',
    ];
}

/* ── Comment partials (shared by moment.php and the JSON endpoint) ────── */

/**
 * Renders one comment row. $comment needs: id, user_id, first_name, body,
 * created_at, like_count, liked. Replies ($isReply) skip the reply button.
 */
function momentCommentItemHtml(array $comment, bool $signedIn, bool $isReply = false): string
{
    $viewerId = (int) ($_SESSION['user_id'] ?? 0);
    $id = (int) $comment['id'];
    $mine = $signedIn && (int) $comment['user_id'] === $viewerId;
    $initial = strtoupper(substr(trim((string) $comment['first_name']), 0, 1)) ?: '?';
    $likeCount = (int) ($comment['like_count'] ?? 0);
    $liked = !empty($comment['liked']);

    // Profile photo when the account has one, otherwise the initial.
    $photoSrc = '';
    if (!empty($comment['profile_data']) && !empty($comment['profile_mime'])) {
        $photoSrc = 'data:' . $comment['profile_mime'] . ';base64,' . base64_encode($comment['profile_data']);
    }

    $html  = '<li class="moment-comment' . ($isReply ? ' is-reply' : '') . '" id="moment-comment-' . $id . '" data-comment-id="' . $id . '"' . ($isReply ? '' : ' data-root="1"') . '>';
    $html .= $photoSrc
        ? '<span class="moment-avatar"><img src="' . momentEscape($photoSrc) . '" alt="' . momentEscape($comment['first_name']) . '" /></span>'
        : '<span class="moment-avatar" aria-hidden="true">' . momentEscape($initial) . '</span>';
    $html .= '<div class="moment-comment-main">';
    $html .= '<div class="moment-comment-bubble">';
    $html .= '<span class="moment-comment-name">' . momentEscape($comment['first_name']) . '</span>';
    $html .= '<span class="moment-comment-time">' . momentEscape(momentTimeAgo($comment['created_at'])) . '</span>';
    $html .= '<p>' . nl2br(momentEscape($comment['body'])) . '</p>';
    $html .= '</div>';
    $html .= '<div class="moment-comment-actions">';

    if ($signedIn) {
        $html .= '<button type="button" class="moment-comment-like' . ($liked ? ' is-on' : '') . '" data-like-comment="' . $id . '" aria-pressed="' . ($liked ? 'true' : 'false') . '">';
        $html .= '<i class="fa-solid fa-thumbs-up"></i> <span data-like-label>' . ($liked ? 'Liked' : 'Like') . '</span></button>';
    } else {
        $html .= '<span class="moment-comment-like is-static"><i class="fa-solid fa-thumbs-up"></i></span>';
    }
    $html .= '<span class="moment-comment-like-count"' . ($likeCount > 0 ? '' : ' hidden') . ' data-like-count>' . $likeCount . '</span>';

    if ($signedIn && !$isReply) {
        $html .= '<button type="button" class="moment-comment-reply" data-reply-to="' . $id . '"><i class="fa-solid fa-reply"></i> Reply</button>';
    }
    if ($mine) {
        $html .= '<button type="button" class="moment-comment-delete" data-delete-comment="' . $id . '"><i class="fa-solid fa-trash"></i> Delete</button>';
    }

    $html .= '</div>';

    if (!$isReply && $signedIn) {
        $html .= '<form class="moment-reply-form" data-moment="' . (int) ($comment['moment_id'] ?? 0) . '" data-parent="' . $id . '" hidden>';
        $html .= '<input type="text" class="moment-reply-input" name="body" maxlength="1000" placeholder="Reply to ' . momentEscape($comment['first_name']) . '…" required />';
        $html .= '<button type="submit" class="moment-reply-send"><i class="fa-solid fa-paper-plane"></i> Reply</button>';
        $html .= '<button type="button" class="moment-reply-cancel" data-cancel-reply>Cancel</button>';
        $html .= '</form>';
    }

    $html .= '</div>';
    $html .= '</li>';
    return $html;
}

/**
 * Renders a top-level comment: its bubble, actions and reply form plus the
 * nested replies (only the first MOMENT_REPLIES_VISIBLE are shown, the rest
 * stay collapsed behind a "View more replies" button — Facebook style).
 */
function momentCommentHtml(array $comment, bool $signedIn, int $momentId, array $replies = []): string
{
    $comment['moment_id'] = $momentId;
    $html = momentCommentItemHtml($comment, $signedIn, false);

    if ($replies) {
        $values = array_values($replies);
        $total = count($values);
        $replyList = '';
        foreach ($values as $index => $reply) {
            $item = momentCommentItemHtml($reply, $signedIn, true);
            if ($index >= MOMENT_REPLIES_VISIBLE) {
                // Collapsed until the visitor presses "View more replies".
                $item = str_replace('<li class="moment-comment is-reply"', '<li class="moment-comment is-reply" hidden', $item);
            }
            $replyList .= $item;
        }

        $extra = '<ol class="moment-replies">' . $replyList . '</ol>';
        if ($total > MOMENT_REPLIES_VISIBLE) {
            $extra .= '<button type="button" class="moment-more-replies" data-more-replies>View more replies (' . ($total - MOMENT_REPLIES_VISIBLE) . ' more)</button>';
        }

        // Nest the replies INSIDE the comment column (before its closing
        // </div></li>) so they stack directly BELOW the comment instead of
        // sitting beside it as a flex sibling.
        $closeAt = strrpos($html, '</div></li>');
        if ($closeAt !== false) {
            $html = substr($html, 0, $closeAt) . $extra . substr($html, $closeAt);
        }
    }

    return $html;
}
