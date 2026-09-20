<?php
// ============================================================
// payment-return.php  –  Landing page after Xendit redirect
// ============================================================
// Xendit redirects here after the customer finishes (or cancels)
// on the GCash page. We do NOT mark the order as paid here —
// only the webhook (webhook/xendit.php) does that after verification.
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireRole('customer');

$status = $_GET['status'] ?? '';  // 'success' or 'cancel'
$ref    = trim($_GET['ref'] ?? '');

$pageTitle = 'Payment Verification | BreadBreak';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/style.css" />
    <link rel="stylesheet" href="/BreadBreak/assets/css/checkout.css" />
    <?php if ($ref): ?>
    <!-- Auto-redirect to order confirmation after 4 seconds -->
    <meta http-equiv="refresh" content="4;url=/BreadBreak/order-confirmation.php?ref=<?php echo urlencode($ref); ?>" />
    <?php endif; ?>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="payment-verify-page">
<div class="container" style="max-width:560px;text-align:center;">

    <?php if ($status === 'success'): ?>
        <div class="payment-verify-icon"><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i></div>
        <h2 style="color:var(--brown-900)">Payment Submitted</h2>
        <p>Thank you! Your GCash payment has been submitted. We are now verifying your payment with Xendit. This usually takes just a few seconds.</p>
    <?php else: ?>
        <div class="payment-verify-icon"><i class="fa-solid fa-circle-xmark" style="color:#b91c1c"></i></div>
        <h2 style="color:var(--brown-900)">Payment Cancelled</h2>
        <p>You cancelled or did not complete the GCash payment. Your order is still saved as <strong>Pending</strong>. You can try again from your order confirmation page.</p>
    <?php endif; ?>

    <div class="payment-verify-spinner"></div>
    <p style="font-size:.9rem;color:var(--muted);">Redirecting to your order status page&hellip;</p>

    <?php if ($ref): ?>
        <a href="/BreadBreak/order-confirmation.php?ref=<?php echo urlencode($ref); ?>"
           class="btn btn-primary" style="margin-top:1.5rem;display:inline-block;">
            View Order Status
        </a>
    <?php else: ?>
        <a href="/BreadBreak/customer/menu_dashboard.php"
           class="btn btn-primary" style="margin-top:1.5rem;display:inline-block;">
            Return to Menu
        </a>
    <?php endif; ?>

</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

