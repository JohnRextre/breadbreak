<?php
$pageTitle = 'Forgot Password | BreadBreak';
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $errors['identifier'] = 'Email or phone number is required.';
    }

    if (empty($errors)) {
        $successMessage = 'Password recovery will be implemented in a later phase.';
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
    <link rel="stylesheet" href="/BreadBreak/assets/css/auth.css" />
    <script defer src="/BreadBreak/assets/js/auth.js"></script>
</head>
<body class="auth-body">
    <div class="auth-page">
        <div class="auth-shell">
            <header class="auth-topbar">
                <a href="/BreadBreak/index.php" class="brand" aria-label="BreadBreak home page">
                    <span class="brand-mark">B</span>
                    <span>
                        <strong>BreadBreak</strong>
                        <small>Bakery &amp; Online Ordering</small>
                    </span>
                </a>

                <a href="/BreadBreak/index.php" class="back-home-link">← Back to Homepage</a>
            </header>

            <main class="auth-card reset-card">
                <div class="auth-heading">
                    <span class="eyebrow text-accent">Need Help?</span>
                    <h1>Forgot Your Password?</h1>
                    <p>Enter your email or phone number and we'll help you recover your account.</p>
                </div>

                <form class="auth-form" method="POST" novalidate data-form-type="forgot-password">
                    <div class="field-group">
                        <label for="identifier">Email or Phone Number</label>
                        <div class="input-wrap">
                            <span class="input-icon" aria-hidden="true">✉</span>
                            <input id="identifier" name="identifier" type="text" placeholder="Enter your email or phone number" value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>" aria-invalid="false" />
                        </div>
                        <div class="error-message" data-error-for="identifier"><?php echo isset($errors['identifier']) ? htmlspecialchars($errors['identifier']) : ''; ?></div>
                    </div>

                    <button type="submit" class="auth-btn primary-btn">Continue</button>

                    <?php if (!empty($successMessage)): ?>
                        <div class="form-status success" aria-live="polite">
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="auth-footer condensed">
                    <a href="/BreadBreak/login.php" class="auth-link">← Back to Sign In</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
