<?php
// Customer order reviews and ratings.
//
// One review per order. A customer "receives" an order (or skips it) which moves
// the order into the Completed Orders list; only then can they rate it, and they
// can always come back and finish later.

function ensureReviewSupport(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Flag on orders: set when the customer receives or skips the order. This is
    // what moves a delivered order into "Completed Orders".
    $ackCol = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ' . $pdo->quote('orders') . '
           AND column_name = ' . $pdo->quote('review_acknowledged_at')
    )->fetchColumn();

    if (!$ackCol) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN review_acknowledged_at DATETIME NULL');
        } catch (Throwable) {
            // Column already exists, or the account cannot ALTER.
        }
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_reviews (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL,
                customer_id INT NOT NULL,
                rider_id INT NULL,
                food_rating TINYINT NOT NULL,
                shop_service_rating TINYINT NULL,
                delivery_speed_rating TINYINT NULL,
                driver_service_rating TINYINT NULL,
                food_topics TEXT NULL,
                service_story TEXT NULL,
                traits VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_order_review (order_id),
                INDEX idx_review_customer (customer_id),
                INDEX idx_review_rider (rider_id),
                CONSTRAINT fk_review_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_review_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable) {
        // Table already exists, or the account cannot CREATE.
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_review_media (
                id INT PRIMARY KEY AUTO_INCREMENT,
                review_id INT NOT NULL,
                media_data MEDIUMBLOB NOT NULL,
                media_mime VARCHAR(80) NOT NULL,
                media_kind ENUM('image', 'video') NOT NULL DEFAULT 'image',
                media_name VARCHAR(160) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_review_media (review_id, sort_order),
                CONSTRAINT fk_review_media_review FOREIGN KEY (review_id) REFERENCES order_reviews(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable) {
        // Table already exists, or the account cannot CREATE.
    }
}

/** The review topics the customer writes about the food. At least two required. */
function reviewFoodTopics(): array
{
    return [
        'food_quality' => 'Food Quality',
        'taste' => 'Taste & Freshness',
        'presentation' => 'Presentation',
        'value' => 'Value for Money',
    ];
}

/** The things a customer can tick off about the rider / service. */
function reviewTraits(): array
{
    return [
        'friendly' => 'Friendly',
        'good_hygiene' => 'Good hygiene',
        'good_communication' => 'Good communication',
        'reliable' => 'Reliable',
        'regular_delivery_update' => 'Regular delivery update',
        'helpful' => 'Helpful',
        'responsive' => 'Responsive',
        'professional' => 'Professional',
        'fast_delivery' => 'Fast Delivery',
    ];
}

function reviewServiceRatings(): array
{
    return [
        'shop_service_rating' => 'Shop Service',
        'delivery_speed_rating' => 'Delivery Speed',
        'driver_service_rating' => 'Driver Service',
    ];
}

/** Copy shown when the food rating is low — the message the brief left blank. */
function reviewLowRatingMessage(int $stars): string
{
    if ($stars <= 1) {
        return 'We are really sorry this order missed the mark. Please tell us what went wrong below — our baker will review it personally and get back to you.';
    }
    return 'Sorry this did not quite meet expectations. Tell us what fell short below so we can do better next time.';
}

function reviewStarLabel(int $stars): string
{
    return match (true) {
        $stars >= 5 => 'Amazing! Thank you so much.',
        $stars === 4 => 'Really good — we are glad you enjoyed it.',
        $stars === 3 => 'Thanks for the honest rating.',
        $stars === 2 => 'We are sorry about this one.',
        default => 'We are really sorry about this one.',
    };
}

function reviewLimits(): array
{
    return [
        'max_media' => 4,
        'max_image_bytes' => 8 * 1024 * 1024,
        'max_video_bytes' => 25 * 1024 * 1024,
        'topic_chars' => 500,
        'story_chars' => 1500,
    ];
}

/** 1-5 or null. */
function reviewStarValue($raw): ?int
{
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (int) $raw;
    return ($value >= 1 && $value <= 5) ? $value : null;
}

function reviewRatingAverages(array $reviews): array
{
    $out = [];
    foreach (array_keys(reviewServiceRatings()) as $field) {
        $sum = 0;
        $count = 0;
        foreach ($reviews as $review) {
            $v = (int) ($review[$field] ?? 0);
            if ($v > 0) {
                $sum += $v;
                $count++;
            }
        }
        $out[$field] = $count ? round($sum / $count, 2) : null;
    }
    $sum = 0;
    foreach ($reviews as $review) {
        $sum += (int) ($review['food_rating'] ?? 0);
    }
    $out['food_rating'] = $reviews ? round($sum / count($reviews), 2) : null;
    return $out;
}

function reviewMediaFor(PDO $pdo, int $reviewId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, media_mime, media_kind, media_name
         FROM order_review_media
         WHERE review_id = :rid
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute(['rid' => $reviewId]);
    return $stmt->fetchAll();
}

/** topic key => text, only keeping the ones the customer actually filled in. */
function reviewTopicsFilled($stored): array
{
    if (!is_string($stored) || trim($stored) === '') {
        return [];
    }
    $decoded = json_decode($stored, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $out[$key] = $value;
        }
    }
    return $out;
}

function reviewTraitList($stored): array
{
    if (!is_string($stored) || trim($stored) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $stored))));
}
