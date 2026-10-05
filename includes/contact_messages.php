<?php
/**
 * Shared schema bootstrap for the contact message inbox.
 * Creates contact_messages + contact_replies and ensures the status column.
 */
function ensureContactMessageTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        subject VARCHAR(120) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $column = $pdo->query("SHOW COLUMNS FROM contact_messages LIKE 'status'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new' AFTER message");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        reply_body TEXT NOT NULL,
        admin_name VARCHAR(120) DEFAULT '',
        sender VARCHAR(20) NOT NULL DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact_replies_message (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $senderCol = $pdo->query("SHOW COLUMNS FROM contact_replies LIKE 'sender'")->fetch();
    if (!$senderCol) {
        $pdo->exec("ALTER TABLE contact_replies ADD COLUMN sender VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER admin_name");
    }
}
