<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();
$customerId = (int) ($_SESSION['user_id'] ?? 0);

// ── Ensure profile columns exist ──────────────────────────────────────────────
$profileColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_data'")->fetchColumn();
if (!$profileColumn) $pdo->exec("ALTER TABLE users ADD COLUMN profile_data MEDIUMBLOB NULL AFTER password, ADD COLUMN profile_mime VARCHAR(50) NULL AFTER profile_data");

$accountStatement = $pdo->prepare('SELECT first_name, last_name, phone, email, profile_data, profile_mime FROM users WHERE id = :id LIMIT 1');
$accountStatement->execute(['id' => $customerId]);
$account = $accountStatement->fetch() ?: [];
$profileError = '';
$profileSuccess = '';

// ── Profile photo update ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile_photo') {
    $photoAction = $_POST['photo_action'] ?? 'upload';
    if ($photoAction === 'remove') {
        $pdo->prepare('UPDATE users SET profile_data = NULL, profile_mime = NULL WHERE id = :id')->execute(['id' => $customerId]);
        $profileSuccess = 'Profile photo removed.';
    } elseif (empty($_FILES['profile_photo']['name']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        $profileError = 'Please choose a profile photo to upload.';
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_photo']['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) $profileError = 'Only JPG, PNG, and WEBP photos are allowed.';
        elseif ((int) $_FILES['profile_photo']['size'] > 5 * 1024 * 1024) $profileError = 'Profile photo must be 5 MB or smaller.';
        else {
            $photoData = file_get_contents($_FILES['profile_photo']['tmp_name']);
            if ($photoData === false) $profileError = 'Unable to read the profile photo.';
            else { $pdo->prepare('UPDATE users SET profile_data = :profile_data, profile_mime = :profile_mime WHERE id = :id')->execute(['profile_data' => $photoData, 'profile_mime' => $mime, 'id' => $customerId]); $profileSuccess = 'Profile photo updated successfully.'; }
        }
    }
    $accountStatement->execute(['id' => $customerId]);
    $account = $accountStatement->fetch() ?: [];
}

$profileImage = !empty($account['profile_data']) && !empty($account['profile_mime']) ? 'data:' . $account['profile_mime'] . ';base64,' . base64_encode($account['profile_data']) : '';
$passwordError = '';
$passwordSuccess = '';
$passwordPanelActive = false;

function accountPasswordStrength(string $password): int
{
    $score = 0;
    if (strlen($password) >= 8) $score++;
    if (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password)) $score++;
    if (preg_match('/\d/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    return $score;
}

// ── Password change ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $passwordPanelActive = true;
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $passwordStatement = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
    $passwordStatement->execute(['id' => $customerId]);
    $storedPassword = (string) ($passwordStatement->fetchColumn() ?: '');
    if ($currentPassword === '' || !password_verify($currentPassword, $storedPassword)) $passwordError = 'Current password is incorrect.';
    elseif (accountPasswordStrength($newPassword) < 2) $passwordError = 'New password must be at least Medium strength.';
    elseif ($newPassword !== $confirmPassword) $passwordError = 'New password and confirmation do not match.';
    elseif ($newPassword === $currentPassword) $passwordError = 'New password must be different from your current password.';
    else {
        $pdo->prepare('UPDATE users SET password = :password WHERE id = :id')->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $customerId]);
        $passwordSuccess = 'Password changed successfully.';
    }
}

// ── Address management ────────────────────────────────────────────────────────
$addressError   = '';
$addressSuccess = '';
$addressPanelActive = false;
$MAX_ADDRESSES  = 5;

