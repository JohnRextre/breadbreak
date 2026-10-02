<?php
// =============================================================================
// includes/vouchers.php – BreadBreak Voucher System helpers
// Shared by registration (welcome voucher), checkout (validation + redemption),
// order cancellation (restore), and the admin voucher manager.
// =============================================================================

// ── Table / column bootstrap (safe to call on every request) ─────────────────
function ensureVoucherTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS vouchers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL UNIQUE,
        title VARCHAR(150) NOT NULL,
        description VARCHAR(255) NULL,
        discount_type ENUM('free_delivery','percent','fixed') NOT NULL DEFAULT 'free_delivery',
        discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        max_discount DECIMAL(10,2) NULL,
        min_spend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        usage_limit_total INT NULL,
        usage_limit_per_user INT NOT NULL DEFAULT 1,
        valid_from DATETIME NULL,
        valid_until DATETIME NULL,
        is_public TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_vouchers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        voucher_id INT NOT NULL,
        customer_id INT NOT NULL,
        source ENUM('welcome','admin_grant','promo_claim') NOT NULL DEFAULT 'admin_grant',
        status ENUM('unused','used','expired') NOT NULL DEFAULT 'unused',
        expires_at DATETIME NULL,
        used_at DATETIME NULL,
        order_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cv_customer (customer_id),
        KEY idx_cv_voucher (voucher_id),
        KEY idx_cv_order (order_id),
        CONSTRAINT fk_cv_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
        CONSTRAINT fk_cv_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$pdo->query("SHOW COLUMNS FROM vouchers LIKE 'is_promoted'")->fetch()) {
        $pdo->exec("ALTER TABLE vouchers ADD COLUMN is_promoted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public");
    }
    if (!$pdo->query("SHOW COLUMNS FROM vouchers LIKE 'promoted_moment_id'")->fetch()) {
        $pdo->exec("ALTER TABLE vouchers ADD COLUMN promoted_moment_id INT NULL AFTER is_promoted");
    }

    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'voucher_code'")->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN voucher_code VARCHAR(50) NULL AFTER discount_name");
    }
    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'voucher_discount'")->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN voucher_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER voucher_code");
    }

    $done = true;
}

// ── Welcome voucher (Free Delivery, one-time, granted on registration) ───────
function ensureWelcomeVoucher(PDO $pdo): int
{
    ensureVoucherTables($pdo);

    $stmt = $pdo->prepare("SELECT id FROM vouchers WHERE code = 'WELCOME' LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $pdo->exec(
        "INSERT INTO vouchers
            (code, title, description, discount_type, min_spend,
             usage_limit_per_user, is_public, status)
         VALUES
            ('WELCOME', 'Welcome Free Delivery',
             'Free delivery on your first order — our treat!',
             'free_delivery', 0.00, 1, 0, 'active')"
    );

    return (int) $pdo->lastInsertId();
}

