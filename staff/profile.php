<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'My Account';
$activePage = 'profile';
$pdo = getDatabaseConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$accountStatement = $pdo->prepare('SELECT first_name, last_name, phone, email, profile_data, profile_mime FROM users WHERE id = :id LIMIT 1');
$accountStatement->execute(['id' => $userId]);
$account = $accountStatement->fetch() ?: [];
$accountError = '';
$accountSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_account' && ($_POST['confirmation'] ?? '') === 'Delete') {
        $pdo->prepare('DELETE FROM users WHERE id = :id AND role = :role')->execute(['id' => $userId, 'role' => 'staff']);
        session_destroy();
        header('Location: /BreadBreak/login.php');
        exit;
    } elseif ($action === 'update_profile' || $action === 'update_profile_photo') {
        if ($action === 'update_profile') {
            $updatedFirstName = trim((string) ($_POST['first_name'] ?? ''));
            $updatedLastName = trim((string) ($_POST['last_name'] ?? ''));
            $updatedPhone = trim((string) ($_POST['phone'] ?? ''));
            $updatedEmail = trim((string) ($_POST['email'] ?? ''));
            $pdo->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, phone = :phone, email = :email WHERE id = :id')->execute(['first_name' => $updatedFirstName, 'last_name' => $updatedLastName, 'phone' => $updatedPhone, 'email' => $updatedEmail, 'id' => $userId]);
            $_SESSION['first_name'] = $updatedFirstName;
            $_SESSION['last_name'] = $updatedLastName;
            $_SESSION['email'] = $updatedEmail;
            $accountSuccess = 'Profile details updated successfully.';
        }
        if (($_POST['photo_action'] ?? 'upload') === 'remove') {
            $pdo->prepare('UPDATE users SET profile_data = NULL, profile_mime = NULL WHERE id = :id')->execute(['id' => $userId]);
            $accountSuccess = 'Profile photo removed.';
        } elseif (empty($_FILES['profile_photo']['name']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            if ($action === 'update_profile') $accountSuccess = 'Profile details updated successfully.';
            else $accountError = 'Please choose a profile photo to upload.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_photo']['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) $accountError = 'Only JPG, PNG, and WEBP photos are allowed.';
            elseif ((int) $_FILES['profile_photo']['size'] > 5 * 1024 * 1024) $accountError = 'Profile photo must be 5 MB or smaller.';
            else {
                $photoData = file_get_contents($_FILES['profile_photo']['tmp_name']);
                if ($photoData === false) $accountError = 'Unable to read the profile photo.';
                else {
                    $pdo->prepare('UPDATE users SET profile_data = :profile_data, profile_mime = :profile_mime WHERE id = :id')->execute(['profile_data' => $photoData, 'profile_mime' => $mime, 'id' => $userId]);
                    $accountSuccess = 'Profile photo updated successfully.';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $passwordStatement = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $passwordStatement->execute(['id' => $userId]);
        $storedPassword = (string) ($passwordStatement->fetchColumn() ?: '');
        if ($currentPassword === '' || !password_verify($currentPassword, $storedPassword)) $accountError = 'Current password is incorrect.';
        elseif (strlen($newPassword) < 8) $accountError = 'New password must be at least 8 characters.';
        elseif ($newPassword !== $confirmPassword) $accountError = 'New password and confirmation do not match.';
        elseif ($newPassword === $currentPassword) $accountError = 'New password must be different from your current password.';
        else {
            $pdo->prepare('UPDATE users SET password = :password WHERE id = :id')->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
            $accountSuccess = 'Password changed successfully.';
        }
    }
    $accountStatement->execute(['id' => $userId]);
    $account = $accountStatement->fetch() ?: [];
}

$staffInitial = strtoupper(substr(trim((string) ($account['first_name'] ?? '')), 0, 1)) ?: '?';
$profileImage = !empty($account['profile_data']) && !empty($account['profile_mime']) ? 'data:' . $account['profile_mime'] . ';base64,' . base64_encode($account['profile_data']) : '';
require __DIR__ . '/../includes/staff_header.php';
?>
<section class="page-intro"><h2>My Account</h2><p>Manage your staff account details and security.</p></section>
<?php if ($accountError): ?><p class="admin-notice danger admin-account-alert" role="alert"><?php echo htmlspecialchars($accountError); ?></p><?php endif; ?>
<?php if ($accountSuccess): ?><p class="admin-notice success admin-account-alert" role="status"><?php echo htmlspecialchars($accountSuccess); ?></p><?php endif; ?>
<section class="panel admin-account-card admin-account-single">
    <div class="admin-account-section"><span class="admin-account-kicker">Your information</span><h3>Personal Details</h3><p>These details are connected to your staff account.</p>
        <div class="admin-account-profile-photo"><form id="admin-profile-photo-form" class="admin-account-photo admin-profile-photo-form" method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="update_profile_photo" /><div class="admin-account-photo-preview"><?php if ($profileImage): ?><img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile photo preview" /><?php else: ?><span><?php echo htmlspecialchars($staffInitial); ?></span><?php endif; ?></div><div class="admin-account-photo-actions"><h3>Profile</h3><p>Add a photo so your account is easier to recognize.</p><button class="admin-button secondary" type="button" data-admin-photo-trigger><i class="fa-solid fa-camera"></i> Choose Photo</button><input id="admin-profile-photo" class="sr-only" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" /><small>JPG, PNG, or WEBP up to 5 MB.</small><button class="admin-account-remove-button" type="submit" form="staff-remove-photo-form"><i class="fa-solid fa-trash-can"></i> Remove Photo</button></div></form><?php if ($profileImage): ?><form id="staff-remove-photo-form" method="POST" class="admin-account-remove-form"><input type="hidden" name="action" value="update_profile_photo" /><input type="hidden" name="photo_action" value="remove" /></form><?php endif; ?></div>
        <div class="form-grid two admin-account-details"><div class="form-field"><label for="staff-first-name">First Name</label><input id="staff-first-name" type="text" value="<?php echo htmlspecialchars($account['first_name'] ?? ''); ?>" readonly /></div><div class="form-field"><label for="staff-last-name">Last Name</label><input id="staff-last-name" type="text" value="<?php echo htmlspecialchars($account['last_name'] ?? ''); ?>" readonly /></div><div class="form-field"><label for="staff-phone">Phone Number</label><input id="staff-phone" type="text" value="<?php echo htmlspecialchars($account['phone'] ?? ''); ?>" readonly /></div><div class="form-field"><label for="staff-email">Email</label><input id="staff-email" type="email" value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" readonly /></div></div>
        <button class="admin-button primary admin-profile-save" type="submit" form="admin-profile-photo-form" disabled>Save Profile</button>
    </div>
    <form class="admin-form admin-account-form admin-account-section staff-password-section" method="POST"><input type="hidden" name="action" value="change_password" /><h3>Password &amp; Security</h3><p>Keep your staff account password secure.</p><div class="form-grid two"><div class="form-field"><label for="staff-current-password">Current Password</label><input id="staff-current-password" type="password" name="current_password" required /></div><div class="form-field"><label for="staff-new-password">New Password</label><input id="staff-new-password" type="password" name="new_password" minlength="8" required /><div class="staff-password-strength" data-staff-password-strength><div><span>Password strength</span><strong data-staff-strength-label>Enter a password</strong></div><div class="staff-password-strength-track"><span data-staff-strength-bar></span></div><small data-staff-strength-help>Use at least 8 characters with uppercase, lowercase, number, and symbol.</small></div></div><div class="form-field"><label for="staff-confirm-password">Confirm Password</label><input id="staff-confirm-password" type="password" name="confirm_password" minlength="8" required /></div></div><div class="modal-actions"><button class="admin-button primary" type="submit"><i class="fa-solid fa-lock"></i> Change Password</button></div></form>
    <div class="admin-account-section staff-account-delete"><h3>Delete Account</h3><p>This action is permanent and cannot be undone.</p><button class="admin-button danger-button" type="button" data-staff-delete-open><i class="fa-solid fa-trash-can"></i> Delete Account</button></div>
</section>
<div class="admin-profile-crop-backdrop" data-staff-delete-backdrop></div><div class="admin-profile-crop-modal staff-delete-modal" data-staff-delete-modal role="dialog" aria-modal="true" aria-labelledby="staff-delete-title"><div class="modal-heading"><div><span class="modal-kicker">Permanent Action</span><h2 id="staff-delete-title">Delete Account</h2></div><button class="modal-close" type="button" data-staff-delete-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>This will permanently delete your staff account. This action cannot be undone.</p><form method="POST"><input type="hidden" name="action" value="delete_account" /><label class="form-field"><span>Type Delete to confirm</span><input type="text" name="confirmation" data-staff-delete-confirm required autocomplete="off" /></label><div class="modal-actions"><button class="admin-button secondary" type="button" data-staff-delete-close>Cancel</button><button class="admin-button danger-button" type="submit" data-staff-delete-submit disabled><i class="fa-solid fa-trash-can"></i> Delete Account</button></div></form></div>
<div class="admin-profile-crop-backdrop" data-admin-crop-backdrop></div><div class="admin-profile-crop-modal" data-admin-crop-modal role="dialog" aria-modal="true" aria-labelledby="admin-crop-title"><div class="modal-heading"><div><span class="modal-kicker">Profile Photo</span><h2 id="admin-crop-title">Adjust Your Photo</h2></div><button class="modal-close" type="button" data-admin-close-crop aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>Drag the photo to position it and use the slider to zoom.</p><div class="admin-profile-crop-stage" data-admin-crop-stage><img data-admin-crop-image alt="Profile photo preview" /></div><label class="admin-profile-zoom-control">Zoom<input type="range" min="1" max="3" step="0.01" value="1" data-admin-zoom /></label><div class="modal-actions"><button class="admin-button secondary" type="button" data-admin-close-crop>Cancel</button><button class="admin-button primary" type="button" data-admin-save-crop>Save Photo</button></div></div>
<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
