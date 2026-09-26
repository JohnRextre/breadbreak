<?php
// Server-side payment verification.
//
// The webhook is the authoritative confirmation, but it needs a public HTTPS URL
// that stays alive. When that URL is unreachable (dead tunnel, laptop asleep) the
// order would sit at "pending" forever, because the customer-facing return page
// deliberately does not mark anything paid.
//
// This is the safety net: the server asks Xendit directly what happened to the
// payment request and applies the same transitions the webhook applies. It is
// called from pages the customer already loads, so it needs no public endpoint.

require_once __DIR__ . '/order_status.php';

function ensurePaymentVerificationSupport(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $col = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ' . $pdo->quote('payments') . '
           AND column_name = ' . $pdo->quote('xendit_checked_at')
    )->fetchColumn();

    if (!$col) {
        try {
            $pdo->exec('ALTER TABLE payments ADD COLUMN xendit_checked_at DATETIME NULL');
        } catch (Throwable) {
            // Column already exists, or the account cannot ALTER.
        }
    }
}

/** Xendit payment-request status -> our payments.status value. */
function xenditStatusToLocal(?string $status): ?string
{
    $status = strtoupper((string) $status);
    return match ($status) {
        'SUCCEEDED', 'PAID', 'CAPTURED', 'SUCCESS', 'COMPLETED' => 'paid',
        'FAILED', 'DECLINED' => 'failed',
        'EXPIRED' => 'expired',
        'VOIDED', 'CANCELLED' => 'voided',
        default => null,
    };
}

function isOnlinePaymentMethod(?string $method): bool
{
    return strtoupper((string) $method) !== 'CASH' && trim((string) $method) !== '';
}

/**
 * Asks Xendit for the true state of an order's payment and applies it.
 * Safe to call on every page load: it no-ops for cash orders, for already-terminal
 * payments, and when the previous check was less than $throttleSeconds ago.
 *
 * @return array{checked:bool,status:?string,reason:string}
 */
function verifyOrderPaymentAtXendit(PDO $pdo, int $orderId, int $throttleSeconds = 20): array
{
    $skip = ['checked' => false, 'status' => null, 'reason' => 'skipped'];

    if (!function_exists('xenditRequest')) {
        $config = __DIR__ . '/../config/xendit.php';

        if (!is_file($config)) {
            $skip['reason'] = 'no config';
            return $skip;
        }
        require_once $config;
    }

    if (!defined('XENDIT_API_BASE') || !defined('XENDIT_SECRET_KEY')) {
        $skip['reason'] = 'not configured';
        return $skip;
    }

    ensurePaymentVerificationSupport($pdo);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.xendit_payment_request_id, p.status, p.amount, p.payment_method,
                p.xendit_raw_response, p.xendit_checked_at,
                (p.xendit_checked_at IS NOT NULL
                 AND p.xendit_checked_at > (NOW() - INTERVAL :throttle SECOND)) AS recently_checked,
                o.status AS order_status, o.reference_id, o.payment_method AS order_pay_method
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         WHERE p.order_id = :oid
         ORDER BY p.id DESC
         LIMIT 1'
    );
    $stmt->bindValue(':oid', $orderId, PDO::PARAM_INT);
    $stmt->bindValue(':throttle', max(0, $throttleSeconds), PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        $skip['reason'] = 'no payment row';
        return $skip;
    }

    if (!isOnlinePaymentMethod($row['order_pay_method'])) {
        $skip['reason'] = 'cash order';
        return $skip;
    }

    // Never re-verify something already settled — this must never downgrade an order.
    if (in_array(strtolower((string) $row['status']), ['paid', 'failed', 'expired', 'voided'], true)) {
        return ['checked' => false, 'status' => $row['status'], 'reason' => 'already terminal'];
    }

    $xenditId = trim((string) $row['xendit_payment_request_id']);
    if ($xenditId === '') {
        $skip['reason'] = 'no xendit id';
        return $skip;
    }

    // Throttle so a page that refreshes every few seconds does not hammer the API.
    // The window is compared inside MySQL on purpose: PHP's clock and MySQL's
    // NOW() can sit in different timezones, and comparing them in PHP would be wrong.
    if (!empty($row['recently_checked'])) {
        return ['checked' => false, 'status' => $row['status'], 'reason' => 'throttled'];
    }

    // xenditRequest() wraps the payload: ['ok' => bool, 'status' => http code, 'body' => decoded json].
    $response = xenditRequest('GET', '/v3/payment_requests/' . rawurlencode($xenditId));
    $body = is_array($response['body'] ?? null) ? $response['body'] : [];

    $pdo->prepare('UPDATE payments SET xendit_checked_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);

    if (empty($response['ok'])) {
        $message = $body['message'] ?? ('HTTP ' . ($response['status'] ?? '?'));
        return ['checked' => true, 'status' => $row['status'], 'reason' => 'xendit lookup failed: ' . $message];
    }

    $remoteStatus = xenditStatusToLocal($body['status'] ?? null);

    if ($remoteStatus === null) {
        return [
            'checked' => true,
            'status' => $row['status'],
            'reason' => 'still ' . strtolower((string) ($body['status'] ?? 'unknown')) . ' at xendit',
        ];
    }

    // Amount must match what we asked for, otherwise this is not our payment.
    $remoteAmount = isset($body['request_amount']) ? (float) $body['request_amount'] : null;
    if ($remoteAmount !== null && abs($remoteAmount - (float) $row['amount']) > 0.01) {
        return [
            'checked' => true,
            'status' => $row['status'],
            'reason' => 'amount mismatch (xendit ' . $remoteAmount . ' vs local ' . $row['amount'] . ')',
        ];
    }

    applyVerifiedPayment($pdo, $row, $remoteStatus, $body);

    return ['checked' => true, 'status' => $remoteStatus, 'reason' => 'applied: ' . $remoteStatus];
}

/** Writes a verified Xendit result to the database. Mirrors webhook/xendit.php. */
function applyVerifiedPayment(PDO $pdo, array $payment, string $localStatus, array $response): void
{
    $actor = ['actor_id' => null, 'actor_role' => 'system', 'actor_name' => 'Xendit'];
    $orderLookup = $pdo->prepare('SELECT order_id FROM payments WHERE id = :id');
    $orderLookup->execute(['id' => (int) $payment['id']]);
    $orderId = (int) $orderLookup->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE payments
             SET status = :status, xendit_raw_response = :raw, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'status' => $localStatus,
            'raw' => json_encode($response),
            'id' => (int) $payment['id'],
        ]);

        // Foreign key with ON DELETE RESTRICT, so resolve it inside the transaction.

        if ($localStatus === 'paid') {
            $current = $pdo->prepare('SELECT status FROM orders WHERE id = :oid');
            $current->execute(['oid' => $orderId]);
            $from = (string) ($current->fetchColumn() ?: 'pending');

            if ($from === 'pending') {
                // Online payments skip the manual accept step and start baking.
                applyOrderStatus(
                    $pdo,
                    $orderId,
                    'processing',
                    $actor,
                    'Payment confirmed with Xendit — baking started automatically.'
                );
                deductOrderStock($pdo, $orderId);
            }
        } elseif (!in_array((string) $payment['order_status'], ['completed', 'cancelled'], true)) {
            applyOrderStatus(
                $pdo,
                $orderId,
                'cancelled',
                $actor,
                'Payment ' . $localStatus . ' at Xendit.'
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
