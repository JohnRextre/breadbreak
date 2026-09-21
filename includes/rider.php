<?php
// Rider role + assignment helpers.

function ensureRiderSupport(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "ALTER TABLE users
             MODIFY COLUMN role ENUM('admin', 'staff', 'rider', 'customer') NOT NULL"
        );
    } catch (Throwable) {
        // Already migrated, or the account cannot ALTER.
    }

    $riderCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'orders'
           AND column_name = 'rider_id'"
    )->fetchColumn();

    if (!$riderCol) {
        try {
            $pdo->exec(
                "ALTER TABLE orders
                 ADD COLUMN rider_id INT NULL AFTER customer_id,
                 ADD CONSTRAINT fk_order_rider
                    FOREIGN KEY (rider_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL"
            );
        } catch (Throwable) {
            try {
                $pdo->exec('ALTER TABLE orders ADD COLUMN rider_id INT NULL AFTER customer_id');
            } catch (Throwable) {
                // Column already exists or cannot be added.
            }
        }
    }

    ensureDeliveryMessages($pdo);
}

function activeRiders(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, first_name, last_name, phone
         FROM users
         WHERE role = 'rider' AND status = 'active'
         ORDER BY first_name ASC, last_name ASC"
    );
    return $stmt ? $stmt->fetchAll() : [];
}

function riderDisplayName(array $rider): string
{
    return trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''));
}

function ensureDeliveryMessages(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS delivery_messages (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL,
                sender_id INT NOT NULL,
                sender_role ENUM('rider', 'customer') NOT NULL,
                body VARCHAR(500) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_delivery_msg_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_delivery_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_delivery_msg_order_created (order_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable) {
        // Table already exists, or the account cannot CREATE.
    }
}

function deliveryChatOrder(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT o.id, o.reference_id, o.status, o.fulfillment_type, o.delivery_address,
                o.customer_id, o.rider_id,
                c.first_name AS customer_first, c.last_name AS customer_last, c.phone AS customer_phone,
                c.profile_data AS customer_photo, c.profile_mime AS customer_mime,
                r.first_name AS rider_first, r.last_name AS rider_last, r.phone AS rider_phone,
                r.profile_data AS rider_photo, r.profile_mime AS rider_mime
         FROM orders o
         JOIN users c ON c.id = o.customer_id
         LEFT JOIN users r ON r.id = o.rider_id
         WHERE o.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function deliveryMessages(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, sender_id, sender_role, body, created_at
         FROM delivery_messages
         WHERE order_id = :oid
         ORDER BY id ASC"
    );
    $stmt->execute(['oid' => $orderId]);
    return $stmt->fetchAll();
}

function deliveryRiderHasMessaged(array $messages): bool
{
    foreach ($messages as $message) {
        if (($message['sender_role'] ?? '') === 'rider') {
            return true;
        }
    }
    return false;
}

function sendDeliveryMessage(PDO $pdo, int $orderId, int $senderId, string $role, string $body): string
{
    $text = trim($body);
    if ($text === '') {
        return 'Type a message first.';
    }
    if (mb_strlen($text) > 500) {
        return 'Keep messages under 500 characters.';
    }

    $pdo->prepare(
        "INSERT INTO delivery_messages (order_id, sender_id, sender_role, body)
         VALUES (:oid, :sid, :role, :body)"
    )->execute([
        'oid' => $orderId,
        'sid' => $senderId,
        'role' => $role,
        'body' => $text,
    ]);
    return '';
}

function userPhotoDataUri(?string $data, ?string $mime): string
{
    if ($data !== null && $data !== '' && $mime) {
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
    return '';
}
