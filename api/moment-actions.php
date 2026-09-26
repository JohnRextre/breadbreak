<?php
/**
 * BreadMoments AJAX endpoint — post likes, comments, replies and comment
 * deletion for moment.php. Answers JSON only: { ok: true, ... } or
 * { ok: false, error: "..." } with a matching HTTP status.
 */

require_once __DIR__ . '/../includes/moments.php';

header('Content-Type: application/json; charset=utf-8');

function momentApiFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function momentApiOk(array $payload = []): void
{
    echo json_encode(['ok' => true] + $payload);
    exit;
}

/**
 * Hidden posts (waiting for review, rejected or removed) only answer to their
 * owner and the admin — everybody else gets a 403.
 */
function momentApiAssertVisible(array $post, int $viewerId): void
{
    if (($post['moderation_status'] ?? 'visible') === 'visible') {
        return;
    }
    $role = $_SESSION['role'] ?? '';
    if ((int) ($post['user_id'] ?? 0) !== $viewerId && $role !== 'admin') {
        momentApiFail(403, 'This post is not available right now.');
    }
}

/** Loads a post row for moderation checks (404 when it is gone). */
function momentApiGuardPost(PDO $pdo, int $momentId, int $viewerId): void
{
    $statement = $pdo->prepare('SELECT user_id, moderation_status FROM bread_moments WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $momentId]);
    $post = $statement->fetch();
    if (!$post) momentApiFail(404, 'That post no longer exists.');
    momentApiAssertVisible($post, $viewerId);
}

/** Same guard reached through one of the post's comments. */
function momentApiGuardComment(PDO $pdo, int $commentId, int $viewerId): void
{
    $statement = $pdo->prepare(
        'SELECT m.user_id, m.moderation_status
         FROM bread_moment_comments c
         JOIN bread_moments m ON m.id = c.moment_id
         WHERE c.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $commentId]);
    $post = $statement->fetch();
    if (!$post) momentApiFail(404, 'That comment no longer exists.');
    momentApiAssertVisible($post, $viewerId);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    momentApiFail(405, 'POST only.');
}

$momentPdo = momentDb();
if (!$momentPdo) {
    momentApiFail(503, momentDbError() ?: 'Database unavailable. Please try again.');
}

$momentUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($momentUserId <= 0) {
    momentApiFail(401, 'Please sign in first.');
}

$momentAction = (string) ($_POST['action'] ?? '');
$momentId = (int) ($_POST['moment_id'] ?? 0);
$momentCommentId = (int) ($_POST['comment_id'] ?? 0);
$momentParentId = (int) ($_POST['parent_id'] ?? 0);

