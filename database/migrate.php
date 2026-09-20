<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();

$tables = [
    'orders' => "CREATE TABLE IF NOT EXISTS orders (
        id INT PRIMARY KEY AUTO_INCREMENT,
        customer_id INT NOT NULL,
        reference_id VARCHAR(32) NOT NULL UNIQUE,
        status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
    )",
    'order_items' => "CREATE TABLE IF NOT EXISTS order_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        order_id INT NOT NULL,
        variant_id INT NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        service_size VARCHAR(100) NOT NULL,
        sku VARCHAR(80) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
        CONSTRAINT fk_order_item_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON UPDATE CASCADE ON DELETE RESTRICT
    )",
    'payments' => "CREATE TABLE IF NOT EXISTS payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        order_id INT NOT NULL,
        xendit_payment_request_id VARCHAR(255) NOT NULL UNIQUE,
        reference_id VARCHAR(64) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'PHP',
        payment_method VARCHAR(60) NULL,
        payment_channel VARCHAR(60) NULL,
        status ENUM('pending','paid','failed','expired','voided') NOT NULL DEFAULT 'pending',
        xendit_raw_response TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT
    )",
];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $name\n";
    } catch (PDOException $e) {
        echo "ERROR $name: " . $e->getMessage() . "\n";
    }
}

// Verify
$rows = $pdo->query("SHOW TABLES LIKE 'order%'")->fetchAll(PDO::FETCH_COLUMN, 0);
$pay  = $pdo->query("SHOW TABLES LIKE 'payments'")->fetchColumn();
echo "Tables created: " . implode(', ', $rows) . ", payments=" . ($pay ?: 'NO') . "\n";
echo "Migration DONE\n";
