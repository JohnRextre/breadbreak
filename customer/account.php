<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('customer');
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();
$profileColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_data'")->fetchColumn();
if (!$profileColumn) $pdo->exec("ALTER TABLE users ADD COLUMN profile_data MEDIUMBLOB NULL AFTER password, ADD COLUMN profile_mime VARCHAR(50) NULL AFTER profile_data");
$accountStatement = $pdo->prepare('SELECT first_name, last_name, phone, email, profile_data, profile_mime FROM users WHERE id = :id LIMIT 1');
$accountStatement->execute(['id' => (int) ($_SESSION['user_id'] ?? 0)]);
$account = $accountStatement->fetch() ?: [];
$profileError = '';
$profileSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile_photo') {
    $photoAction = $_POST['photo_action'] ?? 'upload';
    if ($photoAction === 'remove') {
        $pdo->prepare('UPDATE users SET profile_data = NULL, profile_mime = NULL WHERE id = :id')->execute(['id' => (int) $_SESSION['user_id']]);
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
            else { $pdo->prepare('UPDATE users SET profile_data = :profile_data, profile_mime = :profile_mime WHERE id = :id')->execute(['profile_data' => $photoData, 'profile_mime' => $mime, 'id' => (int) $_SESSION['user_id']]); $profileSuccess = 'Profile photo updated successfully.'; }
        }
    }
    $accountStatement->execute(['id' => (int) ($_SESSION['user_id'] ?? 0)]);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $passwordPanelActive = true;
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $passwordStatement = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
    $passwordStatement->execute(['id' => (int) ($_SESSION['user_id'] ?? 0)]);
    $storedPassword = (string) ($passwordStatement->fetchColumn() ?: '');
    if ($currentPassword === '' || !password_verify($currentPassword, $storedPassword)) $passwordError = 'Current password is incorrect.';
    elseif (accountPasswordStrength($newPassword) < 2) $passwordError = 'New password must be at least Medium strength.';
    elseif ($newPassword !== $confirmPassword) $passwordError = 'New password and confirmation do not match.';
    elseif ($newPassword === $currentPassword) $passwordError = 'New password must be different from your current password.';
    else {
        $updatePassword = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
        $updatePassword->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => (int) ($_SESSION['user_id'] ?? 0)]);
        $passwordSuccess = 'Password changed successfully.';
    }
}

