<?php
// =============================================================================
// admin/vouchers.php  –  BreadBreak Voucher Manager
// Create promo vouchers, grant them to customers, and track redemptions.
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../includes/voucher_promotion.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/vouchers.php';

$pageTitle  = 'Vouchers';
$activePage = 'vouchers';

$pdo = getDatabaseConnection();
$saveSuccess = '';
$saveError   = '';

// datetime-local inputs give "YYYY-MM-DDTHH:MM" — normalize to MySQL DATETIME.
function voucherNormalizeDatetime(string $raw): ?string
{
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw) ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $raw);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

try {
    ensureVoucherTables($pdo);
    $tablesReady = true;
} catch (Throwable $e) {
    $tablesReady = false;
    $saveError = 'Voucher tables are not ready: ' . $e->getMessage();
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesReady) {
    $action = $_POST['action'] ?? '';

    // ── Create voucher ──
    if ($action === 'create_voucher') {
        $code        = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $title       = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $minSpend    = max(0, (float) ($_POST['min_spend'] ?? 0));
        $perUser     = max(1, (int) ($_POST['usage_limit_per_user'] ?? 1));
        $totalLimit  = trim((string) ($_POST['usage_limit_total'] ?? ''));
        $totalLimit  = $totalLimit === '' ? null : max(1, (int) $totalLimit);
        $validFrom   = trim((string) ($_POST['valid_from'] ?? ''));
        $validUntil  = trim((string) ($_POST['valid_until'] ?? ''));
        $isPublic    = !empty($_POST['is_public']) ? 1 : 0;

        if ($code === '' || !preg_match('/^[A-Z0-9][A-Z0-9\-_]{1,48}[A-Z0-9]$/', $code)) {
            $saveError = 'Code must be 3–50 characters: letters, numbers, dashes, underscores (e.g. FREEDEL50).';
        } elseif ($title === '') {
            $saveError = 'Please give the voucher a title (shown to customers).';
        }

        // ── Schedule validation (server-side — the picker limits can be bypassed) ──
        $validFromDb  = null;
        $validUntilDb = null;
        if (!$saveError) {
            $validFromDb  = $validFrom !== '' ? voucherNormalizeDatetime($validFrom) : null;
            $validUntilDb = $validUntil !== '' ? voucherNormalizeDatetime($validUntil) : null;

            $nowTs      = time();
            $fromTs     = $validFromDb ? strtotime($validFromDb) : null;
            $untilTs    = $validUntilDb ? strtotime($validUntilDb) : null;
            $maxAheadTs = strtotime('+1 year', $nowTs);

            if ($validFrom !== '' && $validFromDb === null) {
                $saveError = 'The "Valid from" date is not a valid date.';
            } elseif ($validUntil !== '' && $validUntilDb === null) {
                $saveError = 'The "Valid until" date is not a valid date.';
            } elseif ($fromTs !== null && $fromTs < $nowTs - 300) {
                // 5-minute grace so "right now" picked a minute ago still passes.
                $saveError = 'Valid from cannot be in the past — pick today or a future date.';
            } elseif ($fromTs !== null && $fromTs > $maxAheadTs) {
                $saveError = 'Valid from cannot be more than 1 year ahead.';
            } elseif ($untilTs !== null && $untilTs <= ($fromTs ?? $nowTs)) {
                $saveError = 'Valid until must be later than the start date.';
            } elseif ($untilTs !== null && $untilTs > strtotime('+1 year', $fromTs ?? $nowTs)) {
                $saveError = 'Vouchers can run for up to 1 year only. Pick a closer end date.';
            }
        }

        if (!$saveError) {
            try {
                $dup = $pdo->prepare('SELECT id FROM vouchers WHERE code = :c LIMIT 1');
                $dup->execute(['c' => $code]);
                if ($dup->fetch()) {
                    $saveError = "The code {$code} already exists. Pick a different one.";
                } else {
                    $pdo->prepare(
                        "INSERT INTO vouchers
                            (code, title, description, discount_type, min_spend,
                             usage_limit_total, usage_limit_per_user,
                             valid_from, valid_until, is_public, status)
                         VALUES
                            (:code, :title, :descr, 'free_delivery', :minspend,
                             :totallimit, :peruser,
                             :vfrom, :vuntil, :public, 'active')"
                    )->execute([
                        'code'       => $code,
                        'title'      => $title,
                        'descr'      => $description !== '' ? $description : null,
                        'minspend'   => $minSpend,
                        'totallimit' => $totalLimit,
                        'peruser'    => $perUser,
                        'vfrom'      => $validFromDb,
                        'vuntil'     => $validUntilDb,
                        'public'     => $isPublic,
                    ]);
                    $saveSuccess = "Voucher {$code} created."
                        . ($isPublic ? ' Customers can now type it at checkout.' : ' Grant it to customers below.');
                }
            } catch (Throwable $e) {
                $saveError = 'Could not create the voucher: ' . $e->getMessage();
            }
        }
    }

    // ── Promote / unpromote in BreadMoments ──
    if ($action === 'toggle_promote') {
        $vid = (int) ($_POST['voucher_id'] ?? 0);
        $promote = !empty($_POST['promote']);
        $result = setVoucherPromotion($pdo, $vid, $promote, (int) ($_SESSION['user_id'] ?? 0));
        if (!$result['ok']) {
            $saveError = $result['error'];
        } else {
            $saveSuccess = $result['promoted']
                ? 'Promotion is live in BreadMoments with a Claim button.'
                : 'Promotion removed from BreadMoments.';
        }
    }

    // ── Toggle active / inactive ──
    if ($action === 'toggle_voucher') {
        $vid = (int) ($_POST['voucher_id'] ?? 0);
        $pdo->prepare("UPDATE vouchers SET status = IF(status = 'active', 'inactive', 'active') WHERE id = :id")
            ->execute(['id' => $vid]);
        $saveSuccess = 'Voucher status updated.';
    }

    // ── Delete (only when nobody holds it) ──
    if ($action === 'delete_voucher') {
        $vid = (int) ($_POST['voucher_id'] ?? 0);
        $grants = (int) $pdo->query("SELECT COUNT(*) FROM customer_vouchers WHERE voucher_id = " . $vid)->fetchColumn();
        if ($grants > 0) {
            $saveError = 'This voucher is already held by customers, so it cannot be deleted. Deactivate it instead.';
        } else {
            $pdo->prepare('DELETE FROM vouchers WHERE id = :id')->execute(['id' => $vid]);
            $saveSuccess = 'Voucher deleted.';
        }
    }

    // ── Grant to a customer by email ──
    if ($action === 'grant_voucher') {
        $vid   = (int) ($_POST['voucher_id'] ?? 0);
        $email = trim((string) ($_POST['customer_email'] ?? ''));
        $days  = max(0, (int) ($_POST['expires_days'] ?? 0));

        $custStmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email = :e AND role = 'customer' LIMIT 1");
        $custStmt->execute(['e' => $email]);
        $customer = $custStmt->fetch();

        $vStmt = $pdo->prepare("SELECT id, code, usage_limit_per_user FROM vouchers WHERE id = :id LIMIT 1");
        $vStmt->execute(['id' => $vid]);
        $vRow = $vStmt->fetch();

        if (!$vRow) {
            $saveError = 'Pick a voucher to grant.';
        } elseif (!$customer) {
            $saveError = 'No customer account found with that email.';
        } else {
            $heldStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM customer_vouchers WHERE voucher_id = :vid AND customer_id = :cid"
            );
            $heldStmt->execute(['vid' => $vid, 'cid' => (int) $customer['id']]);
            if ((int) $heldStmt->fetchColumn() >= max(1, (int) $vRow['usage_limit_per_user'])) {
                $saveError = 'That customer already holds this voucher the maximum number of times.';
            } else {
                $expiresAt = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;
                if (grantVoucherToCustomer($pdo, $vid, (int) $customer['id'], 'admin_grant', $expiresAt)) {
                    $saveSuccess = "Voucher {$vRow['code']} granted to {$customer['first_name']} ({$email}).";
                } else {
                    $saveError = 'Could not grant the voucher. Please try again.';
                }
            }
        }
    }
}

