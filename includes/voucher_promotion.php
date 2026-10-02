<?php
// =============================================================================
// includes/voucher_promotion.php – BreadMoments promotion for vouchers
//
// A promoted voucher is advertised in the BreadMoments feed as a store
// promotion whose ONLY action is "Claim" (it adds the voucher to the
// customer's account). Likes and comments are hidden for these posts.
// =============================================================================

require_once __DIR__ . '/vouchers.php';

/**
 * Turns the BreadMoments promotion for a voucher on or off.
 * Re-promoting refreshes the existing ad so voucher edits show in the feed.
 *
 * Returns ['ok' => bool, 'promoted' => bool, 'error' => string].
 */
function setVoucherPromotion(PDO $pdo, int $voucherId, bool $promote, int $actorId): array
{
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $voucherId]);
    $voucher = $stmt->fetch();
    if (!$voucher) {
        return ['ok' => false, 'promoted' => false, 'error' => 'Voucher not found.'];
    }

    $momentId = $voucher['promoted_moment_id'] !== null ? (int) $voucher['promoted_moment_id'] : 0;

    // ── Turning it off removes the ad ──
    if (!$promote) {
        if ($momentId > 0) {
            try {
                $pdo->prepare("DELETE FROM bread_moments WHERE id = :id AND post_type = 'promotion'")
                    ->execute(['id' => $momentId]);
            } catch (Throwable) {
                // Feed tables may not exist yet — the flag still turns off below.
            }
        }
        $pdo->prepare('UPDATE vouchers SET is_promoted = 0, promoted_moment_id = NULL WHERE id = :id')
            ->execute(['id' => $voucherId]);
        return ['ok' => true, 'promoted' => false, 'error' => ''];
    }

    if ($voucher['status'] !== 'active') {
        return ['ok' => false, 'promoted' => false, 'error' => 'Activate the voucher before promoting it to BreadMoments.'];
    }

    $minSpend = (float) ($voucher['min_spend'] ?? 0);
    $lines = [];
    if (!empty($voucher['description'])) {
        $lines[] = trim((string) $voucher['description']);
    }
    $lines[] = 'Get FREE DELIVERY on your next order using code ' . $voucher['code'] . ' at checkout.';
    if ($minSpend > 0) {
        $lines[] = 'Minimum spend: ₱' . number_format($minSpend, 2) . '.';
    }
    if (!empty($voucher['valid_until'])) {
        $lines[] = 'Offer ends ' . date('M j, Y', strtotime((string) $voucher['valid_until'])) . '.';
    }
    $lines[] = 'Tap Claim to add this voucher to your BreadBreak account.';

    $body    = implode("\n\n", $lines);
    $title   = trim((string) ($voucher['title'] ?? '')) ?: ('Free delivery code ' . $voucher['code']);
    $safeTitle = mb_substr($title, 0, 140);

    // ── Refresh the existing ad instead of stacking duplicates ──
    if ($momentId > 0) {
        try {
            $pdo->prepare(
                "UPDATE bread_moments
                 SET title = :title, body = :body, topics = 'voucher', moderation_status = 'visible'
                 WHERE id = :id AND post_type = 'promotion'"
            )->execute(['title' => $safeTitle, 'body' => $body, 'id' => $momentId]);

            $pdo->prepare('UPDATE vouchers SET is_promoted = 1 WHERE id = :id')->execute(['id' => $voucherId]);
            return ['ok' => true, 'promoted' => true, 'error' => ''];
        } catch (Throwable) {
            // Ad row disappeared — fall through and publish a fresh one.
        }
    }

    try {
        $pdo->prepare(
            "INSERT INTO bread_moments (user_id, title, body, topics, post_type, moderation_status)
             VALUES (:user_id, :title, :body, 'voucher', 'promotion', 'visible')"
        )->execute([
            'user_id' => $actorId,
            'title'   => $safeTitle,
            'body'    => $body,
        ]);

        $pdo->prepare('UPDATE vouchers SET is_promoted = 1, promoted_moment_id = :mid WHERE id = :id')
            ->execute(['mid' => (int) $pdo->lastInsertId(), 'id' => $voucherId]);

        return ['ok' => true, 'promoted' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'promoted' => false, 'error' => 'Could not publish the promotion: ' . $e->getMessage()];
    }
}

/**
 * Promoted vouchers joined to their BreadMoment ad.
 * Returns [momentId => voucherRow].
 */
function promotedVouchersByMoment(PDO $pdo, array $momentIds = []): array
{
    if (empty($momentIds)) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $momentIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT v.id AS voucher_id, v.code, v.title, v.description, v.discount_type,
                v.min_spend, v.valid_until, v.status, v.is_public,
                v.usage_limit_per_user, v.usage_limit_total, v.promoted_moment_id
         FROM vouchers v
         WHERE v.promoted_moment_id IN ($placeholders) AND v.is_promoted = 1"
    );
    $stmt->execute($ids);

    $byMoment = [];
    foreach ($stmt->fetchAll() as $row) {
        $byMoment[(int) $row['promoted_moment_id']] = $row;
    }
    return $byMoment;
}

/** Voucher ids this customer already holds from the given promotions. */
function claimedPromotedVoucherIds(PDO $pdo, int $customerId, array $voucherIds = []): array
{
    if ($customerId <= 0 || empty($voucherIds)) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $voucherIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT DISTINCT voucher_id FROM customer_vouchers
         WHERE customer_id = ? AND voucher_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$customerId], $ids));
    return array_map('intval', array_column($stmt->fetchAll(), 'voucher_id'));
}

/**
 * Claims a promoted voucher for a customer — the same rules the checkout uses,
 * minus the order context. Returns ['ok' => bool, 'message' => string].
 */
function claimPromotedVoucher(PDO $pdo, int $voucherId, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $voucherId]);
    $voucher = $stmt->fetch();

    if (!$voucher || (int) ($voucher['is_promoted'] ?? 0) !== 1 || $voucher['status'] !== 'active') {
        return ['ok' => false, 'message' => 'This promotion is no longer available.'];
    }

    $now = time();
    if (!empty($voucher['valid_from']) && strtotime((string) $voucher['valid_from']) > $now) {
        return ['ok' => false, 'message' => 'This promotion has not started yet.'];
    }
    if (!empty($voucher['valid_until']) && strtotime((string) $voucher['valid_until']) < $now) {
        return ['ok' => false, 'message' => 'This promotion has ended.'];
    }

    $held = $pdo->prepare('SELECT COUNT(*) FROM customer_vouchers WHERE voucher_id = :vid AND customer_id = :cid');
    $held->execute(['vid' => $voucherId, 'cid' => $customerId]);
    if ((int) $held->fetchColumn() >= max(1, (int) ($voucher['usage_limit_per_user'] ?? 1))) {
        return ['ok' => false, 'message' => 'You already claimed this voucher.'];
    }

    $totalLimit = $voucher['usage_limit_total'] !== null ? (int) $voucher['usage_limit_total'] : null;
    if ($totalLimit !== null && $totalLimit > 0) {
        $used = (int) $pdo->query(
            "SELECT COUNT(*) FROM customer_vouchers WHERE voucher_id = " . $voucherId . " AND status = 'used'"
        )->fetchColumn();
        if ($used >= $totalLimit) {
            return ['ok' => false, 'message' => 'This promotion has reached its limit.'];
        }
    }

    if (!grantVoucherToCustomer($pdo, $voucherId, $customerId, 'promo_claim', null)) {
        return ['ok' => false, 'message' => 'We could not add the voucher right now. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Voucher ' . $voucher['code'] . ' added to your account!'];
}