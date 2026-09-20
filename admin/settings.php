<?php
// =============================================================================
// admin/settings.php  –  BreadBreak Delivery Settings
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

$pageTitle  = 'Settings';
$activePage = 'settings';

$pdo = getDatabaseConnection();
$saveSuccess = '';
$saveError   = '';

// ── Helper: get / upsert setting ─────────────────────────────────────────────
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $s = $pdo->prepare('SELECT setting_value FROM delivery_settings WHERE setting_key = :k LIMIT 1');
    $s->execute(['k' => $key]);
    return (string) ($s->fetchColumn() ?: $default);
}

function upsertSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        "INSERT INTO delivery_settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    )->execute(['k' => $key, 'v' => $value]);
}

// ── Check if delivery_settings table exists ───────────────────────────────────
$tableExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'delivery_settings'"
)->fetchColumn();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_delivery_settings') {
        try {
            // Mode
            $mode = in_array($_POST['delivery_mode'] ?? '', ['simple','full'], true)
                  ? $_POST['delivery_mode'] : 'simple';
            upsertSetting($pdo, 'delivery_mode', $mode);

            // Zones
            $zones = [];
            $zoneNames   = ['zone_1','zone_2','zone_3'];
            $zoneLabels  = ['Zone 1','Zone 2','Zone 3'];
            foreach ($zoneNames as $i => $zn) {
                $zones[] = [
                    'name'      => $zn,
                    'label'     => $zoneLabels[$i],
                    'max_km'    => max(0, (float) ($_POST['zone_max_km'][$i]    ?? 0)),
                    'fee'       => max(0, (int)   ($_POST['zone_fee'][$i]       ?? 0)),
                    'min_order' => max(0, (int)   ($_POST['zone_min_order'][$i] ?? 0)),
                ];
            }
            upsertSetting($pdo, 'delivery_zones', json_encode($zones));

            // In-range areas (comma-separated → JSON array)
            $areasRaw = trim($_POST['in_range_areas'] ?? '');
            $areas    = array_values(array_filter(
                array_map('trim', explode(',', $areasRaw)),
                fn($a) => $a !== ''
            ));
            upsertSetting($pdo, 'in_range_areas', json_encode(array_map('strtolower', $areas)));

            // Branch coords
            upsertSetting($pdo, 'branch_lat',  trim($_POST['branch_lat']  ?? '14.8310'));
            upsertSetting($pdo, 'branch_lng',  trim($_POST['branch_lng']  ?? '120.8720'));
            upsertSetting($pdo, 'branch_name', trim($_POST['branch_name'] ?? 'Estrella Village Branch'));
            upsertSetting($pdo, 'max_drive_time_min', (string) max(1, (int) ($_POST['max_drive_time_min'] ?? 25)));

            // Geoapify key (optional, for future full mode via Geoapify)
            $geoKey = trim($_POST['geoapify_api_key'] ?? '');
            upsertSetting($pdo, 'geoapify_api_key', $geoKey);

            $saveSuccess = 'Delivery settings saved successfully.';
        } catch (Throwable $e) {
            $saveError = 'Save failed: ' . $e->getMessage();
        }
    }
}

// ── Load current values ───────────────────────────────────────────────────────
$mode         = $tableExists ? getSetting($pdo, 'delivery_mode',      'simple') : 'simple';
$zonesJson    = $tableExists ? getSetting($pdo, 'delivery_zones',     '[]')     : '[]';
$areasJson    = $tableExists ? getSetting($pdo, 'in_range_areas',     '[]')     : '[]';
$branchLat    = $tableExists ? getSetting($pdo, 'branch_lat',         '14.8310')  : '14.8310';
$branchLng    = $tableExists ? getSetting($pdo, 'branch_lng',         '120.8720') : '120.8720';
$branchName   = $tableExists ? getSetting($pdo, 'branch_name',        'Estrella Village Branch') : 'Estrella Village Branch';
$maxDriveTime = $tableExists ? getSetting($pdo, 'max_drive_time_min', '25')     : '25';
$geoapifyKey  = $tableExists ? getSetting($pdo, 'geoapify_api_key',   '')       : '';

