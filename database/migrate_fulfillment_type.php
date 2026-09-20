<?php
// =============================================================================
// migrate_fulfillment_type.php – BreadBreak Fulfillment (Delivery vs Pickup)
// =============================================================================
require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();

try {
    $check = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns 
         WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'fulfillment_type'"
    )->fetchColumn();

    if (!$check) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN fulfillment_type ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery' AFTER status");
        echo "[MIGRATION] Added column `fulfillment_type` to `orders`.\n";
    } else {
        echo "[MIGRATION] Column `fulfillment_type` already exists in `orders`.\n";
    }
} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