// ── Load vouchers with redemption stats ───────────────────────────────────────
$vouchers = [];
$statTotals = ['total' => 0, 'active' => 0, 'used' => 0, 'unused' => 0];
if ($tablesReady) {
    $vouchers = $pdo->query(
        "SELECT v.*,
                (SELECT COUNT(*) FROM customer_vouchers cv WHERE cv.voucher_id = v.id) AS grants_total,
                (SELECT COUNT(*) FROM customer_vouchers cv WHERE cv.voucher_id = v.id AND cv.status = 'used') AS grants_used,
                (SELECT COUNT(*) FROM customer_vouchers cv WHERE cv.voucher_id = v.id AND cv.status = 'unused'
                    AND (cv.expires_at IS NULL OR cv.expires_at > NOW())) AS grants_unused
         FROM vouchers v
         ORDER BY v.created_at DESC, v.id DESC"
    )->fetchAll();

    $statTotals['total']  = count($vouchers);
    foreach ($vouchers as $vRow) {
        if ($vRow['status'] === 'active') $statTotals['active']++;
        $statTotals['used']   += (int) $vRow['grants_used'];
        $statTotals['unused'] += (int) $vRow['grants_unused'];
    }
}

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro">
    <h2>Vouchers</h2>
    <p>Create free delivery vouchers, hand them to customers, and track redemptions.</p>
