<?php
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Create Account | BreadBreak';
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $errors['email'] = 'This email is already registered.';
            } else {
                $insertStmt = $pdo->prepare('INSERT INTO users (first_name, last_name, phone, email, password, role, status) VALUES (:first_name, :last_name, :phone, :email, :password, :role, :status)');
                $insertStmt->execute([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'customer',
                    'status' => 'active',
                ]);

                $successMessage = 'Account created successfully!';
            }
        } catch (Throwable $e) {
            $errors['database'] = 'Unable to create the account right now.';
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
    <meta name="description" content="BreadBreak customer registration page." />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/auth.css" />
    <script defer src="/BreadBreak/assets/js/auth.js"></script>
</head>
<body class="auth-body">
    <div class="auth-page">
        <div class="auth-shell wide-shell">
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
                            <span class="input-icon" aria-hidden="true">🔒</span>
                            <input id="password" name="password" type="password" placeholder="Create a password" aria-invalid="false" />
                            <button type="button" class="toggle-password" data-target="password" aria-label="Show password">👁</button>
                        </div>
                        <div class="error-message" data-error-for="password"><?php echo isset($errors['password']) ? htmlspecialchars($errors['password']) : ''; ?></div>
                        <div class="password-strength" aria-live="polite">Password strength: <span id="strengthText">-</span></div>
                    </div>

                    <div class="field-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrap password-wrap">
                            <span class="input-icon" aria-hidden="true">🔒</span>
                            <input id="confirm_password" name="confirm_password" type="password" placeholder="Confirm your password" aria-invalid="false" />
                            <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password">👁</button>
                        </div>
                        <div class="error-message" data-error-for="confirm_password"><?php echo isset($errors['confirm_password']) ? htmlspecialchars($errors['confirm_password']) : ''; ?></div>
                    </div>

                    <button type="submit" class="auth-btn primary-btn">Create Account</button>

                    <?php if (!empty($errors['database'])): ?>
                        <div class="form-status success" aria-live="polite" style="background: rgba(181, 51, 44, 0.1); border-color: rgba(181, 51, 44, 0.2); color: #b5332c;">
                            <?php echo htmlspecialchars($errors['database']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($successMessage)): ?>
                        <div class="form-status success" aria-live="polite">
                            <?php echo htmlspecialchars($successMessage); ?>
                            <div class="form-actions-inline">
                                <a href="/BreadBreak/login.php" class="auth-link inline">Sign In</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="auth-footer">
                    <p>Already have an account?</p>
                    <a href="/BreadBreak/login.php" class="auth-link">Sign In</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
