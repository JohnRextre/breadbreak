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
    ensureDeliveryCompletionSupport($pdo);
}

// Columns the rider needs to close out a delivery: proof photo, cash collected, chat lock.
function ensureDeliveryCompletionSupport(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'proof_photo_data' => 'MEDIUMBLOB NULL',
        'proof_photo_mime' => 'VARCHAR(50) NULL',
        'proof_note' => 'VARCHAR(255) NULL',
        'proof_captured_at' => 'DATETIME NULL',
        'collected_amount' => 'DECIMAL(10,2) NULL',
        'collected_at' => 'DATETIME NULL',
        'chat_closed_at' => 'DATETIME NULL',
        'chat_closed_by' => 'VARCHAR(20) NULL',
    ];

    foreach ($columns as $name => $definition) {
        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ' . $pdo->quote('orders') . '
               AND column_name = ' . $pdo->quote($name)
        )->fetchColumn();

        if ($exists) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE orders ADD COLUMN {$name} {$definition}");
        } catch (Throwable) {
            // Column already exists, or the account cannot ALTER.
        }
    }
}

function deliveryProofLimits(): array
{
    return [
        'max_bytes' => 5 * 1024 * 1024,
        'mimes' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ];
}

// Returns ['data' => binary, 'mime' => 'image/jpeg'] or null when no file was attached.
// Throws RuntimeException with a rider-friendly message on anything invalid.
function readDeliveryProofUpload(string $field = 'proof_photo'): ?array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    $file = $_FILES[$field];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $limits = deliveryProofLimits();

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That photo is too large. Please upload one under 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('The delivery photo failed to upload. Please try again.');
    }
    if ((int) ($file['size'] ?? 0) > $limits['max_bytes']) {
        throw new RuntimeException('That photo is too large. Please upload one under 5 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    if (!isset($limits['mimes'][$mime])) {
        throw new RuntimeException('The delivery photo must be a JPG, PNG, or WEBP image.');
    }

    $data = file_get_contents((string) $file['tmp_name']);
    if ($data === false || $data === '') {
        throw new RuntimeException('Unable to read the uploaded delivery photo.');
    }

    return ['data' => $data, 'mime' => $mime];
}

// Locks the rider/customer thread. Called the moment an order leaves the rider's hands.
function endDeliveryConversation(PDO $pdo, int $orderId, string $closedBy = 'rider'): void
{
    $pdo->prepare(
        "UPDATE orders
         SET chat_closed_at = IFNULL(chat_closed_at, NOW()),
             chat_closed_by = IFNULL(chat_closed_by, :by)
         WHERE id = :id"
    )->execute(['id' => $orderId, 'by' => $closedBy]);
}

function reopenDeliveryConversation(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE orders SET chat_closed_at = NULL, chat_closed_by = NULL WHERE id = :id')
        ->execute(['id' => $orderId]);
}

function deliveryChatClosed(array $order): bool
{
    if (trim((string) ($order['chat_closed_at'] ?? '')) !== '') {
        return true;
    }
    return in_array((string) ($order['status'] ?? ''), ['cancelled', 'completed'], true);
}

function deliveryChatClosedReason(array $order): string
{
    $status = (string) ($order['status'] ?? '');
    if ($status === 'cancelled') {
        return 'This order was cancelled, so the chat is closed.';
    }
    if ($status === 'completed' || trim((string) ($order['chat_closed_at'] ?? '')) !== '') {
        return 'Delivered. This conversation has ended — please contact the bakery if you need help.';
    }
    return 'This conversation has ended.';
}

// Logs an automated note in the thread (e.g. the closing message after a delivery).
function logDeliverySystemMessage(PDO $pdo, int $orderId, int $senderId, string $body): void
{
    $text = trim($body);
    if ($text === '') {
        return;
    }
    try {
        $pdo->prepare(
            "INSERT INTO delivery_messages (order_id, sender_id, sender_role, body)
             VALUES (:oid, :sid, 'rider', :body)"
        )->execute([
            'oid' => $orderId,
            'sid' => $senderId,
            'body' => mb_substr($text, 0, 500),
        ]);
    } catch (Throwable) {
        // Never block a delivery just because the audit note could not be written.
    }
}

// Normalised cash picture for an order row, so the card and the handlers agree.
function orderCashSummary(array $row): array
{
    $isCash = strtoupper((string) ($row['payment_method'] ?? '')) === 'CASH';
    $total = (float) ($row['total_amount'] ?? 0);
    $declared = (float) ($row['cash_amount'] ?? 0);
    $collected = array_key_exists('collected_amount', $row) && $row['collected_amount'] !== null
        ? (float) $row['collected_amount']
        : null;
    $received = $collected ?? $declared;

    return [
        'is_cash' => $isCash,
        'total' => $total,
        'declared' => $declared,
        'collected' => $collected,
        'received' => $received,
        'change' => $received > 0 ? max(0, $received - $total) : 0.0,
        'paid' => strtolower((string) ($row['payment_status'] ?? 'pending')) === 'paid',
        'collected_at' => $row['collected_at'] ?? null,
    ];
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
                o.customer_id, o.rider_id, o.chat_closed_at, o.chat_closed_by,
                o.proof_captured_at, (o.proof_photo_data IS NOT NULL) AS has_proof,
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
