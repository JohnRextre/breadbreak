<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
$pageTitle = 'Reports & Analytics';
$activePage = 'reports';
require __DIR__ . '/../includes/staff_header.php';
?>
<section class="page-intro"><h2>Reports &amp; Analytics</h2><p>Review operational insights for BreadBreak.</p></section><section class="panel placeholder-panel"><div><strong>Staff reports will be implemented here.</strong><p>Reporting tools are coming next.</p></div></section>
<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
