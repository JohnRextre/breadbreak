<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Staff Dashboard | BreadBreak</title>
    <link rel="stylesheet" href="/BreadBreak/assets/css/auth.css" />
</head>
<body class="auth-body">
    <div class="auth-page">
        <div class="auth-shell">
            <main class="auth-card">
                <div class="auth-heading">
                    <span class="eyebrow text-accent">Staff Portal</span>
                    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?></h1>
                    <p>Account Type: Staff</p>
                </div>
                <div class="form-status success">Staff dashboard placeholder.</div>
                <div class="form-actions-inline" style="text-align: center; margin-top: 1rem;">
                    <a href="/BreadBreak/logout.php" class="auth-link">Logout</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
