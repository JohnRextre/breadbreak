<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['action']) && $_GET['action'] === 'create_account') {
    try {
        $pdo = getDatabaseConnection();
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();

        if ($adminCount === 0) {
            header('Location: /BreadBreak/setup-admin.php');
            exit;
        }

        header('Location: /BreadBreak/register.php');
        exit;
    } catch (Throwable $e) {
        header('Location: /BreadBreak/setup-admin.php');
        exit;
    }
}

$pageTitle = 'Sign In | BreadBreak';
$errors = [];
$successMessage = ($_GET['logged_out'] ?? '') === '1' ? 'You have been logged out.' : '';
$selectedAccountType = $_POST['account_type'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountType = trim($_POST['account_type'] ?? '');
    $identifier = trim($_POST['identifier'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $allowedRoles = ['admin' => 'Admin', 'staff' => 'Staff', 'rider' => 'Rider', 'customer' => 'Customer'];

    if ($accountType === '') {
        $errors['account_type'] = 'Please select an account type.';
    }

    if ($identifier === '') {
        $errors['identifier'] = 'Email or phone number is required.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    if (empty($errors)) {
        try {
            $pdo = getDatabaseConnection();
            require_once __DIR__ . '/includes/rider.php';
            ensureRiderSupport($pdo);
            $roleKey = strtolower($accountType);

            if (!isset($allowedRoles[$roleKey])) {
                $errors['account_type'] = 'Invalid account type.';
            } else {
                $sql = 'SELECT * FROM users WHERE email = :email_identifier OR phone = :phone_identifier LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'email_identifier' => $identifier,
                    'phone_identifier' => $identifier,
                ]);

                $user = $stmt->fetch();

                if (!$user || $user['role'] !== $roleKey || !password_verify($password, $user['password'])) {
                    $errors['password'] = 'Incorrect Password or Account Type';
                } elseif (($user['status'] ?? 'active') !== 'active') {
                    $reason = trim((string) ($user['status_reason'] ?? ''));
                    $errors['password'] = 'This account has been deactivated.' . ($reason !== '' ? ' Reason: ' . $reason : ' Please contact an administrator.');
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['cart'] = is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [];

                    if ($user['role'] === 'customer') {
                        $pdo->exec('CREATE TABLE IF NOT EXISTS customer_cart (user_id INT NOT NULL, variant_id INT NOT NULL, quantity INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (user_id, variant_id), CONSTRAINT fk_customer_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_customer_cart_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON DELETE CASCADE)');
                        $savedCartStatement = $pdo->prepare('SELECT variant_id, quantity FROM customer_cart WHERE user_id = :user_id');
                        $savedCartStatement->execute(['user_id' => (int) $user['id']]);
                        foreach ($savedCartStatement->fetchAll() as $savedItem) {
                            $_SESSION['cart'][(int) $savedItem['variant_id']] = (int) $savedItem['quantity'];
                        }
                    }

                    if ($user['role'] === 'admin') {
                        header('Location: /BreadBreak/admin/dashboard.php');
                        exit;
                    }

                    if ($user['role'] === 'staff') {
                        header('Location: /BreadBreak/staff/dashboard.php');
                        exit;
                    }

                    if ($user['role'] === 'rider') {
                        header('Location: /BreadBreak/rider/dashboard.php');
                        exit;
                    }

                    header('Location: /BreadBreak/customer/menu_dashboard.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $errors['password'] = 'Database connection failed. Please start MySQL in XAMPP and try again.';
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
    <meta name="description" content="BreadBreak account sign in page." />
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

            <main class="auth-card login-card">
                <div class="auth-heading">
                    <span class="eyebrow text-accent">Welcome Back</span>
                    <h1>Welcome Back</h1>
                    <p>Sign in to continue to BreadBreak.</p>
                </div>
                <?php if ($successMessage): ?><div class="form-status success" role="status"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>

                <form class="auth-form" method="POST" novalidate data-form-type="login">
                    <div class="field-group">
                        <label for="account_type">Select Account Type</label>
                        <div class="custom-select-wrap">
                            <select id="account_type" name="account_type" class="custom-select<?php echo isset($errors['account_type']) ? ' error-field' : ''; ?>" aria-invalid="<?php echo isset($errors['account_type']) ? 'true' : 'false'; ?>">
                                <option value="" <?php echo $selectedAccountType === '' ? 'selected' : ''; ?>>Select account type</option>
                                <option value="Admin" <?php echo $selectedAccountType === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="Staff" <?php echo $selectedAccountType === 'Staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="Rider" <?php echo $selectedAccountType === 'Rider' ? 'selected' : ''; ?>>Rider</option>
                                <option value="Customer" <?php echo $selectedAccountType === 'Customer' ? 'selected' : ''; ?>>Customer</option>
                            </select>
                        </div>
                        <div class="error-message" data-error-for="account_type"><?php echo isset($errors['account_type']) ? htmlspecialchars($errors['account_type']) : ''; ?></div>
                    </div>

                    <div class="field-group">
                        <label for="identifier">Email or Phone Number</label>
                        <div class="input-wrap">
                            <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                            <input id="identifier" name="identifier" type="text" placeholder="Enter your email or phone number" value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>" class="<?php echo isset($errors['identifier']) ? 'error-field' : ''; ?>" aria-invalid="<?php echo isset($errors['identifier']) ? 'true' : 'false'; ?>" />
                        </div>
                        <div class="error-message" data-error-for="identifier"><?php echo isset($errors['identifier']) ? htmlspecialchars($errors['identifier']) : ''; ?></div>
                    </div>

                    <div class="field-group">
                        <label for="password">Password</label>
                        <div class="input-wrap password-wrap">
                            <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                            <input id="password" name="password" type="password" placeholder="Enter your password" value="<?php echo isset($_POST['password']) ? htmlspecialchars($_POST['password']) : ''; ?>" class="<?php echo isset($errors['password']) ? 'error-field' : ''; ?>" aria-invalid="<?php echo isset($errors['password']) ? 'true' : 'false'; ?>" />
                            <button type="button" class="toggle-password" data-target="password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <div class="error-message" data-error-for="password"><?php echo isset($errors['password']) ? htmlspecialchars($errors['password']) : ''; ?></div>
                    </div>

                    <div class="form-row between">
                        <div class="checkbox-wrap">
                            <input type="checkbox" id="remember" />
                            <label for="remember">Remember me</label>
                        </div>
                        <a href="/BreadBreak/forgot-password.php" class="inline-link">Forgot Password?</a>
                    </div>

                    <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>

                </form>

                <div class="auth-footer">
                    <p>Don't have an account?</p>
                    <a href="/BreadBreak/login.php?action=create_account" class="auth-link">Create an account</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
