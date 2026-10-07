<?php

/**
 * Account activity log — powers the rider's "Activity History" feed.
 *
 * One row per account-level event (sign-in, sign-out, password/photo changes).
 * Delivery milestones are NOT written here: order_status_history already records
 * who did what to an order, so the Activity page joins the two sources together.
 *
 * Auditing is best-effort by design — a failed insert must never break the
 * action it is auditing (mirrors logOrderStatusChange in includes/order_status.php).
 */
function ensureUserActivityLog(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_activity_log (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                action VARCHAR(40) NOT NULL,
                detail VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ual_user_created (user_id, created_at),
                CONSTRAINT fk_ual_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (Throwable) {
        // Already exists, or this account cannot CREATE — inserts below still try,
        // and any failure there is swallowed the same way.
    }
}

function logUserActivity(PDO $pdo, int $userId, string $action, ?string $detail = null): void
{
    if ($userId <= 0 || $action === '') {
        return;
    }

    ensureUserActivityLog($pdo);

    try {
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $pdo->prepare(
            'INSERT INTO user_activity_log (user_id, action, detail, ip_address)
             VALUES (:user_id, :action, :detail, :ip_address)'
        )->execute([
            'user_id' => $userId,
            'action' => $action,
            'detail' => ($detail !== null && trim($detail) !== '') ? mb_substr(trim($detail), 0, 255) : null,
            'ip_address' => $ipAddress !== '' ? mb_substr($ipAddress, 0, 45) : null,
        ]);
    } catch (Throwable) {
        // Best-effort audit only — never block the real action.
    }
}
