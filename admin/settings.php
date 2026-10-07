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

            // Zones — only write rows the form actually posted. A short form
            // (fewer rendered rows) must never silently zero out the zones it
            // didn't send, and labels are carried in a hidden input so custom
            // labels survive a save.
            $zones = [];
            $zoneNames   = ['zone_1','zone_2','zone_3'];
            $zoneLabels  = ['Zone 1 (0-3km)', 'Zone 2 (3-6km)', 'Zone 3 (6-8km)'];
            foreach ($zoneNames as $i => $zn) {
                if (!isset($_POST['zone_max_km'][$i])) {
                    continue; // row was not rendered — leave it alone below
                }
                $postedLabel = trim((string) ($_POST['zone_label'][$i] ?? ''));
                $zones[] = [
                    'name'      => $zn,
                    'label'     => $postedLabel !== '' ? $postedLabel : $zoneLabels[$i],
                    'max_km'    => max(0, (float) ($_POST['zone_max_km'][$i]    ?? 0)),
                    'fee'       => max(0, (int)   ($_POST['zone_fee'][$i]       ?? 0)),
                    'min_order' => max(0, (int)   ($_POST['zone_min_order'][$i] ?? 0)),
                ];
            }
            // Never overwrite stored zones with an empty/partial payload.
            if ($zones !== []) {
                upsertSetting($pdo, 'delivery_zones', json_encode($zones));
            }

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

// Always expose exactly 3 zone rows. A stored list shorter than 3 (or an invalid
// one) used to render fewer rows, and saving that short form zeroed out the
// zones it never rendered — defaults fill the gaps, stored values win.
$zoneDefaults = [
    ['name'=>'zone_1','label'=>'Zone 1 (0-3km)','max_km'=>3, 'fee'=>50,'min_order'=>0],
    ['name'=>'zone_2','label'=>'Zone 2 (3-6km)','max_km'=>6, 'fee'=>50,'min_order'=>0],
    ['name'=>'zone_3','label'=>'Zone 3 (6-8km)','max_km'=>8, 'fee'=>50,'min_order'=>0],
];
$decodedZones = json_decode($zonesJson, true);
$decodedZones = is_array($decodedZones) ? $decodedZones : [];
$zones = [];
foreach ($zoneDefaults as $i => $defaults) {
    $saved = isset($decodedZones[$i]) && is_array($decodedZones[$i]) ? $decodedZones[$i] : [];
    $zones[] = array_merge($defaults, $saved);
}
// No hardcoded fallback: if the list is legitimately empty the textarea must stay
// empty. A fallback list used to reappear here after clearing and saving, which
// quietly wrote the stale areas back on the next save.
$areas = json_decode($areasJson, true);
$areas = is_array($areas) ? array_values($areas) : [];

require __DIR__ . '/../includes/admin_header.php';
?>

