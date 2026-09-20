<?php
// =============================================================================
// migrate_vat_discount.php – BreadBreak VAT & Senior/PWD Discount Migration
// =============================================================================
require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
$results = [];

function checkAndAddColumn(PDO $pdo, string $table, string $column, string $definition): void {
    global $results;
    try {
        $check = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns 
             WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = '$column'"
        )->fetchColumn();

        if (!$check) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $results[] = "Added column `$column` to `$table`";
        } else {
            $results[] = "Column `$column` already exists in `$table` (skipped)";
        }
    } catch (PDOException $e) {
        $results[] = "Error on `$column`: " . $e->getMessage();
    }
}

// Add columns to orders table
checkAndAddColumn($pdo, 'orders', 'subtotal', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER status");
checkAndAddColumn($pdo, 'orders', 'vatable_sales', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal");
checkAndAddColumn($pdo, 'orders', 'vat_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vatable_sales");
checkAndAddColumn($pdo, 'orders', 'vat_exempt_sales', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat_amount");
checkAndAddColumn($pdo, 'orders', 'discount_type', "ENUM('none','senior','pwd') NOT NULL DEFAULT 'none' AFTER vat_exempt_sales");
checkAndAddColumn($pdo, 'orders', 'discount_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type");
checkAndAddColumn($pdo, 'orders', 'discount_id_number', "VARCHAR(100) NULL AFTER discount_amount");
checkAndAddColumn($pdo, 'orders', 'discount_name', "VARCHAR(150) NULL AFTER discount_id_number");
checkAndAddColumn($pdo, 'orders', 'total_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER delivery_address");

if (php_sapi_name() === 'cli') {
    foreach ($results as $res) {
        echo "[MIGRATION] $res\n";
    }
} else {
    echo "<h1>VAT & Discount Migration</h1><ul>";
    foreach ($results as $res) {
        echo "<li>" . htmlspecialchars($res) . "</li>";
    }
    echo "</ul><p><a href='/BreadBreak/checkout.php'>Go to Checkout</a></p>";
}