// Count existing addresses
$addrCount = (int) $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :cid')->execute(['cid' => $customerId]) ? $pdo->query("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = $customerId")->fetchColumn() : 0;
// Recount properly
$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :cid');
$cntStmt->execute(['cid' => $customerId]);
$addrCount = (int) $cntStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add_address','delete_address','set_default_address'], true)) {
    $addressPanelActive = true;
    $addrAction = $_POST['action'];

    if ($addrAction === 'add_address') {
        $label      = trim(strip_tags($_POST['addr_label']       ?? 'Home'));
        $fullAddr   = trim(strip_tags($_POST['addr_full']        ?? ''));
        $barangay   = trim(strip_tags($_POST['addr_barangay']    ?? ''));
        $city       = trim(strip_tags($_POST['addr_city']        ?? ''));
        $province   = trim(strip_tags($_POST['addr_province']    ?? ''));
        $postal     = trim(strip_tags($_POST['addr_postal']      ?? ''));
        $isDefault  = !empty($_POST['addr_is_default']) ? 1 : 0;

        if ($fullAddr === '') {
            $addressError = 'Full address is required.';
        } elseif ($addrCount >= $MAX_ADDRESSES) {
            $addressError = "You can save up to $MAX_ADDRESSES addresses. Please remove one before adding a new one.";
        } else {
            try {
                $pdo->beginTransaction();
                if ($isDefault) {
                    $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid')->execute(['cid' => $customerId]);
                }
                // If this is the first address, make it default automatically
                if ($addrCount === 0) $isDefault = 1;
                $pdo->prepare(
                    "INSERT INTO customer_addresses (customer_id, label, full_address, barangay, city, province, postal_code, is_default)
                     VALUES (:cid, :label, :full, :bar, :city, :prov, :postal, :def)"
                )->execute([
                    'cid' => $customerId, 'label' => $label ?: 'Home',
                    'full' => $fullAddr, 'bar' => $barangay, 'city' => $city,
                    'prov' => $province, 'postal' => $postal, 'def' => $isDefault,
                ]);
                $pdo->commit();
                $addressSuccess = 'Address saved successfully.';
                $addrCount++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $addressError = 'Could not save address. Please try again.';
            }
        }
    }

    if ($addrAction === 'delete_address') {
        $addrId = (int) ($_POST['addr_id'] ?? 0);
        // Verify ownership
        $own = $pdo->prepare('SELECT id, is_default FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
        $own->execute(['id' => $addrId, 'cid' => $customerId]);
        $row = $own->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM customer_addresses WHERE id = :id')->execute(['id' => $addrId]);
            // If deleted address was default, make the newest remaining address default
            if ($row['is_default']) {
                $pdo->prepare(
                    'UPDATE customer_addresses SET is_default = 1 WHERE customer_id = :cid ORDER BY created_at DESC LIMIT 1'
                )->execute(['cid' => $customerId]);
            }
            $addressSuccess = 'Address removed.';
            $addrCount = max(0, $addrCount - 1);
        }
    }

    if ($addrAction === 'set_default_address') {
        $addrId = (int) ($_POST['addr_id'] ?? 0);
        $own = $pdo->prepare('SELECT id FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
        $own->execute(['id' => $addrId, 'cid' => $customerId]);
        if ($own->fetch()) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid')->execute(['cid' => $customerId]);
            $pdo->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = :id')->execute(['id' => $addrId]);
            $pdo->commit();
            $addressSuccess = 'Default address updated.';
        }
    }
}

// Load saved addresses
$addrStmt = $pdo->prepare(
    'SELECT id, label, full_address, barangay, city, province, postal_code, is_default
     FROM customer_addresses WHERE customer_id = :cid ORDER BY is_default DESC, created_at ASC'
);
$addrStmt->execute(['cid' => $customerId]);
$savedAddresses = $addrStmt->fetchAll();

// ── Page meta ──────────────────────────────────────────────────────────────────
$pageTitle = 'My Account | BreadBreak';
$fullName = trim(($account['first_name'] ?? $_SESSION['first_name'] ?? '') . ' ' . ($account['last_name'] ?? $_SESSION['last_name'] ?? ''));
$profileInitial = strtoupper(substr(trim((string) ($account['first_name'] ?? '')), 0, 1)) ?: '?';

// Determine which panel is active on load
$activePanelOnLoad = 'personal-details';
if ($passwordPanelActive)  $activePanelOnLoad = 'password-security';
if ($addressPanelActive)   $activePanelOnLoad = 'my-addresses';

$accountSections = [
    ['id' => 'personal-details', 'icon' => 'fa-user', 'title' => 'Personal Details', 'description' => 'Manage your name and contact information.'],
    ['id' => 'my-addresses',     'icon' => 'fa-location-dot', 'title' => 'My Addresses', 'description' => 'Save addresses for faster checkout.'],
    ['id' => 'account-activities', 'icon' => 'fa-clock-rotate-left', 'title' => 'Account Activities', 'description' => 'Review activity from your BreadBreak account.', 'disabled' => true],
    ['id' => 'password-security', 'icon' => 'fa-lock', 'title' => 'Password & Security', 'description' => 'Keep your account password secure.'],
];

