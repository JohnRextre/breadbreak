<?php
// =============================================================================
// admin/reports.php  –  Reports & Analytics (Admin)
// Markup and data live in includes/reports.php + includes/reports_view.php so
// Admin and Staff can never drift apart.
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reports.php';

$pageTitle  = 'Reports & Analytics';
$activePage = 'reports';

$pdo     = getDatabaseConnection();
$query   = $_GET;
$reports = reportsBuildDataset($pdo, $query);

$ordersUrl = BASE_URL . '/admin/orders.php';

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="page-intro users-page-intro" style="margin-bottom: 22px;">
    <div>
        <span class="eyebrow" style="font-size: 11px; font-weight: 800; color: var(--admin-brown); letter-spacing: 0.1em; text-transform: uppercase;">Business Insights &amp; Financials</span>
        <h2 style="margin: 4px 0 6px; font-family: 'Manrope', sans-serif; font-size: 26px; color: var(--admin-ink);">Reports &amp; Analytics</h2>
        <p style="margin: 0; color: var(--admin-muted); font-size: 13px;">Overview of sales performance, paid revenue from Xendit, and inventory valuation.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <button onclick="window.print()" class="admin-button secondary" style="height: 42px; border-radius: 8px;">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</section>

<?php require __DIR__ . '/../includes/reports_view.php'; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>