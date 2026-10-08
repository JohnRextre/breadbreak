<?php
// =============================================================================
// migrate_auth.php – BreadBreak Email Authentication Migration
// Adds the email-verification columns to users, creates password_resets, and
// auto-verifies accounts that existed BEFORE this migration (so nobody who
// could sign in yesterday is locked out today). Safe to re-run: the backfill
// only happens on the run that actually adds the columns.
// =============================================================================
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();
$results = [];

try {
    // ── 1. users: verification columns (idempotent) ──────────────────────────
    $existing = [];
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll();
    foreach ($columns as $column) {
        $existing[] = (string) $column['Field'];
    }

    $wanted = [
        'email_verified_at' => 'DATETIME NULL DEFAULT NULL',
        'verification_token_hash' => 'CHAR(64) NULL DEFAULT NULL',
        'verification_expires_at' => 'DATETIME NULL DEFAULT NULL',
        'verification_sent_at' => 'DATETIME NULL DEFAULT NULL',
    ];

    $addedFresh = [];
    foreach ($wanted as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$definition} AFTER status_reason");
            $addedFresh[] = $name;
        }
    }
    $results[] = $addedFresh
        ? 'Added columns: ' . implode(', ', $addedFresh) . '.'
        : 'Verification columns already present.';

    // ── 2. password_resets table (idempotent) ────────────────────────────────
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            request_ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_token (token_hash),
            INDEX idx_password_resets_user_created (user_id, created_at),
            CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $results[] = 'Table `password_resets` is ready.';

    // ── 3. Backfill ONLY on the run that added the columns ───────────────────
    if (in_array('email_verified_at', $addedFresh, true)) {
        $verified = $pdo->exec(
            'UPDATE users SET email_verified_at = COALESCE(created_at, NOW()) WHERE email_verified_at IS NULL'
        );
        $results[] = "Pre-existing accounts verified on first run: {$verified}.";
    } else {
        $results[] = 'Backfill skipped (columns were not added by this run) — pending accounts stay pending.';
    }
} catch (Throwable $e) {
    $results[] = 'Error: ' . $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    foreach ($results as $res) {
        echo "[MIGRATION] {$res}\n";
    }
} else {
    echo '<h1>Email Authentication Migration</h1><ul>';
    foreach ($results as $res) {
        echo '<li>' . htmlspecialchars($res) . '</li>';
    }
    echo '</ul>';
}
