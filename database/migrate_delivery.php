<?php
// =============================================================================
// migrate_delivery.php  –  BreadBreak Delivery Zone System Migration
// Run this once via: http://localhost/BreadBreak/database/migrate_delivery.php
// Safe to re-run (all operations are idempotent).
// =============================================================================
require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
$results = [];

function runStep(PDO $pdo, string $label, string $sql): void {
    global $results;
    try {
        $pdo->exec($sql);
        $results[] = ['ok', $label];
    } catch (PDOException $e) {
        $results[] = ['err', $label . ': ' . $e->getMessage()];
    }
}

// ── 0. customer_addresses ─────────────────────────────────────────────────────
runStep($pdo, 'Create customer_addresses table', "
    CREATE TABLE IF NOT EXISTS customer_addresses (
        id INT PRIMARY KEY AUTO_INCREMENT,
        customer_id INT NOT NULL,
        label VARCHAR(60) NOT NULL DEFAULT 'Home',
        full_address TEXT NOT NULL,
        barangay VARCHAR(100) NOT NULL DEFAULT '',
        city VARCHAR(100) NOT NULL DEFAULT '',
        province VARCHAR(100) NOT NULL DEFAULT '',
        postal_code VARCHAR(20) NOT NULL DEFAULT '',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_addr_customer (customer_id),
        CONSTRAINT fk_addr_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

// ── 1. delivery_settings ──────────────────────────────────────────────────────
runStep($pdo, 'Create delivery_settings table', "
    CREATE TABLE IF NOT EXISTS delivery_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// ── 2. delivery_geocode_cache (24-hr TTL to respect Nominatim rate limit) ─────
runStep($pdo, 'Create delivery_geocode_cache table', "
    CREATE TABLE IF NOT EXISTS delivery_geocode_cache (
        id INT PRIMARY KEY AUTO_INCREMENT,
        address_hash CHAR(64) NOT NULL UNIQUE,
        address_raw TEXT NOT NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        geocode_success TINYINT(1) NOT NULL DEFAULT 0,
        cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_geocache_hash (address_hash)
    )
");

// ── 3. delivery_zone_logs (analytics per order) ───────────────────────────────
runStep($pdo, 'Create delivery_zone_logs table', "
    CREATE TABLE IF NOT EXISTS delivery_zone_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        order_id INT NOT NULL,
        customer_address TEXT NOT NULL,
        zone VARCHAR(20) NULL,
        distance_km DECIMAL(8,3) NULL,
        drive_time_min DECIMAL(8,1) NULL,
        delivery_fee_charged DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        mode_used ENUM('simple','full') NOT NULL DEFAULT 'simple',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_zone_logs_order (order_id)
    )
");

// ── 4. Add delivery_fee + delivery_address columns to orders ──────────────────
$colCheck = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'orders'
       AND column_name = 'delivery_fee'"
)->fetchColumn();

if (!$colCheck) {
    runStep($pdo, 'Add delivery_fee to orders', "
        ALTER TABLE orders
            ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER status,
            ADD COLUMN delivery_address TEXT NULL AFTER delivery_fee
    ");
} else {
    $results[] = ['ok', 'delivery_fee column already exists in orders (skipped)'];
}

// ── 5. Seed default delivery_settings ────────────────────────────────────────
$defaults = [
    'delivery_mode' => 'simple',
    'branch_lat'    => '14.8310',
    'branch_lng'    => '120.8720',
    'branch_name'   => 'Estrella Village Branch',
    'max_drive_time_min' => '25',
    'delivery_zones' => json_encode([
        ['name' => 'zone_1', 'label' => 'Zone 1', 'max_km' => 3,  'fee' => 0,  'min_order' => 0],
        ['name' => 'zone_2', 'label' => 'Zone 2', 'max_km' => 6,  'fee' => 50, 'min_order' => 0],
        ['name' => 'zone_3', 'label' => 'Zone 3', 'max_km' => 8,  'fee' => 80, 'min_order' => 300],
    ]),
    'in_range_areas' => json_encode([
        'guiguinto','ilang-ilang','ligas','malolos','meycauayan',
        'san jose del monte','plaridel','hagonoy'
    ]),
    'geoapify_api_key' => '',
];

$upsert = $pdo->prepare(
    "INSERT INTO delivery_settings (setting_key, setting_value)
     VALUES (:k, :v)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
);

foreach ($defaults as $key => $value) {
    // Don't overwrite user-edited values — only insert if missing
    $existing = $pdo->prepare(
        "SELECT COUNT(*) FROM delivery_settings WHERE setting_key = :k"
    );
    $existing->execute(['k' => $key]);
    if ((int) $existing->fetchColumn() === 0) {
        $upsert->execute(['k' => $key, 'v' => $value]);
        $results[] = ['ok', "Seeded setting: $key"];
    } else {
        $results[] = ['ok', "Setting already exists: $key (skipped)"];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Migration | BreadBreak</title>
    <style>
        body { font-family: system-ui, sans-serif; padding: 2rem; max-width: 780px; margin: auto; background: #faf8f5; }
        h1 { color: #3d1f0d; margin-bottom: 1.5rem; }
        .step { padding: .55rem 1rem; border-radius: 8px; margin-bottom: .5rem; font-size: .93rem; display: flex; gap: .75rem; align-items: center; }
        .step.ok  { background: #e8f7ef; color: #1a6645; border: 1px solid #a3d9bc; }
        .step.err { background: #fde8e8; color: #891515; border: 1px solid #f5a3a3; }
        .done { background: #fff3e0; color: #7a4500; padding: 1.2rem 1.5rem; border-radius: 12px; border: 1px solid #f5c842; margin-top: 1.5rem; }
        a { color: #b85c00; }
    </style>
</head>
<body>
    <h1>🚚 Delivery Zone Migration</h1>
    <?php foreach ($results as [$status, $msg]): ?>
        <div class="step <?= $status ?>">
            <?= $status === 'ok' ? '✅' : '❌' ?>
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endforeach; ?>

    <div class="done">
        <strong>Migration complete!</strong><br>
        You can now go to <a href="/BreadBreak/admin/settings.php">Admin → Settings</a> to configure delivery zones,
        or try the delivery check on the <a href="/BreadBreak/checkout.php">Checkout page</a>.
    </div>
</body>
</html>