<style>
    /* ── Tips: content lives in the modal below, not on the page ── */
    .tip {
        display: flex; gap: .7rem; align-items: flex-start;
        background: #fdf7f0; border: 1px solid #f2e3d3; border-radius: 10px;
        padding: .85rem 1.05rem; margin: 0 0 .85rem;
        font-size: .845rem; line-height: 1.62; color: #6b5744;
    }
    .tip:last-child { margin-bottom: 0; }
    .tip > i { color: var(--accent,#b85c00); margin-top: .2rem; flex: 0 0 auto; }
    .tip strong { color: #3d1f0d; }
    .tip em { font-style: normal; font-weight: 600; color: #7a4500; }
    .tip ul, .tip ol { margin: .4rem 0 0; padding-left: 1.2rem; }
    .tip li { margin: .2rem 0; }
    .tip code { background: #f3ece3; padding: .08em .4em; border-radius: 4px; font-size: .95em; }
    .tip .tip-foot {
        display: block; margin-top: .55rem; padding-top: .5rem;
        border-top: 1px dashed #eadbcb; font-size: .79rem; color: #8a7a68;
    }

    .mode-badge {
        display: inline-block; margin-left: .55rem; padding: .12rem .6rem;
        border-radius: 999px; font-size: .7rem; font-weight: 800;
        letter-spacing: .04em; vertical-align: middle;
    }
    .mode-badge.is-full   { background: #e8f7ef; color: #1a6645; border: 1px solid #a3d9bc; }
    .mode-badge.is-simple { background: #fff3e0; color: #7a4500; border: 1px solid #f5c842; }

    .section-tips-link {
        float: right; font-size: .8rem; font-weight: 600;
        color: var(--accent,#b85c00); text-decoration: none;
        margin-left: 1rem; padding-top: .1rem;
    }
    .section-tips-link:hover { text-decoration: underline; }

    /* ── Floating "Tips" button ── */
    .tips-fab {
        position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 940;
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .72rem 1.2rem; border: none; border-radius: 999px;
        background: var(--accent,#b85c00); color: #fff;
        font-size: .92rem; font-weight: 700; cursor: pointer;
        box-shadow: 0 8px 22px rgba(90,50,0,.32);
    }
    .tips-fab:hover { filter: brightness(1.07); }
    .tips-fab:focus-visible { outline: 3px solid rgba(184,92,0,.45); outline-offset: 2px; }

    /* ── Tips modal ── */
    .tips-backdrop {
        position: fixed; inset: 0; z-index: 950;
        background: rgba(45,25,6,.5);
    }
    .tips-modal {
        position: fixed; z-index: 960; left: 50%; top: 50%;
        transform: translate(-50%, -50%);
        width: min(620px, calc(100vw - 2rem));
        max-height: min(82vh, 720px);
        display: flex; flex-direction: column;
        background: var(--panel-bg,#fff);
        border: 1px solid var(--border-color,#ede8e0);
        border-radius: 16px;
        box-shadow: 0 24px 60px rgba(40,20,5,.35);
        overflow: hidden;
    }
    .tips-backdrop[hidden], .tips-modal[hidden] { display: none; }
    .tips-head {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        padding: .95rem 1.2rem;
        background: var(--panel-header-bg,#faf8f5);
        border-bottom: 1px solid var(--border-color,#ede8e0);
    }
    .tips-head h3 { margin: 0; font-size: 1rem; color: var(--text-primary,#3d1f0d); }
    .tips-head h3 i { color: var(--accent,#b85c00); margin-right: .45rem; }
    .tips-head .tips-sub {
        display: block; margin-top: .15rem;
        font-size: .76rem; font-weight: 400; color: var(--text-muted,#888);
    }
    .tips-close {
        border: none; background: transparent; cursor: pointer;
        color: var(--text-muted,#888); font-size: 1.2rem;
        padding: .25rem .45rem; border-radius: 8px; line-height: 1;
    }
    .tips-close:hover { background: #f0ebe4; color: var(--text-primary,#3d1f0d); }
    .tips-body { padding: 1.1rem; overflow-y: auto; }

    @media (max-width: 560px) {
        .tips-modal { width: calc(100vw - 1rem); max-height: 88vh; }
        .tips-fab { right: 1rem; bottom: 1rem; padding: .62rem 1rem; }
    }
</style>

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
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-truck" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Delivery Mode<span class="mode-badge <?= $mode === 'full' ? 'is-full' : 'is-simple' ?>">Currently: <?= $mode === 'full' ? 'Full' : 'Simple' ?></span><a href="#" data-tips-open class="section-tips-link">Tips <i class="fa-regular fa-circle-question"></i></a></h3>
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
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-layer-group" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Delivery Zones</h3>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.88rem;color:var(--text-muted,#888);margin:0 0 1.2rem;">Used for the delivery fee and minimum order. Zones are checked in order; the first matching zone is applied. <a href="#" data-tips-open style="color:var(--accent,#b85c00);text-decoration:none;font-weight:600;">How do these work? <i class="fa-regular fa-circle-question"></i></a></p>
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
                            <td style="padding:.7rem .8rem;font-weight:600;color:var(--text-primary,#3d1f0d);">
                                <input type="hidden" name="zone_label[]" value="<?= htmlspecialchars($zone['label'] ?? 'Zone ' . ($i+1)) ?>" />
                                <?= htmlspecialchars($zone['label'] ?? 'Zone ' . ($i+1)) ?>
                            </td>
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
                                <i class="fa-solid fa-ban" style="margin-right:.4rem;color:#c00;"></i> Beyond <?= htmlspecialchars($zones[count($zones) - 1]['label'] ?? 'the last zone') ?> max km → Order blocked automatically.
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
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-list-check" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>In-Range Areas (Simple Mode)<a href="#" data-tips-open class="section-tips-link">Tips <i class="fa-regular fa-circle-question"></i></a></h3>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.88rem;color:var(--text-muted,#888);margin:0 0 1rem;">
                Enter barangay or city names separated by commas. The check is <strong>case-insensitive partial match</strong>.<br>
                Example: <code style="background:#f3f0eb;padding:.1em .4em;border-radius:4px;">guiguinto, ilang-ilang, malolos, plaridel</code>
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
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-map-pin" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Branch & Advanced Settings<a href="#" data-tips-open class="section-tips-link">Tips <i class="fa-regular fa-circle-question"></i></a></h3>
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
            </div>
        </div>
    </section>

    <!-- ── Database Backup ── -->
    <section class="panel" style="margin-bottom:1.5rem;" id="backup">
        <div class="panel-header" style="padding:1.2rem 1.5rem;border-bottom:1px solid var(--border-color,#ede8e0);background:var(--panel-header-bg,#faf8f5);border-radius:12px 12px 0 0;">
            <h3 style="margin:0;font-size:1rem;color:var(--text-primary,#3d1f0d);"><i class="fa-solid fa-download" style="margin-right:.5rem;color:var(--accent,#b85c00);"></i>Database Backup<a href="#" data-tips-open class="section-tips-link">Tips <i class="fa-regular fa-circle-question"></i></a></h3>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.88rem;color:var(--text-muted,#888);margin:0 0 1.1rem;">
                Downloads a full <strong>.sql</strong> copy of the database — every table's structure and every row —
                ready to reload later with phpMyAdmin → Import. Take one before making a big change.
            </p>
            <div style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:center;">
                <a href="<?= BASE_URL ?>/admin/backup.php" class="btn btn-primary" style="padding:.75rem 1.5rem;text-decoration:none;">
                    <i class="fa-solid fa-download"></i>Download Backup Database
                </a>
                <span style="font-size:.8rem;color:var(--text-muted,#888);">Admin only · built when you click · nothing is left on the server</span>
            </div>
            <p style="margin:1.1rem 0 0;padding-top:.9rem;border-top:1px solid var(--border-color,#ede8e0);font-size:.8rem;color:var(--text-muted,#888);">
                <i class="fa-solid fa-circle-info" style="color:var(--accent,#b85c00);margin-right:.4rem;"></i>
                This is the real backup. <strong>Re-run migration</strong> only creates missing tables and fills in
                defaults — it never saves your data.
            </p>
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

<!-- ══════════════════════════════════════════════════════════════════════════
     Tips — hidden until the floating button (or any [data-tips-open] link) is
     pressed. Nothing from below appears inline on the page itself.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tips-backdrop" id="tipsBackdrop" hidden></div>

<div class="tips-modal" id="tipsModal" role="dialog" aria-modal="true" aria-labelledby="tipsTitle" hidden>
    <div class="tips-head">
        <h3 id="tipsTitle"><i class="fa-solid fa-lightbulb"></i>Delivery Settings — Tips</h3>
        <button type="button" class="tips-close" data-tips-close aria-label="Close tips"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="tips-body">

        <section class="tip">
            <i class="fa-solid fa-lightbulb"></i>
            <div>
                <strong>How a delivery address gets checked</strong>
                <ol>
                    <li><strong>Where is it?</strong> The customer's address text — or the pin they drop on the map — is compared against the branch coordinates on this page.</li>
                    <li><strong>Is it allowed?</strong> <em>Delivery Mode</em> decides <em>how</em> that is answered: Simple Mode matches the In-Range Areas list, Full Mode measures the real distance.</li>
                    <li><strong>How much?</strong> Once the address is allowed, <em>Delivery Zones</em> supply the delivery fee, minimum order and drive-time estimate shown at checkout.</li>
                </ol>
                <span class="tip-foot">Everything on this page is read live from the database — press <strong>Save Settings</strong> and it applies on the next page load. No restart and no cache clearing needed.</span>
            </div>
        </section>

        <section class="tip">
            <i class="fa-solid fa-circle-question"></i>
            <div>
                <strong>Which mode should I use?</strong>
                <ul>
                    <li><strong>Simple Mode</strong> — instant and fully offline, but it never measures distance. If a name appears in <em>In-Range Areas</em> the order goes through no matter how far it actually is.</li>
                    <li><strong>Full Mode</strong> — looks up where the address really is and enforces the 8km limit properly. Uses free public services (Nominatim + OSRM), so there is <strong>no API key and no billing</strong>.</li>
                </ul>
                <span class="tip-foot">In Full Mode an address saved with a <strong>map pin</strong> is measured straight-line — the same number the customer saw on their map — while an address typed as free text is measured by <strong>driving distance</strong>. That is why a pin can accept a place the plain text lookup cannot resolve.</span>
            </div>
        </section>

        <section class="tip">
            <i class="fa-solid fa-layer-group"></i>
            <div>
                <strong>Delivery Zones — what each column means</strong>
                <ul>
                    <li><strong>Max Driving km</strong> — how far the customer is allowed to be from the branch. Anything past the last zone's limit is rejected automatically.</li>
                    <li><strong>Delivery Fee</strong> — the amount added to the order total at checkout.</li>
                    <li><strong>Min Order</strong> — the subtotal the cart must reach for that zone. Below it the customer is asked to add more before ordering.</li>
                </ul>
                <span class="tip-foot">Rows are checked top to bottom. Full Mode picks the zone from the measured distance, so a 4.2km address picks up Zone 2's settings. Simple Mode checking plain text has no distance to measure and therefore charges <strong>Zone 1</strong>'s fee — but a customer who drops a map pin is still matched by distance, in either mode.</span>
            </div>
        </section>

        <section class="tip">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>In-Range Areas — read before editing</strong>
                <ul>
                    <li>The list is used <strong>only in Simple Mode</strong>. Full Mode ignores it entirely and measures distance instead.</li>
                    <li>Matching is a <strong>partial, case-insensitive</strong> substring test — <code>malolos</code> matches "Malolos", "Malolos City" and "Barangay Malolos" alike.</li>
                    <li>One town name opens that town up <strong>completely</strong>, including its far corners. Prefer barangay names, and never add a whole province such as <code>bulacan</code>.</li>
                    <li>Keep every entry inside the last zone's <strong><?= htmlspecialchars($zones[count($zones) - 1]['max_km']) ?>km</strong> limit, so Simple Mode never accepts an address that Full Mode would turn away.</li>
                </ul>
            </div>
        </section>

        <section class="tip">
            <i class="fa-solid fa-location-crosshairs"></i>
            <div>
                <strong>Branch coordinates are the centre of everything</strong>
                <ul>
                    <li>They pin the branch on the map. Every distance — the 8km circle, the zone a customer lands in and the fee they pay — is measured from this exact point.</li>
                    <li>If the shop ever moves, update both. Everything re-measures from the new point automatically; no saved address needs re-entering.</li>
                </ul>
                <span class="tip-foot">To find them: search the address in Google Maps, right-click the exact spot, and the first number is latitude, the second is longitude.</span>
            </div>
        </section>

        <section class="tip">
            <i class="fa-solid fa-sliders"></i>
            <div>
                <strong>Field reference</strong>
                <ul>
                    <li><strong>Branch Name</strong> — name for this branch, saved with the rest of the settings.</li>
                    <li><strong>Branch Latitude</strong> — north–south position; positive values are north of the equator.</li>
                    <li><strong>Branch Longitude</strong> — east–west position; positive values are east of the prime meridian.</li>
                    <li><strong>Drive Time Warning (min)</strong> — Full Mode shows a soft ⚠️ note at checkout past this drive time. It only warns the customer; it never blocks the order.</li>
                    <li><strong>Re-run migration</strong> — safe to run any time. It creates whatever table or column is missing and fills in settings you have never touched.</li>
                </ul>
            </div>
        </section>

        <section class="tip">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Saving and backups</strong>
                <span class="tip-foot" style="margin-top:0;padding-top:0;border-top:none;">
                    <strong>Save Settings</strong> writes straight to the database, so a file taken before a big change is the only way back. Press <strong>Download Backup Database</strong> (below) to get a <strong>.sql</strong> copy of every table — reload it later with phpMyAdmin → Import, or use phpMyAdmin → <em>breadbreak_db</em> → Export. Re-running the migration is <strong>not</strong> a backup: it creates whatever table or column is missing and fills in settings you have never touched, and it does <strong>not</strong> overwrite anything saved on this page.
                </span>
            </div>
        </section>

    </div>
</div>

<button type="button" class="tips-fab" id="tipsOpen" aria-haspopup="dialog" aria-controls="tipsModal" aria-expanded="false">
    <i class="fa-solid fa-lightbulb"></i>Tips
</button>

<script>
(function () {
    var modal    = document.getElementById('tipsModal');
    var backdrop = document.getElementById('tipsBackdrop');
    var openBtn  = document.getElementById('tipsOpen');
    if (!modal || !backdrop || !openBtn) return;

    function show() {
        modal.hidden = false;
        backdrop.hidden = false;
        openBtn.setAttribute('aria-expanded', 'true');
        var close = modal.querySelector('[data-tips-close]');
        if (close) close.focus();
    }
    function hide() {
        modal.hidden = true;
        backdrop.hidden = true;
        openBtn.setAttribute('aria-expanded', 'false');
        openBtn.focus();
    }

    openBtn.addEventListener('click', show);
    backdrop.addEventListener('click', hide);

    // Close button (the ✕ and any future one)
    modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-tips-close]')) { e.preventDefault(); hide(); }
    });

    // Inline "How do these work?" links inside the form
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-tips-open]');
        if (trigger) { e.preventDefault(); show(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) hide();
    });
})();
</script>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
