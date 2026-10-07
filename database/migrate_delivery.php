<?php
// =============================================================================
// migrate_delivery.php  –  BreadBreak Delivery Zone System Migration
// Run this once via: http://localhost/BreadBreak/database/migrate_delivery.php
// Safe to re-run (all operations are idempotent).
// =============================================================================
require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
$results = [];

// Results are grouped so the report reads as sections rather than one long
// undifferentiated list. 'action' drives the icon and the summary counters.
const G_TABLES   = 'tables';
const G_COLUMNS  = 'columns';
const G_SETTINGS = 'settings';
const G_CLEANUP  = 'cleanup';

function addResult(string $status, string $group, string $action, string $msg): void {
    global $results;
    $results[] = ['status' => $status, 'group' => $group, 'action' => $action, 'msg' => $msg];
}

function runStep(PDO $pdo, string $group, string $label, string $sql): void {
    // CREATE TABLE IF NOT EXISTS is a no-op when the table is already there —
    // say so instead of always reporting "Create X table", which made every
    // re-run look as though it had rebuilt something.
    if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[`"]?(\w+)/i', $sql, $m)) {
        try {
            $chk = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t'
            );
            $chk->execute([':t' => $m[1]]);
            if ((int) $chk->fetchColumn() > 0) {
                addResult('ok', $group, 'skipped', $m[1] . ' table already exists');
                return;
            }
        } catch (PDOException $e) {
            // Fall through and let the CREATE attempt report its own outcome.
        }
    }

    try {
        $pdo->exec($sql);
        addResult('ok', $group, 'created', $label);
    } catch (PDOException $e) {
        addResult('err', $group, 'error', $label . ': ' . $e->getMessage());
    }
}

// ── 0. customer_addresses ─────────────────────────────────────────────────────
runStep($pdo, G_TABLES, 'Create customer_addresses table', "
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
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_addr_customer (customer_id),
        CONSTRAINT fk_addr_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

// ── 1. delivery_settings ──────────────────────────────────────────────────────
runStep($pdo, G_TABLES, 'Create delivery_settings table', "
    CREATE TABLE IF NOT EXISTS delivery_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// ── 2. delivery_geocode_cache (24-hr TTL to respect Nominatim rate limit) ─────
runStep($pdo, G_TABLES, 'Create delivery_geocode_cache table', "
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
runStep($pdo, G_TABLES, 'Create delivery_zone_logs table', "
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
    runStep($pdo, G_COLUMNS, 'Add delivery_fee to orders', "
        ALTER TABLE orders
            ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER status,
            ADD COLUMN delivery_address TEXT NULL AFTER delivery_fee
    ");
} else {
    addResult('ok', G_COLUMNS, 'skipped', 'orders.delivery_fee already exists');
}

// ── 4b. Pin coordinates on saved addresses ─────────────────────────────────────
// Without them checkout can only re-check a saved address by its text, which
// needs a fresh Nominatim geocode plus an OSRM driving distance. That often
// lands outside 8km for an address the customer already approved with a pin
// (Cutcot: 7.93km straight-line, 8.89km driving). Storing the pin lets
// checkout run the exact straight-line check they approved when saving.
$addrCoordCheck = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'customer_addresses'
       AND column_name = 'latitude'"
)->fetchColumn();

if (!$addrCoordCheck) {
    runStep($pdo, G_COLUMNS, 'Add pin coordinates to customer_addresses', "
        ALTER TABLE customer_addresses
            ADD COLUMN latitude DECIMAL(10,7) NULL AFTER is_default,
            ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude
    ");
} else {
    addResult('ok', G_COLUMNS, 'skipped', 'customer_addresses.latitude / longitude already exist');
}

// ── 5. Seed default delivery_settings ────────────────────────────────────────
$defaults = [
    'delivery_mode' => 'simple',
    'branch_lat'    => '14.8310',
    'branch_lng'    => '120.8720',
    'branch_name'   => 'Estrella Village Branch',
    'max_drive_time_min' => '25',
    // 3 tiers so the admin form always has a full set of rows. Every tier
    // defaults to the same ₱50 / 8km behaviour the app messages describe.
    'delivery_zones' => json_encode([
        ['name' => 'zone_1', 'label' => 'Zone 1 (0-3km)', 'max_km' => 3, 'fee' => 50, 'min_order' => 0],
        ['name' => 'zone_2', 'label' => 'Zone 2 (3-6km)', 'max_km' => 6, 'fee' => 50, 'min_order' => 0],
        ['name' => 'zone_3', 'label' => 'Zone 3 (6-8km)', 'max_km' => 8, 'fee' => 50, 'min_order' => 0],
    ]),
    // Only places within the branch's 8km *driving* radius (verified against
    // OSRM). A wider list made simple mode accept addresses that full mode
    // blocked — e.g. Pulilan (10.5km), Marilao (13km), Baliwag (16.4km).
    // "bulacan" is deliberately absent: as a province keyword it matched every
    // address in the province regardless of distance.
    'in_range_areas' => json_encode([
        'guiguinto', 'ilang-ilang', 'ligas', 'malolos', 'plaridel',
        'balagtas', 'bocaue', 'bulakan', 'tikay', 'tabang', 'daungan',
        'longos', 'estacion', 'tuktukan', 'tabe', 'malis', 'panginay',
        'pritil', 'pulong gubat', 'santa ines', 'sta. ines'
    ]),
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
        addResult('ok', G_SETTINGS, 'seeded', "Seeded default: $key");
    } else {
        addResult('ok', G_SETTINGS, 'skipped', "Already saved: $key");
    }
}

// ── 6. Remove settings that are no longer used ────────────────────────────────
// geoapify_api_key was read on every delivery check but never referenced —
// Nominatim (geocoding) and OSRM (driving distance) are free and keyless.
$obsoleteSettings = ['geoapify_api_key'];
foreach ($obsoleteSettings as $key) {
    $del = $pdo->prepare("DELETE FROM delivery_settings WHERE setting_key = :k");
    $del->execute(['k' => $key]);
    // Only report when something was actually removed — an absent key is the
    // normal case and printing it every run just put the retired name back on
    // screen after it had been taken out of the app.
    if ($del->rowCount() > 0) {
        addResult('ok', G_CLEANUP, 'removed', "Removed obsolete setting: $key");
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Delivery Migration | BreadBreak</title>
    <style>
        :root {
            --bg: #faf8f5; --card: #fff; --ink: #3d1f0d; --muted: #8a7a68;
            --line: #ede8e0; --accent: #b85c00;
            --err-bg: #fde8e8; --err-ink: #891515; --err-line: #f5a3a3;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 2.5rem 1.25rem; background: var(--bg); color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.55;
        }
        .wrap { max-width: 820px; margin: 0 auto; }

        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, #3d1f0d 0%, #6b3a12 55%, #b85c00 100%);
            color: #fff; border-radius: 18px; padding: 1.5rem 1.7rem;
            box-shadow: 0 12px 30px rgba(61,31,13,.22);
        }
        .hero .row { display: flex; gap: 1.1rem; align-items: center; }
        .hero .emoji { font-size: 2.5rem; line-height: 1; }
        .hero h1 { margin: 0; font-size: 1.4rem; letter-spacing: -.015em; }
        .hero p { margin: .2rem 0 0; font-size: .87rem; color: rgba(255,255,255,.8); }
        .hero .pill {
            display: inline-block; margin-top: .75rem; padding: .2rem .7rem;
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
            border-radius: 999px; font-size: .74rem; font-weight: 700; letter-spacing: .05em;
            text-transform: uppercase;
        }

        /* ── Summary counters ── */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px,1fr)); gap: .7rem; margin: 1.1rem 0; }
        .stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: .8rem .95rem; display: flex; gap: .6rem; align-items: center; }
        .stat .ico { font-size: 1.4rem; line-height: 1; }
        .stat .num { font-size: 1.25rem; font-weight: 800; line-height: 1.05; }
        .stat .lbl { font-size: .7rem; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); }
        .stat.bad { background: var(--err-bg); border-color: var(--err-line); }
        .stat.bad .num, .stat.bad .lbl { color: var(--err-ink); }

        /* ── Grouped steps ── */
        .grp { background: var(--card); border: 1px solid var(--line); border-radius: 16px; margin-bottom: .85rem; overflow: hidden; }
        .grp > header {
            display: flex; align-items: center; gap: .6rem;
            padding: .78rem 1.1rem; background: #faf8f5; border-bottom: 1px solid var(--line);
        }
        .grp > header h2 { margin: 0; font-size: .78rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 700; }
        .grp > header .ico { font-size: 1rem; }
        .grp > header .count { margin-left: auto; font-size: .72rem; color: var(--muted); background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: .08rem .6rem; }
        .step { display: flex; gap: .7rem; align-items: flex-start; padding: .6rem 1.1rem; font-size: .895rem; border-bottom: 1px solid #f5f1eb; }
        .step:last-child { border-bottom: none; }
        .step .ico { flex: 0 0 auto; width: 1.4rem; text-align: center; }
        .step.err { background: var(--err-bg); color: var(--err-ink); font-weight: 600; }

        /* ── Verdict ── */
        .verdict { border-radius: 16px; padding: 1.1rem 1.25rem; margin-top: 1.2rem; display: flex; gap: .85rem; align-items: flex-start; }
        .verdict.good { background: #e8f7ef; border: 1px solid #a3d9bc; color: #14503a; }
        .verdict.bad  { background: var(--err-bg);  border: 1px solid var(--err-line); color: var(--err-ink); }
        .verdict .ico { font-size: 1.55rem; line-height: 1.2; }
        .verdict h3 { margin: 0 0 .15rem; font-size: 1.02rem; }
        .verdict p { margin: .1rem 0 0; font-size: .865rem; }
        .verdict a { color: inherit; font-weight: 700; }

        /* ── Action buttons ── */
        .actions { display: flex; flex-wrap: wrap; gap: .65rem; margin-top: 1.2rem; }
        .btn {
            display: inline-flex; align-items: center; gap: .45rem;
            padding: .7rem 1.15rem; border-radius: 10px; font-size: .89rem; font-weight: 700;
            text-decoration: none; border: 1.5px solid transparent; cursor: pointer;
            transition: filter .15s, border-color .15s;
        }
        .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 6px 16px rgba(184,92,0,.28); }
        .btn-primary:hover { filter: brightness(1.07); }
        .btn-ghost { background: var(--card); color: var(--accent); border-color: var(--line); }
        .btn-ghost:hover { border-color: var(--accent); }

        .note { margin-top: 1.5rem; font-size: .78rem; color: var(--muted); text-align: center; }
        .note strong { color: var(--err-ink); }
        .note a { color: var(--accent); }
    </style>
</head>
<body>
<div class="wrap">

    <div class="hero">
        <div class="row">
            <span class="emoji">🚚</span>
            <div>
                <h1>Delivery Zone Migration</h1>
                <p>Creates anything that is missing and fills in defaults you have never set.</p>
            </div>
        </div>
        <span class="pill">Idempotent · safe to re-run</span>
    </div>

    <?php
    // ── Counters ────────────────────────────────────────────────────────────
    $actionIcon = ['created' => '🆕', 'skipped' => '⏭️', 'seeded' => '🌱', 'removed' => '🧹', 'error' => '❌'];
    $actionName = ['created' => 'Created', 'skipped' => 'Skipped', 'seeded' => 'Seeded', 'removed' => 'Removed', 'error' => 'Failed'];
    $counts = [];
    foreach ($results as $r) {
        $counts[$r['action']] = ($counts[$r['action']] ?? 0) + 1;
    }
    $failed = $counts['error'] ?? 0;

    $cards = [];
    foreach (['created', 'skipped', 'seeded', 'removed', 'error'] as $a) {
        $n = $counts[$a] ?? 0;
        if ($n === 0 && $a !== 'error' && $a !== 'created' && $a !== 'skipped') continue;
        $cards[] = ['ico' => $actionIcon[$a], 'n' => $n, 'lbl' => $actionName[$a], 'bad' => $a === 'error'];
    }
    ?>
    <div class="stats">
        <?php foreach ($cards as $c): ?>
        <div class="stat <?= $c['bad'] ? 'bad' : '' ?>">
            <span class="ico"><?= $c['ico'] ?></span>
            <div>
                <div class="num"><?= $c['n'] ?></div>
                <div class="lbl"><?= $c['lbl'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
    // ── Grouped sections ────────────────────────────────────────────────────
    $groupMeta = [
        G_TABLES   => ['🗄️', 'Database tables'],
        G_COLUMNS  => ['🧩', 'Columns'],
        G_SETTINGS => ['⚙️', 'Delivery settings'],
        G_CLEANUP  => ['🧹', 'Cleanup'],
    ];
    ?>
    <?php foreach ($groupMeta as $key => $meta): ?>
        <?php
        $rows = [];
        foreach ($results as $r) { if ($r['group'] === $key) $rows[] = $r; }
        if (!$rows) continue;
        ?>
        <section class="grp">
            <header>
                <span class="ico"><?= $meta[0] ?></span>
                <h2><?= $meta[1] ?></h2>
                <span class="count"><?= count($rows) ?> step<?= count($rows) === 1 ? '' : 's' ?></span>
            </header>
            <?php foreach ($rows as $r): ?>
                <div class="step <?= $r['status'] === 'err' ? 'err' : '' ?>">
                    <span class="ico"><?= $actionIcon[$r['action']] ?? '✅' ?></span>
                    <span><?= htmlspecialchars($r['msg']) ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($failed === 0): ?>
    <div class="verdict good">
        <span class="ico">🎉</span>
        <div>
            <h3>Migration complete</h3>
            <p>All <?= count($results) ?> steps finished with no errors. Nothing you had already saved was overwritten.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="verdict bad">
        <span class="ico">⚠️</span>
        <div>
            <h3>Finished with <?= (int) $failed ?> error<?= $failed === 1 ? '' : 's' ?></h3>
            <p>The highlighted rows above say what went wrong. Re-running this page is still safe.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="actions">
        <a class="btn btn-primary" href="migrate_delivery.php">🔄 Run again</a>
        <a class="btn btn-ghost" href="../admin/settings.php">⬅ Back to Settings</a>
        <a class="btn btn-ghost" href="../admin/backup.php">📥 Download Backup</a>
        <a class="btn btn-ghost" href="../checkout.php">🛒 Test checkout</a>
    </div>

    <p class="note">
        This page creates missing tables and fills in defaults — it is <strong>not</strong> a backup.
        Save your data with <a href="../admin/backup.php">Download Backup</a> or phpMyAdmin → Export.
    </p>

</div>
</body>
</html>

