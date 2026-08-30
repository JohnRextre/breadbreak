<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
$pageTitle = 'Reports & Analytics';
$activePage = 'reports';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>Reports &amp; Analytics</h2><p>Understand the performance of the BreadBreak system.</p></section>
<section class="panel placeholder-panel"><div><strong>Reports and analytics will be implemented here.</strong><p>Business insights will be available when reporting data is connected.</p></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