</section>

<?php if (!$tablesReady): ?>
<div class="voucher-alert is-error">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span>Voucher tables not found. Please run the <a href="<?php echo BASE_URL; ?>/database/migrate_vouchers.php" style="color:inherit;text-decoration:underline;">voucher migration</a> first.</span>
</div>
<?php endif; ?>

<?php if ($saveSuccess): ?>
<div class="voucher-alert is-success"><i class="fa-solid fa-circle-check"></i><span><?php echo htmlspecialchars($saveSuccess); ?></span></div>
<?php elseif ($saveError): ?>
<div class="voucher-alert is-error"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($saveError); ?></span></div>
<?php endif; ?>

<?php if ($tablesReady): ?>

<!-- ── Stats Row ── -->
<div class="voucher-stats-grid">
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-total"><i class="fa-solid fa-ticket"></i></div>
        <div><strong><?php echo $statTotals['total']; ?></strong><span>Total Vouchers</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-active"><i class="fa-solid fa-signal"></i></div>
        <div><strong><?php echo $statTotals['active']; ?></strong><span>Active</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-used"><i class="fa-solid fa-circle-check"></i></div>
        <div><strong><?php echo $statTotals['used']; ?></strong><span>Times Redeemed</span></div>
    </div>
    <div class="voucher-stat-card">
        <div class="voucher-stat-icon is-unused"><i class="fa-solid fa-gift"></i></div>
        <div><strong><?php echo $statTotals['unused']; ?></strong><span>Unused Grants</span></div>
    </div>
</div>