try {
    switch ($momentAction) {
        case 'like_post':
        {
            if (!$momentId) momentApiFail(400, 'Missing post.');
            momentApiGuardPost($momentPdo, $momentId, $momentUserId);

            $momentCheck = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_likes WHERE moment_id = :moment_id AND user_id = :user_id');
            $momentCheck->execute(['moment_id' => $momentId, 'user_id' => $momentUserId]);
            $momentLiked = (bool) $momentCheck->fetchColumn();

            if ($momentLiked) {
                $momentToggle = $momentPdo->prepare('DELETE FROM bread_moment_likes WHERE moment_id = :moment_id AND user_id = :user_id');
            } else {
                $momentToggle = $momentPdo->prepare('INSERT INTO bread_moment_likes (moment_id, user_id) VALUES (:moment_id, :user_id)');
            }
            $momentToggle->execute(['moment_id' => $momentId, 'user_id' => $momentUserId]);

            $momentCount = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_likes WHERE moment_id = :moment_id');
            $momentCount->execute(['moment_id' => $momentId]);

            momentApiOk(['liked' => !$momentLiked, 'likes' => (int) $momentCount->fetchColumn()]);
            break;
        }

        case 'like_comment':
        {
            if (!$momentCommentId) momentApiFail(400, 'Missing comment.');
            $momentComment = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_comments WHERE id = :id');
            $momentComment->execute(['id' => $momentCommentId]);
            if (!$momentComment->fetchColumn()) momentApiFail(404, 'That comment no longer exists.');
            momentApiGuardComment($momentPdo, $momentCommentId, $momentUserId);

            $momentCheck = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_comment_likes WHERE comment_id = :comment_id AND user_id = :user_id');
            $momentCheck->execute(['comment_id' => $momentCommentId, 'user_id' => $momentUserId]);
            $momentLiked = (bool) $momentCheck->fetchColumn();

            if ($momentLiked) {
                $momentToggle = $momentPdo->prepare('DELETE FROM bread_moment_comment_likes WHERE comment_id = :comment_id AND user_id = :user_id');
            } else {
                $momentToggle = $momentPdo->prepare('INSERT INTO bread_moment_comment_likes (comment_id, user_id) VALUES (:comment_id, :user_id)');
            }
            $momentToggle->execute(['comment_id' => $momentCommentId, 'user_id' => $momentUserId]);

            $momentCount = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_comment_likes WHERE comment_id = :comment_id');
            $momentCount->execute(['comment_id' => $momentCommentId]);

            momentApiOk(['liked' => !$momentLiked, 'likes' => (int) $momentCount->fetchColumn()]);
            break;
        }

        case 'add_comment':
        {
            $momentBody = trim((string) ($_POST['body'] ?? ''));
            if ($momentBody === '') momentApiFail(400, 'Write something first.');
            if (mb_strlen($momentBody) > 1000) momentApiFail(400, 'Keep your comment under 1,000 characters.');
            if (!$momentId) momentApiFail(400, 'Missing post.');

            momentApiGuardPost($momentPdo, $momentId, $momentUserId);

            // One level only: replying to a reply attaches to its root comment.
            $momentParent = null;
            if ($momentParentId) {
                $momentParentStatement = $momentPdo->prepare(
                    'SELECT id, parent_id FROM bread_moment_comments WHERE id = :id AND moment_id = :moment_id'
                );
                $momentParentStatement->execute(['id' => $momentParentId, 'moment_id' => $momentId]);
                $momentParentRow = $momentParentStatement->fetch();
                if (!$momentParentRow) momentApiFail(404, 'That comment no longer exists.');
                $momentParent = $momentParentRow['parent_id'] !== null ? (int) $momentParentRow['parent_id'] : (int) $momentParentRow['id'];
            }

            $momentInsert = $momentPdo->prepare(
                'INSERT INTO bread_moment_comments (moment_id, user_id, parent_id, body) VALUES (:moment_id, :user_id, :parent_id, :body)'
            );
            $momentInsert->execute([
                'moment_id' => $momentId,
                'user_id'   => $momentUserId,
                'parent_id' => $momentParent,
                'body'      => $momentBody,
            ]);
            $momentNewId = (int) $momentPdo->lastInsertId();

            $momentName = $momentPdo->prepare('SELECT first_name, profile_data, profile_mime FROM users WHERE id = :id');
            $momentName->execute(['id' => $momentUserId]);
            $momentAuthor = $momentName->fetch() ?: [];

            $momentComment = [
                'id'           => $momentNewId,
                'moment_id'    => $momentId,
                'user_id'      => $momentUserId,
                'parent_id'    => $momentParent,
                'body'         => $momentBody,
                'created_at'   => date('Y-m-d H:i:s'),
                'first_name'   => (string) ($momentAuthor['first_name'] ?? ''),
                'profile_data' => $momentAuthor['profile_data'] ?? null,
                'profile_mime' => $momentAuthor['profile_mime'] ?? null,
                'like_count'   => 0,
                'liked'        => 0,
            ];

            $momentCount = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_comments WHERE moment_id = :moment_id');
            $momentCount->execute(['moment_id' => $momentId]);

            momentApiOk([
                'html'  => momentCommentItemHtml($momentComment, true, $momentParent !== null),
                'count' => (int) $momentCount->fetchColumn(),
                'reply' => $momentParent !== null,
            ]);
            break;
        }

        case 'delete_comment':
        {
            if (!$momentCommentId) momentApiFail(400, 'Missing comment.');
            momentApiGuardComment($momentPdo, $momentCommentId, $momentUserId);
            $momentDelete = $momentPdo->prepare('DELETE FROM bread_moment_comments WHERE id = :id AND user_id = :user_id');
            $momentDelete->execute(['id' => $momentCommentId, 'user_id' => $momentUserId]);
            if (!$momentDelete->rowCount()) momentApiFail(403, 'You can only delete your own comments.');

            $momentCount = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moment_comments WHERE moment_id = :moment_id');
            $momentCount->execute(['moment_id' => $momentId]);

            momentApiOk(['count' => (int) $momentCount->fetchColumn()]);
            break;
        }

        default:
            momentApiFail(400, 'Unknown action.');
    }
} catch (Throwable $momentApiException) {
    momentApiFail(500, 'Something went wrong. Please try again.');
}
