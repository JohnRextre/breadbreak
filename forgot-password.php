<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/auth_tokens.php';

$pageTitle = 'Forgot Password | BreadBreak';
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        // Identical outcome whether or not the account exists — never leaks
        // which addresses are registered.
        $successMessage = 'If an account exists for that email, we’ve sent a password reset link. Check your inbox and spam folder.';

        try {
            $pdo = getDatabaseConnection();
            $resetStmt = $pdo->prepare('SELECT id, first_name, email FROM users WHERE email = :email LIMIT 1');
            $resetStmt->execute(['email' => $email]);
            $resetUser = $resetStmt->fetch();

            if ($resetUser) {
                $result = issuePasswordReset($pdo, (int) $resetUser['id'], $_SERVER['REMOTE_ADDR'] ?? null);

                if ($result['sent'] && $result['token'] !== null) {
                    $sent = sendPasswordResetEmail(
                        $resetUser['email'],
                        (string) $resetUser['first_name'],
                        appUrl('reset-password.php?token=' . $result['token'])
                    );

                    if ($sent) {
                        require_once __DIR__ . '/includes/activity_log.php';
                        logUserActivity($pdo, (int) $resetUser['id'], 'password_reset_requested', 'Reset link sent to email');
                    }
                } else {
                    error_log('forgot-password: reset not sent for user ' . $resetUser['id'] . ' (' . ($result['reason'] ?? 'unknown') . ')');
                }
            }
        } catch (Throwable $exception) {
            error_log('forgot-password: ' . $exception->getMessage());
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
    <meta name="description" content="BreadBreak password recovery page." />
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
                <div class="auth-heading">
                    <span class="eyebrow text-accent">Need Help?</span>
                    <h1>Forgot Your Password?</h1>
                    <p>Enter your email address and we'll send you a link to set a new password.</p>
                </div>

                <form class="auth-form" method="POST" novalidate data-form-type="forgot-password">
                    <div class="field-group">
                        <label for="email">Email Address</label>
                        <div class="input-wrap">
                            <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
                            <input id="email" name="email" type="email" placeholder="Enter your email address" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" aria-invalid="false" />
                        </div>
                        <div class="error-message" data-error-for="email"><?php echo isset($errors['email']) ? htmlspecialchars($errors['email']) : ''; ?></div>
                    </div>

                    <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-paper-plane"></i> Send reset link</button>

                    <?php if (!empty($successMessage)): ?>
                        <div class="form-status success" role="status">
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="auth-footer condensed">
                    <a href="/BreadBreak/login.php" class="auth-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
