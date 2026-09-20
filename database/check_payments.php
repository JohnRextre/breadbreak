<?php
require_once __DIR__ . '/../config/database.php';
$pdo  = getDatabaseConnection();
$rows = $pdo->query('SELECT reference_id, xendit_payment_request_id, status, order_id FROM payments ORDER BY id DESC LIMIT 5')->fetchAll();
echo "=== Recent Payments ===\n";
foreach ($rows as $r) {
    echo $r['reference_id'] . ' | status=' . $r['status'] . ' | order_id=' . $r['order_id'] . "\n";
}
if (empty($rows)) echo "(no payment records yet)\n";

