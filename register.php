<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/auth_tokens.php';

$pageTitle = 'Create Account | BreadBreak';
$errors = [];
$successMessage = '';
$successTitle = '';
$modalTone = 'success';
$reopenLabel = 'Didn\'t see the verification email? Open the resend panel';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Resend verification (from the success panel below) ──────────────────
    if (($_POST['resend_verification'] ?? '') === '1') {
        $resendEmail = trim($_POST['email'] ?? '');
        if ($resendEmail === '' || !filter_var($resendEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['resend'] = 'Please enter a valid email address.';
            $modalTone = 'error';
        } else {
            try {
                $pdo = getDatabaseConnection();
                $resendStmt = $pdo->prepare('SELECT id, first_name, email, email_verified_at FROM users WHERE email = :email LIMIT 1');
                $resendStmt->execute(['email' => $resendEmail]);
                $resendUser = $resendStmt->fetch();

                if (!$resendUser || !emailIsUnverified($resendUser)) {
                    // Generic — never reveals whether the address exists or is already verified.
                    $successTitle = 'Check your inbox';
                    $successMessage = 'If that email still needs verification, a new link is on its way. Check your inbox and spam folder.';
                } elseif (($waitMessage = verificationResendBlocked($pdo, (int) $resendUser['id'])) !== null) {
                    $errors['resend'] = $waitMessage;
                    $modalTone = 'error';
                } else {
                    $token = issueEmailVerification($pdo, (int) $resendUser['id']);
                    $sent = sendVerificationEmail($resendUser['email'], (string) $resendUser['first_name'], appUrl('verify-email.php?token=' . $token));
                    $successTitle = 'Check your inbox';
                    $successMessage = $sent
                        ? 'Verification email sent again — check your inbox and spam folder.'
                        : 'We couldn’t send the email right now. Please wait a minute and try Resend again.';
                    if (!$sent) {
                        $modalTone = 'error';
                    }
                }
            } catch (Throwable) {
                $errors['resend'] = 'Unable to resend right now. Please try again in a moment.';
                $modalTone = 'error';
            }
        }
    } else {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    }

    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    }

    if ($phone === '') {
        $errors['phone'] = 'Phone number is required.';
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($confirmPassword === '') {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $pdo = getDatabaseConnection();
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $checkStmt->execute(['email' => $email]);

            if ($checkStmt->fetch()) {
                // Already registered — guide instead of a dead end. The modal
                // offers Sign In plus the resend form; the wording stays generic
                // so it never reveals whether the address is verified.
                $successTitle = 'Already have an account?';
                $successMessage = 'This email is already registered. If you just signed up, the confirmation email can take a few minutes — check your Spam, Junk, or Promotions folder, then resend it below. Already verified? Sign in to continue.';
                $modalTone = 'info';
                $reopenLabel = 'Already registered? Open sign-in and resend options';
            } else {
                // Created UNVERIFIED — sign-in unlocks only after the email link is clicked.
                $verificationToken = randomToken();
                $insertStmt = $pdo->prepare(
                    'INSERT INTO users (first_name, last_name, phone, email, password, role, status,
                                        verification_token_hash, verification_expires_at, verification_sent_at)
                     VALUES (:first_name, :last_name, :phone, :email, :password, :role, :status,
                             :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())'
                );
                $insertStmt->execute([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'customer',
                    'status' => 'active',
                    'token_hash' => hashToken($verificationToken),
                ]);

                $verificationSent = sendVerificationEmail(
                    $email,
                    $firstName,
                    appUrl('verify-email.php?token=' . $verificationToken)
                );
                $successTitle = 'Account created!';
                $successMessage = $verificationSent
                    ? 'Check ' . $email . ' for your verification link — you can sign in once your email is verified.'
                    : 'We couldn’t send the verification email right now. Please wait a minute and use the Resend button below.';
                if (!$verificationSent) {
                    $modalTone = 'error';
                }

                // Welcome treat: one-time Free Delivery voucher, valid 30 days.
                try {
                    require_once __DIR__ . '/includes/vouchers.php';
                    $newCustomerId = (int) $pdo->lastInsertId();
                    if ($newCustomerId > 0) {
                        $welcomeVoucherId = ensureWelcomeVoucher($pdo);
                        if (grantVoucherToCustomer($pdo, $welcomeVoucherId, $newCustomerId, 'welcome', date('Y-m-d H:i:s', strtotime('+30 days')))) {
                            $successMessage .= ' You also received a FREE DELIVERY welcome voucher — valid for 30 days on your first order.';
                        }
                    }
                } catch (Throwable) {
                    // Voucher grant is a bonus — never block a successful signup.
                }
            }
        } catch (Throwable $e) {
            $errors['database'] = 'Unable to create the account right now.';
        }
    }
    } // end normal-registration branch
}

