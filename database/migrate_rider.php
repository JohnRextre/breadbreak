<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rider.php';

$pdo = getDatabaseConnection();
ensureRiderSupport($pdo);
echo "OK: rider role and orders.rider_id are ready.\n";
