<?php
// =============================================================================
// admin/reviews.php  –  Customer Reviews & Ratings (Admin)
// Everything a customer leaves after delivery: stars, the written topics, the
// traits they ticked, and the photos / video they attached. Attachments are
// streamed through api/review-media.php (staff access) and open in a lightbox.
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reviews.php';

$pageTitle = 'Customer Reviews';
$activePage = 'reviews';

$pdo = getDatabaseConnection();
ensureReviewSupport($pdo);

$search = trim($_GET['search'] ?? '');
$ratingFilter = trim($_GET['rating'] ?? 'all');
$sortBy = trim($_GET['sort'] ?? 'newest');

// ── Filters ────────────────────────────────────────────────────────────────
$where = 'WHERE 1=1';
$params = [];

if ($search !== '') {
    $where .= " AND (o.reference_id LIKE :sref
                  OR CONCAT(u.first_name, ' ', u.last_name) LIKE :sname
                  OR u.email LIKE :semail)";
    $like = '%' . $search . '%';
    $params['sref'] = $like;
    $params['sname'] = $like;
    $params['semail'] = $like;
}

if (in_array($ratingFilter, ['5', '4', '3', '2', '1'], true)) {
    $where .= ' AND r.food_rating = :rating';
    $params['rating'] = (int) $ratingFilter;
} elseif ($ratingFilter === 'low') {
    $where .= ' AND r.food_rating <= 2';
} elseif ($ratingFilter === 'media') {
    $where .= ' AND EXISTS (SELECT 1 FROM order_review_media m WHERE m.review_id = r.id)';
}

$orderSql = match ($sortBy) {
    'oldest' => 'r.created_at ASC',
    'highest' => 'r.food_rating DESC, r.created_at DESC',
    'lowest' => 'r.food_rating ASC, r.created_at DESC',
    default => 'r.created_at DESC',
};

$reviewStmt = $pdo->prepare(
    "SELECT r.id, r.food_rating, r.shop_service_rating, r.delivery_speed_rating,
            r.driver_service_rating, r.food_topics, r.service_story, r.traits, r.created_at,
            o.reference_id, o.status AS order_status,
            u.first_name, u.last_name
     FROM order_reviews r
     JOIN orders o ON o.id = r.order_id
     JOIN users u ON u.id = r.customer_id
     $where
     ORDER BY $orderSql"
);
$reviewStmt->execute($params);
$reviews = $reviewStmt->fetchAll();

// Attachments for all visible reviews in one query.
$mediaMap = [];
$reviewIds = array_map('intval', array_column($reviews, 'id'));
if ($reviewIds) {
    $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
    $mediaStmt = $pdo->prepare(
        "SELECT id, review_id, media_kind, media_name
         FROM order_review_media
         WHERE review_id IN ($placeholders)
         ORDER BY sort_order ASC, id ASC"
    );
    $mediaStmt->execute($reviewIds);
    foreach ($mediaStmt->fetchAll() as $media) {
        $mediaMap[(int) $media['review_id']][] = $media;
    }
}

// ── Page-wide totals ───────────────────────────────────────────────────────
$totals = $pdo->query(
    'SELECT COUNT(*) AS total,
            AVG(food_rating) AS avg_food,
            SUM(food_rating <= 2) AS low_count
     FROM order_reviews'
)->fetch() ?: [];
$totalReviews = (int) ($totals['total'] ?? 0);
$avgFood = $totalReviews ? round((float) $totals['avg_food'], 1) : 0.0;
$lowCount = (int) ($totals['low_count'] ?? 0);
$withMedia = $totalReviews
    ? (int) $pdo->query('SELECT COUNT(DISTINCT review_id) FROM order_review_media')->fetchColumn()
    : 0;

$topics = reviewFoodTopics();
$traits = reviewTraits();

/** Five stars, gold up to the value and faded after it. */
function rvStars(?int $value): string
{
    if (!$value) {
        return '<span class="rv-stars is-empty"><em>not rated</em></span>';
    }
    $out = '<span class="rv-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<i class="fa-solid fa-star' . ($i <= $value ? ' is-on' : '') . '"></i>';
    }
    $out .= '</span>';
    return $out;
}

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro users-page-intro" style="margin-bottom: 22px;">
    <div>
        <span class="eyebrow" style="font-size: 11px; font-weight: 800; color: var(--admin-brown); letter-spacing: 0.1em; text-transform: uppercase;">Feedback &amp; Ratings</span>
        <h2 style="margin: 4px 0 6px; font-family: 'Manrope', sans-serif; font-size: 26px; color: var(--admin-ink);">Customer Reviews</h2>
        <p style="margin: 0; color: var(--admin-muted); font-size: 13px;">Ratings, written feedback, and the photos and video customers attached to their orders.</p>
    </div>
</section>