// The success / resend panel renders as a floating modal overlay.
$showModal = !empty($successMessage) || isset($errors['resend']);
if ($showModal && $successTitle === '') {
    $successTitle = !empty($successMessage) ? 'Check your inbox' : 'Resend verification email';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="BreadBreak customer registration page." />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/auth.css?v=<?php echo file_exists(__DIR__ . '/assets/css/auth.css') ? filemtime(__DIR__ . '/assets/css/auth.css') : time(); ?>" />
    <script defer src="/BreadBreak/assets/js/auth.js?v=<?php echo file_exists(__DIR__ . '/assets/js/auth.js') ? filemtime(__DIR__ . '/assets/js/auth.js') : time(); ?>"></script>
</head>
<body class="auth-body">
    <div class="auth-page">
        <div class="auth-shell wide-shell">
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

            <main class="auth-card register-card">
                <div class="auth-heading">
                    <span class="eyebrow text-accent">Join the Bakery</span>
                    <h1>Create Your Account</h1>
                    <p>Create your BreadBreak Customer account and start ordering your favorite bakery products.</p>
                </div>

                <form class="auth-form" method="POST" novalidate data-form-type="register">
                    <div class="field-row two-columns">
                        <div class="field-group">
                            <label for="first_name">First Name</label>
                            <input id="first_name" name="first_name" type="text" placeholder="Enter your first name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" aria-invalid="false" />
                            <div class="error-message" data-error-for="first_name"><?php echo isset($errors['first_name']) ? htmlspecialchars($errors['first_name']) : ''; ?></div>
                        </div>

                        <div class="field-group">
                            <label for="last_name">Last Name</label>
                            <input id="last_name" name="last_name" type="text" placeholder="Enter your last name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" aria-invalid="false" />
                            <div class="error-message" data-error-for="last_name"><?php echo isset($errors['last_name']) ? htmlspecialchars($errors['last_name']) : ''; ?></div>
                        </div>
                    </div>

                    <div class="field-row two-columns">
                        <div class="field-group">
                            <label for="phone">Phone Number</label>
                            <input id="phone" name="phone" type="tel" placeholder="Enter your phone number" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" aria-invalid="false" />
                            <div class="error-message" data-error-for="phone"><?php echo isset($errors['phone']) ? htmlspecialchars($errors['phone']) : ''; ?></div>
                        </div>

                        <div class="field-group">
                            <label for="email">Email Address</label>
                            <input id="email" name="email" type="email" placeholder="Enter your email address" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" aria-invalid="false" />
                            <div class="error-message" data-error-for="email"><?php echo isset($errors['email']) ? htmlspecialchars($errors['email']) : ''; ?></div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label for="password">Password</label>
                        <div class="input-wrap password-wrap">
                            <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                            <input id="password" name="password" type="password" placeholder="Create a password" aria-invalid="false" />
                            <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <div class="error-message" data-error-for="password"><?php echo isset($errors['password']) ? htmlspecialchars($errors['password']) : ''; ?></div>
                        <div class="password-strength" aria-live="polite">Password strength: <span id="strengthText">-</span></div>
                    </div>

                    <div class="field-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrap password-wrap">
                            <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                            <input id="confirm_password" name="confirm_password" type="password" placeholder="Confirm your password" aria-invalid="false" />
                            <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <div class="error-message" data-error-for="confirm_password"><?php echo isset($errors['confirm_password']) ? htmlspecialchars($errors['confirm_password']) : ''; ?></div>
                    </div>

                    <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-user-plus"></i> Create Account</button>

                    <?php if (!empty($errors['database'])): ?>
                        <div class="form-status success" aria-live="polite" style="background: rgba(181, 51, 44, 0.1); border-color: rgba(181, 51, 44, 0.2); color: #b5332c;">
                            <?php echo htmlspecialchars($errors['database']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showModal): ?>
                        <button type="button" class="resend-reopen" id="resendReopen" hidden>
                            <i class="fa-solid fa-envelope-circle-check"></i> <?php echo htmlspecialchars($reopenLabel); ?>
                        </button>
                    <?php endif; ?>
                </form>

                <div class="auth-footer">
                    <p>Already have an account?</p>
                    <a href="/BreadBreak/login.php" class="auth-link">Sign In</a>
                </div>
            </main>
        </div>
    </div>

    <?php if ($showModal): ?>
        <div class="auth-modal-overlay" id="authModalOverlay">
            <div class="auth-modal<?php echo $modalTone === 'error' ? ' is-error' : ($modalTone === 'info' ? ' is-info' : ''); ?>" role="dialog" aria-modal="true" aria-labelledby="authModalTitle">
                <button type="button" class="auth-modal-close" id="authModalClose" aria-label="Close dialog">
                    <i class="fa-solid fa-xmark"></i>
                </button>

                <div class="auth-modal-icon" aria-hidden="true">
                    <i class="fa-solid <?php
                        echo $modalTone === 'error'
                            ? 'fa-triangle-exclamation'
                            : ($modalTone === 'info' ? 'fa-circle-info' : 'fa-envelope-circle-check');
                    ?>"></i>
                </div>

                <h2 id="authModalTitle"><?php echo htmlspecialchars($successTitle); ?></h2>

                <?php if (!empty($successMessage)): ?>
                    <p class="auth-modal-msg" aria-live="polite"><?php echo htmlspecialchars($successMessage); ?></p>
                <?php endif; ?>

                <?php if (!empty($successMessage)): ?>
                    <a href="/BreadBreak/login.php" class="auth-btn primary-btn auth-modal-cta"><i class="fa-solid fa-right-to-bracket"></i> Sign In</a>
                <?php endif; ?>

                <div class="auth-modal-divider"><span>Didn't see the email? Resend it</span></div>

                <form class="auth-form" method="POST" novalidate>
                    <input type="hidden" name="resend_verification" value="1" />
                    <div class="field-group">
                        <label for="resend_email">Email Address</label>
                        <div class="input-wrap">
                            <span class="input-icon"><i class="fa-solid fa-envelope"></i></span>
                            <input id="resend_email" name="email" type="email" placeholder="Enter your email address" value="<?php echo htmlspecialchars(trim($_POST['email'] ?? '')); ?>" />
                        </div>
                        <div class="error-message" data-error-for="resend"><?php echo isset($errors['resend']) ? htmlspecialchars($errors['resend']) : ''; ?></div>
                    </div>
                    <button type="submit" class="auth-btn secondary-btn"><i class="fa-solid fa-envelope-circle-check"></i> Resend verification email</button>
                </form>

                <div class="auth-modal-tips">
                    <p class="auth-modal-tips-title"><i class="fa-solid fa-lightbulb"></i> Email not arriving?</p>
                    <ul>
                        <li>Wait a minute — delivery isn't always instant.</li>
                        <li>Check your Spam, Junk, and Promotions folders.</li>
                        <li>Resend once every 60 seconds if it still hasn't arrived.</li>
                        <li>Make sure the email address above is spelled correctly.</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>
