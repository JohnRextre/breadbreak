<?php
/**
 * Rider account — Personal details, Password & Security, Delete Account.
 *
 * Mirrors what staff/profile.php offers, but rendered inside the rider portal's
 * own chrome. The topbar's profile menu links here (with #personal-details,
 * #password-security, or ?panel=delete to open the confirmation dialog).
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('rider');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$pdo = getDatabaseConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$accountStatement = $pdo->prepare(
    'SELECT first_name, last_name, phone, email, profile_data, profile_mime
     FROM users WHERE id = :id AND role = :role LIMIT 1'
);
$accountStatement->execute(['id' => $userId, 'role' => 'rider']);
$account = $accountStatement->fetch() ?: [];
if (!$account) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$accountError = '';
$accountSuccess = '';
$passwordError = '';
$passwordSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // Irreversible — only on the exact word, and only for this rider's row.
    if ($action === 'delete_account' && (string) ($_POST['confirmation'] ?? '') === 'Delete') {
        $pdo->prepare('DELETE FROM users WHERE id = :id AND role = :role')
            ->execute(['id' => $userId, 'role' => 'rider']);
        $_SESSION = [];
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if ($action === 'update_profile_photo') {
        if (($_POST['photo_action'] ?? '') === 'remove') {
            $pdo->prepare('UPDATE users SET profile_data = NULL, profile_mime = NULL WHERE id = :id')
                ->execute(['id' => $userId]);
            logUserActivity($pdo, $userId, 'photo_removed', 'Removed the profile photo');
            $accountSuccess = 'Profile photo removed.';
        } elseif (empty($_FILES['profile_photo']['name']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $accountError = 'Please choose a profile photo to upload.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_photo']['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $accountError = 'Only JPG, PNG, and WEBP photos are allowed.';
            } elseif ((int) $_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
                $accountError = 'Profile photo must be 5 MB or smaller.';
            } else {
                $photoData = file_get_contents($_FILES['profile_photo']['tmp_name']);
                if ($photoData === false) {
                    $accountError = 'Unable to read the profile photo.';
                } else {
                    $pdo->prepare('UPDATE users SET profile_data = :profile_data, profile_mime = :profile_mime WHERE id = :id')
                        ->execute(['profile_data' => $photoData, 'profile_mime' => $mime, 'id' => $userId]);
                    logUserActivity($pdo, $userId, 'photo_updated', 'Uploaded a new profile photo');
                    $accountSuccess = 'Profile photo updated successfully.';
                }
            }
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $passwordStatement = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $passwordStatement->execute(['id' => $userId]);
        $storedPassword = (string) ($passwordStatement->fetchColumn() ?: '');
        if ($currentPassword === '' || !password_verify($currentPassword, $storedPassword)) {
            $passwordError = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordError = 'New password and confirmation do not match.';
        } elseif ($newPassword === $currentPassword) {
            $passwordError = 'New password must be different from your current password.';
        } else {
            $pdo->prepare('UPDATE users SET password = :password WHERE id = :id')
                ->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
            logUserActivity($pdo, $userId, 'password_changed', 'Changed the account password');
            $passwordSuccess = 'Password changed successfully.';
        }
    }

    $accountStatement->execute(['id' => $userId, 'role' => 'rider']);
    $account = $accountStatement->fetch() ?: [];
}

$riderInitial = strtoupper(substr(trim((string) ($account['first_name'] ?? '')), 0, 1)) ?: '?';
$profileImage = !empty($account['profile_data']) && !empty($account['profile_mime'])
    ? 'data:' . $account['profile_mime'] . ';base64,' . base64_encode($account['profile_data'])
    : '';
$openDelete = ($_GET['panel'] ?? '') === 'delete';

$pageTitle = 'My Account | BreadBreak';
require __DIR__ . '/../includes/rider_header.php';
?>

<section class="rider-hero is-history">
    <div class="rider-hero-copy">
        <span class="rider-chip">Account settings</span>
        <h1>My Account</h1>
        <p>Manage your rider account details and security.</p>
        <a class="rider-back" href="<?php echo BASE_URL; ?>/rider/dashboard.php">
            <i class="fa-solid fa-arrow-left"></i> Back to dashboard
        </a>
    </div>
</section>

<?php if ($accountError): ?>
    <div class="rider-notice danger"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($accountError); ?></div>
<?php endif; ?>
<?php if ($accountSuccess): ?>
    <div class="rider-notice success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($accountSuccess); ?></div>
<?php endif; ?>
<?php if ($passwordError): ?>
    <div class="rider-notice danger"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($passwordError); ?></div>
<?php endif; ?>
<?php if ($passwordSuccess): ?>
    <div class="rider-notice success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($passwordSuccess); ?></div>
<?php endif; ?>

<nav class="rider-acct-tabs" aria-label="Account sections">
    <a href="#personal-details"><i class="fa-solid fa-user"></i> Personal details</a>
    <a href="#password-security"><i class="fa-solid fa-lock"></i> Password &amp; Security</a>
    <a href="#delete-account" class="is-danger"><i class="fa-solid fa-trash-can"></i> Delete Account</a>
</nav>

<!-- ── Personal details ─────────────────────────────────────────────────── -->
<section class="rider-acct-card" id="personal-details">
    <span class="rider-acct-kicker">Your information</span>
    <h2>Personal Details</h2>
    <p class="rider-acct-lead">These details are connected to your rider account.</p>

    <div class="rider-acct-photo">
        <div class="rider-acct-photo-preview">
            <?php if ($profileImage): ?>
                <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile photo" />
            <?php else: ?>
                <span><?php echo htmlspecialchars($riderInitial); ?></span>
            <?php endif; ?>
        </div>
        <div class="rider-acct-photo-actions">
            <strong>Profile</strong>
            <p>Add a photo so your account is easier to recognize.</p>
            <form id="rider-photo-form" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile_photo" />
                <label class="rider-btn outline" for="rider-photo-input">
                    <i class="fa-solid fa-camera"></i> Choose Photo
                </label>
                <input id="rider-photo-input" class="sr-only" type="file" name="profile_photo"
                       accept="image/jpeg,image/png,image/webp" onchange="this.form.submit();" />
                <small>JPG, PNG, or WEBP up to 5 MB.</small>
            </form>
            <?php if ($profileImage): ?>
                <form method="POST" class="rider-acct-remove-form">
                    <input type="hidden" name="action" value="update_profile_photo" />
                    <input type="hidden" name="photo_action" value="remove" />
                    <button class="rider-acct-remove" type="submit"><i class="fa-solid fa-trash-can"></i> Remove Photo</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="rider-acct-grid">
        <label class="rider-acct-field">First Name<input type="text" value="<?php echo htmlspecialchars($account['first_name'] ?? ''); ?>" readonly /></label>
        <label class="rider-acct-field">Last Name<input type="text" value="<?php echo htmlspecialchars($account['last_name'] ?? ''); ?>" readonly /></label>
        <label class="rider-acct-field">Phone Number<input type="text" value="<?php echo htmlspecialchars($account['phone'] ?? ''); ?>" readonly /></label>
        <label class="rider-acct-field">Email<input type="email" value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" readonly /></label>
    </div>
</section>

<!-- ── Password & Security ─────────────────────────────────────────────── -->
<section class="rider-acct-card" id="password-security">
    <span class="rider-acct-kicker">Account protection</span>
    <h2>Password &amp; Security</h2>
    <p class="rider-acct-lead">Update your password regularly to keep your account protected.</p>

    <form method="POST" class="rider-acct-form" data-password-form>
        <input type="hidden" name="action" value="change_password" />
        <div class="rider-acct-grid">
            <label class="rider-acct-field">Current Password
                <input type="password" name="current_password" placeholder="Enter current password" required />
            </label>
            <label class="rider-acct-field">New Password
                <input type="password" name="new_password" minlength="8" placeholder="Enter new password" required
                       data-new-password />
                <div class="rider-strength" aria-live="polite">
                    <div class="rider-strength-head">
                        <span>Password strength</span>
                        <strong data-strength-label>Enter a password</strong>
                    </div>
                    <div class="rider-strength-track"><span data-strength-bar></span></div>
                    <small>Use at least 8 characters with uppercase, lowercase, number, and symbol.</small>
                </div>
            </label>
            <label class="rider-acct-field">Confirm Password
                <input type="password" name="confirm_password" minlength="8" placeholder="Confirm new password" required
                       data-confirm-password />
                <small class="rider-acct-match" data-password-match></small>
            </label>
        </div>
        <div class="rider-acct-actions">
            <button class="rider-btn primary" type="submit"><i class="fa-solid fa-lock"></i> Change Password</button>
        </div>
    </form>
</section>

<!-- ── Delete Account ───────────────────────────────────────────────────── -->
<section class="rider-acct-card is-danger" id="delete-account">
    <span class="rider-acct-kicker">Permanent action</span>
    <h2>Delete Account</h2>
    <p class="rider-acct-lead">This action is permanent and cannot be undone. Trips you already closed stay in the shop's records, but you will lose access to this rider account.</p>
    <div class="rider-acct-actions">
        <button class="rider-btn is-danger" type="button" data-rider-delete-open>
            <i class="fa-solid fa-trash-can"></i> Delete Account
        </button>
    </div>
</section>

<div class="rider-modal<?php echo $openDelete ? ' is-open' : ''; ?>" id="rider-delete-modal" role="dialog"
     aria-modal="true" aria-labelledby="rider-delete-title" hidden>
    <div class="rider-modal-backdrop" data-rider-delete-close></div>
    <div class="rider-modal-card">
        <header class="rider-modal-head">
            <div>
                <span class="rider-acct-kicker">Permanent action</span>
                <h2 id="rider-delete-title">Delete Account</h2>
            </div>
            <button class="rider-modal-close" type="button" data-rider-delete-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>
        <p>This will permanently delete your rider account. This action cannot be undone.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete_account" />
            <label class="rider-acct-field">
                Confirmation
                <input type="text" name="confirmation" placeholder="Type 'Delete' to confirm"
                       autocomplete="off" data-rider-delete-confirm />
            </label>
            <div class="rider-modal-actions">
                <button class="rider-btn outline" type="button" data-rider-delete-close>Cancel</button>
                <button class="rider-btn is-danger" type="submit" data-rider-delete-submit disabled>
                    <i class="fa-solid fa-trash-can"></i> Delete Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ── Delete confirmation dialog ────────────────────────────────────────
    var modal = document.getElementById('rider-delete-modal');
    var input = modal.querySelector('[data-rider-delete-confirm]');
    var submit = modal.querySelector('[data-rider-delete-submit]');
    var openModal = function () { modal.hidden = false; modal.classList.add('is-open'); input.focus(); };
    var closeModal = function () { modal.hidden = true; modal.classList.remove('is-open'); };

    document.querySelectorAll('[data-rider-delete-open]').forEach(function (button) {
        button.addEventListener('click', openModal);
    });
    modal.querySelectorAll('[data-rider-delete-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
    input.addEventListener('input', function () { submit.disabled = input.value !== 'Delete'; });
    <?php if ($openDelete): ?>
    openModal();
    <?php endif; ?>

    // ── Password strength + match hint ───────────────────────────────────
    var newPassword = document.querySelector('[data-new-password]');
    var confirmPassword = document.querySelector('[data-confirm-password]');
    var label = document.querySelector('[data-strength-label]');
    var bar = document.querySelector('[data-strength-bar]');
    var match = document.querySelector('[data-password-match]');
    if (newPassword && label && bar) {
        var score = function (value) {
            var points = 0;
            if (value.length >= 8) points++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) points++;
            if (/\d/.test(value)) points++;
            if (/[^A-Za-z0-9]/.test(value)) points++;
            if (value.length >= 12) points++;
            return Math.min(points, 4);
        };
        var levels = [
            ['Enter a password', 0, '#c9c1ba'],
            ['Weak', 25, '#d9534f'],
            ['Fair', 50, '#d9932f'],
            ['Good', 75, '#2f9e63'],
            ['Strong', 100, '#1f9d63']
        ];
        newPassword.addEventListener('input', function () {
            var level = levels[score(newPassword.value)];
            label.textContent = level[0];
            bar.style.width = level[1] + '%';
            bar.style.background = level[2];
        });
    }
    if (newPassword && confirmPassword && match) {
        var checkMatch = function () {
            if (!confirmPassword.value) { match.textContent = ''; return; }
            match.textContent = confirmPassword.value === newPassword.value
                ? 'Passwords match.'
                : 'Passwords do not match yet.';
            match.classList.toggle('is-ok', confirmPassword.value === newPassword.value);
        };
        confirmPassword.addEventListener('input', checkMatch);
        newPassword.addEventListener('input', checkMatch);
    }
})();
</script>

<?php require __DIR__ . '/../includes/rider_footer.php'; ?>