$pageTitle = 'My Account | BreadBreak';
$fullName = trim(($account['first_name'] ?? $_SESSION['first_name'] ?? '') . ' ' . ($account['last_name'] ?? $_SESSION['last_name'] ?? ''));
$accountSections = [
    ['id' => 'personal-details', 'icon' => 'fa-user', 'title' => 'Personal Details', 'description' => 'Manage your name and contact information.'],
    ['id' => 'account-activities', 'icon' => 'fa-clock-rotate-left', 'title' => 'Account Activities', 'description' => 'Review activity from your BreadBreak account.', 'disabled' => true],
    ['id' => 'password-security', 'icon' => 'fa-lock', 'title' => 'Password & Security', 'description' => 'Keep your account password secure.'],
];
require __DIR__ . '/../includes/header.php';
?>
<main class="customer-account-page">
    <section class="customer-account-hero"><div class="container"><a class="account-back-link" href="/BreadBreak/customer/menu_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Menu</a><span class="eyebrow">Account Settings</span><h1>My Account</h1><p>Manage your BreadBreak account details and security.</p></div></section>
    <section class="section customer-account-content"><div class="container customer-account-layout">
        <aside class="customer-account-sidebar"><div class="account-sidebar-profile"><div class="customer-profile-avatar"><i class="fa-solid fa-user"></i></div><div><strong><?php echo htmlspecialchars($fullName ?: 'Customer'); ?></strong><span>Customer</span></div></div><nav class="account-section-nav" aria-label="Account sections">
            <?php foreach ($accountSections as $section): ?><button type="button" class="account-section-link<?php echo $section['id'] === 'personal-details' ? ' active' : ''; ?><?php echo !empty($section['disabled']) ? ' is-disabled' : ''; ?>" data-account-section="<?php echo $section['id']; ?>" <?php echo !empty($section['disabled']) ? 'disabled' : ''; ?>><i class="fa-solid <?php echo $section['icon']; ?>"></i><span><?php echo $section['title']; ?><small><?php echo $section['description']; ?></small></span><?php if (!empty($section['disabled'])): ?><em>Soon</em><?php endif; ?></button><?php endforeach; ?>
        </nav><button class="account-delete-link" type="button" data-open-delete-account><i class="fa-solid fa-trash-can"></i> Delete Account</button></aside>
        <div class="customer-account-panels">
            <section class="account-panel<?php echo !$passwordPanelActive ? ' is-active' : ''; ?>" id="personal-details" data-account-panel><div class="account-panel-heading"><div><span class="eyebrow">Your information</span><h2>Personal Details</h2><p>These details are connected to your customer account.</p></div></div><?php if ($profileError): ?><p class="account-form-error" role="alert"><?php echo htmlspecialchars($profileError); ?></p><?php endif; ?><?php if ($profileSuccess): ?><p class="account-form-success" role="status"><?php echo htmlspecialchars($profileSuccess); ?></p><?php endif; ?><form class="account-form profile-photo-form" method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="update_profile_photo" /><div class="profile-photo-editor"><div class="profile-photo-preview"><?php if ($profileImage): ?><img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile photo" /><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?></div><div><strong>Profile</strong><p>Add a photo so your account is easier to recognize.</p><label class="profile-upload-button" for="profile-photo-input"><i class="fa-solid fa-camera"></i> Choose Photo</label><input id="profile-photo-input" class="sr-only" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" /><small class="profile-photo-help">JPG, PNG, or WEBP up to 5 MB.</small></div></div><div class="account-form-grid"><label>First Name<input type="text" value="<?php echo htmlspecialchars($account['first_name'] ?? ''); ?>" readonly /></label><label>Last Name<input type="text" value="<?php echo htmlspecialchars($account['last_name'] ?? ''); ?>" readonly /></label><label>Phone Number<input type="text" value="<?php echo htmlspecialchars($account['phone'] ?? ''); ?>" readonly /></label><label>Email<input type="email" value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" readonly /></label></div><button class="account-save-button profile-save-button" type="submit">Save Profile Photo</button></form><?php if ($profileImage): ?><form class="profile-remove-form" method="POST"><input type="hidden" name="action" value="update_profile_photo" /><input type="hidden" name="photo_action" value="remove" /><button class="profile-remove-button" type="submit"><i class="fa-solid fa-trash-can"></i> Remove Photo</button></form><?php endif; ?></section>
            <section class="account-panel<?php echo $passwordPanelActive ? ' is-active' : ''; ?>" id="password-security" data-account-panel><div class="account-panel-heading"><div><span class="eyebrow">Account protection</span><h2>Password &amp; Security</h2><p>Update your password regularly to keep your account protected.</p></div></div><?php if ($passwordError): ?><p class="account-form-error" role="alert"><?php echo htmlspecialchars($passwordError); ?></p><?php endif; ?><form class="account-form" method="POST" data-password-form><input type="hidden" name="action" value="change_password" /><label>Current Password<div class="account-password-field"><input type="password" name="current_password" data-current-password placeholder="Enter current password" required /><button type="button" data-toggle-account-password aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div></label><label>New Password<div class="account-password-field"><input type="password" name="new_password" data-new-password placeholder="Enter new password" required /><button type="button" data-toggle-account-password aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><div class="password-strength" aria-live="polite"><div class="password-strength-label"><span>Password strength</span><strong data-password-strength-label>Enter a password</strong></div><div class="password-strength-track"><span data-password-strength-bar></span></div><small data-password-strength-help>Use at least 8 characters with uppercase, lowercase, number, and symbol.</small></div></label><label>Confirm Password<div class="account-password-field"><input type="password" name="confirm_password" data-confirm-password placeholder="Confirm new password" required /><button type="button" data-toggle-account-password aria-label="Show password"><i class="fa-solid fa-eye"></i></button></div><small class="password-match-message" data-password-match></small></label><button class="account-save-button" type="submit" disabled>Change Password</button><p class="account-form-note" data-password-form-message><?php echo htmlspecialchars($passwordSuccess); ?></p></form></section>
        </div>
    </div></section>