<section class="orders-summary-grid">
    <article class="summary-card">
        <div class="summary-top">
            <span>Total Reviews</span>
            <div class="summary-icon" style="background: rgba(123, 82, 59, 0.1); color: var(--admin-brown);">
                <i class="fa-solid fa-star"></i>
            </div>
        </div>
        <div class="summary-value"><?php echo $totalReviews; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Average Food Rating</span>
            <div class="summary-icon" style="background: rgba(240, 168, 48, 0.14); color: #b9821d;">
                <i class="fa-solid fa-star-half-stroke"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #b9821d;"><?php echo $totalReviews ? number_format($avgFood, 1) : '—'; ?><small style="font-size: 13px; color: var(--admin-muted); font-weight: 700;"> / 5</small></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>With Photos / Video</span>
            <div class="summary-icon" style="background: rgba(53, 109, 145, 0.1); color: var(--admin-blue);">
                <i class="fa-solid fa-camera"></i>
            </div>
        </div>
        <div class="summary-value" style="color: var(--admin-blue);"><?php echo $withMedia; ?></div>
    </article>
    <article class="summary-card">
        <div class="summary-top">
            <span>Low Ratings (1–2)</span>
            <div class="summary-icon" style="background: rgba(200, 40, 40, 0.1); color: #b23026;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
        <div class="summary-value" style="color: #b23026;"><?php echo $lowCount; ?></div>
    </article>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h3>All Reviews</h3>
            <p class="panel-subtitle"><?php echo $totalReviews; ?> review<?php echo $totalReviews === 1 ? '' : 's'; ?> on file · newest first</p>
        </div>
    </div>

    <form method="GET" class="orders-filter-bar" style="margin-bottom: 20px;">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="search" placeholder="Search by order #, customer name, email..." value="<?php echo htmlspecialchars($search); ?>" />
        </div>
        <div class="filter-actions">
            <div class="select-control" style="margin: 0;">
                <select name="rating" onchange="this.form.submit()" style="height: 42px; border-radius: 8px; border: 1px solid var(--admin-line); padding: 0 32px 0 12px; font-size: 13px; font-weight: 600; color: var(--admin-ink);">
                    <option value="all" <?php echo $ratingFilter === 'all' ? 'selected' : ''; ?>>All ratings</option>
                    <option value="5" <?php echo $ratingFilter === '5' ? 'selected' : ''; ?>>5 stars</option>
                    <option value="4" <?php echo $ratingFilter === '4' ? 'selected' : ''; ?>>4 stars</option>
                    <option value="3" <?php echo $ratingFilter === '3' ? 'selected' : ''; ?>>3 stars</option>
                    <option value="2" <?php echo $ratingFilter === '2' ? 'selected' : ''; ?>>2 stars</option>
                    <option value="1" <?php echo $ratingFilter === '1' ? 'selected' : ''; ?>>1 star</option>
                    <option value="low" <?php echo $ratingFilter === 'low' ? 'selected' : ''; ?>>1–2 stars (unhappy)</option>
                    <option value="media" <?php echo $ratingFilter === 'media' ? 'selected' : ''; ?>>With photos / video</option>
                </select>
            </div>
            <div class="select-control" style="margin: 0;">
                <select name="sort" onchange="this.form.submit()" style="height: 42px; border-radius: 8px; border: 1px solid var(--admin-line); padding: 0 32px 0 12px; font-size: 13px; font-weight: 600; color: var(--admin-ink);">
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                    <option value="highest" <?php echo $sortBy === 'highest' ? 'selected' : ''; ?>>Highest rated</option>
                    <option value="lowest" <?php echo $sortBy === 'lowest' ? 'selected' : ''; ?>>Lowest rated</option>
                </select>
            </div>
            <button type="submit" class="admin-button primary" style="height: 42px; border-radius: 8px; padding: 0 18px;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if ($search !== '' || $ratingFilter !== 'all' || $sortBy !== 'newest'): ?>
                <a href="reviews.php" class="admin-button secondary" style="height: 42px; border-radius: 8px; padding: 0 16px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$reviews): ?>
        <div class="empty-state" style="text-align: center; padding: 60px 20px;">
            <div style="width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; background: #faf7f4; display: flex; align-items: center; justify-content: center; font-size: 26px; color: var(--admin-muted);">
                <i class="fa-solid fa-star"></i>
            </div>
            <h3 style="color: var(--admin-brown-dark); margin: 0 0 6px; font-size: 16px;">No reviews yet</h3>
            <p style="color: var(--admin-muted); font-size: 13px; margin: 0;">Customer reviews will appear here as soon as they rate a delivered order.</p>
        </div>
    <?php else: ?>
        <div class="rv-list">
            <?php foreach ($reviews as $review):
                $reviewId = (int) $review['id'];
                $media = $mediaMap[$reviewId] ?? [];
                $filledTopics = reviewTopicsFilled($review['food_topics'] ?? '');
                $tickedTraits = reviewTraitList($review['traits'] ?? '');
                $firstName = (string) $review['first_name'];
                $lastName = (string) $review['last_name'];
                $customerName = trim($firstName . ' ' . $lastName);
                $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
                $searchUrl = 'orders.php?search=' . urlencode($review['reference_id']);
            ?>
            <article class="rv-card">
                <header class="rv-card-head">
                    <div class="rv-who">
                        <span class="rv-avatar"><?php echo htmlspecialchars($initials); ?></span>
                        <div class="rv-who-copy">
                            <strong><?php echo htmlspecialchars($customerName); ?></strong>
                            <small>
                                <a href="<?php echo htmlspecialchars($searchUrl); ?>" title="Open this order">
                                    <i class="fa-solid fa-receipt"></i> #<?php echo htmlspecialchars($review['reference_id']); ?>
                                </a>
                                · <?php echo date('M j, Y · g:i A', strtotime($review['created_at'])); ?>
                            </small>
                        </div>
                    </div>
                    <div class="rv-score">
                        <i class="fa-solid fa-star"></i>
                        <span><?php echo (int) $review['food_rating']; ?>.0</span>
                        <small>Food</small>
                    </div>
                </header>

                <div class="rv-ratings">
                    <div><span>Shop Service</span><?php echo rvStars($review['shop_service_rating'] ? (int) $review['shop_service_rating'] : null); ?></div>
                    <div><span>Delivery Speed</span><?php echo rvStars($review['delivery_speed_rating'] ? (int) $review['delivery_speed_rating'] : null); ?></div>
                    <div><span>Driver Service</span><?php echo rvStars($review['driver_service_rating'] ? (int) $review['driver_service_rating'] : null); ?></div>
                </div>

                <?php if ($filledTopics): ?>
                    <dl class="rv-topics">
                        <?php foreach ($filledTopics as $key => $text): ?>
                            <dt><?php echo htmlspecialchars($topics[$key] ?? ucwords(str_replace('_', ' ', $key))); ?></dt>
                            <dd><?php echo htmlspecialchars($text); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>

                <?php if (trim((string) ($review['service_story'] ?? '')) !== ''): ?>
                    <p class="rv-story">
                        <i class="fa-solid fa-quote-left"></i>
                        <?php echo htmlspecialchars($review['service_story']); ?>
                    </p>
                <?php endif; ?>

                <?php if ($tickedTraits): ?>
                    <div class="rv-traits">
                        <?php foreach ($tickedTraits as $key): ?>
                            <span><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($traits[$key] ?? ucwords(str_replace('_', ' ', $key))); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($media): ?>
                    <div class="rv-media">
                        <?php foreach ($media as $item):
                            $isVideo = $item['media_kind'] === 'video';
                            $src = BASE_URL . '/api/review-media.php?id=' . (int) $item['id'];
                            $name = (string) ($item['media_name'] ?: ($isVideo ? 'video' : 'photo'));
                        ?>
                            <button
                                type="button"
                                class="rv-media-tile"
                                data-rv-open
                                data-kind="<?php echo $isVideo ? 'video' : 'image'; ?>"
                                data-src="<?php echo htmlspecialchars($src); ?>"
                                data-name="<?php echo htmlspecialchars($name); ?>"
                                aria-label="View <?php echo htmlspecialchars($name); ?>"
                            >
                                <?php if ($isVideo): ?>
                                    <span class="rv-media-video"><i class="fa-solid fa-play"></i></span>
                                <?php else: ?>
                                    <img src="<?php echo htmlspecialchars($src); ?>" alt="" loading="lazy" />
                                <?php endif; ?>
                                <span class="rv-media-tag<?php echo $isVideo ? ' is-video' : ''; ?>"><?php echo $isVideo ? 'Video' : 'Photo'; ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* ── Attachment viewer ─────────────────────────────────────────────── */ ?>