require __DIR__ . '/../includes/header.php';
?>
<!-- Leaflet.js (100% Free OpenStreetMap Interactive Map) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<main class="customer-account-page">
    <section class="customer-account-hero"><div class="container"><a class="account-back-link" href="/BreadBreak/customer/menu_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Menu</a><span class="eyebrow">Account Settings</span><h1>My Account</h1><p>Manage your BreadBreak account details and security.</p></div></section>
    <section class="section customer-account-content"><div class="container customer-account-layout">
        <aside class="customer-account-sidebar"><div class="account-sidebar-profile"><div class="customer-profile-avatar"><?php if ($profileImage): ?><img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile photo" /><?php else: ?><span aria-hidden="true"><?php echo htmlspecialchars($profileInitial); ?></span><?php endif; ?></div><div><strong><?php echo htmlspecialchars($fullName ?: 'Customer'); ?></strong><span>Customer</span></div></div><nav class="account-section-nav" aria-label="Account sections">
            <?php foreach ($accountSections as $section): ?><button type="button" class="account-section-link<?php echo $section['id'] === $activePanelOnLoad ? ' active' : ''; ?><?php echo !empty($section['disabled']) ? ' is-disabled' : ''; ?>" data-account-section="<?php echo $section['id']; ?>" <?php echo !empty($section['disabled']) ? 'disabled' : ''; ?>><i class="fa-solid <?php echo $section['icon']; ?>"></i><span><?php echo $section['title']; ?><small><?php echo $section['description']; ?></small></span><?php if (!empty($section['disabled'])): ?><em>Soon</em><?php endif; ?></button><?php endforeach; ?>
        </nav><button class="account-delete-link" type="button" data-open-delete-account><i class="fa-solid fa-trash-can"></i> Delete Account</button></aside>
        <div class="customer-account-panels">

            <!-- ── Personal Details Panel ── -->
            <section class="account-panel<?php echo $activePanelOnLoad === 'personal-details' ? ' is-active' : ''; ?>" id="personal-details" data-account-panel><div class="account-panel-heading"><div><span class="eyebrow">Your information</span><h2>Personal Details</h2><p>These details are connected to your customer account.</p></div></div><?php if ($profileError): ?><p class="account-form-error" role="alert"><?php echo htmlspecialchars($profileError); ?></p><?php endif; ?><?php if ($profileSuccess): ?><p class="account-form-success" role="status"><?php echo htmlspecialchars($profileSuccess); ?></p><?php endif; ?><form class="account-form profile-photo-form" method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="update_profile_photo" /><div class="profile-photo-editor"><div class="profile-photo-preview"><?php if ($profileImage): ?><img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile photo" /><?php else: ?><span aria-hidden="true"><?php echo htmlspecialchars($profileInitial); ?></span><?php endif; ?></div><div><strong>Profile</strong><p>Add a photo so your account is easier to recognize.</p><label class="profile-upload-button" for="profile-photo-input"><i class="fa-solid fa-camera"></i> Choose Photo</label><input id="profile-photo-input" class="sr-only" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" /><small class="profile-photo-help">JPG, PNG, or WEBP up to 5 MB.</small></div></div><div class="account-form-grid"><label>First Name<input type="text" value="<?php echo htmlspecialchars($account['first_name'] ?? ''); ?>" readonly /></label><label>Last Name<input type="text" value="<?php echo htmlspecialchars($account['last_name'] ?? ''); ?>" readonly /></label><label>Phone Number<input type="text" value="<?php echo htmlspecialchars($account['phone'] ?? ''); ?>" readonly /></label><label>Email<input type="email" value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" readonly /></label></div><button class="account-save-button profile-save-button" type="submit">Save Profile Photo</button></form><?php if ($profileImage): ?><form class="profile-remove-form" method="POST"><input type="hidden" name="action" value="update_profile_photo" /><input type="hidden" name="photo_action" value="remove" /><button class="profile-remove-button" type="submit"><i class="fa-solid fa-trash-can"></i> Remove Photo</button></form><?php endif; ?></section>

            <!-- ── My Addresses Panel ── -->
            <section class="account-panel<?php echo $activePanelOnLoad === 'my-addresses' ? ' is-active' : ''; ?>" id="my-addresses" data-account-panel>
                <div class="account-panel-heading">
                    <div>
                        <span class="eyebrow">Delivery information</span>
                        <h2>My Addresses</h2>
                        <p>Save up to <?php echo $MAX_ADDRESSES; ?> addresses for faster checkout. Your default address will be pre-selected at checkout.</p>
                    </div>
                </div>

                <?php if ($addressError): ?>
                    <p class="account-form-error" role="alert"><i class="fa-solid fa-circle-exclamation" style="margin-right:.35rem;"></i><?php echo htmlspecialchars($addressError); ?></p>
                <?php endif; ?>
                <?php if ($addressSuccess): ?>
                    <p class="account-form-success" role="status"><i class="fa-solid fa-circle-check" style="margin-right:.35rem;"></i><?php echo htmlspecialchars($addressSuccess); ?></p>
                <?php endif; ?>

                <!-- Saved address list -->
                <?php if (!empty($savedAddresses)): ?>
                <div class="address-list">
                    <?php foreach ($savedAddresses as $addr): ?>
                    <div class="address-card<?php echo $addr['is_default'] ? ' address-card-default' : ''; ?>">
                        <div class="address-card-top">
                            <div class="address-card-label-row">
                                <span class="address-card-label"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($addr['label']); ?></span>
                                <?php if ($addr['is_default']): ?>
                                    <span class="address-default-badge"><i class="fa-solid fa-star"></i> Default</span>
                                <?php endif; ?>
                            </div>
                            <p class="address-card-text"><?php echo nl2br(htmlspecialchars($addr['full_address'])); ?></p>
                            <?php if ($addr['city'] || $addr['province']): ?>
                                <p class="address-card-meta"><?php echo htmlspecialchars(implode(', ', array_filter([$addr['barangay'], $addr['city'], $addr['province'], $addr['postal_code']]))); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="address-card-actions">
                            <?php if (!$addr['is_default']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="set_default_address" />
                                <input type="hidden" name="addr_id" value="<?php echo $addr['id']; ?>" />
                                <button type="submit" class="addr-btn addr-btn-default"><i class="fa-regular fa-star"></i> Set Default</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this address?');">
                                <input type="hidden" name="action" value="delete_address" />
                                <input type="hidden" name="addr_id" value="<?php echo $addr['id']; ?>" />
                                <button type="submit" class="addr-btn addr-btn-remove"><i class="fa-solid fa-trash-can"></i> Remove</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="address-empty">
                    <i class="fa-solid fa-location-dot"></i>
                    <p>No saved addresses yet. Add one below to speed up checkout!</p>
                </div>
                <?php endif; ?>

                <!-- Add new address -->
                <?php if ($addrCount < $MAX_ADDRESSES): ?>
                <div class="address-add-wrap">
                    <button type="button" class="addr-add-toggle" id="addr-add-toggle" aria-expanded="false">
                        <i class="fa-solid fa-plus"></i> Add New Address
                    </button>
                    <div class="address-add-form" id="addr-add-form" style="display:none;">
                        <form method="POST" class="account-form" style="margin-top:0;">
                            <input type="hidden" name="action" value="add_address" />

                            <!-- Interactive Map Location Picker (100% Free Leaflet + OpenStreetMap) -->
                            <div class="map-picker-card" style="margin-bottom:1.1rem;border:1.5px solid var(--border,#ded6d0);border-radius:14px;overflow:hidden;background:#fff;">
                                <div style="padding:.75rem 1rem;background:#fdfbf8;border-bottom:1px solid var(--border,#ded6d0);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                                    <div>
                                        <strong style="font-size:.88rem;color:var(--brown-900);display:flex;align-items:center;gap:.4rem;">
                                            <i class="fa-solid fa-map-location-dot" style="color:var(--accent);"></i> Pin Your Location on Map
                                        </strong>
                                        <p style="margin:.15rem 0 0;font-size:.76rem;color:var(--muted);">Click on the map or drag the pin to auto-fill your address. Green circle is our 8km delivery zone.</p>
                                    </div>
                                    <button type="button" id="btn-locate-me" class="addr-btn addr-btn-default" style="font-size:.78rem;padding:.35rem .75rem;">
                                        <i class="fa-solid fa-crosshairs"></i> Use My GPS Location
                                    </button>
                                </div>
                                <div id="address-map" style="width:100%;height:260px;background:#e5e3df;position:relative;z-index:1;"></div>
                                <div style="padding:.55rem 1rem;background:#fff;border-top:1px solid var(--border,#ded6d0);font-size:.82rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                                    <span id="map-zone-status" style="font-weight:600;color:var(--muted);">
                                        <i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>Click anywhere on map to set your pin
                                    </span>
                                    <span id="map-distance-km" style="font-weight:700;color:var(--brown-900);"></span>
                                </div>
                            </div>

                            <div class="account-form-grid" style="grid-template-columns:1fr 1fr;gap:.9rem;">
                                <label style="grid-column:1/-1;">
                                    Label <span style="font-size:.78rem;font-weight:400;color:var(--muted);">(e.g. Home, Work, Parents)</span>
                                    <input type="text" name="addr_label" placeholder="Home" maxlength="60" value="Home" />
                                </label>
                                <label style="grid-column:1/-1;">
                                    Full Address <span style="color:#c00;font-weight:700;">*</span>
                                    <textarea name="addr_full" rows="2" placeholder="e.g. Blk 5 Lot 3, Purok 2, Ilang-Ilang, Guiguinto, Bulacan" required style="width:100%;border:1.5px solid var(--border,#ede8e0);border-radius:10px;padding:.6rem .9rem;font-family:inherit;font-size:.92rem;box-sizing:border-box;resize:vertical;margin-top:.35rem;"></textarea>
                                </label>
                                <label>
                                    Barangay
                                    <input type="text" name="addr_barangay" placeholder="Ilang-Ilang" maxlength="100" />
                                </label>
                                <label>
                                    City / Municipality
                                    <input type="text" name="addr_city" placeholder="Guiguinto" maxlength="100" />
                                </label>
                                <label>
                                    Province
                                    <input type="text" name="addr_province" placeholder="Bulacan" maxlength="100" />
                                </label>
                                <label>
                                    Postal Code
                                    <input type="text" name="addr_postal" placeholder="3015" maxlength="20" />
                                </label>
                                <div style="grid-column:1/-1;display:flex;align-items:center;gap:.6rem;margin-top:.4rem;cursor:pointer;">
                                    <input type="checkbox" id="addr_is_default" name="addr_is_default" value="1" <?php echo empty($savedAddresses) ? 'checked' : ''; ?> />
                                    <label for="addr_is_default" style="display:inline;font-size:.88rem;font-weight:600;color:var(--brown-700);cursor:pointer;margin:0;">Set as my default address</label>
                                </div>
                            </div>
                            <!-- Zone check result for new address -->
                            <div id="addr-zone-result" class="zone-check-result" style="display:none;margin-top:.5rem;" aria-live="polite"></div>
                            <p style="font-size:.8rem;color:var(--muted);margin:.5rem 0 0;">We'll check your address against our delivery zones when you save.</p>
                            <div style="display:flex;gap:.75rem;margin-top:1rem;flex-wrap:wrap;">
                                <button type="submit" class="account-save-button" style="margin:0;">Save Address</button>
                                <button type="button" class="addr-btn addr-btn-default" id="addr-add-cancel">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <p style="font-size:.85rem;color:var(--muted);margin-top:1rem;"><i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>You've reached the maximum of <?php echo $MAX_ADDRESSES; ?> saved addresses. Remove one to add a new address.</p>
                <?php endif; ?>
            </section>

            <!-- ── Password & Security Panel ── -->
            <section class="account-panel<?php echo $activePanelOnLoad === 'password-security' ? ' is-active' : ''; ?>" id="password-security" data-account-panel><div class="account-panel-heading"><div><span class="eyebrow">Account protection</span><h2>Password &amp; Security</h2><p>Update your password regularly to keep your account protected.</p></div></div><?php if ($passwordError): ?><p class="account-form-error" role="alert"><?php echo htmlspecialchars($passwordError); ?></p><?php endif; ?><form class="account-form" method="POST" data-password-form><input type="hidden" name="action" value="change_password" /><label>Current Password<div class="account-password-field"><input type="password" name="current_password" data-current-password placeholder="Enter current password" required /><button type="button" data-toggle-account-password aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div></label><label>New Password<div class="account-password-field"><input type="password" name="new_password" data-new-password placeholder="Enter new password" required /><button type="button" data-toggle-account-password aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><div class="password-strength" aria-live="polite"><div class="password-strength-label"><span>Password strength</span><strong data-password-strength-label>Enter a password</strong></div><div class="password-strength-track"><span data-password-strength-bar></span></div><small data-password-strength-help>Use at least 8 characters with uppercase, lowercase, number, and symbol.</small></div></label><label>Confirm Password<div class="account-password-field"><input type="password" name="confirm_password" data-confirm-password placeholder="Confirm new password" required /><button type="button" data-toggle-account-password aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><small class="password-match-message" data-password-match></small></label><button class="account-save-button" type="submit" disabled>Change Password</button><p class="account-form-note" data-password-form-message><?php echo htmlspecialchars($passwordSuccess); ?></p></form></section>

        </div>
    </div></section>
</main>

<div class="account-modal-backdrop" data-account-backdrop></div>
<div class="account-delete-modal" id="delete-account-modal" role="dialog" aria-modal="true" aria-labelledby="delete-account-title"><div class="account-modal-heading"><div><span class="eyebrow warning-eyebrow">Permanent Action</span><h2 id="delete-account-title">Delete Account</h2></div><button class="account-modal-close" type="button" data-close-delete-account aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>Are you sure you want to delete this account? This action is irreversible.</p><label class="account-delete-confirmation">Confirmation<input type="text" data-delete-account-input placeholder="Type 'Delete' to confirm" autocomplete="off" /></label><div class="account-modal-actions"><button class="account-cancel-button" type="button" data-close-delete-account>Cancel</button><button class="account-danger-button" type="button" data-delete-account-button disabled><i class="fa-solid fa-trash-can"></i> Delete Account</button></div></div>
<div class="profile-crop-backdrop" data-profile-crop-backdrop></div><div class="profile-crop-modal" data-profile-crop-modal role="dialog" aria-modal="true" aria-labelledby="profile-crop-title"><div class="account-modal-heading"><div><span class="eyebrow">Profile Photo</span><h2 id="profile-crop-title">Adjust Your Photo</h2></div><button class="account-modal-close" type="button" data-close-profile-crop aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>Drag the photo to position it and use the slider to zoom.</p><div class="profile-crop-stage" data-profile-crop-stage><img data-profile-crop-image alt="Profile photo preview" /></div><label class="profile-zoom-control">Zoom<input type="range" min="1" max="3" step="0.01" value="1" data-profile-zoom /></label><div class="account-modal-actions"><button class="account-cancel-button" type="button" data-close-profile-crop>Cancel</button><button class="account-danger-button profile-crop-save" type="button" data-save-profile-crop>Save Photo</button></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ── Panel navigation ──────────────────────────────────────────────────────
    const panels = document.querySelectorAll('[data-account-panel]');
    document.querySelectorAll('[data-account-section]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('[data-account-section]').forEach(function (item) { item.classList.remove('active'); });
            panels.forEach(function (panel) { panel.classList.toggle('is-active', panel.id === button.dataset.accountSection); });
            button.classList.add('active');
        });
    });

    // ── Password toggle ───────────────────────────────────────────────────────
    document.querySelectorAll('[data-toggle-account-password]').forEach(function (button) { button.addEventListener('click', function () { const input = button.previousElementSibling; input.type = input.type === 'password' ? 'text' : 'password'; button.querySelector('i').classList.toggle('fa-eye'); button.querySelector('i').classList.toggle('fa-eye-slash'); }); });
    const passwordForm = document.querySelector('[data-password-form]');
    if (passwordForm) {
        const currentPassword = passwordForm.querySelector('[data-current-password]'); const newPassword = passwordForm.querySelector('[data-new-password]'); const confirmPassword = passwordForm.querySelector('[data-confirm-password]'); const strengthLabel = passwordForm.querySelector('[data-password-strength-label]'); const strengthBar = passwordForm.querySelector('[data-password-strength-bar]'); const strengthHelp = passwordForm.querySelector('[data-password-strength-help]'); const matchMessage = passwordForm.querySelector('[data-password-match]'); const changeButton = passwordForm.querySelector('.account-save-button'); const formMessage = passwordForm.querySelector('[data-password-form-message]');
        function passwordStrength(value) { let score = 0; if (value.length >= 8) score++; if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++; if (/\d/.test(value)) score++; if (/[^A-Za-z0-9]/.test(value)) score++; return value === '' ? 0 : score <= 1 ? 1 : score <= 3 ? 2 : 3; }
        function updatePasswordState() { const strength = passwordStrength(newPassword.value); const labels = ['Enter a password', 'Weak', 'Medium', 'Strong']; const classes = ['', 'weak', 'medium', 'strong']; strengthLabel.textContent = labels[strength]; strengthLabel.className = classes[strength]; strengthBar.className = classes[strength]; strengthBar.style.width = (strength * 33.33) + '%'; strengthHelp.textContent = strength === 3 ? 'Strong password.' : 'Use at least 8 characters with uppercase, lowercase, number, and symbol.'; if (!confirmPassword.value) { matchMessage.textContent = ''; matchMessage.className = 'password-match-message'; } else if (newPassword.value === confirmPassword.value) { matchMessage.textContent = 'Passwords match.'; matchMessage.className = 'password-match-message matches'; } else { matchMessage.textContent = 'Passwords do not match.'; matchMessage.className = 'password-match-message does-not-match'; } changeButton.disabled = !(currentPassword.value && strength >= 2 && newPassword.value === confirmPassword.value); }
        passwordForm.querySelectorAll('input').forEach(function (input) { input.addEventListener('input', updatePasswordState); });
    }

    // ── Delete account modal ──────────────────────────────────────────────────
    const modal = document.getElementById('delete-account-modal'); const backdrop = document.querySelector('[data-account-backdrop]'); const input = document.querySelector('[data-delete-account-input]'); const deleteButton = document.querySelector('[data-delete-account-button]');
    function closeDeleteModal() { modal.classList.remove('is-open'); backdrop.classList.remove('is-open'); }
    document.querySelector('[data-open-delete-account]').addEventListener('click', function () { modal.classList.add('is-open'); backdrop.classList.add('is-open'); input.focus(); });
    document.querySelectorAll('[data-close-delete-account]').forEach(function (button) { button.addEventListener('click', closeDeleteModal); }); backdrop.addEventListener('click', closeDeleteModal);
    input.addEventListener('input', function () { deleteButton.disabled = input.value !== 'Delete'; });
    deleteButton.addEventListener('click', function () { deleteButton.textContent = 'Coming Soon'; deleteButton.disabled = true; });

    // ── Profile photo crop ────────────────────────────────────────────────────
    const photoInput = document.getElementById('profile-photo-input'); const cropModal = document.querySelector('[data-profile-crop-modal]'); const cropBackdrop = document.querySelector('[data-profile-crop-backdrop]'); const cropStage = document.querySelector('[data-profile-crop-stage]'); const cropImage = document.querySelector('[data-profile-crop-image]'); const zoomInput = document.querySelector('[data-profile-zoom]'); const profilePreview = document.querySelector('.profile-photo-preview'); let cropScale = 1; let cropX = 0; let cropY = 0; let dragStartX = 0; let dragStartY = 0; let dragging = false;
    function renderCropImage() { cropImage.style.transform = 'translate(-50%, -50%) translate(' + cropX + 'px, ' + cropY + 'px) scale(' + cropScale + ')'; }
    function closeCropModal(clearInput) { cropModal.classList.remove('is-open'); cropBackdrop.classList.remove('is-open'); if (clearInput) photoInput.value = ''; }
    function openCropModal(file) { const reader = new FileReader(); reader.onload = function (event) { cropImage.src = event.target.result; cropImage.onload = function () { cropScale = 260 / Math.min(cropImage.naturalWidth, cropImage.naturalHeight); zoomInput.value = 1; cropX = 0; cropY = 0; renderCropImage(); cropModal.classList.add('is-open'); cropBackdrop.classList.add('is-open'); }; }; reader.readAsDataURL(file); }
    photoInput.addEventListener('change', function () { const file = photoInput.files[0]; if (file) openCropModal(file); });
    zoomInput.addEventListener('input', function () { cropScale = (260 / Math.min(cropImage.naturalWidth, cropImage.naturalHeight)) * Number(zoomInput.value); renderCropImage(); });
    cropStage.addEventListener('pointerdown', function (event) { dragging = true; cropStage.setPointerCapture(event.pointerId); dragStartX = event.clientX - cropX; dragStartY = event.clientY - cropY; });
    cropStage.addEventListener('pointermove', function (event) { if (!dragging) return; cropX = event.clientX - dragStartX; cropY = event.clientY - dragStartY; renderCropImage(); });
    cropStage.addEventListener('pointerup', function () { dragging = false; });
    document.querySelectorAll('[data-close-profile-crop]').forEach(function (button) { button.addEventListener('click', function () { closeCropModal(true); }); }); cropBackdrop.addEventListener('click', function () { closeCropModal(true); });
    document.querySelector('[data-save-profile-crop]').addEventListener('click', function () { const canvas = document.createElement('canvas'); canvas.width = 500; canvas.height = 500; const context = canvas.getContext('2d'); const ratio = 500 / 260; context.fillStyle = '#f5f0eb'; context.fillRect(0, 0, 500, 500); context.translate(250 + cropX * ratio, 250 + cropY * ratio); context.scale(cropScale * ratio, cropScale * ratio); context.drawImage(cropImage, -cropImage.naturalWidth / 2, -cropImage.naturalHeight / 2); canvas.toBlob(function (blob) { const croppedFile = new File([blob], 'profile-photo.jpg', { type: 'image/jpeg' }); const transfer = new DataTransfer(); transfer.items.add(croppedFile); photoInput.files = transfer.files; profilePreview.innerHTML = '<img src="' + URL.createObjectURL(blob) + '" alt="Profile photo preview">'; closeCropModal(false); }, 'image/jpeg', .9); });

    // ── Add address toggle & Map Picker ───────────────────────────────────────
    const addToggle  = document.getElementById('addr-add-toggle');
    const addForm    = document.getElementById('addr-add-form');
    const addCancel  = document.getElementById('addr-add-cancel');

    // BreadBreak Store Branch Coordinates (Estrella Village, Guiguinto, Bulacan)
    const STORE_LAT = 14.830905;
    const STORE_LNG = 120.869675;
    let map = null;
    let customerMarker = null;

    function getHaversineDistanceKm(lat1, lon1, lat2, lon2) {
        const R = 6371; // Earth's radius in km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    let geocodeTimeout = null;
    async function reverseGeocode(lat, lng) {
        const zoneStatus = document.getElementById('map-zone-status');
        if (zoneStatus) {
            zoneStatus.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:var(--accent);margin-right:.3rem;"></i>Finding address details…';
        }

        try {
            const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`, {
                headers: { 'Accept-Language': 'en' }
            });
            if (!resp.ok) throw new Error('Geocoding network error');
            const data = await resp.json();
            const addr = data.address || {};

            // Extract Barangay / Village / Suburb
            const barangay = addr.quarter || addr.suburb || addr.neighbourhood || addr.village || addr.hamlet || '';
            // Extract City / Municipality / Town
            const city = addr.city || addr.town || addr.municipality || '';
            // Extract Province / State
            const province = addr.province || addr.state || 'Bulacan';
            // Extract Postal Code
            const postal = addr.postcode || '';

            // Extract Street / Road / House Number
            const roadParts = [];
            if (addr.house_number) roadParts.push(addr.house_number);
            if (addr.road || addr.pedestrian || addr.footway || addr.building) {
                roadParts.push(addr.road || addr.pedestrian || addr.footway || addr.building);
            }
            const road = roadParts.join(' ');

            // Populate form fields
            const barInput = addForm.querySelector('input[name="addr_barangay"]');
            const cityInput = addForm.querySelector('input[name="addr_city"]');
            const provInput = addForm.querySelector('input[name="addr_province"]');
            const postInput = addForm.querySelector('input[name="addr_postal"]');
            const fullTextarea = addForm.querySelector('textarea[name="addr_full"]');

            if (barInput && barangay) barInput.value = barangay;
            if (cityInput && city) cityInput.value = city;
            if (provInput && province) provInput.value = province;
            if (postInput && postal) postInput.value = postal;

            // Construct readable full address string
            const addressPieces = [];
            if (road) addressPieces.push(road);
            if (barangay) addressPieces.push('Brgy. ' + barangay);
            if (city) addressPieces.push(city);
            if (province) addressPieces.push(province);
            if (postal) addressPieces.push(postal);

            if (fullTextarea) {
                fullTextarea.value = addressPieces.length > 0 ? addressPieces.join(', ') : (data.display_name || '');
                checkAddrZone(fullTextarea.value.trim());
            }

            // Restore distance & zone status badge after geocode completes
            const distKm = getHaversineDistanceKm(STORE_LAT, STORE_LNG, lat, lng);
            updateDistanceDisplay(distKm);
        } catch (err) {
            console.warn('Reverse geocoding error:', err);
            const distKm = getHaversineDistanceKm(STORE_LAT, STORE_LNG, lat, lng);
            updateDistanceDisplay(distKm);
        }
    }

    function updateDistanceDisplay(distKm) {
        const distBadge = document.getElementById('map-distance-km');
        const statusBadge = document.getElementById('map-zone-status');

        if (distBadge) {
            distBadge.textContent = distKm.toFixed(2) + ' km away';
        }

        if (statusBadge) {
            if (distKm <= 8.0) {
                statusBadge.innerHTML = '<span style="color:#1d7044;font-weight:700;"><i class="fa-solid fa-circle-check" style="color:#28a067;margin-right:.3rem;"></i>Within 8km Delivery Zone (' + distKm.toFixed(1) + ' km)</span>';
            } else {
                statusBadge.innerHTML = '<span style="color:#c53030;font-weight:700;"><i class="fa-solid fa-circle-xmark" style="color:#e53e3e;margin-right:.3rem;"></i>Beyond 8km Delivery Zone (' + distKm.toFixed(1) + ' km)</span>';
            }
        }
    }

    function setCustomerPin(lat, lng, doGeocode = true) {
        if (!map) return;

        if (!customerMarker) {
            customerMarker = L.marker([lat, lng], {
                draggable: true,
                title: 'Your Location (Drag to adjust)'
            }).addTo(map);

            customerMarker.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                setCustomerPin(pos.lat, pos.lng, true);
            });
        } else {
            customerMarker.setLatLng([lat, lng]);
        }

        const distKm = getHaversineDistanceKm(STORE_LAT, STORE_LNG, lat, lng);
        updateDistanceDisplay(distKm);

        customerMarker.bindPopup('<b>Your Selected Pin</b><br>' + distKm.toFixed(2) + ' km from BreadBreak').openPopup();

        if (doGeocode) {
            clearTimeout(geocodeTimeout);
            geocodeTimeout = setTimeout(() => reverseGeocode(lat, lng), 300);
        }
    }

    function initAddressMap() {
        if (map || !document.getElementById('address-map') || typeof L === 'undefined') return;

        map = L.map('address-map').setView([STORE_LAT, STORE_LNG], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(map);

        // 8km delivery zone circle
        L.circle([STORE_LAT, STORE_LNG], {
            radius: 8000,
            color: '#28a067',
            fillColor: '#28a067',
            fillOpacity: 0.09,
            weight: 2,
            dashArray: '5, 5'
        }).addTo(map);

        // Store marker with bakery icon
        const storeIcon = L.divIcon({
            className: 'breadbreak-map-store-icon',
            html: '<div style="background:#c05c28;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 8px rgba(0,0,0,0.35);border:2px solid #fff;"><i class="fa-solid fa-bread-slice" style="font-size:14px;"></i></div>',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -18]
        });

        const storeMarker = L.marker([STORE_LAT, STORE_LNG], { icon: storeIcon }).addTo(map);
        storeMarker.bindPopup('<strong>BreadBreak Bakery</strong><br>Estrella Village, Guiguinto, Bulacan<br><span style="color:#28a067;font-weight:700;">8km delivery hub</span>');

        // Click on map to set pin
        map.on('click', function (e) {
            setCustomerPin(e.latlng.lat, e.latlng.lng, true);
        });

        // Locate me button
        const locateBtn = document.getElementById('btn-locate-me');
        if (locateBtn) {
            locateBtn.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    alert('Geolocation is not supported by your browser.');
                    return;
                }
                const originalHtml = locateBtn.innerHTML;
                locateBtn.disabled = true;
                locateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Locating…';

                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        locateBtn.disabled = false;
                        locateBtn.innerHTML = originalHtml;
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;
                        map.setView([lat, lng], 15);
                        setCustomerPin(lat, lng, true);
                    },
                    function (err) {
                        locateBtn.disabled = false;
                        locateBtn.innerHTML = originalHtml;
                        alert('Unable to get your GPS location. Please allow location permissions or click directly on the map to set your pin.');
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            });
        }
    }

    if (addToggle && addForm) {
        addToggle.addEventListener('click', function () {
            const open = addForm.style.display === 'none';
            addForm.style.display = open ? 'block' : 'none';
            addToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                initAddressMap();
                setTimeout(function () {
                    if (map) map.invalidateSize();
                }, 200);
                addForm.querySelector('input[name="addr_label"]')?.focus();
            }
        });
        if (addCancel) {
            addCancel.addEventListener('click', function () {
                addForm.style.display = 'none';
                addToggle.setAttribute('aria-expanded', 'false');
            });
        }

        // Zone check on full_address textarea blur
        const addrTextarea = addForm.querySelector('textarea[name="addr_full"]');
        const zoneResult   = document.getElementById('addr-zone-result');
        let zoneTimer = null;

        function showAddrZone(type, msg) {
            if (!zoneResult) return;
            zoneResult.style.display = 'flex';
            zoneResult.className = 'zone-check-result zone-' + type;
            const icons = { loading:'fa-spinner fa-spin', allowed:'fa-circle-check', blocked:'fa-circle-xmark', warning:'fa-triangle-exclamation' };
            zoneResult.innerHTML = '<i class="fa-solid ' + (icons[type]||'fa-info') + '"></i><span>' + msg + '</span>';
        }

        async function checkAddrZone(val) {
            if (!zoneResult) return;
            if (val.length < 5) { zoneResult.style.display = 'none'; return; }
            showAddrZone('loading', 'Checking delivery zone…');
            try {
                const resp = await fetch('/BreadBreak/api/delivery/check-address.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ address: val }),
                });
                const data = await resp.json();
                if (data.allowed) {
                    const fee = data.delivery_fee > 0 ? ' · ₱' + data.delivery_fee + ' delivery fee' : ' · Free delivery';
                    showAddrZone('allowed', (data.message || 'Delivery available.') + fee);
                } else {
                    showAddrZone('warning', (data.message || 'Outside delivery zone.') + ' You can still save this address, but it may not be selectable at checkout.');
                }
            } catch (e) {
                zoneResult.style.display = 'none';
            }
        }

        if (addrTextarea) {
            addrTextarea.addEventListener('input', function () {
                clearTimeout(zoneTimer);
                zoneTimer = setTimeout(() => checkAddrZone(this.value.trim()), 900);
            });
            addrTextarea.addEventListener('blur', function () {
                clearTimeout(zoneTimer);
                checkAddrZone(this.value.trim());
            });
        }
    }
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
