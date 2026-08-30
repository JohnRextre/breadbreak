<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
$pageTitle = 'My Profile';
$activePage = 'profile';
require __DIR__ . '/../includes/staff_header.php';
?>
<section class="page-intro"><h2>My Profile</h2><p>View your staff account details.</p></section><section class="panel placeholder-panel"><div><strong><?php echo htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Staff'); ?></strong><p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?> &middot; Staff</p></div></section>
<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
