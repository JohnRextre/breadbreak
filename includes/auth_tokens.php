<?php

/**
 * Email-verification and password-reset tokens.
 *
 * Only SHA-256 hashes are stored — a database copy never exposes a usable
 * link. Verification lives on the users row (24 h); resets live in
 * password_resets (60 min, single-use, throttled to 5/hour with a 60 s gap).
 */
require_once __DIR__ . '/../config/database.php';

function randomToken(): string
{
    return bin2hex(random_bytes(32));
}

function hashToken(string $token): string
{
    return hash('sha256', $token);
}

// ─────────────────────────────────────────────────────────────────────────────
// Email verification
// ─────────────────────────────────────────────────────────────────────────────

/** True when the account row carries the verification columns but hasn't verified. */
function emailIsUnverified(array $user): bool
{
    return array_key_exists('email_verified_at', $user) && !$user['email_verified_at'];
}

/** Store a fresh 24-hour token and return the raw value for the link. */
function issueEmailVerification(PDO $pdo, int $userId): string
{
    $token = randomToken();
    $statement = $pdo->prepare(
        'UPDATE users
            SET verification_token_hash = :hash,
                verification_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                verification_sent_at = NOW()
          WHERE id = :id'
    );
    $statement->execute(['hash' => hashToken($token), 'id' => $userId]);

    return $token;
}

/** Returns a user-facing wait message when a link went out within the last 60 s. */
function verificationResendBlocked(PDO $pdo, int $userId): ?string
{
    $statement = $pdo->prepare('SELECT verification_sent_at FROM users WHERE id = :id');
    $statement->execute(['id' => $userId]);
    $sentAt = $statement->fetchColumn();

    if ($sentAt && strtotime((string) $sentAt) > time() - 60) {
        return 'We just sent a link — please wait a minute before requesting another one.';
    }

    return null;
}

/**
 * Validate a verification token WITHOUT consuming it (GET only renders a
 * confirm page — mail link scanners that prefetch the URL can never burn the
 * token before the user actually clicks Confirm).
 *
 * Returns ['status' => ok|invalid|expired] (+ user_id on ok).
 */
function lookupEmailVerification(PDO $pdo, string $token): array
{
    if ($token === '') {
        return ['status' => 'invalid'];
    }

    $statement = $pdo->prepare(
        'SELECT id, email_verified_at, verification_expires_at
           FROM users
          WHERE verification_token_hash = :hash
          LIMIT 1'
    );
    $statement->execute(['hash' => hashToken($token)]);
    $user = $statement->fetch();

    if (!$user) {
        return ['status' => 'invalid'];
    }

    $userId = (int) $user['id'];
    if (empty($user['verification_expires_at']) || strtotime((string) $user['verification_expires_at']) < time()) {
        return ['status' => 'expired', 'user_id' => $userId];
    }

    return ['status' => 'ok', 'user_id' => $userId];
}

/** Atomically verify + retire the token — false means someone already used it. */
function consumeEmailVerification(PDO $pdo, int $userId): bool
{
    $statement = $pdo->prepare(
        'UPDATE users
            SET email_verified_at = COALESCE(email_verified_at, NOW()),
                verification_token_hash = NULL,
                verification_expires_at = NULL,
                verification_sent_at = NULL
          WHERE id = :id
            AND verification_token_hash IS NOT NULL'
    );
    $statement->execute(['id' => $userId]);

    return $statement->rowCount() > 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Password reset
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a 60-minute single-use reset token, throttled (60 s between requests,
 * max 5 per hour per account). Previous unused tokens are retired first so
 * only the newest link works.
 *
 * Returns ['sent' => bool, 'token' => ?string, 'reason' => ?string] — callers
 * ALWAYS show the same generic success message; 'reason' is for error_log only.
 */
function issuePasswordReset(PDO $pdo, int $userId, ?string $ip = null): array
{
    try {
        $recent = $pdo->prepare('SELECT created_at FROM password_resets WHERE user_id = :uid ORDER BY id DESC LIMIT 1');
        $recent->execute(['uid' => $userId]);
        $lastRequestedAt = $recent->fetchColumn();
        if ($lastRequestedAt && strtotime((string) $lastRequestedAt) > time() - 60) {
            return ['sent' => false, 'token' => null, 'reason' => 'requested within the last minute'];
        }

        $hourCount = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :uid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $hourCount->execute(['uid' => $userId]);
        if ((int) $hourCount->fetchColumn() >= 5) {
            return ['sent' => false, 'token' => null, 'reason' => 'hourly limit reached'];
        }

        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
            ->execute(['uid' => $userId]);

        $token = randomToken();
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip)
             VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), :ip)'
        )->execute(['uid' => $userId, 'hash' => hashToken($token), 'ip' => $ip]);

        return ['sent' => true, 'token' => $token, 'reason' => null];
    } catch (Throwable $exception) {
        error_log('auth_tokens: password reset issue failed — ' . $exception->getMessage());
        return ['sent' => false, 'token' => null, 'reason' => 'database error'];
    }
}

/**
 * Validate a reset token WITHOUT consuming it (GET renders the form; the POST
 * that actually changes the password consumes it — so email-link scanners can
 * never burn a token the user hasn't used).
 *
 * Returns ['status' => ok|invalid|expired|used] (+ reset_id/user_id on ok).
 */
function lookupPasswordReset(PDO $pdo, string $token): array
{
    if ($token === '') {
        return ['status' => 'invalid'];
    }

    $statement = $pdo->prepare(
        'SELECT id, user_id, expires_at, used_at
           FROM password_resets
          WHERE token_hash = :hash
          LIMIT 1'
    );
    $statement->execute(['hash' => hashToken($token)]);
    $row = $statement->fetch();

    if (!$row) {
        return ['status' => 'invalid'];
    }
    if ($row['used_at']) {
        return ['status' => 'used'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['status' => 'expired'];
    }

    return ['status' => 'ok', 'reset_id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
}

/** Atomically consume the token — false means someone else already used it. */
function consumePasswordReset(PDO $pdo, int $resetId): bool
{
    $statement = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
    $statement->execute(['id' => $resetId]);

    return $statement->rowCount() > 0;
}

function setUserPassword(PDO $pdo, int $userId, string $newPassword): void
{
    $pdo->prepare('UPDATE users SET password = :password WHERE id = :id')
        ->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
}