<div class="rv-viewer" id="rv-viewer" hidden role="dialog" aria-modal="true" aria-label="Attachment preview">
    <div class="rv-viewer-backdrop" data-rv-close></div>
    <div class="rv-viewer-card">
        <button class="rv-viewer-close" type="button" data-rv-close aria-label="Close preview"><i class="fa-solid fa-xmark"></i></button>
        <div class="rv-viewer-stage" data-rv-stage></div>
        <p class="rv-viewer-caption" data-rv-caption></p>
    </div>
</div>

<script>
(function () {
    var viewer = document.getElementById('rv-viewer');
    if (!viewer) return;
    var stage = viewer.querySelector('[data-rv-stage]');
    var caption = viewer.querySelector('[data-rv-caption]');

    function close() {
        viewer.hidden = true;
        document.body.classList.remove('rv-viewer-open');
        stage.innerHTML = '';
    }

    document.addEventListener('click', function (event) {
        var tile = event.target.closest('[data-rv-open]');
        if (tile) {
            stage.innerHTML = '';
            var media;
            if (tile.getAttribute('data-kind') === 'video') {
                media = document.createElement('video');
                media.setAttribute('controls', '');
                media.setAttribute('playsinline', '');
                media.autoplay = true;
            } else {
                media = document.createElement('img');
                media.alt = tile.getAttribute('data-name') || 'Review attachment';
            }
            media.src = tile.getAttribute('data-src');
            stage.appendChild(media);
            caption.textContent = tile.getAttribute('data-name') || '';
            viewer.hidden = false;
            document.body.classList.add('rv-viewer-open');
            viewer.querySelector('.rv-viewer-close').focus();
            return;
        }
        if (event.target.closest('[data-rv-close]')) close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !viewer.hidden) close();
    });
})();
</script>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