</main>
<div class="account-modal-backdrop" data-account-backdrop></div><div class="account-delete-modal" id="delete-account-modal" role="dialog" aria-modal="true" aria-labelledby="delete-account-title"><div class="account-modal-heading"><div><span class="eyebrow warning-eyebrow">Permanent Action</span><h2 id="delete-account-title">Delete Account</h2></div><button class="account-modal-close" type="button" data-close-delete-account aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>Are you sure you want to delete this account? This action is irreversible.</p><label class="account-delete-confirmation">Confirmation<input type="text" data-delete-account-input placeholder="Type 'Delete' to confirm" autocomplete="off" /></label><div class="account-modal-actions"><button class="account-cancel-button" type="button" data-close-delete-account>Cancel</button><button class="account-danger-button" type="button" data-delete-account-button disabled><i class="fa-solid fa-trash-can"></i> Delete Account</button></div></div>
<div class="profile-crop-backdrop" data-profile-crop-backdrop></div><div class="profile-crop-modal" data-profile-crop-modal role="dialog" aria-modal="true" aria-labelledby="profile-crop-title"><div class="account-modal-heading"><div><span class="eyebrow">Profile Photo</span><h2 id="profile-crop-title">Adjust Your Photo</h2></div><button class="account-modal-close" type="button" data-close-profile-crop aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>Drag the photo to position it and use the slider to zoom.</p><div class="profile-crop-stage" data-profile-crop-stage><img data-profile-crop-image alt="Profile photo preview" /></div><label class="profile-zoom-control">Zoom<input type="range" min="1" max="3" step="0.01" value="1" data-profile-zoom /></label><div class="account-modal-actions"><button class="account-cancel-button" type="button" data-close-profile-crop>Cancel</button><button class="account-danger-button profile-crop-save" type="button" data-save-profile-crop>Save Photo</button></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const panels = document.querySelectorAll('[data-account-panel]');
    document.querySelectorAll('[data-account-section]').forEach(function (button) { button.addEventListener('click', function () { document.querySelectorAll('[data-account-section]').forEach(function (item) { item.classList.remove('active'); }); panels.forEach(function (panel) { panel.classList.toggle('is-active', panel.id === button.dataset.accountSection); }); button.classList.add('active'); }); });
    document.querySelectorAll('[data-toggle-account-password]').forEach(function (button) { button.addEventListener('click', function () { const input = button.previousElementSibling; input.type = input.type === 'password' ? 'text' : 'password'; button.querySelector('i').classList.toggle('fa-eye'); button.querySelector('i').classList.toggle('fa-eye-slash'); }); });
    const passwordForm = document.querySelector('[data-password-form]');
    if (passwordForm) {
        const currentPassword = passwordForm.querySelector('[data-current-password]'); const newPassword = passwordForm.querySelector('[data-new-password]'); const confirmPassword = passwordForm.querySelector('[data-confirm-password]'); const strengthLabel = passwordForm.querySelector('[data-password-strength-label]'); const strengthBar = passwordForm.querySelector('[data-password-strength-bar]'); const strengthHelp = passwordForm.querySelector('[data-password-strength-help]'); const matchMessage = passwordForm.querySelector('[data-password-match]'); const changeButton = passwordForm.querySelector('.account-save-button'); const formMessage = passwordForm.querySelector('[data-password-form-message]');
        function passwordStrength(value) { let score = 0; if (value.length >= 8) score++; if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++; if (/\d/.test(value)) score++; if (/[^A-Za-z0-9]/.test(value)) score++; return value === '' ? 0 : score <= 1 ? 1 : score <= 3 ? 2 : 3; }
        function updatePasswordState() { const strength = passwordStrength(newPassword.value); const labels = ['Enter a password', 'Weak', 'Medium', 'Strong']; const classes = ['', 'weak', 'medium', 'strong']; strengthLabel.textContent = labels[strength]; strengthLabel.className = classes[strength]; strengthBar.className = classes[strength]; strengthBar.style.width = (strength * 33.33) + '%'; strengthHelp.textContent = strength === 3 ? 'Strong password.' : 'Use at least 8 characters with uppercase, lowercase, number, and symbol.'; if (!confirmPassword.value) { matchMessage.textContent = ''; matchMessage.className = 'password-match-message'; } else if (newPassword.value === confirmPassword.value) { matchMessage.textContent = 'Passwords match.'; matchMessage.className = 'password-match-message matches'; } else { matchMessage.textContent = 'Passwords do not match.'; matchMessage.className = 'password-match-message does-not-match'; } changeButton.disabled = !(currentPassword.value && strength >= 2 && newPassword.value === confirmPassword.value); }
        passwordForm.querySelectorAll('input').forEach(function (input) { input.addEventListener('input', updatePasswordState); });
    }
    const modal = document.getElementById('delete-account-modal'); const backdrop = document.querySelector('[data-account-backdrop]'); const input = document.querySelector('[data-delete-account-input]'); const deleteButton = document.querySelector('[data-delete-account-button]');
    function closeDeleteModal() { modal.classList.remove('is-open'); backdrop.classList.remove('is-open'); }
    document.querySelector('[data-open-delete-account]').addEventListener('click', function () { modal.classList.add('is-open'); backdrop.classList.add('is-open'); input.focus(); });
    document.querySelectorAll('[data-close-delete-account]').forEach(function (button) { button.addEventListener('click', closeDeleteModal); }); backdrop.addEventListener('click', closeDeleteModal);
    input.addEventListener('input', function () { deleteButton.disabled = input.value !== 'Delete'; });
    deleteButton.addEventListener('click', function () { deleteButton.textContent = 'Coming Soon'; deleteButton.disabled = true; });
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
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
