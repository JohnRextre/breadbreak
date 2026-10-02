<?php
// =============================================================================
// migrate_vouchers.php – BreadBreak Voucher System Migration
// Creates the vouchers + customer_vouchers tables, adds voucher columns to
// orders, and seeds the WELCOME free-delivery voucher. Safe to re-run.
// =============================================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/vouchers.php';

$pdo = getDatabaseConnection();
$results = [];

try {
    ensureVoucherTables($pdo);
    $results[] = 'Tables `vouchers` and `customer_vouchers` are ready.';
    $results[] = 'Columns `orders.voucher_code` and `orders.voucher_discount` are ready.';

    $welcomeId = ensureWelcomeVoucher($pdo);
    $results[] = "WELCOME voucher is present (id {$welcomeId}).";
} catch (Throwable $e) {
    $results[] = 'Error: ' . $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    foreach ($results as $res) {
        echo "[MIGRATION] $res\n";
    }
} else {
    echo "<h1>Voucher System Migration</h1><ul>";
    foreach ($results as $res) {
        echo "<li>" . htmlspecialchars($res) . "</li>";
    }
    echo "</ul><p><a href='/BreadBreak/admin/vouchers.php'>Go to Voucher Manager</a></p>";
}