$zones = json_decode($zonesJson, true) ?: [
    ['name'=>'zone_1','label'=>'Zone 1','max_km'=>3,  'fee'=>0,  'min_order'=>0],
    ['name'=>'zone_2','label'=>'Zone 2','max_km'=>6,  'fee'=>50, 'min_order'=>0],
    ['name'=>'zone_3','label'=>'Zone 3','max_km'=>8,  'fee'=>80, 'min_order'=>300],
];
$areas = json_decode($areasJson, true) ?: ['guiguinto','ilang-ilang','ligas','malolos','meycauayan','san jose del monte','plaridel','hagonoy'];

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro">
    <h2>Settings</h2>
    <p>Manage BreadBreak system configuration.</p>
</section>

<?php if (!$tableExists): ?>
<div class="panel" style="border-left:4px solid #f5a623;background:#fff8e6;padding:1.2rem 1.5rem;border-radius:12px;margin-bottom:1.5rem;">
    <strong>⚠️ Delivery settings table not found.</strong>
    <p style="margin:.5rem 0 0;">Please run the <a href="<?= BASE_URL ?>/database/migrate_delivery.php" style="color:var(--accent);">delivery migration</a> first to set up the required database tables.</p>
</div>
<?php endif; ?>

<?php if ($saveSuccess): ?>
<div class="panel" style="border-left:4px solid #1a6645;background:#e8f7ef;padding:1rem 1.5rem;border-radius:12px;margin-bottom:1.2rem;color:#1a6645;font-weight:600;">
    ✅ <?= htmlspecialchars($saveSuccess) ?>
</div>
<?php elseif ($saveError): ?>
<div class="panel" style="border-left:4px solid #891515;background:#fde8e8;padding:1rem 1.5rem;border-radius:12px;margin-bottom:1.2rem;color:#891515;font-weight:600;">
    ❌ <?= htmlspecialchars($saveError) ?>
</div>
<?php endif; ?>

