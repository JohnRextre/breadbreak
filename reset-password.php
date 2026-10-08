<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_tokens.php';

$pageTitle = 'Reset Password | BreadBreak';
$errors = [];

// States: form | success | invalid | expired | used
$state = 'invalid';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

try {
    $pdo = getDatabaseConnection();
    $lookup = lookupPasswordReset($pdo, $token);
    $state = $lookup['status'] === 'ok' ? 'form' : $lookup['status'];
} catch (Throwable $exception) {
    error_log('reset-password: ' . $exception->getMessage());
    $state = 'invalid';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $state === 'form') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($confirmPassword === '') {
        $errors['confirm_password'] = 'Please confirm your new password.';
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Consume first (single-use, race-safe) — a second submit fails here.
            if (!consumePasswordReset($pdo, (int) $lookup['reset_id'])) {
                $pdo->rollBack();
                $state = 'used';
            } else {
                setUserPassword($pdo, (int) $lookup['user_id'], $password);
                $pdo->commit();

                // Audit AFTER commit: ensureUserActivityLog() runs a CREATE TABLE
                // (DDL) which implicitly commits MySQL transactions — calling it
                // inside would kill the transaction and throw on commit().
                try {
                    require_once __DIR__ . '/includes/activity_log.php';
                    logUserActivity($pdo, (int) $lookup['user_id'], 'password_changed', 'Password reset via email link');
                } catch (Throwable) {
                    // Auditing is best-effort — the password is already changed.
                }
                $state = 'success';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('reset-password: ' . $exception->getMessage());
            $errors['password'] = 'Unable to save the new password right now. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="BreadBreak password reset page." />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/auth.css?v=<?php echo file_exists(__DIR__ . '/assets/css/auth.css') ? filemtime(__DIR__ . '/assets/css/auth.css') : time(); ?>" />
    <script defer src="/BreadBreak/assets/js/auth.js?v=<?php echo file_exists(__DIR__ . '/assets/js/auth.js') ? filemtime(__DIR__ . '/assets/js/auth.js') : time(); ?>"></script>
</head>
<body class="auth-body">
    <div class="auth-page">
        <div class="auth-shell">
            <header class="auth-topbar">
                <a href="/BreadBreak/index.php" class="brand" aria-label="BreadBreak home page">
                    <img src="/BreadBreak/assets/breadbreak_png/breadbreak_logo.png" alt="BreadBreak logo" class="brand-logo" width="48" height="48" />
                    <span>
                        <strong>BreadBreak</strong>
                        <small>Bakery &amp; Online Ordering</small>
                    </span>
                </a>

                <a href="/BreadBreak/index.php" class="back-home-link"><i class="fa-solid fa-arrow-left"></i> Back to Homepage</a>
            </header>

            <main class="auth-card reset-card">
                <?php if ($state === 'form'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">New Password</span>
                        <h1>Reset Your Password</h1>
                        <p>Choose a new password for your account. This link works once.</p>
                    </div>

                    <form class="auth-form" method="POST" novalidate data-form-type="reset-password">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />

                        <div class="field-group">
                            <label for="password">New Password</label>
                            <div class="input-wrap">
                                <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                                <input id="password" name="password" type="password" placeholder="At least 8 characters" autocomplete="new-password" />
                                <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                            </div>
                            <div class="error-message" data-error-for="password"><?php echo isset($errors['password']) ? htmlspecialchars($errors['password']) : ''; ?></div>
                        </div>

                        <div class="field-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <div class="input-wrap">
                                <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                                <input id="confirm_password" name="confirm_password" type="password" placeholder="Repeat the new password" autocomplete="new-password" />
                                <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                            </div>
                            <div class="error-message" data-error-for="confirm_password"><?php echo isset($errors['confirm_password']) ? htmlspecialchars($errors['confirm_password']) : ''; ?></div>
                        </div>

                        <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-key"></i> Save new password</button>
                    </form>

                <?php elseif ($state === 'success'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow" style="color: var(--auth-success);">Done</span>
                        <h1>Password Updated</h1>
                        <p>Your password has been changed. Sign in with the new one to continue.</p>
                    </div>

                    <div class="form-status success" role="status">Password changed successfully.</div>

                    <a href="/BreadBreak/login.php" class="auth-btn primary-btn" style="display: block; text-align: center; text-decoration: none; margin-top: 1rem;"><i class="fa-solid fa-right-to-bracket"></i> Sign In</a>

                <?php else: ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Link Problem</span>
                        <h1><?php echo $state === 'expired' ? 'This Link Has Expired' : ($state === 'used' ? 'Link Already Used' : 'Link Not Valid'); ?></h1>
                        <p><?php echo $state === 'expired'
                                ? 'Password reset links last 60 minutes. Request a fresh one and we’ll email it to you.'
                                : ($state === 'used'
                                    ? 'This reset link has already been used. If that wasn’t you, request a new link and change your password immediately.'
                                    : 'This reset link is invalid or has already been used.'); ?></p>
                    </div>

                    <div class="form-status error" role="status"><?php echo $state === 'expired' ? 'The link expired.' : 'The link cannot be used.'; ?></div>

                    <a href="/BreadBreak/forgot-password.php" class="auth-btn primary-btn" style="display: block; text-align: center; text-decoration: none; margin-top: 1rem;"><i class="fa-solid fa-paper-plane"></i> Request a new link</a>
                <?php endif; ?>

                <div class="auth-footer condensed">
                    <a href="/BreadBreak/login.php" class="auth-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
