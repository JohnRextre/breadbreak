<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];
$successMessage = '';

try {
    $pdo = getDatabaseConnection();
    $adminExists = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch();
} catch (Throwable $e) {
    $adminExists = null;
    $errors['database'] = 'Unable to connect to the database yet.';
}

if (!empty($adminExists)) {
    $adminConfigured = true;
} else {
    $adminConfigured = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminConfigured) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

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
            $existingUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $existingUser->execute(['email' => $email]);

            if ($existingUser->fetch()) {
                $errors['email'] = 'This email is already registered.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, phone, email, password, role, status) VALUES (:first_name, :last_name, :phone, :email, :password, :role, :status)');
                $stmt->execute([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'admin',
                    'status' => 'active',
                ]);

                $successMessage = 'Administrator Account Created!';
                $_SESSION['admin_setup_done'] = true;
            }
        } catch (Throwable $e) {
            $errors['database'] = 'Database connection failed. Please start MySQL in XAMPP and try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Setup Admin | BreadBreak</title>
    <meta name="description" content="BreadBreak admin setup page." />
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
                <?php if ($adminConfigured): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Setup Status</span>
                        <h1>Administrator Account Already Configured</h1>
                        <p>An Administrator account has already been created for this BreadBreak system. New users can now register as Customers.</p>
                    </div>
                    <div class="form-actions-inline" style="margin-top: 1rem; text-align: center; display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                        <a href="/BreadBreak/login.php" class="auth-btn primary-btn"><i class="fa-solid fa-right-to-bracket"></i> Go to Sign In</a>
                        <a href="/BreadBreak/register.php" class="auth-btn primary-btn" style="background: linear-gradient(135deg, #5b4033, #8d5a3b);"><i class="fa-solid fa-user-plus"></i> Customer Registration</a>
                    </div>
                <?php else: ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">System Setup</span>
                        <h1>Set Up Administrator Account</h1>
                        <p>This is a one-time setup for the BreadBreak Administrator. Once an Administrator account has been created, new accounts can be registered as Customer accounts.</p>
                    </div>

                    <form class="auth-form" method="POST" novalidate data-form-type="register">
                        <div class="field-row two-columns">
                            <div class="field-group">
                                <label for="first_name">First Name</label>
                                <input id="first_name" name="first_name" type="text" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" placeholder="Enter your first name" aria-invalid="false" />
                                <div class="error-message" data-error-for="first_name"><?php echo isset($errors['first_name']) ? htmlspecialchars($errors['first_name']) : ''; ?></div>
                            </div>
                            <div class="field-group">
                                <label for="last_name">Last Name</label>
                                <input id="last_name" name="last_name" type="text" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" placeholder="Enter your last name" aria-invalid="false" />
                                <div class="error-message" data-error-for="last_name"><?php echo isset($errors['last_name']) ? htmlspecialchars($errors['last_name']) : ''; ?></div>
                            </div>
                        </div>

                        <div class="field-row two-columns">
                            <div class="field-group">
                                <label for="phone">Phone Number</label>
                                <input id="phone" name="phone" type="tel" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" placeholder="Enter your phone number" aria-invalid="false" />
                                <div class="error-message" data-error-for="phone"><?php echo isset($errors['phone']) ? htmlspecialchars($errors['phone']) : ''; ?></div>
                            </div>
                            <div class="field-group">
                                <label for="email">Email</label>
                                <input id="email" name="email" type="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder="Enter your email address" aria-invalid="false" />
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

                        <?php if (!empty($successMessage)): ?>
                            <div class="form-status success" aria-live="polite">
                                <strong><?php echo htmlspecialchars($successMessage); ?></strong>
                                <div style="margin-top: 0.75rem;">Your BreadBreak Administrator account has been created successfully. You can now sign in.</div>
                                <div class="form-actions-inline">
                                    <a href="/BreadBreak/login.php" class="auth-link inline"><i class="fa-solid fa-right-to-bracket"></i> Go to Sign In</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($errors['database'])): ?>
                            <div class="form-status success" aria-live="polite">
                                <?php echo htmlspecialchars($errors['database']); ?>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-user-shield"></i> Create Administrator Account</button>
                        <div class="form-actions-inline" style="text-align: center; margin-top: 0.75rem;">
                            <a href="/BreadBreak/login.php" class="auth-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
                        </div>
                    </form>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