<form method="POST" id="delivery-settings-form">
    <input type="hidden" name="action" value="save_delivery_settings" />

    <!-- ── Delivery Mode ── -->
    <section class="panel" style="margin-bottom:1.5rem;">
        <div class="panel-header" style="padding:1.2rem 1.5rem;border-bottom:1px solid var(--border-color, #ede8e0);background:var(--panel-header-bg, #faf8f5);border-radius:12px 12px 0 0;">
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-truck" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Delivery Mode</h3>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.88rem;color:var(--text-muted,#888);margin:0 0 1.2rem;">
                <strong>Simple mode</strong> uses keyword matching (no API calls) — ideal for demo/testing.<br>
                <strong>Full mode</strong> uses OpenStreetMap geocoding + OSRM driving distance (free, no credit card required).
            </p>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                <label class="settings-radio-card <?= $mode === 'simple' ? 'is-selected' : '' ?>" style="flex:1;min-width:200px;border:2px solid <?= $mode === 'simple' ? 'var(--accent,#b85c00)' : 'var(--border,#ede8e0)' ?>;border-radius:12px;padding:1.1rem 1.3rem;cursor:pointer;display:flex;gap:.75rem;align-items:flex-start;">
                    <input type="radio" name="delivery_mode" value="simple" <?= $mode === 'simple' ? 'checked' : '' ?> style="margin-top:.2rem;" />
                    <div>
                        <strong style="display:block;margin-bottom:.2rem;">🔤 Simple Mode</strong>
                        <small style="color:var(--text-muted,#888);">Keyword match against allowed barangays/cities list. Fast, no external APIs.</small>
                    </div>
                </label>
                <label class="settings-radio-card <?= $mode === 'full' ? 'is-selected' : '' ?>" style="flex:1;min-width:200px;border:2px solid <?= $mode === 'full' ? 'var(--accent,#b85c00)' : 'var(--border,#ede8e0)' ?>;border-radius:12px;padding:1.1rem 1.3rem;cursor:pointer;display:flex;gap:.75rem;align-items:flex-start;">
                    <input type="radio" name="delivery_mode" value="full" <?= $mode === 'full' ? 'checked' : '' ?> style="margin-top:.2rem;" />
                    <div>
                        <strong style="display:block;margin-bottom:.2rem;">📍 Full Mode</strong>
                        <small style="color:var(--text-muted,#888);">Real geocoding + actual driving distance via free public APIs (Nominatim + OSRM).</small>
                    </div>
                </label>
            </div>
        </div>
    </section>

    <!-- ── Zone Table ── -->
    <section class="panel" style="margin-bottom:1.5rem;">
        <div class="panel-header" style="padding:1.2rem 1.5rem;border-bottom:1px solid var(--border-color,#ede8e0);background:var(--panel-header-bg,#faf8f5);border-radius:12px 12px 0 0;">
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-layer-group" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Delivery Zones (Full Mode)</h3>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.88rem;color:var(--text-muted,#888);margin:0 0 1.2rem;">Used when Full Mode is active. Zones are checked in order; the first matching zone is applied.</p>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border,#ede8e0);">
                            <th style="text-align:left;padding:.5rem .8rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#888);">Zone</th>
                            <th style="text-align:left;padding:.5rem .8rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#888);">Max Driving km</th>
                            <th style="text-align:left;padding:.5rem .8rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#888);">Delivery Fee (₱)</th>
                            <th style="text-align:left;padding:.5rem .8rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#888);">Min Order (₱)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($zones as $i => $zone): ?>
                        <tr style="border-bottom:1px solid var(--border,#ede8e0);">
                            <td style="padding:.7rem .8rem;font-weight:600;color:var(--text-primary,#3d1f0d);"><?= htmlspecialchars($zone['label'] ?? 'Zone ' . ($i+1)) ?></td>
                            <td style="padding:.7rem .8rem;">
                                <input type="number" name="zone_max_km[]" value="<?= htmlspecialchars($zone['max_km']) ?>" min="0" max="100" step="0.5"
                                    style="width:90px;border:1px solid var(--border,#ede8e0);border-radius:8px;padding:.4rem .7rem;font-size:.9rem;" />
                            </td>
                            <td style="padding:.7rem .8rem;">
                                <input type="number" name="zone_fee[]" value="<?= htmlspecialchars($zone['fee']) ?>" min="0" max="9999" step="1"
                                    style="width:90px;border:1px solid var(--border,#ede8e0);border-radius:8px;padding:.4rem .7rem;font-size:.9rem;" />
                            </td>
                            <td style="padding:.7rem .8rem;">
                                <input type="number" name="zone_min_order[]" value="<?= htmlspecialchars($zone['min_order']) ?>" min="0" max="99999" step="1"
                                    style="width:90px;border:1px solid var(--border,#ede8e0);border-radius:8px;padding:.4rem .7rem;font-size:.9rem;" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <tr style="background:#faf8f5;">
                            <td colspan="4" style="padding:.7rem .8rem;font-size:.82rem;color:var(--text-muted,#888);">
                                <i class="fa-solid fa-ban" style="margin-right:.4rem;color:#c00;"></i> Beyond Zone 3 max km → Order blocked automatically.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ── In-Range Areas (Simple Mode) ── -->
    <section class="panel" style="margin-bottom:1.5rem;">
        <div class="panel-header" style="padding:1.2rem 1.5rem;border-bottom:1px solid var(--border-color,#ede8e0);background:var(--panel-header-bg,#faf8f5);border-radius:12px 12px 0 0;">
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-list-check" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>In-Range Areas (Simple Mode)</h3>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.88rem;color:var(--text-muted,#888);margin:0 0 1rem;">
                Enter barangay or city names separated by commas. The check is <strong>case-insensitive partial match</strong>.<br>
                Example: <code style="background:#f3f0eb;padding:.1em .4em;border-radius:4px;">guiguinto, malolos, meycauayan</code>
            </p>
            <textarea name="in_range_areas" rows="4"
                style="width:100%;border:1.5px solid var(--border,#ede8e0);border-radius:10px;padding:.75rem 1rem;font-family:inherit;font-size:.92rem;box-sizing:border-box;"
                placeholder="guiguinto, ilang-ilang, malolos, meycauayan, plaridel"
            ><?= htmlspecialchars(implode(', ', $areas)) ?></textarea>
            <small style="color:var(--text-muted,#888);font-size:.8rem;">Comma-separated list. Lowercase recommended. Partial match is used (e.g. "guiguinto" matches "Guiguinto, Bulacan").</small>
        </div>
    </section>

    <!-- ── Branch & Advanced ── -->
    <section class="panel" style="margin-bottom:1.5rem;">
        <div class="panel-header" style="padding:1.2rem 1.5rem;border-bottom:1px solid var(--border-color,#ede8e0);background:var(--panel-header-bg,#faf8f5);border-radius:12px 12px 0 0;">
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-map-pin" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Branch & Advanced Settings</h3>
        </div>
        <div style="padding:1.5rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;">
                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;color:var(--text-primary,#3d1f0d);">
                    Branch Name
                    <input type="text" name="branch_name" value="<?= htmlspecialchars($branchName) ?>"
                        style="border:1.5px solid var(--border,#ede8e0);border-radius:8px;padding:.5rem .8rem;font-size:.9rem;font-weight:400;" />
                </label>
                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;color:var(--text-primary,#3d1f0d);">
                    Branch Latitude
                    <input type="number" name="branch_lat" value="<?= htmlspecialchars($branchLat) ?>" step="0.0001"
                        style="border:1.5px solid var(--border,#ede8e0);border-radius:8px;padding:.5rem .8rem;font-size:.9rem;font-weight:400;" />
                </label>
                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;color:var(--text-primary,#3d1f0d);">
                    Branch Longitude
                    <input type="number" name="branch_lng" value="<?= htmlspecialchars($branchLng) ?>" step="0.0001"
                        style="border:1.5px solid var(--border,#ede8e0);border-radius:8px;padding:.5rem .8rem;font-size:.9rem;font-weight:400;" />
                </label>
                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;color:var(--text-primary,#3d1f0d);">
                    Drive Time Warning (min)
                    <input type="number" name="max_drive_time_min" value="<?= htmlspecialchars($maxDriveTime) ?>" min="1" max="120"
                        style="border:1.5px solid var(--border,#ede8e0);border-radius:8px;padding:.5rem .8rem;font-size:.9rem;font-weight:400;" />
                </label>
                <label style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;color:var(--text-primary,#3d1f0d);grid-column:1/-1;">
                    Geoapify API Key <span style="font-weight:400;color:var(--text-muted,#888);">(optional — for Full Mode Geoapify routing)</span>
                    <input type="text" name="geoapify_api_key" value="<?= htmlspecialchars($geoapifyKey) ?>"
                        placeholder="Leave blank to use free OSRM (no key needed)"
                        style="border:1.5px solid var(--border,#ede8e0);border-radius:8px;padding:.5rem .8rem;font-size:.9rem;font-weight:400;" />
                </label>
            </div>
        </div>
    </section>

    <div style="display:flex;gap:1rem;align-items:center;padding-bottom:2rem;">
        <button type="submit" class="btn btn-primary" style="padding:.75rem 2rem;font-size:.95rem;">
            <i class="fa-solid fa-floppy-disk" style="margin-right:.5rem;"></i>Save Settings
        </button>
        <a href="<?= BASE_URL ?>/database/migrate_delivery.php" target="_blank"
            style="font-size:.85rem;color:var(--accent,#b85c00);text-decoration:none;">
            <i class="fa-solid fa-database" style="margin-right:.3rem;"></i>Re-run migration
        </a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