<div class="vouchers-layout">

    <!-- ── Create Voucher ── -->
    <section class="panel voucher-form-card">
        <div class="panel-heading" style="margin-bottom:4px;">
            <h3><i class="fa-solid fa-plus" style="margin-right:.45rem;color:var(--admin-brown);"></i>Create Voucher</h3>
        </div>
        <p class="panel-subtitle" style="margin-bottom:18px;">All vouchers are the <strong>Free Delivery</strong> type.</p>

        <form method="POST">
            <input type="hidden" name="action" value="create_voucher" />

            <div class="vf-section-label">Basics</div>

            <label class="vf-field">
                <span>Code <em>*</em></span>
                <input type="text" name="code" class="vf-input is-code" maxlength="50" required placeholder="E.G. FREEDEL50" />
                <small>Letters, numbers, dashes. Customers type this at checkout.</small>
            </label>

            <label class="vf-field">
                <span>Title <em>*</em></span>
                <input type="text" name="title" class="vf-input" maxlength="150" required placeholder="e.g. Free Delivery Weekend" />
            </label>

            <label class="vf-field">
                <span>Description</span>
                <input type="text" name="description" class="vf-input" maxlength="255" placeholder="e.g. Free delivery on all orders this weekend!" />
            </label>

            <div class="vf-section-label">Rules</div>

            <div class="vf-grid-2" style="margin-bottom:13px;">
                <label class="vf-field">
                    <span>Min. spend (₱)</span>
                    <input type="number" name="min_spend" class="vf-input" min="0" step="1" value="0" />
                </label>
                <label class="vf-field">
                    <span>Uses per customer</span>
                    <input type="number" name="usage_limit_per_user" class="vf-input" min="1" step="1" value="1" />
                </label>
            </div>

            <label class="vf-field">
                <span>Total uses</span>
                <input type="number" name="usage_limit_total" class="vf-input" min="1" step="1" placeholder="Leave blank for unlimited" />
            </label>

            <label class="vf-toggle" style="margin-bottom:6px;">
                <input type="checkbox" name="is_public" value="1" />
                <span class="vf-toggle-track"></span>
                <span class="vf-toggle-text">
                    <strong>Public promo code</strong>
                    <small>Anyone can type this code at checkout. Off = grant to customers manually.</small>
                </span>
            </label>

            <div class="vf-section-label">Schedule</div>

            <div class="vf-grid-2">
                <label class="vf-field">
                    <span>Valid from</span>
                    <input type="datetime-local" name="valid_from" class="vf-input" id="vf-valid-from" />
                </label>
                <label class="vf-field">
                    <span>Valid until</span>
                    <input type="datetime-local" name="valid_until" class="vf-input" id="vf-valid-until" />
                </label>
            </div>
            <small id="vf-schedule-hint" style="display:block;margin-top:8px;color:var(--admin-muted);font-size:11px;">
                Start: now up to 1 year ahead · End: after the start, up to 1 year long.
            </small>

            <div class="vf-hint">
                <i class="fa-solid fa-circle-info"></i>
                <span>Blank dates mean the voucher works right away and never expires. Customers with unused grants still see them in <strong>My Account → My Vouchers</strong>.</span>
            </div>

            <button type="submit" class="admin-button primary vf-submit"><i class="fa-solid fa-ticket"></i> Create Voucher</button>
        </form>
    </section>

    <!-- ── Voucher List ── -->
    <section class="panel" style="margin:0;">
        <div class="panel-heading" style="margin-bottom:16px;">
            <h3><i class="fa-solid fa-ticket" style="margin-right:.45rem;color:var(--admin-brown);"></i>All Vouchers</h3>
            <span style="color:var(--admin-muted);font-size:12px;font-weight:700;"><?php echo count($vouchers); ?> total</span>
        </div>

        <?php if (empty($vouchers)): ?>
            <div class="vouchers-empty">
                <i class="fa-solid fa-ticket"></i>
                <p>No vouchers yet. Create your first one on the left —<br>the <strong>WELCOME</strong> voucher is seeded automatically by the migration.</p>
            </div>
        <?php else: ?>
            <?php foreach ($vouchers as $v):
                $isActive  = $v['status'] === 'active';
                $totalLim  = $v['usage_limit_total'] !== null ? (int) $v['usage_limit_total'] : null;
                $usedCount = (int) $v['grants_used'];
                $usagePct  = $totalLim ? min(100, round(($usedCount / max(1, $totalLim)) * 100)) : null;
            ?>
            <div class="voucher-ticket<?php echo $isActive ? '' : ' is-inactive'; ?>">
                <div class="vt-stub">
                    <i class="fa-solid fa-truck-fast"></i>
                    <strong>Free<br>Delivery</strong>
                </div>
                <div class="vt-body">
                    <div class="vt-top">
                        <div style="min-width:0;">
                            <div class="vt-code"><?php echo htmlspecialchars($v['code']); ?></div>
                            <div class="vt-badges">
                                <?php if ($isActive): ?>
                                    <span class="vt-badge is-active"><i class="fa-solid fa-circle" style="font-size:6px;"></i> ACTIVE</span>
                                <?php else: ?>
                                    <span class="vt-badge is-inactive-badge"><i class="fa-solid fa-pause" style="font-size:7px;"></i> INACTIVE</span>
                                <?php endif; ?>
                                <?php if (!empty($v['is_public'])): ?>
                                    <span class="vt-badge is-public"><i class="fa-solid fa-bullhorn"></i> PUBLIC CODE</span>
                                <?php else: ?>
                                    <span class="vt-badge is-private"><i class="fa-solid fa-gift"></i> GRANTED ONLY</span>
                                <?php endif; ?>
                                <?php if (!empty($v['is_promoted'])): ?>
                                    <span class="vt-badge is-promoted"><i class="fa-solid fa-star"></i> IN BREADMOMENTS</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="vt-actions">
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_promote" />
                                <input type="hidden" name="voucher_id" value="<?php echo (int) $v['id']; ?>" />
                                <input type="hidden" name="promote" value="<?php echo !empty($v['is_promoted']) ? '0' : '1'; ?>" />
                                <button type="submit" class="admin-button <?php echo !empty($v['is_promoted']) ? 'secondary' : 'primary'; ?> mod-table-btn"
                                        title="<?php echo !empty($v['is_promoted']) ? 'Remove from BreadMoments' : 'Show in BreadMoments'; ?>">
                                    <i class="fa-solid <?php echo !empty($v['is_promoted']) ? 'fa-eye-slash' : 'fa-bullhorn'; ?>"></i>
                                    <?php echo !empty($v['is_promoted']) ? 'Unpromote' : 'Promote'; ?>
                                </button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_voucher" />
                                <input type="hidden" name="voucher_id" value="<?php echo (int) $v['id']; ?>" />
                                <button type="submit" class="admin-button secondary mod-table-btn" title="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>">
                                    <i class="fa-solid <?php echo $isActive ? 'fa-pause' : 'fa-play'; ?>"></i> <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                            <?php if ((int) $v['grants_total'] === 0): ?>
                            <form method="POST" onsubmit="return confirm('Delete this voucher permanently?');">
                                <input type="hidden" name="action" value="delete_voucher" />
                                <input type="hidden" name="voucher_id" value="<?php echo (int) $v['id']; ?>" />
                                <button type="submit" class="admin-button danger-button mod-table-btn" title="Delete">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="vt-title"><?php echo htmlspecialchars($v['title']); ?></div>
                    <?php if (!empty($v['description'])): ?>
                        <div class="vt-desc"><?php echo htmlspecialchars($v['description']); ?></div>
                    <?php endif; ?>

                    <div class="vt-rules">
                        <span><i class="fa-solid fa-basket-shopping"></i>Min spend ₱<?php echo number_format((float) $v['min_spend'], 2); ?></span>
                        <span><i class="fa-solid fa-user"></i><?php echo (int) $v['usage_limit_per_user']; ?>× per customer</span>
                        <span><i class="fa-solid fa-hashtag"></i>Total limit: <?php echo $totalLim !== null ? $totalLim : '∞'; ?></span>
                    </div>

                    <?php if (!empty($v['valid_from']) || !empty($v['valid_until'])): ?>
                    <div class="vt-validity">
                        <i class="fa-regular fa-clock"></i>
                        <?php echo !empty($v['valid_from']) ? date('M j, Y g:i A', strtotime((string) $v['valid_from'])) : 'Anytime'; ?>
                        →
                        <?php echo !empty($v['valid_until']) ? date('M j, Y g:i A', strtotime((string) $v['valid_until'])) : 'No expiry'; ?>
                    </div>
                    <?php endif; ?>

                    <div class="vt-stats-row">
                        <div class="vt-stat is-used"><strong><?php echo $usedCount; ?></strong><span>Used</span></div>
                        <div class="vt-stat"><strong><?php echo (int) $v['grants_unused']; ?></strong><span>Unused</span></div>
                        <div class="vt-stat"><strong><?php echo (int) $v['grants_total']; ?></strong><span>Granted</span></div>
                        <?php if ($usagePct !== null): ?>
                        <div class="vt-usage-bar">
                            <div class="vt-usage-track"><div class="vt-usage-fill" style="width:<?php echo $usagePct; ?>%;"></div></div>
                            <div class="vt-usage-label"><?php echo $usedCount; ?> / <?php echo $totalLim; ?> total uses</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <details class="vt-grant">
                        <summary><i class="fa-solid fa-gift"></i> Grant to a customer <i class="fa-solid fa-chevron-down"></i></summary>
                        <form method="POST" class="vt-grant-form">
                            <input type="hidden" name="action" value="grant_voucher" />
                            <input type="hidden" name="voucher_id" value="<?php echo (int) $v['id']; ?>" />
                            <label class="vt-grant-email">
                                Customer email
                                <input type="email" name="customer_email" class="vf-input" required placeholder="customer@email.com" />
                            </label>
                            <label class="vt-grant-days">
                                Expires in (days)
                                <input type="number" name="expires_days" class="vf-input" min="0" step="1" value="30" />
                            </label>
                            <button type="submit" class="admin-button primary mod-table-btn"><i class="fa-solid fa-gift"></i> Grant</button>
                        </form>
                    </details>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    const fromInput  = document.getElementById('vf-valid-from');
    const untilInput = document.getElementById('vf-valid-until');
    const hint       = document.getElementById('vf-schedule-hint');
    if (!fromInput || !untilInput) return;

    const ONE_YEAR_MS = 365 * 24 * 60 * 60 * 1000;
    const DEFAULT_HINT = hint ? hint.textContent : '';

    // Format a Date as the datetime-local value "YYYY-MM-DDTHH:MM" (local time).
    function toLocalValue(d) {
        const p = n => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
             + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function showHint(msg, isWarning) {
        if (!hint) return;
        hint.textContent = msg || DEFAULT_HINT;
        hint.style.color = msg ? '#a34f43' : 'var(--admin-muted)';
        hint.style.fontWeight = msg ? '700' : '400';
    }

    function syncLimits() {
        const now = new Date();

        // Valid from: now → +1 year
        fromInput.min = toLocalValue(now);
        fromInput.max = toLocalValue(new Date(now.getTime() + ONE_YEAR_MS));

        // Valid until: (from, or now) + 1 minute → +1 year after that base
        const fromVal = fromInput.value && !isNaN(new Date(fromInput.value))
            ? new Date(fromInput.value) : now;
        untilInput.min = toLocalValue(new Date(fromVal.getTime() + 60 * 1000));
        untilInput.max = toLocalValue(new Date(fromVal.getTime() + ONE_YEAR_MS));
    }

    // datetime-local values are zero-padded ISO strings, so < and > compare correctly.
    function validateFrom() {
        syncLimits();
        if (!fromInput.value) { showHint('', false); return true; }
        if (fromInput.value < fromInput.min) {
            fromInput.value = '';
            showHint('Valid from cannot be in the past — it was cleared.');
            return false;
        }
        if (fromInput.value > fromInput.max) {
            fromInput.value = '';
            showHint('Valid from cannot be more than 1 year ahead — it was cleared.');
            return false;
        }
        showHint('', false);
        return true;
    }

    function validateUntil() {
        syncLimits();
        if (!untilInput.value) { showHint('', false); return true; }
        if (untilInput.value < untilInput.min) {
            untilInput.value = '';
            showHint('Valid until must be later than the start date — it was cleared.');
            return false;
        }
        if (untilInput.value > untilInput.max) {
            untilInput.value = '';
            showHint('Vouchers can run for up to 1 year only — it was cleared.');
            return false;
        }
        showHint('', false);
        return true;
    }

    fromInput.addEventListener('change', function () {
        if (validateFrom()) {
            // The end date must stay after the (new) start date.
            validateUntil();
        }
    });
    untilInput.addEventListener('change', validateUntil);

    // Initial limits as soon as the page loads.
    syncLimits();
})();
</script>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
