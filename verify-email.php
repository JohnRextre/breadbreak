<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/auth_tokens.php';

$pageTitle = 'Verify Email | BreadBreak';
$errors = [];

// States: confirm (render form) | success | expired | resent | wait | failed | invalid
$state = 'invalid';
$token = '';
$accountEmail = '';
$accountFirstName = '';
$lookup = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $action = ($_POST['action'] ?? 'confirm') === 'resend' ? 'resend' : 'confirm';

    try {
        $pdo = getDatabaseConnection();
        $lookup = lookupEmailVerification($pdo, $token);

        if ($lookup['status'] === 'ok' || $lookup['status'] === 'expired') {
            $accountStmt = $pdo->prepare('SELECT email, first_name FROM users WHERE id = :id LIMIT 1');
            $accountStmt->execute(['id' => $lookup['user_id']]);
            $accountRow = $accountStmt->fetch();
            $accountEmail = (string) ($accountRow['email'] ?? '');
            $accountFirstName = (string) ($accountRow['first_name'] ?? '');
        }

        if ($action === 'resend' && $lookup['status'] !== 'invalid') {
            // Possession of (even an expired) link lets this page re-send to
            // the account's own mailbox — throttled to one per minute.
            if (verificationResendBlocked($pdo, (int) $lookup['user_id']) !== null) {
                $state = 'wait';
            } else {
                $freshToken = issueEmailVerification($pdo, (int) $lookup['user_id']);
                $state = sendVerificationEmail($accountEmail, $accountFirstName, appUrl('verify-email.php?token=' . $freshToken))
                    ? 'resent'
                    : 'failed';
            }
        } elseif ($lookup['status'] === 'ok') {
            if (consumeEmailVerification($pdo, (int) $lookup['user_id'])) {
                require_once __DIR__ . '/includes/activity_log.php';
                logUserActivity($pdo, (int) $lookup['user_id'], 'email_verified', 'Email address verified');
                $state = 'success';
            } else {
                $state = 'invalid';
            }
        } else {
            $state = $lookup['status'] === 'expired' ? 'expired' : 'invalid';
        }
    } catch (Throwable $exception) {
        error_log('verify-email: ' . $exception->getMessage());
        $state = 'invalid';
    }
} else {
    $token = trim($_GET['token'] ?? '');
    try {
        $pdo = getDatabaseConnection();
        $lookup = lookupEmailVerification($pdo, $token);

        if ($lookup['status'] === 'ok') {
            $state = 'confirm';
        } elseif ($lookup['status'] === 'expired') {
            $state = 'expired';
        } else {
            $state = 'invalid';
        }

        if ($lookup['status'] === 'ok' || $lookup['status'] === 'expired') {
            $accountStmt = $pdo->prepare('SELECT email, first_name FROM users WHERE id = :id LIMIT 1');
            $accountStmt->execute(['id' => $lookup['user_id']]);
            $accountRow = $accountStmt->fetch();
            $accountEmail = (string) ($accountRow['email'] ?? '');
            $accountFirstName = (string) ($accountRow['first_name'] ?? '');
        }
    } catch (Throwable $exception) {
        error_log('verify-email: ' . $exception->getMessage());
        $state = 'invalid';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="BreadBreak email verification page." />
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
                <?php if ($state === 'confirm'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">One Last Step</span>
                        <h1>Confirm Your Email</h1>
                        <p>Click below to verify <strong><?php echo htmlspecialchars($accountEmail); ?></strong> and activate your BreadBreak account.</p>
                    </div>

                    <form class="auth-form" method="POST" novalidate>
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />
                        <input type="hidden" name="action" value="confirm" />
                        <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-envelope-circle-check"></i> Confirm my email</button>
                    </form>

                <?php elseif ($state === 'success'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow" style="color: var(--auth-success);">All Set</span>
                        <h1>Email Verified!</h1>
                        <p>Thanks<?php echo $accountFirstName !== '' ? ', ' . htmlspecialchars($accountFirstName) : '' ?> — your email is confirmed and your account is ready. You can sign in now.</p>
                    </div>

                    <div class="form-status success" role="status">
                        <?php echo htmlspecialchars($accountEmail); ?> is verified.
                    </div>

                    <a href="/BreadBreak/login.php" class="auth-btn primary-btn" style="display: block; text-align: center; text-decoration: none; margin-top: 1rem;"><i class="fa-solid fa-right-to-bracket"></i> Sign In</a>

                <?php elseif ($state === 'expired'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Link Expired</span>
                        <h1>This Link Has Expired</h1>
                        <p>Verification links last 24 hours. We can send a fresh one to <strong><?php echo htmlspecialchars($accountEmail); ?></strong>.</p>
                    </div>

                    <form class="auth-form" method="POST" novalidate>
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />
                        <input type="hidden" name="action" value="resend" />
                        <button type="submit" class="auth-btn primary-btn"><i class="fa-solid fa-envelope-circle-check"></i> Send a new link</button>
                    </form>

                <?php elseif ($state === 'resent'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Check Your Inbox</span>
                        <h1>New Link Sent</h1>
                        <p>We sent a fresh verification link to <strong><?php echo htmlspecialchars($accountEmail); ?></strong>. It lasts 24 hours.</p>
                    </div>

                    <div class="form-status success" role="status">Verification email sent — check your inbox and spam folder.</div>

                <?php elseif ($state === 'wait'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Hang On</span>
                        <h1>Link Already Sent</h1>
                        <p>We just emailed <strong><?php echo htmlspecialchars($accountEmail); ?></strong> — please wait a minute before asking for another link.</p>
                    </div>

                    <div class="form-status error" role="status">Please wait about a minute, then try again.</div>

                <?php elseif ($state === 'failed'): ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Almost There</span>
                        <h1>Couldn't Send the Email</h1>
                        <p>Something went wrong delivering to <strong><?php echo htmlspecialchars($accountEmail); ?></strong>. Please wait a minute and try again.</p>
                    </div>

                    <div class="form-status error" role="status">We couldn't send the email right now.</div>

                <?php else: ?>
                    <div class="auth-heading">
                        <span class="eyebrow text-accent">Hmm…</span>
                        <h1>Link Not Valid</h1>
                        <p>This verification link is invalid or has already been used. If you still need to verify, sign in and request a new link.</p>
                    </div>

                    <div class="form-status error" role="status">No account matches this verification link.</div>
                <?php endif; ?>

                <div class="auth-footer condensed">
                    <a href="/BreadBreak/login.php" class="auth-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
