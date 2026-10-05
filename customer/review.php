<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reviews.php';
require_once __DIR__ . '/../includes/rider.php';
require_once __DIR__ . '/../includes/order_status.php';

$pdo = getDatabaseConnection();
ensureReviewSupport($pdo);
ensureRiderSupport($pdo);

$customerId = (int) $_SESSION['user_id'];
$ref = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
$error = '';
$saved = false;

// ── Load the order, scoped to this customer, delivered only ────────────────
$stmt = $pdo->prepare(
    "SELECT o.id, o.reference_id, o.status, o.fulfillment_type, o.total_amount, o.rider_id,
            o.created_at, o.chat_closed_at, o.review_acknowledged_at,
            c.first_name AS customer_first, c.last_name AS customer_last,
            r.first_name AS rider_first, r.last_name AS rider_last, r.phone AS rider_phone,
            r.profile_data AS rider_photo, r.profile_mime AS rider_mime
     FROM orders o
     JOIN users c ON c.id = o.customer_id
     LEFT JOIN users r ON r.id = o.rider_id
     WHERE o.reference_id = :ref AND o.customer_id = :cid
     LIMIT 1"
);
$stmt->execute(['ref' => $ref, 'cid' => $customerId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ' . BASE_URL . '/customer/order-status.php');
    exit;
}

$orderId = (int) $order['id'];

if ($order['status'] !== 'completed') {
    $error = 'You can only review an order once it has been delivered.';
}

$existingStmt = $pdo->prepare('SELECT * FROM order_reviews WHERE order_id = :oid AND customer_id = :cid LIMIT 1');
$existingStmt->execute(['oid' => $orderId, 'cid' => $customerId]);
$review = $existingStmt->fetch() ?: null;

$topics = reviewFoodTopics();
$traitOptions = reviewTraits();
$serviceFields = reviewServiceRatings();
$limits = reviewLimits();

// ── Actions ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order['status'] === 'completed') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'skip_review') {
        // Move it to Completed Orders without rating. They can come back any time.
        $pdo->prepare(
            'UPDATE orders
             SET review_acknowledged_at = IFNULL(review_acknowledged_at, NOW())
             WHERE id = :id AND customer_id = :cid'
        )->execute(['id' => $orderId, 'cid' => $customerId]);
        header('Location: ' . BASE_URL . '/customer/order-status.php?skipped=1&ref=' . urlencode($ref));
        exit;
    }

    if ($action === 'delete_media') {
        // Removing an attachment that was already uploaded — called over fetch()
        // from the "Add photos and video" tiles, so answer in JSON.
        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $removed = false;

        if ($mediaId > 0) {
            // Scoped to this order and this customer, so a guessed id cannot
            // touch somebody else's review.
            $deleteStmt = $pdo->prepare(
                'DELETE m FROM order_review_media m
                 JOIN order_reviews r ON r.id = m.review_id
                 WHERE m.id = :mid AND r.order_id = :oid AND r.customer_id = :cid'
            );
            $removed = $deleteStmt->execute(['mid' => $mediaId, 'oid' => $orderId, 'cid' => $customerId])
                && $deleteStmt->rowCount() > 0;
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => $removed]);
        exit;
    }

    if ($action === 'save_review') {
        $food = reviewStarValue($_POST['food_rating'] ?? null);

        if ($food === null) {
            $error = 'Please give a star rating for your food before submitting.';
        } else {
            $shop = reviewStarValue($_POST['shop_service_rating'] ?? null);
            $speed = reviewStarValue($_POST['delivery_speed_rating'] ?? null);
            $driver = reviewStarValue($_POST['driver_service_rating'] ?? null);

            $story = trim((string) ($_POST['service_story'] ?? ''));
            if (mb_strlen($story) > $limits['story_chars']) {
                $story = mb_substr($story, 0, $limits['story_chars']);
            }

            $filled = [];
            foreach ($topics as $key => $label) {
                $value = trim((string) ($_POST['topic_' . $key] ?? ''));
                if ($value !== '') {
                    $filled[$key] = mb_substr($value, 0, $limits['topic_chars']);
                }
            }
            if (count($filled) < 2) {
                $error = 'Please write at least two of the review topics so we know what to improve.';
            } else {
                $ticked = [];
                foreach ($traitOptions as $key => $label) {
                    if (!empty($_POST['trait_' . $key])) {
                        $ticked[] = $key;
                    }
                }

                $mediaErrors = [];

                try {
                    $pdo->beginTransaction();

                    if ($review) {
                        $pdo->prepare(
                            'UPDATE order_reviews
                             SET food_rating = :food,
                                 shop_service_rating = :shop,
                                 delivery_speed_rating = :speed,
                                 driver_service_rating = :driver,
                                 food_topics = :topics,
                                 service_story = :story,
                                 traits = :traits,
                                 updated_at = NOW()
                             WHERE id = :id'
                        )->execute([
                            'id' => (int) $review['id'],
                            'food' => $food,
                            'shop' => $shop,
                            'speed' => $speed,
                            'driver' => $driver,
                            'topics' => json_encode($filled, JSON_UNESCAPED_UNICODE),
                            'story' => $story !== '' ? $story : null,
                            'traits' => $ticked ? implode(',', $ticked) : null,
                        ]);
                        $reviewId = (int) $review['id'];
                    } else {
                        $pdo->prepare(
                            'INSERT INTO order_reviews
                                (order_id, customer_id, rider_id, food_rating,
                                 shop_service_rating, delivery_speed_rating, driver_service_rating,
                                 food_topics, service_story, traits)
                             VALUES (:oid, :cid, :rid, :food, :shop, :speed, :driver, :topics, :story, :traits)'
                        )->execute([
                            'oid' => $orderId,
                            'cid' => $customerId,
                            'rid' => $order['rider_id'] ?: null,
                            'food' => $food,
                            'shop' => $shop,
                            'speed' => $speed,
                            'driver' => $driver,
                            'topics' => json_encode($filled, JSON_UNESCAPED_UNICODE),
                            'story' => $story !== '' ? $story : null,
                            'traits' => $ticked ? implode(',', $ticked) : null,
                        ]);
                        $reviewId = (int) $pdo->lastInsertId();
                    }

                    // Optional photos and video.
                    $uploads = $_FILES['review_media'] ?? null;
                    if (is_array($uploads) && !empty($uploads['name'][0])) {
                        $allowed = [
                            'image/jpeg' => ['image', 'jpg'],
                            'image/png' => ['image', 'png'],
                            'image/webp' => ['image', 'webp'],
                            'video/mp4' => ['video', 'mp4'],
                            'video/webm' => ['video', 'webm'],
                        ];

                        $imageCount = 0;
                        $videoSaved = false;
                        $existingMedia = $reviewId
                            ? (int) $pdo->query('SELECT COUNT(*) FROM order_review_media WHERE review_id = ' . $reviewId)->fetchColumn()
                            : 0;

                        for ($i = 0; $i < count($uploads['name']); $i++) {
                            if (($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($uploads['tmp_name'][$i]);
                            if (!isset($allowed[$mime])) {
                                $mediaErrors[] = 'Only JPG, PNG, WEBP, MP4 or WEBM files can be attached.';
                                break;
                            }
                            [$kind, $ext] = $allowed[$mime];

                            if ($kind === 'video') {
                                if ($videoSaved) {
                                    $mediaErrors[] = 'You can attach only one video.';
                                    break;
                                }
                                if ($uploads['size'][$i] > $limits['max_video_bytes']) {
                                    $mediaErrors[] = 'That video is too large (max 25 MB).';
                                    break;
                                }
                                $videoSaved = true;
                            } else {
                                if ($existingMedia + $imageCount >= $limits['max_media']) {
                                    $mediaErrors[] = 'You can attach up to ' . $limits['max_media'] . ' photos.';
                                    break;
                                }
                                if ($uploads['size'][$i] > $limits['max_image_bytes']) {
                                    $mediaErrors[] = 'One of the photos is too large (max 8 MB).';
                                    break;
                                }
                                $imageCount++;
                            }

                            $data = file_get_contents($uploads['tmp_name'][$i]);
                            if ($data === false) {
                                $mediaErrors[] = 'Could not read one of the attachments.';
                                break;
                            }

                            $pdo->prepare(
                                'INSERT INTO order_review_media
                                    (review_id, media_data, media_mime, media_kind, media_name, sort_order)
                                 VALUES (:rid, :data, :mime, :kind, :name, :sort)'
                            )->execute([
                                'rid' => $reviewId,
                                'data' => $data,
                                'mime' => $mime,
                                'kind' => $kind,
                                'name' => mb_substr(basename((string) $uploads['name'][$i]), 0, 160),
                                'sort' => $existingMedia + $imageCount,
                            ]);
                        }
                    }

                    $pdo->prepare(
                        'UPDATE orders
                         SET review_acknowledged_at = IFNULL(review_acknowledged_at, NOW())
                         WHERE id = :id AND customer_id = :cid'
                    )->execute(['id' => $orderId, 'cid' => $customerId]);

                    $pdo->commit();
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'We could not save your review. Please try again.';
                }

                if ($error === '' && $mediaErrors) {
                    $error = implode(' ', $mediaErrors);
                }

                if ($error === '') {
                    header('Location: ' . BASE_URL . '/customer/order-status.php?reviewed=1&ref=' . urlencode($ref));
                    exit;
                }
            }
        }

        // Re-read so a failed save shows what they typed.
        $existingStmt->execute(['oid' => $orderId, 'cid' => $customerId]);
        $review = $existingStmt->fetch() ?: null;
    }
}

$riderName = trim(($order['rider_first'] ?? '') . ' ' . ($order['rider_last'] ?? '')) ?: 'Your rider';
$riderInitial = strtoupper(substr($riderName, 0, 1)) ?: 'R';
$riderPhoto = userPhotoDataUri($order['rider_photo'] ?? null, $order['rider_mime'] ?? null);
$isPickup = ($order['fulfillment_type'] ?? 'delivery') === 'pickup';

$existingTopics = $review ? reviewTopicsFilled($review['food_topics'] ?? '') : [];
$existingTraits = $review ? reviewTraitList($review['traits'] ?? '') : [];
$savedMedia = $review ? reviewMediaFor($pdo, (int) $review['id']) : [];

// Skipping is a one-time decision. Once the order has been received (reviewed or
// skipped) the offer is gone and the top action simply becomes "Leave".
$alreadyReceived = trim((string) ($order['review_acknowledged_at'] ?? '')) !== '';
$canSkip = !$review && !$alreadyReceived && $order['status'] === 'completed';
$leaveUrl = BASE_URL . '/customer/order-status.php?ref=' . urlencode($ref);

function starField(string $name, ?int $value, string $label, bool $required, string $idPrefix): void
{
    ?>
    <fieldset class="rr-stars<?php echo $required ? ' is-required' : ''; ?>" data-star-field>
        <legend><?php echo htmlspecialchars($label); ?><?php if ($required): ?> <em>required</em><?php endif; ?></legend>
        <div class="rr-star-row" data-star-row>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <label class="rr-star" title="<?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?>">
                    <input type="radio" id="<?php echo $idPrefix . $name . $i; ?>" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo $i; ?>"<?php echo $value === $i ? ' checked' : ''; ?><?php echo $required ? ' required' : ''; ?> />
                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                    <span class="sr-only"><?php echo $i; ?></span>
                </label>
            <?php endfor; ?>
        </div>
        <p class="rr-star-text" data-star-text><?php echo $value ? htmlspecialchars(reviewStarLabel((int) $value)) : ''; ?></p>
    </fieldset>
    <?php
}

$pageTitle = 'Review Order #' . $order['reference_id'] . ' | BreadBreak';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/review.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/review.css') ? filemtime(__DIR__ . '/../assets/css/review.css') : time(); ?>" />

<main class="review-page">
    <div class="container">

        <?php /* Skip is offered once. After that the same slot becomes a plain Leave. */ ?>
        <div class="rr-top-actions">
            <?php if ($canSkip): ?>
                <form method="POST" data-rr-skip>
                    <input type="hidden" name="action" value="skip_review" />
                    <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>" />
                    <button class="rr-top-skip" type="submit">
                        <i class="fa-solid fa-forward"></i> Skip for now
                    </button>
                    <p class="rr-top-note">
                        Your order moves to <strong>Order History</strong>. You can still review it there any time.
                    </p>
                </form>
            <?php else: ?>
                <a class="rr-top-leave" href="<?php echo htmlspecialchars($leaveUrl); ?>">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Leave
                </a>
            <?php endif; ?>
        </div>

        <header class="rr-hero">
            <span class="eyebrow">Order Received</span>
            <h1>Reviews &amp; Rating</h1>
            <p>Order #<?php echo htmlspecialchars($order['reference_id']); ?> · Delivered
                <?php echo $order['proof_captured_at'] ?? $order['chat_closed_at'] ? ' · ' . date('M j, Y', strtotime($order['proof_captured_at'] ?? $order['chat_closed_at'])) : ''; ?>
            </p>
        </header>

        <?php if ($error): ?>
            <div class="rr-alert is-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($order['status'] !== 'completed'): ?>
            <div class="rr-alert" role="alert">
                <i class="fa-solid fa-clock"></i> This order has not been delivered yet, so there is nothing to review.
            </div>
        <?php else: ?>

        <form method="POST" enctype="multipart/form-data" class="rr-form" novalidate>
            <input type="hidden" name="action" value="save_review" />
            <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>" />

            <!-- ── Rate your Food ─────────────────────────────────────────── -->
            <section class="rr-card">
                <div class="rr-card-head">
                    <h2><i class="fa-solid fa-bread-slice"></i> Rate your Food</h2>
                    <?php if ($review): ?><span class="rr-badge">Saved</span><?php endif; ?>
                </div>

                <?php starField('food_rating', $review ? (int) $review['food_rating'] : null, 'How was your food?', true, 'rr-food-'); ?>

                <div class="rr-low" data-low-rating<?php echo (!$review || (int) $review['food_rating'] > 2) ? ' hidden' : ''; ?>>
                    <p data-low-text><?php echo $review ? htmlspecialchars(reviewLowRatingMessage((int) $review['food_rating'])) : ''; ?></p>
                </div>

                <div class="rr-block">
                    <h3>Add photos and video <em>optional</em></h3>
                    <p class="rr-hint">Photos and a short video help other customers decide. Up to 4 photos, or 1 video.</p>

                    <?php if ($savedMedia): ?>
                        <ul class="rr-media-list" data-rr-saved>
                            <?php foreach ($savedMedia as $media): ?>
                                <?php
                                    $mediaId = (int) $media['id'];
                                    $isVideo = $media['media_kind'] === 'video';
                                    $mediaSrc = BASE_URL . '/api/review-media.php?id=' . $mediaId;
                                    $mediaName = (string) ($media['media_name'] ?: ($isVideo ? 'video' : 'photo'));
                                ?>
                                <li class="rr-tile" data-saved-media="<?php echo $mediaId; ?>">
                                    <button
                                        type="button"
                                        class="rr-tile-open"
                                        data-open-src="<?php echo htmlspecialchars($mediaSrc); ?>"
                                        data-open-kind="<?php echo $isVideo ? 'video' : 'image'; ?>"
                                        data-open-name="<?php echo htmlspecialchars($mediaName); ?>"
                                        aria-label="View <?php echo htmlspecialchars($mediaName); ?>"
                                    >
                                        <?php if ($isVideo): ?>
                                            <span class="rr-tile-video"><i class="fa-solid fa-play"></i></span>
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($mediaSrc); ?>" alt="" loading="lazy" />
                                        <?php endif; ?>
                                        <span class="rr-tile-tag<?php echo $isVideo ? ' is-video' : ''; ?>"><?php echo $isVideo ? 'Video' : 'Photo'; ?></span>
                                    </button>
                                    <button type="button" class="rr-tile-remove" data-remove-saved aria-label="Remove <?php echo htmlspecialchars($mediaName); ?>">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                    <small class="rr-tile-name"><?php echo htmlspecialchars($mediaName); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <label class="rr-drop" data-rr-drop>
                        <i class="fa-solid fa-camera"></i>
                        <strong>Choose photos or a video</strong>
                        <small>JPG, PNG, WEBP · MP4, WEBM</small>
                        <input type="file" name="review_media[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" data-rr-input />
                    </label>
                    <ul
                        class="rr-file-list"
                        data-rr-files
                        data-max-photos="<?php echo (int) $limits['max_media']; ?>"
                        data-max-videos="1"
                        data-max-image-bytes="<?php echo (int) $limits['max_image_bytes']; ?>"
                        data-max-video-bytes="<?php echo (int) $limits['max_video_bytes']; ?>"
                    ></ul>
                </div>

                <div class="rr-block">
                    <h3>Write your reviews <em>at least two topics</em></h3>
                    <div class="rr-topics">
                        <?php foreach ($topics as $key => $label): ?>
                            <label class="rr-topic">
                                <span><?php echo htmlspecialchars($label); ?>:</span>
                                <textarea
                                    name="topic_<?php echo htmlspecialchars($key); ?>"
                                    rows="3"
                                    maxlength="<?php echo $limits['topic_chars']; ?>"
                                    placeholder="How was it?"
                                ><?php echo htmlspecialchars($existingTopics[$key] ?? ''); ?></textarea>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- ── Services ──────────────────────────────────────────────── -->
            <section class="rr-card">
                <div class="rr-card-head">
                    <h2><i class="fa-solid fa-bread-slice"></i> Services</h2>
                </div>

                <div class="rr-rider">
                    <?php if ($riderPhoto): ?>
                        <img src="<?php echo htmlspecialchars($riderPhoto); ?>" alt="" />
                    <?php else: ?>
                        <span class="rr-rider-avatar"><?php echo htmlspecialchars($riderInitial); ?></span>
                    <?php endif; ?>
                    <div>
                        <strong><?php echo htmlspecialchars($riderName); ?></strong>
                        <small><?php echo $isPickup ? 'Store pickup' : 'Your rider'; ?></small>
                    </div>
                </div>

                <div class="rr-service-grid">
                    <?php foreach ($serviceFields as $field => $label): ?>
                        <?php starField($field, $review && $review[$field] ? (int) $review[$field] : null, $label, false, 'rr-svc-'); ?>
                    <?php endforeach; ?>
                </div>

                <div class="rr-block">
                    <h3>What stood out?</h3>
                    <p class="rr-hint">Tick everything that applied to your delivery.</p>
                    <div class="rr-traits">
                        <?php foreach ($traitOptions as $key => $label): ?>
                            <label class="rr-trait">
                                <input type="checkbox" name="trait_<?php echo htmlspecialchars($key); ?>" value="1"<?php echo in_array($key, $existingTraits, true) ? ' checked' : ''; ?> />
                                <span><?php echo htmlspecialchars($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rr-block">
                    <h3>Tell us more</h3>
                    <textarea
                        class="rr-story"
                        name="service_story"
                        rows="5"
                        maxlength="<?php echo $limits['story_chars']; ?>"
                        placeholder="Anything else you would like us to know?"
                    ><?php echo htmlspecialchars((string) ($review['service_story'] ?? '')); ?></textarea>
                </div>
            </section>

            <p class="rr-note">
                <i class="fa-solid fa-heart"></i>
                Your rider rating and review will be shared to our shop and will be used to further improve our services. Thank you!
            </p>

            <div class="rr-actions">
                <button class="rr-submit" type="submit">
                    <i class="fa-solid fa-paper-plane"></i> Submit Review
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</main>

<?php /* ── Leave this page? ─────────────────────────────────────────────── */ ?>
<div class="rr-leave-modal" id="rr-leave-dialog" hidden role="dialog" aria-modal="true" aria-labelledby="rr-leave-title">
    <div class="rr-leave-backdrop" data-leave-stay></div>
    <div class="rr-leave-card">
        <div class="rr-leave-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h2 id="rr-leave-title">Leave this page?</h2>
        <p>If you leave, you will lose the changes you just made.</p>
        <div class="rr-leave-actions">
            <button class="rr-leave-btn is-stay" type="button" data-leave-stay>
                <i class="fa-solid fa-rotate-left"></i> Stay
            </button>
            <a class="rr-leave-btn is-go" href="#" data-leave-go>
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Leave
            </a>
        </div>
    </div>
</div>

<?php /* ── Attachment viewer ─────────────────────────────────────────────── */ ?>
<div class="rr-viewer" id="rr-viewer" hidden role="dialog" aria-modal="true" aria-label="Attachment preview">
    <div class="rr-viewer-backdrop" data-viewer-close></div>
    <div class="rr-viewer-card">
        <button class="rr-viewer-close" type="button" data-viewer-close aria-label="Close preview">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="rr-viewer-stage" data-viewer-stage></div>
        <p class="rr-viewer-caption" data-viewer-caption></p>
    </div>
</div>

<script>
(function () {
    // Star rows: highlight up to the hovered/checked star and show the caption.
    document.querySelectorAll('[data-star-row]').forEach(function (row) {
        var field = row.closest('[data-star-field]');
        var text = field.querySelector('[data-star-text]');
        var inputs = row.querySelectorAll('input');

        function paint(value) {
            row.querySelectorAll('.rr-star').forEach(function (star, index) {
                star.classList.toggle('is-on', index < value);
            });
            if (text) {
                text.textContent = value ? ['', 'We are really sorry about this one.', 'We are sorry about this one.',
                    'Thanks for the honest rating.', 'Really good — we are glad you enjoyed it.',
                    'Amazing! Thank you so much.'][value] : '';
            }
        }

        inputs.forEach(function (input) {
            input.addEventListener('change', function () { paint(Number(input.value)); });
        });
        row.addEventListener('mouseover', function (event) {
            var label = event.target.closest('.rr-star');
            if (label) paint(Number(label.querySelector('input').value));
        });
        row.addEventListener('mouseleave', function () {
            var checked = field.querySelector('input:checked');
            paint(checked ? Number(checked.value) : 0);
        });

        var current = field.querySelector('input:checked');
        if (current) paint(Number(current.value));
    });

    // Reveal the apology copy when the food rating is 1 or 2.
    var foodRow = document.querySelector('input[name="food_rating"]');
    var low = document.querySelector('[data-low-rating]');
    var lowText = document.querySelector('[data-low-text]');
    if (foodRow && low) {
        foodRow.addEventListener('change', function () {
            var value = Number(foodRow.value);
            var isLow = value === 1 || value === 2;
            low.hidden = !isLow;
            if (isLow && lowText) {
                lowText.textContent = value === 1
                    ? 'We are really sorry this order missed the mark. Please tell us what went wrong below — our baker will review it personally and get back to you.'
                    : 'Sorry this did not quite meet expectations. Tell us what fell short below so we can do better next time.';
            }
        });
    }

    // ── Attachments ──────────────────────────────────────────────────────
    // Chosen files get a tappable thumbnail: click it to watch/read it, hit the
    // X to drop it from the selection. Uploads already on the server work the
    // same way, except the X also deletes the row (delete_media action).
    var input = document.querySelector('[data-rr-input]');
    var list = document.querySelector('[data-rr-files]');
    var refInput = document.querySelector('form.rr-form input[name="ref"]');
    var canStage = typeof DataTransfer !== 'undefined';
    var limits = {
        photos: Number(list && list.getAttribute('data-max-photos')) || 4,
        videos: Number(list && list.getAttribute('data-max-videos')) || 1,
        imageBytes: Number(list && list.getAttribute('data-max-image-bytes')) || 8388608,
        videoBytes: Number(list && list.getAttribute('data-max-video-bytes')) || 26214400
    };
    var staged = [];      // File[] still waiting to be submitted
    var stagedUrls = [];  // object URL, index-matched with `staged`
    var imageTypes = ['image/jpeg', 'image/png', 'image/webp'];
    var videoTypes = ['video/mp4', 'video/webm'];

    function fileKey(file) {
        return file.name + '|' + file.size + '|' + file.lastModified;
    }
    function isVideoFile(file) {
        return videoTypes.indexOf(file.type) > -1;
    }

    // Push the staged files back into the input so the real submit picks them up.
    function syncInput() {
        if (!input || !canStage) return;
        var transfer = new DataTransfer();
        staged.forEach(function (file) { transfer.items.add(file); });
        input.files = transfer.files;
    }

    function buildTile(options) {
        var li = document.createElement('li');
        li.className = 'rr-tile';
        li.setAttribute('data-index', String(options.index));

        var open = document.createElement('button');
        open.type = 'button';
        open.className = 'rr-tile-open';
        open.setAttribute('data-open-src', options.src);
        open.setAttribute('data-open-kind', options.kind);
        open.setAttribute('data-open-name', options.name);
        open.setAttribute('aria-label', 'View ' + options.name);

        if (options.kind === 'video') {
            var play = document.createElement('span');
            play.className = 'rr-tile-video';
            play.innerHTML = '<i class="fa-solid fa-play"></i>';
            open.appendChild(play);
        } else {
            var img = document.createElement('img');
            img.src = options.src;
            img.alt = '';
            open.appendChild(img);
        }

        var tag = document.createElement('span');
        tag.className = 'rr-tile-tag' + (options.kind === 'video' ? ' is-video' : '');
        tag.textContent = options.kind === 'video' ? 'Video' : 'Photo';
        open.appendChild(tag);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'rr-tile-remove';
        remove.setAttribute('data-remove-pending', '');
        remove.setAttribute('aria-label', 'Remove ' + options.name);
        remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        if (!canStage) remove.hidden = true;   // cannot edit the native selection

        var name = document.createElement('small');
        name.className = 'rr-tile-name';
        name.textContent = options.caption;

        li.appendChild(open);
        li.appendChild(remove);
        li.appendChild(name);
        return li;
    }

    function renderFiles(warnings) {
        if (!list) return;
        list.innerHTML = '';

        (warnings || []).forEach(function (message) {
            var warn = document.createElement('li');
            warn.className = 'rr-file-warning';
            warn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span></span>';
            warn.querySelector('span').textContent = message;
            list.appendChild(warn);
        });

        staged.forEach(function (file, index) {
            list.appendChild(buildTile({
                index: index,
                src: stagedUrls[index],
                kind: isVideoFile(file) ? 'video' : 'image',
                name: file.name,
                caption: file.name + ' · ' + (file.size / 1048576).toFixed(1) + ' MB'
            }));
        });
    }

    if (input && list) {
        // Picking more files adds to the pile instead of replacing it, so photos
        // and a video can be gathered one at a time.
        input.addEventListener('change', function () {
            var picked = Array.prototype.slice.call(input.files || []);
            var warnings = [];
            var stagedKey = {};
            var photoCount = 0;
            var videoCount = 0;

            staged.forEach(function (file) {
                stagedKey[fileKey(file)] = true;
                if (isVideoFile(file)) { videoCount++; } else { photoCount++; }
            });

            if (!canStage) {
                // No DataTransfer: the browser's own selection is all we get.
                staged = picked;
                stagedUrls = staged.map(function (file) { return URL.createObjectURL(file); });
                renderFiles();
                return;
            }

            picked.forEach(function (file) {
                var video = isVideoFile(file);
                var knownType = video
                    ? videoTypes.indexOf(file.type) > -1
                    : imageTypes.indexOf(file.type) > -1;
                if (!knownType) {
                    warnings.push(file.name + ' is not a JPG, PNG, WEBP, MP4 or WEBM file.');
                    return;
                }
                if (stagedKey[fileKey(file)]) return;   // already in the pile

                if (video) {
                    if (videoCount >= limits.videos) {
                        warnings.push('Only ' + limits.videos + ' video can be attached — remove the one you have first.');
                        return;
                    }
                    if (file.size > limits.videoBytes) {
                        warnings.push(file.name + ' is over ' + Math.round(limits.videoBytes / 1048576) + ' MB.');
                        return;
                    }
                    videoCount++;
                } else {
                    if (photoCount >= limits.photos) {
                        warnings.push('You can attach up to ' + limits.photos + ' photos.');
                        return;
                    }
                    if (file.size > limits.imageBytes) {
                        warnings.push(file.name + ' is over ' + Math.round(limits.imageBytes / 1048576) + ' MB.');
                        return;
                    }
                    photoCount++;
                }

                stagedKey[fileKey(file)] = true;
                staged.push(file);
                stagedUrls.push(URL.createObjectURL(file));
            });

            syncInput();
            renderFiles(warnings);
        });
    }

    // ── Attachment viewer ────────────────────────────────────────────────
    var viewer = document.getElementById('rr-viewer');
    var viewerStage = viewer ? viewer.querySelector('[data-viewer-stage]') : null;
    var viewerCaption = viewer ? viewer.querySelector('[data-viewer-caption]') : null;

    function openViewer(kind, src, caption) {
        if (!viewer || !viewerStage) return;
        viewerStage.innerHTML = '';

        var media;
        if (kind === 'video') {
            media = document.createElement('video');
            media.setAttribute('controls', '');
            media.setAttribute('playsinline', '');
            media.autoplay = true;
        } else {
            media = document.createElement('img');
            media.alt = caption || 'Review attachment';
        }
        media.src = src;
        viewerStage.appendChild(media);

        if (viewerCaption) viewerCaption.textContent = caption || '';
        viewer.hidden = false;
        document.body.classList.add('rr-viewer-open');
        var closeBtn = viewer.querySelector('.rr-viewer-close');
        if (closeBtn) closeBtn.focus();
    }

    function closeViewer() {
        if (!viewer) return;
        viewer.hidden = true;
        document.body.classList.remove('rr-viewer-open');
        if (viewerStage) viewerStage.innerHTML = '';   // stops playback too
    }

    if (viewer) {
        viewer.addEventListener('click', function (event) {
            if (event.target.closest('[data-viewer-close]')) closeViewer();
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && viewer && !viewer.hidden) closeViewer();
    });

    document.addEventListener('click', function (event) {
        // View an attachment (pending thumbnail or an uploaded one).
        var open = event.target.closest('[data-open-src]');
        if (open) {
            event.preventDefault();
            openViewer(
                open.getAttribute('data-open-kind'),
                open.getAttribute('data-open-src'),
                open.getAttribute('data-open-name')
            );
            return;
        }

        // X on a file that has not been submitted yet: drop it from the pile.
        var removePending = event.target.closest('[data-remove-pending]');
        if (removePending) {
            event.preventDefault();
            var tile = removePending.closest('[data-index]');
            var index = tile ? Number(tile.getAttribute('data-index')) : NaN;
            if (!isNaN(index) && staged[index]) {
                URL.revokeObjectURL(stagedUrls[index]);
                staged.splice(index, 1);
                stagedUrls.splice(index, 1);
                syncInput();
                renderFiles();
            }
            return;
        }

        // X on an attachment from an earlier save: delete it on the server.
        var removeSaved = event.target.closest('[data-remove-saved]');
        if (removeSaved) {
            event.preventDefault();
            var savedTile = removeSaved.closest('[data-saved-media]');
            var mediaId = savedTile ? Number(savedTile.getAttribute('data-saved-media')) : 0;
            if (!mediaId) return;

            removeSaved.disabled = true;
            var body = new FormData();
            body.append('action', 'delete_media');
            body.append('media_id', String(mediaId));
            body.append('ref', refInput ? refInput.value : '');

            fetch(window.location.href, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok) throw new Error('remove failed');
                    savedTile.remove();
                    var savedList = document.querySelector('[data-rr-saved]');
                    if (savedList && !savedList.children.length) savedList.remove();
                })
                .catch(function () {
                    removeSaved.disabled = false;
                    alert('We could not remove that attachment. Please try again.');
                });
        }
    });

    // ── Unsaved-changes guard ────────────────────────────────────────────
    // Any in-progress edit makes leaving the page destructive, so both in-app
    // links and browser navigation (back button, close tab) go through the same
    // Leave / Stay dialog.
    var dirty = false;
    var form = document.querySelector('form.rr-form');
    if (form) {
        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('change', function () { dirty = true; });
        // Submitting the review is a deliberate save, not an accidental exit.
        form.addEventListener('submit', function () { dirty = false; });
    }

    var dialog = document.getElementById('rr-leave-dialog');
    var pendingUrl = null;

    function requestLeave(url) {
        if (!dialog) { window.location.href = url; return; }
        pendingUrl = url;
        dialog.hidden = false;
        document.body.classList.add('rr-dialog-open');
        var stay = dialog.querySelector('[data-leave-stay]');
        if (stay) stay.focus();
    }

    function closeDialog() {
        if (!dialog) return;
        dialog.hidden = true;
        pendingUrl = null;
        document.body.classList.remove('rr-dialog-open');
    }

    // Any link or the Skip button that would navigate away asks first.
    document.addEventListener('click', function (event) {
        if (!dirty) return;
        var link = event.target.closest('a[href]');
        if (link) {
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#') return;
            event.preventDefault();
            requestLeave(href);
            return;
        }
        // Skipping also throws the edits away, so it is guarded too.
        var skipBtn = event.target.closest('.rr-top-skip');
        if (skipBtn) {
            event.preventDefault();
            requestLeave(null);
        }
    });

    if (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target.closest('[data-leave-stay]') || event.target.closest('.rr-leave-backdrop')) {
                closeDialog();
                return;
            }
            var go = event.target.closest('[data-leave-go]');
            if (go) {
                event.preventDefault();
                dirty = false;                 // confirmed: stop guarding
                var target = go.getAttribute('href');
                if (target && target !== '#') { window.location.href = target; return; }
                var url = pendingUrl;
                closeDialog();
                if (url) { window.location.href = url; return; }
                // No URL: the pending action was the Skip form.
                var skipForm = document.querySelector('form[data-rr-skip]');
                if (skipForm) skipForm.submit();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && dialog && !dialog.hidden) closeDialog();
    });

    // Browser-level navigation (back button, refresh, closing the tab).
    window.addEventListener('beforeunload', function (event) {
        if (!dirty) return undefined;
        event.preventDefault();
        event.returnValue = '';
        return '';
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
