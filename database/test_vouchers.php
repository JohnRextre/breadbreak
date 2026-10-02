<?php
// =============================================================================
// test_vouchers.php – End-to-end voucher flow test (CLI only)
// Simulates: welcome grant → validate → redeem → cancel/restore → re-validate
// Usage: C:\xampp\php\php.exe database\test_vouchers.php
// =============================================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/vouchers.php';

$pdo = getDatabaseConnection();
ensureVoucherTables($pdo);

$customerId = 2; // rextermendoza5@gmail.com (customer)
$pass = 0;
$fail = 0;

function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $label\n"; }
    else       { $fail++; echo "  ❌ $label\n"; }
}

echo "\n=== 1. Cleanup previous test state ===\n";
$pdo->exec("DELETE FROM customer_vouchers WHERE customer_id = $customerId");
$pdo->exec("DELETE FROM vouchers WHERE code = 'TESTPROMO'");
echo "  done\n";

echo "\n=== 2. Welcome grant (register.php flow) ===\n";
$welcomeId = ensureWelcomeVoucher($pdo);
check('WELCOME voucher exists', $welcomeId > 0);
$granted = grantVoucherToCustomer($pdo, $welcomeId, $customerId, 'welcome', date('Y-m-d H:i:s', strtotime('+30 days')));
check('Granted to customer #2', $granted);

echo "\n=== 3. Usable vouchers list (checkout/account display) ===\n";
$usable = getUsableCustomerVouchers($pdo, $customerId);
check('1 usable voucher', count($usable) === 1 && $usable[0]['code'] === 'WELCOME');

echo "\n=== 4. Validation (checkout server-side) ===\n";
$r = validateVoucherForOrder($pdo, 'WELCOME', $customerId, 250.00, 'delivery');
check('WELCOME valid for delivery', $r['ok'] === true && $r['grant'] !== null);
$r = validateVoucherForOrder($pdo, 'WELCOME', $customerId, 250.00, 'pickup');
check('WELCOME rejected for pickup', $r['ok'] === false);
$r = validateVoucherForOrder($pdo, 'NOSUCHCODE', $customerId, 250.00, 'delivery');
check('Unknown code rejected', $r['ok'] === false);
$r = validateVoucherForOrder($pdo, 'WELCOME', 1, 250.00, 'delivery');
check('Admin without grant rejected (assigned voucher)', $r['ok'] === false);

echo "\n=== 5. Redemption (checkout transaction) ===\n";
$r = validateVoucherForOrder($pdo, 'WELCOME', $customerId, 250.00, 'delivery');
redeemVoucherForOrder($pdo, $r['voucher'], $r['grant'], $customerId, 999999);
$check = $pdo->query("SELECT status, order_id FROM customer_vouchers WHERE customer_id = $customerId AND voucher_id = $welcomeId")->fetch();
check('Marked used with order link', $check['status'] === 'used' && (int) $check['order_id'] === 999999);
$r = validateVoucherForOrder($pdo, 'WELCOME', $customerId, 250.00, 'delivery');
check('Cannot re-use after redemption', $r['ok'] === false);

echo "\n=== 6. Cancel → restore (order_status flow) ===\n";
restoreVoucherForOrder($pdo, 999999);
$check = $pdo->query("SELECT status, used_at, order_id FROM customer_vouchers WHERE customer_id = $customerId AND voucher_id = $welcomeId")->fetch();
check('Restored to unused, order link cleared', $check['status'] === 'unused' && $check['order_id'] === null);
$r = validateVoucherForOrder($pdo, 'WELCOME', $customerId, 250.00, 'delivery');
check('Usable again after restore', $r['ok'] === true);

echo "\n=== 7. Public promo code flow ===\n";
$pdo->exec("INSERT INTO vouchers (code, title, description, discount_type, min_spend, usage_limit_per_user, usage_limit_total, is_public, status)
            VALUES ('TESTPROMO', 'Test Promo', 'public test', 'free_delivery', 200, 1, NULL, 1, 'active')");
$promoId = (int) $pdo->lastInsertId();
$r = validateVoucherForOrder($pdo, 'TESTPROMO', $customerId, 100.00, 'delivery');
check('Public code blocked under min spend', $r['ok'] === false);
$r = validateVoucherForOrder($pdo, 'TESTPROMO', $customerId, 250.00, 'delivery');
check('Public code valid without prior grant', $r['ok'] === true && $r['grant'] === null);
redeemVoucherForOrder($pdo, $r['voucher'], $r['grant'], $customerId, 999998);
$check = $pdo->query("SELECT status, source FROM customer_vouchers WHERE customer_id = $customerId AND voucher_id = $promoId")->fetch();
check('Promo claim recorded as used/promo_claim', $check['status'] === 'used' && $check['source'] === 'promo_claim');
$r = validateVoucherForOrder($pdo, 'TESTPROMO', $customerId, 250.00, 'delivery');
check('Public code blocked after per-user limit', $r['ok'] === false);

echo "\n=== 8. Cleanup test artifacts ===\n";
$pdo->exec("DELETE FROM customer_vouchers WHERE customer_id = $customerId");
$pdo->exec("DELETE FROM vouchers WHERE code = 'TESTPROMO'");
// Re-grant the welcome voucher so the real customer account still has it for manual testing
grantVoucherToCustomer($pdo, $welcomeId, $customerId, 'welcome', date('Y-m-d H:i:s', strtotime('+30 days')));
echo "  done (WELCOME re-granted to customer #2 for manual testing)\n";

echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