// ── Grant a voucher to a customer ────────────────────────────────────────────
function grantVoucherToCustomer(
    PDO $pdo,
    int $voucherId,
    int $customerId,
    string $source = 'admin_grant',
    ?string $expiresAt = null
): bool {
    if (!in_array($source, ['welcome', 'admin_grant', 'promo_claim'], true)) {
        $source = 'admin_grant';
    }

    try {
        $pdo->prepare(
            "INSERT INTO customer_vouchers (voucher_id, customer_id, source, status, expires_at)
             VALUES (:vid, :cid, :src, 'unused', :exp)"
        )->execute([
            'vid' => $voucherId,
            'cid' => $customerId,
            'src' => $source,
            'exp' => $expiresAt,
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

// ── Vouchers the customer can still use right now ────────────────────────────
function getUsableCustomerVouchers(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare(
        "SELECT cv.id AS grant_id, cv.expires_at AS grant_expires_at, cv.source,
                v.id AS voucher_id, v.code, v.title, v.description, v.discount_type,
                v.discount_value, v.max_discount, v.min_spend, v.valid_until
         FROM customer_vouchers cv
         JOIN vouchers v ON v.id = cv.voucher_id
         WHERE cv.customer_id = :cid
           AND cv.status = 'unused'
           AND v.status = 'active'
           AND (cv.expires_at IS NULL OR cv.expires_at > NOW())
           AND (v.valid_from IS NULL OR v.valid_from <= NOW())
           AND (v.valid_until IS NULL OR v.valid_until >= NOW())
         ORDER BY (cv.expires_at IS NULL) ASC, cv.expires_at ASC, cv.id ASC"
    );
    $stmt->execute(['cid' => $customerId]);
    return $stmt->fetchAll();
}

// ── Validate a voucher code for an order being placed ────────────────────────
// Returns ['ok' => true, 'voucher' => row, 'grant' => row|null] or
//         ['ok' => false, 'error' => 'customer-friendly message']
function validateVoucherForOrder(
    PDO $pdo,
    string $code,
    int $customerId,
    float $subtotal,
    string $fulfillmentType
): array {
    ensureVoucherTables($pdo);

    $code = strtoupper(trim($code));
    if ($code === '' || strlen($code) > 50 || !preg_match('/^[A-Z0-9][A-Z0-9\-_]{0,48}[A-Z0-9]$/', $code)) {
        return ['ok' => false, 'error' => 'Please enter a valid voucher code.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $code]);
    $voucher = $stmt->fetch();

    if (!$voucher || $voucher['status'] !== 'active') {
        return ['ok' => false, 'error' => 'This voucher code is not valid or is no longer active.'];
    }

    $now = time();
    if (!empty($voucher['valid_from']) && strtotime((string) $voucher['valid_from']) > $now) {
        return ['ok' => false, 'error' => 'This voucher is not active yet.'];
    }
    if (!empty($voucher['valid_until']) && strtotime((string) $voucher['valid_until']) < $now) {
        return ['ok' => false, 'error' => 'This voucher has expired.'];
    }

    $minSpend = (float) ($voucher['min_spend'] ?? 0);
    if ($minSpend > 0 && $subtotal < $minSpend) {
        return ['ok' => false, 'error' => 'This voucher needs a minimum order of ₱' . number_format($minSpend, 2) . '.'];
    }

    if (($voucher['discount_type'] ?? 'free_delivery') === 'free_delivery' && $fulfillmentType !== 'delivery') {
        return ['ok' => false, 'error' => 'This voucher is for delivery orders only.'];
    }

    // Grants this customer holds for the voucher
    $grantStmt = $pdo->prepare(
        "SELECT * FROM customer_vouchers
         WHERE voucher_id = :vid AND customer_id = :cid
         ORDER BY id ASC"
    );
    $grantStmt->execute(['vid' => (int) $voucher['id'], 'cid' => $customerId]);
    $grants = $grantStmt->fetchAll();

    $usableGrant = null;
    $usedCount   = 0;
    foreach ($grants as $grant) {
        if ($grant['status'] === 'used') {
            $usedCount++;
            continue;
        }
        if ($grant['status'] === 'unused'
            && (empty($grant['expires_at']) || strtotime((string) $grant['expires_at']) > $now)
            && $usableGrant === null
        ) {
            $usableGrant = $grant;
        }
    }

    $perUserLimit = max(1, (int) ($voucher['usage_limit_per_user'] ?? 1));

    if (empty($voucher['is_public'])) {
        // Assigned vouchers need an unused grant sitting on the account.
        if (!$usableGrant) {
            return ['ok' => false, 'error' => $usedCount > 0
                ? 'You have already used this voucher.'
                : 'This voucher is not available on your account.'];
        }
    } else {
        // Public promo codes are claimable on the spot, within the per-user limit.
        if (!$usableGrant && ($usedCount + count(array_filter($grants, fn($g) => $g['status'] === 'unused'))) >= $perUserLimit) {
            return ['ok' => false, 'error' => 'You have already used this voucher the maximum number of times.'];
        }
    }

    $totalLimit = $voucher['usage_limit_total'] !== null ? (int) $voucher['usage_limit_total'] : null;
    if ($totalLimit !== null && $totalLimit > 0) {
        $totalUsed = (int) $pdo->query(
            "SELECT COUNT(*) FROM customer_vouchers WHERE voucher_id = " . (int) $voucher['id'] . " AND status = 'used'"
        )->fetchColumn();
        if ($totalUsed >= $totalLimit) {
            return ['ok' => false, 'error' => 'This voucher has reached its usage limit.'];
        }
    }

    return ['ok' => true, 'voucher' => $voucher, 'grant' => $usableGrant];
}

// ── Redeem a voucher inside the checkout transaction ─────────────────────────
// Throws RuntimeException if the grant was already consumed (double-submit safe).
function redeemVoucherForOrder(PDO $pdo, array $voucherRow, ?array $grantRow, int $customerId, int $orderId): void
{
    if ($grantRow) {
        $upd = $pdo->prepare(
            "UPDATE customer_vouchers
             SET status = 'used', used_at = NOW(), order_id = :oid
             WHERE id = :id AND status = 'unused'"
        );
        $upd->execute(['oid' => $orderId, 'id' => (int) $grantRow['id']]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('This voucher was already used. Please remove it and try again.');
        }
        return;
    }

    // Public promo code claimed at checkout time — record the redemption.
    $pdo->prepare(
        "INSERT INTO customer_vouchers (voucher_id, customer_id, source, status, expires_at, used_at, order_id)
         VALUES (:vid, :cid, 'promo_claim', 'used', NULL, NOW(), :oid)"
    )->execute([
        'vid' => (int) $voucherRow['id'],
        'cid' => $customerId,
        'oid' => $orderId,
    ]);
}

// ── Give the voucher back when its order gets cancelled ──────────────────────
function restoreVoucherForOrder(PDO $pdo, int $orderId): void
{
    try {
        $pdo->prepare(
            "UPDATE customer_vouchers
             SET status = 'unused', used_at = NULL, order_id = NULL
             WHERE order_id = :oid AND status = 'used'"
        )->execute(['oid' => $orderId]);
    } catch (Throwable) {
        // Voucher tables may not exist yet on older installs — never block a cancel.
    }
}
