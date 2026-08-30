<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
$pageTitle = 'My Profile';
$activePage = 'profile';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="page-intro"><h2>My Profile</h2><p>View your administrator account details.</p></section>
<section class="panel placeholder-panel"><div><strong><?php echo htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin'); ?></strong><p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?> &middot; Administrator</p></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
